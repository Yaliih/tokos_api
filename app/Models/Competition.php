<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Competition extends Model
{
    use SoftDeletes;

    public const TYPE_CHALLENGE = 'challenge';
    public const TYPE_DUEL = 'duel';
    public const TYPE_CONTEST = 'contest';
    public const TYPE_TOURNAMENT = 'tournament';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_OPEN = 'open';
    public const STATUS_LIVE = 'live';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $guarded = ['id'];

    protected $casts = [
        'hidden_at' => 'datetime',
        'media_types' => 'array',
        'settings' => 'array',
        'geo_bbox' => 'array',
        'geo_zones' => 'array',
        'requires_approval' => 'boolean',
        'blind_votes' => 'boolean',
        'registration_ends_at' => 'datetime',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'completed_at' => 'datetime',
        'prize_tiers' => 'array',
        'prize_settled_at' => 'datetime',
        'edited_at' => 'datetime',
        'pulse' => 'array',
        'prize_proof' => 'array',
        'prize_proof_at' => 'datetime',
    ];

    /** Une récompense est annoncée (texte ou paliers) : il y a une remise à montrer à la fin. */
    public function hasPrize(): bool
    {
        return filled($this->prize) || ! empty($this->prize_tiers);
    }

    /** Scores toujours visibles (l'ancienne option « votes à l'aveugle » n'est plus proposée). */
    public function getBlindVotesAttribute(): bool
    {
        return false;
    }

    /** Participation sous forme de candidature (photo, description, vidéo facultative). */
    public function isApplication(): bool
    {
        return (bool) ($this->settings['application'] ?? false);
    }

    protected static function booted(): void
    {
        static::creating(function (Competition $c) {
            if (empty($c->slug)) {
                $base = Str::slug(Str::limit($c->title, 50, '')) ?: $c->type;
                $c->slug = $base.'-'.Str::lower(Str::random(6));
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // ── Relations ─────────────────────────────────────────────

    public function organizer()
    {
        return $this->morphTo();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    public function entries()
    {
        return $this->hasMany(CompetitionEntry::class);
    }

    public function activeEntries()
    {
        return $this->hasMany(CompetitionEntry::class)->whereIn('status', ['active', 'winner', 'eliminated']);
    }

    public function matches()
    {
        return $this->hasMany(CompetitionMatch::class);
    }

    public function votes()
    {
        return $this->hasMany(CompetitionVote::class);
    }

    public function invitations()
    {
        return $this->hasMany(CompetitionInvitation::class);
    }

    public function jurors()
    {
        return $this->belongsToMany(User::class, 'competition_jurors')->withTimestamps();
    }

    public function winnerEntry()
    {
        return $this->belongsTo(CompetitionEntry::class, 'winner_entry_id');
    }

    public function proposals()
    {
        return $this->hasMany(EntryProposal::class);
    }

    // ── Scopes ────────────────────────────────────────────────

    public function scopeVisible(Builder $q): Builder
    {
        // Ni brouillons, ni compétitions masquées par la modération, ni types cachés depuis l'admin
        return $q->whereNotIn('status', [self::STATUS_DRAFT])->whereNull('hidden_at')
            ->whereIn('type', \App\Support\Features::competitionTypes() ?: ['__none__']);
    }

    public function scopeOfType(Builder $q, ?string $type): Builder
    {
        return $type ? $q->where('type', $type) : $q;
    }

    // ── Helpers ───────────────────────────────────────────────

    /** Page visible ? Masquée par la modération ou type coupé : seuls l'admin et les gestionnaires la voient. */
    public function isShownTo(?User $viewer): bool
    {
        if ($this->hidden_at === null && \App\Support\Features::type($this->type)) {
            return true;
        }

        return $viewer && ($viewer->is_admin || $this->canManage($viewer));
    }

    public function isOrganizedBySpace(): bool
    {
        return $this->organizer_type === Space::class;
    }

    public function organizerSpace(): ?Space
    {
        return $this->isOrganizedBySpace() ? $this->organizer : null;
    }

    public function canManage(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        if ((int) $this->created_by === (int) $user->id) {
            return true;
        }
        $space = $this->organizerSpace();

        return $space ? $space->canManage($user) : false;
    }

    public function isJuror(?User $user): bool
    {
        return $user ? $this->jurors()->where('users.id', $user->id)->exists() : false;
    }

    public function acceptsEntries(): bool
    {
        if ($this->status !== self::STATUS_OPEN) {
            return false;
        }
        if ($this->registration_ends_at && now()->greaterThan($this->registration_ends_at)) {
            return false;
        }
        if ($this->type === self::TYPE_CHALLENGE && $this->ends_at && now()->greaterThan($this->ends_at)) {
            return false;
        }
        if ($this->max_entries && $this->entries_count >= $this->max_entries) {
            return false;
        }

        return true;
    }

    public const FORMAT_LABELS = ['text' => 'Texte', 'image' => 'Photo', 'audio' => 'Audio', 'video' => 'Vidéo'];

    /**
     * Format imposé des participations (texte, photo, audio ou vidéo) : tout le monde répond
     * dans le même format que le créateur, pour des duels cohérents (vidéo contre vidéo, texte contre texte…).
     * Défi, duel, versus : le format du créateur (ou du premier choix). Tournoi : celui choisi, sinon celui du premier inscrit.
     * Null = format libre (concours sans format choisi).
     */
    public function entryFormat(): ?string
    {
        $types = array_values((array) ($this->media_types ?: []));
        if (count($types) === 1) {
            return $types[0];
        }
        // Défis et duels créés avant le choix du format : on prend celui de la performance du créateur
        if (in_array($this->type, [self::TYPE_CHALLENGE, self::TYPE_DUEL], true) && $this->format !== 'versus' && $this->exists) {
            return $this->ownerFormatCache ??= (function () {
                $owner = $this->entries()->where('participant_type', $this->organizer_type)->where('participant_id', $this->organizer_id)->first();
                if (! $owner) {
                    return null;
                }
                $media = (array) ($owner->media ?? []);

                return $media ? ($media[0]['type'] ?? null) : (filled($owner->content) ? 'text' : null);
            })();
        }

        // Tournoi sans format choisi : le premier inscrit donne le format, tous les duels se jouent pareil
        if ($this->type === self::TYPE_TOURNAMENT && $this->exists) {
            return $this->ownerFormatCache ??= (function () {
                $first = $this->entries()->whereNotIn('status', ['withdrawn', 'rejected'])->orderBy('id')->first();
                if (! $first) {
                    return null;
                }
                $media = (array) ($first->media ?? []);

                return $media ? ($media[0]['type'] ?? null) : (filled($first->content) ? 'text' : null);
            })();
        }

        return null;
    }

    private ?string $ownerFormatCache = null;

    public function allowsMedia(string $type): bool
    {
        $allowed = $this->media_types ?: config('competition.media_types');

        return in_array($type, $allowed, true);
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_CHALLENGE => 'Défi',
            self::TYPE_DUEL => $this->format === 'versus' ? 'Versus' : 'Duel',
            self::TYPE_CONTEST => 'Concours',
            self::TYPE_TOURNAMENT => 'Tournoi',
            default => 'Compétition',
        };
    }

    /** Terminé pour de bon : fini, annulé, ou temps écoulé (même si la clôture automatique n'est pas encore passée). */
    public function isOver(): bool
    {
        return $this->timeIsUp();
    }

    /**
     * Le temps est fini, pour tous les types :
     *  - terminé ou annulé ;
     *  - la fin prévue est passée (défi, duel en cours de votes, concours, tournoi avec une date de fin) ;
     *  - duel en attente de l'adversaire dont l'invitation a expiré.
     * Plus rien ne se modifie alors (voir CompetitionEditor).
     */
    public function timeIsUp(): bool
    {
        if (in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
            return true;
        }
        if ($this->ends_at && $this->ends_at->isPast()) {
            return true;
        }

        return $this->type === self::TYPE_DUEL && $this->status === self::STATUS_OPEN
            && $this->registration_ends_at && $this->registration_ends_at->isPast();
    }

    /** Ce qu'on peut encore rejoindre : ouvert, inscriptions pas closes, défi pas fini. */
    public function scopeAcceptingEntries($q)
    {
        return $q->where('status', self::STATUS_OPEN)
            ->where(fn ($w) => $w->whereNull('registration_ends_at')->orWhere('registration_ends_at', '>', now()))
            ->where(fn ($w) => $w->where('type', '!=', self::TYPE_CHALLENGE)->orWhereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function statusLabel(): string
    {
        if ($this->status === self::STATUS_OPEN && $this->isOver()) {
            return 'Terminé';
        }
        if ($this->status === self::STATUS_LIVE && $this->isOver()) {
            return 'Votes terminés';
        }
        if ($this->status === self::STATUS_OPEN && $this->registration_ends_at && $this->registration_ends_at->isPast()) {
            return 'Inscriptions closes';
        }

        return match ($this->status) {
            self::STATUS_DRAFT => 'Brouillon',
            self::STATUS_OPEN => match ($this->type) {
                self::TYPE_CHALLENGE => 'Ouvert aux challengers',
                self::TYPE_DUEL => 'En attente de l\'adversaire',
                default => 'Inscriptions ouvertes',
            },
            self::STATUS_LIVE => 'Votes en cours',
            self::STATUS_COMPLETED => 'Terminé',
            self::STATUS_CANCELLED => 'Annulé',
            default => $this->status,
        };
    }

    public function categoryLabel(): ?string
    {
        return \App\Support\Categories::label($this->category);
    }

    public function url(): string
    {
        return '/c/'.$this->slug;
    }

    public function coverUrl(): ?string
    {
        return \App\Support\MediaUrl::make($this->cover);
    }

    /** Résumé figé, stocké dans les activités et notifications. */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'type' => $this->type,
            'format' => $this->format,
            'type_label' => $this->typeLabel(),
            'category' => $this->category,
            'category_label' => $this->categoryLabel(),
            'cover_url' => $this->coverUrl(),
            'url' => $this->url(),
        ];
    }

    public function totalRounds(): int
    {
        if ($this->type !== self::TYPE_TOURNAMENT) {
            return 1;
        }
        $size = (int) ($this->settings['bracket_size'] ?? 0);

        return $size > 1 ? (int) log($size, 2) : 0;
    }

    public static function roundName(int $round, int $totalRounds): string
    {
        $remaining = $totalRounds - $round;

        return match ($remaining) {
            0 => 'finale',
            1 => 'demi-finales',
            2 => 'quarts de finale',
            3 => 'huitièmes de finale',
            4 => 'seizièmes de finale',
            default => 'tour '.$round,
        };
    }
}
