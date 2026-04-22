<?php

declare(strict_types=1);

/**
 * Double Bottom Long Strategy — Admin Config Page
 */

use Core\System\System;
use Core\System\SystemPaths;
use Core\Auth\Auth;

if (!Auth::check()) {
    header('Location: ' . System::adminUrl('login'));
    exit;
}

$moduleDir = SystemPaths::instance()->get('strategy.double_bottom_long');

require_once $moduleDir . '/service.php';
require_once $moduleDir . '/bootstrap.php';

use Modules\Strategy\DoubleBottomLong\DoubleBottomLongService;
use Modules\Strategy\DoubleBottomLong\DoubleBottomLongBootstrap;

$service = DoubleBottomLongService::instance($moduleDir);

try {
    $boot         = DoubleBottomLongBootstrap::instance($moduleDir)->load();
    $config       = $boot['config'];
    $configValid  = $boot['valid'];
    $configErrors = $boot['errors'];
} catch (\Throwable $e) {
    $config       = [];
    $configValid  = false;
    $configErrors = [$e->getMessage()];
}

$schema = require $moduleDir . '/config/schema.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$flash = $_SESSION['dbl_flash'] ?? null;
unset($_SESSION['dbl_flash']);

$dblUrl  = rtrim(System::web('admin/strategy/double_bottom_long'), '/');
$ajaxUrl = System::web('admin/strategy/double_bottom_long/ajax');

