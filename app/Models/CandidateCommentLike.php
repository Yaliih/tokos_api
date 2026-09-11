<?php
// app/Models/CandidateCommentLike.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidateCommentLike extends Model
{
    use HasFactory;

    protected $fillable = [
        'comment_id',
        'user_id',
        'phone_number',
        'phone_code',
        'is_anonymous',
    ];

    protected $casts = [
        'is_anonymous' => 'boolean',
    ];

    public function comment()
    {
        return $this->belongsTo(CandidateComment::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}