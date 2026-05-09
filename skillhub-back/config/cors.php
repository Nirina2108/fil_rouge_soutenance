<?php

return [
    // Préfixes d'URL auxquels la politique CORS s'applique (pas /web, juste l'API).
    'paths' => ['api/*'],
    // Méthodes HTTP autorisées par les preflights OPTIONS.
    'allowed_methods' => ['*'],

    // Whitelist des origines autorisées. Inclus les deux variantes (localhost ET 127.0.0.1)
    // car selon la barre d'adresse du navigateur de l'utilisateur, l'une ou l'autre est
    // utilisée. Sans les deux, on a un mismatch entre Origin reçu et Allow-Origin renvoyé.
    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ],

    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
