<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompetitionJuryScore extends Model
{
    protected $guarded = ['id'];

    public function entry()
    {
        return $this->belongsTo(CompetitionEntry::class, 'entry_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
