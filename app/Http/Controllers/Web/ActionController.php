<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\CompetitionException;
use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\CompetitionInvitation;
use App\Models\CompetitionMatch;
use App\Models\EntryProposal;
use App\Models\PostComment;
use App\Models\PostLike;
use App\Models\Reaction;
use App\Models\Space;
use App\Models\User;
use App\Services\Competition\CompetitionService;
use App\Services\Competition\VoteService;
use App\Services\Social\SocialHooks;
use App\Support\Presenter;
use Illuminate\Http\Request;

/**
 * Actions du site web. Chaque action marche en formulaire classique,
 * et renvoie du JSON quand elle est appelée en arrière-plan par tokos.js.
 */
class ActionController extends Controller
{
    public function __construct(
        private CompetitionService $competitions,
        private VoteService $votes,
        private SocialHooks $hooks,
    ) {
    }

    private function done(Request $request, string $message, ?string $redirect = null, array $json = [])
    {
        if ($request->expectsJson()) {
            return response()->json(array_merge(['success' => true, 'message' => $message], $json));
        }

        return ($redirect ? redirect($redirect) : back())->with('ok', $message);
    }

    /** Recherche de lieux pour la zone d'une compétition (ville, province, pays). */
    public function places(Request $request)
    {
        return response()->json(['items' => \App\Support\PlaceSearch::search((string) $request->query('q'))]);
    }

    /** Position du téléphone → ville de la personne (on ne garde pas les coordonnées). */
    public function locate(Request $request)
    {
        $data = $request->validate([
            'lat' => 'required|numeric|between:-90,90', 'lng' => 'required|numeric|between:-180,180',
            // Ce que le téléphone a déjà trouvé (OpenStreetMap depuis le navigateur), vérifié côté serveur
            'city_hint' => 'nullable|string|max:80', 'country_hint' => 'nullable|string|max:80', 'country_code' => 'nullable|string|max:2',
            'state_hint' => 'nullable|string|max:80', 'county_hint' => 'nullable|string|max:80',
            // Marge d'erreur donnée par l'appareil, en mètres
            'accuracy' => 'required|numeric|min:0',
        ]);
        // Position devinée d'après la connexion internet (ordinateur sans GPS, localisation coupée) : refusée
        $max = (int) config('places.max_accuracy_m');
        if ((float) $data['accuracy'] > $max) {
            $km = (float) $data['accuracy'] >= 2000 ? 'à '.round($data['accuracy'] / 1000).' km près' : 'à '.round($data['accuracy']).' m près';
            throw new CompetitionException('Position trop imprécise ('.$km.') : elle vient de ta connexion internet, pas de ton appareil. '
                .'Active la localisation : sur téléphone, le GPS ; sur ordinateur Windows, Paramètres → Confidentialité et sécurité → Localisation. Puis réessaie.', 422, 'imprecise');
        }
        $where = \App\Support\Geo::locate((float) $data['lat'], (float) $data['lng'], [
            'city' => $data['city_hint'] ?? null, 'country' => $data['country_hint'] ?? null, 'country_code' => $data['country_code'] ?? null,
            'state' => $data['state_hint'] ?? null, 'county' => $data['county_hint'] ?? null,
        ]);
        if (! $where['city'] && ! $where['country']) {
            throw new CompetitionException('On ne reconnaît pas l\'endroit où tu es. Vérifie que la localisation (GPS) est activée, puis réessaie.');
        }
        $u = $request->user();
        // La position fait foi : ville (même inconnue) et pays d'aujourd'hui
        $u->forceFill(['city' => $where['city'], 'country' => $where['country'] ?? $u->country, 'city_located_at' => now()])->save();
        // Position du moment gardée dans la session (pas en base) pour vérifier les zones pendant quelques minutes
        \App\Support\Geo::remember((float) $data['lat'], (float) $data['lng'], (float) $data['accuracy'], $where['country_code'] ?? null, $where['city'], $where['state'] ?? null, $where['county'] ?? null);
        $label = implode(', ', array_filter([$where['city'], $where['country']]));

        return $this->done($request, 'Position vérifiée : '.$label, null, ['city' => $where['city'], 'country' => $where['country'], 'label' => $label]);
    }

    /** Ta ville (rappel de l'accueil, à part des centres d'intérêt). « plus tard » repousse le rappel. */
    public function saveCity(Request $request)
    {
        $data = $request->validate(['city' => 'nullable|string|max:60', 'later' => 'nullable|boolean']);
        $u = $request->user();
        $u->city_asked_at = now();
        if (! $request->boolean('later') && ! empty($data['city']) && ! \App\Support\Geo::fresh($u)) {
            $u->city = \App\Support\Geo::canonical($data['city']);
        }
        $u->save();

        return $this->done($request, $request->boolean('later') ? 'D\'accord, plus tard.' : 'Ville enregistrée.');
    }

    /** Centres d'intérêt : depuis le rappel de l'accueil ou les paramètres. « plus tard » repousse le rappel. */
    public function saveInterests(Request $request)
    {
        $keys = array_keys(config('competition.categories'));
        $data = $request->validate([
            'interests' => 'nullable|array|max:12', 'interests.*' => 'in:'.implode(',', $keys),
            'later' => 'nullable|boolean',
        ]);
        $u = $request->user();
        $u->interests_asked_at = now();
        if (! $request->boolean('later')) {
            $u->interests = array_values($data['interests'] ?? []);
        }
        $u->save();

        return $this->done($request, $request->boolean('later') ? 'D\'accord, on te redemandera plus tard.' : 'C\'est noté : ton fil s\'adapte.');
    }

