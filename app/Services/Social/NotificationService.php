<?php

namespace App\Services\Social;

use App\Models\User;
use App\Notifications\AppNotification;
use App\Support\Participant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Point d'entrée unique pour notifier des utilisateurs.
 *
 * Types utilisés : challenge.received, challenge.accepted, entry.submitted, entry.approved,
 * entry.rejected, match.started, match.ending_soon, match.won, match.lost, match.draw,
 * competition.completed, competition.started, tournament.next_round, tournament.eliminated,
 * follower.new, space.joined, badge.earned, rematch.requested, proposal.submitted,
 * comment.new, invitation.declined
 */
class NotificationService
{
    public const ICONS = [
        'verification.approved' => '',
        'verification.rejected' => '',
        'challenge.received' => '',
        'challenge.accepted' => '',
        'entry.submitted' => '',
        'entry.approved' => '',
        'entry.rejected' => '',
        'match.started' => '',
        'match.ending_soon' => '',
        'match.lead' => '',
        'gift.received' => '',
        'match.won' => '',
        'match.lost' => '',
        'match.draw' => '',
        'competition.completed' => '',
        'competition.started' => '',
        'tournament.next_round' => '',
        'tournament.eliminated' => '',
        'follower.new' => '',
        'space.joined' => '',
        'badge.earned' => '',
        'rematch.requested' => '',
        'proposal.submitted' => '',
        'comment.new' => '',
        'invitation.declined' => '',
    ];

    /**
     * Destinataires qui représentent un acteur : l'utilisateur lui-même,
     * ou le propriétaire et les gestionnaires d'un Espace.
     */
    public static function recipientsFor(?Model $actor): array
    {
        if ($actor instanceof User) {
            return [$actor->id];
        }
        if ($actor instanceof \App\Models\Space) {
            return collect([(int) $actor->owner_id])
                ->merge($actor->managers()->wherePivotIn('role', ['admin', 'editor'])->pluck('users.id'))
                ->map(fn ($id) => (int) $id)->unique()->values()->all();
        }

        return [];
    }

    /** Au-delà de ce nombre de destinataires, tout l'envoi passe par la file d'attente. */
    public const QUEUE_ABOVE = 50;

    /**
     * La cloche tout de suite (petits envois), le push sur le téléphone et le navigateur dans la file d'attente.
     *
     * @param  iterable|int|User  $recipients  ids ou utilisateurs
     */
    public function send($recipients, string $type, string $title, string $body, ?string $url = null, ?Model $actor = null, array $extra = [], ?int $exceptUserId = null): void
    {
        $ids = self::ids($recipients, $exceptUserId);
        if (! $ids) {
            return;
        }
        // Beaucoup de monde : on ne fait pas attendre la personne qui agit
        if (count($ids) > self::QUEUE_ABOVE) {
            foreach (array_chunk($ids, 500) as $chunk) {
                \App\Jobs\SendNotifications::dispatch($chunk, $type, $title, $body, $url, $actor ? $actor::class : null, $actor?->getKey(), $extra, null);
            }

            return;
        }
        $this->sendNow($ids, $type, $title, $body, $url, $actor, $extra);
    }

    /** L'envoi lui-même (appelé directement, ou par la file d'attente). */
    public function sendNow(array $recipients, string $type, string $title, string $body, ?string $url = null, ?Model $actor = null, array $extra = [], ?int $exceptUserId = null): void
    {
        $ids = self::ids($recipients, $exceptUserId);
        if (! $ids) {
            return;
        }
        // Réglages de l'admin (Réglages → Notifications) : dans la cloche, et/ou en push
        $inApp = \App\Support\NotifyRules::allows($type, 'app');
        $push = \App\Support\NotifyRules::allows($type, 'push') && \App\Support\Features::on('push') && empty($extra['_no_push']);
        unset($extra['_no_push']);
        if (! $inApp && ! $push) {
            return;
        }

        $payload = array_merge([
            'type' => $type,
            'icon' => self::ICONS[$type] ?? '🔔',
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'actor' => Participant::present($actor),
        ], $extra);

        $pushIds = [];
        User::whereIn('id', $ids)->get()->each(function (User $user) use ($payload, $type, $inApp, &$pushIds) {
            $muted = $user->notification_settings['muted'] ?? [];
            if (in_array($type, (array) $muted, true)) {
                return;
            }
            if ($inApp) {
                try {
                    $user->notify(new AppNotification($payload + ['_in_app' => true, '_push' => false]));
                } catch (\Throwable $e) {
                    Log::warning('Notification failed: '.$e->getMessage());
                }
            }
            $pushIds[] = $user->id;
        });

        // Le push (Firebase et navigateur) : un appel réseau par appareil, donc dans la file d'attente
        if ($push && $pushIds) {
            \App\Jobs\SendPush::dispatch($pushIds, $payload, [
                'title' => $title,
                'body' => $body,
                'url' => $url ? url($url) : url('/notifications'),
                'tag' => $type.':'.md5((string) $url),
                'icon' => $payload['actor']['avatar_url'] ?? null,
            ]);
        }
    }

    /** @return array<int, int> */
    private static function ids($recipients, ?int $exceptUserId): array
    {
        return collect(is_iterable($recipients) ? $recipients : [$recipients])
            ->map(fn ($r) => $r instanceof User ? $r->id : (int) $r)
            ->filter()
            ->unique()
            ->reject(fn ($id) => $exceptUserId && $id === $exceptUserId)
            ->values()->all();
    }
}
