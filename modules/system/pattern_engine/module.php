<?php
declare(strict_types=1);

/**
 * Pattern Engine Module Bootstrap
 *
 * Loads service and controller.
 * This module is standalone — it does NOT depend on Smart Brain at load time.
 * Coin Passport passports are consumed at runtime via file path.
 */

$__patternEngineBase = __DIR__;

require_once $__patternEngineBase . '/service.php';
require_once $__patternEngineBase . '/controller.php';
