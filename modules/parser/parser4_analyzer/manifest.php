<?php
/**
 * Parser 4: Analyzer — Manifest
 *
 * Pure analytical module. Reads Parser2 history + Parser3 symbols,
 * calculates metrics, outputs classification (blocklist/watchlist/candidates).
 *
 * @package Modules\Parser4Analyzer
 */
return [
    'name' => 'parser4_analyzer',
    'version' => '1.0.0',
    'description' => 'Parser4: Analyzer. Reads Parser2 price history + Parser3 symbol list, calculates rolling metrics, outputs blocklist/watchlist/candidates. JSON-only.',
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
