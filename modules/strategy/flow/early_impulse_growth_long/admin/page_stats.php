<?php

declare(strict_types=1);

/**
 * Early Impulse Growth Long — Admin Stats Page
 */

use Core\System\System;
use Core\System\SystemPaths;
use Core\Auth\Auth;

if (!Auth::check()) {
    header('Location: ' . System::adminUrl('login'));
    exit;
}

$moduleDir  = SystemPaths::instance()->get('strategy.early_impulse_growth_long');
$storageDir = $moduleDir . '/storage';
$eigUrl     = rtrim(System::web('admin/strategy/early_impulse_growth_long'), '/');

$readJson = static function (string $file, mixed $default = []) use ($storageDir): mixed {
    $path = $storageDir . '/' . $file;
    if (!file_exists($path)) {
        return $default;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return $default;
    }
    $decoded = json_decode($raw, true);
    return $decoded !== null ? $decoded : $default;
};

$lastRun   = $readJson('last_run.json', []);
$signals   = $readJson('signals.json', []);
$candidates = $readJson('candidates.json', []);

// Cycle history for throughput stats
$cycleHistory = [];
$cycleHistoryPath = $storageDir . '/cycle_history.ndjson';
if (is_file($cycleHistoryPath)) {
    $fh = @fopen($cycleHistoryPath, 'r');
    if ($fh !== false) {
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
    $cycleHistory = array_slice($cycleHistory, -100);
}

$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fmtBool = static function (mixed $v): string {
    if ($v === true || $v === 1) {
        return '<span style="color:#22c55e">true</span>';
    }
    if ($v === false || $v === 0) {
        return '<span style="color:#94a3b8">false</span>';
    }
    return '<span style="color:#94a3b8">—</span>';
};

// Aggregate from cycle history
$totalCycles      = count($cycleHistory);
$totalCandidates  = 0;
$totalSignals     = 0;
$totalHandoff     = 0;
$totalRejects     = 0;
$totalPricePass   = 0;
$totalOiPass      = 0;
$totalOiMissing   = 0;
$totalInsuffData  = 0;
$totalStaleData   = 0;
$totalSrcError    = 0;
$rejectReasonAgg  = [];

foreach ($cycleHistory as $entry) {
    $totalCandidates += (int)($entry['candidates_total'] ?? 0);
    $totalSignals    += (int)($entry['signals_total'] ?? 0);
    $totalHandoff    += (int)($entry['handoff_ready_total'] ?? 0);
    $totalRejects    += (int)($entry['rejects_total'] ?? 0);
    $totalPricePass  += (int)($entry['price_impulse_pass_total'] ?? 0);
    $totalOiPass     += (int)($entry['oi_growth_pass_total'] ?? 0);
    $totalOiMissing  += (int)($entry['oi_missing_allowed_total'] ?? 0);
    $totalInsuffData += (int)($entry['insufficient_data_total'] ?? 0);
    $totalStaleData  += (int)($entry['stale_data_total'] ?? 0);
    $totalSrcError   += (int)($entry['data_source_error_total'] ?? 0);
    foreach ((array)($entry['reject_reason_counts'] ?? []) as $reason => $cnt) {
        $rejectReasonAgg[$reason] = ($rejectReasonAgg[$reason] ?? 0) + (int)$cnt;
    }
}

$storageExists = is_dir($storageDir);
?>
<style>
.eig-stats-page { max-width: 860px; }
.st-section { background: var(--card-bg,#1e293b); border: 1px solid var(--border-color,#334155); border-radius: 8px; padding: 20px; margin-bottom: 20px; }
.st-section h6 { color: #94a3b8; text-transform: uppercase; font-size: 11px; letter-spacing: .05em; margin-bottom: 14px; }
.st-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px,1fr)); gap: 10px; margin-bottom: 14px; }
.st-box  { background: rgba(255,255,255,.03); border: 1px solid var(--border-color,#334155); border-radius: 6px; padding: 10px 14px; }
.st-val  { font-size: 22px; font-weight: 700; color: #e2e8f0; word-break: break-all; }
.st-lbl  { font-size: 11px; color: #64748b; margin-top: 2px; }
.st-kv td { font-size: 12px; padding: 4px 8px; }
</style>

<div class="eig-stats-page">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:8px;">
    <h4 style="margin:0;font-size:18px;">Early Impulse Growth Long — Stats</h4>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="<?= $e($eigUrl) ?>/config" class="btn btn-sm" style="background:rgba(88,166,255,.10);color:#58a6ff;border:1px solid #58a6ff44;">Config</a>
      <a href="<?= $e($eigUrl) ?>/runtime" class="btn btn-sm" style="background:rgba(56,189,248,.10);color:#38bdf8;border:1px solid #38bdf844;">Runtime</a>
      <a href="<?= $e($eigUrl) ?>/stats" class="btn btn-sm" style="background:rgba(167,139,250,.15);color:#a78bfa;border:1px solid #a78bfa44;">Stats</a>
    </div>
  </div>

  <?php if (!$storageExists): ?>
  <div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.3);border-radius:6px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#f59e0b;">
    Storage directory does not exist yet. Stats will appear after the first run.
  </div>
  <?php endif; ?>

  <!-- Last run summary -->
  <div class="st-section">
    <h6>Last Run Summary</h6>
    <div class="st-grid">
      <div class="st-box">
        <div class="st-val"><?= $e((int)($lastRun['candidates_total'] ?? 0)) ?></div>
        <div class="st-lbl">candidates_total</div>
      </div>
      <div class="st-box">
        <div class="st-val"><?= $e((int)($lastRun['signals_total'] ?? 0)) ?></div>
        <div class="st-lbl">signals_total</div>
      </div>
      <div class="st-box">
        <div class="st-val"><?= $e((int)($lastRun['handoff_ready_total'] ?? 0)) ?></div>
        <div class="st-lbl">handoff_ready</div>
      </div>
      <div class="st-box">
        <div class="st-val"><?= $e((int)($lastRun['rejects_total'] ?? 0)) ?></div>
        <div class="st-lbl">rejects_total</div>
      </div>
      <div class="st-box">
        <div class="st-val"><?= $e((int)($lastRun['universe_total'] ?? 0)) ?></div>
        <div class="st-lbl">universe_total</div>
      </div>
      <div class="st-box">
        <div class="st-val"><?= $e((int)($lastRun['batch_symbols_total'] ?? 0)) ?></div>
        <div class="st-lbl">batch_symbols</div>
      </div>
    </div>
    <table class="st-kv">
      <tr><td style="color:#64748b">price_impulse_pass_total</td><td><?= $e((int)($lastRun['price_impulse_pass_total'] ?? 0)) ?></td>
          <td style="color:#64748b;padding-left:16px">oi_growth_pass_total</td><td><?= $e((int)($lastRun['oi_growth_pass_total'] ?? 0)) ?></td></tr>
      <tr><td style="color:#64748b">oi_missing_allowed_total</td><td><?= $e((int)($lastRun['oi_missing_allowed_total'] ?? 0)) ?></td>
          <td style="color:#64748b;padding-left:16px">oi_missing_blocked_total</td><td><?= $e((int)($lastRun['oi_missing_blocked_total'] ?? 0)) ?></td></tr>
      <tr><td style="color:#64748b">insufficient_data_total</td><td><?= $e((int)($lastRun['insufficient_data_total'] ?? 0)) ?></td>
          <td style="color:#64748b;padding-left:16px">stale_data_total</td><td><?= $e((int)($lastRun['stale_data_total'] ?? 0)) ?></td></tr>
      <tr><td style="color:#64748b">data_source_error_total</td><td><?= $e((int)($lastRun['data_source_error_total'] ?? 0)) ?></td>
          <td style="color:#64748b;padding-left:16px">filter_engine_checked_total</td><td><?= $e((int)($lastRun['filter_engine_checked_total'] ?? 0)) ?></td></tr>
      <tr><td style="color:#64748b">filter_engine_blocked_total</td><td><?= $e((int)($lastRun['filter_engine_blocked_total'] ?? 0)) ?></td>
          <td style="color:#64748b;padding-left:16px">filter_engine_diagnostic_only_total</td><td><?= $e((int)($lastRun['filter_engine_diagnostic_only_total'] ?? 0)) ?></td></tr>
    </table>
    <?php if (!empty($lastRun['reject_reason_counts'])): ?>
    <div style="margin-top:12px;">
      <div style="font-size:11px;color:#94a3b8;margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em;">Reject Reasons (last run)</div>
      <table class="st-kv">
        <?php foreach ((array)$lastRun['reject_reason_counts'] as $reason => $cnt): ?>
        <tr><td style="color:#64748b"><?= $e($reason) ?></td><td><?= $e($cnt) ?></td></tr>
        <?php endforeach; ?>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Cumulative from cycle history -->
  <?php if ($totalCycles > 0): ?>
  <div class="st-section">
    <h6>Cumulative (last <?= $e($totalCycles) ?> cycles from cycle_history.ndjson)</h6>
    <div class="st-grid">
      <div class="st-box">
        <div class="st-val"><?= $e($totalCandidates) ?></div>
        <div class="st-lbl">candidates</div>
      </div>
      <div class="st-box">
        <div class="st-val"><?= $e($totalSignals) ?></div>
        <div class="st-lbl">signals</div>
      </div>
      <div class="st-box">
        <div class="st-val"><?= $e($totalHandoff) ?></div>
        <div class="st-lbl">handoff_ready</div>
      </div>
      <div class="st-box">
        <div class="st-val"><?= $e($totalRejects) ?></div>
        <div class="st-lbl">rejects</div>
      </div>
      <div class="st-box">
        <div class="st-val"><?= $e($totalPricePass) ?></div>
        <div class="st-lbl">price_pass</div>
      </div>
      <div class="st-box">
        <div class="st-val"><?= $e($totalOiPass) ?></div>
        <div class="st-lbl">oi_pass</div>
      </div>
    </div>
    <table class="st-kv">
      <tr><td style="color:#64748b">oi_missing_allowed</td><td><?= $e($totalOiMissing) ?></td>
          <td style="color:#64748b;padding-left:16px">insufficient_data</td><td><?= $e($totalInsuffData) ?></td></tr>
      <tr><td style="color:#64748b">stale_data</td><td><?= $e($totalStaleData) ?></td>
          <td style="color:#64748b;padding-left:16px">data_source_error</td><td><?= $e($totalSrcError) ?></td></tr>
    </table>
    <?php if (!empty($rejectReasonAgg)): ?>
    <div style="margin-top:12px;">
      <div style="font-size:11px;color:#94a3b8;margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em;">Reject Reasons (cumulative)</div>
      <table class="st-kv">
        <?php arsort($rejectReasonAgg); foreach ($rejectReasonAgg as $reason => $cnt): ?>
        <tr><td style="color:#64748b"><?= $e($reason) ?></td><td><?= $e($cnt) ?></td></tr>
        <?php endforeach; ?>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Active signals sample -->
  <?php if (!empty($signals)): ?>
  <div class="st-section">
    <h6>Active Signals (<?= $e(count($signals)) ?> stored)</h6>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead>
          <tr style="border-bottom:1px solid var(--border-color,#334155);">
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">symbol</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">detected_at</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">price_change%</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">oi_growth%</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">score</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">handoff</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (array_slice(array_reverse($signals), 0, 20) as $sig): ?>
          <tr style="border-bottom:1px solid rgba(51,65,85,.5);">
            <td style="padding:3px 8px;font-weight:600;"><?= $e($sig['symbol'] ?? '—') ?></td>
            <td style="padding:3px 8px;color:#64748b;"><?= $e(substr((string)($sig['detected_at'] ?? '—'), 0, 19)) ?></td>
            <td style="padding:3px 8px;color:<?= (float)($sig['price_change_pct'] ?? 0) >= 0 ? '#22c55e' : '#f87171' ?>;">
              <?= $e(number_format((float)($sig['price_change_pct'] ?? 0), 3)) ?>%
            </td>
            <td style="padding:3px 8px;">
              <?= $sig['open_interest_growth_pct'] !== null ? $e(number_format((float)$sig['open_interest_growth_pct'], 3)) . '%' : '<span style="color:#64748b">n/a</span>' ?>
            </td>
            <td style="padding:3px 8px;"><?= $e(number_format((float)($sig['combined_impulse_score'] ?? 0), 4)) ?></td>
            <td style="padding:3px 8px;"><?= $fmtBool($sig['handoff_ready'] ?? false) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>
