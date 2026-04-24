<?php

declare(strict_types=1);

/**
 * Stop Manager Module — Admin Controller
 *
 * Operator management surface for the Stop Manager module.
 * Allows the operator to:
 *   - see current runtime / last-run state
 *   - view active stops and recent action log
 *   - edit compact config
 *   - run a manual tick
 *
 * Routes handled (registered in public/index.php):
 *   GET  /admin/stop-manager           → dashboard
 *   POST /admin/stop-manager/tick      → manual tick
 *   POST /admin/stop-manager/config/save → save config
 */

namespace Modules\StopManager\Admin;

use Core\System\System;
use Core\System\SystemPaths;
use Core\Auth\Auth;

final class StopManagerAdminController
{
    private static ?self $instance = null;
    private string $moduleDir;

    private function __construct()
    {
        $this->moduleDir = rtrim(
            SystemPaths::instance()->get('stop_manager.stop_manager'),
            '/'
        );
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // =========================================================================
    // Pages
    // =========================================================================

    public function dashboard(): string
    {
        $lastRun    = $this->readJson('storage/last_run.json', []);
        $stats      = $this->readJson('storage/stats.json', []);
        $stops      = $this->readJson('storage/stops.json', []);
        $config     = $this->loadConfig();

        // Recent action log — last 50 lines
        $actionLog = $this->readActionLog(50);

        $e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

        $tickUrl       = System::web('admin/stop-manager/tick');
        $configSaveUrl = System::web('admin/stop-manager/config/save');
        $selfUrl       = System::web('admin/stop-manager');

        // ── flash ─────────────────────────────────────────────────────────────
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $flash = null;
        if (!empty($_SESSION['sm_flash'])) {
            $flash = $_SESSION['sm_flash'];
            unset($_SESSION['sm_flash']);
        }
        $flashHtml = '';
        if ($flash) {
            $ftype = ($flash['type'] === 'success') ? 'success' : 'danger';
            $fbg   = $ftype === 'success' ? 'rgba(63,185,80,.12)' : 'rgba(248,81,73,.12)';
            $fclr  = $ftype === 'success' ? '#3fb950' : '#f85149';
            $fmsg  = $e($flash['msg'] ?? '');
            $flashHtml = <<<HTML
<div style="background:{$fbg};border:1px solid {$fclr}55;border-radius:8px;padding:10px 16px;margin-bottom:16px;font-size:13px;color:{$fclr};">{$fmsg}</div>
HTML;
        }

        // ── runtime summary ───────────────────────────────────────────────────
        $smEnabled   = $e($config['enabled'] ? 'Да' : 'Нет');
        $smMode      = $e($config['mode']      ?? 'disabled');
        $smStopMode  = $e($config['stop_mode'] ?? 'entry_liq_percent');
        $smBuf       = $e($config['stop_from_liq_buffer_pct'] ?? 0.05);
        $smBeEn      = $e(($config['breakeven_enabled'] ?? false) ? 'Да' : 'Нет');
        $smBeTrig    = $e($config['breakeven_trigger_roi']     ?? 10.0);
        $smBeLock    = $e($config['breakeven_profit_lock_roi'] ?? 3.0);

        $lrStatus    = $e($lastRun['status']     ?? 'never_run');
        $lrTickAt    = $e($lastRun['tick_at']    ?? '—');
        $lrElapsed   = $e($lastRun['elapsed_sec'] ?? 0);
        $lrPosSeen   = $e($lastRun['positions_seen']              ?? 0);
        $lrRealLiq   = $e($lastRun['positions_with_real_liq']     ?? 0);
        $lrEstLiq    = $e($lastRun['positions_with_estimated_liq'] ?? 0);
        $lrInited    = $e($lastRun['stops_initialized']           ?? 0);
        $lrRecalc    = $e($lastRun['stops_recalculated']          ?? 0);
        $lrBe        = $e($lastRun['breakeven_applied']           ?? 0);
        $lrNoLiq     = $e($lastRun['positions_without_liq']       ?? 0);
        $lrActive    = $e($lastRun['stops_active_count']     ?? 0);
        $lrClosed    = $e($lastRun['stops_closed_reference'] ?? 0);
        $lrTicks     = $e($lastRun['ticks_total'] ?? 0);

        $statusColor = ($lastRun['status'] ?? '') === 'ok' ? '#3fb950' : '#8b949e';

        // ── stops table ───────────────────────────────────────────────────────
        $stopsRows = '';
        foreach (array_slice(array_reverse($stops), 0, 100) as $stop) {
            $stState     = (string)($stop['stop_state'] ?? 'unknown');
            $stColor     = match ($stState) {
                'active' => '#3fb950',
                'no_liq' => '#f0883e',
                'stale'  => '#8b949e',
                default  => '#c9d1d9',
            };
            $beApplied   = ($stop['breakeven_applied'] ?? false) ? '<span style="color:#58a6ff;">✓ BE</span>' : '';
            $stopsRows .= '<tr>'
                . '<td>' . $e($stop['symbol'] ?? '') . '</td>'
                . '<td>' . $e($stop['side'] ?? '') . '</td>'
                . '<td>' . $e($stop['entry_price'] ?? '') . '</td>'
                . '<td>' . $e($stop['liq_price'] ?? '—') . '</td>'
                . '<td>' . $e($stop['stop_price'] ?? '—') . '</td>'
                . '<td style="color:' . $stColor . ';">' . $e($stState) . '</td>'
                . '<td>' . $beApplied . '</td>'
                . '<td style="font-size:11px;">' . $e($stop['last_updated_at'] ?? '') . '</td>'
                . '<td style="font-size:11px;color:var(--ui-text-muted);">' . $e($stop['transition_reason'] ?? '') . '</td>'
                . '</tr>';
        }
        if ($stopsRows === '') {
            $stopsRows = '<tr><td colspan="9" style="color:var(--ui-text-muted);padding:12px 0;text-align:center;">Нет активных стопов</td></tr>';
        }
        $stopsCount = count($stops);

        // ── action log ────────────────────────────────────────────────────────
        $logRows = '';
        foreach ($actionLog as $entry) {
            $evType  = (string)($entry['event_type'] ?? 'unknown');
            $evColor = match ($evType) {
                'stop_initialized'               => '#3fb950',
                'stop_recalculated'              => '#58a6ff',
                'breakeven_applied'              => '#a78bfa',
                'stop_position_closed_reference' => '#8b949e',
                default                          => '#c9d1d9',
            };
            $logRows .= '<tr>'
                . '<td style="font-size:11px;">' . $e($entry['timestamp'] ?? '') . '</td>'
                . '<td style="color:' . $evColor . ';white-space:nowrap;">' . $e($evType) . '</td>'
                . '<td>' . $e($entry['symbol'] ?? '') . '</td>'
                . '<td>' . $e($entry['side'] ?? '') . '</td>'
                . '<td>' . $e($entry['stop_price'] ?? '—') . '</td>'
                . '<td style="font-size:11px;color:var(--ui-text-muted);">' . $e($entry['reason'] ?? '') . '</td>'
                . '</tr>';
        }
        if ($logRows === '') {
            $logRows = '<tr><td colspan="6" style="color:var(--ui-text-muted);padding:12px 0;text-align:center;">Лог пуст</td></tr>';
        }

        // ── cumulative stats ──────────────────────────────────────────────────
        $statsRows = '';
        $statLabels = [
            'ticks_total'                      => 'Тиков всего',
            'positions_seen_total'             => 'Позиций просмотрено',
            'positions_with_real_liq_total'    => 'Позиций с реальным liq',
            'positions_with_estimated_liq_total' => 'Позиций с расчётным liq',
            'positions_without_liq_total'      => 'Позиций без liq_price',
            'stops_initialized_total'          => 'Стопов инициализировано',
            'stops_recalculated_total'         => 'Стопов пересчитано',
            'breakeven_applied_total'          => 'Breakeven применено',
            'stops_closed_reference_total'     => 'Закрыто (reference)',
            'stops_active_total'               => 'Активных стопов (сейчас)',
        ];
        foreach ($statLabels as $key => $label) {
            $val = $stats[$key] ?? 0;
            $statsRows .= '<tr><td style="color:var(--ui-text-muted);">' . $e($label) . '</td><td><strong>' . $e($val) . '</strong></td></tr>';
        }

        // ── config form values ────────────────────────────────────────────────
        $cfgEnYes  = $config['enabled'] ? ' selected' : '';
        $cfgEnNo   = !$config['enabled'] ? ' selected' : '';
        $cfgModeD  = ($config['mode'] ?? '') === 'disabled' ? ' selected' : '';
        $cfgModeP  = ($config['mode'] ?? '') === 'paper'    ? ' selected' : '';
        $cfgBeEnYes = ($config['breakeven_enabled'] ?? false) ? ' selected' : '';
        $cfgBeEnNo  = !($config['breakeven_enabled'] ?? false) ? ' selected' : '';

        return <<<HTML
<style>
.sm-tab-nav{display:flex;gap:0;border-bottom:1px solid var(--ui-border);margin-bottom:20px;}
.sm-tab-btn{background:none;border:none;border-bottom:2px solid transparent;padding:10px 22px;color:var(--ui-text-muted);cursor:pointer;font-size:14px;transition:color .15s,border-color .15s;outline:none;}
.sm-tab-btn:hover{color:var(--ui-text);border-bottom-color:var(--ui-border);}
.sm-tab-btn.sm-active{color:var(--ui-accent);border-bottom-color:var(--ui-accent);font-weight:600;}
.sm-pane{display:none;}
.sm-pane.sm-visible{display:block;}
</style>

<div style="max-width:1200px;">
{$flashHtml}
<div style="margin-bottom:16px;">
  <h4 style="margin:0 0 2px;"><i class="bi bi-shield-exclamation" style="margin-right:8px;"></i>Stop Manager</h4>
  <div style="font-size:12px;color:var(--ui-text-muted);">Paper / local · Единственный владелец стоп-лосс расчётов</div>
</div>

<nav class="sm-tab-nav" role="tablist">
  <button class="sm-tab-btn sm-active" onclick="smTab(this,'sm-runtime')" type="button">
    <i class="bi bi-activity" style="margin-right:5px;"></i>Runtime
  </button>
  <button class="sm-tab-btn" onclick="smTab(this,'sm-stops')" type="button">
    <i class="bi bi-list-ul" style="margin-right:5px;"></i>Стопы ({$e($stopsCount)})
  </button>
  <button class="sm-tab-btn" onclick="smTab(this,'sm-log')" type="button">
    <i class="bi bi-journal-text" style="margin-right:5px;"></i>Лог действий
  </button>
  <button class="sm-tab-btn" onclick="smTab(this,'sm-config')" type="button">
    <i class="bi bi-sliders" style="margin-right:5px;"></i>Конфиг
  </button>
  <button class="sm-tab-btn" onclick="smTab(this,'sm-stats')" type="button">
    <i class="bi bi-bar-chart" style="margin-right:5px;"></i>Статистика
  </button>
</nav>

<!-- Runtime pane -->
<div id="sm-runtime" class="sm-pane sm-visible">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
    <div class="card">
      <div class="card-header">Состояние модуля</div>
      <div class="card-body">
        <table style="width:100%;font-size:13px;border-collapse:collapse;">
          <tr><td style="color:var(--ui-text-muted);width:180px;padding:3px 12px 3px 0;">Включён</td><td>{$smEnabled}</td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Режим</td><td><code>{$smMode}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Stop mode</td><td><code>{$smStopMode}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Буфер от liq (%)</td><td><code>{$smBuf}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Breakeven</td><td>{$smBeEn}</td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">BE trigger ROI%</td><td><code>{$smBeTrig}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">BE lock ROI%</td><td><code>{$smBeLock}</code></td></tr>
        </table>
      </div>
    </div>
    <div class="card">
      <div class="card-header">Последний тик</div>
      <div class="card-body">
        <table style="width:100%;font-size:13px;border-collapse:collapse;">
          <tr><td style="color:var(--ui-text-muted);width:180px;padding:3px 12px 3px 0;">Статус</td><td style="color:{$statusColor};"><strong>{$lrStatus}</strong></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Время тика</td><td><code>{$lrTickAt}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Длительность</td><td><code>{$lrElapsed}s</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Позиций увидено</td><td><code>{$lrPosSeen}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">С реальным liq</td><td><code>{$lrRealLiq}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">С расчётным liq</td><td><code>{$lrEstLiq}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Стопов инициализировано</td><td><code>{$lrInited}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Стопов пересчитано</td><td><code>{$lrRecalc}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Breakeven применено</td><td><code>{$lrBe}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Без liq_price</td><td><code>{$lrNoLiq}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Активных стопов</td><td><code>{$lrActive}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Closed reference</td><td><code>{$lrClosed}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Тиков всего</td><td><code>{$lrTicks}</code></td></tr>
        </table>
      </div>
    </div>
  </div>
  <div class="card">
    <div class="card-header">Ручное управление</div>
    <div class="card-body">
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <form method="post" action="{$tickUrl}" style="margin:0;">
          <input type="hidden" name="action" value="tick">
          <button type="submit" class="btn btn-sm" style="background:rgba(63,185,80,.12);color:#3fb950;border:1px solid #3fb95055;padding:6px 18px;">
            <i class="bi bi-play-fill" style="margin-right:4px;"></i>Тик стоп-менеджера
          </button>
        </form>
        <form method="post" action="{$tickUrl}" style="margin:0;">
          <input type="hidden" name="action" value="refresh">
          <button type="submit" class="btn btn-sm" style="background:rgba(139,148,158,.12);color:#8b949e;border:1px solid #8b949e55;padding:6px 18px;">
            <i class="bi bi-arrow-clockwise" style="margin-right:4px;"></i>Обновить runtime
          </button>
        </form>
      </div>
      <div style="margin-top:10px;font-size:11px;color:var(--ui-text-muted);">
        «Тик стоп-менеджера» читает active_positions.json бота, пересчитывает стоп-прайсы, применяет breakeven и записывает stops.json. Биржевого взаимодействия нет.
      </div>
    </div>
  </div>
</div>

<!-- Stops pane -->
<div id="sm-stops" class="sm-pane">
  <div class="card">
    <div class="card-header">Текущие стопы (stops.json)</div>
    <div class="card-body" style="padding:0;overflow-x:auto;">
      <table class="table" style="margin:0;font-size:12px;">
        <thead>
          <tr>
            <th>Symbol</th><th>Side</th><th>Entry</th><th>Liq</th>
            <th>Stop</th><th>State</th><th>BE</th><th>Updated</th><th>Reason</th>
          </tr>
        </thead>
        <tbody>{$stopsRows}</tbody>
      </table>
    </div>
  </div>
</div>

<!-- Log pane -->
<div id="sm-log" class="sm-pane">
  <div class="card">
    <div class="card-header">Лог действий (последние 50 событий)</div>
    <div class="card-body" style="padding:0;overflow-x:auto;">
      <table class="table" style="margin:0;font-size:12px;">
        <thead>
          <tr>
            <th>Timestamp</th><th>Event</th><th>Symbol</th><th>Side</th><th>Stop</th><th>Reason</th>
          </tr>
        </thead>
        <tbody>{$logRows}</tbody>
      </table>
    </div>
  </div>
</div>

<!-- Config pane -->
<div id="sm-config" class="sm-pane">
  <div class="card">
    <div class="card-header">Конфигурация</div>
    <div class="card-body">
      <form method="post" action="{$configSaveUrl}">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px 16px;margin-bottom:14px;">
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Включён</label>
            <select name="enabled" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
              <option value="1"{$cfgEnYes}>Да</option>
              <option value="0"{$cfgEnNo}>Нет</option>
            </select>
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Режим</label>
            <select name="mode" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
              <option value="disabled"{$cfgModeD}>disabled</option>
              <option value="paper"{$cfgModeP}>paper</option>
            </select>
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Stop mode</label>
            <select name="stop_mode" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
              <option value="entry_liq_percent" selected>entry_liq_percent</option>
            </select>
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Буфер от liq (доля 0–1)</label>
            <input type="number" step="0.001" min="0" max="1" name="stop_from_liq_buffer_pct"
              value="{$e($config['stop_from_liq_buffer_pct'] ?? 0.05)}"
              class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Breakeven включён</label>
            <select name="breakeven_enabled" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
              <option value="1"{$cfgBeEnYes}>Да</option>
              <option value="0"{$cfgBeEnNo}>Нет</option>
            </select>
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">BE trigger ROI%</label>
            <input type="number" step="0.1" min="0" name="breakeven_trigger_roi"
              value="{$e($config['breakeven_trigger_roi'] ?? 10.0)}"
              class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">BE lock ROI%</label>
            <input type="number" step="0.1" name="breakeven_profit_lock_roi"
              value="{$e($config['breakeven_profit_lock_roi'] ?? 3.0)}"
              class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
          </div>
        </div>
        <button type="submit" class="btn btn-sm btn-primary">Сохранить</button>
        <div style="margin-top:10px;font-size:11px;color:var(--ui-text-muted);">
          Изменения записываются в <code>config/active.php</code>. Источник истины для dashboard — тот же файл.
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Stats pane -->
<div id="sm-stats" class="sm-pane">
  <div class="card">
    <div class="card-header">Накопленная статистика (stats.json)</div>
    <div class="card-body" style="padding:0;">
      <table class="table" style="margin:0;">
        {$statsRows}
      </table>
    </div>
  </div>
</div>

</div><!-- /max-width -->

<script>
function smTab(btn, panelId) {
    document.querySelectorAll('.sm-tab-btn').forEach(function(b){ b.classList.remove('sm-active'); });
    document.querySelectorAll('.sm-pane').forEach(function(p){ p.classList.remove('sm-visible'); });
    btn.classList.add('sm-active');
    var panel = document.getElementById(panelId);
    if (panel) { panel.classList.add('sm-visible'); }
}
</script>
HTML;
    }

    // =========================================================================
    // POST handlers
    // =========================================================================

    public function tick(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!Auth::check()) {
            http_response_code(403);
            exit;
        }

        $action  = trim((string)($_POST['action'] ?? 'tick'));
        $selfUrl = System::web('admin/stop-manager');

        if ($action === 'refresh') {
            $_SESSION['sm_flash'] = ['type' => 'success', 'msg' => 'Stop Manager runtime обновлён'];
            header('Location: ' . $selfUrl);
            exit;
        }

        // action === 'tick'
        try {
            require_once $this->moduleDir . '/bootstrap.php';
            require_once $this->moduleDir . '/service.php';
            $service = \Modules\StopManager\StopManagerService::instance($this->moduleDir);
            $service->tick();
            $_SESSION['sm_flash'] = ['type' => 'success', 'msg' => 'Stop Manager tick выполнен'];
        } catch (\Throwable $ex) {
            $_SESSION['sm_flash'] = ['type' => 'error', 'msg' => 'Ошибка Stop Manager tick: ' . $ex->getMessage()];
        }

        header('Location: ' . $selfUrl);
        exit;
    }

