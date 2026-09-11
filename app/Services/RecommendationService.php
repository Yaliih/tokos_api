<?php
// app/Services/RecommendationService.php

namespace App\Services;

use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class RecommendationService
{
    private const CANDIDATE_POOL_SIZE = 150;

    /**
     * Construit le feed sans requêtes SQL à l'intérieur de la boucle de score.
     */
    public function getPersonalizedFeed(
        ?User $user,
        int $page = 1,
        int $perPage = 20
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 50));

        $context = $this->buildUserContext($user);
        $posts = $this->getAvailablePosts($user);

        if ($posts->isEmpty()) {
            return $this->emptyFeed($page, $perPage);
        }

        $scored = $posts
            ->map(function (Post $post) use ($context): array {
                return [
                    'post' => $post,
                    'score' => $this->calculateScore($post, $context),
                ];
            })
            ->sortByDesc('score')
            ->values();

        $feed = $this->applyRatios($scored, $context['interaction_count']);

        if ($feed->isEmpty()) {
            $feed = $scored;
        }

        $total = $feed->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $safePage = min($page, $lastPage);
        $offset = ($safePage - 1) * $perPage;

        return [
            'data' => $feed
                ->slice($offset, $perPage)
                ->pluck('post')
                ->values(),
            'total' => $total,
            'current_page' => $safePage,
            'last_page' => $lastPage,
            'per_page' => $perPage,
        ];
    }

    private function emptyFeed(int $page, int $perPage): array
    {
        return [
            'data' => collect(),
            'total' => 0,
            'current_page' => $page,
            'last_page' => 1,
            'per_page' => $perPage,
        ];
    }

    /**
     * Toutes les informations utilisateur sont récupérées une seule fois.
     */
    private function buildUserContext(?User $user): array
    {
        if (!$user) {
            return [
                'user_id' => null,
                'preferences' => [],
                'followed_author_ids' => [],
                'liked_author_ids' => [],
                'commented_author_ids' => [],
                'joined_space_ids' => [],
                'viewed_post_ids' => [],
                'interaction_count' => 0,
            ];
        }

        try {
            $preferences = $user->preferences()
                ->pluck('name')
                ->map(fn ($value) => mb_strtolower((string) $value))
                ->filter()
                ->values()
                ->all();
        } catch (\Throwable) {
            $preferences = [];
        }

        try {
            $followedAuthorIds = $user->following()
                ->pluck('users.id')
                ->map(fn ($id) => (int) $id)
                ->all();
        } catch (\Throwable) {
            $followedAuthorIds = [];
        }

        try {
            $joinedSpaceIds = $user->joinedSpaces()
                ->pluck('spaces.id')
                ->map(fn ($id) => (int) $id)
                ->all();
        } catch (\Throwable) {
            $joinedSpaceIds = [];
        }

        $likedAuthorIds = DB::table('post_likes')
            ->join('posts', 'post_likes.post_id', '=', 'posts.id')
            ->where('post_likes.user_id', $user->id)
            ->whereNotNull('posts.user_id')
            ->distinct()
            ->pluck('posts.user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $commentedAuthorIds = DB::table('post_comments')
            ->join('posts', 'post_comments.post_id', '=', 'posts.id')
            ->where('post_comments.user_id', $user->id)
            ->whereNotNull('posts.user_id')
            ->distinct()
            ->pluck('posts.user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $viewedPostIds = DB::table('post_views')
            ->where('user_id', $user->id)
            ->pluck('post_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [
            'user_id' => (int) $user->id,
            'preferences' => array_fill_keys($preferences, true),
            'followed_author_ids' => array_fill_keys($followedAuthorIds, true),
            'liked_author_ids' => array_fill_keys($likedAuthorIds, true),
            'commented_author_ids' => array_fill_keys($commentedAuthorIds, true),
            'joined_space_ids' => array_fill_keys($joinedSpaceIds, true),
            'viewed_post_ids' => array_fill_keys($viewedPostIds, true),
            'interaction_count' => $this->getUserInteractionCount($user),
        ];
    }

    /**
     * Aucune relation lourde de participants n'est chargée dans le feed.
     */
    private function getAvailablePosts(?User $user): Collection
    {
        try {
            $query = Post::query()
                ->with([
                    'author',
                    'mediaItems',
                    'duel',
                    'debate',
                    'challenge' => fn ($query) =>
                        $query->withCount('participants'),
                    'contest' => fn ($query) =>
                        $query
                            ->withCount([
                                'participants as entries_count',
                                'candidates',
                            ])
                            ->withSum(
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
                ->whereNotNull('published_at')
                ->orderByDesc('published_at')
                ->limit(self::CANDIDATE_POOL_SIZE);

            if ($user) {
                $userId = $user->id;

                $query
                    ->withExists([
                        'likes as is_liked_by_user' => fn ($likeQuery) =>
                            $likeQuery->where('user_id', $userId),
                        'bookmarks as is_bookmarked_by_user' => fn ($bookmarkQuery) =>
                            $bookmarkQuery->where('user_id', $userId),
                    ]);
            }

            return $query->get();
        } catch (\Throwable $exception) {
            Log::error('Recommendation feed query failed', [
                'message' => $exception->getMessage(),
            ]);

            return collect();
        }
    }

    private function calculateScore(Post $post, array $context): float
    {
        $score =
            ($this->getPreferenceScore($post, $context) * 0.20) +
            ($this->getInteractionScore($post, $context) * 0.20) +
            ($this->getPopularityScore($post) * 0.20) +
            ($this->getFreshnessScore($post) * 0.25) +
            ($this->getSocialScore($post, $context) * 0.15);

        $score += $this->getTypeBoost($post);

        if ($this->postHasMedia($post)) {
            $score += 0.08;
        }

        if ($this->isActiveInteractivePost($post)) {
            $score += 0.15;
        }

        if (
            $context['interaction_count'] < 30 &&
            $this->getFreshnessScore($post) >= 0.50
        ) {
            $score += 0.12;
        }

        if (!isset($context['viewed_post_ids'][(int) $post->id])) {
            $score += 0.25;
        } else {
            $score -= 0.10;
        }

        // Petit bruit déterministe : le classement ne change pas à chaque requête.
        $score += ((int) $post->id % 17) / 1000;

        return max(0.0, min(2.0, $score));
    }

    private function getPreferenceScore(Post $post, array $context): float
    {
        if (empty($context['preferences'])) {
            return 0.50;
        }

        $category = mb_strtolower($this->extractCategory($post));
        $content = mb_strtolower((string) ($post->content ?? ''));

        foreach ($context['preferences'] as $preference => $_) {
            if (
                str_contains($category, $preference) ||
                str_contains($content, $preference)
            ) {
                return 1.0;
            }
        }

        return 0.35;
    }

    private function getInteractionScore(Post $post, array $context): float
    {
        $authorId = (int) ($post->user_id ?? 0);

        if (isset($context['followed_author_ids'][$authorId])) {
            return 0.90;
        }

        if (isset($context['liked_author_ids'][$authorId])) {
            return 0.70;
        }

        if (isset($context['commented_author_ids'][$authorId])) {
            return 0.65;
        }

        return 0.35;
    }

    private function getPopularityScore(Post $post): float
    {
        $likes = (int) ($post->likes_count ?? 0);
        $comments = (int) ($post->comments_count ?? 0);
        $shares = (int) ($post->shares_count ?? 0);
        $bookmarks = (int) ($post->bookmarks_count ?? 0);
        $views = (int) ($post->views_count ?? 0);
        $participants = (int) (
            $post->challenge?->participants_count ?? 0
        );

        $weighted =
            $likes +
            ($comments * 3) +
            ($shares * 4) +
            ($bookmarks * 3) +
            ($participants * 5) +
            ($views * 0.2);

        return min($weighted / 300, 1.0);
    }

    private function getFreshnessScore(Post $post): float
    {
        if (!$post->published_at) {
            return 0.10;
        }

        $hours = $post->published_at->diffInHours(now());

        return match (true) {
            $hours < 1 => 1.00,
            $hours < 3 => 0.95,
            $hours < 6 => 0.85,
            $hours < 12 => 0.75,
            $hours < 24 => 0.65,
            $hours < 48 => 0.50,
            $hours < 72 => 0.40,
            $hours < 168 => 0.25,
            default => 0.10,
        };
    }

    private function getSocialScore(Post $post, array $context): float
    {
        $score = 0.0;

        if (
            $context['user_id'] &&
            (int) $post->user_id === $context['user_id']
        ) {
            $score += 0.40;
        }

        if (
            $post->authorable_type === 'App\\Models\\Space' &&
            isset(
                $context['joined_space_ids'][(int) $post->authorable_id]
            )
        ) {
            $score += 0.45;
        }

        if (
            isset(
                $context['followed_author_ids'][(int) ($post->user_id ?? 0)]
            )
        ) {
            $score += 0.35;
        }

        return min($score, 1.0);
    }

    private function extractCategory(Post $post): string
    {
        if ($post->contest) {
            return (string) ($post->contest->category ?? 'contest');
        }

        return (string) ($post->type ?? 'post');
    }

    private function applyRatios(
        Collection $sorted,
        int $interactionCount
    ): Collection {
        if ($sorted->isEmpty()) {
            return collect();
        }

        if ($interactionCount < 30) {
            return $this->mergeFeedBuckets([
                $sorted->filter(
                    fn ($item) =>
                        $this->getFreshnessScore($item['post']) >= 0.40
                )->take(40),
                $sorted->filter(
                    fn ($item) =>
                        in_array(
                            $item['post']->type,
                            ['challenge', 'duel', 'contest'],
                            true
                        )
                )->take(30),
                $sorted->take(50),
            ]);
        }

        if ($interactionCount < 200) {
            return $this->mergeFeedBuckets([
                $sorted->take(70),
                $sorted->filter(
                    fn ($item) =>
                        in_array(
                            $item['post']->type,
                            ['challenge', 'duel', 'contest'],
                            true
                        )
                )->take(25),
            ]);
        }

        return $sorted->take(100)->values();
    }

    /**
     * Fusion O(n), sans contains() imbriqué.
     */
    private function mergeFeedBuckets(array $buckets): Collection
    {
        $merged = [];
        $seen = [];

        foreach ($buckets as $bucket) {
            foreach ($bucket as $item) {
                $postId = (int) ($item['post']->id ?? 0);

                if (!$postId || isset($seen[$postId])) {
                    continue;
                }

                $seen[$postId] = true;
                $merged[] = $item;
            }
        }

        return collect($merged)
            ->sortByDesc('score')
            ->values();
    }

    public function getReels(?User $user): array
    {
        try {
            $challenges = Post::query()
                ->with([
                    'author',
                    'mediaItems',
                    'challenge' => fn ($query) =>
                        $query->withCount('participants'),
                ])
                ->where('type', 'challenge')
                ->where('status', 'published')
                ->whereHas('challenge.participants')
                ->latest('published_at')
                ->limit(20)
                ->get()
                ->sortByDesc(
                    fn (Post $post) =>
                        (int) ($post->challenge?->participants_count ?? 0)
                )
                ->take(10)
                ->values()
                ->map(function (Post $post): array {
                    return [
                        'id' => $post->id,
                        'type' => 'challenge_reel',
                        'title' => $post->content ?? 'Challenge',
                        'media' => $post->mediaItems?->first(),
                        'author' => $post->author,
                        'participants_count' =>
                            (int) (
                                $post->challenge?->participants_count ?? 0
                            ),
                        'timeLeft' =>
                            $post->challenge?->timeLeft ?? null,
                    ];
                });

            $participants = collect();

            if (Schema::hasTable('contest_participants')) {
                $participants = DB::table('contest_participants')
                    ->join(
                        'users',
                        'contest_participants.user_id',
                        '=',
                        'users.id'
                    )
                    ->join(
                        'posts',
                        'contest_participants.post_id',
                        '=',
                        'posts.id'
                    )
                    ->where('posts.type', 'contest')
                    ->where('posts.status', 'published')
                    ->whereNotNull('users.avatar')
                    ->orderByDesc('contest_participants.score')
                    ->limit(12)
                    ->get([
                        'users.id',
                        'users.first_name',
                        'users.last_name',
                        'users.username',
                        'users.avatar',
                        'contest_participants.score as participant_score',
                    ]);
            }

            return [
                'challenges' => $challenges,
                'participants' => $participants,
            ];
        } catch (\Throwable $exception) {
            Log::error('Reels generation failed', [
                'message' => $exception->getMessage(),
            ]);

            return [
                'challenges' => collect(),
                'participants' => collect(),
            ];
        }
    }

    private function getUserInteractionCount(User $user): int
    {
        try {
            $likes = DB::table('post_likes')
                ->where('user_id', $user->id)
                ->count();

            $comments = DB::table('post_comments')
                ->where('user_id', $user->id)
                ->count();

            $views = DB::table('post_views')
                ->where('user_id', $user->id)
                ->count();

            $interactions = Schema::hasTable('user_interactions')
                ? DB::table('user_interactions')
                    ->where('user_id', $user->id)
                    ->count()
                : 0;

            return $likes + $comments + $views + $interactions;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function getTypeBoost(Post $post): float
    {
        return match ($post->type) {
            'challenge' => 0.18,
            'duel' => 0.16,
            'contest' => 0.14,
            'debate' => 0.10,
            default => 0.04,
        };
    }

    private function postHasMedia(Post $post): bool
    {
        if ($post->relationLoaded('mediaItems')) {
            return $post->mediaItems->isNotEmpty();
        }

        return is_array($post->media)
            ? count($post->media) > 0
            : !empty($post->media);
    }

    private function isActiveInteractivePost(Post $post): bool
    {
        return match ($post->type) {
            'contest' => $post->contest
                ? (
                    !$post->contest->vote_end_at ||
                    $post->contest->vote_end_at->isFuture()
                )
                : false,
            'challenge' => $post->challenge
                ? (
                    !$post->challenge->end_date ||
                    $post->challenge->end_date->isFuture()
                )
                : false,
            'duel' => $post->duel
                ? (
                    !$post->duel->end_date ||
                    $post->duel->end_date->isFuture()
                )
                : false,
            default => false,
        };
    }
}
