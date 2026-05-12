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
$profilePath = $moduleDir . '/storage/profiles/early_impulse_growth_long/current_profile.json';
$profile = is_file($profilePath) ? (json_decode((string)@file_get_contents($profilePath), true) ?: []) : [];
$profileStatus = is_array($profile) ? (string)($profile['status'] ?? 'missing') : 'missing';
$profileRulesTotal = is_array($profile) ? count((array)($profile['rules'] ?? [])) : 0;
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
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">apply_learning_to_strategy_enabled</div>
      <div style="font-weight:600;"><?= $e((bool)($run['apply_learning_to_strategy_enabled'] ?? false) ? 'true' : 'false') ?></div>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">apply_learning_to_live_enabled</div>
      <div style="font-weight:600;"><?= $e((bool)($run['apply_learning_to_live_enabled'] ?? false) ? 'true' : 'false') ?></div>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">profile_status</div>
      <div style="font-weight:600;"><?= $e($profileStatus) ?></div>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">profile_rules_total</div>
      <div style="font-weight:600;"><?= $e($profileRulesTotal) ?></div>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">closed_outcomes_raw_loaded_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['closed_outcomes_raw_loaded_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">closed_outcomes_unique_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['closed_outcomes_unique_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">closed_outcomes_duplicates_skipped_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['closed_outcomes_duplicates_skipped_total'] ?? 0)) ?></div>
    </div>
  </div>
  <pre style="margin:0;background:#0f172a;color:#cbd5e1;padding:12px;border-radius:8px;overflow:auto;"><?= $e(json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
</div>
