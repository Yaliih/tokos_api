<?php
// app/Http/Controllers/Api/VoteController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\ChallengeDuel;
use App\Models\ContestCandidate;
use App\Services\VoteCounterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VoteController extends Controller
{
    protected $voteCounter;

    public function __construct(VoteCounterService $voteCounter)
    {
        $this->voteCounter = $voteCounter;
    }

    /**
     * ⭐ Voter pour un duel (option A ou B)
     * - Si l'utilisateur vote pour la même option : retire le vote
     * - Si l'utilisateur vote pour une autre option : change le vote
     * - Si l'utilisateur n'a pas voté : enregistre le vote
     */
    public function voteDuel(Request $request, int $postId): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'option' => 'required|in:a,b',
        ]);

        $post = Post::with('duel')->find($postId);
        if (!$post || !$post->duel) {
            return response()->json(['success' => false, 'message' => 'Duel not found'], 404);
        }

        $duel = $post->duel;

        // Vérifier si le duel est terminé
        if ($duel->end_date && now()->greaterThan($duel->end_date)) {
            return response()->json(['success' => false, 'message' => 'This duel has ended.'], 400);
        }

        $option = $validated['option'];

        // Vérifier si l'utilisateur a déjà voté sur ce duel
        $existingVote = PostVote::where('post_id', $postId)
            ->where('user_id', $user->id)
            ->where('voteable_type', 'duel_option')
            ->where('voteable_id', $duel->id)
            ->first();

        DB::beginTransaction();

        try {
            if ($existingVote) {
                // Cas 1: L'utilisateur vote pour la même option → retire le vote
                if ($existingVote->value === $option) {
                    $existingVote->delete();

                    // Décrémenter le compteur
                    if ($option === 'a') {
                        $duel->votes_a = max(0, $duel->votes_a - 1);
                    } else {
                        $duel->votes_b = max(0, $duel->votes_b - 1);
                    }
                    $duel->total_votes = max(0, $duel->total_votes - 1);
                    $duel->save();

                    DB::commit();

                    return response()->json([
                        'success' => true,
                        'message' => 'Vote removed successfully.',
                        'data' => [
                            'option' => null,
                            'votes_a' => $duel->votes_a,
                            'votes_b' => $duel->votes_b,
                            'total_votes' => $duel->total_votes,
                            'percentage_a' => $duel->percentage_a,
                            'percentage_b' => $duel->percentage_b,
                            'voted' => false,
                        ]
                    ]);
                }

                // Cas 2: L'utilisateur change de vote (A → B ou B → A)
                $oldOption = $existingVote->value;
                $existingVote->value = $option;
                $existingVote->save();

                // Mettre à jour les compteurs
                if ($oldOption === 'a') {
                    $duel->votes_a = max(0, $duel->votes_a - 1);
                    $duel->votes_b = $duel->votes_b + 1;
                } else {
                    $duel->votes_b = max(0, $duel->votes_b - 1);
                    $duel->votes_a = $duel->votes_a + 1;
                }
                $duel->save();

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Vote changed successfully.',
                    'data' => [
                        'option' => $option,
                        'votes_a' => $duel->votes_a,
                        'votes_b' => $duel->votes_b,
                        'total_votes' => $duel->total_votes,
                        'percentage_a' => $duel->percentage_a,
                        'percentage_b' => $duel->percentage_b,
                        'voted' => true,
                    ]
                ]);
            }

            // Cas 3: Nouveau vote
            PostVote::create([
                'post_id' => $postId,
                'user_id' => $user->id,
                'voteable_type' => 'duel_option',
                'voteable_id' => $duel->id,
                'value' => $option,
                'ip_address' => $request->ip(),
            ]);

            // Incrémenter le compteur
            if ($option === 'a') {
                $duel->votes_a = $duel->votes_a + 1;
            } else {
                $duel->votes_b = $duel->votes_b + 1;
            }
            $duel->total_votes = $duel->total_votes + 1;
            $duel->save();

            // Enregistrer l'interaction
            \App\Models\UserInteraction::record($user->id, $postId, 'vote');

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Vote recorded successfully.',
                'data' => [
                    'option' => $option,
                    'votes_a' => $duel->votes_a,
                    'votes_b' => $duel->votes_b,
                    'total_votes' => $duel->total_votes,
                    'percentage_a' => $duel->percentage_a,
                    'percentage_b' => $duel->percentage_b,
                    'voted' => true,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Duel vote failed: ' . $e->getMessage(), [
                'post_id' => $postId,
                'user_id' => $user->id,
                'option' => $option
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to process vote: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ⭐ Récupérer le vote de l'utilisateur sur un duel
     */
    public function getDuelVote(Request $request, int $postId): JsonResponse
    {
        $user = $request->user();
        $post = Post::with('duel')->find($postId);

        if (!$post || !$post->duel) {
            return response()->json(['success' => false, 'message' => 'Duel not found'], 404);
        }

        $duel = $post->duel;

        $vote = PostVote::where('post_id', $postId)
            ->where('user_id', $user->id)
            ->where('voteable_type', 'duel_option')
            ->where('voteable_id', $duel->id)
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'voted' => (bool) $vote,
                'option' => $vote ? $vote->value : null,
                'votes_a' => $duel->votes_a,
                'votes_b' => $duel->votes_b,
                'total_votes' => $duel->total_votes,
                'percentage_a' => $duel->percentage_a,
                'percentage_b' => $duel->percentage_b,
            ]
        ]);
    }

    public function voteDebate(Request $request, int $postId): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'side' => 'required|in:for,against',
        ]);

        $post = Post::with('debate')->find($postId);
        if (!$post || !$post->debate) {
            return response()->json(['success' => false, 'message' => 'Debate not found'], 404);
        }

        $debate = $post->debate;

        // Vérifier si le débat est terminé
        if ($debate->end_date && now()->greaterThan($debate->end_date)) {
            return response()->json(['success' => false, 'message' => 'This debate has ended.'], 400);
        }

        $side = $validated['side'];

        // Vérifier si l'utilisateur a déjà voté sur ce débat
        $existingVote = PostVote::where('post_id', $postId)
            ->where('user_id', $user->id)
            ->where('voteable_type', 'debate_side')
            ->where('voteable_id', $debate->id)
            ->first();

        DB::beginTransaction();

        try {
            if ($existingVote) {
                // Même côté → retire le vote
                if ($existingVote->value === $side) {
                    $existingVote->delete();

                    if ($side === 'for') {
                        $debate->votes_for = max(0, $debate->votes_for - 1);
                    } else {
                        $debate->votes_against = max(0, $debate->votes_against - 1);
                    }
                    $debate->total_votes = max(0, $debate->total_votes - 1);
                    $debate->save();

                    DB::commit();

                    return response()->json([
                        'success' => true,
                        'message' => 'Vote removed successfully.',
                        'data' => [
                            'votes_for' => $debate->votes_for,
                            'votes_against' => $debate->votes_against,
                            'total_votes' => $debate->total_votes,
                            'percentage_for' => $debate->percentage_for,
                            'percentage_against' => $debate->percentage_against,
                            'voted' => false,
                        ]
                    ]);
                }

                // Changement de côté
                $oldSide = $existingVote->value;
                $existingVote->value = $side;
                $existingVote->save();

                if ($oldSide === 'for') {
                    $debate->votes_for = max(0, $debate->votes_for - 1);
                    $debate->votes_against = $debate->votes_against + 1;
                } else {
                    $debate->votes_against = max(0, $debate->votes_against - 1);
                    $debate->votes_for = $debate->votes_for + 1;
                }
                $debate->save();

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Vote changed successfully.',
                    'data' => [
                        'votes_for' => $debate->votes_for,
                        'votes_against' => $debate->votes_against,
                        'total_votes' => $debate->total_votes,
                        'percentage_for' => $debate->percentage_for,
                        'percentage_against' => $debate->percentage_against,
                        'voted' => true,
                    ]
                ]);
            }

            // Nouveau vote
            PostVote::create([
                'post_id' => $postId,
                'user_id' => $user->id,
                'voteable_type' => 'debate_side',
                'voteable_id' => $debate->id,
                'value' => $side,
                'ip_address' => $request->ip(),
            ]);

            if ($side === 'for') {
                $debate->votes_for = $debate->votes_for + 1;
            } else {
                $debate->votes_against = $debate->votes_against + 1;
            }
            $debate->total_votes = $debate->total_votes + 1;
            $debate->save();

            \App\Models\UserInteraction::record($user->id, $postId, 'vote');

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Vote recorded successfully.',
                'data' => [
                    'votes_for' => $debate->votes_for,
                    'votes_against' => $debate->votes_against,
                    'total_votes' => $debate->total_votes,
                    'percentage_for' => $debate->percentage_for,
                    'percentage_against' => $debate->percentage_against,
                    'voted' => true,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Debate vote failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to process vote: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function voteChallengeDuel(Request $request, int $duelId): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'participant_id' => 'required|integer|exists:challenge_participants,id',
        ]);

        $duel = ChallengeDuel::find($duelId);
        if (!$duel) {
            return response()->json(['success' => false, 'message' => 'Duel not found'], 404);
        }

        // Vérifier si le duel est terminé
        if ($duel->status === 'ended') {
            return response()->json(['success' => false, 'message' => 'This duel has ended.'], 400);
        }

        $participantId = $validated['participant_id'];
        if ($participantId !== $duel->participant_a_id && $participantId !== $duel->participant_b_id) {
            return response()->json(['success' => false, 'message' => 'Invalid participant'], 422);
        }

        $postId = $duel->challenge_id;

        // Vérifier si l'utilisateur a déjà voté
        $existingVote = PostVote::where('post_id', $postId)
            ->where('user_id', $user->id)
            ->where('voteable_type', 'duel_option')
            ->where('voteable_id', $participantId)
            ->first();

        DB::beginTransaction();

        try {
            if ($existingVote) {
                // Même participant → retire le vote
                if ($existingVote->voteable_id == $participantId) {
                    $existingVote->delete();
                    
                    if ($participantId === $duel->participant_a_id) {
                        $duel->votes_a = max(0, $duel->votes_a - 1);
                    } else {
                        $duel->votes_b = max(0, $duel->votes_b - 1);
                    }
                    $duel->total_votes = max(0, $duel->total_votes - 1);
                    $duel->save();

                    DB::commit();

                    return response()->json([
                        'success' => true,
                        'message' => 'Vote removed successfully.',
                        'data' => [
                            'votes_a' => $duel->votes_a,
                            'votes_b' => $duel->votes_b,
                            'total_votes' => $duel->total_votes,
                            'voted' => false,
                        ]
                    ]);
                }

                // Changement de participant
                $oldParticipantId = $existingVote->voteable_id;
                $existingVote->voteable_id = $participantId;
                $existingVote->save();

                if ($oldParticipantId === $duel->participant_a_id) {
                    $duel->votes_a = max(0, $duel->votes_a - 1);
                    $duel->votes_b = $duel->votes_b + 1;
                } else {
                    $duel->votes_b = max(0, $duel->votes_b - 1);
                    $duel->votes_a = $duel->votes_a + 1;
                }
                $duel->save();

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Vote changed successfully.',
                    'data' => [
                        'votes_a' => $duel->votes_a,
                        'votes_b' => $duel->votes_b,
                        'total_votes' => $duel->total_votes,
                        'voted' => true,
                    ]
                ]);
            }

            // Nouveau vote
            PostVote::create([
                'post_id' => $postId,
                'user_id' => $user->id,
                'voteable_type' => 'duel_option',
                'voteable_id' => $participantId,
                'value' => null,
                'ip_address' => $request->ip(),
            ]);

            if ($participantId === $duel->participant_a_id) {
                $duel->votes_a = $duel->votes_a + 1;
            } else {
                $duel->votes_b = $duel->votes_b + 1;
            }
            $duel->total_votes = $duel->total_votes + 1;
            $duel->save();

            \App\Models\UserInteraction::record($user->id, $postId, 'vote');

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Vote recorded successfully.',
                'data' => [
                    'votes_a' => $duel->votes_a,
                    'votes_b' => $duel->votes_b,
                    'total_votes' => $duel->total_votes,
                    'voted' => true,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Challenge duel vote failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to process vote: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function voteContestCandidate(Request $request, int $candidateId): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'value' => 'nullable|integer|min:1|max:10',
        ]);

        $candidate = ContestCandidate::find($candidateId);
        if (!$candidate) {
            return response()->json(['success' => false, 'message' => 'Candidate not found'], 404);
        }

        $value = $validated['value'] ?? 1;

        // Vérifier si l'utilisateur a déjà voté
        $existingVote = \App\Models\CandidateVote::where('candidate_id', $candidateId)
            ->where('user_id', $user->id)
            ->first();

        DB::beginTransaction();

        try {
            if ($existingVote) {
                // Si même valeur → retire le vote
                if ($existingVote->value === $value) {
                    $existingVote->delete();
                    $candidate->total_votes = max(0, $candidate->total_votes - $value);
                    $candidate->score = max(0, $candidate->score - $value);
                    $candidate->save();

                    DB::commit();

                    return response()->json([
                        'success' => true,
                        'message' => 'Vote removed successfully.',
                        'data' => [
                            'total_votes' => $candidate->total_votes,
                            'score' => $candidate->score,
                            'voted' => false,
                        ]
                    ]);
                }

                // Change la valeur du vote
                $oldValue = $existingVote->value;
                $existingVote->value = $value;
                $existingVote->save();

                $candidate->total_votes = $candidate->total_votes - $oldValue + $value;
                $candidate->score = $candidate->score - $oldValue + $value;
                $candidate->save();

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Vote updated successfully.',
                    'data' => [
                        'total_votes' => $candidate->total_votes,
                        'score' => $candidate->score,
                        'voted' => true,
                    ]
                ]);
            }

            // Nouveau vote
            \App\Models\CandidateVote::create([
                'candidate_id' => $candidateId,
                'user_id' => $user->id,
                'value' => $value,
                'ip_address' => $request->ip(),
            ]);

            $candidate->total_votes = $candidate->total_votes + $value;
            $candidate->score = $candidate->score + $value;
            $candidate->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Vote recorded successfully.',
                'data' => [
                    'total_votes' => $candidate->total_votes,
                    'score' => $candidate->score,
                    'voted' => true,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Contest vote failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to process vote: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ⭐ Récupérer le vote de l'utilisateur sur un challenge duel
     */
    public function getChallengeDuelVote(Request $request, int $duelId): JsonResponse
    {
        $user = $request->user();
        $duel = ChallengeDuel::find($duelId);

        if (!$duel) {
            return response()->json(['success' => false, 'message' => 'Duel not found'], 404);
        }

        $postId = $duel->challenge_id;

        $vote = PostVote::where('post_id', $postId)
            ->where('user_id', $user->id)
            ->where('voteable_type', 'duel_option')
            ->whereIn('voteable_id', [$duel->participant_a_id, $duel->participant_b_id])
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'voted' => (bool) $vote,
                'participant_id' => $vote ? $vote->voteable_id : null,
                'votes_a' => $duel->votes_a,
                'votes_b' => $duel->votes_b,
                'total_votes' => $duel->total_votes,
            ]
        ]);
    }

    public function refreshAll(Request $request): JsonResponse
    {
        if (!$request->user() || !$request->user()->is_admin) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $this->voteCounter->refreshAllCounters();

        return response()->json([
            'success' => true,
            'message' => 'All vote counters refreshed',
        ]);
    }
}