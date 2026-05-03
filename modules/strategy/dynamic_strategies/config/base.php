<?php

declare(strict_types=1);

/**
 * Dynamic Strategies — Base Config
 *
 * Standalone strategy-layer module that consumes useful rejected/failed/diagnostic
 * contexts from ordinary strategies (e.g. double_bottom_long) and generates
 * short-watch shadow candidates and demo signals.
 *
 * Override individual values in active.php without touching this file.
 *
 * SAFETY: shadow_only = true, handoff_enabled = false, live_enabled = false
 * by default.  Live execution requires explicit manual enable.
 */

return [
    // ── Identity ─────────────────────────────────────────────────────────────
    'strategy_id'     => 'dynamic_strategies',
    'enabled'         => true,
    'mode'            => 'demo',
    'side'            => 'short',

    // ── Safety gates ─────────────────────────────────────────────────────────
    'shadow_only'       => true,   // true = no executable signals regardless of other flags
    'handoff_enabled'   => false,  // true = write executable rows to bot_handoff_queue.json
    'live_enabled'      => false,  // must be explicitly set true to allow live signals
    'emit_bot_handoff'  => false,  // combined gate: handoff_enabled AND emit_bot_handoff must both be true

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
    'min_confirmations_for_shadow_candidate' => 2,
    'min_confirmations_for_demo_signal'      => 3,
    'min_confidence_for_demo_signal'         => 0.65,
    'min_confidence_for_live_signal'         => 0.85,
    'live_requires_manual_enable'            => true,
    'live_requires_governor_later'           => true,

    // ── Dynamic rules enabled ─────────────────────────────────────────────────
    'falling_knife_short_watch'              => true,
    'failed_reclaim_short_watch'             => true,
    'base_breakdown_short_watch'             => true,
    'failed_pending_breakdown_short_watch'   => true,
    'failed_long_after_entry_short_watch'    => true,
    'late_exhaustion_short_watch'            => true,

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
];
