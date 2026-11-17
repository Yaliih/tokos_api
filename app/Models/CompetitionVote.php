<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompetitionVote extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'weight' => 'float',
        'is_jury' => 'boolean',
    ];

    public function competition()
    {
        return $this->belongsTo(Competition::class);
    }

    public function match()
    {
        return $this->belongsTo(CompetitionMatch::class, 'match_id');
    }

    public function entry()
    {
        return $this->belongsTo(CompetitionEntry::class, 'entry_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
