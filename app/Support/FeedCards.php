<?php

namespace App\Support;

use App\Models\Activity;
use App\Models\Competition;
use App\Models\CompetitionEntry;

/**
 * Cartes « résumé » du fil, pour que les concours et les tournois fassent vivre la plateforme :
 *  - classement d'un concours : le podium, et parfois une tranche plus loin (20e, 21e, 22e…) ;
 *  - tableau d'un tournoi : le tour en cours (ex. 32e de finale), les participants en ronds, et la suite jusqu'à la finale.
 * Les données sont figées au moment de la carte (instantané), le fil reste rapide.
 */
class FeedCards
{
    private static function person(CompetitionEntry $e): array
    {
        $p = $e->participant ? Participant::present($e->participant) : null;

        return ['name' => $e->displayName(), 'avatar' => $p['avatar_url'] ?? null, 'url' => $p['url'] ?? null];
    }

    /** @return array{rows: array, total: int, final: bool} */
    public static function standings(Competition $c, bool $final = false): array
    {
        $q = $c->entries()->with('participant')->whereIn('status', $final ? ['winner', 'active', 'eliminated'] : ['active']);
        $entries = $final ? $q->whereNotNull('rank')->orderBy('rank')->get() : $q->orderByDesc('score')->orderByDesc('votes_count')->orderBy('id')->get();
        $total = $entries->count();
        $row = fn (CompetitionEntry $e, int $i) => ['rank' => $final ? (int) $e->rank : $i + 1, 'votes' => (int) $e->votes_count] + self::person($e);

        $rows = $entries->take(3)->values()->map($row)->all();
        // Une tranche plus loin, pour que chacun ait sa chance d'être vu (ex. 20e, 21e, 22e)
        if ($total > 8) {
            $start = random_int(4, $total - 3);
            $rows[] = ['gap' => true];
            foreach ($entries->slice($start, 3)->values() as $k => $e) {
                $rows[] = $row($e, $start + $k);
            }
        }

        return ['rows' => $rows, 'total' => $total, 'final' => $final];
    }

    /** @return array{round: int, rounds: int, pairs: array<int, array{0: ?string, 1: ?string}>} */
    public static function bracket(Competition $c, int $round): array
    {
        $matches = $c->matches()->with(['entryA.participant', 'entryB.participant'])->where('round', $round)->orderBy('position')->get();
        $av = fn (?CompetitionEntry $e) => $e ? self::person($e)['avatar'] : null;

        return [
            'round' => $round,
            'rounds' => max(1, $c->totalRounds()),
            'pairs' => $matches->map(fn ($m) => [$av($m->entryA), $av($m->entryB)])->values()->all(),
        ];
    }

    /** Concours en cours : un classement provisoire de temps en temps (au plus un par jour et par concours). */
    public static function postStandings(): int
    {
        $n = 0;
        $live = Competition::where('type', Competition::TYPE_CONTEST)->where('status', Competition::STATUS_LIVE)->whereNull('hidden_at')
            ->where('starts_at', '<=', now()->subHours(6))->where('entries_count', '>=', 4)->get();
        foreach ($live as $c) {
            $recent = Activity::where('verb', 'contest.standings')->where('subject_type', Competition::class)->where('subject_id', $c->id)
                ->where('created_at', '>=', now()->subHours(22))->exists();
            if ($recent) {
                continue;
            }
            app(\App\Services\Social\ActivityService::class)->record($c->organizer, 'contest.standings', $c, null, [
                'standings' => self::standings($c), 'competition' => $c->summary(),
            ], 2);
            $n++;
        }

        return $n;
    }
}
