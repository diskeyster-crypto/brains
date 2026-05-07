<?php

declare(strict_types=1);

/**
 * Confirmed Continuation Strategy — Config Schema
 *
 * Bidirectional (long + short). Used by ConfirmedContinuationBootstrap
 * to validate the merged effective config on every startup.
 */

return [
    // Core identity
    'strategy_id' => 'string',
    'enabled'     => 'bool',
    'mode'        => 'string',
    'side_mode'   => 'string',

    // Execution gates
    'handoff_enabled' => 'bool',

    // Signal lifecycle
    'max_active_signals_per_symbol_side' => 'int',
    'signal_ttl_minutes'                 => 'int',

    // Universe / batching
    'batch_size'          => 'int',
    'max_symbols_per_run' => 'int',

    // Pattern quality gates
    'min_structure_score'                   => 'float',
    'min_candidate_quality_score'           => 'float',
    'min_higher_lows_long'                  => 'int',
    'min_lower_highs_short'                 => 'int',
    'max_entry_distance_from_structure_pct' => 'float',
    'max_pullback_depth_pct'                => 'float',
    'max_extension_from_structure_pct'      => 'float',
    'max_extension_from_local_base_pct'     => 'float',
    'max_1m_blowoff_pct'                    => 'float',
    'min_volume_persistence_score'          => 'float',

    // Pattern filters
    'require_retest'                    => 'bool',
    'require_structure_hold'            => 'bool',
    'require_continuation_after_retest' => 'bool',

    // OBC gate
    'orderbook_wall_gate_enabled'               => 'bool',
    'orderbook_wall_gate_mode'                  => 'string',
    'orderbook_wall_soft_demote_blocks_handoff' => 'bool',
    'orderbook_wall_fetch_after_score'          => 'float',
    'orderbook_wall_near_pct'                   => 'float',
    'orderbook_wall_persistent_required'        => 'bool',

    // Candle data
    'lookback_candles'  => 'int',
    'bybit_base_url'    => 'string',
    'bybit_timeout_sec' => 'int',
];
