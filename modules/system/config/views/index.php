<?php
declare(strict_types=1);

/**
 * Quick Control tab — key operational parameters at a glance.
 *
 * Shows the most frequently-changed operational params extracted from
 * Smart Brain, Trading Bot, and Profit Manager configs.
 * READ-ONLY — shadow system.
 */

$params = $data['params'] ?? [];

// Keys surfaced on Quick Control
$quickKeys = [
    'live_trading_enabled',
    'bot_mode',
    'bot_enabled',
    'live_max_positions',
    'max_intents_per_run',
    'live_signal_selection_mode',
    'live_entry_policy',
    'execution_profile',
    'leverage_mode',
    'manual_leverage',
    'max_budget_per_coin',
    'stop_control_mode',
    'stop_loss_from_entry_roi',
    'trailing_enabled',
    'trailing_mode',
    'trailing_activation_roi',
    'break_even_enabled',
    'pm_trailing_owner',
    'pm_enabled',
];

$quick = [];
foreach ($quickKeys as $k) {
    if (isset($params[$k])) {
        $quick[$k] = $params[$k];
    }
}

ob_start();
?>
<div class="row g-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="bi bi-sliders me-2 text-warning"></i>Quick Control — Operational Parameters</span>
                <span class="badge badge-shadow">preview only</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($quick)): ?>
                    <div class="p-3 text-muted">No data yet. Click <strong>Re-extract</strong> to run the audit pass.</div>
                <?php else: ?>
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th style="width:28%">Parameter</th>
                            <th style="width:20%">Current Value</th>
                            <th style="width:22%">Source</th>
                            <th style="width:10%">Type</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($quick as $key => $entry): ?>
                        <?php
                        $val      = $entry['value'];
                        $valStr   = is_array($val) ? implode(', ', array_map('strval', $val)) : (is_bool($val) ? ($val ? 'true' : 'false') : (string)$val);
                        $valStr   = $valStr === '' || $val === null ? '—' : $valStr;
                        $srcFile  = $entry['source_file'] ?? '';
                        $srcShort = basename($srcFile);
                        $conflict = isset($entry['all_values']) && count($entry['all_values']) > 1;
                        ?>
                        <tr class="param-row">
                            <td>
                                <strong><?= htmlspecialchars($entry['label'] ?? $key) ?></strong>
                                <?php if ($conflict): ?>
                                    <span class="badge badge-conflict conflict-badge ms-1">conflict</span>
                                <?php endif; ?>
                            </td>
                            <td class="font-monospace"><?= htmlspecialchars($valStr) ?></td>
                            <td class="source-tag"><?= htmlspecialchars($srcShort) ?></td>
                            <td><span class="badge badge-operational">operational</span></td>
                            <td class="small text-muted"><?= htmlspecialchars($entry['notes'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Conflict highlight -->
    <?php
    $conflicts = array_filter($quick, static fn($e) => isset($e['all_values']) && count($e['all_values']) > 1);
    if (!empty($conflicts)):
    ?>
    <div class="col-12">
        <div class="card border-danger">
            <div class="card-header text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>Detected Conflicts in Quick Control Params</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Parameter</th><th>Source</th><th>Value</th></tr></thead>
                    <tbody>
                    <?php foreach ($conflicts as $key => $entry): ?>
                        <?php foreach ($entry['all_values'] as $srcName => $srcVal): ?>
                        <tr>
                            <td><?= htmlspecialchars($entry['label'] ?? $key) ?></td>
                            <td class="source-tag"><?= htmlspecialchars($srcName) ?></td>
                            <td class="font-monospace small"><?= htmlspecialchars(is_array($srcVal) ? json_encode($srcVal) : (string)$srcVal) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
