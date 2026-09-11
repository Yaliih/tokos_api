<?php
// app/Models/Post.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'authorable_type',
        'authorable_id',
        'user_id',
        'type',
        'slug',
        'content',
        'media',
        'status',
        'is_pinned',
        'is_featured',
        'published_at',
    ];

    protected $casts = [
        'media' => 'array',
        'is_pinned' => 'boolean',
        'is_featured' => 'boolean',
        'published_at' => 'datetime',
    ];

    protected $appends = [
        'likes_count',
        'comments_count',
        'shares_count',
        'bookmarks_count',
        'is_liked_by_user',
        'is_bookmarked_by_user',
        'is_following_user',
        'time_ago',
        'media_urls',
        'is_voted_by_user',
        'voted_option',
        // ⭐ Ajouts pour les concours
        'contest_status_label',
        'contest_status_color',
        'can_user_participate',
        'can_user_vote',
        'has_active_contest',
    ];

    // ⭐ Relations
    public function author()
    {
        return $this->morphTo('authorable');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function mediaItems()
    {
        return $this->hasMany(PostMedia::class)->orderBy('order');
    }

    public function media()
    {
        return $this->mediaItems();
    }

    public function likes()
    {
        return $this->hasMany(PostLike::class);
    }

    public function comments()
    {
        return $this->hasMany(PostComment::class)->whereNull('parent_id');
    }

    public function allComments()
    {
        return $this->hasMany(PostComment::class);
    }

    public function shares()
    {
        return $this->hasMany(PostShare::class);
    }

    public function bookmarks()
    {
        return $this->hasMany(PostBookmark::class);
    }

    public function views()
    {
        return $this->hasMany(PostView::class);
    }

    public function votes()
    {
        return $this->hasMany(PostVote::class);
    }

    public function interactions()
    {
        return $this->hasMany(UserInteraction::class);
    }

    // ⭐ Relations spécifiques
    public function duel()
    {
        return $this->hasOne(PostDuel::class);
    }

    public function challenge()
    {
        return $this->hasOne(PostChallenge::class);
    }

    public function debate()
    {
        return $this->hasOne(PostDebate::class);
    }

    public function contest()
    {
        return $this->hasOne(PostContest::class);
    }

    public function contestRounds()
    {
        return $this->hasMany(ContestRound::class);
    }

    public function contestCandidates()
    {
        return $this->hasMany(ContestCandidate::class);
    }

    // ⭐ Accesseurs calculés
    public function getLikesCountAttribute()
    {
        return $this->likes()->count();
    }

    public function getCommentsCountAttribute()
    {
        return $this->allComments()->count();
    }

    public function getSharesCountAttribute()
    {
        return $this->shares()->count();
    }

    public function getBookmarksCountAttribute()
    {
        return $this->bookmarks()->count();
    }

    public function getViewsCountAttribute()
    {
        return $this->views()->count();
    }

    public function getIsLikedByUserAttribute()
    {
        if (!auth()->check()) return false;
        return $this->likes()->where('user_id', auth()->id())->exists();
    }

    public function getIsBookmarkedByUserAttribute()
    {
        if (!auth()->check()) return false;
        return $this->bookmarks()->where('user_id', auth()->id())->exists();
    }

    /**
     * ⭐ Vérifie si l'utilisateur connecté a voté sur ce post (pour les duels)
     */
    public function getIsVotedByUserAttribute()
    {
        if (!auth()->check()) return false;
        if ($this->type !== 'duel') return false;
        
        $duel = $this->duel;
        if (!$duel) return false;

        return PostVote::where('post_id', $this->id)
            ->where('user_id', auth()->id())
            ->where('voteable_type', 'duel_option')
            ->where('voteable_id', $duel->id)
            ->exists();
    }

    /**
     * ⭐ Récupère l'option votée par l'utilisateur connecté ('a' ou 'b')
     */
    public function getVotedOptionAttribute()
    {
        if (!auth()->check()) return null;
        if ($this->type !== 'duel') return null;
        
        $duel = $this->duel;
        if (!$duel) return null;

        $vote = PostVote::where('post_id', $this->id)
            ->where('user_id', auth()->id())
            ->where('voteable_type', 'duel_option')
            ->where('voteable_id', $duel->id)
            ->first();

        return $vote ? $vote->value : null;
    }

    /**
     * Vérifie si l'utilisateur connecté suit l'auteur du post
     */
    public function getIsFollowingUserAttribute()
    {
        if (!auth()->check()) return false;
        
        $userId = auth()->id();
        
        if ($this->user_id) {
            return Follow::where('follower_id', $userId)
                ->where('following_id', $this->user_id)
                ->where('is_accepted', true)
                ->exists();
        }
        
        if ($this->authorable_type === 'App\Models\Space' && $this->authorable_id) {
            return $this->checkIfUserFollowsSpace($userId, $this->authorable_id);
        }
        
        if ($this->authorable_type) {
            return $this->checkIfUserFollowsMorph($userId, $this->authorable_type, $this->authorable_id);
        }
        
        return false;
    }

    private function checkIfUserFollowsSpace(int $userId, int $spaceId): bool
    {
        return Follow::where('follower_id', $userId)
            ->where('following_type', 'App\Models\Space')
            ->where('following_id', $spaceId)
            ->where('is_accepted', true)
            ->exists();
    }

    private function checkIfUserFollowsMorph(int $userId, string $authorableType, int $authorableId): bool
    {
        return Follow::where('follower_id', $userId)
            ->where('following_type', $authorableType)
            ->where('following_id', $authorableId)
            ->where('is_accepted', true)
            ->exists();
    }

    public function getTimeAgoAttribute()
    {
        return $this->published_at ? $this->published_at->diffForHumans() : null;
    }

    public function getMediaUrlsAttribute(): array
    {
        if (empty($this->media)) {
            return [];
        }

        $mediaUrls = [];
        foreach ($this->media as $mediaItem) {
            if (isset($mediaItem['url']) && $mediaItem['url']) {
                $mediaUrls[] = [
                    'type' => $mediaItem['type'] ?? 'image',
                    'url' => $this->getMediaUrl($mediaItem['url']),
                ];
            }
        }
        return $mediaUrls;
    }

    public function getMediaUrl(string $path): string
    {
        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }
        return route('media', preg_replace('/\.(?=[^.]*$)/', ',', preg_replace('#/#', '&', $path)));
    }

    public function getFirstMediaAttribute(): ?array
    {
        $urls = $this->media_urls;
        return !empty($urls) ? $urls[0] : null;
    }

    public function getCoverImageAttribute(): ?string
    {
        $first = $this->first_media;
        return $first ? $first['url'] : null;
    }

    // ⭐ ============================================
    // ⭐ MÉTHODES POUR LES CONCOURS
    // ⭐ ============================================

    /**
     * ⭐ Vérifie si le post a un concours actif
     */
    public function getHasActiveContestAttribute(): bool
    {
        if ($this->type !== 'contest') return false;
        
        $contest = $this->contest;
        if (!$contest) return false;
        
        return $contest->status_text === 'live' || $contest->status_text === 'submission';
    }

    /**
     * ⭐ Vérifie si l'utilisateur connecté peut participer au concours
     */
    public function getCanUserParticipateAttribute(): bool
    {
        if (!auth()->check()) return false;
        if ($this->type !== 'contest') return false;
        
        $contest = $this->contest;
        if (!$contest) return false;
        
        $user = auth()->user();
        
        // Vérifier si le concours est ouvert
        if ($contest->status_text !== 'submission' && $contest->status_text !== 'live') {
            return false;
        }
        
        // Vérifier si l'utilisateur n'a pas déjà participé
        if ($contest->participants()->where('user_id', $user->id)->exists()) {
            return false;
        }
        
        // Vérifier le nombre max de participants
        if ($contest->max_participants && $contest->entries_count >= $contest->max_participants) {
            return false;
        }
        
        return true;
    }

    /**
     * ⭐ Vérifie si l'utilisateur connecté peut voter dans le concours
     */
    public function getCanUserVoteAttribute(): bool
    {
        if (!auth()->check()) return false;
        if ($this->type !== 'contest') return false;
        
        $contest = $this->contest;
        if (!$contest) return false;
        
        $user = auth()->user();
        
        // Vérifier si le vote est ouvert
        if ($contest->status_text !== 'live') return false;
        
        // Vérifier si l'utilisateur n'a pas déjà voté
        if ($contest->candidates()->whereHas('votes', function($q) use ($user) {
            $q->where('user_id', $user->id);
        })->exists()) {
            return false;
        }
        
        return true;
    }

    /**
     * ⭐ Récupère le statut du concours en français
     */
    public function getContestStatusLabelAttribute(): ?string
    {
        if ($this->type !== 'contest') return null;
        
        $contest = $this->contest;
        if (!$contest) return null;
        
        $statusMap = [
            'upcoming' => 'À venir',
            'submission' => 'Inscriptions',
            'live' => 'En cours',
            'ended' => 'Terminé',
        ];
        
        return $statusMap[$contest->status_text] ?? $contest->status_text;
    }

    /**
     * ⭐ Récupère la couleur du statut
     */
    public function getContestStatusColorAttribute(): ?string
    {
        if ($this->type !== 'contest') return null;
        
        $contest = $this->contest;
        if (!$contest) return null;
        
        $colorMap = [
            'upcoming' => 'bg-blue-500/10 text-blue-500 border-blue-500/20',
            'submission' => 'bg-purple-500/10 text-purple-500 border-purple-500/20',
            'live' => 'bg-green-500/10 text-green-500 border-green-500/20',
            'ended' => 'bg-gray-500/10 text-gray-500 border-gray-500/20',
        ];
        
        return $colorMap[$contest->status_text] ?? 'bg-gray-500/10 text-gray-500 border-gray-500/20';
    }

    // ⭐ Scopes
    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeWithInteractions($query)
    {
        return $query->withCount(['likes', 'comments', 'shares', 'bookmarks']);
    }

    // ⭐ Méthodes
    public function isOwner(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    public function canEdit(User $user): bool
    {
        if ($this->user_id === $user->id) return true;
        
        if ($this->authorable_type === 'App\Models\Space') {
            $space = $this->authorable;
            if ($space && $space->isManager($user)) {
                return true;
            }
        }
        
        return false;
    }

    public function canDelete(User $user): bool
    {
        if ($this->user_id === $user->id) return true;
        
        if ($this->authorable_type === 'App\Models\Space') {
            $space = $this->authorable;
            if ($space && $space->isAdmin($user)) {
                return true;
            }
        }
        
        return false;
    }

    public function getMediaAttribute($value)
    {
        $media = json_decode($value, true);
        if (empty($media)) {
            return [];
        }

        foreach ($media as &$item) {
            if (isset($item['url'])) {
                $item['url'] = $this->getMediaUrl($item['url']);
            }
        }

        return $media;
    }
}