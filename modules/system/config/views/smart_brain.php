<?php
declare(strict_types=1);

/**
 * Smart Brain tab — Brain routing and live-trading config preview.
 * READ-ONLY — shadow system.
 */

$params          = $data['params']             ?? [];
$brainPreview    = ($preview['modules'] ?? [])['smart_brain'] ?? [];
$migrationStatus = $migrationStatus            ?? [];

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

    <?php
    // ── Migration Status Banner ──────────────────────────────────────────────
    $msAvail     = !empty($migrationStatus['available']);
    $msWave      = htmlspecialchars($migrationStatus['switch_wave']      ?? 'v1_operational_params');
    $msPartial   = (bool)($migrationStatus['partially_migrated']         ?? false);
    $msUnified   = (bool)($migrationStatus['unified_config_available']   ?? false);
    $msMigCount  = (int)($migrationStatus['migrated_count']              ?? count($migrationStatus['switched_params'] ?? []));
    $msFbCount   = (int)($migrationStatus['fallback_count']              ?? count($migrationStatus['fallback_params'] ?? []));
    $msTotalCount= (int)($migrationStatus['first_wave_total']            ?? ($msMigCount + $msFbCount));
    $msSwitched  = (array)($migrationStatus['switched_params']           ?? []);
    $msFallback  = (array)($migrationStatus['fallback_params']           ?? []);
    $msFbDetail  = (array)($migrationStatus['fallback_params_detail']    ?? []);
    $msSwDetail  = (array)($migrationStatus['switched_params_detail']    ?? []);
    $msRecAt     = htmlspecialchars($migrationStatus['recorded_at']      ?? '—');
    ?>
    <div class="col-12">
        <div class="card border-<?= $msAvail ? ($msFbCount > 0 ? 'warning' : 'success') : 'secondary' ?>">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span>
                    <i class="bi bi-shuffle me-2 <?= $msAvail ? 'text-warning' : 'text-secondary' ?>"></i>
                    <strong>Smart Brain — Config Migration Status</strong>
                </span>
                <span class="badge <?= $msAvail ? ($msPartial ? 'bg-warning text-dark' : 'bg-success') : 'bg-secondary' ?>">
                    <?php if (!$msAvail): ?>not available
                    <?php elseif ($msPartial): ?>partially migrated / soft-switched
                    <?php elseif ($msMigCount > 0): ?>fully migrated (wave 1)
                    <?php else: ?>legacy only
                    <?php endif; ?>
                </span>
            </div>
            <div class="card-body">
                <?php if (!$msAvail): ?>
                <p class="text-muted mb-0 small">Migration status not available — run Smart Brain at least once to generate <code>config_source_status.json</code>.</p>
                <?php else: ?>
                <div class="row g-3">
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="small text-muted mb-1">Migration Wave</div>
                        <code class="fs-6"><?= $msWave ?></code>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="small text-muted mb-1">Unified Config Available</div>
                        <span class="badge <?= $msUnified ? 'bg-success' : 'bg-danger' ?>"><?= $msUnified ? 'yes' : 'no' ?></span>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="small text-muted mb-1">Params via Unified Config</div>
                        <strong class="text-success"><?= $msMigCount ?></strong>
                        <span class="text-muted small"> / <?= $msTotalCount ?></span>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="small text-muted mb-1">Params on Legacy Fallback</div>
                        <strong class="<?= $msFbCount > 0 ? 'text-warning' : 'text-muted' ?>"><?= $msFbCount ?></strong>
                        <span class="text-muted small"> / <?= $msTotalCount ?></span>
                    </div>
                </div>

                <?php if (!empty($msSwitched)): ?>
                <div class="mt-3">
                    <div class="small fw-bold text-success mb-1"><i class="bi bi-check-circle me-1"></i>Using Unified Config (<?= $msMigCount ?> params)</div>
                    <div class="d-flex flex-wrap gap-1">
                    <?php foreach ($msSwitched as $sp):
                        $spd  = $msSwDetail[$sp] ?? [];
                        $spv  = $spd['value'] ?? '—';
                        $spvStr = is_bool($spv) ? ($spv ? 'true' : 'false') : (string)$spv;
                        $title = 'value=' . htmlspecialchars($spvStr)
                               . ' | source_layer=' . htmlspecialchars($spd['source_layer'] ?? 'unified_config')
                               . ' | via=' . htmlspecialchars($spd['via'] ?? 'unified_config_operational_draft')
                               . ' | original_source=' . htmlspecialchars($spd['original_source'] ?? '—');
                    ?>
                        <span class="badge bg-success" title="<?= htmlspecialchars($title) ?>"
                              data-bs-toggle="tooltip" data-bs-placement="top">
                            <?= htmlspecialchars($sp) ?>
                        </span>
                    <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($msFallback)): ?>
                <div class="mt-2">
                    <div class="small fw-bold text-warning mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Legacy Fallback — explicit (<?= $msFbCount ?> params)</div>
                    <div class="d-flex flex-wrap gap-1">
                    <?php foreach ($msFallback as $fp):
                        $fpd   = $msFbDetail[$fp] ?? [];
                        $fpv   = $fpd['value'] ?? '—';
                        $fpvStr = is_bool($fpv) ? ($fpv ? 'true' : 'false') : (string)$fpv;
                        $title = 'value=' . htmlspecialchars($fpvStr)
                               . ' | source_layer=' . htmlspecialchars($fpd['source_layer'] ?? 'legacy_user_config')
                               . ' | legacy_fallback_used=true'
                               . ' | fallback_reason=' . htmlspecialchars($fpd['fallback_reason'] ?? '—')
                               . ' | fallback_source=' . htmlspecialchars($fpd['fallback_source'] ?? 'brain_user_config');
                    ?>
                        <span class="badge bg-warning text-dark" title="<?= htmlspecialchars($title) ?>"
                              data-bs-toggle="tooltip" data-bs-placement="top">
                            <?= htmlspecialchars($fp) ?>
                        </span>
                    <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="mt-2 small text-muted">Last recorded: <code><?= $msRecAt ?></code></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

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
