<?php

declare(strict_types=1);

/**
 * Tredercopis Core Bootstrap
 * 
 * Explicit bootstrap via require_once - no autoloaders, no magic.
 * Works in both CLI and Web environments.
 * 
 * Usage:
 *   require_once __DIR__ . '/core/bootstrap.php';
 */

if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__));
}

// Core System Layer
require_once ROOT . '/core/system/systempaths.php';
require_once ROOT . '/core/system/systempaths_bootstrap.php';
require_once ROOT . '/core/system/systemenv.php';
require_once ROOT . '/core/system/systemconfig.php';
require_once ROOT . '/core/system/systemruntime.php';
require_once ROOT . '/core/system/systemstate.php';
require_once ROOT . '/core/system/systemversion.php';
require_once ROOT . '/core/system/systemerror.php';
require_once ROOT . '/core/system/systemevents.php';
require_once ROOT . '/core/system/systemmigration.php';
require_once ROOT . '/core/system/entrypoint.php';
require_once ROOT . '/core/system/url.php';
require_once ROOT . '/core/system/system.php';

// Core Logger
require_once ROOT . '/core/logger/logger.php';

// Core Storage Layer
require_once ROOT . '/core/storage/storageinterface.php';
require_once ROOT . '/core/storage/jsonstorage.php';
require_once ROOT . '/core/storage/sqlitestorage.php';
require_once ROOT . '/core/storage/mysqlstorage.php';
require_once ROOT . '/core/storage/storagemanager.php';

// Core Key Center
require_once ROOT . '/core/keycenter/keycenter.php';

// Core Module System
require_once ROOT . '/core/module/modulemanager.php';

// Core Cron
require_once ROOT . '/core/cron/cronmanager.php';

// Core Gateway
require_once ROOT . '/core/gateway/gatewayinterface.php';
require_once ROOT . '/core/gateway/bybit.php';

// Core Auth
require_once ROOT . '/core/auth/auth.php';
require_once ROOT . '/core/auth/authmanager.php';

// Core Router
require_once ROOT . '/core/router/middleware.php';
require_once ROOT . '/core/router/zones.php';
require_once ROOT . '/core/router/router.php';

// Core View Engine
require_once ROOT . '/core/view/viewengine.php';

// NOTE: System::init() is NOT called here.
// It must be called by the entrypoint (index.php, public/index.php, scripts/*)
// with proper options like: System::init(['root' => __DIR__])

/* RULES
- Purpose: Core bootstrap - loads all kernel classes in correct order
- Config sources: None (only loads classes)
- Paths: Uses ROOT constant (defined by entrypoint or detected)
- Logs: None
- Prohibitions:
  - NO System::init() call here - entrypoints must call it
  - NO business logic
  - NO autoloaders
*/
