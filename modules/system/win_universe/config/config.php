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
         * Minimum average ROI (%) a symbol must show in the lookback window
         * to qualify as a winning coin.
         * A trade ROI above this threshold counts as a "win above threshold".
         */
        'min_roi_threshold' => 1.5,

        /**
         * Lookback window in days for recent trade statistics.
         * Trades older than this are excluded from the qualification pass.
         */
        'lookback_days' => 30,

        /**
         * Minimum number of closed trades within the lookback window
         * required before a symbol can qualify.
         * Prevents single-trade flukes from inflating the universe.
         */
        'min_closed_trades' => 3,

        /**
         * Minimum win-rate (0.0–1.0) required for qualification.
         * A "win" is defined as roi >= min_roi_threshold.
         * Set to 0.0 to disable the win-rate gate.
         */
        'min_winrate' => 0.0,

        /**
         * Minimum average ROI (%) across all trades in the lookback window.
         * This is independent of min_roi_threshold (which defines per-trade wins).
         * min_avg_roi ensures the symbol is consistently profitable on average,
         * not just in a few lucky trades.
         * Set to 0.0 to disable (any positive average passes).
         */
        'min_avg_roi' => 0.0,
    ],

];
