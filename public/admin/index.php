<?php
/**
 * Admin Directory Front Controller Fallback
 *
 * If the web server resolves /public/admin/* to this physical directory,
 * route handling must still be delegated to the main front controller
 * without dropping the original path (e.g. /admin/system).
 */

require dirname(__DIR__) . '/index.php';
