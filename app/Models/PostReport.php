<?php
// app/Models/PostReport.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PostReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'user_id',
        'reason',
        'description',
        'status',
    ];

    protected $casts = [
        'status' => 'string',
    ];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeResolved($query)
    {
        return $query->where('status', 'resolved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public static function hasReported($userId, $postId): bool
    {
        return self::where('user_id', $userId)
            ->where('post_id', $postId)
            ->exists();
    }

    public function markAsResolved(): void
    {
        $this->update(['status' => 'resolved']);
    }

    public function markAsRejected(): void
    {
        $this->update(['status' => 'rejected']);
    }
}