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

    // ── stop_manager config + runtime ────────────────────────────────────
    $smDir     = System::path('root') . '/modules/stop_manager';
    $smStorage = $smDir . '/storage';
    $readSmJson = static function (string $file, mixed $default = []) use ($smStorage): mixed {
        $path = $smStorage . '/' . $file;
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
    $smLastRun = $readSmJson('last_run.json', []);
    $smStats   = $readSmJson('stats.json',   []);
    $smStops   = $readSmJson('stops.json',   []);
    $smConfig  = [];
    try {
        $smBase   = is_file($smDir . '/config/base.php')   ? (require $smDir . '/config/base.php')   : [];
        $smActive = is_file($smDir . '/config/active.php') ? (require $smDir . '/config/active.php') : [];
        $smConfig = array_merge(is_array($smBase) ? $smBase : [], is_array($smActive) ? $smActive : []);
    } catch (\Throwable) {
        // keep empty
    }

    // ── prof_manager runtime data ─────────────────────────────────────────
    $pmModuleDir = System::path('root') . '/modules/prof_manager';
    $pmStatus = [];
    try {
        if (is_file($pmModuleDir . '/service.php')) {
            if (is_file($pmModuleDir . '/bootstrap.php')) {
                require_once $pmModuleDir . '/bootstrap.php';
            }
            require_once $pmModuleDir . '/service.php';
            $pmSvc    = new \Modules\ProfManager\ProfManagerService($pmModuleDir);
            $pmStatus = $pmSvc->getStatus();
        }
    } catch (\Throwable) {
        // keep empty status
    }

    $pmEnabled       = ($pmStatus['enabled']          ?? false) ? 'Вкл' : 'Выкл';
    $pmEnabledBool   = ($pmStatus['enabled']          ?? false);
    $pmMode          = (string) ($pmStatus['mode']          ?? 'paper');
    $pmProfile       = (string) ($pmStatus['active_profile'] ?? 'legacy_safe');
    $pmLastTick      = (string) ($pmStatus['last_tick']      ?? '—');
    $pmPosTracked    = (int)    ($pmStatus['positions_tracked'] ?? 0);
    $pmLocksActive   = (int)    ($pmStatus['locks_active']   ?? 0);
    $pmPlanned       = (int)    ($pmStatus['planned_updates'] ?? 0);
    $pmSkipped       = (int)    ($pmStatus['skipped']        ?? 0);
    $pmLastErrorRaw  = $pmStatus['last_error'] ?? '';
    $pmLastError     = is_array($pmLastErrorRaw)
        ? implode(', ', array_filter(array_map('strval', $pmLastErrorRaw)))
        : (string)$pmLastErrorRaw;
    $pmCronInterval  = (int)    ($pmStatus['cron_interval_sec'] ?? 60);
    $pmCronConfigured = (bool)  ($pmStatus['cron_configured'] ?? false);
    $pmCronStatusText = $pmCronConfigured ? 'Настроен ✓' : 'Токен не задан';
    $pmCronPath      = 'public/cron/prof_manager.php';

    // ── PM historical error log presence check ────────────────────────────
    $pmHasHistoricalErrors = false;
    $pmErrLogPath = $pmModuleDir . '/storage/logs/error.log';
    if (is_file($pmErrLogPath) && @filesize($pmErrLogPath) > 0) {
        $pmHasHistoricalErrors = true;
    }

    $pmToggleTarget  = $pmEnabledBool ? '0' : '1';
    $pmToggleLabel   = $pmEnabledBool ? 'Выключить PM' : 'Включить PM';
    $pmToggleBg      = $pmEnabledBool ? 'rgba(248,81,73,.10)' : 'rgba(63,185,80,.10)';
    $pmToggleColor   = $pmEnabledBool ? '#f85149' : '#3fb950';

    $pmTickUrl         = System::web('admin/dashboard/profit-manager/tick');
    $pmToggleUrl       = System::web('admin/dashboard/profit-manager/toggle');
    $pmConfigSaveUrl   = System::web('admin/dashboard/profit-manager/config/save');

    // ── prof_manager config (for Управление form) ─────────────────────────
    $pmCfg = [];
    try {
        $pmCfgBase   = is_file($pmModuleDir . '/config/config.php')
            ? (require $pmModuleDir . '/config/config.php') : [];
        $pmCfgActive = is_file($pmModuleDir . '/config/active.php')
            ? (require $pmModuleDir . '/config/active.php') : [];
        $pmCfg = array_merge(
            is_array($pmCfgBase)   ? $pmCfgBase   : [],
            is_array($pmCfgActive) ? $pmCfgActive : []
        );
    } catch (\Throwable) {}

    $pmCfgProfile   = (string) ($pmCfg['active_profile'] ?? 'legacy_safe');
    $pmCfgProfileCfg = $pmCfg['profiles'][$pmCfgProfile] ?? [];

    $pmCfgEnYes = ($pmCfg['enabled'] ?? false) ? ' selected' : '';
    $pmCfgEnNo  = !($pmCfg['enabled'] ?? false) ? ' selected' : '';

    $pmCfgInitRoi      = (string) ($pmCfgProfileCfg['init_roi']               ?? 2.0);
    $pmCfgActivRoi     = (string) ($pmCfgProfileCfg['activation_roi']         ?? 10.0);
    $pmCfgStepRoi      = (string) ($pmCfgProfileCfg['step_roi']               ?? 3.0);
    $pmCfgLockBuf      = (string) ($pmCfgProfileCfg['lock_buffer_roi']        ?? 2.0);
    $pmCfgLockFloor    = (string) ($pmCfgProfileCfg['lock_floor_roi']         ?? 5.0);
    $pmCfgMinInterval  = (string) ($pmCfgProfileCfg['min_update_interval_sec']?? 30);
    $pmCfgMinPriceDist = (string) ($pmCfgProfileCfg['min_price_distance_pct'] ?? 0.15);
    $pmCfgMinRoiStep   = (string) ($pmCfgProfileCfg['min_roi_step']           ?? 1.0);
    $pmCfgMaxUpdates   = (string) ($pmCfgProfileCfg['max_updates_per_run']    ?? 20);
    $pmCfgTickSize     = (string) ($pmCfgProfileCfg['default_tick_size']      ?? 0.0001);

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
    // Fallback: scan strategy module manifests if bot hasn't run yet.
    // Scans two levels deep (e.g. modules/strategy/fish/ and
    // modules/strategy/pattern/double_bottom_long/) so all concrete strategy
    // modules are discovered regardless of nesting.
    if (empty($registry)) {
        $stratRoot = System::path('root') . '/modules/strategy';
        $addFromManifest = static function (string $mfPath, string $relDir) use (&$registry): void {
            if (!file_exists($mfPath)) {
                return;
            }
            $mfRaw = file_get_contents($mfPath);
            $mf    = ($mfRaw !== false) ? json_decode($mfRaw, true) : null;
            if (!is_array($mf) || ($mf['category'] ?? '') !== 'strategy') {
                return;
            }
            $mfId = (string)($mf['name'] ?? '');
            if ($mfId === '') {
                return;
            }
            // Avoid duplicates
            foreach ($registry as $r) {
                if (($r['strategy_id'] ?? '') === $mfId) {
                    return;
                }
            }
            $registry[] = [
                'strategy_id'        => $mfId,
                'module_path'        => $relDir,
                'manifest_path'      => $relDir . '/manifest.json',
                'title'              => (string)($mf['title'] ?? $mfId),
                'category'           => 'strategy',
                'enabled_by_default' => (bool)($mf['enabled_by_default'] ?? true),
                'handoff_queue_path' => null,
                'supports_long'      => true,
                'supports_short'     => true,
                'status'             => 'discovered',
                'discovered_at'      => null,
            ];
        };
        if (is_dir($stratRoot)) {
            foreach (new \DirectoryIterator($stratRoot) as $entry) {
                if (!$entry->isDir() || $entry->isDot()) {
                    continue;
                }
                $relDir  = 'modules/strategy/' . $entry->getFilename();
                $absDir  = $entry->getPathname();
                // Try top-level manifest
                $addFromManifest($absDir . '/manifest.json', $relDir);
                // Try one level of subdirectories (e.g. pattern/double_bottom_long)
                foreach (new \DirectoryIterator($absDir) as $sub) {
                    if (!$sub->isDir() || $sub->isDot()) {
                        continue;
                    }
                    $addFromManifest(
                        $sub->getPathname() . '/manifest.json',
                        $relDir . '/' . $sub->getFilename()
                    );
                }
            }
        }
    }

    $totalStrat    = count($registry);
    $enabledStrat  = 0;
    $disabledStrat = 0;
    foreach ($registry as $r) {
        $rid = (string)($r['strategy_id'] ?? '');
        $op  = (array)($overrides[$rid] ?? []);
        // Use override if present; fall back to manifest's enabled_by_default; then true
        $isEnabled = array_key_exists('enabled', $op)
            ? (bool)$op['enabled']
            : (bool)($r['enabled_by_default'] ?? true);
        if ($isEnabled) {
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
    // $sigProc will be computed after $stratSignals is populated below

    // ── escape helper ─────────────────────────────────────────────────────
    $e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

    // ── per-strategy active signal counts from handoff queues ────────────
    // Also attempt direct last_run bot_handoff_ready_total per strategy as fallback
    $stratSignals = [];
    foreach ($registry as $rec) {
        $hqPath = $rec['handoff_queue_path'] ?? null;
        if ($hqPath !== null) {
            $hqAbs = System::path('root') . '/' . $hqPath;
            if (file_exists($hqAbs)) {
                $hqRaw  = file_get_contents($hqAbs);
                $hqData = ($hqRaw !== false) ? json_decode($hqRaw, true) : [];
                $active = 0;
                foreach ((array)$hqData as $sig) {
                    $hs = (string)($sig['handoff_status'] ?? '');
                    if ($hs === 'new' || $hs === 'refreshed') {
                        $active++;
                    }
                }
                $stratSignals[$rec['strategy_id']] = $active;
            }
        }
    }

    // $sigProc: sum of active handoff-ready signals across all strategies, with
    // fallback to the bot's last_run processed count when we have no strategy data.
    $sigProcFromStrats = array_sum($stratSignals);
    $sigProcFromBot    = (int)($lastRun['handoff_signals_processed'] ?? 0);
    $sigProc           = $sigProcFromStrats > 0 ? $sigProcFromStrats : $sigProcFromBot;

    // ── strategy cards HTML ───────────────────────────────────────────────
    $saveUrl      = System::web('admin/dashboard/overrides/save');
    $stratActUrl  = System::web('admin/dashboard/strategy/action');
    $stratToggleUrl = System::web('admin/dashboard/strategy/toggle');
    $botToggleUrl   = System::web('admin/dashboard/bot/toggle');
    $smToggleUrl    = System::web('admin/dashboard/stop-manager/toggle');
    $chainRunUrl    = System::web('admin/dashboard/chain-run');
    $resetRuntimeUrl = System::web('admin/dashboard/reset-runtime');
    $stratCards = '';
    if (empty($registry)) {
        $stratCards = '<p style="color:var(--ui-text-muted);padding:20px 0;">Стратегии не обнаружены.</p>';
    } else {
        foreach ($registry as $rec) {
            $stratId    = (string)($rec['strategy_id'] ?? '');
            $title      = (string)($rec['title']       ?? $stratId);
            $status     = (string)($rec['status']      ?? 'discovered');
            $modulePath = (string)($rec['module_path'] ?? '');
            $supLong    = $rec['supports_long']  ?? true;
            $supShort   = $rec['supports_short'] ?? true;
            $hasHandoff = ($rec['handoff_queue_path'] ?? null) !== null;
            $signalCount = $stratSignals[$stratId] ?? null;

            $op          = (array)($overrides[$stratId] ?? []);
            // Use stored override if present; fall back to manifest's enabled_by_default; then true
            $opEnabled   = array_key_exists('enabled', $op)
                ? (bool)$op['enabled']
                : (bool)($rec['enabled_by_default'] ?? true);
            // Mode: use stored override if present; fall back to manifest default_mode; then 'passive'
            $defaultMode = (string)($rec['default_mode'] ?? 'passive');
            $opMode      = array_key_exists('mode', $op)
                ? (string)$op['mode']
                : $defaultMode;

            $esId    = $e($stratId);
            $esTitle = $e($title);
            $esPath  = $e($modulePath);
            $esMode  = $e($opMode);

            // Status badge colour
            $statusColor = $status === 'bot_ready' ? '#3fb950' : '#8b949e';
            $statusLabel = $e($status);

            // Enabled badge
            $enBg    = $opEnabled ? 'rgba(63,185,80,.15)' : 'rgba(107,114,128,.15)';
            $enColor = $opEnabled ? '#3fb950' : '#8b949e';
            $enLabel = $opEnabled ? 'Включено' : 'Выключено';

            // Direction
            $dir = [];
            if ($supLong)  { $dir[] = 'Long'; }
            if ($supShort) { $dir[] = 'Short'; }
            $dirStr = $e(implode(' / ', $dir));

            // Handoff
            $handoffStr   = $hasHandoff ? '<span style="color:#3fb950;">Да</span>' : '<span style="color:#8b949e;">Нет</span>';
            $signalStr    = ($signalCount !== null) ? $e((string)$signalCount) : '—';

            // Options: mode select
            $modePassive  = $opMode === 'passive'  ? ' selected' : '';
            $modeActive   = $opMode === 'active'   ? ' selected' : '';
            $modeDisabled = $opMode === 'disabled' ? ' selected' : '';

            // Options: enabled select
            $enYes = $opEnabled ? ' selected' : '';
            $enNo  = $opEnabled ? '' : ' selected';

            // ── strategy run_state ────────────────────────────────────────
            $runStatePath = System::path('root') . '/' . $modulePath . '/storage/run_state.json';
            $runState     = [];
            if (file_exists($runStatePath)) {
                $rsRaw = file_get_contents($runStatePath);
                if ($rsRaw !== false) {
                    $rsDec = json_decode($rsRaw, true);
                    if (is_array($rsDec)) {
                        $runState = $rsDec;
                    }
                }
            }
            $rsStatus    = $e((string)($runState['status']       ?? 'idle'));
            $rsCursor    = (int)($runState['cursor']             ?? 0);
            $rsTotal     = (int)($runState['total']              ?? 0);
            $rsCycleId   = (int)($runState['cycle_id']           ?? 0);
            $rsLastTick  = $e((string)($runState['last_tick_at'] ?? '—'));

            // ── strategy last_run for handoff trace ───────────────────────
            $stratLastRunPath = System::path('root') . '/' . $modulePath . '/storage/last_run.json';
            $stratLastRun = [];
            if ($modulePath !== '' && file_exists($stratLastRunPath)) {
                $slrRaw = file_get_contents($stratLastRunPath);
                if ($slrRaw !== false) {
                    $slrDec = json_decode($slrRaw, true);
                    if (is_array($slrDec)) {
                        $stratLastRun = $slrDec;
                    }
                }
            }
            $slrStatus       = $e((string)($stratLastRun['status']                              ?? '—'));
            $slrCandidates   = (int)($stratLastRun['found']                                     ?? 0);
            $slrEmitted      = (int)($stratLastRun['current_cycle_signals_emitted_total']        ?? 0);
            $slrPoolTotal    = (int)($stratLastRun['active_pool_signals_total']                  ?? 0);
            $slrHandoffReady = (int)($stratLastRun['bot_handoff_ready_total']                    ?? $stratSignals[$stratId] ?? 0);
            $slrHasData      = $stratLastRun !== [];

            $cardId      = 'card-edit-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $stratId);

            // Quick toggle button: shows action to flip enabled state
            if ($opEnabled) {
                $toggleTarget = '0';
                $toggleLabel  = 'Выключить';
                $toggleBg     = 'rgba(248,81,73,.10)';
                $toggleColor  = '#f85149';
            } else {
                $toggleTarget = '1';
                $toggleLabel  = 'Включить';
                $toggleBg     = 'rgba(63,185,80,.10)';
                $toggleColor  = '#3fb950';
            }

            // Manual run action buttons — only active for strategies with a wired service.
            // All other strategies show disabled/unavailable buttons so the operator can see
            // the actions exist but are not yet supported for that module.
            $supportsManualRun = ($stratId === 'double_bottom_long');
            if ($supportsManualRun) {
                $actionButtonsHtml = <<<BTN
      <form method="post" action="{$stratActUrl}" style="margin:0;">
        <input type="hidden" name="strategy_id" value="{$esId}">
        <input type="hidden" name="action" value="queue_run">
        <button type="submit" class="btn btn-sm" style="background:rgba(63,185,80,.12);color:#3fb950;border:1px solid #3fb95055;">
          Запуск цикла
        </button>
      </form>
      <form method="post" action="{$stratActUrl}" style="margin:0;">
        <input type="hidden" name="strategy_id" value="{$esId}">
        <input type="hidden" name="action" value="tick_batch">
        <button type="submit" class="btn btn-sm" style="background:rgba(88,166,255,.12);color:#58a6ff;border:1px solid #58a6ff55;">
          Тик батча
        </button>
      </form>
      <form method="post" action="{$stratActUrl}" style="margin:0;">
        <input type="hidden" name="strategy_id" value="{$esId}">
        <input type="hidden" name="action" value="refresh">
        <button type="submit" class="btn btn-sm" style="background:rgba(139,148,158,.12);color:#8b949e;border:1px solid #8b949e55;">
          Обновить runtime
        </button>
      </form>
BTN;
            } else {
                $actionButtonsHtml = <<<BTN
      <button type="button" disabled class="btn btn-sm" style="opacity:.4;cursor:not-allowed;border:1px solid var(--ui-border);color:var(--ui-text-muted);"
        title="Ручной запуск не поддерживается для этой стратегии">Запуск цикла</button>
      <button type="button" disabled class="btn btn-sm" style="opacity:.4;cursor:not-allowed;border:1px solid var(--ui-border);color:var(--ui-text-muted);"
        title="Ручной запуск не поддерживается для этой стратегии">Тик батча</button>
BTN;
            }

            $stratCards .= <<<HTML
<div class="card" style="margin-bottom:16px;">
  <!-- Card header -->
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <div>
      <strong style="font-size:15px;">{$esTitle}</strong>
      <code style="margin-left:8px;font-size:11px;color:#58a6ff;">{$esId}</code>
    </div>
    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
      <span style="background:{$enBg};color:{$enColor};border:1px solid {$enColor};font-size:11px;padding:2px 8px;border-radius:999px;">{$enLabel}</span>
      <span style="background:rgba(100,116,139,.12);color:{$statusColor};border:1px solid {$statusColor}55;font-size:11px;padding:2px 8px;border-radius:999px;">{$statusLabel}</span>
    </div>
  </div>
  <!-- Card info row -->
  <div class="card-body" style="padding:12px 16px;">
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
      <tr>
        <td style="padding:3px 12px 3px 0;color:var(--ui-text-muted);white-space:nowrap;">Модуль</td>
        <td style="padding:3px 0;"><code style="font-size:11px;">{$esPath}</code></td>
        <td style="padding:3px 12px 3px 16px;color:var(--ui-text-muted);white-space:nowrap;">Направление</td>
        <td style="padding:3px 0;">{$dirStr}</td>
      </tr>
      <tr>
        <td style="padding:3px 12px 3px 0;color:var(--ui-text-muted);white-space:nowrap;">Handoff</td>
        <td style="padding:3px 0;">{$handoffStr}</td>
        <td style="padding:3px 12px 3px 16px;color:var(--ui-text-muted);white-space:nowrap;">Сигналов</td>
        <td style="padding:3px 0;">{$signalStr}</td>
      </tr>
      <tr>
        <td style="padding:3px 12px 3px 0;color:var(--ui-text-muted);">Режим</td>
        <td colspan="3" style="padding:3px 0;"><code>{$esMode}</code></td>
      </tr>
      <tr>
        <td style="padding:3px 12px 3px 0;color:var(--ui-text-muted);white-space:nowrap;">Runtime</td>
        <td colspan="3" style="padding:3px 0;">
          <code style="font-size:11px;">{$rsStatus}</code>
          <span style="font-size:11px;color:var(--ui-text-muted);margin-left:8px;">{$rsCursor}/{$rsTotal} · цикл {$rsCycleId} · {$rsLastTick}</span>
        </td>
      </tr>
      <tr>
        <td style="padding:3px 12px 3px 0;color:var(--ui-text-muted);white-space:nowrap;">Цикл</td>
        <td colspan="3" style="padding:3px 0;font-size:11px;">
          статус <code>{$slrStatus}</code>
          · кандидатов <code>{$slrCandidates}</code>
          · сигналов <code>{$slrEmitted}</code>
          · активных <code>{$slrPoolTotal}</code>
          · handoff-ready <code>{$slrHandoffReady}</code>
        </td>
      </tr>
    </table>

    <!-- Toggle edit / manual action buttons -->
    <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <!-- Quick enable/disable toggle -->
      <form method="post" action="{$stratToggleUrl}" style="margin:0;">
        <input type="hidden" name="strategy_id" value="{$esId}">
        <input type="hidden" name="enabled" value="{$toggleTarget}">
        <button type="submit" class="btn btn-sm" style="background:{$toggleBg};color:{$toggleColor};border:1px solid {$toggleColor}55;font-weight:600;">
          {$toggleLabel}
        </button>
      </form>
      <button type="button" class="btn btn-sm btn-primary" onclick="dhToggleEdit('{$cardId}')">
        Изменить
      </button>
      {$actionButtonsHtml}
    </div>

    <!-- Inline edit form (hidden by default) -->
    <div id="{$cardId}" style="display:none;margin-top:14px;padding-top:14px;border-top:1px solid var(--ui-border);">
      <form method="post" action="{$saveUrl}">
        <input type="hidden" name="strategy_id" value="{$esId}">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 16px;margin-bottom:12px;">
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Включено</label>
            <select name="enabled" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
              <option value="1"{$enYes}>Да</option>
              <option value="0"{$enNo}>Нет</option>
            </select>
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Режим</label>
            <select name="mode" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
              <option value="passive"{$modePassive}>passive</option>
              <option value="active"{$modeActive}>active</option>
              <option value="disabled"{$modeDisabled}>disabled</option>
            </select>
          </div>
        </div>
        <div style="display:flex;gap:8px;">
          <button type="submit" class="btn btn-sm btn-primary">Сохранить</button>
          <button type="button" class="btn btn-sm" style="background:transparent;border:1px solid var(--ui-border);color:var(--ui-text-muted);"
            onclick="dhToggleEdit('{$cardId}')">Отмена</button>
        </div>
      </form>
    </div>
  </div><!-- /card-body -->
</div>
HTML;
        }
    }

    // ── Control tab: global bot config ───────────────────────────────────
    $cfgEnabled     = ($botConfig['enabled'] ?? false) ? '1' : '0';
    $cfgMode        = $e($botConfig['mode']              ?? 'passive');
    $cfgMaxBudget   = (float)($botConfig['max_bot_budget']   ?? 0.0);
    $cfgMaxLeverage = (int)($botConfig['max_bot_leverage']   ?? 0);
    $cfgDefEntry    = $e($botConfig['default_entry_mode']    ?? '');
    $cfgDefMaxPos   = (int)($botConfig['default_max_active_positions'] ?? 0);
    $cfgEntryModes  = $e(implode(', ', (array)($botConfig['allowed_entry_modes'] ?? [])));
    $cfgMaxAgeSec   = (int)($botConfig['max_signal_age_sec'] ?? 0);
    $cfgDedupWindow = (int)($botConfig['queue_dedup_ttl_sec'] ?? 0);
    $cfgScanRoots   = $e(implode(', ', (array)($botConfig['strategy_scan_roots'] ?? [])));

    // Bot quick-toggle values
    $botCurrentlyEnabled = ($botConfig['enabled'] ?? false);
    $botToggleTarget     = $botCurrentlyEnabled ? '0' : '1';
    $botToggleLabel      = $botCurrentlyEnabled ? 'Выключить бота' : 'Включить бота';
    $botToggleBg         = $botCurrentlyEnabled ? 'rgba(248,81,73,.10)' : 'rgba(63,185,80,.10)';
    $botToggleColor      = $botCurrentlyEnabled ? '#f85149' : '#3fb950';

    // SM quick-toggle values
    $smCurrentlyEnabled  = ($smConfig['enabled'] ?? false);
    $smToggleTarget      = $smCurrentlyEnabled ? '0' : '1';
    $smToggleLabel       = $smCurrentlyEnabled ? 'Выключить Stop Manager' : 'Включить Stop Manager';
    $smToggleBg          = $smCurrentlyEnabled ? 'rgba(248,81,73,.10)' : 'rgba(63,185,80,.10)';
    $smToggleColor       = $smCurrentlyEnabled ? '#f85149' : '#3fb950';

    $gcfgEnYes  = $cfgEnabled === '1' ? ' selected' : '';
    $gcfgEnNo   = $cfgEnabled === '0' ? ' selected' : '';
    $gcfgModeP  = $cfgMode === 'passive'  ? ' selected' : '';
    $gcfgModeA  = $cfgMode === 'active'   ? ' selected' : '';
    $gcfgModeD  = $cfgMode === 'disabled' ? ' selected' : '';
    $gcfgEntN   = $cfgDefEntry === ''       ? ' selected' : '';
    $gcfgEntL   = $cfgDefEntry === 'limit'  ? ' selected' : '';
    $gcfgEntM   = $cfgDefEntry === 'market' ? ' selected' : '';

    $globalSaveUrl = System::web('admin/dashboard/global/save');

    // ── Control tab: per-strategy mirrored overrides ──────────────────────
    $mirrorRows = '';
    foreach ($registry as $rec) {
        $sid    = (string)($rec['strategy_id'] ?? '');
        $stitle = $e($rec['title'] ?? $sid);
        $op     = (array)($overrides[$sid] ?? []);

        // Use same consistent logic as summary counts and strategy cards
        $mEnabled = array_key_exists('enabled', $op)
            ? (bool)$op['enabled']
            : (bool)($rec['enabled_by_default'] ?? true);
        $mDefaultMode = (string)($rec['default_mode'] ?? 'passive');
        $mMode = array_key_exists('mode', $op)
            ? (string)$op['mode']
            : $mDefaultMode;

        $enColor  = $mEnabled ? '#3fb950' : '#8b949e';
        $enLbl    = $mEnabled ? 'Включено' : 'Выключено';
        // Toggle points to the opposite state
        $toggleTo    = $mEnabled ? '0' : '1';
        $toggleLabel = $mEnabled ? 'Выкл' : 'Вкл';
        $toggleBg    = $mEnabled ? 'rgba(248,81,73,.10)' : 'rgba(63,185,80,.10)';
        $toggleClr   = $mEnabled ? '#f85149' : '#3fb950';
        $eSid = $e($sid);

        $mirrorRows .= '<tr>';
        $mirrorRows .= '<td><strong>' . $stitle . '</strong><br><code style="font-size:11px;color:#58a6ff;">' . $e($sid) . '</code></td>';
        $mirrorRows .= '<td style="color:' . $enColor . ';font-weight:600;">' . $enLbl . '</td>';
        $mirrorRows .= '<td><code>' . $e($mMode) . '</code></td>';
        $mirrorRows .= '<td>'
            . '<form method="post" action="' . $e($stratToggleUrl) . '" style="margin:0;">'
            . '<input type="hidden" name="strategy_id" value="' . $eSid . '">'
            . '<input type="hidden" name="enabled" value="' . $toggleTo . '">'
            . '<button type="submit" class="btn btn-sm" style="background:' . $toggleBg . ';color:' . $toggleClr . ';border:1px solid ' . $toggleClr . '55;font-size:11px;padding:2px 8px;">'
            . $toggleLabel . '</button>'
            . '</form>'
            . '</td>';
        $mirrorRows .= '</tr>';
    }
    if ($mirrorRows === '') {
        $mirrorRows = '<tr><td colspan="4" style="color:var(--ui-text-muted);padding:12px 0;">Нет данных. Стратегии ещё не обнаружены.</td></tr>';
    }

    // ── Bot tab: last-tick trace block ────────────────────────────────────
    $trSeenTotal   = (int)($lastRun['handoff_signals_processed']                    ?? 0);
    $trNew         = (int)($lastRun['order_queue_new_total']                        ?? 0);
    $trRefreshed   = (int)($lastRun['order_queue_refreshed_total']                  ?? 0);
    $trExpired     = (int)($lastRun['order_queue_expired_total']                    ?? 0);
    $trWithdrawn   = (int)($lastRun['order_queue_withdrawn_total']                  ?? 0);
    $trIgnDis      = (int)($lastRun['handoff_signals_ignored_disabled_strategy']    ?? 0);
    $trIgnPay      = (int)($lastRun['handoff_signals_ignored_invalid_payload']      ?? 0);
    $trIgnMode     = (int)($lastRun['handoff_signals_ignored_invalid_entry_mode']   ?? 0);
    $trQueueTotal  = (int)($lastRun['order_queue_total']                            ?? 0);

    $tickTraceRows = <<<ROWS
<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;white-space:nowrap;">Сигналов получено</td><td><strong>{$e($trSeenTotal)}</strong></td></tr>
<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">→ новых в очереди</td><td style="color:#3fb950;"><strong>{$e($trNew)}</strong></td></tr>
<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">→ обновлено</td><td style="color:#58a6ff;"><strong>{$e($trRefreshed)}</strong></td></tr>
<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">→ истекло (TTL)</td><td style="color:#f0883e;"><strong>{$e($trExpired)}</strong></td></tr>
<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">→ отозвано</td><td style="color:#8b949e;"><strong>{$e($trWithdrawn)}</strong></td></tr>
<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">проигнор.: стратегия выкл.</td><td style="color:#8b949e;">{$e($trIgnDis)}</td></tr>
<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">проигнор.: невалид. payload</td><td style="color:#8b949e;">{$e($trIgnPay)}</td></tr>
<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">проигнор.: режим входа</td><td style="color:#8b949e;">{$e($trIgnMode)}</td></tr>
<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;border-top:1px solid var(--ui-border);padding-top:6px;">Очередь всего (после тика)</td><td style="border-top:1px solid var(--ui-border);padding-top:6px;"><strong style="color:#f0883e;">{$e($trQueueTotal)}</strong></td></tr>
ROWS;

    // ── Bot tab: stats rows ───────────────────────────────────────────────
    $statsRows = '';
    $statLabels = [
        'ticks_total'                                     => 'Всего тиков',
        'strategies_discovered_total'                     => 'Стратегий обнаружено',
        'strategies_enabled_total'                        => 'Стратегий включено',
        'strategies_disabled_total'                       => 'Стратегий выключено',
        'handoff_signals_seen_total'                      => 'Handoff-сигналов получено',
        'handoff_signals_ignored_disabled_strategy_total' => 'Сигналов проигнорировано (выкл. стратегия)',
        'order_queue_total'                               => 'Очередь ордеров (всего активных)',
        'order_queue_new_total'                           => 'Ордеров поставлено в очередь',
        'order_queue_refreshed_total'                     => 'Ордеров обновлено',
        'order_queue_expired_total'                       => 'Ордеров истекло',
        'order_queue_withdrawn_total'                     => 'Ордеров отозвано',
        'active_orders_total'                             => 'Активных ордеров',
        'active_positions_total'                          => 'Активных позиций',
    ];
    foreach ($statLabels as $key => $label) {
        $val = $stats[$key] ?? 0;
        $statsRows .= '<tr><td style="color:var(--ui-text-muted);">' . $e($label) . '</td><td><strong>' . $e($val) . '</strong></td></tr>';
    }

    // ── flash HTML ────────────────────────────────────────────────────────
    $botTickUrl = System::web('admin/dashboard/bot/tick');
    $smTickUrl  = System::web('admin/dashboard/stop-manager/tick');
    $smPageUrl  = System::web('admin/stop-manager');

    // ── stop_manager display values ───────────────────────────────────────
    $smEnabled     = ($smConfig['enabled']          ?? false) ? 'Да' : 'Нет';
    $smMode        = (string)($smConfig['mode']      ?? 'disabled');
    $smStopMode    = (string)($smConfig['stop_mode'] ?? 'entry_liq_percent');
    $smBuf         = (string)($smConfig['stop_from_liq_buffer_pct'] ?? 0.05);
    $smBeEn        = ($smConfig['breakeven_enabled'] ?? false) ? 'Да' : 'Нет';
    $smBeTrig      = (string)($smConfig['breakeven_trigger_roi']     ?? 10.0);
    $smBeLock      = (string)($smConfig['breakeven_profit_lock_roi'] ?? 3.0);
    $smLastTick    = (string)($smLastRun['tick_at']                      ?? '—');
    $smLastStatus  = (string)($smLastRun['status']                       ?? 'never_run');
    $smPosSeen     = (string)($smLastRun['positions_seen']                ?? 0);
    $smEstLiq      = (string)($smLastRun['positions_with_estimated_liq']  ?? 0);
    $smNoLiq       = (string)($smLastRun['positions_without_liq']         ?? 0);
    $smActiveStops = (string)($smLastRun['stops_active_count']            ?? count($smStops));
    $smBeApplied   = (string)($smStats['breakeven_applied_total']         ?? 0);
    $smStatusColor = $smLastStatus === 'ok' ? '#3fb950' : '#8b949e';

    // ── stop_manager config for Control tab (mirrored) ───────────────────
    $smCfgEnYes = ($smConfig['enabled'] ?? false) ? ' selected' : '';
    $smCfgEnNo  = !($smConfig['enabled'] ?? false) ? ' selected' : '';
    $smCfgModeD = $smMode === 'disabled' ? ' selected' : '';
    $smCfgModeP = $smMode === 'paper'    ? ' selected' : '';
    $smCfgBeEnYes = ($smConfig['breakeven_enabled'] ?? false) ? ' selected' : '';
    $smCfgBeEnNo  = !($smConfig['breakeven_enabled'] ?? false) ? ' selected' : '';
    $smCfgBuf   = $e($smBuf);
    $smCfgTrig  = $e($smBeTrig);
    $smCfgLock  = $e($smBeLock);
    $smConfigSaveUrl = System::web('admin/stop-manager/config/save');

    // ── PM direct last_run.json read (for status chain + runtime note) ────
    $pmRawLastRun = [];
    $pmLastRunFile = $pmModuleDir . '/storage/runtime/last_run.json';
    if (is_file($pmLastRunFile)) {
        $raw = @file_get_contents($pmLastRunFile);
        if ($raw !== false) {
            $dec = @json_decode($raw, true);
            if (is_array($dec)) {
                $pmRawLastRun = $dec;
            }
        }
    }
    $pmRawSkipped = (string)($pmRawLastRun['skip_reason'] ?? '');
    // Fallback: if skip_reason is absent (old last_run.json), derive from legacy 'skipped' field
    if ($pmRawSkipped === '' && isset($pmRawLastRun['skipped'])) {
        $legacySkipped = $pmRawLastRun['skipped'];
        if (is_string($legacySkipped) && $legacySkipped !== '') {
            $pmRawSkipped = $legacySkipped;
        } elseif (is_array($legacySkipped) && count($legacySkipped) > 0) {
            $reasons = array_unique(array_filter(array_column($legacySkipped, 'reason')));
            $pmRawSkipped = implode(', ', $reasons);
        }
    }

    // ── PM diagnostic fields from last_run.json ───────────────────────────
    $pmDiagPositions   = isset($pmRawLastRun['positions'])               ? (int)$pmRawLastRun['positions']              : -1;
    $pmDiagValidPos    = isset($pmRawLastRun['valid_positions'])         ? (int)$pmRawLastRun['valid_positions']        : -1;
    $pmDiagInvalidPos  = isset($pmRawLastRun['invalid_positions'])       ? (int)$pmRawLastRun['invalid_positions']      : -1;
    $pmDiagPriceMiss   = isset($pmRawLastRun['price_missing_positions']) ? (int)$pmRawLastRun['price_missing_positions']: -1;
    $pmDiagSource      = (string)($pmRawLastRun['source'] ?? '');
    $pmDiagErrSumRaw   = $pmRawLastRun['validation_errors_summary'] ?? [];
    $pmDiagErrSum      = is_array($pmDiagErrSumRaw)
        ? implode(', ', $pmDiagErrSumRaw)
        : (string)$pmDiagErrSumRaw;
    $pmDiagWarnSumRaw  = $pmRawLastRun['warnings_summary'] ?? [];
    $pmDiagWarnSum     = is_array($pmDiagWarnSumRaw)
        ? implode(', ', $pmDiagWarnSumRaw)
        : (string)$pmDiagWarnSumRaw;
    $pmDiagEnrichment  = is_array($pmRawLastRun['enrichment_summary'] ?? null)
        ? $pmRawLastRun['enrichment_summary'] : [];
    $pmDiagPriceProvErr    = (string)($pmRawLastRun['price_provider_error']  ?? '');
    $pmDiagPriceProvSource = (string)($pmRawLastRun['price_provider_source'] ?? '');

    // ── PM per-position runtime rows (new diagnostics) ────────────────────
    $pmPositionsRuntime   = is_array($pmRawLastRun['positions_runtime']    ?? null)
        ? $pmRawLastRun['positions_runtime']    : [];
    $pmSkipReasonsSummary = is_array($pmRawLastRun['skip_reasons_summary'] ?? null)
        ? $pmRawLastRun['skip_reasons_summary'] : [];

    // Human-readable skip reason labels (Russian)
    $pmReasonLabels = [
        'below_init_roi'                  => 'ROI ниже порога инициализации PM',
        'below_activation_roi'            => 'ROI ниже порога активации lock',
        'lock_price_too_close_to_current' => 'Lock слишком близко к текущей цене',
        'lock_not_improving'              => 'Новый lock не улучшает старый',
        'no_price_data'                   => 'Нет текущей цены',
        'cannot_calculate_roi'            => 'Невозможно рассчитать ROI',
        'all_positions_invalid'           => 'Все позиции невалидны',
        'no_positions'                    => 'Нет позиций',
        'module_disabled'                 => 'Модуль отключён',
    ];

    // Normal paper-runtime skip conditions (WARN, not ERR)
    $pmNormalSkipReasons = [
        'below_init_roi', 'below_activation_roi',
        'lock_not_improving', 'lock_price_too_close_to_current',
        'lock_not_on_profit_side', 'roi_step_too_small',
        'update_interval_not_elapsed', 'planned',
    ];

    // ── PM cron task check ────────────────────────────────────────────────
    $pmCronTaskExists  = false;
    $pmCronTaskEnabled = false;
    $pmCronLastRunTs   = null;
    try {
        $cronMgr      = \Core\Cron\CronManager::instance();
        $allCronTasks = $cronMgr->getAllTasks();
        foreach ($allCronTasks as $tId => $tData) {
            if (($tData['module'] ?? '') === 'prof_manager') {
                $pmCronTaskExists  = true;
                $pmCronTaskEnabled = (bool)($tData['enabled'] ?? false);
                $pmCronLastRunTs   = isset($tData['last_run']) ? (int)$tData['last_run'] : null;
                break;
            }
        }
    } catch (\Throwable) {}

    // ── Status chain badge computation ────────────────────────────────────
    // helper: [color, bg]
    $scColor = static function (string $state): array {
        switch ($state) {
            case 'ON':   return ['#3fb950', 'rgba(63,185,80,.15)'];
            case 'WARN': return ['#f0883e', 'rgba(240,136,62,.15)'];
            case 'ERR':  return ['#f85149', 'rgba(248,81,73,.15)'];
            default:     return ['#8b949e', 'rgba(107,114,128,.12)'];
        }
    };

    // Strategy badge
    $scStratState  = ($enabledStrat > 0) ? 'ON' : 'OFF';
    $scStratReason = ($enabledStrat === 0) ? 'нет включённых стратегий' : '';

    // Bot badge
    $botLastError = (string)($lastRun['last_error'] ?? '');
    if ($botLastError !== '') {
        $scBotState  = 'ERR';
        $scBotReason = $botLastError;
    } elseif ($botCurrentlyEnabled) {
        if ($tickStatus === 'skipped') {
            $scBotState  = 'WARN';
            $scBotReason = 'tick skipped';
        } elseif ($posCount === 0 && $queueSize === 0 && $tickStatus !== 'never_run') {
            $scBotState  = 'WARN';
            $scBotReason = 'нет позиций / очереди';
        } else {
            $scBotState  = 'ON';
            $scBotReason = '';
        }
    } else {
        $scBotState  = 'OFF';
        $scBotReason = 'bot disabled';
    }

    // Stop Manager badge
    $smLastError = (string)($smLastRun['last_error'] ?? '');
    if ($smLastError !== '') {
        $scSmState  = 'ERR';
        $scSmReason = $smLastError;
    } elseif ($smCurrentlyEnabled) {
        $scSmState  = ($smLastStatus === 'skipped') ? 'WARN' : 'ON';
        $scSmReason = ($smLastStatus === 'skipped') ? 'tick skipped' : '';
    } else {
        $scSmState  = 'OFF';
        $scSmReason = '';
    }

    // Profit Manager badge
    if ($pmLastError !== '') {
        $scPmState  = 'ERR';
        $scPmReason = $pmLastError;
    } elseif ($pmDiagPriceProvErr !== '') {
        $scPmState  = 'ERR';
        $scPmReason = 'price_provider: ' . $pmDiagPriceProvErr;
    } elseif ($pmEnabledBool) {
        if ($pmRawSkipped !== '' && $pmRawSkipped !== 'module_disabled') {
            $scPmState = 'WARN';
            // Use clean summary for badge; fall back to raw string for legacy data
            if (!empty($pmSkipReasonsSummary)) {
                $topReason    = (string) array_key_first($pmSkipReasonsSummary);
                $totalSkipped = (int) array_sum($pmSkipReasonsSummary);
                $topReasonLabel = $pmReasonLabels[$topReason] ?? $topReason;
                $scPmReason   = $topReasonLabel . ' ×' . $totalSkipped;
            } else {
                $scPmReason = $pmReasonLabels[$pmRawSkipped] ?? $pmRawSkipped;
            }
        } elseif (!empty($pmSkipReasonsSummary)) {
            // Positions processed; some skipped for normal reasons (skip_reason='' globally)
            $allNormal = true;
            foreach (array_keys($pmSkipReasonsSummary) as $r) {
                if (!in_array($r, $pmNormalSkipReasons, true)) {
                    $allNormal = false;
                    break;
                }
            }
            $scPmState    = $allNormal ? 'WARN' : 'ERR';
            $topReason    = (string) array_key_first($pmSkipReasonsSummary);
            $totalSkipped = (int) array_sum($pmSkipReasonsSummary);
            $topReasonLabel = $pmReasonLabels[$topReason] ?? $topReason;
            $scPmReason   = $topReasonLabel . ' ×' . $totalSkipped;
        } elseif ($pmDiagInvalidPos > 0 && $pmDiagErrSum !== '') {
            $scPmState  = 'WARN';
            $scPmReason = $pmDiagErrSum;
        } elseif ($pmDiagPriceMiss > 0) {
            $scPmState  = 'WARN';
            $scPmReason = 'no_price_data';
        } elseif ($pmDiagWarnSum !== '') {
            $scPmState  = 'WARN';
            $scPmReason = $pmDiagWarnSum;
        } else {
            $scPmState  = 'ON';
            $scPmReason = '';
        }
    } else {
        $scPmState  = 'OFF';
        $scPmReason = $pmRawSkipped !== '' ? $pmRawSkipped : 'module_disabled';
    }

    // Cron badge
    if (!$pmCronTaskExists) {
        $scCronState  = 'OFF';
        $scCronReason = 'PM cron task не найден';
    } elseif (!$pmCronTaskEnabled) {
        $scCronState  = 'OFF';
        $scCronReason = 'PM cron task disabled';
    } elseif ($pmCronLastRunTs !== null) {
        $cronAge = time() - $pmCronLastRunTs;
        if ($cronAge > 180) {
            $scCronState  = 'WARN';
            $scCronReason = 'последний тик ' . round($cronAge / 60, 1) . ' мин назад';
        } else {
            $scCronState  = 'ON';
            $scCronReason = '';
        }
    } else {
        $scCronState  = 'WARN';
        $scCronReason = 'не запускался';
    }

    // Build badge HTML helper
    $scBadge = static function (string $label, string $state, string $reason, string $tabId = '') use ($scColor, $e): string {
        [$clr, $bg] = $scColor($state);
        $title = $reason !== '' ? ' title="' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . '"' : '';
        $sub   = $reason !== ''
            ? '<div style="font-size:10px;color:' . $clr . ';opacity:.8;margin-top:1px;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'
              . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . '</div>'
            : '';
        $pointer = $tabId !== '' ? 'cursor:pointer;' : '';
        $onclick = $tabId !== '' ? ' onclick="dhSwitchToTab(\'' . $tabId . '\')"' : '';
        return '<div style="' . $pointer . 'display:flex;flex-direction:column;align-items:center;padding:6px 14px;background:' . $bg . ';border:1px solid ' . $clr . '55;border-radius:8px;min-width:80px;"' . $title . $onclick . '>'
            . '<div style="font-size:11px;color:var(--ui-text-muted);margin-bottom:2px;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>'
            . '<div style="font-size:13px;font-weight:700;color:' . $clr . ';">' . $state . '</div>'
            . $sub
            . '</div>';
    };

    $scHtml = '<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px;padding:10px 14px;background:var(--ui-card);border:1px solid var(--ui-border);border-radius:10px;">'
        . '<span style="font-size:11px;color:var(--ui-text-muted);margin-right:4px;white-space:nowrap;">Статус цепочки:</span>'
        . $scBadge('Стратегии', $scStratState, $scStratReason, 'dh-strat')
        . '<span style="color:var(--ui-text-muted);font-size:18px;align-self:center;">→</span>'
        . $scBadge('Бот', $scBotState, $scBotReason, 'dh-bot')
        . '<span style="color:var(--ui-text-muted);font-size:18px;align-self:center;">→</span>'
        . $scBadge('Stop', $scSmState, $scSmReason, 'dh-sm')
        . '<span style="color:var(--ui-text-muted);font-size:18px;align-self:center;">→</span>'
        . $scBadge('Profit', $scPmState, $scPmReason, 'dh-pm')
        . '<span style="color:var(--ui-text-muted);font-size:18px;align-self:center;">→</span>'
        . $scBadge('Cron', $scCronState, $scCronReason, 'dh-ctrl')
        . '</div>';

    $pmRuntimeNote = '';
    if (!$pmEnabledBool && $pmRawLastRun !== []) {
        $noteReason = $pmRawSkipped !== '' ? $pmRawSkipped : 'module_disabled';
        $pmRuntimeNote = '<div style="color:#f0883e;font-size:12px;margin-top:8px;padding:7px 12px;background:rgba(240,136,62,.08);border-radius:6px;border-left:3px solid #f0883e77;">'
            . 'Последний тик был, но PM выключен: <strong>' . $e($noteReason) . '</strong></div>';
    } elseif ($pmEnabledBool && $pmRawSkipped === 'all_positions_invalid') {
        $errDetail = $pmDiagErrSum !== '' ? ' (' . $e($pmDiagErrSum) . ')' : '';
        $pmRuntimeNote = '<div style="color:#f85149;font-size:12px;margin-top:8px;padding:7px 12px;background:rgba(248,81,73,.08);border-radius:6px;border-left:3px solid #f8514977;">'
            . 'Позиции найдены, но все невалидны для PM' . $errDetail . '</div>';
    } elseif ($pmEnabledBool && !empty($pmSkipReasonsSummary)) {
        // Per-reason summary with human-readable labels (replaces raw concatenated message)
        $noteLines = [];
        foreach ($pmSkipReasonsSummary as $reason => $cnt) {
            $label      = $pmReasonLabels[$reason] ?? $reason;
            $noteLines[] = $e($label) . ' (' . (int)$cnt . ')';
        }
        $pmRuntimeNote = '<div style="color:#f0883e;font-size:12px;margin-top:8px;padding:7px 12px;background:rgba(240,136,62,.08);border-radius:6px;border-left:3px solid #f0883e77;">'
            . 'Позиции в режиме ожидания: ' . implode(' · ', $noteLines) . '</div>';
    } elseif ($pmEnabledBool && $pmRawSkipped !== '') {
        // Legacy fallback for old last_run.json without skip_reasons_summary
        $pmRuntimeNote = '<div style="color:#f0883e;font-size:12px;margin-top:8px;padding:7px 12px;background:rgba(240,136,62,.08);border-radius:6px;border-left:3px solid #f0883e77;">'
            . 'Тик пропущен: <strong>' . $e($pmRawSkipped) . '</strong></div>';
    } elseif ($pmEnabledBool && $pmDiagInvalidPos > 0 && $pmDiagValidPos === 0 && $pmDiagPositions > 0) {
        $errDetail = $pmDiagErrSum !== '' ? ' (' . $e($pmDiagErrSum) . ')' : '';
        $pmRuntimeNote = '<div style="color:#f85149;font-size:12px;margin-top:8px;padding:7px 12px;background:rgba(248,81,73,.08);border-radius:6px;border-left:3px solid #f8514977;">'
            . 'Позиции найдены, но все невалидны для PM' . $errDetail . '</div>';
    } elseif ($pmEnabledBool && $pmDiagValidPos > 0 && $pmDiagPriceMiss > 0) {
        $pmRuntimeNote = '<div style="color:#f0883e;font-size:12px;margin-top:8px;padding:7px 12px;background:rgba(240,136,62,.08);border-radius:6px;border-left:3px solid #f0883e77;">'
            . 'Нет текущей цены для расчёта ROI (' . $pmDiagPriceMiss . ' поз.)</div>';
    } elseif ($pmEnabledBool && !empty($pmDiagEnrichment['sizes_calculated'])) {
        $pmRuntimeNote = '<div style="color:#58a6ff;font-size:12px;margin-top:8px;padding:7px 12px;background:rgba(88,166,255,.08);border-radius:6px;border-left:3px solid #58a6ff77;">'
            . 'Размер позиции рассчитан из budget/leverage (' . (int)$pmDiagEnrichment['sizes_calculated'] . ' поз.)</div>';
    }

    // ── PM extra diagnostic rows for Runtime table ────────────────────────
    $pmDiagRows = '';
    if ($pmDiagPositions >= 0) {
        $pmDiagRows .= '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Найдено позиций</td>'
            . '<td><code>' . $pmDiagPositions . '</code></td></tr>';
    }
    if ($pmDiagValidPos >= 0) {
        $validColor = ($pmDiagValidPos === 0 && $pmDiagPositions > 0) ? '#f85149' : 'inherit';
        $pmDiagRows .= '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Валидных позиций</td>'
            . '<td><code style="color:' . $validColor . ';">' . $pmDiagValidPos . '</code></td></tr>';
    }
    if ($pmDiagInvalidPos > 0) {
        $pmDiagRows .= '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Невалидных позиций</td>'
            . '<td><code style="color:#f85149;">' . $pmDiagInvalidPos . '</code></td></tr>';
    }
    if ($pmDiagPriceMiss > 0) {
        $pmDiagRows .= '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Без текущей цены</td>'
            . '<td><code style="color:#f0883e;">' . $pmDiagPriceMiss . '</code></td></tr>';
    }
    if ($pmRawSkipped !== '') {
        $pmDiagRows .= '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Skip reason</td>'
            . '<td style="color:#f0883e;font-size:12px;">' . $e($pmRawSkipped) . '</td></tr>';
    }
    if ($pmDiagErrSum !== '') {
        $pmDiagRows .= '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Ошибки валидации</td>'
            . '<td style="color:#f85149;font-size:12px;">' . $e($pmDiagErrSum) . '</td></tr>';
    }
    if ($pmDiagWarnSum !== '') {
        $pmDiagRows .= '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Предупреждения</td>'
            . '<td style="color:#f0883e;font-size:12px;">' . $e($pmDiagWarnSum) . '</td></tr>';
    }
    if (!empty($pmDiagEnrichment['sizes_calculated'])) {
        $pmDiagRows .= '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Размер рассчитан</td>'
            . '<td><code style="color:#f0883e;">' . (int)$pmDiagEnrichment['sizes_calculated'] . '</code></td></tr>';
    }
    if (!empty($pmDiagEnrichment['prices_from_position'])) {
        $pmDiagRows .= '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Цена из позиции</td>'
            . '<td><code style="color:#3fb950;">' . (int)$pmDiagEnrichment['prices_from_position'] . '</code></td></tr>';
    }
    if (!empty($pmDiagEnrichment['prices_from_gateway'])) {
        $pmDiagRows .= '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Цена via Gateway</td>'
            . '<td><code style="color:#3fb950;">' . (int)$pmDiagEnrichment['prices_from_gateway'] . '</code></td></tr>';
    }
    if ($pmDiagPriceProvErr !== '') {
        $pmDiagRows .= '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Price provider error</td>'
            . '<td style="color:#f85149;font-size:11px;">' . $e($pmDiagPriceProvErr) . '</td></tr>';
    }
    if ($pmDiagPriceProvSource !== '' && $pmDiagPriceProvSource !== 'none') {
        $pmDiagRows .= '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Price provider source</td>'
            . '<td style="font-size:11px;"><code>' . $e($pmDiagPriceProvSource) . '</code></td></tr>';
    }
    if (!empty($pmDiagEnrichment['leverage_defaulted'])) {
        $pmDiagRows .= '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Плечо по умолч.</td>'
            . '<td><code style="color:#f0883e;">' . (int)$pmDiagEnrichment['leverage_defaulted'] . '</code></td></tr>';
    }
    if (!empty($pmDiagEnrichment['budget_defaulted'])) {
        $pmDiagRows .= '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Бюджет по умолч.</td>'
            . '<td><code style="color:#f0883e;">' . (int)$pmDiagEnrichment['budget_defaulted'] . '</code></td></tr>';
    }
    if ($pmDiagSource !== '' && $pmDiagSource !== 'none') {
        $pmDiagRows .= '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Источник позиций</td>'
            . '<td style="font-size:11px;"><code>' . $e($pmDiagSource) . '</code></td></tr>';
    }
    if (!empty($pmSkipReasonsSummary)) {
        $skipSummaryParts = [];
        foreach ($pmSkipReasonsSummary as $reason => $cnt) {
            $label = $pmReasonLabels[$reason] ?? $reason;
            $skipSummaryParts[] = $e($label) . ': ' . (int)$cnt;
        }
        $pmDiagRows .= '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Skip по причинам</td>'
            . '<td style="color:#f0883e;font-size:12px;">' . implode('<br>', $skipSummaryParts) . '</td></tr>';
    }

    // ── PM per-position runtime table HTML ───────────────────────────────
    $pmPositionsTable = '';
    if (!empty($pmPositionsRuntime)) {
        $fmtFloat = static function ($v, int $dec = 2): string {
            return ($v === null || $v === '') ? '—' : number_format((float)$v, $dec);
        };
        $actionColor = static function (string $action): string {
            if ($action === 'skip') {
                return '#8b949e';
            }
            if (str_contains($action, 'would_set') || str_contains($action, 'would_move')) {
                return '#3fb950';
            }
            if (str_contains($action, 'would_close')) {
                return '#f0883e';
            }
            return '#58a6ff';
        };

        $tableRows = '';
        foreach ($pmPositionsRuntime as $pr) {
            $symbol      = $e((string)($pr['symbol']          ?? ''));
            $side        = $e((string)($pr['side']            ?? ''));
            $roi         = $fmtFloat($pr['roi']               ?? null);
            $peakRoi     = $fmtFloat($pr['peak_roi']          ?? null);
            $gapVal      = $pr['roi_gap_to_activation']       ?? null;
            $gap         = $gapVal !== null ? $fmtFloat($gapVal) : '—';
            $gapColor    = ($gapVal !== null && (float)$gapVal <= 0) ? '#3fb950' : '#f0883e';
            $initRoi     = $fmtFloat($pr['init_roi']          ?? null);
            $activRoi    = $fmtFloat($pr['activation_roi']    ?? null);
            $action      = (string)($pr['action']             ?? 'skip');
            $rawReason   = (string)($pr['skip_reason']        ?? '');
            $priceSource = $e((string)($pr['price_source']    ?? ''));
            $reasonLabel = $rawReason !== ''
                ? ($pmReasonLabels[$rawReason] ?? $rawReason)
                : '—';
            $actionShort = match (true) {
                $action === 'skip'                      => 'skip',
                $action === 'would_set_profit_lock'     => 'set lock',
                $action === 'would_move_profit_lock'    => 'move lock',
                $action === 'would_close_on_lock_touch' => 'close',
                default                                 => $e($action),
            };
            $aClr = $actionColor($action);
            // Distance: show only for lock_price_too_close_to_current
            if ($rawReason === 'lock_price_too_close_to_current') {
                $distVal = $pr['distance_pct']              ?? null;
                $minDist = $pr['min_required_distance_pct'] ?? null;
                $distStr = ($distVal !== null)
                    ? $fmtFloat($distVal, 3) . '% / min ' . $fmtFloat($minDist, 2) . '%'
                    : '—';
            } else {
                $distStr = '—';
            }
            $tableRows .= '<tr style="border-bottom:1px solid var(--ui-border);">'
                . '<td style="padding:4px 8px;font-weight:600;">' . $symbol . '</td>'
                . '<td style="padding:4px 8px;color:#8b949e;">' . $side . '</td>'
                . '<td style="padding:4px 8px;text-align:right;">' . $roi . '</td>'
                . '<td style="padding:4px 8px;text-align:right;color:#a78bfa;">' . $peakRoi . '</td>'
                . '<td style="padding:4px 8px;text-align:right;color:' . $gapColor . ';">' . $gap . '</td>'
                . '<td style="padding:4px 8px;text-align:right;color:#8b949e;">' . $initRoi . '</td>'
                . '<td style="padding:4px 8px;text-align:right;color:#8b949e;">' . $activRoi . '</td>'
                . '<td style="padding:4px 8px;font-size:11px;color:' . $aClr . ';font-weight:600;">' . $actionShort . '</td>'
                . '<td style="padding:4px 8px;font-size:11px;color:#f0883e;">' . $e($reasonLabel) . '</td>'
                . '<td style="padding:4px 8px;font-size:10px;color:#8b949e;">' . $e($distStr) . '</td>'
                . '<td style="padding:4px 8px;font-size:10px;color:#8b949e;">' . $priceSource . '</td>'
                . '</tr>';
        }

        $pmPositionsTable = <<<HTML
<div class="card" style="margin-bottom:16px;">
  <div class="card-header">Profit Manager — Позиции (runtime)</div>
  <div class="card-body" style="padding:0;">
    <div style="overflow-x:auto;">
      <table style="width:100%;font-size:12px;border-collapse:collapse;">
        <thead>
          <tr style="border-bottom:2px solid var(--ui-border);">
            <th style="padding:6px 8px;text-align:left;color:var(--ui-text-muted);font-weight:600;">Symbol</th>
            <th style="padding:6px 8px;text-align:left;color:var(--ui-text-muted);font-weight:600;">Side</th>
            <th style="padding:6px 8px;text-align:right;color:var(--ui-text-muted);font-weight:600;">ROI %</th>
            <th style="padding:6px 8px;text-align:right;color:var(--ui-text-muted);font-weight:600;">Peak ROI %</th>
            <th style="padding:6px 8px;text-align:right;color:var(--ui-text-muted);font-weight:600;">Gap</th>
            <th style="padding:6px 8px;text-align:right;color:var(--ui-text-muted);font-weight:600;">Init</th>
            <th style="padding:6px 8px;text-align:right;color:var(--ui-text-muted);font-weight:600;">Activation</th>
            <th style="padding:6px 8px;text-align:left;color:var(--ui-text-muted);font-weight:600;">Action</th>
            <th style="padding:6px 8px;text-align:left;color:var(--ui-text-muted);font-weight:600;">Reason</th>
            <th style="padding:6px 8px;text-align:left;color:var(--ui-text-muted);font-weight:600;">Distance</th>
            <th style="padding:6px 8px;text-align:left;color:var(--ui-text-muted);font-weight:600;">Price Source</th>
          </tr>
        </thead>
        <tbody>
          {$tableRows}
        </tbody>
      </table>
    </div>
  </div>
</div>
HTML;
    }

    // ── System Overview block ────────────────────────────────────────────
    // Collect system-level stats from existing runtime JSONs (no new modules).
    $sysLastError = '';
    if ($pmLastError !== '') {
        $sysLastError = 'PM: ' . $pmLastError;
    } elseif ($smLastStatus !== 'ok' && $smLastStatus !== 'never_run') {
        $sysLastError = 'SM: ' . $smLastStatus;
    } elseif (!empty($lastRun['error'])) {
        $sysLastError = 'Bot: ' . (string)$lastRun['error'];
    }
    // Average ROI from PM runtime positions (if any tracked with ROI)
    $sysRoiValues = [];
    foreach ($pmPositionsRuntime as $pr) {
        if (isset($pr['roi']) && $pr['roi'] !== null) {
            $sysRoiValues[] = (float)$pr['roi'];
        }
    }
    $sysAvgRoi = count($sysRoiValues) > 0
        ? number_format(array_sum($sysRoiValues) / count($sysRoiValues), 2) . '%'
        : '—';

    $sysLastErrorHtml = $sysLastError !== ''
        ? '<span style="color:#f85149;">' . $e($sysLastError) . '</span>'
        : '<span style="color:#3fb950;">—</span>';

    $sysOverviewHtml = <<<HTML
<div class="card" style="margin-bottom:16px;">
  <div class="card-header"><i class="bi bi-grid-1x2" style="margin-right:6px;"></i>System Overview</div>
  <div class="card-body" style="padding:12px 16px;">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px 20px;font-size:13px;">
      <div><span style="color:var(--ui-text-muted);">Позиций активно</span><br><strong style="color:#a78bfa;">{$posCount}</strong></div>
      <div><span style="color:var(--ui-text-muted);">Стратегий включено</span><br><strong style="color:#3fb950;">{$enabledStrat}</strong> / {$totalStrat}</div>
      <div><span style="color:var(--ui-text-muted);">PM отслеживает</span><br><strong style="color:#f0883e;">{$pmPosTracked}</strong> поз.</div>
      <div><span style="color:var(--ui-text-muted);">SM стопов активно</span><br><strong style="color:#a78bfa;">{$smActiveStops}</strong></div>
      <div><span style="color:var(--ui-text-muted);">Ср. ROI (PM поз.)</span><br><strong style="color:#58a6ff;">{$sysAvgRoi}</strong></div>
      <div><span style="color:var(--ui-text-muted);">Последняя ошибка</span><br>{$sysLastErrorHtml}</div>
    </div>
  </div>
</div>
HTML;

    // ── Compact strategy summary (for Обзор pane) ────────────────────────
    $compactStratRows = '';
    foreach ($registry as $rec) {
        $cSid    = (string)($rec['strategy_id'] ?? '');
        $cTitle  = $e($rec['title'] ?? $cSid);
        $cOp     = (array)($overrides[$cSid] ?? []);
        $cEnabled = array_key_exists('enabled', $cOp)
            ? (bool)$cOp['enabled']
            : (bool)($rec['enabled_by_default'] ?? true);
        $cEnColor = $cEnabled ? '#3fb950' : '#8b949e';
        $cEnLabel = $cEnabled ? 'ON' : 'OFF';
        $cStatus  = $e((string)($rec['status'] ?? 'discovered'));
        $cSigs    = $stratSignals[$cSid] ?? null;
        $cSigsStr = ($cSigs !== null) ? (string)$cSigs : '—';
        $compactStratRows .= '<tr style="border-bottom:1px solid var(--ui-border);">'
            . '<td style="padding:5px 10px;font-weight:600;">' . $cTitle . '</td>'
            . '<td style="padding:5px 10px;"><code style="font-size:11px;color:#58a6ff;">' . $e($cSid) . '</code></td>'
            . '<td style="padding:5px 10px;"><span style="color:' . $cEnColor . ';font-weight:700;">' . $cEnLabel . '</span></td>'
            . '<td style="padding:5px 10px;font-size:11px;color:#8b949e;">' . $cStatus . '</td>'
            . '<td style="padding:5px 10px;text-align:right;color:#58a6ff;">' . $e($cSigsStr) . '</td>'
            . '</tr>';
    }
    if ($compactStratRows === '') {
        $compactStratRows = '<tr><td colspan="5" style="padding:12px 10px;color:var(--ui-text-muted);">Стратегии не обнаружены.</td></tr>';
    }

    // ── PM "Последняя ошибка" table row HTML ─────────────────────────────
    if ($pmLastError !== '') {
        $pmLastErrorRow = '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Последняя ошибка</td>'
            . '<td style="color:#f85149;font-size:12px;">' . $e($pmLastError) . '</td></tr>';
    } else {
        $pmHistNote = $pmHasHistoricalErrors
            ? ' <span style="font-size:11px;opacity:.65;">(исторические ошибки есть в error.log)</span>'
            : '';
        $pmLastErrorRow = '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Последняя ошибка</td>'
            . '<td style="font-size:12px;color:var(--ui-text-muted);">—' . $pmHistNote . '</td></tr>';
    }

    // ── Build PM runtime lookup by symbol+side for position merging ──────
    $pmRtLookup = [];
    foreach ($pmPositionsRuntime as $pr) {
        $pKey = strtolower((string)($pr['symbol'] ?? '')) . '_' . strtolower((string)($pr['side'] ?? ''));
        $pmRtLookup[$pKey] = $pr;
        // Also index by symbol only as fallback
        $pKeySymbol = strtolower((string)($pr['symbol'] ?? ''));
        if (!isset($pmRtLookup[$pKeySymbol])) {
            $pmRtLookup[$pKeySymbol] = $pr;
        }
    }

    // Helper: format seconds as human-readable duration
    $fmtDuration = static function (int $sec): string {
        if ($sec < 60) {
            return $sec . 'с';
        }
        if ($sec < 3600) {
            return floor($sec / 60) . 'м';
        }
        $h = floor($sec / 3600);
        $m = floor(($sec % 3600) / 60);
        return $m > 0 ? $h . 'ч ' . $m . 'м' : $h . 'ч';
    };

    // ── Open positions table for Overview pane ────────────────────────────
    $overviewPositionsHtml = '';
    if (!empty($positions)) {
        $posRows = '';
        foreach ($positions as $pos) {
            if (!is_array($pos)) {
                continue;
            }
            $posSymbol = (string)($pos['symbol'] ?? '');
            $posSide   = (string)($pos['side']   ?? '');
            // Look up PM runtime data: try symbol+side first, then symbol only
            $pmKey     = strtolower($posSymbol) . '_' . strtolower($posSide);
            $pmRt      = $pmRtLookup[$pmKey] ?? $pmRtLookup[strtolower($posSymbol)] ?? [];

            $pSymbol   = $e($posSymbol);
            $pSide     = $e($posSide);
            $pStrategy = $e((string)($pos['strategy_id']     ?? $pos['owner_strategy'] ?? $pos['source'] ?? '—'));
            $pEntry    = isset($pos['entry_price'])     ? number_format((float)$pos['entry_price'],    4) : '—';

            // current_price: prefer PM runtime, fallback to bot position
            $curPriceRaw = $pmRt['current_price'] ?? $pos['current_price'] ?? null;
            $pCur        = ($curPriceRaw !== null && (float)$curPriceRaw > 0)
                ? number_format((float)$curPriceRaw, 4) : '—';

            // ROI: prefer PM runtime, fallback to bot position
            $pRoiRaw   = $pmRt['roi'] ?? $pos['roi'] ?? null;
            $pRoi      = ($pRoiRaw !== null) ? number_format((float)$pRoiRaw, 2) . '%' : '—';
            $pRoiColor = ($pRoiRaw !== null && (float)$pRoiRaw > 0) ? '#3fb950' : '#f85149';

            // unrealised_pnl: bot position only (PM does not compute PnL)
            $pPnl      = isset($pos['unrealised_pnl'])  ? number_format((float)$pos['unrealised_pnl'], 4)  : '—';

            // budget: from bot signal fields
            $budgetRaw = $pos['bot_budget'] ?? $pos['budget'] ?? null;
            $pBudget   = ($budgetRaw !== null) ? number_format((float)$budgetRaw, 2) : '—';

            $pLev      = $e((string)($pos['bot_leverage'] ?? $pos['leverage'] ?? '—'));
            $pSize     = $e((string)($pos['size'] ?? $pos['amount'] ?? '—'));

            // opened_at
            $openedAtRaw = (string)($pos['opened_at'] ?? $pos['created_at'] ?? $pos['entered_at'] ?? '');
            $pOpenedAt   = $e($openedAtRaw !== '' ? $openedAtRaw : '—');

            // time_in_position: compute from opened_at
            $pTimeInPos = '—';
            if ($openedAtRaw !== '') {
                $openedTs = @strtotime($openedAtRaw);
                if ($openedTs !== false && $openedTs > 0) {
                    $elapsed = max(0, time() - $openedTs);
                    $pTimeInPos = $fmtDuration($elapsed);
                }
            }

            // status
            $pStatus = $e((string)($pos['position_status'] ?? $pos['status'] ?? '—'));

            // PM action and skip_reason (human-readable)
            $pmAction      = (string)($pmRt['action'] ?? '');
            $pmSkipRaw     = (string)($pmRt['skip_reason'] ?? '');
            $pmSkipLabel   = $pmSkipRaw !== ''
                ? ($pmReasonLabels[$pmSkipRaw] ?? $pmSkipRaw)
                : '—';
            $pmPriceSource = $e((string)($pmRt['price_source'] ?? '—'));

            $posRows .= '<tr style="border-bottom:1px solid var(--ui-border);">'
                . '<td style="padding:4px 8px;font-weight:600;">' . $pSymbol . '</td>'
                . '<td style="padding:4px 8px;color:#8b949e;">' . $pSide . '</td>'
                . '<td style="padding:4px 8px;font-size:11px;color:#8b949e;">' . $pStrategy . '</td>'
                . '<td style="padding:4px 8px;text-align:right;">' . $pEntry . '</td>'
                . '<td style="padding:4px 8px;text-align:right;">' . $pCur . '</td>'
                . '<td style="padding:4px 8px;text-align:right;color:' . $pRoiColor . ';font-weight:600;">' . $pRoi . '</td>'
                . '<td style="padding:4px 8px;text-align:right;">' . $pPnl . '</td>'
                . '<td style="padding:4px 8px;text-align:right;">' . $pBudget . '</td>'
                . '<td style="padding:4px 8px;text-align:right;">' . $pLev . '</td>'
                . '<td style="padding:4px 8px;text-align:right;">' . $pSize . '</td>'
                . '<td style="padding:4px 8px;font-size:11px;color:#8b949e;">' . $pOpenedAt . '</td>'
                . '<td style="padding:4px 8px;font-size:11px;color:#58a6ff;">' . $pTimeInPos . '</td>'
                . '<td style="padding:4px 8px;font-size:11px;color:#8b949e;">' . $pStatus . '</td>'
                . '<td style="padding:4px 8px;font-size:11px;color:#f0883e;">' . $e($pmSkipLabel) . '</td>'
                . '</tr>';
        }
        $overviewPositionsHtml = <<<HTML
<div class="card" style="margin-bottom:16px;">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
    <span><i class="bi bi-bar-chart-line" style="margin-right:6px;"></i>Открытые позиции ({$posCount})</span>
    <small style="color:var(--ui-text-muted);font-size:11px;">read-only · биржевых действий нет</small>
  </div>
  <div class="card-body" style="padding:0;">
    <div style="overflow-x:auto;">
      <table style="width:100%;font-size:12px;border-collapse:collapse;">
        <thead>
          <tr style="border-bottom:2px solid var(--ui-border);">
            <th style="padding:5px 8px;text-align:left;color:var(--ui-text-muted);">Symbol</th>
            <th style="padding:5px 8px;text-align:left;color:var(--ui-text-muted);">Side</th>
            <th style="padding:5px 8px;text-align:left;color:var(--ui-text-muted);">Стратегия</th>
            <th style="padding:5px 8px;text-align:right;color:var(--ui-text-muted);">Вход</th>
            <th style="padding:5px 8px;text-align:right;color:var(--ui-text-muted);">Текущая</th>
            <th style="padding:5px 8px;text-align:right;color:var(--ui-text-muted);">ROI%</th>
            <th style="padding:5px 8px;text-align:right;color:var(--ui-text-muted);">PnL</th>
            <th style="padding:5px 8px;text-align:right;color:var(--ui-text-muted);">Бюджет</th>
            <th style="padding:5px 8px;text-align:right;color:var(--ui-text-muted);">Плечо</th>
            <th style="padding:5px 8px;text-align:right;color:var(--ui-text-muted);">Размер</th>
            <th style="padding:5px 8px;text-align:left;color:var(--ui-text-muted);">Открыто</th>
            <th style="padding:5px 8px;text-align:left;color:var(--ui-text-muted);">В позиции</th>
            <th style="padding:5px 8px;text-align:left;color:var(--ui-text-muted);">Статус</th>
            <th style="padding:5px 8px;text-align:left;color:var(--ui-text-muted);">PM причина</th>
          </tr>
        </thead>
        <tbody>{$posRows}</tbody>
      </table>
    </div>
  </div>
</div>
HTML;
    }

    // ── Module state strip for Overview pane ─────────────────────────────
    $modStripRows = '';
    // Bot
    [$modBotClr] = $scColor($scBotState);
    $modStripRows .= '<tr style="border-bottom:1px solid var(--ui-border);">'
        . '<td style="padding:5px 10px;font-weight:600;width:70px;">Бот</td>'
        . '<td style="padding:5px 10px;color:' . $modBotClr . ';font-weight:600;width:55px;">' . $scBotState . '</td>'
        . '<td style="padding:5px 10px;width:90px;"><code style="font-size:11px;">' . $e($botMode) . '</code></td>'
        . '<td style="padding:5px 10px;font-size:11px;color:#8b949e;width:160px;">' . $e($tickAt) . '</td>'
        . '<td style="padding:5px 10px;font-size:11px;color:#8b949e;">позиций: ' . $posCount . ' · очередь: ' . $queueSize . '</td>'
        . '<td style="padding:5px 10px;font-size:11px;color:#f85149;">' . $e($scBotReason) . '</td>'
        . '</tr>';
    // Stop Manager
    [$modSmClr] = $scColor($scSmState);
    $modStripRows .= '<tr style="border-bottom:1px solid var(--ui-border);">'
        . '<td style="padding:5px 10px;font-weight:600;">Stop</td>'
        . '<td style="padding:5px 10px;color:' . $modSmClr . ';font-weight:600;">' . $scSmState . '</td>'
        . '<td style="padding:5px 10px;"><code style="font-size:11px;">' . $e($smMode) . '</code></td>'
        . '<td style="padding:5px 10px;font-size:11px;color:#8b949e;">' . $e($smLastTick) . '</td>'
        . '<td style="padding:5px 10px;font-size:11px;color:#8b949e;">стопов: ' . $smActiveStops . ' · позиций: ' . $smPosSeen . '</td>'
        . '<td style="padding:5px 10px;font-size:11px;color:#f85149;">' . $e($scSmReason) . '</td>'
        . '</tr>';
    // Profit Manager
    [$modPmClr] = $scColor($scPmState);
    $modPmActivity = 'отслеж.: ' . $pmPosTracked . ' · пропущено: ' . $pmSkipped;
    $modPmIssue    = $pmLastError !== '' ? $pmLastError : $scPmReason;
    $modStripRows .= '<tr style="border-bottom:1px solid var(--ui-border);">'
        . '<td style="padding:5px 10px;font-weight:600;">Profit</td>'
        . '<td style="padding:5px 10px;color:' . $modPmClr . ';font-weight:600;">' . $scPmState . '</td>'
        . '<td style="padding:5px 10px;"><code style="font-size:11px;">' . $e($pmMode) . '</code></td>'
        . '<td style="padding:5px 10px;font-size:11px;color:#8b949e;">' . $e($pmLastTick) . '</td>'
        . '<td style="padding:5px 10px;font-size:11px;color:#8b949e;">' . $e($modPmActivity) . '</td>'
        . '<td style="padding:5px 10px;font-size:11px;color:#f85149;">' . $e($modPmIssue) . '</td>'
        . '</tr>';
    // Cron
    [$modCronClr] = $scColor($scCronState);
    $modCronLastRunStr = $pmCronLastRunTs !== null ? date('d.m H:i', $pmCronLastRunTs) : '—';
    $modStripRows .= '<tr>'
        . '<td style="padding:5px 10px;font-weight:600;">Cron</td>'
        . '<td style="padding:5px 10px;color:' . $modCronClr . ';font-weight:600;">' . $scCronState . '</td>'
        . '<td style="padding:5px 10px;font-size:11px;color:#8b949e;">' . ($pmCronTaskEnabled ? 'включён' : 'выключен') . '</td>'
        . '<td style="padding:5px 10px;font-size:11px;color:#8b949e;">' . $e($modCronLastRunStr) . '</td>'
        . '<td style="padding:5px 10px;font-size:11px;color:#8b949e;"><code style="font-size:10px;">' . $e($pmCronPath) . '</code></td>'
        . '<td style="padding:5px 10px;font-size:11px;color:#f85149;">' . $e($scCronReason) . '</td>'
        . '</tr>';
    $modStripHtml = <<<HTML
<div class="card" style="margin-bottom:16px;">
  <div class="card-header"><i class="bi bi-hdd-stack" style="margin-right:6px;"></i>Состояние модулей</div>
  <div class="card-body" style="padding:0;">
    <table style="width:100%;font-size:13px;border-collapse:collapse;">
      <thead>
        <tr style="border-bottom:2px solid var(--ui-border);">
          <th style="padding:5px 10px;text-align:left;color:var(--ui-text-muted);">Модуль</th>
          <th style="padding:5px 10px;text-align:left;color:var(--ui-text-muted);">Статус</th>
          <th style="padding:5px 10px;text-align:left;color:var(--ui-text-muted);">Режим</th>
          <th style="padding:5px 10px;text-align:left;color:var(--ui-text-muted);">Последний тик</th>
          <th style="padding:5px 10px;text-align:left;color:var(--ui-text-muted);">Активность</th>
          <th style="padding:5px 10px;text-align:left;color:var(--ui-text-muted);">Ошибка/Причина</th>
        </tr>
      </thead>
      <tbody>{$modStripRows}</tbody>
    </table>
  </div>
</div>
HTML;

    $flashHtml = '';
    if ($flash) {
        $ftype = ($flash['type'] === 'success') ? 'success' : 'danger';
        $fmsg  = $e($flash['msg'] ?? '');
        $fbg   = $ftype === 'success' ? 'rgba(63,185,80,.12)' : 'rgba(248,81,73,.12)';
        $fclr  = $ftype === 'success' ? '#3fb950' : '#f85149';
        $flashHtml = <<<HTML
<div style="background:{$fbg};border:1px solid {$fclr}55;border-radius:8px;padding:10px 16px;margin-bottom:16px;font-size:13px;color:{$fclr};">
    {$fmsg}
</div>
HTML;
    }

    // ── render ────────────────────────────────────────────────────────────
    return <<<HTML
<style>
.dh-tab-nav{display:flex;gap:0;border-bottom:1px solid var(--ui-border);margin-bottom:20px;}
.dh-tab-btn{background:none;border:none;border-bottom:2px solid transparent;padding:10px 22px;color:var(--ui-text-muted);cursor:pointer;font-size:14px;transition:color .15s,border-color .15s;outline:none;}
.dh-tab-btn:hover{color:var(--ui-text);border-bottom-color:var(--ui-border);}
.dh-tab-btn.dh-active{color:var(--ui-accent);border-bottom-color:var(--ui-accent);font-weight:600;}
.dh-pane{display:none;}
.dh-pane.dh-visible{display:block;}
.dh-stat{background:var(--ui-card);border:1px solid var(--ui-border);border-radius:8px;padding:10px 18px;min-width:110px;text-align:center;}
.dh-stat-val{font-size:22px;font-weight:700;}
.dh-stat-lbl{font-size:11px;color:var(--ui-text-muted);text-transform:uppercase;margin-top:2px;}
</style>

<div style="max-width:1200px;">

{$flashHtml}

<!-- Page header -->
<div style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;">
  <div>
    <h4 style="margin:0 0 2px;"><i class="bi bi-layout-text-sidebar-reverse" style="margin-right:8px;"></i>Оперативный центр</h4>
    <div style="font-size:12px;color:var(--ui-text-muted);">Управление стратегиями · Бот · Контроль</div>
  </div>
  <!-- One-button chain run -->
  <form method="post" action="{$chainRunUrl}" style="margin:0;">
    <button type="submit" class="btn btn-sm" style="background:rgba(88,166,255,.15);color:#58a6ff;border:1px solid #58a6ff66;padding:7px 20px;font-weight:600;font-size:13px;">
      <i class="bi bi-lightning-fill" style="margin-right:5px;"></i>Запустить цепочку
    </button>
  </form>
</div>

<!-- Summary strip -->
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;">
  <div class="dh-stat"><div class="dh-stat-val" style="color:var(--ui-text);">{$totalStrat}</div><div class="dh-stat-lbl">Стратегий</div></div>
  <div class="dh-stat"><div class="dh-stat-val" style="color:#3fb950;">{$enabledStrat}</div><div class="dh-stat-lbl">Включено</div></div>
  <div class="dh-stat"><div class="dh-stat-val" style="color:#8b949e;">{$disabledStrat}</div><div class="dh-stat-lbl">Выключено</div></div>
  <div class="dh-stat"><div class="dh-stat-val" style="color:#58a6ff;">{$sigProc}</div><div class="dh-stat-lbl">Сигналов</div></div>
  <div class="dh-stat"><div class="dh-stat-val" style="color:#f0883e;">{$queueSize}</div><div class="dh-stat-lbl">Очередь</div></div>
  <div class="dh-stat"><div class="dh-stat-val" style="color:#58a6ff;">{$ordersCount}</div><div class="dh-stat-lbl">Ордеров</div></div>
  <div class="dh-stat"><div class="dh-stat-val" style="color:#a78bfa;">{$posCount}</div><div class="dh-stat-lbl">Позиций</div></div>
  <div class="dh-stat" style="min-width:160px;">
    <div style="font-size:13px;font-weight:700;color:var(--ui-text);">{$e($tickStatus)}</div>
    <div style="font-size:11px;color:var(--ui-text-muted);">{$e($tickAt)}</div>
    <div style="font-size:11px;color:var(--ui-text-muted);margin-top:2px;">Бот: {$botEnabled}</div>
  </div>
</div>

<!-- Status chain (always visible) -->
{$scHtml}

<!-- System Overview (always visible) -->
{$sysOverviewHtml}

<!-- Top tab navigation (vanilla JS) -->
<nav class="dh-tab-nav" role="tablist">
  <button class="dh-tab-btn dh-active" onclick="dhTab(this,'dh-overview')" type="button">
    <i class="bi bi-grid-1x2" style="margin-right:5px;"></i>Обзор
  </button>
  <button class="dh-tab-btn" onclick="dhTab(this,'dh-strat')" type="button">
    <i class="bi bi-layers" style="margin-right:5px;"></i>Стратегии
  </button>
  <button class="dh-tab-btn" onclick="dhTab(this,'dh-bot')" type="button">
    <i class="bi bi-cpu" style="margin-right:5px;"></i>Бот
  </button>
  <button class="dh-tab-btn" onclick="dhTab(this,'dh-sm')" type="button">
    <i class="bi bi-shield-exclamation" style="margin-right:5px;"></i>Стоп
  </button>
  <button class="dh-tab-btn" onclick="dhTab(this,'dh-pm')" type="button">
    <i class="bi bi-graph-up-arrow" style="margin-right:5px;"></i>Профит
  </button>
  <button class="dh-tab-btn" onclick="dhTab(this,'dh-ctrl')" type="button">
    <i class="bi bi-sliders" style="margin-right:5px;"></i>Управление
  </button>
</nav>

<!-- ── Overview pane (default) ─────────────────────────────────────── -->
<div id="dh-overview" class="dh-pane dh-visible">
  <div class="card" style="margin-bottom:16px;">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <span><i class="bi bi-layers" style="margin-right:6px;"></i>Стратегии — сводка</span>
      <small style="color:var(--ui-text-muted);font-size:11px;">Полное управление → вкладка «Стратегии»</small>
    </div>
    <div class="card-body" style="padding:0;">
      <table style="width:100%;font-size:13px;border-collapse:collapse;">
        <thead>
          <tr style="border-bottom:2px solid var(--ui-border);">
            <th style="padding:5px 10px;text-align:left;color:var(--ui-text-muted);">Название</th>
            <th style="padding:5px 10px;text-align:left;color:var(--ui-text-muted);">ID</th>
            <th style="padding:5px 10px;text-align:left;color:var(--ui-text-muted);">Статус</th>
            <th style="padding:5px 10px;text-align:left;color:var(--ui-text-muted);">Тип</th>
            <th style="padding:5px 10px;text-align:right;color:var(--ui-text-muted);">Сигналов</th>
          </tr>
        </thead>
        <tbody>{$compactStratRows}</tbody>
      </table>
    </div>
  </div>
  {$overviewPositionsHtml}
  {$modStripHtml}
  <div style="margin-top:16px;text-align:right;">
    <button
      type="button"
      onclick="dhResetRuntime()"
      style="background:rgba(248,81,73,.12);border:1px solid #f85149;color:#f85149;padding:6px 16px;border-radius:5px;cursor:pointer;font-size:13px;"
    ><i class="bi bi-trash3" style="margin-right:5px;"></i>Сбросить данные (paper)</button>
  </div>
</div>

<!-- ── Strategies pane ──────────────────────────────────────────────── -->
<div id="dh-strat" class="dh-pane">
  <div style="font-size:12px;color:var(--ui-text-muted);margin-bottom:12px;">
    Источник: <code>bot/storage/strategy_registry.json</code> · <code>modules/strategy/*/manifest.json</code> · <code>operator_overrides.json</code>
  </div>
  {$stratCards}
</div>

<!-- ── Bot pane ─────────────────────────────────────────────────────── -->
<div id="dh-bot" class="dh-pane">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
    <div class="card">
      <div class="card-header">Бот</div>
      <div class="card-body">
        <table style="width:100%;font-size:13px;border-collapse:collapse;">
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;width:140px;">Включён</td><td>{$botEnabled}</td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Режим</td><td><code>{$e($botMode)}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Статус тика</td><td><code>{$e($tickStatus)}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Время тика</td><td><code>{$e($tickAt)}</code></td></tr>
        </table>
      </div>
    </div>
    <div class="card">
      <div class="card-header">Текущий прогон</div>
      <div class="card-body">
        <table style="width:100%;font-size:13px;border-collapse:collapse;">
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;width:160px;">Очередь ордеров</td><td><code>{$e($lastRun['order_queue_total'] ?? 0)}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Активных ордеров</td><td><code>{$e($lastRun['active_orders_count'] ?? 0)}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Активных позиций</td><td><code>{$e($lastRun['active_positions_count'] ?? 0)}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Сигналов обработано</td><td><code>{$e($sigProc)}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Стратегий обнаружено</td><td><code>{$e($lastRun['strategies_discovered_total'] ?? 0)}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Стратегий включено</td><td><code>{$e($lastRun['strategies_enabled_total'] ?? 0)}</code></td></tr>
        </table>
      </div>
    </div>
  </div>
  <div class="card">
    <div class="card-header">Ручное управление</div>
    <div class="card-body">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <!-- Bot quick toggle -->
        <form method="post" action="{$botToggleUrl}" style="margin:0;">
          <input type="hidden" name="enabled" value="{$botToggleTarget}">
          <button type="submit" class="btn btn-sm" style="background:{$botToggleBg};color:{$botToggleColor};border:1px solid {$botToggleColor}55;padding:6px 18px;font-weight:600;">
            {$botToggleLabel}
          </button>
        </form>
        <form method="post" action="{$botTickUrl}" style="margin:0;">
          <input type="hidden" name="action" value="tick">
          <button type="submit" class="btn btn-sm" style="background:rgba(63,185,80,.12);color:#3fb950;border:1px solid #3fb95055;padding:6px 18px;">
            <i class="bi bi-play-fill" style="margin-right:4px;"></i>Тик бота
          </button>
        </form>
        <form method="post" action="{$botTickUrl}" style="margin:0;">
          <input type="hidden" name="action" value="refresh">
          <button type="submit" class="btn btn-sm" style="background:rgba(139,148,158,.12);color:#8b949e;border:1px solid #8b949e55;padding:6px 18px;">
            <i class="bi bi-arrow-clockwise" style="margin-right:4px;"></i>Обновить runtime
          </button>
        </form>
      </div>
      <div style="margin-top:10px;font-size:11px;color:var(--ui-text-muted);">
        «Тик бота» запускает полный цикл Bot::tick() — сканирование реестра, применение переопределений, ingestion handoff-очередей. Без биржевого исполнения.
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header">Последний тик — трассировка</div>
    <div class="card-body" style="padding:12px 16px;">
      <table style="width:100%;font-size:13px;border-collapse:collapse;">
        {$tickTraceRows}
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header">Накопленная статистика (stats.json)</div>
    <div class="card-body" style="padding:0;">
      <table class="table" style="margin:0;">
        {$statsRows}
      </table>
    </div>
  </div>
</div>

<!-- ── Stop Manager pane ────────────────────────────────────────────── -->
<div id="dh-sm" class="dh-pane">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
    <div class="card">
      <div class="card-header">Stop Manager — Конфигурация</div>
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
        <div style="margin-top:10px;">
          <a href="{$smPageUrl}" class="btn btn-sm" style="background:rgba(88,166,255,.12);color:#58a6ff;border:1px solid #58a6ff55;padding:5px 14px;font-size:12px;">
            <i class="bi bi-arrow-right" style="margin-right:4px;"></i>Полный интерфейс
          </a>
        </div>
      </div>
    </div>
    <div class="card">
      <div class="card-header">Stop Manager — Runtime</div>
      <div class="card-body">
        <table style="width:100%;font-size:13px;border-collapse:collapse;">
          <tr><td style="color:var(--ui-text-muted);width:180px;padding:3px 12px 3px 0;">Последний тик</td><td><code>{$smLastTick}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Статус тика</td><td style="color:{$smStatusColor};"><strong>{$smLastStatus}</strong></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Позиций увидено</td><td><code>{$smPosSeen}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">С расчётным liq</td><td><code>{$smEstLiq}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Без liq</td><td><code>{$smNoLiq}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Активных стопов</td><td><code>{$smActiveStops}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Breakeven применено</td><td><code>{$smBeApplied}</code></td></tr>
        </table>
      </div>
    </div>
  </div>
  <div class="card">
    <div class="card-header">Ручное управление</div>
    <div class="card-body">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <!-- SM quick toggle -->
        <form method="post" action="{$smToggleUrl}" style="margin:0;">
          <input type="hidden" name="enabled" value="{$smToggleTarget}">
          <button type="submit" class="btn btn-sm" style="background:{$smToggleBg};color:{$smToggleColor};border:1px solid {$smToggleColor}55;padding:6px 18px;font-weight:600;">
            {$smToggleLabel}
          </button>
        </form>
        <form method="post" action="{$smTickUrl}" style="margin:0;">
          <input type="hidden" name="action" value="tick">
          <button type="submit" class="btn btn-sm" style="background:rgba(167,139,250,.12);color:#a78bfa;border:1px solid #a78bfa55;padding:6px 18px;">
            <i class="bi bi-play-fill" style="margin-right:4px;"></i>Тик стоп-менеджера
          </button>
        </form>
        <form method="post" action="{$smTickUrl}" style="margin:0;">
          <input type="hidden" name="action" value="refresh">
          <button type="submit" class="btn btn-sm" style="background:rgba(139,148,158,.12);color:#8b949e;border:1px solid #8b949e55;padding:6px 18px;">
            <i class="bi bi-arrow-clockwise" style="margin-right:4px;"></i>Обновить runtime
          </button>
        </form>
      </div>
      <div style="margin-top:10px;font-size:11px;color:var(--ui-text-muted);">
        Только paper/local. Биржевые стопы не размещаются.
      </div>
    </div>
  </div>
</div>

<!-- ── Profit Manager pane ──────────────────────────────────────────── -->
<div id="dh-pm" class="dh-pane">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
    <div class="card">
      <div class="card-header">Profit Manager — Статус</div>
      <div class="card-body">
        <table style="width:100%;font-size:13px;border-collapse:collapse;">
          <tr><td style="color:var(--ui-text-muted);width:180px;padding:3px 12px 3px 0;">Включён</td><td>{$pmEnabled}</td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Режим</td><td><code>{$pmMode}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Профиль</td><td><code>{$pmProfile}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Последний тик</td><td><code>{$pmLastTick}</code></td></tr>
        </table>
      </div>
    </div>
    <div class="card">
      <div class="card-header">Profit Manager — Runtime</div>
      <div class="card-body">
        <table style="width:100%;font-size:13px;border-collapse:collapse;">
          <tr><td style="color:var(--ui-text-muted);width:180px;padding:3px 12px 3px 0;">Позиций отслеживается</td><td><code>{$pmPosTracked}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Локов активно</td><td><code>{$pmLocksActive}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Плановых обновлений</td><td><code>{$pmPlanned}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Пропущено</td><td><code>{$pmSkipped}</code></td></tr>
          {$pmDiagRows}
          {$pmLastErrorRow}
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Cron-обработчик</td><td><code>{$pmCronPath}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Интервал крона</td><td>{$pmCronInterval} сек</td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Статус токена</td><td>{$pmCronStatusText}</td></tr>
        </table>
        {$pmRuntimeNote}
      </div>
    </div>
  </div>
  <div class="card">
    <div class="card-header">Ручное управление</div>
    <div class="card-body">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <form method="post" action="{$pmToggleUrl}" style="margin:0;">
          <input type="hidden" name="enabled" value="{$pmToggleTarget}">
          <button type="submit" class="btn btn-sm" style="background:{$pmToggleBg};color:{$pmToggleColor};border:1px solid {$pmToggleColor}55;padding:6px 18px;font-weight:600;">
            {$pmToggleLabel}
          </button>
        </form>
        <form method="post" action="{$pmTickUrl}" style="margin:0;">
          <input type="hidden" name="action" value="tick">
          <button type="submit" class="btn btn-sm" style="background:rgba(240,136,62,.12);color:#f0883e;border:1px solid #f0883e55;padding:6px 18px;">
            <i class="bi bi-play-fill" style="margin-right:4px;"></i>Тик PM
          </button>
        </form>
        <form method="post" action="{$pmTickUrl}" style="margin:0;">
          <input type="hidden" name="action" value="refresh">
          <button type="submit" class="btn btn-sm" style="background:rgba(139,148,158,.12);color:#8b949e;border:1px solid #8b949e55;padding:6px 18px;">
            <i class="bi bi-arrow-clockwise" style="margin-right:4px;"></i>Обновить runtime
          </button>
        </form>
      </div>
      <div style="margin-top:10px;font-size:11px;color:var(--ui-text-muted);">
        Только paper. PM управляет исключительно прибыльными lock-ами — биржевые ордера не размещаются.
      </div>
    </div>
  </div>
  {$pmPositionsTable}
</div>

<!-- ── Control pane ──────────────────────────────────────────────────── -->
<div id="dh-ctrl" class="dh-pane">
  <div class="card" style="margin-bottom:16px;">
    <div class="card-header">Глобальные настройки бота</div>
    <div class="card-body">
      <form method="post" action="{$globalSaveUrl}">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px 16px;margin-bottom:14px;">
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Бот включён</label>
            <select name="enabled" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
              <option value="1"{$gcfgEnYes}>Да</option>
              <option value="0"{$gcfgEnNo}>Нет</option>
            </select>
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Режим бота</label>
            <select name="mode" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
              <option value="passive"{$gcfgModeP}>passive</option>
              <option value="active"{$gcfgModeA}>active</option>
              <option value="disabled"{$gcfgModeD}>disabled</option>
            </select>
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Вход по умолчанию</label>
            <select name="default_entry_mode" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
              <option value=""{$gcfgEntN}>— из сигнала —</option>
              <option value="limit"{$gcfgEntL}>limit</option>
              <option value="market"{$gcfgEntM}>market</option>
            </select>
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Бюджет глоб. (0=из стратегии)</label>
            <input type="number" step="0.01" min="0" name="max_bot_budget" value="{$e($cfgMaxBudget)}" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Плечо глоб. (0=из стратегии)</label>
            <input type="number" step="1" min="0" name="max_bot_leverage" value="{$e($cfgMaxLeverage)}" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Макс. позиций (0=∞)</label>
            <input type="number" step="1" min="0" name="default_max_active_positions" value="{$e($cfgDefMaxPos)}" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
          </div>
        </div>
        <button type="submit" class="btn btn-sm btn-primary">Сохранить</button>
      </form>
      <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--ui-border);">
        <table style="font-size:12px;width:100%;border-collapse:collapse;">
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;white-space:nowrap;width:220px;">Допустимые режимы входа</td><td><code>{$cfgEntryModes}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Макс. возраст сигнала (сек)</td><td><code>{$e($cfgMaxAgeSec)}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Окно дедупликации (сек)</td><td><code>{$e($cfgDedupWindow)}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Корни сканирования</td><td><code>{$cfgScanRoots}</code></td></tr>
        </table>
      </div>
    </div>
  </div>

  <!-- Mirrored per-strategy overrides -->
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <span>Переопределения по стратегиям</span>
      <small style="color:var(--ui-text-muted);font-size:11px;">Зеркало: operator_overrides.json · тот же источник истины что и карточки</small>
    </div>
    <div class="card-body" style="padding:0;">
      <table class="table" style="margin:0;font-size:13px;">
        <thead>
          <tr>
            <th>Стратегия</th>
            <th>Состояние</th>
            <th>Режим</th>
            <th>Действие</th>
          </tr>
        </thead>
        <tbody>{$mirrorRows}</tbody>
      </table>
    </div>
  </div>

  <!-- Stop Manager mirrored controls -->
  <div class="card" style="margin-top:16px;">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <span><i class="bi bi-shield-exclamation" style="margin-right:6px;"></i>Stop Manager — Быстрые настройки</span>
      <small style="color:var(--ui-text-muted);font-size:11px;">Зеркало config/active.php · тот же источник истины</small>
    </div>
    <div class="card-body">
      <form method="post" action="{$smConfigSaveUrl}">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px 16px;margin-bottom:14px;">
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Включён</label>
            <select name="enabled" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
              <option value="1"{$smCfgEnYes}>Да</option>
              <option value="0"{$smCfgEnNo}>Нет</option>
            </select>
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Режим</label>
            <select name="mode" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
              <option value="disabled"{$smCfgModeD}>disabled</option>
              <option value="paper"{$smCfgModeP}>paper</option>
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
              value="{$smCfgBuf}" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Breakeven</label>
            <select name="breakeven_enabled" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
              <option value="1"{$smCfgBeEnYes}>Да</option>
              <option value="0"{$smCfgBeEnNo}>Нет</option>
            </select>
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">BE trigger ROI%</label>
            <input type="number" step="0.1" min="0" name="breakeven_trigger_roi"
              value="{$smCfgTrig}" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">BE lock ROI%</label>
            <input type="number" step="0.1" name="breakeven_profit_lock_roi"
              value="{$smCfgLock}" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
          </div>
        </div>
        <button type="submit" class="btn btn-sm btn-primary">Сохранить</button>
      </form>
    </div>
  </div>

  <!-- Profit Manager quick settings -->
  <div class="card" style="margin-top:16px;">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <span><i class="bi bi-graph-up-arrow" style="margin-right:6px;"></i>Profit Manager — Быстрые настройки</span>
      <small style="color:var(--ui-text-muted);font-size:11px;">Зеркало config/active.php · paper-only profit-lock</small>
    </div>
    <div class="card-body">
      <form method="post" action="{$pmConfigSaveUrl}">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px 16px;margin-bottom:14px;">
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Включён</label>
            <select name="enabled" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
              <option value="1"{$pmCfgEnYes}>Да</option>
              <option value="0"{$pmCfgEnNo}>Нет</option>
            </select>
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Режим</label>
            <select name="mode" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
              <option value="paper" selected>paper</option>
            </select>
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Профиль</label>
            <select name="active_profile" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
              <option value="legacy_safe" selected>legacy_safe</option>
            </select>
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Init ROI%</label>
            <input type="number" step="0.1" min="0" name="init_roi"
              value="{$pmCfgInitRoi}" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Activation ROI%</label>
            <input type="number" step="0.1" min="0" name="activation_roi"
              value="{$pmCfgActivRoi}" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Step ROI%</label>
            <input type="number" step="0.1" min="0" name="step_roi"
              value="{$pmCfgStepRoi}" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Lock buffer ROI%</label>
            <input type="number" step="0.1" min="0" name="lock_buffer_roi"
              value="{$pmCfgLockBuf}" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Lock floor ROI%</label>
            <input type="number" step="0.1" min="0" name="lock_floor_roi"
              value="{$pmCfgLockFloor}" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Min update interval (сек)</label>
            <input type="number" step="1" min="1" name="min_update_interval_sec"
              value="{$pmCfgMinInterval}" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Min price distance %</label>
            <input type="number" step="0.01" min="0" name="min_price_distance_pct"
              value="{$pmCfgMinPriceDist}" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Min ROI step%</label>
            <input type="number" step="0.1" min="0" name="min_roi_step"
              value="{$pmCfgMinRoiStep}" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Max updates per run</label>
            <input type="number" step="1" min="1" name="max_updates_per_run"
              value="{$pmCfgMaxUpdates}" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Default tick size</label>
            <input type="number" step="0.00001" min="0.00001" name="default_tick_size"
              value="{$pmCfgTickSize}" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
          </div>
        </div>
        <button type="submit" class="btn btn-sm btn-primary">Сохранить</button>
      </form>
      <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--ui-border);">
        <table style="font-size:12px;width:100%;border-collapse:collapse;">
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;width:200px;">Cron-обработчик</td><td><code>{$pmCronPath}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Интервал крона</td><td>{$pmCronInterval} сек</td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Статус токена</td><td>{$pmCronStatusText}</td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Последний тик</td><td>{$pmLastTick}</td></tr>
        </table>
      </div>
    </div>
  </div>
</div>

</div><!-- /max-width -->

<script>
function dhTab(btn, panelId) {
    document.querySelectorAll('.dh-tab-btn').forEach(function(b){ b.classList.remove('dh-active'); });
    document.querySelectorAll('.dh-pane').forEach(function(p){ p.classList.remove('dh-visible'); });
    if (btn) { btn.classList.add('dh-active'); }
    var panel = document.getElementById(panelId);
    if (panel) { panel.classList.add('dh-visible'); }
}
function dhSwitchToTab(panelId) {
    var navBtn = document.querySelector('.dh-tab-btn[onclick*="\'' + panelId + '\'"]');
    dhTab(navBtn || null, panelId);
}
function dhToggleEdit(id) {
    var el = document.getElementById(id);
    if (el) { el.style.display = el.style.display === 'none' ? 'block' : 'none'; }
}
function dhResetRuntime() {
    if (!confirm('Вы уверены? Это удалит все текущие paper позиции и runtime PM данные.')) {
        return;
    }
    fetch('{$resetRuntimeUrl}', {
        method: 'POST',
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        credentials: 'same-origin'
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        if (d && d.ok) {
            window.location.reload();
        } else {
            alert('Ошибка сброса: ' + (d && d.error ? d.error : 'неизвестная ошибка'));
        }
    })
    .catch(function(){ alert('Не удалось выполнить сброс. Повторите попытку.'); });
}
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

    if (!in_array($mode, ['passive', 'active', 'disabled'], true)) {
        $mode = 'passive';
    }

    // Preserve all bot-owned execution fields from existing override; do not clobber
    // them — they are managed via the Bot/Control tab, not the strategy card form.
    $prev = (array)($overrides[$stratId] ?? []);
    $overrides[$stratId] = array_merge($prev, [
        'enabled' => (bool)$enabled,
        'mode'    => $mode,
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

// ──────────────────────────────────────────────────────────────────────────────
// POST handler: manual strategy actions (queue_run / tick_batch / refresh)
// Registered as: POST /admin/dashboard/strategy/action
// ──────────────────────────────────────────────────────────────────────────────
if (!function_exists('handleDashboardStrategyAction')) {
function handleDashboardStrategyAction(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!\Core\Auth\Auth::check()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $stratId = trim((string)($_POST['strategy_id'] ?? ''));
    $action  = trim((string)($_POST['action']      ?? ''));
    $dashUrl = System::web('admin/dashboard');

    if ($stratId === '' || $action === '') {
        $_SESSION['dashboard_flash'] = ['type' => 'error', 'msg' => 'strategy_id или action не указаны'];
        header('Location: ' . $dashUrl);
        exit;
    }

    // Route by strategy_id to its service
    // Currently only double_bottom_long is wired; extend here as more strategies are added.
    if ($stratId === 'double_bottom_long') {
        $moduleDir = \Core\System\SystemPaths::instance()->get('strategy.double_bottom_long');

        require_once $moduleDir . '/bootstrap.php';
        require_once $moduleDir . '/service.php';

        $service = \Modules\Strategy\DoubleBottomLong\DoubleBottomLongService::instance($moduleDir);

        if ($action === 'queue_run') {
            $result = $service->queueRun();
            $msg = $result['ok']
                ? 'Запуск цикла поставлен в очередь (' . ($result['total'] ?? 0) . ' символов)'
                : ('Ошибка: ' . ($result['error'] ?? 'Неизвестная'));
            $_SESSION['dashboard_flash'] = ['type' => $result['ok'] ? 'success' : 'error', 'msg' => $msg];
            header('Location: ' . $dashUrl);
            exit;
        }

        if ($action === 'tick_batch') {
            try {
                $service->tickBatch();
                $_SESSION['dashboard_flash'] = ['type' => 'success', 'msg' => 'Шаг батча выполнен'];
            } catch (\Throwable $ex) {
                $_SESSION['dashboard_flash'] = ['type' => 'error', 'msg' => 'Ошибка тика батча: ' . $ex->getMessage()];
            }
            header('Location: ' . $dashUrl);
            exit;
        }

        if ($action === 'refresh') {
            // No-op: page reload will re-read fresh storage state
            $_SESSION['dashboard_flash'] = ['type' => 'success', 'msg' => 'Runtime обновлён'];
            header('Location: ' . $dashUrl);
            exit;
        }
    }

    $_SESSION['dashboard_flash'] = ['type' => 'error', 'msg' => "Неизвестная стратегия или действие: {$stratId}/{$action}"];
    header('Location: ' . $dashUrl);
    exit;
}
} // end if (!function_exists('handleDashboardStrategyAction'))

// ──────────────────────────────────────────────────────────────────────────────
// POST handler: manual bot tick / refresh
// Registered as: POST /admin/dashboard/bot/tick
// ──────────────────────────────────────────────────────────────────────────────
if (!function_exists('handleDashboardBotTick')) {
function handleDashboardBotTick(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!\Core\Auth\Auth::check()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $action  = trim((string)($_POST['action'] ?? 'tick'));
    $dashUrl = System::web('admin/dashboard');

    if ($action === 'refresh') {
        // No-op: page reload re-reads storage
        $_SESSION['dashboard_flash'] = ['type' => 'success', 'msg' => 'Bot runtime обновлён'];
        header('Location: ' . $dashUrl);
        exit;
    }

    // action === 'tick' (default)
    $moduleDir = \Core\System\SystemPaths::instance()->get('bot.bot');

    require_once $moduleDir . '/bootstrap.php';
    require_once $moduleDir . '/service.php';

    try {
        $service = \Modules\Bot\BotService::instance($moduleDir);
        $service->tick();
        $_SESSION['dashboard_flash'] = ['type' => 'success', 'msg' => 'Bot tick выполнен'];
    } catch (\Throwable $ex) {
        $_SESSION['dashboard_flash'] = ['type' => 'error', 'msg' => 'Ошибка bot tick: ' . $ex->getMessage()];
    }

    header('Location: ' . $dashUrl);
    exit;
}
} // end if (!function_exists('handleDashboardBotTick'))

// ──────────────────────────────────────────────────────────────────────────────
// POST handler: manual stop_manager tick from dashboard
// Registered as: POST /admin/dashboard/stop-manager/tick
// ──────────────────────────────────────────────────────────────────────────────
if (!function_exists('handleDashboardStopManagerTick')) {
function handleDashboardStopManagerTick(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!\Core\Auth\Auth::check()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $action  = trim((string)($_POST['action'] ?? 'tick'));
    $dashUrl = System::web('admin/dashboard');

    if ($action === 'refresh') {
        $_SESSION['dashboard_flash'] = ['type' => 'success', 'msg' => 'Stop Manager runtime обновлён'];
        header('Location: ' . $dashUrl);
        exit;
    }

    $moduleDir = System::path('root') . '/modules/stop_manager';

    try {
        require_once $moduleDir . '/bootstrap.php';
        require_once $moduleDir . '/service.php';
        $service = \Modules\StopManager\StopManagerService::instance($moduleDir);
        $service->tick();
        $_SESSION['dashboard_flash'] = ['type' => 'success', 'msg' => 'Stop Manager tick выполнен'];
    } catch (\Throwable $ex) {
        $_SESSION['dashboard_flash'] = ['type' => 'error', 'msg' => 'Ошибка Stop Manager tick: ' . $ex->getMessage()];
    }

    header('Location: ' . $dashUrl);
    exit;
}
} // end if (!function_exists('handleDashboardStopManagerTick'))

// ──────────────────────────────────────────────────────────────────────────────
// POST handler: quick strategy enable/disable toggle
// Registered as: POST /admin/dashboard/strategy/toggle
// Only flips the `enabled` flag; all other override fields are preserved.
// ──────────────────────────────────────────────────────────────────────────────
if (!function_exists('handleDashboardStrategyToggle')) {
function handleDashboardStrategyToggle(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!\Core\Auth\Auth::check()) {
        http_response_code(403);
        exit;
    }

    $stratId = trim((string)($_POST['strategy_id'] ?? ''));
    $enabled = (bool)(int)($_POST['enabled'] ?? 0);
    $dashUrl = System::web('admin/dashboard');

    if ($stratId === '') {
        $_SESSION['dashboard_flash'] = ['type' => 'error', 'msg' => 'strategy_id не указан'];
        header('Location: ' . $dashUrl);
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

    $prev = (array)($overrides[$stratId] ?? []);
    $overrides[$stratId] = array_merge($prev, ['enabled' => $enabled]);

    if (!is_dir($storageDir)) {
        mkdir($storageDir, 0755, true);
    }
    file_put_contents($overridesFile, json_encode($overrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $label = $enabled ? 'включена' : 'выключена';
    $_SESSION['dashboard_flash'] = ['type' => 'success', 'msg' => "Стратегия «{$stratId}» {$label}"];
    header('Location: ' . $dashUrl);
    exit;
}
} // end if (!function_exists('handleDashboardStrategyToggle'))

// ──────────────────────────────────────────────────────────────────────────────
// POST handler: quick bot enable/disable toggle
// Registered as: POST /admin/dashboard/bot/toggle
// Only flips the `enabled` flag in modules/bot/config/active.php.
// ──────────────────────────────────────────────────────────────────────────────
if (!function_exists('handleDashboardBotToggle')) {
function handleDashboardBotToggle(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!\Core\Auth\Auth::check()) {
        http_response_code(403);
        exit;
    }

    $enabled    = (bool)(int)($_POST['enabled'] ?? 0);
    $dashUrl    = System::web('admin/dashboard');
    $activeFile = System::path('root') . '/modules/bot/config/active.php';

    $current = [];
    if (file_exists($activeFile)) {
        $loaded = @include $activeFile;
        if (is_array($loaded)) {
            $current = $loaded;
        }
    }

    $current['enabled'] = $enabled;

    $php  = "<?php\n\ndeclare(strict_types=1);\n\n/**\n * Bot Module — Active Config Overrides\n * Written by the admin UI. Edit via the config page.\n */\n\nreturn ";
    $php .= var_export($current, true);
    $php .= ";\n";

    $dir = dirname($activeFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($activeFile, $php);

    $label = $enabled ? 'включён' : 'выключен';
    $_SESSION['dashboard_flash'] = ['type' => 'success', 'msg' => "Бот {$label}"];
    header('Location: ' . $dashUrl);
    exit;
}
} // end if (!function_exists('handleDashboardBotToggle'))

// ──────────────────────────────────────────────────────────────────────────────
// POST handler: quick stop_manager enable/disable toggle
// Registered as: POST /admin/dashboard/stop-manager/toggle
// Only flips the `enabled` flag in modules/stop_manager/config/active.php.
// ──────────────────────────────────────────────────────────────────────────────
if (!function_exists('handleDashboardSmToggle')) {
function handleDashboardSmToggle(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!\Core\Auth\Auth::check()) {
        http_response_code(403);
        exit;
    }

    $enabled    = (bool)(int)($_POST['enabled'] ?? 0);
    $dashUrl    = System::web('admin/dashboard');
    $activeFile = System::path('root') . '/modules/stop_manager/config/active.php';

    $current = [];
    if (file_exists($activeFile)) {
        $loaded = @include $activeFile;
        if (is_array($loaded)) {
            $current = $loaded;
        }
    }

    $current['enabled'] = $enabled;

    $php  = "<?php\n\ndeclare(strict_types=1);\n\n/**\n * Stop Manager Module — Active Config Overrides\n * Written by the admin UI. Edit via the config page.\n */\n\nreturn ";
    $php .= var_export($current, true);
    $php .= ";\n";

    $dir = dirname($activeFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($activeFile, $php);

    $label = $enabled ? 'включён' : 'выключен';
    $_SESSION['dashboard_flash'] = ['type' => 'success', 'msg' => "Stop Manager {$label}"];
    header('Location: ' . $dashUrl);
    exit;
}
} // end if (!function_exists('handleDashboardSmToggle'))

// ──────────────────────────────────────────────────────────────────────────────
// POST handler: one-button chain run
// Registered as: POST /admin/dashboard/chain-run
// Order: enabled strategy batch ticks → bot tick → stop_manager tick
// ──────────────────────────────────────────────────────────────────────────────
if (!function_exists('handleDashboardChainRun')) {
function handleDashboardChainRun(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!\Core\Auth\Auth::check()) {
        http_response_code(403);
        exit;
    }

    $dashUrl = System::web('admin/dashboard');
    $steps   = [];
    $hasErr  = false;

    // ── Step 1: strategy batch ticks for enabled strategies with manual runtime ──
    $storageDir    = System::path('root') . '/modules/bot/storage';
    $registryFile  = $storageDir . '/strategy_registry.json';
    $overridesFile = $storageDir . '/operator_overrides.json';

    $registry  = [];
    $overrides = [];
    if (file_exists($registryFile)) {
        $raw = file_get_contents($registryFile);
        if ($raw !== false) {
            $dec = json_decode($raw, true);
            if (is_array($dec)) {
                $registry = $dec;
            }
        }
    }
    if (file_exists($overridesFile)) {
        $raw = file_get_contents($overridesFile);
        if ($raw !== false) {
            $dec = json_decode($raw, true);
            if (is_array($dec)) {
                $overrides = $dec;
            }
        }
    }

    // Strategies wired for manual runtime
    $manualStrategyIds = ['double_bottom_long'];

    foreach ($registry as $rec) {
        $sid = (string)($rec['strategy_id'] ?? '');
        if (!in_array($sid, $manualStrategyIds, true)) {
            continue;
        }
        $op      = (array)($overrides[$sid] ?? []);
        $enabled = $op['enabled'] ?? true;
        if (!$enabled) {
            $steps[] = "Стратегия «{$sid}»: пропущена (выключена)";
            continue;
        }

        $moduleDir = \Core\System\SystemPaths::instance()->get('strategy.' . $sid);
        try {
            require_once $moduleDir . '/bootstrap.php';
            require_once $moduleDir . '/service.php';
            $svcClass = match ($sid) {
                'double_bottom_long' => \Modules\Strategy\DoubleBottomLong\DoubleBottomLongService::class,
                default              => null,
            };
            if ($svcClass === null) {
                $steps[] = "Стратегия «{$sid}»: сервис не подключён";
                continue;
            }
            /** @var object $svc */
            $svc = $svcClass::instance($moduleDir);
            $svc->tickBatch();
            $steps[] = "Стратегия «{$sid}»: тик батча выполнен";
        } catch (\Throwable $ex) {
            $steps[]  = "Стратегия «{$sid}»: ошибка — " . $ex->getMessage();
            $hasErr   = true;
        }
    }

    // ── Step 2: bot tick ──────────────────────────────────────────────────────
    $botDir = \Core\System\SystemPaths::instance()->get('bot.bot');
    try {
        require_once $botDir . '/bootstrap.php';
        require_once $botDir . '/service.php';
        $botSvc = \Modules\Bot\BotService::instance($botDir);
        $botSvc->tick();
        $steps[] = 'Бот: тик выполнен';
    } catch (\Throwable $ex) {
        $steps[] = 'Бот: ошибка — ' . $ex->getMessage();
        $hasErr  = true;
    }

    // ── Step 3: stop_manager tick ────────────────────────────────────────────
    $smDir = System::path('root') . '/modules/stop_manager';
    try {
        require_once $smDir . '/bootstrap.php';
        require_once $smDir . '/service.php';
        $smSvc = \Modules\StopManager\StopManagerService::instance($smDir);
        $smSvc->tick();
        $steps[] = 'Stop Manager: тик выполнен';
    } catch (\Throwable $ex) {
        $steps[] = 'Stop Manager: ошибка — ' . $ex->getMessage();
        $hasErr  = true;
    }

    $summary = implode(' · ', $steps);
    $_SESSION['dashboard_flash'] = [
        'type' => $hasErr ? 'error' : 'success',
        'msg'  => 'Цепочка: ' . $summary,
    ];

    header('Location: ' . $dashUrl);
    exit;
}
} // end if (!function_exists('handleDashboardChainRun'))

// ──────────────────────────────────────────────────────────────────────────────
// POST handler: manual Profit Manager tick from dashboard
// Registered as: POST /admin/dashboard/profit-manager/tick
// ──────────────────────────────────────────────────────────────────────────────
if (!function_exists('handleDashboardPmTick')) {
function handleDashboardPmTick(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!\Core\Auth\Auth::check()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }

    $action  = trim((string)($_POST['action'] ?? 'tick'));
    $dashUrl = System::web('admin/dashboard');

    if ($action === 'refresh') {
        $_SESSION['dashboard_flash'] = ['type' => 'success', 'msg' => 'Profit Manager runtime обновлён'];
        header('Location: ' . $dashUrl);
        exit;
    }

    $moduleDir = System::path('root') . '/modules/prof_manager';

    try {
        if (is_file($moduleDir . '/bootstrap.php')) {
            require_once $moduleDir . '/bootstrap.php';
        }
        require_once $moduleDir . '/service.php';
        $service = new \Modules\ProfManager\ProfManagerService($moduleDir);
        $service->setEnabled(true);
        $service->tick();
        $_SESSION['dashboard_flash'] = ['type' => 'success', 'msg' => 'Profit Manager tick выполнен'];
    } catch (\Throwable $ex) {
        $_SESSION['dashboard_flash'] = ['type' => 'error', 'msg' => 'Ошибка Profit Manager tick: ' . $ex->getMessage()];
    }

    header('Location: ' . $dashUrl);
    exit;
}
} // end if (!function_exists('handleDashboardPmTick'))

