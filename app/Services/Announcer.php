<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\User;
use App\Services\Social\NotificationService;

/**
 * Envoie les annonces de l'admin : dans la cloche et en push (téléphone et navigateur),
 * à tous les inscrits ou à une sélection. Les annonces mensuelles repartent chaque mois.
 */
class Announcer
{
    /** Navigateurs atteints au dernier envoi « à tout le monde » (avec ou sans compte). */
    public int $devices = 0;

    public function __construct(private NotificationService $notifications)
    {
    }

    /** Envoie une annonce ; renvoie le nombre de personnes touchées. $onlyTo : un test (ex. l'admin seul). */
    public function send(Announcement $a, ?array $onlyTo = null): int
    {
        $query = User::query()->where('is_active', true);
        if ($onlyTo !== null) {
            $query->whereIn('id', $onlyTo);
        } elseif ($a->audience === 'selected') {
            $query->whereIn('id', (array) ($a->user_ids ?? []));
        } elseif ($a->audience === 'segment') {
            // Groupe recalculé à chaque envoi (nouveaux inscrits, plus actifs, venus une fois…)
            $seg = (array) ($a->segment ?? []);
            $query->whereIn('id', \App\Support\Audiences::ids((string) ($seg['key'] ?? ''), (int) ($seg['n'] ?? 0), $seg['city'] ?? null) ?: [0]);
        }
        // À tout le monde : la cloche des inscrits sans push par personne, puis un seul push par appareil,
        // y compris les téléphones et navigateurs sans compte (sujet « all » de l'app, abonnements du navigateur)
        $everyone = $onlyTo === null && $a->audience === 'all';
        $count = 0;
        $query->select('id')->orderBy('id')->chunkById(500, function ($users) use ($a, &$count, $everyone) {
            $ids = $users->pluck('id')->all();
            $this->notifications->send($ids, 'announcement', $a->title, $a->body, $a->url ?: '/', null, $everyone ? ['_no_push' => true] : []);
            $count += count($ids);
        });
        if ($everyone && \App\Support\NotifyRules::allows('announcement', 'push') && \App\Support\Features::on('push')) {
            \App\Notifications\Channels\FcmChannel::toTopic('all', $a->title, $a->body, ['type' => 'announcement', 'url' => $a->url ?: '/']);
            $this->devices = app(\App\Services\Push\WebPush::class)->toEveryone([
                'title' => $a->title, 'body' => $a->body, 'url' => url($a->url ?: '/'), 'tag' => 'announcement:'.$a->id,
            ]);
        }
        if ($onlyTo === null) {
            $a->forceFill([
                'last_sent_at' => now(), 'sent_count' => $a->sent_count + 1, 'last_recipients' => $count,
                // Mensuelle : même jour et même heure, le mois prochain ; sinon c'est fini
                'next_send_at' => $a->repeat === 'monthly' ? ($a->next_send_at ?? now())->copy()->addMonthNoOverflow() : null,
                'is_active' => $a->repeat === 'monthly' ? $a->is_active : false,
            ])->save();
        }

        return $count;
    }

    /** Planifiée : envoie celles dont l'heure est venue. */
    public function sendDue(): int
    {
        $n = 0;
        Announcement::where('is_active', true)->whereNotNull('next_send_at')->where('next_send_at', '<=', now())
            ->orderBy('next_send_at')->limit(20)->get()
            ->each(function (Announcement $a) use (&$n) {
                $this->send($a);
                $n++;
            });

        return $n;
    }
}
