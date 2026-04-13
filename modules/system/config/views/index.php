<?php
declare(strict_types=1);

/**
 * Быстрое управление — ключевые операционные параметры.
 * Editable — сохраняется в config_operational_master.json.
 * Immutable/internal параметры остаются в разделе "Расширенный".
 */

$params = $data['params'] ?? [];
$master = $master ?? null;
$masterParams = $master['params'] ?? [];

// Helper: get effective display value — master overrides draft
$masterVal = static function (string $key, $draftVal) use ($masterParams) {
    if (isset($masterParams[$key])) {
        return $masterParams[$key]['value'];
    }
    return $draftVal;
};

// Human-readable labels (Russian)
$labels = [
    'live_trading_enabled'          => 'Живая торговля включена',
    'live_max_positions'            => 'Макс. позиций (лайв)',
    'live_signal_selection_mode'    => 'Режим выбора сигнала',
    'live_one_trade_per_symbol'     => 'Одна сделка на монету',
    'live_entry_policy'             => 'Политика входа',
    'live_reverse_side_enabled'     => 'Разворот разрешён',
    'leverage_mode'                 => 'Режим плеча',
    'manual_leverage'               => 'Плечо (ручное)',
    'max_leverage'                  => 'Макс. плечо',
    'max_budget_per_coin'           => 'Бюджет на монету (USDT)',
    'stop_control_mode'             => 'Режим стопа',
    'stop_loss_from_entry_roi'      => 'Стоп от входа (ROI)',
    'trailing_enabled'              => 'Трейлинг включён',
    'trailing_mode'                 => 'Режим трейлинга',
    'trailing_activation_roi'       => 'Активация трейлинга (ROI)',
    'trailing_activation_floor_roi' => 'Нижняя граница трейлинга (ROI)',
    'break_even_enabled'            => 'Безубыток включён',
    'break_even_activation_roi'     => 'Активация безубытка (ROI)',
    'execution_profile'             => 'Профиль исполнения',
    'patterns_enabled'              => 'Активные паттерны',
    'patterns_mode'                 => 'Режим паттернов',
    'bot_enabled'                   => 'Бот включён',
    'bot_mode'                      => 'Режим бота',
    'max_intents_per_run'           => 'Макс. намерений за цикл',
    'max_concurrent_positions'      => 'Макс. одновременных позиций',
    'bot_brain_controlled'          => 'Управление от Brain',
    'pm_trailing_owner'             => 'Трейлинг управляет',
    'pm_enabled'                    => 'PM включён',
    'cp_enabled'                    => 'Coin Passport включён',
    'cp_rebuild_all_enabled'        => 'CP: полный перестрой',
    'cp_rebuild_recent_enabled'     => 'CP: перестрой активных',
    'cp_cycle_profiles_enabled'     => 'CP: цикловые профили',
];

$groups = [
    'Живая торговля' => [
        'icon'  => 'bi-activity',
        'color' => 'text-success',
        'keys'  => ['live_trading_enabled', 'live_max_positions', 'live_signal_selection_mode', 'live_entry_policy', 'live_one_trade_per_symbol', 'live_reverse_side_enabled'],
    ],
    'Плечо' => [
        'icon'  => 'bi-speedometer2',
        'color' => 'text-warning',
        'keys'  => ['leverage_mode', 'manual_leverage', 'max_leverage'],
    ],
    'Бюджет' => [
        'icon'  => 'bi-wallet2',
        'color' => 'text-info',
        'keys'  => ['max_budget_per_coin'],
    ],
    'Управление выходом' => [
        'icon'  => 'bi-sign-stop',
        'color' => 'text-danger',
        'keys'  => ['stop_control_mode', 'stop_loss_from_entry_roi', 'trailing_enabled', 'trailing_mode', 'trailing_activation_roi', 'trailing_activation_floor_roi', 'break_even_enabled', 'break_even_activation_roi'],
    ],
    'Профиль и паттерны' => [
        'icon'  => 'bi-diagram-3',
        'color' => 'text-primary',
        'keys'  => ['execution_profile', 'patterns_enabled', 'patterns_mode'],
    ],
    'Управление ботом' => [
        'icon'  => 'bi-robot',
        'color' => 'text-warning',
        'keys'  => ['bot_enabled', 'bot_mode', 'max_intents_per_run', 'max_concurrent_positions', 'bot_brain_controlled', 'pm_trailing_owner'],
    ],
    'Менеджер Прибыли' => [
        'icon'  => 'bi-cash-coin',
        'color' => 'text-success',
        'keys'  => ['pm_enabled'],
    ],
    'Coin Passport' => [
        'icon'  => 'bi-coin',
        'color' => 'text-warning',
        'keys'  => ['cp_enabled', 'cp_rebuild_all_enabled', 'cp_rebuild_recent_enabled', 'cp_cycle_profiles_enabled'],
    ],
];

