<?php
// app/Models/PostDebate.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PostDebate extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'topic',
        'for_argument',
        'against_argument',
        'end_date',
        'votes_for',
        'votes_against',
        'total_votes',
    ];

    protected $casts = [
        'end_date' => 'datetime',
        'votes_for' => 'integer',
        'votes_against' => 'integer',
        'total_votes' => 'integer',
    ];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    public function votes()
    {
        return $this->morphMany(PostVote::class, 'voteable');
    }

    public function refreshVotesCount(): void
    {
        $this->votes_for = $this->votes()
            ->where('voteable_type', 'debate_side')
            ->where('voteable_id', $this->id)
            ->where('value', 'for')
            ->count();
            
        $this->votes_against = $this->votes()
            ->where('voteable_type', 'debate_side')
            ->where('voteable_id', $this->id)
            ->where('value', 'against')
            ->count();
            
        $this->total_votes = $this->votes_for + $this->votes_against;
        $this->save();
    }

    public function getPercentageForAttribute(): float
    {
        if ($this->total_votes === 0) return 50;
        return round(($this->votes_for / $this->total_votes) * 100, 1);
    }

    public function getPercentageAgainstAttribute(): float
    {
        if ($this->total_votes === 0) return 50;
        return round(($this->votes_against / $this->total_votes) * 100, 1);
    }

    public function getWinnerAttribute(): ?string
    {
        if ($this->votes_for > $this->votes_against) return 'for';
        if ($this->votes_against > $this->votes_for) return 'against';
        return null;
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