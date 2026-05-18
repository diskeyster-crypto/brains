<?php

declare(strict_types=1);

/**
 * Confirmed Continuation Strategy — Admin Runtime Page
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

$signals      = $readJson($moduleDir . '/storage/signals.json',    []);
$candidates   = $readJson($moduleDir . '/storage/candidates.json', []);
$handoffQueue = $readJson($moduleDir . '/storage/bot_handoff_queue.json', []);
$runState     = $readJson($moduleDir . '/storage/run_state.json',  []);
$lastRun      = $readJson($moduleDir . '/storage/last_run.json',   []);

// Treat missing storage as not_run_yet — not as an error
$neverRun = !is_file($moduleDir . '/storage/last_run.json');
if ($neverRun && empty($lastRun)) {
    $lastRun = [
        'status'               => 'not_run_yet',
        'candidates_total'     => 0,
        'signals_total'        => 0,
        'handoff_ready_total'  => 0,
    ];
}
if ($neverRun && empty($runState)) {
    $runState = ['status' => 'not_run_yet'];
}

$activeSignals = array_filter($signals, fn($s) => !($s['stale'] ?? false) && ($s['active_final'] ?? false));
$longSignals   = array_filter($activeSignals, fn($s) => ($s['side'] ?? '') === 'long');
$shortSignals  = array_filter($activeSignals, fn($s) => ($s['side'] ?? '') === 'short');
?>
<style>
.cc-runtime { max-width: 1000px; }
.signal-row-long { border-left: 3px solid #22c55e; }
.signal-row-short { border-left: 3px solid #ef4444; }
.diag-badge { font-size: 10px; padding: 2px 6px; border-radius: 4px; }
</style>

<div class="cc-runtime">
  <h4 class="mb-3">Confirmed Continuation — Runtime</h4>

  <!-- Run state -->
  <div class="card mb-3">
    <div class="card-body py-2">
      <strong>Run state:</strong>
      <span class="ms-2"><?= htmlspecialchars((string)($runState['status'] ?? '—')) ?></span>
      <?php if (!empty($runState['finished_at'])): ?>
        &nbsp;· finished <?= htmlspecialchars($runState['finished_at']) ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Last run summary -->
  <?php if (!empty($lastRun)): ?>
  <div class="card mb-3">
    <div class="card-body">
      <h6 class="text-muted mb-2" style="font-size:11px;text-transform:uppercase">Last Run</h6>
      <div class="row g-2 text-center">
        <div class="col"><div class="text-muted" style="font-size:10px">Candidates</div><strong><?= (int)($lastRun['candidates_total'] ?? 0) ?></strong></div>
        <div class="col"><div class="text-muted" style="font-size:10px">Signals</div><strong><?= (int)($lastRun['signals_total'] ?? 0) ?></strong></div>
        <div class="col"><div class="text-muted" style="font-size:10px">Handoff Ready</div><strong><?= (int)($lastRun['handoff_ready_total'] ?? 0) ?></strong></div>
        <div class="col"><div class="text-muted" style="font-size:10px">Rejected</div><strong><?= (int)($lastRun['rejected_total'] ?? 0) ?></strong></div>
        <div class="col"><div class="text-muted" style="font-size:10px">OBC Checked</div><strong><?= (int)($lastRun['obc_checked_total'] ?? 0) ?></strong></div>
        <div class="col"><div class="text-muted" style="font-size:10px">OBC Blocked</div><strong><?= (int)($lastRun['obc_soft_demote_blocked_total'] ?? 0) ?></strong></div>
      </div>
      <?php if (!empty($lastRun['reject_reason_counts'])): ?>
        <div class="mt-2" style="font-size:11px">
          <strong>Reject reasons:</strong>
          <?php foreach ($lastRun['reject_reason_counts'] as $reason => $cnt): ?>
            <span class="badge bg-secondary ms-1"><?= htmlspecialchars($reason) ?>: <?= (int)$cnt ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Active signals -->
  <h5 class="mb-2">Active Signals (<?= count($activeSignals) ?>)</h5>
  <div class="row g-2 mb-2">
    <div class="col-auto"><span class="badge bg-success">LONG <?= count($longSignals) ?></span></div>
    <div class="col-auto"><span class="badge bg-danger">SHORT <?= count($shortSignals) ?></span></div>
  </div>

  <?php if (empty($activeSignals)): ?>
    <p class="text-muted">No active signals.</p>
  <?php else: ?>
    <table class="table table-sm table-hover">
      <thead>
        <tr>
          <th>Symbol</th><th>Side</th><th>Setup</th><th>Score</th>
          <th>Handoff</th><th>Executable</th><th>OBC</th><th>Trend Phase</th><th>Detected</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($activeSignals as $sig): ?>
          <tr class="signal-row-<?= htmlspecialchars($sig['side'] ?? 'long') ?>">
            <td><?= htmlspecialchars($sig['symbol'] ?? '') ?></td>
            <td><span class="badge bg-<?= ($sig['side'] ?? '') === 'long' ? 'success' : 'danger' ?>"><?= strtoupper($sig['side'] ?? '') ?></span></td>
            <td style="font-size:10px"><?= htmlspecialchars($sig['setup_class'] ?? '') ?></td>
            <td><?= number_format((float)($sig['candidate_quality_score'] ?? 0), 3) ?></td>
            <td><?= ($sig['handoff_ready'] ?? false) ? '<span class="badge bg-success">YES</span>' : '<span class="badge bg-secondary">NO</span>' ?></td>
            <td><?= ($sig['executable'] ?? false) ? '<span class="badge bg-primary">YES</span>' : '<span class="badge bg-secondary">NO</span>' ?></td>
            <td>
              <?php if ($sig['ob_soft_demoted'] ?? false): ?>
                <span class="badge bg-warning text-dark">DEMOTED</span>
              <?php elseif ($sig['ob_wall_checked'] ?? false): ?>
                <span class="badge bg-success">OK</span>
              <?php else: ?>
                <span class="badge bg-secondary">—</span>
              <?php endif; ?>
            </td>
            <td style="font-size:10px"><?= htmlspecialchars($sig['trend_phase'] ?? '') ?></td>
            <td style="font-size:10px"><?= htmlspecialchars((string)($sig['detected_at'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- Handoff queue -->
  <h5 class="mt-4 mb-2">Bot Handoff Queue (<?= count($handoffQueue) ?>)</h5>
  <?php if (empty($handoffQueue)): ?>
    <p class="text-muted">Queue is empty.</p>
  <?php else: ?>
    <table class="table table-sm">
      <thead><tr><th>Symbol</th><th>Side</th><th>Entry</th><th>Score</th><th>Setup</th></tr></thead>
      <tbody>
        <?php foreach ($handoffQueue as $item): ?>
          <tr>
            <td><?= htmlspecialchars($item['symbol'] ?? '') ?></td>
            <td><?= htmlspecialchars(strtoupper($item['side'] ?? '')) ?></td>
            <td><?= number_format((float)($item['entry_price'] ?? 0), 4) ?></td>
            <td><?= number_format((float)($item['candidate_quality_score'] ?? 0), 3) ?></td>
            <td style="font-size:10px"><?= htmlspecialchars($item['setup_class'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
