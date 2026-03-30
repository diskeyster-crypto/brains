<?php
/**
 * Trading Bot — Настройки
 *
 * Редактирование runtime-настроек (config/bot.json).
 * Базовые значения — в config/config.php. UI НЕ перезаписывает config.php.
 */

use Core\System\System;
use Core\System\SystemPaths;
use Core\KeyCenter\KeyCenter;

$tab = 'settings';

// Effective (merged) config
$cfg = $this->config ?? [];

// --- Module
$mode = (string)($cfg['module']['mode'] ?? 'dry');
$enabled = (bool)($cfg['module']['enabled'] ?? false);
$accountId = (string)($cfg['module']['account_id'] ?? 'trading_bot');
$maxPositions = (int)($cfg['module']['max_concurrent_positions'] ?? 10);
$reconcileBeforeAction = (bool)($cfg['module']['reconcile_before_action'] ?? true);

// --- Exchange
$exchangeCategory = (string)($cfg['exchange']['category'] ?? 'linear');
$exchangeSettleCoin = (string)($cfg['exchange']['settle_coin'] ?? 'USDT');
$exchangePositionIdx = (int)($cfg['exchange']['position_idx'] ?? 0);
$exchangeAccountType = (string)($cfg['exchange']['account_type'] ?? 'UNIFIED');
$exchangeTpslMode = (string)($cfg['exchange']['tpsl_mode'] ?? 'Full');
$exchangeSlTriggerBy = (string)($cfg['exchange']['sl_trigger_by'] ?? 'LastPrice');

// --- Sources
$signalsKey = (string)($cfg['sources']['signals_key'] ?? '');
$signalsFile = (string)($cfg['sources']['signals_file'] ?? '');
$riskActiveKey = (string)($cfg['sources']['risk_active_key'] ?? '');
$riskActiveFile = (string)($cfg['sources']['risk_active_file'] ?? '');
$commandsKey = (string)($cfg['sources']['commands_key'] ?? '');
$commandsFile = (string)($cfg['sources']['commands_file'] ?? '');

// --- Execution
$maxScanIntents = (int)($cfg['execution']['max_scan_intents_per_run'] ?? 2000);
$maxExecutePerRun = (int)($cfg['execution']['max_intents_per_run'] ?? 1);
$maxDeferredPerRun = (int)($cfg['execution']['max_deferred_intents_per_run'] ?? 10);
$defaultEntryTimeout = (int)($cfg['execution']['default_entry_timeout_minutes'] ?? 10);
$defaultLateThresholdPct = (float)($cfg['execution']['default_late_threshold_pct'] ?? 1.5);
$retraceSlackPct = (float)($cfg['execution']['retrace_slack_pct'] ?? 0.05);
$reverseSideEnabled = (bool)($cfg['execution']['reverse_side_enabled'] ?? false);
$requirePriceCheckLive = (bool)($cfg['execution']['require_price_check_live'] ?? true);
$exchangePosCacheTtl = (int)($cfg['execution']['exchange_positions_cache_ttl_sec'] ?? 2);

// --- Per-symbol overrides
$symbolOverrides = is_array($cfg['symbol_overrides'] ?? null) ? (array)$cfg['symbol_overrides'] : [];
if (!empty($symbolOverrides)) {
    ksort($symbolOverrides);
}

// --- Safety
$safetyStopErrors = (int)($cfg['execution']['safety_stop_on_errors'] ?? 5);

// --- Commands
$commandsEnabled = (bool)($cfg['execution']['commands_enabled'] ?? true);
$commandsApplyBeforeIntents = (bool)($cfg['execution']['commands_apply_before_intents'] ?? true);
$commandsAllowClose = (bool)($cfg['execution']['commands_allow_close'] ?? true);
$commandsMaxPerRun = (int)($cfg['execution']['commands_max_per_run'] ?? 100);

// --- Dumb trailing
$dumbTrailingEnabled = (bool)($cfg['execution']['dumb_trailing_enabled'] ?? false);
$enableTrailingOnOpen = (bool)($cfg['execution']['enable_trailing_on_open'] ?? true);
$dumbTrailingEpsPct = (float)($cfg['execution']['dumb_trailing_activation_epsilon_pct'] ?? 0.2);

// --- Profit Add-On
$profitAddonEnabled = (bool)($cfg['execution']['profit_addon_enabled'] ?? false);
$profitAddonBudgetPct = (float)($cfg['execution']['profit_addon_budget_pct'] ?? 0.0);

// V3: Detect Brain-controlled mode from last_run.json for deprecation notices
$_brainModeActive = false;
if (isset($this->storageDir)) {
    $_lastRunPath = $this->storageDir . '/last_run.json';
    if (is_file($_lastRunPath)) {
        $_lastRunData = @json_decode((string)@file_get_contents($_lastRunPath), true);
        if (is_array($_lastRunData)) {
            $_brainModeActive = (bool)($_lastRunData['trailing_controlled_by_brain'] ?? false);
        }
    }
}

// --- Balance
$balanceCoin = (string)($cfg['execution']['balance_coin'] ?? 'USDT');
$balanceBufferPct = (int)($cfg['execution']['balance_required_buffer_pct'] ?? 10);
$balanceRejectBelow = (int)($cfg['execution']['balance_reject_below_usdt'] ?? 2);
$balanceCacheTtl = (int)($cfg['execution']['balance_cache_ttl_sec'] ?? 10);
$balanceStrictStable = (bool)($cfg['execution']['balance_strict_stable_coin_only'] ?? true);

// --- Validation
$signalSchemaVersion = (string)($cfg['validation']['signal_schema_version'] ?? ($cfg['validation']['schema_version'] ?? 'clean_signal_v1'));
$allowedOrderTypes = $cfg['validation']['allowed_order_types'] ?? ['market'];
$requiredRiskFields = $cfg['validation']['required_risk_fields'] ?? [];

// --- UI
$maxPreviewItems = (int)($cfg['ui']['max_preview_items'] ?? 10);

