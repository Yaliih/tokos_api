<?php

namespace App\Services\Messaging\Channels;

use App\Services\Messaging\Channel;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Africa's Talking (SMS), présent dans plusieurs pays d'Afrique. */
class AfricasTalkingChannel implements Channel
{
    public function send(string $to, string $text, string $purpose = 'text', array $context = []): ?string
    {
        $c = config('messaging.africastalking');
        if (! $c['username'] || ! $c['api_key']) {
            throw new RuntimeException('Africa\'s Talking : AT_USERNAME et AT_API_KEY manquants.');
        }
        $url = $c['sandbox'] ? 'https://api.sandbox.africastalking.com/version1/messaging' : 'https://api.africastalking.com/version1/messaging';
        $res = Http::asForm()->acceptJson()->withHeaders(['apiKey' => $c['api_key']])->timeout(15)
            ->post($url, array_filter(['username' => $c['username'], 'to' => $to, 'message' => $text, 'from' => $c['from']]));
        $rec = $res->json('SMSMessageData.Recipients.0');
        if (! $res->successful() || ! $rec || ! in_array((int) ($rec['statusCode'] ?? 0), [100, 101, 102], true)) {
            throw new RuntimeException('Africa\'s Talking '.$res->status().' : '.($rec['status'] ?? $res->body()));
        }

        return $rec['messageId'] ?? null;
    }
}
