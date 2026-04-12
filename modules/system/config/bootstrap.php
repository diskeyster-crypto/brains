<?php
declare(strict_types=1);

/**
 * Unified Config Module Bootstrap
 *
 * Loads all required lib files BEFORE service.php / controller.php can be loaded.
 * Auto-loaded by CronManager before service.php.
 */

$moduleDir = __DIR__;

require_once $moduleDir . '/lib/config_store.php';
require_once $moduleDir . '/lib/config_audit_engine.php';

/* RULES
- Load order matters: store first, then engine (engine depends on store)
- NO executable logic beyond require_once
- This file is loaded by CronManager before service.php
*/
