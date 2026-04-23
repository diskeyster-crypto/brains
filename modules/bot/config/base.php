<?php

declare(strict_types=1);

/**
 * Bot Module — Base Config
 *
 * Foundation-only. No exchange execution yet.
 * Override individual values in active.php without touching this file.
 */

return [
    // Core identity
    'bot_id'  => 'bot',
    'enabled' => false,
    'mode'    => 'passive',   // active | passive | disabled

    // Strategy handoff source
    'handoff_source_strategy' => 'double_bottom_long',
    'handoff_source_path'     => 'modules/strategy/pattern/double_bottom_long/storage/bot_handoff_queue.json',

    // Entry modes supported: limit | market
    // Read directly from each handoff signal's entry_mode field.
    // This flag restricts which entry_modes this bot instance will accept.
    'allowed_entry_modes' => ['limit', 'market'],

    // Signal freshness: reject handoff signals older than this many seconds.
    // 0 = rely entirely on signal expires_at from the strategy.
    'max_signal_age_sec' => 0,

    // Order queue deduplication window (seconds).
    // Signals that are still within this window are refreshed, not re-queued.
    'queue_dedup_ttl_sec' => 57600,   // 16 h  (4 × H4 bars)

    // Budget / leverage caps enforced at bot level (0 = use signal value as-is)
    'max_bot_budget'   => 0.0,
    'max_bot_leverage' => 0,

    // Cron / batch
    'tick_interval_sec'    => 60,
    'max_runtime_seconds'  => 25,

    // Continuous processing
    'continuous_enabled' => true,
];
