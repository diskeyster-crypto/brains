<?php

declare(strict_types=1);

/**
 * Fish Strategy — Config Schema (v1)
 *
 * Defines allowed config keys and their expected PHP types.
 * Used by FishBootstrap to validate config on every startup.
 * Adding a new key here is mandatory before using it anywhere in logic.
 */

return [
    // Core identity
    'strategy_id'      => 'string',
    'enabled'          => 'bool',
    'mode'             => 'string',   // active | passive | disabled

    // Timeframe
    'timeframe'        => 'string',   // H4

    // Universe — v1 supports: all | manual_list
    'universe_mode'    => 'string',
    'allowed_symbols'  => 'array',    // used when universe_mode = manual_list
    'excluded_symbols' => 'array',    // always applied

    // Trading window (single window only in v1)
    'window_enabled'   => 'bool',
    'window_start'     => 'string',   // HH:MM
    'window_end'       => 'string',   // HH:MM

    // Execution parameters
    'budget'           => 'int',
    'leverage'         => 'int',

    // Profile references
    'sl_profile'       => 'string',
    'pm_profile'       => 'string',

    // Ownership contract
    'owner_strategy'   => 'string',
];
