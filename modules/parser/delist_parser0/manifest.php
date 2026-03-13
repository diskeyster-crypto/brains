<?php
/**
 * Delist Parser 0 Manifest
 *
 * @package Modules\DelistParser0
 */
return [
    'name' => 'delist_parser0',
    'version' => '2.0.0',
    'description' => 'Parser 0: monitors Bybit announcements to detect new listings and delistings (whitelist/blacklist outputs)',
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
- Purpose: Module metadata for Delist Parser 0
- Config sources: This file is metadata only (not runtime config)
- Paths: All paths are relative to module directory
- Logs: None (metadata only)
- Prohibitions:
  - No executable logic here (return array only)
*/
