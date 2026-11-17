<?php
// app/Http/Controllers/Api/VoteController.php
//
// Ne gère plus que les votes de débats. Les votes de duels, défis, concours et
// tournois sont gérés par App\Services\Competition\VoteService (routes /matches et /competitions).

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostVote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VoteController extends Controller
{
    public function voteDebate(Request $request, int $postId): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'side' => 'required|in:for,against',
        ]);

        $post = Post::with('debate')->find($postId);
        if (!$post || !$post->debate) {
            return response()->json(['success' => false, 'message' => __('Debate not found')], 404);
        }

        $debate = $post->debate;

        // Vérifier si le débat est terminé
        if ($debate->end_date && now()->greaterThan($debate->end_date)) {
            return response()->json(['success' => false, 'message' => __('This debate has ended.')], 400);
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
                        'message' => __('Vote removed successfully.'),
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
                    'message' => __('Vote changed successfully.'),
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
                'message' => __('Vote recorded successfully.'),
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
                'message' => 'Impossible d\'enregistrer le vote. Réessaie.',
            ], 500);
        }
    }
}
