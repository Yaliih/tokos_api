<?php

namespace App\Support;

use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\CompetitionInvitation;
use App\Models\Space;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * « Reçus » : ce qui attend ma réponse (différent des notifications, qui informent seulement).
 *  - défis et duels qu'on m'a lancés (à moi ou à un Espace que je gère), dont les demandes de revanche ;
 *  - invitations à participer à un concours ou un tournoi ;
 *  - candidatures à valider dans les compétitions que j'organise ;
 *  - demandes pour rejoindre un Espace que j'administre ou modère.
 */
class Inbox
{
    public static function invitations(User $me): Collection
    {
        $spaceIds = $me->manageableSpaceIds();

        return CompetitionInvitation::with(['competition.organizer', 'invitee', 'inviter'])->where('status', 'pending')
            ->where(function ($q) use ($me, $spaceIds) {
                $q->where(fn ($x) => $x->where('invitee_type', User::class)->where('invitee_id', $me->id))
                    ->orWhere(fn ($x) => $x->where('invitee_type', Space::class)->whereIn('invitee_id', $spaceIds ?: [0]));
            })->latest()->limit(100)->get()
            ->filter(fn ($i) => $i->competition && ! in_array($i->competition->status, [Competition::STATUS_COMPLETED, Competition::STATUS_CANCELLED], true))
            ->values();
    }

    /** Candidatures en attente dans mes compétitions (ou celles de mes Espaces). */
    public static function candidates(User $me): Collection
    {
        $spaceIds = $me->manageableSpaceIds();
        $mine = Competition::query()->where('requires_approval', true)
            ->whereNotIn('status', [Competition::STATUS_COMPLETED, Competition::STATUS_CANCELLED])
            ->where(fn ($q) => $q->where('created_by', $me->id)
                ->orWhere(fn ($x) => $x->where('organizer_type', Space::class)->whereIn('organizer_id', $spaceIds ?: [0])))
            ->pluck('id');
        if ($mine->isEmpty()) {
            return collect();
        }

        return CompetitionEntry::with(['competition.organizer', 'participant'])->whereIn('competition_id', $mine)
            ->where('status', 'pending')->latest()->limit(100)->get()->filter(fn ($e) => $e->competition && $e->participant)->values();
    }

    /** Demandes d'adhésion en attente dans les Espaces que j'administre ou modère. */
    public static function memberRequests(User $me): Collection
    {
        $spaceIds = $me->manageableSpaceIds();
        if (! $spaceIds) {
            return collect();
        }

        return \Illuminate\Support\Facades\DB::table('space_members')->whereIn('space_id', $spaceIds)->where('is_accepted', false)
            ->whereNull('invited_by')->orderByDesc('created_at')->limit(100)->get()
            ->map(function ($row) {
                $space = Space::find($row->space_id);
                $user = User::find($row->user_id);

                return $space && $user ? (object) ['space' => $space, 'user' => $user, 'at' => $row->created_at ? \Illuminate\Support\Carbon::parse($row->created_at) : null] : null;
            })->filter()->values();
    }

    /** Invitations à rejoindre un Espace, reçues par moi. */
    public static function spaceInvites(User $me): Collection
    {
        return \Illuminate\Support\Facades\DB::table('space_members')->where('user_id', $me->id)->where('is_accepted', false)
            ->whereNotNull('invited_by')->orderByDesc('created_at')->limit(50)->get()
            ->map(function ($row) {
                $space = Space::where('id', $row->space_id)->where('is_active', true)->first();

                return $space ? (object) ['space' => $space, 'by' => User::find($row->invited_by), 'at' => $row->created_at ? \Illuminate\Support\Carbon::parse($row->created_at) : null] : null;
            })->filter()->values();
    }

    public static function count(User $me): int
    {
        return self::invitations($me)->count() + self::candidates($me)->count() + self::memberRequests($me)->count() + self::spaceInvites($me)->count();
    }

    /** Tout, du plus récent au plus ancien, prêt à afficher (site et app). */
    public static function items(User $me): array
    {
        $inv = self::invitations($me)->map(function (CompetitionInvitation $i) use ($me) {
            $c = $i->competition;
            $duel = in_array($c->type, [Competition::TYPE_DUEL, Competition::TYPE_CHALLENGE], true);
            $rematch = ! empty(($c->settings ?? [])['rematch_of']);
            $toSpace = $i->invitee instanceof Space;

            return [
                'key' => 'i'.$i->id,
                'kind' => $duel ? 'challenge' : 'invite',
                'id' => $i->id,
                'label' => $rematch ? 'Revanche' : ($duel ? ($c->type === Competition::TYPE_DUEL ? 'Duel' : 'Défi') : $c->typeLabel()),
                'text' => $rematch ? 'réclame une revanche'.($toSpace ? ' à '.Participant::name($i->invitee) : '')
                    : ($duel ? 'défie '.($toSpace ? Participant::name($i->invitee) : 'toi')
                        : 'invite '.($toSpace ? Participant::name($i->invitee) : 'toi').' '.($c->type === Competition::TYPE_TOURNAMENT ? 'au tournoi' : 'au concours')),
                'from' => Participant::present($i->inviter),
                'message' => $i->message,
                'title' => $c->title,
                'url' => $c->url(),
                'competition_slug' => $c->slug,
                'at' => Presenter::date($i->created_at),
                'sort' => $i->created_at?->timestamp ?? 0,
            ];
        });
        $cand = self::candidates($me)->map(fn (CompetitionEntry $e) => [
            'key' => 'e'.$e->id,
            'kind' => 'candidate',
            'id' => $e->id,
            'label' => 'Candidature',
            'text' => 'veut participer à',
            'from' => Participant::present($e->participant),
            'message' => $e->content ? \Illuminate\Support\Str::limit($e->content, 140) : null,
            'title' => $e->competition->title,
            'url' => $e->competition->url(),
            'competition_slug' => $e->competition->slug,
            'at' => Presenter::date($e->created_at),
            'sort' => $e->created_at?->timestamp ?? 0,
        ]);

        $req = self::memberRequests($me)->map(fn ($r) => [
            'key' => 's'.$r->space->id.'-'.$r->user->id,
            'kind' => 'member',
            'id' => $r->user->id,
            'label' => 'Espace',
            'text' => 'demande à rejoindre',
            'from' => Participant::present($r->user),
            'message' => null,
            'title' => $r->space->name,
            'url' => '/espaces/'.$r->space->slug,
            'space_slug' => $r->space->slug,
            'competition_slug' => null,
            'at' => Presenter::date($r->at),
            'sort' => $r->at?->timestamp ?? 0,
        ]);

        $sinv = self::spaceInvites($me)->map(fn ($r) => [
            'key' => 'si'.$r->space->id,
            'kind' => 'space_invite',
            'id' => $r->space->id,
            'label' => 'Espace',
            'text' => 't\'invite à rejoindre',
            'from' => $r->by ? Participant::present($r->by) : Participant::present($r->space),
            'message' => null,
            'title' => $r->space->name,
            'url' => '/espaces/'.$r->space->slug,
            'space_slug' => $r->space->slug,
            'competition_slug' => null,
            'at' => Presenter::date($r->at),
            'sort' => $r->at?->timestamp ?? 0,
        ]);

        return $inv->concat($cand)->concat($req)->concat($sinv)->sortByDesc('sort')->values()->map(fn ($x) => collect($x)->except('sort')->all())->all();
    }
}
