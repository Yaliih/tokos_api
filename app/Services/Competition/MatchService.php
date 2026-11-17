<?php

namespace App\Services\Competition;

use App\Exceptions\CompetitionException;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\CompetitionMatch;
use App\Services\Social\ActivityService;
use App\Services\Social\BadgeService;
use App\Services\Social\NotificationService;
use App\Services\Social\RatingService;
use App\Support\Participant;
use Illuminate\Support\Facades\DB;

/**
 * Cycle de vie d'un duel : ouverture des votes → clôture → gagnant figé,
 * classements, badges, fil d'activité et notifications.
 */
class MatchService
{
    public function __construct(
        private ActivityService $activities,
        private NotificationService $notifications,
        private RatingService $ratings,
        private BadgeService $badges,
    ) {
    }

    public function open(Competition $c, ?CompetitionEntry $a, ?CompetitionEntry $b, int $round = 1, ?int $position = null, bool $notify = true): CompetitionMatch
    {
        $position ??= (int) CompetitionMatch::where('competition_id', $c->id)->where('round', $round)->max('position') + 1;

        // Exempté (tournoi avec nombre impair) : qualifié directement, pas de vote.
        if (! $a || ! $b) {
            $match = CompetitionMatch::create([
                'competition_id' => $c->id,
                'round' => $round,
                'position' => $position,
                'entry_a_id' => $a?->id,
                'entry_b_id' => $b?->id,
                'status' => 'completed',
                'is_bye' => true,
                'winner_entry_id' => ($a ?? $b)?->id,
                'starts_at' => now(),
                'ends_at' => now(),
                'completed_at' => now(),
            ]);
            $c->increment('matches_count');

            return $match;
        }

        // Tournoi, 2e tour et suivants : d'abord chacun envoie sa nouvelle prestation, puis les votes s'ouvrent
        if ($c->type === Competition::TYPE_TOURNAMENT && $round > 1) {
            return $this->openForSubmissions($c, $a, $b, $round, $position, $notify);
        }

        $hours = max(1, (int) $c->match_duration_hours);
        $endsAt = now()->addHours($hours);
        // Défi : tous les duels se terminent à la fin du défi. Le dernier challenger arrivé a donc
        // moins de temps : il vaut mieux relever le défi tôt.
        if ($c->type === Competition::TYPE_CHALLENGE && $c->ends_at && $c->ends_at->isFuture()) {
            $endsAt = $c->ends_at->copy();
        }

        $match = CompetitionMatch::create([
            'competition_id' => $c->id,
            'round' => $round,
            'position' => $position,
            'entry_a_id' => $a->id,
            'entry_b_id' => $b->id,
            'status' => 'voting',
            'starts_at' => now(),
            'ends_at' => $endsAt,
        ]);
        $c->increment('matches_count');

        if ($notify) {
            foreach ([[$a, $b], [$b, $a]] as [$me, $other]) {
                $this->notifications->send(
                    $me->stakeholderIds(),
                    'match.started',
                    'Ton duel a commencé',
                    'Face à '.$other->displayName().' dans « '.$c->title.' ». Partage-le pour récolter des votes !',
                    $match->url(),
                    $other->participant,
                );
            }
        }

        return $match;
    }

    private function openForSubmissions(Competition $c, CompetitionEntry $a, CompetitionEntry $b, int $round, int $position, bool $notify): CompetitionMatch
    {
        $by = now()->addHours((int) config('competition.durations.round_submission_hours', 24));
        $match = CompetitionMatch::create([
            'competition_id' => $c->id, 'round' => $round, 'position' => $position,
            'entry_a_id' => $a->id, 'entry_b_id' => $b->id, 'status' => 'preparing', 'submit_by' => $by,
        ]);
        $c->increment('matches_count');
        if ($notify) {
            $roundName = Competition::roundName($round, $c->totalRounds());
            foreach ([[$a, $b], [$b, $a]] as [$me, $other]) {
                $this->notifications->send($me->stakeholderIds(), 'match.prepare', 'Envoie ta prestation pour les '.$roundName,
                    'Face à '.$other->displayName().'. Tu as jusqu\'au '.$by->locale('fr')->isoFormat('D MMM à HH:mm').' : sans envoi, ton adversaire passe.',
                    $match->url().'#prestation', $other->participant);
            }
        }

        return $match;
    }

