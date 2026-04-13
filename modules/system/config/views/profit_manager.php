<?php
declare(strict_types=1);

/**
 * Менеджер прибыли — конфигурация и предпросмотр.
 * Частично мигрирован (волна 1): pm_enabled и pm_trailing_owner
 * читаются из config_operational_master.json (с явным fallback на legacy прокси).
 */

$params          = $data['params']                        ?? [];
$masterParams    = ($master['params'] ?? []);
$pmPreview       = ($preview['modules'] ?? [])['profit_manager'] ?? [];
$migrationStatus = $migrationStatus ?? ['available' => false];

// Build migration summary display data
$migAvail    = (bool)($migrationStatus['available'] ?? false);
$migPartial  = (bool)($migrationStatus['partially_migrated'] ?? false);
$migCount    = (int)($migrationStatus['migrated_count']   ?? 0);
$fallback    = (int)($migrationStatus['fallback_count']   ?? 0);
$totalFirst  = (int)($migrationStatus['first_wave_total'] ?? 0);
$switchedParams = (array)($migrationStatus['switched_params'] ?? []);
$fallbackParams = (array)($migrationStatus['fallback_params'] ?? []);
$switchedDetail = (array)($migrationStatus['switched_params_detail'] ?? []);
$fallbackDetail = (array)($migrationStatus['fallback_params_detail'] ?? []);

$pmFirstWaveKeys = ['pm_enabled', 'pm_trailing_owner'];

// Helper: get display value — master overrides draft
$effectiveVal = static function (string $key, array $params, array $masterParams) {
    if (isset($masterParams[$key])) {
        $v = $masterParams[$key]['value'];
        return [
            'value'  => $v,
            'source' => 'master',
        ];
    }
    if (isset($params[$key])) {
        return [
            'value'  => $params[$key]['value'],
            'source' => 'draft',
        ];
    }
    return ['value' => null, 'source' => 'unknown'];
};

$labels = [
    'pm_enabled'        => 'PM включён',
    'pm_trailing_owner' => 'Владелец трейлинга',
    'trailing_enabled'  => 'Трейлинг включён',
    'trailing_mode'     => 'Режим трейлинга',
    'trailing_activation_roi'       => 'Активация трейлинга (ROI)',
    'trailing_activation_floor_roi' => 'Нижняя граница (ROI)',
    'break_even_enabled'            => 'Безубыток включён',
    'break_even_activation_roi'     => 'Активация безубытка (ROI)',
];

