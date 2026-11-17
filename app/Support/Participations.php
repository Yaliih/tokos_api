<?php

namespace App\Support;

use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\CompetitionMatch;
use App\Models\Rating;
use Illuminate\Database\Eloquent\Model;

/**
 * Page « Compétitions » d'un profil (personne ou Espace), site et app :
 *  - Organisées : avec filtre (toutes, en cours, terminées) et tris (récentes, anciennes, participants, votes, fin proche) ;
 *  - Participées : idem, tris (récentes, meilleurs résultats, votes reçus) et le résultat de chaque participation ;
 *  - Stats : publications par type, participations, victoires, podiums, votes reçus, saison…
 */
class Participations
{
    public const ORGANIZED_SORTS = ['recent' => 'Récentes', 'oldest' => 'Plus anciennes', 'entries' => 'Plus de participants', 'votes' => 'Plus de votes', 'ending' => 'Se terminent bientôt'];

    public const JOINED_SORTS = ['recent' => 'Récentes', 'best' => 'Meilleurs résultats', 'votes' => 'Plus de votes reçus', 'oldest' => 'Plus anciennes'];

    public const STATUSES = ['all' => 'Toutes', 'live' => 'En cours', 'done' => 'Terminées'];

    private static function statusList(string $status): array
    {
        return match ($status) {
            'live' => [Competition::STATUS_OPEN, Competition::STATUS_LIVE],
            'done' => [Competition::STATUS_COMPLETED],
            default => [Competition::STATUS_OPEN, Competition::STATUS_LIVE, Competition::STATUS_COMPLETED],
        };
    }

    private static function organizedQuery(Model $owner, string $status)
    {
        return Competition::visible()->where('organizer_type', $owner::class)->where('organizer_id', $owner->getKey())
            ->whereIn('status', self::statusList($status));
    }

    private static function joinedQuery(Model $owner, string $status)
    {
        return CompetitionEntry::query()
            ->where('competition_entries.participant_type', $owner::class)->where('competition_entries.participant_id', $owner->getKey())
            ->whereNotIn('competition_entries.status', ['rejected', 'withdrawn', 'pending', 'declined', 'cancelled'])
            ->whereHas('competition', fn ($c) => $c->visible()->whereIn('status', self::statusList($status)));
    }

    /** Nombre de compétitions liées au profil (organisées ou participées, sans doublon). */
    public static function count(Model $owner): int
    {
        $org = self::organizedQuery($owner, 'all')->pluck('id');
        $joined = self::joinedQuery($owner, 'all')->pluck('competition_id');

        return $org->merge($joined)->unique()->count();
    }

    /** Résultat lisible d'une participation. */
    public static function result(CompetitionEntry $e): array
    {
        $c = $e->competition;
        if ($c->status !== Competition::STATUS_COMPLETED) {
            return $e->status === 'eliminated' ? ['label' => 'Éliminé', 'tone' => 'muted'] : ['label' => 'En lice', 'tone' => 'live'];
        }
        if ((int) $c->winner_entry_id === (int) $e->id || (int) $e->rank === 1) {
            return ['label' => $c->type === Competition::TYPE_CONTEST ? '1er' : 'Gagné', 'tone' => 'gold'];
        }
        if (in_array($c->type, [Competition::TYPE_CHALLENGE, Competition::TYPE_DUEL], true)) {
            $draw = CompetitionMatch::where('competition_id', $c->id)->where('is_draw', true)
                ->where(fn ($q) => $q->where('entry_a_id', $e->id)->orWhere('entry_b_id', $e->id))->exists();

            return $draw ? ['label' => 'Égalité', 'tone' => 'muted'] : ['label' => 'Perdu', 'tone' => 'muted'];
        }
        if ($e->rank) {
            return ['label' => $e->rank.'e', 'tone' => $e->rank <= 3 ? 'podium' : 'muted'];
        }

        return ['label' => $e->status === 'eliminated' ? 'Éliminé' : 'Participé', 'tone' => 'muted'];
    }

    private static function statusTag(Competition $c): array
    {
        return match ($c->status) {
            Competition::STATUS_COMPLETED => ['label' => 'Terminée', 'tone' => 'muted'],
            Competition::STATUS_LIVE => ['label' => 'En cours', 'tone' => 'live'],
            default => ['label' => 'Ouverte', 'tone' => 'live'],
        };
    }

    private static function item(Competition $c, array $tag, array $extra = []): array
    {
        return [
            'id' => $c->id, 'slug' => $c->slug, 'url' => $c->url(), 'title' => $c->title ?: $c->typeLabel(),
            'type' => $c->type, 'type_label' => $c->typeLabel(), 'status' => $c->status,
            'cover_url' => $c->coverUrl(),
            'entries' => (int) $c->entries_count, 'votes' => (int) $c->votes_count,
            'at' => Presenter::date($c->status === Competition::STATUS_COMPLETED ? $c->updated_at : $c->created_at),
            'ends_at' => Presenter::date($c->ends_at),
            'result' => $tag,
        ] + $extra;
    }

