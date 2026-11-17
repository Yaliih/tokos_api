<?php

// Catégories d'Espace, puis une sous-catégorie facultative.
// « education » = les institutions : leurs membres peuvent les choisir sur leur profil, et leurs points comptent pour elles.
return [
    // Adresse propre à chaque Espace : lions.sosa.social. Laisser vide pour désactiver.
    // Il faut un DNS « *.sosa.social » vers le serveur, un certificat HTTPS pour *.sosa.social, et SESSION_DOMAIN=.sosa.social.
    'domain' => env('SPACE_DOMAIN'),
    'reserved_subdomains' => ['www', 'admin', 'api', 'app', 'mail', 'smtp', 'ftp', 'cdn', 'static', 'media', 'm', 'blog', 'aide', 'support', 'status'],

    'categories' => [
        'education' => ['label' => 'Éducation', 'icon' => 'school', 'sub' => [
            'ecole' => 'École', 'lycee' => 'Lycée', 'universite' => 'Université', 'institut' => 'Institut supérieur',
            'centre_formation' => 'Centre de formation', 'club_scolaire' => 'Club scolaire ou étudiant',
        ]],
        'arts' => ['label' => 'Arts et culture', 'icon' => 'mic', 'sub' => [
            'musique' => 'Groupe de musique', 'danse' => 'Troupe de danse', 'theatre' => 'Théâtre', 'chorale' => 'Chorale',
            'humour' => 'Humour', 'collectif' => 'Collectif d\'artistes', 'label' => 'Label ou studio',
        ]],
        'sport' => ['label' => 'Sport', 'icon' => 'trophy', 'sub' => [
            'club' => 'Club', 'academie' => 'Académie', 'supporters' => 'Supporters',
        ]],
        'religion' => ['label' => 'Église et foi', 'icon' => 'heart', 'sub' => [
            'eglise' => 'Église', 'jeunesse' => 'Groupe de jeunes', 'chorale' => 'Chorale',
        ]],
        'communaute' => ['label' => 'Communauté', 'icon' => 'users', 'sub' => [
            'quartier' => 'Quartier', 'association' => 'Association ou ONG', 'fans' => 'Fan-club', 'diaspora' => 'Diaspora', 'amis' => 'Groupe d\'amis',
        ]],
        'pro' => ['label' => 'Entreprise et média', 'icon' => 'megaphone', 'sub' => [
            'entreprise' => 'Entreprise', 'marque' => 'Marque', 'media' => 'Média', 'evenement' => 'Événement',
        ]],
        'autre' => ['label' => 'Autre', 'icon' => 'sparkles', 'sub' => []],
    ],
];
