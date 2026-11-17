<?php

namespace App\Jobs;

use App\Models\DeviceToken;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Les alertes sur le téléphone (app, Firebase) et dans le navigateur (Web Push) : un appel réseau par appareil,
 * donc dans la file d'attente. La cloche, elle, est déjà remplie.
 */
class SendPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 300;

    public int $tries = 2;

    public function __construct(public array $ids, public array $payload, public array $web)
    {
    }

    public function handle(): void
    {
        // Application (Firebase) : seulement ceux qui ont un téléphone enregistré
        if (config('services.fcm.project_id') && config('services.fcm.credentials')) {
            $phones = DeviceToken::whereIn('user_id', $this->ids)->distinct()->pluck('user_id');
            if ($phones->isNotEmpty()) {
                Notification::sendNow(User::whereIn('id', $phones)->get(), new AppNotification(['_in_app' => false, '_push' => true] + $this->payload));
            }
        }
        // Navigateurs (site et PWA)
        foreach (PushSubscription::whereIn('user_id', $this->ids)->distinct()->pluck('user_id') as $id) {
            try {
                app(\App\Services\Push\WebPush::class)->toUser((int) $id, $this->web);
            } catch (\Throwable $e) {
                Log::info('Push : '.$e->getMessage());
            }
        }
    }
}
