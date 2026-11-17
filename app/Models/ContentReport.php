<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un signalement fait par une personne sur un contenu (duel, compétition, commentaire, participation). */
class ContentReport extends Model
{
    public const REASONS = [
        'shocking' => 'Contenu choquant ou violent',
        'harassment' => 'Harcèlement ou insultes',
        'spam' => 'Arnaque ou spam',
        'cheating' => 'Triche aux votes',
        'copyright' => 'Contenu volé',
        'other' => 'Autre chose',
    ];

    /** Ce qu'on peut signaler, et le modèle correspondant. */
    public const TYPES = [
        'match' => CompetitionMatch::class,
        'competition' => Competition::class,
        'comment' => PostComment::class,
        'entry' => CompetitionEntry::class,
    ];

    protected $fillable = ['user_id', 'reportable_type', 'reportable_id', 'reason', 'details', 'status'];

    public function reportable()
    {
        return $this->morphTo();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
