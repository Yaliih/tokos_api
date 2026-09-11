<?php
// app/Models/ContestRound.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContestRound extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'round_number',
        'qualifiers',
        'start_date',
        'end_date',
        'jury_active',
        'results',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'jury_active' => 'boolean',
        'results' => 'array',
        'qualifiers' => 'integer',
        'round_number' => 'integer',
    ];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }
}