<?php
// app/Models/PostShare.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PostShare extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'user_id',
        'platform',
    ];

    protected $casts = [
        'platform' => 'string',
    ];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopePlatform($query, $platform)
    {
        return $query->where('platform', $platform);
    }

    public static function isSharedByUser($userId, $postId): bool
    {
        return self::where('user_id', $userId)
            ->where('post_id', $postId)
            ->exists();
    }

    public static function getShareCount($postId): int
    {
        return self::where('post_id', $postId)->count();
    }
}