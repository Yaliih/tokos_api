<?php

namespace App\Support;

use App\Models\Rating;
use App\Models\Space;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Classements de la saison.
 *  - Personnes : leurs points, filtrables par ville, par Espace et par discipline.
 *  - Espaces (tous types, dont les institutions) : leurs propres points + ceux de leurs membres.
 *  - Villes : les points des personnes de la ville, additionnés.
 */
class Rankings
{
    /** Étiquette d'un Espace : sa sous-catégorie si elle est précisée, sinon sa catégorie. */
    public static function spaceLabel(Space $s): ?string
    {
        $c = config('spaces.categories.'.$s->category);

        return $c ? ($c['sub'][$s->subcategory] ?? $c['label']) : null;
    }

    private static function base(string $season, string $category = 'all')
    {
        return Rating::query()->from('ratings as r')
            ->join('users as u', 'u.id', '=', 'r.rateable_id')
            ->where('r.rateable_type', User::class)->where('r.category', $category)->where('r.season', $season)
            ->where('r.points', '>', 0)->whereNull('u.deleted_at');
    }

    /** Qui appartient à quel Espace : les membres, plus les personnes rattachées à leur institution sur leur profil. */
    private static function memberships()
    {
        return DB::table('space_members')->where('is_accepted', true)->select('space_id as s', 'user_id as m')
            ->union(DB::table('users')->whereNotNull('school_space_id')->select('school_space_id as s', 'id as m'));
    }

    /** @return array<int, array> rank, name, participant, url, points, own, wins, losses, members */
    public static function spaces(string $season, ?string $type = null, int $limit = 50): array
    {
        $byMembers = self::base($season)
            ->joinSub(self::memberships(), 'ms', 'ms.m', '=', 'u.id')
            ->select('ms.s', DB::raw('SUM(r.points) as pts'), DB::raw('SUM(r.wins) as w'), DB::raw('SUM(r.losses) as l'), DB::raw('COUNT(*) as n'))
            ->groupBy('ms.s')->get()->keyBy('s');
        $own = Rating::where('rateable_type', Space::class)->where('category', 'all')->where('season', $season)
            ->where('points', '>', 0)->get()->keyBy('rateable_id');

        $ids = $byMembers->keys()->merge($own->keys())->unique();
        $spaces = Space::whereIn('id', $ids)->where('is_active', true)
            ->when($type, fn ($q) => $q->where('category', $type))->get();

        return $spaces->map(function (Space $s) use ($byMembers, $own) {
            $m = $byMembers[$s->id] ?? null;
            $o = $own[$s->id] ?? null;

            return [
                'name' => $s->name,
                'participant' => Participant::present($s),
                'url' => '/classements?kind=user&espace='.$s->slug,
                'own' => (int) ($o->points ?? 0),
                'points' => (int) ($o->points ?? 0) + (int) ($m->pts ?? 0),
                'wins' => (int) ($o->wins ?? 0) + (int) ($m->w ?? 0),
                'losses' => (int) ($o->losses ?? 0) + (int) ($m->l ?? 0),
                'members' => (int) ($m->n ?? 0),
                'label' => self::spaceLabel($s),
            ];
        })->sortByDesc(fn ($r) => [$r['points'], $r['wins']])->take($limit)->values()
            ->map(fn ($r, $i) => ['rank' => $i + 1] + $r)->all();
    }

    /** @return array<int, array> villes : rank, name, url, points, wins, losses, members */
    public static function cities(string $season, int $limit = 50): array
    {
        return self::base($season)->whereNotNull('u.city')->where('u.city', '!=', '')
            ->select(DB::raw('u.city as g'), DB::raw('SUM(r.points) as pts'), DB::raw('SUM(r.wins) as w'), DB::raw('SUM(r.losses) as l'), DB::raw('COUNT(*) as n'))
            ->groupBy('g')->orderByDesc('pts')->orderByDesc('w')->limit($limit)->get()
            ->values()->map(fn ($row, $i) => [
                'rank' => $i + 1, 'name' => $row->g, 'participant' => null,
                'url' => '/classements?kind=user&ville='.urlencode($row->g),
                'points' => (int) $row->pts, 'wins' => (int) $row->w, 'losses' => (int) $row->l, 'members' => (int) $row->n,
            ])->all();
    }

    /** Ancien nom, gardé pour les appels existants. */
    public static function groups(string $by, string $season, int $limit = 50): array
    {
        return $by === 'city' ? self::cities($season, $limit) : self::spaces($season, 'education', $limit);
    }