    // ── Création & participation ────────────────────────────────

    public function store(Request $request)
    {
        $input = $request->all();
        if (($input['type'] ?? null) !== 'duel') {
            unset($input['format']);
        }
        // Une seule durée selon le format (le formulaire a un choix par format)
        $hours = ['duel' => 'duel_hours', 'challenge' => 'challenge_hours', 'tournament' => 'round_hours'][$input['type'] ?? ''] ?? null;
        if ($hours && ! empty($input[$hours])) {
            $input[($input['type'] === 'tournament') ? 'match_duration_hours' : 'duration_hours'] = (int) $input[$hours];
        }
        unset($input['duel_hours'], $input['challenge_hours'], $input['round_hours']);
        foreach (['media', 'side_a_media', 'side_b_media'] as $k) {
            // Sélecteur vide : on ne garde que les médias réellement choisis
            if (isset($input[$k]) && is_array($input[$k])) {
                $input[$k] = array_values(array_filter($input[$k], fn ($m) => ($m['file'] ?? null) instanceof \Illuminate\Http\UploadedFile));
                if (! $input[$k]) {
                    unset($input[$k]);
                }
            }
        }
        if (empty($input['cover']) || ! $input['cover'] instanceof \Illuminate\Http\UploadedFile) {
            unset($input['cover'], $input['cover_duration']);
        }
        foreach (['registration_ends_at', 'starts_at', 'ends_at'] as $k) {
            if (empty($input[$k])) {
                unset($input[$k]);
            }
        }
        $input['invitees'] = collect(preg_split('/[\s,]+/', (string) $request->input('invitees_text'), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($h) => ['type' => str_starts_with($h, '#') ? 'space' : 'user', 'handle' => ltrim($h, '@#')])->all() ?: null;
        $input['jurors'] = collect(preg_split('/[\s,]+/', (string) $request->input('jurors_text'), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($h) => ltrim($h, '@'))->all() ?: null;
        // Sans juré, seul le public compte ; « Jury seul » : le public ne vote pas
        if (! $input['jurors']) {
            unset($input['jury_weight']);
        } elseif ((int) ($input['jury_weight'] ?? 0) >= 100) {
            $input['vote_audience'] = 'jury';
        }
        $input = array_filter($input, fn ($v) => $v !== null && $v !== '');
        // Défi ou duel en texte, écrit sur une seule ligne passée en titre : ce titre est aussi la performance
        if (in_array($input['type'] ?? null, ['challenge', 'duel'], true) && ($input['format'] ?? 'challenge') !== 'versus'
            && empty($input['content']) && empty($input['media']) && ! empty($input['title'])) {
            $input['content'] = $input['title'];
        }

        // Duel : on peut défier plusieurs personnes d'un coup ; chacune reçoit son propre duel
        $isDuelTo = ($input['type'] ?? null) === 'duel' && ($input['format'] ?? 'challenge') !== 'versus';
        [$made, $failed] = $isDuelTo ? $this->competitions->createDuels($request->user(), $input) : [[$this->competitions->create($request->user(), $input)], []];
        $c = $made[0];
        // Bon moment pour proposer les notifications (le navigateur ne les demande qu'après un geste)
        session()->flash('push_nudge', true);
        if (count($made) > 1) {
            $sms = \App\Models\CompetitionPhoneInvite::whereIn('competition_id', collect($made)->pluck('id'))->get();
            return redirect($c->url())->with('ok', count($made).' duels envoyés : chacun a reçu son défi'
                .($sms->isNotEmpty() ? ' ('.$sms->count().' par SMS)' : '').'.'
                .($failed ? ' Pas envoyé à '.implode(' ; ', $failed) : ''));
        }

        $invite = \App\Models\CompetitionPhoneInvite::where('competition_id', $c->id)->latest('id')->first();
        if ($invite) {
            return redirect($c->url())
                ->with('ok', $invite->sms_sent_at ? 'Duel lancé ! Le défi est parti par SMS au '.$invite->fullPhone().'.' : 'Duel lancé ! Envoie-lui le défi sur WhatsApp.')
                ->with('whatsapp', $invite->whatsappUrl());
        }

        return redirect($c->url())->with('ok', match ($c->type) {
            Competition::TYPE_CHALLENGE => 'Défi lancé ! Partage-le pour attirer des challengers.',
            Competition::TYPE_DUEL => 'Duel lancé ! Partage-le pour récolter des votes.',
            Competition::TYPE_TOURNAMENT => 'Tournoi publié ! Partage-le pour remplir le tableau.',
            default => 'Concours publié ! Partage-le pour attirer des candidats.',
        });
    }

    public function enter(Request $request, Competition $competition)
    {
        $entry = $this->competitions->submitEntry($request->user(), $competition, $request->all());

        return redirect($competition->url())->with('ok', $entry->status === 'pending'
            ? 'Candidature envoyée. L\'organisateur va la valider.'
            : ($competition->type === Competition::TYPE_CHALLENGE ? 'Défi relevé ! Ton duel a commencé.' : 'Tu participes !'));
    }

    public function withdraw(Request $request, Competition $competition, CompetitionEntry $entry)
    {
        abort_unless((int) $entry->competition_id === (int) $competition->id, 404);
        $this->competitions->withdrawEntry($request->user(), $entry);

        return $this->done($request, 'Participation retirée.');
    }

    public function manageEntry(Request $request, Competition $competition, CompetitionEntry $entry)
    {
        abort_unless((int) $entry->competition_id === (int) $competition->id, 404);
        $request->input('decision') === 'reject'
            ? $this->competitions->rejectEntry($request->user(), $entry, $request->input('reason'))
            : $this->competitions->approveEntry($request->user(), $entry);

        return $this->done($request, $request->input('decision') === 'reject' ? 'Candidature refusée.' : 'Candidature acceptée.');
    }

    public function start(Request $request, Competition $competition)
    {
        $this->competitions->start($request->user(), $competition);

        return $this->done($request, 'C\'est parti !', $competition->url());
    }

    /** La remise du prix filmée (vidéo ou photo + un mot), une fois la compétition terminée. */
    public function prizeProof(Request $request, Competition $competition, \App\Services\Competition\PrizeProof $proof)
    {
        $request->validate([
            'files' => 'required|array|min:1|max:4',
            'files.*' => 'file|max:'.\App\Services\MediaUploader::maxKb().'|mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm,video/3gpp,application/octet-stream',
            'text' => 'nullable|string|max:500',
        ], ['files.required' => 'Ajoute la vidéo (ou une photo) du gagnant qui reçoit son prix.']);
        $proof->publish($request->user(), $competition, (array) $request->file('files'), $request->input('text'));

        return $this->done($request, 'Remise du prix publiée. Merci !', $competition->url().'#remise');
    }

    public function cancel(Request $request, Competition $competition)
    {
        $this->competitions->cancel($request->user(), $competition, $request->input('reason'));

        return $this->done($request, 'Compétition annulée.', $competition->url());
    }

    /** Modifier sa publication, selon ce que permet CompetitionEditor (rien après les premiers votes pour un défi ou un duel). */
    public function edit(Request $request, Competition $competition, \App\Services\Competition\CompetitionEditor $editor)
    {
        $editor->update($request->user(), $competition, $request->only(['title', 'text', 'category', 'rules', 'prize']));

        return $this->done($request, 'Publication modifiée.', $competition->url());
    }

    public function invite(Request $request, Competition $competition)
    {
        $list = collect(preg_split('/[\s,]+/', (string) $request->input('handles'), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($h) => ['type' => str_starts_with($h, '#') ? 'space' : 'user', 'handle' => ltrim($h, '@#')])->all();
        if (! $list) {
            throw new CompetitionException('Indique au moins un @pseudo ou un #espace.');
        }
        $n = $this->competitions->invite($request->user(), $competition, $list, $request->input('message'));

        return $this->done($request, $n.' invitation(s) envoyée(s).');
    }

    public function addCandidate(Request $request, Competition $competition)
    {
        $this->competitions->addCandidate($request->user(), $competition, $request->all());

        return $this->done($request, 'Candidat ajouté.');
    }

    public function propose(Request $request, Competition $competition)
    {
        $space = Space::where('slug', $request->input('space'))->firstOrFail();
        $this->competitions->propose($request->user(), $competition, $space, $request->all());

        return $this->done($request, 'Proposition envoyée aux gestionnaires de '.$space->name.'.', $competition->url().'?tab=propositions');
    }

    public function selectProposal(Request $request, Competition $competition, EntryProposal $proposal)
    {
        abort_unless((int) $proposal->competition_id === (int) $competition->id, 404);
        $this->competitions->selectProposal($request->user(), $proposal);

        return $this->done($request, 'Participation de l\'Espace envoyée !', $competition->url());
    }

    /** L'organisateur (un Espace) choisit son jury et le poids du jury, tant que la compétition n'est pas finie. */
    public function setJury(Request $request, Competition $competition)
    {
        abort_unless($competition->canManage($request->user()), 403);
        if (! $competition->isOrganizedBySpace() || ! in_array($competition->type, [Competition::TYPE_CONTEST, Competition::TYPE_TOURNAMENT], true)) {
            throw new CompetitionException('Le jury est réservé aux concours et tournois d\'un Espace.');
        }
        if ($competition->status === Competition::STATUS_COMPLETED) {
            throw new CompetitionException('Cette compétition est terminée.');
        }
        $data = $request->validate(['jurors_text' => 'nullable|string|max:600', 'jury_weight' => 'nullable|integer|in:0,30,50,70,100']);
        $names = collect(preg_split('/[\s,]+/', (string) ($data['jurors_text'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))->map(fn ($h) => ltrim($h, '@'))->unique()->take(15);
        $users = \App\Models\User::whereIn('username', $names)->get();
        if ($users->count() < $names->count()) {
            throw new CompetitionException('Pseudo introuvable : '.$names->diff($users->pluck('username'))->implode(', ').'.');
        }
        $competition->jurors()->sync($users->pluck('id'));
        $weight = $users->isEmpty() ? 0 : (int) ($data['jury_weight'] ?? 50);
        $competition->update([
            'jury_weight' => min(100, $weight),
            'vote_audience' => $weight >= 100 ? 'jury' : ($competition->vote_audience === 'jury' ? 'public' : $competition->vote_audience),
        ]);

        return $this->done($request, $users->isEmpty() ? 'Jury retiré : seul le public compte.' : 'Jury enregistré : '.$users->count().' juré'.($users->count() > 1 ? 's' : '').', '.$weight.' % du résultat.');
    }

    public function juryScore(Request $request, Competition $competition, CompetitionEntry $entry)
    {
        $data = $request->validate(['score' => 'required|integer|min:0|max:10']);
        $this->votes->juryScore($request->user(), $competition, $entry, $data['score']);

        return $this->done($request, 'Note enregistrée.');
    }

    // ── Votes & réactions ───────────────────────────────────────

    public function vote(Request $request, CompetitionMatch $match)
    {
        $action = $this->votes->voteMatch($request->user(), $match, (int) $request->input('entry_id'), $request->ip(), max(1, (int) $request->input('quantity', 1)));
        $m = Presenter::match($match->fresh(), $request->user(), true);

        return $this->done($request, match ($action) {
            'removed' => 'Vote retiré.',
            'switched' => 'Vote changé.',
            default => 'Vote enregistré. Partage le duel pour le faire vivre !',
        }, null, ['html' => view('web.partials.versus', [
            'm' => $m,
            'compact' => $request->boolean('compact'),
            'frame' => in_array($request->input('frame'), ['boxed', 'inset', 'none'], true) ? $request->input('frame') : 'boxed',
            'showTop' => $request->has('top') ? $request->boolean('top') : ($request->input('frame') !== 'none' && $request->input('frame') !== 'inset'),
            'social' => $request->boolean('social'),
            'showTime' => $request->has('time') ? $request->boolean('time') : true,
            'defy' => $request->has('defy') ? $request->boolean('defy') : true,
            'note' => $request->has('note') ? $request->boolean('note') : true,
        ])->render(), 'balance' => (int) $request->user()->fresh()->coins]);
    }

    public function voteEntry(Request $request, Competition $competition, CompetitionEntry $entry)
    {
        abort_unless((int) $entry->competition_id === (int) $competition->id, 404);
        $action = $this->votes->voteContestEntry($request->user(), $competition, $entry, $request->ip(), max(1, (int) $request->input('quantity', 1)));

        return $this->done($request, $action === 'removed' ? 'Vote retiré.' : ((int) $competition->vote_cost > 0 ? 'Merci pour tes votes !' : 'Vote enregistré.'));
    }

    public function react(Request $request, CompetitionMatch $match)
    {
        $emoji = $request->input('emoji');
        abort_unless(in_array($emoji, Reaction::EMOJIS, true), 422);
        $key = ['reactable_type' => CompetitionMatch::class, 'reactable_id' => $match->id, 'user_id' => $request->user()->id];
        $existing = Reaction::where($key)->first();
        if ($existing && $existing->emoji === $emoji) {
            $existing->delete();
        } else {
            Reaction::updateOrCreate($key, ['emoji' => $emoji]);
            $match->increment('hot_score');
        }

        return $this->done($request, 'Réaction enregistrée.');
    }

    /** Tournoi : envoyer sa nouvelle prestation pour ce tour. */
    public function roundSubmit(Request $request, CompetitionMatch $match)
    {
        $m = app(\App\Services\Competition\MatchService::class)->submitRound($request->user(), $match, $request->all());

        return $this->done($request, $m->status === 'voting' ? 'Envoyé ! Les deux prestations sont là : le duel commence.' : 'Envoyé ! Le duel commence dès que ton adversaire envoie la sienne.', $m->url());
    }

    public function rematch(Request $request, CompetitionMatch $match)
    {
        $duel = $this->competitions->rematch($request->user(), $match);

        return redirect($duel->url())->with('ok', 'Revanche envoyée ! Ton adversaire a 72 h pour accepter.');
    }

    // ── Invitations ─────────────────────────────────────────────

    public function respondInvitation(Request $request, CompetitionInvitation $invitation)
    {
        if ($request->input('decision') === 'decline') {
            $this->competitions->declineInvitation($request->user(), $invitation);

            return $this->done($request, 'Invitation déclinée.');
        }
        $this->competitions->acceptInvitation($request->user(), $invitation, $request->all());

        return redirect($invitation->competition->url())->with('ok', 'Défi accepté, le duel commence !');
    }

    // ── Social ──────────────────────────────────────────────────

    public function follow(Request $request, string $username)
    {
        $me = $request->user();
        $user = User::where('username', $username)->firstOrFail();
        if ($me->id === $user->id) {
            throw new CompetitionException('Tu ne peux pas te suivre toi-même.');
        }
        if ($me->isFollowing($user)) {
            $me->unfollow($user);

            return $this->done($request, 'Tu ne suis plus '.$user->first_name.'.');
        }
        $me->follow($user);
        $this->hooks->followed($me, $user);

        return $this->done($request, 'Tu suis '.$user->first_name.'.');
    }

    public function joinSpace(Request $request, string $slug)
    {
        $space = Space::where('slug', $slug)->firstOrFail();
        [$state, $message] = app(\App\Services\SpaceTeam::class)->toggle($space, $request->user());

        return $this->done($request, $message, null, ['state' => $state]);
    }

    // ── Membres et équipe d'un Espace ───────────────────────────

    private function spaceAndUser(string $slug, int $userId): array
    {
        return [Space::where('slug', $slug)->firstOrFail(), \App\Models\User::findOrFail($userId)];
    }

    public function spaceMemberDecision(Request $request, string $slug, int $userId)
    {
        [$space, $who] = $this->spaceAndUser($slug, $userId);
        $msg = app(\App\Services\SpaceTeam::class)->decide($space, $request->user(), $who, $request->input('decision') !== 'refuse');

        return $this->done($request, $msg);
    }

    public function spaceAddMember(Request $request, string $slug)
    {
        $request->validate(['username' => 'required|string|max:60'], ['username.required' => 'Écris le @pseudo de la personne.']);
        $space = Space::where('slug', $slug)->firstOrFail();
        [, $msg] = app(\App\Services\SpaceTeam::class)->add($space, $request->user(), (string) $request->input('username'));

        return $this->done($request, $msg);
    }

    /** La personne invitée répond (depuis « Reçus » ou la page de l'Espace). */
    public function spaceInviteAnswer(Request $request, string $slug)
    {
        $space = Space::where('slug', $slug)->firstOrFail();
        [$state, $msg] = app(\App\Services\SpaceTeam::class)->respondInvite($space, $request->user(), $request->input('decision') !== 'refuse');

        return $this->done($request, $msg, null, ['state' => $state]);
    }

    public function spaceRemoveMember(Request $request, string $slug, int $userId)
    {
        [$space, $who] = $this->spaceAndUser($slug, $userId);
        app(\App\Services\SpaceTeam::class)->remove($space, $request->user(), $who);

        return $this->done($request, \App\Support\Participant::name($who).' ne fait plus partie de l\'Espace.');
    }

    public function spaceSetRole(Request $request, string $slug, int $userId)
    {
        [$space, $who] = $this->spaceAndUser($slug, $userId);
        $role = $request->input('role');
        $msg = app(\App\Services\SpaceTeam::class)->setRole($space, $request->user(), $who, in_array($role, ['', 'member', null], true) ? null : (string) $role);

        return $this->done($request, $msg);
    }

    public function createSpace(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|min:3|max:100|unique:spaces,name',
            'bio' => 'nullable|string|max:500',
            'category' => 'nullable|in:'.implode(',', array_keys(config('spaces.categories'))),
            'subcategory' => 'nullable|string|max:40',
        ]);
        $data = self::spaceCategory($data);
        $me = $request->user();
        $space = Space::create($data + ['owner_id' => $me->id, 'trust_score' => 10, 'is_active' => true]);
        $space->addManager($me, 'admin');
        $space->addMember($me);
        $this->hooks->createdSpace($me, $space);

        return redirect('/espaces/'.$space->slug)->with('ok', 'Ton Espace est créé. Invite tes membres et lance votre première compétition !');
    }

    public function like(Request $request, Competition $competition)
    {
        $post = $competition->post;
        abort_unless($post, 404);
        $me = $request->user();
        $existing = PostLike::where(['post_id' => $post->id, 'user_id' => $me->id])->first();
        $existing ? $existing->delete() : PostLike::create(['post_id' => $post->id, 'user_id' => $me->id]);
        if (! $existing) {
            $competition->increment('hot_score');
        }

        return $this->done($request, $existing ? 'J\'aime retiré.' : 'Tu aimes cette compétition.', null,
            ['liked' => ! $existing, 'count' => $post->likes()->count()]);
    }

    public function comment(Request $request, Competition $competition)
    {
        $data = $request->validate(['content' => 'required|string|max:1000', 'parent_id' => 'nullable|integer', 'match_id' => 'nullable|integer']);
        $post = $competition->post;
        abort_unless($post, 404);
        // Le duel visé (un défi en a plusieurs) : seulement un duel de cette compétition
        $matchId = ! empty($data['match_id']) ? \App\Models\CompetitionMatch::where('competition_id', $competition->id)->whereKey($data['match_id'])->value('id') : null;
        // Réponse : toujours rattachée au commentaire principal (un seul niveau)
        $parent = ! empty($data['parent_id']) ? PostComment::where('post_id', $post->id)->find($data['parent_id']) : null;
        if ($parent && $parent->parent_id) {
            $parent = PostComment::find($parent->parent_id) ?? $parent;
        }
        // Le duel visé n'est écrit que s'il y en a un : un commentaire simple passe même si la base n'est pas encore à jour
        $comment = PostComment::create([
            'post_id' => $post->id, 'user_id' => $request->user()->id, 'content' => $data['content'], 'parent_id' => $parent?->id,
        ] + ($matchId ? ['match_id' => $matchId] : []));
        $this->hooks->commented($comment);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => $parent ? 'Réponse publiée.' : 'Commentaire publié.', 'id' => $comment->id]);
        }

        return redirect($competition->url().'?tab=commentaires#commentaire-'.($parent?->id ?? $comment->id))->with('ok', $parent ? 'Réponse publiée.' : 'Commentaire publié.');
    }

