<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Models\CompetitionMatch;
use App\Models\Reaction;
use App\Services\Competition\CompetitionService;
use App\Services\Competition\VoteService;
use App\Support\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MatchController extends Controller
{
    public function __construct(private VoteService $votes, private CompetitionService $competitions)
    {
    }

    public function show(Request $request, CompetitionMatch $match): JsonResponse
    {
        \App\Support\Views::record($request, $match);

        return response()->json(['success' => true, 'data' => Presenter::match($match, $this->viewer($request), true)]);
    }

    /** Duels en cours les plus chauds (votes récents + écart serré). */
    public function hot(Request $request): JsonResponse
    {
        $viewer = $this->viewer($request);
        $q = CompetitionMatch::query()
            ->with(['competition', 'entryA.participant', 'entryB.participant'])
            ->where('status', 'voting')->where('is_bye', false)
            ->whereHas('competition', fn ($c) => $c->where('blind_votes', false));

        if ($request->query('sort') === 'ending') {
            $q->orderBy('ends_at');
        } else {
            // Score tendance + bonus quand c'est serré
            $q->orderByRaw('hot_score + CASE WHEN (votes_a + votes_b) >= 10 AND ABS(votes_a - votes_b) * 10 < (votes_a + votes_b) THEN 20 ELSE 0 END DESC')
                ->orderByDesc('id');
        }
        if ($category = $request->query('category')) {
            $q->whereHas('competition', fn ($c) => $c->where('category', $category));
        }

        $page = $q->paginate(min(30, (int) $request->integer('per_page', 10)));

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn ($m) => Presenter::match($m, $viewer))->values(),
            'has_more' => $page->hasMorePages(),
        ]);
    }

    public function vote(Request $request, CompetitionMatch $match): JsonResponse
    {
        $data = $request->validate(['entry_id' => 'required|integer']);
        $action = $this->votes->voteMatch($request->user(), $match, (int) $data['entry_id'], $request->ip(), max(1, (int) $request->input('quantity', 1)));

        return response()->json([
            'success' => true,
            'action' => $action,
            'message' => match ($action) {
                'removed' => 'Vote retiré.',
                'switched' => 'Vote changé.',
                default => 'Vote enregistré',
            },
            'data' => Presenter::match($match->fresh(), $request->user(), true),
        ]);
    }

    public function react(Request $request, CompetitionMatch $match): JsonResponse
    {
        $data = $request->validate(['emoji' => 'required|string|in:'.implode(',', Reaction::EMOJIS)]);
        $existing = Reaction::where(['reactable_type' => CompetitionMatch::class, 'reactable_id' => $match->id, 'user_id' => $request->user()->id])->first();

        if ($existing && $existing->emoji === $data['emoji']) {
            $existing->delete();
        } else {
            Reaction::updateOrCreate(
                ['reactable_type' => CompetitionMatch::class, 'reactable_id' => $match->id, 'user_id' => $request->user()->id],
                ['emoji' => $data['emoji']]
            );
            $match->increment('hot_score');
        }

        return response()->json(['success' => true, 'data' => Presenter::reactions($match, $request->user())]);
    }

    public function rematch(Request $request, CompetitionMatch $match): JsonResponse
    {
        $duel = $this->competitions->rematch($request->user(), $match);

        return response()->json(['success' => true, 'message' => 'Revanche envoyée !', 'data' => Presenter::competition($duel, $request->user(), true)], 201);
    }
}
