<?php
declare(strict_types=1);

/**
 * Coin Passport — конфигурация и статус миграции.
 * Частично мигрирован (волна 1): cp_enabled, cp_rebuild_all_enabled,
 * cp_rebuild_recent_enabled и cp_cycle_profiles_enabled читаются из
 * config_operational_master.json (с явным fallback на cp_config defaults).
 */

$params          = $data['params']                        ?? [];
$masterParams    = ($master['params'] ?? []);
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

$cpFirstWaveKeys = ['cp_enabled', 'cp_rebuild_all_enabled', 'cp_rebuild_recent_enabled', 'cp_cycle_profiles_enabled'];

// Helper: get display value — master overrides draft
$effectiveVal = static function (string $key, array $params, array $masterParams) {
    if (isset($masterParams[$key])) {
        return ['value' => $masterParams[$key]['value'], 'source' => 'master'];
    }
    if (isset($params[$key])) {
        return ['value' => $params[$key]['value'], 'source' => 'draft'];
    }
    return ['value' => null, 'source' => 'unknown'];
};

$labels = [
    'cp_enabled'                => 'Coin Passport включён',
    'cp_rebuild_all_enabled'    => 'Полный перестрой включён',
    'cp_rebuild_recent_enabled' => 'Перестрой активных монет включён',
    'cp_cycle_profiles_enabled' => 'Цикловые профили включены',
];

ob_start();

