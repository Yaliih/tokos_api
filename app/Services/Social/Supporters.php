<?php

namespace App\Services\Social;

use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\CompetitionMatch;
use App\Models\CompetitionVote;
use App\Models\User;
use App\Support\Features;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Faire revenir ceux qui sont passés une fois, juste pour voter ou participer.
 * Chaque votant suit « son » candidat, sans rien faire de plus :
 *  - son duel est gagné (ou il est éliminé du tournoi) ;
 *  - il est dépassé, ou il passe premier dans un concours ;
 *  - la compétition est finie : sa place, et le gagnant ;
 *  - il participe à une nouvelle compétition (« ton favori est de retour ») ;
 * et ceux qui ont déjà voté ou participé apprennent qu'un nouveau concours ou tournoi est lancé.
 *
 * Toujours dans la file d'attente (NotifySupporters), et avec des limites par personne pour ne jamais lasser.
 * Fonction « supporter_alerts » dans l'admin.
 */
class Supporters
{
    /** [nombre maximum, sur combien d'heures] par sorte de relance et par personne */
    private const BUDGET = [
        'result' => [4, 24],
        'progress' => [3, 24],
        'favorite' => [2, 24],
        'new' => [1, 72],
    ];

    /** Au plus tant de personnes touchées par une nouvelle compétition */
    private const NEW_MAX = 2000;

    public function __construct(private NotificationService $notifications)
    {
    }

    // ─────────────────────────── Un duel se termine ───────────────────────────

    public function matchClosed(int $matchId): void
    {
        $m = CompetitionMatch::with(['competition', 'entryA.participant', 'entryB.participant'])->find($matchId);
        if (! $this->on() || ! $m || $m->is_draw || ! $m->winner_entry_id || ! $m->competition) {
            return;
        }
        $c = $m->competition;
        $title = Str::limit($c->title, 40);
        $win = (int) $m->winner_entry_id === (int) $m->entry_a_id ? $m->entryA : $m->entryB;
        $lose = $win === $m->entryA ? $m->entryB : $m->entryA;
        if (! $win) {
            return;
        }
        $tournament = $c->type === Competition::TYPE_TOURNAMENT;
        $last = $tournament && (int) $m->round >= $c->totalRounds();

        // Les votants du gagnant (sauf en finale : les résultats de la compétition suivent)
        if (! $last) {
            $this->tell($this->voters($win, $m->id), 'result', 'Ton vote a compté',
                $win->displayName().', pour qui tu as voté, gagne son duel dans « '.$title.' ».'.($tournament ? ' Il passe au tour suivant : continue de le soutenir.' : ''),
                $tournament ? $c->url() : $m->url(), $win->participant);
        }
        // Les votants du perdant, seulement quand il est éliminé d'un tournoi
        if ($tournament && ! $last && $lose) {
            $this->tell($this->voters($lose, $m->id), 'result', 'Fin de parcours',
                $lose->displayName().', pour qui tu as voté, est éliminé de « '.$title.' ». Découvre qui continue.', $c->url(), $lose->participant);
        }
    }

    // ─────────────────────────── La compétition est terminée ───────────────────────────

    public function competitionCompleted(int $competitionId): void
    {
        $c = Competition::with('winnerEntry')->find($competitionId);
        if (! $this->on() || ! $c || ! in_array($c->type, [Competition::TYPE_CONTEST, Competition::TYPE_TOURNAMENT], true)) {
            return;
        }
        $title = Str::limit($c->title, 40);
        $winner = $c->winnerEntry;
        $entries = $c->entries()->with('participant')->get()->keyBy('id');
        // Ceux qui jouaient ont déjà leur résultat
        $players = $entries->flatMap(fn (CompetitionEntry $e) => $e->stakeholderIds())->unique()->all();

        // Pour chaque votant, le candidat le mieux classé parmi ceux qu'il a soutenus
        $best = [];
        CompetitionVote::query()->where('competition_id', $c->id)->where('is_jury', false)
            ->select('user_id', 'entry_id')->distinct()->get()
            ->each(function ($v) use ($entries, &$best) {
                $e = $entries[$v->entry_id] ?? null;
                if (! $e) {
                    return;
                }
                $rank = (int) ($e->rank ?: 9999);
                if (! isset($best[$v->user_id]) || $rank < $best[$v->user_id][0]) {
                    $best[$v->user_id] = [$rank, $e];
                }
            });

        $groups = [];
        foreach ($best as $uid => [$rank, $e]) {
            if (in_array((int) $uid, $players, true)) {
                continue;
            }
            $groups[$e->id][] = (int) $uid;
        }
        foreach ($groups as $entryId => $ids) {
            $e = $entries[$entryId];
            $isWinner = $winner && (int) $winner->id === (int) $e->id;
            if ($isWinner) {
                $this->tell($ids, 'result', 'Ton favori a gagné', $e->displayName().', pour qui tu as voté, remporte « '.$title.' ».', $c->url(), $e->participant);
            } else {
                $place = $e->rank ? Pulse::place((int) $e->rank) : null;
                $this->tell($ids, 'result', 'Résultats : « '.$title.' »',
                    $e->displayName().', pour qui tu as voté'.($place ? ', finit '.$place : ', n\'a pas gagné cette fois').'.'.($winner ? ' Le gagnant : '.$winner->displayName().'.' : ''),
                    $c->url(), $winner?->participant ?? $e->participant);
            }
        }
    }

    // ─────────────────────────── Concours : quelqu'un prend la tête ───────────────────────────

