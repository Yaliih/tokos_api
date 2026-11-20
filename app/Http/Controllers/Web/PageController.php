<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Models\CompetitionInvitation;
use App\Models\CompetitionMatch;
use App\Models\EntryProposal;
use App\Models\Post;
use App\Models\Rating;
use App\Models\Space;
use App\Models\User;
use App\Services\Competition\TournamentService;
use App\Services\Social\ActivityService;
use App\Http\Controllers\Api\SocialController;
use App\Support\Participant;
use App\Support\Presenter;
use Illuminate\Http\Request;

/**
 * Pages du site web. Les données passent par App\Support\Presenter,
 * exactement comme l'API : le web et l'app affichent la même chose.
 */
class PageController extends Controller
{
    public function __construct(private ActivityService $activities)
    {
    }

    public function home(Request $request)
    {
        $me = $request->user();
        $view = in_array($request->query('vue'), ['direct', 'ouvertes'], true) ? $request->query('vue') : 'fil';
        $data = [
            'current' => 'home', 'view' => $view, 'blocks' => [], 'next' => null, 'matches' => collect(), 'open' => collect(),
            'invitations' => $me ? $this->pendingInvitations($me)->count() : 0,
            // Badge de l'onglet « Duels » : seulement les duels apparus depuis la dernière visite de l'onglet
            'liveCount' => $view === 'direct' ? 0 : self::newDuelsCount($request),
            // De temps en temps (tous les 5 jours au plus) : ce qui plaît. La ville se demande à part, jamais le même jour.
            'askInterests' => $askInterests = $me && \App\Support\Features::on('welcome_questions') && empty($me->interests)
                && (! $me->interests_asked_at || $me->interests_asked_at->lt(now()->subDays(5))),
            'askCity' => $me && \App\Support\Features::on('welcome_questions') && ! $askInterests && ! $me->city
                && (! $me->interests_asked_at || $me->interests_asked_at->lt(now()->subDay()))
                && (! $me->city_asked_at || $me->city_asked_at->lt(now()->subDays(5))),
        ];

        if ($view === 'direct') {
            // Onglet ouvert : tout ce qui est en cours est maintenant « vu »
            if ($me) {
                $me->forceFill(['duels_seen_at' => now()])->saveQuietly();
            } else {
                \Illuminate\Support\Facades\Cookie::queue('tk_duels_seen', (string) now()->timestamp, 60 * 24 * 365);
            }
            $data['matches'] = CompetitionMatch::with(['competition', 'entryA.participant', 'entryB.participant'])
                ->where('status', 'voting')->where('is_bye', false)
                ->orderByDesc('hot_score')->orderByDesc('id')->limit(20)->get()
                ->map(fn ($m) => Presenter::match($m, $me));
        } elseif ($view === 'ouvertes') {
            $mine = array_values(array_intersect((array) ($me?->interests ?? []), array_keys(config('competition.categories'))));
            $data['open'] = Competition::with(['organizer', 'winnerEntry'])->acceptingEntries()
                ->where('access', '!=', 'invited')
                // Ses disciplines d'abord, puis sa ville, puis le reste
                ->when($mine, fn ($q) => $q->orderByRaw('CASE WHEN category IN ('.implode(',', array_fill(0, count($mine), '?')).') THEN 0 ELSE 1 END', $mine))
                ->when($me?->city, fn ($q) => $q->orderByRaw('CASE WHEN geo_city = ? THEN 0 ELSE 1 END', [$me->city]))
                ->orderByDesc('hot_score')->orderByDesc('id')->limit(20)->get()
                ->map(fn ($c) => Presenter::competition($c, $me));
        } else {
            $res = $this->activities->feedFor($me, $request->query('avant'), 8); // 8 d'abord : la page arrive vite sur réseau lent, la suite vient en défilant
            $data['blocks'] = self::feedBlocks(Presenter::activities($res['items'], $me));
            $data['next'] = $res['next_cursor'];
        }

        return view('web.pages.home', $data);
    }

    /** Duels en cours apparus depuis la dernière ouverture de l'onglet « Duels » (compte, ou cookie pour un visiteur). */
    private static function newDuelsCount(Request $request): int
    {
        $me = $request->user();
        $seen = $me ? $me->duels_seen_at : (($t = (int) $request->cookie('tk_duels_seen')) ? \Illuminate\Support\Carbon::createFromTimestamp($t) : null);

        return CompetitionMatch::where('status', 'voting')->where('is_bye', false)
            ->when($seen, fn ($q) => $q->where('created_at', '>', $seen))->count();
    }

    /** Fenêtre ouverte depuis le fil sur un défi : ses duels (en cours d'abord) et comment le relever. */
    public function duelsSheet(Request $request, Competition $competition)
    {
        abort_if($competition->hidden_at || ! \App\Support\Features::type($competition->type), 404);
        $me = $request->user();
        $c = Presenter::competition($competition, $me);
        $matches = $competition->matches()->with(['entryA.participant', 'entryB.participant'])->where('is_bye', false)
            ->orderByRaw("CASE WHEN status = 'voting' THEN 0 ELSE 1 END")->orderByDesc('id')->limit(40)->get()
            ->map(fn ($m) => Presenter::match($m, $me, false, $competition));
        $live = $matches->where('status', 'voting')->count();

        return response()->json([
            'html' => view('web.partials.duels-sheet', ['c' => $c, 'matches' => $matches, 'live' => $live])->render(),
            'title' => $c['title'],
        ]);
    }

