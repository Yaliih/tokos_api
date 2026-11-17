<?php

// Villes proposées sur le profil (on peut aussi en écrire une autre). Sert au classement par ville
// et aux compétitions réservées à une ville.
return [
    'cities' => [
        'Kinshasa', 'Lubumbashi', 'Mbuji-Mayi', 'Kisangani', 'Goma', 'Bukavu', 'Kananga', 'Kolwezi', 'Likasi',
        'Matadi', 'Boma', 'Mbandaka', 'Kikwit', 'Tshikapa', 'Uvira', 'Butembo', 'Beni', 'Bunia', 'Kindu',
        'Kalemie', 'Isiro', 'Gemena', 'Gbadolite', 'Kamina', 'Kenge', 'Bandundu', 'Lisala', 'Lusambo', 'Mwene-Ditu',
        'Kabinda', 'Inongo', 'Moanda', 'Mbanza-Ngungu', 'Kisantu', 'Kimpese', 'Kasangulu', 'Lukala', 'Tshela', 'Luozi', 'Songololo', 'Seke-Banza', 'Madimba', 'Zongo', 'Bumba', 'Buta', 'Aru', 'Kasongo', 'Kipushi', 'Kasumbalesa',
        'Brazzaville', 'Pointe-Noire', 'Luanda', 'Kigali', 'Bujumbura', 'Paris', 'Bruxelles',
    ],

    // Position du téléphone → ville. D'abord OpenStreetMap (Nominatim, gratuit, une requête par vérification),
    // sinon la ville la plus proche ci-dessous : [latitude, longitude, rayon de la ville en km].
    'geocoder' => env('GEOCODER', env('LOCATIONIQ_KEY') ? 'locationiq' : 'nominatim'), // locationiq | nominatim | local
    // Recherche de lieux par l'organisateur (zone d'une compétition) : LocationIQ si une clé est donnée, sinon Photon (gratuit, sans clé)
    'locationiq_key' => env('LOCATIONIQ_KEY'),
    'search_provider' => env('PLACE_SEARCH', env('LOCATIONIQ_KEY') ? 'locationiq' : 'photon'),
    'photon_url' => env('PHOTON_URL', 'https://photon.komoot.io/api/'),
    // Marge autour de la zone (km) pour accepter quelqu'un au bord, en plus de la précision de sa position
    'zone_margin_km' => (float) env('ZONE_MARGIN_KM', 2),
    'nominatim_url' => env('NOMINATIM_URL', 'https://nominatim.openstreetmap.org/reverse'),
    // Une position vérifiée reste valable ce nombre d'heures (ensuite on redemande)
    'located_valid_hours' => (int) env('LOCATION_VALID_HOURS', 72),
    // Pour voter ou participer dans une compétition réservée à une ville : position prise il y a moins de N minutes
    'action_valid_minutes' => (int) env('LOCATION_ACTION_MINUTES', 15),
    // Précision minimale (mètres). Au-delà, la position vient de la connexion internet et non de l'appareil
    // (GPS, Wi-Fi) : on la refuse et on demande d'activer la localisation.
    'max_accuracy_m' => (int) env('LOCATION_MAX_ACCURACY', 1500),
    // Au-delà de cette distance de toute ville connue, on ne devine pas
    'max_km' => 80,

    // Pays de chaque ville du tableau ci-dessous (code ISO) et noms affichés
    'countries' => [
        'CD' => 'RD Congo', 'CG' => 'Congo-Brazzaville', 'AO' => 'Angola', 'RW' => 'Rwanda', 'BI' => 'Burundi',
        'FR' => 'France', 'BE' => 'Belgique', 'UG' => 'Ouganda', 'TZ' => 'Tanzanie', 'ZM' => 'Zambie', 'CF' => 'Centrafrique',
        'CM' => 'Cameroun', 'GA' => 'Gabon', 'SS' => 'Soudan du Sud', 'ZA' => 'Afrique du Sud',
    ],
    'city_country' => [
        'Brazzaville' => 'CG', 'Pointe-Noire' => 'CG', 'Luanda' => 'AO', 'Kigali' => 'RW', 'Bujumbura' => 'BI',
        'Paris' => 'FR', 'Bruxelles' => 'BE',
        // toutes les autres villes du tableau sont en RD Congo
    ],
    // Sans ville reconnue, on donne au moins le pays de la ville connue la plus proche, jusqu'à cette distance
    'country_max_km' => 450,

    'coords' => [
        'Kinshasa' => [-4.325, 15.322, 35], 'Lubumbashi' => [-11.664, 27.479, 20], 'Mbuji-Mayi' => [-6.136, 23.590, 15],
        'Kisangani' => [0.515, 25.191, 15], 'Goma' => [-1.679, 29.222, 12], 'Bukavu' => [-2.508, 28.861, 12],
        'Kananga' => [-5.896, 22.417, 15], 'Kolwezi' => [-10.716, 25.472, 15], 'Likasi' => [-10.981, 26.733, 12],
        'Matadi' => [-5.817, 13.450, 10], 'Boma' => [-5.854, 13.056, 10], 'Mbandaka' => [0.048, 18.260, 10],
        'Kikwit' => [-5.041, 18.816, 10], 'Tshikapa' => [-6.416, 20.800, 10], 'Uvira' => [-3.395, 29.138, 10],
        'Butembo' => [0.142, 29.291, 10], 'Beni' => [0.491, 29.473, 10], 'Bunia' => [1.566, 30.252, 10],
        'Kindu' => [-2.944, 25.923, 10], 'Kalemie' => [-5.947, 29.194, 10], 'Isiro' => [2.776, 27.616, 10],
        'Gemena' => [3.257, 19.772, 10], 'Gbadolite' => [4.279, 21.003, 10], 'Kamina' => [-8.736, 24.991, 10],
        'Kenge' => [-4.806, 16.900, 8], 'Bandundu' => [-3.316, 17.380, 8], 'Lisala' => [2.149, 21.513, 8],
        'Lusambo' => [-4.973, 23.444, 8], 'Mwene-Ditu' => [-7.000, 23.450, 8], 'Kabinda' => [-6.138, 24.481, 8],
        'Inongo' => [-1.955, 18.272, 8], 'Moanda' => [-5.922, 12.355, 8],
        'Mbanza-Ngungu' => [-5.254, 14.866, 8], 'Kisantu' => [-5.128, 15.100, 6], 'Kimpese' => [-5.556, 14.433, 6],
        'Kasangulu' => [-4.591, 15.170, 6], 'Lukala' => [-5.512, 14.533, 5], 'Tshela' => [-4.977, 12.933, 6],
        'Luozi' => [-4.950, 14.133, 5], 'Songololo' => [-5.700, 14.033, 5], 'Seke-Banza' => [-5.333, 13.267, 5], 'Madimba' => [-4.967, 15.133, 5], 'Zongo' => [4.343, 18.594, 6],
        'Bumba' => [2.184, 22.468, 8], 'Buta' => [2.794, 24.733, 8], 'Aru' => [2.866, 30.835, 6],
        'Kasongo' => [-4.450, 26.666, 8], 'Kipushi' => [-11.761, 27.250, 8], 'Kasumbalesa' => [-12.264, 27.800, 6],
        'Brazzaville' => [-4.263, 15.243, 8], 'Pointe-Noire' => [-4.778, 11.866, 12], 'Luanda' => [-8.839, 13.289, 25],
        'Kigali' => [-1.944, 30.062, 15], 'Bujumbura' => [-3.383, 29.364, 12], 'Paris' => [48.857, 2.352, 20],
        'Bruxelles' => [50.847, 4.357, 15],
    ],
];
