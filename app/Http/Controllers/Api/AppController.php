<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\ActionController;
use App\Http\Controllers\Web\CoinController;
use App\Http\Controllers\Web\PageController;
use App\Models\CoinTransaction;
use App\Models\Competition;
use App\Models\CompetitionMatch;
use App\Models\PostComment;
use App\Models\Space;
use App\Models\User;
use App\Services\Social\ActivityService;
use App\Support\Features;
use App\Support\Participant;
use App\Support\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ce dont l'app mobile a besoin en plus de l'API existante, avec les mêmes règles que le site :
 * réglages (fonctions, disciplines, limites), fil « Pour toi / Duels / À rejoindre », commentaires,
 * classement repensé, position, goûts, portefeuille et cadeaux.
 * Les actions réutilisent ActionController (il répond en JSON quand on demande du JSON).
 */
class AppController extends Controller
{
    public function __construct(private ActivityService $activities) {}

    /** Réglages de l'app : fonctions ouvertes, disciplines, limites, tailles de tournoi… */
    public function config(Request $request): JsonResponse
    {
        $me = $this->viewer($request);

        return response()->json(['success' => true, 'data' => [
            'features' => Features::all(),
            'categories' => collect(config('competition.categories'))->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'limits' => config('competition.limits'),
            'tournament_sizes' => config('competition.tournament_sizes'),
            'duration_presets' => collect(config('competition.duration_presets'))->map(fn ($label, $hours) => compact('hours', 'label'))->values(),
            'media' => config('competition.media'),
            'round_submission_hours' => (int) config('competition.durations.round_submission_hours', 24),
            'coins' => ['currency' => config('coins.currency'), 'commission' => (int) config('coins.commission')],
            'me' => $me ? $this->me($me) : null,
        ]]);
    }

    private function me(User $u): array
    {
        return Participant::present($u) + [
            'coins' => (int) $u->coins, 'city' => $u->city, 'interests' => $u->interests ?? [],
            'is_admin' => (bool) $u->is_admin, 'bio' => $u->bio,
            'my_spaces' => \App\Models\Space::whereIn('id', $u->publishableSpaceIds())->get()->map(fn ($s) => Participant::present($s))->values(),
        ];
    }

    /** Les bulles en haut de l'accueil (comme le site) : les duels en cours qui se terminent bientôt. */
    public function stories(): JsonResponse
    {
        $side = fn ($e) => $e ? ['name' => $e->displayName(), 'avatar_url' => $e->participant ? Participant::present($e->participant)['avatar_url'] : null] : null;
        $live = \App\Models\CompetitionMatch::with(['competition', 'entryA.participant', 'entryB.participant'])
            ->where('status', 'voting')->where('is_bye', false)->whereNotNull('ends_at')->where('ends_at', '>', now())
            ->orderBy('ends_at')->limit(12)->get();

        return response()->json(['success' => true, 'data' => $live->map(fn ($m) => [
            'id' => $m->id,
            'url' => $m->url(),
            'ends_at' => $m->ends_at->toIso8601String(),
            'title' => $m->competition?->title,
            'a' => $side($m->entryA),
            'b' => $side($m->entryB),
        ])->values()]);
    }

