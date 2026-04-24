<?php

declare(strict_types=1);

/**
 * Profit Manager Module — Bootstrap
 *
 * Loads all lib/ dependencies required by ProfManagerService.
 * Called by CronManager before service.php is loaded.
 * No autoloader is available; all includes are explicit.
 */

$libDir = __DIR__ . '/lib';

require_once $libDir . '/store.php';
require_once $libDir . '/price_provider.php';
require_once $libDir . '/position_reader.php';
require_once $libDir . '/validator.php';
require_once $libDir . '/risk_math.php';
require_once $libDir . '/profit_lock_planner.php';
require_once $libDir . '/profile_legacy_safe.php';
require_once $libDir . '/paper_executor.php';