    public function likeComment(Request $request, PostComment $comment)
    {
        $me = $request->user();
        $existing = \App\Models\CommentLike::where(['comment_id' => $comment->id, 'user_id' => $me->id])->first();
        $existing ? $existing->delete() : \App\Models\CommentLike::create(['comment_id' => $comment->id, 'user_id' => $me->id]);

        return $this->done($request, $existing ? 'J\'aime retiré.' : 'Tu aimes ce commentaire.', null, [
            'liked' => ! $existing,
            'count' => \App\Models\CommentLike::where('comment_id', $comment->id)->count(),
        ]);
    }

    /** Suggestions pour « @… » : personnes et Espaces (défier, inviter). */
    public function suggest(Request $request)
    {
        $q = trim(ltrim((string) $request->query('q'), '@#'));
        $only = $request->query('type'); // user | space | null
        if (mb_strlen($q) < 1) {
            return response()->json(['data' => []]);
        }
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
        $out = collect();
        if ($only !== 'space') {
            $out = $out->concat(User::where('is_active', true)->where('id', '!=', $request->user()->id)
                ->where(fn ($w) => $w->where('username', 'like', $like)->orWhere('first_name', 'like', $like)->orWhere('last_name', 'like', $like))
                ->withCount('followers')->orderByDesc('followers_count')->limit(6)->get()
                ->map(fn ($u) => \App\Support\Participant::present($u)));
        }
        if ($only !== 'user') {
            $out = $out->concat(Space::where('is_active', true)
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('slug', 'like', $like))
                ->limit(4)->get()->map(fn ($s) => \App\Support\Participant::present($s)));
        }

