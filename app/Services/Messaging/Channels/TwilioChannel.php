<?php

namespace App\Services\Messaging\Channels;

use App\Services\Messaging\Channel;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Twilio (SMS ou WhatsApp) par son API REST, sans SDK. */
class TwilioChannel implements Channel
{
    public function __construct(private bool $whatsapp = false)
    {
    }

    public function send(string $to, string $text, string $purpose = 'text', array $context = []): ?string
    {
        $c = config('messaging.twilio');
        if (! $c['sid'] || ! $c['token']) {
            throw new RuntimeException('Twilio : TWILIO_SID et TWILIO_TOKEN manquants.');
        }
        $from = $this->whatsapp ? 'whatsapp:'.$c['whatsapp_from'] : $c['from'];
        $res = Http::asForm()->withBasicAuth($c['sid'], $c['token'])->timeout(15)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$c['sid']}/Messages.json", [
                'To' => $this->whatsapp ? 'whatsapp:'.$to : $to,
                'From' => $from,
                'Body' => $text,
            ]);
        if (! $res->successful()) {
            throw new RuntimeException('Twilio '.$res->status().' : '.($res->json('message') ?? $res->body()));
        }

        return $res->json('sid');
    }
}
