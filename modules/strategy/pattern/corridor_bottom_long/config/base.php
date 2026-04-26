<?php

declare(strict_types=1);

/**
 * Corridor Bottom Long — Base Config
 *
 * 24h corridor bottom reversal strategy (validation-based).
 * Draft mode. NOT connected to cron or trading.
 * Override individual values in active.php without touching this file.
 */

return [
    // ── Identity ─────────────────────────────────────────────────────────────
    'strategy_id'     => 'corridor_bottom_long',
    'enabled'         => false,  // must remain false until fully wired
    'mode'            => 'demo', // mode = demo; enabled=false controls off state
    'handoff_enabled' => false,  // set true when ready to send signals to Bot

    // ── Universe ──────────────────────────────────────────────────────────────
    'universe_mode'       => 'all', // all | manual_list
    'allowed_symbols'     => [],
    'excluded_symbols'    => [],
    'max_symbols_per_run' => 50,    // safer draft limit

    // ── Corridor ──────────────────────────────────────────────────────────────
    'corridor_lookback_minutes' => 1440, // 24 h
    'max_distance_from_low_pct' => 8,    // % — tighter zone: price ≤ 8% above corridor low

    // ── Validation window ─────────────────────────────────────────────────────
    'validation_window_seconds' => 240, // 4 min — wait before evaluating a candidate
    'validation_min_score'      => 3,   // minimum combined score to emit a signal (max 6)

    // ── Holding-low / new-low tolerance ──────────────────────────────────────
    'allow_new_low'   => false, // reject immediately on new low when false
    'max_new_low_pct' => 0.2,   // % tolerance when allow_new_low = true

    // ── Reversal patterns (checked ONLY inside corridor low zone) ────────────
    'enabled_patterns' => [
        'double_bottom',
        'reclaim_low',
        'higher_low',
        'engulfing_reversal',
    ],

    // ── Accumulation score ────────────────────────────────────────────────────
    'min_accumulation_score' => 2, // reject if accumulation_score < this value

    // ── Risk to corridor low ──────────────────────────────────────────────────
    'max_risk_to_low_roi' => 60,  // % max acceptable drawdown from entry to low
    'default_leverage'    => 5,   // fallback leverage when none in config

    // ── Candle data (Bybit 1m) ────────────────────────────────────────────────
    'kline_interval'    => '1',  // 1-minute bars
    'lookback_candles'  => 1440, // 1440 × 1 min = 24 h
    'bybit_base_url'    => 'https://api.bybit.com',
    'bybit_timeout_sec' => 10,

    // ── Runtime controls ──────────────────────────────────────────────────────
    'batch_size'          => 50,
    'max_runtime_seconds' => 55,

    // ── Handoff flood protection ──────────────────────────────────────────────
    'max_handoff_per_run' => 3,  // max new signals written to bot_handoff_queue per run
    'max_active_signals'  => 10, // max signals in signals.json eligible for handoff
];
