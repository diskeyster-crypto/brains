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
    $pmMode          = (string) ($pmStatus['mode']          ?? 'demo');
    $pmAccount       = (string) ($pmStatus['account']       ?? 'bybit_demo');
    $pmProfile       = 'AUTO';
    $pmLongProfile   = (string) ($pmStatus['long_profile']  ?? 'legacy_safe_long');
    $pmShortProfile  = (string) ($pmStatus['short_profile'] ?? 'unavailable');
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

    $pmTickUrl         = System::web('admin/dashboard');
    $pmToggleUrl       = System::web('admin/dashboard');
    $pmConfigSaveUrl   = System::web('admin/dashboard');

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

    $pmCfgProfile   = 'auto';
    $pmCfgProfileCfg = $pmCfg['profiles']['long'] ?? [];

    $pmCfgEnYes = ($pmCfg['enabled'] ?? false) ? ' selected' : '';
    $pmCfgEnNo  = !($pmCfg['enabled'] ?? false) ? ' selected' : '';
    $pmCfgModeDemoSel = ((string)($pmCfg['mode'] ?? 'demo') !== 'live') ? ' selected' : '';
    $pmCfgModeLiveSel = ((string)($pmCfg['mode'] ?? 'demo') === 'live') ? ' selected' : '';

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

    // ── Lazy storage init for discovered pattern strategies ───────────────
    // Ensures storage files exist before any render or run attempt.
    // Files are only created if absent; existing content is never overwritten.
    {
        $lazyStorageDefaults = [
            'signals.json'    => '[]',
            'candidates.json' => '[]',
            'runtime.json'    => '{}',
            'last_run.json'   => '{}',
        ];
        foreach ($registry as $_lzRec) {
            $_lzPath = (string)($_lzRec['module_path'] ?? '');
            if ($_lzPath === '') {
                continue;
            }
            $_lzStorageDir = System::path('root') . '/' . $_lzPath . '/storage';
            if (!is_dir($_lzStorageDir)) {
                @mkdir($_lzStorageDir, 0755, true);
            }
            foreach ($lazyStorageDefaults as $_lzFile => $_lzDefault) {
                $_lzFilePath = $_lzStorageDir . '/' . $_lzFile;
                if (!file_exists($_lzFilePath)) {
                    @file_put_contents($_lzFilePath, $_lzDefault);
                }
            }
        }
        unset($_lzRec, $_lzPath, $_lzStorageDir, $_lzFile, $_lzDefault, $_lzFilePath);
    }

    $activeStatuses       = ['queued', 'ready'];
    $queueActiveItems     = array_filter($queue, fn($i) => in_array($i['queue_status'] ?? '', $activeStatuses, true));
    $queueSize            = count($queueActiveItems);
    $queueSubmittedCount  = count(array_filter($queue, fn($i) => ($i['queue_status'] ?? '') === 'submitted'));
    $queueExpiredCount    = count(array_filter($queue, fn($i) => ($i['queue_status'] ?? '') === 'expired'));
    $queueWithdrawnCount  = count(array_filter($queue, fn($i) => ($i['queue_status'] ?? '') === 'withdrawn'));
    $queueRejectedCount   = count(array_filter($queue, fn($i) => ($i['queue_status'] ?? '') === 'rejected'));
    $ordersCount = count($orders);
    $posCount    = count($positions);

    // ── Queue mode-diagnostics (Part 6) ──────────────────────────────────
    // Use current config mode (most up-to-date) for stale detection
    $cfgCurrentMode     = (string)($botConfig['mode'] ?? $botMode);
    $queueReadyLiveCount      = 0;
    $queueSubmittedDemoCount  = 0;
    $queueStaleOtherModeCount = 0;
    foreach ($queue as $qi) {
        $qs   = (string)($qi['queue_status']   ?? '');
        $qm   = (string)($qi['execution_mode'] ?? '');
        if ($qs === 'ready' && $qm === 'live') {
            $queueReadyLiveCount++;
        }
        if ($qs === 'submitted' && $qm === 'demo') {
            $queueSubmittedDemoCount++;
        }
        // Stale: active (queued/ready) items stamped for a different mode
        if (in_array($qs, ['queued', 'ready'], true) && $qm !== '' && $qm !== $cfgCurrentMode) {
            $queueStaleOtherModeCount++;
        }
    }
    // Warning when mode=live but queue contains only old demo-submitted items (no live-ready)
    $staleQueueWarningHtml = '';
    if ($cfgCurrentMode === 'live' && $queueSubmittedDemoCount > 0 && $queueReadyLiveCount === 0) {
        $staleQueueWarningHtml = '<div style="background:rgba(248,81,73,.10);border:1px solid #f8514966;border-radius:8px;padding:10px 16px;margin-bottom:14px;font-size:13px;color:#f85149;">'
            . '<strong>&#9888; Очередь содержит ' . $queueSubmittedDemoCount . ' старых demo submitted элементов. Для live они не исполняются.</strong>'
            . ' Следующий тик бота создаст новые live-элементы из активных сигналов.'
            . '</div>';
    }

    // ── last-run headline fields ──────────────────────────────────────────
    $tickAt     = (string)($lastRun['tick_at']    ?? '—');
    $tickStatus = (string)($lastRun['status']     ?? 'never_run');
    $botEnabled = ($lastRun['bot_enabled'] ?? false) ? 'Включён' : 'Выключен';
    $botMode    = (string)($lastRun['bot_mode']   ?? 'demo');

    // ── Demo connection diagnostics from last_run.json ────────────────────
    $lrDemoCredsConfigured = (bool)($lastRun['demo_credentials_configured'] ?? false);
    $lrDemoConnected       = (bool)($lastRun['demo_connected']              ?? false);
    $lrDemoConnError       = (string)($lastRun['demo_connection_error']     ?? '');
    $lrAccount             = (string)($lastRun['account']                   ?? ($botMode === 'demo' ? 'bybit_demo' : 'local'));

    // ── Live connection diagnostics from last_run.json ────────────────────
    $lrLiveAccountId       = (string)($lastRun['live_account_id']        ?? '');
    $lrLiveEnabled         = (bool)($lastRun['live_enabled']             ?? false);
    $lrLiveConnected       = (bool)($lastRun['live_connected']           ?? false);
    $lrLiveConnError       = (string)($lastRun['live_connection_error']  ?? '');
    $lrLiveCredsMissing    = (bool)($lastRun['live_credentials_missing'] ?? false);

    // Also read live config directly for control form
    $cfgLiveAccountId   = (string)($botConfig['account_id']  ?? $lrLiveAccountId);
    $cfgLiveEnabled     = (bool)($botConfig['live_enabled']  ?? false);

    // ── Demo execution diagnostics from last_run.json ─────────────────────
    $lrDemoOrdersPrepared      = (int)($lastRun['demo_orders_prepared']           ?? 0);
    $lrDemoOrdersRejected      = (int)($lastRun['demo_orders_rejected']           ?? 0);
    $lrDemoOrdersSubmitted     = (int)($lastRun['demo_orders_submitted']          ?? 0);
    $lrDemoOrdersConfirmed     = (int)($lastRun['demo_orders_confirmed']          ?? 0);
    $lrDemoLastErrCode         = $lastRun['demo_last_error_code']                 ?? null;
    $lrDemoLastErrMsg          = (string)($lastRun['demo_last_error_msg']         ?? '');
    $lrDemoLastRejectedSym     = (string)($lastRun['demo_last_rejected_symbol']   ?? '');
    $lrDemoQtyInvalidCount     = (int)($lastRun['demo_qty_invalid_count']         ?? 0);
    $lrDemoLevClampedCount     = (int)($lastRun['demo_leverage_clamped_count']    ?? 0);
    $lrDemoSetLevFailedCount   = (int)($lastRun['demo_set_leverage_failed_count'] ?? 0);
    $lrDemoLastReqLev          = $lastRun['demo_last_req_leverage']               ?? null;
    $lrDemoLastEffLev          = $lastRun['demo_last_eff_leverage']               ?? null;
    $lrDemoLastLevSrc          = (string)($lastRun['demo_last_leverage_source']   ?? '');
    $lrDemoLastBudgetSrc       = (string)($lastRun['demo_last_budget_source']     ?? '');
    $lrDemoLastSetLevNote      = (string)($lastRun['demo_last_set_lev_note']      ?? '');
    $lrDemoLastSetLevCode      = $lastRun['demo_last_set_lev_code']               ?? null;
    $lrDemoLastSetLevMsg       = (string)($lastRun['demo_last_set_lev_msg']       ?? '');
    $lrDemoLevMismatchCount    = (int)($lastRun['demo_leverage_mismatch_count']   ?? 0);

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
    $saveUrl        = System::web('admin/dashboard');
    $stratActUrl    = System::web('admin/dashboard');
    $stratToggleUrl = System::web('admin/dashboard');
    $botToggleUrl   = System::web('admin/dashboard');
    $smToggleUrl    = System::web('admin/dashboard');
    $chainRunUrl    = System::web('admin/dashboard');
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
            // Mode: use stored override if present; fall back to manifest default_mode; then 'demo'
            $defaultMode = (string)($rec['default_mode'] ?? 'demo');
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
            // Read handoff_enabled from operator_overrides; fall back to strategy's own config.
            // double_bottom_long always has handoff active (its service writes bot_handoff_queue natively).
            $opHandoffEnabled = null;
            if (array_key_exists('handoff_enabled', $op)) {
                $opHandoffEnabled = (bool)$op['handoff_enabled'];
            } else {
                // Try strategy's own active.php first, then base.php
                if ($modulePath !== '') {
                    $_hoModDir = System::path('root') . '/' . $modulePath;
                    foreach (['config/active.php', 'config/base.php'] as $_hoCfg) {
                        $_hoCfgPath = $_hoModDir . '/' . $_hoCfg;
                        if (is_file($_hoCfgPath)) {
                            $_hoCfgData = @include $_hoCfgPath;
                            if (is_array($_hoCfgData) && array_key_exists('handoff_enabled', $_hoCfgData)) {
                                $opHandoffEnabled = (bool)$_hoCfgData['handoff_enabled'];
                                break;
                            }
                        }
                    }
                }
                if ($opHandoffEnabled === null) {
                    // double_bottom_long: handoff is always on in its service
                    $opHandoffEnabled = ($stratId === 'double_bottom_long');
                }
            }
            $handoffStr = $opHandoffEnabled
                ? '<span style="color:#3fb950;">Да</span>'
                : '<span style="color:#8b949e;">Нет</span>';
            $signalStr = ($signalCount !== null) ? $e((string)$signalCount) : '—';

            // Options: mode select
            $modeDemo = $opMode !== 'live' ? ' selected' : '';
            $modeLive = $opMode === 'live' ? ' selected' : '';

            // Options: enabled select
            $enYes = $opEnabled ? ' selected' : '';
            $enNo  = $opEnabled ? '' : ' selected';

            // Options: handoff_enabled select
            $enHandoffYes = $opHandoffEnabled ? 'selected' : '';
            $enHandoffNo  = $opHandoffEnabled ? '' : 'selected';

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
            $slrHandoffReady = (int)($stratLastRun['bot_handoff_ready_total'] ?? $stratLastRun['handoff_ready'] ?? $stratSignals[$stratId] ?? 0);
            // For corridor_bottom_long: prefer generated_signals_count; for others: emitted total
            $slrGeneratedSig = (int)($stratLastRun['generated_signals_count']                   ?? $slrEmitted);
            $slrHasData      = $stratLastRun !== [];

            // ── corridor_bottom_long: remap top-card fields from its own last_run keys ──
            if ($stratId === 'corridor_bottom_long' && $slrHasData) {
                // candidates = how many symbols entered the candidate zone
                $slrCandidates   = (int)($stratLastRun['candidates_found']   ?? 0);
                // pool = candidates currently waiting for validation
                $slrPoolTotal    = (int)($stratLastRun['candidates_waiting'] ?? 0);
                // Runtime line: corridor has no run_state.json; derive from last_run
                $rsStatus   = $e((string)($stratLastRun['status']      ?? 'idle'));
                $rsCursor   = (int)($stratLastRun['symbols_checked']   ?? 0);
                $rsTotal    = (int)($stratLastRun['symbols_checked']   ?? 0);
                $rsLastTick = $e((string)($stratLastRun['finished_at'] ?? $stratLastRun['started_at'] ?? '—'));
            }

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
            if ($stratId === 'double_bottom_long') {
                $actionButtonsHtml = <<<BTN
      <form method="post" action="{$stratActUrl}" style="margin:0;">
        <input type="hidden" name="dashboard_action" value="strategy_action">
        <input type="hidden" name="strategy_id" value="{$esId}">
        <input type="hidden" name="action" value="queue_run">
        <input type="hidden" name="active_tab" value="dh-strat">
        <button type="submit" class="btn btn-sm" style="background:rgba(63,185,80,.12);color:#3fb950;border:1px solid #3fb95055;">
          Запуск цикла
        </button>
      </form>
      <form method="post" action="{$stratActUrl}" style="margin:0;">
        <input type="hidden" name="dashboard_action" value="strategy_action">
        <input type="hidden" name="strategy_id" value="{$esId}">
        <input type="hidden" name="action" value="tick_batch">
        <input type="hidden" name="active_tab" value="dh-strat">
        <button type="submit" class="btn btn-sm" style="background:rgba(88,166,255,.12);color:#58a6ff;border:1px solid #58a6ff55;">
          Тик батча
        </button>
      </form>
      <form method="post" action="{$stratActUrl}" style="margin:0;">
        <input type="hidden" name="dashboard_action" value="strategy_action">
        <input type="hidden" name="strategy_id" value="{$esId}">
        <input type="hidden" name="action" value="refresh">
        <input type="hidden" name="active_tab" value="dh-strat">
        <button type="submit" class="btn btn-sm" style="background:rgba(139,148,158,.12);color:#8b949e;border:1px solid #8b949e55;">
          Обновить runtime
        </button>
      </form>
BTN;
            } elseif ($stratId === 'corridor_bottom_long') {
                // Buttons enabled only when strategy is enabled=true and mode=demo
                $cblRunAvailable = $opEnabled && $opMode === 'demo';
                if ($cblRunAvailable) {
                    $actionButtonsHtml = <<<BTN
      <form method="post" action="{$stratActUrl}" style="margin:0;">
        <input type="hidden" name="dashboard_action" value="strategy_action">
        <input type="hidden" name="strategy_id" value="{$esId}">
        <input type="hidden" name="action" value="queue_run">
        <input type="hidden" name="active_tab" value="dh-strat">
        <button type="submit" class="btn btn-sm" style="background:rgba(63,185,80,.12);color:#3fb950;border:1px solid #3fb95055;">
          Запуск цикла
        </button>
      </form>
      <form method="post" action="{$stratActUrl}" style="margin:0;">
        <input type="hidden" name="dashboard_action" value="strategy_action">
        <input type="hidden" name="strategy_id" value="{$esId}">
        <input type="hidden" name="action" value="tick_batch">
        <input type="hidden" name="active_tab" value="dh-strat">
        <button type="submit" class="btn btn-sm" style="background:rgba(88,166,255,.12);color:#58a6ff;border:1px solid #58a6ff55;">
          Тик батча
        </button>
      </form>
      <form method="post" action="{$stratActUrl}" style="margin:0;">
        <input type="hidden" name="dashboard_action" value="strategy_action">
        <input type="hidden" name="strategy_id" value="{$esId}">
        <input type="hidden" name="action" value="refresh">
        <input type="hidden" name="active_tab" value="dh-strat">
        <button type="submit" class="btn btn-sm" style="background:rgba(139,148,158,.12);color:#8b949e;border:1px solid #8b949e55;">
          Обновить runtime
        </button>
      </form>
BTN;
                } else {
                    $_cblDisabledReason = !$opEnabled ? 'Стратегия отключена — нажмите Включить' : 'Требуется mode=demo';
                    $actionButtonsHtml = <<<BTN
      <button type="button" disabled class="btn btn-sm" style="opacity:.4;cursor:not-allowed;border:1px solid var(--ui-border);color:var(--ui-text-muted);"
        title="{$_cblDisabledReason}">Запуск цикла</button>
      <button type="button" disabled class="btn btn-sm" style="opacity:.4;cursor:not-allowed;border:1px solid var(--ui-border);color:var(--ui-text-muted);"
        title="{$_cblDisabledReason}">Тик батча</button>
BTN;
                }
            } else {
                $actionButtonsHtml = <<<BTN
      <button type="button" disabled class="btn btn-sm" style="opacity:.4;cursor:not-allowed;border:1px solid var(--ui-border);color:var(--ui-text-muted);"
        title="Ручной запуск не поддерживается для этой стратегии">Запуск цикла</button>
      <button type="button" disabled class="btn btn-sm" style="opacity:.4;cursor:not-allowed;border:1px solid var(--ui-border);color:var(--ui-text-muted);"
        title="Ручной запуск не поддерживается для этой стратегии">Тик батча</button>
BTN;
            }

            // Handoff quick-toggle button (shows action to flip handoff_enabled)
            $_hoTarget = $opHandoffEnabled ? '0' : '1';
            $_hoLabel  = $opHandoffEnabled ? 'Отключить handoff' : 'Включить handoff';
            $_hoBg     = $opHandoffEnabled ? 'rgba(248,81,73,.10)' : 'rgba(167,139,250,.10)';
            $_hoColor  = $opHandoffEnabled ? '#f85149' : '#a78bfa';
            $handoffToggleHtml = <<<HTG
      <form method="post" action="{$stratToggleUrl}" style="margin:0;">
        <input type="hidden" name="dashboard_action" value="strategy_handoff_toggle">
        <input type="hidden" name="strategy_id" value="{$esId}">
        <input type="hidden" name="handoff_enabled" value="{$_hoTarget}">
        <input type="hidden" name="active_tab" value="dh-strat">
        <button type="submit" class="btn btn-sm" style="background:{$_hoBg};color:{$_hoColor};border:1px solid {$_hoColor}55;">
          {$_hoLabel}
        </button>
      </form>
