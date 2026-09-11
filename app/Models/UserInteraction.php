<?php
// app/Models/UserInteraction.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserInteraction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'post_id',
        'type',
        'weight',
    ];

    protected $casts = [
        'weight' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    public static function record($userId, $postId, $type, $weight = 1)
    {
        return self::updateOrCreate(
            [
                'user_id' => $userId,
                'post_id' => $postId,
                'type' => $type,
            ],
            [
                'weight' => $weight,
            ]
        );
    }

    public static function getInteractionWeight($type): int
    {
        $weights = [
            'view' => 1,
            'like' => 3,
            'comment' => 5,
            'share' => 4,
            'bookmark' => 2,
            'vote' => 6,
        ];
        return $weights[$type] ?? 1;
    }

    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeRecent($query, $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}