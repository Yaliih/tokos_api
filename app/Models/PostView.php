<?php
// app/Models/PostView.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PostView extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'post_id',
        'viewed_at',
        'duration',
    ];

    protected $casts = [
        'viewed_at' => 'datetime',
        'duration' => 'integer',
    ];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeViewedToday($query)
    {
        return $query->where('viewed_at', '>=', now()->startOfDay());
    }

    public function scopeViewedInLastHours($query, $hours = 24)
    {
        return $query->where('viewed_at', '>=', now()->subHours($hours));
    }

    public static function hasViewed($userId, $postId): bool
    {
        return self::where('user_id', $userId)
            ->where('post_id', $postId)
            ->exists();
    }

    public static function getViewCount($postId): int
    {
        return self::where('post_id', $postId)->count();
    }

    public static function getUniqueViewers($postId): int
    {
        return self::where('post_id', $postId)->distinct('user_id')->count('user_id');
    }
}