// Available KeyCenter Bybit accounts
$availableAccounts = [];
try {
    $keyCenter = KeyCenter::instance();
    if (method_exists($keyCenter, 'listAccounts')) {
        $availableAccounts = $keyCenter->listAccounts('bybit') ?? [];
    } elseif (method_exists($keyCenter, 'getAccounts')) {
        $availableAccounts = $keyCenter->getAccounts('bybit') ?? [];
    }
} catch (\Throwable $e) {
    $availableAccounts = [];
}

if (!empty($accountId) && !in_array($accountId, $availableAccounts, true)) {
    $availableAccounts[] = $accountId;
}

// Include tabs via SystemPaths
$moduleBase = SystemPaths::instance()->get('system.trading_bot');
require $moduleBase . '/views/_tabs.php';

$helpIcon = '<i class="bi bi-question-circle ms-1 text-muted" title="%s"></i>';
?>

<div class="row">
    <div class="col-md-7">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-gear me-2"></i>Настройки Trading Bot
            </div>
            <div class="card-body">
                <form id="settingsForm">

                    <h6 class="mb-3">Основное</h6>

                    <div class="mb-3">
                        <label class="form-label">
                            Режим
                            <?= sprintf($helpIcon, htmlspecialchars('dry — без реальных ордеров. live — реальные ордера на биржу.')) ?>
                        </label>
                        <select class="form-select" name="mode" id="mode">
                            <option value="dry" <?= $mode === 'dry' ? 'selected' : '' ?>>dry — тестовый (без ордеров)</option>
                            <option value="live" <?= $mode === 'live' ? 'selected' : '' ?>>live — боевой (реальные ордера)</option>
                        </select>
                        <div class="form-text text-warning">
                            Внимание: в режиме <b>live</b> бот отправляет реальные ордера на биржу.
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="enabled" name="enabled" <?= $enabled ? 'checked' : '' ?>>
                            <label class="form-check-label" for="enabled">
                                Бот включён
                                <?= sprintf($helpIcon, htmlspecialchars('Если выключить — intents будут загружаться/валидироваться, но исполняться не будут.')) ?>
                            </label>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            Account ID (KeyCenter)
                            <?= sprintf($helpIcon, htmlspecialchars('ID аккаунта в KeyCenter (Admin → KeyCenter). Используется для подписи запросов к Bybit.')) ?>
                        </label>
                        <select class="form-select" name="account_id" id="account_id">
                            <?php foreach ($availableAccounts as $acc): ?>
                                <option value="<?= htmlspecialchars($acc) ?>" <?= $accountId === $acc ? 'selected' : '' ?>><?= htmlspecialchars($acc) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            Если ключи были зашифрованы другим storage/.encryption_key — будет <code>decryption failed</code>.
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">
                                Макс. одновременно позиций
                                <?= sprintf($helpIcon, htmlspecialchars('0 = без лимита. Это «мягкий» лимит на количество активных сделок, которые бот будет вести.')) ?>
                            </label>
                            <input type="number" class="form-control" id="max_positions" name="max_positions" value="<?= (int)$maxPositions ?>" min="0" max="1000">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">
                                Stop при ошибках (Safety)
                                <?= sprintf($helpIcon, htmlspecialchars('Если ошибок за один run станет >= этому значению — бот прервёт выполнение (safety_stop_on_errors). 0 = выключить.')) ?>
                            </label>
                            <input type="number" class="form-control" id="safety_stop_errors" name="safety_stop_errors" value="<?= (int)$safetyStopErrors ?>" min="0" max="1000">
                        </div>
                    </div>

                    <div class="mt-3 mb-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="reconcile_before_action" name="reconcile_before_action" <?= $reconcileBeforeAction ? 'checked' : '' ?>>
                            <label class="form-check-label" for="reconcile_before_action">
                                Делать reconcile перед действиями
                                <?= sprintf($helpIcon, htmlspecialchars('Сверка позиций и ордеров с биржей перед execute_intents/update_positions. Рекомендовано держать включённым.')) ?>
                            </label>
                        
                    <div class="row g-3 mt-0">
                        <div class="col-md-8">
                            <div class="alert alert-warning py-2 px-3 mb-2" style="font-size: 0.82rem;">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                <strong>DEPRECATED — Brain-controlled:</strong>
                                Стратегические контролы ниже (reverse_side, force_side, symbol_overrides)
                                теперь управляются из Smart Brain → User Config → Live Trading Control.
                                Здесь они работают только как legacy-fallback, если Brain не отправляет live_intents.
                            </div>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="reverse_side_enabled" name="reverse_side_enabled" <?= $reverseSideEnabled ? 'checked' : '' ?>>
                                <label class="form-check-label" for="reverse_side_enabled">
                                    Инвертировать направление (LONG ↔ SHORT) <span class="badge bg-secondary">deprecated</span>
                                    <?= sprintf($helpIcon, htmlspecialchars('DEPRECATED: Теперь Brain контролирует reverse_side через live_reverse_side_enabled в User Config. Bot-local toggle работает только в legacy mode.')) ?>
                                </label>
                            </div>

                            <div class="mt-3 p-3 rounded" style="background: rgba(0,0,0,0.15); border: 1px solid var(--border-color);">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                    <div>
                                        <strong>Переопределения по символам</strong> <span class="badge bg-secondary">deprecated</span>
                                        <span class="text-muted">(legacy fallback — Brain теперь контролирует selection mode)</span>
                                        <?= sprintf($helpIcon, htmlspecialchars('Позволяет точечно включать/выключать торговлю по символу и (опционально) инвертировать сторону только для конкретных тикеров. Это лучше, чем общий "переворот" для всех.')) ?>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <input type="text" class="form-control form-control-sm" id="symbol_override_new" placeholder="BTCUSDT" style="max-width: 140px;">
                                        <button type="button" class="btn btn-sm btn-outline-light" onclick="addSymbolOverride()">Добавить</button>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0" id="symbolOverridesTable">
                                        <thead>
                                            <tr>
                                                <th style="min-width: 120px;">Символ</th>
                                                <th style="min-width: 90px;">Вкл.</th>
                                                <th style="min-width: 140px;">Инвертировать</th>
                                                <th style="min-width: 160px;">Принуд. сторона</th>
                                                <th style="width: 1%;">Удалить</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php if (!empty($symbolOverrides)): ?>
                                            <?php foreach ($symbolOverrides as $sym => $row): ?>
                                                <?php
                                                    $row = is_array($row) ? $row : [];
                                                    $en = array_key_exists('enabled', $row) ? (bool)$row['enabled'] : true;
                                                    $rev = array_key_exists('reverse_side_enabled', $row) ? (bool)$row['reverse_side_enabled'] : false;
                                                    $fs = (string)($row['force_side'] ?? '');
                                                    $fs = strtolower(trim($fs));
                                                    if (!in_array($fs, ['long', 'short'], true)) {
                                                        $fs = '';
                                                    }
                                                ?>
                                                <tr>
                                                    <td>
                                                        <input type="text" class="form-control form-control-sm sym-symbol" value="<?= htmlspecialchars((string)$sym) ?>" readonly>
                                                    </td>
                                                    <td>
                                                        <div class="form-check form-switch">
                                                            <input class="form-check-input sym-enabled" type="checkbox" <?= $en ? 'checked' : '' ?>>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="form-check form-switch">
                                                            <input class="form-check-input sym-reverse" type="checkbox" <?= $rev ? 'checked' : '' ?>>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <select class="form-select form-select-sm sym-force">
                                                            <option value="" <?= $fs === '' ? 'selected' : '' ?>>Авто (как в сигнале)</option>
                                                            <option value="long" <?= $fs === 'long' ? 'selected' : '' ?>>LONG</option>
                                                            <option value="short" <?= $fs === 'short' ? 'selected' : '' ?>>SHORT</option>
                                                        </select>
                                                    </td>
                                                    <td class="text-end">
                                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeSymbolOverride(this)">×</button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="form-text text-muted mt-2">
                                    Совет: для начала лучше <b>отключать</b> убыточные символы, чем включать общий переворот для всех.
                                    Принудительную сторону используйте только если у вас есть статистика по символу.
                                </div>
                            </div>

                        </div>
                    </div>

