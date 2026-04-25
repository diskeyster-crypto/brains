<?php

declare(strict_types=1);

/**
 * Stop Manager Module — Config Schema
 *
 * Used by StopManagerBootstrap to validate config on every startup.
 */

return [
    // Core identity
    'module_id' => 'string',
    'enabled'   => 'bool',
    'mode'      => 'string',

    // Stop computation
    'stop_mode'            => 'string',
    'liq_distance_percent' => 'int',

    // Breakeven
    'breakeven_enabled'         => 'bool',
    'breakeven_trigger_roi'     => 'float',
    'breakeven_profit_lock_roi' => 'float',

    // Source
    'bot_positions_path' => 'string',

    // Cron
    'tick_interval_sec'   => 'int',
    'max_runtime_seconds' => 'int',
];
