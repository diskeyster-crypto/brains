<?php
declare(strict_types=1);

/**
 * Trading Bot Module Bootstrap
 * 
 * Loads all required dependencies (traits, lib classes, gateway)
 * BEFORE service.php can be loaded.
 * 
 * This file is auto-loaded by CronManager before service.php.
 */

$moduleDir = __DIR__;

// Load lib files FIRST (traits and classes must be loaded before service.php)
require_once $moduleDir . '/lib/bot_core_trait.php';
require_once $moduleDir . '/lib/bot_config_trait.php';
require_once $moduleDir . '/lib/bot_sources_trait.php';
require_once $moduleDir . '/lib/bot_reconcile_trait.php';
require_once $moduleDir . '/lib/bot_executor_trait.php';
require_once $moduleDir . '/lib/bot_api_trait.php';
require_once $moduleDir . '/lib/bot_commands_trait.php';
require_once $moduleDir . '/lib/bot_risk_engine.php';
require_once $moduleDir . '/lib/bot_reversal_signal_helper.php';
require_once $moduleDir . '/lib/bot_trailing_engine.php';
require_once $moduleDir . '/lib/bot_validator.php';
require_once $moduleDir . '/lib/bot_store.php';

// Load gateway
require_once $moduleDir . '/gateway.php';

/* RULES
- Load order matters: traits -> engines -> store -> gateway
- NO executable logic beyond require_once
- This file is loaded by CronManager before service.php
*/