// ──────────────────────────────────────────────────────────────────────────────
// POST handler: quick Profit Manager enable/disable toggle
// Registered as: POST /admin/dashboard/profit-manager/toggle
// Persists the `enabled` flag to modules/prof_manager/config/active.php.
// ──────────────────────────────────────────────────────────────────────────────
if (!function_exists('handleDashboardPmToggle')) {
function handleDashboardPmToggle(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!\Core\Auth\Auth::check()) {
        http_response_code(403);
        exit;
    }

    $enabled    = (bool)(int)($_POST['enabled'] ?? 0);
    $dashUrl    = System::web('admin/dashboard');
    $activeFile = System::path('root') . '/modules/prof_manager/config/active.php';

    $current = [];
    if (file_exists($activeFile)) {
        $loaded = @include $activeFile;
        if (is_array($loaded)) {
            $current = $loaded;
        }
    }

    $current['enabled'] = $enabled;

    $php  = "<?php\n\ndeclare(strict_types=1);\n\n/**\n * Profit Manager Module — Active Config Overrides\n * Written by the admin UI.\n */\n\nreturn ";
    $php .= var_export($current, true);
    $php .= ";\n";

    $dir = dirname($activeFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($activeFile, $php);

    $label = $enabled ? 'включён' : 'выключен';
    $_SESSION['dashboard_flash'] = ['type' => 'success', 'msg' => "Profit Manager {$label}"];
    header('Location: ' . $dashUrl);
    exit;
}
} // end if (!function_exists('handleDashboardPmToggle'))

