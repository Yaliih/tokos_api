<?php
// app/Models/PostVote.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PostVote extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'user_id',
        'voteable_type',
        'voteable_id',
        'value',
        'ip_address',
    ];

    protected $casts = [
        'value' => 'string',
    ];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function voteable()
    {
        return $this->morphTo();
    }

    public static function canVote($postId, $voteableType, $voteableId, $userId): bool
    {
        return !self::where('post_id', $postId)
            ->where('voteable_type', $voteableType)
            ->where('voteable_id', $voteableId)
            ->where('user_id', $userId)
            ->exists();
    }

    public static function createVote($postId, $voteableType, $voteableId, $userId, $value = null, $ip = null)
    {
        return self::create([
            'post_id' => $postId,
            'user_id' => $userId,
            'voteable_type' => $voteableType,
            'voteable_id' => $voteableId,
            'value' => $value,
            'ip_address' => $ip,
        ]);
    }
}