</div>
                    </div>

                    <hr class="my-4">

                    <h6 class="mb-3">Биржа (Bybit)</h6>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">
                                Category
                                <?= sprintf($helpIcon, htmlspecialchars('Bybit category: linear / inverse / spot / option. Для фьючерсов обычно linear.')) ?>
                            </label>
                            <select class="form-select" id="exchange_category" name="exchange_category">
                                <?php foreach (['linear','inverse','spot','option'] as $c): ?>
                                    <option value="<?= $c ?>" <?= $exchangeCategory === $c ? 'selected' : '' ?>><?= $c ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">
                                Account Type
                                <?= sprintf($helpIcon, htmlspecialchars('Тип аккаунта Bybit. Обычно UNIFIED.')) ?>
                            </label>
                            <select class="form-select" id="exchange_account_type" name="exchange_account_type">
                                <?php foreach (['UNIFIED','CONTRACT'] as $t): ?>
                                    <option value="<?= $t ?>" <?= strtoupper($exchangeAccountType) === $t ? 'selected' : '' ?>><?= $t ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">
                                Settle coin
                                <?= sprintf($helpIcon, htmlspecialchars('Монета расчёта/маржи (обычно USDT).')) ?>
                            </label>
                            <input type="text" class="form-control" id="exchange_settle_coin" name="exchange_settle_coin" value="<?= htmlspecialchars($exchangeSettleCoin) ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">
                                position_idx
                                <?= sprintf($helpIcon, htmlspecialchars('Bybit positionIdx. 0 = one-way, 1/2 = hedge. Обычно 0.')) ?>
                            </label>
                            <input type="number" class="form-control" id="exchange_position_idx" name="exchange_position_idx" value="<?= (int)$exchangePositionIdx ?>" min="0" max="2">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">
                                TPSL mode
                                <?= sprintf($helpIcon, htmlspecialchars('Full/Partial. Режим TP/SL на Bybit.')) ?>
                            </label>
                            <select class="form-select" id="exchange_tpsl_mode" name="exchange_tpsl_mode">
                                <?php foreach (['Full','Partial'] as $m): ?>
                                    <option value="<?= $m ?>" <?= $exchangeTpslMode === $m ? 'selected' : '' ?>><?= $m ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">
                                SL trigger
                                <?= sprintf($helpIcon, htmlspecialchars('По какой цене срабатывает StopLoss: Last/Index/Mark.')) ?>
                            </label>
                            <select class="form-select" id="exchange_sl_trigger_by" name="exchange_sl_trigger_by">
                                <?php foreach (['LastPrice','IndexPrice','MarkPrice'] as $m): ?>
                                    <option value="<?= $m ?>" <?= $exchangeSlTriggerBy === $m ? 'selected' : '' ?>><?= $m ?></option>
                                <?php endforeach; ?>
                            </select>
                        
                    <div class="row g-3 mt-0">
                        <div class="col-md-8">
                            

                            

                        </div>
                    </div>

</div>
                    </div>

                    <hr class="my-4">

                    <h6 class="mb-3">Источники (Brain → Bot)</h6>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">
                                Signals storage key
                                <?= sprintf($helpIcon, htmlspecialchars('SystemPaths ключ, где лежит signals.json (обычно system.brain.storage).')) ?>
                            </label>
                            <input type="text" class="form-control" id="signals_key" name="signals_key" value="<?= htmlspecialchars($signalsKey) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">
                                Signals filename
                                <?= sprintf($helpIcon, htmlspecialchars('Имя файла сигналов (обычно signals.json).')) ?>
                            </label>
                            <input type="text" class="form-control" id="signals_file" name="signals_file" value="<?= htmlspecialchars($signalsFile) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">
                                RiskActive storage key
                                <?= sprintf($helpIcon, htmlspecialchars('SystemPaths ключ для risk_active.json (Brain runtime).')) ?>
                            </label>
                            <input type="text" class="form-control" id="risk_active_key" name="risk_active_key" value="<?= htmlspecialchars($riskActiveKey) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">
                                RiskActive filename
                                <?= sprintf($helpIcon, htmlspecialchars('Имя файла активного риск-профиля (обычно risk_active.json).')) ?>
                            </label>
                            <input type="text" class="form-control" id="risk_active_file" name="risk_active_file" value="<?= htmlspecialchars($riskActiveFile) ?>">
                        
                    <div class="row g-3 mt-0">
                        <div class="col-md-8">
                            

                            

                        </div>
                    </div>

