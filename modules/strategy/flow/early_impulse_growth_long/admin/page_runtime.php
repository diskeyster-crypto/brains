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
$ajaxUrl = $eigUrl . '/ajax';

$uiStorageWarnings = [];
$storageWarningIfLarge = static function (string $path, int $maxBytes) use (&$uiStorageWarnings): ?array {
    if (!is_file($path)) {
        return null;
    }
    $size = (int)@filesize($path);
    if ($size <= $maxBytes) {
        return null;
    }
    $warning = [
        'file_too_large_for_ui' => true,
        'file' => basename($path),
        'file_size_mb' => round($size / 1048576, 3),
        'max_allowed_mb' => round($maxBytes / 1048576, 3),
    ];
    $uiStorageWarnings[] = $warning;
    return $warning;
};
$safeJsonReadLimited = static function (string $path, int $maxBytes, mixed $default = []) use ($storageWarningIfLarge): mixed {
    if (!is_file($path)) {
        return $default;
    }
    if ($storageWarningIfLarge($path, $maxBytes) !== null) {
        return $default;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return $default;
    }
    $decoded = json_decode($raw, true);
    return $decoded !== null ? $decoded : $default;
};

$uiJsonMaxBytes = 2 * 1024 * 1024;
$runState = $safeJsonReadLimited($storageDir . '/run_state.json', $uiJsonMaxBytes, []);
$lastRun = $safeJsonReadLimited($storageDir . '/last_run.json', $uiJsonMaxBytes, []);
$storageExists = is_dir($storageDir);

$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fmtNum = static fn(mixed $v, int $p = 3): string => is_numeric($v) ? number_format((float)$v, $p) : '—';
$fmtBool = static fn(mixed $v): string => $v ? '<span style="color:#22c55e">true</span>' : '<span style="color:#94a3b8">false</span>';

$status = (string)($runState['status'] ?? 'idle');
$statusColor = match ($status) {
    'running' => '#22c55e',
    'queued' => '#f59e0b',
    'done' => '#3b82f6',
    default => '#64748b',
};

$acceptedExamples = is_array($lastRun['accepted_examples'] ?? null) ? (array)$lastRun['accepted_examples'] : [];
$nearPassExamples = is_array($lastRun['near_pass_examples'] ?? null) ? (array)$lastRun['near_pass_examples'] : [];
$rejectedExamples = is_array($lastRun['rejected_examples'] ?? null) ? (array)$lastRun['rejected_examples'] : [];
$bestRecoveryExamples = is_array($lastRun['best_recovery_examples'] ?? null) ? (array)$lastRun['best_recovery_examples'] : [];
$openInterestExamples = is_array($lastRun['open_interest_growth_examples'] ?? null) ? (array)$lastRun['open_interest_growth_examples'] : [];
$accelExamples = is_array($lastRun['current_acceleration_examples'] ?? null) ? (array)$lastRun['current_acceleration_examples'] : [];