    /** Côté (a ou b) de la personne dans ce duel, ou null. */
    public static function sideOf(CompetitionMatch $m, ?\App\Models\User $user): ?string
    {
        if (! $user) {
            return null;
        }
        foreach (['a', 'b'] as $s) {
            $e = $m->{'entry'.strtoupper($s)};
            if ($e && in_array((int) $user->id, array_map('intval', $e->stakeholderIds()), true)) {
                return $s;
            }
        }

        return null;
    }

    /** Un qualifié envoie sa prestation pour ce tour. Quand les deux l'ont fait, les votes s'ouvrent. */
    public function submitRound(\App\Models\User $user, CompetitionMatch $match, array $input): CompetitionMatch
    {
        $match->loadMissing(['competition', 'entryA', 'entryB']);
        $c = $match->competition;
        $side = self::sideOf($match, $user);
        if (! $side) {
            throw new CompetitionException('Seuls les deux participants de ce duel peuvent envoyer leur prestation.', 403);
        }
        if ($match->status !== 'preparing' || ($match->submit_by && $match->submit_by->isPast())) {
            throw new CompetitionException('Le temps pour envoyer ta prestation est écoulé.');
        }
        if ($match->{$side.'_submitted_at'}) {
            throw new CompetitionException('Ta prestation est déjà envoyée.');
        }
        $data = \Illuminate\Support\Facades\Validator::make($input, array_merge(['content' => 'nullable|string|max:5000'], \App\Services\MediaUploader::rules('media')))->validate();
        $uploader = app(\App\Services\MediaUploader::class);
        $media = $uploader->storeMany($input['media'] ?? [], 'competitions/rounds');
        // Les deux prestations d'un duel ont le même format : celui de la compétition, sinon celui de l'adversaire s'il a déjà envoyé
        $format = $c->entryFormat();
        if (! $format) {
            $other = $side === 'a' ? 'b' : 'a';
            if ($match->{$other.'_submitted_at'}) {
                $om = (array) ($match->{$other.'_media'} ?? []);
                $format = $om ? ($om[0]['type'] ?? null) : 'text';
            }
        }
        $word = ['video' => 'une vidéo', 'audio' => 'un audio', 'image' => 'une photo'];
        if ($format === 'text' && $media) {
            throw new CompetitionException('Ce duel se joue en texte : envoie seulement ton texte, sans média.');
        }
        if ($format && $format !== 'text' && (! collect($media)->contains(fn ($m) => ($m['type'] ?? null) === $format) || collect($media)->contains(fn ($m) => ($m['type'] ?? null) !== $format))) {
            throw new CompetitionException('Envoie '.($word[$format] ?? 'ton média').' uniquement : les deux côtés du duel ont le même format.');
        }
        if (! $media && trim((string) ($data['content'] ?? '')) === '') {
            throw new CompetitionException('Ajoute ta prestation avant d\'envoyer.');
        }

        $ready = DB::transaction(function () use ($match, $side, $data, $media) {
            $m = CompetitionMatch::query()->lockForUpdate()->find($match->id);
            $m->forceFill([$side.'_content' => $data['content'] ?? null, $side.'_media' => $media ?: null, $side.'_submitted_at' => now()])->save();

            return $m->a_submitted_at && $m->b_submitted_at ? $m : null;
        });
        if ($ready) {
            $this->startVoting($ready->fresh(['competition', 'entryA.participant', 'entryB.participant']));
        } else {
            $other = $side === 'a' ? $match->entryB : $match->entryA;
            $this->notifications->send($other->stakeholderIds(), 'match.prepare', 'Ton adversaire est prêt',
                'Il a envoyé sa prestation dans « '.$c->title.' ». Envoie la tienne avant la fin du temps.', $match->url().'#prestation');
        }

        return $match->fresh();
    }

