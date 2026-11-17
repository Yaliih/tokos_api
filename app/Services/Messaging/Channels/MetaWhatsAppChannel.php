<?php

namespace App\Services\Messaging\Channels;

use App\Services\Messaging\Channel;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * WhatsApp Cloud API (Meta). Un message envoyé en premier doit passer par un modèle approuvé :
 * - les codes : modèle « authentication » (WHATSAPP_CODE_TEMPLATE) avec le code en paramètre et en bouton « copier » ;
 * - les autres messages : modèle à un paramètre (WHATSAPP_TEXT_TEMPLATE), sinon texte libre
 *   (accepté seulement si la personne a écrit au numéro dans les dernières 24 h).
 */
class MetaWhatsAppChannel implements Channel
{
    public function send(string $to, string $text, string $purpose = 'text', array $context = []): ?string
    {
        $c = config('messaging.meta');
        if (! $c['token'] || ! $c['phone_number_id']) {
            throw new RuntimeException('WhatsApp : WHATSAPP_TOKEN et WHATSAPP_PHONE_NUMBER_ID manquants.');
        }
        $num = ltrim($to, '+');
        if ($purpose === 'code' && ! empty($context['code'])) {
            $payload = ['type' => 'template', 'template' => [
                'name' => $c['code_template'], 'language' => ['code' => $c['language']],
                'components' => [
                    ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => (string) $context['code']]]],
                    ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => (string) $context['code']]]],
                ],
            ]];
        } elseif ($c['text_template']) {
            $payload = ['type' => 'template', 'template' => [
                'name' => $c['text_template'], 'language' => ['code' => $c['language']],
                'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $text]]]],
            ]];
        } else {
            $payload = ['type' => 'text', 'text' => ['body' => $text, 'preview_url' => true]];
        }
        $res = Http::withToken($c['token'])->timeout(15)
            ->post("https://graph.facebook.com/{$c['version']}/{$c['phone_number_id']}/messages", ['messaging_product' => 'whatsapp', 'to' => $num] + $payload);
        if (! $res->successful()) {
            throw new RuntimeException('WhatsApp '.$res->status().' : '.($res->json('error.message') ?? $res->body()));
        }

        return $res->json('messages.0.id');
    }
}