</div>
                    </div>

                    <hr class="my-4">

                    <h6 class="mb-3">Валидация</h6>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">
                                schema_version (signals)
                                <?= sprintf($helpIcon, htmlspecialchars('Ожидаемый schema_version у Brain signals.')) ?>
                            </label>
                            <input type="text" class="form-control" id="signal_schema_version" name="signal_schema_version" value="<?= htmlspecialchars($signalSchemaVersion) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">
                                Allowed order types
                                <?= sprintf($helpIcon, htmlspecialchars('Какие order_type бот допускает из risk-block. Обычно только market.')) ?>
                            </label>
                            <select class="form-select" id="allowed_order_types" name="allowed_order_types" disabled>
                                <?php foreach ((array)$allowedOrderTypes as $t): ?>
                                    <option value="<?= htmlspecialchars((string)$t) ?>" selected><?= htmlspecialchars((string)$t) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Список типов ордеров сейчас фиксирован (read-only).</div>
                        
                    <div class="row g-3 mt-0">
                        <div class="col-md-8">
                            

                            

                        </div>
                    </div>

</div>
                    </div>

                    <hr class="my-4">

                    <h6 class="mb-3">Исполнение</h6>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">
                                max_scan_intents_per_run
                                <?= sprintf($helpIcon, htmlspecialchars('Сколько intents максимум читать/сканировать за один запуск.')) ?>
                            </label>
                            <input type="number" class="form-control" id="max_scan_intents_per_run" name="max_scan_intents_per_run" value="<?= (int)$maxScanIntents ?>" min="1" max="10000">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">
                                max_execute_per_run
                                <?= sprintf($helpIcon, htmlspecialchars('Сколько intents максимум открыть/исполнить за один запуск.')) ?>
                            </label>
                            <input type="number" class="form-control" id="max_intents_per_run" name="max_intents_per_run" value="<?= (int)$maxExecutePerRun ?>" min="0" max="1000">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">
                                max_deferred_per_run
                                <?= sprintf($helpIcon, htmlspecialchars('Сколько deferred intents максимум обработать за один запуск (проверка условий).')) ?>
                            </label>
                            <input type="number" class="form-control" id="max_deferred_intents_per_run" name="max_deferred_intents_per_run" value="<?= (int)$maxDeferredPerRun ?>" min="0" max="10000">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">
                                entry_timeout_min
                                <?= sprintf($helpIcon, htmlspecialchars('Сколько минут intent живёт для entry_action=wait_retrace (если в intent нет entry_timeout_minutes).')) ?>
                            </label>
                            <input type="number" class="form-control" id="default_entry_timeout_minutes" name="default_entry_timeout_minutes" value="<?= (int)$defaultEntryTimeout ?>" min="1" max="1440">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">
                                late_threshold_pct
                                <?= sprintf($helpIcon, htmlspecialchars('Если цена ушла от entry_price больше этого процента — intent помечается как «late».')) ?>
                            </label>
                            <input type="number" step="0.01" class="form-control" id="default_late_threshold_pct" name="default_late_threshold_pct" value="<?= htmlspecialchars((string)$defaultLateThresholdPct) ?>" min="0" max="100">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">
                                retrace_slack_pct
                                <?= sprintf($helpIcon, htmlspecialchars('Допуск к цене для wait_retrace. Пример 0.05% значит: current_price <= entry_price*(1+0.05/100).')) ?>
                            </label>
                            <input type="number" step="0.001" class="form-control" id="retrace_slack_pct" name="retrace_slack_pct" value="<?= htmlspecialchars((string)$retraceSlackPct) ?>" min="0" max="10">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">
                                positions_cache_ttl_sec
                                <?= sprintf($helpIcon, htmlspecialchars('Кеш позиций/ордеров с биржи, чтобы не спамить API.')) ?>
                            </label>
                            <input type="number" class="form-control" id="exchange_positions_cache_ttl_sec" name="exchange_positions_cache_ttl_sec" value="<?= (int)$exchangePosCacheTtl ?>" min="0" max="600">
                        </div>

                        <div class="col-md-8">
                            <div class="form-check form-switch mt-4">
                                <input class="form-check-input" type="checkbox" id="require_price_check_live" name="require_price_check_live" <?= $requirePriceCheckLive ? 'checked' : '' ?>>
                                <label class="form-check-label" for="require_price_check_live">
                                    Проверять цену перед ордером (live)
                                    <?= sprintf($helpIcon, htmlspecialchars('Если true — бот не откроет сделку, если текущая цена недоступна/невалидна.')) ?>
                                </label>
                            </div>

                            

                        
                    <div class="row g-3 mt-0">
                        <div class="col-md-8">
                            

                            

                        </div>
                    </div>

