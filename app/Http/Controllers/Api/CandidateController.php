<?php
// app/Http/Controllers/Api/CandidateController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CandidateComment;
use App\Models\CandidateCommentLike;
use App\Models\CandidateVote;
use App\Models\ContestCandidate;
use App\Models\Post;
use App\Models\Space;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CandidateController extends Controller
{
    /**
     * Récupérer les candidats d'un concours
     */
    public function index(int $postId): JsonResponse
    {
        $post = Post::find($postId);
        if (!$post) {
            return response()->json(['success' => false, 'message' => 'Post not found.'], 404);
        }

        $candidates = ContestCandidate::with(['user', 'votes'])
            ->where('post_id', $postId)
            ->active()
            ->ordered()
            ->get()
            ->map(function ($candidate) {
                $candidate->total_votes = $candidate->votes()->sum('value');
                $candidate->user_voted = auth()->check() ? $candidate->hasUserVoted(auth()->id()) : false;
                return $candidate;
            });

        return response()->json([
            'success' => true,
            'data' => $candidates,
        ]);
    }

    /**
     * Voter pour un candidat
     */
    public function vote(Request $request, int $candidateId): JsonResponse
    {
        $candidate = ContestCandidate::find($candidateId);
        if (!$candidate) {
            return response()->json(['success' => false, 'message' => 'Candidate not found.'], 404);
        }

        $contest = $candidate->post->contest;
        if ($contest) {
            $now = now();
            if ($contest->vote_start_at && $now < $contest->vote_start_at) {
                return response()->json(['success' => false, 'message' => 'Voting has not started yet.'], 422);
            }
            if ($contest->vote_end_at && $now > $contest->vote_end_at) {
                return response()->json(['success' => false, 'message' => 'Voting has ended.'], 422);
            }
        }

        $validated = $request->validate([
            'phone_number' => 'required_without:user_id|string',
            'phone_code' => 'required_with:phone_number|string',
            'user_id' => 'required_without:phone_number|exists:users,id',
            'value' => 'nullable|integer|min:1',
        ]);

        $value = $validated['value'] ?? 1;
        $ip = $request->ip();

        if (isset($validated['user_id'])) {
            if (CandidateVote::canVote($candidateId, $validated['user_id'])) {
                $vote = CandidateVote::createUser($candidateId, $validated['user_id'], $value, $ip);
            } else {
                return response()->json(['success' => false, 'message' => 'You already voted for this candidate.'], 422);
            }
        } else {
            $phoneNumber = $validated['phone_code'] . $validated['phone_number'];
            if (CandidateVote::canVote($candidateId, $phoneNumber)) {
                $vote = CandidateVote::createAnonymous($candidateId, $validated['phone_number'], $validated['phone_code'], $value, $ip);
            } else {
                return response()->json(['success' => false, 'message' => 'This phone number already voted.'], 422);
            }
        }

        $candidate->increment('score', $value);

        return response()->json([
            'success' => true,
            'message' => 'Vote recorded.',
            'data' => [
                'candidate' => $candidate,
                'total_votes' => $candidate->votes()->sum('value'),
                'score' => $candidate->score,
            ]
        ]);
    }

    /**
     * Commenter un candidat
     */
    public function comment(Request $request, int $candidateId): JsonResponse
    {
        $candidate = ContestCandidate::find($candidateId);
        if (!$candidate) {
            return response()->json(['success' => false, 'message' => 'Candidate not found.'], 404);
        }

        $validated = $request->validate([
            'content' => 'required|string|max:1000',
            'parent_id' => 'nullable|exists:candidate_comments,id',
            'phone_number' => 'required_without:user_id|string',
            'phone_code' => 'required_with:phone_number|string',
            'user_id' => 'required_without:phone_number|exists:users,id',
        ]);

        if (isset($validated['parent_id'])) {
            $parent = CandidateComment::find($validated['parent_id']);
            if (!$parent || $parent->candidate_id !== $candidateId) {
                return response()->json(['success' => false, 'message' => 'Parent comment not found for this candidate.'], 422);
            }
        }

        $comment = CandidateComment::create([
            'candidate_id' => $candidateId,
            'user_id' => $validated['user_id'] ?? null,
            'phone_number' => $validated['phone_number'] ?? null,
            'phone_code' => $validated['phone_code'] ?? null,
            'content' => $validated['content'],
            'parent_id' => $validated['parent_id'] ?? null,
            'is_anonymous' => !isset($validated['user_id']),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Comment added.',
            'data' => $comment->load('user', 'replies'),
        ], 201);
    }

    /**
     * Répondre à un commentaire de candidat
     */
    public function replyComment(Request $request, int $commentId): JsonResponse
    {
        $parentComment = CandidateComment::find($commentId);

        if (!$parentComment) {
            return response()->json(['success' => false, 'message' => 'Comment not found.'], 404);
        }

        $validated = $request->validate([
            'content' => 'required|string|max:1000',
            'phone_number' => 'required_without:user_id|string',
            'phone_code' => 'required_with:phone_number|string',
            'user_id' => 'required_without:phone_number|exists:users,id',
        ]);

        $reply = CandidateComment::create([
            'candidate_id' => $parentComment->candidate_id,
            'user_id' => $validated['user_id'] ?? null,
            'phone_number' => $validated['phone_number'] ?? null,
            'phone_code' => $validated['phone_code'] ?? null,
            'content' => $validated['content'],
            'parent_id' => $commentId,
            'is_anonymous' => !isset($validated['user_id']),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Reply added.',
            'data' => $reply->load('user', 'replies'),
        ], 201);
    }

    /**
     * Liker un commentaire de candidat
     */
    public function likeComment(Request $request, int $commentId): JsonResponse
    {
        $comment = CandidateComment::find($commentId);
        if (!$comment) {
            return response()->json(['success' => false, 'message' => 'Comment not found.'], 404);
        }

        $validated = $request->validate([
            'phone_number' => 'required_without:user_id|string',
            'phone_code' => 'required_with:phone_number|string',
            'user_id' => 'required_without:phone_number|exists:users,id',
        ]);

        $identifier = $validated['user_id'] ?? ($validated['phone_code'] . $validated['phone_number']);

        $existingLike = CandidateCommentLike::where('comment_id', $commentId)
            ->where(function($q) use ($identifier, $validated) {
                if (isset($validated['user_id'])) {
                    $q->where('user_id', $validated['user_id']);
                } else {
                    $q->where('phone_number', $validated['phone_number']);
                }
            })->first();

        if ($existingLike) {
            $existingLike->delete();
            $liked = false;
        } else {
            CandidateCommentLike::create([
                'comment_id' => $commentId,
                'user_id' => $validated['user_id'] ?? null,
                'phone_number' => $validated['phone_number'] ?? null,
                'phone_code' => $validated['phone_code'] ?? null,
                'is_anonymous' => !isset($validated['user_id']),
            ]);
            $liked = true;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'liked' => $liked,
                'likes_count' => $comment->likes()->count(),
            ]
        ]);
    }

    /**
     * Récupérer les commentaires d'un candidat avec réponses
     */
    public function getComments(int $candidateId): JsonResponse
    {
        $candidate = ContestCandidate::find($candidateId);
        if (!$candidate) {
            return response()->json(['success' => false, 'message' => 'Candidate not found.'], 404);
        }

        $comments = CandidateComment::with([
            'user',
            'likes',
            'replies' => function ($q) {
                $q->with([
                    'user',
                    'likes',
                    'replies' => function ($q2) {
                        $q2->with(['user', 'likes'])
                           ->withCount(['likes', 'replies'])
                           ->orderBy('created_at', 'asc');
                    }
                ])
                ->withCount(['likes', 'replies'])
                ->orderBy('created_at', 'asc');
            }
        ])
        ->withCount(['likes', 'replies'])
        ->where('candidate_id', $candidateId)
        ->whereNull('parent_id')
        ->orderBy('created_at', 'desc')
        ->paginate(20);

        if (auth()->check()) {
            $userId = auth()->id();
            $comments->getCollection()->each(function ($comment) use ($userId) {
                $comment->is_liked_by_user = $comment->likes()->where('user_id', $userId)->exists();
                $comment->replies->each(function ($reply) use ($userId) {
                    $reply->is_liked_by_user = $reply->likes()->where('user_id', $userId)->exists();
                    $reply->replies->each(function ($subReply) use ($userId) {
                        $subReply->is_liked_by_user = $subReply->likes()->where('user_id', $userId)->exists();
                    });
                });
            });
        }

        return response()->json([
            'success' => true,
            'data' => $comments,
        ]);
    }

    /**
     * Supprimer un commentaire de candidat
     */
    public function deleteComment(Request $request, int $commentId): JsonResponse
    {
        $comment = CandidateComment::find($commentId);
        if (!$comment) {
            return response()->json(['success' => false, 'message' => 'Comment not found.'], 404);
        }

        $user = $request->user();
        $isAuthor = $comment->user_id === $user->id;
        $isAdmin = false;

        $candidate = ContestCandidate::find($comment->candidate_id);
        if ($candidate) {
            $post = Post::find($candidate->post_id);
            if ($post && $post->authorable_type === Space::class) {
                $space = Space::find($post->authorable_id);
                if ($space) {
                    $isAdmin = $space->isAdmin($user);
                }
            }
        }

        if (!$isAuthor && !$isAdmin) {
            return response()->json(['success' => false, 'message' => 'You cannot delete this comment.'], 403);
        }

        $comment->replies()->delete();
        $comment->likes()->delete();
        $comment->delete();

        return response()->json([
            'success' => true,
            'message' => 'Comment deleted.',
        ]);
    }
}