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
        'website',
        'location',
        'phone',
        'email',
        'address',
        'social_links',
        'is_private',
        'is_verified',
        'is_active',
        'trust_score',
    ];

    protected $casts = [
        'is_private' => 'boolean',
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

    public function canPost(User $user): bool
    {
        // Seuls le propriétaire, les admins et les editors publient.
        return $this->canManage($user);
    }

    // ⭐ Actions
    public function addMember(User $user): void
    {
        if (!$this->isMember($user)) {
            $this->members()->attach($user->id, [
                'is_accepted' => true,
                'accepted_at' => now(),
            ]);
        }
    }

    public function removeMember(User $user): void
    {
        $this->members()->detach($user->id);
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
}