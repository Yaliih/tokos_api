<?php

namespace App\Services;

use Twilio\Rest\Client;
use Illuminate\Support\Facades\Log;

class TwilioService implements SmsServiceInterface
{
    protected Client $client;
    protected string $from;

    public function __construct()
    {
        $this->client = new Client(
            config('services.twilio.sid'),
            config('services.twilio.token')
        );
        $this->from = config('services.twilio.from');
    }

    public function send(string $phoneNumber, string $message, array $context = []): bool
    {
        try {
            $this->client->messages->create($phoneNumber, [
                'from' => $this->from,
                'body' => $message
            ]);
            
            Log::info("SMS envoyé à {$phoneNumber}");
            return true;
            
        } catch (\Exception $e) {
            Log::error("Échec SMS: " . $e->getMessage());
            return false;
        }
    }
}