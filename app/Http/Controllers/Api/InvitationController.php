<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompetitionInvitation;
use App\Models\Space;
use App\Models\User;
use App\Services\Competition\CompetitionService;
use App\Support\Participant;
use App\Support\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Défis reçus : par moi ou par les Espaces que je gère. */
class InvitationController extends Controller
{
    public function __construct(private CompetitionService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $spaceIds = $user->manageableSpaceIds();

        $list = CompetitionInvitation::with(['competition.organizer', 'invitee', 'inviter'])
            ->where('status', $request->query('status', 'pending'))
            ->where(function ($q) use ($user, $spaceIds) {
                $q->where(fn ($x) => $x->where('invitee_type', User::class)->where('invitee_id', $user->id))
                    ->orWhere(fn ($x) => $x->where('invitee_type', Space::class)->whereIn('invitee_id', $spaceIds ?: [0]));
            })
            ->latest()->limit(100)->get()
            ->filter(fn ($i) => $i->competition)
            ->map(fn ($i) => [
                'id' => $i->id,
                'status' => $i->status,
                'message' => $i->message,
                'invitee' => Participant::present($i->invitee),
                'inviter' => Participant::present($i->inviter),
                'competition' => Presenter::competition($i->competition, $user),
                'created_at' => Presenter::date($i->created_at),
            ])->values();

        return response()->json(['success' => true, 'data' => $list]);
    }

    public function accept(Request $request, CompetitionInvitation $invitation): JsonResponse
    {
        $entry = $this->service->acceptInvitation($request->user(), $invitation, $request->all());

        return response()->json([
            'success' => true,
            'message' => 'Défi accepté, le duel commence !',
            'data' => Presenter::competition($invitation->competition->fresh(), $request->user(), true),
            'entry' => Presenter::entry($entry),
        ]);
    }

    public function decline(Request $request, CompetitionInvitation $invitation): JsonResponse
    {
        $this->service->declineInvitation($request->user(), $invitation);

        return response()->json(['success' => true, 'message' => 'Invitation déclinée.']);
    }
}