    /** L'accueil : fil (cartes, comme sur le site), duels en cours, ou compétitions à rejoindre. */
    public function home(Request $request): JsonResponse
    {
        $me = $this->viewer($request);
        $view = in_array($request->query('vue'), ['direct', 'ouvertes'], true) ? $request->query('vue') : 'fil';
        $page = max(1, $request->integer('page', 1)); // défilement infini des onglets Duels et À rejoindre
        if ($view === 'direct') {
            $matches = CompetitionMatch::with(['competition', 'entryA.participant', 'entryB.participant'])
                ->where('status', 'voting')->where('is_bye', false)->whereHas('competition', fn ($q) => $q->whereNull('hidden_at'))
                ->orderByDesc('hot_score')->orderByDesc('id')->skip(($page - 1) * 20)->take(21)->get();
            if ($me && $page === 1) {
                $me->forceFill(['duels_seen_at' => now()])->saveQuietly();
            }

            return response()->json(['success' => true, 'has_more' => $matches->count() > 20,
                'data' => $matches->take(20)->map(fn ($m) => Presenter::match($m, $me))->values()]);
        }
        if ($view === 'ouvertes') {
            $mine = array_values(array_intersect((array) ($me?->interests ?? []), array_keys(config('competition.categories'))));
            $items = Competition::with(['organizer', 'winnerEntry'])->acceptingEntries()->where('access', '!=', 'invited')->whereNull('hidden_at')
                ->when($mine, fn ($q) => $q->orderByRaw('CASE WHEN category IN ('.implode(',', array_fill(0, count($mine), '?')).') THEN 0 ELSE 1 END', $mine))
                ->when($me?->city, fn ($q) => $q->orderByRaw('CASE WHEN geo_city = ? THEN 0 ELSE 1 END', [$me->city]))
                ->orderByDesc('hot_score')->orderByDesc('id')->skip(($page - 1) * 20)->take(21)->get();

            return response()->json(['success' => true, 'has_more' => $items->count() > 20,
                'data' => $items->take(20)->map(fn ($c) => Presenter::competition($c, $me))->values()]);
        }
        $res = $this->activities->feedFor($me, $request->query('avant'), 20);
        $blocks = PageController::feedBlocks(Presenter::activities($res['items'], $me));

        // Comme le site : des bandes « À suivre » et « Espaces à rejoindre » glissées dans le fil.
        // 1re page : personnes après la 2e publication, Espaces après la 6e ; ensuite une bande par page, en alternance
        // (si une sorte n'a plus rien à proposer, on montre l'autre).
        $spacesOn = Features::on('spaces');
        $strips = [];
        if (! $request->query('avant')) {
            $strips[] = ['at' => 2, 'kind' => 'people', 'items' => \App\Support\Suggestions::people($me)];
            if ($spacesOn) {
                $strips[] = ['at' => 6, 'kind' => 'spaces', 'items' => \App\Support\Suggestions::spaces($me)];
            }
        } else {
            $kind = $page % 2 === 1 || ! $spacesOn ? 'people' : 'spaces';
            $people = fn () => \App\Support\Suggestions::people($me, intdiv($page + 1, 2));
            $spaces = fn () => $spacesOn ? \App\Support\Suggestions::spaces($me, intdiv($page, 2)) : [];
            $items = $kind === 'people' ? $people() : $spaces();
            if (! $items) {
                $kind = $kind === 'people' ? 'spaces' : 'people';
                $items = $kind === 'people' ? $people() : $spaces();
            }
            $strips[] = ['at' => 4, 'kind' => $kind, 'items' => $items];
        }

        return response()->json([
            'success' => true,
            'data' => collect($blocks)->where('type', 'post')->pluck('item')->values(),
            'suggestions' => array_values(array_filter($strips, fn ($x) => count($x['items']) > 0)),
            'next' => $res['next_cursor'],
            'ask_interests' => $me && Features::on('welcome_questions') && empty($me->interests) && (! $me->interests_asked_at || $me->interests_asked_at->lt(now()->subDays(5))),
            'ask_city' => $me && Features::on('welcome_questions') && ! empty($me->interests) && ! $me->city && (! $me->city_asked_at || $me->city_asked_at->lt(now()->subDays(5))),
            'unread' => $me ? $me->unreadNotifications()->count() : 0,
            'inbox' => $me ? \App\Support\Inbox::count($me) : 0, // le compteur de l'icône « Reçus »
        ]);
    }

    /** « Reçus » : ce qui attend ma réponse (défis, revanches, invitations, candidatures à valider). */
    public function inbox(Request $request): JsonResponse
    {
        $items = \App\Support\Inbox::items($request->user());

        return response()->json(['success' => true, 'data' => $items, 'count' => count($items)]);
    }

