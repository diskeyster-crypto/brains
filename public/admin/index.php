<?php
/**
 * Admin Directory Index
 * 
 * Redirects to the admin panel when accessing /public/admin/ directly.
 * This prevents 403 Forbidden errors on directory access.
 */

// Redirect to the main admin panel
header('Location: ../index.php');
exit;