</div>
                    </div>

                    <hr class="my-4">

                    <h6 class="mb-3">Команды (commands.json)</h6>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="commands_enabled" name="commands_enabled" <?= $commandsEnabled ? 'checked' : '' ?>>
                                <label class="form-check-label" for="commands_enabled">
                                    Обрабатывать команды
                                    <?= sprintf($helpIcon, htmlspecialchars('Включает apply_commands (закрыть позицию, stop и т.п.).')) ?>
                                </label>
                            </div>

                            

                        </div>

                        <div class="col-md-6">
                            <label class="form-label">
                                commands_max_per_run
                                <?= sprintf($helpIcon, htmlspecialchars('Сколько команд максимум применять за один запуск.')) ?>
                            </label>
                            <input type="number" class="form-control" id="commands_max_per_run" name="commands_max_per_run" value="<?= (int)$commandsMaxPerRun ?>" min="0" max="1000">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">
                                Commands storage key
                                <?= sprintf($helpIcon, htmlspecialchars('SystemPaths ключ, где лежит commands.json.')) ?>
                            </label>
                            <input type="text" class="form-control" id="commands_key" name="commands_key" value="<?= htmlspecialchars($commandsKey) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">
                                Commands filename
                                <?= sprintf($helpIcon, htmlspecialchars('Имя файла команд (обычно commands.json).')) ?>
                            </label>
                            <input type="text" class="form-control" id="commands_file" name="commands_file" value="<?= htmlspecialchars($commandsFile) ?>">
                        </div>

                        <div class="col-md-6">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="commands_apply_before_intents" name="commands_apply_before_intents" <?= $commandsApplyBeforeIntents ? 'checked' : '' ?>>
                                <label class="form-check-label" for="commands_apply_before_intents">
                                    Применять команды до intents
                                    <?= sprintf($helpIcon, htmlspecialchars('Если true — сначала команды, потом execute_intents.')) ?>
                                </label>
                            </div>

                            

                        </div>

                        <div class="col-md-6">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="commands_allow_close" name="commands_allow_close" <?= $commandsAllowClose ? 'checked' : '' ?>>
                                <label class="form-check-label" for="commands_allow_close">
                                    Разрешить команды close
                                    <?= sprintf($helpIcon, htmlspecialchars('Если false — команды закрытия игнорируются (безопасность).')) ?>
                                </label>
                            </div>

                            

                        
                    <div class="row g-3 mt-0">
                        <div class="col-md-8">
                            

                            

                        </div>
                    </div>

</div>
                    </div>

                    <hr class="my-4">

                    <h6 class="mb-3">Trailing (Dumb / On-Open)</h6>

<?php if ($_brainModeActive): ?>
                    <div class="alert alert-info py-2 mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        <strong>Brain-controlled mode active:</strong>
                        These local trailing toggles (<code>dumb_trailing_enabled</code>, <code>enable_trailing_on_open</code>)
                        are <strong>overridden</strong> by Brain trailing contract.
                        Trailing enabled/disabled is determined by normalized Brain <code>risk.trailing</code>.
                        Local toggles are used only in legacy (non-Brain) mode.
                    </div>
<?php endif; ?>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="dumb_trailing_enabled" name="dumb_trailing_enabled" <?= $dumbTrailingEnabled ? 'checked' : '' ?>>
                                <label class="form-check-label" for="dumb_trailing_enabled">
                                    Dumb Trailing включён
                                    <?php if ($_brainModeActive): ?><span class="badge bg-secondary ms-1">overridden</span><?php endif; ?>
                                    <?= sprintf($helpIcon, htmlspecialchars('Простой trailing по последней цене. В Brain-controlled mode этот toggle игнорируется — trailing управляется Brain контрактом.')) ?>
                                </label>
                            </div>

                            

                        </div>

                        <div class="col-md-6">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="enable_trailing_on_open" name="enable_trailing_on_open" <?= $enableTrailingOnOpen ? 'checked' : '' ?>>
                                <label class="form-check-label" for="enable_trailing_on_open">
                                    Включать trailing сразу при открытии
                                    <?php if ($_brainModeActive): ?><span class="badge bg-secondary ms-1">overridden</span><?php endif; ?>
                                    <?= sprintf($helpIcon, htmlspecialchars('Если true — бот активирует trailing при open. В Brain-controlled mode этот toggle игнорируется — Brain контракт решает.')) ?>
                                </label>
                            </div>

                            

                        </div>

                        <div class="col-md-6">
                            <label class="form-label">
                                epsilon_pct
                                <?= sprintf($helpIcon, htmlspecialchars('Защита от шума: минимальная разница в % для обновления trailing.')) ?>
                            </label>
                            <input type="number" step="0.001" class="form-control" id="dumb_trailing_activation_epsilon_pct" name="dumb_trailing_activation_epsilon_pct" value="<?= htmlspecialchars((string)$dumbTrailingEpsPct) ?>" min="0" max="10">
                        
                    <div class="row g-3 mt-0">
                        <div class="col-md-8">
                            

                            

                        </div>
                    </div>

