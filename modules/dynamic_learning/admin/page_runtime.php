<?php

declare(strict_types=1);

use Core\Auth\Auth;
use Core\System\System;

if (!Auth::check()) {
    header('Location: ' . System::adminUrl('login'));
    exit;
}

$moduleDir = dirname(__DIR__);
require_once $moduleDir . '/service.php';
$svc = \Modules\DynamicLearning\DynamicLearningService::instance($moduleDir);
$run = $svc->getLastRun();
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$baseUrl = rtrim(System::web('admin/dynamic_learning'), '/');
?>
<div style="max-width:1200px;display:grid;gap:12px;">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">
    <h3 style="margin:0;">Dynamic Learning — Runtime</h3>
    <div style="display:flex;gap:8px;">
      <a href="<?= $e($baseUrl) ?>/config" class="btn btn-sm">Config</a>
      <a href="<?= $e($baseUrl) ?>/stats" class="btn btn-sm">Stats</a>
      <form method="post" action="<?= $e($baseUrl) ?>/ajax" style="display:inline;">
        <input type="hidden" name="action" value="run_cycle">
        <button class="btn btn-sm" type="submit">Run cycle</button>
      </form>
    </div>
  </div>
  <pre style="margin:0;background:#0f172a;color:#cbd5e1;padding:12px;border-radius:8px;overflow:auto;"><?= $e(json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
</div>
