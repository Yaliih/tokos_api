<?php

namespace App\Services\Competition;

use App\Exceptions\CompetitionException;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\CompetitionJuryScore;
use App\Models\CompetitionMatch;
use App\Models\CompetitionVote;
use App\Models\Space;
use App\Models\User;
use App\Services\Social\ActivityService;
use App\Services\Social\BadgeService;
use Illuminate\Support\Facades\DB;

/**
 * Votes : un vote par personne et par duel, verrouillage de ligne pour des
 * compteurs exacts même avec des votes simultanés, et règles anti-triche.
 */
class VoteService
{
    public function __construct(
        private ActivityService $activities,
        private BadgeService $badges,
        private \App\Services\Social\NotificationService $notifications,
    ) {
    }

    /**
     * Vote (ou retire / change son vote) dans un duel.
     *
     * @return string "added" | "removed" | "switched"
     */
    public function voteMatch(User $user, CompetitionMatch $match, int $entryId, ?string $ip = null, int $quantity = 1): string
    {
        $milestoneReached = null;
        $leaderBefore = self::leader(CompetitionMatch::find($match->id));

        $action = DB::transaction(function () use ($user, $match, $entryId, $ip, $quantity, &$milestoneReached) {
            /** @var CompetitionMatch $m */
            $m = CompetitionMatch::query()->lockForUpdate()->findOrFail($match->id);
            $m->load(['competition', 'entryA.participant', 'entryB.participant']);
            $c = $m->competition;

            if (! $m->isOpenForVotes()) {
                throw new CompetitionException('Les votes sont fermés pour ce duel.');
            }
            $side = $m->sideOf($entryId);
            if (! $side) {
                throw new CompetitionException('Ce participant ne fait pas partie de ce duel.');
            }

            $isJuror = $c->isJuror($user);
            if ($c->vote_audience === 'jury' && ! $isJuror) {
                throw new CompetitionException('Seul le jury peut voter dans cette compétition.', 403);
            }
            if (! $isJuror) {
                \App\Support\Geo::assert($user, $c, 'vote');
            }

            $entries = array_filter([$m->entryA, $m->entryB]);
            $weight = $isJuror ? 1.0 : $this->weightFor($user, $c, $entries, true);

            $existing = CompetitionVote::where('match_id', $m->id)->where('user_id', $user->id)->lockForUpdate()->first();
            // Anti-triche (nouveau vote seulement) : même téléphone, même réseau, compte tout neuf
            $guard = ($isJuror || $existing) ? ['device_id' => \App\Support\Device::id(), 'flagged' => false, 'flag_reason' => null]
                : app(AntiFraud::class)->checkVote($user, $c, $m->id, $ip);

            // Votes payants : plusieurs votes possibles, mais toujours pour le même camp, et sans retour en arrière
            if ((int) $c->vote_cost > 0 && \App\Support\Features::on('paid_votes') && ! $isJuror) {
                if ($existing && (int) $existing->entry_id !== $entryId) {
                    throw new CompetitionException('Tu soutiens déjà l\'autre camp dans ce duel : tes votes vont tous au même.');
                }
                $qty = max(1, min($quantity, (int) config('coins.max_votes_at_once', 500)));
                $price = $qty * (int) $c->vote_cost;
                $this->payForVotes($user, $c, $price, $qty, $m);
                $before = $m->totalVotes();
                if ($existing) {
                    $existing->increment('quantity', $qty);
                    $existing->increment('coins_spent', $price);
                } else {
                    CompetitionVote::create([
                        'competition_id' => $c->id, 'match_id' => $m->id, 'entry_id' => $entryId, 'user_id' => $user->id,
                        'weight' => $weight, 'is_jury' => false, 'ip_address' => $ip, 'quantity' => $qty, 'coins_spent' => $price,
                    ] + $guard);
                }
                $this->applyMatchCounters($m, $side, $qty, $weight, false);
                $c->increment('votes_count', $qty);
                $c->increment('hot_score', $qty);
                foreach ((array) config('competition.votes.milestones', []) as $milestone) {
                    if ($before < $milestone && $m->totalVotes() >= $milestone) {
                        $milestoneReached = $milestone;
                    }
                }

                return 'added';
            }

            if ($existing && (int) $existing->entry_id === $entryId) {
                $this->applyMatchCounters($m, $side, -1, $existing->weight, $existing->is_jury);
                $existing->delete();
                $c->decrement('votes_count');

                return 'removed';
            }

            if ($existing) {
                $oldSide = $side === 'a' ? 'b' : 'a';
                $this->applyMatchCounters($m, $oldSide, -1, $existing->weight, $existing->is_jury);
                $this->applyMatchCounters($m, $side, +1, $weight, $isJuror);
                $existing->update(['entry_id' => $entryId, 'weight' => $weight, 'is_jury' => $isJuror]);

                return 'switched';
            }

            CompetitionVote::create([
                'competition_id' => $c->id,
                'match_id' => $m->id,
                'entry_id' => $entryId,
                'user_id' => $user->id,
                'weight' => $weight,
                'is_jury' => $isJuror,
                'ip_address' => $ip,
            ] + $guard);
            $before = $m->totalVotes();
            $this->applyMatchCounters($m, $side, +1, $weight, $isJuror);
            $c->increment('votes_count');
            $c->increment('hot_score');

            foreach ((array) config('competition.votes.milestones', []) as $milestone) {
                if ($before < $milestone && $m->totalVotes() >= $milestone) {
                    $milestoneReached = $milestone;
                }
            }

            return 'added';
        });

        if ($action === 'added') {
            $this->badges->afterVote($user);
        }
        $this->notifyLeadChange($match->id, $leaderBefore, $user->id);
        if ($milestoneReached) {
            $match->refresh()->load(['competition.organizer', 'entryA.participant', 'entryB.participant']);
            if ($match->competition->organizer) {
                $this->activities->record($match->competition->organizer, 'match.milestone', $match, $match->competition, [
                    'votes' => $milestoneReached,
                    'a_name' => $match->entryA?->displayName(),
                    'b_name' => $match->entryB?->displayName(),
                    'competition' => $match->competition->summary(),
                ], $milestoneReached >= 500 ? 3 : 2);
            }
        }

        return $action;
    }

