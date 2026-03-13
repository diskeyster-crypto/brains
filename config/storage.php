<?php

declare(strict_types=1);

return [
    'backend' => 'json',
    
    'json' => [
        'path' => null,
    ],
    
    'sqlite' => [
        'path' => null,
    ],
    
    'mysql' => [
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'tredercopis',
        'username' => 'root',
        'password' => '',
        'table' => 'storage',
    ],
];
