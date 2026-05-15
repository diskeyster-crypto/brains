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
      <div style="font-size:12px;opacity:.8;">PM profit reference ROI</div>
      <div style="font-weight:600;"><?= $e((float)($run['pm_profit_reference_roi'] ?? 0.0)) ?></div>
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
    <div style="background:#111827;color:#e5e7eb;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">closed_outcome_time_tolerance_enabled</div>
      <div style="font-weight:600;"><?= $e((bool)($run['closed_outcome_time_tolerance_enabled'] ?? false) ? 'true' : 'false') ?></div>
    </div>
    <div style="background:#111827;color:#e5e7eb;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">closed_outcome_dedupe_closed_at_tolerance_seconds</div>
      <div style="font-weight:600;"><?= $e((int)($run['closed_outcome_dedupe_closed_at_tolerance_seconds'] ?? 0)) ?></div>
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
      <div style="font-size:12px;opacity:.8;">closed_outcomes_near_time_duplicates_merged_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['closed_outcomes_near_time_duplicates_merged_total'] ?? 0)) ?></div>
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

  <!-- Real-learning epoch diagnostics -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
    <div style="background:#1e1b4b;color:#a5b4fc;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">real_learning_epoch_enabled</div>
      <div style="font-weight:600;"><?= $e((bool)($run['real_learning_epoch_enabled'] ?? ($run['micro_learning_epoch_enabled'] ?? false)) ? 'true' : 'false') ?></div>
    </div>
    <div style="background:#1e1b4b;color:#a5b4fc;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">real_learning_epoch_id</div>
      <div style="font-weight:600;"><?= $e((string)($run['real_learning_epoch_id'] ?? $run['micro_learning_epoch_id'] ?? 'n/a')) ?></div>
    </div>
    <div style="background:#1e1b4b;color:#a5b4fc;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">real_learning_epoch_start_at</div>
      <div style="font-weight:600;"><?= $e((string)($run['real_learning_epoch_start_at'] ?? $run['micro_learning_epoch_start_at'] ?? 'n/a')) ?></div>
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
      <div style="font-size:12px;opacity:.8;">previous_epoch_outcomes_excluded_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['previous_epoch_outcomes_excluded_total'] ?? $run['legacy_outcomes_total'] ?? 0)) ?></div>
    </div>
    <div style="background:#1e1b4b;color:#a5b4fc;border-radius:8px;padding:10px;">
      <div style="font-size:12px;opacity:.8;">active_epoch_outcomes_total</div>
      <div style="font-weight:600;"><?= $e((int)($run['active_epoch_outcomes_total'] ?? $run['epoch_outcomes_total'] ?? 0)) ?></div>
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

  <!-- ── Rolling Quality Guard ─────────────────────────────────────────── -->
  <div style="border:1px solid #a5b4fc44;border-radius:10px;padding:14px;background:rgba(165,180,252,.05);">
    <div style="font-size:13px;font-weight:700;color:#a5b4fc;margin-bottom:10px;">
      <i class="bi bi-shield-check" style="margin-right:6px;"></i>Rolling Quality Guard
    </div>

    <!-- Rolling window counters -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;margin-bottom:10px;">
      <div style="background:#1e1b4b;color:#c4b5fd;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;">rolling_learning_enabled</div>
        <div style="font-weight:600;"><?= $e((bool)($run['rolling_learning_enabled'] ?? false) ? 'true' : 'false') ?></div>
      </div>
      <div style="background:#1e1b4b;color:#c4b5fd;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;">window_minutes</div>
        <div style="font-weight:600;"><?= $e((int)($run['rolling_learning_window_minutes'] ?? 120)) ?></div>
      </div>
      <div style="background:#1e1b4b;color:#c4b5fd;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;">rolling_window_outcomes</div>
        <div style="font-weight:600;"><?= $e((int)($run['rolling_window_outcomes_total'] ?? 0)) ?></div>
      </div>
      <div style="background:#1e1b4b;color:#c4b5fd;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;">rolling_window_bad</div>
        <div style="font-weight:600;"><?= $e((int)($run['rolling_window_bad_entry_total'] ?? 0)) ?></div>
      </div>
      <div style="background:#1e1b4b;color:#c4b5fd;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;">rolling_window_good</div>
        <div style="font-weight:600;"><?= $e((int)($run['rolling_window_good_entry_total'] ?? 0)) ?></div>
      </div>
      <div style="background:#1e1b4b;color:#c4b5fd;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;">rolling_min_closed_outcomes</div>
        <div style="font-weight:600;"><?= $e((int)($run['rolling_min_closed_outcomes'] ?? 0)) ?></div>
      </div>
      <div style="background:#1e1b4b;color:#c4b5fd;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;">rolling_min_bad_entries</div>
        <div style="font-weight:600;"><?= $e((int)($run['rolling_min_bad_entries'] ?? 0)) ?></div>
      </div>
      <div style="background:#1e1b4b;color:#c4b5fd;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;">rolling_min_good_entries</div>
        <div style="font-weight:600;"><?= $e((int)($run['rolling_min_good_entries'] ?? 0)) ?></div>
      </div>
      <div style="background:#1e1b4b;color:#c4b5fd;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;">rolling_window_exit_issue</div>
        <div style="font-weight:600;"><?= $e((int)($run['rolling_window_entry_ok_exit_issue_total'] ?? 0)) ?></div>
      </div>
    </div>

    <!-- Quality scores comparison -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;margin-bottom:10px;">
      <div style="background:#14532d;color:#86efac;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;">default_quality_score</div>
        <div style="font-weight:600;"><?= $e($run['default_quality_score'] ?? 'n/a') ?></div>
      </div>
      <div style="background:#1e3a5f;color:#93c5fd;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;">active_dynamic_quality_score</div>
        <div style="font-weight:600;"><?= $e($run['active_dynamic_quality_score'] ?? 'none') ?></div>
      </div>
      <div style="background:#1e3a5f;color:#93c5fd;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;">candidate_quality_score</div>
        <div style="font-weight:600;"><?= $e($run['candidate_quality_score'] ?? 'n/a') ?></div>
      </div>
      <div style="background:#111827;color:#e5e7eb;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;">candidate_vs_default_delta</div>
        <div style="font-weight:600;"><?= $e($run['candidate_vs_default_delta_pct'] ?? 'n/a') ?></div>
      </div>
      <div style="background:#111827;color:#e5e7eb;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;">no_change_band</div>
        <div style="font-weight:600;">±<?= $e((float)($run['no_change_band_pct'] ?? 5.0)) ?></div>
      </div>
      <div style="background:#111827;color:#e5e7eb;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;">min_improvement_required</div>
        <div style="font-weight:600;">+<?= $e((float)($run['min_candidate_improvement_pct'] ?? 7.0)) ?></div>
      </div>
    </div>

    <!-- Decision block -->
    <?php
    $candStatus = (string)($run['candidate_status'] ?? 'pending');
    $promoDecision = (string)($run['promotion_decision'] ?? 'none');
    $promoReason = (string)($run['promotion_reason'] ?? '');
    $decisionColor = match($promoDecision) {
        'promote_candidate_demo', 'candidate_ready_but_apply_disabled' => '#86efac',
        'reject_candidate' => '#f87171',
        'keep_current' => '#fcd34d',
        default => '#94a3b8',
    };
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:8px;margin-bottom:10px;">
      <div style="background:#0f172a;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;color:#94a3b8;">candidate_status</div>
        <div style="font-weight:600;color:#c4b5fd;"><?= $e($candStatus) ?></div>
      </div>
      <div style="background:#0f172a;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;color:#94a3b8;">promotion_decision</div>
        <div style="font-weight:600;color:<?= $decisionColor ?>;"><?= $e($promoDecision) ?></div>
      </div>
      <div style="background:#0f172a;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;color:#94a3b8;">promotion_reason</div>
        <div style="font-weight:600;color:#94a3b8;font-size:12px;"><?= $e($promoReason !== '' ? $promoReason : '—') ?></div>
      </div>
      <div style="background:#0f172a;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;color:#94a3b8;">auto_apply_to_demo</div>
        <div style="font-weight:600;color:<?= (bool)($run['auto_apply_to_demo_enabled'] ?? false) ? '#86efac' : '#94a3b8' ?>;">
          <?= $e((bool)($run['auto_apply_to_demo_enabled'] ?? false) ? 'true' : 'false') ?>
        </div>
      </div>
      <div style="background:#0f172a;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;color:#94a3b8;">require_not_worse_than_default</div>
        <div style="font-weight:600;color:#94a3b8;"><?= $e((bool)($run['require_not_worse_than_default'] ?? true) ? 'true' : 'false') ?></div>
      </div>
      <div style="background:#0f172a;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;color:#94a3b8;">replay_diagnostic_available</div>
        <div style="font-weight:600;color:#93c5fd;"><?= $e((bool)($run['replay_diagnostic_available'] ?? false) ? 'true' : 'false') ?></div>
      </div>
      <div style="background:#0f172a;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;color:#94a3b8;">replay_suggests_improvement</div>
        <div style="font-weight:600;color:#93c5fd;"><?= $e((bool)($run['replay_suggests_improvement'] ?? false) ? 'true' : 'false') ?></div>
      </div>
      <div style="background:#0f172a;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;color:#94a3b8;">promotion_blocked_by_min_data</div>
        <div style="font-weight:600;color:<?= (bool)($run['promotion_blocked_by_min_data'] ?? false) ? '#fcd34d' : '#86efac' ?>;">
          <?= $e((bool)($run['promotion_blocked_by_min_data'] ?? false) ? 'true' : 'false') ?>
        </div>
      </div>
      <div style="background:#0f172a;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;color:#94a3b8;">promotion_blocked_reason</div>
        <div style="font-weight:600;color:#94a3b8;font-size:12px;"><?= $e((string)($run['promotion_blocked_reason'] ?? '—')) ?></div>
      </div>
      <div style="background:#0f172a;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;color:#94a3b8;">candidate_build_scope</div>
        <div style="font-weight:600;color:#94a3b8;"><?= $e((string)($run['candidate_build_scope'] ?? '—')) ?></div>
      </div>
      <div style="background:#0f172a;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;color:#94a3b8;">candidate_replay_scope</div>
        <div style="font-weight:600;color:#94a3b8;"><?= $e((string)($run['candidate_replay_scope'] ?? '—')) ?></div>
      </div>
      <div style="background:#0f172a;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;color:#94a3b8;">promotion_guard_scope</div>
        <div style="font-weight:600;color:#94a3b8;"><?= $e((string)($run['promotion_guard_scope'] ?? '—')) ?></div>
      </div>
    </div>

    <!-- Rollback block -->
    <?php
    $rollbackReq = (bool)($run['rollback_required'] ?? false);
    $rollbackColor = $rollbackReq ? '#f87171' : '#86efac';
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;">
      <div style="background:#0f172a;border-radius:7px;padding:8px;border-left:3px solid <?= $rollbackColor ?>;">
        <div style="font-size:11px;opacity:.8;color:#94a3b8;">rollback_required</div>
        <div style="font-weight:600;color:<?= $rollbackColor ?>;"><?= $e($rollbackReq ? 'YES' : 'no') ?></div>
      </div>
      <div style="background:#0f172a;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;color:#94a3b8;">rollback_reason</div>
        <div style="font-weight:600;color:#94a3b8;font-size:12px;"><?= $e((string)($run['rollback_reason'] ?? '—')) ?></div>
      </div>
      <div style="background:#0f172a;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;color:#94a3b8;">rollback_cooldown_until</div>
        <div style="font-weight:600;color:#94a3b8;font-size:12px;"><?= $e((string)($run['rollback_cooldown_until'] ?? '—')) ?></div>
      </div>
      <div style="background:#0f172a;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.8;color:#94a3b8;">rollback_action</div>
        <div style="font-weight:600;color:#94a3b8;font-size:12px;"><?= $e((string)($run['rollback_action'] ?? '—')) ?></div>
      </div>
    </div>
  </div>

  <!-- ── Candidate Profile & Replay ──────────────────────────────────────── -->
  <?php
  $candProfileId     = (string)($run['candidate_profile_id']    ?? '—');
  $candStatus        = (string)($run['candidate_status']         ?? '—');
  $candRulesTotal    = (int)($run['candidate_rules_total']       ?? 0);
  $candComponents    = (int)($run['candidate_weighted_components_total'] ?? 0);
  $candReplayEnabled = (bool)($run['candidate_replay_enabled']   ?? false);
  $rpDefaultScore    = $run['default_quality_score']             ?? null;
  $rpCandScore       = $run['candidate_quality_score']           ?? null;
  $rpDelta           = $run['candidate_vs_default_delta_pct']    ?? null;
  $rpBadBlocked      = (int)($run['replay_bad_blocked_total']    ?? 0);
  $rpGoodBlocked     = (int)($run['replay_good_blocked_total']   ?? 0);
  $rpBadCapture      = $run['replay_bad_capture_rate_pct']       ?? null;
  $rpGoodBlock       = $run['replay_good_block_rate_pct']        ?? null;
  $rpNetScore        = $run['replay_net_score']                  ?? null;
  $rpPromoDec        = (string)($run['promotion_decision']       ?? '—');
  $rpPromoReason     = (string)($run['promotion_reason']         ?? '');

  $candStatusColor = match($candStatus) {
    'eligible_for_demo_apply'                          => '#86efac',
    'insufficient_data', 'no_material_improvement',
    'insufficient_bad_capture', 'no_score', 'pending'  => '#fcd34d',
    'no_safe_candidate_rules'                          => '#fb923c',
    'rejected', 'below_improvement_threshold'          => '#f87171',
    default                                            => '#94a3b8',
  };
  $rpPromoColor = match($rpPromoDec) {
    'promote_candidate_demo', 'candidate_ready_but_apply_disabled' => '#86efac',
    'reject_candidate'                                              => '#f87171',
    'keep_current'                                                  => '#fcd34d',
    default                                                         => '#94a3b8',
  };
  $rpPromoBg = match($rpPromoDec) {
    'promote_candidate_demo', 'candidate_ready_but_apply_disabled' => '#14532d',
    'reject_candidate'                                              => '#450a0a',
    'keep_current'                                                  => '#451a03',
    default                                                         => '#0f172a',
  };

  $showWarn = in_array($candStatus, ['insufficient_data', 'no_material_improvement', 'insufficient_bad_capture', 'no_safe_candidate_rules'], true)
              || (bool)($run['promotion_blocked_by_min_data'] ?? false)
              || ($rpGoodBlock !== null && (float)$rpGoodBlock > 20.0);
  ?>
  <div style="border:1px solid #93c5fd44;border-radius:10px;padding:14px;background:rgba(147,197,253,.04);margin-top:6px;">
    <div style="font-size:13px;font-weight:700;color:#93c5fd;margin-bottom:10px;">
      <i class="bi bi-cpu" style="margin-right:6px;"></i>Candidate Profile &amp; Replay
      <?php if (!$candReplayEnabled): ?>
        <span style="font-size:11px;color:#6b7280;font-weight:400;"> — replay not run yet this cycle</span>
      <?php endif; ?>
    </div>

    <?php if ($showWarn): ?>
    <div style="background:#451a03;color:#fcd34d;border-radius:6px;padding:8px 12px;font-size:12px;margin-bottom:8px;">
      ⚠&nbsp;<?php
        if ((bool)($run['promotion_blocked_by_min_data'] ?? false)) {
            echo $e('Promotion blocked by rolling minimum data gate: ' . (string)($run['promotion_blocked_reason'] ?? 'insufficient_rolling_data'));
        } elseif ($candStatus === 'insufficient_data') {
            echo $e('Insufficient data — rolling window does not meet minimum outcomes/bad/good thresholds.');
        } elseif ($candStatus === 'no_safe_candidate_rules') {
            $sfTotal    = (int)($run['candidate_single_feature_candidates_total'] ?? 0);
            $sfRejected = (int)($run['candidate_single_feature_rejected_total']   ?? 0);
            $compTested = (int)($run['composite_candidates_tested_total']         ?? 0);
            $compPassed = (int)($run['composite_candidates_passed_total']         ?? 0);
            if ($compTested > 0) {
                echo $e("No safe candidate rules — {$sfTotal} single-feature candidates evaluated ({$sfRejected} rejected). Composite: {$compTested} tested, {$compPassed} passed.");
            } else {
                echo $e("No safe candidate rules — {$sfTotal} single-feature candidates evaluated, {$sfRejected} rejected (good-overlap guard). No composite candidates tested.");
            }
        } elseif ($candStatus === 'no_material_improvement') {
            echo $e('No material improvement — candidate inside no-change band.');
        } elseif ($candStatus === 'insufficient_bad_capture') {
            echo $e('Bad capture rate too low — candidate does not capture enough bad entries.');
        } elseif ($rpGoodBlock !== null && (float)$rpGoodBlock > 20.0) {
            echo $e('Too many good trades blocked — good_block_rate=' . round((float)$rpGoodBlock, 1) . '%');
        }
      ?>
    </div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:7px;">
      <div style="background:#1e293b;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.7;color:#94a3b8;">candidate_profile_id</div>
        <div style="font-weight:600;font-size:11px;color:#93c5fd;word-break:break-all;"><?= $e($candProfileId) ?></div>
      </div>
      <div style="background:#1e293b;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.7;color:#94a3b8;">candidate_status</div>
        <div style="font-weight:600;color:<?= $candStatusColor ?>;"><?= $e($candStatus) ?></div>
      </div>
      <div style="background:#1e293b;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.7;color:#94a3b8;">rules_total</div>
        <div style="font-weight:600;color:#93c5fd;"><?= $e($candRulesTotal) ?></div>
      </div>
      <div style="background:#1e293b;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.7;color:#94a3b8;">risk_components</div>
        <div style="font-weight:600;color:#93c5fd;"><?= $e($candComponents) ?></div>
      </div>
      <div style="background:#14532d;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.7;color:#86efac;">default_score</div>
        <div style="font-weight:600;color:#86efac;"><?= $e($rpDefaultScore ?? 'n/a') ?></div>
      </div>
      <div style="background:#1e3a5f;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.7;color:#93c5fd;">candidate_score</div>
        <div style="font-weight:600;color:#93c5fd;"><?= $e($rpCandScore ?? 'n/a') ?></div>
      </div>
      <?php
      $dv = $rpDelta !== null ? (float)$rpDelta : null;
      $dvBg  = $dv === null ? '#111827' : ($dv > 0 ? '#14532d' : '#450a0a');
      $dvClr = $dv === null ? '#6b7280'  : ($dv > 0 ? '#86efac' : '#f87171');
      ?>
      <div style="background:<?= $dvBg ?>;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.7;color:<?= $dvClr ?>;">delta</div>
        <div style="font-weight:600;color:<?= $dvClr ?>;"><?= $e($dv !== null ? ($dv >= 0 ? '+' : '') . round($dv, 2) : 'n/a') ?></div>
      </div>
      <div style="background:#111827;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.7;color:#94a3b8;">bad_blocked</div>
        <div style="font-weight:600;color:#e5e7eb;"><?= $e($rpBadBlocked) ?></div>
      </div>
      <div style="background:#111827;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.7;color:#94a3b8;">good_blocked</div>
        <div style="font-weight:600;color:<?= $rpGoodBlocked > 0 ? '#fcd34d' : '#e5e7eb' ?>;"><?= $e($rpGoodBlocked) ?></div>
      </div>
      <?php
      $capBg  = ($rpBadCapture !== null && (float)$rpBadCapture >= 20.0) ? '#14532d' : '#450a0a';
      $capClr = ($rpBadCapture !== null && (float)$rpBadCapture >= 20.0) ? '#86efac' : '#f87171';
      $blkBg  = ($rpGoodBlock  !== null && (float)$rpGoodBlock  <= 20.0) ? '#14532d' : '#450a0a';
      $blkClr = ($rpGoodBlock  !== null && (float)$rpGoodBlock  <= 20.0) ? '#86efac' : '#f87171';
      ?>
      <div style="background:<?= $capBg ?>;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.7;color:<?= $capClr ?>;">bad_capture_rate</div>
        <div style="font-weight:600;color:<?= $capClr ?>;"><?= $e($rpBadCapture !== null ? round((float)$rpBadCapture, 1) . '%' : 'n/a') ?></div>
      </div>
      <div style="background:<?= $blkBg ?>;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.7;color:<?= $blkClr ?>;">good_block_rate</div>
        <div style="font-weight:600;color:<?= $blkClr ?>;"><?= $e($rpGoodBlock !== null ? round((float)$rpGoodBlock, 1) . '%' : 'n/a') ?></div>
      </div>
      <div style="background:<?= $rpPromoBg ?>;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.7;color:<?= $rpPromoColor ?>;">promotion_decision</div>
        <div style="font-weight:600;font-size:11px;color:<?= $rpPromoColor ?>;"><?= $e($rpPromoDec) ?></div>
      </div>
      <div style="background:#0f172a;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.7;color:#94a3b8;">promotion_reason</div>
        <div style="font-size:11px;color:#94a3b8;"><?= $e($rpPromoReason !== '' ? $rpPromoReason : '—') ?></div>
      </div>
      <?php if ($candReplayEnabled): ?>
      <div style="background:#0f172a;border-radius:7px;padding:8px;">
        <div style="font-size:11px;opacity:.7;color:#94a3b8;">replay_net_score</div>
        <div style="font-weight:600;color:<?= ($rpNetScore !== null && (float)$rpNetScore > 0) ? '#86efac' : '#f87171' ?>;"><?= $e($rpNetScore ?? 'n/a') ?></div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Single-feature + composite diagnostics -->
    <?php
    $sfTotal    = (int)($run['candidate_single_feature_candidates_total']   ?? 0);
    $sfRejected = (int)($run['candidate_single_feature_rejected_total']     ?? 0);
    $sfReasons  = (array)($run['candidate_single_feature_reject_reason_counts'] ?? []);
    $compEnabled  = (bool)($run['composite_candidate_enabled']   ?? false);
    $compTested   = (int)($run['composite_candidates_tested_total']   ?? 0);
    $compPassed   = (int)($run['composite_candidates_passed_total']   ?? 0);
    $compRejected = (int)($run['composite_candidates_rejected_total'] ?? 0);
    $compBestBad  = $run['composite_candidate_best_bad_capture_rate_pct'] ?? null;
    $compBestGood = $run['composite_candidate_best_good_block_rate_pct']  ?? null;
    $compBestNet  = $run['composite_candidate_best_net_score']            ?? null;
    $compSelected = (bool)($run['composite_candidate_selected'] ?? false);
    $compSelId    = (string)($run['composite_candidate_selected_id'] ?? '—');
    $compReject   = (array)($run['composite_candidate_reject_reason_counts'] ?? []);
    $rulesMissingReason = (string)($run['candidate_rules_missing_reason'] ?? '');
    ?>
    <?php if ($sfTotal > 0 || $compTested > 0 || $rulesMissingReason !== ''): ?>
    <div style="margin-top:10px;border-top:1px solid #1e293b;padding-top:10px;">
      <div style="font-size:12px;font-weight:600;color:#fb923c;margin-bottom:8px;">
        <i class="bi bi-diagram-3" style="margin-right:5px;"></i>Candidate Selection Diagnostics
      </div>
      <?php if ($rulesMissingReason !== ''): ?>
      <div style="background:#431407;color:#fb923c;border-radius:6px;padding:6px 10px;font-size:12px;margin-bottom:8px;">
        candidate_rules_missing_reason: <strong><?= $e($rulesMissingReason) ?></strong>
      </div>
      <?php endif; ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:6px;margin-bottom:8px;">
        <div style="background:#1e293b;border-radius:6px;padding:7px;">
          <div style="font-size:10px;color:#94a3b8;">single_feature_candidates</div>
          <div style="font-weight:600;color:#e5e7eb;"><?= $e($sfTotal) ?></div>
        </div>
        <div style="background:#1e293b;border-radius:6px;padding:7px;">
          <div style="font-size:10px;color:#94a3b8;">single_feature_rejected</div>
          <div style="font-weight:600;color:<?= $sfRejected > 0 ? '#fcd34d' : '#86efac' ?>;"><?= $e($sfRejected) ?></div>
        </div>
        <?php if (!empty($sfReasons)): ?>
        <div style="background:#1e293b;border-radius:6px;padding:7px;">
          <div style="font-size:10px;color:#94a3b8;">reject_reasons</div>
          <div style="font-size:10px;color:#94a3b8;"><?= $e(implode(', ', array_map(static fn($k, $v) => "{$k}:{$v}", array_keys($sfReasons), $sfReasons))) ?></div>
        </div>
        <?php endif; ?>
        <div style="background:#1e293b;border-radius:6px;padding:7px;">
          <div style="font-size:10px;color:#94a3b8;">composite_enabled</div>
          <div style="font-weight:600;color:<?= $compEnabled ? '#93c5fd' : '#6b7280' ?>;"><?= $e($compEnabled ? 'true' : 'false') ?></div>
        </div>
        <div style="background:#1e293b;border-radius:6px;padding:7px;">
          <div style="font-size:10px;color:#94a3b8;">composite_tested</div>
          <div style="font-weight:600;color:#e5e7eb;"><?= $e($compTested) ?></div>
        </div>
        <div style="background:#1e293b;border-radius:6px;padding:7px;">
          <div style="font-size:10px;color:#94a3b8;">composite_passed</div>
          <div style="font-weight:600;color:<?= $compPassed > 0 ? '#86efac' : '#6b7280' ?>;"><?= $e($compPassed) ?></div>
        </div>
        <div style="background:#1e293b;border-radius:6px;padding:7px;">
          <div style="font-size:10px;color:#94a3b8;">composite_rejected</div>
          <div style="font-weight:600;color:<?= $compRejected > 0 ? '#fcd34d' : '#6b7280' ?>;"><?= $e($compRejected) ?></div>
        </div>
        <?php if ($compBestBad !== null): ?>
        <div style="background:<?= (float)$compBestBad >= 20.0 ? '#14532d' : '#450a0a' ?>;border-radius:6px;padding:7px;">
          <div style="font-size:10px;color:#94a3b8;">best_bad_capture</div>
          <div style="font-weight:600;color:<?= (float)$compBestBad >= 20.0 ? '#86efac' : '#f87171' ?>;"><?= $e(round((float)$compBestBad, 1)) ?>%</div>
        </div>
        <?php endif; ?>
        <?php if ($compBestGood !== null): ?>
        <div style="background:<?= (float)$compBestGood <= 20.0 ? '#14532d' : '#450a0a' ?>;border-radius:6px;padding:7px;">
          <div style="font-size:10px;color:#94a3b8;">best_good_block</div>
          <div style="font-weight:600;color:<?= (float)$compBestGood <= 20.0 ? '#86efac' : '#f87171' ?>;"><?= $e(round((float)$compBestGood, 1)) ?>%</div>
        </div>
        <?php endif; ?>
        <?php if ($compBestNet !== null): ?>
        <div style="background:#1e293b;border-radius:6px;padding:7px;">
          <div style="font-size:10px;color:#94a3b8;">best_net_score</div>
          <div style="font-weight:600;color:<?= (float)$compBestNet > 0 ? '#86efac' : '#f87171' ?>;"><?= $e(round((float)$compBestNet, 2)) ?></div>
        </div>
        <?php endif; ?>
        <div style="background:<?= $compSelected ? '#14532d' : '#1e293b' ?>;border-radius:6px;padding:7px;">
          <div style="font-size:10px;color:#94a3b8;">composite_selected</div>
          <div style="font-weight:600;color:<?= $compSelected ? '#86efac' : '#6b7280' ?>;"><?= $e($compSelected ? 'YES' : 'no') ?></div>
        </div>
        <?php if ($compSelected): ?>
        <div style="background:#1e293b;border-radius:6px;padding:7px;">
          <div style="font-size:10px;color:#94a3b8;">selected_id</div>
          <div style="font-size:10px;color:#93c5fd;word-break:break-all;"><?= $e($compSelId) ?></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($compReject)): ?>
        <div style="background:#1e293b;border-radius:6px;padding:7px;">
          <div style="font-size:10px;color:#94a3b8;">composite_reject_reasons</div>
          <div style="font-size:10px;color:#94a3b8;"><?= $e(implode(', ', array_map(static fn($k, $v) => "{$k}:{$v}", array_keys($compReject), $compReject))) ?></div>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
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
    <div style="font-size:12px;opacity:.8;margin-bottom:8px;">closed_outcomes_near_time_duplicate_examples</div>
    <pre style="margin:0;overflow:auto;"><?= $e(json_encode((array)($run['closed_outcomes_near_time_duplicate_examples'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
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
</div>