    /** Défilement infini : la suite du fil, déjà mise en forme. */
    public function feedFragment(Request $request)
    {
        $me = $request->user();
        $res = $this->activities->feedFor($me, $request->query('avant'), 8);
        $html = '';
        // Chaque page suivante commence par une nouvelle bande de suggestions (personnes, puis Espaces)
        $page = max(1, $request->integer('page', 1));
        $kind = $page % 2 === 1 || \App\Support\Features::off('spaces') ? 'people' : 'spaces';
        $items = $kind === 'people' ? \App\Support\Suggestions::people($me, intdiv($page + 1, 2)) : \App\Support\Suggestions::spaces($me, intdiv($page, 2));
        $blocks = self::feedBlocks(Presenter::activities($res['items'], $me));
        foreach ($blocks as $i => $b) {
            if ($i === 4) {
                $html .= view('web.partials.suggest-strip', ['kind' => $kind, 'items' => $items])->render();
            }
            $html .= $b['type'] === 'post'
                ? view('web.partials.activity', ['a' => $b['item']])->render()
                : view('web.partials.brief', ['items' => $b['items']])->render();
        }

        return response()->json(['html' => $html, 'next' => $res['next_cursor']]);
    }

    /** Une personne = une vue (voir App\Support\Views). */
    private static function countView(Request $request, \Illuminate\Database\Eloquent\Model $model, string $prefix): void
    {
        // Page seulement préchargée (navigation instantanée) : pas encore une vue
        if (str_contains((string) $request->header('Sec-Purpose', $request->header('Purpose', '')), 'prefetch')) {
            return;
        }
        \App\Support\Views::record($request, $model);
    }

    /**
     * Rend le fil lisible : les grandes publications (duels, compétitions, posts) restent des cartes,
     * les petites nouvelles (badges, abonnements, Espaces rejoints, classement…) sont regroupées,
     * et une compétition déjà affichée plus haut n'est pas répétée en grand.
     */
    public static function feedBlocks(array $activities): array
    {
        $brief = ['badge.earned', 'rank.top10', 'user.followed', 'space.joined', 'streak', 'competition.completed'];
        $seen = [];
        $seenMatches = [];
        $blocks = [];
        // Masqué par la modération, type coupé, badges coupés : on ne le montre pas
        $hidden = Competition::whereNotNull('hidden_at')->pluck('id')->flip();
        foreach ($activities as $a) {
            $kind = $a['preview']['kind'] ?? null;
            $compId = $a['preview']['competition']['id'] ?? ($a['preview']['match']['competition']['id'] ?? null);
            $compType = $a['preview']['competition']['type'] ?? ($a['preview']['match']['competition']['type'] ?? null);
            if (($compType && ! \App\Support\Features::type($compType)) || ($compId && isset($hidden[$compId]))
                || (\App\Support\Features::off('badges') && in_array($a['verb'], ['badge.earned', 'streak'], true))
                || (\App\Support\Features::off('spaces') && in_array($a['verb'], ['space.joined', 'space.created'], true))) {
                continue;
            }
            // Duel affiché : celui d'un aperçu de duel, ou celui qu'un défi montre à la place de sa carte
            $pc = $a['preview']['competition'] ?? null;
            $matchId = $kind === 'match' ? ($a['preview']['match']['id'] ?? null)
                : (($pc && in_array($pc['type'], ['duel', 'challenge'], true) && ($pc['featured_match']['status'] ?? null) === 'voting') ? $pc['featured_match']['id'] : null);
            $isBrief = (in_array($a['verb'], $brief, true) && ! in_array($kind, ['standings', 'bracket'], true)) || in_array($kind, [null, 'badge', 'competition_summary'], true)
                || ($kind === 'competition' && $compId && isset($seen[$compId]))
                || ($matchId && isset($seenMatches[$matchId]));
            if (! $isBrief && $matchId) {
                $seenMatches[$matchId] = true;
            }
            if (! $isBrief && $compId && $kind === 'competition') {
                $seen[$compId] = true;
            }
            // Les petites nouvelles (badges, abonnements, Espaces rejoints…) restent dans les notifications
            if (! $isBrief) {
                $blocks[] = ['type' => 'post', 'item' => $a];
            }
        }

        return $blocks;
    }

    /** Modifier son profil : photo, couverture (photo ou vidéo), nom, pseudo, bio. */
    public function editProfile(Request $request)
    {
        $u = $request->user();

        return view('web.pages.edit-profile', [
            'current' => 'profile', 'title' => 'Modifier le profil', 'hideRail' => true, 'kind' => 'user',
            'action' => '/parametres/profil', 'back' => '/@'.$u->username,
            'avatar' => $u->avatar_url, 'hasAvatar' => (bool) $u->getRawOriginal('avatar'), 'cover' => $u->getRawOriginal('cover') ? $u->urlt('cover') : null, 'coverType' => $u->cover_type ?: 'image',
            'values' => ['first_name' => $u->first_name, 'last_name' => $u->last_name, 'username' => $u->username, 'bio' => $u->bio, 'city' => $u->city, 'school_space_id' => $u->school_space_id, 'interests' => $u->interests ?? [], 'located' => \App\Support\Geo::fresh($u)],
        ]);
    }

