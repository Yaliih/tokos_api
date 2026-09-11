<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Space;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SpaceStatisticsController extends Controller
{
    public function show(Request $request, string $slug): JsonResponse
    {
        $space = Space::where('slug', $slug)->firstOrFail();
        abort_unless($request->user() && $space->canManage($request->user()), 403, 'Vous ne pouvez pas consulter ces statistiques.');

        $postIds = DB::table('posts')->where('authorable_type', Space::class)->where('authorable_id', $space->id)
            ->whereNull('deleted_at')->pluck('id');

        $followers = DB::table('space_managers')->where('space_id', $space->id)->where('role', 'viewer')->count();
        $members = DB::table('space_members')->where('space_id', $space->id)->count();
        $posts = $postIds->count();
        $likes = DB::table('post_likes')->whereIn('post_id', $postIds)->count();
        $comments = DB::table('post_comments')->whereIn('post_id', $postIds)->whereNull('deleted_at')->count();
        $commentLikes = DB::table('comment_likes')->join('post_comments', 'post_comments.id', '=', 'comment_likes.comment_id')->whereIn('post_comments.post_id', $postIds)->count();
        $views = DB::table('post_views')->whereIn('post_id', $postIds)->count();
        $shares = DB::table('post_shares')->whereIn('post_id', $postIds)->count();
        $bookmarks = DB::table('post_bookmarks')->whereIn('post_id', $postIds)->count();
        $postVotes = DB::table('post_votes')->whereIn('post_id', $postIds)->count();
        $candidateVotes = DB::table('candidate_votes')->join('contest_candidates', 'contest_candidates.id', '=', 'candidate_votes.candidate_id')->whereIn('contest_candidates.post_id', $postIds)->sum('candidate_votes.value');
        $applications = DB::table('contest_participants')->whereIn('post_id', $postIds)->count();
        $candidates = DB::table('contest_candidates')->whereIn('post_id', $postIds)->whereNull('deleted_at')->count();

        $interactionTotal = $likes + $comments + $commentLikes + $shares + $bookmarks + $postVotes + $candidateVotes;
        $engagementRate = $views > 0 ? round(($interactionTotal / $views) * 100, 2) : 0;

        $byType = DB::table('posts')->whereIn('id', $postIds)->selectRaw('type, COUNT(*) total')->groupBy('type')->pluck('total', 'type');
        $last30Days = DB::table('posts')->whereIn('id', $postIds)->where('created_at', '>=', now()->subDays(29))
            ->selectRaw('DATE(created_at) day, COUNT(*) posts')->groupBy('day')->orderBy('day')->get();

        return response()->json(['success' => true, 'data' => [
            'audience' => ['followers' => $followers, 'members' => $members, 'total' => $followers + $members],
            'content' => ['posts' => $posts, 'by_type' => $byType],
            'interactions' => compact('likes', 'comments', 'commentLikes', 'views', 'shares', 'bookmarks'),
            'votes' => ['post_votes' => $postVotes, 'candidate_votes' => (int) $candidateVotes, 'total' => $postVotes + (int) $candidateVotes],
            'contests' => ['applications' => $applications, 'candidates' => $candidates],
            'engagement_rate' => $engagementRate,
            'last_30_days' => $last30Days,
        ]]);
    }
}
