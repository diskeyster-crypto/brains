<?php

declare(strict_types=1);

/**
 * Strategy Governor — Base Config
 *
 * V1 is shadow-only.
 * It must NOT block orders, must NOT change bot or strategy behaviour.
 * It only observes signals, queues, positions, closed trades, and writes
 * recommended decisions.
 *
 * Override individual values in config/active.php (not committed) without
 * touching this file.
 */

return [
    // Master enable.  When false the Governor cron tick exits immediately.
    'enabled'                       => true,

    // Operating mode.  V1 only supports 'shadow'.
    // Future: 'enforced' would allow the Governor to gate bot queue entries.
    'mode'                          => 'shadow',

    // V1 safety: enforce_live_gate must remain false.
    // Setting this true in a future version would gate live routing decisions.
    'enforce_live_gate'             => false,

    // Number of Governor ticks a signal may remain in wait_confirmation before
    // it must receive a final decision (approve or reject).
    // Used only as a legacy fallback; prefer strategy_policies below.
    'pending_confirmation_ticks'    => 5,

    // Maximum age (seconds) of a newly discovered signal before it is considered
    // stale and rejected without entering active pending state.
    // 1800 = 30 minutes.
    'max_signal_age_seconds'        => 1800,

    // Maximum time (seconds) a pending signal may wait without appearing in the
    // current signal batch before it is expired (signal disappeared).
    // 3600 = 1 hour.
    'max_pending_age_seconds'       => 3600,

    // When true, finalized pending entries (approve/reject/expire) are kept in
    // pending_signals.json for visibility until final_decision_ttl_seconds passes.
    'keep_final_decisions_in_pending' => true,

    // Seconds after which a final pending entry is removed from pending_signals.json.
    // 86400 = 24 hours.
    'final_decision_ttl_seconds'    => 86400,

    // Per-strategy confirmation policy.
    // Keys must match strategy_id values used in signal/trade records.
    // 'default' applies to any strategy not explicitly listed.
    // Priority: strategy_policies[strategy_id] > strategy_policies['default'] > global fallback (no wait).
    'strategy_policies'             => [
        'corridor_bottom_long' => [
            'confirmation_required'       => true,
            'pending_confirmation_ticks'  => 5,
        ],
        'default' => [
            'confirmation_required'       => false,
            'pending_confirmation_ticks'  => 0,
        ],
    ],

    // Minimum total closed trades before the Governor may recommend live routing.
    'min_closed_trades_for_live'    => 20,

    // Minimum trades per hour (on the current hour-of-day / weekday bucket)
    // before considering the live gate open.
    'min_hourly_trades_for_live'    => 5,

    // Win-rate threshold (0.0–1.0) required to allow recommended live routing.
    'min_winrate_for_live'          => 0.55,

    // Average ROI % required to allow recommended live routing.
    'min_avg_roi_for_live'          => 1.0,

    // Maximum allowed consecutive losses before the Governor recommends a pause.
    'max_consecutive_losses_live'   => 3,

    // Minutes to keep a strategy in cooldown after a recommended block.
    'cooldown_minutes_after_block'  => 180,

    // ── Phase 3A: approved demo queue (shadow bridge) ─────────────────────────
    // When true, Governor maintains its own approved demo queue for inspection.
    // The bot does NOT read this queue in this phase.
    'approved_demo_queue_enabled'   => true,

    // Queue operating mode.  'shadow_bridge' = Governor writes for visibility only;
    // the bot must not consume it until Phase 3B+ explicitly enables consumption.
    'approved_demo_queue_mode'      => 'shadow_bridge',

    // Maximum number of new queue items written per Governor run.
    'max_approved_demo_per_run'     => 10,

    // Seconds after approval before a queued entry is considered stale and dropped.
    // 1800 = 30 minutes.
    'approved_demo_ttl_seconds'     => 1800,
];
