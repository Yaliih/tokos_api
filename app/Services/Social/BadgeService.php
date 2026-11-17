<?php

namespace App\Services\Social;

use App\Models\Badge;
use App\Models\CompetitionVote;
use App\Support\Participant;
use Illuminate\Database\Eloquent\Model;

/**
 * Attribution des badges (définitions dans config/badges.php).
 * Chaque badge n'est donné qu'une fois ; il génère une activité et une notification.
 */
class BadgeService
{
    public function __construct(
        private ActivityService $activities,
        private NotificationService $notifications,
    ) {
    }

    public function award(Model $owner, string $code, array $data = []): ?Badge
    {
        if (! config('badges.'.$code)) {
            return null;
        }

        $badge = Badge::firstOrNew([
            'owner_type' => $owner::class,
            'owner_id' => $owner->getKey(),
            'code' => $code,
        ]);
        if ($badge->exists) {
            return null;
        }
        $badge->data = $data ?: null;
        $badge->awarded_at = now();
        $badge->save();

        $def = config('badges.'.$code);
        $this->activities->record($owner, 'badge.earned', $badge, null, [
            'code' => $code,
            'name' => $def['name'],
            'emoji' => $def['emoji'],
        ], 2);

        $this->notifications->send(
            NotificationService::recipientsFor($owner),
            'badge.earned',
            'Nouveau badge '.$def['emoji'],
            ($owner instanceof \App\Models\Space ? Participant::name($owner).' a' : 'Tu as').' obtenu le badge « '.$def['name'].' ». '.$def['description'],
            Participant::present($owner)['url'] ?? null,
            $owner,
        );

        return $badge;
    }

    /** Après un match terminé, pour un participant. */
    public function afterMatch(Model $participant, bool $won, array $info, int $totalVotes): void
    {
        if (! $won) {
            return;
        }
        $wins = (int) ($info['wins'] ?? 0);
        $streak = (int) ($info['streak'] ?? 0);

        if ($wins >= 1) {
            $this->award($participant, 'first_win');
        }
        if ($wins >= 10) {
            $this->award($participant, 'wins_10');
        }
        if ($wins >= 50) {
            $this->award($participant, 'wins_50');
        }
        if ($streak >= 3) {
            $this->award($participant, 'streak_3');
        }
        if ($streak >= 10) {
            $this->award($participant, 'streak_10');
        }
        if (($info['opponent_gap'] ?? 0) >= 200) {
            $this->award($participant, 'giant_slayer', ['gap' => $info['opponent_gap']]);
        }
        if ($totalVotes >= 100) {
            $this->award($participant, 'crowd_favorite', ['votes' => $totalVotes]);
        }
        $rank = $info['rank_after'] ?? null;
        if ($rank !== null && $rank <= 10) {
            $this->award($participant, 'top_10', ['rank' => $rank]);
        }
    }

    public function afterVote(Model $user): void
    {
        if (CompetitionVote::where('user_id', $user->getKey())->count() === 100) {
            $this->award($user, 'voter_100');
        }
    }
}
