<?php

namespace App\Services\Social;

use App\Models\CompetitionMatch;
use App\Models\Rating;
use App\Models\Space;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Classement ELO + points de saison, pour les utilisateurs ET les Espaces,
 * par catégorie ("all" = toutes) et par saison ("all" = tous les temps).
 */
class RatingService
{
    public function get(Model $rateable, string $category = 'all', string $season = 'all'): Rating
    {
        $rating = Rating::firstOrCreate([
            'rateable_type' => $rateable::class,
            'rateable_id' => $rateable->getKey(),
            'category' => $category,
            'season' => $season,
        ]);

        // Récupère les valeurs par défaut de la base (rating 1000, compteurs à 0)
        return $rating->wasRecentlyCreated ? $rating->refresh() : $rating;
    }

    /** Combinaisons (catégorie, saison) mises à jour à chaque résultat. */
    private function scopes(?string $category): array
    {
        $seasons = ['all', Rating::currentSeason(), Rating::currentWeek()];
        $categories = array_unique(array_filter(['all', $category]));
        $out = [];
        foreach ($categories as $c) {
            foreach ($seasons as $s) {
                $out[] = [$c, $s];
            }
        }

        return $out;
    }

    /**
     * Met à jour les classements après un match terminé.
     *
     * @return array{a: array, b: array} infos utiles pour les badges et le fil
     */
    public function recordMatch(CompetitionMatch $match): array
    {
        $match->loadMissing(['entryA.participant', 'entryB.participant', 'competition']);
        $pa = $match->entryA?->participant;
        $pb = $match->entryB?->participant;
        if (! $pa || ! $pb) {
            return ['a' => [], 'b' => []];
        }

        $category = $match->competition->category;
        $resultA = $match->is_draw ? 0.5 : ((int) $match->winner_entry_id === (int) $match->entry_a_id ? 1.0 : 0.0);
        $k = (int) config('competition.elo_k', 32);
        $points = config('competition.points');

        $info = ['a' => [], 'b' => []];
        $topBefore = [
            'a' => $this->rankOf($pa),
            'b' => $this->rankOf($pb),
        ];

        foreach ($this->scopes($category) as [$cat, $season]) {
            $ra = $this->get($pa, $cat, $season);
            $rb = $this->get($pb, $cat, $season);

            $expectedA = 1 / (1 + 10 ** (($rb->rating - $ra->rating) / 400));
            $deltaA = (int) round($k * ($resultA - $expectedA));

            if ($cat === 'all' && $season === 'all') {
                $info['a']['opponent_gap'] = $rb->rating - $ra->rating;
                $info['b']['opponent_gap'] = $ra->rating - $rb->rating;
            }

            $this->apply($ra, $deltaA, $resultA, $points);
            $this->apply($rb, -$deltaA, 1 - $resultA, $points);

            if ($cat === 'all' && $season === 'all') {
                $info['a'] += ['streak' => $ra->current_streak, 'wins' => $ra->wins, 'rating' => $ra->rating];
                $info['b'] += ['streak' => $rb->current_streak, 'wins' => $rb->wins, 'rating' => $rb->rating];
            }
        }

        $info['a']['rank_before'] = $topBefore['a'];
        $info['b']['rank_before'] = $topBefore['b'];
        $info['a']['rank_after'] = $this->rankOf($pa);
        $info['b']['rank_after'] = $this->rankOf($pb);

        return $info;
    }

    private function apply(Rating $r, int $delta, float $result, array $points): void
    {
        $r->rating = max(100, $r->rating + $delta);
        $r->peak_rating = max($r->peak_rating, $r->rating);
        $r->matches++;
        if ($result === 1.0) {
            $r->wins++;
            $r->current_streak++;
            $r->best_streak = max($r->best_streak, $r->current_streak);
            $r->points += $points['match_win'];
        } elseif ($result === 0.0) {
            $r->losses++;
            $r->current_streak = 0;
        } else {
            $r->draws++;
            $r->points += $points['match_draw'];
        }
        $r->save();
    }

    public function addPoints(Model $rateable, int $points, ?string $category = null, bool $trophy = false): void
    {
        foreach ($this->scopes($category) as [$cat, $season]) {
            $r = $this->get($rateable, $cat, $season);
            $r->points += $points;
            if ($trophy) {
                $r->trophies++;
            }
            $r->save();
        }
    }

    /** Rang (1 = premier) dans le classement ELO global de la saison en cours. */
    public function rankOf(Model $rateable, string $category = 'all', ?string $season = null, string $sort = 'rating'): ?int
    {
        $col = $sort === 'points' ? 'points' : 'rating';
        $filter = $sort === 'points' ? 'points' : 'matches';
        $season ??= Rating::currentSeason();
        $mine = Rating::where([
            'rateable_type' => $rateable::class,
            'rateable_id' => $rateable->getKey(),
            'category' => $category,
            'season' => $season,
        ])->first();
        if (! $mine || (int) $mine->{$filter} === 0) {
            return null;
        }

        return Rating::where('rateable_type', $rateable::class)
            ->where('category', $category)
            ->where('season', $season)
            ->where($filter, '>', 0)
            ->where(fn ($q) => $q->where($col, '>', $mine->{$col})
                ->orWhere(fn ($t) => $t->where($col, $mine->{$col})->where('wins', '>', $mine->wins)))
            ->count() + 1;
    }

    /**
     * @param  string  $kind  user | space
     * @param  string  $sort  rating | points
     */
    public function leaderboard(string $kind, string $category = 'all', ?string $season = null, string $sort = 'rating', int $limit = 50): Collection
    {
        $season ??= Rating::currentSeason();
        $class = $kind === 'space' ? Space::class : User::class;

        return Rating::query()
            ->with('rateable')
            ->where('rateable_type', $class)
            ->where('category', $category)
            ->where('season', $season)
            // Elo : seulement ceux qui ont joué au moins un duel ; points : ceux qui en ont
            ->where($sort === 'points' ? 'points' : 'matches', '>', 0)
            ->orderByDesc($sort === 'points' ? 'points' : 'rating')
            ->orderByDesc('wins')
            ->limit($limit)
            ->get()
            ->filter(fn ($r) => $r->rateable !== null)
            ->values();
    }
}