    /** Modifier un Espace (gestionnaires seulement). */
    public function editSpace(Request $request, string $slug)
    {
        $space = Space::where('slug', $slug)->firstOrFail();
        abort_unless($space->canEditInfo($request->user()), 403);

        return view('web.pages.edit-profile', [
            'current' => 'spaces', 'title' => 'Modifier l\'Espace', 'hideRail' => true, 'kind' => 'space',
            'action' => '/espaces/'.$space->slug.'/modifier', 'back' => '/espaces/'.$space->slug,
            'avatar' => $space->avatar_url, 'hasAvatar' => (bool) $space->getRawOriginal('avatar'), 'cover' => $space->cover ? $space->cover_url : null, 'coverType' => $space->cover_type ?: 'image',
            'values' => ['name' => $space->name, 'bio' => $space->bio, 'category' => $space->category, 'subcategory' => $space->subcategory, 'kind' => $space->kind, 'website' => $space->website, 'site_template' => $space->site_template, 'slug' => $space->slug, 'location' => $space->location, 'join_approval' => (bool) $space->join_approval],
        ]);
    }

    /** Changer son mot de passe (code par SMS). */
    public function passwordPage(Request $request)
    {
        $me = $request->user();

        return view('web.pages.password', ['current' => 'profile', 'title' => 'Mot de passe', 'hideRail' => true,
            'phone' => $me->phone_code.' •••• '.substr((string) $me->phone_number, -3), 'sent' => (bool) session('password_code_sent')]);
    }

    /** Membres de l'Espace : demandes, ajout, rôles (administrateurs et modérateurs). */
    public function spaceMembers(Request $request, string $slug)
    {
        $space = Space::where('slug', $slug)->firstOrFail();
        $data = app(\App\Services\SpaceTeam::class)->data($space, $request->user());

        return view('web.pages.space-members', $data + ['current' => 'spaces', 'title' => 'Membres · '.$space->name, 'hideRail' => true]);
    }

