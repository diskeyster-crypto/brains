<?php
/**
 * Trading Bot Dashboard Tab
 * 
 * Dashboard content (same as index).
 */
use Core\System\SystemPaths;

$moduleBase = SystemPaths::instance()->get('system.trading_bot');
require $moduleBase . '/views/index.php';
