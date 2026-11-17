<?php
// app/Services/RecommendationService.php

namespace App\Services;

use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class RecommendationService
{
    /**
     * Retourne les publications par ordre purement chronologique.
     */
    public function getPersonalizedFeed(
        ?User $user,
        int $page = 1,
        int $perPage = 20,
        ?string $type = null
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 50));

        try {
            $query = Post::query()
                ->with([
                    'author',
                    'mediaItems',
                    'duel',
                    'debate',
                    'challenge' => fn ($q) => $q->withCount('participants'),
                    'contest' => fn ($q) => $q->withCount([
                        'participants as entries_count',
                        'candidates',
                    ])->withSum(
                        'candidates as total_votes_count',
                        'total_votes'
                    ),
                ])
                ->withCount([
                    'likes',
                    'comments',
                    'shares',
                    'bookmarks',
                    'views',
                ])
                ->published()
                ->where('status', 'published')
                // Les anciens formats (duel / challenge / contest) sont remplacés par le moteur de compétition
                ->whereNotIn('type', ['duel', 'challenge', 'contest'])
                // Onglets de l'app : "duels" = duels + tournois, "challenges" = défis + concours
                ->when(in_array($type, ['duels', 'challenges'], true), function ($q) use ($type) {
                    $types = $type === 'duels' ? ['duel', 'tournament'] : ['challenge', 'contest'];
                    $q->where('type', 'competition')
                        ->whereIn('id', \App\Models\Competition::whereIn('type', $types)->whereNotNull('post_id')->select('post_id'));
                })
                ->whereNotNull('published_at')
                ->orderByDesc('published_at');

            // Si l'utilisateur est connecté, on ajoute les vérifications de likes/bookmarks
            if ($user) {
                $userId = $user->id;
                $query->withExists([
                    'likes as is_liked_by_user' => fn ($l) => $l->where('user_id', $userId),
                    'bookmarks as is_bookmarked_by_user' => fn ($b) => $b->where('user_id', $userId),
                ]);
            }

            $paginator = $query->paginate($perPage, ['*'], 'page', $page);

            $items = collect($paginator->items())->each(function (Post $post) use ($user) {
                if ($post->type === 'competition') {
                    $competition = \App\Models\Competition::where('post_id', $post->id)->first();
                    $post->setAttribute('competition', $competition ? \App\Support\Presenter::competition($competition, $user) : null);
                }
            });

            return [
                'data' => $items->values(),
                'total' => $paginator->total(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
            ];
        } catch (\Throwable $exception) {
            Log::error('Feed query failed', [
                'message' => $exception->getMessage(),
            ]);

            return [
                'data' => collect(),
                'total' => 0,
                'current_page' => $page,
                'last_page' => 1,
                'per_page' => $perPage,
            ];
        }
    }

    public function getReels(?User $user): array
    {
        // Conserve la logique de vos reels si nécessaire
        try {
            $challenges = Post::query()
                ->with([
                    'author',
                    'mediaItems',
                    'challenge' => fn ($query) => $query->withCount('participants'),
                ])
                ->where('type', 'challenge')
                ->where('status', 'published')
                ->whereHas('challenge.participants')
                ->latest('published_at')
                ->limit(10)
                ->get()
                ->map(function (Post $post): array {
                    return [
                        'id' => $post->id,
                        'type' => 'challenge_reel',
                        'title' => $post->content ?? 'Challenge',
                        'media' => $post->mediaItems?->first(),
                        'author' => $post->author,
                        'participants_count' => (int) ($post->challenge?->participants_count ?? 0),
                        'timeLeft' => $post->challenge?->timeLeft ?? null,
                    ];
                });

            return [
                'challenges' => $challenges,
                'participants' => collect(),
            ];
        } catch (\Throwable) {
            return [
                'challenges' => collect(),
                'participants' => collect(),
            ];
        }
    }
}