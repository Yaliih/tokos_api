<?php
// app/Models/CandidateComment.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CandidateComment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'candidate_id',
        'user_id',
        'phone_number',
        'phone_code',
        'content',
        'parent_id',
        'is_anonymous',
    ];

    protected $casts = [
        'is_anonymous' => 'boolean',
    ];

    public function candidate()
    {
        return $this->belongsTo(ContestCandidate::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function parent()
    {
        return $this->belongsTo(CandidateComment::class, 'parent_id');
    }

    public function replies()
    {
        return $this->hasMany(CandidateComment::class, 'parent_id')
            ->with('user')
            ->withCount(['likes', 'replies'])
            ->orderBy('created_at', 'asc');
    }

    public function allReplies()
    {
        return $this->hasMany(CandidateComment::class, 'parent_id')
            ->with('allReplies')
            ->orderBy('created_at', 'asc');
    }

    public function likes()
    {
        return $this->hasMany(CandidateCommentLike::class);
    }

    public function getLikesCountAttribute()
    {
        return $this->likes()->count();
    }

    public function getRepliesCountAttribute()
    {
        return $this->replies()->count();
    }

    public function getIsLikedByUserAttribute()
    {
        if (!auth()->check()) return false;
        return $this->likes()->where('user_id', auth()->id())->exists();
    }

    public function getIsLikedByPhoneAttribute($phoneNumber)
    {
        return $this->likes()->where('phone_number', $phoneNumber)->exists();
    }

    public function scopeRoot($query)
    {
        return $query->whereNull('parent_id');
    }
}