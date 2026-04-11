<?php
declare(strict_types=1);

/**
 * Coin Passport Module — Cron Tasks
 *
 * CronManager auto-discovers this file.
 * Handler methods are called on CoinPassportService instance.
 *
 * Tasks:
 *   coin_passport:rebuildAll           — rebuild every known symbol (full pass, every 6 h)
 *   coin_passport:rebuildRecentSymbols — lighter pass for recently active symbols (every 1 h)
 */
return [
    'buildCycleProfiles' => [
        'interval'    => 3600, // 1 hour
        'enabled'     => true,
        'description' => 'Coin Passport: build derived coin behavior cycle profiles from parser2 history data',
    ],
    'buildCycleReadModel' => [
        'interval'    => 3600, // 1 hour — runs after buildCycleProfiles
        'enabled'     => true,
        'description' => 'Coin Passport: build compact per-symbol cycle read model from coin_cycle_profile.json',
    ],
    'rebuildAll' => [
        'interval'    => 21600, // 6 hours
        'enabled'     => true,
        'description' => 'Coin Passport: rebuild all symbol passports from trade history',
    ],
    'rebuildRecentSymbols' => [
        'interval'    => 3600, // 1 hour
        'enabled'     => true,
        'description' => 'Coin Passport: rebuild passports for recently active symbols only',
    ],
];