        return response()->json(['data' => $out->values()]);
    }

    // ── Changer son mot de passe (connecté) : un code par SMS, puis le nouveau mot de passe ──

    public function sendPasswordCode(Request $request)
    {
        $me = $request->user();
        $code = (string) $me->phone_code;
        $number = (string) $me->phone_number;
        abort_if($number === '', 422, 'Aucun numéro sur ce compte.');
        $recent = \App\Models\VerificationCode::where('phone_code', $code)->where('phone_number', $number)->where('created_at', '>=', now()->subHour())->count();
        if ($recent >= 5) {
            throw \Illuminate\Validation\ValidationException::withMessages(['verification_code' => 'Trop de codes demandés. Réessaie dans une heure.']);
        }
        $last = \App\Models\VerificationCode::where('phone_code', $code)->where('phone_number', $number)->latest('id')->first();
        if ($last && $last->created_at->diffInSeconds(now()) < 60) {
            throw \Illuminate\Validation\ValidationException::withMessages(['verification_code' => 'Patiente une minute avant de redemander un code.']);
        }
        $otp = \App\Models\VerificationCode::generateUniqueCode();
        \App\Models\VerificationCode::create(['phone_code' => $code, 'phone_number' => $number, 'code' => $otp,
            'expires_at' => now()->addMinutes((int) config('messaging.code_ttl', 10)), 'is_used' => false, 'attempts' => 0]);
        $sent = app(\App\Services\Messaging\Messenger::class)->sendCode($code.$number, $otp);
        if (! $sent) {
            throw \Illuminate\Validation\ValidationException::withMessages(['verification_code' => 'Le SMS n\'a pas pu partir. Réessaie dans un instant.']);
        }
        $masked = $code.' •••• '.substr($number, -3);
        session(['password_code_sent' => true]);

        return $this->done($request, 'Code envoyé au '.$masked.'.', null, ['sent_to' => $masked]);
    }

    public function changePassword(Request $request)
    {
        $me = $request->user();
        $data = $request->validate([
            'verification_code' => 'required|digits:6',
            'password' => 'required|string|min:6|confirmed',
        ], ['password.confirmed' => 'Les deux mots de passe ne sont pas identiques.', 'password.min' => 'Au moins 6 caractères.',
            'verification_code.required' => 'Entre le code reçu par SMS.', 'verification_code.digits' => 'Le code a 6 chiffres.']);
        $otp = \App\Models\VerificationCode::where('phone_code', $me->phone_code)->where('phone_number', $me->phone_number)
            ->where('is_used', false)->latest('id')->first();
        if (! $otp || $otp->attempts >= 5 || ! $otp->isValid() || ! hash_equals((string) $otp->code, (string) $data['verification_code'])) {
            $otp?->incrementAttempts();
            throw \Illuminate\Validation\ValidationException::withMessages(['verification_code' => 'Code invalide ou expiré.']);
        }
        $otp->markAsUsed();
        $me->forceFill(['password' => \Illuminate\Support\Facades\Hash::make($data['password'])])->save();
        // Les autres appareils sont déconnectés ; celui-ci reste connecté
        $current = $request->user()->currentAccessToken();
        $me->tokens()->when($current && isset($current->id), fn ($q) => $q->where('id', '!=', $current->id))->delete();
        session()->forget('password_code_sent');

        return $this->done($request, 'Mot de passe changé. Les autres appareils sont déconnectés.', '/parametres/mot-de-passe');
    }

    /** Lien reçu par SMS ou WhatsApp. */
    public function phoneInvite(Request $request, string $token)
    {
        $invite = \App\Models\CompetitionPhoneInvite::with('competition')->where('token', $token)->firstOrFail();
        $url = $invite->competition?->url() ?? '/';
        if (! $request->user()) {
            $request->session()->put('phone_invite', $token);

            return redirect('/inscription?suite='.urlencode($url))->with('ok', 'Tu as été défié ! Crée ton compte en 1 minute pour répondre.');
        }
        $this->competitions->claimPhoneInvites($request->user(), $token);

        return redirect($url);
    }

    /** Signaler un contenu. Un seul signalement par personne et par contenu. */
    public function report(Request $request)
    {
        $data = $request->validate([
            'type' => 'required|in:'.implode(',', array_keys(\App\Models\ContentReport::TYPES)),
            'id' => 'required|integer',
            'reason' => 'required|in:'.implode(',', array_keys(\App\Models\ContentReport::REASONS)),
            'details' => 'nullable|string|max:1000',
        ], ['reason.required' => 'Choisis une raison.']);
        $class = \App\Models\ContentReport::TYPES[$data['type']];
        abort_unless($class::whereKey($data['id'])->exists(), 404);
        \App\Models\ContentReport::updateOrCreate(
            ['user_id' => $request->user()->id, 'reportable_type' => $class, 'reportable_id' => $data['id']],
            ['reason' => $data['reason'], 'details' => $data['details'] ?? null, 'status' => 'pending'],
        );

        return $this->done($request, 'Merci, on regarde ça.');
    }

    // ── Profil et Espaces : photo, couverture, nom ─────────────────

    public function updateProfile(Request $request)
    {
        $u = $request->user();
        // Chaque champ est facultatif à l'envoi : l'app enregistre une option à la fois (le nom, la bio, la couverture…)
        $data = $request->validate([
            'first_name' => 'sometimes|required|string|min:2|max:60',
            'last_name' => 'sometimes|nullable|string|max:60',
            'username' => 'sometimes|required|string|min:3|max:30|regex:/^[a-zA-Z0-9_.]+$/|unique:users,username,'.$u->id,
            'bio' => 'sometimes|nullable|string|max:300',
            'city' => 'sometimes|nullable|string|max:60',
            'school_space_id' => 'sometimes|nullable|integer|exists:spaces,id',
            'interests' => 'nullable|array|max:12', 'interests.*' => 'in:'.implode(',', array_keys(config('competition.categories'))),
            'avatar' => 'nullable|file|image|max:8192',
            'cover' => 'nullable|file|max:'.\App\Services\MediaUploader::maxKb(),
            'remove_cover' => 'nullable|boolean',
        ], [
            'first_name.required' => 'Ton prénom est obligatoire.',
            'first_name.min' => 'Ton prénom : au moins 2 lettres.',
            'username.required' => __('Ton pseudo est obligatoire.'),
            'username.min' => 'Ton pseudo : au moins 3 caractères.',
            'username.regex' => __('Le pseudo : lettres, chiffres, point ou tiret bas, sans espace.'),
            'username.unique' => 'Ce pseudo est déjà pris.',
            'avatar.image' => 'La photo de profil doit être une image.',
        ]);
        // Le pseudo : une seule fois, et jamais l'ancien pseudo de quelqu'un d'autre
        $newName = array_key_exists('username', $data) ? trim($data['username']) : null;
        $renaming = $newName !== null && mb_strtolower($newName) !== mb_strtolower((string) $u->username);
        if ($renaming) {
            if (! $u->canChangeUsername()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['username' => ['Tu as déjà changé ton pseudo une fois : il ne peut plus être modifié.']]);
            }
            if (\App\Models\User::usernameReserved($newName, $u->id)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['username' => ['Ce pseudo est déjà pris.']]);
            }
        } elseif ($newName !== null) {
            $data['username'] = $u->username; // même pseudo (majuscules près) : rien ne change
        }
        $this->applyImages($request, $u, 'users');
        if ($renaming) {
            // L'ancien pseudo reste à cette personne, pour toujours, et mène à son profil
            \Illuminate\Support\Facades\DB::table('username_history')->insertOrIgnore([
                'user_id' => $u->id, 'username' => $u->username, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $u->username_changed = true;
        }
        if (array_key_exists('city', $data)) {
            $data['city'] = \Illuminate\Support\Str::title(trim((string) $data['city'])) ?: null;
        }
        if (! empty($data['school_space_id']) && ! \App\Models\Space::whereKey($data['school_space_id'])->where('kind', 'school')->exists()) {
            $data['school_space_id'] = null;
        }
        if ($request->boolean('interests_sent')) {
            $u->interests = array_values($data['interests'] ?? []);
            $u->interests_asked_at = now();
        }
        // Ville changée à la main : elle n'est plus « vérifiée » par la position
        if (array_key_exists('city', $data) && $u->city_located_at && ! \App\Support\Geo::same($data['city'], $u->city)) {
            $u->city_located_at = null;
        }
        $u->fill(collect($data)->only(['first_name', 'last_name', 'username', 'bio', 'city', 'school_space_id'])->all())->save();

        return $this->done($request, 'Profil mis à jour.', '/@'.$u->username);
    }

    /** Sous-catégorie gardée seulement si elle appartient à la catégorie ; « Éducation » = institution. */
    public static function spaceCategory(array $data): array
    {
        $cat = $data['category'] ?? null;
        if (! $cat || ! array_key_exists((string) ($data['subcategory'] ?? ''), config('spaces.categories.'.$cat.'.sub', []))) {
            $data['subcategory'] = null;
        }
        $data['kind'] = $cat === 'education' ? 'school' : 'group';

        return $data;
    }

    public function updateSpace(Request $request, string $slug)
    {
        $space = \App\Models\Space::where('slug', $slug)->firstOrFail();
        abort_unless($space->canEditInfo($request->user()), 403);
        // Chaque champ est facultatif à l'envoi : l'app enregistre une option à la fois, le site tout le formulaire
        $data = $request->validate([
            'name' => 'sometimes|required|string|min:2|max:80|unique:spaces,name,'.$space->id,
            'bio' => 'sometimes|nullable|string|max:500',
            'category' => 'sometimes|nullable|in:'.implode(',', array_keys(config('spaces.categories'))),
            'subcategory' => 'sometimes|nullable|string|max:40',
            'location' => 'sometimes|nullable|string|max:80',
            'website' => 'sometimes|nullable|string|max:120',
            'site_template' => 'sometimes|nullable|in:'.implode(',', array_keys(config('sites.templates'))),
            'join_approval' => 'sometimes|boolean',
            'avatar' => 'nullable|file|image|max:8192',
            'cover' => 'nullable|file|max:'.\App\Services\MediaUploader::maxKb(),
            'remove_cover' => 'nullable|boolean',
        ], ['name.unique' => 'Un Espace porte déjà ce nom. Choisis-en un autre.']);
        $this->applyImages($request, $space, 'spaces');
        if (array_key_exists('category', $data)) {
            $data = self::spaceCategory($data);
        }
        if (array_key_exists('location', $data)) {
            $data['location'] = $data['location'] ? \Illuminate\Support\Str::title(trim($data['location'])) ?: null : null;
        }
        // Le site envoie une case à cocher : décochée, elle n'est pas envoyée
        if ($request->has('join_approval_sent')) {
            $data['join_approval'] = $request->boolean('join_approval');
        }
        $space->fill(collect($data)->only(['name', 'bio', 'category', 'subcategory', 'kind', 'location', 'website', 'site_template', 'join_approval'])->all())->save();

        return $this->done($request, 'Espace mis à jour.', '/espaces/'.$space->slug);
    }

    /** Photo de profil (image) et couverture (photo ou courte vidéo, type lu dans le fichier). */
    /** Photo de profil ou couverture d'un compte : enregistrée tout de suite (sans passer par « Enregistrer »). */
    public function updateProfilePhoto(Request $request)
    {
        return $this->savePhoto($request, $request->user(), 'users');
    }

    /** Photo de profil ou couverture d'un Espace : idem. */
    public function updateSpacePhoto(Request $request, string $slug)
    {
        $space = \App\Models\Space::where('slug', $slug)->firstOrFail();
        abort_unless($space->canEditInfo($request->user()), 403);

        return $this->savePhoto($request, $space, 'spaces');
    }

    /** Applique une photo (ou sa suppression) et renvoie les nouvelles adresses. */
    private function savePhoto(Request $request, \Illuminate\Database\Eloquent\Model $model, string $folder)
    {
        $request->validate([
            'avatar' => 'nullable|file|image|max:8192',
            'cover' => 'nullable|file|max:'.\App\Services\MediaUploader::maxKb(),
            'remove' => 'nullable|in:avatar,cover',
        ], [
            'avatar.image' => 'La photo de profil doit être une image.',
            'avatar.max' => 'Photo trop lourde : 8 Mo maximum.',
        ]);
        if ($request->input('remove') === 'avatar') {
            $request->merge(['remove_avatar' => 1]);
        }
        if ($request->input('remove') === 'cover') {
            $request->merge(['remove_cover' => 1]);
        }
        $this->applyImages($request, $model, $folder);
        $model->save();

        return response()->json([
            'ok' => true,
            'avatar' => $model->avatar_url,
            'has_avatar' => (bool) $model->getRawOriginal('avatar'),
            'cover' => $model->cover ? $model->cover_url : null,
            'cover_type' => $model->cover_type ?: 'image',
        ]);
    }
    private function applyImages(Request $request, \Illuminate\Database\Eloquent\Model $model, string $folder): void
    {
        $disk = \Illuminate\Support\Facades\Storage::disk(config('filesystems.media', 'public'));
        $oldAvatar = $model->getRawOriginal('avatar');
        if ($file = $request->file('avatar')) {
            $old = $oldAvatar;
            // Photo de profil : allégée (1600 px au plus, JPEG) avant d'être rangée
            [$toStore, $ext] = app(\App\Services\MediaCompressor::class)->compress($file, 'image');
            $model->avatar = $disk->putFileAs('avatars/'.$folder, $toStore, \Illuminate\Support\Str::random(24).'.'.$ext);
            if ($toStore instanceof \Illuminate\Http\File) {
                @unlink($toStore->getRealPath());
            }
            if ($old && ! str_starts_with($old, 'demo/') && ! str_contains($old, 'null') && $disk->exists($old)) {
                $disk->delete($old);
            }
        } elseif ($request->boolean('remove_avatar')) {
            // On retire la photo : le profil reprend l'image par défaut
            $model->avatar = null;
            if ($oldAvatar && ! str_starts_with($oldAvatar, 'demo/') && ! str_contains($oldAvatar, 'null') && $disk->exists($oldAvatar)) {
                $disk->delete($oldAvatar);
            }
        }
        $oldCover = $model->getRawOriginal('cover');
        if ($file = $request->file('cover')) {
            $stored = app(\App\Services\MediaUploader::class)->storeOne(['file' => $file], 'covers/'.$folder, ['video' => (int) config('competition.media.max_cover_video_seconds', 30)]);
            if (! $stored || ! in_array($stored['type'], ['image', 'video'], true)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['cover' => ['La couverture doit être une photo ou une courte vidéo.']]);
            }
            $model->cover = $stored['url'];
            $model->cover_type = $stored['type'];
        } elseif ($request->boolean('remove_cover')) {
            $model->cover = null;
            $model->cover_type = 'image';
        }
        if ($oldCover && $oldCover !== $model->cover && ! str_starts_with($oldCover, 'demo/') && ! str_contains($oldCover, 'null') && $disk->exists($oldCover)) {
            $disk->delete($oldCover);
        }
    }
}