    public function configSave(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!Auth::check()) {
            http_response_code(403);
            exit;
        }

        $validTabs = ['dh-overview', 'dh-strat', 'dh-bot', 'dh-sm', 'dh-pm', 'dh-ctrl'];
        $postTab   = trim((string)($_POST['active_tab'] ?? ''));
        $fromDash  = in_array($postTab, $validTabs, true);
        $selfUrl   = $fromDash
            ? System::web('admin/dashboard') . '?tab=' . $postTab
            : System::web('admin/stop-manager');

        try {
            $active = [
                'enabled'                   => (bool)(int)($_POST['enabled']                   ?? 0),
                'mode'                      => in_array($_POST['mode'] ?? '', ['disabled', 'paper', 'demo'], true)
                    ? (string)$_POST['mode']
                    : 'disabled',
                'stop_mode'                 => 'entry_liq_percent',
                'stop_from_liq_buffer_pct'  => max(0.0, min(1.0, (float)($_POST['stop_from_liq_buffer_pct'] ?? 0.05))),
                'breakeven_enabled'         => (bool)(int)($_POST['breakeven_enabled']         ?? 0),
                'breakeven_trigger_roi'     => max(0.0, (float)($_POST['breakeven_trigger_roi']     ?? 10.0)),
                'breakeven_profit_lock_roi' => (float)($_POST['breakeven_profit_lock_roi'] ?? 3.0),
            ];

            $this->writeActive($active);
            $flashKey = $fromDash ? 'dashboard_flash' : 'sm_flash';
            $_SESSION[$flashKey] = ['type' => 'success', 'msg' => 'Конфигурация сохранена'];
        } catch (\Throwable $ex) {
            $flashKey = $fromDash ? 'dashboard_flash' : 'sm_flash';
            $_SESSION[$flashKey] = ['type' => 'error', 'msg' => 'Ошибка сохранения: ' . $ex->getMessage()];
        }

