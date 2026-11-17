<?php

// Modèles du petit site d'un Espace (lions.sosa.social). Chaque modèle = resources/views/sites/{clé}.blade.php
return [
    'default' => 'classique',
    'templates' => [
        'classique' => ['label' => 'Classique', 'help' => 'Clair, la photo de couverture en grand, puis les compétitions.'],
        'affiche' => ['label' => 'Affiche', 'help' => 'Sombre, le nom en très grand, comme une affiche de spectacle.'],
    ],
];
