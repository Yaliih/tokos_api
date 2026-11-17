<?php

/*
| Certification (badge vérifié). Les pièces sont gardées sur le disque privé et supprimées
| quelques jours après la décision : on ne garde que la décision.
*/
return [
    'categories' => [
        'user' => [
            'artist' => 'Artiste ou créateur',
            'student' => 'Étudiant ou élève',
            'athlete' => 'Sportif',
            'public' => 'Personnalité, média ou influenceur',
            'organizer' => 'Organisateur d\'événements',
        ],
        'space' => [
            'school' => 'Université, école ou institut',
            'association' => 'Association, club ou collectif',
            'company' => 'Entreprise, marque ou média',
            'community' => 'Communauté ou paroisse',
        ],
    ],

    'documents' => [
        'voter_card' => 'Carte d\'électeur',
        'passport' => 'Passeport',
        'driving_license' => 'Permis de conduire',
        'student_card' => 'Carte d\'étudiant ou d\'élève',
        'company_doc' => 'RCCM, Id. Nat. ou statuts',
        'official_letter' => 'Lettre officielle de l\'établissement',
    ],

    // Pièces conservées après la décision (jours)
    'keep_documents_days' => (int) env('CERTIFICATION_KEEP_DAYS', 30),

    // Conditions avant de pouvoir demander
    'require_avatar' => true,
    'max_file_kb' => 6144,
];
