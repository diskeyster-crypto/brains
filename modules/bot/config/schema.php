<?php

declare(strict_types=1);

/**
 * Bot Module — Config Schema
 *
 * Used by BotBootstrap to validate config on every startup.
 */

return [
    // Core identity
    'bot_id'  => 'string',
    'enabled' => 'bool',
    'mode'    => 'string',

    // Strategy autodiscovery
    'strategy_scan_roots' => 'array',
    'strategy_scan_depth' => 'int',

    // Entry mode filter
    'allowed_entry_modes' => 'array',

    // Freshness
    'max_signal_age_sec' => 'int',

    // Dedup
    'queue_dedup_ttl_sec' => 'int',

    // Budget / leverage caps
    'max_bot_budget'   => 'float',
    'max_bot_leverage' => 'int',

    // Cron
    'tick_interval_sec'   => 'int',
    'max_runtime_seconds' => 'int',

    // Continuous
    'continuous_enabled' => 'bool',
];
