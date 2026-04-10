<?php
declare(strict_types=1);

/**
 * Trading Bot v1 — Configuration
 *
 * CONFIG FIRST / ZERO-HARDCODE
 * Bot executes intents created by Brain (clean_signal_v1 -> intent_live_v1).
 * Risk source for execution: ONLY from Brain risk-block (signal.risk / intent.risk).
 *
 * NOTE:
 * - UI runtime overrides are stored in config/bot.json (enabled/mode/account/max_positions/etc).
 * - This file MUST NOT be overwritten by UI; it is the single source of truth for module defaults.
 *
 * @return array<string,mixed>
 */
return [

    /* ======================================================
       MODULE CORE
       ====================================================== */
    'module' => [
        'enabled' => true,

        // live  = sends real exchange calls via KeyCenter credentials
        // demo  = sends real API calls to Bybit Demo account (credentials stored locally in bot config)
        // paper = simulates locally, no real orders (legacy alias: dry)
        'mode' => 'paper',

        // KeyCenter account id for Bybit credentials (used only in live mode)
        'account_id' => 'trading_bot',

        // Per-mode local credentials (populated by UI settings, stored in bot.json)
        // demo credentials are NEVER stored in KeyCenter — local bot config only
        // live credentials use KeyCenter (account_id above); the block below is informational only
        'credentials' => [
            'demo' => [
                'api_key'      => '',
                'api_secret'   => '',
                'api_base_url' => 'https://api-demo.bybit.com',
            ],
            'live' => [
                // live uses KeyCenter via account_id above — no raw keys here
            ],
        ],

        // Reconcile exchange positions & orders before executing intents
        // true = reconcile first (recommended)
        // false = skip reconcile step (faster, but less safe)
        'reconcile_before_action' => true,

        // Soft limit for concurrent positions managed by the bot (0 = unlimited)
        'max_concurrent_positions' => 10,
    ],

    /**
     * Per-symbol overrides (manual)
     *
     * Runtime config (bot.json) may define:
     * 'symbol_overrides' => [
     *   'BTCUSDT' => ['enabled'=>true, 'reverse_side_enabled'=>false, 'force_side'=>null],
     *   'MERLUSDT' => ['enabled'=>false],
     * ]
     */
    'symbol_overrides' => [],

    /* ======================================================
       INPUT SOURCES (SystemPaths keys + relative filenames)
       ====================================================== */
    'sources' => [
        // Brain CLEAN signals (clean_signal_v1)
        'signals_key'  => 'system.brain.storage',
        'signals_file' => 'signals.json',

        // Brain runtime risk profile snapshot (risk_active_v1) — fallback for some modes/tests
        'risk_active_key'  => 'system.brain.storage',
        'risk_active_file' => 'runtime/risk_active.json',

        // Brain commands (trading_commands_v1)
        'commands_key'  => 'system.brain.storage',
        'commands_file' => 'runtime/trading_commands.json',
    ],

    /* ======================================================
       SIGNAL VALIDATION (strict)
       ====================================================== */
    'validation' => [
        // Expected Brain signal schema_version
        'signal_schema_version' => 'clean_signal_v1',

        'required_signal_fields' => [
            'id',
            'symbol',
            'side',
            'entry',
            'created_ts',
            'entry_action',
            'risk',
        ],

        'required_risk_fields' => [
            'profile_id',
            'budget_usdt_per_trade',
            'leverage',
            'stop_from_liq_range_pct',
            'slippage_bps',
            'fees_bps',
            'order_type',
            'trailing',
            'limits',
        ],

        // Allowed values
        'allowed_sides' => ['long', 'short'],
        'allowed_entry_actions' => ['enter_now', 'wait_retrace'],
        'allowed_order_types' => ['market'],
    ],

    /* ======================================================
       EXCHANGE DEFAULTS (Bybit V5)
       ====================================================== */
    'exchange' => [
        'category' => 'linear',
        'settle_coin' => 'USDT',
        'position_idx' => 0,
        'account_type' => 'UNIFIED',

        // SL/TP update behavior
        'tpsl_mode' => 'Full',          // Full | Partial
        'sl_trigger_by' => 'IndexPrice' // MarkPrice | IndexPrice | LastPrice
    ],

    /* ======================================================
       EXECUTION POLICY
       ====================================================== */
    'execution' => [
        // Run lock (prevents concurrent runs)
        'run_lock_enabled' => true,
        'run_lock_file' => 'runtime/run.lock',

        // Scans / selection
        'max_scan_intents_per_run' => 200,     // read intents pool
        'max_intents_per_run' => 1,            // execute at most N new intents per run
        'max_deferred_intents_per_run' => 10,  // evaluate + emit deferred reasons (debug/UI)

        // Late / retrace policy defaults (can be overridden by intent fields)
        'default_entry_timeout_minutes' => 8,
        'default_late_threshold_pct' => 1.25,
        'late_entry_buffer_pct' => 0.15,
        // Side-specific overrides (null = use default_late_threshold_pct)
        'late_entry_threshold_pct_long' => null,
        'late_entry_threshold_pct_short' => 1.75,
        // Freshness: intents newer than this window get extra tolerance
        'late_entry_freshness_window_seconds' => 180,
        'late_entry_freshness_bonus_pct' => 0.20,
        'late_entry_short_enter_now_bonus_pct' => 0.25,
        'retrace_slack_pct' => 0.05,

        // Stale claimed intent finalization
        'claim_timeout_minutes' => 10,

        // Experimental: invert direction from Brain signals (LONG↔SHORT)
        // Useful for contrarian tests; intent will include side_original.
        'reverse_side_enabled' => false,

        // Reconcile / exchange cache
        'exchange_positions_cache_ttl_sec' => 2,

        // Closed-PnL enrichment (reconcile)
        'reconcile_closed_pnl_lookup_minutes' => 180,
        'reconcile_closed_pnl_limit' => 200,
        'reconcile_closed_pnl_match_window_sec' => 900,
        'reconcile_closed_pnl_qty_tolerance_pct' => 5.0,

        // Close reason inference
        'close_reason_sl_tolerance_pct' => 0.30,

        // Closed trades backfill (fills close_price/pnl + fixes legacy reasons)
        'reconcile_closed_backfill_enabled' => true,
        'reconcile_closed_backfill_lookback_minutes' => 120,
        'reconcile_closed_backfill_max_items' => 12,
        'reconcile_closed_backfill_max_attempts' => 3,
        'balance_cache_ttl_sec' => 8,
        'require_price_check_live' => true,

        // Safety
        'safety_stop_on_errors' => 3,

        // Post-open reconcile (helps to see filled size/avgPrice fast)
        'post_open_reconcile_delay_ms' => 250,
        'post_open_reconcile_retries' => 3,

        // Commands
        'commands_enabled' => false,
        'commands_apply_before_intents' => true,
        'commands_max_per_run' => 50,
        'commands_allow_close' => true,

        // Dumb trailing defaults (phase-1)
        'dumb_trailing_enabled' => true,
        'enable_trailing_on_open' => false,
        'dumb_trailing_activation_epsilon_pct' => 0.02,

        // ROI-based trailing presets for price_distance_floor mode
        // Preset mode: soft | medium | hard | custom
        // When preset is active, distance is defined in ROI units and converted to price distance via leverage
        'trailing_preset_mode' => 'medium',
        'trailing_presets' => [
            'soft' => [
                'activation_roi' => 2.0,
                'floor_lock_roi' => 2.0,
                'distance_roi'   => 0.5,
            ],
            'medium' => [
                'activation_roi' => 3.0,
                'floor_lock_roi' => 3.0,
                'distance_roi'   => 0.8,
            ],
            'hard' => [
                'activation_roi' => 4.0,
                'floor_lock_roi' => 4.0,
                'distance_roi'   => 1.0,
            ],
        ],

        // Profit Add-On: one-time scale-in into a winning position
        // Triggered when ROI >= trailing_activation_floor_roi (same threshold as floor lock).
        // Add-on amount = budget_usdt_per_trade * (profit_addon_budget_pct / 100).
        // One-time only per trade; does NOT reset protection state.
        'profit_addon_enabled' => false,
        'profit_addon_budget_pct' => 0.0,

        // ROI Ladder Trailing step (fixed_roi_ladder mode)
        // Locked ROI grows in discrete steps of trailing_step_roi from floor_lock base.
        // Formula: locked_roi = floor_lock + floor((peak_roi - activation_roi) / step_roi) * step_roi
        'trailing_step_roi' => 1.5,

        // Trend-Reversal Soft Ladder (TEST MODE — short V2/V3 only).
        // Activated when trailing_step_mode = 'trend_reversal_soft_ladder_short'.
        // Requires: short trade from double_top_contextual_v2 or _v3, AND
        //           mirrored double_bottom_contextual_v2 or _v3 signal present.
        // Fixed test constants (not user-configurable in v1):
        //   reversal_overlay_activation_peak_roi = 10
        //   reversal_overlay_base_lock_roi       = 5
        //   reversal_overlay_main_step_roi       = 3
        //   reversal_overlay_lock_step_roi       = 1
        // To enable: set trailing_step_mode = 'trend_reversal_soft_ladder_short'
        //            in trailing config (Brain or bot local).
        'reversal_overlay_enabled' => false,

        // Balance checks
        'balance_strict_stable_coin_only' => true,
        'balance_coin' => 'USDT',
        'balance_required_buffer_pct' => 5,
        'balance_reject_below_usdt' => 2,
    ],

    /* ======================================================
       OUTPUT / STORAGE
       ====================================================== */
    'output' => [
        'runtime_dir' => 'storage/runtime',
        'logs_dir' => 'storage/logs',

        // Files written by bot
        'last_run_file' => 'storage/runtime/last_run.json',
        'positions_file' => 'storage/runtime/positions.json',
        'orders_file' => 'storage/runtime/orders.json',
        'trades_file' => 'storage/runtime/trades.json',
    ],

    /* ======================================================
       UI
       ====================================================== */
    'ui' => [
        'max_preview_items' => 50,
    ],

    /* ======================================================
       WRITE SAFETY
       ====================================================== */
    'write' => [
        'atomic' => true,
        'backup_before_write' => false,
        'backup_suffix' => '.bak',
    ],

    /* ======================================================
       PROFIT MANAGER (shared config block)
       profit_manager/config/config.php returns this block.
       ====================================================== */
    'profit_manager' => [
        /* ======================================================
               MODULE CORE
               ====================================================== */
            'module' => [
                'enabled' => true,

                // live | dry
                // live = sends real setTradingStop calls to exchange
                // dry = logs actions but doesn't send
                'mode' => 'dry',

                // Phase identifier
                'phase' => 'profit_manager_v1',

                // Bybit account id from central key center
                'account_id' => 'trading_bot',
            ],

            /* ======================================================
               EXCHANGE PARAMETERS (Bybit V5 specific)
               ====================================================== */
            'exchange' => [
                // Category for Bybit V5 instruments/orders
                'category' => 'linear',

                // Settle coin for positions
                'settle_coin' => 'USDT',

                // Position mode: 0 = one-way mode (default), 1 = hedge buy, 2 = hedge sell
                'position_idx' => 0,

                // SL trigger type: "LastPrice", "IndexPrice", "MarkPrice"
                'sl_trigger_by' => 'IndexPrice',

                // Default tick size fallback (used if instruments-info unavailable)
                'tick_size_fallback' => 0.0001,
            ],

            /* ======================================================
               SELECTOR: Which positions to manage
               ====================================================== */
            'selector' => [
                // Mode A: "exchange_only" — all exchange positions
                // Mode B: "trading_bot_trades" — only positions from Trading Bot
                'mode' => 'exchange_only',

                // Include only these symbols (empty = all)
                'include_symbols' => [],

                // Exclude these symbols (blacklist)
                'exclude_symbols' => [],

                // Mode A: Require tb_ prefix in orderLinkId to identify bot positions
                'require_bot_prefix' => false,
            ],

            /* ======================================================
               STEP TRAILING (SL Ratchet)
               ====================================================== */
            'step_trailing' => [
                // Enable step trailing
                'enabled' => true,

                // Default activation ROI % (used if not in trade risk)
                // Step trailing starts when ROI >= this (first lock = activation_roi_pct_default - lock_buffer)
                'activation_roi_pct_default' => 1.0,

                // ROI step size (%)
                // Every +step_roi_pct we move SL up
                'step_roi_pct' => 1.0,

                // Buffer subtracted from step lock (%)
                // Avoids setting SL too tight
                'lock_buffer_roi_pct' => 0.25,

                // Minimum locked ROI (%)
                // 0 = do not move SL until first step lock
                'lock_floor_roi_pct' => 0.0,

                // Minimum distance from current price (%)
                // Prevents SL from being too close to market
                'min_distance_to_price_pct' => 0.1,
            ],

            /* ======================================================
               DUMB BYBIT TRAILING (Exchange-managed)
               ====================================================== */
            'dumb_trailing' => [
                // Enable dumb trailing
                'enabled' => false,

                // Epsilon for activePrice shift (%)
                // LONG: floor(refPrice * (1 - epsilon))
                // SHORT: ceil(refPrice * (1 + epsilon))
                'epsilon_pct' => 0.02,

                // Minimum trailing distance (%)
                'min_distance_pct' => 0.3,

                // Drawdown factor (trailing distance = activation_roi / drawdown_factor / leverage)
                'drawdown_factor_default' => 2.0,

                // Re-arm if trailing exists but not armed
                'rearm_if_not_armed' => true,
            ],

            /* ======================================================
               SHADOW TRAILING (PM shadow-only, no exchange writes)
               ====================================================== */
            'shadow_trailing' => [
                // Activation threshold: arm trailing once peak_roi >= this (%)
                'activation_roi_pct' => 3.5,

                // First lock ROI after arming (soft lock — break-even or small positive %)
                // Set 0.0 for break-even, or a small positive value for initial profit lock
                'first_lock_roi_pct' => 0.0,

                // Step size: tighten lock every +N% ROI above activation (%)
                'step_roi_pct' => 2.0,

                // Buffer subtracted from step lock to avoid setting stop too tight (%)
                'lock_buffer_roi_pct' => 0.5,

                // Cooldown between proposed lock updates (seconds)
                // Prevents rapid repeated moves on noisy candles
                'cooldown_sec' => 30,

                // Minimum distance from current price (% of mark price)
                // Proposed stop must be at least this far from mark price
                'min_distance_to_price_pct' => 1.0,

                // Use peak_roi as main driver (monotonic — never decreases)
                'peak_based_mode' => true,
            ],

            /* ======================================================
               PM-10: POST-ENTRY REFINEMENT POLICY
               Applied only in active-owner mode (trailing_owner=profit_manager),
               after trailing is armed. Controls three refinement branches:
               first_lock_protection, shallow_pullback_protection,
               and continuation_extension (observational).
               ====================================================== */
            'pm10_refinement' => [
                // first_lock_protection: hold first lock when peak is barely above activation.
                // Requires peakRoi >= activationRoi + (stepRoi * this_factor) before placing lock.
                // 0.0 = disable (lock immediately on arm), 1.0 = require full first step.
                'first_lock_min_continuation_factor' => 0.5,

                // shallow_pullback_protection: hold tightening when pullback fraction exceeds this.
                // Pullback fraction = (peakRoi - currentRoi) / peakRoi.
                // 0.30 = hold if more than 30% of peak ROI has been given back.
                // 0.0 = disable, 1.0 = never hold on pullback.
                'shallow_pullback_threshold_factor' => 0.30,

                // continuation_extension: observational branch, fires when position is this many
                // ROI points above the last lock (confirms PM is allowing position to breathe).
                'continuation_extension_min_headroom' => 1.0,
            ],

            'pm11_adaptive' => [
                // strong_continuation: minimum headroom above last lock (in ROI points) to classify
                // a position as "strong continuation". Must be >= 2 × step_roi_pct to qualify.
                // Prevents classifying normal progress as strong continuation prematurely.
                'strong_continuation_headroom_factor' => 2.0,

                // strong_continuation: maximum ROI extension PM-11 may record as adaptive
                // adjustment for a strong-continuation position. Pure observational bound —
                // does not bypass the step trailing ratchet or cooldown guards.
                'max_extension_roi' => 0.5,

                // flat_carry: position is classified as flat_carry when trailing is armed,
                // a lock is already placed, and the headroom (currentRoi - prevLockRoi) is below
                // this fraction of step_roi_pct (e.g. 0.3 × step means barely above lock).
                'flat_carry_headroom_factor' => 0.3,
            ],

            /* ======================================================
               ANTI-SPAM / RATE LIMITS
               ====================================================== */
            'limits' => [
                // Maximum setTradingStop calls per run
                'max_updates_per_run' => 10,

                // Minimum seconds between updates for same symbol
                'min_seconds_between_updates_per_symbol' => 15,

                // Minimum SL change in ticks to allow update
                'min_sl_change_ticks' => 2,
            ],

            /* ======================================================
               RUNTIME
               ====================================================== */
            'runtime' => [
                // Run lock: prevent concurrent executions
                'run_lock_enabled' => true,
                'run_lock_file' => 'runtime/exec.lock',

                // Max items in applied_index.json (ring buffer)
                'applied_index_max_items' => 500,

                // Symbol lock timeout (seconds)
                'symbol_lock_timeout_sec' => 60,
            ],

            /* ======================================================
               INPUT SOURCES (SystemPaths keys)
               ====================================================== */
            'sources' => [
                // SystemPaths key for THIS module base dir
                'module_key' => 'system.profit_manager',

                // Trading Bot active trades (Mode B)
                'trading_bot_storage_key' => 'system.trading_bot.storage',
                'trading_bot_trades_path' => 'trades/active',
            ],

            /* ======================================================
               STORAGE (relative to module base)
               ====================================================== */
            'output' => [
                // Runtime state files
                'last_run' => 'storage/runtime/last_run.json',
                'status' => 'storage/runtime/status.json',
                'applied_index' => 'storage/runtime/applied_index.json',
                'locks' => 'storage/runtime/locks.json',

                // Logs
                'error_log' => 'storage/logs/error.log',
            ],

            /* ======================================================
               WRITE SAFETY
               ====================================================== */
            'write' => [
                'atomic' => true,
                'backup_before_write' => false,
                'backup_suffix' => '.bak',
            ],
    ],

];

/* RULES
- Purpose: Trading Bot v1 base configuration (single source of truth; UI overrides in config/bot.json)
- No hardcode: Use SystemPaths keys for external storages (Brain signals/commands)
- Risk source: ONLY from Brain risk-block (signal.risk / intent.risk)
- UI must not overwrite this file; runtime overrides are stored in config/bot.json
- LF only
*/