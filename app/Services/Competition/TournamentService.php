<?php

namespace App\Services\Competition;

use App\Exceptions\CompetitionException;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\CompetitionMatch;
use App\Models\Rating;
use App\Services\Social\ActivityService;
use App\Services\Social\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Tournoi à élimination directe : têtes de série selon le classement,
 * exemptions quand le nombre n'est pas une puissance de 2, et passage
 * automatique au tour suivant quand tous les duels d'un tour sont terminés.
 */
class TournamentService
{
    public function __construct(
        private MatchService $matches,
        private ActivityService $activities,
        private NotificationService $notifications,
    ) {
    }

    public function start(Competition $c): Competition
    {
        if ($c->type !== Competition::TYPE_TOURNAMENT) {
            throw new CompetitionException('Ce n\'est pas un tournoi.');
        }
        if ($c->status !== Competition::STATUS_OPEN) {
            throw new CompetitionException('Ce tournoi a déjà commencé ou est terminé.');
        }

        $entries = $c->entries()->where('status', 'active')->with('participant')->get();
        if ($entries->count() < max(2, (int) $c->min_entries)) {
            throw new CompetitionException('Il faut au moins '.max(2, (int) $c->min_entries).' participants pour lancer le tournoi.');
        }

        DB::transaction(function () use ($c, $entries) {
            // Têtes de série : meilleur classement d'abord, puis premiers inscrits.
            $ratings = $entries->mapWithKeys(fn ($e) => [$e->id => $this->ratingOf($e)]);
            $ranked = $entries->sortBy([
                fn ($x, $y) => $ratings[$y->id] <=> $ratings[$x->id],
                fn ($x, $y) => $x->id <=> $y->id,
            ])->values();

            $size = 2 ** (int) ceil(log($ranked->count(), 2));
            foreach ($ranked as $i => $entry) {
                $entry->update(['seed' => $i + 1]);
            }

            $c->update([
                'status' => Competition::STATUS_LIVE,
                'starts_at' => now(),
                'current_round' => 1,
                'settings' => array_merge($c->settings ?? [], ['bracket_size' => $size]),
            ]);

            $order = self::seedOrder($size);
            $bySeed = $ranked->keyBy('seed');
            for ($i = 0, $pos = 1; $i < $size; $i += 2, $pos++) {
                $this->matches->open($c, $bySeed->get($order[$i]), $bySeed->get($order[$i + 1]), 1, $pos);
            }
        });

        $c->refresh();
        $this->activities->record($c->organizer, 'tournament.started', $c, null, [
            'entries' => $entries->count(),
            'round_name' => Competition::roundName(1, $c->totalRounds()),
            'bracket' => \App\Support\FeedCards::bracket($c, 1),
            'competition' => $c->summary(),
        ], $entries->count() >= 8 ? 3 : 2);

        $this->notifications->send(
            $entries->flatMap(fn (CompetitionEntry $e) => $e->stakeholderIds()),
            'competition.started',
            'Le tournoi commence',
            '« '.$c->title.' » est lancé avec '.$entries->count().' participants. Ton premier duel t\'attend.',
            $c->url(),
            $c->organizer,
        );

        // Cas limite : un tour composé uniquement d'exemptions
        $this->advanceIfRoundDone($c, 1);

        return $c;
    }

    public function onMatchClosed(CompetitionMatch $m): void
    {
        $this->advanceIfRoundDone($m->competition->fresh(), $m->round);
    }

    private function advanceIfRoundDone(Competition $c, int $round): void
    {
        $roundMatches = CompetitionMatch::where('competition_id', $c->id)->where('round', $round)->orderBy('position')->get();
        if ($roundMatches->isEmpty() || $roundMatches->contains(fn ($m) => $m->status !== 'completed')) {
            return;
        }
        // Déjà avancé (appel concurrent) ?
        if (CompetitionMatch::where('competition_id', $c->id)->where('round', $round + 1)->exists()) {
            return;
        }

        $winners = $roundMatches->map(fn ($m) => $m->winner_entry_id)->filter()->values();

        if ($winners->count() <= 1) {
            $c->update(['winner_entry_id' => $winners->first()]);
            app(CompetitionService::class)->complete($c->fresh());

            return;
        }

        $next = $round + 1;
        $entries = CompetitionEntry::whereIn('id', $winners)->get()->keyBy('id');

        DB::transaction(function () use ($c, $winners, $entries, $next) {
            $c->update(['current_round' => $next]);
            for ($i = 0, $pos = 1; $i < $winners->count(); $i += 2, $pos++) {
                $this->matches->open($c, $entries->get($winners[$i]), $entries->get($winners[$i + 1] ?? null), $next, $pos);
            }
        });

        $roundName = Competition::roundName($next, $c->totalRounds());
        $this->activities->record($c->organizer, 'tournament.round', $c, null, [
            'round' => $next,
            'round_name' => $roundName,
            'remaining' => $winners->count(),
            'bracket' => \App\Support\FeedCards::bracket($c, $next),
            'competition' => $c->summary(),
        ], $roundName === 'finale' ? 3 : 2);

        // Les qualifiés sont prévenus par leur nouveau duel : « Envoie ta prestation pour les … » (MatchService)
    }

    private function ratingOf(CompetitionEntry $e): int
    {
        if (! $e->participant) {
            return 1000;
        }

        return (int) (Rating::where('rateable_type', $e->participant_type)
            ->where('rateable_id', $e->participant_id)
            ->where('category', 'all')->where('season', 'all')
            ->value('rating') ?? 1000);
    }

    /**
     * Ordre classique des têtes de série dans un tableau : 1 contre 8, 4 contre 5, 2 contre 7, 3 contre 6...
     * Les deux meilleurs ne peuvent se rencontrer qu'en finale.
     */
    public static function seedOrder(int $size): array
    {
        $order = [1, 2];
        while (count($order) < $size) {
            $n = count($order) * 2;
            $next = [];
            foreach ($order as $s) {
                $next[] = $s;
                $next[] = $n + 1 - $s;
            }
            $order = $next;
        }

        return array_slice($order, 0, $size);
    }

    /** Structure du tableau pour l'affichage : tours → duels (y compris ceux à venir). */
    public function bracket(Competition $c): array
    {
        $total = max(1, $c->totalRounds());
        $matches = $c->matches()->with(['entryA.participant', 'entryB.participant'])->get()->groupBy('round');
        $rounds = [];
        for ($r = 1; $r <= $total; $r++) {
            $count = (int) (($c->settings['bracket_size'] ?? 2) / (2 ** $r));
            $existing = ($matches->get($r) ?? collect())->keyBy('position');
            $slots = [];
            for ($p = 1; $p <= max(1, $count); $p++) {
                $slots[] = $existing->get($p);
            }
            $rounds[] = [
                'round' => $r,
                'name' => Competition::roundName($r, $total),
                'matches' => $slots,
            ];
        }

        return $rounds;
    }
}
