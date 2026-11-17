<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Competition;
use App\Models\CompetitionEntry;
use App\Models\CompetitionMatch;
use App\Models\DeviceToken;
use App\Models\Rating;
use App\Models\Space;
use App\Models\User;
use App\Services\Social\ActivityService;
use App\Services\Social\RatingService;
use App\Support\Participant;
use App\Support\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tout ce qui fait vivre le réseau : fil d'activité, notifications,
 * classements, découverte, statistiques des profils et Espaces.
 */
class SocialController extends Controller
{
    public function __construct(private ActivityService $activities, private RatingService $ratings)
    {
    }

    // ── Fil d'activité ──────────────────────────────────────────

    public function feed(Request $request): JsonResponse
    {
        $viewer = $this->viewer($request);
        $res = $this->activities->feedFor($viewer, $request->query('cursor'), (int) $request->integer('limit', 30));

        return response()->json([
            'success' => true,
            'data' => Presenter::activities($res['items'], $viewer),
            'next_cursor' => $res['next_cursor'],
        ]);
    }

    public function userActivity(Request $request, string $username): JsonResponse
    {
        $user = User::where('username', $username)->firstOrFail();
        $res = $this->activities->forActor($user, $request->integer('cursor') ?: null);

        return response()->json(['success' => true, 'data' => Presenter::activities($res['items'], $this->viewer($request)), 'next_cursor' => $res['next_cursor']]);
    }

    public function spaceActivity(Request $request, string $slug): JsonResponse
    {
        $space = Space::where('slug', $slug)->firstOrFail();
        $res = $this->activities->forActor($space, $request->integer('cursor') ?: null);

        return response()->json(['success' => true, 'data' => Presenter::activities($res['items'], $this->viewer($request)), 'next_cursor' => $res['next_cursor']]);
    }

    // ── Notifications ───────────────────────────────────────────

    public function notifications(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = $user->notifications();
        if ($request->boolean('unread')) {
            $q = $user->unreadNotifications();
        }
        $page = $q->paginate(min(50, (int) $request->integer('per_page', 30)));

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn ($n) => Presenter::notification($n))->values(),
            'unread_count' => $user->unreadNotifications()->count(),
            'has_more' => $page->hasMorePages(),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->forceFill(['last_seen_at' => now()])->saveQuietly();

