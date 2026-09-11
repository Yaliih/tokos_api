<?php

// config/cors.php
// return [
//     'paths' => ['api/*', '/csrf-cookie'],
//     'allowed_methods' => ['*'],
//     'allowed_origins' => [
//         'http://localhost:3001',
//         'http://127.0.0.1:3001', // ⭐ AJOUTER 127.0.0.1
//         'http://localhost:3000',
//         'http://127.0.0.1:3000',
//     ],
//     'allowed_origins_patterns' => [],
//     'allowed_headers' => ['*'],
//     'exposed_headers' => [],
//     'max_age' => 0,
//     'supports_credentials' => true,
// ];
// config/cors.php - Version développement
return [

'paths' => ['api/*', 'sanctum/csrf-cookie'],
'allowed_methods' => ['*'],
'allowed_origins' => ['http://localhost:3000','http://10.106.67.193:3000'],
'allowed_origins_patterns' => [],
'allowed_headers' => ['*'],
'exposed_headers' => [],
'max_age' => 0,
'supports_credentials' => true, // ✅ Important
];