// Operational params allowed in the save form (immutable params excluded)
$editableKeys = [
    'live_trading_enabled', 'live_max_positions', 'live_signal_selection_mode',
    'live_entry_policy', 'live_one_trade_per_symbol', 'live_reverse_side_enabled',
    'leverage_mode', 'manual_leverage', 'max_leverage', 'max_budget_per_coin',
    'stop_control_mode', 'stop_loss_from_entry_roi',
    'trailing_enabled', 'trailing_mode', 'trailing_activation_roi', 'trailing_activation_floor_roi',
    'break_even_enabled', 'break_even_activation_roi',
    'execution_profile', 'patterns_mode',
    'bot_enabled', 'bot_mode', 'max_intents_per_run', 'max_concurrent_positions',
    'bot_brain_controlled', 'pm_trailing_owner', 'pm_enabled',
    'cp_enabled', 'cp_rebuild_all_enabled', 'cp_rebuild_recent_enabled', 'cp_cycle_profiles_enabled',
];

// Conflict detection
$allGroupKeys = [];
foreach ($groups as $g) { $allGroupKeys = array_merge($allGroupKeys, $g['keys']); }
$allGroupKeys = array_unique($allGroupKeys);
$conflicts = array_filter($params, static fn($e, $k) => in_array($k, $allGroupKeys, true) && isset($e['all_values']) && count($e['all_values']) > 1, ARRAY_FILTER_USE_BOTH);

$renderVal = static function($val): string {
    if ($val === null) { return '—'; }
    if (is_bool($val)) { return $val ? 'true' : 'false'; }
    if (is_array($val)) { return implode(', ', array_map('strval', $val)); }
    $s = (string)$val;
    return $s === '' ? '—' : $s;
};

$knownPatterns = [
    'double_bottom_contextual_v2' => 'Double Bottom Ctx V2',
    'double_bottom_contextual_v3' => 'Double Bottom Ctx V3',
    'double_top_contextual_v2'    => 'Double Top Ctx V2',
    'double_top_contextual_v3'    => 'Double Top Ctx V3',
    'double_bottom'               => 'Double Bottom (классик)',
    'double_top'                  => 'Double Top (классик)',
    'double_bottom_confirm_v2'    => 'Double Bottom Confirm V2',
    'double_top_confirm_v2'       => 'Double Top Confirm V2',
    'pullback_trend_continue'     => 'Pullback Trend Continue',
];

