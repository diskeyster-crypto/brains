<?php
/**
 * Parser 6: Simulator — Manifest
 *
 * Evaluates Parser5 signals using Bybit public API.
 * Classifies outcomes: OK / FAIL / EARLY / LATE / FLAT / NO_DATA
 *
 * @package Modules\Parser6Simulator
 */
return [
    'name' => 'parser6_simulator',
    'version' => '1.0.0',
    'description' => 'Parser6: Simulator. Reads Parser5 signals, fetches Bybit klines, evaluates outcomes.',
    'entry' => 'runner.php',
    'requires' => [
        'core' => '>=1.0.0',
    ],
    'author' => 'Tredercopis',
    'cron' => [
        'execute' => [
            'handler' => 'service.php',
            'schedule' => '60', // 1 minute
        ],
    ],
    'routes' => [],
];

/* RULES
- Metadata only (return array)
- No executable logic here
*/
