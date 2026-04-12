<?php
declare(strict_types=1);

/**
 * Smart Brain tab — Brain routing and live-trading config preview.
 * READ-ONLY — shadow system.
 */

$params        = $data['params']             ?? [];
$brainPreview  = ($preview['modules'] ?? [])['smart_brain'] ?? [];

$brainKeys = [
    'live_trading_enabled',
    'live_max_positions',
    'live_signal_selection_mode',
    'live_one_trade_per_symbol',
    'live_entry_policy',
    'live_reverse_side_enabled',
    'execution_profile',
    'leverage_mode',
    'manual_leverage',
    'max_leverage',
    'max_budget_per_coin',
    'stop_control_mode',
    'stop_loss_from_entry_roi',
    'trailing_enabled',
    'trailing_mode',
    'trailing_activation_roi',
    'trailing_activation_floor_roi',
    'trailing_floor_lock_roi',
    'break_even_enabled',
    'break_even_activation_roi',
];

ob_start();
?>
<div class="row g-3">
    <div class="col-12 col-xl-7">
        <div class="card">
            <div class="card-header"><i class="bi bi-cpu me-2 text-primary"></i>Smart Brain — Operational Config (Draft)</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th style="width:32%">Parameter</th><th>Value</th><th>Source File</th><th>Conflict?</th></tr></thead>
                    <tbody>
                    <?php foreach ($brainKeys as $k):
                        if (!isset($params[$k])) continue;
                        $e      = $params[$k];
                        $val    = $e['value'];
                        $valStr = is_array($val)
                            ? implode(', ', array_map('strval', $val))
                            : (is_bool($val) ? ($val ? 'true' : 'false') : (string)$val);
                        $valStr = ($val === null || $valStr === '') ? '—' : $valStr;
                        $conflict = isset($e['all_values']) && count($e['all_values']) > 1;
                    ?>
                    <tr class="param-row">
                        <td><strong><?= htmlspecialchars($e['label'] ?? $k) ?></strong></td>
                        <td class="font-monospace"><?= htmlspecialchars($valStr) ?></td>
                        <td class="source-tag"><?= htmlspecialchars(basename($e['source_file'] ?? '')) ?></td>
                        <td><?php if ($conflict): ?><span class="badge badge-conflict conflict-badge">conflict</span><?php else: ?>—<?php endif; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-5">
        <div class="card">
            <div class="card-header"><i class="bi bi-eye me-2 text-success"></i>Smart Brain — Effective Config Preview</div>
            <div class="card-body p-0">
                <?php if (empty($brainPreview)): ?>
                    <div class="p-3 text-muted">No data — run Re-extract first.</div>
                <?php else: ?>
                <table class="table table-sm mb-0">
                    <thead><tr><th style="width:30%">Section</th><th>Key / Value</th><th style="width:20%">Layer</th></tr></thead>
                    <tbody>
                    <?php foreach ($brainPreview as $section => $vals): ?>
                    <tr class="param-row">
                        <td class="font-monospace small align-top"><?= htmlspecialchars($section) ?></td>
                        <td>
                        <?php if (is_array($vals)): ?>
                            <?php foreach ($vals as $k => $v): ?>
                                <div class="small">
                                    <span class="text-muted"><?= htmlspecialchars($k) ?>:</span>
                                    <span class="font-monospace ms-1">
                                        <?= htmlspecialchars(is_array($v) ? json_encode($v) : (is_bool($v) ? ($v ? 'true' : 'false') : (string)$v)) ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php elseif ($vals === null): ?>
                            <span class="text-muted">—</span>
                        <?php else: ?>
                            <span class="font-monospace small"><?= htmlspecialchars((string)$vals) ?></span>
                        <?php endif; ?>
                        </td>
                        <td class="align-top">
                            <span class="badge bg-secondary" title="Source: brain_effective (merged runtime snapshot)">effective_runtime</span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="p-2 border-top" style="border-color:var(--border-color)!important;font-size:0.78rem;color:#64748b;">
                    Source: <code>brain_effective</code> = merged runtime snapshot (effective_config.json).
                    Values shown are what Smart Brain currently operates under.
                    Not yet governed by Config Center.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
