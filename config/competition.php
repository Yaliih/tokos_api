<?php

/*
|--------------------------------------------------------------------------
| Règles du moteur de compétition
|--------------------------------------------------------------------------
| Tout est réglable ici sans toucher au code.
*/

return [

    'types' => ['challenge', 'duel', 'contest', 'tournament'],

    // Les disciplines ne sont plus dans le code : elles se règlent dans l'admin (Réglages → Disciplines).
    // La liste de départ est posée dans la base par une migration. Remplie au démarrage par App\Support\Categories.
    'categories' => [],

    // Anciennes disciplines regroupées : musique avec chant, photo avec art
    'category_aliases' => ['musique' => 'chant', 'photo' => 'dessin'],

    'media_types' => ['text', 'image', 'video', 'audio'],

    // Tailles de tableau proposées pour un tournoi (filtrées selon la limite de l'organisateur)
    // Toujours une puissance de 2 : chaque tour divise par deux, sans cas compliqués
    'tournament_sizes' => [4, 8, 16, 32, 64, 128, 256, 512, 1024, 2048],

    // Limites selon l'organisateur : un utilisateur simple peut organiser des concours
    // et des tournois, mais plus petits qu'un Espace.
    'limits' => [
        'user' => [
            'contest_max_entries'    => 50,
            'tournament_max_entries' => 32,
            'challenge_max_entries'  => 50,
            'allow_jury'             => false,
            'allow_prize'            => true,
        ],
        'space' => [
            'contest_max_entries'    => 500,
            'tournament_max_entries' => 2048,
            'challenge_max_entries'  => 1000,
            'allow_jury'             => true,
            'allow_prize'            => true,
        ],
    ],

    // Durées maximales des médias, vérifiées sur le serveur et avant l'envoi (site et app)
    'media' => [
        'max_video_seconds'       => 180, // 3 min
        'max_audio_seconds'       => 300, // 5 min
        'max_intro_video_seconds' => 60,  // vidéo de présentation d'une candidature
        'max_cover_video_seconds' => 30,  // vidéo de couverture (concours, tournoi, profil, Espace)
        'max_upload_mb'           => 50,  // poids maximum d'un fichier
    ],

    // Durées proposées (en heures) pour un défi, un duel ou un tour de tournoi
    'duration_presets' => [1 => '1 h', 6 => '6 h', 12 => '12 h', 24 => '24 h', 48 => '2 jours', 72 => '3 jours', 168 => '1 semaine'],

    'durations' => [
        'default_match_hours'  => 24,
        'min_match_hours'      => 1,
        'max_match_hours'      => 168,
        'invitation_expiry_hours' => 72,
        'ending_soon_minutes'  => 60,
        // Tournoi, à partir du 2e tour : temps laissé à chaque qualifié pour envoyer sa nouvelle prestation.
        // Fixé par la plateforme (pas par l'organisateur). Rien envoyé à temps : l'autre passe.
        'round_submission_hours' => 24,
        'round_reminder_hours'   => 4,   // rappel quand il reste ce temps
    ],

    // Anti-triche
    'votes' => [
        // Comptes plus jeunes que ça : leur vote compte à moitié
        'new_account_hours'     => 24,
        'new_account_weight'    => 0.5,
        // Membres d'un Espace en compétition, quand la règle est "reduced"
        'member_reduced_weight' => 0.5,
        // Au-delà de ce nombre de votes par heure depuis une même IP sur un match, on refuse
        'max_votes_per_ip_per_match' => 15,
        // Seuils qui déclenchent une activité "ce duel a dépassé N votes"
        'milestones' => [50, 100, 500, 1000, 5000],
    ],

    // Points de saison
    'points' => [
        'participation'    => 1,
        'match_win'        => 3,
        'match_draw'       => 1,
        'contest_podium'   => [1 => 10, 2 => 6, 3 => 3],
        'tournament_win'   => 15,
        'tournament_final' => 8,
    ],

    'elo_k' => 32,
];
