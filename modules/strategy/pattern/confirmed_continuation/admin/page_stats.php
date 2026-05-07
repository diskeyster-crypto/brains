<?php

declare(strict_types=1);

/**
 * Confirmed Continuation Strategy — Admin Stats Page
 */

use Core\System\System;
use Core\System\SystemPaths;
use Core\Auth\Auth;

if (!Auth::check()) {
    header('Location: ' . System::adminUrl('login'));
    exit;
}

$moduleDir = SystemPaths::instance()->get('strategy.confirmed_continuation');

$readJson = function (string $path, mixed $default = []) {
    if (!is_file($path)) {
        return $default;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $default;
    }
    $decoded = @json_decode($raw, true);
    return ($decoded !== null) ? $decoded : $default;
};

$lastRun = $readJson($moduleDir . '/storage/last_run.json',   []);
$rejects = $readJson($moduleDir . '/storage/rejects.json',    []);
$signals = $readJson($moduleDir . '/storage/signals.json',    []);

$staleCount  = count(array_filter($signals, fn($s) => ($s['stale'] ?? false)));
$activeCount = count(array_filter($signals, fn($s) => !($s['stale'] ?? false) && ($s['active_final'] ?? false)));
?>
<style>
.cc-stats { max-width: 900px; }
.stat-card { background: var(--card-bg,#1e293b); border: 1px solid var(--border-color,#334155); border-radius: 8px; padding: 16px; }
</style>

<div class="cc-stats">
  <h4 class="mb-3">Confirmed Continuation — Stats</h4>

  <div class="row g-3 mb-4">
    <div class="col-sm-3">
      <div class="stat-card text-center">
        <div class="text-muted" style="font-size:10px;text-transform:uppercase">Total Signals</div>
        <div class="fs-4 fw-bold"><?= count($signals) ?></div>
      </div>
    </div>
    <div class="col-sm-3">
      <div class="stat-card text-center">
        <div class="text-muted" style="font-size:10px;text-transform:uppercase">Active</div>
        <div class="fs-4 fw-bold text-success"><?= $activeCount ?></div>
      </div>
    </div>
    <div class="col-sm-3">
      <div class="stat-card text-center">
        <div class="text-muted" style="font-size:10px;text-transform:uppercase">Stale</div>
        <div class="fs-4 fw-bold text-muted"><?= $staleCount ?></div>
      </div>
    </div>
    <div class="col-sm-3">
      <div class="stat-card text-center">
        <div class="text-muted" style="font-size:10px;text-transform:uppercase">Rejects (stored)</div>
        <div class="fs-4 fw-bold text-warning"><?= count($rejects) ?></div>
      </div>
    </div>
  </div>

  <?php if (!empty($lastRun)): ?>
  <div class="stat-card mb-4">
    <h6 class="text-muted mb-3" style="font-size:11px;text-transform:uppercase">Last Run Diagnostics</h6>
    <table class="table table-sm mb-0">
      <tbody>
        <?php foreach ([
          'started_at', 'finished_at', 'duration_ms',
          'candidates_total', 'long_candidates_total', 'short_candidates_total',
          'signals_total', 'long_signals_total', 'short_signals_total',
          'handoff_ready_total', 'rejected_total',
          'obc_checked_total', 'obc_soft_demote_blocked_total',
          'strategy_is_environment_neutral', 'execution_mode_used_for_selection',
        ] as $key): ?>
          <?php if (array_key_exists($key, $lastRun)): ?>
            <tr>
              <td class="text-muted" style="width:50%;font-size:11px"><?= htmlspecialchars($key) ?></td>
              <td style="font-size:11px"><?= htmlspecialchars(is_bool($lastRun[$key]) ? ($lastRun[$key] ? 'true' : 'false') : (string)$lastRun[$key]) ?></td>
            </tr>
          <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php if (!empty($lastRun['reject_reason_counts'])): ?>
      <h6 class="text-muted mt-3 mb-1" style="font-size:11px">Reject Reason Counts</h6>
      <?php foreach ($lastRun['reject_reason_counts'] as $reason => $cnt): ?>
        <span class="badge bg-secondary me-1"><?= htmlspecialchars($reason) ?>: <?= (int)$cnt ?></span>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Recent rejects -->
  <h5 class="mb-2">Recent Rejects</h5>
  <?php $recentRejects = array_slice(array_reverse($rejects), 0, 20); ?>
  <?php if (empty($recentRejects)): ?>
    <p class="text-muted">No rejects stored.</p>
  <?php else: ?>
    <table class="table table-sm">
      <thead><tr><th>Symbol</th><th>Side</th><th>Setup</th><th>Reason</th><th>Stage</th><th>Time</th></tr></thead>
      <tbody>
        <?php foreach ($recentRejects as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['symbol'] ?? '') ?></td>
            <td><?= htmlspecialchars($r['side'] ?? '') ?></td>
            <td style="font-size:10px"><?= htmlspecialchars($r['setup_class'] ?? '') ?></td>
            <td style="font-size:10px"><?= htmlspecialchars($r['reject_reason'] ?? '') ?></td>
            <td style="font-size:10px"><?= htmlspecialchars($r['failed_stage'] ?? '') ?></td>
            <td style="font-size:10px"><?= htmlspecialchars((string)($r['rejected_at'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
