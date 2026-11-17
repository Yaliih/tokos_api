<?php

namespace App\Services\Social;

use App\Models\Activity;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\CompetitionMatch;
use App\Models\CompetitionVote;
use App\Support\FeedCards;
use App\Support\Features;
use App\Support\Participant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * La plateforme raconte ce qui s'y passe, toute seule (toutes les 10 minutes) :
 *
 *  Publications automatiques du fil (fonction « auto_feed ») :
 *   - « X prend la tête / repasse premier au concours Y » ;
 *   - « X cumule le plus de voix aujourd'hui dans Y (+N votes en 24 h) » (une fois par jour) ;
 *   - « Y atteint 50 participants » (10, 25, 50, 100…) ;
 *   - « Dernières heures pour voter dans Y » ;
 *   - « Duel serré : A et B à 2 voix d'écart » (dans les 3 dernières heures).
 *
 *  Alertes aux participants (fonction « progress_alerts ») : chacun sait où il en est.
 *   - tu passes premier, on te dépasse, tu passes dernier ;
 *   - quelqu'un d'autre prend la tête (tous les participants, avec leur place) ;
 *   - dernières heures : ta place actuelle.
 *
 *  Remise du prix : rappel à l'organisateur 3 jours après la fin s'il n'a pas encore publié la vidéo.
 *
 * Ce qui a déjà été dit est noté dans competitions.pulse, pour ne jamais répéter ni inonder.
 */
class Pulse
{
    /** Paliers de participants annoncés dans le fil */
    public const ENTRY_STEPS = [10, 25, 50, 100, 250, 500, 1000, 2500, 5000];

    /** Pas plus d'une carte « prend la tête » par concours sur cette durée (heures) */
    private const LEADER_POST_GAP = 3;

    /** Pas plus d'une alerte de classement par participant sur cette durée (minutes) */
    private const ALERT_GAP = 60;

    public function __construct(private ActivityService $activities, private NotificationService $notifications)
    {
    }

    /** @return array<string, int> ce qui a été publié ou envoyé */
    public function run(): array
    {
        $feed = Features::on('auto_feed');
        $alerts = Features::on('progress_alerts');
        $stats = ['posts' => 0, 'alerts' => 0, 'reminders' => 0];

        if ($feed || $alerts) {
            Competition::query()->where('type', Competition::TYPE_CONTEST)->where('status', Competition::STATUS_LIVE)->whereNull('hidden_at')
                ->with('organizer')->get()
                ->each(fn (Competition $c) => $this->safe(fn () => $this->contest($c, $feed, $alerts, $stats)));
        }
        if ($feed) {
            Competition::query()->whereIn('type', [Competition::TYPE_CONTEST, Competition::TYPE_TOURNAMENT])
                ->whereIn('status', [Competition::STATUS_OPEN, Competition::STATUS_LIVE])->whereNull('hidden_at')
                ->where('entries_count', '>=', self::ENTRY_STEPS[0])->with('organizer')->get()
                ->each(fn (Competition $c) => $this->safe(fn () => $this->entriesStep($c, $stats)));
            $this->safe(fn () => $this->tightDuels($stats));
        }
        $this->safe(fn () => $this->prizeReminders($stats));

        return $stats;
    }

    // ───────────────────────── Concours en cours ─────────────────────────

