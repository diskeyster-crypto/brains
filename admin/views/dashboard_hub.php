<?php

declare(strict_types=1);

use Core\System\System;

/**
 * Operator Dashboard Hub
 *
 * Main operator control surface. Replaces Brain as the primary entry point.
 * Reads directly from bot module storage — no secondary state store.
 *
 * Tabs: Стратегии | Бот | Управление
 *
 * Routes registered in public/index.php:
 *   GET  /admin/dashboard
 *   POST /admin/dashboard/overrides/save
 */

if (!function_exists('renderDashboardHub')) {

function renderDashboardHub(): string
{
    $botDir     = System::path('root') . '/modules/bot';
    $storageDir = $botDir . '/storage';

    // ── read storage files ────────────────────────────────────────────────
    $readJson = static function (string $file, mixed $default = []) use ($storageDir): mixed {
        $path = $storageDir . '/' . $file;
        if (!file_exists($path)) {
            return $default;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        return ($decoded !== null) ? $decoded : $default;
    };

    $registry  = $readJson('strategy_registry.json', []);
    $overrides = $readJson('operator_overrides.json', []);
    $lastRun   = $readJson('last_run.json', []);
    $stats     = $readJson('stats.json', []);
    $orders    = $readJson('active_orders.json', []);
    $positions = $readJson('active_positions.json', []);
    $queue     = $readJson('order_queue.json', []);

    // ── bot base config (merged base + active) ────────────────────────────
    $botConfig = [];
    $botConfigErrors = [];
    try {
        $base   = is_file($botDir . '/config/base.php')   ? (require $botDir . '/config/base.php')   : [];
        $active = is_file($botDir . '/config/active.php') ? (require $botDir . '/config/active.php') : [];
        $botConfig = array_merge(is_array($base) ? $base : [], is_array($active) ? $active : []);
    } catch (\Throwable $e) {
        $botConfigErrors[] = $e->getMessage();
    }

    // ── flash message ─────────────────────────────────────────────────────
    $flash = null;
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!empty($_SESSION['dashboard_flash'])) {
        $flash = $_SESSION['dashboard_flash'];
        unset($_SESSION['dashboard_flash']);
    }

    // ── summary counts ────────────────────────────────────────────────────
    $totalStrat    = count($registry);
    $enabledStrat  = 0;
    $disabledStrat = 0;
    foreach ($registry as $r) {
        $op = (array)($overrides[$r['strategy_id'] ?? ''] ?? []);
        if ($op['enabled'] ?? true) {
            $enabledStrat++;
        } else {
            $disabledStrat++;
        }
    }
    $queueSize   = count($queue);
    $ordersCount = count($orders);
    $posCount    = count($positions);

    // ── last-run headline fields ──────────────────────────────────────────
    $tickAt     = (string)($lastRun['tick_at']    ?? '—');
    $tickStatus = (string)($lastRun['status']     ?? 'never_run');
    $botEnabled = ($lastRun['bot_enabled'] ?? false) ? 'Включён' : 'Выключен';
    $botMode    = (string)($lastRun['bot_mode']   ?? 'passive');
    $sigProc    = (int)($lastRun['handoff_signals_processed'] ?? 0);
    $handoffTotal = (int)($lastRun['handoff_signals_seen_total'] ?? $lastRun['handoff_sources_active_total'] ?? 0);

    // ── escape helper ─────────────────────────────────────────────────────
    $e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

    // ── Strategies tab rows ───────────────────────────────────────────────
    $stratRows = '';
    if (empty($registry)) {
        $stratRows = '<tr><td colspan="7" class="text-center" style="color:#64748b;padding:20px;">Стратегии ещё не обнаружены. Выполните первый тик бота.</td></tr>';
    } else {
        foreach ($registry as $rec) {
            $stratId  = (string)($rec['strategy_id'] ?? '');
            $title    = (string)($rec['title']       ?? $stratId);
            $status   = (string)($rec['status']      ?? 'discovered');
            $supLong  = $rec['supports_long']  ? '<span style="color:#22c55e;">✓ Long</span>'  : '<span style="color:#475569;">—</span>';
            $supShort = $rec['supports_short'] ? '<span style="color:#22c55e;">✓ Short</span>' : '<span style="color:#475569;">—</span>';
            $handoff  = $rec['handoff_queue_path'] ? '<span style="color:#22c55e;">Да</span>' : '<span style="color:#475569;">Нет</span>';

            $op         = (array)($overrides[$stratId] ?? []);
            $opEnabled  = $op['enabled']               ?? true;
            $opMode     = (string)($op['mode']         ?? 'passive');
            $opBudget   = $op['bot_budget']             ?? 0;
            $opLev      = $op['bot_leverage']           ?? 0;
            $opEntryMode= (string)($op['entry_mode']   ?? '');
            $opMax      = $op['max_active_positions']   ?? 0;

            $enabledBadge = $opEnabled
                ? '<span style="background:rgba(34,197,94,.15);color:#22c55e;border:1px solid rgba(34,197,94,.3);font-size:11px;padding:2px 7px;border-radius:999px;">Включено</span>'
                : '<span style="background:rgba(107,114,128,.15);color:#6b7280;border:1px solid rgba(107,114,128,.3);font-size:11px;padding:2px 7px;border-radius:999px;">Выключено</span>';

            $statusBadge = $status === 'bot_ready'
                ? '<span style="background:rgba(34,197,94,.15);color:#22c55e;border:1px solid rgba(34,197,94,.3);font-size:11px;padding:2px 7px;border-radius:999px;">bot_ready</span>'
                : '<span style="background:rgba(100,116,139,.15);color:#94a3b8;border:1px solid rgba(100,116,139,.3);font-size:11px;padding:2px 7px;border-radius:999px;">' . $e($status) . '</span>';

            $dataEnabled = $opEnabled ? 'true' : 'false';
            $dataBudget  = (float)$opBudget;
            $dataLev     = (int)$opLev;
            $dataMode    = $e($opMode);
            $dataEntryMode = $e($opEntryMode);
            $dataMax     = (int)$opMax;
            $esStratId   = $e($stratId);
            $esTitle     = $e($title);

            $stratRows .= <<<HTML
<tr>
    <td><strong>{$esTitle}</strong><br><code style="font-size:11px;color:#7dd3fc;">{$esStratId}</code></td>
    <td>{$statusBadge}</td>
    <td>{$supLong} / {$supShort}</td>
    <td>{$handoff}</td>
    <td>{$enabledBadge}</td>
    <td style="font-size:12px;">
        Режим: <code>{$dataMode}</code> &nbsp;
        Бюджет: <code>{$dataBudget}</code> &nbsp;
        Плечо: <code>{$dataLev}</code> &nbsp;
        Вход: <code>{$dataEntryMode}</code> &nbsp;
        Макс.поз: <code>{$dataMax}</code>
    </td>
    <td>
        <button class="btn btn-sm btn-primary"
            onclick="dhOpenEdit({$dataEnabled},'{$esStratId}','{$esTitle}','{$dataMode}',{$dataBudget},{$dataLev},'{$dataEntryMode}',{$dataMax})">
            Изменить
        </button>
    </td>
</tr>
HTML;
        }
    }

    // ── Control tab: bot config display ───────────────────────────────────
    $cfgEnabled     = ($botConfig['enabled'] ?? false) ? '1' : '0';
    $cfgMode        = $e($botConfig['mode']              ?? 'passive');
    $cfgMaxBudget   = (float)($botConfig['max_bot_budget']   ?? 0.0);
    $cfgMaxLeverage = (int)($botConfig['max_bot_leverage']   ?? 0);
    $cfgDefEntry    = $e($botConfig['default_entry_mode']    ?? '');
    $cfgDefMaxPos   = (int)($botConfig['default_max_active_positions'] ?? 0);
    $cfgEntryModes  = implode(', ', (array)($botConfig['allowed_entry_modes'] ?? []));
    $cfgMaxAgeSec   = (int)($botConfig['max_signal_age_sec'] ?? 0);
    $cfgDedupWindow = (int)($botConfig['queue_dedup_ttl_sec'] ?? 0);
    $cfgScanRoots   = implode(', ', (array)($botConfig['strategy_scan_roots'] ?? []));
    $cfgEnabledLabel = $cfgEnabled === '1' ? 'Да' : 'Нет';

    $globalSaveUrl = System::web('admin/dashboard/global/save');

    $overridesTable = '';
    if (empty($overrides)) {
        $overridesTable = '<p style="color:#64748b;font-size:13px;">Нет переопределений оператора.</p>';
    } else {
        $overridesTable .= '<table class="table table-sm" style="font-size:12px;"><thead><tr><th>Стратегия</th><th>Вкл</th><th>Бюджет</th><th>Плечо</th><th>Вход</th><th>Макс.поз</th></tr></thead><tbody>';
        foreach ($overrides as $sid => $ov) {
            $overridesTable .= '<tr>';
            $overridesTable .= '<td><code>' . $e($sid) . '</code></td>';
            $overridesTable .= '<td>' . ($ov['enabled'] ? '✓' : '—') . '</td>';
            $overridesTable .= '<td>' . $e($ov['bot_budget'] ?? 0) . '</td>';
            $overridesTable .= '<td>' . $e($ov['bot_leverage'] ?? 0) . '</td>';
            $overridesTable .= '<td>' . $e($ov['entry_mode'] ?? '—') . '</td>';
            $overridesTable .= '<td>' . $e($ov['max_active_positions'] ?? 0) . '</td>';
            $overridesTable .= '</tr>';
        }
        $overridesTable .= '</tbody></table>';
    }

    // ── Bot tab: stats rows ───────────────────────────────────────────────
    $statsRows = '';
    $statLabels = [
        'ticks_total'                            => 'Всего тиков',
        'strategies_discovered_total'            => 'Стратегий обнаружено',
        'strategies_enabled_total'               => 'Стратегий включено',
        'strategies_disabled_total'              => 'Стратегий выключено',
        'handoff_signals_seen_total'             => 'Handoff-сигналов получено',
        'handoff_signals_ignored_disabled_strategy_total' => 'Сигналов проигнорировано (выкл. стратегия)',
        'order_queue_total'                      => 'Очередь ордеров (всего активных)',
        'order_queue_new_total'                  => 'Ордеров поставлено в очередь',
        'order_queue_refreshed_total'            => 'Ордеров обновлено',
        'order_queue_expired_total'              => 'Ордеров истекло',
        'order_queue_withdrawn_total'            => 'Ордеров отозвано',
        'active_orders_total'                    => 'Активных ордеров',
        'active_positions_total'                 => 'Активных позиций',
    ];
    foreach ($statLabels as $key => $label) {
        $val = $stats[$key] ?? 0;
        $statsRows .= '<tr><td style="color:#94a3b8;">' . $e($label) . '</td><td><strong>' . $e($val) . '</strong></td></tr>';
    }

    // ── save URL ──────────────────────────────────────────────────────────
    $saveUrl = System::web('admin/dashboard/overrides/save');

    // ── flash HTML ────────────────────────────────────────────────────────
    $flashHtml = '';
    if ($flash) {
        $type = ($flash['type'] === 'success') ? 'success' : 'danger';
        $msg  = $e($flash['msg'] ?? '');
        $flashHtml = <<<HTML
<div class="alert alert-{$type} alert-dismissible fade show" style="font-size:13px;">
    {$msg}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
HTML;
    }

    // ── render ────────────────────────────────────────────────────────────
    return <<<HTML
<div style="max-width:1200px;">

{$flashHtml}

<!-- Page header -->
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h4 class="mb-0"><i class="bi bi-layout-text-sidebar-reverse me-2"></i>Оперативный центр</h4>
        <div style="font-size:12px;color:#64748b;">Управление стратегиями · Бот · Контроль</div>
    </div>
</div>

<!-- Summary strip -->
<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
    <div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:10px 18px;min-width:110px;text-align:center;">
        <div style="font-size:22px;font-weight:700;color:#e2e8f0;">{$totalStrat}</div>
        <div style="font-size:11px;color:#64748b;text-transform:uppercase;">Стратегий</div>
    </div>
    <div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:10px 18px;min-width:110px;text-align:center;">
        <div style="font-size:22px;font-weight:700;color:#22c55e;">{$enabledStrat}</div>
        <div style="font-size:11px;color:#64748b;text-transform:uppercase;">Включено</div>
    </div>
    <div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:10px 18px;min-width:110px;text-align:center;">
        <div style="font-size:22px;font-weight:700;color:#6b7280;">{$disabledStrat}</div>
        <div style="font-size:11px;color:#64748b;text-transform:uppercase;">Выключено</div>
    </div>
    <div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:10px 18px;min-width:110px;text-align:center;">
        <div style="font-size:22px;font-weight:700;color:#06b6d4;">{$sigProc}</div>
        <div style="font-size:11px;color:#64748b;text-transform:uppercase;">Сигналов</div>
    </div>
    <div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:10px 18px;min-width:110px;text-align:center;">
        <div style="font-size:22px;font-weight:700;color:#f59e0b;">{$queueSize}</div>
        <div style="font-size:11px;color:#64748b;text-transform:uppercase;">Очередь</div>
    </div>
    <div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:10px 18px;min-width:110px;text-align:center;">
        <div style="font-size:22px;font-weight:700;color:#3b82f6;">{$ordersCount}</div>
        <div style="font-size:11px;color:#64748b;text-transform:uppercase;">Ордеров</div>
    </div>
    <div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:10px 18px;min-width:110px;text-align:center;">
        <div style="font-size:22px;font-weight:700;color:#a78bfa;">{$posCount}</div>
        <div style="font-size:11px;color:#64748b;text-transform:uppercase;">Позиций</div>
    </div>
    <div style="background:#1e293b;border:1px solid #334155;border-radius:8px;padding:10px 18px;min-width:160px;text-align:center;">
        <div style="font-size:14px;font-weight:700;color:#e2e8f0;">{$e($tickStatus)}</div>
        <div style="font-size:11px;color:#64748b;">{$e($tickAt)}</div>
        <div style="font-size:11px;color:#64748b;text-transform:uppercase;margin-top:2px;">Бот: {$botEnabled}</div>
    </div>
</div>

<!-- Tabs navigation -->
<ul class="nav nav-tabs mb-3" id="dhTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="dh-strat-tab" data-bs-toggle="tab" data-bs-target="#dh-strat" type="button" role="tab">
            <i class="bi bi-layers me-1"></i>Стратегии
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="dh-bot-tab" data-bs-toggle="tab" data-bs-target="#dh-bot" type="button" role="tab">
            <i class="bi bi-cpu me-1"></i>Бот
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="dh-ctrl-tab" data-bs-toggle="tab" data-bs-target="#dh-ctrl" type="button" role="tab">
            <i class="bi bi-sliders me-1"></i>Управление
        </button>
    </li>
</ul>

<div class="tab-content">

    <!-- ── Strategies tab ────────────────────────────────────────────── -->
    <div class="tab-pane fade show active" id="dh-strat" role="tabpanel">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Обнаруженные стратегии</span>
                <small style="color:#64748b;font-size:11px;">Источник: bot/storage/strategy_registry.json</small>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Стратегия</th>
                            <th>Статус</th>
                            <th>Направление</th>
                            <th>Handoff</th>
                            <th>Оператор</th>
                            <th>Параметры</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>{$stratRows}</tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ── Bot tab ───────────────────────────────────────────────────── -->
    <div class="tab-pane fade" id="dh-bot" role="tabpanel">
        <div class="card mb-3">
            <div class="card-header">Текущий прогон (last_run.json)</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><th style="width:180px;">Статус тика</th><td><code>{$e($tickStatus)}</code></td>
                        <th style="width:160px;">Время тика</th><td><code>{$e($tickAt)}</code></td></tr>
                    <tr><th>Бот включён</th><td>{$botEnabled}</td>
                        <th>Режим</th><td><code>{$e($botMode)}</code></td></tr>
                    <tr><th>Обработано сигналов</th><td>{$e($sigProc)}</td>
                        <th>Очередь ордеров</th><td>{$e($lastRun['order_queue_total'] ?? 0)}</td></tr>
                    <tr><th>Активных ордеров</th><td>{$e($lastRun['active_orders_count'] ?? 0)}</td>
                        <th>Активных позиций</th><td>{$e($lastRun['active_positions_count'] ?? 0)}</td></tr>
                    <tr><th>Стратегий обнаружено</th><td>{$e($lastRun['strategies_discovered_total'] ?? 0)}</td>
                        <th>Стратегий включено</th><td>{$e($lastRun['strategies_enabled_total'] ?? 0)}</td></tr>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Накопленная статистика (stats.json)</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    {$statsRows}
                </table>
            </div>
        </div>
    </div>

    <!-- ── Control tab ───────────────────────────────────────────────── -->
    <div class="tab-pane fade" id="dh-ctrl" role="tabpanel">
        <div class="card mb-3">
            <div class="card-header">Глобальные настройки бота</div>
            <div class="card-body">
                <form method="post" action="{$globalSaveUrl}">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label" style="font-size:13px;">Бот включён</label>
                            <select name="enabled" class="form-select form-select-sm">
                                <option value="1" <?= $cfgEnabled === '1' ? 'selected' : '' ?>>Да</option>
                                <option value="0" <?= $cfgEnabled === '0' ? 'selected' : '' ?>>Нет</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" style="font-size:13px;">Режим бота</label>
                            <select name="mode" class="form-select form-select-sm">
                                <option value="passive"  <?= $cfgMode === 'passive'  ? 'selected' : '' ?>>passive</option>
                                <option value="active"   <?= $cfgMode === 'active'   ? 'selected' : '' ?>>active</option>
                                <option value="disabled" <?= $cfgMode === 'disabled' ? 'selected' : '' ?>>disabled</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" style="font-size:13px;">Режим входа по умолчанию</label>
                            <select name="default_entry_mode" class="form-select form-select-sm">
                                <option value=""       <?= $cfgDefEntry === '' ? 'selected' : '' ?>>— из сигнала —</option>
                                <option value="limit"  <?= $cfgDefEntry === 'limit'  ? 'selected' : '' ?>>limit</option>
                                <option value="market" <?= $cfgDefEntry === 'market' ? 'selected' : '' ?>>market</option>
                            </select>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label" style="font-size:13px;">Бюджет глобальный (0=из стратегии)</label>
                            <input type="number" step="0.01" min="0" name="max_bot_budget" value="{$cfgMaxBudget}" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" style="font-size:13px;">Плечо глобальное (0=из стратегии)</label>
                            <input type="number" step="1" min="0" name="max_bot_leverage" value="{$cfgMaxLeverage}" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" style="font-size:13px;">Макс. позиций по умолчанию (0=∞)</label>
                            <input type="number" step="1" min="0" name="default_max_active_positions" value="{$cfgDefMaxPos}" class="form-control form-control-sm">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Сохранить</button>
                </form>
                <hr style="border-color:#334155;margin:14px 0;">
                <table class="table table-sm mb-0" style="font-size:12px;">
                    <tr><th style="width:260px;color:#94a3b8;">Допустимые режимы входа</th><td><code>{$e($cfgEntryModes)}</code></td></tr>
                    <tr><th style="color:#94a3b8;">Макс. возраст сигнала (сек)</th><td><code>{$cfgMaxAgeSec}</code></td></tr>
                    <tr><th style="color:#94a3b8;">Окно дедупликации (сек)</th><td><code>{$cfgDedupWindow}</code></td></tr>
                    <tr><th style="color:#94a3b8;">Корни сканирования стратегий</th><td><code>{$e($cfgScanRoots)}</code></td></tr>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Переопределения оператора (operator_overrides.json)</div>
            <div class="card-body">
                {$overridesTable}
            </div>
        </div>
    </div>

</div><!-- /tab-content -->

</div><!-- /max-width -->

<!-- Edit override modal -->
<div id="dhEditModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.65);z-index:2000;align-items:center;justify-content:center;">
    <div style="background:#1e293b;border:1px solid #334155;border-radius:10px;padding:24px;min-width:440px;max-width:560px;width:100%;">
        <h5 id="dhEditTitle" class="mb-3">Настройки стратегии</h5>
        <form id="dhEditForm" method="post" action="{$saveUrl}">
            <input type="hidden" name="strategy_id" id="dhEditStratId">

            <div class="row g-2 mb-2">
                <div class="col-6">
                    <label class="form-label" style="font-size:13px;">Включено</label>
                    <select name="enabled" id="dhEditEnabled" class="form-select form-select-sm">
                        <option value="1">Да</option>
                        <option value="0">Нет</option>
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label" style="font-size:13px;">Режим стратегии</label>
                    <select name="mode" id="dhEditMode" class="form-select form-select-sm">
                        <option value="passive">passive</option>
                        <option value="active">active</option>
                        <option value="disabled">disabled</option>
                    </select>
                </div>
            </div>

            <div class="row g-2 mb-2">
                <div class="col-6">
                    <label class="form-label" style="font-size:13px;">Режим входа</label>
                    <select name="entry_mode" id="dhEditEntryMode" class="form-select form-select-sm">
                        <option value="">— из сигнала —</option>
                        <option value="limit">limit</option>
                        <option value="market">market</option>
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label" style="font-size:13px;">Макс. позиций (0=∞)</label>
                    <input type="number" step="1" min="0" name="max_active_positions" id="dhEditMaxPos" class="form-control form-control-sm">
                </div>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label" style="font-size:13px;">Бюджет (0=из сигнала)</label>
                    <input type="number" step="0.01" min="0" name="bot_budget" id="dhEditBudget" class="form-control form-control-sm">
                </div>
                <div class="col-6">
                    <label class="form-label" style="font-size:13px;">Плечо (0=из сигнала)</label>
                    <input type="number" step="1" min="0" name="bot_leverage" id="dhEditLeverage" class="form-control form-control-sm">
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">Сохранить</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="dhCloseEdit()">Отмена</button>
            </div>
        </form>
    </div>
</div>

<script>
function dhOpenEdit(enabled, stratId, title, mode, budget, leverage, entryMode, maxPos) {
    document.getElementById('dhEditTitle').textContent = 'Стратегия: ' + title;
    document.getElementById('dhEditStratId').value  = stratId;
    document.getElementById('dhEditEnabled').value  = enabled ? '1' : '0';
    document.getElementById('dhEditMode').value     = mode || 'passive';
    document.getElementById('dhEditBudget').value   = budget;
    document.getElementById('dhEditLeverage').value = leverage;
    document.getElementById('dhEditEntryMode').value = entryMode || '';
    document.getElementById('dhEditMaxPos').value   = maxPos;
    document.getElementById('dhEditModal').style.display = 'flex';
}
function dhCloseEdit() {
    document.getElementById('dhEditModal').style.display = 'none';
}
document.getElementById('dhEditModal').addEventListener('click', function(e) {
    if (e.target === this) dhCloseEdit();
});
</script>
HTML;
}

// ──────────────────────────────────────────────────────────────────────────────
// POST handler: save operator overrides for a single strategy
// Registered as: POST /admin/dashboard/overrides/save
// ──────────────────────────────────────────────────────────────────────────────
function handleDashboardOverridesSave(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $stratId = trim((string)($_POST['strategy_id'] ?? ''));
    if ($stratId === '') {
        $_SESSION['dashboard_flash'] = ['type' => 'error', 'msg' => 'strategy_id не указан'];
        header('Location: ' . System::web('admin/dashboard'));
        exit;
    }

    $storageDir    = System::path('root') . '/modules/bot/storage';
    $overridesFile = $storageDir . '/operator_overrides.json';

    $overrides = [];
    if (file_exists($overridesFile)) {
        $raw = file_get_contents($overridesFile);
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $overrides = $decoded;
            }
        }
    }

    $enabled   = (int)($_POST['enabled']               ?? 1);
    $mode      = trim((string)($_POST['mode']           ?? 'passive'));
    $budget    = (float)($_POST['bot_budget']           ?? 0.0);
    $leverage  = (int)($_POST['bot_leverage']           ?? 0);
    $entryMode = trim((string)($_POST['entry_mode']     ?? ''));
    $maxPos    = (int)($_POST['max_active_positions']   ?? 0);

    if (!in_array($entryMode, ['limit', 'market'], true)) {
        $entryMode = null;
    }
    if (!in_array($mode, ['passive', 'active', 'disabled'], true)) {
        $mode = 'passive';
    }

    $prev = (array)($overrides[$stratId] ?? []);
    $overrides[$stratId] = array_merge($prev, [
        'enabled'              => (bool)$enabled,
        'mode'                 => $mode,
        'bot_budget'           => $budget,
        'bot_leverage'         => $leverage,
        'entry_mode'           => $entryMode,
        'stop_preset'          => $prev['stop_preset']  ?? null,
        'exit_preset'          => $prev['exit_preset']  ?? null,
        'max_active_positions' => $maxPos,
    ]);

    if (!is_dir($storageDir)) {
        mkdir($storageDir, 0755, true);
    }
    file_put_contents($overridesFile, json_encode($overrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $_SESSION['dashboard_flash'] = ['type' => 'success', 'msg' => "Настройки стратегии «{$stratId}» сохранены"];
    header('Location: ' . System::web('admin/dashboard'));
    exit;
}

} // end if (!function_exists('renderDashboardHub'))

