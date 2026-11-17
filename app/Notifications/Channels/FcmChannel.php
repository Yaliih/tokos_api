<?php

namespace App\Notifications\Channels;

use App\Models\DeviceToken;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envoi des notifications push via Firebase Cloud Messaging (API HTTP v1).
 *
 * Activation : dans .env
 *   FCM_PROJECT_ID=mon-projet-firebase
 *   FCM_CREDENTIALS=storage/app/firebase.json   (clé du compte de service)
 * Sans ces deux valeurs, ce canal n'est jamais utilisé.
 */
class FcmChannel
{
    /** Canal Android « tokos_high » créé par l'app (bannière + son), et priorité haute pour arriver tout de suite. */
    private const ANDROID = ['priority' => 'high', 'notification' => ['channel_id' => 'tokos_high', 'sound' => 'default']];

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toFcm') || ! isset($notifiable->id)) {
            return;
        }

        $tokens = DeviceToken::where('user_id', $notifiable->id)->pluck('token');
        if ($tokens->isEmpty()) {
            return;
        }

        $accessToken = $this->accessToken();
        if (! $accessToken) {
            return;
        }

        $message = $notification->toFcm($notifiable);
        $url = 'https://fcm.googleapis.com/v1/projects/'.config('services.fcm.project_id').'/messages:send';

        foreach ($tokens as $token) {
            try {
                $response = Http::withToken($accessToken)->timeout(5)->post($url, [
                    'message' => [
                        'token' => $token,
                        'notification' => ['title' => $message['title'], 'body' => $message['body']],
                        'data' => $message['data'],
                        'android' => self::ANDROID,
                    ],
                ]);
                // Jeton expiré ou désinstallé : on le supprime.
                if (in_array($response->status(), [404, 410], true)) {
                    DeviceToken::where('token', $token)->delete();
                } elseif (! $response->successful()) {
                    Log::warning('FCM refusé '.$response->status().' : '.mb_substr($response->body(), 0, 300));
                }
            } catch (\Throwable $e) {
                Log::warning('FCM send failed: '.$e->getMessage());
            }
        }
    }

    /**
     * Diagnostic pour l'admin (« M'envoyer un test ») : réglages, jeton d'accès Google, téléphones enregistrés,
     * et la réponse de Firebase pour chacun. Renvoie des lignes lisibles.
     */
    public static function diagnose(int $userId, string $title, string $body, string $url): array
    {
        $out = [];
        if (! config('services.fcm.project_id') || ! config('services.fcm.credentials')) {
            return ['FCM_PROJECT_ID ou FCM_CREDENTIALS manque dans .env (puis php artisan config:clear).'];
        }
        $path = base_path(config('services.fcm.credentials'));
        if (! is_file($path)) {
            return ['Fichier du compte de service introuvable : '.$path];
        }
        Cache::forget('fcm_access_token');
        $self = new self();
        $access = $self->accessToken();
        if (! $access) {
            return ['Google refuse la clé du compte de service (fichier invalide, ou heure du PC décalée).'];
        }
        $out[] = 'Clé Firebase : OK.';
        $tokens = DeviceToken::where('user_id', $userId)->get();
        if ($tokens->isEmpty()) {
            $out[] = 'Aucun téléphone enregistré pour ton compte : dans l\'app, Profil → réglages → « Notifications sur ce téléphone », puis accepte.';

            return $out;
        }
        foreach ($tokens as $t) {
            try {
                $res = Http::withToken($access)->timeout(10)->post('https://fcm.googleapis.com/v1/projects/'.config('services.fcm.project_id').'/messages:send', [
                    'message' => ['token' => $t->token, 'notification' => ['title' => $title, 'body' => $body], 'data' => ['type' => 'announcement', 'url' => $url], 'android' => self::ANDROID],
                ]);
                $out[] = 'Téléphone ('.($t->platform ?? 'android').', enregistré '.$t->created_at?->diffForHumans().') : '
                    .($res->successful() ? 'envoyé à Firebase.' : 'refusé '.$res->status().' — '.mb_substr((string) ($res->json('error.message') ?? $res->body()), 0, 160));
                if (in_array($res->status(), [404, 410], true)) {
                    $t->delete();
                }
            } catch (\Throwable $e) {
                $out[] = 'Erreur réseau : '.$e->getMessage();
            }
        }

        return $out;
    }

    /**
     * Une alerte à tous les téléphones abonnés à un sujet (l'app abonne chaque installation à « all »,
     * même sans compte). Pour les annonces à tout le monde.
     */
    public static function toTopic(string $topic, string $title, string $body, array $data = []): bool
    {
        if (! config('services.fcm.project_id') || ! config('services.fcm.credentials')) {
            return false;
        }
        $self = new self();
        $accessToken = $self->accessToken();
        if (! $accessToken) {
            return false;
        }
        try {
            $res = Http::withToken($accessToken)->timeout(10)->post('https://fcm.googleapis.com/v1/projects/'.config('services.fcm.project_id').'/messages:send', [
                'message' => [
                    'topic' => $topic,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => array_map('strval', $data),
                    'android' => self::ANDROID,
                ],
            ]);
            if (! $res->successful()) {
                Log::warning('FCM topic refusé '.$res->status().' : '.mb_substr($res->body(), 0, 200));
            }

            return $res->successful();
        } catch (\Throwable $e) {
            Log::warning('FCM topic failed: '.$e->getMessage());

            return false;
        }
    }

    private function accessToken(): ?string
    {
        return Cache::remember('fcm_access_token', 3000, function () {
            $path = base_path(config('services.fcm.credentials'));
            if (! is_file($path)) {
                return null;
            }
            $key = json_decode(file_get_contents($path), true);
            $now = time();
            $header = $this->b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $this->b64(json_encode([
                'iss' => $key['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));
            openssl_sign($header.'.'.$claims, $signature, $key['private_key'], 'sha256WithRSAEncryption');
            $jwt = $header.'.'.$claims.'.'.$this->b64($signature);

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            return $response->json('access_token');
        });
    }

    private function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
