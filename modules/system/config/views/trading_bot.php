<?php
declare(strict_types=1);

/**
 * Trading Bot — операционная конфигурация и статус миграции.
 * Editable — первая волна параметров Trading Bot сохраняется в мастер-конфиге.
 * Частично мигрирован (волна 1). Менеджер Прибыли — не мигрирован.
 */

$params          = $data['params']             ?? [];
$master          = $master                     ?? null;
$masterParams    = $master['params']           ?? [];
$botPreview      = ($preview['modules'] ?? [])['trading_bot'] ?? [];
$migrationStatus = $migrationStatus            ?? [];

$botKeys = ['bot_enabled','bot_mode','bot_brain_controlled','max_concurrent_positions',
            'max_intents_per_run','trailing_enabled','break_even_enabled','pm_trailing_owner','pm_enabled'];

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

    <!-- Статус миграции -->
    <div class="col-12">
        <div class="card border-<?= $msAvail ? ($msFbCount > 0 ? 'warning' : 'success') : 'secondary' ?>">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span>
                    <i class="bi bi-shuffle me-2 <?= $msAvail ? 'text-warning' : 'text-secondary' ?>"></i>
                    <strong>Trading Bot — Статус миграции конфига</strong>
                </span>
                <span class="badge <?= $msAvail ? ($msPartial ? 'bg-warning text-dark' : 'bg-success') : 'bg-secondary' ?>">
                    <?php if (!$msAvail): ?>не доступно
                    <?php elseif ($msPartial): ?>частично мигрирован / мягкое переключение
                    <?php elseif ($msMigCount > 0): ?>полностью мигрирован (волна 1)
                    <?php else: ?>только legacy<?php endif; ?>
                </span>
            </div>
            <div class="card-body">
                <?php if (!$msAvail): ?>
                <p class="text-muted mb-0 small">Статус миграции недоступен — запустите Trading Bot хотя бы один раз.</p>
                <?php else: ?>
                <div class="row g-3">
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="small text-muted mb-1">Волна миграции</div><code class="fs-6"><?= $msWave ?></code>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="small text-muted mb-1">Единый конфиг доступен</div>
                        <span class="badge <?= $msUnified ? 'bg-success' : 'bg-danger' ?>"><?= $msUnified ? 'да' : 'нет' ?></span>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="small text-muted mb-1">Параметров через единый конфиг</div>
                        <strong class="text-success"><?= $msMigCount ?></strong><span class="text-muted small"> / <?= $msTotalCount ?></span>
                    </div>
                    <div class="col-12 col-md-6 col-xl-3">
                        <div class="small text-muted mb-1">Параметров на legacy-fallback</div>
                        <strong class="<?= $msFbCount > 0 ? 'text-warning' : 'text-muted' ?>"><?= $msFbCount ?></strong>
                        <span class="text-muted small"> / <?= $msTotalCount ?></span>
                    </div>
                </div>
                <?php if ($msMasterSrc): ?>
                <div class="mt-2"><span class="badge badge-master"><i class="bi bi-floppy me-1"></i>Источник: config_operational_master.json</span></div>
                <?php endif; ?>
                <?php if (!empty($msSwitched)): ?>
                <div class="mt-3">
                    <div class="small fw-bold text-success mb-1"><i class="bi bi-check-circle me-1"></i>Используют единый конфиг (<?= $msMigCount ?> параметров)</div>
                    <div class="d-flex flex-wrap gap-1">
                    <?php foreach ($msSwitched as $sp):
                        $spd = $msSwDetail[$sp] ?? []; $spv = $spd['value'] ?? '—';
                        if (is_bool($spv)) $spv = $spv ? 'true' : 'false';
                        $isMaster = str_contains($spd['via'] ?? '', 'master');
                        $spTitle = 'value='.$spv.' | source_layer='.($spd['source_layer'] ?? '?').' | via='.($spd['via'] ?? '?');
                    ?>
                        <span class="badge" style="<?= $isMaster ? 'background:#7c3aed!' : 'background:#16a34a!' ?>important"
                              title="<?= htmlspecialchars($spTitle) ?>" data-bs-toggle="tooltip">
                            <?= htmlspecialchars($sp) ?><?php if ($isMaster): ?><i class="bi bi-floppy ms-1"></i><?php endif; ?>
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
                        $fpd = $msFbDetail[$fp] ?? []; $fpv = $fpd['value'] ?? '—';
                        if (is_bool($fpv)) $fpv = $fpv ? 'true' : 'false';
                        $fpTitle = 'value='.$fpv.' | source_layer=legacy_bot_runtime | fallback_reason='.($fpd['fallback_reason'] ?? '—');
                    ?>
                        <span class="badge bg-warning text-dark" title="<?= htmlspecialchars($fpTitle) ?>" data-bs-toggle="tooltip"><?= htmlspecialchars($fp) ?></span>
                    <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <!-- Таблица доказательств источников -->
                <?php
                $allMigDetail = array_merge(
                    array_map(fn($k) => array_merge(['_key' => $k, '_status' => 'unified'], $msSwDetail[$k] ?? []), $msSwitched),
                    array_map(fn($k) => array_merge(['_key' => $k, '_status' => 'fallback'], $msFbDetail[$k] ?? []), $msFallback)
                );
                ?>
                <?php if (!empty($allMigDetail)): ?>
                <div class="mt-3">
                    <div class="small fw-bold mb-2">Доказательство источника по параметрам</div>
                    <div class="table-responsive">
                    <table class="table table-sm mb-0" style="font-size:0.8rem;">
                        <thead>
                            <tr>
                                <th>Параметр</th><th>Значение</th><th>Владелец</th>
                                <th>Слой источника</th><th>Единый конфиг</th><th>Legacy fallback</th><th>Причина / Источник</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($allMigDetail as $row):
                            $isUnified = ($row['_status'] === 'unified');
                            $val = $row['value'] ?? '—'; if (is_bool($val)) $val = $val ? 'true' : 'false';
                            $srcOwner = $row['source_owner'] ?? 'trading_bot';
                            $srcLayer = $row['source_layer'] ?? ($isUnified ? 'unified_config' : 'legacy_bot_runtime');
                            $isMaster = str_contains($srcLayer, 'master');
                            $ucUsed   = (bool)($row['unified_config_used'] ?? $isUnified);
                            $lfUsed   = (bool)($row['legacy_fallback_used'] ?? !$isUnified);
                            $fbReason = $row['fallback_reason'] ?? ($isUnified ? ('via: '.($row['via'] ?? 'unified_config_operational_draft')) : '—');
                            $origSrc  = $row['original_source'] ?? ($row['fallback_source'] ?? '—');
                        ?>
                        <tr>
                            <td><code><?= htmlspecialchars($row['_key']) ?></code></td>
                            <td class="font-monospace"><?= htmlspecialchars((string)$val) ?></td>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars($srcOwner) ?></span></td>
                            <td><?php if ($isUnified): ?>
                                <span class="badge" style="background:<?= $isMaster ? '#7c3aed' : '#16a34a' ?>">
                                    <?= $isMaster ? 'мастер' : 'unified_config' ?>
                                </span>
                            <?php else: ?><span class="badge bg-warning text-dark">legacy_bot_runtime</span><?php endif; ?></td>
                            <td class="text-center"><?php if ($ucUsed): ?><span class="badge bg-success"><i class="bi bi-check"></i> да</span><?php else: ?><span class="badge bg-secondary">нет</span><?php endif; ?></td>
                            <td class="text-center"><?php if ($lfUsed): ?><span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle"></i> да</span><?php else: ?><span class="badge bg-secondary">нет</span><?php endif; ?></td>
                            <td class="source-tag"><?= htmlspecialchars($fbReason) ?><br><span class="text-secondary"><?= htmlspecialchars($origSrc) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <?php endif; ?>
                <div class="mt-2 text-secondary" style="font-size:0.78rem;">Записано: <code><?= $msRecAt ?></code> &nbsp;·&nbsp; <code>trading_bot/runtime/config_source_status.json</code></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Редактируемые параметры -->
    <div class="col-12 col-xl-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-robot me-2 text-warning"></i>Trading Bot — Операционные параметры (редактировать)</div>
            <div class="card-body">
                <form method="post" action="<?= htmlspecialchars($baseUrl) ?>/api/save">
                    <input type="hidden" name="_redirect" value="<?= htmlspecialchars($baseUrl) ?>/trading_bot">
                    <input type="hidden" name="_html_form" value="1">
                    <?php
                    $editFields = [
                        'bot_enabled'              => ['type'=>'bool',   'label'=>'Бот включён'],
                        'bot_mode'                 => ['type'=>'select', 'label'=>'Режим бота',
                            'opts'=>['live'=>'live — лайв','demo'=>'demo — демо','paper'=>'paper — бумажная']],
                        'bot_brain_controlled'     => ['type'=>'bool',   'label'=>'Управление от Brain'],
                        'max_concurrent_positions' => ['type'=>'int',    'label'=>'Макс. одновременных позиций'],
                        'max_intents_per_run'      => ['type'=>'int',    'label'=>'Макс. намерений за цикл'],
                        'pm_trailing_owner'        => ['type'=>'select', 'label'=>'Трейлинг управляет',
                            'opts'=>['profit_manager'=>'profit_manager','trading_bot'=>'trading_bot']],
                    ];
                    foreach ($editFields as $k => $def):
                        $draftEntry  = $params[$k] ?? null;
                        $draftVal    = $draftEntry['value'] ?? null;
                        $effectiveV  = isset($masterParams[$k]) ? $masterParams[$k]['value'] : $draftVal;
                        $hasMast     = isset($masterParams[$k]);
                    ?>
                    <div class="mb-3">
                        <label class="form-label small fw-bold mb-1">
                            <?= htmlspecialchars($def['label']) ?>
                            <?php if ($hasMast): ?><span class="master-badge ms-1">мастер</span><?php endif; ?>
                        </label>
                        <?php if ($def['type'] === 'bool'): ?>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="<?= htmlspecialchars($k) ?>"
                                       id="bf_<?= htmlspecialchars($k) ?>" value="1" <?= (bool)$effectiveV ? 'checked' : '' ?>>
                                <label class="form-check-label small text-muted" for="bf_<?= htmlspecialchars($k) ?>">
                                    <?= (bool)$effectiveV ? 'включено' : 'выключено' ?>
                                </label>
                            </div>
                        <?php elseif ($def['type'] === 'int'): ?>
                            <input type="number" class="form-control form-control-sm" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string)(int)$effectiveV) ?>" step="1">
                        <?php elseif ($def['type'] === 'select'): ?>
                            <select class="form-select form-select-sm" name="<?= htmlspecialchars($k) ?>">
                                <?php foreach ($def['opts'] as $ov => $ol): ?>
                                    <option value="<?= htmlspecialchars($ov) ?>" <?= (string)$effectiveV === $ov ? 'selected' : '' ?>><?= htmlspecialchars($ol) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <?php if ($draftEntry): ?><div class="source-tag mt-1"><?= htmlspecialchars(basename($draftEntry['source_file'] ?? '—')) ?></div><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-floppy me-1"></i>Сохранить</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="botSaveReextract"><i class="bi bi-arrow-clockwise me-1"></i>Сохранить и перечитать</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Эффективный конфиг -->
    <div class="col-12 col-xl-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-eye me-2 text-success"></i>Trading Bot — Текущий эффективный конфиг</div>
            <div class="card-body p-0">
                <?php if (empty($botPreview)): ?>
                    <div class="p-3 text-muted">Нет данных — нажмите «Перечитать».</div>
                <?php else: ?>
                    <?php $layerMap = [
                        'module'    => ['label' => 'unified_config › bot_runtime › bot_config',  'title' => 'Параметры первой волны через Config Module; остальное: bot.json'],
                        'execution' => ['label' => 'unified_config › bot_runtime › bot_config',  'title' => 'Параметры исполнения первой волны через Config Module'],
                        'exchange'  => ['label' => 'bot_config (immutable)',                      'title' => 'Параметры биржи из config.php — внутренние, не в первой волне'],
                    ]; ?>
                    <?php foreach ($botPreview as $section => $block): ?>
                    <div class="p-2 border-bottom" style="border-color: var(--border-color) !important;">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span class="text-muted small text-uppercase" style="letter-spacing:.05em"><?= htmlspecialchars($section) ?></span>
                            <?php $layer = $layerMap[$section] ?? ['label' => 'operational_master', 'title' => '']; ?>
                            <span class="badge bg-secondary" title="<?= htmlspecialchars($layer['title']) ?>"><?= htmlspecialchars($layer['label']) ?></span>
                        </div>
                        <?php if (is_array($block)): ?>
                            <?php foreach ($block as $k => $v): ?>
                            <div class="small d-flex gap-2">
                                <span class="text-muted" style="min-width:200px"><?= htmlspecialchars($k) ?></span>
                                <span class="font-monospace"><?= htmlspecialchars(is_array($v) ? json_encode($v) : (is_bool($v) ? ($v ? 'true' : 'false') : (string)$v)) ?></span>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <div class="p-2" style="font-size:0.78rem;color:#64748b;">
                        Параметры первой волны (<code>bot_enabled</code>, <code>bot_mode</code>, <code>max_intents_per_run</code> и др.) разрешаются через Центр Конфигурации.
                        Остальное: <code>bot.json</code> → <code>config.php</code>.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script>
document.getElementById('botSaveReextract')?.addEventListener('click', function() {
    const form = this.closest('form'); if (!form) return;
    const orig = form.action;
    form.action = orig.replace('/api/save', '/api/save_and_reextract');
    form.submit(); form.action = orig;
});
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el, {trigger:'hover'}));
document.querySelectorAll('.form-check-input[type=checkbox]').forEach(function(el) {
    if (!el.closest('.form-switch')) return;
    const label = el.closest('.form-switch')?.querySelector('.form-check-label');
    if (!label) return;
    el.addEventListener('change', function() { label.textContent = this.checked ? 'включено' : 'выключено'; });
});
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
