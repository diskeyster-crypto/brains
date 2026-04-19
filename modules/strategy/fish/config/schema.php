<?php

declare(strict_types=1);

/**
 * Fish Strategy — Config Schema
 *
 * Defines allowed config keys and their expected types.
 * Used by bootstrap and service to validate config on startup.
 * Adding a new config key here is mandatory before using it in logic.
 */

return [
    // Core identity
    'strategy_id'           => 'string',
    'enabled'               => 'bool',
    'mode'                  => 'string',   // active | passive | disabled

    // Market parameters
    'market_type'           => 'string',   // spot | futures | perp
    'universe_mode'         => 'string',   // manual | dynamic | whitelist
    'timeframe'             => 'string',   // e.g. 15m, 1h, 4h

    // Profile references (strings — resolved by the executing layer)
    'budget_profile'        => 'string',
    'leverage_profile'      => 'string',
    'sl_profile'            => 'string',
    'pm_profile'            => 'string',

    // Signal quality gate
    'max_signal_age'        => 'int',      // seconds

    // Ownership contract (used by execution layer — not implemented yet)
    'owner_strategy'        => 'string',
    'order_owner_prefix'    => 'string',
    'position_owner_prefix' => 'string',

    // Feature flags map (key => bool)
    'feature_flags'         => 'array',
];