    /** Les deux prestations sont là : le duel commence. */
    private function startVoting(CompetitionMatch $m): void
    {
        $c = $m->competition;
        $m->forceFill(['status' => 'voting', 'starts_at' => now(), 'ends_at' => now()->addHours(max(1, (int) $c->match_duration_hours))])->save();
        foreach ([[$m->entryA, $m->entryB], [$m->entryB, $m->entryA]] as [$me, $other]) {
            $this->notifications->send($me->stakeholderIds(), 'match.started', 'Ton duel a commencé',
                'Face à '.$other->displayName().' dans « '.$c->title.' ». Partage-le pour récolter des votes !', $m->url(), $other->participant);
        }
    }

    /**
     * Délai écoulé : celui qui a envoyé passe. Personne : la meilleure tête de série passe.
     * Les deux ont envoyé (de justesse) : le duel commence.
     */
    public function resolvePreparation(CompetitionMatch $match): void
    {
        $m = DB::transaction(function () use ($match) {
            $m = CompetitionMatch::query()->lockForUpdate()->find($match->id);
            if (! $m || $m->status !== 'preparing') {
                return null;
            }
            $m->load(['competition', 'entryA.participant', 'entryB.participant']);
            if ($m->a_submitted_at && $m->b_submitted_at) {
                return ['start', $m];
            }
            $a = (bool) $m->a_submitted_at;
            $b = (bool) $m->b_submitted_at;
            $winner = $a ? 'a' : ($b ? 'b' : ((($m->entryB->seed ?? PHP_INT_MAX) < ($m->entryA->seed ?? PHP_INT_MAX)) ? 'b' : 'a'));
            $loser = $winner === 'a' ? 'b' : 'a';
            $m->forceFill([
                'status' => 'completed', 'winner_entry_id' => $m->{'entry_'.$winner.'_id'}, 'forfeit' => ($a || $b) ? $loser : 'both',
                'starts_at' => now(), 'ends_at' => now(), 'completed_at' => now(),
            ])->save();
            $m->{'entry'.strtoupper($winner)}->increment('wins');
            $m->{'entry'.strtoupper($loser)}->increment('losses');
            $m->{'entry'.strtoupper($loser)}->update(['status' => 'eliminated']);

            return ['forfeit', $m];
        });
        if (! $m) {
            return;
        }
        [$what, $m] = $m;
        if ($what === 'start') {
            $this->startVoting($m);

            return;
        }
        $c = $m->competition;
        $win = (int) $m->winner_entry_id === (int) $m->entry_a_id ? $m->entryA : $m->entryB;
        $lose = $win === $m->entryA ? $m->entryB : $m->entryA;
        if ($m->forfeit === 'both') {
            $this->notifications->send($win->stakeholderIds(), 'match.won', 'Tu passes au tour suivant',
                'Aucun des deux n\'a envoyé sa prestation à temps : tu passes grâce à ton meilleur classement. Ne rate pas le prochain envoi !', $c->url());
            $this->notifications->send($lose->stakeholderIds(), 'match.lost', 'Éliminé',
                'Aucune prestation envoyée à temps dans « '.$c->title.' ».', $c->url());
        } else {
            $this->notifications->send($win->stakeholderIds(), 'match.won', 'Tu passes au tour suivant',
                $lose->displayName().' n\'a pas envoyé sa prestation à temps.', $c->url(), $lose->participant);
            $this->notifications->send($lose->stakeholderIds(), 'match.lost', 'Éliminé : temps écoulé',
                'Tu n\'as pas envoyé ta prestation à temps. '.$win->displayName().' passe au tour suivant.', $c->url(), $win->participant);
        }
        app(TournamentService::class)->onMatchClosed($m);
    }

    /** Rappel : il reste peu de temps et la prestation n'est pas envoyée. */
    public function remindPreparation(CompetitionMatch $m): void
    {
        $m->loadMissing(['competition', 'entryA', 'entryB']);
        foreach (['a', 'b'] as $s) {
            if ($m->{$s.'_submitted_at'}) {
                continue;
            }
            $e = $m->{'entry'.strtoupper($s)};
            $this->notifications->send($e->stakeholderIds(), 'match.prepare', 'Plus que quelques heures',
                'Envoie ta prestation dans « '.$m->competition->title.' » avant le '.$m->submit_by->locale('fr')->isoFormat('D MMM à HH:mm').', sinon ton adversaire passe.', $m->url().'#prestation');
        }
        $m->forceFill(['ending_soon_notified' => true])->save();
    }