    /** Image d'aperçu des liens partagés (WhatsApp, Facebook…). */
    public function matchImage(CompetitionMatch $match)
    {
        return response()->file(\App\Support\ShareImage::forMatch($match), ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=600']);
    }

    public function competitionImage(Competition $competition)
    {
        return response()->file(\App\Support\ShareImage::forCompetition($competition), ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=600']);
    }

    /** Mode swipe : les duels un par un, en plein écran ; on glisse vers celui qu'on préfère. */
    public function swipe(Request $request)
    {
        $me = $request->user();
        $q = CompetitionMatch::with(['competition.organizer', 'entryA.participant', 'entryB.participant'])
            ->where('status', 'voting')->where('is_bye', false)->whereNotNull('entry_a_id')->whereNotNull('entry_b_id')
            ->whereHas('competition', fn ($c) => $c->where('vote_cost', 0)); // les duels payants se votent avec un choix de coins
        if ($me) {
            $q->whereNotIn('id', \App\Models\CompetitionVote::where('user_id', $me->id)->whereNotNull('match_id')->pluck('match_id'));
        }
        $matches = $q->orderByDesc('hot_score')->orderByDesc('id')->limit(40)->get()
            ->map(fn ($m) => Presenter::match($m, $me))
            ->filter(fn ($m) => ! $me || $m['viewer']['can_vote'])
            ->take(20)->values();

        return view('web.pages.swipe', ['current' => 'swipe', 'matches' => $matches, 'title' => 'Voter', 'hideRail' => true]);
    }

    public function discover(Request $request)
    {
        // Avec un filtre (type, recherche…), Découvrir montre directement la liste
        if (collect(['type', 'q', 'statut', 'categorie', 'mode'])->contains(fn ($k) => $request->filled($k)) || $request->has('page')) {
            return $this->explore($request);
        }

        $data = app(SocialController::class)->discover($request)->getData(true)['data'];

        return view('web.pages.discover', ['current' => 'discover', 'd' => $data, 'title' => 'Découvrir']);
    }

    public function explore(Request $request)
    {
        $me = $request->user();
        $q = Competition::query()->visible()->with(['organizer', 'winnerEntry.participant', 'post']);
        $type = $request->query('type');
        $status = $request->query('statut', 'en-cours');
        if ($type) {
            $q->where('type', $type);
        }
        if ($cat = $request->query('categorie')) {
            $q->where('category', $cat);
        }
        if ($request->query('mode') === 'espaces') {
            $q->whereIn('participation_mode', ['space', 'mixed']);
        }
        if ($search = trim((string) $request->query('q'))) {
            $q->where('title', 'like', '%'.$search.'%');
        }
        // Sans filtre de discipline : celles qui plaisent à la personne d'abord
        $mine = $cat ? [] : array_values(array_intersect((array) ($me?->interests ?? []), array_keys(config('competition.categories'))));
        if ($mine && $status !== 'termines') {
            $q->orderByRaw('CASE WHEN category IN ('.implode(',', array_fill(0, count($mine), '?')).') THEN 0 ELSE 1 END', $mine);
        }
        $status === 'termines'
            ? $q->where('status', Competition::STATUS_COMPLETED)->latest('completed_at')
            : $q->whereIn('status', [Competition::STATUS_OPEN, Competition::STATUS_LIVE])->orderByDesc('hot_score')->latest();

        $page = $q->paginate(12)->withQueryString();

        return view('web.pages.explore', [
            'current' => 'discover',
            'title' => 'Découvrir',
            'items' => collect($page->items())->map(fn ($c) => Presenter::competition($c, $me)),
            'page' => $page,
            'filters' => compact('type', 'status') + ['categorie' => $request->query('categorie'), 'mode' => $request->query('mode'), 'q' => $search],
        ]);
    }

    public function competition(Request $request, Competition $competition)
    {
        $me = $request->user();
        abort_unless($competition->isShownTo($me), 404);
        self::countView($request, $competition, 'c');
        $c = Presenter::competition($competition, $me, true);
        $default = match ($competition->type) {
            Competition::TYPE_CONTEST => 'classement',
            Competition::TYPE_TOURNAMENT => $competition->status === Competition::STATUS_OPEN ? 'participants' : 'duels',
            default => 'duels',
        };
        $tab = $request->query('tab', $default);
        // Infos et commentaires s'ouvrent dans une fenêtre par-dessus le contenu principal
        $openSheet = in_array($tab, ['infos', 'commentaires'], true) ? $tab : null;
        if ($openSheet || ! in_array($tab, ['duels', 'tableau', 'classement', 'participants', 'propositions', 'gestion'], true)) {
            $tab = $default;
        }

        $data = ['current' => 'explore', 'c' => $c, 'tab' => $tab, 'openSheet' => $openSheet, 'model' => $competition,
            'edit' => app(\App\Services\Competition\CompetitionEditor::class)->rules($competition, $me),
            'title' => $competition->title, 'description' => $competition->description ?: implode(' ', $c['rules_summary']),
            'ogImage' => url('/c/'.$competition->slug.'/apercu.png').'?v='.substr(md5((string) $competition->updated_at), 0, 8)];

        // Défi envoyé à un numéro sans compte : l'organisateur peut le relancer sur WhatsApp
        $data['phoneInvite'] = $me && $competition->canManage($me)
            ? \App\Models\CompetitionPhoneInvite::where('competition_id', $competition->id)->latest('id')->first()
            : null;

        if ($tab === 'duels') {
            $data['matches'] = $competition->matches()->with(['entryA.participant', 'entryB.participant'])->where('is_bye', false)
                ->orderByRaw("CASE WHEN status = 'voting' THEN 0 ELSE 1 END")->orderByDesc('id')->limit(30)->get()
                ->map(fn ($m) => Presenter::match($m, $me, false, $competition));
        }
        if (in_array($tab, ['classement', 'participants'], true)) {
            $q = $competition->entries()->with(['participant', 'submitter'])->whereIn('status', ['active', 'winner', 'eliminated']);
            if ($tab === 'classement') {
                $competition->status === Competition::STATUS_COMPLETED ? $q->orderBy('rank') : $q->orderByDesc('score')->orderByDesc('votes_count');
            } elseif ($competition->type === Competition::TYPE_CHALLENGE) {
                // Défi : les challengers classés par votes reçus, le premier est le joker
                $q->orderByDesc('votes_count')->orderByDesc('wins')->orderBy('id');
            } else {
                $q->latest();
            }
            $data['entries'] = collect(Presenter::withDuelLinks($q->limit(100)->get()->map(fn ($e) => Presenter::entry($e, $competition, $me)), $competition));
            if ($competition->type === Competition::TYPE_CHALLENGE) {
                $organizer = $competition->organizer;
                $data['jokerId'] = $data['entries']->first(fn ($e) => ($e['votes_count'] ?? 0) > 0 && ! (
                    $organizer && ($e['participant']['type'] ?? null) === ($organizer instanceof Space ? 'space' : 'user') && (int) ($e['participant']['id'] ?? 0) === (int) $organizer->getKey()
                ))['id'] ?? null;
            }
        }
        if ($tab === 'tableau' && $competition->type === Competition::TYPE_TOURNAMENT) {
            $data['bracket'] = collect(app(TournamentService::class)->bracket($competition))->map(fn ($r) => [
                'name' => $r['name'],
                'matches' => collect($r['matches'])->map(fn ($m) => $m ? Presenter::match($m, $me, false, $competition) : null),
            ]);
        }
        if ($tab === 'gestion' && $c['viewer']['can_manage']) {
            $data['pending'] = $competition->entries()->with(['participant', 'submitter'])->where('status', 'pending')->get()
                ->map(fn ($e) => Presenter::entry($e, $competition));
        }
        if ($tab === 'propositions' && $me) {
            $data['proposals'] = EntryProposal::with(['user', 'space'])->where('competition_id', $competition->id)
                ->whereIn('space_id', $me->allSpaceIds() ?: [0])->latest()->get();
        }

        // Défi envoyé à un numéro sans compte : l'organisateur peut le renvoyer sur WhatsApp
        $data['phoneInvite'] = $c['viewer']['can_manage']
            ? \App\Models\CompetitionPhoneInvite::where('competition_id', $competition->id)->where('status', 'pending')->latest('id')->first()
            : null;

        $post = $competition->post;
        // Commentaires principaux avec leurs réponses, j'aime compris
        $withLikes = fn ($q) => $q->withCount('likes')->withExists(['likes as liked' => fn ($l) => $l->where('user_id', $me?->id ?? 0)]);
        $data['comments'] = $post && $tab === 'commentaires'
            ? $post->comments()->whereNull('parent_id')->with(['author', 'match.entryA', 'match.entryB', 'replies' => fn ($r) => $withLikes($r->with(['author', 'match.entryA', 'match.entryB'])->oldest())])
                ->tap($withLikes)->latest()->limit(60)->get()
            : collect();
        if ($tab === 'commentaires') {
            [$data['duels'], $data['duel']] = self::commentDuels($competition, $request->integer('duel'));
        }
        $data['liked'] = $me && $post ? $post->likes()->where('user_id', $me->id)->exists() : false;
        $data['mySpaces'] = $me ? Space::whereIn('id', $me->allSpaceIds())->get() : collect();

        return view('web.pages.competition', $data);
    }

    /**
     * Les duels d'un défi ou d'un tournoi qu'un commentaire peut viser (« X contre Y »),
     * et celui déjà choisi quand on commente depuis un duel.
     */
    public static function commentDuels(Competition $competition, ?int $current = null): array
    {
        if (! in_array($competition->type, [Competition::TYPE_CHALLENGE, Competition::TYPE_TOURNAMENT], true)) {
            return [[], null];
        }
        $rows = $competition->matches()->with(['entryA', 'entryB'])->whereNotNull('entry_a_id')->whereNotNull('entry_b_id')
            ->where('is_bye', false)->latest('id')->take(100)->get();
        $duels = $rows->map(fn ($m) => \App\Support\CommentText::duel($m))->values()->all();
        $duel = $current ? collect($duels)->firstWhere('id', $current) : null;

        return [$duels, $duel];
    }

    /** Commentaires d'une publication, pour la fenêtre qui monte du bas (comme TikTok). */
    public function commentsFragment(Request $request, Competition $competition)
    {
        $me = $request->user();
        abort_unless($competition->isShownTo($me) && \App\Support\Features::on('comments'), 404);
        $post = $competition->post;
        $withLikes = fn ($q) => $q->withCount('likes')->withExists(['likes as liked' => fn ($l) => $l->where('user_id', $me?->id ?? 0)]);
        $comments = $post
            ? $post->comments()->whereNull('parent_id')->with(['author', 'match.entryA', 'match.entryB', 'replies' => fn ($r) => $withLikes($r->with(['author', 'match.entryA', 'match.entryB'])->oldest())])
                ->tap($withLikes)->latest()->limit(60)->get()
            : collect();
        $c = ['slug' => $competition->slug, 'url' => $competition->url(), 'comments_count' => (int) ($post?->comments_count ?? $comments->count())];
        [$duels, $duel] = self::commentDuels($competition, $request->integer('duel'));

        return response()->json([
            'html' => view('web.partials.comments-block', ['c' => $c, 'comments' => $comments, 'inSheet' => true, 'duels' => $duels, 'duel' => $duel])->render(),
            'count' => $post ? $post->comments()->count() : 0,
        ]);
    }

    public function match(Request $request, CompetitionMatch $match)
    {
        $me = $request->user();
        abort_unless($match->competition?->isShownTo($me), 404);
        self::countView($request, $match, 'm');
        $m = Presenter::match($match, $me, true);
        $a = $m['entry_a']['display_name'] ?? '?';
        $b = $m['entry_b']['display_name'] ?? '?';

        return view('web.pages.match', [
            'current' => 'explore', 'm' => $m,
            'title' => $a.' vs '.$b,
            'description' => 'Vote dans « '.$m['competition']['title'].' » : '.$a.' contre '.$b.'.',
            'ogImage' => url('/m/'.$match->id.'/apercu.png').'?v='.substr(md5($match->votes_a.'-'.$match->votes_b.'-'.$match->status), 0, 8),
        ]);
    }

    /** Morceau HTML du duel, pour le rafraîchissement du score en direct. */
    public function matchFragment(Request $request, CompetitionMatch $match)
    {
        return response()->json(['html' => view('web.partials.versus', ['m' => Presenter::match($match, $request->user(), true), 'showTop' => false, 'social' => true, 'showTime' => false])->render()]);
    }

    public function create(Request $request)
    {
        $me = $request->user();
        $type = in_array($request->query('type'), ['challenge', 'duel', 'contest', 'tournament'], true) ? $request->query('type') : 'challenge';
        if (! \App\Support\Features::type($type)) {
            $type = collect(['challenge', 'duel', 'contest', 'tournament'])->first(fn ($t) => \App\Support\Features::type($t)) ?? abort(404);
        }
        // Défi et duel : un seul écran, comme une publication. Concours et tournoi : l'assistant en étapes.
        $quick = in_array($type, ['challenge', 'duel'], true);

        return view($quick ? 'web.pages.create-quick' : 'web.pages.create', [
            'current' => 'create', 'title' => match ($type) { 'duel' => 'Lancer un duel', 'contest' => 'Lancer un concours', 'tournament' => 'Lancer un tournoi', default => 'Lancer un défi' }, // la colonne de droite reste : la carte est à sa place habituelle, au centre
            'mySpaces' => Space::whereIn('id', $me->publishableSpaceIds())->get(),
            'preset' => $type,
            'opponent' => $request->query('adversaire'),
            'opponentType' => $request->query('adversaire_type', 'user'),
            'space' => $request->query('espace'),
        ]);
    }

    /** Pour « Publier en tant que » : moi, puis les Espaces que je gère. */
    public function mySpaces(Request $request)
    {
        $me = $request->user();
        $list = collect([['slug' => '', 'name' => $me->first_name.' '.$me->last_name, 'avatar_url' => $me->avatar_url, 'sub' => 'Moi']])
            ->concat(Space::whereIn('id', $me->publishableSpaceIds())->where('is_active', true)->orderBy('name')->get()
                ->map(fn ($s) => ['slug' => $s->slug, 'name' => $s->name, 'avatar_url' => $s->avatar_url, 'sub' => \App\Support\Rankings::spaceLabel($s) ?: 'Espace']));

        return response()->json(['items' => $list->values(), 'can_create' => \App\Support\Features::on('spaces')]);
    }

    public function leaderboards(Request $request)
    {
        return view('web.pages.leaderboards', self::leaderboardData($request, $request->user()));
    }

    /** Données du classement (site et app) : ma place, mes tableaux, ou la liste complète avec filtres. */
    public static function leaderboardData(Request $request, ?User $me): array
    {
        // Pas une longue liste de 1 000 personnes : d'abord ce qui compte pour moi
        // (ma place et mes voisins, mes abonnements, ma ville, ma discipline), puis seulement le top 10.
        // « Cette semaine » par défaut : tout le monde repart de zéro le lundi, un nouveau peut être premier.
        $kind = in_array($request->query('kind'), ['space', 'city'], true) ? $request->query('kind')
            : ($request->query('kind') === 'school' ? 'space' : 'user'); // ancien lien « universités »
        $period = $request->query('periode') === 'saison' ? 'saison' : 'semaine';
        // Saison choisie (?saison=2026-T3) : on peut revoir le classement final des saisons passées
        $seasons = \App\Models\Rating::seasons();
        $asked = (string) $request->query('saison');
        $season = $period === 'saison'
            ? (collect($seasons)->contains('value', $asked) ? $asked : \App\Models\Rating::currentSeason())
            : \App\Models\Rating::currentWeek();
        $past = $period === 'saison' && $season !== \App\Models\Rating::currentSeason();
        $sq = $past ? '&saison='.$season : ''; // garde la saison dans les liens
        $categories = config('competition.categories');
        $category = array_key_exists((string) $request->query('discipline'), $categories) ? $request->query('discipline') : 'all';
        $type = array_key_exists((string) $request->query('type'), config('spaces.categories')) ? $request->query('type')
            : ($request->query('kind') === 'school' ? 'education' : null);
        $city = trim((string) $request->query('ville')) ?: null;
        $slug = $request->query('espace') ?: $request->query('institution') ?: $request->query('universite');
        $space = $slug ? Space::where('slug', $slug)->first() : null;
        $full = $request->query('vue') === 'tout' || $city || $space || $category !== 'all';

        $data = ['kind' => $kind, 'season_label' => \App\Models\Rating::seasonLabel($season), 'season' => $season, 'past' => $past, 'rows' => [], 'me' => null];
        $boards = [];
        if ($kind === 'user' && $full) {
            $data['rows'] = \App\Support\Rankings::people($season, $city, $space?->id, 100, $category);
        } elseif ($kind === 'user') {
            $data['me'] = $me ? \App\Support\Rankings::around($season, $me) : null;
            if ($me && $me->following()->exists()) {
                $boards[] = ['title' => 'Toi et tes abonnements', 'icon' => 'users', 'rows' => \App\Support\Rankings::among($season, $me), 'more' => null];
            }
            if ($me?->city) {
                $boards[] = ['title' => 'À '.$me->city, 'icon' => 'map-pin', 'rows' => \App\Support\Rankings::people($season, $me->city, null, 5),
                    'more' => '?vue=tout&periode='.$period.$sq.'&ville='.urlencode($me->city)];
            }
            $fav = collect((array) ($me?->interests ?? []))->first(fn ($k) => isset($categories[$k]));
            if ($fav) {
                $boards[] = ['title' => 'En '.mb_strtolower($categories[$fav]), 'icon' => 'sparkles', 'rows' => \App\Support\Rankings::people($season, null, null, 5, $fav),
                    'more' => '?vue=tout&periode='.$period.$sq.'&discipline='.$fav];
            }
            $boards[] = ['title' => 'Top 10 de Sosa', 'icon' => 'crown', 'rows' => \App\Support\Rankings::people($season, null, null, 10),
                'more' => '?vue=tout&periode='.$period.$sq];
            $boards = array_values(array_filter($boards, fn ($b) => count($b['rows'])));
        }
        $groups = match ($kind) {
            'space' => \App\Support\Rankings::spaces($season, $type),
            'city' => \App\Support\Rankings::cities($season),
            default => [],
        };

        return ['current' => 'leaderboards', 'title' => 'Classement', 'd' => $data, 'sort' => 'points',
            'boards' => $boards, 'full' => $full, 'period' => $period, 'seasons' => $seasons, 'season' => $season, 'past' => $past, 'sq' => $sq,
            'groups' => $groups, 'city' => $city, 'space' => $space, 'type' => $type, 'category' => $category, 'categories' => $categories,
            'cities' => \App\Models\User::whereNotNull('city')->where('city', '!=', '')->distinct()->orderBy('city')->pluck('city'),
            'spaceTypes' => collect(config('spaces.categories'))->only(Space::where('is_active', true)->whereNotNull('category')->distinct()->pluck('category'))->all(),
            'institutions' => Space::where('kind', 'school')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'slug'])];
    }

