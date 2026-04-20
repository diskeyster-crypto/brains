<?php

declare(strict_types=1);

/**
 * Fish Strategy — Base Config (v1)
 *
 * All working parameter defaults live here.
 * Override individual values in active.php without touching this file.
 * Do NOT put inline logic or hardcoded trading thresholds here — only values.
 *
 * Universe modes supported in v1:
 *   all         — use every active symbol from the market registry
 *   manual_list — use only symbols listed in `allowed_symbols`
 *
 * `excluded_symbols` applies in both modes.
 */

return [
    // Core identity
    'strategy_id'      => 'fish',
    'enabled'          => false,
    'mode'             => 'passive',  // active | passive | disabled

    // Timeframe — Рыбалка runs on H4
    'timeframe'        => 'H4',

    // Universe selection — v1 only supports: all | manual_list
    'universe_mode'    => 'all',
    'allowed_symbols'  => [],  // used when universe_mode = manual_list
    'excluded_symbols' => [],  // always applied regardless of mode

    // Trading window — single window only in v1
    'window_enabled'   => false,
    'window_start'     => '08:00',
    'window_end'       => '22:00',

    // Execution parameters — flat values, not profile references
    'budget'           => 0,
    'leverage'         => 1,

    // Profile references — resolved by the executing layer (not implemented yet)
    'sl_profile'       => 'default',
    'pm_profile'       => 'default',

    // Ownership contract (placeholder — execution not wired yet)
    'owner_strategy'   => 'fish',
];
