<?php

namespace App\Services\Messaging\Channels;

use App\Services\Messaging\Channel;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Fournisseur quelconque avec une API HTTP « numéro + texte » (agrégateur local, opérateur…). */
class HttpChannel implements Channel
{
    public function __construct(private string $kind = 'sms')
    {
    }

    public function send(string $to, string $text, string $purpose = 'text', array $context = []): ?string
    {
        $c = config('messaging.http.'.$this->kind);
        if (empty($c['url'])) {
            throw new RuntimeException('HTTP '.$this->kind.' : URL du fournisseur manquante.');
        }
        $data = $c['extra'] + [$c['to_field'] => $to, $c['text_field'] => $text];
        $req = Http::timeout(15)->acceptJson();
        if ($c['token']) {
            $req = $req->withToken($c['token']);
        }
        if (! $c['json']) {
            $req = $req->asForm();
        }
        $res = strtoupper($c['method']) === 'GET' ? $req->get($c['url'], $data) : $req->send(strtoupper($c['method']), $c['url'], [$c['json'] ? 'json' : 'form_params' => $data]);
        if (! $res->successful()) {
            throw new RuntimeException('HTTP '.$this->kind.' '.$res->status().' : '.mb_substr($res->body(), 0, 200));
        }

        return (string) ($res->json('id') ?? $res->json('message_id') ?? $res->json('messageId') ?? '');
    }
}
