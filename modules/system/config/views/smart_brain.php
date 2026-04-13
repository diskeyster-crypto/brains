<?php
declare(strict_types=1);

/**
 * Smart Brain — операционная конфигурация и статус миграции.
 * Editable — первая волна параметров Smart Brain сохраняется в мастер-конфиге.
 * Частично мигрирован (волна 1).
 */

$params          = $data['params']             ?? [];
$master          = $master                     ?? null;
$masterParams    = $master['params']           ?? [];
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

$msAvail      = !empty($migrationStatus['available']);
$msWave       = htmlspecialchars($migrationStatus['switch_wave']       ?? 'v1_operational_params');
$msPartial    = (bool)($migrationStatus['partially_migrated']           ?? false);
$msUnified    = (bool)($migrationStatus['unified_config_available']    ?? false);
$msMigCount   = (int)($migrationStatus['migrated_count']               ?? count($migrationStatus['switched_params'] ?? []));
$msFbCount    = (int)($migrationStatus['fallback_count']               ?? count($migrationStatus['fallback_params'] ?? []));
$msTotalCount = (int)($migrationStatus['first_wave_total']             ?? ($msMigCount + $msFbCount));
$msSwitched   = (array)($migrationStatus['switched_params']            ?? []);
$msFallback   = (array)($migrationStatus['fallback_params']            ?? []);
$msFbDetail   = (array)($migrationStatus['fallback_params_detail']     ?? []);
$msSwDetail   = (array)($migrationStatus['switched_params_detail']     ?? []);
$msRecAt      = htmlspecialchars($migrationStatus['recorded_at']       ?? '—');
$msMasterSrc  = str_contains($migrationStatus['source'] ?? '', 'master');

