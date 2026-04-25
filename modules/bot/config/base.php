<?php

declare(strict_types=1);

/**
 * Bot Module — Base Config
 *
 * Primary test mode: demo (Bybit Demo account, api-demo.bybit.com).
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
    'mode'    => 'demo',   // demo | live
                           // demo    = Bybit Demo account execution (api-demo.bybit.com); primary test mode
                           // live    = live account execution via KeyCenter

    // Bybit Demo account credentials (stored locally in config — not live account).
    // Used only when mode = demo.  Do NOT put live/mainnet keys here.
    'demo_api_key'     => '',
    'demo_api_secret'  => '',
    'demo_api_base_url'=> 'https://api-demo.bybit.com',

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

    // Per-trade execution defaults.
    // Resolution order: signal value → operator override → these config values → hard fallback.
    // 0 means "not set at config level" — the hard fallbacks (leverage=5, budget=6) will apply.
    'leverage'             => 5,     // default leverage for demo/live positions
    'budget_per_trade'     => 6.0,   // default USDT allocated per position
    'max_active_positions' => 10,    // maximum concurrently open positions (0 = unlimited)

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