ob_start();
?>
<div class="row g-3">

    <!-- Save form (covers all editable groups) -->
    <div class="col-12">
        <form method="post" action="<?= htmlspecialchars($baseUrl) ?>/api/save" id="masterSaveForm">
            <input type="hidden" name="_redirect" value="<?= htmlspecialchars($baseUrl) ?>">
            <input type="hidden" name="_html_form" value="1">

            <div class="row g-3">

            <?php foreach ($groups as $groupName => $groupDef): ?>
            <?php
            $groupParams = [];
            foreach ($groupDef['keys'] as $k) {
                if (isset($params[$k])) {
                    $groupParams[$k] = $params[$k];
                }
            }
            if (empty($groupParams)) continue;
            ?>
            <div class="col-12 col-xl-6">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center">
                        <i class="bi <?= htmlspecialchars($groupDef['icon']) ?> me-2 <?= htmlspecialchars($groupDef['color']) ?>"></i>
                        <strong><?= htmlspecialchars($groupName) ?></strong>
                    </div>
                    <div class="card-body">
                    <?php foreach ($groupParams as $key => $entry): ?>
                        <?php
                        $editable    = in_array($key, $editableKeys, true);
                        $draftVal    = $entry['value'];
                        $effectiveVal = $masterVal($key, $draftVal);
                        $hasMaster   = isset($masterParams[$key]);
                        $conflict    = isset($entry['all_values']) && count($entry['all_values']) > 1;
                        $label       = $labels[$key] ?? ($entry['label'] ?? $key);
                        $type        = $entry['type'] ?? 'operational';
                        ?>
                        <div class="mb-3">
                            <label class="form-label small fw-bold mb-1">
                                <?= htmlspecialchars($label) ?>
                                <?php if ($hasMaster): ?>
                                    <span class="master-badge ms-1">мастер</span>
                                <?php endif; ?>
                                <?php if ($conflict): ?>
                                    <span class="badge badge-conflict conflict-badge ms-1">конфликт</span>
                                <?php endif; ?>
                                <?php if (!$editable): ?>
                                    <span class="badge badge-immutable ms-1 small">только чтение</span>
                                <?php endif; ?>
                            </label>
                            <?php if (!$editable): ?>
                                <!-- Immutable / not editable — just show the value -->
                                <div class="font-monospace text-secondary small"><?= htmlspecialchars($renderVal($effectiveVal)) ?></div>
                            <?php elseif (is_bool($draftVal) || $key === 'live_trading_enabled' || $key === 'bot_enabled' || $key === 'trailing_enabled' || $key === 'break_even_enabled' || $key === 'live_one_trade_per_symbol' || $key === 'live_reverse_side_enabled' || $key === 'bot_brain_controlled' || $key === 'pm_enabled' || $key === 'cp_enabled' || $key === 'cp_rebuild_all_enabled' || $key === 'cp_rebuild_recent_enabled' || $key === 'cp_cycle_profiles_enabled'): ?>
                                <!-- Boolean toggle -->
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="<?= htmlspecialchars($key) ?>"
                                           id="f_<?= htmlspecialchars($key) ?>"
                                           value="1"
                                           <?= (bool)$effectiveVal ? 'checked' : '' ?>>
                                    <label class="form-check-label small text-muted" for="f_<?= htmlspecialchars($key) ?>">
                                        <?= (bool)$effectiveVal ? 'включено' : 'выключено' ?>
                                    </label>
                                </div>
                            <?php elseif ($key === 'patterns_enabled'): ?>
                                <!-- Pattern multi-checkbox -->
                                <div class="d-flex flex-wrap gap-2">
                                <?php
                                $enabledSet = is_array($effectiveVal) ? array_flip($effectiveVal) : [];
                                foreach ($knownPatterns as $pid => $plabel):
                                ?>
                                    <div class="form-check me-2">
                                        <input class="form-check-input" type="checkbox"
                                               name="patterns_enabled[]"
                                               id="pat_<?= htmlspecialchars($pid) ?>"
                                               value="<?= htmlspecialchars($pid) ?>"
                                               <?= isset($enabledSet[$pid]) ? 'checked' : '' ?>>
                                        <label class="form-check-label small" for="pat_<?= htmlspecialchars($pid) ?>"><?= htmlspecialchars($plabel) ?></label>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                            <?php elseif (is_int($draftVal) || is_float($draftVal) || $key === 'live_max_positions' || $key === 'manual_leverage' || $key === 'max_leverage' || $key === 'max_budget_per_coin' || $key === 'max_intents_per_run' || $key === 'max_concurrent_positions' || $key === 'stop_loss_from_entry_roi' || $key === 'trailing_activation_roi' || $key === 'trailing_activation_floor_roi' || $key === 'break_even_activation_roi'): ?>
                                <!-- Numeric -->
                                <input type="number"
                                       class="form-control form-control-sm"
                                       name="<?= htmlspecialchars($key) ?>"
                                       value="<?= htmlspecialchars((string)$effectiveVal) ?>"
                                       step="<?= (is_float($draftVal) || in_array($key, ['stop_loss_from_entry_roi','trailing_activation_roi','trailing_activation_floor_roi','break_even_activation_roi','max_budget_per_coin'], true)) ? '0.001' : '1' ?>">
                            <?php else: ?>
                                <!-- Text / select -->
                                <?php
                                $selectOpts = match ($key) {
                                    'live_signal_selection_mode' => ['all' => 'all — все сигналы', 'best' => 'best — лучший сигнал'],
                                    'live_entry_policy'          => ['enter_now' => 'enter_now — входить сразу', 'entry_queued' => 'entry_queued — в очередь'],
                                    'leverage_mode'              => ['manual' => 'manual — ручное', 'auto' => 'auto — автоматическое'],
                                    'stop_control_mode'          => ['entry_roi' => 'entry_roi', 'price_distance' => 'price_distance', 'none' => 'none'],
                                    'trailing_mode'              => ['price_distance_floor' => 'price_distance_floor', 'price_distance' => 'price_distance'],
                                    'execution_profile'          => ['sniper_lite' => 'sniper_lite', 'balanced' => 'balanced', 'passive' => 'passive', 'custom' => 'custom'],
                                    'patterns_mode'              => ['any' => 'any — любой', 'all' => 'all — все'],
                                    'bot_mode'                   => ['live' => 'live — лайв', 'demo' => 'demo — демо', 'paper' => 'paper — бумажная'],
                                    'pm_trailing_owner'          => ['profit_manager' => 'profit_manager', 'trading_bot' => 'trading_bot'],
                                    default                      => [],
                                };
                                ?>
                                <?php if (!empty($selectOpts)): ?>
                                    <select class="form-select form-select-sm" name="<?= htmlspecialchars($key) ?>">
                                        <?php foreach ($selectOpts as $optVal => $optLabel): ?>
                                            <option value="<?= htmlspecialchars($optVal) ?>" <?= (string)$effectiveVal === $optVal ? 'selected' : '' ?>><?= htmlspecialchars($optLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input type="text" class="form-control form-control-sm"
                                           name="<?= htmlspecialchars($key) ?>"
                                           value="<?= htmlspecialchars((string)$effectiveVal) ?>">
                                <?php endif; ?>
                            <?php endif; ?>
                            <div class="source-tag mt-1">
                                <?= htmlspecialchars(basename($entry['source_file'] ?? '—')) ?>
                                <?php if ($hasMaster): ?>
                                    → <span class="text-violet-400" style="color:#a78bfa">мастер</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            </div><!-- /row -->

            <!-- Conflict summary -->
            <?php if (!empty($conflicts)): ?>
            <div class="mt-3 card border-danger">
                <div class="card-header text-danger">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    Обнаружены конфликты в параметрах — подробности в разделе «Расширенный»
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Параметр</th><th>Источник</th><th>Значение</th></tr></thead>
                        <tbody>
                        <?php foreach ($conflicts as $key => $entry): ?>
                            <?php foreach ($entry['all_values'] as $srcName => $srcVal): ?>
                            <tr>
                                <td><?= htmlspecialchars($labels[$key] ?? ($entry['label'] ?? $key)) ?></td>
                                <td class="source-tag"><?= htmlspecialchars($srcName) ?></td>
                                <td class="font-monospace small"><?= htmlspecialchars(is_array($srcVal) ? json_encode($srcVal) : (string)$srcVal) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <?php if (empty($params)): ?>
            <div class="mt-3 card">
                <div class="card-body text-muted">
                    Данные отсутствуют. Нажмите <strong>Перечитать</strong> в шапке для запуска аудита.
                </div>
            </div>
            <?php endif; ?>

            <!-- Save actions -->
            <?php if (!empty($params)): ?>
            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-floppy me-1"></i>Сохранить
                </button>
                <button type="button" class="btn btn-outline-secondary" id="btnSaveReextract">
                    <i class="bi bi-arrow-clockwise me-1"></i>Сохранить и перечитать
                </button>
                <a href="<?= htmlspecialchars($baseUrl) ?>" class="btn btn-outline-secondary ms-auto">
                    <i class="bi bi-x me-1"></i>Сбросить изменения
                </a>
            </div>
            <?php endif; ?>

        </form>
    </div>

</div>
<script>
document.getElementById('btnSaveReextract')?.addEventListener('click', function() {
    const form = document.getElementById('masterSaveForm');
    if (!form) return;
    const orig = form.action;
    form.action = orig.replace('/api/save', '/api/save_and_reextract');
    form.submit();
    form.action = orig;
});
// Live toggle label update
document.querySelectorAll('.form-check-input[type=checkbox].form-check-input').forEach(function(el) {
    if (!el.closest('.form-switch')) return;
    const label = el.closest('.form-switch')?.querySelector('.form-check-label');
    if (!label) return;
    el.addEventListener('change', function() {
        label.textContent = this.checked ? 'включено' : 'выключено';
    });
});
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
