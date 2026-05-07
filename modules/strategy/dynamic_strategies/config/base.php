<?php

declare(strict_types=1);

/**
 * Dynamic Strategies — Base Config
 *
 * Standalone strategy-layer module that consumes useful rejected/failed/diagnostic
 * contexts from ordinary strategies (e.g. double_bottom_long) and generates
 * short-watch candidates and trading signals.
 *
 * Override individual values in active.php without touching this file.
 *
 * ARCHITECTURE: Strategies are environment-neutral. Execution mode (demo/live) is owned
 * by the Bot environment based on its own config. Strategy signals are just signals.
 * Keys mode/live_enabled/live_handoff_enabled are kept for backward compatibility only
 * and are NOT used as handoff execution gates.
 *
 * Observation-only mode: set handoff_enabled = false (no executable handoff).
 */

return [
    // ── Identity ─────────────────────────────────────────────────────────────
    'strategy_id'     => 'dynamic_strategies',
    'enabled'         => true,
    'mode'            => 'demo',   // deprecated execution gate — kept for backward compat; ignored by Bot
    'side_mode'       => 'short',   // short | long | all — filters which candidate sides are allowed

    // ── Execution gates ───────────────────────────────────────────────────────
    'handoff_enabled'          => true,   // true = write executable rows to bot_handoff_queue.json
    'emit_bot_handoff'         => true,   // combined gate: handoff_enabled AND emit_bot_handoff both required
    // Deprecated execution gates — kept for backward compat; NOT used for handoff gating.
    // Bot environment owns execution mode; see modules/bot/service.php.
    'live_enabled'             => false,  // deprecated; kept for backward compat
    'live_handoff_enabled'     => false,  // deprecated; kept for backward compat
    'live_requires_manual_enable'   => true,   // informational only; not enforced
    'live_requires_governor_later'  => true,   // informational only; not enforced

    // ── Backward-compat shadow flag (deprecated; kept false; not used as execution gate) ──
    'shadow_only'       => false,  // deprecated — use handoff_enabled=false for observation-only mode

    // ── Input contexts ────────────────────────────────────────────────────────
    'consume_input_contexts'   => true,
    'input_contexts_file'      => 'modules/strategy/dynamic_strategies/storage/input_contexts.json',
    'context_max_age_minutes'  => 180,
    'max_contexts_per_run'     => 100,

    // ── Output ───────────────────────────────────────────────────────────────
    'write_candidates'       => true,
    'write_rejects'          => true,
    'write_signals'          => true,
    'write_bot_handoff_queue' => true,

    // ── Signal quality gates ──────────────────────────────────────────────────
    // Per-mode thresholds (preferred keys)
    'min_confirmations_demo'                 => 3,
    'min_confidence_demo'                    => 0.65,
    'min_confirmations_live'                 => 4,
    'min_confidence_live'                    => 0.85,
    // Legacy aliases kept for backward compatibility
    'min_confirmations_for_shadow_candidate' => 2,
    'min_confirmations_for_demo_signal'      => 3,
    'min_confidence_for_demo_signal'         => 0.65,
    'min_confidence_for_live_signal'         => 0.85,

    // ── Dynamic rules enabled (flat booleans — backward-compat aliases) ──────
    'falling_knife_short_watch'              => true,
    'failed_reclaim_short_watch'             => true,
    'base_breakdown_short_watch'             => true,
    'failed_pending_breakdown_short_watch'   => true,
    'failed_long_after_entry_short_watch'    => true,
    'late_exhaustion_short_watch'            => true,

    // ── Per-rule config (preferred over flat global thresholds) ───────────────
    // strategy.php reads $config['rules'][$ruleId] first; falls back to global
    // min_confirmations_demo / min_confidence_demo etc. if a rule is missing here.
    'rules' => [
        'falling_knife_short_watch' => [
            'enabled'                => true,
            'side'                   => 'short',
            'min_confirmations_demo' => 3,
            'min_confidence_demo'    => 0.65,
            'min_confirmations_live' => 4,
            'min_confidence_live'    => 0.85,
        ],
        'failed_reclaim_short_watch' => [
            'enabled'                => true,
            'side'                   => 'short',
            'min_confirmations_demo' => 2,   // lower bar — reclaim-lost patterns are high-signal
            'min_confidence_demo'    => 0.65,
            'min_confirmations_live' => 4,
            'min_confidence_live'    => 0.85,
        ],
        'base_breakdown_short_watch' => [
            'enabled'                => true,
            'side'                   => 'short',
            'min_confirmations_demo' => 2,   // lower bar — base support broken is a strong directional signal
            'min_confidence_demo'    => 0.65,
            'min_confirmations_live' => 4,
            'min_confidence_live'    => 0.85,
        ],
        'failed_pending_breakdown_short_watch' => [
            'enabled'                => true,
            'side'                   => 'short',
            'min_confirmations_demo' => 3,
            'min_confidence_demo'    => 0.65,
            'min_confirmations_live' => 4,
            'min_confidence_live'    => 0.85,
        ],
        'failed_long_after_entry_short_watch' => [
            'enabled'                => true,
            'side'                   => 'short',
            'min_confirmations_demo' => 3,
            'min_confidence_demo'    => 0.68,  // slightly higher bar — requires more conviction
            'min_confirmations_live' => 4,
            'min_confidence_live'    => 0.88,
        ],
        'late_exhaustion_short_watch' => [
            'enabled'                => true,
            'side'                   => 'short',
            'min_confirmations_demo' => 3,
            'min_confidence_demo'    => 0.65,
            'min_confirmations_live' => 4,
            'min_confidence_live'    => 0.85,
        ],
    ],

    // ── Signal lifecycle ──────────────────────────────────────────────────────
    'signal_ttl_minutes'    => 120,   // shadow signals expire after 2 h
    'max_signals_per_symbol' => 1,
    'max_signals_per_run'    => 20,

    // ── Rejected-context short replay analyzer ────────────────────────────────
    // Diagnostics-only replay — no orders, no handoff, no live signals.
    // Analyzes directional rejected contexts from double_bottom_long to measure
    // whether hypothetical short entries would have been profitable.
    'replay_enabled'                 => true,
    'replay_context_max_age_minutes' => 480,  // 8 h lookback for replay (wider than main pipeline)
    'replay_pause_minutes'           => 3,    // 3-minute confirmation pause before hypothetical entry
    'replay_max_contexts'            => 500,  // max contexts processed per replay run
    'replay_candle_storage_dir'      => 'modules/parser/parser2_history_accumulator/storage',

    // ── Replay/trend confirmation gate for executable demo handoff ────────────
    // Applied only to side=short candidates.
    // When true, executable bot_handoff_queue entries require matching replay
    // confirmation (status=replay_short_candidate) and passing trend checks.
    // Candidates and signals may still be written as diagnostic (non-executable).
    'dynamic_handoff_require_replay_confirmation' => true,
    'dynamic_handoff_require_5m_bearish'          => true,
    'dynamic_handoff_require_15m_not_bullish'     => true,
    'dynamic_handoff_require_30m_not_bullish'     => true,
    'dynamic_handoff_block_on_strong_recovery'    => true,
    // Max allowed price_change_pct (%) for 15m/30m trend to be "not bullish".
    // A value greater than the threshold means the window is too bullish to short.
    'dynamic_handoff_max_15m_price_change_pct'    => 0.20,  // >0.20% 15 m rise = bullish → block
    'dynamic_handoff_max_30m_price_change_pct'    => 0.30,  // >0.30% 30 m rise = bullish → block
    // Minimum replay confirmation count required when replay gate is active.
    // This checks replay_record.confirmations.count (not per-rule min_confirmations_demo).
    // Per-rule min_confirmations_demo is checked separately before the replay gate.
    // Both must pass for a short candidate to become executable.
    'dynamic_handoff_min_confirmations_with_replay' => 3,

    // ── Entry wall gate (OrderBook Context) ───────────────────────────────────
    // Block or demote entry signals when price is too close to a persistent wall.
    // Requires OrderBookContextService at modules/system/orderbook_context/service.php.
    // Fails gracefully when OBC service is unavailable — signal is never blocked.
    'entry_wall_gate_enabled'             => true,
    'entry_wall_block_distance_pct'       => 0.8,    // block if wall within 0.8% of entry price
    'entry_wall_require_persistent'       => true,   // only block for persistent walls (seen >= N ticks)
    'entry_wall_demote_instead_of_reject' => true,   // demote (reduce confidence) instead of hard reject

    // ── Source adapter paths ──────────────────────────────────────────────────
    // Read-only module paths used by source adapters.
    // Dynamic Strategies never writes to these paths.
    'bot_module_dir'          => 'modules/bot',
    'stop_manager_module_dir' => 'modules/stop_manager',

    // ── Source adapters ───────────────────────────────────────────────────────
    // Dynamic Strategies pulls contexts from existing source strategy artifacts.
    // Source strategies are NOT required to export data to Dynamic Strategies.
    // All reads are passive/read-only. Missing files are handled gracefully.
    'sources' => [
        'double_bottom_long' => [
            'enabled'                   => true,
            'module_path'               => 'modules/strategy/pattern/double_bottom_long',
            'read_last_run'             => true,   // read storage/last_run.json examples
            'read_rejects'              => true,   // reserved for future dedicated rejects.json
            'read_pending'              => true,   // read storage/pending_confirmations.json
            'read_signals'              => false,  // do not read signals (not needed)
            'read_handoff_queue'        => false,  // do not read handoff queue
            'read_closed_trades'        => true,   // read bot/storage/trades/closed_trades.json
            'read_bot_last_run'         => true,   // read bot/storage/last_run.json examples
            'read_stop_manager_last_run' => true,  // read stop_manager/storage/last_run.json examples
            'max_items_per_run'         => 200,    // cap on contexts extracted per adapter per run
            'context_max_age_minutes'   => 480,    // skip artifacts older than 8 hours
        ],
        'confirmed_continuation' => [
            'enabled'                   => true,
            'module_path'               => 'modules/strategy/pattern/confirmed_continuation',
            'read_last_run'             => true,   // read storage/last_run.json reject examples
            'read_rejects'              => true,   // read storage/rejects.json
            'read_signals'              => true,   // read stale signals for directional context
            'read_handoff_queue'        => false,  // do not read handoff queue
            'read_closed_trades'        => true,   // read bot/storage/trades/closed_trades.json
            'max_items_per_run'         => 200,
            'context_max_age_minutes'   => 480,
        ],
    ],
];
