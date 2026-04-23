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

    // ── escape helper ─────────────────────────────────────────────────────
    $e = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

    // ── per-strategy active signal counts from handoff queues ────────────
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

    // ── strategy cards HTML ───────────────────────────────────────────────
    $saveUrl      = System::web('admin/dashboard/overrides/save');
    $stratActUrl  = System::web('admin/dashboard/strategy/action');
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
            $opEnabled   = $op['enabled']              ?? true;
            $opMode      = (string)($op['mode']        ?? 'passive');
            $opBudget    = (float)($op['bot_budget']   ?? 0);
            $opLev       = (int)($op['bot_leverage']   ?? 0);
            $opEntry     = (string)($op['entry_mode']  ?? '');
            $opMax       = (int)($op['max_active_positions'] ?? 0);

            $esId    = $e($stratId);
            $esTitle = $e($title);
            $esPath  = $e($modulePath);
            $esBudget   = $e($opBudget);
            $esLev      = $e($opLev);
            $esEntry    = $e($opEntry);
            $esMax      = $e($opMax);
            $esMode     = $e($opMode);

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

            // Options: entry_mode select
            $entryNone    = $opEntry === ''       ? ' selected' : '';
            $entryLimit   = $opEntry === 'limit'  ? ' selected' : '';
            $entryMarket  = $opEntry === 'market' ? ' selected' : '';

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
        <td style="padding:3px 0;"><code>{$esMode}</code></td>
        <td style="padding:3px 12px 3px 16px;color:var(--ui-text-muted);">Вход</td>
        <td style="padding:3px 0;"><code>{$esEntry}</code></td>
      </tr>
      <tr>
        <td style="padding:3px 12px 3px 0;color:var(--ui-text-muted);">Бюджет</td>
        <td style="padding:3px 0;"><code>{$esBudget}</code></td>
        <td style="padding:3px 12px 3px 16px;color:var(--ui-text-muted);">Плечо</td>
        <td style="padding:3px 0;"><code>{$esLev}</code></td>
      </tr>
      <tr>
        <td style="padding:3px 12px 3px 0;color:var(--ui-text-muted);">Макс.поз</td>
        <td colspan="3" style="padding:3px 0;"><code>{$esMax}</code></td>
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
    <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
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
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Бюджет (0=из сигнала)</label>
            <input type="number" step="0.01" min="0" name="bot_budget" value="{$esBudget}" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Плечо (0=из сигнала)</label>
            <input type="number" step="1" min="0" name="bot_leverage" value="{$esLev}" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Режим входа</label>
            <select name="entry_mode" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
              <option value=""{$entryNone}>— из сигнала —</option>
              <option value="limit"{$entryLimit}>limit</option>
              <option value="market"{$entryMarket}>market</option>
            </select>
          </div>
          <div>
            <label style="font-size:12px;color:var(--ui-text-muted);display:block;margin-bottom:4px;">Макс. позиций (0=∞)</label>
            <input type="number" step="1" min="0" name="max_active_positions" value="{$esMax}" class="form-control" style="height:30px;font-size:13px;padding:2px 8px;">
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
        $sid = (string)($rec['strategy_id'] ?? '');
        $stitle = $e($rec['title'] ?? $sid);
        $op  = (array)($overrides[$sid] ?? []);
        $opEnabled = $op['enabled'] ?? true;
        $enColor = $opEnabled ? '#3fb950' : '#8b949e';
        $enLbl   = $opEnabled ? '✓' : '—';
        $mirrorRows .= '<tr>';
        $mirrorRows .= '<td><strong>' . $stitle . '</strong><br><code style="font-size:11px;color:#58a6ff;">' . $e($sid) . '</code></td>';
        $mirrorRows .= '<td style="color:' . $enColor . ';">' . $enLbl . '</td>';
        $mirrorRows .= '<td><code>' . $e($op['mode'] ?? 'passive') . '</code></td>';
        $mirrorRows .= '<td><code>' . $e($op['bot_budget'] ?? 0) . '</code></td>';
        $mirrorRows .= '<td><code>' . $e($op['bot_leverage'] ?? 0) . '</code></td>';
        $mirrorRows .= '<td><code>' . $e($op['entry_mode'] ?? '—') . '</code></td>';
        $mirrorRows .= '<td><code>' . $e($op['max_active_positions'] ?? 0) . '</code></td>';
        $mirrorRows .= '</tr>';
    }
    if ($mirrorRows === '') {
        $mirrorRows = '<tr><td colspan="7" style="color:var(--ui-text-muted);padding:12px 0;">Нет данных. Стратегии ещё не обнаружены.</td></tr>';
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
<div style="margin-bottom:16px;">
  <h4 style="margin:0 0 2px;"><i class="bi bi-layout-text-sidebar-reverse" style="margin-right:8px;"></i>Оперативный центр</h4>
  <div style="font-size:12px;color:var(--ui-text-muted);">Управление стратегиями · Бот · Контроль</div>
</div>

<!-- Summary strip -->
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;">
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

<!-- Top tab navigation (vanilla JS) -->
<nav class="dh-tab-nav" role="tablist">
  <button class="dh-tab-btn dh-active" onclick="dhTab(this,'dh-strat')" type="button">
    <i class="bi bi-layers" style="margin-right:5px;"></i>Стратегии
  </button>
  <button class="dh-tab-btn" onclick="dhTab(this,'dh-bot')" type="button">
    <i class="bi bi-cpu" style="margin-right:5px;"></i>Бот
  </button>
  <button class="dh-tab-btn" onclick="dhTab(this,'dh-ctrl')" type="button">
    <i class="bi bi-sliders" style="margin-right:5px;"></i>Управление
  </button>
</nav>

<!-- ── Strategies pane ──────────────────────────────────────────────── -->
<div id="dh-strat" class="dh-pane dh-visible">
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
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
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
      <small style="color:var(--ui-text-muted);font-size:11px;">Зеркало: operator_overrides.json · редактировать через карточки стратегий</small>
    </div>
    <div class="card-body" style="padding:0;">
      <table class="table" style="margin:0;font-size:13px;">
        <thead>
          <tr>
            <th>Стратегия</th>
            <th>Вкл</th>
            <th>Режим</th>
            <th>Бюджет</th>
            <th>Плечо</th>
            <th>Вход</th>
            <th>Макс.поз</th>
          </tr>
        </thead>
        <tbody>{$mirrorRows}</tbody>
      </table>
    </div>
  </div>
</div>

</div><!-- /max-width -->

<script>
function dhTab(btn, panelId) {
    document.querySelectorAll('.dh-tab-btn').forEach(function(b){ b.classList.remove('dh-active'); });
    document.querySelectorAll('.dh-pane').forEach(function(p){ p.classList.remove('dh-visible'); });
    btn.classList.add('dh-active');
    var panel = document.getElementById(panelId);
    if (panel) { panel.classList.add('dh-visible'); }
}
function dhToggleEdit(id) {
    var el = document.getElementById(id);
    if (el) { el.style.display = el.style.display === 'none' ? 'block' : 'none'; }
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
