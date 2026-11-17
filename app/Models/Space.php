<?php
// app/Models/Space.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Space extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'owner_id',
        'name',
        'slug',
        'avatar',
        'cover',
        'cover_type',
        'bio',
        'category',
        'kind',
        'subcategory',
        'site_template',
        'website',
        'location',
        'phone',
        'email',
        'address',
        'social_links',
        'is_private',
        'join_approval',
        'is_verified',
        'is_active',
        'trust_score',
    ];

    protected $casts = [
        'is_private' => 'boolean',
        'join_approval' => 'boolean',
        'is_verified' => 'boolean',
        'is_active' => 'boolean',
        'social_links' => 'array',
        'trust_score' => 'integer',
    ];

    protected $appends = ['avatar_url', 'cover_url', 'members_count', 'trust_level', 'total_posts'];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($space) {
            if (empty($space->slug)) {
                $baseSlug = Str::slug($space->name);
                $slug = $baseSlug;
                $counter = 1;
                
                // Vérifier l'unicité du slug
                while (static::where('slug', $slug)->exists()) {
                    $slug = $baseSlug . '-' . $counter;
                    $counter++;
                }
                
                $space->slug = $slug;
            }
        });
    }
   
    // ⭐ Relations
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function managers()
    {
        return $this->belongsToMany(User::class, 'space_managers')
            ->withPivot('role', 'joined_at')
            ->withTimestamps();
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'space_members')
            ->withPivot('is_accepted', 'accepted_at')
            ->wherePivot('is_accepted', true)
            ->withTimestamps();
    }

    public function posts()
    {
        return $this->morphMany(Post::class, 'authorable');
    }

    public function publishedPosts()
    {
        return $this->morphMany(Post::class, 'authorable')
            ->where('status', 'published');
    }

    public function activities()
    {
        return $this->hasMany(SpaceActivity::class)->orderBy('created_at', 'desc');
    }

    // ⭐ Accesseurs
    public function getAvatarUrlAttribute(): string
    {
        if ($this->avatar) {
            return route('media', preg_replace('/\.(?=[^.]*$)/', ',', preg_replace('#/#', '&', $this->avatar)));
        }
        return "https://ui-avatars.com/api/?name=" . urlencode($this->name) . "&background=random&size=200";
    }

    public function getCoverUrlAttribute(): string
    {
        if (!$this->cover) {
            return '';
        }
        $fullPath = $this->cover;
        return route('media', preg_replace('/\.(?=[^.]*$)/', ',', preg_replace('#/#', '&',  $fullPath)));
    }

    public function getMembersCountAttribute(): int
    {
        // Utilise le withCount('members') s'il a été chargé (évite une requête par Espace).
        if (array_key_exists('members_count', $this->attributes)) {
            return (int) $this->attributes['members_count'];
        }

        return $this->members()->count();
    }

    public function getTrustLevelAttribute(): string
    {
        $score = $this->trust_score ?? 0;
        if ($score >= 100) return '⭐ Elite';
        if ($score >= 50) return '🌟 Trusted';
        if ($score >= 20) return '👍 Good';
        return 'Confiance';
    }

    public function getTotalPostsAttribute(): int
    {
        if (array_key_exists('published_posts_count', $this->attributes)) {
            return (int) $this->attributes['published_posts_count'];
        }

        return $this->posts()->where('status', 'published')->count();
    }

    // ⭐ Vérifications
    public function isMember(User $user): bool
    {
        return $this->members()->where('users.id', $user->id)->exists();
    }

    public function isManager(User $user): bool
    {
        return $this->managers()->where('users.id', $user->id)->exists();
    }

    public function isAdmin(User $user): bool
    {
        return $this->managers()
            ->where('users.id', $user->id)
            ->wherePivot('role', 'admin')
            ->exists();
    }

    public function canManage(User $user): bool
    {
        if ((int) $this->owner_id === (int) $user->id) {
            return true;
        }

        return $this->managers()
            ->where('users.id', $user->id)
            ->wherePivotIn('role', ['admin', 'editor'])
            ->exists();
    }

    /*
     * Rôles, du plus simple au plus large (le créateur est toujours administrateur) :
     *  - viewer  « Publieur »       : publie et inscrit l'Espace au nom de l'Espace ;
     *  - editor  « Modérateur »     : publie aussi, et valide (membres, candidatures, invitations) ;
     *  - admin   « Administrateur » : tout, dont le nom, le logo, la couverture et les rôles.
     */
    public const ROLES = ['viewer' => 'Publieur', 'editor' => 'Modérateur', 'admin' => 'Administrateur'];

    /** owner | admin | editor | viewer | member | pending | null */
    public function roleOf(?User $user): ?string
    {
        if (! $user) {
            return null;
        }
        if ((int) $this->owner_id === (int) $user->id) {
            return 'owner';
        }
        $role = $this->managers()->where('users.id', $user->id)->first()?->pivot?->role;
        if ($role) {
            return $role;
        }
        $row = $this->memberships()->where('users.id', $user->id)->first();

        return $row ? ($row->pivot->is_accepted ? 'member' : ($row->pivot->invited_by ? 'invited' : 'pending')) : null;
    }

    /** Nom, logo, couverture, réglages et rôles : les administrateurs seulement. */
    public function canEditInfo(User $user): bool
    {
        return (int) $this->owner_id === (int) $user->id || $this->isAdmin($user);
    }

    /** Valider (membres, candidatures, invitations) : administrateurs et modérateurs. */
    public function canModerate(User $user): bool
    {
        return $this->canManage($user);
    }

    /** Publier au nom de l'Espace : administrateurs, modérateurs et publieurs. */
    public function canPublish(User $user): bool
    {
        return $this->canManage($user)
            || $this->managers()->where('users.id', $user->id)->wherePivot('role', 'viewer')->exists();
    }

    public function canPost(User $user): bool
    {
        return $this->canPublish($user);
    }

    /** Toutes les lignes d'adhésion, acceptées ou en attente (demandes et invitations). */
    public function memberships()
    {
        return $this->belongsToMany(User::class, 'space_members')->withPivot('is_accepted', 'accepted_at', 'invited_by')->withTimestamps();
    }

    /** Demandes d'adhésion en attente (la personne a demandé à entrer). */
    public function pendingMembers()
    {
        return $this->belongsToMany(User::class, 'space_members')
            ->withPivot('is_accepted', 'accepted_at', 'invited_by')
            ->wherePivot('is_accepted', false)
            ->wherePivotNull('invited_by')
            ->withTimestamps();
    }

    /** Invitations envoyées par l'Espace, pas encore acceptées. */
    public function invitedMembers()
    {
        return $this->belongsToMany(User::class, 'space_members')
            ->withPivot('is_accepted', 'accepted_at', 'invited_by')
            ->wherePivot('is_accepted', false)
            ->wherePivotNotNull('invited_by')
            ->withTimestamps();
    }

    public function isPending(User $user): bool
    {
        return $this->pendingMembers()->where('users.id', $user->id)->exists();
    }

    public function isInvited(User $user): bool
    {
        return $this->invitedMembers()->where('users.id', $user->id)->exists();
    }

    /** L'Espace invite quelqu'un : il entrera seulement s'il accepte. */
    public function inviteMember(User $user, User $by): void
    {
        if (! $this->isMember($user) && ! $this->isInvited($user) && ! $this->isPending($user)) {
            $this->memberships()->attach($user->id, ['is_accepted' => false, 'invited_by' => $by->id]);
        }
    }

    // ⭐ Actions
    public function addMember(User $user): void
    {
        if ($this->isMember($user)) {
            return;
        }
        if ($this->isPending($user) || $this->isInvited($user)) {
            $this->memberships()->updateExistingPivot($user->id, ['is_accepted' => true, 'accepted_at' => now()]);

            return;
        }
        $this->memberships()->attach($user->id, ['is_accepted' => true, 'accepted_at' => now()]);
    }

    /** Demande d'adhésion (Espace sur validation). */
    public function requestMembership(User $user): void
    {
        if (! $this->isMember($user) && ! $this->isPending($user) && ! $this->isInvited($user)) {
            $this->memberships()->attach($user->id, ['is_accepted' => false]);
        }
    }

    public function removeMember(User $user): void
    {
        $this->memberships()->detach($user->id);
    }

    public function addManager(User $user, string $role = 'editor'): void
    {
        if (!$this->isManager($user)) {
            $this->managers()->attach($user->id, [
                'role' => $role,
                'joined_at' => now(),
            ]);
        }
    }

    public function updateManagerRole(User $user, string $role): void
    {
        if ($this->isManager($user)) {
            $this->managers()->updateExistingPivot($user->id, ['role' => $role]);
        }
    }

    public function removeManager(User $user): void
    {
        $this->managers()->detach($user->id);
    }

    // ── Compétitions & couche sociale ───────────────────────────

    public function organizedCompetitions()
    {
        return $this->morphMany(Competition::class, 'organizer');
    }

    public function competitionEntries()
    {
        return $this->morphMany(CompetitionEntry::class, 'participant');
    }

    public function ratings()
    {
        return $this->morphMany(Rating::class, 'rateable');
    }

    public function badges()
    {
        return $this->morphMany(Badge::class, 'owner');
    }

    /** Membre au sens large : propriétaire, gestionnaire ou membre. */
    public function hasAffiliate(User $user): bool
    {
        return (int) $this->owner_id === (int) $user->id
            || $this->isManager($user)
            || $this->isMember($user);
    }

    /** Ids de tous les utilisateurs liés à l'Espace (propriétaire, gestionnaires, membres). */
    public function affiliateIds(): array
    {
        return collect([(int) $this->owner_id])
            ->merge($this->managers()->pluck('users.id'))
            ->merge($this->members()->pluck('users.id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}