ob_start();
?>
<div class="row g-3">

    <!-- Баннер: частично мигрирован -->
    <div class="col-12">
        <?php if ($migAvail && $migPartial): ?>
        <div class="migration-notice d-flex align-items-start gap-2">
            <i class="bi bi-shuffle text-warning mt-1 flex-shrink-0"></i>
            <div>
                <strong class="text-warning">Менеджер Прибыли — частично мигрирован (волна 1).</strong>
                Ключевые операционные параметры (<code>pm_enabled</code>, <code>pm_trailing_owner</code>)
                теперь читаются из <code>config_operational_master.json</code> в приоритете над legacy-источником.
                Fallback на legacy-прокси активен для параметров, отсутствующих в мастер-конфиге.
                Редактировать эти параметры можно в разделе <a href="<?= htmlspecialchars($baseUrl) ?>" class="text-info">«Быстрое управление»</a>.
            </div>
        </div>
        <?php elseif ($migAvail): ?>
        <div class="migration-notice d-flex align-items-start gap-2" style="border-color:rgba(245,158,11,0.35)!important;">
            <i class="bi bi-hourglass-split text-warning mt-1 flex-shrink-0"></i>
            <div>
                <strong class="text-warning">Менеджер Прибыли — готов к миграции.</strong>
                Мастер-конфиг ещё не сохранён — PM использует legacy-прокси.
                Сохраните операционный мастер-конфиг через <a href="<?= htmlspecialchars($baseUrl) ?>" class="text-info">«Быстрое управление»</a>, чтобы активировать мягкое переключение.
            </div>
        </div>
        <?php else: ?>
        <div class="migration-notice d-flex align-items-start gap-2" style="border-color:rgba(100,116,139,0.35)!important;">
            <i class="bi bi-clock-history text-secondary mt-1 flex-shrink-0"></i>
            <div>
                <strong>Менеджер Прибыли — статус миграции неизвестен.</strong>
                Артефакт <code>storage/runtime/config_source_status.json</code> ещё не создан.
                PM запишет его при следующем цикле выполнения.
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Статус первой волны: pm_enabled и pm_trailing_owner -->
    <div class="col-12 col-xl-6">
        <div class="card">
            <div class="card-header fw-semibold">
                <i class="bi bi-diagram-2 me-2 text-warning"></i>Статус миграции — Волна 1
            </div>
            <div class="card-body p-0">
                <?php if (!$migAvail): ?>
                    <p class="p-3 text-secondary">Статус ещё не записан — запустите PM для получения данных.</p>
                <?php else: ?>
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th style="width:38%">Параметр</th>
                            <th>Значение</th>
                            <th>Источник</th>
                            <th>Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pmFirstWaveKeys as $k):
                        $isSwitched = in_array($k, $switchedParams, true);
                        $isFallback = in_array($k, $fallbackParams, true);
                        $detail     = $isSwitched ? ($switchedDetail[$k] ?? []) : ($fallbackDetail[$k] ?? []);
                        $val        = $detail['value'] ?? null;
                        $valStr     = is_bool($val) ? ($val ? 'true' : 'false') : ($val === null ? '—' : (string)$val);
                        $layer      = $detail['source_layer'] ?? ($isSwitched ? 'unified_config_master' : 'legacy_pm_proxy');
                        $label      = $labels[$k] ?? $k;
                    ?>
                    <tr class="param-row">
                        <td class="fw-medium"><?= htmlspecialchars($label) ?></td>
                        <td class="font-monospace"><?= htmlspecialchars($valStr) ?></td>
                        <td class="source-tag">
                            <?php if ($isSwitched): ?>
                                <span class="badge badge-master" title="<?= htmlspecialchars($detail['via'] ?? '') ?>">мастер</span>
                            <?php elseif ($isFallback): ?>
                                <span class="badge bg-secondary" title="<?= htmlspecialchars($detail['fallback_reason'] ?? '') ?>">fallback</span>
                            <?php else: ?>
                                <span class="badge bg-dark text-secondary">—</span>
                            <?php endif; ?>
                            <small class="ms-1 text-secondary"><?= htmlspecialchars($layer) ?></small>
                        </td>
                        <td>
                            <?php if ($isSwitched): ?>
                                <span class="badge badge-ok" style="font-size:0.7rem;"><i class="bi bi-check-circle me-1"></i>мигрирован</span>
                            <?php elseif ($isFallback): ?>
                                <span class="badge badge-shadow" style="font-size:0.7rem;"><i class="bi bi-arrow-return-right me-1"></i>legacy</span>
                            <?php else: ?>
                                <span class="badge bg-secondary" style="font-size:0.7rem;">ожидание</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="px-3 py-2" style="font-size:0.78rem;color:#94a3b8;border-top:1px solid var(--border-color);">
                    Мигрировано: <strong class="text-light"><?= $migCount ?></strong> / <?= $totalFirst ?> ·
                    Fallback: <strong class="text-warning"><?= $fallback ?></strong> ·
                    Записано: <?= htmlspecialchars($migrationStatus['recorded_at'] ?? '—') ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Предпросмотр операционной конфигурации PM -->
    <div class="col-12 col-xl-6">
        <div class="card">
            <div class="card-header fw-semibold">
                <i class="bi bi-eye me-2 text-info"></i>Операционный конфиг PM — эффективный предпросмотр
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th style="width:40%">Параметр</th>
                            <th>Значение</th>
                            <th>Слой</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_keys($labels) as $k):
                        $eff    = $effectiveVal($k, $params, $masterParams);
                        $val    = $eff['value'];
                        $src    = $eff['source'];
                        $valStr = is_bool($val) ? ($val ? 'true' : 'false') : ($val === null ? '—' : (string)$val);
                        $label  = $labels[$k] ?? $k;
                    ?>
                    <tr class="param-row">
                        <td class="fw-medium"><?= htmlspecialchars($label) ?></td>
                        <td class="font-monospace"><?= htmlspecialchars($valStr) ?></td>
                        <td>
                            <?php if ($src === 'master'): ?>
                                <span class="master-badge">мастер</span>
                            <?php elseif ($src === 'draft'): ?>
                                <span class="badge bg-secondary" style="font-size:0.7rem;">черновик</span>
                            <?php else: ?>
                                <span class="text-secondary small">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Текущий эффективный конфиг из audit engine -->
    <?php if (!empty($pmPreview)): ?>
    <div class="col-12">
        <div class="card">
            <div class="card-header fw-semibold">
                <i class="bi bi-card-list me-2 text-secondary"></i>Детальный конфиг PM из audit engine
            </div>
            <div class="card-body p-0">
                <?php
                $pmLayerMap = [
                    'module'    => ['label' => 'pm_config › bot_config',  'title' => 'Блок module PM — проксируется из config.php Trading Bot'],
                    'execution' => ['label' => 'pm_config › bot_runtime', 'title' => 'Блок execution PM — объединение bot.json + config.php'],
                ];
                ?>
                <?php foreach ($pmPreview as $section => $block): ?>
                <div class="p-2 border-bottom" style="border-color:var(--border-color)!important;">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <span class="text-secondary small text-uppercase fw-semibold"><?= htmlspecialchars($section) ?></span>
                        <?php $layer = $pmLayerMap[$section] ?? ['label' => 'pm_config', 'title' => '']; ?>
                        <span class="badge bg-secondary" title="<?= htmlspecialchars($layer['title']) ?>"><?= htmlspecialchars($layer['label']) ?></span>
                    </div>
                    <?php if (is_array($block)): ?>
                        <?php foreach ($block as $k => $v): ?>
                        <div class="small d-flex gap-2">
                            <span class="text-secondary" style="min-width:200px"><?= htmlspecialchars($k) ?></span>
                            <span class="font-monospace text-light"><?= htmlspecialchars(is_bool($v) ? ($v ? 'true' : 'false') : (string)$v) ?></span>
                        </div>
                        <?php endforeach; ?>
                    <?php elseif ($block !== null): ?>
                        <span class="font-monospace small text-light"><?= htmlspecialchars((string)$block) ?></span>
                    <?php else: ?>
                        <span class="text-secondary">—</span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <div class="p-2" style="font-size:0.78rem;color:#94a3b8;">
                    Источник: <code>pm_config</code> (profit_manager/config/config.php) →
                    <code>bot_config</code> + <code>bot_runtime</code>.
                    Первая волна: <code>pm_enabled</code>, <code>pm_trailing_owner</code>
                    теперь читаются из unified config (мастер-конфиг в приоритете).
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="col-12">
        <div class="card">
            <div class="card-body text-secondary">
                <i class="bi bi-info-circle me-1"></i>Данные audit engine недоступны — нажмите «Перечитать» для обновления.
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Архитектурная справка -->
    <div class="col-12">
        <div class="card">
            <div class="card-header fw-semibold">
                <i class="bi bi-info-circle me-2 text-secondary"></i>Архитектура конфигурации PM (после волны 1)
            </div>
            <div class="card-body small text-secondary">
                <p>Менеджер Прибыли читает конфиг через прокси
                <code>modules/system/profit_manager/config/config.php</code> →
                <code>modules/system/trading_bot/config/config.php</code> (блок <code>profit_manager</code>) +
                <code>trading_bot/config/bot.json</code> (runtime-переопределения).</p>

                <p class="mb-0"><strong class="text-light">После волны 1 (текущее состояние):</strong>
                параметры <code>pm_enabled</code> и <code>pm_trailing_owner</code>
                читаются из <code>config_operational_master.json</code> в приоритете над legacy-источником.
                При отсутствии параметра в мастер-конфиге — явный fallback на legacy-прокси с фиксацией в
                <code>profit_manager/storage/runtime/config_source_status.json</code>.
                Coin Passport мигрирован — см. вкладку
                <a href="<?= htmlspecialchars($baseUrl) ?>/coin_cycle" class="text-info">«Монета / Цикл»</a>.</p>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';