// Phase-based examples (new)
$dumpExamples = is_array($lastRun['dump_examples'] ?? null) ? (array)$lastRun['dump_examples'] : [];
$stabilizationExamples = is_array($lastRun['stabilization_examples'] ?? null) ? (array)$lastRun['stabilization_examples'] : [];
$smoothGrowthExamples = is_array($lastRun['smooth_growth_examples'] ?? null) ? (array)$lastRun['smooth_growth_examples'] : [];
$earlyEntryExamples = is_array($lastRun['early_entry_examples'] ?? null) ? (array)$lastRun['early_entry_examples'] : [];
$stabilizingExamples = is_array($lastRun['stabilizing_examples'] ?? null) ? (array)$lastRun['stabilizing_examples'] : [];
$confirmedLaterExamples = is_array($lastRun['confirmed_later_examples'] ?? null) ? (array)$lastRun['confirmed_later_examples'] : [];
$lateSpikeExamples = is_array($lastRun['late_spike_examples'] ?? null) ? (array)$lastRun['late_spike_examples'] : [];
$extendedExamples = is_array($lastRun['extended_examples'] ?? null) ? (array)$lastRun['extended_examples'] : [];
$coinContextExamples = is_array($lastRun['coin_context_examples'] ?? null) ? (array)$lastRun['coin_context_examples'] : [];
$coinContextPhaseCounts = is_array($lastRun['coin_context_phase_counts'] ?? null) ? (array)$lastRun['coin_context_phase_counts'] : [];
$coinContextTrendCounts = is_array($lastRun['coin_context_trend_1h_counts'] ?? null) ? (array)$lastRun['coin_context_trend_1h_counts'] : [];
$coinContextQualityCounts = is_array($lastRun['coin_context_quality_counts'] ?? null) ? (array)$lastRun['coin_context_quality_counts'] : [];
$waveQualityFilterExamples = is_array($lastRun['wave_quality_filter_examples'] ?? null) ? (array)$lastRun['wave_quality_filter_examples'] : [];
$orderbookFilterExamples = is_array($lastRun['orderbook_filter_examples'] ?? null) ? (array)$lastRun['orderbook_filter_examples'] : [];
$watchRecheckExamples = is_array($lastRun['watch_recheck_examples'] ?? null) ? (array)$lastRun['watch_recheck_examples'] : [];
?>
<style>
.eig-rt-page { max-width: 1220px; }
.rt-section { background: var(--card-bg,#1e293b); border: 1px solid var(--border-color,#334155); border-radius: 8px; padding: 20px; margin-bottom: 18px; }
.rt-section h6 { color: #94a3b8; text-transform: uppercase; font-size: 11px; letter-spacing: .06em; margin-bottom: 12px; }
.rt-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 10px; margin-bottom: 10px; }
.rt-box { background: rgba(255,255,255,.03); border: 1px solid var(--border-color,#334155); border-radius: 6px; padding: 10px 12px; }
.rt-val { font-size: 20px; font-weight: 700; color: #e2e8f0; }
.rt-lbl { font-size: 11px; color: #64748b; margin-top: 2px; }
.rt-kv td { font-size: 12px; padding: 4px 8px; }
</style>

<div class="eig-rt-page">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:18px;">
    <h4 style="margin:0;font-size:18px;">Early Impulse Growth Long — Runtime</h4>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="<?= $e($eigUrl) ?>/config" class="btn btn-sm" style="background:rgba(88,166,255,.10);color:#58a6ff;border:1px solid #58a6ff44;">Config</a>
      <a href="<?= $e($eigUrl) ?>/runtime" class="btn btn-sm" style="background:rgba(56,189,248,.15);color:#38bdf8;border:1px solid #38bdf844;">Runtime</a>
      <a href="<?= $e($eigUrl) ?>/stats" class="btn btn-sm" style="background:rgba(167,139,250,.10);color:#a78bfa;border:1px solid #a78bfa44;">Stats</a>
    </div>
  </div>

  <?php if (!$storageExists): ?>
  <div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.3);border-radius:6px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#f59e0b;">
    Storage directory does not exist yet. Run the first tick to initialise runtime files.
  </div>
  <?php endif; ?>
  <?php if ($uiStorageWarnings !== []): ?>
  <div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.3);border-radius:6px;padding:12px 16px;margin-bottom:16px;font-size:12px;color:#f59e0b;">
    <strong>UI safe-read warnings:</strong>
    <ul style="margin:6px 0 0 18px;">
      <?php foreach ($uiStorageWarnings as $w): ?>
      <li><code><?= $e((string)($w['file'] ?? 'unknown')) ?></code> (<?= $e((string)($w['file_size_mb'] ?? '0')) ?> MB) exceeded UI max <?= $e((string)($w['max_allowed_mb'] ?? '0')) ?> MB</li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <div class="rt-section">
    <h6>Runtime summary (visual review)</h6>
    <div class="rt-grid">
      <div class="rt-box"><div class="rt-val" style="color:<?= $e($statusColor) ?>;font-size:16px;"><?= $e($status) ?></div><div class="rt-lbl">status</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($runState['registry_cursor'] ?? 0)) ?></div><div class="rt-lbl">current_batch_cursor</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['current_run_processed_total'] ?? 0)) ?></div><div class="rt-lbl">processed_symbols</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['current_run_evaluated_total'] ?? ($lastRun['current_run_processed_total'] ?? 0))) ?></div><div class="rt-lbl">current_run_evaluated_total</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($runState['universe_total'] ?? $lastRun['universe_total'] ?? 0)) ?></div><div class="rt-lbl">universe_total</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['current_run_candidates_total'] ?? 0)) ?></div><div class="rt-lbl">current_run_candidates_total</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['current_run_near_pass_total'] ?? 0)) ?></div><div class="rt-lbl">current_run_near_pass_total</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['current_run_signals_total'] ?? 0)) ?></div><div class="rt-lbl">current_run_signals_total</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['current_run_rejects_total'] ?? 0)) ?></div><div class="rt-lbl">current_run_rejects_total</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['stored_evaluated_total'] ?? 0)) ?></div><div class="rt-lbl">stored_evaluated_total</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['stored_candidates_total'] ?? 0)) ?></div><div class="rt-lbl">stored_candidates_total</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['stored_near_pass_total'] ?? 0)) ?></div><div class="rt-lbl">stored_near_pass_total</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['stored_signals_total'] ?? 0)) ?></div><div class="rt-lbl">stored_signals_total</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['stored_rejects_total'] ?? 0)) ?></div><div class="rt-lbl">stored_rejects_total</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['recovery_window_minutes'] ?? 0)) ?></div><div class="rt-lbl">selected_recovery_window</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['prior_decline_passed_total'] ?? 0)) ?></div><div class="rt-lbl">prior_decline_pass_count</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['recovery_growth_passed_total'] ?? 0)) ?></div><div class="rt-lbl">recovery_growth_pass_count</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['open_interest_growth_passed_total'] ?? 0)) ?></div><div class="rt-lbl">oi_growth_pass_count</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['raw_strategy_passed_total'] ?? 0)) ?></div><div class="rt-lbl">raw_strategy_pass_count</div></div>
      <div class="rt-box"><div class="rt-val"><?= $fmtBool((bool)($lastRun['coin_context_enabled'] ?? false)) ?></div><div class="rt-lbl">coin_context_enabled</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['coin_context_checked_total'] ?? 0)) ?></div><div class="rt-lbl">coin_context_checked</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['coin_context_available_total'] ?? 0)) ?></div><div class="rt-lbl">coin_context_available</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['coin_context_missing_total'] ?? 0)) ?></div><div class="rt-lbl">coin_context_missing</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['wave_quality_filter_checked_total'] ?? 0)) ?></div><div class="rt-lbl">wave_quality_checked</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['wave_quality_filter_blocked_total'] ?? 0)) ?></div><div class="rt-lbl">wave_quality_blocked</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['wave_quality_filter_passed_total'] ?? 0)) ?></div><div class="rt-lbl">wave_quality_passed</div></div>
      <div class="rt-box"><div class="rt-val"><?= $fmtBool((bool)($lastRun['orderbook_context_enabled'] ?? false)) ?></div><div class="rt-lbl">orderbook_context_enabled</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['orderbook_context_checked_total'] ?? 0)) ?></div><div class="rt-lbl">orderbook_checked</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['orderbook_context_available_total'] ?? 0)) ?></div><div class="rt-lbl">orderbook_available</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['orderbook_context_missing_total'] ?? 0)) ?></div><div class="rt-lbl">orderbook_missing</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['orderbook_filter_blocked_total'] ?? 0)) ?></div><div class="rt-lbl">orderbook_filter_blocked</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['chaotic_context_quality_downgraded_total'] ?? 0)) ?></div><div class="rt-lbl">chaotic_quality_downgraded</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['downtrend_context_quality_downgraded_total'] ?? 0)) ?></div><div class="rt-lbl">downtrend_quality_downgraded</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['spike_context_quality_downgraded_total'] ?? 0)) ?></div><div class="rt-lbl">spike_quality_downgraded</div></div>
    </div>

    <div style="margin-top:12px;margin-bottom:12px;padding:12px;border:1px solid rgba(56,189,248,.3);border-radius:6px;background:rgba(56,189,248,.04);">
      <div style="font-size:12px;color:#94a3b8;margin-bottom:8px;text-transform:uppercase;letter-spacing:.05em;">Phase breakdown (dump → stabilization → smooth growth)</div>
      <div class="rt-grid">
        <div class="rt-box" style="border-color:rgba(100,116,139,.5);">
          <div class="rt-val" style="color:#94a3b8;"><?= $e((int)($lastRun['dump_detected_total'] ?? 0)) ?></div>
          <div class="rt-lbl">dump_detected</div>
        </div>
        <div class="rt-box" style="border-color:rgba(100,116,139,.5);">
          <div class="rt-val" style="color:#94a3b8;"><?= $e((int)($lastRun['stabilization_detected_total'] ?? 0)) ?></div>
          <div class="rt-lbl">stabilization_detected</div>
        </div>
        <div class="rt-box" style="border-color:rgba(100,116,139,.5);">
          <div class="rt-val" style="color:#94a3b8;"><?= $e((int)($lastRun['smooth_growth_detected_total'] ?? 0)) ?></div>
          <div class="rt-lbl">smooth_growth_detected</div>
        </div>
        <div class="rt-box" style="border-color:rgba(34,197,94,.4);">
          <div class="rt-val" style="color:#22c55e;"><?= $e((int)($lastRun['early_entry_candidates_total'] ?? 0)) ?></div>
          <div class="rt-lbl">early_entry</div>
        </div>
        <div class="rt-box" style="border-color:rgba(56,189,248,.4);">
          <div class="rt-val" style="color:#38bdf8;"><?= $e((int)($lastRun['stabilizing_candidates_total'] ?? 0)) ?></div>
          <div class="rt-lbl">stabilizing (watch)</div>
        </div>
        <div class="rt-box" style="border-color:rgba(167,139,250,.4);">
          <div class="rt-val" style="color:#a78bfa;"><?= $e((int)($lastRun['confirmed_later_candidates_total'] ?? 0)) ?></div>
          <div class="rt-lbl">confirmed_later (visual)</div>
        </div>
        <div class="rt-box" style="border-color:rgba(251,146,60,.4);">
          <div class="rt-val" style="color:#fb923c;"><?= $e((int)($lastRun['late_spike_candidates_total'] ?? 0)) ?></div>
          <div class="rt-lbl">late_spike (blocked)</div>
        </div>
        <div class="rt-box" style="border-color:rgba(248,81,73,.4);">
          <div class="rt-val" style="color:#f85149;"><?= $e((int)($lastRun['extended_candidates_total'] ?? 0)) ?></div>
          <div class="rt-lbl">extended (blocked)</div>
        </div>
        <div class="rt-box" style="border-color:rgba(34,197,94,.5);">
          <div class="rt-val" style="color:#22c55e;"><?= $e((int)($lastRun['early_entry_handoff_ready_total'] ?? 0)) ?></div>
          <div class="rt-lbl">early_entry handoff_ready</div>
        </div>
        <div class="rt-box" style="border-color:rgba(248,81,73,.3);">
          <div class="rt-val" style="color:#f85149;"><?= $e((int)($lastRun['non_early_handoff_blocked_total'] ?? 0)) ?></div>
          <div class="rt-lbl">non_early blocked</div>
        </div>
        <div class="rt-box" style="border-color:rgba(248,81,73,.3);">
          <div class="rt-val" style="color:#f85149;"><?= $e((int)($lastRun['late_spike_handoff_blocked_total'] ?? 0)) ?></div>
          <div class="rt-lbl">late_spike blocked</div>
        </div>
        <div class="rt-box" style="border-color:rgba(248,81,73,.3);">
          <div class="rt-val" style="color:#f85149;"><?= $e((int)($lastRun['extended_handoff_blocked_total'] ?? 0)) ?></div>
          <div class="rt-lbl">extended blocked</div>
        </div>
      </div>
    </div>
    <?php
        $handoffEnabled = (bool)($lastRun['handoff_enabled'] ?? false);
        $emitBotHandoff = (bool)($lastRun['emit_bot_handoff'] ?? false);
        $effectiveHandoff = (bool)($lastRun['effective_bot_handoff_enabled'] ?? false);
        $blockReason = (string)($lastRun['bot_handoff_block_reason'] ?? '');
    ?>
    <div style="margin-top:12px;padding:12px;border:1px solid <?= $effectiveHandoff ? 'rgba(34,197,94,.35)' : 'rgba(245,158,11,.35)' ?>;border-radius:6px;background:<?= $effectiveHandoff ? 'rgba(34,197,94,.05)' : 'rgba(245,158,11,.05)' ?>;">
      <div style="font-size:12px;color:#94a3b8;margin-bottom:8px;text-transform:uppercase;letter-spacing:.05em;">Bot handoff status</div>
      <table class="rt-kv">
        <tr><td style="color:#64748b">handoff_enabled</td><td><?= $fmtBool($handoffEnabled) ?></td><td style="color:#64748b;padding-left:16px;">emit_bot_handoff</td><td><?= $fmtBool($emitBotHandoff) ?></td></tr>
        <tr><td style="color:#64748b">effective_bot_handoff_enabled</td><td><?= $fmtBool($effectiveHandoff) ?></td><td style="color:#64748b;padding-left:16px;">bot_handoff_block_reason</td><td><?= $blockReason !== '' ? '<code>' . $e($blockReason) . '</code>' : '<span style="color:#22c55e">none</span>' ?></td></tr>
        <tr><td style="color:#64748b">current_run_handoff_ready_total</td><td><?= $e((int)($lastRun['current_run_handoff_ready_total'] ?? 0)) ?></td><td style="color:#64748b;padding-left:16px;">current_run_bot_queue_written_total</td><td><?= $e((int)($lastRun['current_run_bot_queue_written_total'] ?? 0)) ?></td></tr>
        <tr><td style="color:#64748b">stored_handoff_ready_total</td><td><?= $e((int)($lastRun['stored_handoff_ready_total'] ?? 0)) ?></td><td style="color:#64748b;padding-left:16px;">stored_bot_queue_written_total</td><td><?= $e((int)($lastRun['stored_bot_queue_written_total'] ?? 0)) ?></td></tr>
        <tr><td style="color:#64748b">actual_bot_handoff_queue_records_total</td><td><?= $e((int)($lastRun['actual_bot_handoff_queue_records_total'] ?? 0)) ?></td><td style="color:#64748b;padding-left:16px;">bot_queue_candidates_considered_total</td><td><?= $e((int)($lastRun['bot_queue_candidates_considered_total'] ?? 0)) ?></td></tr>
        <tr><td style="color:#64748b">bot_queue_stale_skipped_total</td><td><?= $e((int)($lastRun['bot_queue_stale_skipped_total'] ?? 0)) ?></td><td style="color:#64748b;padding-left:16px;">bot_queue_duplicate_skipped_total</td><td><?= $e((int)($lastRun['bot_queue_duplicate_skipped_total'] ?? 0)) ?></td></tr>
        <tr><td style="color:#64748b">bot_queue_missing_required_fields_total</td><td><?= $e((int)($lastRun['bot_queue_missing_required_fields_total'] ?? 0)) ?></td><td style="color:#64748b;padding-left:16px;">bot_queue_written_total</td><td><?= $e((int)($lastRun['bot_queue_written_total'] ?? 0)) ?></td></tr>
        <tr><td style="color:#64748b">bot_queue_missing_required_fields_examples</td><td colspan="3"><code><?= $e(json_encode((array)($lastRun['bot_queue_missing_required_fields_examples'] ?? []), JSON_UNESCAPED_UNICODE)) ?></code></td></tr>
      </table>
      <?php if (!$effectiveHandoff): ?>
      <div style="font-size:12px;color:#f59e0b;margin-top:8px;">&#9888; Bot queue is not written. Both <code>handoff_enabled</code> and <code>emit_bot_handoff</code> must be <code>true</code> to write bot_handoff_queue.json.</div>
      <?php endif; ?>
    </div>
    <table class="rt-kv" style="margin-top:10px;">
      <tr><td style="color:#64748b">filter_engine_available_filters_total</td><td><?= $e((int)($lastRun['filter_engine_available_filters_total'] ?? 0)) ?></td><td style="color:#64748b;padding-left:16px;">filter_engine_enabled_filters_total</td><td><?= $e((int)($lastRun['filter_engine_enabled_filters_total'] ?? 0)) ?></td></tr>
      <tr><td style="color:#64748b">filter_engine_enabled_filter_ids</td><td colspan="3"><code><?= $e(json_encode((array)($lastRun['filter_engine_enabled_filter_ids'] ?? []), JSON_UNESCAPED_UNICODE)) ?></code></td></tr>
      <tr><td style="color:#64748b">filter_engine_results_by_filter</td><td colspan="3"><code><?= $e(json_encode((array)($lastRun['filter_engine_results_by_filter'] ?? []), JSON_UNESCAPED_UNICODE)) ?></code></td></tr>
      <tr><td style="color:#64748b">coin_context_error_counts</td><td colspan="3"><code><?= $e(json_encode((array)($lastRun['coin_context_error_counts'] ?? []), JSON_UNESCAPED_UNICODE)) ?></code></td></tr>
      <tr><td style="color:#64748b">coin_context_phase_counts</td><td colspan="3"><code><?= $e(json_encode($coinContextPhaseCounts, JSON_UNESCAPED_UNICODE)) ?></code></td></tr>
      <tr><td style="color:#64748b">coin_context_trend_1h_counts</td><td colspan="3"><code><?= $e(json_encode($coinContextTrendCounts, JSON_UNESCAPED_UNICODE)) ?></code></td></tr>
      <tr><td style="color:#64748b">coin_context_quality_counts</td><td colspan="3"><code><?= $e(json_encode($coinContextQualityCounts, JSON_UNESCAPED_UNICODE)) ?></code></td></tr>
      <tr><td style="color:#64748b">wave_quality_filter_examples</td><td colspan="3"><code><?= $e(json_encode($waveQualityFilterExamples, JSON_UNESCAPED_UNICODE)) ?></code></td></tr>
      <tr><td style="color:#64748b">orderbook_context_error_counts</td><td colspan="3"><code><?= $e(json_encode((array)($lastRun['orderbook_context_error_counts'] ?? []), JSON_UNESCAPED_UNICODE)) ?></code></td></tr>
      <tr><td style="color:#64748b">orderbook_ask_wall_detected_total</td><td><?= $e((int)($lastRun['orderbook_ask_wall_detected_total'] ?? 0)) ?></td><td style="color:#64748b;padding-left:16px;">orderbook_ask_wall_high_risk_total</td><td><?= $e((int)($lastRun['orderbook_ask_wall_high_risk_total'] ?? 0)) ?></td></tr>
      <tr><td style="color:#64748b">orderbook_bid_support_strong_total</td><td><?= $e((int)($lastRun['orderbook_bid_support_strong_total'] ?? 0)) ?></td><td style="color:#64748b;padding-left:16px;">orderbook_filter_checked_total</td><td><?= $e((int)($lastRun['orderbook_filter_checked_total'] ?? 0)) ?></td></tr>
      <tr><td style="color:#64748b">orderbook_filter_passed_total</td><td><?= $e((int)($lastRun['orderbook_filter_passed_total'] ?? 0)) ?></td><td style="color:#64748b;padding-left:16px;">orderbook_filter_missing_allowed_total</td><td><?= $e((int)($lastRun['orderbook_filter_missing_allowed_total'] ?? 0)) ?></td></tr>
      <tr><td style="color:#64748b">orderbook_filter_examples</td><td colspan="3"><code><?= $e(json_encode($orderbookFilterExamples, JSON_UNESCAPED_UNICODE)) ?></code></td></tr>
    </table>
    <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
      <form method="post" action="<?= $e($ajaxUrl) ?>" style="margin:0;">
        <input type="hidden" name="action" value="queue_run">
        <button type="submit" class="btn btn-sm" style="background:rgba(63,185,80,.12);color:#3fb950;border:1px solid #3fb95055;">Запуск цикла</button>
      </form>
      <form method="post" action="<?= $e($ajaxUrl) ?>" style="margin:0;">
        <input type="hidden" name="action" value="tick_batch">
        <button type="submit" class="btn btn-sm" style="background:rgba(88,166,255,.12);color:#58a6ff;border:1px solid #58a6ff55;">Тик батча</button>
      </form>
    </div>
  </div>

  <div class="rt-section">
    <h6>Watch recheck (priority re-evaluation)</h6>
    <div class="rt-grid">
      <div class="rt-box"><div class="rt-val"><?= $fmtBool((bool)($lastRun['watch_recheck_enabled'] ?? false)) ?></div><div class="rt-lbl">watch_recheck_enabled</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['watch_storage_loaded_total'] ?? 0)) ?></div><div class="rt-lbl">storage_loaded</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['watch_storage_active_total'] ?? 0)) ?></div><div class="rt-lbl">storage_active</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['watch_storage_expired_total'] ?? 0)) ?></div><div class="rt-lbl">storage_expired</div></div>
      <div class="rt-box" style="border-color:rgba(248,81,73,.3);"><div class="rt-val" style="color:#f85149;"><?= $e((int)($lastRun['watch_storage_failed_total'] ?? 0)) ?></div><div class="rt-lbl">storage_failed</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['watch_storage_pruned_total'] ?? 0)) ?></div><div class="rt-lbl">storage_pruned</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['watch_recheck_candidates_loaded_total'] ?? 0)) ?></div><div class="rt-lbl">candidates_loaded</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['watch_recheck_active_priority_total'] ?? 0)) ?></div><div class="rt-lbl">active_priority</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['watch_recheck_selection_limit'] ?? 0)) ?></div><div class="rt-lbl">selection_limit</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['watch_recheck_selected_total'] ?? 0)) ?></div><div class="rt-lbl">selected</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['watch_recheck_processed_total'] ?? 0)) ?></div><div class="rt-lbl">processed</div></div>
      <div class="rt-box" style="border-color:rgba(34,197,94,.4);"><div class="rt-val" style="color:#22c55e;"><?= $e((int)($lastRun['watch_recheck_triggered_total'] ?? 0)) ?></div><div class="rt-lbl">triggered (early_entry)</div></div>
      <div class="rt-box" style="border-color:rgba(56,189,248,.4);"><div class="rt-val" style="color:#38bdf8;"><?= $e((int)($lastRun['watch_recheck_still_stabilizing_total'] ?? 0)) ?></div><div class="rt-lbl">still_stabilizing</div></div>
      <div class="rt-box" style="border-color:rgba(248,81,73,.3);"><div class="rt-val" style="color:#f85149;"><?= $e((int)($lastRun['watch_recheck_failed_total'] ?? 0)) ?></div><div class="rt-lbl">failed</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['watch_recheck_expired_total'] ?? 0)) ?></div><div class="rt-lbl">expired</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['watch_recheck_too_recent_total'] ?? 0)) ?></div><div class="rt-lbl">too_recent</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['watch_recheck_no_priority_total'] ?? 0)) ?></div><div class="rt-lbl">no_priority</div></div>
      <div class="rt-box"><div class="rt-val"><?= $e((int)($lastRun['watch_recheck_skipped_total'] ?? 0)) ?></div><div class="rt-lbl">skipped</div></div>
    </div>
    <?php if (!empty($lastRun['watch_recheck_skip_reasons']) || !empty($lastRun['watch_recheck_selection_reason_counts'])): ?>
    <table class="rt-kv" style="margin-bottom:10px;">
      <tr><td style="color:#64748b">skip_reasons</td><td><code><?= $e(json_encode((array)($lastRun['watch_recheck_skip_reasons'] ?? []), JSON_UNESCAPED_UNICODE)) ?></code></td></tr>
      <tr><td style="color:#64748b">selection_reason_counts</td><td><code><?= $e(json_encode((array)($lastRun['watch_recheck_selection_reason_counts'] ?? []), JSON_UNESCAPED_UNICODE)) ?></code></td></tr>
    </table>
    <?php endif; ?>
    <?php if (!empty($watchRecheckExamples)): ?>
    <div style="font-size:11px;color:#94a3b8;margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em;">Recheck examples (up to 20)</div>
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:12px;">
      <thead><tr style="color:#64748b;text-align:left;border-bottom:1px solid var(--border-color,#334155);">
        <th style="padding:4px 8px;">symbol</th>
        <th style="padding:4px 8px;">prev_phase</th>
        <th style="padding:4px 8px;">new_phase</th>
        <th style="padding:4px 8px;">watch_status</th>
        <th style="padding:4px 8px;">phase_block_reason</th>
        <th style="padding:4px 8px;">stab_min</th>
        <th style="padding:4px 8px;">smooth%</th>
        <th style="padding:4px 8px;">oi%</th>
        <th style="padding:4px 8px;">combined_score</th>
        <th style="padding:4px 8px;">rechecks</th>
        <th style="padding:4px 8px;">selected_rank</th>
        <th style="padding:4px 8px;">handoff</th>
        <th style="padding:4px 8px;">block_reason</th>
      </tr></thead>
      <tbody>
      <?php foreach ($watchRecheckExamples as $row): ?>
        <?php $row = is_array($row) ? $row : []; ?>
        <?php $rStatusColor = match((string)($row['watch_status'] ?? '')) { 'triggered' => '#22c55e', 'failed' => '#f85149', 'expired' => '#f59e0b', default => '#94a3b8' }; ?>
        <tr style="border-top:1px solid #1e293b;">
          <td style="padding:4px 8px;font-weight:600;"><?= $e($row['symbol'] ?? '') ?></td>
          <td style="padding:4px 8px;"><?= $e($row['previous_phase'] ?? '') ?></td>
          <td style="padding:4px 8px;"><?= $e($row['new_phase'] ?? '') ?></td>
          <td style="padding:4px 8px;color:<?= $rStatusColor ?>"><?= $e($row['watch_status'] ?? '') ?></td>
          <td style="padding:4px 8px;font-size:11px;color:#94a3b8;"><?= $e($row['phase_block_reason'] ?? '') ?></td>
          <td style="padding:4px 8px;"><?= $e(is_numeric($row['stabilization_duration_minutes'] ?? null) ? $row['stabilization_duration_minutes'] : '—') ?></td>
          <td style="padding:4px 8px;"><?= $fmtNum($row['smooth_growth_pct'] ?? null) ?></td>
          <td style="padding:4px 8px;"><?= $fmtNum($row['open_interest_growth_pct'] ?? null) ?></td>
          <td style="padding:4px 8px;"><?= $fmtNum($row['combined_recovery_score'] ?? null, 4) ?></td>
          <td style="padding:4px 8px;"><?= $e($row['recheck_count'] ?? 0) ?></td>
          <td style="padding:4px 8px;"><?= $e($row['selected_rank'] ?? '—') ?></td>
          <td style="padding:4px 8px;"><?= $fmtBool($row['handoff_ready'] ?? false) ?></td>
          <td style="padding:4px 8px;font-size:11px;color:#94a3b8;"><?= $e($row['handoff_block_reason'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="rt-section">
    <h6>Accepted examples</h6>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead>
          <tr style="border-bottom:1px solid var(--border-color,#334155);">
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">symbol</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">entry_price</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">prior_decline_pct</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">recovery_growth_pct</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">recovery_duration_minutes</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">recovery_score</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">open_interest_growth_pct</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">combined_recovery_score</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">current_price_change_pct_10m</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">raw_strategy_passed</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">handoff_ready</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($acceptedExamples as $row): ?>
          <tr style="border-bottom:1px solid rgba(51,65,85,.5);">
            <td style="padding:3px 8px;font-weight:600;"><?= $e($row['symbol'] ?? '—') ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['entry_price'] ?? null, 6)) ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['prior_decline_pct'] ?? null, 4)) ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['recovery_growth_pct'] ?? null, 4)) ?></td>
            <td style="padding:3px 8px;"><?= $e((string)($row['recovery_duration_minutes'] ?? '—')) ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['recovery_score'] ?? null, 4)) ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['open_interest_growth_pct'] ?? null, 4)) ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['combined_recovery_score'] ?? null, 4)) ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['current_price_change_pct_10m'] ?? null, 4)) ?></td>
            <td style="padding:3px 8px;"><?= $fmtBool((bool)($row['raw_strategy_passed'] ?? false)) ?></td>
            <td style="padding:3px 8px;"><?= $fmtBool((bool)($row['handoff_ready'] ?? false)) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($acceptedExamples === []): ?><div class="note" style="padding:8px 2px;">No accepted examples in last_run yet.</div><?php endif; ?>
    </div>
  </div>

  <div class="rt-section">
    <h6>Near-pass examples (visual review)</h6>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead>
          <tr style="border-bottom:1px solid var(--border-color,#334155);">
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">symbol</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">entry_price</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">prior_decline_pct</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">recovery_growth_pct</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">open_interest_growth_pct</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">combined_recovery_score</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">raw_reject_reason</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($nearPassExamples as $row): ?>
          <tr style="border-bottom:1px solid rgba(51,65,85,.5);">
            <td style="padding:3px 8px;font-weight:600;"><?= $e($row['symbol'] ?? '—') ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['entry_price'] ?? null, 6)) ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['prior_decline_pct'] ?? null, 4)) ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['recovery_growth_pct'] ?? null, 4)) ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['open_interest_growth_pct'] ?? null, 4)) ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['combined_recovery_score'] ?? null, 4)) ?></td>
            <td style="padding:3px 8px;"><code><?= $e($row['raw_reject_reason'] ?? $row['reject_reason'] ?? '—') ?></code></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($nearPassExamples === []): ?><div class="note" style="padding:8px 2px;">No near-pass examples in last_run.</div><?php endif; ?>
    </div>
  </div>

  <div class="rt-section">
    <h6>Rejected examples</h6>
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead>
          <tr style="border-bottom:1px solid var(--border-color,#334155);">
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">symbol</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">raw_reject_reason</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">prior_decline_pct</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">recovery_growth_pct</th>
            <th style="text-align:left;padding:4px 8px;color:#94a3b8;">open_interest_growth_pct</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rejectedExamples as $row): ?>
          <tr style="border-bottom:1px solid rgba(51,65,85,.5);">
            <td style="padding:3px 8px;font-weight:600;"><?= $e($row['symbol'] ?? '—') ?></td>
            <td style="padding:3px 8px;"><code><?= $e($row['raw_reject_reason'] ?? '—') ?></code></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['prior_decline_pct'] ?? null, 4)) ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['recovery_growth_pct'] ?? null, 4)) ?></td>
            <td style="padding:3px 8px;"><?= $e($fmtNum($row['open_interest_growth_pct'] ?? null, 4)) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($rejectedExamples === []): ?><div class="note" style="padding:8px 2px;">No rejected examples in last_run yet.</div><?php endif; ?>
    </div>
  </div>

  <div class="rt-section">
    <h6>Best recovery / OI / acceleration examples</h6>
    <table class="rt-kv">
      <tr><td style="color:#64748b">best_recovery_examples</td><td><code><?= $e(json_encode($bestRecoveryExamples, JSON_UNESCAPED_UNICODE)) ?></code></td></tr>
      <tr><td style="color:#64748b">open_interest_growth_examples</td><td><code><?= $e(json_encode($openInterestExamples, JSON_UNESCAPED_UNICODE)) ?></code></td></tr>
      <tr><td style="color:#64748b">current_acceleration_examples</td><td><code><?= $e(json_encode($accelExamples, JSON_UNESCAPED_UNICODE)) ?></code></td></tr>
      <tr><td style="color:#64748b">coin_context_examples</td><td><code><?= $e(json_encode($coinContextExamples, JSON_UNESCAPED_UNICODE)) ?></code></td></tr>
    </table>
  </div>

