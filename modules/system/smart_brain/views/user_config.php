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
