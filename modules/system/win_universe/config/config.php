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
         *   0.005 → 0.5% ROI threshold
         *
         * Calibrated value: 0.05 (5% ROI). This is the per-trade bar a symbol must
         * consistently exceed on average to qualify for the win pool.
         * Softer than the previous 1.5 (150% in decimal units — an impossible bar).
         */
        'min_roi_threshold' => 0.05,

        /**
         * Lookback window in days for recent trade statistics.
         * Trades older than this are excluded from the qualification pass.
         * Extended to 60 days to capture enough trade history for meaningful statistics.
         */
        'lookback_days' => 60,

        /**
         * Minimum number of closed trades within the lookback window
         * required before a symbol can qualify.
         * Lowered to 2 to allow bootstrapping qualification on limited trade data.
         * Prevents single-trade flukes: at least 2 trades required for any signal.
         */
        'min_closed_trades' => 2,

        /**
         * Minimum win-rate (0.0–1.0) required for qualification.
         * A "win" is defined as roi >= min_roi_threshold.
         * Set to 0.0 to disable the win-rate gate.
         */
        'min_winrate' => 0.0,

        /**
         * Minimum average ROI across all trades in the lookback window.
         * UNIT: same decimal fraction as min_roi_threshold (0.01 = 1%).
         * Set to a small positive value to ensure the symbol is net-profitable on average.
         * 0.005 = 0.5% average ROI floor (filters pure break-even symbols).
         * Set to 0.0 to disable (any positive average passes).
         */
        'min_avg_roi' => 0.005,

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
         *   shadow   — analytics only, no influence on trading (current phase)
         *   priority — (future) qualified symbols get a priority bonus in Smart Brain ranking
         *   win_only — (future) Smart Brain considers only win-pool symbols
         *
         * Even when set to 'priority' or 'win_only', trading behavior is NOT changed
         * until that integration step is explicitly implemented.
         */
        'win_universe_mode' => 'shadow',

        /**
         * Whether the priority bonus is active (future use only).
         * Has no effect in the current shadow-only step.
         */
        'priority_bonus_enabled' => false,

        /**
         * Strength of priority bonus (0.0–1.0) for future integration.
         * Has no effect in the current shadow-only step.
         */
        'priority_bonus_strength' => 0.1,
    ],

];
