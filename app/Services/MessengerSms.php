<?php

namespace App\Services;

use App\Services\Messaging\Messenger;

/** L'ancienne interface SMS passe maintenant par le Messenger (WhatsApp / SMS avec relais). */
class MessengerSms implements SmsServiceInterface
{
    public function __construct(private Messenger $messenger)
    {
    }

    public function send(string $phoneNumber, string $message, array $context = []): bool
    {
        return $this->messenger->send($phoneNumber, $message, 'invitation', $context);
    }
}
