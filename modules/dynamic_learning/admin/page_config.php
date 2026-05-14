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
$cfg = $svc->getConfig();
$e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$autoApplyDemoEnabled = filter_var($cfg['auto_apply_to_demo_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
$autoApplyDemoEnabled = $autoApplyDemoEnabled ?? false;
$baseUrl = rtrim(System::web('admin/dynamic_learning'), '/');
?>
<div style="max-width:900px;display:grid;gap:12px;">
  <h3 style="margin:0;">Dynamic Learning — Config</h3>
  <form method="post" action="<?= $e($baseUrl) ?>/ajax" style="display:grid;gap:10px;">
    <input type="hidden" name="action" value="save_config">
    <?php
    $boolFields = [
        'enabled',
        'collect_entry_snapshots_enabled',
        'observe_active_positions_enabled',
        'analyze_closed_outcomes_enabled',
        'build_dynamic_profile_enabled',
        'apply_learning_to_strategy_enabled',
        'apply_learning_to_live_enabled',
        'apply_learning_to_demo_enabled',
        'profile_history_enabled',
    ];
    foreach ($boolFields as $key): ?>
      <label><?= $e($key) ?>
        <select name="<?= $e($key) ?>" class="form-control">
          <option value="1" <?= !empty($cfg[$key]) ? 'selected' : '' ?>>true</option>
          <option value="0" <?= empty($cfg[$key]) ? 'selected' : '' ?>>false</option>
        </select>
      </label>
    <?php endforeach; ?>
    <label>mode <input class="form-control" name="mode" value="<?= $e((string)($cfg['mode'] ?? 'diagnostic_only')) ?>"></label>
    <label>supported_strategy_id <input class="form-control" name="supported_strategy_id" value="<?= $e((string)($cfg['supported_strategy_id'] ?? 'early_impulse_growth_long')) ?>"></label>
    <label>risk_profile_mode
      <select name="risk_profile_mode" class="form-control">
        <?php foreach (['fast_demo', 'working_normal', 'working_real', 'custom'] as $option): ?>
          <option value="<?= $e($option) ?>" <?= (($cfg['risk_profile_mode'] ?? 'fast_demo') === $option) ? 'selected' : '' ?>><?= $e($option) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>outcome_classification_profile
      <select name="outcome_classification_profile" class="form-control">
        <?php foreach (['fast_demo_corridor_3_5', 'working_normal_8_10', 'working_real_8_15', 'custom'] as $option): ?>
          <option value="<?= $e($option) ?>" <?= (($cfg['outcome_classification_profile'] ?? 'fast_demo_corridor_3_5') === $option) ? 'selected' : '' ?>><?= $e($option) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>observation_interval_seconds <input type="number" class="form-control" name="observation_interval_seconds" value="<?= $e((int)($cfg['observation_interval_seconds'] ?? 30)) ?>"></label>
    <label>max_observations_per_position <input type="number" class="form-control" name="max_observations_per_position" value="<?= $e((int)($cfg['max_observations_per_position'] ?? 40)) ?>"></label>
    <label>bad_drawdown_roi_threshold <input type="number" step="0.01" class="form-control" name="bad_drawdown_roi_threshold" value="<?= $e((float)($cfg['bad_drawdown_roi_threshold'] ?? -10)) ?>"></label>
    <label>hard_stop_reference_roi <input type="number" step="0.01" class="form-control" name="hard_stop_reference_roi" value="<?= $e((float)($cfg['hard_stop_reference_roi'] ?? -5)) ?>"></label>
    <label>good_close_roi_threshold <input type="number" step="0.01" class="form-control" name="good_close_roi_threshold" value="<?= $e((float)($cfg['good_close_roi_threshold'] ?? 5)) ?>"></label>
    <label>good_max_profit_roi_threshold <input type="number" step="0.01" class="form-control" name="good_max_profit_roi_threshold" value="<?= $e((float)($cfg['good_max_profit_roi_threshold'] ?? 5)) ?>"></label>
    <label>stop_slippage_buffer_roi <input type="number" step="0.01" class="form-control" name="stop_slippage_buffer_roi" value="<?= $e((float)($cfg['stop_slippage_buffer_roi'] ?? 2)) ?>"></label>
    <label>pm_profit_reference_roi <input type="number" step="0.01" class="form-control" value="<?= $e((float)($cfg['pm_profit_reference_roi'] ?? 10)) ?>" readonly></label>
    <label>neutral_close_roi_min <input type="number" step="0.01" class="form-control" name="neutral_close_roi_min" value="<?= $e((float)($cfg['neutral_close_roi_min'] ?? -2)) ?>"></label>
    <label>neutral_close_roi_max <input type="number" step="0.01" class="form-control" name="neutral_close_roi_max" value="<?= $e((float)($cfg['neutral_close_roi_max'] ?? 2)) ?>"></label>
    <label>real_learning_epoch_enabled <input type="text" class="form-control" value="<?= $e(!empty($cfg['real_learning_epoch_enabled']) ? 'true' : 'false') ?>" readonly></label>
    <label>real_learning_epoch_id <input type="text" class="form-control" value="<?= $e((string)($cfg['real_learning_epoch_id'] ?? 'auto')) ?>" readonly></label>
    <label>real_learning_epoch_start_at <input type="text" class="form-control" value="<?= $e((string)($cfg['real_learning_epoch_start_at'] ?? 'null')) ?>" readonly></label>
    <label>ignore_fast_demo_outcomes_in_real_profile <input type="text" class="form-control" value="<?= $e(!empty($cfg['ignore_fast_demo_outcomes_in_real_profile']) ? 'true' : 'false') ?>" readonly></label>
    <label>preserve_fast_demo_history <input type="text" class="form-control" value="<?= $e(!empty($cfg['preserve_fast_demo_history']) ? 'true' : 'false') ?>" readonly></label>

    <hr style="border-color:var(--ui-border);margin:8px 0;">
    <h5 style="margin:4px 0;color:#a5b4fc;">Rolling Learning Guard</h5>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
      <label>rolling_learning_enabled
        <select name="rolling_learning_enabled" class="form-control">
          <option value="1" <?= !empty($cfg['rolling_learning_enabled']) ? 'selected' : '' ?>>true</option>
          <option value="0" <?= empty($cfg['rolling_learning_enabled']) ? 'selected' : '' ?>>false</option>
        </select>
      </label>
      <label>rolling_learning_window_minutes
        <input type="number" class="form-control" name="rolling_learning_window_minutes" value="<?= $e((int)($cfg['rolling_learning_window_minutes'] ?? 120)) ?>">
      </label>
      <label>rolling_retrain_interval_minutes
        <input type="number" class="form-control" name="rolling_retrain_interval_minutes" value="<?= $e((int)($cfg['rolling_retrain_interval_minutes'] ?? 60)) ?>">
      </label>
      <label>rolling_min_closed_outcomes
        <input type="number" class="form-control" name="rolling_min_closed_outcomes" value="<?= $e((int)($cfg['rolling_min_closed_outcomes'] ?? 20)) ?>">
      </label>
      <label>rolling_min_bad_entries
        <input type="number" class="form-control" name="rolling_min_bad_entries" value="<?= $e((int)($cfg['rolling_min_bad_entries'] ?? 3)) ?>">
      </label>
      <label>rolling_min_good_entries
        <input type="number" class="form-control" name="rolling_min_good_entries" value="<?= $e((int)($cfg['rolling_min_good_entries'] ?? 3)) ?>">
      </label>
    </div>

    <hr style="border-color:var(--ui-border);margin:8px 0;">
    <h5 style="margin:4px 0;color:#fcd34d;">Quality Guard Thresholds</h5>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
      <label>min_candidate_improvement_pct
        <input type="number" step="0.1" class="form-control" name="min_candidate_improvement_pct" value="<?= $e((float)($cfg['min_candidate_improvement_pct'] ?? 7.0)) ?>">
      </label>
      <label>no_change_band_pct
        <input type="number" step="0.1" class="form-control" name="no_change_band_pct" value="<?= $e((float)($cfg['no_change_band_pct'] ?? 5.0)) ?>">
      </label>
      <label>max_allowed_quality_degradation_pct
        <input type="number" step="0.1" class="form-control" name="max_allowed_quality_degradation_pct" value="<?= $e((float)($cfg['max_allowed_quality_degradation_pct'] ?? 10.0)) ?>">
      </label>
      <label>max_allowed_winrate_degradation_pct
        <input type="number" step="0.1" class="form-control" name="max_allowed_winrate_degradation_pct" value="<?= $e((float)($cfg['max_allowed_winrate_degradation_pct'] ?? 10.0)) ?>">
      </label>
      <label>max_allowed_avg_roi_degradation_pct
        <input type="number" step="0.1" class="form-control" name="max_allowed_avg_roi_degradation_pct" value="<?= $e((float)($cfg['max_allowed_avg_roi_degradation_pct'] ?? 10.0)) ?>">
      </label>
      <label>max_allowed_bad_entry_rate_increase_pct
        <input type="number" step="0.1" class="form-control" name="max_allowed_bad_entry_rate_increase_pct" value="<?= $e((float)($cfg['max_allowed_bad_entry_rate_increase_pct'] ?? 10.0)) ?>">
      </label>
      <label>max_allowed_drawdown_increase_pct
        <input type="number" step="0.1" class="form-control" name="max_allowed_drawdown_increase_pct" value="<?= $e((float)($cfg['max_allowed_drawdown_increase_pct'] ?? 10.0)) ?>">
      </label>
    </div>

    <hr style="border-color:var(--ui-border);margin:8px 0;">
    <h5 style="margin:4px 0;color:#86efac;">Quality Score Weights</h5>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
      <label>quality_weight_good_capture
        <input type="number" step="0.1" class="form-control" name="quality_weight_good_capture" value="<?= $e((float)($cfg['quality_weight_good_capture'] ?? 1.0)) ?>">
      </label>
      <label>quality_weight_avg_roi
        <input type="number" step="0.1" class="form-control" name="quality_weight_avg_roi" value="<?= $e((float)($cfg['quality_weight_avg_roi'] ?? 1.0)) ?>">
      </label>
      <label>quality_weight_bad_entry
        <input type="number" step="0.1" class="form-control" name="quality_weight_bad_entry" value="<?= $e((float)($cfg['quality_weight_bad_entry'] ?? 1.5)) ?>">
      </label>
      <label>quality_weight_drawdown
        <input type="number" step="0.1" class="form-control" name="quality_weight_drawdown" value="<?= $e((float)($cfg['quality_weight_drawdown'] ?? 1.0)) ?>">
      </label>
      <label>quality_weight_entry_ok_exit_issue
        <input type="number" step="0.1" class="form-control" name="quality_weight_entry_ok_exit_issue" value="<?= $e((float)($cfg['quality_weight_entry_ok_exit_issue'] ?? 0.5)) ?>">
      </label>
    </div>

    <hr style="border-color:var(--ui-border);margin:8px 0;">
    <h5 style="margin:4px 0;color:#f87171;">Rollback Guard</h5>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
      <label>rollback_guard_enabled
        <select name="rollback_guard_enabled" class="form-control">
          <option value="1" <?= !empty($cfg['rollback_guard_enabled']) ? 'selected' : '' ?>>true</option>
          <option value="0" <?= empty($cfg['rollback_guard_enabled']) ? 'selected' : '' ?>>false</option>
        </select>
      </label>
      <label>rollback_cooldown_minutes
        <input type="number" class="form-control" name="rollback_cooldown_minutes" value="<?= $e((int)($cfg['rollback_cooldown_minutes'] ?? 120)) ?>">
      </label>
      <label>rollback_to
        <select name="rollback_to" class="form-control">
          <option value="previous_good_or_default" <?= (($cfg['rollback_to'] ?? 'previous_good_or_default') === 'previous_good_or_default') ? 'selected' : '' ?>>previous_good_or_default</option>
          <option value="default_config" <?= (($cfg['rollback_to'] ?? '') === 'default_config') ? 'selected' : '' ?>>default_config</option>
        </select>
      </label>
    </div>

    <hr style="border-color:var(--ui-border);margin:8px 0;">
    <h5 style="margin:4px 0;color:#94a3b8;">Apply Guard</h5>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
      <label>auto_apply_to_demo_enabled
        <select name="auto_apply_to_demo_enabled" class="form-control">
          <option value="0" <?= !$autoApplyDemoEnabled ? 'selected' : '' ?>>false</option>
          <option value="1" <?= $autoApplyDemoEnabled ? 'selected' : '' ?>>true</option>
        </select>
      </label>
      <label>auto_apply_to_live_enabled <span style="color:#f85149;font-size:11px;">(always false)</span>
        <input type="text" class="form-control" value="false" readonly>
      </label>
      <label>require_not_worse_than_default
        <select name="require_not_worse_than_default" class="form-control">
          <option value="1" <?= !empty($cfg['require_not_worse_than_default']) ? 'selected' : '' ?>>true</option>
          <option value="0" <?= empty($cfg['require_not_worse_than_default']) ? 'selected' : '' ?>>false</option>
        </select>
      </label>
    </div>

    <button type="submit" class="btn btn-primary">Save</button>
  </form>
</div>
