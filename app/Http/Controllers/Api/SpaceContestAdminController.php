<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\Space;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SpaceContestAdminController extends Controller
{
    private function spaceOrFail(string $slug): Space
    {
        return Space::where('slug', $slug)->firstOrFail();
    }

    private function authorizeManager(Space $space, Request $request): void
    {
        abort_unless($request->user() && $space->canManage($request->user()), 403, 'Vous ne pouvez pas gérer cet espace.');
    }

    public function index(Request $request, string $slug): JsonResponse
    {
        $space = $this->spaceOrFail($slug);
        $this->authorizeManager($space, $request);

        $posts = DB::table('posts')
            ->join('post_contests', 'post_contests.post_id', '=', 'posts.id')
            ->where('posts.authorable_type', Space::class)
            ->where('posts.authorable_id', $space->id)
            ->whereNull('posts.deleted_at')
            ->select([
                'post_contests.id', 'post_contests.post_id', 'post_contests.title',
                'post_contests.description', 'post_contests.prize', 'post_contests.category',
                'post_contests.mode', 'post_contests.has_jury', 'post_contests.jury_percentage',
                'post_contests.vote_start_at', 'post_contests.vote_end_at',
                'post_contests.submission_start', 'post_contests.submission_end',
                'posts.status', 'posts.created_at',
            ])
            ->orderByDesc('posts.created_at')
            ->get();

        $postIds = $posts->pluck('post_id');
        $candidateCounts = DB::table('contest_candidates')->whereIn('post_id', $postIds)
            ->whereNull('deleted_at')->selectRaw('post_id, COUNT(*) total')->groupBy('post_id')->pluck('total', 'post_id');
        $applicationCounts = DB::table('contest_participants')->whereIn('post_id', $postIds)
            ->selectRaw("post_id, COUNT(*) total, SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) pending")
            ->groupBy('post_id')->get()->keyBy('post_id');
        $voteCounts = DB::table('candidate_votes')->join('contest_candidates', 'contest_candidates.id', '=', 'candidate_votes.candidate_id')
            ->whereIn('contest_candidates.post_id', $postIds)
            ->selectRaw('contest_candidates.post_id, SUM(candidate_votes.value) total')
            ->groupBy('contest_candidates.post_id')->pluck('total', 'contest_candidates.post_id');

        $contests = $posts->map(function ($contest) use ($candidateCounts, $applicationCounts, $voteCounts) {
            $contest->candidates_count = (int) ($candidateCounts[$contest->post_id] ?? 0);
            $contest->applications_count = (int) ($applicationCounts[$contest->post_id]->total ?? 0);
            $contest->pending_applications_count = (int) ($applicationCounts[$contest->post_id]->pending ?? 0);
            $contest->votes_count = (int) ($voteCounts[$contest->post_id] ?? 0);
            $contest->can_edit = !$contest->vote_start_at || now()->lt($contest->vote_start_at);
            $contest->can_delete = $contest->votes_count === 0 && $contest->candidates_count === 0 && $contest->applications_count === 0;
            $contest->can_cancel = !$contest->can_delete && $contest->status !== 'cancelled';
            return $contest;
        });

        return response()->json(['success' => true, 'data' => $contests]);
    }

    public function show(Request $request, string $slug, int $contestId): JsonResponse
    {
        $space = $this->spaceOrFail($slug);
        $this->authorizeManager($space, $request);
        $contest = $this->contestQuery($space)->where('post_contests.id', $contestId)->firstOrFail();
        return response()->json(['success' => true, 'data' => $contest]);
    }

    public function update(Request $request, string $slug, int $contestId): JsonResponse
    {
        $space = $this->spaceOrFail($slug);
        $this->authorizeManager($space, $request);
        $contest = $this->contestQuery($space)->where('post_contests.id', $contestId)->firstOrFail();

        if ($contest->vote_start_at && now()->gte($contest->vote_start_at)) {
            return response()->json(['message' => 'Le concours ne peut plus être modifié après le début des votes.'], 422);
        }

        $data = $request->validate([
            'title' => 'sometimes|required|string|max:150',
            'description' => 'nullable|string|max:3000',
            'prize' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:80',
            'has_jury' => 'sometimes|boolean',
            'jury_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
            'vote_start_at' => 'nullable|date',
            'vote_end_at' => 'nullable|date|after:vote_start_at',
            'submission_start' => 'nullable|date',
            'submission_end' => 'nullable|date|after:submission_start',
            'status' => ['sometimes', Rule::in(['draft', 'published', 'closed', 'cancelled'])],
        ]);

        if (($data['has_jury'] ?? $contest->has_jury) && !isset($data['jury_percentage']) && !$contest->jury_percentage) {
            return response()->json(['message' => 'Indiquez le pourcentage réservé au jury.'], 422);
        }
        if (array_key_exists('has_jury', $data) && !$data['has_jury']) {
            $data['jury_percentage'] = 0;
        }

        $postData = [];
        if (isset($data['status'])) $postData['status'] = $data['status'];
        unset($data['status']);

        DB::transaction(function () use ($contest, $data, $postData) {
            DB::table('post_contests')->where('id', $contest->id)->update(array_merge($data, ['updated_at' => now()]));
            if ($postData) DB::table('posts')->where('id', $contest->post_id)->update(array_merge($postData, ['updated_at' => now()]));
        });

        return response()->json(['success' => true, 'message' => 'Concours modifié.']);
    }

    public function destroy(Request $request, string $slug, int $contestId): JsonResponse
    {
        $space = $this->spaceOrFail($slug);
        $this->authorizeManager($space, $request);
        $contest = $this->contestQuery($space)->where('post_contests.id', $contestId)->firstOrFail();

        $hasCandidates = DB::table('contest_candidates')->where('post_id', $contest->post_id)->whereNull('deleted_at')->exists();
        $hasApplications = DB::table('contest_participants')->where('post_id', $contest->post_id)->exists();
        $hasVotes = DB::table('candidate_votes')->join('contest_candidates', 'contest_candidates.id', '=', 'candidate_votes.candidate_id')
            ->where('contest_candidates.post_id', $contest->post_id)->exists();

        if ($hasCandidates || $hasApplications || $hasVotes) {
            return response()->json(['message' => 'Ce concours possède déjà des candidatures, candidats ou votes. Annulez-le au lieu de le supprimer.'], 422);
        }

        Post::whereKey($contest->post_id)->delete();
        return response()->json(['success' => true, 'message' => 'Concours supprimé.']);
    }

    public function cancel(Request $request, string $slug, int $contestId): JsonResponse
    {
        $space = $this->spaceOrFail($slug);
        $this->authorizeManager($space, $request);
        $contest = $this->contestQuery($space)->where('post_contests.id', $contestId)->firstOrFail();
        $data = $request->validate(['reason' => 'required|string|min:5|max:1000']);

        DB::table('posts')->where('id', $contest->post_id)->update(['status' => 'cancelled', 'updated_at' => now()]);
        DB::table('contest_participants')->where('post_id', $contest->post_id)->where('status', 'pending')->update([
            'status' => 'rejected', 'decision_reason' => $data['reason'], 'reviewed_at' => now(), 'reviewed_by' => $request->user()->id,
        ]);
        return response()->json(['success' => true, 'message' => 'Concours annulé.']);
    }

    private function contestQuery(Space $space)
    {
        return DB::table('post_contests')->join('posts', 'posts.id', '=', 'post_contests.post_id')
            ->where('posts.authorable_type', Space::class)->where('posts.authorable_id', $space->id)
            ->whereNull('posts.deleted_at')->select('post_contests.*', 'posts.status');
    }
}