    public function profile(Request $request, string $username)
    {
        $user = User::where('username', $username)->first();
        if (! $user) {
            // Ancien pseudo : on renvoie vers le profil actuel (les liens partagés restent bons)
            $now = User::byOldUsername($username);
            abort_unless($now, 404);

            return redirect('/@'.$now->username, 301);
        }
        $me = $request->user();
        $stats = app(SocialController::class)->stats($user, $me);
        $res = $this->activities->forActor($user, null, 20);

        // Profil privé : on demande aux moteurs de ne pas l'indexer
        $noIndex = (bool) ($user->is_private ?? false);

        return view('web.pages.profile', [
            'current' => $me && $me->id === $user->id ? 'profile' : '',
            'owner' => $user, 's' => $stats, 'noIndex' => $noIndex,
            'title' => Participant::name($user),
            'description' => 'Classement, badges et duels de '.Participant::name($user).' sur Sosa.',
            'activities' => Presenter::activities($res['items'], $me),
            'isMe' => $me && $me->id === $user->id,
            'following' => $me ? $me->isFollowing($user) : false,
            'followers' => $user->followers()->count(),
            'followingCount' => $user->following()->count(),
        ]);
    }

    /** Ceux qui suivent quelqu'un, et ceux qu'il suit. Les personnes que je suis apparaissent en premier. */
    public function follows(Request $request, string $username, string $list)
    {
        $user = User::where('username', $username)->first();
        if (! $user) {
            $now = User::byOldUsername($username);
            abort_unless($now, 404);

            return redirect('/@'.$now->username.'/'.$list, 301);
        }
        $me = $request->user();
        $followers = $list === 'abonnes';
        $q = trim((string) $request->query('q'));
        $mineIds = $me ? $me->following()->pluck('users.id')->all() : [];
        $people = ($followers ? $user->followers() : $user->following())
            ->when($q, fn ($w) => $w->where(fn ($x) => $x->where('first_name', 'like', '%'.$q.'%')->orWhere('last_name', 'like', '%'.$q.'%')->orWhere('username', 'like', '%'.$q.'%')))
            ->when($mineIds, fn ($w) => $w->orderByRaw('CASE WHEN users.id IN ('.implode(',', array_map('intval', $mineIds)).') THEN 0 ELSE 1 END'))
            ->orderByDesc('follows.created_at')
            ->paginate(30)->withQueryString();

        return view('web.pages.follows', [
            'current' => $me && $me->id === $user->id ? 'profile' : '', 'title' => Participant::name($user),
            'owner' => $user, 'list' => $list, 'people' => $people, 'q' => $q, 'mineIds' => array_flip($mineIds),
            'followersCount' => $user->followers()->count(), 'followingCount' => $user->following()->count(),
            'followsMe' => $me ? array_flip($me->followers()->pluck('users.id')->all()) : [],
        ]);
    }

