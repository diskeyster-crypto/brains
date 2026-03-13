<?php
/**
 * Admin Directory Index
 * 
 * Redirects to the admin panel when accessing /public/admin/ directly.
 * This prevents 403 Forbidden errors on directory access.
 */

// Detect base URL from script path
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
// Script is at /public/admin/index.php, so base is /public (or empty if at root)
$basePath = dirname(dirname($scriptName));
if ($basePath === '/' || $basePath === '.') {
    $basePath = '';
}

// Sanitize: only allow alphanumeric, hyphens, underscores and forward slashes
$basePath = preg_replace('/[^a-zA-Z0-9\-_\/]/', '', $basePath);

// Redirect to the admin panel route
header('Location: ' . $basePath . '/admin');
exit;