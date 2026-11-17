<?php

namespace App\Services;

use App\Exceptions\CompetitionException;
use App\Models\Space;
use App\Models\User;
use App\Services\Social\NotificationService;
use App\Support\Participant;
use App\Support\Presenter;

/**
 * Membres et équipe d'un Espace (site et app) :
 * rejoindre (ou demander), accepter / refuser, ajouter, retirer, donner un rôle.
 * Rôles : Publieur (viewer), Modérateur (editor), Administrateur (admin) — voir Space::ROLES.
 */
class SpaceTeam
{
    public function __construct(private NotificationService $notifications)
    {
    }

    /** Rejoindre, demander à rejoindre, annuler sa demande ou quitter. Renvoie [état, message]. */
    public function toggle(Space $space, User $me): array
    {
        if ($space->isMember($me)) {
            if ((int) $space->owner_id === (int) $me->id) {
                throw new CompetitionException('Le créateur ne peut pas quitter son Espace.');
            }
            $space->removeManager($me);
            $space->removeMember($me);

            return ['left', 'Tu as quitté '.$space->name.'.'];
        }
        if ($space->isInvited($me)) {
            return $this->respondInvite($space, $me, true);
        }
        if ($space->isPending($me)) {
            $space->removeMember($me);

            return ['cancelled', 'Demande annulée.'];
        }
        if ($space->join_approval) {
            $space->requestMembership($me);
            $this->notify(NotificationService::recipientsFor($space), 'space.request', 'Demande pour rejoindre',
                Participant::name($me).' demande à rejoindre '.$space->name.'.', '/espaces/'.$space->slug.'/membres', $me);

            return ['requested', 'Demande envoyée. Un administrateur ou un modérateur de '.$space->name.' va répondre.'];
        }
        $space->addMember($me);
        app(\App\Services\Social\SocialHooks::class)->joinedSpace($me, $space);

        return ['joined', 'Bienvenue dans '.$space->name.' !'];
    }

    /** Accepter ou refuser une demande. */
    public function decide(Space $space, User $mod, User $who, bool $accept): string
    {
        $this->mustModerate($space, $mod);
        if (! $space->isPending($who)) {
            throw new CompetitionException('Cette demande n\'existe plus.');
        }
        if (! $accept) {
            $space->removeMember($who);

            return 'Demande refusée.';
        }
        $space->addMember($who);
        $this->notify([$who->id], 'space.accepted', 'Bienvenue !', 'Ta demande pour rejoindre '.$space->name.' est acceptée.', '/espaces/'.$space->slug, $space);

        return Participant::name($who).' fait maintenant partie de l\'Espace.';
    }

    /**
     * Inviter quelqu'un (par son @pseudo) : il entre seulement s'il accepte.
     * S'il avait déjà demandé à rejoindre, l'invitation vaut acceptation. Renvoie [personne, message].
     */
    public function add(Space $space, User $mod, string $handle): array
    {
        $this->mustModerate($space, $mod);
        $who = User::where('username', ltrim(trim($handle), '@'))->first();
        if (! $who) {
            throw new CompetitionException('Personne ne s\'appelle @'.ltrim(trim($handle), '@').' sur Sosa.');
        }
        if ($space->isMember($who)) {
            throw new CompetitionException(Participant::name($who).' fait déjà partie de l\'Espace.');
        }
        if ($space->isInvited($who)) {
            throw new CompetitionException(Participant::name($who).' a déjà une invitation en attente.');
        }
        if ($space->isPending($who)) {
            return [$who, $this->decide($space, $mod, $who, true)];
        }
        $space->inviteMember($who, $mod);
        $this->notify([$who->id], 'space.invite', 'Invitation', Participant::name($mod).' t\'invite à rejoindre '.$space->name.'.', '/defis-recus', $space);

        return [$who, 'Invitation envoyée à '.Participant::name($who).'. Il rejoindra l\'Espace s\'il accepte.'];
    }

    /** La personne invitée accepte ou refuse. Renvoie [état, message]. */
    public function respondInvite(Space $space, User $me, bool $accept): array
    {
        if (! $space->isInvited($me)) {
            throw new CompetitionException('Cette invitation n\'existe plus.');
        }
        $by = $space->invitedMembers()->where('users.id', $me->id)->first()?->pivot?->invited_by;
        if (! $accept) {
            $space->removeMember($me);

            return ['declined', 'Invitation refusée.'];
        }
        $space->addMember($me);
        app(\App\Services\Social\SocialHooks::class)->joinedSpace($me, $space);
        if ($by) {
            $this->notify([$by], 'space.joined', 'Invitation acceptée', Participant::name($me).' a rejoint '.$space->name.'.', '/espaces/'.$space->slug, $me);
        }

        return ['joined', 'Bienvenue dans '.$space->name.' !'];
    }

