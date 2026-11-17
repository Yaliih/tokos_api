<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompetitionMatch extends Model
{
    protected $table = 'competition_matches';

    protected $guarded = ['id'];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'completed_at' => 'datetime',
        'submit_by' => 'datetime',
        'a_submitted_at' => 'datetime',
        'b_submitted_at' => 'datetime',
        'a_media' => 'array',
        'b_media' => 'array',
        'is_draw' => 'boolean',
        'is_bye' => 'boolean',
        'ending_soon_notified' => 'boolean',
        'weight_a' => 'float',
        'weight_b' => 'float',
        'score_a' => 'float',
        'score_b' => 'float',
    ];

    public function competition()
    {
        return $this->belongsTo(Competition::class);
    }

    public function entryA()
    {
        return $this->belongsTo(CompetitionEntry::class, 'entry_a_id');
    }

    public function entryB()
    {
        return $this->belongsTo(CompetitionEntry::class, 'entry_b_id');
    }

    public function winner()
    {
        return $this->belongsTo(CompetitionEntry::class, 'winner_entry_id');
    }

    public function votes()
    {
        return $this->hasMany(CompetitionVote::class, 'match_id');
    }

    public function reactions()
    {
        return $this->morphMany(Reaction::class, 'reactable');
    }

    public function isOpenForVotes(): bool
    {
        return $this->status === 'voting'
            && (! $this->ends_at || now()->lessThan($this->ends_at));
    }

    public function totalVotes(): int
    {
        return (int) $this->votes_a + (int) $this->votes_b;
    }

    public function sideOf(int $entryId): ?string
    {
        return match ($entryId) {
            (int) $this->entry_a_id => 'a',
            (int) $this->entry_b_id => 'b',
            default => null,
        };
    }

    /**
     * Score en direct (0..100 pour A), pondéré public + jury.
     */
    public function liveScores(): array
    {
        $jw = (int) ($this->competition?->jury_weight ?? 0) / 100;
        $pubTotal = $this->weight_a + $this->weight_b;
        $pubA = $pubTotal > 0 ? $this->weight_a / $pubTotal * 100 : 50;
        $juryTotal = $this->jury_a + $this->jury_b;
        $juryA = $juryTotal > 0 ? $this->jury_a / $juryTotal * 100 : null;

        $a = ($juryA !== null && $jw > 0) ? (1 - $jw) * $pubA + $jw * $juryA : $pubA;

        return ['a' => round($a, 1), 'b' => round(100 - $a, 1)];
    }

    public function url(): string
    {
        return '/m/'.$this->id;
    }
}