HTG;

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
          · сигналов <code>{$slrGeneratedSig}</code>
          · активных <code>{$slrPoolTotal}</code>
          · handoff-ready <code>{$slrHandoffReady}</code>
        </td>
      </tr>
    </table>

    <!-- Toggle edit / manual action buttons -->
    <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <!-- Quick enable/disable toggle -->
      <form method="post" action="{$stratToggleUrl}" style="margin:0;">
        <input type="hidden" name="dashboard_action" value="strategy_toggle">
        <input type="hidden" name="strategy_id" value="{$esId}">
        <input type="hidden" name="enabled" value="{$toggleTarget}">
        <input type="hidden" name="active_tab" value="dh-strat">
        <button type="submit" class="btn btn-sm" style="background:{$toggleBg};color:{$toggleColor};border:1px solid {$toggleColor}55;font-weight:600;">
          {$toggleLabel}
        </button>
      </form>
      <button type="button" class="btn btn-sm btn-primary" onclick="dhToggleEdit('{$cardId}')">
        Изменить
      </button>
      {$handoffToggleHtml}
      {$actionButtonsHtml}
    </div>

    <!-- Inline edit form (hidden by default) -->
    <div id="{$cardId}" style="display:none;margin-top:14px;padding-top:14px;border-top:1px solid var(--ui-border);">
      <form method="post" action="{$saveUrl}">
        <input type="hidden" name="dashboard_action" value="overrides_save">
        <input type="hidden" name="strategy_id" value="{$esId}">
        <input type="hidden" name="active_tab" value="dh-strat">
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
              <option value="demo"{$modeDemo}>demo</option>
              <option value="live"{$modeLive}>live</option>
            </select>
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Handoff (передавать боту)</label>
            <select name="handoff_enabled" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
              <option value="1" {$enHandoffYes}>Да</option>
              <option value="0" {$enHandoffNo}>Нет</option>
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
    $cfgMode        = $e($botConfig['mode']              ?? 'demo');
    $cfgMaxBudget   = (float)($botConfig['max_bot_budget']   ?? 0.0);
    $cfgMaxLeverage = (int)($botConfig['max_bot_leverage']   ?? 0);
    $cfgDefEntry    = $e($botConfig['default_entry_mode']    ?? '');
    $cfgDefMaxPos   = (int)($botConfig['default_max_active_positions'] ?? 0);
    $cfgEntryModes  = $e(implode(', ', (array)($botConfig['allowed_entry_modes'] ?? [])));
    $cfgMaxAgeSec   = (int)($botConfig['max_signal_age_sec'] ?? 0);
    $cfgDedupWindow = (int)($botConfig['queue_dedup_ttl_sec'] ?? 0);
    $cfgScanRoots   = $e(implode(', ', (array)($botConfig['strategy_scan_roots'] ?? [])));

    // Demo credentials status (never display actual secret value)
    $cfgDemoApiKey    = (string)($botConfig['demo_api_key']      ?? '');
    $cfgDemoApiSecret = (string)($botConfig['demo_api_secret']   ?? '');
    $cfgDemoBaseUrl   = $e($botConfig['demo_api_base_url'] ?? 'https://api-demo.bybit.com');
    $cfgDemoKeyStatus    = $cfgDemoApiKey    !== '' ? '<span style="color:#3fb950;">&#10003; настроен</span>' : '<span style="color:#f85149;">&#10007; не задан</span>';
    $cfgDemoSecretStatus = $cfgDemoApiSecret !== '' ? '<span style="color:#3fb950;">&#10003; настроен</span>' : '<span style="color:#f85149;">&#10007; не задан</span>';

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
    $gcfgModeDe = $cfgMode !== 'live' ? ' selected' : '';
    $gcfgModeLv = $cfgMode === 'live' ? ' selected' : '';
    $gcfgEntN   = $cfgDefEntry === ''       ? ' selected' : '';
    $gcfgEntL   = $cfgDefEntry === 'limit'  ? ' selected' : '';
    $gcfgEntM   = $cfgDefEntry === 'market' ? ' selected' : '';

    $globalSaveUrl = System::web('admin/dashboard');

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
        $mDefaultMode = (string)($rec['default_mode'] ?? 'demo');
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
            . '<input type="hidden" name="dashboard_action" value="strategy_toggle">'
            . '<input type="hidden" name="strategy_id" value="' . $eSid . '">'
            . '<input type="hidden" name="enabled" value="' . $toggleTo . '">'
            . '<input type="hidden" name="active_tab" value="dh-ctrl">'
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
    $botTickUrl = System::web('admin/dashboard');
    $smTickUrl  = System::web('admin/dashboard');
    $smPageUrl  = System::web('admin/stop-manager');

    // ── stop_manager display values ───────────────────────────────────────
    $smEnabled     = ($smConfig['enabled']          ?? false) ? 'Да' : 'Нет';
    $smMode        = (string)($smConfig['mode']      ?? 'demo');
    $smStopMode    = (string)($smConfig['stop_mode'] ?? 'liq_distance_percent');
    $smLiqDist     = (string)($smConfig['liq_distance_percent'] ?? 90);
    $smBeEn        = ($smConfig['breakeven_enabled'] ?? false) ? 'Да' : 'Нет';
    $smBeTrig      = (string)($smConfig['breakeven_trigger_roi']     ?? 10.0);
    $smBeLock      = (string)($smConfig['breakeven_profit_lock_roi'] ?? 3.0);
    $smLastTick    = (string)($smLastRun['tick_at']                      ?? '—');
    $smLastStatus  = (string)($smLastRun['status']                       ?? 'never_run');
    $smPosSeen     = (string)($smLastRun['positions_seen']                ?? 0);
    $smEstLiq      = (string)($smLastRun['positions_with_estimated_liq']  ?? 0);
    $smNoLiq       = (string)($smLastRun['positions_without_liq']         ?? 0);
    $smActiveStops = (string)($smLastRun['stops_active_count']            ?? count(array_filter($smStops, static fn($s) => in_array($s['stop_state'] ?? '', ['active', 'estimated_liq'], true))));
    $smStaleStops  = (string)(count(array_filter($smStops, static fn($s) => ($s['stop_state'] ?? '') === 'stale')));
    $smBeApplied   = (string)($smStats['breakeven_applied_total']         ?? 0);
    $smStatusColor = $smLastStatus === 'ok' ? '#3fb950' : '#8b949e';

    // SM mismatch detection
    $smCfgLiqDistFloat  = (float)($smConfig['liq_distance_percent'] ?? 90.0);
    $smRtLiqDistFloat   = isset($smLastRun['liq_distance_percent']) ? (float)$smLastRun['liq_distance_percent'] : null;
    $smMismatchRow = '';
    if ($smRtLiqDistFloat !== null && abs($smRtLiqDistFloat - $smCfgLiqDistFloat) > 0.01) {
        $smMismatchRow = '<tr><td style="color:#f85149;padding:3px 12px 3px 0;font-weight:600;">⚠ Stop config mismatch</td>'
            . '<td style="color:#f85149;font-size:12px;">config=' . $e($smCfgLiqDistFloat) . '%, runtime=' . $e($smRtLiqDistFloat) . '% — запустите тик SM</td></tr>';
    }

    // Demo stop execution diagnostics
    $smDemoStopsSet         = (int)($smLastRun['demo_stops_set']           ?? 0);
    $smDemoStopsAlreadySet  = (int)($smLastRun['demo_stops_already_set']   ?? 0);
    $smDemoStopsFailed      = (int)($smLastRun['demo_stops_failed']        ?? 0);
    $smDemoStopsSkippedNoGw = (int)($smLastRun['demo_stops_skipped_no_gw'] ?? 0);
    $smDemoLastErrCode      = $smLastRun['demo_last_stop_error_code']      ?? null;
    $smDemoLastErrMsg       = (string)($smLastRun['demo_last_stop_error_msg'] ?? '');
    $smDemoLastSymbol       = (string)($smLastRun['demo_last_stop_symbol']    ?? '');

    // Build demo stop diagnostics card (shown in demo/live mode)
    if ($smMode === 'demo' || $smMode === 'live') {
        $smDemoErrRow = $smDemoLastErrCode !== null
            ? '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Последняя ошибка</td>'
              . '<td><code style="color:#f85149;">[' . $e($smDemoLastErrCode) . '] ' . $e($smDemoLastErrMsg) . '</code></td></tr>'
            : '';
        $smDemoSymRow = $smDemoLastSymbol !== ''
            ? '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Последний символ</td>'
              . '<td><code>' . $e($smDemoLastSymbol) . '</code></td></tr>'
            : '';
        $smDemoNoGwRow = $smDemoStopsSkippedNoGw > 0
            ? '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">&#9888; Нет gateway</td>'
              . '<td><code style="color:#f0883e;">demo_credentials_missing (' . $e($smDemoStopsSkippedNoGw) . ')</code></td></tr>'
            : '';
        $smDemoExecHtml = '<div class="card" style="margin-bottom:16px;">'
            . '<div class="card-header">Demo — стопы на бирже (setTradingStop)</div>'
            . '<div class="card-body">'
            . '<table style="width:100%;font-size:13px;border-collapse:collapse;">'
            . '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;width:200px;">Стопов установлено</td>'
            . '<td><code style="color:#3fb950;">' . $e($smDemoStopsSet) . '</code></td></tr>'
            . '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Уже установлено</td>'
            . '<td><code>' . $e($smDemoStopsAlreadySet) . '</code></td></tr>'
            . '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Ошибок установки</td>'
            . '<td><code style="color:' . ($smDemoStopsFailed > 0 ? '#f85149' : 'inherit') . ';">' . $e($smDemoStopsFailed) . '</code></td></tr>'
            . $smDemoNoGwRow
            . $smDemoErrRow
            . $smDemoSymRow
            . '</table></div></div>';
    } else {
        $smDemoExecHtml = '';
    }

    // ── stop_manager config for Control tab (mirrored) ───────────────────
    $smCfgEnYes  = ($smConfig['enabled'] ?? false) ? ' selected' : '';
    $smCfgEnNo   = !($smConfig['enabled'] ?? false) ? ' selected' : '';
    $smCfgModeDe = $smMode !== 'live' ? ' selected' : '';
    $smCfgModeLi = $smMode === 'live' ? ' selected' : '';
    $smCfgBeEnYes = ($smConfig['breakeven_enabled'] ?? false) ? ' selected' : '';
    $smCfgBeEnNo  = !($smConfig['breakeven_enabled'] ?? false) ? ' selected' : '';
    $smCfgBuf   = $e($smLiqDist);
    $smCfgTrig  = $e($smBeTrig);
    $smCfgLock  = $e($smBeLock);
    $smConfigSaveUrl = System::web('admin/dashboard');

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
    $pmDiagSourceAuthority = (string)($pmRawLastRun['diagnostics']['source_authority'] ?? 'bot_active_positions_demo_cache');

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
        'unsupported_side_short'          => 'Short не поддерживается',
        'unsupported_side'               => 'Сторона не поддерживается',
    ];

    // Normal demo-runtime skip conditions (WARN, not ERR)
    $pmNormalSkipReasons = [
        'below_init_roi', 'below_activation_roi',
        'lock_not_improving', 'lock_price_too_close_to_current',
        'lock_not_on_profit_side', 'roi_step_too_small',
        'update_interval_not_elapsed', 'planned',
        'unsupported_side_short', 'unsupported_side',
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
        $pointer    = $tabId !== '' ? 'cursor:pointer;' : '';
        $dataTarget = $tabId !== '' ? ' data-tab-target="' . htmlspecialchars($tabId, ENT_QUOTES, 'UTF-8') . '" role="button" tabindex="0"' : '';
        return '<div style="' . $pointer . 'display:flex;flex-direction:column;align-items:center;padding:6px 14px;background:' . $bg . ';border:1px solid ' . $clr . '55;border-radius:8px;min-width:80px;"' . $title . $dataTarget . '>'
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

    // ── Mode mismatch warning ─────────────────────────────────────────────
    $modeMismatchHtml = '';
    $botModeForMismatch = $botMode;
    // Only warn when bot is in a meaningful execution mode (live/demo)
    if (in_array($botModeForMismatch, ['live', 'demo'], true)) {
        $mismatchParts = [];
        // SM active and mode differs from bot
        if ($smCurrentlyEnabled && $smMode !== $botModeForMismatch) {
            $mismatchParts[] = 'Stop: ' . strtoupper($smMode);
        }
        // PM active and mode differs from bot
        if ($pmEnabledBool && $pmMode !== $botModeForMismatch) {
            $mismatchParts[] = 'Profit: ' . strtoupper($pmMode);
        }
        if ($mismatchParts !== []) {
            $mismatchMsg = 'Mode mismatch: Bot ' . strtoupper($botModeForMismatch) . ', ' . implode(', ', $mismatchParts);
            $modeMismatchHtml = '<div style="background:rgba(248,81,73,.10);border:1px solid #f8514955;border-radius:8px;padding:10px 16px;margin-bottom:16px;font-size:13px;color:#f85149;">'
                . '<strong>⚠ ' . htmlspecialchars($mismatchMsg, ENT_QUOTES, 'UTF-8') . '</strong>'
                . '<span style="font-size:11px;color:#f85149;margin-left:8px;opacity:.8;">Убедитесь что Stop/Profit Manager настроены на тот же режим что и бот.</span>'
                . '</div>';
        }
    }

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
    $pmDiagRows .= '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Source authority</td>'
        . '<td style="font-size:11px;"><code style="color:#3fb950;">' . $e($pmDiagSourceAuthority) . '</code></td></tr>';
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
            if ($action === 'hybrid_guard_activated' || $action === 'waiting_confirmation') {
                return '#f0883e';
            }
            if ($action === 'hybrid_close_confirmed') {
                return '#3fb950';
            }
            if (str_contains($action, 'hybrid')) {
                return '#a78bfa';
            }
            return '#58a6ff';
        };

        $tableRows = '';
        foreach ($pmPositionsRuntime as $pr) {
            $symbol      = $e((string)($pr['symbol']          ?? ''));
            $side        = $e((string)($pr['side']            ?? ''));
            $profileUsed = $e((string)($pr['profile_used']    ?? ''));
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
            $lockPrice   = isset($pr['lock_price']) && $pr['lock_price'] !== null
                ? number_format((float)$pr['lock_price'], 4)
                : '—';
            $lockActive  = !empty($pr['lock_active']);
            $reasonLabel = $rawReason !== ''
                ? ($pmReasonLabels[$rawReason] ?? $rawReason)
                : '—';
            $actionShort = match (true) {
                $action === 'skip'                          => 'skip',
                $action === 'would_set_profit_lock'         => 'planned demo lock',
                $action === 'would_move_profit_lock'        => 'planned move',
                $action === 'would_close_on_lock_touch'     => 'close',
                $action === 'hybrid_guard_activated'        => 'guard ⬆',
                $action === 'waiting_confirmation'          => 'confirming…',
                $action === 'hybrid_close_confirmed'        => 'hybrid close',
                $action === 'hybrid_rejected'               => 'rejected↩',
                default                                     => $e($action),
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
            // Short positions get a special indicator
            $isShort = ($pr['side'] ?? '') === 'short';
            $reasonDisplay = $isShort
                ? '<span style="color:#8b949e;font-style:italic;">Short not supported yet</span>'
                : $e($reasonLabel);
            $lockDisplay = $lockActive
                ? '<span style="color:#3fb950;">✓ ' . $lockPrice . '</span>'
                : '<span style="color:#8b949e;">' . $lockPrice . '</span>';

            // ── Hybrid fields ─────────────────────────────────────────────────
            $hybridState        = (string) ($pr['hybrid_state']               ?? 'idle');
            $hybridPatDetected  = !empty($pr['hybrid_pattern_detected']);
            $hybridPatternType  = isset($pr['hybrid_pattern_type']) ? (string) $pr['hybrid_pattern_type'] : null;
            $hybridTicks        = (int)   ($pr['hybrid_confirmation_ticks']   ?? 0);
            $hybridConfResult   = isset($pr['hybrid_confirmation_result']) ? (string) $pr['hybrid_confirmation_result'] : null;
            $hybridGuardStop    = $pr['hybrid_guard_stop']    ?? null;
            $hybridGuardActive  = !empty($pr['hybrid_guard_active']);
            $hybridBreathStop   = $pr['hybrid_breathing_stop'] ?? null;
            $hybridBreathActive = !empty($pr['hybrid_breathing_active']);
            $hybridSimEnabled   = !empty($pr['hybrid_simulation_enabled']);
            $hybridScore        = isset($pr['hybrid_detection_score'])    ? (int)    $pr['hybrid_detection_score']    : null;
            $hybridSupport      = isset($pr['hybrid_support_level'])      ? (float)  $pr['hybrid_support_level']      : null;
            $hybridEvidence     = isset($pr['hybrid_detection_evidence']) ? (string) $pr['hybrid_detection_evidence'] : null;
            $hybridReason       = isset($pr['hybrid_detection_reason'])   ? (string) $pr['hybrid_detection_reason']   : null;
            $hybridPriceSrc     = (string) ($pr['hybrid_price_source'] ?? 'none');
            $hybridPricePts     = (int)    ($pr['hybrid_price_points'] ?? 0);
            $hybridMinCloseRoi  = isset($pr['hybrid_min_close_roi'])    ? (float)  $pr['hybrid_min_close_roi']   : null;

            $simBadge = $hybridSimEnabled
                ? ' <span style="font-size:9px;color:#f0883e;background:rgba(240,136,62,.15);border-radius:3px;padding:1px 4px;">SIM</span>'
                : '';

            $hybridStateDisplay = $hybridState !== 'idle'
                ? '<span style="color:#f0883e;font-weight:600;">' . $e($hybridState) . '</span>' . $simBadge
                : '<span style="color:#8b949e;">idle</span>' . $simBadge;

            // Guard/Pattern column: guard price + pattern type + score + support + ticks + detector reason
            if ($hybridGuardActive && $hybridGuardStop !== null) {
                $hybridGuardDisplay = '<span style="color:#f0883e;">⬆ ' . number_format((float)$hybridGuardStop, 4) . '</span>';
                if ($hybridPatternType !== null) {
                    $hybridGuardDisplay .= '<br><span style="font-size:9px;color:#8b949e;">' . $e($hybridPatternType) . '</span>';
                }
                if ($hybridScore !== null) {
                    $hybridGuardDisplay .= '<br><span style="font-size:9px;color:#8b949e;">score:' . $hybridScore . '/5</span>';
                }
                if ($hybridSupport !== null) {
                    $hybridGuardDisplay .= '<br><span style="font-size:9px;color:#8b949e;">sup:' . number_format($hybridSupport, 4) . '</span>';
                }
                $hybridGuardDisplay .= '<br><span style="font-size:9px;color:#8b949e;">ticks:' . $hybridTicks . '</span>';
            } elseif ($hybridTicks > 0 || $hybridScore !== null || $hybridPatternType !== null) {
                $hybridGuardDisplay = '';
                if ($hybridTicks > 0) {
                    $hybridGuardDisplay .= '<span style="color:#8b949e;">ticks:' . $hybridTicks . '</span>';
                }
                if ($hybridPatternType !== null) {
                    $hybridGuardDisplay .= ($hybridGuardDisplay !== '' ? '<br>' : '') . '<span style="font-size:9px;color:#8b949e;">' . $e($hybridPatternType) . '</span>';
                }
                if ($hybridScore !== null) {
                    $hybridGuardDisplay .= ($hybridGuardDisplay !== '' ? '<br>' : '') . '<span style="font-size:9px;color:#8b949e;">score:' . $hybridScore . '/5</span>';
                }
                if ($hybridSupport !== null) {
                    $hybridGuardDisplay .= ($hybridGuardDisplay !== '' ? '<br>' : '') . '<span style="font-size:9px;color:#8b949e;">sup:' . number_format($hybridSupport, 4) . '</span>';
                }
                if ($hybridGuardDisplay === '') {
                    $hybridGuardDisplay = '<span style="color:#8b949e;">—</span>';
                }
            } elseif ($hybridReason !== null) {
                // Show detector reason (e.g. no_candle_data, insufficient_window_data) when idle with no pattern
                $hybridReasonLabel = match ($hybridReason) {
                    'below_hybrid_min_close_roi' => 'ROI ниже минимума Hybrid close'
                        . ($hybridMinCloseRoi !== null ? ' (' . $hybridMinCloseRoi . '%)' : ''),
                    'below_init_roi_hybrid_disabled' => 'ROI ниже init_roi',
                    default => $hybridReason,
                };
                $hybridGuardDisplay = '<span style="font-size:9px;color:#6e7681;">' . $e($hybridReasonLabel) . '</span>';
            } else {
                $hybridGuardDisplay = '<span style="color:#8b949e;">—</span>';
            }

            // Append compact detector price source to Guard/Pattern display
            $srcLabel = match ($hybridPriceSrc) {
                'pm_price_history' => 'pm_hist',
                'parser2'          => 'parser2',
                'simulation'       => 'sim',
                default            => 'none',
            };
            $hybridGuardDisplay .= ($hybridGuardDisplay !== '<span style="color:#8b949e;">—</span>' ? '<br>' : '')
                . '<span style="font-size:9px;color:#6e7681;">src:' . $e($srcLabel) . ' pts:' . $hybridPricePts . '</span>';

            // Breathing/Result column: breathing stop + confirmation result + evidence
            if ($hybridBreathActive && $hybridBreathStop !== null) {
                $hybridBreathDisplay = '<span style="color:#a78bfa;">↩ ' . number_format((float)$hybridBreathStop, 4) . '</span>';
                if ($hybridConfResult !== null) {
                    $hybridBreathDisplay .= '<br><span style="font-size:9px;color:#8b949e;">' . $e($hybridConfResult) . '</span>';
                }
            } elseif ($hybridConfResult !== null) {
                $hybridBreathDisplay = '<span style="font-size:9px;color:#8b949e;">' . $e($hybridConfResult) . '</span>';
                if ($hybridEvidence !== null) {
                    $hybridBreathDisplay .= '<br><span style="font-size:9px;color:#6e7681;">' . $e($hybridEvidence) . '</span>';
                }
            } elseif ($hybridEvidence !== null) {
                $hybridBreathDisplay = '<span style="font-size:9px;color:#6e7681;">' . $e($hybridEvidence) . '</span>';
            } else {
                $hybridBreathDisplay = '<span style="color:#8b949e;">—</span>';
            }
            $tableRows .= '<tr style="border-bottom:1px solid var(--ui-border);">'
                . '<td style="padding:4px 8px;font-weight:600;">' . $symbol . '</td>'
                . '<td style="padding:4px 8px;color:#8b949e;">' . $side . '</td>'
                . '<td style="padding:4px 8px;font-size:10px;color:#58a6ff;">' . $profileUsed . '</td>'
                . '<td style="padding:4px 8px;text-align:right;">' . $roi . '</td>'
                . '<td style="padding:4px 8px;text-align:right;color:#a78bfa;">' . $peakRoi . '</td>'
                . '<td style="padding:4px 8px;text-align:right;color:' . $gapColor . ';">' . $gap . '</td>'
                . '<td style="padding:4px 8px;text-align:right;color:#8b949e;">' . $initRoi . '</td>'
                . '<td style="padding:4px 8px;text-align:right;color:#8b949e;">' . $activRoi . '</td>'
                . '<td style="padding:4px 8px;font-size:11px;color:' . $aClr . ';font-weight:600;">' . $actionShort . '</td>'
                . '<td style="padding:4px 8px;font-size:11px;">' . $lockDisplay . '</td>'
                . '<td style="padding:4px 8px;font-size:11px;color:#f0883e;">' . $reasonDisplay . '</td>'
                . '<td style="padding:4px 8px;font-size:10px;color:#8b949e;">' . $e($distStr) . '</td>'
                . '<td style="padding:4px 8px;font-size:10px;">' . $hybridStateDisplay . '</td>'
                . '<td style="padding:4px 8px;font-size:10px;">' . $hybridGuardDisplay . '</td>'
                . '<td style="padding:4px 8px;font-size:10px;">' . $hybridBreathDisplay . '</td>'
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
            <th style="padding:6px 8px;text-align:left;color:var(--ui-text-muted);font-weight:600;">Profile</th>
            <th style="padding:6px 8px;text-align:right;color:var(--ui-text-muted);font-weight:600;">ROI %</th>
            <th style="padding:6px 8px;text-align:right;color:var(--ui-text-muted);font-weight:600;">Peak ROI %</th>
            <th style="padding:6px 8px;text-align:right;color:var(--ui-text-muted);font-weight:600;">Gap</th>
            <th style="padding:6px 8px;text-align:right;color:var(--ui-text-muted);font-weight:600;">Init</th>
            <th style="padding:6px 8px;text-align:right;color:var(--ui-text-muted);font-weight:600;">Activation</th>
            <th style="padding:6px 8px;text-align:left;color:var(--ui-text-muted);font-weight:600;">Action</th>
            <th style="padding:6px 8px;text-align:left;color:var(--ui-text-muted);font-weight:600;">Lock</th>
            <th style="padding:6px 8px;text-align:left;color:var(--ui-text-muted);font-weight:600;">Reason</th>
            <th style="padding:6px 8px;text-align:left;color:var(--ui-text-muted);font-weight:600;">Distance</th>
            <th style="padding:6px 8px;text-align:left;color:var(--ui-text-muted);font-weight:600;">Hybrid</th>
            <th style="padding:6px 8px;text-align:left;color:var(--ui-text-muted);font-weight:600;">Guard / Pattern</th>
            <th style="padding:6px 8px;text-align:left;color:var(--ui-text-muted);font-weight:600;">Breathing / Result</th>
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
      <div><span style="color:var(--ui-text-muted);">SM стопов активно</span><br><strong style="color:#a78bfa;">{$smActiveStops}</strong> <span style="color:#8b949e;font-size:11px;">(stale: {$smStaleStops})</span></div>
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

    // ── Strategy quality diagnostics from double_bottom_long/pattern storage ──
    $stratQualHtml = '';
    foreach ($registry as $rec) {
        $dblSid  = (string)($rec['strategy_id'] ?? '');
        $dblPath = (string)($rec['module_path'] ?? '');
        if ($dblPath === '') {
            continue;
        }
        $dblLastRunPath = System::path('root') . '/' . $dblPath . '/storage/last_run.json';
        $dblStatsPath   = System::path('root') . '/' . $dblPath . '/storage/stats.json';
        $dblLastRun     = [];
        $dblStats       = [];
        if (file_exists($dblLastRunPath)) {
            $raw = file_get_contents($dblLastRunPath);
            if ($raw !== false) { $dec = json_decode($raw, true); if (is_array($dec)) { $dblLastRun = $dec; } }
        }
        if (file_exists($dblStatsPath)) {
            $raw = file_get_contents($dblStatsPath);
            if ($raw !== false) { $dec = json_decode($raw, true); if (is_array($dec)) { $dblStats = $dec; } }
        }
        if ($dblLastRun === [] && $dblStats === []) {
            continue;
        }

        $dblPipeline  = (array)($dblLastRun['pipeline_summary'] ?? []);
        $dblStatus    = $e((string)($dblLastRun['status']      ?? '—'));
        $dblStarted   = $e((string)($dblLastRun['started_at']  ?? '—'));
        $dblFinished  = $e((string)($dblLastRun['finished_at'] ?? '—'));
        $dblTotal     = $e((string)($dblPipeline['symbols_total']  ?? $dblLastRun['total']     ?? '—'));
        $dblScanned   = $e((string)($dblPipeline['symbols_scanned'] ?? $dblLastRun['processed'] ?? $dblLastRun['symbols_scanned'] ?? $dblStats['symbols_scanned_total'] ?? '—'));
        $dblChecked   = $e((string)($dblPipeline['double_bottom_checked_total'] ?? '—'));
        $dblFound     = $e((string)($dblPipeline['double_bottom_found_total'] ?? $dblLastRun['double_bottom_found'] ?? $dblStats['double_bottom_found_total'] ?? '—'));
        $dblFoundCur  = $e((string)($dblLastRun['double_bottom_found_current'] ?? '—'));
        $dblWaiting   = $e((string)($dblPipeline['candidate_waiting_confirm'] ?? '—'));
        $dblExpired   = $e((string)($dblPipeline['candidate_expired'] ?? '—'));
        $dblEmitted   = $e((string)($dblLastRun['current_cycle_signals_emitted_total'] ?? $dblPipeline['signals_emitted_total'] ?? $dblLastRun['signals_emitted'] ?? '—'));
        $dblPool      = $e((string)($dblLastRun['active_pool_signals_total']   ?? '—'));
        $dblHandoff   = $e((string)($dblLastRun['bot_handoff_ready_total']     ?? '—'));

        // Top reject reasons
        $rejectReasonsHtml = '';
        $rejectSrc = $dblLastRun['reject_reasons'] ?? $dblLastRun['reject_reason_distribution'] ?? $dblLastRun['top_reject_reasons'] ?? [];
        if (is_array($rejectSrc) && count($rejectSrc) > 0) {
            $parts = [];
            foreach (array_slice($rejectSrc, 0, 5, true) as $reason => $cnt) {
                $parts[] = $e(is_int($reason) ? (string)$reason : $reason) . ': <strong>' . $e($cnt) . '</strong>';
            }
            $rejectReasonsHtml = implode(' · ', $parts);
        } else {
            $rejectReasonsHtml = '<span style="color:var(--ui-text-muted);">—</span>';
        }

        // Final reject reasons
        $finalRejectHtml = '';
        $finalRejectSrc = $dblLastRun['final_reject_reasons'] ?? $dblLastRun['final_reject_reason_distribution'] ?? [];
        if (is_array($finalRejectSrc) && count($finalRejectSrc) > 0) {
            $parts = [];
            foreach (array_slice($finalRejectSrc, 0, 5, true) as $reason => $cnt) {
                $parts[] = $e(is_int($reason) ? (string)$reason : $reason) . ': <strong>' . $e($cnt) . '</strong>';
            }
            $finalRejectHtml = implode(' · ', $parts);
        } else {
            $finalRejectHtml = '<span style="color:var(--ui-text-muted);">—</span>';
        }

        $dblTitle = $e($rec['title'] ?? $dblSid);

        // ── corridor_bottom_long: show its own last_run fields ────────────
        if ($dblSid === 'corridor_bottom_long') {
            $cblChecked    = $e((string)($dblLastRun['symbols_checked']         ?? '—'));
            $cblNoCandle   = $e((string)($dblLastRun['no_candle_data']          ?? '—'));
            $cblSkipped    = $e((string)($dblLastRun['skipped_not_near_low']    ?? '—'));
            $cblFound      = $e((string)($dblLastRun['candidates_found']        ?? '—'));
            $cblWaiting    = $e((string)($dblLastRun['candidates_waiting']      ?? '—'));
            $cblValidated  = $e((string)($dblLastRun['candidates_validated']    ?? '—'));
            $cblSignals    = $e((string)($dblLastRun['generated_signals_count'] ?? '—'));
            $cblRejected   = $e((string)($dblLastRun['rejected']                ?? '—'));
            $cblStarted    = $e((string)($dblLastRun['started_at']              ?? '—'));
            $cblFinished   = $e((string)($dblLastRun['finished_at']             ?? '—'));
            $cblSim        = ($dblLastRun['simulation'] ?? false) ? 'Да (demo)' : 'Нет';
            $cblHandoffVal = ($dblLastRun['handoff_enabled'] ?? false) ? '<strong style="color:#3fb950;">Да</strong>' : '<strong style="color:#8b949e;">Нет</strong>';

            // Reject reasons
            $cblRejectHtml = '';
            $cblRejectSrc  = $dblLastRun['reject_reasons'] ?? [];
            if (is_array($cblRejectSrc) && count($cblRejectSrc) > 0) {
                $parts = [];
                foreach (array_slice($cblRejectSrc, 0, 5) as $rr) {
                    $parts[] = '<code style="font-size:11px;">' . $e((string)$rr) . '</code>';
                }
                $cblRejectHtml = implode(' · ', $parts);
            } else {
                $cblRejectHtml = '<span style="color:var(--ui-text-muted);">—</span>';
            }

            $stratQualHtml .= <<<QUAL
<div class="card" style="margin-bottom:16px;">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
    <span><i class="bi bi-activity" style="margin-right:6px;"></i>Диагностика стратегии: {$dblTitle}</span>
    <small style="color:var(--ui-text-muted);font-size:11px;">read-only · last_run.json · handoff: {$cblHandoffVal}</small>
  </div>
  <div class="card-body" style="padding:12px 16px;">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px 16px;font-size:12px;margin-bottom:12px;">
      <div><span style="color:var(--ui-text-muted);">Символов проверено</span><br><strong>{$cblChecked}</strong></div>
      <div><span style="color:var(--ui-text-muted);">Нет данных свечей</span><br><strong style="color:#8b949e;">{$cblNoCandle}</strong></div>
      <div><span style="color:var(--ui-text-muted);">Вне зоны corridor</span><br><strong style="color:#8b949e;">{$cblSkipped}</strong></div>
      <div><span style="color:var(--ui-text-muted);">Кандидатов найдено</span><br><strong style="color:#f0883e;">{$cblFound}</strong></div>
      <div><span style="color:var(--ui-text-muted);">Ожидают валидации</span><br><strong style="color:#58a6ff;">{$cblWaiting}</strong></div>
      <div><span style="color:var(--ui-text-muted);">Валидировано</span><br><strong style="color:#3fb950;">{$cblValidated}</strong></div>
      <div><span style="color:var(--ui-text-muted);">Сигналов создано</span><br><strong style="color:#3fb950;">{$cblSignals}</strong></div>
      <div><span style="color:var(--ui-text-muted);">Отклонено</span><br><strong style="color:#f85149;">{$cblRejected}</strong></div>
    </div>
    <table style="width:100%;font-size:12px;border-collapse:collapse;">
      <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;white-space:nowrap;width:180px;">Режим запуска</td><td>{$cblSim}</td></tr>
      <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Причины отсева</td><td>{$cblRejectHtml}</td></tr>
      <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Запущен</td><td>{$cblStarted}</td></tr>
      <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Завершён</td><td>{$cblFinished}</td></tr>
    </table>
  </div>
</div>
QUAL;
            continue;
        }

        $stratQualHtml .= <<<QUAL
<div class="card" style="margin-bottom:16px;">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
    <span><i class="bi bi-activity" style="margin-right:6px;"></i>Диагностика стратегии: {$dblTitle}</span>
    <small style="color:var(--ui-text-muted);font-size:11px;">read-only · last_run.json / stats.json</small>
  </div>
  <div class="card-body" style="padding:12px 16px;">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px 16px;font-size:12px;margin-bottom:12px;">
      <div><span style="color:var(--ui-text-muted);">Символов проскан.</span><br><strong>{$dblScanned}</strong> / {$dblTotal}</div>
      <div><span style="color:var(--ui-text-muted);">Паттерн проверено</span><br><strong style="color:#8b949e;">{$dblChecked}</strong></div>
      <div><span style="color:var(--ui-text-muted);">Double-bottom найдено</span><br><strong style="color:#f0883e;">{$dblFound}</strong></div>
      <div><span style="color:var(--ui-text-muted);">Ожидают подтверждения</span><br><strong style="color:#58a6ff;">{$dblWaiting}</strong></div>
      <div><span style="color:var(--ui-text-muted);">Кандидатов истекло</span><br><strong style="color:#8b949e;">{$dblExpired}</strong></div>
      <div><span style="color:var(--ui-text-muted);">Сигналов эмитировано</span><br><strong style="color:#3fb950;">{$dblEmitted}</strong></div>
      <div><span style="color:var(--ui-text-muted);">Активных в пуле</span><br><strong style="color:#58a6ff;">{$dblPool}</strong></div>
      <div><span style="color:var(--ui-text-muted);">Handoff-ready</span><br><strong style="color:#a78bfa;">{$dblHandoff}</strong></div>
    </div>
    <table style="width:100%;font-size:12px;border-collapse:collapse;">
      <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;white-space:nowrap;width:180px;">Статус</td><td>{$dblStatus}</td></tr>
      <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Причины отсева (top)</td><td>{$rejectReasonsHtml}</td></tr>
      <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Финальные отсевы</td><td>{$finalRejectHtml}</td></tr>
      <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Запущен</td><td>{$dblStarted}</td></tr>
      <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Завершён</td><td>{$dblFinished}</td></tr>
    </table>
  </div>
</div>
QUAL;
    }

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

    // ── Position runtime age (local tick-counter, avoids stale Bybit createdTime) ──
    $posRuntimeAgeMap = [];
    $posAgeFile = System::path('root') . '/modules/bot/storage/position_runtime_age.json';
    if (is_file($posAgeFile)) {
        $rawAge = @file_get_contents($posAgeFile);
        if ($rawAge !== false && $rawAge !== '') {
            $decAge = @json_decode($rawAge, true);
            if (is_array($decAge)) {
                $posRuntimeAgeMap = $decAge;
            }
        }
    }

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

            // opened_at with diagnostics
            $openedAtRaw   = (string)($pos['opened_at'] ?? $pos['created_at'] ?? $pos['entered_at'] ?? '');
            $openedAtSrc   = (string)($pos['opened_at_source'] ?? '—');
            $pOpenedAt     = $e($openedAtRaw !== '' ? $openedAtRaw : '—');

            // time_in_position: prefer local runtime_age counter; fallback to duration_sec / opened_at
            $pTimeInPos    = '—';
            $pTimeInPosWarn = '';

            // Build age-map key: same scheme as bot/service.php updatePositionRuntimeAge
            $posAccount = (string)($pos['account'] ?? $pos['execution_mode'] ?? 'local');
            $ageKey     = $posSymbol . '_' . $posSide . '_' . $posAccount;
            $ageRecord  = $posRuntimeAgeMap[$ageKey] ?? null;

            if ($ageRecord !== null && isset($ageRecord['runtime_age_minutes'])) {
                $ageMin     = max(0, (int)$ageRecord['runtime_age_minutes']);
                $pTimeInPos = $fmtDuration($ageMin * 60);
            } elseif (isset($pos['duration_sec']) && (int)$pos['duration_sec'] >= 0) {
                $elapsed    = max(0, (int)$pos['duration_sec']);
                $pTimeInPos = $fmtDuration($elapsed);
            } elseif ($openedAtRaw !== '') {
                $openedTs = @strtotime($openedAtRaw);
                if ($openedTs !== false && $openedTs > 0) {
                    $elapsed    = max(0, time() - $openedTs);
                    $pTimeInPos = $fmtDuration($elapsed);
                }
            }
            // Show a note if falling back to Bybit createdTime which may be very stale
            if ($ageRecord === null && $openedAtRaw !== '') {
                $openedTs = @strtotime($openedAtRaw);
                if ($openedTs !== false && $openedTs > 0 && (time() - $openedTs) > 86400) {
                    $warnTitle = ($openedAtSrc === 'bybit_createdTime_ms' || $openedAtSrc === 'bybit_createdTime_sec')
                        ? 'Bybit createdTime (может быть устаревшим). Время в позиции считается с момента первого обнаружения ботом на следующем тике.'
                        : 'Позиция старая или пришла с Bybit Demo. Проверь opened_at_source.';
                    $pTimeInPosWarn = ' <span title="' . $e($warnTitle) . '" style="color:#f0883e;cursor:help;">⚠</span>';
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
                . '<td style="padding:4px 8px;font-size:11px;color:#8b949e;">'
                . $pOpenedAt
                . ($openedAtSrc !== '—' ? '<br><span style="font-size:10px;color:var(--ui-text-muted);">' . $e($openedAtSrc) . '</span>' : '')
                . '</td>'
                . '<td style="padding:4px 8px;font-size:11px;color:#58a6ff;">' . $pTimeInPos . $pTimeInPosWarn . '</td>'
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

    // ── Closed positions block for Overview pane ─────────────────────────
    $closedPositionsHtml = '';
    {
        $closedTradesRaw = [];
        try {
            $botTradesRoot   = System::path('root') . '/modules/bot/storage/trades';
            $aggregatedPath  = $botTradesRoot . '/closed_trades.json';
            $closedDir       = $botTradesRoot . '/closed';

            if (is_file($aggregatedPath)) {
                $ct = @file_get_contents($aggregatedPath);
                if ($ct !== false && $ct !== '') {
                    $dec = @json_decode($ct, true);
                    if (is_array($dec) && !empty($dec)) {
                        $closedTradesRaw = $dec;
                    }
                }
            }

            if (empty($closedTradesRaw) && is_dir($closedDir)) {
                foreach (glob($closedDir . '/*.json') ?: [] as $path) {
                    $ct = @file_get_contents($path);
                    if ($ct === false || $ct === '') {
                        continue;
                    }
                    $tr = @json_decode($ct, true);
                    if (is_array($tr) && !empty($tr)) {
                        $closedTradesRaw[] = $tr;
                    }
                }
            }
        } catch (\Throwable) {
            // non-fatal — show empty state
        }

        // Helper: extract closed unix timestamp from a record
        $extractClosedTs = static function (array $r): int {
            foreach (['closed_at', 'closed_ts', 'closed_timestamp', 'close_time'] as $k) {
                if (isset($r[$k])) {
                    return is_numeric($r[$k]) ? (int)$r[$k] : (int)@strtotime((string)$r[$k]);
                }
            }
            return 0;
        };

        // Sort descending by closed_at, keep last 20
        if (!empty($closedTradesRaw)) {
            usort($closedTradesRaw, static function (array $a, array $b) use ($extractClosedTs): int {
                return $extractClosedTs($b) <=> $extractClosedTs($a);
            });
            $closedTradesRaw = array_slice($closedTradesRaw, 0, 20);
        }

        // Summary stats
        $clTotal      = count($closedTradesRaw);
        $clProfitable = 0;
        $clLosing     = 0;
        $clTotalPnl   = 0.0;
        $clHasPnl     = false;
        $clRoiSum     = 0.0;
        $clRoiCount   = 0;
        foreach ($closedTradesRaw as $ct) {
            if (!is_array($ct)) {
                continue;
            }
            $roiV = isset($ct['roi']) && is_numeric($ct['roi']) ? (float)$ct['roi'] : null;
            $pnlV = null;
            foreach (['pnl', 'realised_pnl', 'realised_profit', 'profit'] as $k) {
                if (isset($ct[$k]) && is_numeric($ct[$k])) {
                    $pnlV = (float)$ct[$k];
                    break;
                }
            }
            if ($roiV !== null) {
                $roiV > 0 ? $clProfitable++ : $clLosing++;
                $clRoiSum += $roiV;
                $clRoiCount++;
            } elseif ($pnlV !== null) {
                $pnlV > 0 ? $clProfitable++ : $clLosing++;
            }
            if ($pnlV !== null) {
                $clTotalPnl += $pnlV;
                $clHasPnl   = true;
            }
        }
        $clWinrate   = ($clTotal > 0) ? round($clProfitable / $clTotal * 100, 1) . '%' : '—';
        $clAvgRoi    = ($clRoiCount > 0) ? number_format($clRoiSum / $clRoiCount, 2) . '%' : '—';
        $clPnlStr    = $clHasPnl ? (($clTotalPnl >= 0 ? '+' : '') . number_format($clTotalPnl, 4)) : '—';
        $clPnlColor  = $clTotalPnl > 0 ? '#3fb950' : ($clTotalPnl < 0 ? '#f85149' : '#8b949e');

        if (empty($closedTradesRaw)) {
            $closedPositionsHtml = '<div class="card" style="margin-bottom:16px;">'
                . '<div class="card-header"><i class="bi bi-check2-square" style="margin-right:6px;"></i>Последние закрытые позиции</div>'
                . '<div class="card-body" style="padding:14px 16px;color:var(--ui-text-muted);font-size:13px;">Закрытых позиций пока нет</div>'
                . '</div>';
        } else {
            $clSummaryHtml = '<div style="display:flex;gap:20px;flex-wrap:wrap;padding:8px 14px 6px;background:var(--ui-bg);border-bottom:1px solid var(--ui-border);font-size:12px;">'
                . '<span><span style="color:var(--ui-text-muted);">Всего:</span> <strong>' . $clTotal . '</strong></span>'
                . '<span><span style="color:var(--ui-text-muted);">Прибыльных:</span> <strong style="color:#3fb950;">' . $clProfitable . '</strong></span>'
                . '<span><span style="color:var(--ui-text-muted);">Убыточных:</span> <strong style="color:#f85149;">' . $clLosing . '</strong></span>'
                . '<span><span style="color:var(--ui-text-muted);">Winrate:</span> <strong>' . $clWinrate . '</strong></span>'
                . '<span><span style="color:var(--ui-text-muted);">Total PnL:</span> <strong style="color:' . $clPnlColor . ';">' . $clPnlStr . '</strong></span>'
                . '<span><span style="color:var(--ui-text-muted);">Avg ROI:</span> <strong>' . $clAvgRoi . '</strong></span>'
                . '</div>';

            $clRows = '';
            foreach ($closedTradesRaw as $ct) {
                if (!is_array($ct)) {
                    continue;
                }

                $clSymbol = $e((string)($ct['symbol'] ?? '—'));
                $clSide   = $e(strtolower((string)($ct['side'] ?? '—')));
                $clStrat  = $e((string)($ct['strategy_id'] ?? $ct['source'] ?? $ct['close_source'] ?? '—'));

                $clEntry = '—';
                if (isset($ct['entry_price']) && is_numeric($ct['entry_price'])) {
                    $clEntry = number_format((float)$ct['entry_price'], 4);
                }

                $clExit = '—';
                foreach (['exit_price', 'close_price', 'closed_price', 'last_price'] as $k) {
                    if (isset($ct[$k]) && is_numeric($ct[$k])) {
                        $clExit = number_format((float)$ct[$k], 4);
                        break;
                    }
                }

                $roiRaw = isset($ct['roi']) && is_numeric($ct['roi']) ? (float)$ct['roi'] : null;
                if ($roiRaw !== null) {
                    $clRoi      = ($roiRaw >= 0 ? '+' : '') . number_format($roiRaw, 2) . '%';
                    $clRoiColor = $roiRaw > 0 ? '#3fb950' : '#f85149';
                } else {
                    $clRoi      = '—';
                    $clRoiColor = '#8b949e';
                }

                $pnlRaw = null;
                foreach (['pnl', 'realised_pnl', 'realised_profit', 'profit'] as $k) {
                    if (isset($ct[$k]) && is_numeric($ct[$k])) {
                        $pnlRaw = (float)$ct[$k];
                        break;
                    }
                }
                if ($pnlRaw !== null) {
                    $clPnlVal  = ($pnlRaw >= 0 ? '+' : '') . number_format($pnlRaw, 4);
                    $clPnlValC = $pnlRaw > 0 ? '#3fb950' : '#f85149';
                } else {
                    $clPnlVal  = '—';
                    $clPnlValC = '#8b949e';
                }

                $clLev = '—';
                foreach (['leverage', 'bot_leverage'] as $k) {
                    if (isset($ct[$k]) && $ct[$k] !== '' && $ct[$k] !== null) {
                        $clLev = $e((string)$ct[$k]);
                        break;
                    }
                }

                $clBudget = '—';
                foreach (['budget', 'bot_budget', 'position_size'] as $k) {
                    if (isset($ct[$k]) && is_numeric($ct[$k])) {
                        $clBudget = number_format((float)$ct[$k], 2);
                        break;
                    }
                }

                // opened_at display
                $openedRaw = null;
                foreach (['opened_at', 'opened_ts', 'open_time', 'created_at'] as $k) {
                    if (isset($ct[$k])) {
                        $openedRaw = $ct[$k];
                        break;
                    }
                }
                $clOpenedAt = '—';
                if ($openedRaw !== null) {
                    $openedTs = is_numeric($openedRaw) ? (int)$openedRaw : (int)@strtotime((string)$openedRaw);
                    if ($openedTs > 0) {
                        $clOpenedAt = date('d.m.y H:i', $openedTs);
                    }
                }

                // closed_at display
                $closedRaw = null;
                foreach (['closed_at', 'closed_ts', 'closed_timestamp', 'close_time'] as $k) {
                    if (isset($ct[$k])) {
                        $closedRaw = $ct[$k];
                        break;
                    }
                }
                $clClosedAt = '—';
                if ($closedRaw !== null) {
                    $closedTs = is_numeric($closedRaw) ? (int)$closedRaw : (int)@strtotime((string)$closedRaw);
                    if ($closedTs > 0) {
                        $clClosedAt = date('d.m.y H:i', $closedTs);
                    }
                }

                // duration
                $clDur = '—';
                if (isset($ct['duration_minutes']) && is_numeric($ct['duration_minutes'])) {
                    $clDur = $fmtDuration((int)round((float)$ct['duration_minutes'] * 60));
                } elseif (isset($ct['duration_sec']) && is_numeric($ct['duration_sec'])) {
                    $clDur = $fmtDuration((int)$ct['duration_sec']);
                } elseif (isset($ct['duration']) && is_numeric($ct['duration'])) {
                    // convention: int minutes
                    $clDur = $fmtDuration((int)round((float)$ct['duration'] * 60));
                } else {
                    // compute from timestamps
                    $oTs = 0;
                    $cTs = 0;
                    foreach (['opened_at', 'opened_ts', 'open_time', 'created_at'] as $k) {
                        if (isset($ct[$k])) {
                            $oTs = is_numeric($ct[$k]) ? (int)$ct[$k] : (int)@strtotime((string)$ct[$k]);
                            break;
                        }
                    }
                    foreach (['closed_at', 'closed_ts', 'closed_timestamp', 'close_time'] as $k) {
                        if (isset($ct[$k])) {
                            $cTs = is_numeric($ct[$k]) ? (int)$ct[$k] : (int)@strtotime((string)$ct[$k]);
                            break;
                        }
                    }
                    if ($oTs > 0 && $cTs > $oTs) {
                        $clDur = $fmtDuration($cTs - $oTs);
                    }
                }

                $clCloseSource = $e((string)($ct['close_source'] ?? $ct['closed_by'] ?? '—'));
                $clCloseReason = $e((string)($ct['close_reason'] ?? $ct['reason'] ?? '—'));
                $clModeVal     = strtolower((string)($ct['mode'] ?? $ct['execution_mode'] ?? ''));
                $clMode        = $e($clModeVal !== '' ? $clModeVal : '—');
                $clModeColor   = $clModeVal === 'live' ? '#f0883e' : '#8b949e';

                $clRows .= '<tr style="border-bottom:1px solid var(--ui-border);">'
                    . '<td style="padding:4px 8px;font-weight:600;">' . $clSymbol . '</td>'
                    . '<td style="padding:4px 8px;color:#8b949e;">' . $clSide . '</td>'
                    . '<td style="padding:4px 8px;font-size:11px;color:#8b949e;">' . $clStrat . '</td>'
                    . '<td style="padding:4px 8px;text-align:right;">' . $clEntry . '</td>'
                    . '<td style="padding:4px 8px;text-align:right;">' . $clExit . '</td>'
                    . '<td style="padding:4px 8px;text-align:right;color:' . $clRoiColor . ';font-weight:600;">' . $clRoi . '</td>'
                    . '<td style="padding:4px 8px;text-align:right;color:' . $clPnlValC . ';">' . $clPnlVal . '</td>'
                    . '<td style="padding:4px 8px;text-align:right;">' . $clLev . '</td>'
                    . '<td style="padding:4px 8px;text-align:right;">' . $clBudget . '</td>'
                    . '<td style="padding:4px 8px;font-size:11px;color:#8b949e;">' . $clOpenedAt . '</td>'
                    . '<td style="padding:4px 8px;font-size:11px;color:#8b949e;">' . $clClosedAt . '</td>'
                    . '<td style="padding:4px 8px;font-size:11px;color:#58a6ff;">' . $clDur . '</td>'
                    . '<td style="padding:4px 8px;font-size:11px;color:#8b949e;">' . $clCloseSource . '</td>'
                    . '<td style="padding:4px 8px;font-size:11px;color:#8b949e;">' . $clCloseReason . '</td>'
                    . '<td style="padding:4px 8px;font-size:11px;color:' . $clModeColor . ';">' . $clMode . '</td>'
                    . '</tr>';
            }

            $closedPositionsHtml = '<div class="card" style="margin-bottom:16px;">'
                . '<div class="card-header"><i class="bi bi-check2-square" style="margin-right:6px;"></i>Последние закрытые позиции (показаны: ' . $clTotal . ')</div>'
                . $clSummaryHtml
                . '<div class="card-body" style="padding:0;">'
                . '<div style="overflow-x:auto;">'
                . '<table style="width:100%;font-size:12px;border-collapse:collapse;">'
                . '<thead><tr style="border-bottom:2px solid var(--ui-border);">'
                . '<th style="padding:5px 8px;text-align:left;color:var(--ui-text-muted);">Symbol</th>'
                . '<th style="padding:5px 8px;text-align:left;color:var(--ui-text-muted);">Side</th>'
                . '<th style="padding:5px 8px;text-align:left;color:var(--ui-text-muted);">Стратегия</th>'
                . '<th style="padding:5px 8px;text-align:right;color:var(--ui-text-muted);">Вход</th>'
                . '<th style="padding:5px 8px;text-align:right;color:var(--ui-text-muted);">Выход</th>'
                . '<th style="padding:5px 8px;text-align:right;color:var(--ui-text-muted);">ROI%</th>'
                . '<th style="padding:5px 8px;text-align:right;color:var(--ui-text-muted);">PnL</th>'
                . '<th style="padding:5px 8px;text-align:right;color:var(--ui-text-muted);">Плечо</th>'
                . '<th style="padding:5px 8px;text-align:right;color:var(--ui-text-muted);">Бюджет</th>'
                . '<th style="padding:5px 8px;text-align:left;color:var(--ui-text-muted);">Открыто</th>'
                . '<th style="padding:5px 8px;text-align:left;color:var(--ui-text-muted);">Закрыто</th>'
                . '<th style="padding:5px 8px;text-align:left;color:var(--ui-text-muted);">Длительность</th>'
                . '<th style="padding:5px 8px;text-align:left;color:var(--ui-text-muted);">Источник закр.</th>'
                . '<th style="padding:5px 8px;text-align:left;color:var(--ui-text-muted);">Причина</th>'
                . '<th style="padding:5px 8px;text-align:left;color:var(--ui-text-muted);">Режим</th>'
                . '</tr></thead>'
                . '<tbody>' . $clRows . '</tbody>'
                . '</table>'
                . '</div>'
                . '</div>'
                . '</div>';
        }
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
        . '<td style="padding:5px 10px;font-size:11px;color:#8b949e;">стопов: ' . $smActiveStops . ' (stale: ' . $smStaleStops . ') · позиций: ' . $smPosSeen . '</td>'
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

    // ── Demo connection status HTML (pre-computed for use in heredoc) ──────
    $demoCredsHtml    = $lrDemoCredsConfigured
        ? '<span style="color:#3fb950;font-weight:600;">&#10003; настроены</span>'
        : '<span style="color:#f85149;font-weight:600;">&#10007; отсутствуют</span>';
    $demoConnHtml     = $lrDemoConnected
        ? '<span style="color:#3fb950;font-weight:600;">&#10003; подключено</span>'
        : '<span style="color:#f85149;font-weight:600;">&#10007; нет соединения</span>';
    $demoConnErrRow   = $lrDemoConnError !== ''
        ? '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Ошибка</td><td style="color:#f85149;font-size:12px;">' . $e($lrDemoConnError) . '</td></tr>'
        : '';

    // ── Live connection status HTML ───────────────────────────────────────
    $liveConnHtml = $lrLiveConnected
        ? '<span style="color:#3fb950;font-weight:600;">&#10003; подключено</span>'
        : ($lrLiveCredsMissing
            ? '<span style="color:#f85149;font-weight:600;">&#10007; KeyCenter: нет credentials</span>'
            : '<span style="color:#f0883e;font-weight:600;">&#10007; нет соединения</span>');
    $liveAccountIdHtml = $lrLiveAccountId !== ''
        ? '<span style="font-family:monospace;">' . $e($lrLiveAccountId) . '</span>'
        : '<span style="color:#f85149;">не настроен</span>';
    $liveEnabledHtml = $lrLiveEnabled
        ? '<span style="color:#f85149;font-weight:600;">ДА — торговля активна</span>'
        : '<span style="color:#8b949e;">нет</span>';
    $liveConnErrRow = $lrLiveConnError !== ''
        ? '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Ошибка</td><td style="color:#f85149;font-size:12px;">' . $e($lrLiveConnError) . '</td></tr>'
        : '';

    // ── Demo execution diagnostics HTML ──────────────────────────────────
    $demoExecDiagHtml = '';
    if ($botMode === 'demo' || $botMode === 'live') {
        $lastErrMsgHtml = '';
        if ($lrDemoLastErrMsg !== '') {
            $userMsg = $lrDemoLastErrMsg;
            if (strpos($lrDemoLastErrMsg, 'Qty invalid') !== false
                || strpos($lrDemoLastErrMsg, '10001') !== false
                || $lrDemoLastErrCode === 10001
            ) {
                $userMsg .= ' — Qty invalid: проверь qtyStep/minOrderQty/minNotionalValue';
            }
            $lastErrMsgHtml = '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Последняя ошибка</td>'
                . '<td style="color:#f85149;font-size:12px;">' . $e($userMsg) . '</td></tr>';
        }
        $lastRejSymHtml = $lrDemoLastRejectedSym !== ''
            ? '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Последний откат</td>'
              . '<td style="color:#f0883e;"><code>' . $e($lrDemoLastRejectedSym) . '</code></td></tr>'
            : '';
        $levClampedNote = $lrDemoLevClampedCount > 0
            ? ' <span style="color:#f0883e;font-size:11px;">— Плечо снижено до максимального Bybit для монеты</span>'
            : '';
        // Leverage diagnostics rows
        $levReqRow = $lrDemoLastReqLev !== null
            ? '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Запрошенное плечо</td>'
              . '<td><code>' . $e($lrDemoLastReqLev) . 'x</code>'
              . ($lrDemoLastLevSrc !== '' ? ' <span style="color:var(--ui-text-muted);font-size:11px;">(' . $e($lrDemoLastLevSrc) . ')</span>' : '')
              . '</td></tr>'
            : '';
        $levEffRow = $lrDemoLastEffLev !== null
            ? '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Эффективное плечо</td>'
              . '<td><code style="color:' . ($lrDemoLastEffLev !== $lrDemoLastReqLev ? '#f0883e' : '#3fb950') . ';">' . $e($lrDemoLastEffLev) . 'x</code></td></tr>'
            : '';
        $levNoteRow = $lrDemoLastSetLevNote !== ''
            ? '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Set leverage статус</td>'
              . '<td><code>' . $e($lrDemoLastSetLevNote) . '</code>'
              . ($lrDemoLastSetLevCode !== null ? ' <span style="color:var(--ui-text-muted);font-size:11px;">[' . $e($lrDemoLastSetLevCode) . ']</span>' : '')
              . '</td></tr>'
            : '';
        $budgetSrcRow = $lrDemoLastBudgetSrc !== ''
            ? '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Источник бюджета</td>'
              . '<td><code>' . $e($lrDemoLastBudgetSrc) . '</code></td></tr>'
            : '';
        $levMismatchRow = $lrDemoLevMismatchCount > 0
            ? '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">&#9888; Плечо не применено</td>'
              . '<td><code style="color:#f85149;">leverage_not_applied_on_exchange (' . $e($lrDemoLevMismatchCount) . ')</code></td></tr>'
            : '';
        $demoExecDiagHtml = '<div class="card" style="margin-bottom:16px;">'
            . '<div class="card-header">' . ($botMode === 'live' ? 'Live' : 'Demo') . ' — диагностика исполнения</div>'
            . '<div class="card-body">'
            . '<table style="width:100%;font-size:13px;border-collapse:collapse;">'
            . '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;width:200px;">Ордеров подготовлено</td><td><code>' . $e($lrDemoOrdersPrepared) . '</code></td></tr>'
            . '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Ордеров отклонено</td><td><code style="color:' . ($lrDemoOrdersRejected > 0 ? '#f85149' : 'inherit') . ';">' . $e($lrDemoOrdersRejected) . '</code></td></tr>'
            . '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Ордеров отправлено</td><td><code style="color:#3fb950;">' . $e($lrDemoOrdersSubmitted) . '</code></td></tr>'
            . '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Позиций подтверждено</td><td><code>' . $e($lrDemoOrdersConfirmed) . '</code></td></tr>'
            . '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Qty invalid (тик)</td><td><code style="color:' . ($lrDemoQtyInvalidCount > 0 ? '#f85149' : 'inherit') . ';">' . $e($lrDemoQtyInvalidCount) . '</code></td></tr>'
            . '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Плечо снижено (тик)</td><td><code>' . $e($lrDemoLevClampedCount) . '</code>' . $levClampedNote . '</td></tr>'
            . '<tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Set leverage ошибок</td><td><code style="color:' . ($lrDemoSetLevFailedCount > 0 ? '#f85149' : 'inherit') . ';">' . $e($lrDemoSetLevFailedCount) . '</code></td></tr>'
            . $levReqRow
            . $levEffRow
            . $budgetSrcRow
            . $levNoteRow
            . $levMismatchRow
            . $lastRejSymHtml
            . $lastErrMsgHtml
            . '</table>'
            . '</div></div>';
    }

    // ── render ────────────────────────────────────────────────────────────
    // Mode switch button state
    $modeIsDemoBtnActive = $botMode === 'demo' ? 'font-weight:700;' : '';
    $modeIsLiveBtnActive = $botMode === 'live' ? 'font-weight:700;' : '';
    $modeDemoBtnBg = $botMode === 'demo'
        ? 'background:#f0883e22;color:#f0883e;border:2px solid #f0883e;'
        : 'background:rgba(139,148,158,.08);color:#8b949e;border:1px solid #8b949e55;';
    $modeLiveBtnBg = $botMode === 'live'
        ? 'background:#f8514922;color:#f85149;border:2px solid #f85149;'
        : 'background:rgba(139,148,158,.08);color:#8b949e;border:1px solid #8b949e55;';
    $switchModeUrl = System::web('admin/dashboard');

    // Live warning banner (shown when mode=live)
    $liveWarningBanner = '';
    if ($botMode === 'live') {
        $liveWarningBanner = '<div style="background:rgba(248,81,73,.12);border:2px solid #f85149;border-radius:8px;padding:14px 18px;margin-bottom:16px;">'
            . '<div style="font-size:15px;font-weight:700;color:#f85149;margin-bottom:6px;">&#9888; ВНИМАНИЕ: LIVE режим. Реальные ордера.</div>'
            . '<div style="font-size:13px;color:#f85149;">Все ордера исполняются на реальном счёте Bybit. Потеря средств возможна.</div>'
            . '</div>';
    }
    // Stale queue warning (appended to live banner area)
    $liveWarningBanner .= $staleQueueWarningHtml;

    // Mode status label
    $modeLabel = $botMode === 'live'
        ? 'LIVE (bybit_live)'
        : ($botMode === 'demo' ? 'DEMO (bybit_demo)' : $botMode);
    $modeColor = $botMode === 'live' ? '#f85149' : ($botMode === 'demo' ? '#f0883e' : '#8b949e');

    // Live form field pre-computed values (can't use ternary inside heredoc)
    $cfgLiveEnabledYes = $cfgLiveEnabled ? ' selected' : '';
    $cfgLiveEnabledNo  = $cfgLiveEnabled ? '' : ' selected';

    // Queue diagnostics pre-computed colors for heredoc
    $qReadyLiveColor      = $queueReadyLiveCount > 0 ? '#3fb950' : '#8b949e';
    $qSubmittedDemoColor  = ($queueSubmittedDemoCount > 0 && $cfgCurrentMode === 'live') ? '#f85149' : '#8b949e';
    $qStaleOtherModeColor = $queueStaleOtherModeCount > 0 ? '#f0883e' : '#8b949e';

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
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <!-- Mode switch buttons -->
    <form method="post" action="{$switchModeUrl}" style="margin:0;display:flex;gap:4px;">
      <input type="hidden" name="dashboard_action" value="switch_mode">
      <input type="hidden" name="active_tab" value="dh-bot">
      <button type="submit" name="mode" value="demo"
        style="padding:6px 16px;border-radius:5px;cursor:pointer;font-size:13px;{$modeDemoBtnBg}{$modeIsDemoBtnActive}">
        DEMO
      </button>
      <button type="submit" name="mode" value="live"
        style="padding:6px 16px;border-radius:5px;cursor:pointer;font-size:13px;{$modeLiveBtnBg}{$modeIsLiveBtnActive}">
        LIVE
      </button>
    </form>
    <!-- One-button chain run -->
    <form method="post" action="{$chainRunUrl}" style="margin:0;">
      <input type="hidden" name="dashboard_action" value="chain_run">
      <input type="hidden" name="active_tab" value="dh-overview">
      <button type="submit" class="btn btn-sm" style="background:rgba(88,166,255,.15);color:#58a6ff;border:1px solid #58a6ff66;padding:7px 20px;font-weight:600;font-size:13px;">
        <i class="bi bi-lightning-fill" style="margin-right:5px;"></i>Запустить цепочку
      </button>
    </form>
  </div>
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
{$modeMismatchHtml}

<!-- System Overview (always visible) -->
{$sysOverviewHtml}

<!-- Top tab navigation (vanilla JS) -->
<nav class="dh-tab-nav" role="tablist">
  <button class="dh-tab-btn dh-active" data-tab-target="dh-overview" type="button">
    <i class="bi bi-grid-1x2" style="margin-right:5px;"></i>Обзор
  </button>
  <button class="dh-tab-btn" data-tab-target="dh-strat" type="button">
    <i class="bi bi-layers" style="margin-right:5px;"></i>Стратегии
  </button>
  <button class="dh-tab-btn" data-tab-target="dh-bot" type="button">
    <i class="bi bi-cpu" style="margin-right:5px;"></i>Бот
  </button>
  <button class="dh-tab-btn" data-tab-target="dh-sm" type="button">
    <i class="bi bi-shield-exclamation" style="margin-right:5px;"></i>Стоп
  </button>
  <button class="dh-tab-btn" data-tab-target="dh-pm" type="button">
    <i class="bi bi-graph-up-arrow" style="margin-right:5px;"></i>Профит
  </button>
  <button class="dh-tab-btn" data-tab-target="dh-ctrl" type="button">
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
  {$stratQualHtml}
  {$overviewPositionsHtml}
  {$closedPositionsHtml}
  {$modStripHtml}
  <div style="padding:10px 14px;margin-bottom:16px;background:rgba(240,136,62,.07);border:1px solid #f0883e44;border-radius:8px;font-size:12px;color:#f0883e;">
    <i class="bi bi-info-circle" style="margin-right:5px;"></i>
    Чтобы убрать старые позиции — закройте их на Bybit вручную.
    Reset не закрывает позиции на бирже и не трогает конфиги или API-ключи.
  </div>
  <div style="margin-top:16px;text-align:right;">
    <form method="post" action="{$chainRunUrl}" style="margin:0;display:inline;"
      onsubmit="return confirm('Это удалит локальные runtime/cache данные бота, стратегий, Stop Manager и Profit Manager. Конфиги и ключи не будут тронуты. Позиции на Bybit НЕ закрываются.\n\nПродолжить?');">
      <input type="hidden" name="dashboard_action" value="reset_runtime">
      <input type="hidden" name="active_tab" value="dh-overview">
      <button type="submit"
        style="background:rgba(248,81,73,.12);border:1px solid #f85149;color:#f85149;padding:6px 16px;border-radius:5px;cursor:pointer;font-size:13px;"
      ><i class="bi bi-trash3" style="margin-right:5px;"></i>Полный локальный reset</button>
    </form>
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
  {$liveWarningBanner}
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
    <div class="card">
      <div class="card-header">Бот</div>
      <div class="card-body">
        <table style="width:100%;font-size:13px;border-collapse:collapse;">
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;width:140px;">Включён</td><td>{$botEnabled}</td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Режим</td><td><code style="color:{$modeColor};font-weight:700;">{$e($modeLabel)}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Аккаунт</td><td><code>{$e($lrAccount)}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Статус тика</td><td><code>{$e($tickStatus)}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Время тика</td><td><code>{$e($tickAt)}</code></td></tr>
        </table>
      </div>
    </div>
    <div class="card">
      <div class="card-header">Текущий прогон</div>
      <div class="card-body">
        <table style="width:100%;font-size:13px;border-collapse:collapse;">
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;width:160px;">Очередь (queued+ready)</td><td><code>{$e($lastRun['order_queue_total'] ?? 0)}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Submitted (история)</td><td><code style="color:#8b949e;">{$queueSubmittedCount}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Ready (live)</td><td><code style="color:{$qReadyLiveColor};">{$queueReadyLiveCount}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Submitted (demo, история)</td><td><code style="color:{$qSubmittedDemoColor};">{$queueSubmittedDemoCount}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Stale (чужой режим)</td><td><code style="color:{$qStaleOtherModeColor};">{$queueStaleOtherModeCount}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Активных ордеров</td><td><code>{$e($lastRun['active_orders_count'] ?? 0)}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Активных позиций</td><td><code>{$e($lastRun['active_positions_count'] ?? 0)}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Сигналов обработано</td><td><code>{$e($sigProc)}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Стратегий обнаружено</td><td><code>{$e($lastRun['strategies_discovered_total'] ?? 0)}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Стратегий включено</td><td><code>{$e($lastRun['strategies_enabled_total'] ?? 0)}</code></td></tr>
        </table>
      </div>
    </div>
  </div>
  <div class="card" style="margin-bottom:16px;">
    <div class="card-header">Demo подключение</div>
    <div class="card-body">
      <table style="width:100%;font-size:13px;border-collapse:collapse;">
        <tr>
          <td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;width:200px;">Учётные данные</td>
          <td>{$demoCredsHtml}</td>
        </tr>
        <tr>
          <td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Статус соединения</td>
          <td>{$demoConnHtml}</td>
        </tr>
        {$demoConnErrRow}
      </table>
      <div style="margin-top:8px;font-size:11px;color:var(--ui-text-muted);">
        Статус обновляется при каждом тике бота в режиме demo. Настройте учётные данные во вкладке «Управление».
      </div>
    </div>
  </div>
  <div class="card" style="margin-bottom:16px;">
    <div class="card-header">Live подключение (KeyCenter)</div>
    <div class="card-body">
      <table style="width:100%;font-size:13px;border-collapse:collapse;">
        <tr>
          <td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;width:200px;">Account ID</td>
          <td>{$liveAccountIdHtml}</td>
        </tr>
        <tr>
          <td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Live торговля</td>
          <td>{$liveEnabledHtml}</td>
        </tr>
        <tr>
          <td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Статус KeyCenter</td>
          <td>{$liveConnHtml}</td>
        </tr>
        {$liveConnErrRow}
      </table>
      <div style="margin-top:8px;font-size:11px;color:var(--ui-text-muted);">
        Статус обновляется при каждом тике бота в режиме live. Ключи управляются через KeyCenter по account_id.
      </div>
    </div>
  </div>
  {$demoExecDiagHtml}
  <div class="card">
    <div class="card-header">Ручное управление</div>
    <div class="card-body">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <!-- Bot quick toggle -->
        <form method="post" action="{$botToggleUrl}" style="margin:0;">
          <input type="hidden" name="dashboard_action" value="bot_toggle">
          <input type="hidden" name="enabled" value="{$botToggleTarget}">
          <input type="hidden" name="active_tab" value="dh-bot">
          <button type="submit" class="btn btn-sm" style="background:{$botToggleBg};color:{$botToggleColor};border:1px solid {$botToggleColor}55;padding:6px 18px;font-weight:600;">
            {$botToggleLabel}
          </button>
        </form>
        <form method="post" action="{$botTickUrl}" style="margin:0;">
          <input type="hidden" name="dashboard_action" value="bot_tick">
          <input type="hidden" name="action" value="tick">
          <input type="hidden" name="active_tab" value="dh-bot">
          <button type="submit" class="btn btn-sm" style="background:rgba(63,185,80,.12);color:#3fb950;border:1px solid #3fb95055;padding:6px 18px;">
            <i class="bi bi-play-fill" style="margin-right:4px;"></i>Тик бота
          </button>
        </form>
        <form method="post" action="{$botTickUrl}" style="margin:0;">
          <input type="hidden" name="dashboard_action" value="bot_tick">
          <input type="hidden" name="action" value="refresh">
          <input type="hidden" name="active_tab" value="dh-bot">
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
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Стоп от liq к входу (%)</td><td><code>{$smLiqDist}</code></td></tr>
          {$smMismatchRow}
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
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Stale стопов</td><td><code style="color:#8b949e;">{$smStaleStops}</code></td></tr>
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
          <input type="hidden" name="dashboard_action" value="stop_manager_toggle">
          <input type="hidden" name="enabled" value="{$smToggleTarget}">
          <input type="hidden" name="active_tab" value="dh-sm">
          <button type="submit" class="btn btn-sm" style="background:{$smToggleBg};color:{$smToggleColor};border:1px solid {$smToggleColor}55;padding:6px 18px;font-weight:600;">
            {$smToggleLabel}
          </button>
        </form>
        <form method="post" action="{$smTickUrl}" style="margin:0;">
          <input type="hidden" name="dashboard_action" value="stop_manager_tick">
          <input type="hidden" name="action" value="tick">
          <input type="hidden" name="active_tab" value="dh-sm">
          <button type="submit" class="btn btn-sm" style="background:rgba(167,139,250,.12);color:#a78bfa;border:1px solid #a78bfa55;padding:6px 18px;">
            <i class="bi bi-play-fill" style="margin-right:4px;"></i>Тик стоп-менеджера
          </button>
        </form>
        <form method="post" action="{$smTickUrl}" style="margin:0;">
          <input type="hidden" name="dashboard_action" value="stop_manager_tick">
          <input type="hidden" name="action" value="refresh">
          <input type="hidden" name="active_tab" value="dh-sm">
          <button type="submit" class="btn btn-sm" style="background:rgba(139,148,158,.12);color:#8b949e;border:1px solid #8b949e55;padding:6px 18px;">
            <i class="bi bi-arrow-clockwise" style="margin-right:4px;"></i>Обновить runtime
          </button>
        </form>
      </div>
      <div style="margin-top:10px;font-size:11px;color:var(--ui-text-muted);">
        Вычисление стопов — локально. В demo режиме стопы устанавливаются на Bybit Demo.
      </div>
    </div>
  </div>
  {$smDemoExecHtml}
</div>

<!-- ── Profit Manager pane ──────────────────────────────────────────── -->
<div id="dh-pm" class="dh-pane">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
    <div class="card">
      <div class="card-header">Profit Manager — Статус</div>
      <div class="card-body">
        <table style="width:100%;font-size:13px;border-collapse:collapse;">
          <tr><td style="color:var(--ui-text-muted);width:180px;padding:3px 12px 3px 0;">Включён</td><td>{$pmEnabled}</td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Режим</td><td><code>demo</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Аккаунт</td><td><code>{$e($pmAccount)}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Профиль</td><td><code>AUTO</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Long профиль</td><td><code style="color:#3fb950;">{$e($pmLongProfile)}</code></td></tr>
          <tr><td style="color:var(--ui-text-muted);padding:3px 12px 3px 0;">Short профиль</td><td><code style="color:#8b949e;">{$e($pmShortProfile)}</code></td></tr>
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
          <input type="hidden" name="dashboard_action" value="profit_manager_toggle">
          <input type="hidden" name="enabled" value="{$pmToggleTarget}">
          <input type="hidden" name="active_tab" value="dh-pm">
          <button type="submit" class="btn btn-sm" style="background:{$pmToggleBg};color:{$pmToggleColor};border:1px solid {$pmToggleColor}55;padding:6px 18px;font-weight:600;">
            {$pmToggleLabel}
          </button>
        </form>
        <form method="post" action="{$pmTickUrl}" style="margin:0;">
          <input type="hidden" name="dashboard_action" value="profit_manager_tick">
          <input type="hidden" name="action" value="tick">
          <input type="hidden" name="active_tab" value="dh-pm">
          <button type="submit" class="btn btn-sm" style="background:rgba(240,136,62,.12);color:#f0883e;border:1px solid #f0883e55;padding:6px 18px;">
            <i class="bi bi-play-fill" style="margin-right:4px;"></i>Тик PM
          </button>
        </form>
        <form method="post" action="{$pmTickUrl}" style="margin:0;">
          <input type="hidden" name="dashboard_action" value="profit_manager_tick">
          <input type="hidden" name="action" value="refresh">
          <input type="hidden" name="active_tab" value="dh-pm">
          <button type="submit" class="btn btn-sm" style="background:rgba(139,148,158,.12);color:#8b949e;border:1px solid #8b949e55;padding:6px 18px;">
            <i class="bi bi-arrow-clockwise" style="margin-right:4px;"></i>Обновить runtime
          </button>
        </form>
      </div>
      <div style="margin-top:10px;font-size:11px;color:var(--ui-text-muted);">
        Режим demo. PM планирует profit-lock только — биржевые ордера не размещаются.
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
        <input type="hidden" name="dashboard_action" value="global_save">
        <input type="hidden" name="active_tab" value="dh-ctrl">
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
              <option value="demo"{$gcfgModeDe}>demo</option>
              <option value="live"{$gcfgModeLv}>live</option>
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

        <!-- Demo credentials section -->
        <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--ui-border);">
          <div style="font-size:12px;color:var(--ui-text-muted);margin-bottom:8px;font-weight:600;">Bybit Demo — учётные данные</div>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px 16px;">
            <div>
              <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">
                API Key <span style="margin-left:6px;">{$cfgDemoKeyStatus}</span>
              </label>
              <input type="text" name="demo_api_key" value="" placeholder="оставьте пустым, чтобы не изменять"
                autocomplete="off" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
            </div>
            <div>
              <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">
                API Secret <span style="margin-left:6px;">{$cfgDemoSecretStatus}</span>
              </label>
              <input type="password" name="demo_api_secret" value="" placeholder="оставьте пустым, чтобы не изменять"
                autocomplete="new-password" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
            </div>
            <div>
              <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Base URL</label>
              <input type="text" name="demo_api_base_url" value="{$cfgDemoBaseUrl}"
                class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
            </div>
          </div>
          <div style="margin-top:6px;font-size:11px;color:var(--ui-text-muted);">
            Ключ и секрет сохраняются только при заполнении. Оставьте поле пустым — текущее значение не изменится.
          </div>
        </div>

        <div style="margin-top:14px;border-top:1px solid var(--ui-border);padding-top:12px;">
          <div style="font-size:12px;color:var(--ui-text-muted);margin-bottom:8px;font-weight:600;">Live режим (KeyCenter)</div>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
            <div>
              <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Account ID</label>
              <input type="text" name="account_id" value="{$e($cfgLiveAccountId)}"
                placeholder="account_id для KeyCenter"
                class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
              <div style="font-size:11px;color:var(--ui-text-muted);margin-top:3px;">
                Используется для live. Ключи управляются KeyCenter.
              </div>
            </div>
            <div>
              <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">
                Live торговля разрешена
              </label>
              <select name="live_enabled" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
                <option value="0"{$cfgLiveEnabledNo}>Нет (ордера заблокированы)</option>
                <option value="1"{$cfgLiveEnabledYes}>Да — ПОДТВЕРЖДАЮ live торговлю</option>
              </select>
              <div style="font-size:11px;color:#f85149;margin-top:3px;">
                Обязательно для исполнения live ордеров.
              </div>
            </div>
          </div>
        </div>

        <div style="margin-top:14px;">
          <button type="submit" class="btn btn-sm btn-primary">Сохранить</button>
        </div>
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
        <input type="hidden" name="dashboard_action" value="stop_manager_config_save">
        <input type="hidden" name="active_tab" value="dh-ctrl">
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
              <option value="demo"{$smCfgModeDe}>demo</option>
              <option value="live"{$smCfgModeLi}>live</option>
            </select>
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Stop mode</label>
            <select name="stop_mode" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
              <option value="liq_distance_percent" selected>liq_distance_percent</option>
            </select>
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Стоп от ликвидации к входу (%)</label>
            <input type="number" step="1" min="1" max="99" name="liq_distance_percent"
              value="{$smCfgBuf}" class="form-control" style="height:32px;font-size:13px;padding:2px 8px;">
            <div style="font-size:11px;color:var(--ui-text-muted);margin-top:3px;">
              90 = стоп близко к входу, 10% до ликвидации. 10 = стоп близко к ликвидации.
            </div>
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
      <small style="color:var(--ui-text-muted);font-size:11px;">Зеркало config/active.php · demo profit-lock (без биржевых ордеров)</small>
    </div>
    <div class="card-body">
      <form method="post" action="{$pmConfigSaveUrl}">
        <input type="hidden" name="dashboard_action" value="profit_manager_config_save">
        <input type="hidden" name="active_tab" value="dh-ctrl">
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
              <option value="demo"{$pmCfgModeDemoSel}>demo</option>
              <option value="live"{$pmCfgModeLiveSel}>live</option>
            </select>
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Профиль</label>
            <div style="height:32px;font-size:13px;padding:4px 8px;background:var(--ui-bg-secondary,#161b22);border:1px solid var(--ui-border);border-radius:4px;color:#58a6ff;">AUTO (long: legacy_safe_long · short: unavailable)</div>
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
function dhTab(panelId) {
    document.querySelectorAll('.dh-tab-btn').forEach(function(b){ b.classList.remove('dh-active'); });
    document.querySelectorAll('.dh-pane').forEach(function(p){ p.classList.remove('dh-visible'); });
    var btn = document.querySelector('.dh-tab-btn[data-tab-target="' + panelId + '"]');
    if (btn) { btn.classList.add('dh-active'); }
    var panel = document.getElementById(panelId);
    if (panel) { panel.classList.add('dh-visible'); }
}
function dhSwitchToTab(panelId) { dhTab(panelId); }
function dhToggleEdit(id) {
    var el = document.getElementById(id);
    if (el) { el.style.display = el.style.display === 'none' ? 'block' : 'none'; }
}
// Event delegation — handles tab-nav buttons and status-chain badges via [data-tab-target]
document.addEventListener('click', function(e) {
    var target = e.target.closest('[data-tab-target]');
    if (target) {
        dhTab(target.getAttribute('data-tab-target'));
    }
});
// Activate tab from URL ?tab= on page load
(function() {
    var valid = ['dh-overview','dh-strat','dh-bot','dh-sm','dh-pm','dh-ctrl'];
    var params = new URLSearchParams(window.location.search);
    var tab = params.get('tab');
    if (tab && valid.indexOf(tab) !== -1) {
        dhTab(tab);
    }
})();
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
        header('Location: ' . System::web('admin/dashboard') . '?tab=dh-strat');
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
    $mode      = trim((string)($_POST['mode']           ?? 'demo'));
    // handoff_enabled: -1 means not submitted (don't change existing value)
    $handoffEnabledPost = isset($_POST['handoff_enabled']) ? (int)$_POST['handoff_enabled'] : -1;

    if (!in_array($mode, ['demo', 'live'], true)) {
        $mode = 'demo';
    }

    // Preserve all bot-owned execution fields from existing override; do not clobber
    // them — they are managed via the Bot/Control tab, not the strategy card form.
    $prev    = (array)($overrides[$stratId] ?? []);
    $newData = [
        'enabled' => (bool)$enabled,
        'mode'    => $mode,
    ];
    if ($handoffEnabledPost >= 0) {
        $newData['handoff_enabled'] = (bool)$handoffEnabledPost;
    }
    $overrides[$stratId] = array_merge($prev, $newData);

    if (!is_dir($storageDir)) {
        mkdir($storageDir, 0755, true);
    }
    file_put_contents($overridesFile, json_encode($overrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // For corridor_bottom_long, also sync enabled+mode+handoff_enabled into the strategy's own active.php
    if ($stratId === 'corridor_bottom_long') {
        $_cblActivePath = System::path('root') . '/modules/strategy/pattern/corridor_bottom_long/config/active.php';
        $_cblSafeMode   = ($mode === 'live') ? 'live' : 'demo';
        $_cblHandoff    = ($handoffEnabledPost >= 0)
            ? ($handoffEnabledPost > 0 ? 'true' : 'false')
            : ((bool)($overrides[$stratId]['handoff_enabled'] ?? false) ? 'true' : 'false');
        $_cblActiveContent = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "/**\n * Corridor Bottom Long — Active Config Overrides\n"
            . " *\n * Written by the admin UI or manually.\n"
            . " * Merged on top of base.php at runtime.\n */\n\n"
            . "return [\n"
            . "    'enabled'         => " . ((bool)$enabled ? 'true' : 'false') . ",\n"
            . "    'mode'            => '" . $_cblSafeMode . "',\n"
            . "    'handoff_enabled' => " . $_cblHandoff . ",\n"
            . "];\n";
        @file_put_contents($_cblActivePath, $_cblActiveContent);
    }

    $_SESSION['dashboard_flash'] = ['type' => 'success', 'msg' => "Настройки стратегии «{$stratId}» сохранены"];
    $activeTab = trim((string)($_POST['active_tab'] ?? 'dh-strat'));
    $validTabs = ['dh-overview', 'dh-strat', 'dh-bot', 'dh-sm', 'dh-pm', 'dh-ctrl'];
    if (!in_array($activeTab, $validTabs, true)) { $activeTab = 'dh-strat'; }
    header('Location: ' . System::web('admin/dashboard') . '?tab=' . $activeTab);
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
    $mode      = trim((string)($_POST['mode'] ?? 'demo'));
    $defEntry  = trim((string)($_POST['default_entry_mode']          ?? ''));
    $budget    = (float)($_POST['max_bot_budget']                    ?? 0.0);
    $leverage  = (int)($_POST['max_bot_leverage']                    ?? 0);
    $defMaxPos = (int)($_POST['default_max_active_positions']        ?? 0);

    // Demo credentials: only update when non-empty; never log the values
    $demoApiKey    = trim((string)($_POST['demo_api_key']      ?? ''));
    $demoApiSecret = trim((string)($_POST['demo_api_secret']   ?? ''));
    $demoBaseUrl   = trim((string)($_POST['demo_api_base_url'] ?? ''));

    if (!in_array($mode, ['demo', 'live'], true)) {
        $mode = 'demo';
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

    // Live credentials: account_id and live_enabled flag
    $accountId   = trim((string)($_POST['account_id']   ?? ''));
    $liveEnabled = (bool)(int)($_POST['live_enabled']  ?? 0);
    if ($accountId !== '') {
        $current['account_id'] = $accountId;
    }
    $current['live_enabled'] = $liveEnabled;

    // Only overwrite credentials when the user actually submitted a value
    if ($demoApiKey !== '') {
        $current['demo_api_key'] = $demoApiKey;
    }
    if ($demoApiSecret !== '') {
        $current['demo_api_secret'] = $demoApiSecret;
    }
    $current['demo_api_base_url'] = $demoBaseUrl !== ''
        ? $demoBaseUrl
        : ($current['demo_api_base_url'] ?? 'https://api-demo.bybit.com');

    // Serialise as PHP array file
    $php  = "<?php\n\ndeclare(strict_types=1);\n\n/**\n * Bot Module — Active Config Overrides\n * Written by the admin UI. Edit via the config page.\n */\n\nreturn ";
    $php .= var_export($current, true);
    $php .= ";\n";

    if (!is_dir(dirname($activeFile))) {
        mkdir(dirname($activeFile), 0755, true);
    }
    file_put_contents($activeFile, $php);

    $_SESSION['dashboard_flash'] = ['type' => 'success', 'msg' => 'Глобальные настройки бота сохранены'];
    $activeTab = trim((string)($_POST['active_tab'] ?? 'dh-ctrl'));
    $validTabs = ['dh-overview', 'dh-strat', 'dh-bot', 'dh-sm', 'dh-pm', 'dh-ctrl'];
    if (!in_array($activeTab, $validTabs, true)) { $activeTab = 'dh-ctrl'; }
    header('Location: ' . System::web('admin/dashboard') . '?tab=' . $activeTab);
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
    $validTabs = ['dh-overview', 'dh-strat', 'dh-bot', 'dh-sm', 'dh-pm', 'dh-ctrl'];
    $postTab   = trim((string)($_POST['active_tab'] ?? 'dh-strat'));
    $activeTab = in_array($postTab, $validTabs, true) ? $postTab : 'dh-strat';
    $dashUrl = System::web('admin/dashboard') . '?tab=' . $activeTab;

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

    // ── corridor_bottom_long — demo simulation only, no bot handoff ──────────
    if ($stratId === 'corridor_bottom_long') {
        $cblModuleDir = System::path('root') . '/modules/strategy/pattern/corridor_bottom_long';
        if (in_array($action, ['queue_run', 'tick_batch'], true)) {
            try {
                require_once $cblModuleDir . '/lib/validation_engine.php';
                require_once $cblModuleDir . '/lib/pattern_detector.php';
                require_once $cblModuleDir . '/strategy.php';
                $cblStrategy = new \Modules\Strategy\CorridorBottomLong\CorridorBottomLongStrategy($cblModuleDir);
                $cblResult   = $cblStrategy->runSimulation();
                $cblStats    = $cblResult['stats'] ?? [];
                $cblMsg = $cblResult['ok']
                    ? 'Симуляция выполнена · символов: ' . ($cblStats['symbols_checked'] ?? 0)
                        . ' · кандидатов: ' . ($cblStats['candidates_found'] ?? 0)
                        . ' · сигналов: ' . ($cblStats['generated_signals_count'] ?? 0)
                    : 'Ошибка симуляции: ' . ($cblResult['error'] ?? 'Неизвестная');
                $_SESSION['dashboard_flash'] = ['type' => $cblResult['ok'] ? 'success' : 'error', 'msg' => $cblMsg];
            } catch (\Throwable $cblEx) {
                $_SESSION['dashboard_flash'] = ['type' => 'error', 'msg' => 'Ошибка corridor_bottom_long: ' . $cblEx->getMessage()];
            }
            header('Location: ' . $dashUrl);
            exit;
        }
        if ($action === 'refresh') {
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
    $validTabs = ['dh-overview', 'dh-strat', 'dh-bot', 'dh-sm', 'dh-pm', 'dh-ctrl'];
    $postTab   = trim((string)($_POST['active_tab'] ?? 'dh-bot'));
    $activeTab = in_array($postTab, $validTabs, true) ? $postTab : 'dh-bot';
    $dashUrl = System::web('admin/dashboard') . '?tab=' . $activeTab;

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
    $validTabs = ['dh-overview', 'dh-strat', 'dh-bot', 'dh-sm', 'dh-pm', 'dh-ctrl'];
    $postTab   = trim((string)($_POST['active_tab'] ?? 'dh-sm'));
    $activeTab = in_array($postTab, $validTabs, true) ? $postTab : 'dh-sm';
    $dashUrl = System::web('admin/dashboard') . '?tab=' . $activeTab;

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
    $validTabs = ['dh-overview', 'dh-strat', 'dh-bot', 'dh-sm', 'dh-pm', 'dh-ctrl'];
    $postTab   = trim((string)($_POST['active_tab'] ?? 'dh-strat'));
    $activeTab = in_array($postTab, $validTabs, true) ? $postTab : 'dh-strat';
    $dashUrl = System::web('admin/dashboard') . '?tab=' . $activeTab;

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

    // For corridor_bottom_long, also sync enabled state into the strategy's own active.php
    // Preserve handoff_enabled from the existing override (or default false).
    if ($stratId === 'corridor_bottom_long') {
        $_cblActivePath   = System::path('root') . '/modules/strategy/pattern/corridor_bottom_long/config/active.php';
        $_cblHandoffVal   = (bool)($overrides[$stratId]['handoff_enabled'] ?? false);
        $_cblActiveContent = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "/**\n * Corridor Bottom Long — Active Config Overrides\n"
            . " *\n * Written by the admin UI or manually.\n"
            . " * Merged on top of base.php at runtime.\n */\n\n"
            . "return [\n"
            . "    'enabled'         => " . ($enabled ? 'true' : 'false') . ",\n"
            . "    'mode'            => 'demo',\n"
            . "    'handoff_enabled' => " . ($_cblHandoffVal ? 'true' : 'false') . ",\n"
            . "];\n";
        @file_put_contents($_cblActivePath, $_cblActiveContent);
    }

    $label = $enabled ? 'включена' : 'выключена';
    $_SESSION['dashboard_flash'] = ['type' => 'success', 'msg' => "Стратегия «{$stratId}» {$label}"];
    header('Location: ' . $dashUrl);
    exit;
}
} // end if (!function_exists('handleDashboardStrategyToggle'))

// ──────────────────────────────────────────────────────────────────────────────
// POST handler: per-strategy handoff toggle
// Registered as: POST /admin/dashboard (dashboard_action = strategy_handoff_toggle)
// Only flips handoff_enabled in operator_overrides.json and syncs to active.php
// for strategies that have their own config (e.g., corridor_bottom_long).
// ──────────────────────────────────────────────────────────────────────────────
if (!function_exists('handleDashboardHandoffToggle')) {
function handleDashboardHandoffToggle(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!\Core\Auth\Auth::check()) {
        http_response_code(403);
        exit;
    }

    $stratId        = trim((string)($_POST['strategy_id']     ?? ''));
    $handoffEnabled = (bool)(int)($_POST['handoff_enabled']   ?? 0);
    $validTabs      = ['dh-overview', 'dh-strat', 'dh-bot', 'dh-sm', 'dh-pm', 'dh-ctrl'];
    $postTab        = trim((string)($_POST['active_tab']      ?? 'dh-strat'));
    $activeTab      = in_array($postTab, $validTabs, true) ? $postTab : 'dh-strat';
    $dashUrl        = System::web('admin/dashboard') . '?tab=' . $activeTab;

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
    $overrides[$stratId] = array_merge($prev, ['handoff_enabled' => $handoffEnabled]);

    if (!is_dir($storageDir)) {
        mkdir($storageDir, 0755, true);
    }
    file_put_contents($overridesFile, json_encode($overrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // For corridor_bottom_long: sync handoff_enabled to the strategy's own active.php
    // Preserve enabled and mode from the existing override.
    if ($stratId === 'corridor_bottom_long') {
        $_cblActivePath   = System::path('root') . '/modules/strategy/pattern/corridor_bottom_long/config/active.php';
        $_cblEnabledVal   = (bool)($overrides[$stratId]['enabled']  ?? false);
        $_cblModeVal      = (string)($overrides[$stratId]['mode']   ?? 'demo');
        $_cblSafeMode     = ($_cblModeVal === 'live') ? 'live' : 'demo';
        $_cblActiveContent = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "/**\n * Corridor Bottom Long — Active Config Overrides\n"
            . " *\n * Written by the admin UI or manually.\n"
            . " * Merged on top of base.php at runtime.\n */\n\n"
            . "return [\n"
            . "    'enabled'         => " . ($_cblEnabledVal ? 'true' : 'false') . ",\n"
            . "    'mode'            => '" . $_cblSafeMode . "',\n"
            . "    'handoff_enabled' => " . ($handoffEnabled ? 'true' : 'false') . ",\n"
            . "];\n";
        @file_put_contents($_cblActivePath, $_cblActiveContent);
    }

    $label = $handoffEnabled ? 'включён' : 'выключен';
    $_SESSION['dashboard_flash'] = ['type' => 'success', 'msg' => "Handoff стратегии «{$stratId}» {$label}"];
    header('Location: ' . $dashUrl);
    exit;
}
} // end if (!function_exists('handleDashboardHandoffToggle'))

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
    $validTabs  = ['dh-overview', 'dh-strat', 'dh-bot', 'dh-sm', 'dh-pm', 'dh-ctrl'];
    $postTab    = trim((string)($_POST['active_tab'] ?? 'dh-bot'));
    $activeTab  = in_array($postTab, $validTabs, true) ? $postTab : 'dh-bot';
    $dashUrl    = System::web('admin/dashboard') . '?tab=' . $activeTab;
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
    $validTabs  = ['dh-overview', 'dh-strat', 'dh-bot', 'dh-sm', 'dh-pm', 'dh-ctrl'];
    $postTab    = trim((string)($_POST['active_tab'] ?? 'dh-sm'));
    $activeTab  = in_array($postTab, $validTabs, true) ? $postTab : 'dh-sm';
    $dashUrl    = System::web('admin/dashboard') . '?tab=' . $activeTab;
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

    $dashUrl = System::web('admin/dashboard') . '?tab=dh-overview';
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
    $validTabs = ['dh-overview', 'dh-strat', 'dh-bot', 'dh-sm', 'dh-pm', 'dh-ctrl'];
    $postTab   = trim((string)($_POST['active_tab'] ?? 'dh-pm'));
    $activeTab = in_array($postTab, $validTabs, true) ? $postTab : 'dh-pm';
    $dashUrl = System::web('admin/dashboard') . '?tab=' . $activeTab;

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
    $validTabs  = ['dh-overview', 'dh-strat', 'dh-bot', 'dh-sm', 'dh-pm', 'dh-ctrl'];
    $postTab    = trim((string)($_POST['active_tab'] ?? 'dh-pm'));
    $activeTab  = in_array($postTab, $validTabs, true) ? $postTab : 'dh-pm';
    $dashUrl    = System::web('admin/dashboard') . '?tab=' . $activeTab;
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

    $validTabs  = ['dh-overview', 'dh-strat', 'dh-bot', 'dh-sm', 'dh-pm', 'dh-ctrl'];
    $postTab    = trim((string)($_POST['active_tab'] ?? 'dh-ctrl'));
    $activeTab  = in_array($postTab, $validTabs, true) ? $postTab : 'dh-ctrl';
    $dashUrl    = System::web('admin/dashboard') . '?tab=' . $activeTab;
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
    $postMode = trim((string)($_POST['mode'] ?? 'demo'));
    $existing['mode']           = in_array($postMode, ['demo', 'live'], true) ? $postMode : 'demo';
    // Profile selection is always AUTO — no manual override
    $existing['active_profile'] = 'auto';

    // ── Long profile numeric fields ───────────────────────────────────────
    // Always writes to profiles['long'] (config key for legacy_safe_long).
    $profile = 'long';

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

    // Load long profile defaults as baseline
    $longProfileConfigFile = $moduleDir . '/profiles/long/config.php';
    $baseProfileCfg = [];
    if (is_file($longProfileConfigFile)) {
        try {
            $lpc = include $longProfileConfigFile;
            if (is_array($lpc)) {
                $baseProfileCfg = $lpc;
            }
        } catch (\Throwable) {}
    }
    $profileData = $existing['profiles'][$profile] ?? $baseProfileCfg;

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
// POST handler: HARD LOCAL RUNTIME RESET
// Clears all local runtime/cache/history storage without touching configs, keys, or Bybit.
// ──────────────────────────────────────────────────────────────────────────────
if (!function_exists('handleDashboardResetRuntime')) {
function handleDashboardResetRuntime(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Fixed absolute root — no user input, no path injection possible
    $root = defined('ROOT') ? rtrim(ROOT, '/') : dirname(__DIR__, 2);

    $filesClearedCount  = 0;
    $filesDeletedCount  = 0;

    // ── Helper: write JSON to fixed path, create dir if needed ────────────
    $safeWriteJson = static function (string $path, string $content) use (&$filesClearedCount): void {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);
        $filesClearedCount++;
    };

    // ── Helper: overwrite all *.json in a directory with a fixed value ────
    $safeWriteJsonDir = static function (string $dir, string $content) use (&$filesClearedCount): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*.json') ?: [] as $path) {
            file_put_contents($path, $content);
            $filesClearedCount++;
        }
    };

    // ── Helper: delete all *.json files inside a single directory ─────────
    $safeDeleteJsonFilesInDir = static function (string $dir) use (&$filesDeletedCount): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*.json') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
                $filesDeletedCount++;
            }
        }
    };

    // ── PART 3 — Bot runtime ──────────────────────────────────────────────
    $safeWriteJson($root . '/modules/bot/storage/order_queue.json',           '[]');
    $safeWriteJson($root . '/modules/bot/storage/active_orders.json',         '[]');
    $safeWriteJson($root . '/modules/bot/storage/active_positions.json',      '[]');
    $safeWriteJson($root . '/modules/bot/storage/position_runtime_age.json',  '{}');

    // bot/storage/runtime/*.json → {}
    $safeWriteJsonDir($root . '/modules/bot/storage/runtime', '{}');

    // bot/storage/trades/closed_trades.json → []
    $safeWriteJson($root . '/modules/bot/storage/trades/closed_trades.json', '[]');

    // bot/storage/trades/closed/*.json → delete files
    $safeDeleteJsonFilesInDir($root . '/modules/bot/storage/trades/closed');

    // ── PART 4 — Profit Manager runtime ──────────────────────────────────
    $safeWriteJsonDir($root . '/modules/prof_manager/storage/runtime', '{}');

    // Long profile
    $safeWriteJson($root . '/modules/prof_manager/profiles/long/storage/state.json',         '{}');
    $safeWriteJson($root . '/modules/prof_manager/profiles/long/storage/locks.json',         '{}');
    $safeWriteJson($root . '/modules/prof_manager/profiles/long/storage/patterns.json',      '{}');
    $safeWriteJson($root . '/modules/prof_manager/profiles/long/storage/price_history.json', '{}');

    // Short profile
    $safeWriteJson($root . '/modules/prof_manager/profiles/short/storage/state.json',    '{}');
    $safeWriteJson($root . '/modules/prof_manager/profiles/short/storage/locks.json',    '{}');
    $safeWriteJson($root . '/modules/prof_manager/profiles/short/storage/patterns.json', '{}');

    // PM close registry (lives in bot storage)
    $safeWriteJson($root . '/modules/bot/storage/runtime/pm_close_registry.json', '{}');

    // ── PART 5 — Stop Manager runtime ────────────────────────────────────
    $safeWriteJson($root . '/modules/stop_manager/storage/stops.json',    '{}');
    $safeWriteJson($root . '/modules/stop_manager/storage/last_run.json', '{}');

    // stop_manager/storage/runtime/*.json → {}
    $safeWriteJsonDir($root . '/modules/stop_manager/storage/runtime', '{}');

    // ── PART 6 — Strategy runtime (recursive) ────────────────────────────
    $strategyRuntimeFiles = [
        'active_positions.json'     => '[]',
        'bot_active_positions.json' => '[]',
        'signals.json'              => '[]',
        'active_signals.json'       => '[]',
        'last_signal.json'          => '{}',
        'last_run.json'             => '{}',
        'runtime.json'              => '{}',
        'candidates.json'           => '[]',
        'handoff.json'              => '[]',
        'queue.json'                => '[]',
        'candidates_found.json'     => '[]',
        'candidates_emitted.json'   => '[]',
        'bot_handoff_queue.json'    => '[]',
        'run_state.json'            => '{}',
        'cycle_stats.json'          => '{}',
        'stats.json'                => '{}',
    ];
    // Recursively find every directory named "storage" under modules/strategy/
    $stratRoot = $root . '/modules/strategy';
    $strategyStorageDirs = [];
    if (is_dir($stratRoot)) {
        $rit = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stratRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($rit as $item) {
            if ($item->isDir() && $item->getFilename() === 'storage') {
                $strategyStorageDirs[] = $item->getPathname();
            }
        }
    }
    foreach ($strategyStorageDirs as $storageDir) {
        foreach ($strategyRuntimeFiles as $filename => $emptyValue) {
            $path = $storageDir . '/' . $filename;
            if (is_file($path)) {
                file_put_contents($path, $emptyValue);
                $filesClearedCount++;
            }
        }
    }

    // ── Flash & redirect ──────────────────────────────────────────────────
    $msg = 'Полный локальный reset выполнен. Bybit позиции не закрывались.'
         . ' Очищено файлов: ' . $filesClearedCount . ', удалено: ' . $filesDeletedCount . '.'
         . ' Если Bybit позиции ещё открыты, они появятся снова после sync.';
    $_SESSION['dashboard_flash'] = ['type' => 'success', 'msg' => $msg];

    $activeTab = trim((string)($_POST['active_tab'] ?? 'dh-overview'));
    $validTabs = ['dh-overview', 'dh-strat', 'dh-bot', 'dh-sm', 'dh-pm', 'dh-ctrl'];
    if (!in_array($activeTab, $validTabs, true)) { $activeTab = 'dh-overview'; }
    header('Location: ' . System::web('admin/dashboard') . '?tab=' . $activeTab);
    exit;
}
} // end if (!function_exists('handleDashboardResetRuntime'))

// ──────────────────────────────────────────────────────────────────────────────
// POST handler: switch bot mode (demo / live)
// Registered as: POST /admin/dashboard (dashboard_action = switch_mode)
// Writes only the `mode` key to modules/bot/config/active.php.
// ──────────────────────────────────────────────────────────────────────────────
if (!function_exists('handleDashboardSwitchMode')) {
function handleDashboardSwitchMode(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!\Core\Auth\Auth::check()) {
        http_response_code(403);
        exit;
    }

    $mode       = trim((string)($_POST['mode'] ?? ''));
    $validModes = ['demo', 'live'];
    $validTabs  = ['dh-overview', 'dh-strat', 'dh-bot', 'dh-sm', 'dh-pm', 'dh-ctrl'];
    $postTab    = trim((string)($_POST['active_tab'] ?? 'dh-bot'));
    $activeTab  = in_array($postTab, $validTabs, true) ? $postTab : 'dh-bot';
    $dashUrl    = System::web('admin/dashboard') . '?tab=' . $activeTab;

    if (!in_array($mode, $validModes, true)) {
        $_SESSION['dashboard_flash'] = ['type' => 'error', 'msg' => 'Неверный режим: ' . htmlspecialchars($mode, ENT_QUOTES, 'UTF-8')];
        header('Location: ' . $dashUrl);
        exit;
    }

    $activeFile = System::path('root') . '/modules/bot/config/active.php';

    $current = [];
    if (file_exists($activeFile)) {
        $loaded = @include $activeFile;
        if (is_array($loaded)) {
            $current = $loaded;
        }
    }

    $oldMode = (string)($current['mode'] ?? '');
    $current['mode'] = $mode;

    $php  = "<?php\n\ndeclare(strict_types=1);\n\n/**\n * Bot Module — Active Config Overrides\n * Written by the admin UI. Edit via the config page.\n */\n\nreturn ";
    $php .= var_export($current, true);
    $php .= ";\n";

    $dir = dirname($activeFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($activeFile, $php);

    // When mode actually changes, clear runtime queue/cache so old-mode items don't leak
    $flashSuffix = '';
    if ($oldMode !== $mode) {
        $root       = defined('ROOT') ? rtrim(ROOT, '/') : dirname(__DIR__, 2);
        $runtimeFiles = [
            $root . '/modules/bot/storage/order_queue.json'      => '[]',
            $root . '/modules/bot/storage/active_orders.json'    => '[]',
            $root . '/modules/bot/storage/active_positions.json' => '[]',
        ];
        foreach ($runtimeFiles as $path => $emptyVal) {
            $fdir = dirname($path);
            if (!is_dir($fdir)) {
                mkdir($fdir, 0755, true);
            }
            file_put_contents($path, $emptyVal);
        }
        $flashSuffix = ' Режим изменён. Runtime queue/cache очищены.';
    }

    $modeLabel = strtoupper($mode);
    $_SESSION['dashboard_flash'] = ['type' => 'success', 'msg' => "Режим переключён: {$modeLabel}.{$flashSuffix}"];
    header('Location: ' . $dashUrl);
    exit;
}
} // end if (!function_exists('handleDashboardSwitchMode'))

// ──────────────────────────────────────────────────────────────────────────────
// POST dispatcher: single POST /admin/dashboard endpoint for all dashboard actions
// ──────────────────────────────────────────────────────────────────────────────
if (!function_exists('dispatchDashboardPost')) {
function dispatchDashboardPost(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!\Core\Auth\Auth::check()) {
        http_response_code(403);
        exit;
    }

    $action    = trim((string)($_POST['dashboard_action'] ?? ''));
    $activeTab = trim((string)($_POST['active_tab']       ?? 'dh-overview'));
    $validTabs = ['dh-overview', 'dh-strat', 'dh-bot', 'dh-sm', 'dh-pm', 'dh-ctrl'];
    if (!in_array($activeTab, $validTabs, true)) {
        $activeTab = 'dh-overview';
    }

    switch ($action) {
        case 'switch_mode':
            handleDashboardSwitchMode();
            break;
        case 'chain_run':
            handleDashboardChainRun();
            break;
        case 'reset_runtime':
            handleDashboardResetRuntime();
            break;
        case 'strategy_toggle':
            handleDashboardStrategyToggle();
            break;
        case 'strategy_handoff_toggle':
            handleDashboardHandoffToggle();
            break;
        case 'strategy_action':
            handleDashboardStrategyAction();
            break;
        case 'overrides_save':
            handleDashboardOverridesSave();
            break;
        case 'global_save':
            handleDashboardGlobalSave();
            break;
        case 'bot_toggle':
            handleDashboardBotToggle();
            break;
        case 'bot_tick':
            handleDashboardBotTick();
            break;
        case 'stop_manager_toggle':
            handleDashboardSmToggle();
            break;
        case 'stop_manager_tick':
            handleDashboardStopManagerTick();
            break;
        case 'stop_manager_config_save':
            \Modules\StopManager\Admin\StopManagerAdminController::instance()->configSave();
            break;
        case 'profit_manager_toggle':
            handleDashboardPmToggle();
            break;
        case 'profit_manager_tick':
            handleDashboardPmTick();
            break;
        case 'profit_manager_config_save':
            handleDashboardPmConfigSave();
            break;
        default:
            $_SESSION['dashboard_flash'] = [
                'type' => 'error',
                'msg'  => 'Неизвестное действие: ' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8'),
            ];
            header('Location: ' . System::web('admin/dashboard') . '?tab=' . $activeTab);
            exit;
    }
}
} // end if (!function_exists('dispatchDashboardPost'))