        header('Location: ' . $selfUrl);
        exit;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function loadConfig(): array
    {
        try {
            require_once $this->moduleDir . '/bootstrap.php';
            return \Modules\StopManager\StopManagerBootstrap::instance($this->moduleDir)->load()['config'];
        } catch (\Throwable) {
            return [];
        }
    }

    private function readJson(string $relPath, mixed $default = null): mixed
    {
        $path = $this->moduleDir . '/' . $relPath;
        if (!file_exists($path)) {
            return $default;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        return ($decoded !== null) ? $decoded : $default;
    }

    private function readActionLog(int $lines = 50): array
    {
        $path = $this->moduleDir . '/storage/actions_log.ndjson';
        if (!file_exists($path)) {
            return [];
        }
        $raw = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($raw === false || $raw === []) {
            return [];
        }
        $recent = array_slice($raw, -$lines);
        $result = [];
        foreach (array_reverse($recent) as $line) {
            $entry = json_decode(trim($line), true);
            if (is_array($entry)) {
                $result[] = $entry;
            }
        }
        return $result;
    }

    /**
     * Write operator overrides to config/active.php.
     * Only keys in $values are written; base config defaults are preserved
     * because bootstrap merges base + active.
     */
    private function writeActive(array $values): void
    {
        $path = $this->moduleDir . '/config/active.php';
        $php  = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "/**\n * Stop Manager Module — Active Config Overrides\n"
            . " * Written by the admin UI. Edit via the config page.\n */\n\n"
            . 'return ' . var_export($values, true) . ";\n";
        file_put_contents($path, $php);
    }
}