// ──────────────────────────────────────────────────────────────────────────────
// POST handler: save Profit Manager quick settings
// Registered as: POST /admin/dashboard/profit-manager/config/save
// Saves enabled, mode, active_profile, and per-profile numeric fields to
// modules/prof_manager/config/active.php. Does not touch Stop Manager.
// ──────────────────────────────────────────────────────────────────────────────
if (!function_exists('handleDashboardPmConfigSave')) {
function handleDashboardPmConfigSave(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!\Core\Auth\Auth::check()) {
        http_response_code(403);
        exit;
    }

    $dashUrl    = System::web('admin/dashboard') . '#dh-ctrl';
    $moduleDir  = System::path('root') . '/modules/prof_manager';
    $activeFile = $moduleDir . '/config/active.php';

    // Load existing active config (preserve unknown keys like cron_token)
    $existing = [];
    if (is_file($activeFile)) {
        try {
            $loaded = @include $activeFile;
            if (is_array($loaded)) {
                $existing = $loaded;
            }
        } catch (\Throwable) {}
    }

    // Load base config to know defaults
    $base = [];
    $baseFile = $moduleDir . '/config/config.php';
    if (is_file($baseFile)) {
        try {
            $b = @include $baseFile;
            if (is_array($b)) {
                $base = $b;
            }
        } catch (\Throwable) {}
    }

    // ── Top-level fields ─────────────────────────────────────────────────
    $existing['enabled']        = (bool)(int)($_POST['enabled'] ?? 0);
    $existing['mode']           = 'paper'; // always paper
    $existing['active_profile'] = trim((string)($_POST['active_profile'] ?? 'legacy_safe'));
    if ($existing['active_profile'] === '') {
        $existing['active_profile'] = 'legacy_safe';
    }

    // ── Profile numeric fields ───────────────────────────────────────────
    $profile = $existing['active_profile'];

    $numericFields = [
        'init_roi'                => ['step' => 0.1,    'min' => 0.0],
        'activation_roi'          => ['step' => 0.1,    'min' => 0.0],
        'step_roi'                => ['step' => 0.1,    'min' => 0.0],
        'lock_buffer_roi'         => ['step' => 0.1,    'min' => 0.0],
        'lock_floor_roi'          => ['step' => 0.1,    'min' => 0.0],
        'min_update_interval_sec' => ['step' => 1,      'min' => 1.0, 'int' => true],
        'min_price_distance_pct'  => ['step' => 0.01,   'min' => 0.0],
        'min_roi_step'            => ['step' => 0.1,    'min' => 0.0],
        'max_updates_per_run'     => ['step' => 1,      'min' => 1.0, 'int' => true],
        'default_tick_size'       => ['step' => 0.00001,'min' => 0.00001],
    ];

    $baseProfileCfg = $base['profiles'][$profile] ?? [];
    $profileData    = $existing['profiles'][$profile] ?? $baseProfileCfg;

    foreach ($numericFields as $field => $rules) {
        if (isset($_POST[$field])) {
            $raw = (float) $_POST[$field];
            if ($raw < $rules['min']) {
                $raw = (float) $rules['min'];
            }
            $profileData[$field] = isset($rules['int']) ? (int) $raw : $raw;
        }
    }

    if (!isset($existing['profiles'])) {
        $existing['profiles'] = [];
    }
    $existing['profiles'][$profile] = $profileData;

    // ── Persist ──────────────────────────────────────────────────────────
    $php  = "<?php\n\ndeclare(strict_types=1);\n\n";
    $php .= "/**\n * Profit Manager Module — Active Config Overrides\n";
    $php .= " * Written by the admin UI. Do not edit manually.\n */\n\n";
    $php .= "return ";
    $php .= var_export($existing, true);
    $php .= ";\n";

    $dir = dirname($activeFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($activeFile, $php);

    $_SESSION['dashboard_flash'] = ['type' => 'success', 'msg' => 'Profit Manager настройки сохранены'];
    header('Location: ' . $dashUrl);
    exit;
}
} // end if (!function_exists('handleDashboardPmConfigSave'))

