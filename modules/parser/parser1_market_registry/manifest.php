<?php
/**
 * Parser 1: Market Registry Manifest
 *
 * @package Modules\Parser1MarketRegistry
 */
return [
    'name' => 'parser1_market_registry',
    'version' => '1.0.0',
    'description' => 'Parser 1: fetches Bybit public instruments registry, applies Parser0 delist blacklist, and produces clean active market registry (JSON-only).',
    'entry' => 'runner.php',
    'requires' => [
        'core' => '>=1.0.0',
    ],
    'author' => 'Tredercopis',
    'cron' => [
        'execute' => [
            'handler' => 'service.php',
            'schedule' => '10800', // 3 hours
        ],
    ],
    'routes' => [],
];

/* RULES
- Purpose: Module metadata for Parser 1 Market Registry
- Config sources: This file is metadata only (not runtime config)
- Paths: All paths are relative to module directory
- Logs: None (metadata only)
- Prohibitions:
  - No executable logic here (return array only)
*/
