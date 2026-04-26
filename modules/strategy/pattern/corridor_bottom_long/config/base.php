<?php

declare(strict_types=1);

/**
 * Corridor Bottom Long — Base Config
 *
 * 24h corridor bottom reversal strategy.
 * Detection-only draft; NOT connected to cron or trading.
 * Override individual values in active.php without touching this file.
 */

return [
    // ── Identity ────────────────────────────────────────────────────────────
    'strategy_id' => 'corridor_bottom_long',
    'enabled'     => false,       // must remain false until fully wired
    'mode'        => 'demo_only', // demo_only | passive | active | disabled

    // ── Universe ─────────────────────────────────────────────────────────────
    'universe_mode'    => 'all',  // all | manual_list
    'allowed_symbols'  => [],
    'excluded_symbols' => [],
    'max_symbols_per_run' => 0,   // 0 = no limit

    // ── Corridor ─────────────────────────────────────────────────────────────
    'corridor_lookback_minutes' => 1440, // 24 h
    'max_distance_from_low_pct' => 15,   // % — price must be within 15% of corridor low

    // ── Validation window ────────────────────────────────────────────────────
    'validation_window_seconds' => 240, // 4 min — wait before evaluating a candidate
    'validation_min_score'      => 3,   // minimum score to emit a signal

    // ── Accumulation / exhaustion ────────────────────────────────────────────
    'allow_new_low'  => false, // reject candidate immediately on new low
    'max_new_low_pct' => 0.2,  // % tolerance when allow_new_low = true

    // ── Candle data (Bybit 1m) ───────────────────────────────────────────────
    'kline_interval'    => '1',   // 1-minute bars
    'lookback_candles'  => 1440,  // 1440 × 1 min = 24 h
    'bybit_base_url'    => 'https://api.bybit.com',
    'bybit_timeout_sec' => 10,

    // ── Runtime controls ─────────────────────────────────────────────────────
    'batch_size'          => 50,
    'max_runtime_seconds' => 55,
];
