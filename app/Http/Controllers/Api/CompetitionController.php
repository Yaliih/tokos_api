<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CompetitionException;
use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\EntryProposal;
use App\Models\Space;
use App\Services\Competition\CompetitionService;
use App\Services\Competition\TournamentService;
use App\Services\Competition\VoteService;
use App\Support\Participant;
use App\Support\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Compétitions : défis, duels, concours et tournois (un seul contrôleur pour les quatre).
 */
class CompetitionController extends Controller
{
    public function __construct(
        private CompetitionService $service,
        private VoteService $votes,
        private TournamentService $tournaments,
    ) {
    }

    /** Options pour les formulaires de création (catégories, limites, formats). */
    public function options(Request $request): JsonResponse
    {
        $viewer = $this->viewer($request);

        return response()->json(['success' => true, 'data' => [
            'types' => [
                ['value' => 'challenge', 'label' => 'Défi', 'description' => 'Tu publies une performance, les autres viennent t\'affronter un par un.'],
                ['value' => 'duel', 'label' => 'Duel', 'description' => 'Défie une personne ou un Espace précis, ou crée un versus entre deux propositions.'],
                ['value' => 'contest', 'label' => 'Concours', 'description' => 'Des candidats, un classement final par votes (et jury).'],
                ['value' => 'tournament', 'label' => 'Tournoi', 'description' => 'Tableau à élimination directe, tour après tour.'],
            ],
            'categories' => collect(config('competition.categories'))->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'media_types' => config('competition.media_types'),
            'limits' => config('competition.limits'),
            'tournament_sizes' => config('competition.tournament_sizes'),
            'media_limits' => config('competition.media'),
            'duration_presets' => collect(config('competition.duration_presets'))->map(fn ($label, $hours) => compact('hours', 'label'))->values(),
            'durations' => config('competition.durations'),
            'my_spaces' => $viewer ? Space::whereIn('id', $viewer->publishableSpaceIds())->get()->map(fn ($s) => Participant::present($s))->values() : [],
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $viewer = $this->viewer($request);
        $q = Competition::query()->visible()->with(['organizer', 'winnerEntry.participant', 'post']);

        if ($type = $request->query('type')) {
            $q->where('type', $type);
        }
        if ($status = $request->query('status')) {
            $q->whereIn('status', explode(',', $status));
        } else {
            $q->whereIn('status', [Competition::STATUS_OPEN, Competition::STATUS_LIVE]);
        }
        if ($category = $request->query('category')) {
            $q->where('category', $category);
        }
        if ($space = $request->query('space')) {
            $q->where('organizer_type', Space::class)->whereIn('organizer_id', Space::where('slug', $space)->pluck('id'));
        }
        if ($mode = $request->query('participation_mode')) {
            $q->where('participation_mode', $mode);
        }
        if ($search = trim((string) $request->query('q'))) {
            $q->where('title', 'like', '%'.$search.'%');
        }

        match ($request->query('sort', 'hot')) {
            'new' => $q->latest(),
            'ending' => $q->whereNotNull('ends_at')->orderBy('ends_at'),
            'popular' => $q->orderByDesc('votes_count'),
            default => $q->orderByDesc('hot_score')->latest(),
        };

        $page = $q->paginate(min(30, (int) $request->integer('per_page', 15)));

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn ($c) => Presenter::competition($c, $viewer))->values(),
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'has_more' => $page->hasMorePages(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->all() + ['cover' => $request->file('cover')];
        // Duel : plusieurs adversaires possibles, un duel chacun
        if (($input['type'] ?? null) === 'duel' && ($input['format'] ?? 'challenge') !== 'versus') {
            [$made, $failed] = $this->service->createDuels($request->user(), $input);
            $competition = $made[0];

            return response()->json([
                'success' => true,
                'message' => count($made) > 1 ? count($made).' duels envoyés : chacun a reçu son défi.' : $competition->typeLabel().' publié !',
                'data' => Presenter::competition($competition, $request->user(), true),
                'count' => count($made),
                'duels' => collect($made)->map(fn ($c) => ['slug' => $c->slug, 'url' => $c->url(), 'title' => $c->title])->all(),
                'failed' => $failed,
            ], 201);
        }
        $competition = $this->service->create($request->user(), $input);

        return response()->json([
            'success' => true,
            'message' => $competition->typeLabel().' publié !',
            'data' => Presenter::competition($competition, $request->user(), true),
        ], 201);
    }

    public function show(Request $request, Competition $competition): JsonResponse
    {
        \App\Support\Views::record($request, $competition);

        return response()->json(['success' => true, 'data' => Presenter::competition($competition, $this->viewer($request), true)]);
    }

    /** Mêmes règles que sur le site (CompetitionEditor) : rien ne change après les premiers votes d'un défi ou d'un duel. */
    public function update(Request $request, Competition $competition): JsonResponse
    {
        $editor = app(\App\Services\Competition\CompetitionEditor::class);
        $input = $request->only(['title', 'text', 'category', 'rules', 'prize']);
        if (! $request->has('text') && $request->has('description')) {
            $input['text'] = $request->input('description');
        }
        $editor->update($request->user(), $competition, $input);

        return response()->json(['success' => true, 'data' => Presenter::competition($competition->fresh(), $request->user(), true)
            + ['edit' => $editor->rules($competition->fresh(), $request->user())]]);
    }

    public function destroy(Request $request, Competition $competition): JsonResponse
    {
        $this->service->cancel($request->user(), $competition, $request->input('reason'));

        return response()->json(['success' => true, 'message' => 'Compétition annulée.']);
    }

    public function start(Request $request, Competition $competition): JsonResponse
    {
        $c = $this->service->start($request->user(), $competition);

        return response()->json(['success' => true, 'message' => __('C\'est parti !'), 'data' => Presenter::competition($c->fresh(), $request->user(), true)]);
    }

    // ── Participations ─────────────────────────────────────────

    public function entries(Request $request, Competition $competition): JsonResponse
    {
        $viewer = $this->viewer($request);
        $q = $competition->entries()->with(['participant', 'submitter']);

        $status = $request->query('status');
        if ($status === 'pending' && $competition->canManage($viewer)) {
            $q->where('status', 'pending');
        } else {
            $q->whereIn('status', ['active', 'winner', 'eliminated']);
        }

        $sort = $request->query('sort', $competition->type === Competition::TYPE_CONTEST ? 'ranking' : 'recent');
        if ($sort === 'ranking') {
            $competition->status === Competition::STATUS_COMPLETED
                ? $q->orderBy('rank')
                : ($competition->blind_votes ? $q->inRandomOrder() : $q->orderByDesc('score')->orderByDesc('votes_count'));
        } else {
            $q->latest();
        }

        $page = $q->paginate(min(50, (int) $request->integer('per_page', 20)));

        return response()->json([
            'success' => true,
            'data' => Presenter::withDuelLinks(collect($page->items())->map(fn ($e) => Presenter::entry($e, $competition, $viewer)), $competition),
            'has_more' => $page->hasMorePages(),
        ]);
    }

    public function enter(Request $request, Competition $competition): JsonResponse
    {
        $entry = $this->service->submitEntry($request->user(), $competition, $request->all());

        return response()->json([
            'success' => true,
            'message' => $entry->status === 'pending' ? 'Candidature envoyée, en attente de validation.' : 'Tu participes !',
            'data' => Presenter::entry($entry, $competition->fresh()),
            'competition' => Presenter::competition($competition->fresh(), $request->user(), true),
        ], 201);
    }

    public function addCandidate(Request $request, Competition $competition): JsonResponse
    {
        $entry = $this->service->addCandidate($request->user(), $competition, $request->all());

        return response()->json(['success' => true, 'data' => Presenter::entry($entry, $competition)], 201);
    }

    public function withdraw(Request $request, Competition $competition, CompetitionEntry $entry): JsonResponse
    {
        abort_unless((int) $entry->competition_id === (int) $competition->id, 404);
        $this->service->withdrawEntry($request->user(), $entry);

        return response()->json(['success' => true, 'message' => 'Participation retirée.']);
    }

    public function approve(Request $request, Competition $competition, CompetitionEntry $entry): JsonResponse
    {
        abort_unless((int) $entry->competition_id === (int) $competition->id, 404);

        return response()->json(['success' => true, 'data' => Presenter::entry($this->service->approveEntry($request->user(), $entry), $competition)]);
    }

    public function reject(Request $request, Competition $competition, CompetitionEntry $entry): JsonResponse
    {
        abort_unless((int) $entry->competition_id === (int) $competition->id, 404);
        $entry = $this->service->rejectEntry($request->user(), $entry, $request->input('reason'));

        return response()->json(['success' => true, 'data' => Presenter::entry($entry, $competition)]);
    }

    /** Vote dans un concours (pour un candidat). */
    public function voteEntry(Request $request, Competition $competition, CompetitionEntry $entry): JsonResponse
    {
        abort_unless((int) $entry->competition_id === (int) $competition->id, 404);
        $action = $this->votes->voteContestEntry($request->user(), $competition, $entry, $request->ip(), max(1, (int) $request->input('quantity', 1)));

        return response()->json([
            'success' => true,
            'action' => $action,
            'data' => Presenter::entry($entry->fresh(), $competition->fresh(), $request->user()),
            'votes_left' => Presenter::competitionViewer($competition->fresh(), $request->user())['votes_left'],
        ]);
    }

    public function juryScore(Request $request, Competition $competition, CompetitionEntry $entry): JsonResponse
    {
        abort_unless((int) $entry->competition_id === (int) $competition->id, 404);
        $data = $request->validate(['score' => 'required|integer|min:0|max:10', 'comment' => 'nullable|string|max:500']);
        $this->votes->juryScore($request->user(), $competition, $entry, $data['score'], $data['comment'] ?? null);

        return response()->json(['success' => true, 'message' => 'Note enregistrée.']);
    }

    // ── Duels, tableau, classement ──────────────────────────────

    public function matches(Request $request, Competition $competition): JsonResponse
    {
        $viewer = $this->viewer($request);
        $q = $competition->matches()->with(['entryA.participant', 'entryB.participant'])->where('is_bye', false);
        if ($round = $request->integer('round')) {
            $q->where('round', $round);
        }
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        $q->orderByRaw("CASE WHEN status = 'voting' THEN 0 ELSE 1 END")->orderByDesc('round')->orderByDesc('id');
        $page = $q->paginate(min(50, (int) $request->integer('per_page', 20)));

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn ($m) => Presenter::match($m, $viewer, false, $competition))->values(),
            'has_more' => $page->hasMorePages(),
        ]);
    }

