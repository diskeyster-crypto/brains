<?php
declare(strict_types=1);

/**
 * Brain Module Default Configuration
 * 
 * Strategy Builder and Pipeline Orchestrator settings.
 * All settings can be overridden via UI.
 */

return [
    /**
     * Default strategy template
     * Used when creating new strategies
     */
    'default_strategy' => [
        'roi_target' => 5.0,        // Target ROI % per trade
        'max_drawdown' => 2.0,      // Maximum drawdown %
        'session' => 'any',         // Trading session: any, asia, europe, us
        'timing' => 'swing',        // Timing: scalp, intraday, swing, position
        'direction' => 'both',      // Direction: long, short, both
        'risk_per_trade' => 1.0,    // Risk % per trade
        'max_positions' => 3,       // Max simultaneous positions
        'min_volume_24h' => 1000000, // Min 24h volume USD
        'min_liquidity' => 500000,  // Min liquidity USD
    ],
    
    /**
     * Passport settings (coin performance profiles)
     * Controls how Brain applies passport data to adjust signals
     */
    'passport' => [
        'min_trades' => 1,              // Minimum trades in passport to apply adjustments (1 for initial statistics gathering)
        'timeout_factor' => 0.4,        // Factor to multiply median_duration_min for entry timeout
        'mae_min_abs_pct' => 0.1,       // Minimum abs MAE % to consider "significant" for retrace
        'max_entry_adjust_abs_pct' => 1.2, // Cap abs MAE % used to shift entry (avoid huge retrace shifts)
        'entry_timeout_min' => 2,       // Min entry timeout minutes (clamp lower)
        'entry_timeout_max' => 120,     // Max entry timeout minutes (clamp upper)
        
        /**
         * Auto entry_mode selection (Этап 3)
         * Brain automatically chooses impulse vs retrace based on passport metrics
         */
        'entry_mode_auto' => [
            'enabled' => true,                          // Enable automatic entry_mode selection
            'impulse_min_win_rate' => 0.52,             // Min win rate for impulse mode (0..1)
            'impulse_max_mae_abs_pct' => 0.5,           // Max abs MAE % for impulse mode
            'impulse_min_mfe_abs_pct' => 0.0,           // Min abs MFE % for impulse mode  
            'impulse_max_median_duration_min' => 60,    // Max median duration in minutes for impulse
            'retrace_default_if_no_mfe' => true,        // Use retrace if no MFE data available
            'impulse_late_threshold_min_pct' => 0.35,   // Min late_threshold_pct for impulse signals
            'retrace_enter_now_if_no_mae_adjustment' => true, // If retrace mode but MAE not applied -> enter_now
        ],

        /**

         * STEP 2: Auto trailing mode selection by passport
         * Brain chooses tight/normal/loose based on passport metrics
         */
        'trailing_mode_auto' => [
            'enabled' => true,              // Enable automatic trailing mode selection
            'min_trades_for_auto' => 20,    // Minimum trades to apply auto-select (below = normal)
            
            // Trailing modes with drawdown factors
            // drawdown_factor determines protected_roi = peak_roi - activation_roi * drawdown_factor
            'modes' => [
                'tight'  => ['drawdown_factor' => 0.25],  // Tight: small allowed drawdown
                'normal' => ['drawdown_factor' => 0.50],  // Normal: medium allowed drawdown
                'loose'  => ['drawdown_factor' => 0.75],  // Loose: large allowed drawdown
            ],
            
            // loose_if: ALL conditions must be true for loose mode
            // Use for "strong" coins with high win rate and good MFE
            'loose_if' => [
                'min_win_rate' => 0.60,             // Min win rate (0..1) for loose mode
                'min_mfe_abs_pct' => 0.60,          // Min abs MFE % for loose mode
                'max_median_duration_min' => 45,   // Max median duration for loose mode
            ],
            
            // tight_if: ANY condition triggers tight mode
            // Use for "noisy" coins with low win rate or high MAE
            'tight_if' => [
                'max_win_rate' => 0.52,             // If win rate <= this, trigger tight
                'min_mae_abs_pct' => 0.35,          // If abs MAE >= this, trigger tight
            ],
        ],
    ],
    
    /**
     * Symbol Policy (per-symbol gates for LIVE):
     * - Read Trading Bot closed trades and compute win-rate/ROI/streak per symbol.
     * - Reject weak symbols instead of global 'reverse_side_enabled' hacks.
     * - Optionally enforce wait_retrace and cooldown after loss streak.
     *
     * Note: by default, DOES NOT auto-invert side. Use side_mismatch_action='invert' only with per-symbol allow_invert.
     */
    'symbol_policy' => [
        'enabled' => true,
        'use_live_trades' => true,
        'live_trades_window' => 200,
        'cache_ttl_sec' => 30,

        // Hard reject gate: if trades_total >= min_trades AND (win_rate < min_win_rate) AND (avg_roi_pct < min_avg_roi_pct)
        'min_trades' => 8,
        'min_win_rate' => 0.52,
        'min_avg_roi_pct' => 0.00,

        // Cooldown after consecutive losses
        'max_loss_streak' => 3,
        'cooldown_minutes' => 180,

        // Weak symbol hardening
        'force_wait_retrace_on_weak' => true,
        'weak_win_rate_threshold' => 0.55,
        'weak_entry_timeout_minutes' => 12,
        'weak_retrace_deepen_factor' => 0.50,

        // Side preference gate (by avg ROI). Default action is REJECT (safe).
        'prefer_side_by_roi' => true,
        'min_side_delta_roi_pct' => 0.30,
        'side_mismatch_action' => 'reject', // reject|invert|allow

        // Manual per-symbol overrides example:
        // 'overrides' => [
        //     'BTCUSDT' => ['disabled' => false, 'min_trades' => 20, 'min_win_rate' => 0.55],
        //     'MERLUSDT' => ['disabled' => true],
        //     'TRIAUSDT' => ['allow_invert' => true, 'min_win_rate' => 0.60],
        // ],
        'overrides' => [],
    ],

    /**
     * Pipeline settings
     */
    'pipeline' => [
        'candidate_source' => 'parser4',   // Source module for candidates
        'signal_target' => 'parser5',      // Target module for signals
        'simulator' => 'simulator',        // Simulator module
        'feedback_enabled' => true,        // Collect feedback from simulator
        'auto_learn' => false,             // Auto-adjust strategies based on feedback
    ],
    
    /**
     * Run settings
     */
    'run' => [
        'max_history' => 100,              // Max run history records
        'timeout_sec' => 300,              // Pipeline run timeout
    ],
    
    /**
     * Risk Profile Defaults (v1)
     * Used when no active profile is set, or for simulator fallback
     * Per ТЗ: Brain Risk Profile v1 + Smart Trailing
     */
    'risk_defaults' => [
        'budget_usdt_per_trade' => 50,       // USDT budget per trade (margin)
        'leverage' => 10,                     // Leverage multiplier
        'stop_from_liq_range_pct' => 20,     // SL at 20% of entry↔liq range (1..90)
        'slippage_bps' => 20,                // Slippage in basis points (20 bps = 0.2%)
        'fees_bps' => 6,                     // STEP 1.3: Taker fee in basis points (6 bps = 0.06%)
        
        'trailing' => [
            'enabled' => true,                // Enable smart trailing stop
            'activation_roi_pct' => 6,        // Activate trailing at 6% ROI
            'mode' => 'normal',               // STEP 2: Trailing mode (tight|normal|loose)
            'drawdown_factor' => 0.50,        // STEP 2: Drawdown factor for protected_roi calculation
        ],
        
        'take_profit' => [
            'enabled' => false,               // TP disabled by default
            'roi_pct' => null,                // TP ROI % (null = no TP)
        ],
        
        // C1: Trading limits (moved from Parser6 config per ТЗ)
        // Brain Risk Profile is the single source of truth for all risk parameters
        'limits' => [
            'max_open_trades' => 0,           // Max open trades (0 or negative = unlimited)
            'one_trade_per_symbol' => true,   // Only one trade per symbol at a time
        ],
    ],
    
    /**
     * STEP 4+5: Learning subsystem configuration
     * Brain learns optimal trailing modes from LIVE and/or SIM datasets
     */
    'learning' => [
        'trailing' => [
            'enabled' => true,               // Enable learning-based trailing mode selection
            
            // STEP 5: Multiple dataset sources (LIVE priority 1, SIM priority 2)
            'dataset_sources' => [
                // LIVE - real execution data (priority 1)
                'live' => [
                    'enabled' => true,
                    'source_storage_key' => 'simulator.parser6_simulator.storage', // Shares storage with simulator
                    'filename' => 'training_trailing_live.ndjson',                  // Root file (Executor writes here)
                    'mode_expected' => 'clean',                                     // Expected mode in dataset
                    'source_expected' => 'live',                                    // Expected source field
                ],
                
                // SIM - simulation data (fallback, priority 2)
                'sim' => [
                    'enabled' => true,
                    'source_storage_key' => 'simulator.parser6_simulator.storage', // Uses same storage path
                    'filename' => 'training_trailing_sim.ndjson',                  // Root file (copy of clean)
                    'mode_expected' => 'clean',                                     // Expected mode in dataset
                    'source_expected' => 'sim',                                     // Expected source field
                ],
            ],
            
            // STEP 5: Source preference
            'prefer_live_if_available' => true,          // Prefer LIVE data when sufficient
            
            // STEP 5: Minimum requirements for LIVE data per symbol
            'min_total_episodes_live' => 60,             // Min total episodes in LIVE to use it
            'min_episodes_per_mode_live' => 20,          // Min episodes per mode in LIVE
            
            // Memory limits
            'max_lines' => 20000,            // Max lines to read per dataset
            
            // Data requirements (for SIM fallback and final analysis)
            'min_episodes_per_mode' => 20,   // Min episodes per mode for comparison
            'min_total_episodes' => 60,      // Min total episodes per symbol
            
            // Analysis window
            'window_per_symbol' => 200,      // Last N episodes per symbol to analyze
            
            // Update throttling
            'min_update_interval_sec' => 86400,  // Update recommendation max once per day
            
            // Improvement threshold
            'min_improvement_roi_pct' => 0.20,   // Min improvement vs normal (in net ROI%)
            
            // Score weights (higher = better, lower = better for giveback/drawdown)
            'score_weights' => [
                'roi_net_pct' => 1.0,                    // Weight for median net ROI
                'giveback_from_peak_pct' => 0.30,       // Weight penalty for giveback
                'worst_drawdown_from_peak_pct' => 0.20, // Weight penalty for drawdown
            ],
            
            // Rollback safety
            'rollback' => [
                'enabled' => true,
                'window' => 80,                          // Episodes to check for degradation
                'max_degradation_roi_pct' => 0.20,       // Max allowed degradation vs normal
            ],
        ],
    ],
    
    /**
     * STEP 6: Brain Management configuration
     * Brain monitors active simulator trades and issues commands to adjust trailing
     */
    'management' => [
        'enabled' => true,
        
        // Simulator source (CLEAN root storage for state.json)
        'simulator' => [
            'storage_key' => 'simulator.parser6_simulator.storage', // SystemPaths key for parser6 storage
            'state_file' => 'state.json',                           // Simulator state file
        ],
        
        // Commands destination (Brain storage)
        'commands' => [
            'storage_key' => 'system.brain.storage',                // SystemPaths key for brain storage
            'filename' => 'management/commands.json',               // Commands file path
            'ttl_sec' => 900,                                        // Commands TTL (15 minutes)
        ],
        
        // Limits
        'limits' => [
            'min_interval_sec_per_trade' => 120,  // Don't send commands more often than 2 min per trade
            'max_commands_per_run' => 200,        // Max commands in one run
        ],
        
        // Trailing drawdown_factor limits
        'trailing' => [
            'drawdown_factor_min' => 0.20,        // Min allowed drawdown_factor
            'drawdown_factor_max' => 0.80,        // Max allowed drawdown_factor
        ],
        
        // Adjustment step
        'step_delta' => 0.10,                     // Drawdown factor change per command
        
        // Decision thresholds (based on activation ROI)
        'thresholds' => [
            // TIGHTEN: if giveback_now_pct >= activation_roi_pct * giveback_factor_to_tighten
            // (giving back too much from peak → tighten the stop)
            'giveback_factor_to_tighten' => 0.60,
            
            // LOOSEN: if giveback_now_pct <= activation_roi_pct * giveback_factor_to_loosen
            // (very little giveback, may exit too early on noise → loosen)
            'giveback_factor_to_loosen' => 0.20,
        ],
    ],
    
    /**
     * UI configuration
     */
    'ui' => [
        'sessions' => [
            'any' => 'Any Session',
            'asia' => 'Asian Session (00:00-08:00 UTC)',
            'europe' => 'European Session (08:00-16:00 UTC)',
            'us' => 'US Session (14:00-22:00 UTC)',
        ],
        'timings' => [
            'scalp' => 'Scalping (1-15 min)',
            'intraday' => 'Intraday (15 min - 4h)',
            'swing' => 'Swing (4h - 1 week)',
            'position' => 'Position (1 week+)',
        ],
        'directions' => [
            'long' => 'Long Only',
            'short' => 'Short Only',
            'both' => 'Both Directions',
        ],
    ],
];

/* RULES
- Added symbol_policy configuration block for per-symbol live gates.
- Conservative defaults: reject weak symbols; no auto-invert by default.
- LF only
*/
