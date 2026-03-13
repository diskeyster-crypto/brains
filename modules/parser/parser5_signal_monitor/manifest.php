<?php
/**
 * Parser 5: Signal Monitor Engine — Manifest
 *
 * Monitors candidates from Parser4, tracks price movement,
 * publishes entry signals when conditions are met.
 *
 * @package Modules\Parser5SignalMonitor
 */
return [
    'name' => 'parser5_signal_monitor',
    'version' => '1.0.0',
    'description' => 'Parser5: Signal Monitor Engine. Takes candidates from Parser4, monitors price movement, publishes entry signals for Executor.',
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
