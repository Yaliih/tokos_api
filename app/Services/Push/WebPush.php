<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Notifications push du navigateur (Web Push, RFC 8291 + VAPID RFC 8292), sans bibliothèque :
 * le message est chiffré pour le téléphone de la personne puis remis à son service push
 * (Google, Mozilla, Apple). Fonctionne sur http://localhost en développement, en https en ligne.
 */
class WebPush
{
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function unb64(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/').str_repeat('=', (4 - strlen($text) % 4) % 4));
    }

    /** Clés VAPID : .env (VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY_PEM en base64) ou fichier créé une fois pour toutes. */
    public static function keys(bool $create = true): ?array
    {
        if (config('push.public_key') && config('push.private_key_pem')) {
            return ['public' => config('push.public_key'), 'pem' => base64_decode((string) config('push.private_key_pem'))];
        }
        $file = storage_path('app/private/vapid.json');
        if (is_file($file)) {
            return json_decode((string) file_get_contents($file), true);
        }
        if (! $create) {
            return null;
        }
        [$pem, $public] = self::newKeyPair();
        $keys = ['public' => self::b64($public), 'pem' => $pem];
        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, json_encode($keys));

        return $keys;
    }

    /** @return array{0: string, 1: string} [clé privée PEM, point public brut de 65 octets] */
    public static function newKeyPair(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (! $key) {
            throw new RuntimeException('OpenSSL ne sait pas créer de clé P-256.');
        }
        openssl_pkey_export($key, $pem);
        $ec = openssl_pkey_get_details($key)['ec'];

        return [$pem, "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)];
    }

    public static function publicKeyFromPoint(string $point)
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin(self::P256_SPKI_PREFIX).$point), 64, "\n")."-----END PUBLIC KEY-----\n";

        return openssl_pkey_get_public($pem);
    }

    private static function hmac(string $key, string $data): string
    {
        return hash_hmac('sha256', $data, $key, true);
    }

    /**
     * Chiffre un message pour un abonnement (aes128gcm).
     *
     * @return string corps binaire à envoyer
     */
    public static function encrypt(string $payload, string $userPublic, string $authSecret, ?string $salt = null, ?array $serverKeys = null): string
    {
        [$serverPem, $serverPublic] = $serverKeys ?? self::newKeyPair();
        $serverPrivate = openssl_pkey_get_private($serverPem);
        $shared = openssl_pkey_derive(self::publicKeyFromPoint($userPublic), $serverPrivate, 32);
        if ($shared === false) {
            throw new RuntimeException('Clé du téléphone invalide.');
        }
        $salt ??= random_bytes(16);
        $prkKey = self::hmac($authSecret, $shared);
        $ikm = self::hmac($prkKey, "WebPush: info\0".$userPublic.$serverPublic."\x01");
        $prk = self::hmac($salt, $ikm);
        $cek = substr(self::hmac($prk, "Content-Encoding: aes128gcm\0\x01"), 0, 16);
        $nonce = substr(self::hmac($prk, "Content-Encoding: nonce\0\x01"), 0, 12);
        $tag = '';
        $cipher = openssl_encrypt($payload."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);

        return $salt.pack('N', 4096).chr(strlen($serverPublic)).$serverPublic.$cipher.$tag;
    }

    /** Jeton VAPID (ES256) pour le service push de l'adresse donnée. */
    public static function vapidJwt(string $endpoint, array $keys): string
    {
        $parts = parse_url($endpoint);
        $aud = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $data = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])).'.'.self::b64(json_encode([
            'aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => config('push.subject'),
        ]));
        openssl_sign($data, $der, openssl_pkey_get_private($keys['pem']), OPENSSL_ALGO_SHA256);

        return $data.'.'.self::b64(self::derToRaw($der));
    }

    /** Signature ECDSA DER → r||s (64 octets) attendue par JWT. */
    public static function derToRaw(string $der): string
    {
        $offset = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7f : 0);
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$offset + 1]);
            $int = substr($der, $offset + 2, $len);
            $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $len;
        }

        return $out;
    }

    /** Envoie à un abonnement. Retourne false si l'abonnement n'existe plus (et le supprime). */
    public function send(PushSubscription $sub, array $message): bool
    {
        $keys = self::keys();
        $body = self::encrypt(json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), self::unb64($sub->p256dh), self::unb64($sub->auth));
        try {
            $res = Http::withHeaders([
                'Authorization' => 'vapid t='.self::vapidJwt($sub->endpoint, $keys).', k='.$keys['public'],
                'Content-Encoding' => 'aes128gcm',
                'Content-Type' => 'application/octet-stream',
                'TTL' => (string) config('push.ttl', 86400),
                'Urgency' => $message['urgency'] ?? 'normal',
            ])->withBody($body, 'application/octet-stream')->timeout(10)->post($sub->endpoint);
        } catch (\Throwable $exception) {
            Log::info('Push non remis : '.$exception->getMessage());

            return true;
        }
        if (in_array($res->status(), [404, 410], true)) {
            $sub->delete(); // désabonné ou application désinstallée

            return false;
        }
        if (! $res->successful()) {
            Log::info('Push refusé '.$res->status().' : '.mb_substr($res->body(), 0, 200));
        } else {
            $sub->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $res->successful();
    }

    /** Envoie à tous les navigateurs abonnés (avec ou sans compte). Renvoie le nombre d'envois réussis. */
    public function toEveryone(array $message): int
    {
        $n = 0;
        PushSubscription::query()->orderBy('id')->chunkById(200, function ($subs) use ($message, &$n) {
            foreach ($subs as $sub) {
                $n += $this->send($sub, $message) ? 1 : 0;
            }
        });

        return $n;
    }

    /** Envoie à tous les téléphones d'une personne. */
    public function toUser(int $userId, array $message): int
    {
        $n = 0;
        foreach (PushSubscription::where('user_id', $userId)->get() as $sub) {
            $n += $this->send($sub, $message) ? 1 : 0;
        }

        return $n;
    }
}
