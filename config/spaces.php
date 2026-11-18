<?php

// Catégories d'Espace, puis une sous-catégorie facultative.
// « education » = les institutions : leurs membres peuvent les choisir sur leur profil, et leurs points comptent pour elles.

// Adresse propre à chaque Espace : lions.sosa.social.
// 1. SPACE_DOMAIN s'il est réglé (jamais localhost ni une IP) ;
// 2. sinon le domaine de APP_URL : en production (APP_URL=https://sosa.social) chaque Espace a donc
//    automatiquement son adresse « slug.sosa.social » ; sur localhost les adresses restent désactivées.
// Il faut un DNS « *.sosa.social » vers le serveur, un certificat HTTPS pour *.sosa.social,
// SESSION_DOMAIN=.sosa.social, et la fonction « Sites d'Espaces » ouverte dans l'admin.
$spaceHost = '';
foreach ([env('SPACE_DOMAIN'), parse_url((string) env('APP_URL'), PHP_URL_HOST)] as $candidate) {
    $candidate = strtolower(trim((string) $candidate));
    if ($candidate !== '' && ! in_array($candidate, ['localhost', '127.0.0.1', '::1'], true) && ! filter_var($candidate, FILTER_VALIDATE_IP)) {
        $spaceHost = $candidate;
        break;
    }
}

return [
    'domain' => $spaceHost ?: null,
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