    private static function row(Rating $r, int $rank): array
    {
        return ['rank' => $rank, 'participant' => Participant::present($r->rateable), 'rating' => $r->rating, 'points' => $r->points,
            'wins' => $r->wins, 'losses' => $r->losses, 'draws' => $r->draws, 'streak' => $r->current_streak, 'trophies' => $r->trophies];
    }

    /** Ma place : rang, points, et les 2 juste devant et les 2 juste derrière. */
    public static function around(string $season, User $user, int $span = 2): ?array
    {
        $mine = Rating::where(['rateable_type' => User::class, 'rateable_id' => $user->id, 'category' => 'all', 'season' => $season])->first();
        if (! $mine || $mine->points <= 0) {
            return null;
        }
        // Même ordre que les listes : points, puis victoires, puis le plus ancien classé
        $rank = self::base($season)->where(fn ($q) => $q->where('r.points', '>', $mine->points)
            ->orWhere(fn ($t) => $t->where('r.points', $mine->points)->where('r.wins', '>', $mine->wins))
            ->orWhere(fn ($t) => $t->where('r.points', $mine->points)->where('r.wins', $mine->wins)->where('r.id', '<', $mine->id)))->count() + 1;
        $from = max(0, $rank - 1 - $span);
        $rows = self::base($season)->select('r.*')->orderByDesc('r.points')->orderByDesc('r.wins')->orderBy('r.id')
            ->offset($from)->limit($span * 2 + 1)->get()->load('rateable')->filter(fn ($r) => $r->rateable)->values();

        $total = self::base($season)->count();

        return ['rank' => $rank, 'points' => $mine->points, 'wins' => $mine->wins, 'losses' => $mine->losses, 'total' => $total,
            // Peu de classés : le top 10 suffit, pas besoin de répéter les voisins
            'rows' => $total <= 10 ? [] : $rows->map(fn (Rating $r, $i) => self::row($r, $from + $i + 1))->all()];
    }

    /** Moi et les gens que je suis, classés entre nous (je suis toujours dedans, même à 0 point). */
    public static function among(string $season, User $user, int $limit = 10): array
    {
        $ids = $user->following()->pluck('users.id')->push($user->id)->all();
        $rows = Rating::query()->with('rateable')->where('rateable_type', User::class)->whereIn('rateable_id', $ids)
            ->where('category', 'all')->where('season', $season)->where('points', '>', 0)
            ->orderByDesc('points')->orderByDesc('wins')->orderBy('id')->get()->filter(fn ($r) => $r->rateable)->values();
        $out = $rows->map(fn (Rating $r, $i) => self::row($r, $i + 1));
        if (! $rows->contains(fn ($r) => (int) $r->rateable_id === (int) $user->id)) {
            $out->push(['rank' => null, 'participant' => Participant::present($user), 'rating' => 1000, 'points' => 0, 'wins' => 0, 'losses' => 0, 'draws' => 0, 'streak' => 0, 'trophies' => 0]);
        }
        // Trop de monde : les premiers, puis moi à ma place
        if ($out->count() > $limit) {
            $me = $out->first(fn ($r) => $r['participant']['type'] === 'user' && (int) $r['participant']['id'] === (int) $user->id);
            $top = $out->take($limit - 1);
            $out = $top->contains(fn ($r) => $r === $me) ? $out->take($limit) : $top->push($me);
        }

        return $out->values()->all();
    }

    /** Personnes d'une ville et/ou d'un Espace, éventuellement dans une discipline. */
    public static function people(string $season, ?string $city = null, ?int $spaceId = null, int $limit = 100, string $category = 'all'): array
    {
        $q = self::base($season, $category)->select('r.*')->orderByDesc('r.points')->orderByDesc('r.wins')->orderBy('r.id')->limit($limit);
        if ($city) {
            $q->where('u.city', $city);
        }
        if ($spaceId) {
            $q->where(fn ($w) => $w->where('u.school_space_id', $spaceId)
                ->orWhereIn('u.id', DB::table('space_members')->where('space_id', $spaceId)->where('is_accepted', true)->select('user_id')));
        }

        return $q->get()->load('rateable')->filter(fn ($r) => $r->rateable)->values()->map(fn (Rating $r, $i) => [
            'rank' => $i + 1, 'participant' => Participant::present($r->rateable), 'rating' => $r->rating, 'points' => $r->points,
            'wins' => $r->wins, 'losses' => $r->losses, 'draws' => $r->draws, 'streak' => $r->current_streak, 'trophies' => $r->trophies,
        ])->all();
    }
}
