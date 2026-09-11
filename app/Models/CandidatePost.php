<?php
// app/Models/CandidatePost.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidatePost extends Model
{
    use HasFactory;

    protected $fillable = [
        'candidate_id',
        'post_id',
    ];

    public function candidate()
    {
        return $this->belongsTo(ContestCandidate::class);
    }

    public function post()
    {
        return $this->belongsTo(Post::class);
    }
}