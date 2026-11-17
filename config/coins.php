<?php

/*
| Les coins de Sosa.
| Prix des packs en francs congolais (CDF). La part de la plateforme est prélevée
| sur ce que reçoit l'organisateur (votes payants) ou la personne (cadeaux).
*/
return [
    // Moyen de paiement : « saspay » dès que la clé SasPay est saisie dans l'admin (Réglages → Paiements).
    // Sans clé : « test » (coins crédités tout de suite) en développement, achat en attente en production.
    'provider' => env('COINS_PROVIDER', 'test'),

    'currency' => 'CDF',
    // Prix d'un coin en francs congolais, et tailles des packs proposés (réglables dans l'admin).
    'coin_price' => 20,
    'pack_sizes' => [50, 150, 400, 1000],
    'popular' => 150,
    // Calculés à partir du prix du coin (voir App\Support\Payments::apply)
    'packs' => [],

    // SasPay (https://saspay.me) : mobile money RDC (M-Pesa, Orange Money, Airtel Money), en CDF.
    'saspay' => [
        'base_url' => env('SASPAY_BASE_URL', 'https://api.saspay.me/api/v1'),
        'api_key' => env('SASPAY_API_KEY'),
        'webhook_secret' => env('SASPAY_WEBHOOK_SECRET'),
        'networks' => ['mpesa' => 'vodacom_cd', 'orange' => 'orange_cd', 'airtel' => 'airtel_cd'],
    ],

    // Part prélevée par la plateforme (en %)
    'commission' => (int) env('COINS_COMMISSION', 20),

    // Montants proposés pour un cadeau, et quantités proposées pour voter
    'gift_presets' => [10, 25, 50, 100, 250],
    'vote_quantities' => [1, 5, 10, 25],
    'vote_cost_presets' => [1, 2, 5, 10],
    'max_vote_cost' => 1000,
    'max_votes_at_once' => 500,

    // Retraits : seuls les coins gagnés (votes reçus, cadeaux reçus) peuvent être convertis en argent
    'cashout' => [
        'rate' => (int) env('COINS_CASHOUT_RATE', 10), // FC versés par coin
        'min' => (int) env('COINS_CASHOUT_MIN', 100),  // retrait minimum, en coins
    ],

    // Coins offerts à l'inscription, pour essayer (0 pour désactiver)
    'welcome_bonus' => (int) env('COINS_WELCOME_BONUS', 0),
];
