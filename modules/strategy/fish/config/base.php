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
    'budget'           => 0.0,  // float — supports fractional amounts
    'leverage'         => 1,

    // Profile references — resolved by the executing layer (not implemented yet)
    'sl_profile'       => 'default',
    'pm_profile'       => 'default',

    // Ownership contract (placeholder — execution not wired yet)
    'owner_strategy'   => 'fish',

    // -----------------------------------------------------------------------
    // Strategy-specific logic parameters (Рыбалка rules)
    // -----------------------------------------------------------------------

    // Structure detection: number of bars on each side required to confirm a swing extremum
    'structure_pivot_window'          => 3,

    // Liquidity level: accepted range of consecutive consolidation bars
    'liquidity_pattern_min_bars'      => 3,
    'liquidity_pattern_max_bars'      => 4,

    // Liquidity level: maximum body range as a fraction of price (0.003 = 0.3%)
    // All bar opens/closes must fit within this fraction of the level midpoint
    'liquidity_level_tolerance'       => 0.003,

    // Confirming bar: require the bar immediately after the consolidation
    // to close outside the consolidation range before the level is valid
    'confirm_bar_required'            => true,

    // Level lifetime: a level expires after this many H4 bars have elapsed
    // since the confirming bar (or last consolidation bar if confirm not required)
    'level_max_age_bars'              => 20,

    // Take profit: distance from entry = (liquidity pattern range) * tp_multiplier
    'tp_multiplier'                   => 4.0,

    // Breakeven trigger: distance from entry = (liquidity pattern range) * breakeven_trigger_multiplier
    'breakeven_trigger_multiplier'    => 1.0,

    // Candle data: how many H4 bars to fetch per symbol for analysis
    'lookback_candles'                => 100,

    // Candle data: Bybit API settings
    'bybit_base_url'                  => 'https://api.bybit.com',
    'bybit_timeout_sec'               => 10,
];