    public function bracket(Request $request, Competition $competition): JsonResponse
    {
        if ($competition->type !== Competition::TYPE_TOURNAMENT) {
            throw new CompetitionException('Seuls les tournois ont un tableau.');
        }
        $viewer = $this->viewer($request);
        $rounds = collect($this->tournaments->bracket($competition))->map(fn ($r) => [
            'round' => $r['round'],
            'name' => $r['name'],
            'matches' => collect($r['matches'])->map(fn ($m) => $m ? Presenter::match($m, $viewer, false, $competition) : null)->values(),
        ]);

        return response()->json(['success' => true, 'data' => ['rounds' => $rounds, 'current_round' => $competition->current_round]]);
    }

    // ── Invitations, jury, propositions ─────────────────────────

    public function invite(Request $request, Competition $competition): JsonResponse
    {
        $data = $request->validate([
            'invitees' => 'required|array|min:1|max:100',
            'invitees.*.type' => 'required|in:user,space',
            'invitees.*.handle' => 'required|string|max:100',
            'message' => 'nullable|string|max:280',
        ]);
        $count = $this->service->invite($request->user(), $competition, $data['invitees'], $data['message'] ?? null);

        return response()->json(['success' => true, 'message' => $count.' invitation(s) envoyée(s).', 'sent' => $count]);
    }

