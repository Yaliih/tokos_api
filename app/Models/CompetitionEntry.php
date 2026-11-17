<?php

namespace App\Models;

use App\Support\MediaUrl;
use App\Support\Participant;
use Illuminate\Database\Eloquent\Model;

class CompetitionEntry extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'media' => 'array',
        'score' => 'float',
    ];

    public function competition()
    {
        return $this->belongsTo(Competition::class);
    }

    public function participant()
    {
        return $this->morphTo();
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function votes()
    {
        return $this->hasMany(CompetitionVote::class, 'entry_id');
    }

    public function reactions()
    {
        return $this->morphMany(Reaction::class, 'reactable');
    }

    public function displayName(): string
    {
        if ($this->participant) {
            return Participant::name($this->participant);
        }

        return $this->label ?: 'Participant #'.$this->id;
    }

    public function mediaUrls(): array
    {
        return MediaUrl::list($this->media);
    }

    public function isSpace(): bool
    {
        return $this->participant_type === Space::class;
    }

    /**
     * Utilisateurs qui "représentent" cette participation : pour les notifications
     * et pour empêcher de voter pour soi-même.
     */
    public function stakeholderIds(): array
    {
        $ids = [];
        if ($this->submitted_by) {
            $ids[] = (int) $this->submitted_by;
        }
        if ($this->participant_type === User::class) {
            $ids[] = (int) $this->participant_id;
        }
        if ($this->participant_type === Space::class && $this->participant) {
            $ids[] = (int) $this->participant->owner_id;
            $ids = array_merge($ids, $this->participant->managers()->pluck('users.id')->map(fn ($id) => (int) $id)->all());
        }

        return array_values(array_unique($ids));
    }
}