// Russian labels for fallback reasons shown in the UI.
$fallbackReasonLabels = [
    'missing_in_unified'          => 'параметр отсутствует в едином конфиге',
    'invalid_value'               => 'значение в едином конфиге недействительно (null)',
    'read_error'                  => 'ошибка чтения файла единого конфига',
    'unified_config_not_found'    => 'файлы единого конфига не найдены',
    'param_not_in_unified_config' => 'параметр не найден в едином конфиге',
];
?>
<div class="row g-3">

    <!-- Баннер: статус миграции -->
    <div class="col-12">
        <?php if ($migAvail && $migPartial): ?>
        <div class="migration-notice d-flex align-items-start gap-2">
            <i class="bi bi-shuffle text-warning mt-1 flex-shrink-0"></i>
            <div>
                <strong class="text-warning">Coin Passport — частично мигрирован (волна 1).</strong>
                Ключевые операционные параметры (<code>cp_enabled</code>, <code>cp_rebuild_all_enabled</code>,
                <code>cp_rebuild_recent_enabled</code>, <code>cp_cycle_profiles_enabled</code>)
                теперь читаются из <code>config_operational_master.json</code> в приоритете над
                локальными defaults (<code>coin_passport/config/config.php</code>).
                Fallback на локальный конфиг активен для параметров, отсутствующих в мастер-конфиге.
                Редактировать эти параметры можно в разделе
                <a href="<?= htmlspecialchars($baseUrl) ?>" class="text-info">«Быстрое управление»</a>.
            </div>
        </div>
        <?php elseif ($migAvail): ?>
        <div class="migration-notice d-flex align-items-start gap-2" style="border-color:rgba(245,158,11,0.35)!important;">
            <i class="bi bi-hourglass-split text-warning mt-1 flex-shrink-0"></i>
            <div>
                <strong class="text-warning">Coin Passport — готов к миграции.</strong>
                Мастер-конфиг ещё не сохранён — CP использует локальные defaults.
                Сохраните операционный мастер-конфиг через
                <a href="<?= htmlspecialchars($baseUrl) ?>" class="text-info">«Быстрое управление»</a>,
                чтобы активировать мягкое переключение.
            </div>
        </div>
        <?php else: ?>
        <div class="migration-notice d-flex align-items-start gap-2" style="border-color:rgba(100,116,139,0.35)!important;">
            <i class="bi bi-clock-history text-secondary mt-1 flex-shrink-0"></i>
            <div>
                <strong class="text-light">Coin Passport — статус миграции неизвестен.</strong>
                Артефакт <code>storage/runtime/config_source_status.json</code> ещё не создан.
                CP запишет его при следующем цикле выполнения (rebuildAll / rebuildRecentSymbols / buildCycleProfiles).
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Статус первой волны -->
    <div class="col-12 col-xl-6">
        <div class="card">
            <div class="card-header fw-semibold">
                <i class="bi bi-diagram-2 me-2 text-warning"></i>Статус миграции — Волна 1
            </div>
            <div class="card-body p-0">
                <?php if (!$migAvail): ?>
                    <p class="p-3 text-secondary">Статус ещё не записан — запустите Coin Passport (cron) для получения данных.</p>
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
                    <?php foreach ($cpFirstWaveKeys as $k):
                        $isSwitched     = in_array($k, $switchedParams, true);
                        $isFallback     = in_array($k, $fallbackParams, true);
                        $detail         = $isSwitched ? ($switchedDetail[$k] ?? []) : ($fallbackDetail[$k] ?? []);
                        $val            = $detail['value'] ?? null;
                        $valStr         = is_bool($val) ? ($val ? 'true' : 'false') : ($val === null ? '—' : (string)$val);
                        $layer          = $detail['source_layer'] ?? ($isSwitched ? 'unified_config_master' : 'legacy_cp_config');
                        $label          = $labels[$k] ?? $k;
                        $rawReason      = $detail['fallback_reason'] ?? '';
                        $ruReason       = $fallbackReasonLabels[$rawReason] ?? $rawReason;
                        $defaultApplied = (bool)($detail['default_applied'] ?? false);
                        $userDefined    = (bool)($detail['user_defined']    ?? false);
                        $rowClass       = $isFallback ? 'table-warning' : '';
                    ?>
                    <tr class="param-row <?= $rowClass ?>">
                        <td class="fw-medium"><?= htmlspecialchars($label) ?></td>
                        <td class="font-monospace"><?= htmlspecialchars($valStr) ?></td>
                        <td class="source-tag">
                            <?php if ($isSwitched): ?>
                                <span class="badge badge-master" title="<?= htmlspecialchars($detail['via'] ?? '') ?>">мастер</span>
                            <?php elseif ($isFallback): ?>
                                <span class="badge bg-warning text-dark" title="<?= htmlspecialchars($rawReason) ?>">fallback</span>
                            <?php else: ?>
                                <span class="badge bg-dark text-secondary">—</span>
                            <?php endif; ?>
                            <small class="ms-1 text-secondary"><?= htmlspecialchars($layer) ?></small>
                        </td>
                        <td>
                            <?php if ($isSwitched): ?>
                                <span class="badge badge-ok" style="font-size:0.7rem;"><i class="bi bi-check-circle me-1"></i>мигрирован</span>
                                <?php if ($userDefined): ?>
                                    <br><span class="badge bg-success" style="font-size:0.65rem;margin-top:2px;"><i class="bi bi-person-check me-1"></i>задано пользователем</span>
                                <?php elseif ($defaultApplied): ?>
                                    <br><span class="badge bg-info text-dark" style="font-size:0.65rem;margin-top:2px;" title="Используется значение по умолчанию (не задано пользователем)"><i class="bi bi-info-circle me-1"></i>по умолчанию</span>
                                    <br><small class="text-info" style="font-size:0.63rem;">Используется значение по умолчанию (не задано пользователем)</small>
                                <?php endif; ?>
                            <?php elseif ($isFallback): ?>
                                <span class="badge bg-warning text-dark" style="font-size:0.7rem;" title="<?= htmlspecialchars($rawReason) ?>">
                                    <i class="bi bi-arrow-return-right me-1"></i>legacy
                                </span>
                                <?php if ($defaultApplied): ?>
                                    <br><span class="badge bg-info text-dark" style="font-size:0.65rem;margin-top:2px;" title="Используется значение по умолчанию (не задано пользователем)"><i class="bi bi-info-circle me-1"></i>по умолчанию</span>
                                    <br><small class="text-info" style="font-size:0.63rem;">Используется значение по умолчанию (не задано пользователем)</small>
                                <?php elseif ($ruReason): ?>
                                    <br><small class="text-warning" style="font-size:0.65rem;"><?= htmlspecialchars($ruReason) ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-secondary" style="font-size:0.7rem;">ожидание</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php $defaultAppliedCount = (int)($migrationStatus['default_applied_count'] ?? 0); ?>
                <div class="px-3 py-2" style="font-size:0.78rem;color:#94a3b8;border-top:1px solid var(--border-color);">
                    Мигрировано: <strong class="text-light"><?= $migCount ?></strong> / <?= $totalFirst ?> ·
                    Fallback: <strong class="text-warning"><?= $fallback ?></strong><?php if ($defaultAppliedCount > 0): ?> ·
                    По умолчанию: <strong class="text-info"><?= $defaultAppliedCount ?></strong><?php endif; ?> ·
                    Записано: <?= htmlspecialchars($migrationStatus['recorded_at'] ?? '—') ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Эффективный предпросмотр из мастер / черновика -->
    <div class="col-12 col-xl-6">
        <div class="card">
            <div class="card-header fw-semibold">
                <i class="bi bi-eye me-2 text-info"></i>Операционный конфиг CP — эффективный предпросмотр
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th style="width:45%">Параметр</th>
                            <th>Значение</th>
                            <th>Слой</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($cpFirstWaveKeys as $k):
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

    <!-- Политика live-маршрутизации (из конфига бота) — только чтение -->
    <?php
    $passportRoutingKeys = [
        'live_routing_policy'           => 'Политика маршрутизации (live)',
        'yellow_live_max_positions'     => 'Yellow — макс. позиций',
        'yellow_live_max_leverage'      => 'Yellow — макс. плечо',
        'yellow_live_budget_multiplier' => 'Yellow — множитель бюджета',
        'no_passport_green_threshold'   => 'Без паспорта — порог Green',
        'no_passport_yellow_threshold'  => 'Без паспорта — порог Yellow',
        'no_passport_red_threshold'     => 'Без паспорта — порог Red',
    ];
    $botExec = ($preview['modules'] ?? [])['trading_bot']['execution'] ?? [];
    $hasRoutingData = false;
    foreach ($passportRoutingKeys as $k => $lbl) {
        if (array_key_exists($k, $botExec)) { $hasRoutingData = true; break; }
    }
    if ($hasRoutingData):
    ?>
    <div class="col-12 col-xl-7">
        <div class="card">
            <div class="card-header fw-semibold">
                <i class="bi bi-signpost-split me-2 text-success"></i>Политика live-маршрутизации (из Trading Bot — только чтение)
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Параметр</th><th>Значение</th></tr></thead>
                    <tbody>
                    <?php foreach ($passportRoutingKeys as $k => $lbl):
                        if (!array_key_exists($k, $botExec)) continue;
                        $v = $botExec[$k];
                        $vStr = is_bool($v) ? ($v ? 'true' : 'false') : (string)$v;
                    ?>
                    <tr class="param-row">
                        <td class="fw-medium"><?= htmlspecialchars($lbl) ?></td>
                        <td class="font-monospace"><?= htmlspecialchars($vStr) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="px-3 py-2" style="font-size:0.78rem;color:#94a3b8;border-top:1px solid var(--border-color);">
                    Passport gate пороги управляются Trading Bot. Миграция в Coin Passport config — следующая волна.
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Архитектурная справка -->
    <div class="col-12">
        <div class="card">
            <div class="card-header fw-semibold">
                <i class="bi bi-info-circle me-2 text-secondary"></i>Архитектура конфигурации Coin Passport (после волны 1)
            </div>
            <div class="card-body small text-secondary">
                <p>Coin Passport читает операционный конфиг из
                <code>modules/system/coin_passport/config/config.php</code>.
                После волны 1 параметры
                <code>cp_enabled</code>, <code>cp_rebuild_all_enabled</code>,
                <code>cp_rebuild_recent_enabled</code> и <code>cp_cycle_profiles_enabled</code>
                читаются из <code>config_operational_master.json</code> в приоритете над локальным
                конфигом. При отсутствии параметра в мастер-конфиге — явный fallback на
                <code>coin_passport/config/config.php</code> с фиксацией в
                <code>coin_passport/storage/runtime/config_source_status.json</code>.</p>

                <p class="mb-0"><strong class="text-light">Что остаётся в legacy / внутреннем слое:</strong>
                Пороговые значения passport gate (<code>LIVE_GATE_*</code> константы),
                окна достаточности данных (<code>WINDOW_*</code>), внутренние константы
                <code>passport_engine.php</code> — остаются неизменными до следующей волны.</p>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
