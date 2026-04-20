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

    // -----------------------------------------------------------------------
    // Batched scan / smoke-test run controls
    // -----------------------------------------------------------------------

    // Number of symbols to process per cron tick / per tickBatch() call.
    // Reduce if each symbol takes long; increase if symbols are fast.
    'batch_size'            => 20,

    // Hard cap on how many symbols will be scanned in a single queued run.
    // 0 = unlimited (use the full universe).
    'max_symbols_per_run'   => 0,

    // Abort a cron tick after this many seconds, saving progress for next tick.
    // Should be safely below your cron interval and HTTP timeout.
    'max_runtime_seconds'   => 55,

    // Signal freshness: H4 bar-based TTL (primary freshness gate).
    // A signal whose source level is older than this many H4 bars is rejected
    // during scanning and will not enter the active signal set.
    // Fish runs on H4; 2 bars = 8 hours.  Adjust up for large universes where
    // a full scan cycle takes more than 1–2 bars to complete.
    'signal_ttl_bars'       => 2,

    // Hard cap: only this many active signals per symbol+side are kept in the
    // final active signal set.  Excess signals are rejected as duplicates.
    // Set to 1 to enforce strict uniqueness (recommended for v1).
    'max_active_signals_per_symbol_side' => 1,

    // Signal lifetime in minutes (safety-net cross-cycle expiry).
    // Signals that are never re-seen by the scanner are evicted after this
    // duration.  Derived automatically when 0: signal_ttl_bars * 240 min.
    'signal_ttl_minutes'    => 0,

    // -----------------------------------------------------------------------
    // Risk / reward geometry validation
    // -----------------------------------------------------------------------

    // Minimum acceptable reward/risk ratio for a signal to be emitted.
    // Signals with RR below this threshold are rejected as geometry-invalid.
    'min_rr_ratio'          => 2.0,

    // -----------------------------------------------------------------------
    // Fish Bot — execution runtime config
    // -----------------------------------------------------------------------

    // Master on/off switch for the Fish bot execution layer.
    // When false the bot tick is a no-op; scanning / signal generation still runs.
    'bot_enabled'             => false,

    // Execution mode: smoke | demo | live
    //   smoke — log-only, no orders sent, safe for testing
    //   demo  — send orders to Bybit testnet (not supported yet; falls back to smoke)
    //   live  — send real orders to Bybit mainnet (only when explicitly set)
    'execution_mode'          => 'smoke',

    // Hard caps
    'max_active_orders'       => 5,
    'max_active_positions'    => 3,

    // Per-signal execution budget (base currency, e.g. USDT)
    'bot_budget'              => 0.0,

    // Leverage applied to each new position (1 = no leverage)
    'bot_leverage'            => 1,

    // Stop-loss profile name (resolved by sl_manager; 'default' = fixed initial stop)
    'bot_sl_profile'          => 'default',

    // Position-management profile name (resolved by pm_manager; 'default' = breakeven only)
    'bot_pm_profile'          => 'default',

    // Bybit KeyCenter account ID used for live order placement.
    // Must match an account stored in KeyCenter (Admin → KeyCenter).
    // Smoke/demo mode ignores this field.
    'account_id'              => '',
];
