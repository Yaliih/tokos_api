<?php
// app/Http/Controllers/Api/SpacePostController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Space;
use App\Models\SpaceActivity;
use App\Models\Post;
use App\Models\PostLike;
use App\Models\PostComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpacePostController extends Controller
{
    /**
     * Liste des posts d'un espace
     */
    public function index(string $slug): JsonResponse
    {
        $space = Space::where('slug', $slug)->first();
        if (!$space) {
            return response()->json(['success' => false, 'message' => __('Space not found.')], 404);
        }

        $posts = Post::with(['author', 'media', 'duel', 'contest', 'debate', 'challenge'])
            ->withCount(['likes', 'comments'])
            ->where('authorable_type', Space::class)
            ->where('authorable_id', $space->id)
            ->where('status', 'published')
            ->orderBy('is_pinned', 'desc')
            ->orderBy('published_at', 'desc')
            ->paginate(20);

        if (auth()->check()) {
            $posts->getCollection()->each(function ($post) {
                $post->is_liked_by_user = $post->likes()->where('user_id', auth()->id())->exists();
            });
        }

        return response()->json([
            'success' => true,
            'data' => $posts,
        ]);
    }

    /**
     * Créer un post dans un espace
     */
    public function store(Request $request, string $slug): JsonResponse
    {
        $space = Space::where('slug', $slug)->first();
        if (!$space) {
            return response()->json(['success' => false, 'message' => __('Space not found.')], 404);
        }

        $user = $request->user();
        if (!$space->canPost($user)) {
            return response()->json(['success' => false, 'message' => __('You cannot post in this space.')], 403);
        }

        $validated = $request->validate([
            'content' => 'nullable|string|max:5000',
            'type' => 'nullable|in:post,duel,contest,challenge,announcement',
            'media' => 'nullable|array',
            'media.*.type' => 'required|in:image,video,audio,pdf,other',
            'media.*.url' => 'required|string|max:2048',
            'is_pinned' => 'boolean',
            'is_featured' => 'boolean',
            // Duel
            'option_a' => 'required_if:type,duel|string',
            'option_b' => 'required_if:type,duel|string',
            'option_a_media' => 'nullable|array',
            'option_b_media' => 'nullable|array',
            'end_date' => 'nullable|date|after:now',
            // Contest
            'contest_data' => 'nullable|array',
            // Challenge
            'challenge_description' => 'required_if:type,challenge|string',
            'max_participants' => 'nullable|integer|min:1',
            'votes_to_win' => 'nullable|integer|min:1',
            // Debate
            'topic' => 'required_if:type,debate|string',
            'for_argument' => 'required_if:type,debate|string',
            'against_argument' => 'required_if:type,debate|string',
        ]);

        // Créer le post
        $post = Post::create([
            'authorable_type' => Space::class,
            'authorable_id' => $space->id,
            'user_id' => $user->id,
            'type' => $validated['type'] ?? 'post',
            'content' => $validated['content'] ?? null,
            'media' => $validated['media'] ?? null,
            'is_pinned' => $validated['is_pinned'] ?? false,
            'is_featured' => $validated['is_featured'] ?? false,
            'published_at' => now(),
        ]);

        // Créer les données spécifiques
        $this->createTypeData($post, $validated);

        SpaceActivity::create([
            'space_id' => $space->id,
            'user_id' => $user->id,
            'type' => 'post_created',
            'description' => "{$user->full_name} posted in {$space->name}",
            'metadata' => ['post_id' => $post->id],
        ]);

        return response()->json([
            'success' => true,
            'message' => __('Post created successfully.'),
            'data' => $post->load('author'),
        ], 201);
    }

    /**
     * Créer les données spécifiques
     */
    private function createTypeData(Post $post, array $data): void
    {
        switch ($post->type) {
            case 'duel':
                $post->duel()->create([
                    'option_a' => $data['option_a'],
                    'option_b' => $data['option_b'],
                    'option_a_media' => $data['option_a_media'] ?? null,
                    'option_b_media' => $data['option_b_media'] ?? null,
                    'end_date' => $data['end_date'] ?? null,
                ]);
                break;

            case 'contest':
                $contestData = $data['contest_data'] ?? [];
                $post->contest()->create([
                    'title' => $contestData['title'] ?? '',
                    'description' => $contestData['description'] ?? null,
                    'prize' => $contestData['prize'] ?? null,
                    'category' => $contestData['category'] ?? null,
                    'mode' => $contestData['mode'] ?? 'simple',
                    'has_jury' => $contestData['has_jury'] ?? false,
                    'jury_percentage' => $contestData['jury_percentage'] ?? 0,
                    'jury_members' => $contestData['jury_members'] ?? null,
                    'vote_type' => $contestData['vote_type'] ?? 'single',
                    'vote_cost' => $contestData['vote_cost'] ?? 0,
                    'vote_start_at' => $data['start_date'] ?? null,
                    'vote_end_at' => $data['end_date'] ?? null,
                ]);
                break;

            case 'debate':
                $post->debate()->create([
                    'topic' => $data['topic'],
                    'for_argument' => $data['for_argument'],
                    'against_argument' => $data['against_argument'],
                    'end_date' => $data['end_date'] ?? null,
                ]);
                break;

            case 'challenge':
                $post->challenge()->create([
                    'description' => $data['challenge_description'],
                    'max_participants' => $data['max_participants'] ?? null,
                    'votes_to_win' => $data['votes_to_win'] ?? 10,
                    'end_date' => $data['end_date'] ?? null,
                ]);
                break;
        }
    }

    /**
     * Mettre à jour un post
     */
    public function update(Request $request, string $slug, int $postId): JsonResponse
    {
        $space = Space::where('slug', $slug)->first();
        if (!$space) {
            return response()->json(['success' => false, 'message' => __('Space not found.')], 404);
        }

        $user = $request->user();
        $post = $space->posts()->where('id', $postId)->first();

        if (!$post) {
            return response()->json(['success' => false, 'message' => __('Post not found.')], 404);
        }

        if (!$space->canManage($user) && $post->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => __('You cannot edit this post.')], 403);
        }

        $validated = $request->validate([
            'content' => 'nullable|string|max:5000',
            'media' => 'nullable|array',
            'is_pinned' => 'boolean',
            'is_featured' => 'boolean',
        ]);

        $post->update($validated);

        return response()->json([
            'success' => true,
            'message' => __('Post updated.'),
            'data' => $post,
        ]);
    }

    /**
     * Supprimer un post
     */
    public function destroy(Request $request, string $slug, int $postId): JsonResponse
    {
        $space = Space::where('slug', $slug)->first();
        if (!$space) {
            return response()->json(['success' => false, 'message' => __('Space not found.')], 404);
        }

        $user = $request->user();
        $post = $space->posts()->where('id', $postId)->first();

        if (!$post) {
            return response()->json(['success' => false, 'message' => __('Post not found.')], 404);
        }

        if (!$space->canManage($user) && $post->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => __('You cannot delete this post.')], 403);
        }

        $post->delete();

        SpaceActivity::create([
            'space_id' => $space->id,
            'user_id' => $user->id,
            'type' => 'post_deleted',
            'description' => "A post was deleted in {$space->name}",
        ]);

        return response()->json([
            'success' => true,
            'message' => __('Post deleted.'),
        ]);
    }

    /**
     * Liker un post
     */
    public function like(Request $request, string $slug, int $postId): JsonResponse
    {
        $space = Space::where('slug', $slug)->first();
        if (!$space) {
            return response()->json(['success' => false, 'message' => __('Space not found.')], 404);
        }

        $user = $request->user();
        $post = $space->posts()->where('id', $postId)->first();

        if (!$post) {
            return response()->json(['success' => false, 'message' => __('Post not found.')], 404);
        }

        $existingLike = PostLike::where('post_id', $postId)
            ->where('user_id', $user->id)
            ->first();

        if ($existingLike) {
            $existingLike->delete();
            $liked = false;
        } else {
            PostLike::create([
                'post_id' => $postId,
                'user_id' => $user->id,
            ]);
            $liked = true;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'liked' => $liked,
                'likes_count' => $post->likes()->count(),
            ]
        ]);
    }

    /**
     * Commenter un post
     */
    public function comment(Request $request, string $slug, int $postId): JsonResponse
    {
        $space = Space::where('slug', $slug)->first();
        if (!$space) {
            return response()->json(['success' => false, 'message' => __('Space not found.')], 404);
        }

        $user = $request->user();
        $post = $space->posts()->where('id', $postId)->first();

        if (!$post) {
            return response()->json(['success' => false, 'message' => __('Post not found.')], 404);
        }

        $validated = $request->validate([
            'content' => 'required|string|max:1000',
            'parent_id' => 'nullable|exists:post_comments,id',
        ]);

        $comment = PostComment::create([
            'post_id' => $postId,
            'user_id' => $user->id,
            'content' => $validated['content'],
            'parent_id' => $validated['parent_id'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => __('Comment added.'),
            'data' => $comment->load('author'),
        ], 201);
    }

    /**
     * Supprimer un commentaire
     */
    public function deleteComment(Request $request, string $slug, int $postId, int $commentId): JsonResponse
    {
        $space = Space::where('slug', $slug)->first();
        if (!$space) {
            return response()->json(['success' => false, 'message' => __('Space not found.')], 404);
        }

        $user = $request->user();
        $post = $space->posts()->where('id', $postId)->first();

        if (!$post) {
            return response()->json(['success' => false, 'message' => __('Post not found.')], 404);
        }

        $comment = $post->comments()->where('id', $commentId)->first();
        if (!$comment) {
            return response()->json(['success' => false, 'message' => __('Comment not found.')], 404);
        }

        if (!$space->canManage($user) && $comment->user_id !== $user->id) {
            return response()->json(['success' => false, 'message' => __('You cannot delete this comment.')], 403);
        }

        $comment->delete();

        return response()->json([
            'success' => true,
            'message' => __('Comment deleted.'),
        ]);
    }
}