    private function contest(Competition $c, bool $feed, bool $alerts, array &$stats): void
    {
        $entries = $c->entries()->with('participant')->where('status', 'active')
            ->orderByDesc('score')->orderByDesc('votes_count')->orderBy('id')->get()->values();
        if ($entries->count() < 2) {
            return;
        }
        $state = (array) ($c->pulse ?? []);
        $old = array_map('intval', (array) ($state['ranks'] ?? []));
        $ranks = [];
        foreach ($entries as $i => $e) {
            $ranks[$e->id] = $i + 1;
        }
        $total = count($ranks);
        $leader = $entries[0];
        $second = $entries[1];
        // Un vrai premier : des voix, et pas d'égalité avec le deuxième
        $hasLeader = (int) $leader->votes_count > 0
            && ((float) $leader->score !== (float) $second->score || (int) $leader->votes_count !== (int) $second->votes_count);
        $prevLeader = isset($state['leader']) ? (int) $state['leader'] : null;
        $leaderChanged = $hasLeader && $prevLeader !== (int) $leader->id;
        $leaderName = $leader->displayName();
        $title = Str::limit($c->title, 40);

        // 1. Le fil : « X prend la tête / repasse premier »
        if ($leaderChanged) {
            $again = in_array((int) $leader->id, array_map('intval', (array) ($state['past_leaders'] ?? [])), true);
            $lastPost = isset($state['leader_post_at']) ? \Illuminate\Support\Carbon::parse($state['leader_post_at']) : null;
            $worth = $prevLeader !== null || (int) $leader->votes_count >= 3; // pas de « prend la tête » pour un seul vote
            if ($feed && $worth && $leader->participant && (! $lastPost || $lastPost->lt(now()->subHours(self::LEADER_POST_GAP)))) {
                $this->activities->record($leader->participant, 'contest.leader', $c, $c->organizerSpace(), [
                    'again' => $again,
                    'votes' => (int) $leader->votes_count,
                    'total' => $total,
                    'competition' => $c->summary(),
                    'standings' => FeedCards::standings($c),
                ], 2);
                $state['leader_post_at'] = now()->toIso8601String();
                $stats['posts']++;
            }
            // Les votants de l'ancien et du nouveau premier
            if ($alerts && $prevLeader !== null) {
                \App\Jobs\NotifySupporters::dispatch('leaderChanged', [$c->id, (int) $leader->id, $prevLeader]);
            }
            $state['leader'] = (int) $leader->id;
            $state['past_leaders'] = array_values(array_unique([...array_map('intval', (array) ($state['past_leaders'] ?? [])), (int) $leader->id]));
        }

        // 2. Les participants : où en est chacun
        if ($alerts && $old) {
            $sent = (array) ($state['alerted'] ?? []);
            foreach ($entries as $e) {
                $r = $ranks[$e->id];
                $o = $old[$e->id] ?? null;
                if ($o === null || ! $e->participant) {
                    continue; // nouveau venu, ou candidat ajouté sans compte
                }
                $msg = null;
                if ($r === 1 && $o !== 1 && $hasLeader) {
                    $msg = ['Tu es premier !', 'Tu passes en tête de « '.$title.' ». Partage ton lien pour garder la première place.'];
                } elseif ($o === 1 && $r !== 1) {
                    $msg = ['On t\'a dépassé', $leaderName.' passe devant toi dans « '.$title.' ». Tu es maintenant '.self::place($r).' sur '.$total.'.'];
                } elseif ($r === $total && $o < $total && $total >= 3) {
                    $msg = ['Tu es dernier', 'Tu passes à la dernière place de « '.$title.' ». Partage ton lien pour remonter.'];
                } elseif ($leaderChanged && $prevLeader !== null && (int) $e->id !== (int) $leader->id) {
                    $msg = ['Nouveau premier', $leaderName.' passe premier dans « '.$title.' ». Tu es '.self::place($r).' sur '.$total.'.'];
                }
                if (! $msg || (isset($sent[$e->id]) && \Illuminate\Support\Carbon::parse($sent[$e->id])->gt(now()->subMinutes(self::ALERT_GAP)))) {
                    continue;
                }
                $this->notifications->send($this->people($e), 'contest.progress', $msg[0], $msg[1], $c->url(), $leader->participant);
                $sent[$e->id] = now()->toIso8601String();
                $stats['alerts']++;
            }
            $state['alerted'] = $sent;
        }

        // 3. Dernières heures : une carte dans le fil, et chacun reçoit sa place
        if ($c->ends_at && $c->ends_at->isFuture() && $c->ends_at->lte(now()->addHours(6)) && empty($state['last_call'])) {
            if ($feed && $c->organizer) {
                $this->activities->record($c->organizer, 'competition.last_call', $c, $c->organizerSpace(), [
                    'hours' => max(1, (int) ceil(now()->diffInMinutes($c->ends_at) / 60)),
                    'competition' => $c->summary(),
                ], 2);
                $stats['posts']++;
            }
            if ($alerts) {
                foreach ($entries as $e) {
                    if ($e->participant) {
                        $r = $ranks[$e->id];
                        $this->notifications->send($this->people($e), 'contest.progress', 'Dernières heures',
                            '« '.$title.' » se termine bientôt. Tu es '.self::place($r).' sur '.$total.($r > 1 ? ' : partage ton lien pour remonter.' : ' : tiens bon !'), $c->url(), $c->organizer);
                        $stats['alerts']++;
                    }
                }
            }
            $state['last_call'] = now()->toIso8601String();
        }

        // 4. Le plus de voix sur 24 h (une fois par jour, l'après-midi)
        if ($feed && ($state['surge_day'] ?? null) !== now()->toDateString() && now()->hour >= 14) {
            $rows = CompetitionVote::query()->where('competition_id', $c->id)->where('is_jury', false)->where('created_at', '>=', now()->subDay())
                ->selectRaw('entry_id, COUNT(*) as n')->groupBy('entry_id')->orderByDesc('n')->limit(2)->get();
            $top = $rows[0] ?? null;
            if ($top && (int) $top->n >= 5 && (int) $top->n > (int) ($rows[1]->n ?? 0) && ($e = $entries->firstWhere('id', (int) $top->entry_id)) && $e->participant) {
                $this->activities->record($e->participant, 'contest.surge', $c, $c->organizerSpace(), [
                    'votes' => (int) $top->n,
                    'rank' => $ranks[$e->id] ?? null,
                    'competition' => $c->summary(),
                ], 2);
                $stats['posts']++;
            }
            $state['surge_day'] = now()->toDateString();
        }

        $state['ranks'] = $ranks;
        $c->forceFill(['pulse' => $state])->saveQuietly();
    }