<?php
$phaseTableRow = static function (array $row) use ($e, $fmtNum, $fmtBool): string {
    $phaseColor = match ((string)($row['entry_timing'] ?? '')) {
        'early' => '#22c55e',
        'stabilizing' => '#38bdf8',
        'dump_only' => '#94a3b8',
        'confirmed_later' => '#a78bfa',
        'late_spike' => '#fb923c',
        'extended' => '#f85149',
        default => '#64748b',
    };
    $waveQualityFilterRow = is_array($row['wave_quality_filter_result'] ?? null) ? (array)$row['wave_quality_filter_result'] : [];
    $waveQualityFilterReason = (string)($waveQualityFilterRow['reason'] ?? '');
    return '<tr style="border-bottom:1px solid rgba(51,65,85,.5);">'
        . '<td style="padding:3px 8px;font-weight:600;">' . $e($row['symbol'] ?? '—') . '</td>'
        . '<td style="padding:3px 8px;color:' . $phaseColor . ';font-weight:600;">' . $e($row['entry_timing'] ?? '—') . '</td>'
        . '<td style="padding:3px 8px;">' . $e($row['recovery_phase'] ?? '—') . '</td>'
        . '<td style="padding:3px 8px;">' . $e($fmtNum($row['dump_pct'] ?? null, 2)) . '</td>'
        . '<td style="padding:3px 8px;">' . $e((string)($row['stabilization_duration_minutes'] ?? '—')) . '</td>'
        . '<td style="padding:3px 8px;">' . $e($fmtNum($row['stabilization_range_pct'] ?? null, 3)) . '</td>'
        . '<td style="padding:3px 8px;">' . $e($fmtNum($row['smooth_growth_pct'] ?? null, 3)) . '</td>'
        . '<td style="padding:3px 8px;">' . $e((string)($row['smooth_growth_duration_minutes'] ?? '—')) . '</td>'
        . '<td style="padding:3px 8px;">' . $e($fmtNum($row['open_interest_growth_pct'] ?? null, 3)) . '</td>'
        . '<td style="padding:3px 8px;">' . $e($row['context_phase'] ?? '—') . '</td>'
        . '<td style="padding:3px 8px;">' . $e($row['context_quality'] ?? '—') . '</td>'
        . '<td style="padding:3px 8px;"><code style="font-size:11px;">' . $e(implode(',', (array)($row['context_reasons'] ?? []))) . '</code></td>'
        . '<td style="padding:3px 8px;">' . $e($row['trend_1h_direction'] ?? '—') . '</td>'
        . '<td style="padding:3px 8px;">' . $e($row['trend_2h_direction'] ?? '—') . '</td>'
        . '<td style="padding:3px 8px;"><code style="font-size:11px;">' . $e($waveQualityFilterReason) . '</code></td>'
        . '<td style="padding:3px 8px;">' . $fmtBool((bool)($row['handoff_ready'] ?? false)) . '</td>'
        . '<td style="padding:3px 8px;"><code style="font-size:11px;">' . $e($row['handoff_block_reason'] ?? '') . '</code></td>'
        . '</tr>';
};
$phaseTableHead = '<thead><tr style="border-bottom:1px solid var(--border-color,#334155);">'
    . '<th style="text-align:left;padding:4px 8px;color:#94a3b8;">symbol</th>'
    . '<th style="text-align:left;padding:4px 8px;color:#94a3b8;">entry_timing</th>'
    . '<th style="text-align:left;padding:4px 8px;color:#94a3b8;">recovery_phase</th>'
    . '<th style="text-align:left;padding:4px 8px;color:#94a3b8;">dump_pct</th>'
    . '<th style="text-align:left;padding:4px 8px;color:#94a3b8;">stab_min</th>'
    . '<th style="text-align:left;padding:4px 8px;color:#94a3b8;">stab_range%</th>'
    . '<th style="text-align:left;padding:4px 8px;color:#94a3b8;">growth_pct</th>'
    . '<th style="text-align:left;padding:4px 8px;color:#94a3b8;">growth_min</th>'
    . '<th style="text-align:left;padding:4px 8px;color:#94a3b8;">oi_growth%</th>'
    . '<th style="text-align:left;padding:4px 8px;color:#94a3b8;">context_phase</th>'
    . '<th style="text-align:left;padding:4px 8px;color:#94a3b8;">context_quality</th>'
    . '<th style="text-align:left;padding:4px 8px;color:#94a3b8;">context_reasons</th>'
    . '<th style="text-align:left;padding:4px 8px;color:#94a3b8;">trend_1h</th>'
    . '<th style="text-align:left;padding:4px 8px;color:#94a3b8;">trend_2h</th>'
    . '<th style="text-align:left;padding:4px 8px;color:#94a3b8;">wave_quality_filter</th>'
    . '<th style="text-align:left;padding:4px 8px;color:#94a3b8;">handoff_ready</th>'
    . '<th style="text-align:left;padding:4px 8px;color:#94a3b8;">block_reason</th>'
    . '</tr></thead>';
