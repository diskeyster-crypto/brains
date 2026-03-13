<?php
declare(strict_types=1);

/**
 * Profit Manager Module Bootstrap
 * 
 * Loads all required dependencies (lib classes, gateway)
 * BEFORE service.php can be loaded.
 * 
 * This file is auto-loaded by CronManager before service.php.
 */

$moduleDir = __DIR__;

// Load lib files FIRST (classes must be loaded before service.php)
require_once $moduleDir . '/lib/store.php';
require_once $moduleDir . '/lib/risk_math.php';
require_once $moduleDir . '/lib/position_selector.php';
require_once $moduleDir . '/lib/validator.php';
require_once $moduleDir . '/lib/stop_applier.php';
require_once $moduleDir . '/lib/profit_manager.php';

// Load gateway
require_once $moduleDir . '/gateway.php';

/* RULES
- Load order matters: store -> math -> selector -> validator -> applier -> manager -> gateway
- NO executable logic beyond require_once
- This file is loaded by CronManager before service.php
*/
