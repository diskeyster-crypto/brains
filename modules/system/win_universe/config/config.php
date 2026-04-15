<?php
declare(strict_types=1);

/**
 * Win Universe — Default Operational Configuration
 *
 * Shadow-mode only. These defaults are the baseline.
 * The unified Config Module overlay takes priority at runtime if configured.
 *
 * @return array<string,mixed>
 */
return [

    /* ======================================================
       MODULE — top-level operational toggles
       ====================================================== */
    'module' => [
        /**
         * Master on/off switch for the Win Universe module.
         * When false, the module becomes a no-op (safe fallback: true).
         */
        'enabled' => true,
    ],

    /* ======================================================
       WIN UNIVERSE — qualification parameters
       ====================================================== */
    'win_universe' => [
        /**
         * Enable/disable win universe qualification pass.
         * When false, no outputs are written.
         */
        'win_universe_enabled' => true,

        /**
         * Minimum per-trade ROI required for a trade to count as a "win above threshold".
         *
         * UNIT NOTE: ROI values from closed trade sources are stored as decimal fractions
         * where 0.01 = 1% price return. This threshold must be expressed in the same unit.
         *   0.05  → 5% ROI threshold
         *   0.02  → 2% ROI threshold
         *   0.01  → 1% ROI threshold
         *
         * Calibrated value: 0.01 (1% ROI). This is the per-trade bar a symbol must
         * consistently exceed on average to qualify for the win pool.
         * Realistic for the early bootstrap phase where trade history is limited.
         * Symbols with simulator active positions at 1%+ unrealised ROI qualify.
         */
        'min_roi_threshold' => 0.01,

        /**
         * Lookback window in days for recent trade statistics.
         * Trades older than this are excluded from the qualification pass.
         * Extended to 60 days to capture enough trade history for meaningful statistics.
         */
        'lookback_days' => 60,

        /**
         * Minimum number of closed trades within the lookback window
         * required before a symbol can qualify.
         * Set to 1 to allow qualification from a single trade record (including
         * simulator active positions during the bootstrap phase). Prevents
         * zero-evidence promotion while allowing early qualification.
         */
        'min_closed_trades' => 1,

        /**
         * Minimum win-rate (0.0–1.0) required for qualification.
         * A "win" is defined as roi >= min_roi_threshold.
         * Set to 0.0 to disable the win-rate gate.
         */
        'min_winrate' => 0.0,

        /**
         * Minimum average ROI across all trades in the lookback window.
         * UNIT: same decimal fraction as min_roi_threshold (0.01 = 1%).
         * Set to 0.0 to rely solely on min_roi_threshold for the quality gate.
         * This avoids double-gating in bootstrap environments where the average
         * is already constrained by the per-trade threshold check.
         */
        'min_avg_roi' => 0.0,

        /**
         * Days after which a win-pool symbol must be re-validated.
         * If a symbol was promoted more than this many days ago and is no longer
         * currently qualified, it is demoted.
         * Set to 0 to disable expiry-based demotion.
         */
        'qualification_expiry_days' => 90,

        /**
         * Number of consecutive recent losing trades (roi < min_roi_threshold)
         * required to trigger demotion from win pool.
         * Set to 0 to disable loss-streak demotion.
         */
        'demotion_loss_streak' => 0,

        /**
         * Operating mode for Win Universe.
         * Allowed values:
         *   shadow   — analytics only, no influence on trading
         *   priority — qualified win-pool symbols receive a bounded ranking bonus in Smart Brain
         *              slot competition (soft preference, NOT a hard gate)
         *   win_only — (future) Smart Brain considers only win-pool symbols
         *
         * Priority mode: qualified symbols earn up to priority_bonus_strength × 10 pts added to
         * their slot_priority_score. All hard gates (passport, cycle, entry filter, wave) still apply.
         * Non-win candidates remain eligible and can still win if clearly stronger.
         */
        'win_universe_mode' => 'priority',

        /**
         * Whether the priority bonus is active.
         * When true and win_universe_mode = 'priority', qualified win-pool symbols receive
         * a bounded ranking bonus in Smart Brain slot competition.
         * Has no effect in shadow mode.
         */
        'priority_bonus_enabled' => true,

        /**
         * Strength of priority bonus (0.0–1.0).
         * Final bonus = priority_bonus_strength × 10 pts (bounded to [0, 10]).
         * Default 0.5 → 5 pts bonus for qualified win-pool symbols.
         * This keeps the bonus meaningful but does not overwhelm signal quality.
         */
        'priority_bonus_strength' => 0.5,
    ],

];