    /** Une page de la liste « organized » ou « joined ». */
    public static function page(Model $owner, string $tab, string $status = 'all', string $sort = 'recent', int $perPage = 20): array
    {
        $status = array_key_exists($status, self::STATUSES) ? $status : 'all';
        if ($tab === 'organized') {
            $sort = array_key_exists($sort, self::ORGANIZED_SORTS) ? $sort : 'recent';
            $q = self::organizedQuery($owner, $status);
            match ($sort) {
                'oldest' => $q->orderBy('created_at'),
                'entries' => $q->orderByDesc('entries_count')->orderByDesc('id'),
                'votes' => $q->orderByDesc('votes_count')->orderByDesc('id'),
                'ending' => $q->orderByRaw('CASE WHEN ends_at IS NULL OR status = ? THEN 1 ELSE 0 END', [Competition::STATUS_COMPLETED])->orderBy('ends_at'),
                default => $q->orderByDesc('created_at')->orderByDesc('id'),
            };
            $p = $q->paginate($perPage);
            $items = collect($p->items())->map(fn ($c) => self::item($c, self::statusTag($c)));
        } else {
            $sort = array_key_exists($sort, self::JOINED_SORTS) ? $sort : 'recent';
            $q = self::joinedQuery($owner, $status)->with('competition');
            $created = Competition::select('created_at')->whereColumn('competitions.id', 'competition_entries.competition_id')->limit(1);
            $won = Competition::selectRaw('CASE WHEN winner_entry_id = competition_entries.id THEN 0 ELSE 1 END')->whereColumn('competitions.id', 'competition_entries.competition_id')->limit(1);
            match ($sort) {
                'best' => $q->orderBy($won)->orderByRaw('CASE WHEN `rank` IS NULL THEN 1 ELSE 0 END')->orderBy('rank')->orderByDesc('competition_entries.votes_count'),
                'votes' => $q->orderByDesc('competition_entries.votes_count')->orderByDesc('competition_entries.id'),
                'oldest' => $q->orderBy($created),
                default => $q->orderByDesc($created)->orderByDesc('competition_entries.id'),
            };
            $p = $q->paginate($perPage);
            $items = collect($p->items())->filter(fn ($e) => $e->competition)
                ->map(fn ($e) => self::item($e->competition, self::result($e), ['my_votes' => (int) $e->votes_count]));
        }

        return ['items' => $items->values()->all(), 'has_more' => $p->hasMorePages(), 'total' => $p->total(), 'sort' => $sort, 'status' => $status];
    }

    /** Onglet « Stats ». */
    public static function stats(Model $owner): array
    {
        $types = ['challenge' => 'Défis', 'duel' => 'Duels', 'contest' => 'Concours', 'tournament' => 'Tournois'];
        $org = self::organizedQuery($owner, 'all')->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type');
        $joined = self::joinedQuery($owner, 'all')->join('competitions', 'competitions.id', '=', 'competition_entries.competition_id')
            ->selectRaw('competitions.type as type, count(*) as n')->groupBy('competitions.type')->pluck('n', 'type');
        $entries = self::joinedQuery($owner, 'all');
        // (sum sur la table des participations seulement)
        $doneEntries = self::joinedQuery($owner, 'done')->with('competition')->get();
        $all = Rating::where(['rateable_type' => $owner::class, 'rateable_id' => $owner->getKey(), 'category' => 'all', 'season' => 'all'])->first();
        $season = Rating::where(['rateable_type' => $owner::class, 'rateable_id' => $owner->getKey(), 'category' => 'all', 'season' => Rating::currentSeason()])->first();

        return [
            'by_type' => collect($types)->map(fn ($label, $k) => ['type' => $k, 'label' => $label, 'organized' => (int) ($org[$k] ?? 0), 'joined' => (int) ($joined[$k] ?? 0)])->values()->all(),
            'organized' => (int) $org->sum(),
            'joined' => (int) $joined->sum(),
            'won' => $doneEntries->filter(fn ($e) => (int) $e->competition->winner_entry_id === (int) $e->id || (int) $e->rank === 1)->count(),
            'podiums' => $doneEntries->filter(fn ($e) => $e->rank && $e->rank <= 3)->count(),
            'votes_received' => (int) (clone $entries)->sum('competition_entries.votes_count'),
            'votes_on_mine' => (int) self::organizedQuery($owner, 'all')->sum('votes_count'),
            'matches' => (int) ($all?->matches ?? 0), 'wins' => (int) ($all?->wins ?? 0), 'losses' => (int) ($all?->losses ?? 0), 'draws' => (int) ($all?->draws ?? 0),
            'win_rate' => ($all?->matches ?? 0) > 0 ? (int) round($all->wins / $all->matches * 100) : null,
            'best_streak' => (int) ($all?->best_streak ?? 0), 'current_streak' => (int) ($all?->current_streak ?? 0),
            'trophies' => (int) ($all?->trophies ?? 0),
            'rating' => (int) ($all?->rating ?? 1000), 'peak_rating' => (int) ($all?->peak_rating ?? 1000),
            'season' => ['label' => Rating::seasonLabel(Rating::currentSeason()), 'points' => (int) ($season?->points ?? 0)],
            'categories' => Rating::where(['rateable_type' => $owner::class, 'rateable_id' => $owner->getKey(), 'season' => 'all'])
                ->where('category', '!=', 'all')->where('matches', '>', 0)->orderByDesc('wins')->limit(8)->get()
                ->map(fn ($r) => ['label' => Categories::label($r->category), 'wins' => (int) $r->wins, 'losses' => (int) $r->losses])->values()->all(),
        ];
    }

    public static function sorts(): array
    {
        $f = fn ($a) => collect($a)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values()->all();

        return ['organized' => $f(self::ORGANIZED_SORTS), 'joined' => $f(self::JOINED_SORTS), 'statuses' => $f(self::STATUSES)];
    }
}
