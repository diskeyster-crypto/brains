<?php

declare(strict_types=1);

/**
 * Pattern Strategy — Base Config
 *
 * All working parameter defaults live here.
 * Override individual values in active.php without touching this file.
 * Do NOT put inline logic or hardcoded trading thresholds here — only values.
 */

return [
    // Core identity
    'strategy_id' => 'pattern',
    'enabled'     => false,
    'mode'        => 'passive',   // active | passive | disabled | smoke_demo
    'timeframe'   => 'H4',

    // Universe selection
    'universe_mode'    => 'all',
    'allowed_symbols'  => [],
    'excluded_symbols' => [],

    // Side mode — controls which pattern directions are eligible
    'side_mode' => 'both',        // long_only | short_only | both

    // Market regime gate
    'market_regime_enabled'   => true,
    'market_regime_gate_mode' => 'soft',  // soft = warn only; hard = block signal
    // Market regime classification thresholds
    'market_regime_min_sample_count'        => 5,    // min symbols required for non-unknown classification
    'market_regime_dominance_ratio'         => 0.55, // bull/bear ratio needed for dominance
    'market_regime_flat_dominance_ratio'    => 0.65, // flat ratio needed for flat dominance
    'market_regime_transition_flip_threshold' => 0.20, // how far below dominance a direction must drop to trigger transition

    // Trend gate
    'trend_required' => true,

    // Corridor
    'corridor_required'       => true,
    'corridor_lookback_hours' => 24,
    'corridor_bucket_count'   => 10,
    'allowed_long_buckets'    => [1, 2, 3],
    'allowed_short_buckets'   => [8, 9, 10],

    // Wave
    'wave_required' => true,

    // Patterns enabled
    'enabled_patterns' => ['double_bottom', 'double_top'],

    // Candidate quality filter
    // Candidates whose composite quality score is below this threshold are
    // rejected before control confirmation AND at final active-signal selection.
    // Set to 0.0 to disable.
    // Raised from 0.45 → 0.55 → 0.58 → 0.62: 0.58 still passed all 4 current
    // candidates; 0.62 creates a real decisional cut without killing the whole flow.
    'min_candidate_quality_score' => 0.62,

    // Neckline floor — signals whose neckline_score is below this value are
    // purged from the final active signal set after winner selection.
    // neckline_score = depthRatio / DEPTH_SCALE; DEPTH_SCALE = 0.10.
    // 0.45 → requires ~4.5% pattern depth (neckline 4.5% above avg lows / below avg highs).
    // Raised from 0.40 → 0.45 to remove weak-neckline survivors (~0.42–0.44 range).
    // Set to 0.0 to disable.
    'min_neckline_score' => 0.45,

    // Pattern detection tolerances
    'pattern_similarity_tolerance'           => 0.07,  // global fallback (7%)
    'double_bottom_similarity_tolerance_pct' => 0.07,  // max % deviation between the two lows
    'double_bottom_min_neckline_bounce_pct'  => 0.005, // neckline must be >= 0.5% above avg low
    // Short path: widened to 10% to allow finding valid double-top pairs in trending markets
    // where peaks are not perfectly symmetric.
    'double_top_similarity_tolerance_pct'    => 0.10,  // max % deviation between the two highs
    'double_top_min_neckline_bounce_pct'     => 0.005, // neckline must be >= 0.5% below avg high

    // Neckline distance gate — current price must be within this % of the neckline to qualify.
    // Shared fallback: 0.02 = price may be up to 2% beyond the neckline.
    // Side-specific overrides allow tuning without changing the other path.
    // Long (double_bottom): 2% — price must be at or just above neckline (pre-breakout).
    // Short (double_top):   6% — allows price to have already broken down up to 6%; crypto H4
    //                            candles can close 3-6% below the neckline on the first breakdown.
    'neckline_distance_tolerance_pct'            => 0.02,
    'double_bottom_neckline_distance_tolerance_pct' => 0.02,
    'double_top_neckline_distance_tolerance_pct'    => 0.06,

    // Confirmation
    'confirm_required' => true,
    'confirm_mode'     => 'candle_confirmation',
    'confirm_max_bars' => 2,

    // Signal lifetime
    'signal_ttl_bars'                    => 2,
    'max_active_signals_per_symbol_side' => 1,

    // Stop / trailing
    'stop_mode'        => 'structure',
    'trailing_profile' => 'oldbot_soft',

    // Fibonacci extensions
    'fibo_enabled' => false,

    // Batching / scan run controls
    'batch_size'           => 20,
    'max_symbols_per_run'  => 0,
    'max_runtime_seconds'  => 55,

    // Candle data
    'lookback_candles'  => 120,
    'bybit_base_url'    => 'https://api.bybit.com',
    'bybit_timeout_sec' => 10,
];
