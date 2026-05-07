<?php

declare(strict_types=1);

/**
 * Confirmed Continuation Strategy — Admin Config Page
 */

use Core\System\System;
use Core\System\SystemPaths;
use Core\Auth\Auth;

if (!Auth::check()) {
    header('Location: ' . System::adminUrl('login'));
    exit;
}

$moduleDir = SystemPaths::instance()->get('strategy.confirmed_continuation');

require_once $moduleDir . '/service.php';
require_once $moduleDir . '/bootstrap.php';

use Modules\Strategy\ConfirmedContinuation\ConfirmedContinuationService;
use Modules\Strategy\ConfirmedContinuation\ConfirmedContinuationBootstrap;

$service = ConfirmedContinuationService::instance($moduleDir);

try {
    $boot         = ConfirmedContinuationBootstrap::instance($moduleDir)->load();
    $config       = $boot['config'];
    $configValid  = $boot['valid'];
    $configErrors = $boot['errors'];
} catch (\Throwable $e) {
    $config       = [];
    $configValid  = false;
    $configErrors = [$e->getMessage()];
}

$schema = require $moduleDir . '/config/schema.php';

$ccUrl  = rtrim(System::web('admin/strategy/confirmed_continuation'), '/');
$ajaxUrl = System::web('admin/strategy/confirmed_continuation/ajax');

$fEnabled  = (bool)($config['enabled']  ?? false);
$fSideMode = (string)($config['side_mode'] ?? 'all');
?>
<style>
.cc-config-page { max-width: 860px; }
.cfg-section { background: var(--card-bg,#1e293b); border: 1px solid var(--border-color,#334155); border-radius: 8px; padding: 20px; margin-bottom: 20px; }
.cfg-section h6 { color: #94a3b8; text-transform: uppercase; font-size: 11px; letter-spacing: .05em; margin-bottom: 14px; }
.effective-cfg th, .effective-cfg td { font-size: 12px; padding: 4px 8px; }
</style>

<div class="cc-config-page">
  <h4 class="mb-3">Confirmed Continuation — Config</h4>

  <?php if (!$configValid): ?>
    <div class="alert alert-danger">
      <strong>Config errors:</strong>
      <ul class="mb-0">
        <?php foreach ($configErrors as $err): ?>
          <li><?= htmlspecialchars($err) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="cfg-section">
    <h6>Effective Config</h6>
    <table class="table table-sm effective-cfg">
      <tbody>
        <?php foreach ($config as $k => $v): ?>
          <tr>
            <td class="text-muted"><?= htmlspecialchars($k) ?></td>
            <td><?= htmlspecialchars(is_bool($v) ? ($v ? 'true' : 'false') : (string)$v) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="cfg-section">
    <h6>Quick Controls</h6>
    <form method="post" action="<?= htmlspecialchars($ajaxUrl) ?>">
      <input type="hidden" name="action" value="save_config">
      <div class="row g-2 mb-3">
        <div class="col-auto">
          <label class="form-label text-muted" style="font-size:11px">Enabled</label>
          <select name="enabled" class="form-select form-select-sm">
            <option value="1" <?= $fEnabled ? 'selected' : '' ?>>Yes</option>
            <option value="0" <?= !$fEnabled ? 'selected' : '' ?>>No</option>
          </select>
        </div>
        <div class="col-auto">
          <label class="form-label text-muted" style="font-size:11px">Side Mode</label>
          <select name="side_mode" class="form-select form-select-sm">
            <option value="all"   <?= $fSideMode === 'all'   ? 'selected' : '' ?>>All</option>
            <option value="long"  <?= $fSideMode === 'long'  ? 'selected' : '' ?>>Long only</option>
            <option value="short" <?= $fSideMode === 'short' ? 'selected' : '' ?>>Short only</option>
          </select>
        </div>
        <div class="col-auto align-self-end">
          <button type="submit" class="btn btn-sm btn-primary">Save</button>
        </div>
      </div>
    </form>
  </div>

  <div class="cfg-section">
    <h6>Queue Run</h6>
    <form method="post" action="<?= htmlspecialchars($ajaxUrl) ?>">
      <input type="hidden" name="action" value="queue_run">
      <button type="submit" class="btn btn-sm btn-outline-secondary">Queue Universe Scan</button>
    </form>
  </div>
</div>