    public function setJurors(Request $request, Competition $competition): JsonResponse
    {
        if (! $competition->canManage($request->user())) {
            throw new CompetitionException('Seul l\'organisateur gère le jury.', 403);
        }
        if (! $competition->isOrganizedBySpace()) {
            throw new CompetitionException('Le jury est réservé aux compétitions d\'un Espace.');
        }
        $data = $request->validate(['usernames' => 'present|array|max:15', 'usernames.*' => 'string|exists:users,username']);
        $competition->jurors()->sync(\App\Models\User::whereIn('username', $data['usernames'])->pluck('id'));

        return response()->json(['success' => true, 'data' => $competition->jurors()->get()->map(fn ($u) => Participant::present($u))]);
    }

    public function proposals(Request $request, Competition $competition): JsonResponse
    {
        $user = $request->user();
        $spaceIds = $request->query('space')
            ? Space::where('slug', $request->query('space'))->pluck('id')->all()
            : $user->allSpaceIds();

        $list = EntryProposal::with(['user', 'space'])
            ->where('competition_id', $competition->id)
            ->whereIn('space_id', $spaceIds ?: [0])
            ->latest()->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'space' => Participant::present($p->space),
                'user' => Participant::present($p->user),
                'content' => $p->content,
                'media' => $p->mediaUrls(),
                'status' => $p->status,
                'can_select' => $p->space->canManage($user) && $p->status === 'pending',
                'created_at' => Presenter::date($p->created_at),
            ]);

        return response()->json(['success' => true, 'data' => $list]);
    }

    public function propose(Request $request, Competition $competition): JsonResponse
    {
        $space = Space::where('slug', $request->input('space'))->firstOrFail();
        $proposal = $this->service->propose($request->user(), $competition, $space, $request->all());

        return response()->json(['success' => true, 'message' => 'Proposition envoyée aux gestionnaires de '.$space->name.'.', 'data' => ['id' => $proposal->id]], 201);
    }

    public function selectProposal(Request $request, Competition $competition, EntryProposal $proposal): JsonResponse
    {
        abort_unless((int) $proposal->competition_id === (int) $competition->id, 404);
        $entry = $this->service->selectProposal($request->user(), $proposal);

        return response()->json(['success' => true, 'message' => 'Participation de l\'Espace envoyée !', 'data' => Presenter::entry($entry, $competition->fresh())]);
    }
}
