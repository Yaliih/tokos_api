<?php

/*
|--------------------------------------------------------------------------
| Badges
|--------------------------------------------------------------------------
| code => [nom, emoji, description]. Les règles d'attribution sont dans
| App\Services\Social\BadgeService.
*/

return [
    'first_entry'    => ['name' => 'Premier pas',        'emoji' => '🎬', 'description' => 'Première participation à une compétition.'],
    'first_win'      => ['name' => 'Première victoire',  'emoji' => '🥇', 'description' => 'Premier duel gagné.'],
    'wins_10'        => ['name' => 'Combattant',         'emoji' => '⚔️', 'description' => '10 duels gagnés.'],
    'wins_50'        => ['name' => 'Vétéran',            'emoji' => '🛡️', 'description' => '50 duels gagnés.'],
    'streak_3'       => ['name' => 'En feu',             'emoji' => '🔥', 'description' => '3 victoires d\'affilée.'],
    'streak_10'      => ['name' => 'Inarrêtable',        'emoji' => '⚡', 'description' => '10 victoires d\'affilée.'],
    'giant_slayer'   => ['name' => 'Tueur de géants',    'emoji' => '🗡️', 'description' => 'Victoire contre un adversaire classé 200 points plus haut.'],
    'crowd_favorite' => ['name' => 'Chouchou du public', 'emoji' => '💯', 'description' => 'Victoire dans un duel à plus de 100 votes.'],
    'champion'       => ['name' => 'Champion',           'emoji' => '🏆', 'description' => 'Vainqueur d\'un tournoi.'],
    'laureate'       => ['name' => 'Lauréat',            'emoji' => '🎖️', 'description' => 'Vainqueur d\'un concours.'],
    'organizer'      => ['name' => 'Organisateur',       'emoji' => '📣', 'description' => 'A organisé une compétition avec au moins 10 participants.'],
    'voter_100'      => ['name' => 'Juge du peuple',     'emoji' => '🗳️', 'description' => '100 votes exprimés.'],
    'top_10'         => ['name' => 'Top 10',             'emoji' => '👑', 'description' => 'Entré dans le top 10 du classement.'],
];
