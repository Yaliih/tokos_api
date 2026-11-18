<?php

namespace App\Services\Messaging\Channels;

use App\Services\Messaging\Channel;
use App\Services\Messaging\SmsTemplates;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * eMess (https://emess.cd), SMS en RDC. API v1 :
 *   POST /auth/token {appId, secretKey} → {token} (valable 1 h)
 *   POST /sms/send   {number, template, params} ou {number, message}
 * eMess n'envoie que des textes qui correspondent à un modèle approuvé :
 * le code passe par le modèle EMESS_CODE_TEMPLATE (variable {code}), à créer dans eMess
 * avec le texte : « Sosa : ton code est {code}. Il est valable 10 minutes. Ne le partage avec personne. »
 * Même intégration que Tuendea.
 */
class EmessChannel implements Channel
{
    public static function configured(): bool
    {
        return filled(config('messaging.emess.app_id')) && filled(config('messaging.emess.secret_key'));
    }

    public function send(string $to, string $text, string $purpose = 'text', array $context = []): ?string
    {
        if (! self::configured()) {
            throw new RuntimeException('eMess : EMESS_APP_ID et EMESS_SECRET_KEY manquants.');
        }
        $base = rtrim((string) config('messaging.emess.url'), '/');
        $payload = self::payload($to, $text, $purpose, $context);

        $res = $this->post($base.'/sms/send', $payload);
        // Modèle introuvable ou refusé : on réessaie avec le TEXTE EXACT du modèle,
        // mais seulement s'il est connu (EMESS_CODE_TEXT) — sinon ce serait un refus de plus.
        if (isset($payload['template']) && SmsTemplates::text($purpose) !== null && in_array($res->status(), [404, 422], true)) {
            $res = $this->post($base.'/sms/send', ['number' => $to, 'message' => $text]);
        }
        if (! $res->successful()) {
            $why = match ($res->status()) {
                402 => 'crédit SMS insuffisant',
                404 => 'modèle « '.($payload['template'] ?? '?').' » introuvable sur ce compte eMess',
                422 => 'texte ne correspondant à aucun modèle approuvé',
                default => 'erreur '.$res->status(),
            };
            throw new RuntimeException('eMess : '.$why.' — '.mb_substr((string) ($res->json('message') ?? $res->body()), 0, 160));
        }

        return (string) ($res->json('id') ?? $res->json('data.message_id') ?? '');
    }

    /**
     * Ce qui part vers eMess : le modèle approuvé (nom + variables) quand on en a un,
     * sinon le texte brut. Séparé de l'envoi pour pouvoir le vérifier sans réseau.
     */
    public static function payload(string $to, string $text, string $purpose, array $context = []): array
    {
        $slug = SmsTemplates::slug($purpose);
        $names = $slug ? SmsTemplates::variables($purpose) : [];
        if ($slug === null || ! $names) {
            return ['number' => $to, 'message' => $text];
        }
        $params = [];
        foreach ($names as $name) {
            if (! array_key_exists($name, $context)) {
                return ['number' => $to, 'message' => $text]; // une variable manque : le modèle ne partirait pas
            }
            $params[$name] = (string) $context[$name];
        }

        return ['number' => $to, 'template' => $slug, 'params' => $params];
    }

    private function post(string $url, array $payload, bool $retry = true)
    {
        $res = Http::acceptJson()->asJson()->timeout(15)->withToken((string) $this->token())->post($url, $payload);
        if ($res->status() === 401 && $retry) {
            Cache::forget('emess.token');

            return $this->post($url, $payload, false);
        }

        return $res;
    }

    private function token(): string
    {
        $cached = Cache::get('emess.token');
        if ($cached) {
            return $cached;
        }
        $res = Http::acceptJson()->asJson()->timeout(15)->post(rtrim((string) config('messaging.emess.url'), '/').'/auth/token', [
            'appId' => config('messaging.emess.app_id'), 'secretKey' => config('messaging.emess.secret_key'),
        ]);
        $token = $res->successful() ? (string) $res->json('token') : '';
        if ($token === '') {
            throw new RuntimeException('eMess : connexion refusée (vérifie EMESS_APP_ID et EMESS_SECRET_KEY).');
        }
        Cache::put('emess.token', $token, now()->addMinutes(50));

        return $token;
    }
}
