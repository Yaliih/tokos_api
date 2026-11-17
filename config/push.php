<?php

/*
| Notifications push (Web Push). Les clés sont créées toutes seules au premier usage
| (storage/app/private/vapid.json). Pour les fixer, mets-les dans .env.
*/
return [
    'public_key' => env('VAPID_PUBLIC_KEY'),
    'private_key_pem' => env('VAPID_PRIVATE_KEY_PEM'), // le PEM encodé en base64, sur une ligne
    'subject' => env('VAPID_SUBJECT', 'mailto:contact@sosa.social'),
    'ttl' => 86400,
];
