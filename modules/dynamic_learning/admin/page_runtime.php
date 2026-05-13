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
    <div style="background:#111827;color:#e5e7eb;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">risk_profile_mode</div>
      <div style="font-weight:600;"><?= $e((string)($run['risk_profile_mode'] ?? 'n/a')) ?></div>
    </div>
    <div style="background:#111827;color:#e5e7eb;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">outcome_classification_profile</div>
      <div style="font-weight:600;"><?= $e((string)($run['outcome_classification_profile'] ?? 'n/a')) ?></div>
    </div>
    <div style="background:#111827;color:#e5e7eb;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">bad learning ROI</div>
      <div style="font-weight:600;"><?= $e((float)($run['bad_learning_zone_roi'] ?? 0.0)) ?></div>
    </div>
    <div style="background:#111827;color:#e5e7eb;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">hard stop reference ROI</div>
      <div style="font-weight:600;"><?= $e((float)($run['hard_stop_reference_roi'] ?? 0.0)) ?></div>
    </div>
    <div style="background:#111827;color:#e5e7eb;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">stop slippage buffer ROI</div>
      <div style="font-weight:600;"><?= $e((float)($run['stop_slippage_buffer_roi'] ?? 0.0)) ?></div>
    </div>
    <div style="background:#111827;color:#e5e7eb;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">good learning ROI</div>
      <div style="font-weight:600;"><?= $e((float)($run['good_learning_threshold_roi'] ?? 0.0)) ?></div>
    </div>
    <div style="background:#111827;color:#e5e7eb;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">bad_entry_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['bad_entry_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#111827;color:#e5e7eb;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">good_or_do_not_touch_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['good_or_do_not_touch_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#111827;color:#e5e7eb;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">neutral_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['neutral_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#111827;color:#e5e7eb;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">outcomes_reclassified_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['outcomes_reclassified_total'] ?? 0)) ?></div>
    </div>
  </div>

  <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
    <div style="font-size:12px;opacity:.8;margin-bottom:8px;">outcomes_reclassified_examples</div>
    <pre style="margin:0;overflow:auto;"><?= $e(json_encode((array)($run['outcomes_reclassified_examples'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
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
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">closed_outcomes_merged_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['closed_outcomes_merged_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">closed_outcomes_strong_link_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['closed_outcomes_strong_link_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">closed_outcomes_weak_link_skipped_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['closed_outcomes_weak_link_skipped_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">closed_outcomes_time_mismatch_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['closed_outcomes_time_mismatch_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">closed_outcomes_opened_at_corrected_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['closed_outcomes_opened_at_corrected_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">closed_outcomes_timing_low_confidence_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['closed_outcomes_timing_low_confidence_total'] ?? 0)) ?></div>
    </div>
  </div>

  <!-- Architecture version and feature pipeline -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
    <div style="background:#1e293b;color:#93c5fd;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">architecture_version</div>
      <div style="font-weight:600;"><?= $e((string)($run['architecture_version'] ?? 'unknown')) ?></div>
    </div>
    <div style="background:#1e293b;color:#93c5fd;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">feature_records_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['feature_records_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#1e293b;color:#93c5fd;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">feature_time_valid_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['feature_time_valid_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#1e293b;color:#93c5fd;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">feature_time_invalid_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['feature_time_invalid_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#1e293b;color:#93c5fd;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">candle_micro_available_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['candle_micro_available_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#1e293b;color:#93c5fd;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">candle_micro_real_available_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['candle_micro_real_available_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#1e293b;color:#93c5fd;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">candle_micro_proxy_available_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['candle_micro_proxy_available_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#1e293b;color:#93c5fd;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">dump_micro_available_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['dump_micro_available_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#1e293b;color:#93c5fd;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">dump_micro_real_available_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['dump_micro_real_available_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#1e293b;color:#93c5fd;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">dump_micro_proxy_available_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['dump_micro_proxy_available_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#1e293b;color:#93c5fd;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">weighted_score_calculated_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['weighted_score_calculated_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#1e293b;color:#93c5fd;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">bybit_kline_requests_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['bybit_kline_requests_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#1e293b;color:#93c5fd;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">bybit_kline_success_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['bybit_kline_success_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#1e293b;color:#93c5fd;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">bybit_kline_unique_fetch_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['bybit_kline_unique_fetch_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#1e293b;color:#93c5fd;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">bybit_kline_reused_window_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['bybit_kline_reused_window_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#1e293b;color:#93c5fd;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">bybit_kline_cache_hit_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['bybit_kline_cache_hit_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#1e293b;color:#93c5fd;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">bybit_kline_fetch_cache_key_mode</div>
      <div style="font-weight:600;"><?= $e((string)($run['bybit_kline_fetch_cache_key_mode'] ?? 'n/a')) ?></div>
    </div>
    <div style="background:#1e293b;color:#93c5fd;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">parser2_fallback_used_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['parser2_fallback_used_total'] ?? 0)) ?></div>
    </div>
  </div>

  <!-- Micro-learning epoch diagnostics -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
    <div style="background:#1e1b4b;color:#a5b4fc;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">micro_learning_epoch_enabled</div>
      <div style="font-weight:600;"><?= $e((bool)($run['micro_learning_epoch_enabled'] ?? false) ? 'true' : 'false') ?></div>
    </div>
    <div style="background:#1e1b4b;color:#a5b4fc;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">micro_learning_epoch_id</div>
      <div style="font-weight:600;"><?= $e((string)($run['micro_learning_epoch_id'] ?? 'n/a')) ?></div>
    </div>
    <div style="background:#1e1b4b;color:#a5b4fc;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">micro_learning_epoch_start_at</div>
      <div style="font-weight:600;"><?= $e((string)($run['micro_learning_epoch_start_at'] ?? 'n/a')) ?></div>
    </div>
    <div style="background:#1e1b4b;color:#a5b4fc;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">epoch_start_source</div>
      <div style="font-weight:600;"><?= $e((string)($run['epoch_start_source'] ?? 'n/a')) ?></div>
    </div>
    <div style="background:#1e1b4b;color:#a5b4fc;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">epoch_start_missing_reason</div>
      <div style="font-weight:600;"><?= $e((string)($run['epoch_start_missing_reason'] ?? 'none')) ?></div>
    </div>
    <div style="background:#1e1b4b;color:#a5b4fc;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">legacy_outcomes_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['legacy_outcomes_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#1e1b4b;color:#a5b4fc;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">epoch_outcomes_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['epoch_outcomes_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#1e1b4b;color:#a5b4fc;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">outcomes_excluded_by_epoch_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['outcomes_excluded_by_epoch_total'] ?? 0)) ?></div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;">
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;margin-bottom:8px;">micro_impulse_shape_counts</div>
      <pre style="margin:0;overflow:auto;"><?= $e(json_encode((array)($run['micro_impulse_shape_counts'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;margin-bottom:8px;">dump_shape_counts</div>
      <pre style="margin:0;overflow:auto;"><?= $e(json_encode((array)($run['dump_shape_counts'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;margin-bottom:8px;">micro_bad_good_overlap_examples</div>
      <pre style="margin:0;overflow:auto;"><?= $e(json_encode((array)($run['micro_bad_good_overlap_examples'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
    </div>
  </div>

  <!-- Profile and comparison status -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
    <div style="background:#14532d;color:#86efac;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">profile_status</div>
      <div style="font-weight:600;"><?= $e($profileStatus) ?></div>
    </div>
    <div style="background:#14532d;color:#86efac;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">profile_rules_total</div>
      <div style="font-weight:600;"><?= $e($profileRulesTotal) ?></div>
    </div>
    <div style="background:#14532d;color:#86efac;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">profile_compared_to_default</div>
      <div style="font-weight:600;"><?= $e((bool)($run['profile_compared_to_default'] ?? false) ? 'true' : 'false') ?></div>
    </div>
    <div style="background:#14532d;color:#86efac;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">auto_not_worse_than_default</div>
      <div style="font-weight:600;"><?= $e((bool)($run['auto_not_worse_than_default'] ?? false) ? 'true' : 'false') ?></div>
    </div>
    <div style="background:#14532d;color:#86efac;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">auto_improvement_score</div>
      <div style="font-weight:600;"><?= $e($run['auto_improvement_score'] ?? 'n/a') ?></div>
    </div>
    <div style="background:#14532d;color:#86efac;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">auto_comparison_reason</div>
      <div style="font-weight:600;"><?= $e((string)($run['auto_comparison_reason'] ?? 'n/a')) ?></div>
    </div>
  </div>

  <!-- Storage status -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
    <div style="background:#1c1917;color:#d6d3d1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">storage_size_estimate_mb</div>
      <div style="font-weight:600;"><?= $e($run['storage_size_estimate_mb'] ?? 'n/a') ?></div>
    </div>
    <div style="background:#1c1917;color:#d6d3d1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">storage_pruned_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['storage_pruned_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#1c1917;color:#d6d3d1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">cycle_history_compact_enabled</div>
      <div style="font-weight:600;"><?= $e((bool)($run['cycle_history_compact_enabled'] ?? false) ? 'true' : 'false') ?></div>
    </div>
    <div style="background:#1c1917;color:#d6d3d1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">cycle_history_last_line_bytes</div>
      <div style="font-weight:600;"><?= $e((int)($run['cycle_history_last_line_bytes'] ?? 0)) ?></div>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">apply_learning_to_strategy_enabled</div>
      <div style="font-weight:600;"><?= $e((bool)($run['apply_learning_to_strategy_enabled'] ?? false) ? 'true' : 'false') ?></div>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">apply_learning_to_live_enabled</div>
      <div style="font-weight:600;"><?= $e((bool)($run['apply_learning_to_live_enabled'] ?? false) ? 'true' : 'false') ?></div>
    </div>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">bad_entry_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['bad_entry_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">good_or_do_not_touch_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['good_or_do_not_touch_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">entry_ok_exit_issue_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['entry_ok_exit_issue_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">neutral_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['neutral_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">outcome_incomplete_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['outcome_incomplete_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">outcome_mfe_normalized_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['outcome_mfe_normalized_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">outcome_mae_normalized_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['outcome_mae_normalized_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">outcome_excluded_from_pattern_mining_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['outcome_excluded_from_pattern_mining_total'] ?? 0)) ?></div>
    </div>
  </div>
  <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
    <div style="font-size:12px;opacity:.8;margin-bottom:8px;">closed_outcomes_duplicate_examples</div>
    <pre style="margin:0;overflow:auto;"><?= $e(json_encode((array)($run['closed_outcomes_duplicate_examples'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
  </div>
  <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
    <div style="font-size:12px;opacity:.8;margin-bottom:8px;">closed_outcomes_time_mismatch_examples</div>
    <pre style="margin:0;overflow:auto;"><?= $e(json_encode((array)($run['closed_outcomes_time_mismatch_examples'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
  </div>
  <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
    <div style="font-size:12px;opacity:.8;margin-bottom:8px;">outcome_roi_normalization_examples</div>
    <pre style="margin:0;overflow:auto;"><?= $e(json_encode((array)($run['outcome_roi_normalization_examples'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
  </div>
  <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
    <div style="font-size:12px;opacity:.8;margin-bottom:8px;">outcome_excluded_reasons</div>
    <pre style="margin:0;overflow:auto;"><?= $e(json_encode((array)($run['outcome_excluded_reasons'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
  </div>
  <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
    <div style="font-size:12px;opacity:.8;margin-bottom:8px;">feature_source_counts</div>
    <pre style="margin:0;overflow:auto;"><?= $e(json_encode((array)($run['feature_source_counts'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
  </div>
  <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
    <div style="font-size:12px;opacity:.8;margin-bottom:8px;">feature_time_invalid_examples</div>
    <pre style="margin:0;overflow:auto;"><?= $e(json_encode((array)($run['feature_time_invalid_examples'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
  </div>
  <div style="background:#0f172a;color:#cbd5e1;border-radius:8px;padding:10px;">
    <div style="font-size:12px;opacity:.8;margin-bottom:8px;">storage_prune_examples</div>
    <pre style="margin:0;overflow:auto;"><?= $e(json_encode((array)($run['storage_prune_examples'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
  </div>
  <pre style="margin:0;background:#0f172a;color:#cbd5e1;padding:12px;border-radius:8px;overflow:auto;"><?= $e(json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
</div>
