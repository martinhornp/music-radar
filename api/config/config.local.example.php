<?php

// Copy to config.local.php and fill in. Only include the keys you want to
// override - everything else falls back to config.php.
// config.local.php is git-ignored and never part of the deploy package.
return [
    'debug' => false,
    'db' => [
        'host' => 'your-db-host.mysql.dk',
        'port' => 3306,
        'name' => 'your_database',
        'user' => 'your_user',
        'password' => 'change-me',
    ],
    'lastfm' => [
        // From https://www.last.fm/api/accounts
        'api_key' => 'your-32-character-lastfm-api-key',
    ],
];
