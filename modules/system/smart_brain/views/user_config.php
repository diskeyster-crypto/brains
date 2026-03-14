<?php
/**
 * Smart Brain Module - User Config View
 *
 * Editable form for user hard limits.
 * Save / Reset / Reload buttons.
 */

/** @var string $smartBrainUrl */
/** @var array<string,mixed> $user_limits */
/** @var array<string,mixed> $form_values */
/** @var array{type:string,message:string}|null $flash */

$pageTitle = 'Smart Brain - User Config';
$activeTab = 'user_config';

$pageContent = function() use ($form_values, $user_limits, $smartBrainUrl) {
    $v = function(string $key, $default = '') use ($form_values) {
        return htmlspecialchars((string)($form_values[$key] ?? $default));
    };
    $checked = function(string $key) use ($form_values): string {
        return !empty($form_values[$key]) ? 'checked' : '';
    };
?>
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-sliders me-2 text-primary"></i>User Config</h4>
            <p class="text-secondary mb-0">Edit hard limits — these are user-controlled values</p>
        </div>
    </div>

    <form method="POST" action="<?= htmlspecialchars($smartBrainUrl) ?>/user_config/save" id="user-config-form">

        <div class="row">
            <!-- Trading Limits -->
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-shield-check me-2"></i>
                        <h5 style="margin: 0;">Trading Limits</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="max_budget_per_coin" class="form-label">Max Budget per Coin (USDT)</label>
                            <input type="number" step="0.01" min="0.01" class="form-control" id="max_budget_per_coin" name="max_budget_per_coin" value="<?= $v('max_budget_per_coin', '20') ?>">
                            <small class="text-secondary">Maximum USDT budget per coin position</small>
                        </div>
                        <div class="mb-3">
                            <label for="max_active_tasks" class="form-label">Max Active Tasks</label>
                            <input type="number" step="1" min="1" class="form-control" id="max_active_tasks" name="max_active_tasks" value="<?= $v('max_active_tasks', '10') ?>">
                            <small class="text-secondary">Maximum concurrent active signals</small>
                        </div>
                        <div class="mb-3">
                            <label for="max_leverage" class="form-label">Max Leverage</label>
                            <input type="number" step="1" min="1" class="form-control" id="max_leverage" name="max_leverage" value="<?= $v('max_leverage', '15') ?>">
                            <small class="text-secondary">Maximum leverage for any position</small>
                        </div>
                        <div class="mb-3">
                            <label for="brain_mode" class="form-label">Brain Mode</label>
                            <select class="form-select" id="brain_mode" name="brain_mode">
                                <?php foreach (['safe', 'balanced', 'aggressive'] as $mode): ?>
                                <option value="<?= $mode ?>" <?= ($form_values['brain_mode'] ?? 'balanced') === $mode ? 'selected' : '' ?>><?= ucfirst($mode) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-secondary">Risk tolerance profile</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bootstrap Settings -->
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-rocket-takeoff me-2"></i>
                        <h5 style="margin: 0;">Bootstrap Mode</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3 form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="bootstrap_enabled" name="bootstrap_enabled" value="1" <?= $checked('bootstrap_enabled') ?>>
                            <label class="form-check-label" for="bootstrap_enabled">Bootstrap Enabled</label>
                            <br><small class="text-secondary">Allow signals for cold-start passports</small>
                        </div>
                        <div class="mb-3">
                            <label for="bootstrap_max_signals" class="form-label">Bootstrap Max Signals</label>
                            <input type="number" step="1" min="0" class="form-control" id="bootstrap_max_signals" name="bootstrap_max_signals" value="<?= $v('bootstrap_max_signals', '5') ?>">
                            <small class="text-secondary">Max bootstrap signals per cycle</small>
                        </div>
                        <div class="mb-3">
                            <label for="bootstrap_budget_factor" class="form-label">Bootstrap Budget Factor</label>
                            <input type="number" step="0.01" min="0.01" max="1" class="form-control" id="bootstrap_budget_factor" name="bootstrap_budget_factor" value="<?= $v('bootstrap_budget_factor', '0.5') ?>">
                            <small class="text-secondary">Fraction of max_budget for bootstrap (0..1)</small>
                        </div>
                        <div class="mb-3">
                            <label for="bootstrap_max_leverage" class="form-label">Bootstrap Max Leverage</label>
                            <input type="number" step="1" min="1" class="form-control" id="bootstrap_max_leverage" name="bootstrap_max_leverage" value="<?= $v('bootstrap_max_leverage', '3') ?>">
                            <small class="text-secondary">Max leverage for bootstrap signals</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Warmup / Reliability -->
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-thermometer-half me-2"></i>
                        <h5 style="margin: 0;">Warmup &amp; Reliability</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="min_reliability_after_warmup" class="form-label">Min Reliability (after warmup)</label>
                            <input type="number" step="0.01" min="0" max="1" class="form-control" id="min_reliability_after_warmup" name="min_reliability_after_warmup" value="<?= $v('min_reliability_after_warmup', '0.15') ?>">
                            <small class="text-secondary">Minimum reliability_score for normal signals (0..1)</small>
                        </div>
                        <div class="mb-3">
                            <label for="warmup_min_trades" class="form-label">Warmup Min Trades</label>
                            <input type="number" step="1" min="0" class="form-control" id="warmup_min_trades" name="warmup_min_trades" value="<?= $v('warmup_min_trades', '10') ?>">
                            <small class="text-secondary">Min trades in passport before normal mode activates</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Exit Policy Section -->
        <div class="row">
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-door-open me-2"></i>
                        <h5 style="margin: 0;">Exit Policy</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="exit_mode" class="form-label">Exit Mode</label>
                            <select class="form-select" id="exit_mode" name="exit_mode">
                                <?php foreach (['fixed_tp' => 'Fixed TP', 'trailing_tp' => 'Trailing TP', 'hybrid' => 'Hybrid'] as $em => $emLabel): ?>
                                <option value="<?= $em ?>" <?= ($form_values['exit_mode'] ?? 'fixed_tp') === $em ? 'selected' : '' ?>><?= $emLabel ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-secondary">How take-profit is handled</small>
                        </div>
                        <div class="mb-3">
                            <label for="stop_floor_type" class="form-label">Stop Floor Type</label>
                            <select class="form-select" id="stop_floor_type" name="stop_floor_type">
                                <?php foreach (['roi_percent' => 'ROI Percent', 'corridor_percent' => 'Corridor Percent'] as $sf => $sfLabel): ?>
                                <option value="<?= $sf ?>" <?= ($form_values['stop_floor_type'] ?? 'roi_percent') === $sf ? 'selected' : '' ?>><?= $sfLabel ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-secondary">How minimum stop protection is calculated</small>
                        </div>
                        <div class="mb-3">
                            <label for="stop_floor_value" class="form-label">Stop Floor Value</label>
                            <input type="number" step="0.001" min="0.001" class="form-control" id="stop_floor_value" name="stop_floor_value" value="<?= $v('stop_floor_value', '0.03') ?>">
                            <small class="text-secondary">Minimum stop protection (> 0)</small>
                        </div>
                        <div class="mb-3 form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="brain_may_tighten_stop" name="brain_may_tighten_stop" value="1" <?= $checked('brain_may_tighten_stop') ?>>
                            <label class="form-check-label" for="brain_may_tighten_stop">Brain May Tighten Stop</label>
                            <br><small class="text-secondary">Allow brain to tighten stop (never weaken below floor)</small>
                        </div>
                        <div class="mb-3">
                            <label for="fixed_take_profit_roi" class="form-label">Fixed Take Profit ROI</label>
                            <input type="number" step="0.001" min="0" class="form-control" id="fixed_take_profit_roi" name="fixed_take_profit_roi" value="<?= $v('fixed_take_profit_roi', '0.05') ?>">
                            <small class="text-secondary">ROI target for fixed TP mode (>= 0)</small>
                        </div>
                        <div class="mb-3">
                            <label for="hybrid_tp_share" class="form-label">Hybrid TP Share</label>
                            <input type="number" step="0.01" min="0" max="1" class="form-control" id="hybrid_tp_share" name="hybrid_tp_share" value="<?= $v('hybrid_tp_share', '0.5') ?>">
                            <small class="text-secondary">Share of position for fixed TP in hybrid mode (0..1)</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Trailing Settings -->
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-graph-up-arrow me-2"></i>
                        <h5 style="margin: 0;">Trailing Stop</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3 form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="trailing_enabled" name="trailing_enabled" value="1" <?= $checked('trailing_enabled') ?>>
                            <label class="form-check-label" for="trailing_enabled">Trailing Enabled</label>
                            <br><small class="text-secondary">Enable trailing stop for profit locking</small>
                        </div>
                        <div class="mb-3">
                            <label for="trailing_activation_roi" class="form-label">Trailing Activation ROI</label>
                            <input type="number" step="0.001" min="0" class="form-control" id="trailing_activation_roi" name="trailing_activation_roi" value="<?= $v('trailing_activation_roi', '0.02') ?>">
                            <small class="text-secondary">ROI threshold to activate trailing (>= 0)</small>
                        </div>
                        <div class="mb-3">
                            <label for="trailing_min_lock_roi" class="form-label">Trailing Min Lock ROI</label>
                            <input type="number" step="0.001" min="0" class="form-control" id="trailing_min_lock_roi" name="trailing_min_lock_roi" value="<?= $v('trailing_min_lock_roi', '0.005') ?>">
                            <small class="text-secondary">Minimum ROI to lock when trailing (>= 0)</small>
                        </div>
                        <div class="mb-3">
                            <label for="trailing_min_step" class="form-label">Trailing Min Step</label>
                            <input type="number" step="0.001" min="0.001" class="form-control" id="trailing_min_step" name="trailing_min_step" value="<?= $v('trailing_min_step', '0.005') ?>">
                            <small class="text-secondary">Minimum trailing step size (> 0)</small>
                        </div>
                        <div class="mb-3 form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="brain_may_delay_trailing" name="brain_may_delay_trailing" value="1" <?= $checked('brain_may_delay_trailing') ?>>
                            <label class="form-check-label" for="brain_may_delay_trailing">Brain May Delay Trailing</label>
                            <br><small class="text-secondary">Allow brain to delay trailing activation</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Exit Safety Section -->
        <div class="row">
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi bi-shield-exclamation me-2"></i>
                        <h5 style="margin: 0;">Exit Safety</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="max_trade_duration_minutes" class="form-label">Max Trade Duration (minutes)</label>
                            <input type="number" step="1" min="1" class="form-control" id="max_trade_duration_minutes" name="max_trade_duration_minutes" value="<?= $v('max_trade_duration_minutes', '1440') ?>">
                            <small class="text-secondary">Maximum trade duration before stale exit (>= 1)</small>
                        </div>
                        <div class="mb-3 form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="stale_trade_exit_enabled" name="stale_trade_exit_enabled" value="1" <?= $checked('stale_trade_exit_enabled') ?>>
                            <label class="form-check-label" for="stale_trade_exit_enabled">Stale Trade Exit Enabled</label>
                            <br><small class="text-secondary">Automatically close trades exceeding max duration</small>
                        </div>
                        <div class="mb-3 form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="break_even_enabled" name="break_even_enabled" value="1" <?= $checked('break_even_enabled') ?>>
                            <label class="form-check-label" for="break_even_enabled">Break-Even Enabled</label>
                            <br><small class="text-secondary">Move stop to break-even after ROI threshold</small>
                        </div>
                        <div class="mb-3">
                            <label for="break_even_activation_roi" class="form-label">Break-Even Activation ROI</label>
                            <input type="number" step="0.001" min="0" class="form-control" id="break_even_activation_roi" name="break_even_activation_roi" value="<?= $v('break_even_activation_roi', '0.01') ?>">
                            <small class="text-secondary">ROI to activate break-even (>= 0)</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="d-flex gap-2 mb-4">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg me-1"></i> Save
            </button>
            <button type="reset" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
            </button>
            <a href="<?= htmlspecialchars($smartBrainUrl) ?>/user_config" class="btn btn-outline-info">
                <i class="bi bi-arrow-repeat me-1"></i> Reload
            </a>
        </div>
    </form>
<?php
};

require __DIR__ . '/_layout.php';