    /** Les coins des votes vont à l'organisateur (moins la part de la plateforme). */
    private function payForVotes(User $user, Competition $c, int $price, int $qty, \Illuminate\Database\Eloquent\Model $related): void
    {
        $coins = app(\App\Services\Coins\CoinService::class);
        $label = $qty.' vote'.($qty > 1 ? 's' : '').' dans « '.$c->title.' »';
        $to = \App\Services\Coins\CoinService::beneficiary($c->organizer);
        $to && (int) $to->id !== (int) $user->id
            ? $coins->transfer($user, $to, $price, 'vote', 'vote_income', $label, $related, ['competition_id' => $c->id, 'votes' => $qty])
            : $coins->debit($user, $price, 'vote', $label, $related, null, ['competition_id' => $c->id, 'votes' => $qty]);
    }

    /** Camp en tête ('a', 'b') ou null en cas d'égalité. */
    private static function leader(?CompetitionMatch $m): ?string
    {
        if (! $m) {
            return null;
        }
        $s = $m->liveScores();

        return $s['a'] > $s['b'] ? 'a' : ($s['b'] > $s['a'] ? 'b' : null);
    }

    /**
     * « Tu es passé devant » / « X est passé devant toi » : au plus une fois par demi-heure et par camp,
     * et seulement quand le duel a déjà quelques voix (pas de notification au premier vote).
     */
    private function notifyLeadChange(int $matchId, ?string $before, int $voterId): void
    {
        try {
            $m = CompetitionMatch::with(['competition', 'entryA.participant', 'entryB.participant'])->find($matchId);
            if (! $m || $m->status !== 'voting' || $m->totalVotes() < 3) {
                return;
            }
            $after = self::leader($m);
            if (! $after || $after === $before) {
                return;
            }
            if (! \Illuminate\Support\Facades\Cache::add('lead:'.$m->id.':'.$after, 1, now()->addMinutes(30))) {
                return;
            }
            $lead = $after === 'a' ? $m->entryA : $m->entryB;
            $behind = $after === 'a' ? $m->entryB : $m->entryA;
            if (! $lead || ! $behind) {
                return;
            }
            $pct = round($m->liveScores()[$after]);
            $this->notifications->send($lead->stakeholderIds(), 'match.lead', 'Tu es passé devant', 'Tu es à '.$pct.' % contre '.$behind->displayName().'. Continue de partager !', $m->url(), $behind->participant, [], $voterId);
            $this->notifications->send($behind->stakeholderIds(), 'match.lead', $lead->displayName().' est passé devant toi', 'Il te reste du temps pour remonter : partage ton duel.', $m->url(), $lead->participant, [], $voterId);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Annule un vote (modération) : les compteurs du duel, du candidat et de la compétition sont corrigés. */
    public function cancelVote(CompetitionVote $vote): void
    {
        DB::transaction(function () use ($vote) {
            $v = CompetitionVote::query()->lockForUpdate()->find($vote->id);
            if (! $v) {
                return;
            }
            $qty = max(1, (int) ($v->quantity ?? 1));
            $c = Competition::find($v->competition_id);
            if ($v->match_id) {
                $m = CompetitionMatch::query()->lockForUpdate()->find($v->match_id);
                if ($m && ($side = $m->sideOf((int) $v->entry_id))) {
                    $this->applyMatchCounters($m, $side, -$qty, (float) $v->weight, (bool) $v->is_jury);
                }
            } else {
                $entry = CompetitionEntry::find($v->entry_id);
                if ($entry) {
                    $entry->update(['votes_count' => max(0, $entry->votes_count - $qty), 'score' => max(0, round($entry->score - $v->weight * $qty, 3))]);
                }
            }
            if ($c) {
                $c->update(['votes_count' => max(0, $c->votes_count - $qty)]);
            }
            $v->delete();
        });
    }

    private function applyMatchCounters(CompetitionMatch $m, string $side, int $sign, float $weight, bool $isJury): void
    {
        if ($isJury) {
            $m->{'jury_'.$side} = max(0, $m->{'jury_'.$side} + $sign);
        } else {
            $m->{'votes_'.$side} = max(0, $m->{'votes_'.$side} + $sign);
            $m->{'weight_'.$side} = max(0, round($m->{'weight_'.$side} + $sign * $weight, 3));
            $m->hot_score = max(0, $m->hot_score + ($sign > 0 ? 1 : 0));
        }
        $m->save();

        $entryId = $side === 'a' ? $m->entry_a_id : $m->entry_b_id;
        if (! $isJury && $entryId) {
            $sign > 0
                ? CompetitionEntry::whereKey($entryId)->increment('votes_count', $sign)
                : CompetitionEntry::whereKey($entryId)->where('votes_count', '>', 0)->decrement('votes_count', min(abs($sign), (int) CompetitionEntry::whereKey($entryId)->value('votes_count')));
        }
    }

    /**
     * Vote pour un candidat dans un concours (plusieurs votes possibles selon votes_per_user).
     */
    public function voteContestEntry(User $user, Competition $competition, CompetitionEntry $entry, ?string $ip = null, int $quantity = 1): string
    {
        return DB::transaction(function () use ($user, $competition, $entry, $ip, $quantity) {
            $c = Competition::query()->lockForUpdate()->findOrFail($competition->id);

            if ($c->type !== Competition::TYPE_CONTEST) {
                throw new CompetitionException('Ce vote est réservé aux concours.');
            }
            if ($c->status !== Competition::STATUS_LIVE || ($c->ends_at && now()->greaterThan($c->ends_at))) {
                throw new CompetitionException('Les votes ne sont pas ouverts pour ce concours.');
            }
            if ((int) $entry->competition_id !== (int) $c->id || $entry->status !== 'active') {
                throw new CompetitionException('Ce candidat ne participe pas (ou plus) à ce concours.');
            }
            if ($c->vote_audience === 'jury') {
                throw new CompetitionException('Seul le jury note ce concours.', 403);
            }
            \App\Support\Geo::assert($user, $c, 'vote');

            $weight = $this->weightFor($user, $c, [$entry], false);
            $already = CompetitionVote::where('competition_id', $c->id)->whereNull('match_id')->where('user_id', $user->id)->exists();
            $guard = $already ? ['device_id' => \App\Support\Device::id(), 'flagged' => false, 'flag_reason' => null]
                : app(AntiFraud::class)->checkVote($user, $c, null, $ip);

            // Concours payant : autant de votes que l'on veut, pour un seul candidat
            if ((int) $c->vote_cost > 0 && \App\Support\Features::on('paid_votes')) {
                $other = CompetitionVote::where('competition_id', $c->id)->whereNull('match_id')->where('user_id', $user->id)
                    ->where('contest_entry_id', '!=', $entry->id)->with('entry')->first();
                if ($other) {
                    throw new CompetitionException('Tu soutiens déjà '.($other->entry?->displayName() ?? 'un autre candidat').' : dans un concours payant, tes votes vont à un seul candidat.');
                }
                $qty = max(1, min($quantity, (int) config('coins.max_votes_at_once', 500)));
                $price = $qty * (int) $c->vote_cost;
                $this->payForVotes($user, $c, $price, $qty, $entry);
                $mine = CompetitionVote::where('contest_entry_id', $entry->id)->where('user_id', $user->id)->lockForUpdate()->first();
                if ($mine) {
                    $mine->increment('quantity', $qty);
                    $mine->increment('coins_spent', $price);
                } else {
                    CompetitionVote::create([
                        'competition_id' => $c->id, 'match_id' => null, 'entry_id' => $entry->id, 'contest_entry_id' => $entry->id,
                        'user_id' => $user->id, 'weight' => $weight, 'ip_address' => $ip, 'quantity' => $qty, 'coins_spent' => $price,
                    ] + $guard);
                }
                $entry->increment('votes_count', $qty);
                $entry->update(['score' => round($entry->score + $weight * $qty, 3)]);
                $c->increment('votes_count', $qty);
                $c->increment('hot_score', $qty);

                return 'added';
            }

            $existing = CompetitionVote::where('contest_entry_id', $entry->id)->where('user_id', $user->id)->first();
            if ($existing) {
                $existing->delete();
                $entry->decrement('votes_count');
                $entry->update(['score' => max(0, round($entry->score - $existing->weight, 3))]);
                $c->decrement('votes_count');

                return 'removed';
            }

            $used = CompetitionVote::where('competition_id', $c->id)->whereNull('match_id')->where('user_id', $user->id)->count();
            if ($used >= $c->votes_per_user) {
                throw new CompetitionException($c->votes_per_user > 1
                    ? "Tu as déjà utilisé tes {$c->votes_per_user} votes. Retire un vote pour en donner un autre."
                    : 'Tu as déjà voté dans ce concours. Retire ton vote pour changer de candidat.');
            }

            CompetitionVote::create([
                'competition_id' => $c->id,
                'match_id' => null,
                'entry_id' => $entry->id,
                'contest_entry_id' => $entry->id,
                'user_id' => $user->id,
                'weight' => $weight,
                'ip_address' => $ip,
            ] + $guard);
            $entry->increment('votes_count');
            $entry->update(['score' => round($entry->score + $weight, 3)]);
            $c->increment('votes_count');
            $c->increment('hot_score');

            return 'added';
        });
    }

    /** Note du jury (0 à 10) dans un concours. */
    public function juryScore(User $juror, Competition $c, CompetitionEntry $entry, int $score, ?string $comment = null): CompetitionJuryScore
    {
        if (! $c->isJuror($juror)) {
            throw new CompetitionException('Tu ne fais pas partie du jury.', 403);
        }
        if (! in_array($c->status, [Competition::STATUS_OPEN, Competition::STATUS_LIVE], true)) {
            throw new CompetitionException('Le jury ne peut plus noter cette compétition.');
        }
        if ((int) $entry->competition_id !== (int) $c->id) {
            throw new CompetitionException('Candidat introuvable dans ce concours.');
        }

        return CompetitionJuryScore::updateOrCreate(
            ['entry_id' => $entry->id, 'user_id' => $juror->id],
            ['competition_id' => $c->id, 'score' => max(0, min(10, $score)), 'comment' => $comment]
        );
    }

    /**
     * Calcule le poids d'un vote, ou refuse le vote.
     *
     * @param  CompetitionEntry[]  $entries  participations concernées
     * @param  bool  $strictSelf  true pour un duel (un participant ne vote pas du tout)
     */
    public function weightFor(User $user, Competition $c, array $entries, bool $strictSelf): float
    {
        foreach ($entries as $entry) {
            if (in_array((int) $user->id, $entry->stakeholderIds(), true)) {
                throw new CompetitionException($strictSelf
                    ? 'Tu participes à ce duel : tu ne peux pas voter.'
                    : 'Tu ne peux pas voter pour ta propre participation.', 403);
            }
        }

        $organizer = $c->organizer;
        if ($c->vote_audience === 'followers' || $c->vote_audience === 'members') {
            $allowed = match (true) {
                $organizer instanceof Space => $organizer->hasAffiliate($user),
                $organizer instanceof User => (int) $organizer->id === (int) $user->id || $user->isFollowing($organizer),
                default => true,
            };
            if (! $allowed) {
                throw new CompetitionException($organizer instanceof Space
                    ? 'Rejoins l\'Espace organisateur pour voter.'
                    : 'Abonne-toi à l\'organisateur pour voter.', 403);
            }
        }

        $weight = 1.0;

        foreach ($entries as $entry) {
            if ($entry->isSpace() && $entry->participant && $entry->participant->hasAffiliate($user)) {
                if ($c->member_vote_policy === 'excluded') {
                    throw new CompetitionException('Les membres des Espaces en compétition ne peuvent pas voter ici.', 403);
                }
                if ($c->member_vote_policy === 'reduced') {
                    $weight *= (float) config('competition.votes.member_reduced_weight', 0.5);
                }
            }
        }

        $hours = (int) config('competition.votes.new_account_hours', 24);
        if ($user->created_at && $user->created_at->greaterThan(now()->subHours($hours))) {
            $weight *= (float) config('competition.votes.new_account_weight', 0.5);
        }

        return round($weight, 2);
    }
}