?>

  <div class="rt-section">
    <h6>Early entry examples <span style="font-size:11px;font-weight:400;color:#22c55e;">(executable candidates)</span></h6>
    <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:12px;"><?= $phaseTableHead ?><tbody>
    <?php foreach ($earlyEntryExamples as $row): ?><?= $phaseTableRow((array)$row) ?><?php endforeach; ?>
    </tbody></table><?php if ($earlyEntryExamples === []): ?><div class="note" style="padding:8px 2px;">No early_entry examples yet.</div><?php endif; ?></div>
  </div>

  <div class="rt-section">
    <h6>Stabilizing examples <span style="font-size:11px;font-weight:400;color:#38bdf8;">(watch candidates — dump detected, stabilization forming)</span></h6>
    <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:12px;"><?= $phaseTableHead ?><tbody>
    <?php foreach ($stabilizingExamples as $row): ?><?= $phaseTableRow((array)$row) ?><?php endforeach; ?>
    </tbody></table><?php if ($stabilizingExamples === []): ?><div class="note" style="padding:8px 2px;">No stabilizing examples yet.</div><?php endif; ?></div>
  </div>

  <div class="rt-section">
    <h6>Confirmed later examples <span style="font-size:11px;font-weight:400;color:#a78bfa;">(visual only — structural phases passed but OI failed or past window)</span></h6>
    <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:12px;"><?= $phaseTableHead ?><tbody>
    <?php foreach ($confirmedLaterExamples as $row): ?><?= $phaseTableRow((array)$row) ?><?php endforeach; ?>
    </tbody></table><?php if ($confirmedLaterExamples === []): ?><div class="note" style="padding:8px 2px;">No confirmed_later examples yet.</div><?php endif; ?></div>
  </div>

  <div class="rt-section">
    <h6>Late spike examples <span style="font-size:11px;font-weight:400;color:#fb923c;">(blocked — 10m move too fast)</span></h6>
    <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:12px;"><?= $phaseTableHead ?><tbody>
    <?php foreach ($lateSpikeExamples as $row): ?><?= $phaseTableRow((array)$row) ?><?php endforeach; ?>
    </tbody></table><?php if ($lateSpikeExamples === []): ?><div class="note" style="padding:8px 2px;">No late_spike examples yet.</div><?php endif; ?></div>
  </div>

  <div class="rt-section">
    <h6>Extended recovery examples <span style="font-size:11px;font-weight:400;color:#f85149;">(blocked — already recovered too far from dump low)</span></h6>
    <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:12px;"><?= $phaseTableHead ?><tbody>
    <?php foreach ($extendedExamples as $row): ?><?= $phaseTableRow((array)$row) ?><?php endforeach; ?>
    </tbody></table><?php if ($extendedExamples === []): ?><div class="note" style="padding:8px 2px;">No extended examples yet.</div><?php endif; ?></div>
  </div>

  <div class="rt-section">
    <h6>Dump detected examples <span style="font-size:11px;font-weight:400;color:#94a3b8;">(all symbols where a prior dump was found)</span></h6>
    <div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:12px;"><?= $phaseTableHead ?><tbody>
    <?php foreach ($dumpExamples as $row): ?><?= $phaseTableRow((array)$row) ?><?php endforeach; ?>
    </tbody></table><?php if ($dumpExamples === []): ?><div class="note" style="padding:8px 2px;">No dump examples yet.</div><?php endif; ?></div>
  </div>
</div>
