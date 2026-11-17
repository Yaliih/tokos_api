<?php

namespace App\Services\Messaging\Channels;

use App\Services\Messaging\Channel;
use RuntimeException;
use Throwable;

/**
 * Plusieurs fournisseurs pour un même canal (ex. SMS : eMess, puis Infobip si eMess échoue).
 * Un fournisseur sans clés dans .env est sauté.
 */
class FallbackChannel implements Channel
{
    /** @param array<string, Channel> $providers nom => canal */
    public function __construct(private array $providers, private array $configured)
    {
    }

    public function send(string $to, string $text, string $purpose = 'text', array $context = []): ?string
    {
        $errors = [];
        foreach ($this->providers as $name => $channel) {
            if (! ($this->configured[$name] ?? true)) {
                $errors[] = $name.' : clés manquantes';
                continue;
            }
            try {
                $ref = $channel->send($to, $text, $purpose, $context);

                return $name.($ref ? ':'.$ref : '');
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        throw new RuntimeException(implode(' | ', $errors) ?: 'Aucun fournisseur.');
    }
}