    /**
     * Clôture un duel arrivé à échéance. Idempotent : ne fait rien si déjà clôturé.
     */
    public function close(CompetitionMatch $match): ?CompetitionMatch
    {
        $closed = DB::transaction(function () use ($match) {
            $m = CompetitionMatch::query()->lockForUpdate()->find($match->id);
            if (! $m || $m->status !== 'voting') {
                return null;
            }
            $m->load(['competition', 'entryA.participant', 'entryB.participant']);
            $c = $m->competition;

            $scores = $m->liveScores();
            $m->score_a = $scores['a'];
            $m->score_b = $scores['b'];

            $winner = null;
            if ($scores['a'] > $scores['b']) {
                $winner = 'a';
            } elseif ($scores['b'] > $scores['a']) {
                $winner = 'b';
            } elseif ($c->type === Competition::TYPE_TOURNAMENT) {
                // Égalité en tournoi : plus de votes bruts, puis meilleure tête de série, puis A.
                if ($m->votes_a !== $m->votes_b) {
                    $winner = $m->votes_a > $m->votes_b ? 'a' : 'b';
                } else {
                    $seedA = $m->entryA->seed ?? PHP_INT_MAX;
                    $seedB = $m->entryB->seed ?? PHP_INT_MAX;
                    $winner = $seedB < $seedA ? 'b' : 'a';
                }
            }

            $m->is_draw = $winner === null;
            $m->winner_entry_id = $winner ? $m->{'entry_'.$winner.'_id'} : null;
            $m->status = 'completed';
            $m->completed_at = now();
            $m->save();

            if ($m->is_draw) {
                $m->entryA->increment('draws');
                $m->entryB->increment('draws');
            } else {
                $win = $winner === 'a' ? $m->entryA : $m->entryB;
                $lose = $winner === 'a' ? $m->entryB : $m->entryA;
                $win->increment('wins');
                $lose->increment('losses');
                if ($c->type === Competition::TYPE_TOURNAMENT) {
                    $lose->update(['status' => 'eliminated']);
                }
            }

            return $m;
        });

        if (! $closed) {
            return null;
        }

        $this->afterClose($closed);

        return $closed;
    }

