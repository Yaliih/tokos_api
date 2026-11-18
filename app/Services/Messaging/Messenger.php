<?php

namespace App\Services\Messaging;

use App\Models\OutboundMessage;
use App\Models\Setting;
use App\Services\Messaging\Channels\AfricasTalkingChannel;
use App\Services\Messaging\Channels\HttpChannel;
use App\Services\Messaging\Channels\LogChannel;
use App\Services\Messaging\Channels\MetaWhatsAppChannel;
use App\Services\Messaging\Channels\TwilioChannel;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Envoie un message par le premier canal qui fonctionne (WhatsApp, puis SMS, ou l'inverse),
 * et garde une trace de chaque essai dans outbound_messages (visible dans l'admin).
 */
class Messenger
{
    public const CHANNELS = ['whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'log' => 'Journal (test)'];

    /** Ordre des canaux : celui de l'admin s'il est réglé, sinon celui de .env. */
    public function channels(): array
    {
        $saved = Cache::rememberForever('settings.messaging.channels', function () {
            try {
                return Setting::find('messaging.channels')?->value;
            } catch (Throwable) {
                return null;
            }
        });
        $list = is_array($saved) && $saved ? $saved : config('messaging.channels');

        return array_values(array_intersect($list, array_keys(self::CHANNELS))) ?: ['log'];
    }

    public function saveChannels(array $channels): void
    {
        Setting::updateOrCreate(['key' => 'messaging.channels'], ['value' => array_values($channels)]);
        Cache::forget('settings.messaging.channels');
    }

    /** Fournisseurs d'un canal : SMS_DRIVER peut en lister plusieurs (« emess,infobip » : le second prend le relais). */
    public function channel(string $name): Channel
    {
        $drivers = array_values(array_filter(array_map('trim', explode(',', (string) config('messaging.drivers.'.$name, 'log')))));
        if (count($drivers) > 1) {
            $providers = [];
            $ready = [];
            foreach ($drivers as $d) {
                $providers[$d] = $this->single($name, $d);
                $ready[$d] = self::providerReady($name, $d);
            }

            return new Channels\FallbackChannel($providers, $ready);
        }

        return $this->single($name, $drivers[0] ?? 'log');
    }

    /** Les clés de ce fournisseur sont-elles dans .env ? */
    public static function providerReady(string $channel, string $driver): bool
    {
        return match ($channel.':'.$driver) {
            'sms:emess' => Channels\EmessChannel::configured(),
            'sms:infobip' => Channels\InfobipSmsChannel::configured(),
            'sms:twilio', 'whatsapp:twilio' => filled(config('messaging.twilio.sid')),
            'sms:africastalking' => filled(config('messaging.africastalking.api_key')),
            'sms:http' => filled(config('messaging.http.sms.url')),
            'whatsapp:meta' => filled(config('messaging.meta.token')),
            'whatsapp:http' => filled(config('messaging.http.whatsapp.url')),
            default => true,
        };
    }

    private function single(string $name, string $driver): Channel
    {
        return match ($name.':'.$driver) {
            'sms:emess' => new Channels\EmessChannel(),
            'sms:infobip' => new Channels\InfobipSmsChannel(),
            'sms:twilio' => new TwilioChannel(false),
            'sms:africastalking' => new AfricasTalkingChannel(),
            'sms:http' => new HttpChannel('sms'),
            'whatsapp:meta' => new MetaWhatsAppChannel(),
            'whatsapp:twilio' => new TwilioChannel(true),
            'whatsapp:http' => new HttpChannel('whatsapp'),
            default => new LogChannel($name),
        };
    }

    /** Envoie un texte. Retourne true dès qu'un canal a réussi. */
    public function send(string $to, string $text, string $purpose = 'text', array $context = [], ?array $only = null): bool
    {
        $to = '+'.ltrim(preg_replace('/[^\d+]/', '', $to), '+');
        $msg = OutboundMessage::create(['to' => $to, 'purpose' => $purpose, 'body' => $purpose === 'code' ? preg_replace('/\d{4,}/', '••••••', $text) : $text, 'status' => 'pending']);
        $attempts = [];
        foreach ($only ?? $this->channels() as $name) {
            try {
                $ref = $this->channel($name)->send($to, $text, $purpose, $context);
                $attempts[] = ['channel' => $name, 'ok' => true];
                $msg->update(['status' => 'sent', 'channel' => $name, 'provider_ref' => $ref, 'attempts' => $attempts]);

                return true;
            } catch (Throwable $e) {
                $attempts[] = ['channel' => $name, 'ok' => false, 'error' => mb_substr($e->getMessage(), 0, 300)];
                report($e);
            }
        }
        // En développement, sans aucun fournisseur prêt : le message va au journal (le code reste lisible dans storage/logs)
        if (! app()->environment('production') && ! in_array('log', $only ?? $this->channels(), true)) {
            (new LogChannel('log'))->send($to, $text, $purpose, $context);
            $attempts[] = ['channel' => 'log', 'ok' => true];
            $msg->update(['status' => 'sent', 'channel' => 'log', 'attempts' => $attempts]);

            return true;
        }
        $msg->update(['status' => 'failed', 'attempts' => $attempts]);

        return false;
    }

    /** Code de vérification (modèle approuvé sur eMess, modèle « authentication » sur WhatsApp). */
    public function sendCode(string $to, string $code): bool
    {
        // Texte affiché dans le journal et utilisé en repli : celui du modèle s'il est connu
        // (EMESS_CODE_TEXT). eMess, lui, envoie le modèle approuvé par son nom.
        $context = self::codeContext($code);

        $text = SmsTemplates::render('code', $context)
            ?? "Sosa : ton code est {$code}. Il est valable {$context['validity_minutes']} minutes. Ne le partage avec personne.";

        return $this->send($to, $text, 'code', $context);
    }

    /** Les valeurs que réclame le modèle de code : {app_name}, {code}, {validity_minutes}. @return array<string, mixed> */
    public static function codeContext(string $code): array
    {
        return [
            'app_name' => (string) config('messaging.app_name', 'Sosa'),
            'code' => $code,
            'validity_minutes' => (int) config('messaging.code_ttl', 10),
        ];
    }
}
