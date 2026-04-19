<?php

declare(strict_types=1);

/**
 * Fish Strategy — Base Config
 *
 * All working parameter defaults live here.
 * Override individual values in active.php without touching this file.
 * Do NOT put inline logic or hardcoded trading thresholds here — only values.
 */

return [
    // Core identity
    'strategy_id'           => 'fish',
    'enabled'               => false,
    'mode'                  => 'passive',  // active | passive | disabled

    // Market parameters
    'market_type'           => 'perp',
    'universe_mode'         => 'manual',
    'timeframe'             => '1h',

    // Profile references — resolved by the executing layer (not implemented yet)
    'budget_profile'        => 'default',
    'leverage_profile'      => 'conservative',
    'sl_profile'            => 'default',
    'pm_profile'            => 'default',

    // Signal quality gate: reject signals older than this many seconds
    'max_signal_age'        => 3600,

    // Ownership contract (placeholders — execution not wired yet)
    'owner_strategy'        => 'fish',
    'order_owner_prefix'    => 'fish_',
    'position_owner_prefix' => 'fish_pos_',

    // Feature flags
    'feature_flags'         => [
        'dry_run'               => true,   // No live orders while true
        'shadow_mode'           => false,
        'execution_enabled'     => false,  // Master gate — stays off until wired
        'scan_enabled'          => false,  // Market scanning not implemented yet
    ],
];
