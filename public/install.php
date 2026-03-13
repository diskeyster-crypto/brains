<?php

declare(strict_types=1);

/**
 * Legacy Install Shim
 * 
 * This file exists for backward compatibility when users directly access
 * /public/install.php instead of using the router.
 * 
 * No actual installer logic here - just redirects to the router.
 */

// Determine base URL (might be /public or empty)
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$baseUrl = dirname($scriptName);
if ($baseUrl === '/' || $baseUrl === '\\') {
    $baseUrl = '';
}

// Set REQUEST_URI to the proper route for the router
$_SERVER['REQUEST_URI'] = $baseUrl . '/install';

// Include the main router entry point
require __DIR__ . '/index.php';

/* RULES
- Purpose: Legacy shim for direct /public/install.php access
- Config sources: None
- Paths: Only __DIR__ allowed as this is an entrypoint shim
- Logs: None
- Prohibitions:
  - No installer logic in this file
  - No database access
  - No session handling
  - Must proxy to index.php
*/