    /** Refuser un défi ou une invitation, depuis « Reçus » */
    public function inboxDecline(Request $request, \App\Models\CompetitionInvitation $invitation): JsonResponse
    {
        app(\App\Services\Competition\CompetitionService::class)->declineInvitation($request->user(), $invitation);

        return response()->json(['success' => true, 'message' => 'Refusé.', 'count' => \App\Support\Inbox::count($request->user())]);
    }

    /** Accepter ou refuser une candidature, depuis « Reçus » */
    public function inboxCandidate(Request $request, \App\Models\CompetitionEntry $entry, string $decision): JsonResponse
    {
        $svc = app(\App\Services\Competition\CompetitionService::class);
        $decision === 'refuser' ? $svc->rejectEntry($request->user(), $entry) : $svc->approveEntry($request->user(), $entry);

        return response()->json(['success' => true, 'message' => $decision === 'refuser' ? 'Candidature refusée.' : 'Candidature acceptée.',
            'count' => \App\Support\Inbox::count($request->user())]);
    }

    /** « À suivre » : toutes les personnes suggérées, page par page (l'écran « Tout voir » de l'app). */
    public function suggestions(Request $request): JsonResponse
    {
        $me = $this->viewer($request);
        $page = max(1, $request->integer('page', 1));
        $q = trim((string) $request->query('q'));
        $items = $q !== '' ? \App\Support\Suggestions::searchPeople($me, $q, $page - 1) : \App\Support\Suggestions::people($me, $page - 1, 30);

        return response()->json(['success' => true, 'data' => $items, 'has_more' => count($items) === 30]);
    }

