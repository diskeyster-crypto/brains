<?php

declare(strict_types=1);

/**
 * Stop Manager Module — Base Config
 *
 * Stop Manager is the sole owner of stop-loss computation.
 * Strategy modules provide signal profile references only.
 * Bot module provides position state only.
 * This module owns the stop math.
 *
 * Override individual values in active.php without touching this file.
 *
 * Stop modes:
 *   entry_liq_percent — stop placed above/below liquidation price by a
 *                       configured fraction of the entry↔liq distance.
 *
 *   For long:  stop = liq_price + buffer_pct * (entry_price - liq_price)
 *   For short: stop = liq_price - buffer_pct * (liq_price - entry_price)
 *
 * Execution modes:
 *   disabled — initialize storage only, no stop computation
 *   demo     — local stop computation for Bybit Demo positions (same math as paper)
 *   paper    — local stop computation for paper positions (legacy)
 */

return [
    // Core identity
    'module_id' => 'stop_manager',
    'enabled'   => false,
    'mode'      => 'disabled',  // disabled | paper | demo
                                // demo     = local stop computation for Bybit Demo positions (same math as paper)
                                // paper    = local stop computation for paper positions (legacy)
                                // disabled = initialize storage only, no stop computation

    // Stop computation
    'stop_mode'                => 'entry_liq_percent',
    'stop_from_liq_buffer_pct' => 0.05,  // fraction of entry↔liq distance above/below liq

    // Breakeven / profit-lock rule
    'breakeven_enabled'          => false,
    'breakeven_trigger_roi'      => 10.0,  // ROI% that triggers the shift
    'breakeven_profit_lock_roi'  => 3.0,   // ROI% locked after shift

    // Bot positions source path (relative to repo root)
    'bot_positions_path' => 'modules/bot/storage/active_positions.json',

    // Cron / batch
    'tick_interval_sec'   => 60,
    'max_runtime_seconds' => 25,
];
