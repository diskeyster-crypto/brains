<?php

declare(strict_types=1);

/**
 * Bot Module — Base Config
 *
 * Foundation-only. No exchange execution yet.
 * Override individual values in active.php without touching this file.
 *
 * Strategy sources are managed through:
 *   - storage/strategy_registry.json  (auto-built by discovery scan)
 *   - storage/operator_overrides.json (per-strategy operator controls)
 */

return [
    // Core identity
    'bot_id'  => 'bot',
    'enabled' => false,
    'mode'    => 'passive',   // smoke | active | passive | disabled  (active/smoke = local state execution; passive/disabled = ingest only)

    // Strategy autodiscovery
    // Bot scans these repo-relative directories for strategy modules.
    // A directory is a valid strategy module when it has:
    //   - manifest.json with category == 'strategy'
    //   - storage/ subdirectory
    'strategy_scan_roots' => ['modules/strategy'],
    'strategy_scan_depth' => 3,

    // Entry modes supported by this bot instance.
    // Read directly from each handoff signal's entry_mode field.
    // This flag restricts which entry_modes this bot instance will accept.
    'allowed_entry_modes' => ['limit', 'market'],

    // Signal freshness: reject handoff signals older than this many seconds.
    // 0 = rely entirely on signal expires_at from the strategy.
    'max_signal_age_sec' => 0,

    // Order queue deduplication window (seconds).
    // Signals within this window are refreshed, not re-queued.
    'queue_dedup_ttl_sec' => 57600,   // 16 h  (4 × H4 bars)

    // Global budget / leverage caps enforced at bot level (0 = use per-strategy value).
    // Per-strategy overrides in operator_overrides.json take precedence over signal values.
    'max_bot_budget'   => 0.0,
    'max_bot_leverage' => 0,

    // Cron / batch
    'tick_interval_sec'   => 60,
    'max_runtime_seconds' => 25,

    // Continuous processing
    'continuous_enabled' => true,
];
