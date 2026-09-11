<?php
// app/Models/PostBookmark.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PostBookmark extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'user_id',
    ];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function isBookmarkedByUser($userId, $postId): bool
    {
        return self::where('user_id', $userId)
            ->where('post_id', $postId)
            ->exists();
    }

    public static function getBookmarkCount($postId): int
    {
        return self::where('post_id', $postId)->count();
    }

    public static function toggle($userId, $postId): bool
    {
        $bookmark = self::where('user_id', $userId)
            ->where('post_id', $postId)
            ->first();

        if ($bookmark) {
            $bookmark->delete();
            return false;
        }

        self::create([
            'user_id' => $userId,
            'post_id' => $postId,
        ]);
        return true;
    }
}