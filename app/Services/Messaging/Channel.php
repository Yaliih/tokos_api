<?php

namespace App\Services\Messaging;

/** Un moyen d'envoyer un message (SMS ou WhatsApp, chez un fournisseur donné). */
interface Channel
{
    /**
     * Envoie le message. $purpose : « code » pour un code de vérification (avec $context['code']),
     * sinon un texte libre. Retourne la référence du fournisseur, ou lève une exception en cas d'échec.
     */
    public function send(string $to, string $text, string $purpose = 'text', array $context = []): ?string;
}
