<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PostContest extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'title',
        'description',
        'prize',
        'category',
        'mode',
        'max_applications',
        'max_participants',
        'submission_docs',
        'submission_start',
        'submission_end',
        'vote_start_at',
        'vote_end_at',
        'has_jury',
        'jury_percentage',
        'jury_members',
        'vote_type',
        'vote_cost',
        'candidates',
        'categories',
        'rounds',
        'platform_rounds',
        'cover_media',
        'space_slug',
    ];

    protected $casts = [
        'submission_docs' => 'array',
        'jury_members' => 'array',
        'candidates' => 'array',
        'categories' => 'array',
        'rounds' => 'array',
        'platform_rounds' => 'array',
        'cover_media' => 'array',
        'submission_start' => 'datetime',
        'submission_end' => 'datetime',
        'vote_start_at' => 'datetime',
        'vote_end_at' => 'datetime',
        'has_jury' => 'boolean',
        'jury_percentage' => 'integer',
        'max_participants' => 'integer',
        'max_applications' => 'integer',
        'vote_cost' => 'decimal:2',
        'entries_count' => 'integer',
        'total_votes_count' => 'integer',
        'candidates_count' => 'integer',
    ];

    protected $appends = [
        'cover_media_urls',
        'cover_image',
        'deadline_text',
        'status_text',
        'progress_percentage',
        'jury_members_details',
    ];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    public function participants()
    {
        return $this->hasMany(
            ContestParticipant::class,
            'post_id',
            'post_id'
        );
    }

    public function contestRounds()
    {
        return $this->hasMany(
            ContestRound::class,
            'post_id',
            'post_id'
        );
    }

    public function candidates()
    {
        return $this->hasMany(
            ContestCandidate::class,
            'post_id',
            'post_id'
        );
    }

    public function getCoverMediaUrlsAttribute(): array
    {
        return collect($this->cover_media ?? [])
            ->filter(fn ($item) => !empty($item['url']))
            ->map(fn ($item) => [
                'type' => $item['type'] ?? 'image',
                'url' => $this->mediaUrl((string) $item['url']),
            ])
            ->values()
            ->all();
    }

    public function getCoverImageAttribute(): ?string
    {
        return $this->cover_media_urls[0]['url'] ?? null;
    }

    /**
     * Important : aucun count() SQL dans un accesseur.
     * RecommendationService fournit entries_count avec withCount().
     */
    public function getEntriesCountAttribute($value): int
    {
        return (int) (
            $value ??
            $this->attributes['participants_count'] ??
            0
        );
    }

    /**
     * Important : aucun sum() SQL dans un accesseur.
     * RecommendationService fournit total_votes_count avec withSum().
     */
    public function getTotalVotesCountAttribute($value): int
    {
        return (int) ($value ?? 0);
    }

    public function getDeadlineTextAttribute(): ?string
    {
        $end = $this->vote_end_at ?? $this->submission_end;

        if (!$end) return null;
        if ($end->isPast()) return 'Terminé';

        $seconds = now()->diffInSeconds($end);

        if ($seconds >= 86_400) {
            return floor($seconds / 86_400) . 'j restants';
        }

        if ($seconds >= 3_600) {
            return floor($seconds / 3_600) . 'h restantes';
        }

        return max(1, floor($seconds / 60)) . 'm restantes';
    }

    public function getStatusTextAttribute(): string
    {
        $now = now();

        if (
            $this->submission_start &&
            $now->lt($this->submission_start)
        ) {
            return 'upcoming';
        }

        if (
            $this->submission_start &&
            $this->submission_end &&
            $now->between($this->submission_start, $this->submission_end)
        ) {
            return 'submission';
        }

        if ($this->vote_end_at && $now->gt($this->vote_end_at)) {
            return 'ended';
        }

        return 'live';
    }

    public function getProgressPercentageAttribute(): int
    {
        if (!$this->vote_start_at || !$this->vote_end_at) {
            return 0;
        }

        if (now()->lte($this->vote_start_at)) return 0;
        if (now()->gte($this->vote_end_at)) return 100;

        $total = $this->vote_start_at->diffInSeconds($this->vote_end_at);
        $elapsed = $this->vote_start_at->diffInSeconds(now());

        return $total > 0
            ? min(100, (int) round(($elapsed / $total) * 100))
            : 0;
    }


    /**
     * Résout les membres du jury enregistrés par user_id.
     * Les membres manuels restent utilisables avec leur nom/photo.
     */
    public function getJuryMembersDetailsAttribute(): array
    {
        $members = $this->jury_members ?? [];

        $userIds = collect($members)
            ->pluck('user_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $users = $userIds->isEmpty()
            ? collect()
            : User::query()
                ->whereIn('id', $userIds)
                ->get()
                ->keyBy('id');

        return collect($members)->map(function (array $member) use ($users) {
            $storedUserId = !empty($member['user_id'])
                ? (int) $member['user_id']
                : null;

            $user = $storedUserId
                ? $users->get($storedUserId)
                : null;

            if ($user) {
                $liveName = trim((string) ($user->name ?? ''));

                if ($liveName === '') {
                    $liveName = trim(
                        (string) ($user->first_name ?? '')
                        . ' '
                        . (string) ($user->last_name ?? '')
                    );
                }

                return [
                    'user_id' => (int) $user->id,
                    'name' => $liveName !== ''
                        ? $liveName
                        : ($member['name'] ?? $user->username),
                    'username' => $user->username
                        ?? ($member['username'] ?? null),
                    'avatar_url' => $user->avatar_url
                        ?? ($member['avatar_url'] ?? null),
                    'account_exists' => true,
                    'is_registered_user' => true,
                    'source' => 'live_account',
                ];
            }

            /*
             * Le compte lié a été supprimé ou le membre était manuel :
             * on utilise les informations figées lors de la création.
             */
            $fallbackAvatar = $member['avatar_url'] ?? null;

            if (!$fallbackAvatar && !empty($member['photo'])) {
                $fallbackAvatar = $this->mediaUrl((string) $member['photo']);
            }

            return [
                'user_id' => $storedUserId,
                'name' => $member['name'] ?? 'Ancien membre du jury',
                'username' => $member['username'] ?? null,
                'avatar_url' => $fallbackAvatar,
                'account_exists' => false,
                'is_registered_user' => $storedUserId !== null,
                'source' => $storedUserId
                    ? 'stored_snapshot'
                    : 'manual',
            ];
        })->values()->all();
    }

    private function mediaUrl(string $path): string
    {
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        return route(
            'media',
            preg_replace(
                '/\.(?=[^.]*$)/',
                ',',
                preg_replace('#/#', '&', $path)
            )
        );
    }
}
