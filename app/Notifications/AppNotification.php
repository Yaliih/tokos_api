<?php

namespace App\Notifications;

use App\Notifications\Channels\FcmChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notification générique de l'app. Le contenu (titre, texte, lien, acteur) est
 * construit par NotificationService pour rester identique sur le web et le mobile.
 */
class AppNotification extends Notification
{
    use Queueable;

    public function __construct(public array $payload)
    {
    }

    public function via(object $notifiable): array
    {
        // Réglages de l'admin : dans la cloche (database) et/ou push sur l'app (FCM)
        $channels = ($this->payload['_in_app'] ?? true) ? ['database'] : [];
        if (($this->payload['_push'] ?? true) && config('services.fcm.project_id') && config('services.fcm.credentials')) {
            $channels[] = FcmChannel::class;
        }

        return $channels;
    }

    public function databaseType(object $notifiable): string
    {
        return $this->payload['type'] ?? 'app';
    }

    public function toArray(object $notifiable): array
    {
        return array_diff_key($this->payload, ['_in_app' => 1, '_push' => 1]);
    }

    public function toFcm(object $notifiable): array
    {
        return [
            'title' => $this->payload['title'] ?? config('app.name'),
            'body' => $this->payload['body'] ?? '',
            'data' => [
                'type' => (string) ($this->payload['type'] ?? ''),
                'url' => (string) ($this->payload['url'] ?? ''),
            ],
        ];
    }
}
