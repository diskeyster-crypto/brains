<?php
/**
 * Smart Brain Module - Global Config View
 *
 * Read-only configuration overview:
 * A. Brain Auto — derived values (read-only)
 * B. Effective Runtime — merged snapshot (read-only)
 * C. Advanced / Raw — collapsible technical config
 *
 * User-editable limits are on the dedicated User Config tab.
 */

/** @var string $smartBrainUrl */
/** @var array<string,mixed> $config */
/** @var array<string,mixed> $user_limits */
/** @var array<string,mixed> $brain_auto */
/** @var array<string,mixed> $effective_config */

$pageTitle = 'Smart Brain - Global Config';
$activeTab = 'config';

$pageContent = function() use ($config, $user_limits, $brain_auto, $effective_config, $smartBrainUrl) {
    $renderValue = function($value) use (&$renderValue): string {
        if (is_bool($value)) {
            return '<span class="badge ' . ($value ? 'bg-success' : 'bg-danger') . '">' . ($value ? 'true' : 'false') . '</span>';
        }
        if (is_array($value)) {
            return '<details><summary class="text-info" style="cursor:pointer;">Array (' . count($value) . ')</summary>'
                . '<pre style="background:#0f172a; padding:8px; border-radius:4px; margin-top:4px; font-size:0.8rem; max-height:200px; overflow:auto;">'
                . htmlspecialchars(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                . '</pre></details>';
        }
        if (is_numeric($value)) {
            return '<span class="text-warning">' . htmlspecialchars((string)$value) . '</span>';
        }
        return htmlspecialchars((string)$value);
    };

    $limitLabels = [
        'max_budget_per_coin' => 'Max Budget per Coin (USDT)',
        'max_active_tasks' => 'Max Active Tasks',
        'max_leverage' => 'Max Leverage',
        'brain_mode' => 'Brain Mode',
        'bootstrap_enabled' => 'Bootstrap Enabled',
        'bootstrap_max_signals' => 'Bootstrap Max Signals',
        'bootstrap_budget_factor' => 'Bootstrap Budget Factor',
        'bootstrap_max_leverage' => 'Bootstrap Max Leverage',
        'min_reliability_after_warmup' => 'Min Reliability (after warmup)',
        'warmup_min_trades' => 'Warmup Min Trades',
    ];

    $autoLabels = [
        'effective_strength_threshold' => 'Strength Threshold',
        'effective_entry_zone_percent' => 'Entry Zone %',
        'effective_budget_scaler' => 'Budget Scaler (profile)',
        'effective_leverage_scaler' => 'Leverage Scaler (profile)',
        'effective_reliability_gate' => 'Reliability Gate',
    ];
?>
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-gear me-2 text-primary"></i>Global Configuration</h4>
            <p class="text-secondary mb-0">Brain Auto / Effective Runtime / Advanced Raw — <a href="<?= htmlspecialchars($smartBrainUrl) ?>/user_config" class="text-info">Edit User Config →</a></p>
        </div>
    </div>

    <div class="row">
        <!-- A. User Limits (read-only summary) -->
        <div class="col-md-6 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center">
                    <i class="bi bi-sliders me-2"></i>
                    <h5 style="margin: 0;">User Limits</h5>
                    <a href="<?= htmlspecialchars($smartBrainUrl) ?>/user_config" class="badge bg-primary ms-auto text-decoration-none">edit →</a>
                </div>
                <div class="card-body p-0">
                    <table class="table table-dark table-hover mb-0" style="font-size: 0.9rem;">
                        <thead><tr><th style="width:50%;">Parameter</th><th>Value</th></tr></thead>
                        <tbody>
                        <?php foreach ($limitLabels as $key => $label): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($key) ?></code><br><small class="text-secondary"><?= htmlspecialchars($label) ?></small></td>
                            <td><?= $renderValue($user_limits[$key] ?? '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- B. Brain Auto -->
        <div class="col-md-6 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center">
                    <i class="bi bi-cpu me-2"></i>
                    <h5 style="margin: 0;">Brain Auto Values</h5>
                    <span class="badge bg-secondary ms-auto">read-only</span>
                </div>
                <div class="card-body p-0">
                    <table class="table table-dark table-hover mb-0" style="font-size: 0.9rem;">
                        <thead><tr><th style="width:50%;">Parameter</th><th>Value</th></tr></thead>
                        <tbody>
                        <?php foreach ($autoLabels as $key => $label): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($key) ?></code><br><small class="text-secondary"><?= htmlspecialchars($label) ?></small></td>
                            <td><?= $renderValue($brain_auto[$key] ?? '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- C. Pattern Selection (read-only, effective from User Config) -->
    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center">
                    <i class="bi bi-search me-2"></i>
                    <h5 style="margin: 0;">Pattern Selection</h5>
                    <a href="<?= htmlspecialchars($smartBrainUrl) ?>/user_config" class="badge bg-primary ms-auto text-decoration-none">edit →</a>
                </div>
                <div class="card-body">
                    <?php
                        $ps = (array)($effective_config['pattern_selection'] ?? []);
                        $psEnabled = (array)($ps['enabled'] ?? []);
                        $psMode = (string)($ps['mode'] ?? '-');
                        $allPatterns = ['double_bottom' => 'Double Bottom', 'double_top' => 'Double Top', 'pullback_trend_continue' => 'Pullback Trend Continue'];
                    ?>
                    <?php if (empty($effective_config)): ?>
                        <p class="text-secondary mb-0">Нет данных. Запустите Smart Brain для генерации effective config.</p>
                    <?php else: ?>
                        <div class="mb-2">
                            <?php foreach ($allPatterns as $pKey => $pLabel): ?>
                            <span class="badge <?= in_array($pKey, $psEnabled, true) ? 'bg-success' : 'bg-dark text-secondary' ?> me-1 mb-1"><?= htmlspecialchars($pLabel) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <small class="text-secondary">Режим: <strong class="text-info"><?= htmlspecialchars($psMode) ?></strong></small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- D. Effective Runtime -->
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center">
            <i class="bi bi-file-earmark-code me-2"></i>
            <h5 style="margin: 0;">Effective Runtime</h5>
            <span class="badge bg-info ms-auto">runtime/effective_config.json</span>
        </div>
        <div class="card-body">
            <?php if (empty($effective_config)): ?>
                <p class="text-secondary mb-0">No effective config snapshot yet. Run Smart Brain once to generate.</p>
            <?php else: ?>
                <pre style="background:#0f172a; padding:12px; border-radius:6px; font-size:0.8rem; max-height:400px; overflow:auto; margin:0;"><?= htmlspecialchars(json_encode($effective_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
            <?php endif; ?>
        </div>
    </div>

    <!-- D. Advanced / Raw -->
    <div class="card mb-4">
        <div class="card-header">
            <details>
                <summary style="cursor:pointer;">
                    <i class="bi bi-code-slash me-2"></i>
                    <strong>Advanced / Raw Config</strong>
                    <span class="badge bg-dark ms-2">technical</span>
                </summary>
            </details>
        </div>
        <div class="card-body p-0">
            <details>
                <summary class="px-3 py-2 text-secondary" style="cursor:pointer;">Show raw config sections</summary>
                <?php
                    $sections = [
                        'parser4'     => ['icon' => 'bi-search',            'title' => 'Parser4 (Analyzer)'],
                        'corridor'    => ['icon' => 'bi-arrows-expand',     'title' => 'Corridor Monitor'],
                        'risk_engine' => ['icon' => 'bi-shield-exclamation', 'title' => 'Risk Engine'],
                        'simulator'   => ['icon' => 'bi-joystick',          'title' => 'Simulator'],
                        'profiles'    => ['icon' => 'bi-person-badge',       'title' => 'Profiles'],
                        'ui'          => ['icon' => 'bi-palette',            'title' => 'UI Settings'],
                    ];
                ?>
                <div class="row p-3">
                <?php foreach ($sections as $key => $meta): ?>
                    <?php $sectionData = $config[$key] ?? []; ?>
                    <div class="col-md-6 mb-3">
                        <div class="card">
                            <div class="card-header d-flex align-items-center py-2">
                                <i class="bi <?= $meta['icon'] ?> me-2"></i>
                                <strong><?= htmlspecialchars($meta['title']) ?></strong>
                                <span class="badge bg-secondary ms-2"><?= $key ?></span>
                            </div>
                            <div class="card-body p-0">
                                <pre style="background:#0f172a; padding:8px; font-size:0.75rem; max-height:200px; overflow:auto; margin:0;"><?= htmlspecialchars(json_encode($sectionData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            </details>
        </div>
    </div>
<?php
};

require __DIR__ . '/_layout.php';
