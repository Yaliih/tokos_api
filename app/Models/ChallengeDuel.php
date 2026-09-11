<?php
// app/Models/ChallengeDuel.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChallengeDuel extends Model
{
    use HasFactory;

    protected $fillable = [
        'challenge_id',
        'participant_a_id',
        'participant_b_id',
        'end_date',
        'votes_a',
        'votes_b',
        'total_votes',
        'winner',
    ];

    protected $casts = [
        'end_date' => 'datetime',
        'votes_a' => 'integer',
        'votes_b' => 'integer',
        'total_votes' => 'integer',
    ];

    public function challenge()
    {
        return $this->belongsTo(Post::class, 'challenge_id');
    }

    public function participantA()
    {
        return $this->belongsTo(ChallengeParticipant::class, 'participant_a_id');
    }

    public function participantB()
    {
        return $this->belongsTo(ChallengeParticipant::class, 'participant_b_id');
    }

    public function votes()
    {
        return $this->morphMany(PostVote::class, 'voteable');
    }

    public function refreshVotesCount(): void
    {
        $this->votes_a = $this->votes()
            ->where('voteable_type', 'duel_option')
            ->where('voteable_id', $this->participant_a_id)
            ->count();
            
        $this->votes_b = $this->votes()
            ->where('voteable_type', 'duel_option')
            ->where('voteable_id', $this->participant_b_id)
            ->count();
            
        $this->total_votes = $this->votes_a + $this->votes_b;
        
        if ($this->votes_a > $this->votes_b) {
            $this->winner = 'a';
        } elseif ($this->votes_b > $this->votes_a) {
            $this->winner = 'b';
        } else {
            $this->winner = 'draw';
        }
        
        $this->save();
    }

    public function getWinnerUsernameAttribute(): ?string
    {
        if (!$this->winner || $this->winner === 'draw') return null;
        return $this->winner === 'a' 
            ? $this->participantA->user->username 
            : $this->participantB->user->username;
    }

    public function getTimeLeftAttribute(): ?string
    {
        if (!$this->end_date) return null;
        $diff = now()->diff($this->end_date);
        if ($diff->invert) return 'Terminé';
        if ($diff->days > 0) return "{$diff->days}j {$diff->h}h";
        if ($diff->h > 0) return "{$diff->h}h {$diff->i}m";
        return "{$diff->i}m";
    }

    public function isEnded(): bool
    {
        return $this->end_date && now()->greaterThan($this->end_date);
    }
}