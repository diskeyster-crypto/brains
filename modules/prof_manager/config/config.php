<?php

declare(strict_types=1);

return [
    'enabled'                  => false,
    'mode'                     => 'demo',
    'active_profile'           => 'auto',
    'paper_position_ttl_hours' => 6,

    // ── Fast loop runner (public/cron/prof_manager_fast_loop.php) ─────────
    // ISP cron calls the endpoint once per minute; the loop internally runs
    // lightweight PM fast ticks every fast_loop_interval_seconds.
    'fast_loop_enabled'                            => true,
    'fast_loop_interval_seconds'                   => 15,
    'fast_loop_max_runtime_seconds'                => 55,
    'fast_loop_overlap_guard_seconds'              => 70,
    'fast_loop_max_ticks_per_run'                  => 4,

    // ── Fast tick controls ────────────────────────────────────────────────
    'fast_tick_enabled'                            => true,
    'fast_tick_allow_close'                        => true,
    'fast_tick_allow_lock_move'                    => true,
    'fast_tick_max_positions_per_run'              => 20,
    'fast_tick_min_lock_update_interval_seconds'   => 15,
    'fast_tick_min_close_retry_interval_seconds'   => 15,
    'parser_metrics_fast_cache_ttl_seconds'        => 60,
    'impulse_metrics_fast_cache_ttl_seconds'       => 60,

    // ── PM exchange profit-floor sync ─────────────────────────────────────
    //
    // When enabled, PM computes a safety stopLoss price from its virtual lock
    // and sets it on the exchange via the Bybit trading-stop API.
    //
    // The exchange stopLoss is a SAFETY FLOOR ONLY — it is NOT the primary exit.
    // PM virtual lock logic (impulse hold, grace, staircase, chop) remains primary.
    //
    // Safety defaults:
    //   - disabled by default
    //   - live disabled by default
    //   - min_roi prevents setting a stop below entry (only in profit)
    //
    // pm_exchange_profit_floor_sync_enabled:
    //   false (default) — feature disabled; no exchange stopLoss set by PM.
    //   true             — PM computes and sets exchange stopLoss from virtual lock.
    //
    // pm_exchange_profit_floor_modes:
    //   array of allowed modes, e.g. ['demo'] or ['demo', 'live'].
    //   Checked alongside pm_exchange_profit_floor_live_enabled for live.
    //
    // pm_exchange_profit_floor_live_enabled:
    //   false (default, safety gate) — never set exchange stopLoss on live accounts.
    //   true — allow PM to set exchange stopLoss on live positions (dangerous).
    //
    // pm_exchange_profit_floor_min_roi:
    //   Minimum virtual lock ROI% before the PM exchange floor is applied.
    //   Exchange stopLoss will not be set if virtual_lock_roi < this value.
    //
    // pm_exchange_profit_floor_buffer_roi:
    //   ROI% buffer applied below the virtual lock: exchange_floor_roi = lock_roi - buffer.
    //   Prevents the exchange stop from sitting exactly at the PM lock price.
    //
    // pm_exchange_profit_floor_min_update_interval_seconds:
    //   Minimum seconds between successive exchange stopLoss updates per position.
    //   Prevents excessive API calls on every tick.
    //
    // pm_exchange_profit_floor_min_improvement_roi:
    //   Only update exchange stopLoss if the new floor is at least this ROI% better
    //   than the currently set exchange floor. Avoids trivial updates.
    //
    // pm_exchange_profit_floor_use_mark_price:
    //   true (default) — use MarkPrice as slTriggerBy when setting exchange stopLoss.
    //   false           — use LastPrice.
    'pm_exchange_profit_floor_sync_enabled'              => false,
    'pm_exchange_profit_floor_modes'                     => ['demo'],
    'pm_exchange_profit_floor_live_enabled'              => false,
    'pm_exchange_profit_floor_min_roi'                   => 8.0,
    'pm_exchange_profit_floor_buffer_roi'                => 3.0,
    'pm_exchange_profit_floor_min_update_interval_seconds' => 30,
    'pm_exchange_profit_floor_min_improvement_roi'       => 2.0,
    'pm_exchange_profit_floor_use_mark_price'            => true,

    // ── OrderBook Wall Exit ────────────────────────────────────────────────────
    //
    // Passed as overrides to OrderBookContextService when it is instantiated.
    // These control the PM wall-exit behaviour: close or tighten the profit lock
    // when a profitable position approaches a persistent, unbroken opposing wall.
    //
    //   Long  position + ask wall above → ask_wall_rejection_profit_exit
    //   Short position + bid wall below → bid_wall_rejection_profit_exit
    //
    // wall_exit_enabled:
    //   true  — PM wall-exit checks are active (default).
    //   false — feature disabled; walls have no effect on PM exits.
    //
    // wall_exit_min_roi:
    //   Minimum position ROI% required before a wall-exit can trigger.
    //   Prevents premature exits on barely-profitable positions.
    //
    // wall_exit_distance_pct:
    //   Wall must be within this % of current price to be considered "near".
    //
    // wall_exit_fail_checks:
    //   Number of consecutive ticks price must have failed to break the wall
    //   before the exit is triggered.
    //
    // wall_exit_action:
    //   'close_or_tighten' — close if wall rejection confirmed, else tighten lock.
    //   'close'            — always close on wall rejection.
    //   'tighten'          — always tighten lock (never force-close).
    //
    // wall_exit_tighten_lock_buffer_roi:
    //   When tightening: new lock ROI = current_roi - this buffer.
    'wall_exit_enabled'                 => true,
    'wall_exit_min_roi'                 => 6.0,
    'wall_exit_distance_pct'            => 0.6,
    'wall_exit_fail_checks'             => 2,
    'wall_exit_action'                  => 'close_or_tighten',
    'wall_exit_tighten_lock_buffer_roi' => 2.0,
];