    /** « À suivre » : toutes les personnes suggérées (même ville ou institution d'abord, puis les plus suivies). */
    public function suggestions(Request $request)
    {
        $me = $request->user();
        $page = max(1, $request->integer('page', 1));
        $q = trim((string) $request->query('q'));
        // Avec une recherche : tout le monde (par nom ou pseudo) ; sinon les suggestions
        $items = $q !== '' ? \App\Support\Suggestions::searchPeople($me, $q, $page - 1) : \App\Support\Suggestions::people($me, $page - 1, 30);
        $more = count($items) === 30;

        return view('web.pages.suggestions', [
            'current' => '', 'title' => 'À suivre', 'items' => $items, 'page' => $page, 'more' => $more, 'q' => $q,
        ]);
    }

    public function spaces(Request $request)
    {
        $me = $request->user();
        $search = trim((string) $request->query('q'));
        $spaces = Space::query()->where('is_active', true)->withCount('members')
            ->when($search, fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
            ->orderByDesc('members_count')->paginate(20)->withQueryString();

        return view('web.pages.spaces', ['current' => 'spaces', 'title' => 'Espaces', 'spaces' => $spaces, 'q' => $search,
            'mine' => $me ? $me->allSpaceIds() : []]);
    }

    /** Le petit site de l'Espace (lions.sosa.social, ou l'aperçu /espaces/lions/site). ?modele= pour essayer un autre modèle. */
    /** Compétitions d'un Espace : même page que pour un profil, sans onglet « participées ». */
    public function spaceCompetitions(Request $request, string $slug)
    {
        $space = Space::where('slug', $slug)->where('is_active', true)->firstOrFail();
        $tri = in_array($request->query('tri'), ['recentes', 'bientot', 'anciennes'], true) ? $request->query('tri') : 'recentes';

        $q = $space->organizedCompetitions();
        $q = match ($tri) {
            'bientot' => $q->orderByRaw('ends_at IS NULL')->orderBy('ends_at'),
            'anciennes' => $q->oldest('id'),
            default => $q->latest('id'),
        };

        return view('web.pages.profile-competitions', [
            'current' => 'spaces', 'noIndex' => false,
            'owner' => $space, 'me' => $request->user(), 'tab' => 'creees', 'tri' => $tri,
            'ownerName' => $space->name,
            'pageUrl' => '/espaces/'.$space->slug.'/competitions',
            'backUrl' => '/espaces/'.$space->slug,
            'showJoined' => false,
            'title' => 'Compétitions de '.$space->name,
            'description' => 'Les compétitions organisées par '.$space->name.' sur Sosa.',
            'comps' => $q->paginate(20)->withQueryString(),
            'madeCount' => $space->organizedCompetitions()->count(),
            'joinedCount' => 0,
        ]);
    }
    /** Compétitions d'un profil : deux onglets (créées / participées) et un tri. */
    public function profileCompetitions(Request $request, string $handle)
    {
        $handle = ltrim($handle, '@');
        $user = User::where('username', $handle)->firstOrFail();
        $me = $request->user();

        $tab = $request->query('onglet') === 'participe' ? 'participe' : 'creees';
        $tri = in_array($request->query('tri'), ['recentes', 'bientot', 'anciennes'], true) ? $request->query('tri') : 'recentes';

        $made = $user->organizedCompetitions();
        $joined = method_exists($user, 'enteredCompetitions') ? $user->enteredCompetitions() : null;

        $q = $tab === 'participe' && $joined ? $joined : $made;
        $q = match ($tri) {
            'bientot' => $q->orderByRaw('ends_at IS NULL')->orderBy('ends_at'),
            'anciennes' => $q->oldest('id'),
            default => $q->latest('id'),
        };

        return view('web.pages.profile-competitions', [
            'current' => 'profile', 'noIndex' => false,
            'owner' => $user, 'me' => $me, 'tab' => $tab, 'tri' => $tri, 'ownerName' => Participant::name($user), 'pageUrl' => '/@'.$user->username.'/competitions', 'backUrl' => '/@'.$user->username, 'showJoined' => true,
            'title' => 'Compétitions de '.Participant::name($user),
            'description' => 'Les compétitions de '.Participant::name($user).' sur Sosa : créées et participées.',
            'comps' => $q->paginate(20)->withQueryString(),
            'madeCount' => $user->organizedCompetitions()->count(),
            'joinedCount' => $joined ? $joined->count() : 0,
        ]);
    }
    public function spaceSite(Request $request, string $slug)
    {
        abort_if(\App\Support\Features::off('spaces'), 404);
        $space = Space::where('slug', $slug)->where('is_active', true)->firstOrFail();
        $data = \App\Support\SpaceSiteData::for($space, $request->user());
        if (array_key_exists((string) $request->query('modele'), config('sites.templates'))) {
            $data['template'] = $request->query('modele');
        }

        return view('sites.'.$data['template'], $data);
    }

    public function space(Request $request, string $slug)
    {
        $space = Space::where('slug', $slug)->firstOrFail();
        $me = $request->user();
        $stats = app(SocialController::class)->stats($space, $me);
        $res = $this->activities->forActor($space, null, 20);
        $competitions = Competition::visible()->where('organizer_type', Space::class)->where('organizer_id', $space->id)
            ->latest()->limit(12)->get()->map(fn ($c) => Presenter::competition($c, $me));

        return view('web.pages.space', [
            'current' => 'spaces', 'space' => $space, 's' => $stats, 'competitions' => $competitions,
            'title' => $space->name, 'description' => $space->bio ?: 'Espace '.$space->name.' sur Sosa.',
            'activities' => Presenter::activities($res['items'], $me),
            'isMember' => $me ? $space->hasAffiliate($me) : false,
            'isPending' => $me ? $space->isPending($me) : false,
            'isInvited' => $me ? $space->isInvited($me) : false,
            'canManage' => $me ? $space->canManage($me) : false,
            'canEdit' => $me ? $space->canEditInfo($me) : false,
            'canPublish' => $me ? $space->canPublish($me) : false,
            'pendingCount' => $me && $space->canManage($me) ? $space->pendingMembers()->count() : 0,
        ]);
    }

    public function notifications(Request $request)
    {
        $me = $request->user();
        $page = $me->notifications()->simplePaginate(30);
        $list = collect($page->items())->map(fn ($n) => Presenter::notification($n));
        $me->unreadNotifications()->update(['read_at' => now()]);

        return view('web.pages.notifications', ['current' => 'notifications', 'title' => 'Notifications', 'items' => $list, 'page' => $page]);
    }

    public function invitations(Request $request)
    {
        // « Reçus » : ce qui attend ma réponse (défis, revanches, invitations, candidatures à valider)
        return view('web.pages.invitations', ['current' => 'invitations', 'title' => 'Reçus', 'items' => \App\Support\Inbox::items($request->user())]);
    }

    public function post(Request $request, string $slug)
    {
        $post = Post::where('slug', $slug)->where('status', 'published')->firstOrFail();
        if ($post->type === 'competition' && ($c = Competition::where('post_id', $post->id)->first())) {
            return redirect($c->url());
        }

        return view('web.pages.post', ['post' => $post, 'title' => 'Publication', 'author' => Participant::present($post->author),
            'comments' => $post->comments()->with('author')->latest()->limit(50)->get()]);
    }

    private function pendingInvitations(User $me)
    {
        $spaceIds = $me->manageableSpaceIds();

        return CompetitionInvitation::with(['competition.organizer', 'invitee', 'inviter'])->where('status', 'pending')
            ->where(function ($q) use ($me, $spaceIds) {
                $q->where(fn ($x) => $x->where('invitee_type', User::class)->where('invitee_id', $me->id))
                    ->orWhere(fn ($x) => $x->where('invitee_type', Space::class)->whereIn('invitee_id', $spaceIds ?: [0]));
            })->latest()->get()->filter(fn ($i) => $i->competition);
    }
}