</div>
                    </div>

                    <!-- ROI-Based Trailing Presets (price_distance_floor mode) -->
                    <div class="card mb-3">
                        <div class="card-header py-2">
                            <strong>🎯 Trailing Presets</strong>
                            <small class="text-muted ms-2">(price_distance_floor mode)</small>
                        </div>
                        <div class="card-body py-2">
                            <div class="alert alert-secondary py-1 px-2 mb-2" style="font-size:0.78rem;">
                                Distance ROI is converted to price distance using leverage.<br>
                                <code>price_distance_pct = distance_roi / leverage / 100</code>
                            </div>
                            <?php
                                $cfgPresetMode = (string)($config['execution']['trailing_preset_mode'] ?? 'medium');
                                $cfgPresets = $config['execution']['trailing_presets'] ?? [];
                            ?>
                            <div class="row g-2 mb-2">
                                <div class="col-md-4">
                                    <label class="form-label">Preset Mode</label>
                                    <select class="form-select form-select-sm" id="trailing_preset_mode" name="trailing_preset_mode">
                                        <option value="soft" <?= $cfgPresetMode === 'soft' ? 'selected' : '' ?>>Soft (conservative)</option>
                                        <option value="medium" <?= $cfgPresetMode === 'medium' ? 'selected' : '' ?>>Medium (balanced)</option>
                                        <option value="hard" <?= $cfgPresetMode === 'hard' ? 'selected' : '' ?>>Hard (tight)</option>
                                        <option value="custom" <?= $cfgPresetMode === 'custom' ? 'selected' : '' ?>>Custom (Brain values)</option>
                                    </select>
                                </div>
                            </div>
                            <table class="table table-sm table-bordered mb-0" style="font-size:0.75rem;">
                                <thead><tr><th>Preset</th><th>Activation ROI</th><th>Floor Lock ROI</th><th>Distance ROI</th></tr></thead>
                                <tbody>
                                <?php foreach (['soft', 'medium', 'hard'] as $pName):
                                    $pVals = $cfgPresets[$pName] ?? [];
                                    $isActive = ($cfgPresetMode === $pName);
                                ?>
                                    <tr class="<?= $isActive ? 'table-primary' : '' ?>">
                                        <td><strong><?= $pName ?></strong> <?= $isActive ? '✅' : '' ?></td>
                                        <td><?= (float)($pVals['activation_roi'] ?? 0) ?></td>
                                        <td><?= (float)($pVals['floor_lock_roi'] ?? 0) ?></td>
                                        <td><?= (float)($pVals['distance_roi'] ?? 0) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Profit Add-On (one-time scale-in into winning position) -->
                    <div class="card mb-3">
                        <div class="card-header py-2">
                            <strong>💰 Profit Add-On</strong>
                            <small class="text-muted ms-2">(one-time scale-in into winning trade)</small>
                        </div>
                        <div class="card-body py-2">
                            <div class="alert alert-info py-1 px-2 mb-2" style="font-size:0.78rem;">
                                ℹ️ <strong>Configured in Brain Trailing Block.</strong>
                                Set <code>profit_addon_enabled</code> and <code>profit_addon_budget_pct</code> in the Brain settings trailing section.
                                Bot reads these values from the Brain-resolved trailing contract.
                            </div>
                            <?php
                                $profitAddonEnabledDisplay = (bool)($config['execution']['profit_addon_enabled'] ?? false);
                                $profitAddonBudgetPctDisplay = (float)($config['execution']['profit_addon_budget_pct'] ?? 0.0);
                            ?>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <div class="form-check form-switch mt-2 text-muted">
                                        <input class="form-check-input" type="checkbox" disabled <?= $profitAddonEnabledDisplay ? 'checked' : '' ?>>
                                        <label class="form-check-label">
                                            Profit Add-On (bot fallback default)
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label text-muted">Add-On Budget % (bot fallback default)</label>
                                    <input type="number" step="0.1" min="0" max="500" class="form-control" disabled value="<?= htmlspecialchars((string)$profitAddonBudgetPctDisplay) ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Trend-Reversal Soft Ladder (TEST MODE) -->
                    <div class="card mb-3 border-warning">
                        <div class="card-header py-2 bg-warning bg-opacity-10">
                            <strong>🔬 Trend-Reversal Soft Ladder</strong>
                            <span class="badge bg-warning text-dark ms-2" style="font-size:0.65rem;">TEST MODE</span>
                            <small class="text-muted ms-2">(short V2/V3 only — step_mode: trend_reversal_soft_ladder_short)</small>
                        </div>
                        <div class="card-body py-2">
                            <div class="alert alert-warning py-1 px-2 mb-2" style="font-size:0.78rem;">
                                ⚠️ <strong>TEST MODE — fixed constants, not freely configurable in v1.</strong><br>
                                Short V2/V3 only. Activates by peak ROI ladder in short-only test mode. No long reversal signal required.<br>
                                Soft ROI ladder: once peak ROI ≥ 10, locks profit gradually instead of aggressively.
                            </div>
                            <table class="table table-sm table-bordered mb-2" style="font-size:0.75rem;">
                                <thead><tr><th colspan="2" class="table-secondary">Fixed Test Constants</th></tr></thead>
                                <tbody>
                                    <tr><td>Activation Peak ROI</td><td><strong>10</strong> ROI%</td></tr>
                                    <tr><td>Base Lock ROI</td><td><strong>5</strong> ROI%</td></tr>
                                    <tr><td>Main Step ROI</td><td><strong>3</strong> ROI%</td></tr>
                                    <tr><td>Lock Step ROI</td><td><strong>1</strong> ROI%</td></tr>
                                </tbody>
                            </table>
                            <table class="table table-sm table-bordered mb-2" style="font-size:0.75rem;">
                                <thead><tr><th>Peak ROI</th><th>Lock ROI</th></tr></thead>
                                <tbody>
                                    <tr><td>&lt; 10</td><td>0 (dormant)</td></tr>
                                    <tr><td>10.0 – 12.9</td><td>5</td></tr>
                                    <tr><td>13.0 – 15.9</td><td>6</td></tr>
                                    <tr><td>16.0 – 18.9</td><td>7</td></tr>
                                    <tr><td>19.0 – 21.9</td><td>8</td></tr>
                                </tbody>
                            </table>
                            <div class="text-muted" style="font-size:0.72rem;">
                                <strong>Scope:</strong> double_top_contextual_v2, double_top_contextual_v3 (short source)<br>
                                <strong>Activation:</strong> peak_roi &ge; 10 (short-only, no long reversal required)<br>
                                <strong>Enable:</strong> set <code>trailing_step_mode = trend_reversal_soft_ladder_short</code> in Brain trailing contract.
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">

                    <h6 class="mb-3">Баланс</h6>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">
                                balance_coin
                                <?= sprintf($helpIcon, htmlspecialchars('Монета для контроля баланса (обычно USDT).')) ?>
                            </label>
                            <input type="text" class="form-control" id="balance_coin" name="balance_coin" value="<?= htmlspecialchars($balanceCoin) ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">
                                buffer_pct
                                <?= sprintf($helpIcon, htmlspecialchars('Запас по балансу в %, чтобы не открыть сделку «впритык».')) ?>
                            </label>
                            <input type="number" class="form-control" id="balance_required_buffer_pct" name="balance_required_buffer_pct" value="<?= (int)$balanceBufferPct ?>" min="0" max="100">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">
                                reject_below_usdt
                                <?= sprintf($helpIcon, htmlspecialchars('Если доступно меньше этого USDT — бот не открывает сделки.')) ?>
                            </label>
                            <input type="number" class="form-control" id="balance_reject_below_usdt" name="balance_reject_below_usdt" value="<?= (int)$balanceRejectBelow ?>" min="0" max="1000000">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">
                                balance_cache_ttl_sec
                                <?= sprintf($helpIcon, htmlspecialchars('Кеш баланса (сек). Меньше — чаще дергаем API.')) ?>
                            </label>
                            <input type="number" class="form-control" id="balance_cache_ttl_sec" name="balance_cache_ttl_sec" value="<?= (int)$balanceCacheTtl ?>" min="0" max="600">
                        </div>

                        <div class="col-md-8">
                            <div class="form-check form-switch mt-4">
                                <input class="form-check-input" type="checkbox" id="balance_strict_stable_coin_only" name="balance_strict_stable_coin_only" <?= $balanceStrictStable ? 'checked' : '' ?>>
                                <label class="form-check-label" for="balance_strict_stable_coin_only">
                                    Только stable-coin для баланса
                                    <?= sprintf($helpIcon, htmlspecialchars('Если true — бот игнорирует прочие монеты при расчёте доступного баланса.')) ?>
                                </label>
                            </div>

                            

                        
                    <div class="row g-3 mt-0">
                        <div class="col-md-8">
                            

                            

                        </div>
                    </div>