    private function afterClose(CompetitionMatch $m): void
    {
        $c = $m->competition;
        $a = $m->entryA;
        $b = $m->entryB;

        // Classements + badges (uniquement quand les deux côtés sont de vrais participants)
        $info = $this->ratings->recordMatch($m);
        $total = $m->totalVotes();
        if ($a->participant && $b->participant) {
            $this->badges->afterMatch($a->participant, (int) $m->winner_entry_id === (int) $a->id, $info['a'], $total);
            $this->badges->afterMatch($b->participant, (int) $m->winner_entry_id === (int) $b->id, $info['b'], $total);
            $this->streakActivity($a, $info['a'], (int) $m->winner_entry_id === (int) $a->id);
            $this->streakActivity($b, $info['b'], (int) $m->winner_entry_id === (int) $b->id);
        }

        // Fil d'activité
        $winnerEntry = $m->is_draw ? null : ((int) $m->winner_entry_id === (int) $a->id ? $a : $b);
        $loserEntry = $m->is_draw ? null : ($winnerEntry === $a ? $b : $a);
        $actor = $winnerEntry?->participant ?? $a->participant ?? $c->organizer;
        if ($actor) {
            $this->activities->record($actor, 'match.completed', $m, $c, [
                'is_draw' => $m->is_draw,
                'winner_name' => $winnerEntry?->displayName(),
                'loser_name' => $loserEntry?->displayName(),
                'a_name' => $a->displayName(),
                'b_name' => $b->displayName(),
                'winner_score' => $winnerEntry ? ($winnerEntry === $a ? $m->score_a : $m->score_b) : $m->score_a,
                'loser_score' => $loserEntry ? ($loserEntry === $a ? $m->score_a : $m->score_b) : $m->score_b,
                'votes' => $total,
                'round' => $m->round,
                'competition' => $c->summary(),
            ], $total >= 100 ? 3 : 2);
        }

        // Versus (le créateur a fourni les deux côtés) : on prévient seulement le créateur.
        if (! $a->participant && ! $b->participant) {
            $this->notifications->send([$c->created_by], 'competition.completed', 'Résultat de ton versus',
                $m->is_draw ? 'Égalité parfaite dans « '.$c->title.' ».' : ($winnerEntry->displayName().' l\'emporte dans « '.$c->title.' » ('.$total.' votes).'),
                $m->url());
        }

        // Notifications aux deux camps
        foreach ([$a, $b] as $entry) {
            if (! $entry->participant) {
                continue;
            }
            $other = $entry === $a ? $b : $a;
            if ($m->is_draw) {
                $this->notifications->send($entry->stakeholderIds(), 'match.draw', 'Match nul', 'Égalité parfaite contre '.$other->displayName().' dans « '.$c->title.' ».', $m->url(), $other->participant);
            } elseif ((int) $m->winner_entry_id === (int) $entry->id) {
                $score = $entry === $a ? $m->score_a : $m->score_b;
                $this->notifications->send($entry->stakeholderIds(), 'match.won', 'Victoire !', 'Tu as battu '.$other->displayName().' avec '.rtrim(rtrim(number_format($score, 1, ',', ''), '0'), ',').' % des voix.', $m->url(), $other->participant);
            } else {
                $this->notifications->send($entry->stakeholderIds(), 'match.lost', 'Défaite cette fois', $other->displayName().' l\'emporte dans « '.$c->title.' ». Demande une revanche ?', $m->url(), $other->participant, ['can_rematch' => $c->type !== Competition::TYPE_TOURNAMENT]);
            }
        }

        // Ceux qui ont voté pour un camp apprennent le résultat (dans la file d'attente)
        \App\Jobs\NotifySupporters::dispatch('matchClosed', [$m->id]);

        // Suite selon le type de compétition
        match ($c->type) {
            Competition::TYPE_TOURNAMENT => app(TournamentService::class)->onMatchClosed($m),
            Competition::TYPE_DUEL => app(CompetitionService::class)->complete($c->fresh()),
            Competition::TYPE_CHALLENGE => app(CompetitionService::class)->completeChallengeIfDone($c->fresh()),
            default => null,
        };
    }

    private function streakActivity(CompetitionEntry $entry, array $info, bool $won): void
    {
        $streak = (int) ($info['streak'] ?? 0);
        if ($won && in_array($streak, [3, 5, 10, 20], true) && $entry->participant) {
            $this->activities->record($entry->participant, 'streak', null, null, ['streak' => $streak], $streak >= 5 ? 3 : 2);
        }
        $before = $info['rank_before'] ?? null;
        $after = $info['rank_after'] ?? null;
        if ($after !== null && $after <= 10 && ($before === null || $before > 10) && $entry->participant) {
            $this->activities->record($entry->participant, 'rank.top10', null, null, ['rank' => $after], 3);
        }
    }

    /** Prévient les participants dont le duel se termine bientôt, avec leur position. */
    public function notifyEndingSoon(CompetitionMatch $m): void
    {
        $m->loadMissing(['competition', 'entryA.participant', 'entryB.participant']);
        $m->update(['ending_soon_notified' => true]);
        $scores = $m->liveScores();

        foreach (['a' => 'b', 'b' => 'a'] as $side => $otherSide) {
            $entry = $m->{'entry'.strtoupper($side)};
            $other = $m->{'entry'.strtoupper($otherSide)};
            $gap = $m->{'votes_'.$side} - $m->{'votes_'.$otherSide};
            $pct = round($scores[$side]);
            $state = $scores[$side] > $scores[$otherSide]
                ? 'tu mènes. Tiens bon !'
                : ($scores[$side] < $scores[$otherSide]
                    ? 'il te manque '.abs($gap).' voix. Partage ton duel !'
                    : 'égalité parfaite. Chaque voix compte !');
            $this->notifications->send($entry->stakeholderIds(), 'match.ending_soon', 'Ton duel finit dans 1 h, tu es à '.$pct.' %',
                'Contre '.$other->displayName().' : '.$state, $m->url(), $other->participant);
        }
    }

    public static function participantName(?CompetitionEntry $e): string
    {
        return $e ? $e->displayName() : Participant::name(null);
    }
}
