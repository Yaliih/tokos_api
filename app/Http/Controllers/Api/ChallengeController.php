<?php
// app/Http/Controllers/Api/ChallengeController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\ChallengeParticipant;
use App\Models\ChallengeDuel;
use App\Models\PostVote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChallengeController extends Controller
{
    /**
     * Récupérer les participants d'un challenge
     * Utilise POST.ID
     */
    public function getParticipants(Request $request, int $postId): JsonResponse
    {
        $post = Post::find($postId);
        if (!$post || $post->type !== 'challenge') {
            return response()->json(['success' => false, 'message' => 'Challenge not found.'], 404);
        }

        $challenge = $post->challenge;
        if (!$challenge) {
            return response()->json(['success' => false, 'message' => 'Challenge data not found.'], 404);
        }

        $user = $request->user();

        $participants = ChallengeParticipant::with(['user'])
            ->where('challenge_id', $challenge->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($participant) use ($user) {
                return [
                    'id' => $participant->id,
                    'user_id' => $participant->user_id,
                    'content' => $participant->content,
                    'media' => $participant->media,
                    'media_urls' => $participant->media_urls,
                    'first_media' => $participant->first_media,
                    'status' => $participant->status,
                    'user' => [
                        'id' => $participant->user->id,
                        'username' => $participant->user->username,
                        'first_name' => $participant->user->first_name,
                        'last_name' => $participant->user->last_name,
                        'avatar' => $participant->user->avatar,
                        'is_verified' => $participant->user->is_verified ?? false,
                    ],
                    'total_votes' => $participant->total_votes,
                    'wins' => $participant->wins,
                    'losses' => $participant->losses,
                    'is_current_user' => $user && $participant->user_id === $user->id,
                    'created_at' => $participant->created_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $participants,
        ]);
    }

    /**
     * Récupérer les duels d'un challenge
     * Utilise POST.ID
     */
    public function getDuels(Request $request, int $postId): JsonResponse
    {
        
        $post = Post::where('id', $postId)->first();
        if (!$post || $post->type !== 'challenge') {
            return response()->json(['success' => false, 'message' => 'Challenge not found.'], 404);
        }

        $challenge = $post->challenge;
        if (!$challenge) {
            return response()->json(['success' => false, 'message' => 'Challenge data not found.'], 404);
        }

        $duels = ChallengeDuel::with(['participantA.user', 'participantB.user'])
            ->where('challenge_id', $challenge->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($duel) {
                return [
                    'id' => $duel->id,
                    'participant_a_id' => $duel->participant_a_id,
                    'participant_b_id' => $duel->participant_b_id,
                    'votes_a' => $duel->votes_a,
                    'votes_b' => $duel->votes_b,
                    'total_votes' => $duel->total_votes,
                    'winner' => $duel->winner,
                    'end_date' => $duel->end_date,
                    'time_left' => $duel->time_left,
                    'is_ended' => $duel->isEnded(),
                    'participant_a' => $duel->participantA ? [
                        'id' => $duel->participantA->id,
                        'user_id' => $duel->participantA->user_id,
                        'user' => $duel->participantA->user ? [
                            'id' => $duel->participantA->user->id,
                            'username' => $duel->participantA->user->username,
                            'first_name' => $duel->participantA->user->first_name,
                            'last_name' => $duel->participantA->user->last_name,
                            'avatar' => $duel->participantA->user->avatar,
                            'is_verified' => $duel->participantA->user->is_verified ?? false,
                        ] : null,
                        'total_votes' => $duel->participantA->total_votes,
                        'wins' => $duel->participantA->wins,
                        'losses' => $duel->participantA->losses,
                    ] : null,
                    'participant_b' => $duel->participantB ? [
                        'id' => $duel->participantB->id,
                        'user_id' => $duel->participantB->user_id,
                        'user' => $duel->participantB->user ? [
                            'id' => $duel->participantB->user->id,
                            'username' => $duel->participantB->user->username,
                            'first_name' => $duel->participantB->user->first_name,
                            'last_name' => $duel->participantB->user->last_name,
                            'avatar' => $duel->participantB->user->avatar,
                            'is_verified' => $duel->participantB->user->is_verified ?? false,
                        ] : null,
                        'total_votes' => $duel->participantB->total_votes,
                        'wins' => $duel->participantB->wins,
                        'losses' => $duel->participantB->losses,
                    ] : null,
                    'created_at' => $duel->created_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $duels,
        ]);
    }

    /**
     * Récupérer les détails d'un participant
     * Utilise PARTICIPANT.ID
     */
    public function getParticipant(Request $request, int $participantId): JsonResponse
    {
        $participant = ChallengeParticipant::with(['user'])
            ->find($participantId);

        if (!$participant) {
            return response()->json(['success' => false, 'message' => 'Participant not found.'], 404);
        }

        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $participant->id,
                'user_id' => $participant->user_id,
                'content' => $participant->content,
                'media' => $participant->media,
                'media_urls' => $participant->media_urls,
                'first_media' => $participant->first_media,
                'status' => $participant->status,
                'user' => [
                    'id' => $participant->user->id,
                    'username' => $participant->user->username,
                    'first_name' => $participant->user->first_name,
                    'last_name' => $participant->user->last_name,
                    'avatar' => $participant->user->avatar,
                    'is_verified' => $participant->user->is_verified ?? false,
                ],
                'total_votes' => $participant->total_votes,
                'wins' => $participant->wins,
                'losses' => $participant->losses,
                'is_current_user' => $user && $participant->user_id === $user->id,
                'created_at' => $participant->created_at,
                'updated_at' => $participant->updated_at,
            ],
        ]);
    }

    /**
     * Récupérer les détails d'un duel
     * Utilise DUEL.ID
     */
    public function getDuel(Request $request, int $duelId): JsonResponse
    {
        $duel = ChallengeDuel::with(['participantA.user', 'participantB.user'])
            ->find($duelId);

        if (!$duel) {
            return response()->json(['success' => false, 'message' => 'Duel not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $duel->id,
                'participant_a_id' => $duel->participant_a_id,
                'participant_b_id' => $duel->participant_b_id,
                'votes_a' => $duel->votes_a,
                'votes_b' => $duel->votes_b,
                'total_votes' => $duel->total_votes,
                'winner' => $duel->winner,
                'end_date' => $duel->end_date,
                'time_left' => $duel->time_left,
                'is_ended' => $duel->isEnded(),
                'participant_a' => $duel->participantA ? [
                    'id' => $duel->participantA->id,
                    'user_id' => $duel->participantA->user_id,
                    'user' => $duel->participantA->user ? [
                        'id' => $duel->participantA->user->id,
                        'username' => $duel->participantA->user->username,
                        'first_name' => $duel->participantA->user->first_name,
                        'last_name' => $duel->participantA->user->last_name,
                        'avatar' => $duel->participantA->user->avatar,
                        'is_verified' => $duel->participantA->user->is_verified ?? false,
                    ] : null,
                ] : null,
                'participant_b' => $duel->participantB ? [
                    'id' => $duel->participantB->id,
                    'user_id' => $duel->participantB->user_id,
                    'user' => $duel->participantB->user ? [
                        'id' => $duel->participantB->user->id,
                        'username' => $duel->participantB->user->username,
                        'first_name' => $duel->participantB->user->first_name,
                        'last_name' => $duel->participantB->user->last_name,
                        'avatar' => $duel->participantB->user->avatar,
                        'is_verified' => $duel->participantB->user->is_verified ?? false,
                    ] : null,
                ] : null,
                'created_at' => $duel->created_at,
            ],
        ]);
    }

    /**
     * Rejoindre un challenge
     * Utilise POST.ID
     */
    public function join(Request $request, int $postId): JsonResponse
    {
        $user = $request->user();
        
        $post = Post::find($postId);
        if (!$post || $post->type !== 'challenge') {
            return response()->json(['success' => false, 'message' => 'Challenge not found.'], 404);
        }

        $challenge = $post->challenge;
        if (!$challenge) {
            return response()->json(['success' => false, 'message' => 'Challenge data not found.'], 404);
        }

        // Vérifier si le challenge est terminé
        if ($challenge->isEnded()) {
            return response()->json(['success' => false, 'message' => 'Ce challenge est terminé.'], 422);
        }

        // Vérifier si l'utilisateur a déjà rejoint
        $existing = ChallengeParticipant::where('challenge_id', $challenge->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            return response()->json(['success' => false, 'message' => 'Vous avez déjà rejoint ce challenge.'], 422);
        }

        // Vérifier le nombre max de participants
        if ($challenge->max_participants) {
            $count = ChallengeParticipant::where('challenge_id', $challenge->id)->count();
            if ($count >= $challenge->max_participants) {
                return response()->json(['success' => false, 'message' => 'Le challenge est complet.'], 422);
            }
        }

        $validated = $request->validate([
            'content' => 'nullable|string|max:1000',
            'media' => 'nullable|array',
            'media.*.type' => 'required|in:image,video,audio',
            'media.*.url' => 'nullable|string',
            'media.*.file' => 'nullable|file|max:20480',
        ]);

        // Traiter les médias si présents
        $media = null;
        if (!empty($validated['media'])) {
            $media = $this->processChallengeMedia($validated['media']);
        }

        $participant = ChallengeParticipant::create([
            'challenge_id' => $challenge->id,
            'user_id' => $user->id,
            'content' => $validated['content'] ?? null,
            'media' => $media,
            'status' => 'active',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Vous avez rejoint le challenge !',
            'data' => $participant->load('user'),
        ], 201);
    }

    /**
     * Quitter un challenge
     * Utilise POST.ID
     */
    public function leave(Request $request, int $postId): JsonResponse
    {
        $user = $request->user();
        
        $post = Post::find($postId);
        if (!$post || $post->type !== 'challenge') {
            return response()->json(['success' => false, 'message' => 'Challenge not found.'], 404);
        }

        $challenge = $post->challenge;
        if (!$challenge) {
            return response()->json(['success' => false, 'message' => 'Challenge data not found.'], 404);
        }

        $participant = ChallengeParticipant::where('challenge_id', $challenge->id)
            ->where('user_id', $user->id)
            ->first();

        if (!$participant) {
            return response()->json(['success' => false, 'message' => 'Vous n\'avez pas rejoint ce challenge.'], 422);
        }

        // Vérifier si le participant a des duels en cours
        $activeDuels = ChallengeDuel::where('challenge_id', $challenge->id)
            ->where(function ($query) use ($participant) {
                $query->where('participant_a_id', $participant->id)
                    ->orWhere('participant_b_id', $participant->id);
            })
            ->whereNull('winner')
            ->count();

        if ($activeDuels > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne pouvez pas quitter le challenge car vous avez des duels en cours.',
            ], 422);
        }

        $participant->delete();

        return response()->json([
            'success' => true,
            'message' => 'Vous avez quitté le challenge.',
        ]);
    }

    /**
     * Traiter les médias d'un participant
     */
    private function processChallengeMedia(array $mediaItems): array
    {
        $processed = [];
        foreach ($mediaItems as $mediaItem) {
            if (isset($mediaItem['file']) && $mediaItem['file'] instanceof \Illuminate\Http\UploadedFile) {
                $path = $mediaItem['file']->store('challenges', 'public');
                $processed[] = [
                    'type' => $mediaItem['type'] ?? 'image',
                    'url' => $path,
                ];
            } elseif (isset($mediaItem['url'])) {
                $processed[] = [
                    'type' => $mediaItem['type'] ?? 'image',
                    'url' => $mediaItem['url'],
                ];
            }
        }
        return $processed;
    }
}