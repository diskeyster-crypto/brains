<?php
declare(strict_types=1);

/**
 * Coin Passport — Default Operational Configuration
 *
 * CONFIG FIRST / ZERO-HARDCODE
 *
 * First-wave operational parameters owned by Coin Passport.
 * These defaults are the baseline; the unified Config Module
 * (config_operational_master.json) takes priority at runtime
 * when a master config has been saved via the Config Center.
 *
 * DO NOT put immutable / internal values here (thresholds, engine constants).
 * Those remain as constants in passport_engine.php.
 *
 * @return array<string,mixed>
 */
return [

    /* ======================================================
       MODULE — top-level operational toggles
       ====================================================== */
    'module' => [
        /**
         * Master on/off switch for the Coin Passport module.
         * When false, all rebuild tasks become no-ops and the module
         * gracefully skips execution (safe fallback: true = always run).
         */
        'enabled' => true,

        /**
         * Enable/disable the rebuildAll cron task.
         * When false, the full symbol rebuild pass is skipped.
         */
        'rebuild_all_enabled' => true,

        /**
         * Enable/disable the rebuildRecentSymbols cron task.
         * When false, the lightweight recent-symbol pass is skipped.
         */
        'rebuild_recent_enabled' => true,

        /**
         * Enable/disable the buildCycleProfiles cron task.
         * When false, cycle profile generation and context projection are skipped.
         */
        'cycle_profiles_enabled' => true,
    ],

];
