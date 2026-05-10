<?php

declare(strict_types=1);

use Core\Auth\Auth;
use Core\System\System;
use Core\System\SystemPaths;

if (!Auth::check()) {
    header('Location: ' . System::adminUrl('login'));
    exit;
}

$moduleDir = SystemPaths::instance()->get('strategy.early_impulse_growth_long');
$storageDir = $moduleDir . '/storage';
$eigUrl = rtrim(System::web('admin/strategy/early_impulse_growth_long'), '/');

$readJson = static function (string $file, mixed $default = []) use ($storageDir): mixed {
    $path = $storageDir . '/' . $file;
    if (!is_file($path)) {
        return $default;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return $default;
    }
    $decoded = json_decode($raw, true);
    return $decoded !== null ? $decoded : $default;
};

$lastRun = $readJson('last_run.json', []);
$cycleHistory = [];
$cyclePath = $storageDir . '/cycle_history.ndjson';
if (is_file($cyclePath)) {
    $fh = @fopen($cyclePath, 'r');
    if (is_resource($fh)) {
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (is_array($row)) {
                $cycleHistory[] = $row;
            }
        }
        fclose($fh);
    }
}
$cycleHistory = array_slice($cycleHistory, -200);

$acceptedExamples = is_array($lastRun['accepted_examples'] ?? null) ? (array)$lastRun['accepted_examples'] : [];
$bestRecoveryExamples = is_array($lastRun['best_recovery_examples'] ?? null) ? (array)$lastRun['best_recovery_examples'] : [];
$oiGrowthExamples = is_array($lastRun['open_interest_growth_examples'] ?? null) ? (array)$lastRun['open_interest_growth_examples'] : [];
$rejectReasonCounts = is_array($lastRun['reject_reason_counts'] ?? null) ? (array)$lastRun['reject_reason_counts'] : [];

$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fmtNum = static fn(mixed $v, int $p = 4): string => is_numeric($v) ? number_format((float)$v, $p) : '—';

