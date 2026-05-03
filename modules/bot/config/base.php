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

    // ── Submitted queue reconciliation ────────────────────────────────────
    // submitted_reconcile_enabled:
    //   Master feature flag.  Set false to skip reconciliation entirely.
    // submitted_without_position_ttl_minutes:
    //   If a submitted item has no active order and no active position and no
    //   closed-trade match, expire it after this many minutes (status →
    //   submitted_expired).  30 min is safe for most strategies.
    // submitted_with_position_ttl_minutes:
    //   Not currently used for forced expiry; reserved so future logic can
    //   keep items alive longer when a position exists.  1440 = 24 h.
    'submitted_reconcile_enabled'              => true,
    'submitted_without_position_ttl_minutes'   => 30,
    'submitted_with_position_ttl_minutes'      => 1440,

    // ── Signal source selector ─────────────────────────────────────────────
    // Determines which source the bot reads signals from.
    //
    // Allowed values:
    //   direct_strategy_handoff — current behaviour; bot reads each enabled
    //                             strategy's storage/bot_handoff_queue.json.
    //   governor_approved_demo  — bot reads the Strategy Governor's approved
    //                             demo queue instead.  Items must have mode=demo.
    //                             Governor source is demo-only; no live orders.
    //   shadow_compare          — bot executes direct strategy handoff unchanged
    //                             AND reads the Governor approved_demo_queue for
    //                             diagnostics only.  No orders are created from
    //                             the Governor queue.  Comparison counters and
    //                             example arrays are written to last_run.json.
    //
    // Invalid or unknown values fall back to direct_strategy_handoff automatically.
    //
    // Default: direct_strategy_handoff  (preserves existing working behaviour).
    // Do NOT set governor_approved_demo in production without explicit operator action.
    'signal_source_mode' => 'direct_strategy_handoff',

    // ── Shadow compare optional flag ───────────────────────────────────────
    // When signal_source_mode = shadow_compare, compare runs automatically and
    // Governor data is read for diagnostics only; execution remains direct.
    //
    // When signal_source_mode = direct_strategy_handoff AND this flag = true,
    // direct execution continues unchanged AND Governor comparison counters are
    // also calculated and written to last_run.json for diagnostics.
    //
    // Default false preserves current behaviour and imposes no overhead.
    'shadow_compare_enabled' => false,

    // ── Symbol freeze after close ─────────────────────────────────────────────
    // Temporarily prevents new order_queue items for a symbol after a position closes.
    'symbol_freeze_after_close_enabled'    => true,
    'symbol_freeze_after_close_minutes'    => 10,
    'symbol_freeze_modes'                  => ['demo', 'live'],
    'symbol_freeze_apply_to_profit_close'  => true,
    'symbol_freeze_apply_to_stop_close'    => true,
    'symbol_freeze_apply_to_loss_close'    => true,
    'symbol_freeze_apply_to_manual_close'  => true,

    // ── Adaptive symbol freeze (strategy-specific profiles) ───────────────────
    // When enabled, double_bottom_long (and any future configured strategy)
    // receives a freeze duration computed from close result and ROI instead of
    // the global symbol_freeze_after_close_minutes value.
    // All other strategies continue to use the global flat duration.
    'symbol_freeze_adaptive_enabled'                       => true,

    // double_bottom_long profile
    'symbol_freeze_double_bottom_enabled'                  => true,

    // Profit tier durations (minutes)
    'symbol_freeze_double_bottom_profit_small_minutes'     => 360,
    'symbol_freeze_double_bottom_profit_normal_minutes'    => 720,
    'symbol_freeze_double_bottom_profit_strong_minutes'    => 1080,
    'symbol_freeze_double_bottom_profit_extreme_minutes'   => 1440,

    // Profit tier ROI lower bounds
    'symbol_freeze_double_bottom_profit_small_min_roi'     => 0.0,
    'symbol_freeze_double_bottom_profit_normal_min_roi'    => 5.0,
    'symbol_freeze_double_bottom_profit_strong_min_roi'    => 20.0,
    'symbol_freeze_double_bottom_profit_extreme_min_roi'   => 40.0,

    // Loss / guard profile durations (minutes)
    'symbol_freeze_double_bottom_loss_minutes'             => 1440,
    'symbol_freeze_double_bottom_deep_loss_minutes'        => 2880,
    'symbol_freeze_double_bottom_early_fail_minutes'       => 1440,
    'symbol_freeze_double_bottom_emergency_stop_minutes'   => 2880,

    // ROI boundary for deep-loss classification
    'symbol_freeze_double_bottom_deep_loss_roi'            => -30.0,

    // Fallback when ROI is missing
    'symbol_freeze_double_bottom_default_minutes'          => 720,

    // ── Symbol blacklist ──────────────────────────────────────────────────────
    // Blocks specific symbols from entering the order_queue.
    'symbol_blacklist_enabled'             => true,
    'manual_symbol_blacklist'              => [],

    // ── Auto blacklist ────────────────────────────────────────────────────────
    // Automatically blacklists symbols after repeated losing closed trades.
    'auto_blacklist_enabled'               => true,
    'auto_blacklist_loss_threshold'        => 3,
    'auto_blacklist_window_hours'          => 24,
    'auto_blacklist_duration_hours'        => 24,
    'auto_blacklist_modes'                 => ['demo', 'live'],
    'auto_blacklist_count_only_closed_losses' => true,
    'auto_blacklist_reset_on_win'          => false,
];