// ──────────────────────────────────────────────────────────────────────────────
// POST handler: save global bot config
// Registered as: POST /admin/dashboard/global/save
// Writes operator-controlled keys to modules/bot/config/active.php
// ──────────────────────────────────────────────────────────────────────────────
if (!function_exists('handleDashboardGlobalSave')) {
function handleDashboardGlobalSave(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $root       = System::path('root');
    $activeFile = $root . '/modules/bot/config/active.php';

    // Read current active overrides so we don't clobber keys we don't manage
    $current = [];
    if (file_exists($activeFile)) {
        $loaded = @include $activeFile;
        if (is_array($loaded)) {
            $current = $loaded;
        }
    }

    $enabled   = (int)($_POST['enabled']    ?? 0);
    $mode      = trim((string)($_POST['mode'] ?? 'passive'));
    $defEntry  = trim((string)($_POST['default_entry_mode']          ?? ''));
    $budget    = (float)($_POST['max_bot_budget']                    ?? 0.0);
    $leverage  = (int)($_POST['max_bot_leverage']                    ?? 0);
    $defMaxPos = (int)($_POST['default_max_active_positions']        ?? 0);

    if (!in_array($mode, ['passive', 'active', 'disabled'], true)) {
        $mode = 'passive';
    }
    if (!in_array($defEntry, ['limit', 'market'], true)) {
        $defEntry = '';
    }

    $current['enabled']                       = (bool)$enabled;
    $current['mode']                          = $mode;
    $current['max_bot_budget']                = $budget;
    $current['max_bot_leverage']              = $leverage;
    $current['default_entry_mode']            = $defEntry !== '' ? $defEntry : null;
    $current['default_max_active_positions']  = $defMaxPos;

    // Serialise as PHP array file
    $php  = "<?php\n\ndeclare(strict_types=1);\n\n/**\n * Bot Module — Active Config Overrides\n * Written by the admin UI. Edit via the config page.\n */\n\nreturn ";
    $php .= var_export($current, true);
    $php .= ";\n";

    if (!is_dir(dirname($activeFile))) {
        mkdir(dirname($activeFile), 0755, true);
    }
    file_put_contents($activeFile, $php);

    $_SESSION['dashboard_flash'] = ['type' => 'success', 'msg' => 'Глобальные настройки бота сохранены'];
    header('Location: ' . System::web('admin/dashboard') . '#dh-ctrl');
    exit;
}
} // end if (!function_exists('handleDashboardGlobalSave'))
