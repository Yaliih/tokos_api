<?php
// app/Models/PostChallenge.php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PostChallenge extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'description',
        'max_participants',
        'votes_to_win',
        'end_date',
    ];

    protected $casts = [
        'end_date' => 'datetime',
        'max_participants' => 'integer',
        'votes_to_win' => 'integer',
    ];

    protected $appends = [
        'participants_count',
        'duels_generated',
        'challenger_wins',
        'challenger_losses',
        'time_left',
        'is_ended',
        'title',
    ];

    public function post()
    {
        return $this->belongsTo(Post::class, 'post_id');
    }

    public function participants()
    {
        return $this->hasMany(ChallengeParticipant::class, 'challenge_id', 'id');
    }

    public function duels()
    {
        return $this->hasMany(ChallengeDuel::class, 'challenge_id', 'id');
    }

    // ⭐ Accesseurs
    public function getParticipantsCountAttribute()
    {
        return $this->participants()->count();
    }

    public function getDuelsGeneratedAttribute()
    {
        return $this->duels()->count();
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

    /**
     * ⭐ Vérifier si le challenge est terminé
     */
    public function isEnded(): bool
    {
        if (!$this->end_date) return false;
        return now()->greaterThan($this->end_date);
    }

    /**
     * ⭐ Accesseur pour is_ended (utilisé dans les réponses JSON)
     */
    public function getIsEndedAttribute(): bool
    {
        return $this->isEnded();
    }

    public function getChallengerWinsAttribute()
    {
        return $this->duels()->where('winner', 'a')->count();
    }

    public function getChallengerLossesAttribute()
    {
        return $this->duels()->where('winner', 'b')->count();
    }

    // ⭐ Accesseur pour la description avec fallback
    public function getTitleAttribute(): string
    {
        return $this->description ?? 'Challenge';
    }
}