    /** Retirer un membre (et son rôle). Un modérateur ne retire pas l'équipe : seul un administrateur le fait. */
    public function remove(Space $space, User $mod, User $who): void
    {
        $this->mustModerate($space, $mod);
        $role = $space->roleOf($who);
        if ($role === 'invited') { // annuler une invitation
            $space->removeMember($who);

            return;
        }
        if ($role === 'owner') {
            throw new CompetitionException('Le créateur de l\'Espace ne peut pas être retiré.');
        }
        if (in_array($role, ['admin', 'editor', 'viewer'], true) && ! $space->canEditInfo($mod)) {
            throw new CompetitionException('Seul un administrateur peut retirer quelqu\'un de l\'équipe.');
        }
        $space->removeManager($who);
        $space->removeMember($who);
    }

    /** Donner un rôle (ou le retirer avec null). Administrateurs seulement. */
    public function setRole(Space $space, User $admin, User $who, ?string $role): string
    {
        if (! $space->canEditInfo($admin)) {
            throw new CompetitionException('Seul un administrateur donne les rôles.', 403);
        }
        if ((int) $space->owner_id === (int) $who->id) {
            throw new CompetitionException('Le créateur reste administrateur.');
        }
        if ($role !== null && ! array_key_exists($role, Space::ROLES)) {
            throw new CompetitionException('Rôle inconnu.');
        }
        if ($role === null) {
            $space->removeManager($who);

            return Participant::name($who).' redevient simple membre.';
        }
        $space->addMember($who);
        $space->isManager($who) ? $space->updateManagerRole($who, $role) : $space->addManager($who, $role);
        $this->notify([$who->id], 'space.role', 'Nouveau rôle', 'Tu es maintenant '.mb_strtolower(Space::ROLES[$role]).' de '.$space->name.'.', '/espaces/'.$space->slug, $space);

        return Participant::name($who).' est maintenant '.mb_strtolower(Space::ROLES[$role]).'.';
    }

    /** Tout ce qu'il faut pour l'écran « Membres » (site et app). */
    public function data(Space $space, User $viewer): array
    {
        $this->mustModerate($space, $viewer);
        $roles = $space->managers()->get()->mapWithKeys(fn ($u) => [$u->id => $u->pivot->role])->all();
        $person = function (User $u, ?string $role = null) use ($space) {
            $role ??= (int) $space->owner_id === (int) $u->id ? 'owner' : 'member';

            return Participant::present($u) + ['username' => $u->username, 'role' => $role,
                'role_label' => $role === 'owner' ? 'Créateur' : (Space::ROLES[$role] ?? 'Membre')];
        };
        $owner = $space->owner;
        $members = $space->members()->orderBy('first_name')->limit(500)->get()
            ->reject(fn ($u) => (int) $u->id === (int) $space->owner_id)
            ->map(fn ($u) => $person($u, $roles[$u->id] ?? 'member'));
        $order = ['owner' => 0, 'admin' => 1, 'editor' => 2, 'viewer' => 3, 'member' => 4];
        $all = collect($owner ? [$person($owner, 'owner')] : [])->concat($members)
            ->sortBy(fn ($p) => [$order[$p['role']] ?? 9, mb_strtolower($p['name'] ?? '')])->values();

        return [
            'space' => Participant::present($space) + ['slug' => $space->slug, 'join_approval' => (bool) $space->join_approval],
            'my_role' => $space->roleOf($viewer),
            'can_edit' => $space->canEditInfo($viewer),
            'roles' => collect(Space::ROLES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label, 'help' => [
                'viewer' => 'Publie au nom de l\'Espace.',
                'editor' => 'Publie et valide : membres, candidatures, invitations.',
                'admin' => 'Tout, dont le nom, le logo, la couverture et les rôles.',
            ][$value]])->values()->all(),
            'pending' => $space->pendingMembers()->latest('space_members.created_at')->limit(200)->get()
                ->map(fn ($u) => Participant::present($u) + ['username' => $u->username, 'at' => Presenter::date($u->pivot->created_at)])->values()->all(),
            'invited' => $space->invitedMembers()->latest('space_members.created_at')->limit(200)->get()
                ->map(fn ($u) => Participant::present($u) + ['username' => $u->username, 'at' => Presenter::date($u->pivot->created_at)])->values()->all(),
            'members' => $all->all(),
            'count' => $all->count(),
        ];
    }

    private function mustModerate(Space $space, User $user): void
    {
        if (! $space->canModerate($user)) {
            throw new CompetitionException('Réservé aux administrateurs et modérateurs de l\'Espace.', 403);
        }
    }

    private function notify(array $ids, string $type, string $title, string $body, string $url, $actor): void
    {
        try {
            $this->notifications->send($ids, $type, $title, $body, $url, $actor);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
