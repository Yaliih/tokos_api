<?php

namespace App\Models;

use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Model;

/** Proposition d'un membre pour représenter son Espace dans une compétition. */
class EntryProposal extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['media' => 'array'];

    public function competition()
    {
        return $this->belongsTo(Competition::class);
    }

    public function space()
    {
        return $this->belongsTo(Space::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function mediaUrls(): array
    {
        return MediaUrl::list($this->media);
    }
}
