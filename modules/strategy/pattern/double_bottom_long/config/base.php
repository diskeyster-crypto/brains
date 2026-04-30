<?php

declare(strict_types=1);

/**
 * Double Bottom Long Strategy — Base Config
 *
 * Long-only module. All short-side parameters removed.
 * Override individual values in active.php without touching this file.
 */

return [
    // Core identity
    'strategy_id' => 'double_bottom_long',
    'enabled'     => false,
    'mode'        => 'passive',   // active | passive | disabled | smoke_demo
    'timeframe'   => 'H4',

    // Universe selection
    'universe_mode'    => 'all',
    'allowed_symbols'  => [],
    'excluded_symbols' => [],

    // Market regime gate
    'market_regime_enabled'   => true,
    'market_regime_gate_mode' => 'hard',  // soft = warn only; hard = block signal
    // Market regime classification thresholds
    'market_regime_min_sample_count'          => 5,
    'market_regime_dominance_ratio'           => 0.55,
    'market_regime_flat_dominance_ratio'      => 0.65,
    'market_regime_transition_flip_threshold' => 0.20,
    // How many symbols to use for regime classification (must be > trend SLOW_PERIOD=21)
    'regime_sample_size'                      => 50,
    // Number of candles to fetch per symbol when computing the regime
    'regime_sample_lookback_candles'          => 30,

    // Trend gate
    'trend_required'          => true,
    'trend_long_require_bullish' => true,  // long entries only when trend is bullish

    // Corridor
    'corridor_required'       => true,
    'corridor_lookback_hours' => 24,
    'corridor_bucket_count'   => 10,
    'allowed_long_buckets'    => [1, 2],   // bottom 20% of 24 h range only

    // Wave
    'wave_required' => true,

    // Patterns enabled — long-only: double_bottom only
    'enabled_patterns' => ['double_bottom'],

    // Candidate quality filter
    'min_candidate_quality_score' => 0.68,  // raised from 0.62 — reduces false entries

    // Neckline floor
    'min_neckline_score' => 0.55,  // raised from 0.45 — requires meaningful W-depth

    // Pattern detection tolerances (long / double_bottom)
    'pattern_similarity_tolerance'              => 0.05,   // tightened from 0.07
    'double_bottom_similarity_tolerance_pct'    => 0.05,   // tightened from 0.07 — cleaner patterns
    'double_bottom_min_neckline_bounce_pct'     => 0.015,  // raised from 0.005 — requires 1.5% W-depth

    // Neckline distance gate (long only)
    'neckline_distance_tolerance_pct'               => 0.02,
    'double_bottom_neckline_distance_tolerance_pct' => 0.02,

    // Confirmation
    'confirm_required' => true,
    'confirm_mode'     => 'candle_confirmation',
    'confirm_max_bars' => 2,

    // Signal lifetime
    'signal_ttl_bars'                    => 2,
    'max_active_signals_per_symbol_side' => 1,

    // Stop — fixed_from_liq_zone model (no live order execution yet)
    'stop_mode'                    => 'fixed_from_liq_zone',
    'stop_from_liq_buffer_value'   => 0.002,
    'stop_from_liq_buffer_type'    => 'percent',   // absolute | percent
    // Pattern-based SL: place stop this fraction below the lower of the two lows
    'stop_buffer_pct_below_lows'   => 0.005,  // 0.5% below the lowest low
    // Maximum allowed SL as % of entry; reject signals whose pattern SL is too wide
    'max_stop_loss_pct'            => 0.05,   // skip entries where SL > 5% from entry
    'reverse_pattern_close_enabled'=> false,
    'tp_enabled'                   => true,   // take-profit enabled
    'tp_mode'                      => 'fixed_r',   // fixed_r | fixed_price
    'tp_value'                     => 2.5,    // 2.5 R take-profit target

    // Bot execution parameters
    'bot_budget'   => 0.0,
    'bot_leverage' => 1,
    'entry_mode'   => 'limit',    // limit | market

    // Fibonacci extensions
    'fibo_enabled' => false,

    // Continuous scan
    'continuous_scan_enabled' => true,

    // Batching / scan run controls
    'batch_size'           => 50,
    'max_symbols_per_run'  => 0,

    // Coin trend context and entry quality gate
    'coin_trend_context_enabled'         => true,
    'trend_lookback_short_candles'       => 60,
    'trend_lookback_mid_candles'         => 240,
    'trend_lookback_long_candles'        => 720,

    'active_downtrend_block_enabled'     => true,
    'max_recent_lower_low_count'         => 2,
    'max_recent_down_slope_pct'          => -1.5,
    'max_recent_dump_15m_pct'            => 5.0,
    'max_recent_dump_1h_pct'             => 9.0,

    'post_dump_stabilization_enabled'           => true,
    'post_dump_min_drop_from_recent_high_pct'   => 4.0,
    'post_dump_max_drop_from_recent_high_pct'   => 25.0,
    'post_dump_lookback_candles'                => 240,

    'stabilization_min_bars'             => 12,
    'stabilization_max_range_width_pct'  => 4.0,
    'stabilization_max_down_slope_pct'   => 0.8,
    'stabilization_min_low_hold_bars'    => 6,
    'stabilization_allow_minor_low_break_pct' => 0.6,

    'flat_base_enabled'                  => true,
    'flat_base_lookback_candles'         => 48,
    'flat_base_max_width_pct'            => 3.5,
    'flat_base_min_touches'              => 2,
    'flat_base_volume_cooling_required'  => false,

    'support_resistance_enabled'         => true,
    'sr_lookback_candles'                => 120,
    'support_touch_tolerance_pct'        => 0.4,
    'resistance_touch_tolerance_pct'     => 0.4,
    'min_support_touches'                => 2,
    'min_resistance_touches'             => 1,

    'reclaim_after_flat_required'        => true,
    'reclaim_min_close_above_base_pct'   => 0.4,  // legacy alias
    'reclaim_min_close_above_level_pct'  => 0.4,
    'reclaim_confirm_bars'               => 2,
    'max_entry_distance_from_reclaim_pct' => 2.5,

    'bearish_reversal_exception_enabled'                   => true,
    'bearish_reversal_requires_post_dump_stabilization'    => true,
    'bearish_reversal_requires_flat_base'                  => true,
    'bearish_reversal_requires_reclaim'                    => true,

    'min_reversal_context_score'         => 7.0,
    'min_entry_context_score'            => 7.5,

    // ── Intraday double-bottom detection on entry-context candles ────────────
    // Detects: dump → bottom_1 → neckline bounce → bottom_2/low hold → reclaim
    'intraday_double_bottom_enabled'                     => true,
    'double_bottom_min_separation_bars'                  => 5,     // min bars between b1 and b2
    'double_bottom_max_separation_bars'                  => 80,    // max bars between b1 and b2
    'double_bottom_pivot_window'                         => 2,     // pivot low detection window (bars each side)
    'double_bottom_low_tolerance_pct'                    => 3.0,   // max % difference between the two lows
    'double_bottom_max_second_low_break_pct'             => 1.5,   // max % bottom_2 may be lower than bottom_1
    'double_bottom_neckline_min_bounce_pct'              => 1.5,   // min % neckline is above avg of two lows
    'double_bottom_reclaim_confirm_bars'                 => 2,     // min bars closing above neckline after b2
    'double_bottom_max_entry_distance_from_neckline_pct' => 3.0,   // max % last-close is above neckline

    // Entry context candles — separate short-timeframe feed for
    // dump detection / stabilization / flat-base / reclaim.
    // H4 candles are still used for pattern / trend / corridor / wave.
    'entry_context_enabled'                   => true,
    'entry_context_interval'                  => '1',    // Bybit kline interval (minutes)
    'entry_context_lookback_candles'          => 180,    // 3 h on 1m
    'entry_context_fallback_interval'         => '5',    // used if 1m fetch fails
    'entry_context_fallback_lookback_candles' => 180,    // 15 h on 5m

    'max_runtime_seconds'  => 55,

    // Candle data
    'lookback_candles'  => 120,
    'bybit_base_url'    => 'https://api.bybit.com',
    'bybit_timeout_sec' => 10,
];