</div>
                    </div>

                    <hr class="my-4">

                    <h6 class="mb-3">UI</h6>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">
                                Preview intents (шт.)
                                <?= sprintf($helpIcon, htmlspecialchars('Сколько intents показывать в превью/на странице, чтобы UI не тормозил.')) ?>
                            </label>
                            <input type="number" class="form-control" id="ui_max_preview_items" name="ui_max_preview_items" value="<?= (int)$maxPreviewItems ?>" min="1" max="500">
                        </div>
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Сохранить настройки
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>

    <div class="col-md-5">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-info-circle me-2"></i>Текущая конфигурация (effective)
            </div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr>
                        <td class="text-muted">Account ID</td>
                        <td><?= htmlspecialchars($accountId) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Mode</td>
                        <td><?= htmlspecialchars($mode) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Category</td>
                        <td><?= htmlspecialchars($exchangeCategory) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Reconcile before action</td>
                        <td><?= $reconcileBeforeAction ? 'Да' : 'Нет' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Signals</td>
                        <td><code><?= htmlspecialchars($signalsKey) ?>/<?= htmlspecialchars($signalsFile) ?></code></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Schema (signals)</td>
                        <td><code><?= htmlspecialchars($signalSchemaVersion) ?></code></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Инверсия направления</td>
                        <td><?= $reverseSideEnabled ? 'Вкл' : 'Выкл' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Allowed order types</td>
                        <td><?= htmlspecialchars(implode(', ', (array)$allowedOrderTypes)) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">max_execute_per_run</td>
                        <td><?= (int)$maxExecutePerRun ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">max_deferred_per_run</td>
                        <td><?= (int)$maxDeferredPerRun ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header">
                <i class="bi bi-shield-exclamation me-2"></i>Валидация риска (required fields)
            </div>
            <div class="card-body">
                <?php if (!empty($requiredRiskFields)): ?>
                    <ul class="small mb-0">
                        <?php foreach ((array)$requiredRiskFields as $field): ?>
                            <li><code><?= htmlspecialchars((string)$field) ?></code></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="text-muted small">Список required_risk_fields не задан в config.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header">
                <i class="bi bi-eye me-2"></i>Подсказка
            </div>
            <div class="card-body small text-muted">
                Все подсказки доступны по иконке <i class="bi bi-question-circle"></i> (наведите мышь).
                <br>
                Настройки сохраняются в <code>config/bot.json</code>.
            </div>
        </div>
    </div>
</div>

<script>
/**
 * Symbol overrides UI helpers
 */
