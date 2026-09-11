<?php
// app/Http/Controllers/Api/ParticipationController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostLike;
use App\Models\PostBookmark;
use App\Models\PostShare;
use App\Models\UserInteraction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ParticipationController extends Controller
{
    /**
     * ⭐ Récupérer une participation par son SLUG
     * (Seule méthode qui utilise le SLUG pour le chargement initial)
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $post = Post::with([
            'author',
            'media',
            'participation' => function ($query) {
                $query->with(['challenger', 'challenged']);
            }
        ])
        ->withCount(['likes', 'comments', 'shares', 'bookmarks'])
        ->where('slug', $slug)
        ->where('type', 'participation')
        ->first();

        if (!$post) {
            return response()->json(['success' => false, 'message' => 'Participation not found.'], 404);
        }

        $user = $request->user();
        if ($user) {
            $post->is_liked_by_user = $post->likes()->where('user_id', $user->id)->exists();
            $post->is_bookmarked_by_user = $post->bookmarks()->where('user_id', $user->id)->exists();
        }

        // Formater les données de participation
        $participation = $post->participation;
        $participationData = null;

        if ($participation) {
            $participationData = [
                'id' => $participation->id,
                'challenger' => [
                    'id' => $participation->challenger?->id,
                    'username' => $participation->challenger?->username,
                    'first_name' => $participation->challenger?->first_name,
                    'last_name' => $participation->challenger?->last_name,
                    'avatar' => $participation->challenger?->avatar,
                    'is_verified' => $participation->challenger?->is_verified ?? false,
                ],
                'challenged' => [
                    'id' => $participation->challenged?->id,
                    'username' => $participation->challenged?->username,
                    'first_name' => $participation->challenged?->first_name,
                    'last_name' => $participation->challenged?->last_name,
                    'avatar' => $participation->challenged?->avatar,
                    'is_verified' => $participation->challenged?->is_verified ?? false,
                ],
                'challenge_id' => $participation->challenge_id,
                'duel_id' => $participation->duel_id,
                'challenge_description' => $participation->challenge_description,
                'media' => $participation->media,
                'media_url' => $participation->media ? $this->getMediaUrl($participation->media) : null,
                'status' => $participation->status,
                'created_at' => $participation->created_at,
                'updated_at' => $participation->updated_at,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $post->id,
                'slug' => $post->slug,
                'type' => $post->type,
                'content' => $post->content,
                'created_at' => $post->created_at,
                'authorable' => $post->author,
                'authorable_type' => $post->authorable_type,
                'participation' => $participationData,
                'is_liked_by_user' => $post->is_liked_by_user ?? false,
                'is_bookmarked_by_user' => $post->is_bookmarked_by_user ?? false,
                'likes_count' => $post->likes_count,
                'comments_count' => $post->comments_count,
                'shares_count' => $post->shares_count,
                'bookmarks_count' => $post->bookmarks_count,
            ],
        ]);
    }

    /**
     * Récupérer les commentaires d'une participation
     * Utilise POST.ID
     */
    public function getComments(Request $request, int $postId): JsonResponse
    {
        $post = Post::find($postId);
        if (!$post || $post->type !== 'participation') {
            return response()->json(['success' => false, 'message' => 'Participation not found.'], 404);
        }

        $comments = PostComment::with([
            'author',
            'likes',
            'replies' => function ($q) {
                $q->with(['author', 'likes'])
                    ->withCount(['likes', 'replies'])
                    ->orderBy('created_at', 'asc');
            }
        ])
        ->withCount(['likes', 'replies'])
        ->where('post_id', $post->id)
        ->whereNull('parent_id')
        ->orderBy('created_at', 'desc')
        ->paginate(20);

        $user = $request->user();
        if ($user) {
            $userId = $user->id;
            $comments->getCollection()->each(function ($comment) use ($userId) {
                $comment->is_liked_by_user = $comment->likes()->where('user_id', $userId)->exists();
                $comment->replies->each(function ($reply) use ($userId) {
                    $reply->is_liked_by_user = $reply->likes()->where('user_id', $userId)->exists();
                });
            });
        }

        return response()->json([
            'success' => true,
            'data' => $comments,
        ]);
    }

    /**
     * Liker une participation
     * Utilise POST.ID
     */
    public function like(Request $request, int $postId): JsonResponse
    {
        $user = $request->user();

        $post = Post::find($postId);
        if (!$post || $post->type !== 'participation') {
            return response()->json(['success' => false, 'message' => 'Participation not found.'], 404);
        }

        $userId = $user->id;
        
        $existingLike = PostLike::where('post_id', $post->id)
            ->where('user_id', $userId)
            ->first();

        if ($existingLike) {
            $existingLike->delete();
            $liked = false;
        } else {
            PostLike::create([
                'post_id' => $post->id,
                'user_id' => $userId,
            ]);
            $liked = true;
            
            UserInteraction::record($userId, $post->id, 'like');
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
     * Bookmark une participation
     * Utilise POST.ID
     */
    public function bookmark(Request $request, int $postId): JsonResponse
    {
        $user = $request->user();

        $post = Post::find($postId);
        if (!$post || $post->type !== 'participation') {
            return response()->json(['success' => false, 'message' => 'Participation not found.'], 404);
        }

        $bookmark = PostBookmark::where('post_id', $post->id)
            ->where('user_id', $user->id)
            ->first();

        if ($bookmark) {
            $bookmark->delete();
            $bookmarked = false;
        } else {
            PostBookmark::create([
                'post_id' => $post->id,
                'user_id' => $user->id,
            ]);
            $bookmarked = true;
            UserInteraction::record($user->id, $post->id, 'bookmark');
        }

        return response()->json([
            'success' => true,
            'data' => [
                'bookmarked' => $bookmarked,
                'bookmarks_count' => $post->bookmarks()->count(),
            ]
        ]);
    }

    /**
     * Partager une participation
     * Utilise POST.ID
     */
    public function share(Request $request, int $postId): JsonResponse
    {
        $user = $request->user();

        $post = Post::find($postId);
        if (!$post || $post->type !== 'participation') {
            return response()->json(['success' => false, 'message' => 'Participation not found.'], 404);
        }

        $platform = $request->input('platform', 'web');

        $share = PostShare::create([
            'post_id' => $post->id,
            'user_id' => $user->id,
            'platform' => $platform,
        ]);

        UserInteraction::record($user->id, $post->id, 'share');

        return response()->json([
            'success' => true,
            'data' => [
                'shared' => true,
                'shares_count' => $post->shares()->count(),
            ]
        ]);
    }

    /**
     * Générer l'URL d'un média
     */
    private function getMediaUrl(array $media): ?string
    {
        if (isset($media['url'])) {
            if (filter_var($media['url'], FILTER_VALIDATE_URL)) {
                return $media['url'];
            }
            return route('media', preg_replace('/\.(?=[^.]*$)/', ',', preg_replace('#/#', '&', $media['url'])));
        }
        return null;
    }
}