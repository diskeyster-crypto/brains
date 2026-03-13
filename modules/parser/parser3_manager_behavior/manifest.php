<?php
/**
 * Manager v2: Behavior Profiler — Manifest
 *
 * @package Modules\ManagerBehavior
 */
return [
    'name' => 'manager_behavior',
    'version' => '2.0.0',
    'description' => 'Manager v2: behavior profiler. Reads Parser1 active registry + Parser2 RAW history (NDJSON) and writes class/tags/confidence profiles (NO prices). JSON-only.',
    'entry' => 'runner.php',
    'requires' => [
        'core' => '>=1.0.0',
    ],
    'author' => 'Tredercopis',
    'cron' => [
        'execute' => [
            'handler' => 'service.php',
            'schedule' => '3600', // 1 hour
        ],
    ],
    'routes' => [],
];

/* RULES
- Metadata only (return array)
- No executable logic here
*/