    public function leaderChanged(int $competitionId, int $newLeaderId, int $oldLeaderId): void
    {
        $c = Competition::find($competitionId);
        $new = CompetitionEntry::with('participant')->find($newLeaderId);
        $old = CompetitionEntry::with('participant')->find($oldLeaderId);
        if (! $this->on() || ! $c || ! $new || ! $old) {
            return;
        }
        $title = Str::limit($c->title, 40);
        // Une seule alerte de ce genre par concours et par personne toutes les 6 heures
        $fresh = fn (array $ids) => array_values(array_filter($ids, fn ($id) => Cache::add('sup:lead:'.$c->id.':'.$id, 1, now()->addHours(6))));
        $this->tell($fresh($this->voters($old)), 'progress', 'Ton favori est dépassé',
            $old->displayName().', pour qui tu as voté, vient d\'être dépassé par '.$new->displayName().' dans « '.$title.' ». Partage pour l\'aider à reprendre la tête.',
            $c->url(), $new->participant);
        $this->tell($fresh($this->voters($new)), 'progress', 'Ton favori passe premier',
            $new->displayName().', pour qui tu as voté, prend la tête de « '.$title.' ».', $c->url(), $new->participant);
    }

    // ─────────────────────────── Ton favori participe à nouveau ───────────────────────────

    public function favoriteJoined(int $entryId): void
    {
        $e = CompetitionEntry::with(['participant', 'competition'])->find($entryId);
        if (! $this->on() || ! $e || ! $e->participant || ! $e->competition || $e->competition->hidden_at) {
            return;
        }
        $c = $e->competition;
        // Ceux qui ont voté pour cette personne (ou cet Espace) dans d'autres compétitions
        $ids = CompetitionVote::query()->where('is_jury', false)->where('competition_id', '!=', $c->id)
            ->whereIn('entry_id', CompetitionEntry::query()->where('participant_type', $e->participant_type)->where('participant_id', $e->participant_id)->select('id'))
            ->distinct()->limit(1000)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $ids = array_values(array_diff($ids, $e->stakeholderIds(), NotificationService::recipientsFor($c->organizer)));
        $this->tell($ids, 'favorite', 'Ton favori est de retour',
            $e->displayName().', pour qui tu as déjà voté, participe à « '.Str::limit($c->title, 40).' ». Viens le soutenir.', $c->url(), $e->participant);
    }

    // ─────────────────────────── Une nouvelle compétition est lancée ───────────────────────────

    public function newCompetition(int $competitionId): void
    {
        $c = Competition::with('organizer')->find($competitionId);
        if (! $this->on() || ! $c || $c->hidden_at || $c->access === 'invited'
            || ! in_array($c->type, [Competition::TYPE_CONTEST, Competition::TYPE_TOURNAMENT], true)
            || in_array($c->status, [Competition::STATUS_COMPLETED, Competition::STATUS_CANCELLED], true)) {
            return;
        }
        $since = now()->subDays(90);
        // Ceux qui ont déjà voté ou participé, même une seule fois
        $voters = CompetitionVote::query()->where('created_at', '>=', $since)->distinct()->pluck('user_id');
        $players = CompetitionEntry::query()->where('created_at', '>=', $since)->whereNotNull('submitted_by')->distinct()->pluck('submitted_by');
        $ids = $voters->merge($players)->map(fn ($id) => (int) $id)->unique()
            ->diff(array_merge(NotificationService::recipientsFor($c->organizer), [(int) $c->created_by]))->values();
        if ($ids->isEmpty()) {
            return;
        }
        // D'abord ceux que ça intéresse (même discipline, même ville), puis les autres
        $users = User::query()->whereIn('id', $ids)->where('is_active', true)->get(['id', 'interests', 'city']);
        $score = fn (User $u) => (in_array($c->category, (array) ($u->interests ?? []), true) ? 2 : 0) + ($c->geo_city && $u->city === $c->geo_city ? 1 : 0);
        $ordered = $users->sortByDesc($score)->take(self::NEW_MAX)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $kind = $c->type === Competition::TYPE_TOURNAMENT ? 'tournoi' : 'concours';
        $by = $c->organizer ? ' par '.\App\Support\Participant::name($c->organizer) : '';
        $this->tell($ordered, 'new', 'Nouveau '.$kind,
            '« '.Str::limit($c->title, 50).' » vient d\'être lancé'.$by.'. Inscris-toi ou viens voter.', $c->url(), $c->organizer);
    }

    // ─────────────────────────────────────────────────────────────

    private function on(): bool
    {
        return Features::on('supporter_alerts');
    }

    /** Les votants (hors jury) d'une participation ; dans un seul duel si $matchId. @return array<int, int> */
    private function voters(CompetitionEntry $e, ?int $matchId = null): array
    {
        return CompetitionVote::query()->where('entry_id', $e->id)->where('is_jury', false)
            ->when($matchId, fn ($q) => $q->where('match_id', $matchId))
            ->distinct()->limit(5000)->pluck('user_id')->map(fn ($id) => (int) $id)
            ->diff($e->stakeholderIds())->values()->all();
    }

    /** Envoie à ceux qui n'ont pas déjà eu leur part de relances de cette sorte. */
    private function tell(array $ids, string $kind, string $title, string $body, string $url, $actor = null): int
    {
        [$max, $hours] = self::BUDGET[$kind];
        $ok = [];
        foreach (array_unique($ids) as $id) {
            $key = 'sup:'.$kind.':'.$id;
            $n = (int) Cache::get($key, 0);
            if ($n >= $max) {
                continue;
            }
            $n === 0 ? Cache::put($key, 1, now()->addHours($hours)) : Cache::increment($key);
            $ok[] = (int) $id;
        }
        if ($ok) {
            $type = ['result' => 'supported.result', 'progress' => 'supported.progress', 'favorite' => 'favorite.joined', 'new' => 'competition.new'][$kind];
            $this->notifications->send($ok, $type, $title, $body, $url, $actor);
        }

        return count($ok);
    }
}
