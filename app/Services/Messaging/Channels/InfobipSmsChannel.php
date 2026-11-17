<?php

namespace App\Services\Messaging\Channels;

use App\Services\Messaging\Channel;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Infobip SMS (texte libre, expéditeur alphanumérique « Sosa »).
 *   POST https://{INFOBIP_BASE_URL}/sms/2/text/advanced, en-tête « Authorization: App {INFOBIP_API_KEY} ».
 * Même intégration que Tuendea.
 */
class InfobipSmsChannel implements Channel
{
    public static function configured(): bool
    {
        return filled(config('messaging.infobip.base_url')) && filled(config('messaging.infobip.api_key'));
    }

    public function send(string $to, string $text, string $purpose = 'text', array $context = []): ?string
    {
        if (! self::configured()) {
            throw new RuntimeException('Infobip : INFOBIP_BASE_URL et INFOBIP_API_KEY manquants.');
        }
        $base = 'https://'.preg_replace('#^https?://#', '', rtrim((string) config('messaging.infobip.base_url'), '/'));
        $res = Http::acceptJson()->asJson()->timeout(15)->withHeaders(['Authorization' => 'App '.config('messaging.infobip.api_key')])
            ->post($base.'/sms/2/text/advanced', ['messages' => [[
                'destinations' => [['to' => preg_replace('/\D/', '', $to)]],
                'from' => mb_substr((string) config('messaging.infobip.sender', 'Sosa'), 0, 11),
                'text' => $text,
            ]]]);
        $item = $res->json('messages.0') ?? [];
        $group = strtoupper((string) data_get($item, 'status.groupName', ''));
        if (! $res->successful() || in_array($group, ['REJECTED', 'UNDELIVERABLE', 'EXPIRED'], true)) {
            throw new RuntimeException('Infobip : '.(data_get($item, 'status.description') ?? $res->json('requestError.serviceException.text') ?? 'erreur '.$res->status()));
        }

        return (string) (data_get($item, 'messageId') ?? '');
    }
}
