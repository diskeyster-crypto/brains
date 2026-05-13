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
        <?php foreach (['fast_demo', 'working_normal', 'custom'] as $option): ?>
          <option value="<?= $e($option) ?>" <?= (($cfg['risk_profile_mode'] ?? 'fast_demo') === $option) ? 'selected' : '' ?>><?= $e($option) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>outcome_classification_profile
      <select name="outcome_classification_profile" class="form-control">
        <?php foreach (['fast_demo_corridor_3_5', 'working_normal_8_10', 'custom'] as $option): ?>
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
    <label>neutral_close_roi_min <input type="number" step="0.01" class="form-control" name="neutral_close_roi_min" value="<?= $e((float)($cfg['neutral_close_roi_min'] ?? -2)) ?>"></label>
    <label>neutral_close_roi_max <input type="number" step="0.01" class="form-control" name="neutral_close_roi_max" value="<?= $e((float)($cfg['neutral_close_roi_max'] ?? 2)) ?>"></label>
    <button type="submit" class="btn btn-primary">Save</button>
  </form>
</div>