$fEnabled = (bool)($config['enabled'] ?? false);
$fMode    = (string)($config['mode']  ?? 'passive');
?>
<style>
.dbl-config-page { max-width: 860px; }
.cfg-section { background: var(--card-bg,#1e293b); border: 1px solid var(--border-color,#334155); border-radius: 8px; padding: 20px; margin-bottom: 20px; }
.cfg-section h6 { color: #94a3b8; text-transform: uppercase; font-size: 11px; letter-spacing: .05em; margin-bottom: 14px; }
.effective-cfg th, .effective-cfg td { font-size: 12px; padding: 4px 8px; }
</style>

<div class="dbl-config-page">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h4 class="mb-0">Double Bottom Long — Настройки</h4>
            <div style="font-size: 12px; color: #64748b;">
                strategy_id: <code>double_bottom_long</code>
                &nbsp;|&nbsp;<span class="badge" style="background:#1d4ed8;font-size:10px;">LONG ONLY</span>
            </div>
        </div>
        <div>
            <a href="<?= $dblUrl ?>" class="btn btn-sm btn-outline-secondary">← Главная</a>
            <a href="<?= $dblUrl ?>/stats" class="btn btn-sm btn-outline-secondary ms-1">Статистика</a>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?? 'info' ?> py-2"><?= htmlspecialchars($flash['msg'] ?? '') ?></div>
    <?php endif; ?>

    <?php if (!$configValid): ?>
    <div class="alert alert-danger py-2">
        <strong>Ошибки конфигурации:</strong>
        <ul class="mb-0 mt-1">
            <?php foreach ($configErrors as $err): ?>
            <li><?= htmlspecialchars($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Edit form -->
    <form method="post" action="<?= htmlspecialchars($ajaxUrl) ?>">
        <input type="hidden" name="action" value="save_config">

        <div class="cfg-section">
            <h6>Основное (только лонг)</h6>
            <div class="row g-3">
                <div class="col-sm-4">
                    <label class="form-label">Включено</label>
                    <select name="enabled" class="form-select form-select-sm">
                        <option value="1" <?= $fEnabled ? 'selected' : '' ?>>Да</option>
                        <option value="0" <?= !$fEnabled ? 'selected' : '' ?>>Нет</option>
                    </select>
                </div>
                <div class="col-sm-4">
                    <label class="form-label">Режим</label>
                    <select name="mode" class="form-select form-select-sm">
                        <?php foreach (['passive','active','disabled','smoke_demo'] as $m): ?>
                        <option value="<?= $m ?>" <?= $fMode === $m ? 'selected' : '' ?>><?= $m ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-4">
                    <label class="form-label">Направление</label>
                    <input class="form-control form-control-sm" value="long_only" readonly disabled>
                    <div style="font-size:11px;color:#64748b;margin-top:4px;">Этот модуль — только лонг. Изменить нельзя.</div>
                </div>
            </div>
        </div>

        <div class="cfg-section">
            <h6>Фильтры</h6>
            <div class="row g-3">
                <div class="col-sm-3">
                    <label class="form-label">Рыночный режим</label>
                    <select name="market_regime_enabled" class="form-select form-select-sm">
                        <option value="1" <?= ($config['market_regime_enabled'] ?? true) ? 'selected' : '' ?>>Да</option>
                        <option value="0" <?= !($config['market_regime_enabled'] ?? true) ? 'selected' : '' ?>>Нет</option>
                    </select>
                </div>
                <div class="col-sm-3">
                    <label class="form-label">Режим фильтра</label>
                    <select name="market_regime_gate_mode" class="form-select form-select-sm">
                        <?php foreach (['soft','hard'] as $g): ?>
                        <option value="<?= $g ?>" <?= ($config['market_regime_gate_mode'] ?? 'soft') === $g ? 'selected' : '' ?>><?= $g ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-3">
                    <label class="form-label">Тренд обязателен</label>
                    <select name="trend_required" class="form-select form-select-sm">
                        <option value="1" <?= ($config['trend_required'] ?? true) ? 'selected' : '' ?>>Да</option>
                        <option value="0" <?= !($config['trend_required'] ?? true) ? 'selected' : '' ?>>Нет</option>
                    </select>
                </div>
                <div class="col-sm-3">
                    <label class="form-label">Волна обязательна</label>
                    <select name="wave_required" class="form-select form-select-sm">
                        <option value="1" <?= ($config['wave_required'] ?? true) ? 'selected' : '' ?>>Да</option>
                        <option value="0" <?= !($config['wave_required'] ?? true) ? 'selected' : '' ?>>Нет</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="cfg-section">
            <h6>Коридор</h6>
            <div class="row g-3">
                <div class="col-sm-3">
                    <label class="form-label">Коридор обязателен</label>
                    <select name="corridor_required" class="form-select form-select-sm">
                        <option value="1" <?= ($config['corridor_required'] ?? true) ? 'selected' : '' ?>>Да</option>
                        <option value="0" <?= !($config['corridor_required'] ?? true) ? 'selected' : '' ?>>Нет</option>
                    </select>
                </div>
                <div class="col-sm-3">
                    <label class="form-label">Глубина (часов)</label>
                    <input type="number" name="corridor_lookback_hours" class="form-control form-control-sm"
                           value="<?= (int)($config['corridor_lookback_hours'] ?? 24) ?>">
                </div>
                <div class="col-sm-3">
                    <label class="form-label">Число зон</label>
                    <input type="number" name="corridor_bucket_count" class="form-control form-control-sm"
                           value="<?= (int)($config['corridor_bucket_count'] ?? 10) ?>">
                </div>
                <div class="col-sm-3">
                    <label class="form-label">Разрешённые лонг-зоны</label>
                    <input type="text" name="allowed_long_buckets" class="form-control form-control-sm"
                           value="<?= htmlspecialchars(implode(', ', (array)($config['allowed_long_buckets'] ?? [1,2,3]))) ?>"
                           placeholder="1, 2, 3">
                    <div style="font-size:11px;color:#64748b;margin-top:3px;">Через запятую</div>
                </div>
            </div>
        </div>

        <div class="cfg-section">
            <h6>Ограничения сканера</h6>
            <div class="row g-3">
                <div class="col-sm-3">
                    <label class="form-label">Режим вселенной</label>
                    <select name="universe_mode" class="form-select form-select-sm">
                        <?php foreach (['all','manual_list','excluded'] as $um): ?>
                        <option value="<?= $um ?>" <?= ($config['universe_mode'] ?? 'all') === $um ? 'selected' : '' ?>><?= $um ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-3">
                    <label class="form-label">Размер батча</label>
                    <input type="number" name="batch_size" class="form-control form-control-sm" min="1"
                           value="<?= (int)($config['batch_size'] ?? 50) ?>">
                </div>
                <div class="col-sm-3">
                    <label class="form-label">Макс. символов за запуск (0 = все)</label>
                    <input type="number" name="max_symbols_per_run" class="form-control form-control-sm" min="0"
                           value="<?= (int)($config['max_symbols_per_run'] ?? 0) ?>">
                </div>
                <div class="col-sm-3">
                    <label class="form-label">Макс. время выполнения (сек)</label>
                    <input type="number" name="max_runtime_seconds" class="form-control form-control-sm" min="5"
                           value="<?= (int)($config['max_runtime_seconds'] ?? 55) ?>">
                </div>
                <div class="col-sm-6">
                    <label class="form-label">Разрешённые символы</label>
                    <textarea name="allowed_symbols" class="form-control form-control-sm" rows="3"
                              placeholder="BTCUSDT, ETHUSDT (пусто = все)"><?= htmlspecialchars(implode(', ', (array)($config['allowed_symbols'] ?? []))) ?></textarea>
                    <div style="font-size:11px;color:#64748b;margin-top:3px;">Через запятую или с новой строки. Применяется только при universe_mode=manual_list.</div>
                </div>
                <div class="col-sm-6">
                    <label class="form-label">Исключённые символы</label>
                    <textarea name="excluded_symbols" class="form-control form-control-sm" rows="3"
                              placeholder="XYZUSDT (пусто = ничего)"><?= htmlspecialchars(implode(', ', (array)($config['excluded_symbols'] ?? []))) ?></textarea>
                    <div style="font-size:11px;color:#64748b;margin-top:3px;">Через запятую или с новой строки.</div>
                </div>
                <div class="col-sm-4">
                    <label class="form-label">Непрерывное сканирование</label>
                    <select name="continuous_scan_enabled" class="form-select form-select-sm">
                        <option value="1" <?= ($config['continuous_scan_enabled'] ?? true) ? 'selected' : '' ?>>Включено (автоциклы)</option>
                        <option value="0" <?= !($config['continuous_scan_enabled'] ?? true) ? 'selected' : '' ?>>Выключено (один цикл)</option>
                    </select>
                    <div style="font-size:11px;color:#64748b;margin-top:3px;">Если включено, сканер автоматически перезапускает цикл.</div>
                </div>
            </div>
        </div>

        <div class="cfg-section">
            <h6>Подтверждение</h6>
            <div class="row g-3">
                <div class="col-sm-3">
                    <label class="form-label">Подтверждение обязательно</label>
                    <select name="confirm_required" class="form-select form-select-sm">
                        <option value="1" <?= ($config['confirm_required'] ?? true) ? 'selected' : '' ?>>Да</option>
                        <option value="0" <?= !($config['confirm_required'] ?? true) ? 'selected' : '' ?>>Нет</option>
                    </select>
                </div>
                <div class="col-sm-3">
                    <label class="form-label">Макс. баров подтверждения</label>
                    <input type="number" name="confirm_max_bars" class="form-control form-control-sm"
                           value="<?= (int)($config['confirm_max_bars'] ?? 2) ?>">
                </div>
                <div class="col-sm-3">
                    <label class="form-label">TTL сигнала (баров)</label>
                    <input type="number" name="signal_ttl_bars" class="form-control form-control-sm"
                           value="<?= (int)($config['signal_ttl_bars'] ?? 2) ?>">
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-sm">Сохранить</button>
        <a href="<?= $ajaxUrl ?>?action=reset_active" class="btn btn-sm btn-outline-danger ms-2"
           onclick="return confirm('Сбросить все переопределения к базовым значениям?')">Сброс к базовым</a>

        <div class="cfg-section mt-4">
            <h6>Стоп / ликвидационная зона</h6>
            <div class="row g-3">
                <div class="col-sm-3">
                    <label class="form-label">Режим стопа</label>
                    <select name="stop_mode" class="form-select form-select-sm">
                        <?php foreach (['structure','fixed_from_liq_zone','atr'] as $sm): ?>
                        <option value="<?= $sm ?>" <?= ($config['stop_mode'] ?? 'structure') === $sm ? 'selected' : '' ?>><?= $sm ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-3">
                    <label class="form-label">Буфер стопа от лик. зоны</label>
                    <input type="number" name="stop_from_liq_buffer_value" class="form-control form-control-sm" step="0.0001" min="0"
                           value="<?= number_format((float)($config['stop_from_liq_buffer_value'] ?? 0.002), 4, '.', '') ?>">
                </div>
                <div class="col-sm-3">
                    <label class="form-label">Тип буфера</label>
                    <select name="stop_from_liq_buffer_type" class="form-select form-select-sm">
                        <?php foreach (['percent','absolute'] as $bt): ?>
                        <option value="<?= $bt ?>" <?= ($config['stop_from_liq_buffer_type'] ?? 'percent') === $bt ? 'selected' : '' ?>><?= $bt ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-3">
                    <label class="form-label">Бюджет бота (USDT)</label>
                    <input type="number" name="bot_budget" class="form-control form-control-sm" step="0.01" min="0"
                           value="<?= number_format((float)($config['bot_budget'] ?? 0.0), 2, '.', '') ?>">
                    <div style="font-size:11px;color:#64748b;margin-top:3px;">0 = не задан</div>
                </div>
                <div class="col-sm-3">
                    <label class="form-label">Плечо (x)</label>
                    <input type="number" name="bot_leverage" class="form-control form-control-sm" step="1" min="1"
                           value="<?= (int)($config['bot_leverage'] ?? 1) ?>">
                </div>
            </div>
            <div style="font-size:11px;color:#64748b;margin-top:8px;">
                Параметры стопа сохраняются в конфигурацию и в runtime_snapshot. Активное исполнение ордеров в этом шаге <strong>не реализовано</strong>.
            </div>
        </div>

        <div class="cfg-section">
            <h6>Выход / тейк-профит</h6>
            <?php
                $tpEnabled = (bool)($config['tp_enabled'] ?? false);
            ?>
            <div class="row g-3">
                <div class="col-sm-3">
                    <label class="form-label">Закрытие по реверс-паттерну</label>
                    <select name="reverse_pattern_close_enabled" class="form-select form-select-sm">
                        <option value="1" <?= ($config['reverse_pattern_close_enabled'] ?? false) ? 'selected' : '' ?>>Да</option>
                        <option value="0" <?= !($config['reverse_pattern_close_enabled'] ?? false) ? 'selected' : '' ?>>Нет</option>
                    </select>
                </div>
                <div class="col-sm-3">
                    <label class="form-label">TP включён</label>
                    <select name="tp_enabled" class="form-select form-select-sm" id="tp_enabled">
                        <option value="1" <?= $tpEnabled ? 'selected' : '' ?>>Да</option>
                        <option value="0" <?= !$tpEnabled ? 'selected' : '' ?>>Нет</option>
                    </select>
                </div>
                <div class="col-sm-3">
                    <label class="form-label">Режим TP</label>
                    <select name="tp_mode" class="form-select form-select-sm">
                        <?php foreach (['fixed_r','fixed_price'] as $tpm): ?>
                        <option value="<?= $tpm ?>" <?= ($config['tp_mode'] ?? 'fixed_r') === $tpm ? 'selected' : '' ?>><?= $tpm ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-3">
                    <label class="form-label">Значение TP</label>
                    <input type="number" name="tp_value" class="form-control form-control-sm" step="0.1" min="0"
                           value="<?= number_format((float)($config['tp_value'] ?? 2.0), 2, '.', '') ?>">
                    <div style="font-size:11px;color:#64748b;margin-top:3px;">Для fixed_r — кратное R; для fixed_price — абс. цена</div>
                </div>
            </div>
            <div style="font-size:11px;color:#64748b;margin-top:8px;">
                Трейлинг вынесен в отдельный модуль. Выход здесь: TP и реверс-паттерн только. Активное исполнение ордеров <strong>не реализовано</strong>.
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-sm">Сохранить</button>
        <a href="<?= $ajaxUrl ?>?action=reset_active" class="btn btn-sm btn-outline-danger ms-2"
           onclick="return confirm('Сбросить все переопределения к базовым значениям?')">Сброс к базовым</a>
    </form>

    <!-- Effective config table -->
    <div class="cfg-section mt-4">
        <h6>Активная конфигурация</h6>
        <table class="table table-sm effective-cfg">
            <thead><tr><th>Ключ</th><th>Значение</th><th>Тип</th></tr></thead>
            <tbody>
            <?php foreach ($schema as $k => $type): ?>
            <tr>
                <td><code><?= htmlspecialchars($k) ?></code></td>
                <td>
                    <?php
                    $v = $config[$k] ?? null;
                    if (is_array($v)) { echo '<code>' . htmlspecialchars(json_encode($v)) . '</code>'; }
                    elseif (is_bool($v)) { echo $v ? '<span class="text-success">true</span>' : '<span class="text-danger">false</span>'; }
                    else { echo '<code>' . htmlspecialchars((string)$v) . '</code>'; }
                    ?>
                </td>
                <td><small class="text-muted"><?= htmlspecialchars($type) ?></small></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