    // ───────────────────────── Paliers de participants ─────────────────────────

    private function entriesStep(Competition $c, array &$stats): void
    {
        $state = (array) ($c->pulse ?? []);
        $done = (int) ($state['entries_step'] ?? 0);
        $step = collect(self::ENTRY_STEPS)->filter(fn ($s) => $s <= (int) $c->entries_count)->last();
        if (! $step || $step <= $done) {
            return;
        }
        if ($c->organizer) {
            $this->activities->record($c->organizer, 'competition.entries', $c, $c->organizerSpace(), [
                'entries' => $step,
                'competition' => $c->summary(),
            ], $step >= 100 ? 3 : 2);
            $stats['posts']++;
        }
        $state['entries_step'] = $step;
        $c->forceFill(['pulse' => $state])->saveQuietly();
    }

    // ───────────────────────── Duels serrés ─────────────────────────

    private function tightDuels(array &$stats): void
    {
        $matches = CompetitionMatch::query()->with(['competition.organizer', 'entryA', 'entryB'])
            ->where('status', 'voting')->where('is_bye', false)
            ->where('ends_at', '>', now())->where('ends_at', '<=', now()->addHours(3))
            ->whereRaw('votes_a + votes_b >= 6')
            ->whereNotIn('id', Activity::query()->where('verb', 'match.tight')->where('subject_type', CompetitionMatch::class)->select('subject_id'))
            ->limit(50)->get();
        foreach ($matches as $m) {
            $c = $m->competition;
            $total = (int) $m->votes_a + (int) $m->votes_b;
            $gap = abs((int) $m->votes_a - (int) $m->votes_b);
            if (! $c || $c->hidden_at || ! $c->organizer || $gap > max(2, (int) floor($total * 0.1))) {
                continue;
            }
            $this->activities->record($c->organizer, 'match.tight', $m, $c, [
                'a_name' => $m->entryA?->displayName(),
                'b_name' => $m->entryB?->displayName(),
                'gap' => $gap,
                'competition' => $c->summary(),
            ], 2);
            $stats['posts']++;
        }
    }

    // ───────────────────────── Remise du prix ─────────────────────────

    /** À la fin : l'organisateur est invité à filmer la remise du prix (voir PrizeProof). */
    public function prizeReminder(Competition $c, bool $again = false): void
    {
        if (! $c->hasPrize() || $c->prize_proof_at || $c->type === Competition::TYPE_DUEL) {
            return;
        }
        $title = Str::limit($c->title, 40);
        $this->notifications->send(NotificationService::recipientsFor($c->organizer) ?: [(int) $c->created_by], 'prize.reminder',
            $again ? 'Toujours pas de vidéo de la remise ?' : 'Filme la remise du prix',
            $again
                ? 'Les participants de « '.$title.' » attendent de voir le gagnant recevoir son prix. Publie la vidéo : c\'est la preuve que ça marche.'
                : 'Quand le gagnant de « '.$title.' » reçoit son prix, filme le moment (ou demande au sponsor de filmer) et publie la vidéo : tout le monde verra que les gains sont réels.',
            $c->url().'#remise', $c->organizer);
    }

    private function prizeReminders(array &$stats): void
    {
        Competition::query()->where('status', Competition::STATUS_COMPLETED)->whereNull('prize_proof_at')->whereNull('hidden_at')
            ->where('type', '!=', Competition::TYPE_DUEL)
            ->whereBetween('completed_at', [now()->subDays(10), now()->subDays(3)])
            ->with('organizer')->get()
            ->filter(fn (Competition $c) => $c->hasPrize() && empty(($c->pulse ?? [])['prize_reminded']))
            ->each(function (Competition $c) use (&$stats) {
                $this->prizeReminder($c, true);
                $c->forceFill(['pulse' => ['prize_reminded' => now()->toIso8601String()] + (array) ($c->pulse ?? [])])->saveQuietly();
                $stats['reminders']++;
            });
    }

    // ─────────────────────────────────────────────────────────────────

    /** Qui recevoir l'alerte d'une participation : la personne, ou l'équipe de l'Espace. */
    private function people(CompetitionEntry $e): array
    {
        return $e->participant ? NotificationService::recipientsFor($e->participant) : [];
    }

    public static function place(int $r): string
    {
        return $r === 1 ? '1er' : $r.'e';
    }

    private function safe(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('Pulse : '.$e->getMessage());
            if (app()->runningUnitTests()) {
                throw $e;
            }
        }
    }
}
