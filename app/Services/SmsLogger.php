<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SmsLogger implements SmsServiceInterface
{
    public function send(string $phoneNumber, string $message): bool
    {
        Log::channel('sms')->info("SMS → {$phoneNumber}", [
            'message' => $message,
            'timestamp' => now()->toDateTimeString()
        ]);
        
        return true;
    }
}