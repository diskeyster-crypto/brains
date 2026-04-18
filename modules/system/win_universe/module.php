<?php
declare(strict_types=1);

/**
 * Win Universe Module Bootstrap
 *
 * Loads service and controller for the win_universe module.
 * This module is a standalone shadow-mode observer.
 * It does NOT integrate with Smart Brain, Trading Bot, or Profit Manager.
 */

$__winUniverseBase = __DIR__;

require_once $__winUniverseBase . '/lib/win_universe_engine.php';
require_once $__winUniverseBase . '/service.php';
require_once $__winUniverseBase . '/controller.php';
