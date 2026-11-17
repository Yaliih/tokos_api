<?php

/*
| Les fonctions qu'on peut cacher ou montrer depuis l'admin (/admin, page « Fonctions »).
| La valeur ici est celle par défaut ; ce qui est réglé dans l'admin la remplace.
| Une fonction cachée disparaît de l'interface ET ses routes répondent 404 (API comprise).
*/
return [
    'groups' => [
        'competitions' => 'Compétitions',
        'social' => 'Social',
        'publishing' => 'Publication',
        'coins' => 'Monétisation (coins et paiements)',
        'advanced' => 'Options avancées des organisateurs',
        'trust' => 'Confiance et accès',
    ],

    'list' => [
        'challenges' => ['group' => 'competitions', 'label' => 'Défis', 'help' => 'Un créateur lance un défi, chacun peut le relever en duel.', 'default' => true],
        'duels' => ['group' => 'competitions', 'label' => 'Duels', 'help' => 'Un face-à-face entre deux personnes ou deux propositions.', 'default' => true],
        'contests' => ['group' => 'competitions', 'label' => 'Concours', 'help' => 'Des candidats classés par les votes.', 'default' => true],
        'tournaments' => ['group' => 'competitions', 'label' => 'Tournois', 'help' => 'Élimination directe, tour après tour.', 'default' => true],

        'spaces' => ['group' => 'social', 'label' => 'Espaces', 'help' => 'Pages de groupes, clubs et institutions.', 'default' => true],
        'comments' => ['group' => 'social', 'label' => 'Commentaires', 'help' => 'Commenter les compétitions et les duels.', 'default' => true],
        'swipe' => ['group' => 'social', 'label' => 'Vote rapide (swipe)', 'help' => 'Voter duel après duel en glissant.', 'default' => true],
        'leaderboard' => ['group' => 'social', 'label' => 'Classements', 'help' => 'Classement de la saison.', 'default' => true],
        'badges' => ['group' => 'social', 'label' => 'Badges et séries', 'help' => 'Badges gagnés et séries de victoires sur les profils.', 'default' => true],
        'space_sites' => ['group' => 'social', 'label' => 'Site web des Espaces', 'help' => 'Chaque Espace a son petit site (nom.sosa.social).', 'default' => true, 'requires' => 'spaces'],
        'push' => ['group' => 'social', 'label' => 'Notifications sur le téléphone', 'help' => 'Alertes même quand Sosa est fermé.', 'default' => true],
        'welcome_questions' => ['group' => 'social', 'label' => 'Questions d\'accueil', 'help' => 'De temps en temps, demander ce qui plaît et la ville, pour mieux proposer.', 'default' => true],
        'auto_feed' => ['group' => 'social', 'label' => 'Publications automatiques du fil', 'help' => 'Sosa raconte ce qui se passe : « X repasse premier au concours Y », « Y cumule le plus de voix aujourd\'hui », « duel serré », « dernières heures pour voter », « 50 participants ». Utile au lancement pour un fil vivant.', 'default' => true],
        'progress_alerts' => ['group' => 'social', 'label' => 'Alertes de classement aux participants', 'help' => 'Chaque participant d\'un concours sait où il en est : il passe premier, on le dépasse, il passe dernier, quelqu\'un prend la tête, dernières heures.', 'default' => true],
        'supporter_alerts' => ['group' => 'social', 'label' => 'Relancer les votants et anciens participants', 'help' => 'Ceux qui sont venus une fois (même juste pour voter) suivent leur favori : il gagne, il est dépassé, il finit 3e, il participe à une nouvelle compétition ; et ils apprennent qu\'un nouveau concours ou tournoi est lancé. Avec des limites par personne (pas plus de quelques alertes par jour, une nouvelle compétition tous les 3 jours au plus).', 'default' => true],
        'mentions' => ['group' => 'social', 'label' => 'Mentions @', 'help' => 'Citer une personne ou un Espace avec @ dans une publication ou un commentaire : son nom devient un lien et il est prévenu.', 'default' => true],
        'phone_invites' => ['group' => 'social', 'label' => 'Défier par numéro', 'help' => 'Inviter quelqu\'un qui n\'a pas encore de compte, par SMS ou WhatsApp.', 'default' => true],

        'versus' => ['group' => 'publishing', 'label' => 'Versus « ceci ou cela »', 'help' => 'Un duel entre deux propositions (ex. Pondu ou Moambe), sans adversaire.', 'default' => true, 'requires' => 'duels'],
        'recording' => ['group' => 'publishing', 'label' => 'Filmer et enregistrer dans l\'app', 'help' => 'Boutons « Filmer » et « Enregistrer » à côté de l\'import de fichier.', 'default' => true],
        'prizes' => ['group' => 'publishing', 'label' => 'Récompenses annoncées', 'help' => 'L\'organisateur annonce ce que gagne le vainqueur (argent, lots…).', 'default' => true],
        'editing' => ['group' => 'publishing', 'label' => 'Modifier ses publications', 'help' => 'L\'auteur corrige sa publication tant que personne n\'a répondu ni voté.', 'default' => true],
        'rematch' => ['group' => 'publishing', 'label' => 'Revanche', 'help' => 'Après un duel, le perdant peut demander une revanche.', 'default' => true],

        // Tout ce qui touche à l'argent est coupé par défaut : on l'ouvre depuis l'admin, une fois le paiement prêt.
        'coins' => ['group' => 'coins', 'label' => 'Coins (tout ce qui est payant)', 'help' => 'Interrupteur général : coupé, plus rien de payant n\'apparaît (portefeuille, achats, cadeaux, votes payants, retraits).', 'default' => false],
        'buy_coins_web' => ['group' => 'coins', 'label' => 'Achat de coins sur le site', 'help' => 'Payer par mobile money (SasPay) sur le site. Les clés et le prix se règlent dans « Paiements ».', 'default' => false, 'requires' => 'coins'],
        'buy_coins_app' => ['group' => 'coins', 'label' => 'Achat de coins dans l\'app', 'help' => 'Bouton « Acheter » dans l\'app. Attention : Google Play interdit de payer des coins hors de son système. À n\'activer qu\'avec Google Play Billing, ou pour une app installée hors Play Store.', 'default' => false, 'requires' => 'coins'],
        'gifts' => ['group' => 'coins', 'label' => 'Cadeaux en coins', 'help' => 'Offrir des coins à une personne ou un Espace.', 'default' => true, 'requires' => 'coins'],
        'paid_votes' => ['group' => 'coins', 'label' => 'Votes payants', 'help' => 'Les organisateurs fixent un prix en coins par vote.', 'default' => false, 'requires' => 'coins'],
        'withdrawals' => ['group' => 'coins', 'label' => 'Retraits en argent', 'help' => 'Changer ses coins gagnés en argent mobile money. À activer seulement avec un partenaire agréé et l\'avis d\'un juriste.', 'default' => false, 'requires' => 'coins'],

        'applications' => ['group' => 'advanced', 'label' => 'Candidatures', 'help' => 'Participation sur dossier, validée par l\'organisateur.', 'default' => true],
        'jury' => ['group' => 'advanced', 'label' => 'Jury', 'help' => 'Un jury qui note en plus du public.', 'default' => true],
        'geo_limits' => ['group' => 'advanced', 'label' => 'Compétitions réservées à une ville', 'help' => 'L\'organisateur limite la participation ou le vote à une ville, vérifiée par la position du téléphone.', 'default' => true],
        'space_competitions' => ['group' => 'advanced', 'label' => 'Espaces contre Espaces', 'help' => 'Compétitions entre Espaces, ou ouvertes aux Espaces.', 'default' => true],

        'registration' => ['group' => 'trust', 'label' => 'Inscriptions ouvertes', 'help' => 'Si c\'est coupé, plus personne ne peut créer de compte.', 'default' => true],
        'certification' => ['group' => 'trust', 'label' => 'Certification', 'help' => 'Les personnes et les Espaces peuvent demander le badge certifié.', 'default' => true],
    ],
];
