<?php

/*
| Anti-triche aux votes et aux inscriptions. Les votes « suspects » comptent quand même,
| mais apparaissent dans l'admin (Modération → Votes suspects) pour être annulés d'un clic.
*/
return [
    // Comptes différents qui peuvent voter depuis un même téléphone dans une même compétition
    'accounts_per_device_per_competition' => (int) env('AF_ACCOUNTS_PER_DEVICE', 2),
    // Comptes qu'on peut créer depuis un même téléphone en 30 jours
    'signups_per_device' => (int) env('AF_SIGNUPS_PER_DEVICE', 3),
    // Votes d'un même réseau (IP) sur un duel en une heure : au-delà de « flag », marqués suspects ;
    // au-delà de « block », refusés. Haut, car tout un campus partage souvent la même IP.
    'ip_flag_per_hour' => (int) env('AF_IP_FLAG', 15),
    'ip_block_per_hour' => (int) env('AF_IP_BLOCK', 120),
    // Compte créé il y a moins de N minutes : vote marqué suspect
    'fresh_account_minutes' => 30,
];