    /** La recherche du haut de l'app : des personnes, des Espaces et des publications. */
    public function search(Request $request): JsonResponse
    {
        $me = $this->viewer($request);
        $q = trim(ltrim((string) $request->query('q'), '@#'));
        if (mb_strlen($q) < 2) {
            return response()->json(['success' => true, 'data' => ['people' => [], 'spaces' => [], 'competitions' => []]]);
        }
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
        $spaces = Features::on('spaces') ? \App\Models\Space::where('is_active', true)
            ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('slug', 'like', $like))
            ->withCount('members')->orderByDesc('is_verified')->orderByDesc('members_count')->limit(5)->get()
            ->map(fn ($s) => Participant::present($s) + ['sub' => $s->members_count.' membre'.($s->members_count > 1 ? 's' : ''), 'slug' => $s->slug])->values() : collect();
        $comps = Competition::with(['organizer', 'winnerEntry'])->whereNull('hidden_at')->where('access', '!=', 'invited')
            ->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('description', 'like', $like))
            ->orderByDesc('hot_score')->orderByDesc('id')->limit(8)->get()
            ->map(fn ($c) => Presenter::competition($c, $me))->values();

        return response()->json(['success' => true, 'data' => [
            'people' => \App\Support\Suggestions::searchPeople($me, $q, 0, 6),
            'spaces' => $spaces,
            'competitions' => $comps,
        ]]);
    }

    /** Commentaires d'une publication (et leurs réponses). */
    public function comments(Request $request, Competition $competition): JsonResponse
    {
        $me = $this->viewer($request);
        abort_unless($competition->isShownTo($me) && Features::on('comments'), 404);
        $post = $competition->post;
        $withLikes = fn ($q) => $q->withCount('likes')->withExists(['likes as liked' => fn ($l) => $l->where('user_id', $me?->id ?? 0)]);
        $rows = $post ? $post->comments()->whereNull('parent_id')->with(['author', 'match.entryA', 'match.entryB', 'replies' => fn ($r) => $withLikes($r->with(['author', 'match.entryA', 'match.entryB'])->oldest())])
            ->tap($withLikes)->latest()->skip((max(1, $request->integer('page', 1)) - 1) * 20)->take(21)->get() : collect();
        $people = \App\Support\CommentText::peopleFor($rows);
        $one = fn (PostComment $c) => [
            'id' => $c->id, 'content' => $c->content, 'created_at' => Presenter::date($c->created_at),
            'author' => Participant::present($c->author), 'likes_count' => (int) $c->likes_count, 'liked' => (bool) $c->liked,
            // Mentions cliquables et duel visé (« X contre Y »)
            'parts' => \App\Support\CommentText::parts((string) $c->content, $people),
            'duel' => \App\Support\CommentText::duel($c->match),
        ];

        return response()->json(['success' => true, 'count' => $post ? $post->comments()->count() : 0, 'has_more' => $rows->count() > 20,
            'data' => $rows->take(20)->map(fn ($c) => $one($c) + ['replies' => $c->replies->map($one)->values()])->values()]);
    }

    /** Les duels d'un défi, pour en viser un dans un commentaire. */
    public function commentDuels(Request $request, Competition $competition): JsonResponse
    {
        abort_unless($competition->isShownTo($this->viewer($request)), 404);
        // Seulement pour un défi ou un tournoi (plusieurs duels) : comme le site
        [$duels] = \App\Http\Controllers\Web\PageController::commentDuels($competition);

        return response()->json(['success' => true, 'data' => $duels]);
    }

    /** Classement : ma place et mes voisins, mes abonnements, ma ville, ma discipline, top 10 ; ou la liste complète. */
    public function leaderboards(Request $request): JsonResponse
    {
        $d = PageController::leaderboardData($request, $this->viewer($request));

        return response()->json(['success' => true, 'data' => [
            'kind' => $d['d']['kind'], 'period' => $d['period'], 'season_label' => $d['d']['season_label'], 'full' => $d['full'],
            'season' => $d['season'], 'past' => $d['past'], 'seasons' => $d['seasons'], // saisons passées consultables
            'me' => $d['d']['me'], 'rows' => $d['d']['rows'], 'boards' => $d['boards'], 'groups' => $d['groups'],
        ]]);
    }

    /** Abonnés ou abonnements d'une personne ; les gens que je suis d'abord. */
    public function follows(Request $request, string $username, string $list): JsonResponse
    {
        $user = User::where('username', $username)->firstOrFail();
        $me = $this->viewer($request);
        $mine = $me ? $me->following()->pluck('users.id')->all() : [];
        $followsMe = $me ? $me->followers()->pluck('users.id')->flip() : collect();
        $page = ($list === 'abonnes' ? $user->followers() : $user->following())
            ->when($mine, fn ($w) => $w->orderByRaw('CASE WHEN users.id IN ('.implode(',', array_map('intval', $mine)).') THEN 0 ELSE 1 END'))
            ->orderByDesc('follows.created_at')->paginate(30);

        return response()->json(['success' => true, 'has_more' => $page->hasMorePages(),
            'counts' => ['followers' => $user->followers()->count(), 'following' => $user->following()->count()],
            'data' => collect($page->items())->map(fn ($u) => Participant::present($u) + [
                'is_following' => in_array($u->id, $mine, true), 'follows_me' => isset($followsMe[$u->id]), 'is_me' => $me && $me->id === $u->id,
            ])->values()]);
    }

    /** Portefeuille : solde, gains, derniers mouvements. */
    public function wallet(Request $request): JsonResponse
    {
        abort_if(Features::off('coins'), 404);
        $u = $request->user();
        $coins = app(\App\Services\Coins\CoinService::class);

        return response()->json(['success' => true, 'data' => [
            'balance' => $coins->balance($u), 'earned' => $coins->earned($u), 'withdrawable' => $coins->withdrawable($u),
            // L'app n'achète des coins que si l'admin l'a ouvert pour l'app (règles de Google Play)
            'can_buy' => Features::on('buy_coins_app'),
            'packs' => Features::on('buy_coins_app') ? config('coins.packs') : [], 'currency' => config('coins.currency'),
            'history' => CoinTransaction::where('user_id', $u->id)->with('counterparty')->latest('id')->limit(50)->get()->map(fn ($t) => [
                'id' => $t->id, 'amount' => (int) $t->amount, 'label' => CoinTransaction::LABELS[$t->type] ?? $t->type,
                'description' => $t->description, 'created_at' => Presenter::date($t->created_at),
            ]),
        ]]);
    }

    /** Mot de passe oublié (app) : un code par SMS/WhatsApp si le numéro a un compte. Ensuite : /mobile/auth/reset-password. */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['phone_code' => 'required|string|max:5', 'phone_number' => 'required|string|min:6|max:15']);
        $exists = User::where('phone_code', $data['phone_code'])->where('phone_number', $data['phone_number'])->exists();
        if ($exists) {
            $recent = \App\Models\VerificationCode::where('phone_code', $data['phone_code'])->where('phone_number', $data['phone_number'])
                ->where('created_at', '>=', now()->subHour())->count();
            if ($recent >= 5) {
                return response()->json(['success' => false, 'message' => 'Trop de codes demandés. Réessaie dans une heure.'], 429);
            }
            $code = \App\Models\VerificationCode::generateUniqueCode();
            \App\Models\VerificationCode::create(['phone_code' => $data['phone_code'], 'phone_number' => $data['phone_number'], 'code' => $code,
                'expires_at' => now()->addMinutes((int) config('messaging.code_ttl', 10)), 'is_used' => false, 'attempts' => 0]);
            app(\App\Services\Messaging\Messenger::class)->sendCode($data['phone_code'].$data['phone_number'], $code);
        }

        // Même réponse dans tous les cas : on ne révèle pas si un numéro a un compte
        return response()->json(['success' => true, 'message' => 'Si ce numéro a un compte, un code vient d\'être envoyé.']);
    }

    // ── Actions : mêmes règles que le site ─────────────────────

    private function action(Request $request, string $method, ...$args)
    {
        $request->headers->set('Accept', 'application/json');

        return app(ActionController::class)->{$method}($request, ...$args);
    }

    public function like(Request $r, Competition $competition) { return $this->action($r, 'like', $competition); }

    public function comment(Request $r, Competition $competition) { return $this->action($r, 'comment', $competition); }

    public function likeComment(Request $r, PostComment $comment) { return $this->action($r, 'likeComment', $comment); }

    public function locate(Request $r) { return $this->action($r, 'locate'); }

    public function places(Request $r) { return $this->action($r, 'places'); }

    public function interests(Request $r) { return $this->action($r, 'saveInterests'); }

    public function city(Request $r) { return $this->action($r, 'saveCity'); }

    public function report(Request $r) { return $this->action($r, 'report'); }

    public function edit(Request $r, Competition $competition) { return $this->action($r, 'edit', $competition); }

    public function cancel(Request $r, Competition $competition) { return $this->action($r, 'cancel', $competition); }

    public function prizeProof(Request $r, Competition $competition) { return $this->action($r, 'prizeProof', $competition, app(\App\Services\Competition\PrizeProof::class)); }

    /** Ce que je peux encore modifier dans ma publication (mêmes règles que le site). */
    public function editRules(Request $r, Competition $competition): JsonResponse
    {
        return response()->json(['success' => true, 'data' => app(\App\Services\Competition\CompetitionEditor::class)->rules($competition, $r->user())
            + ['title' => $competition->title, 'category' => $competition->category, 'rules' => $competition->rules, 'prize' => $competition->prize]]);
    }

    /** Profil : en-tête, compteurs, statistiques et premières activités. */
    public function profile(Request $request, string $username): JsonResponse
    {
        // Un ancien pseudo mène aussi au bon profil
        $user = User::where('username', $username)->where('is_active', true)->first()
            ?? tap(User::byOldUsername($username), fn ($u) => abort_unless($u && $u->is_active, 404));
        $me = $this->viewer($request);
        $res = $this->activities->forActor($user, null, 20);
        $isMe = $me && $me->id === $user->id;

        return response()->json(['success' => true, 'data' => [
            'participant' => Participant::present($user),
            'first_name' => $user->first_name, 'last_name' => $user->last_name, 'username' => $user->username,
            'bio' => $user->bio, 'city' => $user->city, 'cover_url' => filled($user->getRawOriginal('cover')) ? $user->cover : null, // pas d'image par défaut
            'cover_type' => ($user->cover_type ?? 'image') === 'video' ? 'video' : 'image',
            'interests' => array_values((array) $user->interests),
            'can_change_username' => $isMe ? $user->canChangeUsername() : null,
            'is_me' => $isMe,
            'is_following' => $me && ! $isMe ? $me->isFollowing($user) : false,
            'follows_me' => $me && ! $isMe ? $user->isFollowing($me) : false,
            'followers' => $user->followers()->count(),
            'following' => $user->following()->count(),
            'stats' => app(SocialController::class)->stats($user, $me),
            'activities' => Presenter::activities($res['items'], $me),
            'next_cursor' => $res['next_cursor'],
        ]]);
    }

    /**
     * Page « Compétitions » d'un profil : onglets organized | joined | stats.
     * ?tab=organized&status=all|live|done&sort=…&page=
     */
    public function profileCompetitions(Request $request, string $kind, string $handle): JsonResponse
    {
        $owner = $kind === 'space'
            ? Space::where('slug', $handle)->where('is_active', true)->firstOrFail()
            : User::where('username', $handle)->where('is_active', true)->firstOrFail();
        $tab = (string) $request->query('tab', 'organized');
        if ($tab === 'stats') {
            return response()->json(['success' => true, 'data' => \App\Support\Participations::stats($owner)]);
        }
        $page = \App\Support\Participations::page($owner, $tab === 'joined' ? 'joined' : 'organized',
            (string) $request->query('status', 'all'), (string) $request->query('sort', 'recent'));

        return response()->json(['success' => true, 'has_more' => $page['has_more'], 'total' => $page['total'],
            'sorts' => \App\Support\Participations::sorts(), 'data' => $page['items']]);
    }

    /** Espaces : recherche, et ceux dont je fais partie. */
    public function spaces(Request $request): JsonResponse
    {
        abort_if(Features::off('spaces'), 404);
        $me = $this->viewer($request);
        $q = trim((string) $request->query('q'));
        $mine = $me ? $me->allSpaceIds() : [];
        $page = Space::query()->where('is_active', true)->withCount('members')
            ->when($q, fn ($w) => $w->where('name', 'like', '%'.$q.'%'))
            ->orderByDesc('members_count')->paginate(20);

        return response()->json(['success' => true, 'has_more' => $page->hasMorePages(),
            'data' => collect($page->items())->map(fn ($s) => Participant::present($s) + [
                'bio' => $s->bio, 'members_count' => (int) $s->members_count, 'is_member' => in_array($s->id, $mine, true),
            ])->values(),
            // Pour « Créer un Espace » : catégories et sous-catégories (comme sur le site)
            'categories' => self::spaceCategories()]);
    }

    /** « Mes Espaces » (profil) : ceux que je gère, puis ceux dont je suis membre. */
    public function mySpaces(Request $request): JsonResponse
    {
        abort_if(Features::off('spaces'), 404);
        $me = $request->user();
        $team = $me->publishableSpaceIds();
        $spaces = Space::whereIn('id', array_unique(array_merge($me->allSpaceIds(), $team)))->where('is_active', true)->withCount('members')->get()
            ->sortBy(fn ($s) => [in_array($s->id, $team, true) ? 0 : 1, mb_strtolower($s->name)])->values();

        return response()->json(['success' => true,
            'data' => $spaces->map(fn ($s) => Participant::present($s) + [
                'bio' => $s->bio, 'members_count' => (int) $s->members_count, 'is_member' => true,
                // owner | admin | editor | viewer | member — « Je gère » regroupe tout sauf member
                'role' => $s->roleOf($me) ?? 'member',
                'role_label' => ['owner' => 'Créateur', 'member' => 'Membre'][$s->roleOf($me) ?? 'member'] ?? (Space::ROLES[$s->roleOf($me)] ?? 'Membre'),
            ])->values(),
            'categories' => self::spaceCategories()]);
    }

    private static function spaceCategories(): array
    {
        return collect(config('spaces.categories'))->map(fn ($c, $k) => [
            'value' => $k, 'label' => $c['label'], 'icon' => $c['icon'] ?? null,
            'sub' => collect($c['sub'] ?? [])->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
        ])->values()->all();
    }

    /** Créer un Espace depuis l'app (même règles que le site). */
    public function createSpace(Request $request): JsonResponse
    {
        abort_if(Features::off('spaces'), 404);
        $data = $request->validate([
            'name' => 'required|string|min:3|max:100|unique:spaces,name',
            'bio' => 'nullable|string|max:500',
            'category' => 'nullable|in:'.implode(',', array_keys(config('spaces.categories'))),
            'subcategory' => 'nullable|string|max:40',
        ], ['name.unique' => 'Un Espace porte déjà ce nom. Choisis-en un autre.']);
        $data = \App\Http\Controllers\Web\ActionController::spaceCategory($data);
        $me = $request->user();
        $space = Space::create($data + ['owner_id' => $me->id, 'trust_score' => 10, 'is_active' => true]);
        $space->addManager($me, 'admin');
        $space->addMember($me);
        app(\App\Services\Social\SocialHooks::class)->createdSpace($me, $space);

        return response()->json(['success' => true, 'message' => 'Ton Espace est créé. Invite tes membres et lance votre première compétition !',
            'data' => Participant::present($space) + ['slug' => $space->slug]], 201);
    }

    public function space(Request $request, string $slug): JsonResponse
    {
        abort_if(Features::off('spaces'), 404);
        $space = Space::where('slug', $slug)->where('is_active', true)->firstOrFail();
        $me = $this->viewer($request);
        $res = $this->activities->forActor($space, null, 20);

        return response()->json(['success' => true, 'data' => [
            'participant' => Participant::present($space),
            'bio' => $space->bio, 'location' => $space->location, 'cover_url' => filled($space->getRawOriginal('cover')) ? $space->cover_url : null,
            'cover_type' => ($space->cover_type ?? 'image') === 'video' ? 'video' : 'image',
            'members_count' => (int) $space->members_count,
            'is_member' => $me ? $space->hasAffiliate($me) : false,
            'is_pending' => $me ? $space->isPending($me) : false,
            'is_invited' => $me ? $space->isInvited($me) : false,
            'join_approval' => (bool) $space->join_approval,
            'my_role' => $space->roleOf($me),
            'can_manage' => $me ? $space->canManage($me) : false, // valider (administrateur, modérateur)
            'can_edit' => $me ? $space->canEditInfo($me) : false,  // nom, logo, couverture, rôles (administrateur)
            'can_publish' => $me ? $space->canPublish($me) : false,
            'pending_count' => $me && $space->canManage($me) ? $space->pendingMembers()->count() : 0,
            'category' => $space->category, 'subcategory' => $space->subcategory, 'name' => $space->name,
            'stats' => app(SocialController::class)->stats($space, $me),
            'competitions' => Competition::visible()->where('organizer_type', Space::class)->where('organizer_id', $space->id)
                ->latest()->limit(12)->get()->map(fn ($c) => Presenter::competition($c, $me))->values(),
            'activities' => Presenter::activities($res['items'], $me),
            'next_cursor' => $res['next_cursor'],
        ]]);
    }

    public function follow(Request $r, string $username)
    {
        $res = $this->action($r, 'follow', $username);
        $user = User::where('username', $username)->first();

        return response()->json(array_merge($res->getData(true), [
            'is_following' => $user ? $r->user()->isFollowing($user) : false,
            'followers' => $user ? $user->followers()->count() : 0,
        ]));
    }

    public function joinSpace(Request $r, string $slug)
    {
        $res = $this->action($r, 'joinSpace', $slug);
        $space = Space::where('slug', $slug)->first();

        return response()->json(array_merge($res->getData(true), [
            'is_member' => $space ? $space->isMember($r->user()) : false,
            'is_pending' => $space ? $space->isPending($r->user()) : false,
            'is_invited' => $space ? $space->isInvited($r->user()) : false,
        ]));
    }

    // ── Membres et équipe d'un Espace (mêmes règles que le site : App\Services\SpaceTeam) ──

    public function sendPasswordCode(Request $r) { return $this->action($r, 'sendPasswordCode'); }

    public function changePassword(Request $r) { return $this->action($r, 'changePassword'); }

    public function spaceTeam(Request $r, string $slug): JsonResponse
    {
        $space = Space::where('slug', $slug)->firstOrFail();

        return response()->json(['success' => true, 'data' => app(\App\Services\SpaceTeam::class)->data($space, $r->user())]);
    }

    public function spaceAddMember(Request $r, string $slug) { return $this->action($r, 'spaceAddMember', $slug); }

    public function spaceInviteAnswer(Request $r, string $slug) { return $this->action($r, 'spaceInviteAnswer', $slug); }

    public function spaceMemberDecision(Request $r, string $slug, int $userId) { return $this->action($r, 'spaceMemberDecision', $slug, $userId); }

    public function spaceRemoveMember(Request $r, string $slug, int $userId) { return $this->action($r, 'spaceRemoveMember', $slug, $userId); }

    public function spaceSetRole(Request $r, string $slug, int $userId) { return $this->action($r, 'spaceSetRole', $slug, $userId); }

    /** Modifier l'Espace (administrateurs) : une option à la fois, comme le profil. */
    public function updateSpace(Request $r, string $slug): JsonResponse
    {
        $this->action($r, 'updateSpace', $slug);
        $space = Space::where('slug', $slug)->firstOrFail()->fresh();

        return response()->json(['success' => true, 'message' => 'Espace mis à jour.', 'data' => Participant::present($space) + [
            'slug' => $space->slug, 'name' => $space->name, 'bio' => $space->bio, 'location' => $space->location,
            'category' => $space->category, 'subcategory' => $space->subcategory, 'join_approval' => (bool) $space->join_approval,
            'cover_url' => filled($space->getRawOriginal('cover')) ? $space->cover_url : null,
            'cover_type' => ($space->cover_type ?? 'image') === 'video' ? 'video' : 'image',
        ]]);
    }

    /** Ce qu'il faut pour l'écran « Modifier l'Espace » de l'app. */
    public function editSpaceData(Request $r, string $slug): JsonResponse
    {
        $space = Space::where('slug', $slug)->firstOrFail();
        abort_unless($space->canEditInfo($r->user()), 403);

        return response()->json(['success' => true, 'data' => Participant::present($space) + [
            'slug' => $space->slug, 'name' => $space->name, 'bio' => $space->bio, 'location' => $space->location,
            'category' => $space->category, 'subcategory' => $space->subcategory, 'join_approval' => (bool) $space->join_approval,
            'cover_url' => filled($space->getRawOriginal('cover')) ? $space->cover_url : null,
            'cover_type' => ($space->cover_type ?? 'image') === 'video' ? 'video' : 'image',
        ], 'categories' => self::spaceCategories()]);
    }

    public function updateProfile(Request $r)
    {
        $this->action($r, 'updateProfile');
        $u = $r->user()->fresh();

        // Ce qu'il faut à l'app pour mettre à jour l'écran sans tout recharger
        return response()->json(['success' => true, 'message' => 'Profil mis à jour.', 'data' => Participant::present($u) + [
            'username' => $u->username, 'can_change_username' => $u->canChangeUsername(), 'first_name' => $u->first_name, 'last_name' => $u->last_name, 'bio' => $u->bio, 'city' => $u->city,
            'interests' => array_values((array) $u->interests),
            'cover_url' => filled($u->getRawOriginal('cover')) ? $u->cover : null,
            'cover_type' => ($u->cover_type ?? 'image') === 'video' ? 'video' : 'image',
        ]]);
    }

    public function gift(Request $r)
    {
        $r->headers->set('Accept', 'application/json');

        return app(CoinController::class)->gift($r);
    }
}