// ──────────────────────────────────────────────────────────────────────────────
// POST handler: reset paper runtime data (active_positions + PM runtime files)
// Registered as: POST /admin/dashboard/reset-runtime
// ──────────────────────────────────────────────────────────────────────────────
if (!function_exists('handleDashboardResetRuntime')) {
function handleDashboardResetRuntime(): void
{
    header('Content-Type: application/json');

    if (!\Core\Auth\Auth::check()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }

    // Fixed absolute paths — no user input, no path injection possible
    $root = defined('ROOT') ? rtrim(ROOT, '/') : dirname(__DIR__, 2);

    $files = [
        $root . '/modules/bot/storage/active_positions.json' => '[]',
        $root . '/modules/prof_manager/storage/runtime/last_run.json' => json_encode([
            'ok'                 => true,
            'positions'          => 0,
            'valid_positions'    => 0,
            'invalid_positions'  => 0,
            'skip_reason'        => '',
            'positions_runtime'  => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        $root . '/modules/prof_manager/storage/runtime/positions_state.json' => '{}',
        $root . '/modules/prof_manager/storage/runtime/locks.json'           => '{}',
    ];

    foreach ($files as $path => $content) {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);
    }

    echo json_encode(['ok' => true]);
    exit;
}
} // end if (!function_exists('handleDashboardResetRuntime'))