        return response()->json(['success' => true, 'data' => [
            'notifications' => $user->unreadNotifications()->count(),
            'invitations' => \App\Models\CompetitionInvitation::where('status', 'pending')
                ->where(function ($q) use ($user) {
                    $q->where(fn ($x) => $x->where('invitee_type', User::class)->where('invitee_id', $user->id))
                        ->orWhere(fn ($x) => $x->where('invitee_type', Space::class)->whereIn('invitee_id', $user->manageableSpaceIds() ?: [0]));
                })->count(),
        ]]);
    }

    public function markRead(Request $request): JsonResponse
    {
        $ids = (array) $request->input('ids', []);
        $q = $request->user()->unreadNotifications();
        if ($ids) {
            $q->whereIn('id', $ids);
        }
        $q->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function notificationSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['muted' => 'present|array', 'muted.*' => 'string|max:40']);
        $user = $request->user();
        $user->forceFill(['notification_settings' => array_merge($user->notification_settings ?? [], ['muted' => array_values($data['muted'])])])->save();

        return response()->json(['success' => true, 'data' => $user->notification_settings]);
    }

    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => 'required|string|max:512', 'platform' => 'nullable|in:android,ios,web']);
        DeviceToken::updateOrCreate(['token' => $data['token']], [
            'user_id' => $request->user()->id,
            'platform' => $data['platform'] ?? 'android',
            'last_used_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    /** Déconnexion de l'app : ce téléphone ne reçoit plus les push de ce compte. */
    public function unregisterDevice(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => 'required|string|max:512']);
        DeviceToken::where('token', $data['token'])->where('user_id', $request->user()->id)->delete();

        return response()->json(['success' => true]);
    }

    // ── Classements ─────────────────────────────────────────────

    public function leaderboard(Request $request): JsonResponse
    {
        $viewer = $this->viewer($request);
        $kind = $request->query('kind', 'user') === 'space' ? 'space' : 'user';
        $category = $request->query('category', 'all');
        $season = $request->query('season', 'current');
        $season = $season === 'current' ? Rating::currentSeason() : $season;
        $sort = $request->query('sort', 'rating');

        $rows = $this->ratings->leaderboard($kind, $category, $season, $sort, 100)
            ->values()
            ->map(fn (Rating $r, $i) => [
                'rank' => $i + 1,
                'participant' => Participant::present($r->rateable),
                'rating' => $r->rating,
                'points' => $r->points,
                'wins' => $r->wins,
                'losses' => $r->losses,
                'draws' => $r->draws,
                'streak' => $r->current_streak,
                'trophies' => $r->trophies,
            ]);

        $me = null;
        if ($viewer && $kind === 'user') {
            $mine = Rating::where(['rateable_type' => User::class, 'rateable_id' => $viewer->id, 'category' => $category, 'season' => $season])->first();
            $me = $mine ? ['rank' => $this->ratings->rankOf($viewer, $category, $season, $sort), 'rating' => $mine->rating, 'points' => $mine->points, 'wins' => $mine->wins, 'losses' => $mine->losses] : null;
        }

        return response()->json(['success' => true, 'data' => [
            'kind' => $kind,
            'category' => $category,
            'season' => $season,
            'season_label' => Rating::seasonLabel($season),
            'rows' => $rows,
            'me' => $me,
            'categories' => collect(['all' => 'Toutes catégories'] + config('competition.categories'))->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
        ]]);
    }

    // ── Découverte ──────────────────────────────────────────────

    public function discover(Request $request): JsonResponse
    {
        $viewer = $this->viewer($request);
        $visible = fn ($q) => $q->where('blind_votes', false);

        $hot = CompetitionMatch::with(['competition', 'entryA.participant', 'entryB.participant'])
            ->where('status', 'voting')->where('is_bye', false)->whereHas('competition', $visible)
            ->orderByDesc('hot_score')->limit(8)->get();

        $ending = CompetitionMatch::with(['competition', 'entryA.participant', 'entryB.participant'])
            ->where('status', 'voting')->where('is_bye', false)->where('ends_at', '<=', now()->addHours(6))
            ->orderBy('ends_at')->limit(6)->get();

        $openChallenges = Competition::visible()->where('type', Competition::TYPE_CHALLENGE)->acceptingEntries()
            ->orderByDesc('hot_score')->latest()->limit(8)->get();

        $registrations = Competition::visible()->whereIn('type', [Competition::TYPE_CONTEST, Competition::TYPE_TOURNAMENT])
            ->acceptingEntries()->latest()->limit(6)->get();

        $spaceBattles = Competition::visible()->whereIn('participation_mode', ['space', 'mixed'])
            ->whereIn('status', [Competition::STATUS_OPEN, Competition::STATUS_LIVE])->orderByDesc('hot_score')->limit(6)->get();

        $season = Rating::currentSeason();
        $topSpaces = $this->ratings->leaderboard('space', 'all', $season, 'points', 8);
        $risingUsers = $this->ratings->leaderboard('user', 'all', $season, 'points', 8);

        // Suggestions : actifs cette saison, pas encore suivis
        $suggested = collect();
        if ($viewer) {
            $following = $viewer->following()->pluck('users.id')->push($viewer->id);
            $friendsOfFriends = \App\Models\Follow::whereIn('follower_id', $following)->whereNotIn('following_id', $following)
                ->selectRaw('following_id, count(*) as c')->groupBy('following_id')->orderByDesc('c')->limit(10)->pluck('following_id');
            $suggested = User::whereIn('id', $friendsOfFriends)->where('is_active', true)->get()
                ->concat($risingUsers->pluck('rateable')->filter(fn ($u) => $u && ! $following->contains($u->id)))
                ->unique('id')->take(10)->values();
        }

        return response()->json(['success' => true, 'data' => [
            'hot_matches' => $hot->map(fn ($m) => Presenter::match($m, $viewer))->values(),
            'ending_soon' => $ending->map(fn ($m) => Presenter::match($m, $viewer))->values(),
            'open_challenges' => $openChallenges->map(fn ($c) => Presenter::competition($c, $viewer))->values(),
            'open_registrations' => $registrations->map(fn ($c) => Presenter::competition($c, $viewer))->values(),
            'space_battles' => $spaceBattles->map(fn ($c) => Presenter::competition($c, $viewer))->values(),
            'top_spaces' => $topSpaces->map(fn ($r) => Participant::present($r->rateable) + ['points' => $r->points, 'wins' => $r->wins])->values(),
            'rising_users' => $risingUsers->map(fn ($r) => Participant::present($r->rateable) + ['points' => $r->points, 'wins' => $r->wins])->values(),
            'suggested_users' => $suggested->map(fn ($u) => Participant::present($u))->values(),
            'season' => ['value' => $season, 'label' => Rating::seasonLabel($season)],
        ]]);
    }

    // ── Statistiques ────────────────────────────────────────────

    public function userStats(Request $request, string $username): JsonResponse
    {
        $user = User::where('username', $username)->firstOrFail();

        return response()->json(['success' => true, 'data' => $this->stats($user, $this->viewer($request))]);
    }

    public function spaceStats(Request $request, string $slug): JsonResponse
    {
        $space = Space::where('slug', $slug)->firstOrFail();

        return response()->json(['success' => true, 'data' => $this->stats($space, $this->viewer($request))]);
    }

    public function stats($owner, ?User $viewer): array
    {
        $all = Rating::where(['rateable_type' => $owner::class, 'rateable_id' => $owner->getKey(), 'category' => 'all', 'season' => 'all'])->first();
        $season = Rating::where(['rateable_type' => $owner::class, 'rateable_id' => $owner->getKey(), 'category' => 'all', 'season' => Rating::currentSeason()])->first();

        $entryIds = CompetitionEntry::where('participant_type', $owner::class)->where('participant_id', $owner->getKey())->pluck('id');
        $recent = CompetitionMatch::with(['competition', 'entryA.participant', 'entryB.participant'])
            ->where('is_bye', false)
            ->where(fn ($q) => $q->whereIn('entry_a_id', $entryIds)->orWhereIn('entry_b_id', $entryIds))
            ->latest()->limit(10)->get();

        $byCategory = Rating::where(['rateable_type' => $owner::class, 'rateable_id' => $owner->getKey(), 'season' => 'all'])
            ->where('category', '!=', 'all')->where('matches', '>', 0)->orderByDesc('rating')->get()
            ->map(fn ($r) => ['category' => $r->category, 'label' => \App\Support\Categories::label($r->category), 'rating' => $r->rating, 'wins' => $r->wins, 'losses' => $r->losses]);

        return [
            'participant' => Participant::present($owner),
            'rating' => $all?->rating ?? 1000,
            'peak_rating' => $all?->peak_rating ?? 1000,
            'rank' => $this->ratings->rankOf($owner),
            'matches' => $all?->matches ?? 0,
            'wins' => $all?->wins ?? 0,
            'losses' => $all?->losses ?? 0,
            'draws' => $all?->draws ?? 0,
            'win_rate' => ($all?->matches ?? 0) > 0 ? round($all->wins / $all->matches * 100) : null,
            'current_streak' => $all?->current_streak ?? 0,
            'best_streak' => $all?->best_streak ?? 0,
            'trophies' => $all?->trophies ?? 0,
            'season' => ['label' => Rating::seasonLabel(Rating::currentSeason()), 'points' => $season?->points ?? 0, 'rating' => $season?->rating ?? 1000],
            'categories' => $byCategory,
            'badges' => $owner->badges()->orderByDesc('awarded_at')->get()->map(fn ($b) => Presenter::badge($b)),
            'organized_count' => Competition::where('organizer_type', $owner::class)->where('organizer_id', $owner->getKey())->count(),
            'recent_matches' => $recent->map(fn ($m) => Presenter::match($m, $viewer))->values(),
            // Compétitions organisées ou participées (le chiffre du profil ; la page « Compétitions » donne le détail)
            'competitions_count' => \App\Support\Participations::count($owner),
            'active_competitions' => Competition::visible()
                ->whereIn('status', [Competition::STATUS_OPEN, Competition::STATUS_LIVE])
                ->where(fn ($q) => $q->where(fn ($x) => $x->where('organizer_type', $owner::class)->where('organizer_id', $owner->getKey()))
                    ->orWhereHas('entries', fn ($e) => $e->where('participant_type', $owner::class)->where('participant_id', $owner->getKey())))
                ->latest()->limit(10)->get()->map(fn ($c) => Presenter::competition($c, $viewer))->values(),
        ];
    }
}
