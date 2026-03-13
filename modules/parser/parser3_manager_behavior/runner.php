<?php
declare(strict_types=1);

/**
 * Parser 3: Manager — Behavior Profiler (Runner)
 *
 * Recommended:
 *   cd <project_root> && php modules/parser/parser3_manager_behavior/runner.php
 */

$dir = getcwd();
$bootstrap = null;
$root = null;

while (is_string($dir) && $dir !== '' && $dir !== '/') {
    $candidate = $dir . '/core/bootstrap.php';
    if (is_file($candidate)) {
        $bootstrap = $candidate;
        $root = $dir;
        break;
    }
    $parent = preg_replace('~/[^/]+$~', '', $dir);
    if (!is_string($parent) || $parent === '' || $parent === $dir) {
        break;
    }
    $dir = $parent;
}

if ($bootstrap === null) {
    fwrite(STDERR, "ERROR: core/bootstrap.php not found. Run from project root.\n");
    exit(1);
}

require_once $bootstrap;

// Initialize the system (this also scans manifest.json and registers paths)
\Core\System\System::init(['root' => $root]);

require_once 'service.php';

$svc = new Parser3ManagerBehaviorService();
$result = $svc->execute();

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

/* RULES (Tredercopis Architecture)
--------------------------------------------------
- SystemPaths ONLY for real paths.
- No filesystem guessing. Use SystemPaths for paths.
- Outputs only into module storage.
- PHP 8.2+ safe (no dynamic properties).
-------------------------------------------------- */
