<?php

// config/cors.php

// config/cors.php - Version développement
return [

'paths' => ['api/*', 'sanctum/csrf-cookie'],
'allowed_methods' => ['*'],
// 'allowed_origins' => ['http://localhost:3000','http://10.158.41.206:3000'],
'allowed_origins' => ['http://localhost:3000','http://10.158.41.206:3000'],
// 'allowed_origins' => ['*'],
'allowed_origins_patterns' => [],
'allowed_headers' => ['*'],
'exposed_headers' => [],
'max_age' => 0,
'supports_credentials' => true, // ✅ Important
];