<?php
/**
 * Copytrading Parser — Manifest
 *
 * Parses trader positions and history from Bybit copytrading.
 *
 * @package Modules\Copytrading
 */
return [
    'name' => 'copytrading',
    'version' => '1.0.0',
    'description' => 'Copytrading Parser: Fetches trader positions and trade history from Bybit copytrading API.',
    'entry' => 'runner.php',
    'requires' => [
        'core' => '>=1.0.0',
    ],
    'author' => 'Tredercopis',
    'cron' => [
        'execute' => [
            'handler' => 'service.php',
            'schedule' => '1800', // 30 minutes
        ],
    ],
    'routes' => [],
];

/* RULES
- Metadata only (return array)
- No executable logic here
*/