$avgRecoveryGrowth = null;
$avgOiGrowth = null;
$recoveryVals = [];
$oiVals = [];
foreach ($acceptedExamples as $row) {
    if (is_numeric($row['recovery_growth_pct'] ?? null)) {
        $recoveryVals[] = (float)$row['recovery_growth_pct'];
    }
    if (is_numeric($row['open_interest_growth_pct'] ?? null)) {
        $oiVals[] = (float)$row['open_interest_growth_pct'];
    }
}
if ($recoveryVals !== []) {
    $avgRecoveryGrowth = array_sum($recoveryVals) / count($recoveryVals);
}
if ($oiVals !== []) {
    $avgOiGrowth = array_sum($oiVals) / count($oiVals);
}
?>
<style>
.eig-st-page { max-width: 1220px; }
.st-section { background: var(--card-bg,#1e293b); border: 1px solid var(--border-color,#334155); border-radius: 8px; padding: 20px; margin-bottom: 18px; }
.st-section h6 { color: #94a3b8; text-transform: uppercase; font-size: 11px; letter-spacing: .06em; margin-bottom: 12px; }
.st-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; margin-bottom: 10px; }
.st-box { background: rgba(255,255,255,.03); border: 1px solid var(--border-color,#334155); border-radius: 6px; padding: 10px 12px; }
.st-val { font-size: 20px; font-weight: 700; color: #e2e8f0; }
.st-lbl { font-size: 11px; color: #64748b; margin-top: 2px; }
</style>

<div class="eig-st-page">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:18px;">
    <h4 style="margin:0;font-size:18px;">Early Impulse Growth Long — Stats</h4>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="<?= $e($eigUrl) ?>/config" class="btn btn-sm" style="background:rgba(88,166,255,.10);color:#58a6ff;border:1px solid #58a6ff44;">Config</a>
      <a href="<?= $e($eigUrl) ?>/runtime" class="btn btn-sm" style="background:rgba(56,189,248,.10);color:#38bdf8;border:1px solid #38bdf844;">Runtime</a>
      <a href="<?= $e($eigUrl) ?>/stats" class="btn btn-sm" style="background:rgba(167,139,250,.15);color:#a78bfa;border:1px solid #a78bfa44;">Stats</a>
    </div>
  </div>

  <div class="st-section">
    <h6>Cycle overview</h6>
    <div class="st-grid">
      <div class="st-box"><div class="st-val"><?= $e(count($cycleHistory)) ?></div><div class="st-lbl">cycle_history_records</div></div>
      <div class="st-box"><div class="st-val"><?= $e((int)($lastRun['current_run_candidates_total'] ?? 0)) ?></div><div class="st-lbl">candidates_per_last_cycle</div></div>
      <div class="st-box"><div class="st-val"><?= $e((int)($lastRun['current_run_signals_total'] ?? 0)) ?></div><div class="st-lbl">signals_per_last_cycle</div></div>
      <div class="st-box"><div class="st-val"><?= $e((int)($lastRun['current_run_rejects_total'] ?? 0)) ?></div><div class="st-lbl">rejects_per_last_cycle</div></div>
      <div class="st-box"><div class="st-val"><?= $e($fmtNum($avgRecoveryGrowth, 4)) ?></div><div class="st-lbl">average_recovery_growth_pct</div></div>
      <div class="st-box"><div class="st-val"><?= $e($fmtNum($avgOiGrowth, 4)) ?></div><div class="st-lbl">average_open_interest_growth_pct</div></div>
    </div>
  </div>

  <div class="st-section">
    <h6>Cycle history (recent)</h6>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead>
          <tr style="border-bottom:1px solid var(--border-color,#334155);">
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">finished_at</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">current_run_candidates_total</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">current_run_signals_total</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">current_run_rejects_total</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">raw_strategy_passed_total</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (array_reverse(array_slice($cycleHistory, -30)) as $row): ?>
          <tr style="border-bottom:1px solid rgba(51,65,85,.5);">
            <td style="padding:3px 8px;"><?= $e(substr((string)($row['finished_at'] ?? '—'), 0, 19)) ?></td>
            <td style="padding:3px 8px;"><?= $e((int)($row['current_run_candidates_total'] ?? 0)) ?></td>
            <td style="padding:3px 8px;"><?= $e((int)($row['current_run_signals_total'] ?? 0)) ?></td>
            <td style="padding:3px 8px;"><?= $e((int)($row['current_run_rejects_total'] ?? 0)) ?></td>
            <td style="padding:3px 8px;"><?= $e((int)($row['raw_strategy_passed_total'] ?? 0)) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="st-section">
    <h6>Reject reason counts</h6>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead><tr style="border-bottom:1px solid var(--border-color,#334155);"><th style="text-align:left;padding:4px 8px;color:#94a3b8;">reason</th><th style="text-align:left;padding:4px 8px;color:#94a3b8;">count</th></tr></thead>
        <tbody>
          <?php foreach ($rejectReasonCounts as $reason => $cnt): ?>
          <tr style="border-bottom:1px solid rgba(51,65,85,.5);"><td style="padding:3px 8px;"><code><?= $e($reason) ?></code></td><td style="padding:3px 8px;"><?= $e((int)$cnt) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($rejectReasonCounts === []): ?><div class="note" style="padding-top:8px;">No reject reasons in last_run.</div><?php endif; ?>
    </div>
  </div>

  <div class="st-section">
    <h6>Best accepted examples by combined_recovery_score</h6>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead><tr style="border-bottom:1px solid var(--border-color,#334155);"><th style="text-align:left;padding:4px 8px;color:#94a3b8;">symbol</th><th style="text-align:left;padding:4px 8px;color:#94a3b8;">combined_recovery_score</th><th style="text-align:left;padding:4px 8px;color:#94a3b8;">recovery_growth_pct</th><th style="text-align:left;padding:4px 8px;color:#94a3b8;">open_interest_growth_pct</th></tr></thead>
        <tbody>
          <?php foreach ($bestRecoveryExamples as $row): ?>
          <tr style="border-bottom:1px solid rgba(51,65,85,.5);"><td style="padding:3px 8px;font-weight:600;"><?= $e($row['symbol'] ?? '—') ?></td><td style="padding:3px 8px;"><?= $e($fmtNum($row['combined_recovery_score'] ?? null, 4)) ?></td><td style="padding:3px 8px;"><?= $e($fmtNum($row['recovery_growth_pct'] ?? null, 4)) ?></td><td style="padding:3px 8px;"><?= $e($fmtNum($row['open_interest_growth_pct'] ?? null, 4)) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($bestRecoveryExamples === []): ?><div class="note" style="padding-top:8px;">No best_recovery_examples in last_run.</div><?php endif; ?>
    </div>
  </div>

  <div class="st-section">
    <h6>Top recovery_growth_pct examples</h6>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead><tr style="border-bottom:1px solid var(--border-color,#334155);"><th style="text-align:left;padding:4px 8px;color:#94a3b8;">symbol</th><th style="text-align:left;padding:4px 8px;color:#94a3b8;">recovery_growth_pct</th><th style="text-align:left;padding:4px 8px;color:#94a3b8;">recovery_duration_minutes</th></tr></thead>
        <tbody>
          <?php foreach ($bestRecoveryExamples as $row): ?>
          <tr style="border-bottom:1px solid rgba(51,65,85,.5);"><td style="padding:3px 8px;font-weight:600;"><?= $e($row['symbol'] ?? '—') ?></td><td style="padding:3px 8px;"><?= $e($fmtNum($row['recovery_growth_pct'] ?? null, 4)) ?></td><td style="padding:3px 8px;"><?= $e((string)($row['recovery_duration_minutes'] ?? '—')) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="st-section">
    <h6>Top open_interest_growth_pct examples</h6>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead><tr style="border-bottom:1px solid var(--border-color,#334155);"><th style="text-align:left;padding:4px 8px;color:#94a3b8;">symbol</th><th style="text-align:left;padding:4px 8px;color:#94a3b8;">open_interest_growth_pct</th><th style="text-align:left;padding:4px 8px;color:#94a3b8;">combined_recovery_score</th></tr></thead>
        <tbody>
          <?php foreach ($oiGrowthExamples as $row): ?>
          <tr style="border-bottom:1px solid rgba(51,65,85,.5);"><td style="padding:3px 8px;font-weight:600;"><?= $e($row['symbol'] ?? '—') ?></td><td style="padding:3px 8px;"><?= $e($fmtNum($row['open_interest_growth_pct'] ?? null, 4)) ?></td><td style="padding:3px 8px;"><?= $e($fmtNum($row['combined_recovery_score'] ?? null, 4)) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
