<?php

namespace App\Services\Messaging\Channels;

use App\Services\Messaging\Channel;
use Illuminate\Support\Facades\Log;

/** Développement : le message est écrit dans storage/logs/laravel.log. */
class LogChannel implements Channel
{
    public function __construct(private string $label = 'log')
    {
    }

    public function send(string $to, string $text, string $purpose = 'text', array $context = []): ?string
    {
        Log::info("[{$this->label}] → {$to} : {$text}");

        return 'log-'.uniqid();
    }
}
