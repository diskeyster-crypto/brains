<?php
declare(strict_types=1);

/**
 * AI Shadow Module Bootstrap
 *
 * Loads service and controller for the ai_shadow module.
 * This module is standalone and isolated — it does NOT touch any other module.
 */

$__aiShadowBase = __DIR__;

require_once $__aiShadowBase . '/service.php';
require_once $__aiShadowBase . '/controller.php';