ob_start();
?>
<div class="row g-3">

    <!-- ── Статус миграции ────────────────────────────────────────────────── -->
    <div class="col-12">
        <div class="card border-<?= $msAvail ? ($msFbCount > 0 ? 'warning' : 'success') : 'secondary' ?>">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span>
                    <i class="bi bi-shuffle me-2 <?= $msAvail ? 'text-warning' : 'text-secondary' ?>"></i>
                    <strong>Smart Brain — Статус миграции конфига</strong>
                </span>
                <span class="badge <?= $msAvail ? ($msPartial ? 'bg-warning text-dark' : 'bg-success') : 'bg-secondary' ?>">
                    <?php if (!$msAvail): ?>не доступно
                    <?php elseif ($msPartial): ?>частично мигрирован / мягкое переключение
                    <?php elseif ($msMigCount > 0): ?>полностью мигрирован (волна 1)
                    <?php else: ?>только legacy
                    <?php endif; ?>
                </span>
            </div>
            <div class="card-body">
                <?php if (!$msAvail): ?>
                <p class="text-muted mb-0 small">Статус миграции недоступен — запустите Smart Brain хотя бы один раз для генерации <code>runtime/config_source_status.json</code>.</p>
                <?php else: ?>
                <div class="row g-3">
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="small text-muted mb-1">Волна миграции</div>
                        <code class="fs-6"><?= $msWave ?></code>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="small text-muted mb-1">Единый конфиг доступен</div>
                        <span class="badge <?= $msUnified ? 'bg-success' : 'bg-danger' ?>"><?= $msUnified ? 'да' : 'нет' ?></span>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="small text-muted mb-1">Параметров через единый конфиг</div>
                        <strong class="text-success"><?= $msMigCount ?></strong>
                        <span class="text-muted small"> / <?= $msTotalCount ?></span>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="small text-muted mb-1">Параметров на legacy-fallback</div>
                        <strong class="<?= $msFbCount > 0 ? 'text-warning' : 'text-muted' ?>"><?= $msFbCount ?></strong>
                        <span class="text-muted small"> / <?= $msTotalCount ?></span>
                    </div>
                </div>

                <?php if ($msMasterSrc): ?>
                <div class="mt-2">
                    <span class="badge badge-master"><i class="bi bi-floppy me-1"></i>Источник: мастер-конфиг (config_operational_master.json)</span>
                </div>
                <?php endif; ?>

                <?php if (!empty($msSwitched)): ?>
                <div class="mt-3">
                    <div class="small fw-bold text-success mb-1"><i class="bi bi-check-circle me-1"></i>Используют единый конфиг (<?= $msMigCount ?> параметров)</div>
                    <div class="d-flex flex-wrap gap-1">
                    <?php foreach ($msSwitched as $sp):
                        $spd    = $msSwDetail[$sp] ?? [];
                        $spv    = $spd['value'] ?? '—';
                        $spvStr = is_bool($spv) ? ($spv ? 'true' : 'false') : (string)$spv;
                        $isMaster = str_contains($spd['via'] ?? '', 'master');
                        $title  = 'value=' . $spvStr
                               . ' | source_layer=' . ($spd['source_layer'] ?? '?')
                               . ' | via=' . ($spd['via'] ?? '?')
                               . ' | original_source=' . ($spd['original_source'] ?? '—');
                    ?>
                        <span class="badge <?= $isMaster ? 'bg-purple' : 'bg-success' ?>"
                              style="<?= $isMaster ? 'background:#7c3aed!important' : '' ?>"
                              title="<?= htmlspecialchars($title) ?>" data-bs-toggle="tooltip">
                            <?= htmlspecialchars($sp) ?>
                            <?php if ($isMaster): ?><i class="bi bi-floppy ms-1"></i><?php endif; ?>
                        </span>
                    <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($msFallback)): ?>
                <div class="mt-2">
                    <div class="small fw-bold text-warning mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Legacy fallback — явный (<?= $msFbCount ?> параметров)</div>
                    <div class="d-flex flex-wrap gap-1">
                    <?php foreach ($msFallback as $fp):
                        $fpd    = $msFbDetail[$fp] ?? [];
                        $fpv    = $fpd['value'] ?? '—';
                        $fpvStr = is_bool($fpv) ? ($fpv ? 'true' : 'false') : (string)$fpv;
                        $title  = 'value=' . $fpvStr
                               . ' | source_layer=' . ($fpd['source_layer'] ?? 'legacy_user_config')
                               . ' | legacy_fallback_used=true'
                               . ' | fallback_reason=' . ($fpd['fallback_reason'] ?? '—');
                    ?>
                        <span class="badge bg-warning text-dark" title="<?= htmlspecialchars($title) ?>" data-bs-toggle="tooltip">
                            <?= htmlspecialchars($fp) ?>
                        </span>
                    <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="mt-2 small text-muted">Записано: <code><?= $msRecAt ?></code></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Операционный конфиг (draft + мастер) ──────────────────────────── -->
    <div class="col-12 col-xl-7">
        <div class="card">
            <div class="card-header"><i class="bi bi-cpu me-2 text-primary"></i>Smart Brain — Операционные параметры</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th style="width:32%">Параметр</th><th>Значение</th><th>Мастер</th><th>Конфликт?</th></tr></thead>
                    <tbody>
                    <?php foreach ($brainKeys as $k):
                        if (!isset($params[$k])) continue;
                        $e        = $params[$k];
                        $val      = $e['value'];
                        $valStr   = is_array($val)
                            ? implode(', ', array_map('strval', $val))
                            : (is_bool($val) ? ($val ? 'true' : 'false') : (string)$val);
                        $valStr   = ($val === null || $valStr === '') ? '—' : $valStr;
                        $hasMast  = isset($masterParams[$k]);
                        $masterV  = $hasMast ? $masterParams[$k]['value'] : null;
                        $masterVS = is_bool($masterV) ? ($masterV ? 'true' : 'false') : (string)$masterV;
                        $conflict = isset($e['all_values']) && count($e['all_values']) > 1;
                    ?>
                    <tr class="param-row">
                        <td><strong><?= htmlspecialchars($e['label'] ?? $k) ?></strong></td>
                        <td class="font-monospace"><?= htmlspecialchars($hasMast ? $masterVS : $valStr) ?></td>
                        <td><?php if ($hasMast): ?><span class="master-badge">мастер</span><?php else: ?>—<?php endif; ?></td>
                        <td><?php if ($conflict): ?><span class="badge badge-conflict conflict-badge">конфликт</span><?php else: ?>—<?php endif; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ── Эффективный конфиг (runtime snapshot) ────────────────────────── -->
    <div class="col-12 col-xl-5">
        <div class="card">
            <div class="card-header"><i class="bi bi-eye me-2 text-success"></i>Smart Brain — Текущий эффективный конфиг</div>
            <div class="card-body p-0">
                <?php if (empty($brainPreview)): ?>
                    <div class="p-3 text-muted">Нет данных — нажмите «Перечитать».</div>
                <?php else: ?>
                <table class="table table-sm mb-0">
                    <thead><tr><th style="width:30%">Раздел</th><th>Ключ / Значение</th><th style="width:20%">Слой</th></tr></thead>
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
                            <span class="badge bg-secondary">effective_runtime</span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="p-2 border-top" style="border-color:var(--border-color)!important;font-size:0.78rem;color:#64748b;">
                    Источник: <code>brain_effective</code> = текущий runtime snapshot (effective_config.json).
                    Показывает, что Smart Brain использует в данный момент.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script>
// Bootstrap tooltips
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
    new bootstrap.Tooltip(el, {trigger: 'hover'});
});
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