(function () {
    function sanitizeSymbol(s) {
        const sym = String(s || '').trim().toUpperCase();
        if (!sym) return '';
        // Simple Bybit symbol pattern (BTCUSDT, MERLUSDT, etc.)
        if (!/^[A-Z0-9]{3,25}$/.test(sym)) return '';
        return sym;
    }

    window.addSymbolOverride = function () {
        const input = document.getElementById('symbol_override_new');
        const sym = sanitizeSymbol(input ? input.value : '');
        if (!sym) {
            alert('Введите корректный символ (пример: BTCUSDT).');
            return;
        }

        const tbody = document.querySelector('#symbolOverridesTable tbody');
        if (!tbody) return;

        // Prevent duplicates
        for (const tr of tbody.querySelectorAll('tr')) {
            const v = tr.querySelector('.sym-symbol');
            if (v && String(v.value || '').trim().toUpperCase() === sym) {
                alert('Этот символ уже есть в списке.');
                return;
            }
        }

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="text" class="form-control form-control-sm sym-symbol" value="${sym}" readonly></td>
            <td><div class="form-check form-switch"><input class="form-check-input sym-enabled" type="checkbox" checked></div></td>
            <td><div class="form-check form-switch"><input class="form-check-input sym-reverse" type="checkbox"></div></td>
            <td>
                <select class="form-select form-select-sm sym-force">
                    <option value="" selected>Авто (как в сигнале)</option>
                    <option value="long">LONG</option>
                    <option value="short">SHORT</option>
                </select>
            </td>
            <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeSymbolOverride(this)">×</button></td>
        `;
        tbody.prepend(tr);
        if (input) input.value = '';
    };

    window.removeSymbolOverride = function (btn) {
        const tr = btn && btn.closest ? btn.closest('tr') : null;
        if (tr && tr.parentNode) {
            tr.parentNode.removeChild(tr);
        }
    };

    window.collectSymbolOverrides = function () {
        const out = {};
        const tbody = document.querySelector('#symbolOverridesTable tbody');
        if (!tbody) return out;

        for (const tr of tbody.querySelectorAll('tr')) {
            const symEl = tr.querySelector('.sym-symbol');
            const enabledEl = tr.querySelector('.sym-enabled');
            const reverseEl = tr.querySelector('.sym-reverse');
            const forceEl = tr.querySelector('.sym-force');

            const sym = sanitizeSymbol(symEl ? symEl.value : '');
            if (!sym) continue;

            out[sym] = {
                enabled: !!(enabledEl && enabledEl.checked),
                reverse_side_enabled: !!(reverseEl && reverseEl.checked),
            };

            const fs = forceEl ? String(forceEl.value || '').trim().toLowerCase() : '';
            if (fs === 'long' || fs === 'short') {
                out[sym].force_side = fs;
            }
        }
        return out;
    };
})();


document.getElementById('settingsForm').addEventListener('submit', async (e) => {
    e.preventDefault();

    function num(v, fallback) {
        const x = parseInt(v, 10);
        return Number.isFinite(x) ? x : fallback;
    }
    function flt(v, fallback) {
        const x = parseFloat(v);
        return Number.isFinite(x) ? x : fallback;
    }

    const config = {
        enabled: document.getElementById('enabled').checked,
        mode: document.getElementById('mode').value,
        account_id: document.getElementById('account_id').value,
        max_positions: num(document.getElementById('max_positions').value, <?= (int)$maxPositions ?>),
        safety_stop_errors: num(document.getElementById('safety_stop_errors').value, <?= (int)$safetyStopErrors ?>),
        reconcile_before_action: document.getElementById('reconcile_before_action').checked,

        symbol_overrides: collectSymbolOverrides(),

        exchange: {
            category: document.getElementById('exchange_category').value,
            account_type: document.getElementById('exchange_account_type').value,
            settle_coin: document.getElementById('exchange_settle_coin').value,
            position_idx: num(document.getElementById('exchange_position_idx').value, <?= (int)$exchangePositionIdx ?>),
            tpsl_mode: document.getElementById('exchange_tpsl_mode').value,
            sl_trigger_by: document.getElementById('exchange_sl_trigger_by').value,
        },

        sources: {
            signals_key: document.getElementById('signals_key').value,
            signals_file: document.getElementById('signals_file').value,
            risk_active_key: document.getElementById('risk_active_key').value,
            risk_active_file: document.getElementById('risk_active_file').value,
            commands_key: document.getElementById('commands_key').value,
            commands_file: document.getElementById('commands_file').value,
        },

        execution: {
            max_scan_intents_per_run: num(document.getElementById('max_scan_intents_per_run').value, <?= (int)$maxScanIntents ?>),
            max_intents_per_run: num(document.getElementById('max_intents_per_run').value, <?= (int)$maxExecutePerRun ?>),
            max_deferred_intents_per_run: num(document.getElementById('max_deferred_intents_per_run').value, <?= (int)$maxDeferredPerRun ?>),
            default_entry_timeout_minutes: num(document.getElementById('default_entry_timeout_minutes').value, <?= (int)$defaultEntryTimeout ?>),
            default_late_threshold_pct: flt(document.getElementById('default_late_threshold_pct').value, <?= (float)$defaultLateThresholdPct ?>),
            retrace_slack_pct: flt(document.getElementById('retrace_slack_pct').value, <?= (float)$retraceSlackPct ?>),
            exchange_positions_cache_ttl_sec: num(document.getElementById('exchange_positions_cache_ttl_sec').value, <?= (int)$exchangePosCacheTtl ?>),
            require_price_check_live: document.getElementById('require_price_check_live').checked,
            reverse_side_enabled: document.getElementById('reverse_side_enabled').checked,

            // commands
            commands_enabled: document.getElementById('commands_enabled').checked,
            commands_apply_before_intents: document.getElementById('commands_apply_before_intents').checked,
            commands_allow_close: document.getElementById('commands_allow_close').checked,
            commands_max_per_run: num(document.getElementById('commands_max_per_run').value, <?= (int)$commandsMaxPerRun ?>),

            // trailing
            dumb_trailing_enabled: document.getElementById('dumb_trailing_enabled').checked,
            enable_trailing_on_open: document.getElementById('enable_trailing_on_open').checked,
            dumb_trailing_activation_epsilon_pct: flt(document.getElementById('dumb_trailing_activation_epsilon_pct').value, <?= (float)$dumbTrailingEpsPct ?>),

            // profit add-on (now controlled by Brain trailing block; bot uses Brain contract value at runtime)
            profit_addon_enabled: <?= $profitAddonEnabled ? 'true' : 'false' ?>,
            profit_addon_budget_pct: <?= (float)$profitAddonBudgetPct ?>,

            // balance
            balance_coin: document.getElementById('balance_coin').value,
            balance_required_buffer_pct: num(document.getElementById('balance_required_buffer_pct').value, <?= (int)$balanceBufferPct ?>),
            balance_reject_below_usdt: num(document.getElementById('balance_reject_below_usdt').value, <?= (int)$balanceRejectBelow ?>),
            balance_cache_ttl_sec: num(document.getElementById('balance_cache_ttl_sec').value, <?= (int)$balanceCacheTtl ?>),
            balance_strict_stable_coin_only: document.getElementById('balance_strict_stable_coin_only').checked,
        },

        validation: {
            signal_schema_version: document.getElementById('signal_schema_version').value,
        },

        ui: {
            max_preview_items: num(document.getElementById('ui_max_preview_items').value, <?= (int)$maxPreviewItems ?>),
        },
    };

    const btn = e.target.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Сохранение...';

    try {
        const result = await apiCall('api/settings/save', 'POST', { config });
        if (result.ok) {
            location.reload();
        } else {
            alert('Ошибка: ' + (result.error || 'Unknown error'));
        }
    } catch (e) {
        alert('Request failed: ' + e.message);
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Сохранить настройки';
});
</script>

<?php
/* RULES
- UI настраивает только runtime overrides через config/bot.json
- config/config.php не переписываем через UI
- Все значения показываются как effective (config.php + bot.json)
- Подсказки реализованы через title на иконке (без bootstrap JS)
*/
?>
