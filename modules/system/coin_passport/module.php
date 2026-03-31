<?php
declare(strict_types=1);

/**
 * Coin Passport Module Bootstrap
 *
 * Loads service and controller for the coin_passport module.
 * This module is the single source of truth for per-symbol coin passports.
 * Smart Brain acts as UI/control-plane only; all passport storage and
 * computation lives here.
 */

$__coinPassportBase = __DIR__;

require_once $__coinPassportBase . '/service.php';
require_once $__coinPassportBase . '/controller.php';
