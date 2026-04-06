<?php
/**
 * Smart Brain — Execution Control-Plane
 *
 * Brain acts as UI/control-plane only.
 * All execution logic stays in modules/system/trading_bot/.
 *
 * @var string               $smartBrainUrl
 * @var bool                 $bot_available
 * @var string|null          $bot_error
 * @var string               $bot_mode           live|demo|paper|dry
 * @var bool                 $bot_enabled
 * @var array<string,mixed>  $bot_config
 * @var array<string,mixed>  $bot_status
 * @var array<string,mixed>  $bot_last_run
 * @var array<string,mixed>  $bot_stats
 * @var array<string,mixed>  $bot_positions
 * @var array<int,mixed>     $bot_active_trades
 * @var array<int,mixed>     $bot_closed_trades
 * @var array<string,mixed>  $bot_balance
 * @var string               $bot_api_base_url
 * @var bool                 $bot_is_real_exchange
 * @var string               $bot_storage_dir
 * @var array<string,mixed>  $bot_demo_creds
 * @var array<string,mixed>  $bot_diag
 * @var array<string,mixed>  $bot_config_debug
 * @var array<string,mixed>  $bot_demo_data_sufficiency
 * @var array<string,mixed>  $bot_demo_truth_audit
 * @var array<string,mixed>  $pe_last_run
 */

$pageTitle = 'Smart Brain — Execution';
$activeTab = 'execution';

$extraStyles = '
.stat-card { background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 1rem; text-align: center; }
.stat-value { font-size: 1.35rem; font-weight: 700; color: #3b82f6; }
.stat-label { font-size: 0.72rem; color: #94a3b8; margin-top: 0.2rem; }
.positive { color: #4ade80; }
.negative { color: #f87171; }
.neutral  { color: #94a3b8; }
.badge-live  { background: #dc2626; color:#fff; padding: 2px 7px; border-radius:4px; font-size:.75rem; font-weight:700; }
.badge-demo  { background: #ea580c; color:#fff; padding: 2px 7px; border-radius:4px; font-size:.75rem; font-weight:700; }
.badge-paper { background: #475569; color:#fff; padding: 2px 7px; border-radius:4px; font-size:.75rem; font-weight:700; }
#flash-msg { display:none; position:fixed; top:1rem; right:1rem; z-index:9999; min-width:280px; }
.section-heading { border-bottom: 1px solid #334155; padding-bottom: .4rem; margin-bottom: 1rem; font-size:.95rem; font-weight:600; color:#94a3b8; text-transform:uppercase; letter-spacing:.04em; }
table.exec-table td, table.exec-table th { font-size: 0.78rem; vertical-align: middle; }
.settings-block { background: #0f172a; border: 1px solid #1e293b; border-radius:6px; padding: 1rem 1.2rem; margin-bottom:1.2rem; }
.settings-block h6 { color:#60a5fa; font-size:.8rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; margin-bottom:.8rem; }
';

$EXEC_URL = $smartBrainUrl . '/execution';

$extraScripts = <<<JS
<script>
const EXEC_URL = '{$EXEC_URL}';

function showFlash(msg, type) {
    const el = document.getElementById('flash-msg');
    el.className = 'alert alert-' + type + ' alert-dismissible shadow';
    document.getElementById('flash-text').textContent = msg;
    el.style.display = 'block';
    if (type === 'success') { setTimeout(() => { el.style.display = 'none'; }, 4500); }
}

function runBot() {
    const btn = document.getElementById('btn-run');
    btn.disabled = true; btn.textContent = 'Запуск…';
    showFlash('Запуск цикла бота…', 'info');
    fetch(EXEC_URL + '/run', { method: 'POST' })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false; btn.textContent = 'Запустить';
            if (d.ok) {
                const s = d.summary ?? {};
                showFlash('Запуск OK — открыто:' + (s.intents_opened ?? 0) + ' закрыто:' + (s.intents_closed ?? 0), 'success');
            } else {
                showFlash('Ошибка запуска: ' + (d.error ?? JSON.stringify(d)), 'danger');
            }
            setTimeout(() => location.reload(), 2200);
        })
        .catch(() => { btn.disabled = false; btn.textContent = 'Запустить'; showFlash('Ошибка сети.', 'danger'); });
}

function reconcileBot() {
    const btn = document.getElementById('btn-reconcile');
    btn.disabled = true; btn.textContent = 'Синхронизация…';
    showFlash('Принудительная синхронизация…', 'info');
    fetch(EXEC_URL + '/reconcile', { method: 'POST' })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false; btn.textContent = 'Синхронизировать';
            showFlash(d.ok ? 'Синхронизация завершена.' : 'Ошибка синхронизации: ' + (d.error ?? ''), d.ok ? 'success' : 'danger');
            if (d.ok) { setTimeout(() => location.reload(), 1800); }
        })
        .catch(() => { btn.disabled = false; btn.textContent = 'Синхронизировать'; showFlash('Ошибка сети.', 'danger'); });
}

function refreshStatus() {
    fetch(EXEC_URL + '/status')
        .then(r => r.json())
        .then(d => {
            const el = document.getElementById('status-badge');
            if (el) {
                el.textContent = d.ok ? (d.enabled ? 'ENABLED' : 'DISABLED') : 'ERROR';
                el.className = 'badge ' + (d.ok && d.enabled ? 'bg-success' : 'bg-secondary') + ' ms-1';
            }
            showFlash('Статус обновлён.', 'success');
        })
        .catch(() => showFlash('Не удалось обновить статус.', 'warning'));
}

function saveSettings() {
    const form = document.getElementById('settings-form');
    const data = {};
    new FormData(form).forEach((v, k) => { data[k] = v; });
    // Checkboxes (absent when unchecked)
    const checkboxes = ['enabled','reconcile_before_action','sources_brain_source_enabled','execution_trailing_enabled',
        'execution_break_even_enabled','execution_emergency_stop_enabled','execution_reverse_side_enabled'];
    checkboxes.forEach(k => {
        data[k] = !!(form.querySelector('[name="'+k+'"]')?.checked);
    });

    // Frontend validation before sending
    const tdf = parseFloat(data['trailing_drawdown_factor']);
    if (data['trailing_drawdown_factor'] !== undefined && data['trailing_drawdown_factor'] !== '') {
        if (isNaN(tdf) || tdf < 0.25 || tdf > 0.50) {
            showFlash('Trailing Drawdown Factor должен быть в диапазоне от 0.25 до 0.50', 'danger');
            return;
        }
    }
    const maxPos = parseInt(data['max_positions']);
    if (data['max_positions'] !== undefined && data['max_positions'] !== '' && (isNaN(maxPos) || maxPos < 1)) {
        showFlash('Максимальное количество позиций должно быть >= 1', 'danger');
        return;
    }
    const slPct = parseFloat(data['stop_loss_pct']);
    if (data['stop_loss_pct'] !== undefined && data['stop_loss_pct'] !== '' && (isNaN(slPct) || slPct <= 0)) {
        showFlash('Stop Loss % должен быть > 0', 'danger');
        return;
    }
    const tpPct = parseFloat(data['take_profit_pct']);
    if (data['take_profit_pct'] !== undefined && data['take_profit_pct'] !== '' && (isNaN(tpPct) || tpPct <= 0)) {
        showFlash('Take Profit % должен быть > 0', 'danger');
        return;
    }

    fetch(EXEC_URL + '/save_config', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(r => r.json())
    .then(d => {
        showFlash(d.ok ? 'Настройки сохранены.' : 'Ошибка сохранения: ' + (d.error ?? ''), d.ok ? 'success' : 'danger');
        if (d.ok) { setTimeout(() => location.reload(), 1600); }
    })
    .catch(() => showFlash('Ошибка сети при сохранении настроек.', 'danger'));
}

// Toggle demo section visibility based on mode selector
function onModeChange(sel) {
    const mode = sel.value;
    document.getElementById('section-demo-creds').style.display = (mode === 'demo') ? '' : 'none';
    document.getElementById('section-live-info').style.display  = (mode === 'live')  ? '' : 'none';
    document.getElementById('section-paper-info').style.display = (mode === 'paper' || mode === 'dry') ? '' : 'none';
    const storageMap = { live: 'storage_live', demo: 'storage_demo', paper: 'storage_paper', dry: 'storage_paper' };
    const el = document.getElementById('storage-ns-display');
    if (el) { el.textContent = storageMap[mode] || 'storage_paper'; }
}
</script>
JS;

include __DIR__ . '/_layout.php';
?>

<div id="flash-msg" class="alert alert-dismissible shadow" role="alert">
    <span id="flash-text"></span>
    <button type="button" class="btn-close" onclick="document.getElementById('flash-msg').style.display='none'"></button>
</div>

<?php
$modeCfg = $bot_config['module'] ?? [];
$exCfg   = $bot_config['execution'] ?? [];
$exchCfg = $bot_config['exchange'] ?? [];
$srcCfg  = $bot_config['sources'] ?? [];
$valCfg  = $bot_config['validation'] ?? [];

// ── DEBUG READBACK PANEL ────────────────────────────────────────────────────
// Temporary diagnostic: shows exactly what config the page is rendering from.
$_dbg = $bot_config_debug ?? [];
$_boolLabel = static fn($v): string => $v ? '<span style="color:#22c55e">true</span>' : '<span style="color:#ef4444">false</span>';
?>
<div style="background:#0f172a;border:2px solid #f97316;border-radius:6px;padding:12px 16px;margin-bottom:16px;font-family:monospace;font-size:12px;color:#e2e8f0">
    <div style="color:#f97316;font-weight:700;margin-bottom:6px">⚠ DEBUG — Readback конфига (убрать после расследования)</div>
    <div><b>Config path:</b> <?= htmlspecialchars((string)($_dbg['config_path'] ?? '(unknown)')) ?></div>
    <div><b>Mode:</b> <?= htmlspecialchars((string)($_dbg['mode'] ?? '(unknown)')) ?></div>
    <div style="margin-top:6px"><b>Toggle values used to render this page:</b></div>
    <table style="border-collapse:collapse;margin-top:4px">
        <tr><td style="padding:1px 12px 1px 0">execution.reverse_side_enabled</td><td><?= $_boolLabel((bool)($_dbg['execution_reverse_side_enabled'] ?? false)) ?></td></tr>
        <tr><td style="padding:1px 12px 1px 0">execution.trailing_enabled</td><td><?= $_boolLabel((bool)($_dbg['execution_trailing_enabled'] ?? false)) ?></td></tr>
        <tr><td style="padding:1px 12px 1px 0">execution.break_even_enabled</td><td><?= $_boolLabel((bool)($_dbg['execution_break_even_enabled'] ?? false)) ?></td></tr>
        <tr><td style="padding:1px 12px 1px 0">execution.emergency_stop_enabled</td><td><?= $_boolLabel((bool)($_dbg['execution_emergency_stop_enabled'] ?? false)) ?></td></tr>
        <tr><td style="padding:1px 12px 1px 0">sources.brain_source_enabled</td><td><?= $_boolLabel((bool)($_dbg['sources_brain_source_enabled'] ?? false)) ?></td></tr>
        <tr><td style="padding:1px 12px 1px 0">reconcile_before_action</td><td><?= $_boolLabel((bool)($_dbg['reconcile_before_action'] ?? false)) ?></td></tr>
    </table>
    <div style="margin-top:6px"><b>Raw execution block:</b> <code><?= htmlspecialchars(json_encode($_dbg['raw_execution_block'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></code></div>
    <div style="margin-top:4px"><b>Raw sources block:</b> <code><?= htmlspecialchars(json_encode($_dbg['raw_sources_block'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></code></div>
</div>
<?php
$modeLabel = match($bot_mode) {
    'live'  => '<span class="badge-live">LIVE</span>',
    'demo'  => '<span class="badge-demo">DEMO</span>',
    default => '<span class="badge-paper">PAPER</span>',
};
$enabledBadge = $bot_enabled
    ? '<span class="badge bg-success" id="status-badge">ENABLED</span>'
    : '<span class="badge bg-secondary" id="status-badge">DISABLED</span>';

$lastRunTs  = $bot_last_run['timestamp'] ?? ($bot_last_run['ts'] ?? null);
$lastRunOk  = $bot_last_run['ok'] ?? null;
$activeCount = count($bot_active_trades);
$closedCount = count($bot_closed_trades);
$storageNs = basename($bot_storage_dir ?? 'storage_paper');

$demoCreds      = $bot_demo_creds ?? [];
$demoKeySet     = (string)($demoCreds['api_key'] ?? '') !== '';
$demoSecretSet  = (bool)($demoCreds['api_secret_set'] ?? false);
$demoBaseUrl    = (string)($demoCreds['api_base_url'] ?? 'https://api-demo.bybit.com');
?>

<!-- ===== Баннер режима ===== -->
<?php if ($bot_mode === 'live'): ?>
<div class="alert alert-danger py-2 mb-3 d-flex align-items-center gap-2">
    <i class="bi bi-exclamation-octagon-fill fs-5"></i>
    <strong>LIVE РЕЖИМ — Реальная биржа, реальные средства. Все действия имеют финансовые последствия.</strong>
</div>
<?php elseif ($bot_mode === 'demo'): ?>
<div class="alert alert-warning py-2 mb-3 d-flex align-items-center gap-2" style="border-color:#ea580c; background:#431407; color:#fdba74;">
    <i class="bi bi-info-circle-fill fs-5"></i>
    <strong>DEMO РЕЖИМ — Bybit Demo API sandbox. Реальные средства не задействованы.</strong>
</div>
<?php else: ?>
<div class="alert alert-secondary py-2 mb-3 d-flex align-items-center gap-2">
    <i class="bi bi-archive-fill fs-5"></i>
    <strong>PAPER РЕЖИМ — Локальная симуляция. Подключение к бирже отсутствует.</strong>
</div>
<?php endif; ?>

<!-- ===== Обзор ===== -->
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
            <div>
                <h5 class="mb-1">
                    <i class="bi bi-cpu me-1"></i> Торговый бот
                    <?= $modeLabel ?>
                    <?= $enabledBadge ?>
                </h5>
                <small class="text-muted">Пространство хранилища: <strong id="storage-ns-display"><?= htmlspecialchars($storageNs) ?></strong></small>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <?php if (!$bot_available): ?>
                    <span class="text-danger small">Модуль недоступен</span>
                <?php else: ?>
                <button id="btn-run"       class="btn btn-success btn-sm" onclick="runBot()"><i class="bi bi-play-fill me-1"></i>Запустить</button>
                <button id="btn-reconcile" class="btn btn-outline-warning btn-sm" onclick="reconcileBot()"><i class="bi bi-arrow-repeat me-1"></i>Синхронизировать</button>
                <button class="btn btn-outline-secondary btn-sm" onclick="refreshStatus()"><i class="bi bi-arrow-clockwise me-1"></i>Обновить статус</button>
                <a href="/admin/trading_bot" class="btn btn-outline-light btn-sm" target="_blank"><i class="bi bi-box-arrow-up-right me-1"></i>Панель бота</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$bot_available): ?>
            <div class="alert alert-danger mb-0">Модуль бота недоступен: <?= htmlspecialchars((string)($bot_error ?? 'unknown')) ?></div>
        <?php else: ?>
        <div class="row g-2">
            <?php
            $cards = [
                ['label' => 'Режим',               'value' => strtoupper($bot_mode)],
                ['label' => 'API Base URL',         'value' => $bot_api_base_url],
                ['label' => 'Хранилище',            'value' => $storageNs],
                ['label' => 'Режим биржи',          'value' => $bot_is_real_exchange ? 'Реальная биржа' : 'Paper (без биржи)'],
                ['label' => 'Последний запуск',     'value' => $lastRunTs ? date('Y-m-d H:i:s', (int)$lastRunTs) : 'Нет'],
                ['label' => 'Статус запуска',       'value' => $lastRunOk === null ? 'н/д' : ($lastRunOk ? 'OK' : 'ОШИБКА')],
                ['label' => 'Активные позиции',     'value' => (string)$activeCount],
                ['label' => 'Закрытые сделки',      'value' => (string)$closedCount . ' (посл. 50)'],
            ];
            foreach ($cards as $c): ?>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-value"><?= htmlspecialchars($c['value']) ?></div>
                    <div class="stat-label"><?= htmlspecialchars($c['label']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($bot_balance)): ?>
        <div class="mt-3">
            <div class="section-heading">Баланс (снимок)</div>
            <div class="row g-2">
            <?php
            $bal = $bot_balance;
            $balItems = [
                'wallet_balance'     => 'Баланс кошелька',
                'available_balance'  => 'Доступный баланс',
                'unrealised_pnl'     => 'Нереализованный PnL',
                'margin_balance'     => 'Маржинальный баланс',
            ];
            foreach ($balItems as $bKey => $bLabel):
                if (!array_key_exists($bKey, $bal)) continue;
                $bVal = number_format((float)$bal[$bKey], 2);
            ?>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-value"><?= htmlspecialchars($bVal) ?></div>
                    <div class="stat-label"><?= htmlspecialchars($bLabel) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($bot_available): ?>

<!-- ===== Demo Credential Diagnostics ===== -->
<?php
$diag = $bot_diag ?? [];
$diagKeyPresent    = (bool)($diag['demo_api_key_present']    ?? false);
$diagSecretPresent = (bool)($diag['demo_api_secret_present'] ?? false);
$diagBaseUrl       = (string)($diag['demo_api_base_url']      ?? '');
$diagRealExchange  = (bool)($diag['is_real_exchange_mode']   ?? false);
$diagNs            = (string)($diag['storage_namespace']      ?? '');
$diagCfgPath       = (string)($diag['config_path']            ?? '');
$credBad = $bot_mode === 'demo' && (!$diagKeyPresent || !$diagSecretPresent);
?>
<?php if ($bot_mode === 'demo' || $credBad): ?>
<div class="card mb-4" style="border-color:<?= $credBad ? '#dc2626' : '#334155' ?>;">
    <div class="card-body">
        <div class="section-heading">Диагностика demo-учётных данных</div>
        <div class="row g-2">
            <?php
            $diagCards = [
                ['label' => 'Режим',               'value' => strtoupper($bot_mode),         'ok' => null],
                ['label' => 'Хранилище',           'value' => $diagNs,                        'ok' => null],
                ['label' => 'API Key задан',        'value' => $diagKeyPresent ? 'ДА' : 'НЕТ', 'ok' => $diagKeyPresent],
                ['label' => 'API Secret задан',     'value' => $diagSecretPresent ? 'ДА' : 'НЕТ', 'ok' => $diagSecretPresent],
                ['label' => 'Demo Base URL',        'value' => $diagBaseUrl ?: 'default',       'ok' => null],
                ['label' => 'Реальная биржа',       'value' => $diagRealExchange ? 'ДА' : 'НЕТ', 'ok' => $diagRealExchange],
            ];
            foreach ($diagCards as $dc):
                $cls = 'neutral';
                if ($dc['ok'] === true) $cls = 'positive';
                if ($dc['ok'] === false) $cls = 'negative';
            ?>
            <div class="col-6 col-md-2">
                <div class="stat-card">
                    <div class="stat-value <?= $cls ?>"><?= htmlspecialchars($dc['value']) ?></div>
                    <div class="stat-label"><?= htmlspecialchars($dc['label']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($credBad): ?>
        <div class="alert alert-danger mt-3 mb-0 py-2 small">
            <strong>Demo-учётные данные не настроены.</strong>
            Введите Bybit Demo API Key и Secret в разделе «Настройки» ниже и сохраните.
            Путь к конфигу: <code><?= htmlspecialchars($diagCfgPath) ?></code>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php
// ── Demo Signal Source panel — only shown in demo mode ──────────────────────
$demoSrcMode           = (string)($bot_last_run['demo_source_mode']               ?? '');
$demoSrcPath           = (string)($bot_last_run['demo_source_path']               ?? '');
$demoSigLoaded         = (int)   ($bot_last_run['demo_signals_loaded']            ?? -1);
$demoSigSkipped        = (int)   ($bot_last_run['demo_signals_skipped']           ?? -1);
$demoIntents           = (int)   ($bot_last_run['intents_loaded']                 ?? 0);
$demoActivePos         = (int)   $activeCount;
$demoStorageNs         = htmlspecialchars($storageNs);
// Pipeline open counters (added by demo pipeline block in service.php)
$demoSigAttempted      = $bot_last_run['demo_signals_attempted']            ?? null;
$demoSigOpened         = $bot_last_run['demo_signals_opened']               ?? null;
$demoSigBlockLimits    = $bot_last_run['demo_signals_blocked_by_limits']    ?? null;
$demoSigBlockValid     = $bot_last_run['demo_signals_blocked_by_validation']?? null;
$demoSigBlockExchange  = $bot_last_run['demo_signals_blocked_by_exchange']  ?? null;
$demoSigBlockOther     = $bot_last_run['demo_signals_blocked_other']        ?? null;
$demoRejStats          = (array)($bot_last_run['rejection_reason_stats']    ?? []);
// Demo close pipeline counters
$demoTradesActiveBefore     = $bot_last_run['demo_trades_active_before']               ?? null;
$demoTradesOpenedThisRun    = $bot_last_run['demo_trades_opened_this_run']             ?? null;
$demoTradesClosedThisRun    = $bot_last_run['demo_trades_closed_this_run']             ?? null;
$demoTradesStillActive      = $bot_last_run['demo_trades_still_active_after']          ?? null;
$demoTradesStaleThisRun     = $bot_last_run['demo_trades_stale_this_run']              ?? null;
$demoTradesReconciledThisRun= $bot_last_run['demo_trades_reconciled_this_run']         ?? null;
$demoFinalizedExchange      = $bot_last_run['demo_trades_finalized_from_exchange_this_run'] ?? null;
$demoFinalizedLocally       = $bot_last_run['demo_trades_finalized_locally_this_run']  ?? null;
$demoAvgAgeMinutes          = $bot_last_run['demo_average_active_age_minutes']         ?? null;
$demoOldestAgeMinutes       = $bot_last_run['demo_oldest_active_trade_minutes']        ?? null;
$demoAiWrittenThisRun       = $bot_last_run['demo_ai_dataset_records_written_this_run']?? null;
$demoCloseFailures          = $bot_last_run['demo_close_failures_this_run']            ?? null;
$demoCloseFailureReasons    = (array)($bot_last_run['demo_close_failure_reasons']      ?? []);
$topStaleTradeReasons       = (array)($bot_last_run['top_stale_trade_reasons']         ?? []);
// PART 1 & 2: Feed intake diagnostics
$demoFeedAvailable      = $bot_last_run['demo_feed_available_count']            ?? null;
$demoFeedSelected       = $bot_last_run['demo_feed_selected_count']             ?? null;
$demoFeedCapSkip        = $bot_last_run['demo_feed_skipped_due_to_cap']         ?? null;
$demoFeedIdempSkip      = $bot_last_run['demo_feed_skipped_due_to_idempotency'] ?? null;
$demoFeedRotMode        = (string)($bot_last_run['demo_signal_rotation_mode']   ?? '');
$demoFeedDeferred       = $bot_last_run['demo_signals_deferred_by_rotation']    ?? null;
// PART 3: Open capacity
$demoCapAvail           = $bot_last_run['demo_open_capacity_available']         ?? null;
$demoCapUsed            = $bot_last_run['demo_open_capacity_used']              ?? null;
$demoCapBlocked         = $bot_last_run['demo_open_blocked_by_capacity_count']  ?? null;
// PART 4: Stale prioritization
$demoStalePrioritized   = $bot_last_run['demo_stale_trades_prioritized_this_run'] ?? null;
$demoStaleFinalized     = $bot_last_run['demo_stale_trades_finalized_this_run']   ?? null;
$demoStaleRemaining     = $bot_last_run['demo_stale_trades_remaining_after_run']  ?? null;
// PART 5: per-run AI consistency
$demoClosedThisRun      = $bot_last_run['demo_closed_trades_this_run']               ?? null;
$demoAiMatchRateRun     = $bot_last_run['demo_closed_to_ai_match_rate_this_run']     ?? null;
$demoClosedNoAiRun      = $bot_last_run['demo_closed_without_ai_dataset_this_run']   ?? null;
// PART 6: closure bottleneck
$demoClosureBottleneck  = (string)($bot_last_run['primary_demo_closure_bottleneck']        ?? '');
$demoClosureReason      = (string)($bot_last_run['primary_demo_closure_bottleneck_reason'] ?? '');
$demoTurnoverFix        = (string)($bot_last_run['recommended_turnover_fix_area']          ?? '');
// PART 7: demo learning mode effective settings (proof that config is loaded)
$dlmEnabled             = $bot_last_run['demo_learning_mode_enabled']              ?? null;
$dlmMaxSignals          = $bot_last_run['demo_max_signals_per_run_effective']      ?? null;
$dlmMaxConcurrent       = $bot_last_run['demo_max_concurrent_positions_effective'] ?? null;
$dlmMaxNewPerRun        = $bot_last_run['demo_max_new_positions_per_run_effective']?? null;
// Demo intent risk limit proof fields
$demoEffRiskMaxOpen     = $bot_last_run['demo_effective_risk_max_open_trades']            ?? null;
$demoEffRiskMaxPerSym   = $bot_last_run['demo_effective_risk_max_open_trades_per_symbol'] ?? null;
$demoLimitsSource       = (string)($bot_last_run['demo_limits_source']                    ?? '');
// Effective trailing/break-even proof fields
$demoEffTrailingEnabled    = $bot_last_run['demo_effective_trailing_enabled']         ?? null;
$demoEffTrailingMode       = (string)($bot_last_run['demo_effective_trailing_mode']   ?? '');
$demoEffTrailingActivation = $bot_last_run['demo_effective_trailing_activation']      ?? null;
$demoEffTrailingDrawdown   = $bot_last_run['demo_effective_trailing_drawdown_factor'] ?? null;
$demoEffBreakEvenEnabled   = $bot_last_run['demo_effective_break_even_enabled']       ?? null;
$demoEffBreakEvenActivation= $bot_last_run['demo_effective_break_even_activation']   ?? null;
// Exchange orders vs local positions (from reconcile steps)
$reconcileStep = null;
foreach ((array)($bot_last_run['steps'] ?? []) as $_step) {
    if (in_array($_step['step'] ?? '', ['reconcile', 'reconcile_demo_forced'], true)) {
        $reconcileStep = $_step;
        break;
    }
}
$exchangePositionsSynced = $reconcileStep !== null ? (int)($reconcileStep['positions_synced'] ?? 0) : null;
$exchangeOrdersSynced    = $reconcileStep !== null ? (int)($reconcileStep['orders_synced'] ?? 0)    : null;
// Prefilter / symbol diversification diagnostics
$demoPrefiltInput    = $bot_last_run['demo_feed_prefilter_input_count']                    ?? null;
$demoPrefiltOutput   = $bot_last_run['demo_feed_prefilter_output_count']                   ?? null;
$demoPrefiltBusy     = $bot_last_run['demo_feed_prefilter_skipped_busy_symbol_count']      ?? null;
$demoPrefiltDup      = $bot_last_run['demo_feed_prefilter_skipped_duplicate_symbol_count'] ?? null;
$demoUniqueSymbols   = $bot_last_run['demo_feed_unique_symbols_selected_count']            ?? null;
$demoSkipSymBusy     = $bot_last_run['demo_selected_skipped_symbol_busy_count']            ?? null;
$demoSkipLateEntry   = $bot_last_run['demo_selected_rejected_late_entry_count']            ?? null;
// PART 3: Demo attempt/open budget proof fields
$demoAttemptBudget      = $bot_last_run['demo_attempt_budget_effective']               ?? null;
$demoOpenBudget         = $bot_last_run['demo_open_budget_effective']                  ?? null;
$demoSelectedScanned    = $bot_last_run['demo_selected_scanned_count']                 ?? null;
$demoSkippedBefore      = $bot_last_run['demo_selected_skipped_before_attempt_count']  ?? null;
$demoLoopAttempted      = $bot_last_run['demo_selected_attempted_count']               ?? null;
$demoLoopOpened         = $bot_last_run['demo_opened_count']                           ?? null;
$demoLoopStopReason     = (string)($bot_last_run['demo_loop_stopped_reason']           ?? '');
// Orphan exchange position blocking diagnostics
$demoOrphanDetected     = $bot_last_run['demo_orphan_positions_detected_count']    ?? null;
// Adopted orphan turnover counters (this run)
$adoptedOrphansActiveBefore          = $bot_last_run['adopted_orphans_active_before']                    ?? null;
$adoptedOrphansClosedThisRun         = $bot_last_run['adopted_orphans_closed_this_run']                  ?? null;
$adoptedOrphansStaleThisRun          = $bot_last_run['adopted_orphans_stale_this_run']                   ?? null;
$adoptedOrphansFinalizedLocally      = $bot_last_run['adopted_orphans_finalized_locally_this_run']       ?? null;
$adoptedOrphansFinalizedExchange     = $bot_last_run['adopted_orphans_finalized_from_exchange_this_run'] ?? null;
$adoptedOrphansCloseFailures         = $bot_last_run['adopted_orphans_close_failures_this_run']          ?? null;
$adoptedOrphanCloseFailureReasons    = (array)($bot_last_run['adopted_orphan_close_failure_reasons']     ?? []);
// Close quality counters (this run)
$adoptedOrphansClosedCompleteThisRun = $bot_last_run['adopted_orphans_closed_complete_this_run']              ?? null;
$adoptedOrphansAiWrittenThisRun      = $bot_last_run['adopted_orphans_ai_dataset_written_this_run']           ?? null;
$adoptedOrphansClosedNoAiThisRun     = $bot_last_run['adopted_orphans_closed_without_ai_dataset_this_run']    ?? null;
$adoptedOrphansRepairAttempted       = $bot_last_run['adopted_orphans_close_repair_attempted_this_run']       ?? null;
$adoptedOrphansRepairSucceeded       = $bot_last_run['adopted_orphans_close_repair_succeeded_this_run']       ?? null;
$adoptedOrphansRepairFailed          = $bot_last_run['adopted_orphans_close_repair_failed_this_run']          ?? null;
// Adopted orphan timing health (per-run counters)
$adoptedOrphansValidTiming   = $bot_last_run['adopted_orphans_with_valid_timing_count']   ?? null;
$adoptedOrphansMissingTiming = $bot_last_run['adopted_orphans_with_missing_timing_count'] ?? null;
$adoptedOrphansStaleEligible = $bot_last_run['adopted_orphans_stale_eligible_count']      ?? null;
$adoptedOrphansTimeoutEligible = $bot_last_run['adopted_orphans_timeout_eligible_count']  ?? null;
$adoptedOrphansAvgAge        = $bot_last_run['adopted_orphans_average_age_minutes']       ?? null;
$adoptedOrphansOldestAge     = $bot_last_run['adopted_orphans_oldest_age_minutes']        ?? null;
// Adopted orphan audit fields (from truth audit)
$auditAdoptedOrphansStale            = $bot_demo_truth_audit['adopted_orphans_stale_count']              ?? null;
$auditAdoptedOrphansClosedTotal      = $bot_demo_truth_audit['adopted_orphans_closed_total']             ?? null;
$auditAdoptedOrphansClosedCompleteRate    = $bot_demo_truth_audit['adopted_orphans_closed_complete_rate']        ?? null;
$auditAdoptedOrphansClosedFullCompleteCount = $bot_demo_truth_audit['adopted_orphans_closed_full_complete_count'] ?? null;
$auditAdoptedOrphansClosedFullCompleteRate  = $bot_demo_truth_audit['adopted_orphans_closed_full_complete_rate']  ?? null;
$auditAdoptedOrphansWithoutAi        = $bot_demo_truth_audit['adopted_orphans_without_ai_dataset_count'] ?? null;
// Adopted orphan missing-field detail counts (from audit)
$auditOrphanMissingClosePrice        = $bot_demo_truth_audit['adopted_orphans_closed_missing_close_price_count']  ?? null;
$auditOrphanMissingRoi               = $bot_demo_truth_audit['adopted_orphans_closed_missing_roi_count']          ?? null;
$auditOrphanMissingMfe               = $bot_demo_truth_audit['adopted_orphans_closed_missing_mfe_count']          ?? null;
$auditOrphanMissingMae               = $bot_demo_truth_audit['adopted_orphans_closed_missing_mae_count']          ?? null;
$auditOrphanMissingHoldMin           = $bot_demo_truth_audit['adopted_orphans_closed_missing_hold_minutes_count'] ?? null;
// Adopted orphan timing health (from truth audit)
$auditOrphanValidTiming              = $bot_demo_truth_audit['orphan_adopted_with_valid_timing_count']    ?? null;
$auditOrphanMissingTimingCount       = $bot_demo_truth_audit['orphan_adopted_with_missing_timing_count']  ?? null;
$auditOrphanStaleEligible            = $bot_demo_truth_audit['orphan_adopted_stale_eligible_count']       ?? null;
$auditOrphanTimeoutEligible          = $bot_demo_truth_audit['orphan_adopted_timeout_eligible_count']     ?? null;
$auditOrphanAvgAge                   = $bot_demo_truth_audit['orphan_adopted_average_age_minutes']        ?? null;
$auditOrphanOldestAge                = $bot_demo_truth_audit['orphan_adopted_oldest_age_minutes']         ?? null;
$demoOrphanBlocking     = $bot_last_run['demo_orphan_positions_blocking_count']    ?? null;
$demoPrimaryExecBlocker = (string)($bot_last_run['demo_primary_execution_blocker'] ?? '');
// Granular execution-stage blocking counters
$demoBlockedByReconcile = $bot_last_run['demo_signals_blocked_by_reconcile']  ?? null;
$demoBlockedByOrphan    = $bot_last_run['demo_signals_blocked_by_orphan']     ?? null;
$demoBlockedByLateEntry = $bot_last_run['demo_signals_blocked_by_late_entry'] ?? null;
$demoOrphansAdopted     = $bot_last_run['orphan_positions_adopted_this_run']  ?? null;
$lateEntryThreshold     = $bot_last_run['late_entry_threshold_effective']     ?? null;
$demoExecBlockerSpecific= (string)($bot_last_run['demo_primary_execution_blocker_specific'] ?? $demoPrimaryExecBlocker);
$lateEntryNearMiss      = $bot_last_run['late_entry_near_miss_count']         ?? null;
$demoFailedAfterOrder   = $bot_last_run['demo_signals_failed_after_order_attempt'] ?? null;
// Orphan adoption quality counters
$orphanAdoptionAttempted = $bot_last_run['orphan_adoption_attempted_count']  ?? null;
$orphanAdoptionSucceeded = $bot_last_run['orphan_adoption_succeeded_count']  ?? null;
$orphanAdoptionFailed    = $bot_last_run['orphan_adoption_failed_count']     ?? null;
$orphanAdoptionReusable  = $bot_last_run['orphan_adoption_reusable_count']   ?? null;
$orphanAdoptionDeadShell = $bot_last_run['orphan_adoption_dead_shell_count'] ?? null;
// Truth audit active trade classification
$auditHealthyActive      = $bot_demo_truth_audit['healthy_active_trades_count']        ?? null;
$auditOrphanAdopted      = $bot_demo_truth_audit['orphan_adopted_active_trades_count'] ?? null;
$auditOrphanDeadShells   = $bot_demo_truth_audit['orphan_dead_shells_count']           ?? null;
$auditOrphanResolved     = $bot_demo_truth_audit['orphan_resolved_active_trades_count'] ?? null;
$auditOrphanUnresolved   = $bot_demo_truth_audit['orphan_unresolved_blocking_count']   ?? null;
// Orphan ownership resolution counters (this run)
$orphanResolvedAsLocal   = $bot_last_run['orphan_positions_resolved_as_local_ownership_this_run'] ?? null;
$orphanStillBlocking     = $bot_last_run['orphan_positions_still_blocking_this_run']              ?? null;
$symbolsBusyAdopted      = $bot_last_run['symbols_busy_due_to_local_adopted_trade_count']         ?? null;
// Capacity / Turnover diagnostics (this run)
$demoCapacityFullRun     = $bot_last_run['demo_capacity_full']                       ?? null;
$demoCapSlotsTotalRun    = $bot_last_run['demo_capacity_slots_total']                ?? null;
$demoCapSlotsBeforeRun   = $bot_last_run['demo_capacity_slots_used_before_turnover'] ?? null;
$demoCapSlotsFreedRun    = $bot_last_run['demo_capacity_slots_freed_this_run']       ?? null;
$demoCapSlotsAfterRun    = $bot_last_run['demo_capacity_slots_used_after_turnover']  ?? null;
$demoTurnoverModeRun     = $bot_last_run['demo_turnover_mode_triggered']             ?? null;
$demoTurnoverCandRun     = $bot_last_run['demo_turnover_candidates_count']           ?? null;
$demoTurnoverProcRun     = $bot_last_run['demo_turnover_processed_count']            ?? null;
$demoTurnoverFreedRun    = $bot_last_run['demo_turnover_freed_capacity']             ?? null;
$demoTurnoverBlockRun    = (string)($bot_last_run['demo_turnover_block_reason']                    ?? '');
$demoTurnoverPriStats    = (array)($bot_last_run['demo_turnover_priority_stats']                   ?? []);
$demoTurnoverAiRun       = $bot_last_run['demo_turnover_pass_ai_records_written']                  ?? null;
// Turnover candidate breakdown (this run)
$demoTurnoverCandStale   = $bot_last_run['demo_turnover_candidates_stale_count']                   ?? null;
$demoTurnoverCandTimeout = $bot_last_run['demo_turnover_candidates_timeout_count']                 ?? null;
$demoTurnoverCandDead    = $bot_last_run['demo_turnover_candidates_dead_shell_count']              ?? null;
$demoTurnoverCandFinElig = $bot_last_run['demo_turnover_candidates_finalize_eligible_count']       ?? null;
$demoTurnoverCandOther   = $bot_last_run['demo_turnover_candidates_other_count']                   ?? null;
// Capacity fields from truth audit
$auditCapFull            = $bot_demo_truth_audit['capacity_full']                                  ?? null;
$auditCapSlotsTotal      = $bot_demo_truth_audit['capacity_slots_total']                           ?? null;
$auditCapSlotsUsed       = $bot_demo_truth_audit['capacity_slots_used']                            ?? null;
$auditCapSlotsFreed      = $bot_demo_truth_audit['capacity_slots_freed_this_run']                  ?? null;
$auditRecoverableCount   = $bot_demo_truth_audit['recoverable_active_trades_count']                ?? null;
$auditTurnoverCandCount  = $bot_demo_truth_audit['turnover_candidates_count']                      ?? null;
$auditConsistencyOk      = $bot_demo_truth_audit['capacity_runtime_consistency_ok']                ?? null;
$auditConsistencyWarning = (string)($bot_demo_truth_audit['capacity_runtime_consistency_warning']  ?? '');
// Healthy active turnover (this run)
$healthyActiveBefore     = $bot_last_run['healthy_active_trades_before']                          ?? null;
$healthyActiveStaleRun   = $bot_last_run['healthy_active_trades_stale_this_run']                  ?? null;
$healthyActiveTimeoutRun = $bot_last_run['healthy_active_trades_timeout_eligible_this_run']       ?? null;
$healthyActiveCandRun    = $bot_last_run['healthy_active_turnover_candidates_count']              ?? null;
$healthyActiveProcRun    = $bot_last_run['healthy_active_turnover_processed_count']               ?? null;
$healthyActiveClosedRun  = $bot_last_run['healthy_active_closed_this_run']                        ?? null;
$healthyActiveFailRun    = $bot_last_run['healthy_active_close_failures_this_run']                ?? null;
$healthyActiveFailRsns   = (array)($bot_last_run['healthy_active_close_failure_reasons']          ?? []);
$healthyTurnoverTriggered   = $bot_last_run['healthy_turnover_triggered']   ?? null;
$healthyTurnoverBlockReason = (string)($bot_last_run['healthy_turnover_block_reason'] ?? '');
// Healthy close quality counters (this run)
$healthyClosedTotal      = $bot_last_run['healthy_closed_this_run_total']               ?? null;
$healthyClosedFullCompl  = $bot_last_run['healthy_closed_this_run_full_complete']       ?? null;
$healthyClosedMissMfe    = $bot_last_run['healthy_closed_this_run_missing_mfe']         ?? null;
$healthyClosedMissMae    = $bot_last_run['healthy_closed_this_run_missing_mae']         ?? null;
$healthyClosedMissCp     = $bot_last_run['healthy_closed_this_run_missing_close_price'] ?? null;
$healthyClosedMissHold   = $bot_last_run['healthy_closed_this_run_missing_hold_minutes']?? null;
$healthyAiWrittenRun     = $bot_last_run['healthy_ai_dataset_written_this_run']         ?? null;
// Healthy close quality from audit (all-time)
$auditHealthyClosedTotal     = $bot_demo_truth_audit['closed_trades_healthy_total']                ?? null;
$auditHealthyClosedFC        = $bot_demo_truth_audit['closed_trades_healthy_full_complete_count']  ?? null;
$auditHealthyClosedFCRate    = $bot_demo_truth_audit['closed_trades_healthy_full_complete_rate']   ?? null;
$auditHealthyMissMfe         = $bot_demo_truth_audit['closed_trades_healthy_missing_mfe_count']   ?? null;
$auditHealthyMissMae         = $bot_demo_truth_audit['closed_trades_healthy_missing_mae_count']   ?? null;
$auditHealthyMissCp          = $bot_demo_truth_audit['closed_trades_healthy_missing_close_price_count'] ?? null;
$auditHealthyMissHold        = $bot_demo_truth_audit['closed_trades_healthy_missing_hold_minutes_count'] ?? null;
// Closed trade breakdown (this run)
$closedTotalRun          = $bot_last_run['closed_trades_this_run_total']                          ?? null;
$closedHealthyRun        = $bot_last_run['closed_trades_this_run_healthy']                        ?? null;
$closedOrphanRun         = $bot_last_run['closed_trades_this_run_orphan_adopted']                 ?? null;
$aiDatasetWrittenRun     = $bot_last_run['ai_dataset_written_this_run_total']                     ?? null;
// Velocity target (this run)
$targetPerRun            = $bot_last_run['demo_closed_trades_target_per_run']                     ?? null;
$targetMet               = $bot_last_run['demo_closed_trades_target_met']                         ?? null;
$targetGap               = $bot_last_run['demo_closed_trades_target_gap']                         ?? null;
// Healthy active counts from audit
$auditHealthyActive      = $bot_demo_truth_audit['healthy_active_trades_count']                   ?? null;
$auditHealthyStale       = $bot_demo_truth_audit['healthy_active_trades_stale_count']             ?? null;
$auditHealthyTimeout     = $bot_demo_truth_audit['healthy_active_trades_timeout_eligible_count']  ?? null;
$auditHealthyCand        = $bot_demo_truth_audit['healthy_active_turnover_candidates_count']      ?? null;
$auditClosedHealthy      = $bot_demo_truth_audit['closed_trades_healthy_total']                   ?? null;
$auditClosedOrphan       = $bot_demo_truth_audit['closed_trades_orphan_adopted_total']            ?? null;
$auditClosedTotal        = $bot_demo_truth_audit['closed_trades_total']                           ?? null;
$auditAiTotal            = $bot_demo_truth_audit['ai_dataset_total']                              ?? null;
$auditTargetPerRun       = $bot_demo_truth_audit['demo_closed_trades_target_per_run']             ?? null;
$auditTargetMet          = $bot_demo_truth_audit['demo_closed_trades_target_met']                 ?? null;
$auditTargetGap          = $bot_demo_truth_audit['demo_closed_trades_target_gap']                 ?? null;
// PART 4-5: Demo composition fields (from last_run + truth audit)
$compActiveHealthy       = $bot_last_run['demo_active_healthy_count']           ?? null;
$compActiveOrphan        = $bot_last_run['demo_active_orphan_adopted_count']    ?? null;
$compClosedHealthyTotal  = $bot_last_run['demo_closed_healthy_total']           ?? $auditClosedHealthy;
$compClosedOrphanTotal   = $bot_last_run['demo_closed_orphan_adopted_total']    ?? $auditClosedOrphan;
$compClosedHealthyRun    = $bot_last_run['demo_closed_this_run_healthy']        ?? null;
$compClosedOrphanRun     = $bot_last_run['demo_closed_this_run_orphan_adopted'] ?? null;
$compHealthyShareActive  = $bot_last_run['demo_healthy_share_active_pct']       ?? $bot_demo_truth_audit['healthy_share_active_pct'] ?? null;
$compHealthyShareClosed  = $bot_last_run['demo_healthy_share_closed_pct']       ?? $bot_demo_truth_audit['healthy_share_closed_pct'] ?? null;
$compOrphanSlotPressure  = $bot_last_run['demo_orphan_slot_pressure']           ?? $bot_demo_truth_audit['orphan_slot_pressure'] ?? null;
$compHealthyReserveTotal = $bot_last_run['demo_healthy_slot_reserve_total']     ?? $bot_demo_truth_audit['healthy_slot_reserve_total'] ?? null;
$compHealthyReserveAvail = $bot_last_run['demo_healthy_slot_reserve_available'] ?? $bot_demo_truth_audit['healthy_slot_reserve_available'] ?? null;
$compOrphanSlotCap       = $bot_last_run['demo_orphan_slot_cap']                ?? $bot_demo_truth_audit['orphan_slot_cap'] ?? null;
$compOrphanCapReached    = $bot_last_run['demo_orphan_slot_cap_reached']        ?? $bot_demo_truth_audit['orphan_slot_cap_reached'] ?? null;
$compBottleneck          = (string)($bot_last_run['demo_composition_bottleneck'] ?? $bot_demo_truth_audit['primary_composition_bottleneck'] ?? '');
$compBottleneckReason    = (string)($bot_last_run['demo_composition_bottleneck_reason'] ?? $bot_demo_truth_audit['primary_composition_bottleneck_reason'] ?? '');
$compShareTarget         = $bot_demo_truth_audit['healthy_share_target_pct']   ?? null;
$compOrphanCapBlocked    = $bot_last_run['demo_orphan_cap_blocked_adoptions']   ?? null;
?>
<?php if ($bot_mode === 'demo' && $demoSrcMode !== ''): ?>
<div class="card mb-4" style="border-color:#1e40af;">
    <div class="card-body">
        <div class="section-heading">Состояние demo-контура</div>
        <?php if ($dlmEnabled !== null): ?>
        <div class="row g-2 mb-3">
            <?php
            $dlmCards = [
                ['label' => 'DLM Активен',          'value' => $dlmEnabled ? 'ДА' : 'НЕТ',                                              'ok' => $dlmEnabled],
                ['label' => 'Сигналов/запуск',      'value' => $dlmMaxSignals !== null ? (string)$dlmMaxSignals : 'н/д',               'ok' => ($dlmMaxSignals ?? 0) > 0 ? true : null],
                ['label' => 'Макс. позиций',        'value' => $dlmMaxConcurrent !== null ? (string)$dlmMaxConcurrent : 'н/д',         'ok' => ($dlmMaxConcurrent ?? 0) > 0 ? true : null],
            ];
            foreach ($dlmCards as $dc):
                $cls = 'neutral';
                if ($dc['ok'] === true) $cls = 'positive';
                if ($dc['ok'] === false) $cls = 'negative';
            ?>
            <div class="col-6 col-md-2">
                <div class="stat-card">
                    <div class="stat-value <?= $cls ?>"><?= htmlspecialchars((string)$dc['value']) ?></div>
                    <div class="stat-label"><?= htmlspecialchars($dc['label']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (!$dlmEnabled): ?>
        <div class="alert alert-warning py-1 px-3 mb-2" style="font-size:.8rem;">
            <strong>Demo Learning Mode отключён.</strong> Включите в bot.json или через Настройки для активации лимитов сигналов и закрытия устаревших сделок.
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <div class="row g-2 mb-2">
            <?php
            $srcCards = [
                ['label' => 'Источник (mode)',      'value' => htmlspecialchars($demoSrcMode),                       'ok' => null],
                ['label' => 'Хранилище',            'value' => $demoStorageNs,                                       'ok' => null],
                ['label' => 'Сигналов загружено',   'value' => $demoSigLoaded >= 0 ? (string)$demoSigLoaded : 'н/д', 'ok' => null],
                ['label' => 'Пропущено (TTL/dup)',  'value' => $demoSigSkipped >= 0 ? (string)$demoSigSkipped : 'н/д', 'ok' => null],
                ['label' => 'Попыток',              'value' => $demoSigAttempted !== null ? (string)$demoSigAttempted : 'н/д', 'ok' => null],
                ['label' => 'Открыто',              'value' => $demoSigOpened !== null ? (string)$demoSigOpened : 'н/д',       'ok' => $demoSigOpened > 0 ?: null],
                ['label' => 'Активных позиций',     'value' => (string)$demoActivePos,                                'ok' => null],
                ['label' => 'Блок: лимиты',         'value' => $demoSigBlockLimits !== null ? (string)$demoSigBlockLimits : 'н/д',   'ok' => $demoSigBlockLimits === 0 ? true : null],
                ['label' => 'Блок: валидация',      'value' => $demoSigBlockValid !== null ? (string)$demoSigBlockValid : 'н/д',     'ok' => null],
                ['label' => 'Блок: биржа',          'value' => $demoSigBlockExchange !== null ? (string)$demoSigBlockExchange : 'н/д','ok' => $demoSigBlockExchange === 0 ? true : null],
                ['label' => 'Блок: прочее',         'value' => $demoSigBlockOther !== null ? (string)$demoSigBlockOther : 'н/д',     'ok' => null],
                ['label' => 'Интентов на исполн.',  'value' => (string)$demoIntents,                                  'ok' => $demoIntents > 0],
            ];
            foreach ($srcCards as $sc):
                $cls = 'neutral';
                if ($sc['ok'] === true) $cls = 'positive';
                if ($sc['ok'] === false) $cls = 'negative';
            ?>
            <div class="col-6 col-md-2">
                <div class="stat-card">
                    <div class="stat-value <?= $cls ?>"><?= $sc['value'] ?></div>
                    <div class="stat-label"><?= htmlspecialchars($sc['label']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
        // ── Execution-Stage Diagnostics (shows WHERE selected signals are blocked) ──
        $execBlockerLabel = [
            'execution_blocked_by_reconcile'       => 'Сбой сверки (позиция после открытия не найдена)',
            'execution_blocked_by_orphan_positions'=> 'Orphan-позиции (нет локальной записи для биржевой позиции)',
            'execution_blocked_by_late_entry'      => 'Поздний вход (цена вышла за допустимый порог)',
            'execution_blocked_by_capacity'        => 'Ёмкость (достигнут лимит одновременных позиций)',
            'execution_blocked_by_validation'      => 'Валидация (поле интента отсутствует или недопустимо)',
            'execution_healthy_waiting_for_closure'=> 'Норма — ожидаем закрытия открытых позиций',
            'none'                                 => 'Ничего не обнаружено за этот запуск',
        ];
        $execBlockerText = $execBlockerLabel[$demoExecBlockerSpecific] ?? $demoExecBlockerSpecific;
        $hasExecData = ($demoBlockedByReconcile !== null || $demoBlockedByOrphan !== null);
        if ($hasExecData):
        ?>
        <div class="section-heading" style="font-size:.8rem;margin-top:.75rem;">Диагностика стадий исполнения demo</div>
        <?php if ($demoExecBlockerSpecific !== '' && $demoExecBlockerSpecific !== 'none' && $demoExecBlockerSpecific !== 'execution_healthy_waiting_for_closure'): ?>
        <div class="alert alert-warning py-1 px-3 mb-2" style="font-size:.8rem;">
            <strong>Основной блокировщик исполнения:</strong> <?= htmlspecialchars($execBlockerText) ?>
        </div>
        <?php elseif ($demoExecBlockerSpecific === 'execution_healthy_waiting_for_closure'): ?>
        <div class="alert alert-success py-1 px-3 mb-2" style="font-size:.8rem;">
            <strong>Исполнение в норме</strong> — позиции открыты, ожидаем закрытий.
        </div>
        <?php endif; ?>
        <div class="row g-2 mb-3">
            <?php
            $execStageCards = [
                ['label' => 'Сигналов выбрано',      'value' => $demoFeedSelected !== null ? (string)$demoFeedSelected : 'n/a',
                    'ok' => ($demoFeedSelected ?? 0) > 0 ? true : null],
                ['label' => 'Попыток',               'value' => $demoSigAttempted !== null ? (string)$demoSigAttempted : 'n/a',
                    'ok' => null],
                ['label' => 'Открыто',               'value' => $demoSigOpened !== null ? (string)$demoSigOpened : 'n/a',
                    'ok' => ($demoSigOpened ?? 0) > 0 ? true : null],
                ['label' => 'Блок: Сверка',          'value' => $demoBlockedByReconcile !== null ? (string)$demoBlockedByReconcile : 'n/a',
                    'ok' => $demoBlockedByReconcile === 0 ? true : ($demoBlockedByReconcile > 0 ? false : null)],
                ['label' => 'Блок: Orphan',          'value' => $demoBlockedByOrphan !== null ? (string)$demoBlockedByOrphan : 'n/a',
                    'ok' => $demoBlockedByOrphan === 0 ? true : ($demoBlockedByOrphan > 0 ? false : null)],
                ['label' => 'Принято orphan',         'value' => $demoOrphansAdopted !== null ? (string)$demoOrphansAdopted : 'n/a',
                    'ok' => ($demoOrphansAdopted ?? 0) > 0 ? true : null],
                ['label' => 'Блок: Поздний вход',    'value' => $demoBlockedByLateEntry !== null ? (string)$demoBlockedByLateEntry : 'n/a',
                    'ok' => $demoBlockedByLateEntry === 0 ? true : null],
                ['label' => 'Почти поздний вход',    'value' => $lateEntryNearMiss !== null ? (string)$lateEntryNearMiss : 'n/a',
                    'ok' => null],
                ['label' => 'Порог позд. входа',     'value' => $lateEntryThreshold !== null ? round($lateEntryThreshold, 2) . '%' : 'n/a',
                    'ok' => null],
                ['label' => 'Ошибок после ордера',   'value' => $demoFailedAfterOrder !== null ? (string)$demoFailedAfterOrder : 'n/a',
                    'ok' => $demoFailedAfterOrder === 0 ? true : ($demoFailedAfterOrder > 0 ? false : null)],
                ['label' => 'Блокировщик исполнения','value' => $demoExecBlockerSpecific ?: 'n/a',
                    'ok' => in_array($demoExecBlockerSpecific, ['none','execution_healthy_waiting_for_closure']) ? true : ($demoExecBlockerSpecific !== '' ? false : null)],
            ];
            foreach ($execStageCards as $ec):
                $cls = 'neutral';
                if ($ec['ok'] === true) $cls = 'positive';
                if ($ec['ok'] === false) $cls = 'negative';
            ?>
            <div class="col-6 col-md-2">
                <div class="stat-card">
                    <div class="stat-value <?= $cls ?>"><?= htmlspecialchars((string)$ec['value']) ?></div>
                    <div class="stat-label"><?= htmlspecialchars($ec['label']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
        // ── Demo Intent Risk Limits (proof that intents carry real capacity) ─
        $hasLimitData = ($demoEffRiskMaxOpen !== null);
        if ($hasLimitData):
        ?>
        <div class="section-heading" style="font-size:.8rem;margin-top:.75rem;">Лимиты рисков demo-интентов</div>
        <div class="row g-2 mb-2">
            <?php
            $limitCards = [
                ['label' => 'Макс. сделок (интент)',  'value' => $demoEffRiskMaxOpen !== null ? (string)$demoEffRiskMaxOpen : 'n/a',
                    'ok' => ($demoEffRiskMaxOpen ?? 0) >= ($dlmMaxConcurrent ?? 0) ? true : ($demoEffRiskMaxOpen !== null ? false : null)],
                ['label' => 'Макс./символ (интент)', 'value' => $demoEffRiskMaxPerSym !== null ? (string)$demoEffRiskMaxPerSym : 'n/a',
                    'ok' => null],
                ['label' => 'Источник лимитов',      'value' => $demoLimitsSource ?: 'n/a',
                    'ok' => ($demoLimitsSource === 'demo_learning_mode.max_concurrent_demo_positions') ? true : null],
            ];
            foreach ($limitCards as $lc):
                $lcls = 'neutral';
                if ($lc['ok'] === true) $lcls = 'positive';
                if ($lc['ok'] === false) $lcls = 'negative';
            ?>
            <div class="col-6 col-md-4">
                <div class="stat-card">
                    <div class="stat-value <?= $lcls ?>" title="<?= htmlspecialchars((string)$lc['value']) ?>"><?= htmlspecialchars((string)$lc['value']) ?></div>
                    <div class="stat-label"><?= htmlspecialchars($lc['label']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($demoEffRiskMaxOpen !== null && $dlmMaxConcurrent !== null && $demoEffRiskMaxOpen < $dlmMaxConcurrent): ?>
        <div class="alert alert-danger py-1 px-3 mb-2" style="font-size:.8rem;">
            <strong>Несоответствие лимита:</strong> intent max_open_trades (<?= (int)$demoEffRiskMaxOpen ?>) &lt; max_concurrent_demo_positions (<?= (int)$dlmMaxConcurrent ?>). Сигналы будут блокироваться по rejected_limits.
        </div>
        <?php endif; ?>
        <?php endif; // hasLimitData ?>
        <?php
        // ── Demo Effective Trailing / Break-even Proof ────────────────────
        if ($demoEffTrailingEnabled !== null):
        ?>
        <div class="section-heading" style="font-size:.8rem;margin-top:.75rem;">Трейлинг / Безубыток (эффективные настройки demo)</div>
        <div class="row g-2 mb-2">
            <?php
            $trailingCards = [
                ['label' => 'Трейлинг вкл.',        'value' => $demoEffTrailingEnabled  ? 'true' : 'false', 'ok' => $demoEffTrailingEnabled ? true : false],
                ['label' => 'Режим трейлинга',      'value' => $demoEffTrailingMode ?: '(по умолч.)',       'ok' => null],
                ['label' => 'Активац. ROI %',       'value' => $demoEffTrailingActivation !== null ? number_format((float)$demoEffTrailingActivation, 2) : 'n/a', 'ok' => null],
                ['label' => 'Фактор отката',        'value' => $demoEffTrailingDrawdown !== null ? number_format((float)$demoEffTrailingDrawdown, 4) : 'n/a',     'ok' => null],
                ['label' => 'Безубыток вкл.',       'value' => $demoEffBreakEvenEnabled ? 'true' : 'false',  'ok' => $demoEffBreakEvenEnabled ? true : null],
                ['label' => 'Активац. безуб. %',    'value' => $demoEffBreakEvenActivation !== null ? number_format((float)$demoEffBreakEvenActivation, 2) : 'n/a', 'ok' => null],
            ];
            foreach ($trailingCards as $tc):
                $cls = 'neutral';
                if ($tc['ok'] === true) $cls = 'positive';
                if ($tc['ok'] === false) $cls = 'negative';
            ?>
            <div class="col-6 col-md-4">
                <div class="stat-card">
                    <div class="stat-value <?= $cls ?>"><?= htmlspecialchars((string)$tc['value']) ?></div>
                    <div class="stat-label"><?= htmlspecialchars($tc['label']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; // trailing proof ?>
        <?php
        // ── Exchange Positions / Orders vs Local Active Positions ─────────
        if ($exchangePositionsSynced !== null || $exchangeOrdersSynced !== null):
        ?>
        <div class="section-heading" style="font-size:.8rem;margin-top:.75rem;">Биржа vs Локальные позиции (сверка)</div>
        <div class="row g-2 mb-2">
            <?php
            $localActiveCount = $bot_demo_truth_audit['capacity_slots_used'] ?? null;
            $syncCards = [
                ['label' => 'Позиций на бирже',      'value' => $exchangePositionsSynced !== null ? (string)$exchangePositionsSynced : 'н/д', 'ok' => null],
                ['label' => 'Ордеров на бирже',      'value' => $exchangeOrdersSynced !== null    ? (string)$exchangeOrdersSynced    : 'н/д', 'ok' => null],
                ['label' => 'Локальных позиций',     'value' => $localActiveCount !== null        ? (string)$localActiveCount        : 'н/д', 'ok' => null],
            ];
            foreach ($syncCards as $sc):
                $cls = 'neutral';
                if ($sc['ok'] === true) $cls = 'positive';
                if ($sc['ok'] === false) $cls = 'negative';
            ?>
            <div class="col-6 col-md-4">
                <div class="stat-card">
                    <div class="stat-value <?= $cls ?>"><?= htmlspecialchars((string)$sc['value']) ?></div>
                    <div class="stat-label"><?= htmlspecialchars($sc['label']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($exchangePositionsSynced !== null && $localActiveCount !== null && (int)$exchangePositionsSynced !== (int)$localActiveCount): ?>
        <div class="alert alert-warning py-1 px-3 mb-2" style="font-size:.8rem;">
            <strong>Замечание по синхронизации:</strong> Позиций на бирже (<?= (int)$exchangePositionsSynced ?>) ≠ Локальных активных (<?= (int)$localActiveCount ?>). Ордера биржи и локальные позиции — разные понятия: разница может объясняться orphan-позициями или ожидающими ордерами.
        </div>
        <?php endif; ?>
        <?php endif; // exchange vs local ?>
        <?php
        // ── Demo Feed Pre-filter / Symbol Diversity ───────────────────────
        $hasPrefiltData = ($demoPrefiltInput !== null);
        if ($hasPrefiltData):
        ?>
        <div class="section-heading" style="font-size:.8rem;margin-top:.75rem;">Фильтр сигналов / Диверсификация символов</div>
        <div class="row g-2 mb-2">
            <?php
            $prefiltCards = [
                ['label' => 'Вход в префильтр',    'value' => $demoPrefiltInput !== null ? (string)$demoPrefiltInput : 'n/a',  'ok' => null],
                ['label' => 'Выход из префильтра', 'value' => $demoPrefiltOutput !== null ? (string)$demoPrefiltOutput : 'n/a','ok' => ($demoPrefiltOutput ?? 0) > 0 ? true : null],
                ['label' => 'Уникальных символов',  'value' => $demoUniqueSymbols !== null ? (string)$demoUniqueSymbols : 'n/a','ok' => ($demoUniqueSymbols ?? 0) > 0 ? true : null],
                ['label' => 'Пропущ.: символ занят','value' => $demoPrefiltBusy !== null ? (string)$demoPrefiltBusy : 'n/a',    'ok' => $demoPrefiltBusy === 0 ? true : null],
                ['label' => 'Пропущ.: дубль символа','value' => $demoPrefiltDup !== null ? (string)$demoPrefiltDup : 'n/a',      'ok' => null],
                ['label' => 'Исп.: занятый символ', 'value' => $demoSkipSymBusy !== null ? (string)$demoSkipSymBusy : 'n/a',    'ok' => $demoSkipSymBusy === 0 ? true : null],
                ['label' => 'Исп.: поздний вход',   'value' => $demoSkipLateEntry !== null ? (string)$demoSkipLateEntry : 'n/a','ok' => $demoSkipLateEntry === 0 ? true : null],
            ];
            foreach ($prefiltCards as $pc):
                $cls = 'neutral';
                if ($pc['ok'] === true) $cls = 'positive';
                if ($pc['ok'] === false) $cls = 'negative';
            ?>
            <div class="col-6 col-md-2">
                <div class="stat-card">
                    <div class="stat-value <?= $cls ?>"><?= htmlspecialchars((string)$pc['value']) ?></div>
                    <div class="stat-label"><?= htmlspecialchars($pc['label']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; // hasPrefiltData ?>
        <?php
        // ── Demo Attempt / Open Budget Proof ─────────────────────────────
        $hasBudgetData = ($demoAttemptBudget !== null || $demoSelectedScanned !== null);
        if ($hasBudgetData):
        ?>
        <div class="section-heading" style="font-size:.8rem;margin-top:.75rem;">Бюджет попыток / открытий (этот запуск)</div>
        <?php
        $stopReasonLabels = [
            'selected_feed_exhausted'      => 'Все выбранные сигналы просмотрены (норма)',
            'demo_open_budget_exhausted'   => 'Бюджет открытий исчерпан (max_new_positions_per_run)',
            'demo_attempt_budget_exhausted'=> 'Бюджет попыток исчерпан (max_demo_signals_per_run)',
            'fatal_exchange_blocker'       => 'Фатальная ошибка биржи / защитная остановка',
            'global_break_unexpected'      => 'Неожиданный глобальный выход (deferred limit)',
        ];
        $stopLabel = $stopReasonLabels[$demoLoopStopReason] ?? ($demoLoopStopReason ?: 'n/a');
        $stopOk = ($demoLoopStopReason === 'selected_feed_exhausted' || $demoLoopStopReason === 'demo_open_budget_exhausted');
        $stopBad = in_array($demoLoopStopReason, ['fatal_exchange_blocker','global_break_unexpected']);
        ?>
        <div class="row g-2 mb-2">
            <?php
            $budgetCards = [
                ['label' => 'Бюджет попыток',       'value' => $demoAttemptBudget !== null ? (string)$demoAttemptBudget : 'n/a',
                    'ok' => ($demoAttemptBudget ?? 0) > 1 ? true : null],
                ['label' => 'Бюджет открытий',      'value' => $demoOpenBudget !== null ? (string)$demoOpenBudget : 'n/a',
                    'ok' => ($demoOpenBudget ?? 0) > 0 ? true : null],
                ['label' => 'Макс. новых/запуск',   'value' => $dlmMaxNewPerRun !== null ? (string)$dlmMaxNewPerRun : 'n/a',
                    'ok' => null],
                ['label' => 'Просмотрено сигналов', 'value' => $demoSelectedScanned !== null ? (string)$demoSelectedScanned : 'n/a',
                    'ok' => ($demoSelectedScanned ?? 0) > 0 ? true : null],
                ['label' => 'Пропущено (не фатал)', 'value' => $demoSkippedBefore !== null ? (string)$demoSkippedBefore : 'n/a',
                    'ok' => $demoSkippedBefore === 0 ? true : null],
                ['label' => 'Попыток',              'value' => $demoLoopAttempted !== null ? (string)$demoLoopAttempted : 'n/a',
                    'ok' => null],
                ['label' => 'Открыто за запуск',    'value' => $demoLoopOpened !== null ? (string)$demoLoopOpened : 'n/a',
                    'ok' => ($demoLoopOpened ?? 0) > 0 ? true : ($demoLoopOpened === 0 ? null : null)],
                ['label' => 'Причина остановки',    'value' => $demoLoopStopReason ?: 'n/a',
                    'ok' => $stopOk ? true : ($stopBad ? false : null)],
            ];
            foreach ($budgetCards as $bc):
                $cls = 'neutral';
                if ($bc['ok'] === true) $cls = 'positive';
                if ($bc['ok'] === false) $cls = 'negative';
            ?>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-value <?= $cls ?>" title="<?= htmlspecialchars((string)$bc['value']) ?>"><?= htmlspecialchars((string)$bc['value']) ?></div>
                    <div class="stat-label"><?= htmlspecialchars($bc['label']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($demoLoopStopReason !== '' && $demoLoopStopReason !== 'selected_feed_exhausted'): ?>
        <div class="alert alert-<?= $stopBad ? 'danger' : 'info' ?> py-1 px-3 mb-2" style="font-size:.8rem;">
            <strong>Петля остановлена:</strong> <?= htmlspecialchars($stopLabel) ?>
        </div>
        <?php endif; ?>
        <?php
        // Explanation when selected > attempted
        $selectedCount  = (int)($demoFeedSelected ?? 0);
        $attemptedCount = (int)($demoLoopAttempted ?? 0);
        $skippedCount   = (int)($demoSkippedBefore ?? 0);
        if ($selectedCount > 0 && $skippedCount > 0):
        ?>
        <div class="alert alert-warning py-1 px-3 mb-2" style="font-size:.8rem;">
            <strong>Выбрано (<?= $selectedCount ?>) &gt; Попыток (<?= $attemptedCount ?>):</strong>
            <?= $skippedCount ?> сигнал(ов) пропущено (не фатально) — символ занят, идемпотентность, поздний вход или валидация. Петля продолжала сканирование.
        </div>
        <?php endif; ?>
        <?php endif; // hasBudgetData ?>
        <?php
        // ── Orphan Adoption Quality sub-section ──────────────────────────────
        $hasAdoptionData = ($orphanAdoptionAttempted !== null || $auditOrphanDeadShells !== null);
        if ($hasAdoptionData):
        ?>
        <div class="section-heading" style="font-size:.8rem;margin-top:.75rem;">Качество принятых orphan-позиций (этот запуск)</div>
        <?php if (($auditOrphanDeadShells ?? 0) > 0): ?>
        <div class="alert alert-danger py-1 px-3 mb-2" style="font-size:.8rem;">
            <strong>Обнаружены мёртвые оболочки:</strong> <?= (int)$auditOrphanDeadShells ?> принятых orphan-сделок не имеют entry_price или qty и не могут участвовать в pipeline закрытий/AI.
        </div>
        <?php endif; ?>
        <div class="row g-2 mb-2">
            <?php
            $adoptCards = [
                ['label' => 'Попыток принятия',      'value' => $orphanAdoptionAttempted !== null ? (string)$orphanAdoptionAttempted : 'n/a',
                    'ok' => null],
                ['label' => 'Принято успешно',       'value' => $orphanAdoptionSucceeded !== null ? (string)$orphanAdoptionSucceeded : 'n/a',
                    'ok' => ($orphanAdoptionSucceeded ?? 0) > 0 ? true : null],
                ['label' => 'Ошибок принятия',       'value' => $orphanAdoptionFailed !== null ? (string)$orphanAdoptionFailed : 'n/a',
                    'ok' => $orphanAdoptionFailed === 0 ? true : ($orphanAdoptionFailed > 0 ? false : null)],
                ['label' => 'Повторно используемых', 'value' => $orphanAdoptionReusable !== null ? (string)$orphanAdoptionReusable : 'n/a',
                    'ok' => ($orphanAdoptionReusable ?? 0) > 0 ? true : null],
                ['label' => 'Активных: здоровых',    'value' => $auditHealthyActive !== null ? (string)$auditHealthyActive : 'n/a',
                    'ok' => ($auditHealthyActive ?? 0) > 0 ? true : null],
                ['label' => 'Активных: orphan OK',   'value' => $auditOrphanAdopted !== null ? (string)$auditOrphanAdopted : 'n/a',
                    'ok' => null],
                ['label' => 'Мёртвых оболочек',      'value' => $auditOrphanDeadShells !== null ? (string)$auditOrphanDeadShells : 'n/a',
                    'ok' => $auditOrphanDeadShells === 0 ? true : ($auditOrphanDeadShells > 0 ? false : null)],
                // Ownership resolution
                ['label' => 'Orphan→Локал. владение','value' => $auditOrphanResolved !== null ? (string)$auditOrphanResolved : 'n/a',
                    'ok' => ($auditOrphanResolved ?? 0) > 0 ? true : null],
                ['label' => 'Orphan нерешённых',     'value' => $auditOrphanUnresolved !== null ? (string)$auditOrphanUnresolved : 'n/a',
                    'ok' => $auditOrphanUnresolved === 0 ? true : ($auditOrphanUnresolved > 0 ? false : null)],
                // This-run ownership resolution
                ['label' => 'Запуск: решено локально','value' => $orphanResolvedAsLocal !== null ? (string)$orphanResolvedAsLocal : 'n/a',
                    'ok' => ($orphanResolvedAsLocal ?? 0) > 0 ? true : null],
                ['label' => 'Запуск: блокирует',     'value' => $orphanStillBlocking !== null ? (string)$orphanStillBlocking : 'n/a',
                    'ok' => $orphanStillBlocking === 0 ? true : ($orphanStillBlocking > 0 ? false : null)],
                ['label' => 'Занято orphan-адопт.',  'value' => $symbolsBusyAdopted !== null ? (string)$symbolsBusyAdopted : 'n/a',
                    'ok' => ($symbolsBusyAdopted ?? 0) > 0 ? true : null],
            ];
            foreach ($adoptCards as $ac):
                $cls = 'neutral';
                if ($ac['ok'] === true)  $cls = 'positive';
                if ($ac['ok'] === false) $cls = 'negative';
            ?>
            <div class="col-6 col-md-2">
                <div class="stat-card">
                    <div class="stat-value <?= $cls ?>"><?= htmlspecialchars((string)$ac['value']) ?></div>
                    <div class="stat-label"><?= htmlspecialchars($ac['label']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <?php if ($demoTradesActiveBefore !== null || $demoTradesClosedThisRun !== null): ?>
        <div class="section-heading" style="font-size:.8rem;margin-top:.75rem;">Конвейер закрытий demo (этот запуск)</div>
        <div class="row g-2 mb-2">
            <?php
            $closeCards = [
                ['label' => 'Активных до запуска',  'value' => $demoTradesActiveBefore !== null ? (string)$demoTradesActiveBefore : 'n/a',  'ok' => null],
                ['label' => 'Открыто за запуск',    'value' => $demoTradesOpenedThisRun !== null ? (string)$demoTradesOpenedThisRun : 'n/a', 'ok' => $demoTradesOpenedThisRun > 0 ? true : null],
                ['label' => 'Закрыто за запуск',    'value' => $demoTradesClosedThisRun !== null ? (string)$demoTradesClosedThisRun : 'n/a', 'ok' => $demoTradesClosedThisRun > 0 ? true : null],
                ['label' => 'Активных после',       'value' => $demoTradesStillActive !== null ? (string)$demoTradesStillActive : 'n/a',     'ok' => null],
                ['label' => 'Устаревших сделок',    'value' => $demoTradesStaleThisRun !== null ? (string)$demoTradesStaleThisRun : 'n/a',   'ok' => $demoTradesStaleThisRun === 0 ? true : ($demoTradesStaleThisRun > 0 ? false : null)],
                ['label' => 'Сверено',              'value' => $demoTradesReconciledThisRun !== null ? (string)$demoTradesReconciledThisRun : 'n/a', 'ok' => null],
                ['label' => 'Закрыто биржей',       'value' => $demoFinalizedExchange !== null ? (string)$demoFinalizedExchange : 'n/a',     'ok' => $demoFinalizedExchange > 0 ? true : null],
                ['label' => 'Закрыто локально (SL)','value' => $demoFinalizedLocally !== null ? (string)$demoFinalizedLocally : 'n/a',       'ok' => $demoFinalizedLocally > 0 ? true : null],
                ['label' => 'Ср. возраст (мин)',    'value' => $demoAvgAgeMinutes !== null ? (string)$demoAvgAgeMinutes : 'n/a',             'ok' => null],
                ['label' => 'Старейшее (мин)',      'value' => $demoOldestAgeMinutes !== null ? (string)$demoOldestAgeMinutes : 'n/a',       'ok' => null],
                ['label' => 'AI-записей создано',   'value' => $demoAiWrittenThisRun !== null ? (string)$demoAiWrittenThisRun : 'n/a',       'ok' => $demoAiWrittenThisRun > 0 ? true : null],
                ['label' => 'Ошибок закрытия',      'value' => $demoCloseFailures !== null ? (string)$demoCloseFailures : 'n/a',             'ok' => $demoCloseFailures === 0 ? true : ($demoCloseFailures > 0 ? false : null)],
            ];
            foreach ($closeCards as $cc):
                $cls = 'neutral';
                if ($cc['ok'] === true) $cls = 'positive';
                if ($cc['ok'] === false) $cls = 'negative';
            ?>
            <div class="col-6 col-md-2">
                <div class="stat-card">
                    <div class="stat-value <?= $cls ?>"><?= htmlspecialchars((string)$cc['value']) ?></div>
                    <div class="stat-label"><?= htmlspecialchars($cc['label']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($demoCloseFailureReasons) || !empty($topStaleTradeReasons)): ?>
        <div class="row g-3 mt-1">
            <?php if (!empty($demoCloseFailureReasons)): ?>
            <div class="col-md-4">
                <div class="section-heading" style="font-size:.75rem;">Причины сбоев закрытия (этот запуск)</div>
                <table class="table table-sm exec-table mb-0">
                    <thead><tr><th>Причина</th><th>Кол-во</th></tr></thead>
                    <tbody>
                    <?php foreach ($demoCloseFailureReasons as $cfr => $cfc): ?>
                    <tr><td><?= htmlspecialchars($cfr) ?></td><td><?= (int)$cfc ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <?php if (!empty($topStaleTradeReasons)): ?>
            <div class="col-md-4">
                <div class="section-heading" style="font-size:.75rem;">Причины устаревших сделок (этот запуск)</div>
                <table class="table table-sm exec-table mb-0">
                    <thead><tr><th>Причина</th><th>Кол-во</th></tr></thead>
                    <tbody>
                    <?php foreach ($topStaleTradeReasons as $str => $stc): ?>
                    <tr><td><?= htmlspecialchars($str) ?></td><td><?= (int)$stc ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <?php
        // ── Adopted Orphan Turnover sub-section ─────────────────────────────
        $hasAdoptedOrphanData = ($adoptedOrphansActiveBefore !== null || $auditAdoptedOrphansClosedTotal !== null);
        if ($hasAdoptedOrphanData):
        ?>
        <div class="section-heading" style="font-size:.8rem;margin-top:.75rem;">Оборот принятых orphan-позиций</div>
        <?php if (($auditAdoptedOrphansStale ?? 0) > 0 && ($auditAdoptedOrphansClosedTotal ?? 0) === 0): ?>
        <div class="alert alert-warning py-1 px-3 mb-2" style="font-size:.8rem;">
            <strong>Устаревшие принятые orphan:</strong> <?= (int)$auditAdoptedOrphansStale ?> принятых orphan-сделок устарели, но закрытий ещё не было. Проверьте <code>learning_close_timeout_minutes</code> и цикличность запусков.
        </div>
        <?php elseif (($adoptedOrphansClosedThisRun ?? 0) > 0): ?>
        <div class="alert alert-success py-1 px-3 mb-2" style="font-size:.8rem;">
            <strong>Принятые orphan прогрессируют:</strong> <?= (int)$adoptedOrphansClosedThisRun ?> принятых orphan-сделок закрыто за этот запуск.
        </div>
        <?php endif; ?>
        <div class="row g-2 mb-2">
            <?php
            $aoCards = [
                ['label' => 'Активных до запуска',   'value' => $adoptedOrphansActiveBefore !== null ? (string)$adoptedOrphansActiveBefore : 'n/a',
                    'ok' => null],
                ['label' => 'Устаревших (аудит)',     'value' => $auditAdoptedOrphansStale !== null ? (string)$auditAdoptedOrphansStale : 'n/a',
                    'ok' => $auditAdoptedOrphansStale === 0 ? true : ($auditAdoptedOrphansStale > 0 ? false : null)],
                ['label' => 'Закрыто за запуск',     'value' => $adoptedOrphansClosedThisRun !== null ? (string)$adoptedOrphansClosedThisRun : 'n/a',
                    'ok' => ($adoptedOrphansClosedThisRun ?? 0) > 0 ? true : null],
                ['label' => 'Фин. через биржу',      'value' => $adoptedOrphansFinalizedExchange !== null ? (string)$adoptedOrphansFinalizedExchange : 'n/a',
                    'ok' => ($adoptedOrphansFinalizedExchange ?? 0) > 0 ? true : null],
                ['label' => 'Фин. локально (ТО)',    'value' => $adoptedOrphansFinalizedLocally !== null ? (string)$adoptedOrphansFinalizedLocally : 'n/a',
                    'ok' => ($adoptedOrphansFinalizedLocally ?? 0) > 0 ? true : null],
                ['label' => 'Ошибок закрытия',       'value' => $adoptedOrphansCloseFailures !== null ? (string)$adoptedOrphansCloseFailures : 'n/a',
                    'ok' => $adoptedOrphansCloseFailures === 0 ? true : ($adoptedOrphansCloseFailures > 0 ? false : null)],
                ['label' => 'Закрыто всего (аудит)', 'value' => $auditAdoptedOrphansClosedTotal !== null ? (string)$auditAdoptedOrphansClosedTotal : 'n/a',
                    'ok' => ($auditAdoptedOrphansClosedTotal ?? 0) > 0 ? true : null],
                ['label' => 'Полнота (аудит)',        'value' => $auditAdoptedOrphansClosedCompleteRate !== null ? $auditAdoptedOrphansClosedCompleteRate . '%' : 'n/a',
                    'ok' => ($auditAdoptedOrphansClosedCompleteRate ?? 0) >= 80 ? true : (($auditAdoptedOrphansClosedTotal ?? 0) > 0 && ($auditAdoptedOrphansClosedCompleteRate ?? 0) < 50 ? false : null)],
                ['label' => 'Полная полнота',         'value' => $auditAdoptedOrphansClosedFullCompleteRate !== null ? $auditAdoptedOrphansClosedFullCompleteRate . '%' : 'n/a',
                    'ok' => ($auditAdoptedOrphansClosedFullCompleteRate ?? 0) >= 80 ? true : (($auditAdoptedOrphansClosedTotal ?? 0) > 0 && ($auditAdoptedOrphansClosedFullCompleteRate ?? 0) < 50 ? false : null)],
                ['label' => 'Без AI (аудит)',         'value' => $auditAdoptedOrphansWithoutAi !== null ? (string)$auditAdoptedOrphansWithoutAi : 'n/a',
                    'ok' => $auditAdoptedOrphansWithoutAi === 0 ? true : ($auditAdoptedOrphansWithoutAi > 0 ? false : null)],
                ['label' => 'Устаревших за запуск',  'value' => $adoptedOrphansStaleThisRun !== null ? (string)$adoptedOrphansStaleThisRun : 'n/a',
                    'ok' => null],
                // Close quality counters (this run)
                ['label' => 'Полных (за запуск)',     'value' => $adoptedOrphansClosedCompleteThisRun !== null ? (string)$adoptedOrphansClosedCompleteThisRun : 'n/a',
                    'ok' => ($adoptedOrphansClosedCompleteThisRun ?? 0) > 0 ? true : (($adoptedOrphansClosedThisRun ?? 0) > 0 && ($adoptedOrphansClosedCompleteThisRun ?? 0) === 0 ? false : null)],
                ['label' => 'AI записано (запуск)',   'value' => $adoptedOrphansAiWrittenThisRun !== null ? (string)$adoptedOrphansAiWrittenThisRun : 'n/a',
                    'ok' => ($adoptedOrphansAiWrittenThisRun ?? 0) > 0 ? true : (($adoptedOrphansClosedThisRun ?? 0) > 0 && ($adoptedOrphansAiWrittenThisRun ?? 0) === 0 ? false : null)],
                ['label' => 'Без AI (за запуск)',     'value' => $adoptedOrphansClosedNoAiThisRun !== null ? (string)$adoptedOrphansClosedNoAiThisRun : 'n/a',
                    'ok' => ($adoptedOrphansClosedNoAiThisRun ?? 0) === 0 ? true : (($adoptedOrphansClosedNoAiThisRun ?? 0) > 0 ? false : null)],
                ['label' => 'Восст. попыток',         'value' => $adoptedOrphansRepairAttempted !== null ? (string)$adoptedOrphansRepairAttempted : 'n/a',
                    'ok' => null],
                ['label' => 'Восст. успешно',         'value' => $adoptedOrphansRepairSucceeded !== null ? (string)$adoptedOrphansRepairSucceeded : 'n/a',
                    'ok' => ($adoptedOrphansRepairAttempted ?? 0) > 0 ? (($adoptedOrphansRepairSucceeded ?? 0) === ($adoptedOrphansRepairAttempted ?? 0) ? true : null) : null],
                ['label' => 'Восст. ошибок',          'value' => $adoptedOrphansRepairFailed !== null ? (string)$adoptedOrphansRepairFailed : 'n/a',
                    'ok' => ($adoptedOrphansRepairFailed ?? 0) === 0 ? true : (($adoptedOrphansRepairFailed ?? 0) > 0 ? false : null)],
            ];
            foreach ($aoCards as $aoc):
                $cls = 'neutral';
                if ($aoc['ok'] === true)  $cls = 'positive';
                if ($aoc['ok'] === false) $cls = 'negative';
            ?>
            <div class="col-6 col-md-2">
                <div class="stat-card">
                    <div class="stat-value <?= $cls ?>"><?= htmlspecialchars((string)$aoc['value']) ?></div>
                    <div class="stat-label"><?= htmlspecialchars($aoc['label']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
        // Missing-field detail counts (only shown when there are closed adopted orphan trades)
        $hasOrphanMissingData = ($auditAdoptedOrphansClosedTotal ?? 0) > 0
            && (($auditOrphanMissingClosePrice ?? 0) + ($auditOrphanMissingRoi ?? 0)
              + ($auditOrphanMissingMfe ?? 0) + ($auditOrphanMissingMae ?? 0)
              + ($auditOrphanMissingHoldMin ?? 0)) > 0;
        // Show mfe/mae warning whenever they inflate the operational complete rate
        $orphanMfeMaeInflation = ($auditAdoptedOrphansClosedTotal ?? 0) > 0
            && ($auditAdoptedOrphansClosedFullCompleteRate !== null)
            && ($auditAdoptedOrphansClosedCompleteRate !== null)
            && ((float)$auditAdoptedOrphansClosedFullCompleteRate < (float)$auditAdoptedOrphansClosedCompleteRate);
        if ($hasOrphanMissingData || $orphanMfeMaeInflation):
        ?>
        <div class="mt-1">
            <div class="section-heading" style="font-size:.75rem;">Принятые orphan — отсутствующие поля (аудит хранилища)</div>
            <?php if ($orphanMfeMaeInflation): ?>
            <div class="alert alert-warning py-1 px-3 mb-2" style="font-size:.8rem;">
                <strong>Предупреждение о полноте:</strong> Операционная полнота (<?= htmlspecialchars((string)$auditAdoptedOrphansClosedCompleteRate) ?>%) считает записи полными, хотя mfe/mae отсутствуют. Полная полнота (с mfe+mae): <?= htmlspecialchars((string)$auditAdoptedOrphansClosedFullCompleteRate) ?>%. Нет mfe: <?= (int)($auditOrphanMissingMfe ?? 0) ?>, нет mae: <?= (int)($auditOrphanMissingMae ?? 0) ?>.
            </div>
            <?php endif; ?>
            <div class="row g-2 mb-1">
            <?php
            $mfCards = [
                ['label' => 'Missing close_price', 'value' => (string)($auditOrphanMissingClosePrice ?? 0), 'ok' => ($auditOrphanMissingClosePrice ?? 0) === 0 ? true : false],
                ['label' => 'Missing roi',         'value' => (string)($auditOrphanMissingRoi ?? 0),        'ok' => ($auditOrphanMissingRoi ?? 0) === 0 ? true : false],
                ['label' => 'Missing mfe',         'value' => (string)($auditOrphanMissingMfe ?? 0),        'ok' => ($auditOrphanMissingMfe ?? 0) === 0 ? true : false],
                ['label' => 'Missing mae',         'value' => (string)($auditOrphanMissingMae ?? 0),        'ok' => ($auditOrphanMissingMae ?? 0) === 0 ? true : false],
                ['label' => 'Missing hold_min',    'value' => (string)($auditOrphanMissingHoldMin ?? 0),    'ok' => ($auditOrphanMissingHoldMin ?? 0) === 0 ? true : null],
            ];
            foreach ($mfCards as $mfc):
                $mfCls = 'neutral';
                if ($mfc['ok'] === true)  $mfCls = 'positive';
                if ($mfc['ok'] === false) $mfCls = 'negative';
            ?>
            <div class="col-6 col-md-2">
                <div class="stat-card">
                    <div class="stat-value <?= $mfCls ?>"><?= htmlspecialchars($mfc['value']) ?></div>
                    <div class="stat-label"><?= htmlspecialchars($mfc['label']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php
        // ── Adopted Orphan Timing Health sub-section ─────────────────────────
        $hasOrphanTimingData = ($auditOrphanAdopted ?? 0) > 0
            || ($adoptedOrphansValidTiming ?? 0) + ($adoptedOrphansMissingTiming ?? 0) > 0;
        if ($hasOrphanTimingData):
        ?>
        <div class="mt-1">
            <div class="section-heading" style="font-size:.75rem;">Состояние таймингов принятых orphan</div>
            <?php if (($adoptedOrphansMissingTiming ?? 0) > 0 || ($auditOrphanMissingTimingCount ?? 0) > 0): ?>
            <div class="alert alert-warning py-1 px-3 mb-2" style="font-size:.8rem;">
                <strong>Предупреждение о таймингах:</strong> <?= (int)(max($adoptedOrphansMissingTiming ?? 0, $auditOrphanMissingTimingCount ?? 0)) ?> принятых orphan-сделок не имеют базы для таймингов. Логика возраста/устаревания/таймаута может не срабатывать. Проверьте доступность <code>createdTime</code> на бирже.
            </div>
            <?php endif; ?>
            <div class="row g-2 mb-1">
            <?php
            $timingCards = [
                ['label' => 'Тайминг есть (запуск)', 'value' => $adoptedOrphansValidTiming !== null ? (string)$adoptedOrphansValidTiming : 'n/a',
                    'ok' => ($adoptedOrphansValidTiming ?? 0) > 0 ? true : (($adoptedOrphansMissingTiming ?? 0) > 0 ? false : null)],
                ['label' => 'Нет таймингов (запуск)','value' => $adoptedOrphansMissingTiming !== null ? (string)$adoptedOrphansMissingTiming : 'n/a',
                    'ok' => ($adoptedOrphansMissingTiming ?? 0) === 0 ? true : (($adoptedOrphansMissingTiming ?? 0) > 0 ? false : null)],
                ['label' => 'Устарев. подходящих',   'value' => $adoptedOrphansStaleEligible !== null ? (string)$adoptedOrphansStaleEligible : 'n/a',
                    'ok' => null],
                ['label' => 'Таймаут подходящих',    'value' => $adoptedOrphansTimeoutEligible !== null ? (string)$adoptedOrphansTimeoutEligible : 'n/a',
                    'ok' => null],
                ['label' => 'Ср. возраст (запуск)',  'value' => $adoptedOrphansAvgAge !== null ? (string)$adoptedOrphansAvgAge . 'м' : 'n/a',
                    'ok' => null],
                ['label' => 'Старейшее (запуск)',    'value' => $adoptedOrphansOldestAge !== null ? (string)$adoptedOrphansOldestAge . 'м' : 'n/a',
                    'ok' => null],
                ['label' => 'Тайминг есть (аудит)',  'value' => $auditOrphanValidTiming !== null ? (string)$auditOrphanValidTiming : 'n/a',
                    'ok' => ($auditOrphanValidTiming ?? 0) > 0 ? true : null],
                ['label' => 'Нет таймингов (аудит)', 'value' => $auditOrphanMissingTimingCount !== null ? (string)$auditOrphanMissingTimingCount : 'n/a',
                    'ok' => ($auditOrphanMissingTimingCount ?? 0) === 0 ? true : (($auditOrphanMissingTimingCount ?? 0) > 0 ? false : null)],
                ['label' => 'Ср. возраст (аудит)',   'value' => $auditOrphanAvgAge !== null ? (string)$auditOrphanAvgAge . 'м' : 'n/a',
                    'ok' => null],
                ['label' => 'Старейшее (аудит)',     'value' => $auditOrphanOldestAge !== null ? (string)$auditOrphanOldestAge . 'м' : 'n/a',
                    'ok' => null],
            ];
            foreach ($timingCards as $tc):
                $tcCls = 'neutral';
                if ($tc['ok'] === true)  $tcCls = 'positive';
                if ($tc['ok'] === false) $tcCls = 'negative';
            ?>
            <div class="col-6 col-md-2">
                <div class="stat-card">
                    <div class="stat-value <?= $tcCls ?>"><?= htmlspecialchars((string)$tc['value']) ?></div>
                    <div class="stat-label"><?= htmlspecialchars($tc['label']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if (!empty($adoptedOrphanCloseFailureReasons)): ?>
        <div class="mt-1">
            <div class="section-heading" style="font-size:.75rem;">Причины сбоев закрытия orphan (этот запуск)</div>
            <table class="table table-sm exec-table mb-0" style="max-width:420px;">
                <thead><tr><th>Причина</th><th>Кол-во</th></tr></thead>
                <tbody>
                <?php foreach ($adoptedOrphanCloseFailureReasons as $aofr => $aofc): ?>
                <tr><td><?= htmlspecialchars($aofr) ?></td><td><?= (int)$aofc ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <?php if (!empty($demoRejStats)): ?>
        <div class="mt-2">
            <div class="section-heading" style="font-size:.75rem;">Топ причин сбоев (этот запуск)</div>
            <table class="table table-sm exec-table mb-0" style="max-width:420px;">
                <thead><tr><th>Причина</th><th>Кол-во</th></tr></thead>
                <tbody>
                <?php
                arsort($demoRejStats);
                foreach (array_slice($demoRejStats, 0, 6, true) as $rrk => $rrc):
                ?>
                <tr><td><?= htmlspecialchars($rrk) ?></td><td><?= (int)$rrc ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <?php if ($demoSrcPath !== ''): ?>
        <div class="mt-2 small text-muted">
            Файл источника: <code><?= htmlspecialchars($demoSrcPath) ?></code>
        </div>
        <?php endif; ?>

        <?php
        // ── Turnover Diagnostics sub-section (PART 7) ───────────────────────
        $hasTurnoverData = $demoFeedAvailable !== null || $demoCapAvail !== null
            || $demoStalePrioritized !== null || $demoClosedThisRun !== null
            || $demoClosureBottleneck !== '';
        ?>
        <?php if ($hasTurnoverData): ?>
        <div class="section-heading" style="font-size:.8rem;margin-top:.85rem;">Диагностика оборота demo (этот запуск)</div>
        <div class="row g-2 mb-2">
            <?php
            $twCards = [
                ['label' => 'Доступно в фиде',      'value' => $demoFeedAvailable !== null ? (string)$demoFeedAvailable : 'n/a', 'ok' => null],
                ['label' => 'Выбрано из фида',      'value' => $demoFeedSelected !== null ? (string)$demoFeedSelected : 'n/a',   'ok' => ($demoFeedSelected ?? 0) > 0 ? true : null],
                ['label' => 'Отложено по ёмкости',  'value' => $demoFeedCapSkip !== null ? (string)$demoFeedCapSkip : 'n/a',     'ok' => ($demoFeedCapSkip ?? 0) === 0 ? true : null],
                ['label' => 'Пропущ. идемпотент.',  'value' => $demoFeedIdempSkip !== null ? (string)$demoFeedIdempSkip : 'n/a', 'ok' => null],
                ['label' => 'Режим ротации',        'value' => $demoFeedRotMode !== '' ? htmlspecialchars($demoFeedRotMode) : 'n/a', 'ok' => null],
                ['label' => 'Отложено ротацией',    'value' => $demoFeedDeferred !== null ? (string)$demoFeedDeferred : 'n/a',   'ok' => null],
                ['label' => 'Ёмкость (своб.)',      'value' => $demoCapAvail !== null ? ($demoCapAvail === -1 ? 'не ограничено' : (string)$demoCapAvail) : 'n/a', 'ok' => null],
                ['label' => 'Ёмкость (занято)',     'value' => $demoCapUsed !== null ? (string)$demoCapUsed : 'n/a',             'ok' => null],
                ['label' => 'Блок по ёмкости',      'value' => $demoCapBlocked !== null ? (string)$demoCapBlocked : 'n/a',       'ok' => ($demoCapBlocked ?? 0) === 0 ? true : null],
                ['label' => 'Приоритетно устар.',   'value' => $demoStalePrioritized !== null ? (string)$demoStalePrioritized : 'n/a', 'ok' => null],
                ['label' => 'Завершено устар.',     'value' => $demoStaleFinalized !== null ? (string)$demoStaleFinalized : 'n/a',     'ok' => ($demoStaleFinalized ?? 0) > 0 ? true : null],
                ['label' => 'Осталось устар.',      'value' => $demoStaleRemaining !== null ? (string)$demoStaleRemaining : 'n/a',     'ok' => ($demoStaleRemaining ?? 0) === 0 ? true : ($demoStaleRemaining > 3 ? false : null)],
                ['label' => 'Закрыто за запуск',    'value' => $demoClosedThisRun !== null ? (string)$demoClosedThisRun : 'n/a', 'ok' => ($demoClosedThisRun ?? 0) > 0 ? true : null],
                ['label' => 'AI записей за запуск', 'value' => $demoAiWrittenThisRun !== null ? (string)$demoAiWrittenThisRun : 'n/a', 'ok' => ($demoAiWrittenThisRun ?? 0) > 0 ? true : null],
                ['label' => 'Без AI (запуск)',      'value' => $demoClosedNoAiRun !== null ? (string)$demoClosedNoAiRun : 'n/a', 'ok' => ($demoClosedNoAiRun ?? 0) === 0 ? true : ($demoClosedNoAiRun > 0 ? false : null)],
                ['label' => 'AI совпадение (запуск)','value' => $demoAiMatchRateRun !== null ? $demoAiMatchRateRun . '%' : 'n/a', 'ok' => ($demoAiMatchRateRun ?? 0) >= 100 ? true : (($demoAiMatchRateRun ?? 0) < 80 ? false : null)],
            ];
            foreach ($twCards as $twc):
                $cls = 'neutral';
                if ($twc['ok'] === true)  $cls = 'positive';
                if ($twc['ok'] === false) $cls = 'negative';
            ?>
            <div class="col-6 col-md-2">
                <div class="stat-card">
                    <div class="stat-value <?= $cls ?>"><?= $twc['value'] ?></div>
                    <div class="stat-label"><?= htmlspecialchars($twc['label']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($demoClosureBottleneck !== '' && $demoClosureBottleneck !== 'none_loop_is_cycling'): ?>
        <div class="alert alert-warning py-1 px-3 mt-2 mb-0" style="font-size:.8rem;">
            <strong>Узкое место закрытий:</strong> <code><?= htmlspecialchars($demoClosureBottleneck) ?></code>
            <?php if ($demoClosureReason !== ''): ?>
            — <?= htmlspecialchars($demoClosureReason) ?>
            <?php endif; ?>
            <?php if ($demoTurnoverFix !== ''): ?>
            <br><strong>Область исправления:</strong> <code><?= htmlspecialchars($demoTurnoverFix) ?></code>
            <?php endif; ?>
        </div>
        <?php elseif ($demoClosureBottleneck === 'none_loop_is_cycling'): ?>
        <div class="alert alert-success py-1 px-3 mt-2 mb-0" style="font-size:.8rem;">
            <strong>Петля работает нормально.</strong> <?= htmlspecialchars($demoClosureReason) ?>
        </div>
        <?php endif; ?>
        <?php if ($demoOrphanDetected !== null || $demoOrphanBlocking !== null): ?>
        <div class="section-heading" style="font-size:.8rem;margin-top:.85rem;">Диагностика orphan-позиций биржи</div>
        <div class="row g-2 mb-2">
            <?php
            $orphanCards = [
                ['label' => 'Orphan обнаружено',     'value' => $demoOrphanDetected !== null ? (string)$demoOrphanDetected : 'n/a',  'ok' => ($demoOrphanDetected ?? 0) === 0 ? true : false],
                ['label' => 'Orphan блокирует',      'value' => $demoOrphanBlocking !== null ? (string)$demoOrphanBlocking : 'n/a',  'ok' => ($demoOrphanBlocking ?? 0) === 0 ? true : false],
                ['label' => 'Осн. блокировщик',      'value' => $demoPrimaryExecBlocker !== '' ? htmlspecialchars($demoPrimaryExecBlocker) : 'нет', 'ok' => ($demoPrimaryExecBlocker === '' || $demoPrimaryExecBlocker === 'none') ? true : false],
            ];
            foreach ($orphanCards as $oc):
                $cls = 'neutral';
                if ($oc['ok'] === true)  $cls = 'positive';
                if ($oc['ok'] === false) $cls = 'negative';
            ?>
            <div class="col-6 col-md-2">
                <div class="stat-card">
                    <div class="stat-value <?= $cls ?>"><?= $oc['value'] ?></div>
                    <div class="stat-label"><?= htmlspecialchars($oc['label']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (($demoOrphanBlocking ?? 0) > 0): ?>
        <div class="alert alert-danger py-1 px-3 mt-1 mb-0" style="font-size:.8rem;">
            <strong>Orphan блокирует:</strong> <?= (int)$demoOrphanBlocking ?> позиции(й) на бирже блокируют новые demo-сделки.
            Позиции существуют на бирже, но не имеют соответствующей локальной записи.
            Выполните сверку или завершите эти orphan-позиции для разблокировки demo-обучения.
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <?php endif; ?>

        <?php
        // ── Capacity / Turnover sub-section ─────────────────────────────────
        $hasCapTurnoverData = $demoCapacityFullRun !== null || $demoTurnoverModeRun !== null
            || $auditCapFull !== null || $demoCapSlotsTotalRun !== null;
        if ($hasCapTurnoverData):
        ?>
        <div class="section-heading" style="font-size:.8rem;margin-top:.85rem;">Ёмкость / Оборот</div>
        <div class="row g-2 mb-2">
            <?php
            $capSlotsSat = ($demoCapacityFullRun === true) ? false : ($demoCapacityFullRun === false ? true : null);
            $capCards = [
                ['label' => 'Ёмкость заполнена',     'value' => $demoCapacityFullRun !== null ? ($demoCapacityFullRun ? 'ДА' : 'НЕТ') : (($auditCapFull !== null) ? ($auditCapFull ? 'ДА' : 'НЕТ') : 'n/a'),
                 'ok' => $demoCapacityFullRun !== null ? !$demoCapacityFullRun : ($auditCapFull !== null ? !$auditCapFull : null)],
                ['label' => 'Слотов всего',           'value' => ($demoCapSlotsTotalRun ?? $auditCapSlotsTotal) !== null ? (string)($demoCapSlotsTotalRun ?? $auditCapSlotsTotal) : 'n/a', 'ok' => null],
                ['label' => 'Слотов занято (до)',     'value' => ($demoCapSlotsBeforeRun ?? $auditCapSlotsUsed) !== null ? (string)($demoCapSlotsBeforeRun ?? $auditCapSlotsUsed) : 'n/a', 'ok' => null],
                ['label' => 'Освобождено за запуск', 'value' => ($demoCapSlotsFreedRun ?? $auditCapSlotsFreed) !== null ? (string)($demoCapSlotsFreedRun ?? $auditCapSlotsFreed) : 'n/a',
                 'ok' => ($demoCapSlotsFreedRun ?? 0) > 0 ? true : (($demoCapacityFullRun === true && ($demoCapSlotsFreedRun ?? 0) === 0) ? false : null)],
                ['label' => 'Режим оборота',         'value' => $demoTurnoverModeRun !== null ? ($demoTurnoverModeRun ? 'ДА' : 'НЕТ') : 'n/a',
                 'ok' => $demoTurnoverModeRun !== null ? $demoTurnoverModeRun : null],
                ['label' => 'Кандидатов оборота',    'value' => ($demoTurnoverCandRun ?? $auditTurnoverCandCount) !== null ? (string)($demoTurnoverCandRun ?? $auditTurnoverCandCount) : 'n/a', 'ok' => null],
                ['label' => 'Обработано оборотом',   'value' => $demoTurnoverProcRun !== null ? (string)$demoTurnoverProcRun : 'n/a', 'ok' => null],
                ['label' => 'Слотов освобожд. pass', 'value' => $demoCapSlotsFreedRun !== null ? (string)$demoCapSlotsFreedRun : 'n/a',
                 'ok' => ($demoCapSlotsFreedRun ?? 0) > 0 ? true : (($demoTurnoverModeRun && ($demoCapSlotsFreedRun ?? 0) === 0) ? false : null)],
                ['label' => 'Восст. активных',       'value' => $auditRecoverableCount !== null ? (string)$auditRecoverableCount : 'n/a',
                 'ok' => ($auditRecoverableCount ?? 0) > 0 ? null : true],
                ['label' => 'AI записей оборота',    'value' => $demoTurnoverAiRun !== null ? (string)$demoTurnoverAiRun : 'n/a',
                 'ok' => ($demoTurnoverAiRun ?? 0) > 0 ? true : null],
                ['label' => 'Ёмкость освобождена',   'value' => $demoTurnoverFreedRun !== null ? ($demoTurnoverFreedRun ? 'ДА' : 'НЕТ') : 'n/a',
                 'ok' => $demoTurnoverFreedRun !== null ? (bool)$demoTurnoverFreedRun : null],
                ['label' => 'Слотов занято (после)', 'value' => $demoCapSlotsAfterRun !== null ? (string)$demoCapSlotsAfterRun : 'n/a', 'ok' => null],
            ];
            foreach ($capCards as $cc):
                $cls = 'neutral';
                if ($cc['ok'] === true)  $cls = 'positive';
                if ($cc['ok'] === false) $cls = 'negative';
            ?>
            <div class="col-6 col-md-2">
                <div class="stat-card">
                    <div class="stat-value <?= $cls ?>"><?= htmlspecialchars((string)$cc['value']) ?></div>
                    <div class="stat-label"><?= htmlspecialchars($cc['label']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($demoTurnoverBlockRun !== '' && $demoTurnoverBlockRun !== 'none'): ?>
        <div class="alert alert-warning py-1 px-3 mt-1 mb-1" style="font-size:.8rem;">
            <strong>Причина блокировки оборота:</strong> <code><?= htmlspecialchars($demoTurnoverBlockRun) ?></code>
        </div>
        <?php endif; ?>
        <?php if ($auditConsistencyOk === false && $auditConsistencyWarning !== ''): ?>
        <div class="alert alert-danger py-1 px-3 mt-1 mb-1" style="font-size:.8rem;">
            <strong>⚠ Предупреждение о согласованности ёмкости:</strong> <?= htmlspecialchars($auditConsistencyWarning) ?>
        </div>
        <?php endif; ?>
        <?php if ($demoTurnoverModeRun && ($demoTurnoverCandStale !== null || $demoTurnoverCandTimeout !== null || $demoTurnoverCandDead !== null)): ?>
        <div class="mt-1 small text-muted">
            <strong>Разбивка кандидатов:</strong>
            <?php if (($demoTurnoverCandDead ?? 0) > 0): ?><span class="badge bg-danger me-1">Dead Shells: <?= (int)$demoTurnoverCandDead ?></span><?php endif; ?>
            <?php if (($demoTurnoverCandTimeout ?? 0) > 0): ?><span class="badge bg-warning text-dark me-1">Timeout: <?= (int)$demoTurnoverCandTimeout ?></span><?php endif; ?>
            <?php if (($demoTurnoverCandStale ?? 0) > 0): ?><span class="badge bg-secondary me-1">Stale: <?= (int)$demoTurnoverCandStale ?></span><?php endif; ?>
            <?php if (($demoTurnoverCandFinElig ?? 0) > 0): ?><span class="badge bg-info text-dark me-1">Finalize-Eligible: <?= (int)$demoTurnoverCandFinElig ?></span><?php endif; ?>
            <?php if (($demoTurnoverCandOther ?? 0) > 0): ?><span class="badge bg-secondary me-1">Other: <?= (int)$demoTurnoverCandOther ?></span><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($demoTurnoverPriStats)): ?>
        <div class="mt-1 small text-muted">
            <strong>Причины приоритета:</strong>
            <?php foreach ($demoTurnoverPriStats as $priReason => $priCount): ?>
            <span class="badge bg-secondary me-1"><?= htmlspecialchars($priReason) ?>: <?= (int)$priCount ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; // $hasCapTurnoverData ?>

        <?php
        // ── Healthy Active Turnover sub-section ───────────────────────────────
        $hasHealthyTurnoverData = $healthyActiveBefore !== null || $auditHealthyActive !== null
            || $healthyActiveClosedRun !== null || $targetPerRun !== null || $auditTargetPerRun !== null;
        if ($hasHealthyTurnoverData):
        ?>
        <div class="section-heading" style="font-size:.8rem;margin-top:.85rem;">Оборот активных позиций</div>
        <div class="row g-2 mb-2">
            <?php
            $effHealthyActive  = $healthyActiveBefore ?? $auditHealthyActive;
            $effHealthyStale   = $healthyActiveStaleRun ?? $auditHealthyStale;
            $effHealthyTimeout = $healthyActiveTimeoutRun ?? $auditHealthyTimeout;
            $effHealthyCand    = $healthyActiveCandRun ?? $auditHealthyCand;
            $effHealthyProc    = $healthyActiveProcRun;
            $effClosedTotal    = $closedTotalRun ?? $auditClosedTotal;
            $effClosedHealthy  = $closedHealthyRun ?? $auditClosedHealthy;
            $effClosedOrphan   = $closedOrphanRun ?? $auditClosedOrphan;
            $effAiWritten      = $aiDatasetWrittenRun ?? $auditAiTotal;
            $effTargetPerRun   = $targetPerRun ?? $auditTargetPerRun;
            $effTargetMet      = $targetMet ?? $auditTargetMet;
            $effTargetGap      = $targetGap ?? $auditTargetGap;
            $htCards = [
                ['label' => 'Активных здоровых',     'value' => $effHealthyActive !== null ? (string)$effHealthyActive : 'n/a', 'ok' => null],
                ['label' => 'Устаревших здоровых',   'value' => $effHealthyStale !== null ? (string)$effHealthyStale : 'n/a',
                 'ok' => ($effHealthyStale ?? 0) > 0 ? false : (($effHealthyActive ?? 0) > 0 ? true : null)],
                ['label' => 'Подходит таймаут',      'value' => $effHealthyTimeout !== null ? (string)$effHealthyTimeout : 'n/a',
                 'ok' => ($effHealthyTimeout ?? 0) > 0 ? null : true],
                ['label' => 'Кандидатов оборота',    'value' => $effHealthyCand !== null ? (string)$effHealthyCand : 'n/a',
                 'ok' => ($effHealthyCand ?? 0) > 0 ? null : (($effHealthyActive ?? 0) > 0 ? true : null)],
                ['label' => 'Обработано (оборот)',   'value' => $effHealthyProc !== null ? (string)$effHealthyProc : 'n/a',
                 'ok' => ($effHealthyProc ?? 0) > 0 ? true : null],
                ['label' => 'Закрыто (всего)',       'value' => $effClosedTotal !== null ? (string)$effClosedTotal : 'n/a',
                 'ok' => ($effClosedTotal ?? 0) > 0 ? true : null],
                ['label' => 'Закрыто (здоровых)',    'value' => $effClosedHealthy !== null ? (string)$effClosedHealthy : 'n/a',
                 'ok' => ($effClosedHealthy ?? 0) > 0 ? true : null],
                ['label' => 'Закрыто (orphan)',      'value' => $effClosedOrphan !== null ? (string)$effClosedOrphan : 'n/a', 'ok' => null],
                ['label' => 'AI-записей создано',    'value' => $effAiWritten !== null ? (string)$effAiWritten : 'n/a',
                 'ok' => ($effAiWritten ?? 0) > 0 ? true : null],
                ['label' => 'Сбоев закрытия',        'value' => $healthyActiveFailRun !== null ? (string)$healthyActiveFailRun : 'n/a',
                 'ok' => ($healthyActiveFailRun ?? 0) > 0 ? false : ($healthyActiveClosedRun !== null ? true : null)],
                ['label' => 'Цель / запуск',         'value' => $effTargetPerRun !== null ? (string)$effTargetPerRun : 'n/a', 'ok' => null],
                ['label' => 'Цель достигнута',       'value' => $effTargetMet !== null ? ($effTargetMet ? 'ДА' : 'НЕТ') : 'n/a',
                 'ok' => $effTargetMet !== null ? (bool)$effTargetMet : null],
                ['label' => 'Разрыв до цели',        'value' => $effTargetGap !== null ? (string)$effTargetGap : 'n/a',
                 'ok' => ($effTargetGap ?? 0) === 0 ? true : (($effTargetGap ?? 0) > 0 ? false : null)],
                ['label' => 'Оборот сработал',       'value' => $healthyTurnoverTriggered !== null ? ($healthyTurnoverTriggered ? 'ДА' : 'НЕТ') : 'n/a',
                 'ok' => $healthyTurnoverTriggered !== null ? (bool)$healthyTurnoverTriggered : null],
                ['label' => 'Причина блокировки',    'value' => ($healthyTurnoverBlockReason !== '' && $healthyTurnoverBlockReason !== 'none') ? $healthyTurnoverBlockReason : 'нет',
                 'ok' => ($healthyTurnoverBlockReason === '' || $healthyTurnoverBlockReason === 'none') ? true : null],
            ];
            foreach ($htCards as $hc):
                $cls = 'neutral';
                if ($hc['ok'] === true)  $cls = 'positive';
                if ($hc['ok'] === false) $cls = 'negative';
            ?>
            <div class="col-6 col-md-2">
                <div class="stat-card">
                    <div class="stat-value <?= $cls ?>"><?= htmlspecialchars((string)$hc['value']) ?></div>
                    <div class="stat-label"><?= htmlspecialchars($hc['label']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($healthyActiveFailRsns)): ?>
        <div class="mt-1 small text-muted">
            <strong>Сбои закрытия здоровых:</strong>
            <?php foreach ($healthyActiveFailRsns as $fr => $fc): ?>
            <span class="badge bg-warning text-dark me-1"><?= htmlspecialchars($fr) ?>: <?= (int)$fc ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; // $hasHealthyTurnoverData ?>

        <?php
        // ── Healthy Close Quality sub-section ─────────────────────────────
        $hasHealthyQualityData = $healthyClosedTotal !== null || $auditHealthyClosedTotal !== null;
        if ($hasHealthyQualityData):
            $hcTotal   = $healthyClosedTotal ?? 0;
            $hcFC      = $healthyClosedFullCompl ?? 0;
            $hcFCRate  = $hcTotal > 0 ? round($hcFC / $hcTotal * 100, 1) : 0.0;
            $hcMissMfe = $healthyClosedMissMfe ?? 0;
            $hcMissMae = $healthyClosedMissMae ?? 0;
            $hcMissCp  = $healthyClosedMissCp  ?? 0;
            $hcMissHold= $healthyClosedMissHold ?? 0;
            $hcAi      = $healthyAiWrittenRun   ?? 0;
            $allTimeFC     = $auditHealthyClosedFCRate !== null ? $auditHealthyClosedFCRate . '%' : 'n/a';
        ?>
        <div class="section-heading" style="font-size:.8rem;margin-top:.85rem;">Качество закрытых здоровых (этот запуск)</div>
        <div class="row g-2 mb-2">
            <?php
            $hqCards = [
                ['label' => 'Закрыто здор. (запуск)',  'value' => (string)$hcTotal,
                 'ok' => $hcTotal > 0 ? true : null],
                ['label' => 'Полных записей',           'value' => "{$hcFC} / {$hcTotal} ({$hcFCRate}%)",
                 'ok' => $hcFCRate >= 80 ? true : ($hcFCRate < 30 ? false : null)],
                ['label' => 'Без MFE',                  'value' => (string)$hcMissMfe,
                 'ok' => $hcMissMfe === 0 ? true : ($hcMissMfe > ($hcTotal / 2) ? false : null)],
                ['label' => 'Без MAE',                  'value' => (string)$hcMissMae,
                 'ok' => $hcMissMae === 0 ? true : ($hcMissMae > ($hcTotal / 2) ? false : null)],
                ['label' => 'Без close_price',          'value' => (string)$hcMissCp,
                 'ok' => $hcMissCp === 0 ? true : ($hcMissCp > 0 ? false : null)],
                ['label' => 'Без hold_minutes',         'value' => (string)$hcMissHold,
                 'ok' => $hcMissHold === 0 ? true : ($hcMissHold > 0 ? false : null)],
                ['label' => 'AI dataset записано',      'value' => (string)$hcAi,
                 'ok' => $hcAi === $hcTotal && $hcTotal > 0 ? true : ($hcAi < $hcTotal && $hcTotal > 0 ? false : null)],
                ['label' => 'Полных (всё время)',        'value' => $allTimeFC,
                 'ok' => ($auditHealthyClosedFCRate ?? 0) >= 80 ? true : (($auditHealthyClosedFCRate ?? 0) < 30 ? false : null)],
            ];
            foreach ($hqCards as $card): ?>
            <div class="col-6 col-md-3">
                <div class="stat-card <?= isset($card['ok']) ? ($card['ok'] ? 'ok' : 'warn') : '' ?>">
                    <div class="stat-label"><?= htmlspecialchars($card['label']) ?></div>
                    <div class="stat-value"><?= htmlspecialchars((string)$card['value']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
        if (($auditHealthyMissMfe ?? 0) > 0 || ($auditHealthyMissMae ?? 0) > 0
            || ($auditHealthyMissCp ?? 0) > 0 || ($auditHealthyMissHold ?? 0) > 0): ?>
        <div class="alert alert-warning py-1 px-2 small mb-2">
            Всего здор. закрытых: <b><?= (int)($auditHealthyClosedTotal ?? 0) ?></b> |
            Полных: <b><?= htmlspecialchars($allTimeFC) ?></b> |
            Без MFE: <b><?= (int)($auditHealthyMissMfe ?? 0) ?></b> |
            Без MAE: <b><?= (int)($auditHealthyMissMae ?? 0) ?></b> |
            Без close_price: <b><?= (int)($auditHealthyMissCp ?? 0) ?></b> |
            Без hold_min: <b><?= (int)($auditHealthyMissHold ?? 0) ?>
            </b>
        </div>
        <?php endif; ?>
        <?php endif; // $hasHealthyQualityData ?>

        <?php
        // ── Demo Composition sub-section (PART 8) ─────────────────────────
        $hasCompData = $compActiveHealthy !== null || $compActiveOrphan !== null
            || $compClosedHealthyTotal !== null || $compOrphanSlotCap !== null;
        if ($hasCompData):
        ?>
        <div class="section-heading" style="font-size:.8rem;margin-top:.85rem;">Состав demo-петли (здоровые vs. orphan)</div>
        <div class="row g-2 mb-2">
            <?php
            $compActiveTotal = ($compActiveHealthy ?? 0) + ($compActiveOrphan ?? 0);
            $compClosedTotal = ($compClosedHealthyTotal ?? 0) + ($compClosedOrphanTotal ?? 0);
            $compShareActive = $compHealthyShareActive ?? ($compActiveTotal > 0 ? round(($compActiveHealthy ?? 0) / $compActiveTotal * 100, 1) : 0.0);
            $compShareClosed = $compHealthyShareClosed ?? ($compClosedTotal > 0 ? round(($compClosedHealthyTotal ?? 0) / $compClosedTotal * 100, 1) : 0.0);
            $compShareTgt    = $compShareTarget !== null ? (float)$compShareTarget : 40.0;
            $compCards = [
                ['label' => 'Активных здоровых',     'value' => $compActiveHealthy !== null ? (string)$compActiveHealthy : 'n/a',
                 'ok' => ($compActiveHealthy ?? 0) > 0 ? true : null],
                ['label' => 'Активных orphan/adopted','value' => $compActiveOrphan !== null ? (string)$compActiveOrphan : 'n/a',
                 'ok' => ($compOrphanCapReached === true) ? false : null],
                ['label' => 'Здоровая доля (актив.)', 'value' => $compShareActive !== null ? $compShareActive . '%' : 'n/a',
                 'ok' => $compShareActive >= $compShareTgt ? true : ($compShareActive < $compShareTgt * 0.5 ? false : null)],
                ['label' => 'Закрыто здор. (всего)', 'value' => $compClosedHealthyTotal !== null ? (string)$compClosedHealthyTotal : 'n/a',
                 'ok' => ($compClosedHealthyTotal ?? 0) > 0 ? true : null],
                ['label' => 'Закрыто orphan (всего)', 'value' => $compClosedOrphanTotal !== null ? (string)$compClosedOrphanTotal : 'n/a',
                 'ok' => null],
                ['label' => 'Здоровая доля (закрыт.)','value' => $compShareClosed !== null ? $compShareClosed . '%' : 'n/a',
                 'ok' => $compShareClosed >= $compShareTgt ? true : ($compShareClosed < $compShareTgt * 0.5 ? false : null)],
                ['label' => 'Orphan-давление (слоты)','value' => $compOrphanSlotPressure !== null ? $compOrphanSlotPressure . '%' : 'n/a',
                 'ok' => ($compOrphanSlotPressure ?? 0) >= 100 ? false : (($compOrphanSlotPressure ?? 0) >= 80 ? null : true)],
                ['label' => 'Резерв здор. (всего)',   'value' => $compHealthyReserveTotal !== null ? (string)$compHealthyReserveTotal : 'n/a',
                 'ok' => null],
                ['label' => 'Резерв здор. (своб.)',   'value' => $compHealthyReserveAvail !== null ? (string)$compHealthyReserveAvail : 'n/a',
                 'ok' => ($compHealthyReserveAvail ?? 0) === 0 ? true : null],
                ['label' => 'Orphan cap (лимит)',     'value' => $compOrphanSlotCap !== null && $compOrphanSlotCap > 0 ? (string)$compOrphanSlotCap : 'н/д',
                 'ok' => null],
                ['label' => 'Orphan cap достигнут',   'value' => $compOrphanCapReached !== null ? ($compOrphanCapReached ? 'ДА' : 'НЕТ') : 'n/a',
                 'ok' => $compOrphanCapReached !== null ? !$compOrphanCapReached : null],
                ['label' => 'Adoptions отложено',     'value' => $compOrphanCapBlocked !== null ? (string)$compOrphanCapBlocked : 'n/a',
                 'ok' => ($compOrphanCapBlocked ?? 0) > 0 ? null : true],
            ];
            foreach ($compCards as $ccc):
                $ccc_cls = 'neutral';
                if ($ccc['ok'] === true)  $ccc_cls = 'positive';
                if ($ccc['ok'] === false) $ccc_cls = 'negative';
            ?>
            <div class="col-6 col-md-2">
                <div class="stat-card">
                    <div class="stat-value <?= $ccc_cls ?>"><?= htmlspecialchars((string)$ccc['value']) ?></div>
                    <div class="stat-label"><?= htmlspecialchars($ccc['label']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
        // Composition bottleneck label map (Russian translations)
        $compBottleneckLabels = [
            'orphan_positions_dominating_capacity'    => 'Orphan позиции доминируют в ёмкости',
            'healthy_share_too_low'                   => 'Доля здоровых сделок слишком мала',
            'healthy_slots_reserved_waiting_for_feed' => 'Резервные слоты ждут сигналов',
            'orphan_recovery_overweight'              => 'Перекос в сторону orphan-recovery',
            'balanced_demo_mix'                       => 'Состав demo-петли сбалансирован',
        ];
        $compBottleneckLabel = $compBottleneckLabels[$compBottleneck] ?? $compBottleneck;
        $compBottleneckIsGood = $compBottleneck === 'balanced_demo_mix';
        $compBottleneckIsWarn = in_array($compBottleneck, ['orphan_positions_dominating_capacity', 'healthy_share_too_low', 'orphan_recovery_overweight'], true);
        ?>
        <?php if ($compBottleneck !== ''): ?>
        <div class="alert <?= $compBottleneckIsGood ? 'alert-success' : ($compBottleneckIsWarn ? 'alert-warning' : 'alert-secondary') ?> py-1 px-3 mt-2 mb-0" style="font-size:.8rem;">
            <strong>Состав петли:</strong> <code><?= htmlspecialchars($compBottleneck) ?></code>
            — <?= htmlspecialchars($compBottleneckLabel) ?>
            <?php if ($compBottleneckReason !== ''): ?>
            <br><?= htmlspecialchars($compBottleneckReason) ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; // $hasCompData ?>
    </div>
</div>
<?php endif; ?>

<?php
// ── Demo Data Readiness panel — always shown in demo mode or when sufficiency data exists ──
$demoSufficiency      = $bot_demo_data_sufficiency ?? [];
$demoClosedTotal      = (int)($demoSufficiency['demo_closed_trades_total']        ?? 0);
$demoClosedComplete   = (int)($demoSufficiency['demo_closed_trades_complete']     ?? 0);
$demoCompleteRate     = (float)($demoSufficiency['demo_closed_trades_complete_rate'] ?? 0.0);
$demoActiveCount2     = (int)($demoSufficiency['demo_active_trades_count']        ?? 0);
$aiDatasetRecords     = (int)($demoSufficiency['ai_dataset_records']              ?? 0);
$aiDatasetComplete    = (int)($demoSufficiency['ai_dataset_records_complete']     ?? 0);
$aiReady              = (bool)($demoSufficiency['ai_dataset_ready']               ?? false);
$aiReadyReason        = (string)($demoSufficiency['ai_dataset_ready_reason']      ?? '');
$aiMinSamples         = (int)($demoSufficiency['ai_dataset_min_samples']          ?? 50);
$demoSuffAt           = (string)($demoSufficiency['computed_at']                  ?? '');
$perPatternCounts     = (array)($demoSufficiency['per_pattern_counts']            ?? []);
$perSideCounts        = (array)($demoSufficiency['per_side_counts']               ?? []);
$topSymbols           = (array)($demoSufficiency['top_symbols']                   ?? []);
$perCloseReason       = (array)($demoSufficiency['per_close_reason_counts']       ?? []);
$nextMilestone        = $demoSufficiency['next_readiness_milestone']              ?? null;
?>
<?php if ($bot_mode === 'demo' || !empty($demoSufficiency)): ?>
<div class="card mb-4" style="border-color:<?= $aiReady ? '#16a34a' : '#334155' ?>;">
    <div class="card-body">
        <div class="section-heading">
            Готовность demo-данных
            <?php if ($aiReady): ?>
            <span class="badge bg-success ms-2" style="font-size:.65rem;">ГОТОВО</span>
            <?php else: ?>
            <span class="badge bg-secondary ms-2" style="font-size:.65rem;">НАБИРАЕТСЯ</span>
            <?php endif; ?>
        </div>
        <div class="row g-2 mb-2">
            <?php
            $nextMs = $nextMilestone !== null ? $demoClosedTotal . '/' . $nextMilestone : ($demoClosedTotal . ' (готово)');
            $readCards = [
                ['label' => 'Закрытых сделок',      'value' => (string)$demoClosedTotal,
                 'ok' => $demoClosedTotal >= $aiMinSamples ? true : null],
                ['label' => 'Полных записей',        'value' => (string)$demoClosedComplete,
                 'ok' => null],
                ['label' => 'Полнота',               'value' => $demoClosedTotal > 0 ? $demoCompleteRate . '%' : 'н/д',
                 'ok' => $demoCompleteRate >= 80 ? true : ($demoClosedTotal > 0 ? false : null)],
                ['label' => 'Активных сделок',       'value' => (string)$demoActiveCount2, 'ok' => null],
                ['label' => 'Записей AI Dataset',    'value' => (string)$aiDatasetRecords, 'ok' => null],
                ['label' => 'Полных AI-записей',     'value' => (string)$aiDatasetComplete, 'ok' => null],
                ['label' => 'AI готов',              'value' => $aiReady ? 'ДА' : 'НЕТ (' . $demoClosedTotal . '/' . $aiMinSamples . ')',
                 'ok' => $aiReady],
                ['label' => 'Следующий рубеж',       'value' => $nextMs, 'ok' => null],
            ];
            foreach ($readCards as $rc):
                $cls = 'neutral';
                if ($rc['ok'] === true)  $cls = 'positive';
                if ($rc['ok'] === false) $cls = 'negative';
            ?>
            <div class="col-6 col-md-2">
                <div class="stat-card">
                    <div class="stat-value <?= $cls ?>"><?= htmlspecialchars((string)$rc['value']) ?></div>
                    <div class="stat-label"><?= htmlspecialchars($rc['label']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($aiReadyReason !== ''): ?>
        <div class="alert <?= $aiReady ? 'alert-success' : 'alert-secondary' ?> py-2 mb-2 small">
            <?= htmlspecialchars($aiReadyReason) ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($perPatternCounts) || !empty($perSideCounts) || !empty($topSymbols) || !empty($perCloseReason)): ?>
        <div class="row g-3">
            <?php if (!empty($perPatternCounts)): ?>
            <div class="col-md-3">
                <div class="section-heading" style="font-size:.75rem;">Закрытия по паттерну</div>
                <table class="table table-sm exec-table mb-0">
                    <thead><tr><th>Паттерн</th><th>Сделок</th></tr></thead>
                    <tbody>
                    <?php foreach ($perPatternCounts as $pat => $cnt): ?>
                    <tr><td><?= htmlspecialchars($pat) ?></td><td><?= (int)$cnt ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <?php if (!empty($perSideCounts)): ?>
            <div class="col-md-2">
                <div class="section-heading" style="font-size:.75rem;">Закрытия по направлению</div>
                <table class="table table-sm exec-table mb-0">
                    <thead><tr><th>Направление</th><th>Сделок</th></tr></thead>
                    <tbody>
                    <?php foreach ($perSideCounts as $side => $cnt): ?>
                    <tr><td><?= htmlspecialchars($side) ?></td><td><?= (int)$cnt ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <?php if (!empty($perCloseReason)): ?>
            <div class="col-md-3">
                <div class="section-heading" style="font-size:.75rem;">Распределение причин закрытия</div>
                <table class="table table-sm exec-table mb-0">
                    <thead><tr><th>Причина</th><th>Кол-во</th></tr></thead>
                    <tbody>
                    <?php foreach ($perCloseReason as $cr => $crc): ?>
                    <tr><td><?= htmlspecialchars($cr) ?></td><td><?= (int)$crc ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <?php if (!empty($topSymbols)): ?>
            <div class="col-md-4">
                <div class="section-heading" style="font-size:.75rem;">Топ символов по demo-данным</div>
                <table class="table table-sm exec-table mb-0">
                    <thead><tr><th>Символ</th><th>Всего</th><th>Полных</th></tr></thead>
                    <tbody>
                    <?php foreach ($topSymbols as $sym => $sc):
                        $sTotal    = (int)($sc['total']    ?? 0);
                        $sComplete = (int)($sc['complete'] ?? 0);
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($sym) ?></td>
                        <td><?= $sTotal ?></td>
                        <td><?= $sComplete ?> (<?= $sTotal > 0 ? round($sComplete / $sTotal * 100) : 0 ?>%)</td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($demoSuffAt !== ''): ?>
        <div class="mt-2 small text-muted">Последний расчёт: <?= htmlspecialchars($demoSuffAt) ?></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php
// ── Demo Velocity mini-panel (this run throughput vs cumulative) ─────────────
$topPatternsByClosed  = (array)($demoSufficiency['top_patterns_by_closed_count'] ?? []);
$topSymbolsByClosed   = (array)($demoSufficiency['top_symbols_by_closed_count']  ?? $topSymbols);
?>
<?php if ($bot_mode === 'demo' && ($demoTradesClosedThisRun !== null || !empty($topPatternsByClosed))): ?>
<div class="card mb-4" style="border-color:#1e3a5f;">
    <div class="card-body">
        <div class="section-heading">Скорость demo / рост датасета</div>
        <div class="row g-2 mb-2">
            <?php
            $velCards = [
                ['label' => 'Открыто за запуск',     'value' => $demoTradesOpenedThisRun !== null ? (string)$demoTradesOpenedThisRun : 'n/a',  'ok' => null],
                ['label' => 'Закрыто за запуск',     'value' => $demoTradesClosedThisRun !== null ? (string)$demoTradesClosedThisRun : 'n/a',  'ok' => $demoTradesClosedThisRun > 0 ? true : null],
                ['label' => 'AI за запуск',          'value' => $demoAiWrittenThisRun !== null ? (string)$demoAiWrittenThisRun : 'n/a',         'ok' => $demoAiWrittenThisRun > 0 ? true : null],
                ['label' => 'Закрыто всего (всё вр.)','value' => (string)$demoClosedTotal,                                                      'ok' => $demoClosedTotal >= 50 ? true : null],
                ['label' => 'AI Dataset всего',       'value' => (string)$aiDatasetRecords,                                                     'ok' => null],
                ['label' => 'Следующий рубеж',        'value' => $nextMilestone !== null ? $demoClosedTotal . '/' . $nextMilestone : $demoClosedTotal . ' ✓', 'ok' => $nextMilestone === null ? true : null],
            ];
            foreach ($velCards as $vc):
                $cls = 'neutral';
                if ($vc['ok'] === true)  $cls = 'positive';
                if ($vc['ok'] === false) $cls = 'negative';
            ?>
            <div class="col-6 col-md-2">
                <div class="stat-card">
                    <div class="stat-value <?= $cls ?>"><?= htmlspecialchars((string)$vc['value']) ?></div>
                    <div class="stat-label"><?= htmlspecialchars($vc['label']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($topPatternsByClosed) || !empty($topSymbolsByClosed)): ?>
        <div class="row g-3">
            <?php if (!empty($topPatternsByClosed)): ?>
            <div class="col-md-4">
                <div class="section-heading" style="font-size:.75rem;">Топ паттернов по закрытым сделкам</div>
                <table class="table table-sm exec-table mb-0">
                    <thead><tr><th>Паттерн</th><th>Закрыто</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($topPatternsByClosed, 0, 8, true) as $pat => $cnt): ?>
                    <tr><td><?= htmlspecialchars($pat) ?></td><td><?= (int)$cnt ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <?php if (!empty($topSymbolsByClosed)): ?>
            <div class="col-md-4">
                <div class="section-heading" style="font-size:.75rem;">Топ символов по закрытым сделкам</div>
                <table class="table table-sm exec-table mb-0">
                    <thead><tr><th>Символ</th><th>Всего</th><th>Полных</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($topSymbolsByClosed, 0, 8, true) as $sym => $sc):
                        $sTotal    = is_array($sc) ? (int)($sc['total']    ?? 0) : (int)$sc;
                        $sComplete = is_array($sc) ? (int)($sc['complete'] ?? 0) : $sTotal;
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($sym) ?></td>
                        <td><?= $sTotal ?></td>
                        <td><?= $sComplete ?> (<?= $sTotal > 0 ? round($sComplete / $sTotal * 100) : 0 ?>%)</td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php
// ── Demo Truth Audit panel — derived from actual storage files ───────────────
$demoTruthAudit        = $bot_demo_truth_audit ?? [];
$auditActive           = $demoTruthAudit['active_trades_count']               ?? null;
$auditClosed           = $demoTruthAudit['closed_trades_count']               ?? null;
$auditAiDataset        = $demoTruthAudit['ai_dataset_count']                  ?? null;
$auditOldestAge        = $demoTruthAudit['oldest_active_trade_age_minutes']   ?? null;
$auditAvgAge           = $demoTruthAudit['avg_active_trade_age_minutes']      ?? null;
$auditStaleCount       = $demoTruthAudit['stale_active_count']                ?? null;
$auditPctStale         = $demoTruthAudit['pct_active_trades_stale']           ?? null;
$auditCompleteRate     = $demoTruthAudit['closed_trades_completeness_rate']   ?? null;
$auditFullCompleteRate = $demoTruthAudit['closed_trades_full_complete_rate']  ?? null;
$auditFullCompleteCount= $demoTruthAudit['closed_trades_full_complete_count'] ?? null;
$auditMissingMfeCount  = $demoTruthAudit['closed_trades_missing_mfe_count']   ?? null;
$auditMissingMaeCount  = $demoTruthAudit['closed_trades_missing_mae_count']   ?? null;
$auditMatchRate        = $demoTruthAudit['closed_to_ai_dataset_match_rate']   ?? null;
$auditMissingMfe       = $demoTruthAudit['pct_closed_missing_mfe']            ?? null;
$auditMissingMae       = $demoTruthAudit['pct_closed_missing_mae']            ?? null;
$auditMissingHold      = $demoTruthAudit['pct_closed_missing_hold_minutes']   ?? null;
$auditMissingReason    = $demoTruthAudit['pct_closed_missing_close_reason']   ?? null;
$auditClosedNoAi       = $demoTruthAudit['closed_trades_without_ai_dataset_count'] ?? null;
$auditBottleneck       = (string)($demoTruthAudit['primary_demo_bottleneck']        ?? '');
$auditBottleneckReason = (string)($demoTruthAudit['primary_demo_bottleneck_reason'] ?? '');
$auditNextFix          = (string)($demoTruthAudit['recommended_next_fix_area']      ?? '');
$auditAt               = (string)($demoTruthAudit['audited_at']                     ?? '');
$auditOrphanDetected   = $demoTruthAudit['orphan_exchange_positions_detected']       ?? null;
$auditOrphanBlocking   = $demoTruthAudit['orphan_exchange_positions_blocking_count'] ?? null;
$auditExecBlocker      = (string)($demoTruthAudit['primary_execution_blocker']       ?? '');
?>
<?php if ($bot_mode === 'demo' && !empty($demoTruthAudit)): ?>
<div class="card mb-4" style="border-color:#7c3aed;">
    <div class="card-body">
        <div class="section-heading">Аудит данных demo <span class="badge bg-secondary ms-2" style="font-size:.6rem;">ИЗ ХРАНИЛИЩА</span></div>
        <div class="row g-2 mb-2">
            <?php
            $auditCards = [
                ['label' => 'Активных сделок',       'value' => $auditActive !== null ? (string)$auditActive : 'n/a',          'ok' => null],
                ['label' => 'Закрытых сделок',       'value' => $auditClosed !== null ? (string)$auditClosed : 'n/a',          'ok' => $auditClosed > 0 ? true : null],
                ['label' => 'Записей AI Dataset',    'value' => $auditAiDataset !== null ? (string)$auditAiDataset : 'n/a',    'ok' => null],
                ['label' => 'Старейшее акт. (мин)',  'value' => $auditOldestAge !== null ? (string)$auditOldestAge : 'n/a',    'ok' => null],
                ['label' => 'Устаревших акт.',       'value' => $auditStaleCount !== null ? $auditStaleCount . ' (' . $auditPctStale . '%)' : 'n/a', 'ok' => ($auditPctStale ?? 0) < 30 ? true : (($auditPctStale ?? 0) > 60 ? false : null)],
                ['label' => 'Осн. полнота',          'value' => $auditCompleteRate !== null ? $auditCompleteRate . '%' : 'n/a', 'ok' => ($auditCompleteRate ?? 0) >= 80 ? true : ($auditCompleteRate !== null && $auditClosed > 3 ? false : null)],
                ['label' => 'Полная полнота',        'value' => $auditFullCompleteRate !== null ? $auditFullCompleteRate . '%' : 'n/a', 'ok' => ($auditFullCompleteRate ?? 0) >= 60 ? true : ($auditFullCompleteRate !== null && $auditClosed > 3 ? false : null)],
                ['label' => 'AI Dataset совпадение', 'value' => $auditMatchRate !== null ? $auditMatchRate . '%' : 'n/a',       'ok' => ($auditMatchRate ?? 0) >= 90 ? true : ($auditMatchRate !== null && $auditClosed > 0 ? false : null)],
                ['label' => 'Без AI-записи',         'value' => $auditClosedNoAi !== null ? (string)$auditClosedNoAi : 'n/a',  'ok' => $auditClosedNoAi === 0 ? true : ($auditClosedNoAi > 0 ? false : null)],
            ];
            foreach ($auditCards as $ac):
                $cls = 'neutral';
                if ($ac['ok'] === true)  $cls = 'positive';
                if ($ac['ok'] === false) $cls = 'negative';
            ?>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-value <?= $cls ?>"><?= htmlspecialchars((string)$ac['value']) ?></div>
                    <div class="stat-label"><?= htmlspecialchars($ac['label']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($auditMissingMfe !== null || $auditMissingMae !== null): ?>
        <div class="row g-2 mb-2">
            <?php
            $fieldCards = [
                ['label' => 'Нет MFE %',         'value' => $auditMissingMfe !== null ? $auditMissingMfe . '%' : 'n/a',    'ok' => $auditMissingMfe === 0.0 ? true : ($auditMissingMfe !== null && $auditMissingMfe > 20 ? false : null)],
                ['label' => 'Нет MAE %',         'value' => $auditMissingMae !== null ? $auditMissingMae . '%' : 'n/a',    'ok' => $auditMissingMae === 0.0 ? true : ($auditMissingMae !== null && $auditMissingMae > 20 ? false : null)],
                ['label' => 'Нет MFE (кол-во)', 'value' => $auditMissingMfeCount !== null ? (string)$auditMissingMfeCount : 'n/a', 'ok' => $auditMissingMfeCount === 0 ? true : ($auditMissingMfeCount > 0 ? false : null)],
                ['label' => 'Нет MAE (кол-во)', 'value' => $auditMissingMaeCount !== null ? (string)$auditMissingMaeCount : 'n/a', 'ok' => $auditMissingMaeCount === 0 ? true : ($auditMissingMaeCount > 0 ? false : null)],
                ['label' => 'Нет Hold %',        'value' => $auditMissingHold !== null ? $auditMissingHold . '%' : 'n/a',  'ok' => $auditMissingHold === 0.0 ? true : ($auditMissingHold !== null && $auditMissingHold > 20 ? false : null)],
                ['label' => 'Нет Reason %',      'value' => $auditMissingReason !== null ? $auditMissingReason . '%' : 'n/a', 'ok' => $auditMissingReason === 0.0 ? true : ($auditMissingReason !== null && $auditMissingReason > 10 ? false : null)],
            ];
            foreach ($fieldCards as $fc):
                $cls = 'neutral';
                if ($fc['ok'] === true)  $cls = 'positive';
                if ($fc['ok'] === false) $cls = 'negative';
            ?>
            <div class="col-6 col-md-2">
                <div class="stat-card">
                    <div class="stat-value <?= $cls ?>"><?= htmlspecialchars((string)$fc['value']) ?></div>
                    <div class="stat-label"><?= htmlspecialchars($fc['label']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
        // Alert: basic completeness looks good but full completeness (MFE+MAE) is low
        $showMfeMaeAlert = $auditClosed > 3
            && ($auditCompleteRate ?? 0) > 50
            && ($auditFullCompleteRate ?? 100) < 20;
        ?>
        <?php if ($showMfeMaeAlert): ?>
        <div class="alert alert-warning py-2 mb-2 small">
            <strong>Разрыв MFE/MAE:</strong> Основная полнота: <?= $auditCompleteRate ?>%, но полная полнота (с MFE+MAE) только <?= $auditFullCompleteRate ?>%.
            <?= $auditMissingMfeCount ?> сделок без MFE, <?= $auditMissingMaeCount ?> без MAE.
            Активные demo-сделки ещё не накапливают данные MFE/MAE — сделки могут закрываться слишком быстро, или отслеживание цен началось слишком недавно.
        </div>
        <?php endif; ?>
        <?php endif; // auditMissingMfe block ?>
        <?php if ($auditBottleneck !== ''): ?>
        <?php
        $auditAlertClass = 'alert-warning';
        if ($auditBottleneck === 'none_loop_is_cycling') $auditAlertClass = 'alert-success';
        elseif (in_array($auditBottleneck, ['orphan_positions_blocking_demo','orphan_dead_shells_blocking_truth_loop'], true)) $auditAlertClass = 'alert-danger';
        elseif ($auditBottleneck === 'adopted_orphans_missing_timing') $auditAlertClass = 'alert-warning';
        elseif ($auditBottleneck === 'adopted_orphans_stale_not_closing') $auditAlertClass = 'alert-warning';
        elseif ($auditBottleneck === 'adopted_orphans_awaiting_close') $auditAlertClass = 'alert-info';
        ?>
        <div class="alert <?= $auditAlertClass ?> py-2 mb-2 small">
            <strong>Узкое место:</strong> <code><?= htmlspecialchars($auditBottleneck) ?></code><br>
            <?= htmlspecialchars($auditBottleneckReason) ?>
            <?php if ($auditNextFix !== '' && $auditBottleneck !== 'none_loop_is_cycling'): ?>
            <br><strong>Следующее исправление:</strong> <code><?= htmlspecialchars($auditNextFix) ?></code>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($auditOrphanDetected !== null): ?>
        <div class="row g-2 mb-2">
            <?php
            $orphanAuditCards = [
                ['label' => 'Orphan блокирует (зап.)', 'value' => (string)($auditOrphanDetected ?? 0), 'ok' => ($auditOrphanDetected ?? 0) === 0 ? true : false],
                ['label' => 'Orphan→Локал. решено',    'value' => $auditOrphanResolved !== null ? (string)$auditOrphanResolved : 'n/a', 'ok' => ($auditOrphanResolved ?? 0) > 0 ? true : null],
                ['label' => 'Orphan нерешённых',       'value' => $auditOrphanUnresolved !== null ? (string)$auditOrphanUnresolved : 'n/a', 'ok' => $auditOrphanUnresolved === 0 ? true : ($auditOrphanUnresolved > 0 ? false : null)],
                ['label' => 'Осн. блокировщик исп.',   'value' => $auditExecBlocker !== '' ? htmlspecialchars($auditExecBlocker) : 'нет', 'ok' => ($auditExecBlocker === '' || $auditExecBlocker === 'none') ? true : false],
            ];
            foreach ($orphanAuditCards as $oac):
                $cls = 'neutral';
                if ($oac['ok'] === true)  $cls = 'positive';
                if ($oac['ok'] === false) $cls = 'negative';
            ?>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-value <?= $cls ?>"><?= $oac['value'] ?></div>
                    <div class="stat-label"><?= htmlspecialchars($oac['label']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($auditAt !== ''): ?>
        <div class="mt-1 small text-muted">Аудит из хранилища: <?= htmlspecialchars($auditAt) ?></div>
        <?php endif; ?>
        <?php
        // ── Pattern Engine feed contribution (shown when feed is the bottleneck) ──
        $peFeedExport   = (int)(($pe_last_run ?? [])['demo_feed_export_total']            ?? (($pe_last_run ?? [])['demo_signals_count'] ?? -1));
        $peFeedTarget   = (int)(($pe_last_run ?? [])['demo_feed_target_min_per_run']      ?? 3);
        $peFeedMax      = (int)(($pe_last_run ?? [])['demo_feed_target_soft_max_per_run'] ?? 10);
        $peFeedMet      = (bool)(($pe_last_run ?? [])['demo_feed_met_target']             ?? false);
        $peFeedBelow    = (int)(($pe_last_run ?? [])['demo_feed_below_target_by']         ?? 0);
        $peFeedBlock    = (string)(($pe_last_run ?? [])['demo_feed_top_block_preventing_target'] ?? '');
        $peTopBlocks    = (array)(($pe_last_run ?? [])['top_demo_feed_block_reasons']     ?? []);
        $peCandTotal    = (int)(($pe_last_run ?? [])['demo_feed_candidate_total']         ?? 0);
        $peRunAt        = (string)(($pe_last_run ?? [])['generated_at']                   ?? '');
        if ($auditBottleneck === 'demo_feed_too_small' && $peFeedExport >= 0):
        ?>
        <div class="mt-2 p-2" style="background:#0f172a; border:1px solid <?= $peFeedMet ? '#22c55e' : '#dc2626' ?>; border-radius:6px;">
            <div class="small mb-1 fw-bold" style="color:<?= $peFeedMet ? '#22c55e' : '#f87171' ?>;">
                <i class="bi bi-broadcast me-1"></i>Pattern Engine Demo Feed
                <?php if ($peFeedMet): ?>
                    <span class="badge ms-1" style="background:#166534; font-size:0.65rem;">цель достигнута</span>
                <?php else: ?>
                    <span class="badge ms-1" style="background:#7f1d1d; font-size:0.65rem;">нехватка — <?= $peFeedBelow ?> ниже мин.</span>
                <?php endif; ?>
            </div>
            <div class="row g-1 mb-1">
                <div class="col-4 col-md-2">
                    <div class="small" style="background:#1e293b; padding:0.2rem 0.4rem; border-radius:4px;">
                        <span class="text-secondary">Экспортировано:</span>
                        <span class="<?= $peFeedMet ? 'text-success' : 'text-danger' ?> ms-1 fw-bold"><?= $peFeedExport ?></span>
                    </div>
                </div>
                <div class="col-4 col-md-2">
                    <div class="small" style="background:#1e293b; padding:0.2rem 0.4rem; border-radius:4px;">
                        <span class="text-secondary">Мин. цель:</span>
                        <span class="text-info ms-1"><?= $peFeedTarget ?></span>
                    </div>
                </div>
                <div class="col-4 col-md-2">
                    <div class="small" style="background:#1e293b; padding:0.2rem 0.4rem; border-radius:4px;">
                        <span class="text-secondary">Мягкий макс.:</span>
                        <span class="text-secondary ms-1"><?= $peFeedMax ?></span>
                    </div>
                </div>
                <div class="col-4 col-md-2">
                    <div class="small" style="background:#1e293b; padding:0.2rem 0.4rem; border-radius:4px;">
                        <span class="text-secondary">Кандидатов:</span>
                        <span class="text-secondary ms-1"><?= $peCandTotal ?></span>
                    </div>
                </div>
            </div>
            <?php if (!$peFeedMet && $peFeedBlock !== ''): ?>
            <div class="small text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Главный блок: <code><?= htmlspecialchars($peFeedBlock) ?></code></div>
            <?php endif; ?>
            <?php if (!empty($peTopBlocks)): ?>
            <div class="d-flex flex-wrap gap-1 mt-1">
                <?php foreach (array_slice($peTopBlocks, 0, 4) as $blk): ?>
                <span class="badge" style="background:#1e293b; border:1px solid #475569; font-size:0.65rem;">
                    <?= htmlspecialchars(str_replace('demo_feed_blocked_by_', '', (string)($blk['reason'] ?? ''))) ?>
                    <span class="text-warning ms-1"><?= (int)($blk['count'] ?? 0) ?></span>
                </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($peRunAt !== ''): ?>
            <div class="mt-1 small text-muted">PE последний запуск: <?= htmlspecialchars(date('d M H:i', strtotime($peRunAt))) ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ===== Stats ===== -->
<?php if (!empty($bot_stats)): ?>
<div class="card mb-4">
    <div class="card-body">
        <div class="section-heading">Статистика</div>
        <div class="row g-2">
        <?php
        $statMap = [
            'total_trades'        => 'Сделок всего',
            'win_rate'            => 'Прибыльных %',
            'avg_roi'             => 'Ср. ROI',
            'expectancy'          => 'Матожидание',
            'stop_hit_rate'       => 'Стоп-лосс %',
            'tp_hit_rate'         => 'Тейк-профит %',
            'trailing_close_rate' => 'Закрытий по трейлингу',
            'total_pnl'           => 'Итого PnL',
            'wins'                => 'Прибыльных',
            'losses'              => 'Убыточных',
        ];
        foreach ($statMap as $sKey => $sLabel):
            if (!array_key_exists($sKey, $bot_stats)) continue;
            $sVal = $bot_stats[$sKey];
            if (is_float($sVal) || is_numeric($sVal)) { $sVal = round((float)$sVal, 2); }
        ?>
        <div class="col-6 col-md-2">
            <div class="stat-card">
                <div class="stat-value"><?= htmlspecialchars((string)$sVal) ?></div>
                <div class="stat-label"><?= htmlspecialchars($sLabel) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>

        <?php
        // Per-symbol stats
        $bySymbol = $bot_stats['by_symbol'] ?? $bot_stats['per_symbol'] ?? [];
        if (!empty($bySymbol) && is_array($bySymbol)):
        ?>
        <div class="mt-3">
            <div class="section-heading" style="margin-top:.5rem;">Статистика по символам</div>
            <div class="table-responsive">
                <table class="table table-dark table-sm exec-table mb-0">
                    <thead><tr><th>Символ</th><th>Сделок</th><th>Прибыльных %</th><th>Ср. ROI</th><th>Итого PnL</th></tr></thead>
                    <tbody>
                    <?php foreach ($bySymbol as $sym => $ss): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$sym) ?></td>
                            <td><?= (int)($ss['count'] ?? $ss['trades'] ?? 0) ?></td>
                            <td><?= round((float)($ss['win_rate'] ?? 0), 1) ?>%</td>
                            <td><?= round((float)($ss['avg_roi'] ?? 0), 2) ?>%</td>
                            <td><?= round((float)($ss['total_pnl'] ?? 0), 4) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php
        // Per-pattern stats
        $byPattern = $bot_stats['by_pattern'] ?? $bot_stats['per_pattern'] ?? [];
        if (!empty($byPattern) && is_array($byPattern)):
        ?>
        <div class="mt-3">
            <div class="section-heading">Статистика по паттернам</div>
            <div class="table-responsive">
                <table class="table table-dark table-sm exec-table mb-0">
                    <thead><tr><th>Паттерн</th><th>Сделок</th><th>Прибыльных %</th><th>Ср. ROI</th></tr></thead>
                    <tbody>
                    <?php foreach ($byPattern as $pat => $ps): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$pat) ?></td>
                            <td><?= (int)($ps['count'] ?? $ps['trades'] ?? 0) ?></td>
                            <td><?= round((float)($ps['win_rate'] ?? 0), 1) ?>%</td>
                            <td><?= round((float)($ps['avg_roi'] ?? 0), 2) ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ===== Active Positions ===== -->
<div class="card mb-4">
    <div class="card-body">
        <div class="section-heading">Активные позиции (<?= $activeCount ?>)</div>
        <?php if (empty($bot_active_trades)): ?>
            <p class="text-muted mb-0">Нет активных позиций.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-dark table-sm exec-table mb-0">
                <thead>
                    <tr><th>Символ</th><th>Направл.</th><th>Вход</th><th>Цена</th><th>ROI%</th><th>PnL</th><th>SL</th><th>MFE%</th><th>Открыта</th></tr>
                </thead>
                <tbody>
                <?php foreach ($bot_active_trades as $t): ?>
                    <?php
                    $roi = (float)($t['roi_pct'] ?? $t['roi'] ?? 0);
                    $pnl = (float)($t['unrealised_pnl'] ?? $t['pnl'] ?? 0);
                    $mfe = (float)($t['mfe_roi'] ?? $t['mfe'] ?? 0);
                    $since = isset($t['opened_at']) ? date('m-d H:i', (int)$t['opened_at']) : '—';
                    $rc = $roi >= 0 ? 'positive' : 'negative';
                    $pc = $pnl >= 0 ? 'positive' : 'negative';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string)($t['symbol'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars((string)($t['side'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars((string)($t['entry_price'] ?? $t['avg_price'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars((string)($t['mark_price'] ?? $t['active_price'] ?? '—')) ?></td>
                        <td class="<?= $rc ?>"><?= round($roi, 2) ?>%</td>
                        <td class="<?= $pc ?>"><?= round($pnl, 4) ?></td>
                        <td><?= htmlspecialchars((string)($t['stop_loss'] ?? '—')) ?></td>
                        <td><?= round($mfe, 2) ?>%</td>
                        <td><?= htmlspecialchars($since) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== Closed Trades ===== -->
<div class="card mb-4">
    <div class="card-body">
        <div class="section-heading">Последние закрытые сделки (последние <?= count($bot_closed_trades) ?>)</div>
        <?php if (empty($bot_closed_trades)): ?>
            <p class="text-muted mb-0">Закрытые сделки не найдены.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-dark table-sm exec-table mb-0">
                <thead>
                    <tr><th>Символ</th><th>Направл.</th><th>Паттерн</th><th>Вход</th><th>Выход</th><th>ROI%</th><th>PnL</th><th>Причина</th><th>Время</th><th>MFE%</th></tr>
                </thead>
                <tbody>
                <?php foreach ($bot_closed_trades as $t): ?>
                    <?php
                    $roi = (float)($t['roi_pct'] ?? $t['roi'] ?? 0);
                    $pnl = (float)($t['realised_pnl'] ?? $t['pnl'] ?? 0);
                    $mfe = (float)($t['mfe_roi'] ?? $t['mfe_pct'] ?? $t['mfe'] ?? 0);
                    // hold: prefer hold_minutes, fall back to hold_seconds/hold_time
                    $holdMin = isset($t['hold_minutes']) && $t['hold_minutes'] !== null ? (float)$t['hold_minutes'] : null;
                    if ($holdMin !== null && $holdMin >= 0) {
                        $holdSec = (int)round($holdMin * 60);
                    } else {
                        $holdSec = (int)($t['hold_seconds'] ?? $t['hold_time'] ?? 0);
                    }
                    $holdStr = $holdSec > 0 ? gmdate('H:i:s', $holdSec) : '—';
                    // exit price: prefer close_price, fall back to exit_price
                    $exitPrice = $t['close_price'] ?? $t['exit_price'] ?? null;
                    // reason: prefer close_reason_normalized, fall back to close_reason
                    $closeReason = $t['close_reason_normalized'] ?? $t['close_reason'] ?? null;
                    $rc = $roi >= 0 ? 'positive' : 'negative';
                    $pc = $pnl >= 0 ? 'positive' : 'negative';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string)($t['symbol'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars((string)($t['side'] ?? '—')) ?></td>
                        <td><small><?= htmlspecialchars((string)($t['pattern_algorithm'] ?? $t['pattern'] ?? '—')) ?></small></td>
                        <td><?= htmlspecialchars((string)($t['entry_price'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars($exitPrice !== null ? (string)$exitPrice : '—') ?></td>
                        <td class="<?= $rc ?>"><?= round($roi, 2) ?>%</td>
                        <td class="<?= $pc ?>"><?= round($pnl, 4) ?></td>
                        <td><small><?= htmlspecialchars((string)($closeReason ?? '—')) ?></small></td>
                        <td><?= htmlspecialchars($holdStr) ?></td>
                        <td><?= round($mfe, 2) ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== Run Journal ===== -->
<?php
$journalRunId    = $bot_last_run['run_id'] ?? null;
$journalStorDir  = $bot_storage_dir ?? '';
$journalPath     = $journalStorDir !== '' ? ($journalStorDir . '/runtime/run_journal.ndjson') : '';
$journalExists   = $journalPath !== '' && is_file($journalPath);
$journalSizeBytes= $journalExists ? (int)filesize($journalPath) : 0;
$journalSizeKb   = $journalSizeBytes > 0 ? round($journalSizeBytes / 1024, 1) : 0;
// Read last 5 events from journal tail
$journalTailEvents = [];
if ($journalExists && $journalSizeBytes > 0) {
    $chunkSize = max(8192, 5 * 500);
    $fp = @fopen($journalPath, 'r');
    if ($fp !== false) {
        $fileSize = $journalSizeBytes;
        $offset = max(0, $fileSize - $chunkSize);
        fseek($fp, $offset);
        $chunk = fread($fp, $chunkSize);
        fclose($fp);
        if ($chunk !== false && $chunk !== '') {
            $lines = explode("\n", trim($chunk));
            if ($offset > 0 && count($lines) > 1) array_shift($lines);
            $lines = array_reverse($lines);
            foreach ($lines as $_jLine) {
                $_jLine = trim($_jLine);
                if ($_jLine === '') continue;
                $_jEvent = @json_decode($_jLine, true);
                if (is_array($_jEvent)) {
                    $journalTailEvents[] = $_jEvent;
                }
                if (count($journalTailEvents) >= 5) break;
            }
        }
    }
}
?>
<div class="card mb-4">
    <div class="card-body">
        <div class="section-heading">Журнал запусков <small class="text-muted fw-normal">(run_journal.ndjson — append-only)</small></div>
        <div class="row g-2 mb-2">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value <?= $journalExists ? 'positive' : 'neutral' ?>"><?= $journalExists ? 'Да' : 'Нет' ?></div>
                    <div class="stat-label">Журнал существует</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value"><?= $journalSizeKb ?> KB</div>
                    <div class="stat-label">Размер файла</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value neutral" style="font-size:.95rem;word-break:break-all;">
                        <?= $journalRunId ? htmlspecialchars(substr($journalRunId, 0, 24)) : '—' ?>
                    </div>
                    <div class="stat-label">run_id последнего запуска</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value neutral" style="font-size:.85rem;word-break:break-all;">
                        <?= $journalTailEvents ? htmlspecialchars($journalTailEvents[0]['event_type'] ?? '—') : '—' ?>
                    </div>
                    <div class="stat-label">Последнее событие</div>
                </div>
            </div>
        </div>
        <?php if ($journalPath !== ''): ?>
        <div class="mb-1" style="font-size:.72rem;color:#475569;">
            <code><?= htmlspecialchars($journalPath) ?></code>
            <span class="text-muted ms-2">— только дозапись, никогда не перезаписывается</span>
        </div>
        <?php endif; ?>
        <?php if (!empty($journalTailEvents)): ?>
        <div class="section-heading" style="font-size:.78rem;margin-top:.85rem;">Последние 5 событий журнала</div>
        <div style="overflow-x:auto;">
        <table class="table table-dark table-sm exec-table mb-0">
            <thead><tr>
                <th>ts</th><th>run_id</th><th>event_type</th><th>step</th><th>ok</th><th>message</th>
            </tr></thead>
            <tbody>
            <?php foreach ($journalTailEvents as $_je): ?>
            <tr>
                <td style="white-space:nowrap;"><?= htmlspecialchars(substr($_je['ts'] ?? '', 0, 19)) ?></td>
                <td style="font-size:.68rem;color:#64748b;"><?= htmlspecialchars(substr($_je['run_id'] ?? '', 0, 20)) ?></td>
                <td><span class="badge bg-secondary"><?= htmlspecialchars($_je['event_type'] ?? '') ?></span></td>
                <td><?= htmlspecialchars($_je['step'] ?? '') ?></td>
                <td><?= ($_je['ok'] ?? false) ? '<span class="positive">✓</span>' : '<span class="negative">✗</span>' ?></td>
                <td style="font-size:.72rem;color:#94a3b8;"><?= htmlspecialchars(substr($_je['message'] ?? '', 0, 80)) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
        <div class="text-muted" style="font-size:.8rem;">Журнал пуст или не существует — появится после первого запуска бота.</div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== Full Settings ===== -->
<div class="card mb-4">
    <div class="card-body">
        <div class="section-heading">Настройки бота <small class="text-muted fw-normal text-lowercase">(сохраняются в config/bot.json)</small></div>
        <form id="settings-form">
        <div class="settings-block">
            <h6><i class="bi bi-toggles me-1"></i> Режим</h6>
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label form-label-sm">Режим</label>
                    <select name="mode" class="form-select form-select-sm bg-dark text-light border-secondary"
                            onchange="onModeChange(this)">
                        <option value="demo"  <?= $bot_mode === 'demo'  ? 'selected' : '' ?>>Demo (Bybit Sandbox)</option>
                        <option value="live"  <?= $bot_mode === 'live'  ? 'selected' : '' ?>>Live (Реальная биржа — ОПАСНО)</option>
                        <option value="paper" <?= ($bot_mode === 'paper' || $bot_mode === 'dry') ? 'selected' : '' ?>>Paper (локальная симуляция)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <div class="form-check mt-4">
                        <input type="checkbox" class="form-check-input" name="enabled" id="chk_enabled"
                            <?= $bot_enabled ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="chk_enabled">Бот включён</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm">Account ID (live)</label>
                    <input type="text" name="account_id" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= htmlspecialchars((string)($modeCfg['account_id'] ?? $bot_config['account_id'] ?? 'trading_bot')) ?>">
                    <small class="text-muted">Аккаунт KeyCenter для LIVE-режима.</small>
                </div>
            </div>
        </div>

        <!-- 2. DEMO CREDENTIALS -->
        <div class="settings-block" id="section-demo-creds" style="<?= $bot_mode !== 'demo' ? 'display:none' : '' ?>">
            <h6><i class="bi bi-key me-1"></i> Demo-учётные данные <span class="text-muted fw-normal text-lowercase">(хранятся локально в bot config; не KeyCenter)</span></h6>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label form-label-sm">Demo API Key</label>
                    <input type="text" name="demo_api_key" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= htmlspecialchars((string)($demoCreds['api_key'] ?? '')) ?>"
                           placeholder="Введите Bybit Demo API Key">
                    <?php if ($demoKeySet): ?>
                        <small class="text-success"><i class="bi bi-check-circle me-1"></i>Ключ настроен.</small>
                    <?php else: ?>
                        <small class="text-warning">Не настроен.</small>
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm">Demo API Secret</label>
                    <input type="password" name="demo_api_secret" class="form-control form-control-sm bg-dark text-light border-secondary"
                           placeholder="<?= $demoSecretSet ? '(секрет задан — оставьте пустым для сохранения)' : 'Введите Bybit Demo API Secret' ?>">
                    <?php if ($demoSecretSet): ?>
                        <small class="text-success"><i class="bi bi-check-circle me-1"></i>Секрет задан. Оставьте пустым для сохранения.</small>
                    <?php else: ?>
                        <small class="text-warning">Не настроен.</small>
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm">Demo API Base URL</label>
                    <input type="text" name="demo_api_base_url" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= htmlspecialchars($demoBaseUrl) ?>"
                           placeholder="https://api-demo.bybit.com">
                </div>
            </div>
        </div>

        <!-- 3. LIVE CONFIG -->
        <div class="settings-block" id="section-live-info" style="<?= $bot_mode !== 'live' ? 'display:none' : '' ?>">
            <h6><i class="bi bi-lightning-charge me-1"></i> Live-конфигурация <span class="text-muted fw-normal text-lowercase">(учётные данные из KeyCenter)</span></h6>
            <div class="row g-3">
                <div class="col-12">
                    <div class="alert alert-danger py-2 mb-0 small">
                        <strong>Активен LIVE-режим.</strong> Учётные данные для реальной торговли управляются в
                        <a href="/admin/keys" class="alert-link">KeyCenter</a> под аккаунтом
                        <strong><?= htmlspecialchars((string)($modeCfg['account_id'] ?? 'trading_bot')) ?></strong>.
                        Не вводите API-ключи здесь для live-режима.
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm">API Base URL</label>
                    <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" readonly
                           value="https://api.bybit.com">
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm">Account ID</label>
                    <input type="text" name="account_id" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= htmlspecialchars((string)($modeCfg['account_id'] ?? 'trading_bot')) ?>">
                </div>
            </div>
        </div>

        <!-- 3b. PAPER INFO -->
        <div class="settings-block" id="section-paper-info" style="<?= !in_array($bot_mode, ['paper','dry']) ? 'display:none' : '' ?>">
            <h6><i class="bi bi-archive me-1"></i> Paper-режим <span class="text-muted fw-normal text-lowercase">(локальная симуляция, без биржи)</span></h6>
            <p class="text-muted small mb-0">Paper/dry-режим работает как локальная симуляция. Подключение к бирже не выполняется. Хранилище: <strong>storage_paper</strong>.</p>
        </div>

        <!-- 4. НАСТРОЙКИ ИСПОЛНЕНИЯ -->
        <div class="settings-block">
            <h6><i class="bi bi-sliders me-1"></i> Настройки исполнения</h6>
            <div class="row g-3">
                <div class="col-md-2">
                    <label class="form-label form-label-sm">
                        Макс. позиций
                        <i class="bi bi-info-circle text-secondary ms-1" title="Максимум одновременно открытых позиций."></i>
                    </label>
                    <input type="number" name="max_positions" min="1" step="1"
                           class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= (int)($bot_config['max_positions'] ?? $modeCfg['max_concurrent_positions'] ?? 3) ?>">
                    <small class="text-muted">Максимум одновременно открытых позиций.</small>
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">
                        Плечо (Leverage)
                        <i class="bi bi-info-circle text-secondary ms-1" title="Плечо по умолчанию для новых сделок."></i>
                    </label>
                    <input type="number" name="leverage_default" min="1" step="1"
                           class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= (int)($exchCfg['leverage'] ?? $bot_config['leverage_default'] ?? 5) ?>">
                    <small class="text-muted">Плечо по умолчанию для новых сделок.</small>
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">
                        Stop Loss %
                        <i class="bi bi-info-circle text-secondary ms-1" title="Базовый размер стоп-лосса в процентах."></i>
                    </label>
                    <input type="number" step="0.01" min="0.01" name="stop_loss_pct"
                           class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= round((float)($exCfg['stop_loss_pct'] ?? $bot_config['stop_loss_pct'] ?? 2.0), 2) ?>">
                    <small class="text-muted">Базовый размер стоп-лосса в процентах.</small>
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">
                        Take Profit %
                        <i class="bi bi-info-circle text-secondary ms-1" title="Базовая цель по прибыли в процентах."></i>
                    </label>
                    <input type="number" step="0.01" min="0.01" name="take_profit_pct"
                           class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= round((float)($exCfg['take_profit_pct'] ?? $bot_config['take_profit_pct'] ?? 5.0), 2) ?>">
                    <small class="text-muted">Базовая цель по прибыли в процентах.</small>
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Тип ордера</label>
                    <select name="order_type" class="form-select form-select-sm bg-dark text-light border-secondary">
                        <?php foreach (['Market', 'Limit'] as $ot): ?>
                        <option value="<?= $ot ?>" <?= ($exCfg['order_type'] ?? 'Market') === $ot ? 'selected' : '' ?>><?= $ot ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">
                        Аварийный Stop Loss %
                        <i class="bi bi-info-circle text-secondary ms-1" title="Аварийный стоп-лосс в процентах."></i>
                    </label>
                    <input type="number" step="0.01" min="0.01" name="emergency_stop_loss_pct"
                           class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= round((float)($exCfg['emergency_stop_loss_pct'] ?? $bot_config['emergency_stop_loss_pct'] ?? 5.0), 2) ?>">
                </div>
            </div>
            <div class="d-flex flex-wrap gap-4 mt-3">
                <?php
                $execChecks = [
                    ['name' => 'reconcile_before_action',   'label' => 'Синхронизация перед запуском', 'hint' => 'Перед запуском сверять локальное состояние с биржей.',  'val' => (bool)($bot_config['reconcile_before_action'] ?? false)],
                    ['name' => 'execution_reverse_side_enabled', 'label' => 'Инверсия направления',    'hint' => 'Инвертировать направление сигнала: long ↔ short.',      'val' => (bool)($exCfg['reverse_side_enabled'] ?? false)],
                    ['name' => 'execution_trailing_enabled','label' => 'Трейлинг',                      'hint' => 'Включить трейлинг-стоп.',                                'val' => (bool)($exCfg['trailing_enabled'] ?? false)],
                    ['name' => 'execution_break_even_enabled','label' => 'Безубыток',                   'hint' => 'Включить перенос стопа в безубыток.',                    'val' => (bool)($exCfg['break_even_enabled'] ?? false)],
                    ['name' => 'execution_emergency_stop_enabled','label' => 'Аварийный стоп',           'hint' => 'Включить аварийный стоп-лосс.',                          'val' => (bool)($exCfg['emergency_stop_enabled'] ?? false)],
                ];
                foreach ($execChecks as $ch): ?>
                <div class="form-check" title="<?= htmlspecialchars($ch['hint']) ?>">
                    <input type="checkbox" class="form-check-input" name="<?= $ch['name'] ?>" id="chk_<?= $ch['name'] ?>"
                        <?= $ch['val'] ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="chk_<?= $ch['name'] ?>"><?= htmlspecialchars($ch['label']) ?> <span style="font-family:monospace;font-size:10px;color:<?= $ch['val'] ? '#22c55e' : '#ef4444' ?>">(render=<?= $ch['val'] ? 'true' : 'false' ?>)</span></label>
                </div>
                <?php endforeach; ?>
            </div>
            <!-- Trailing sub-fields -->
            <div class="row g-3 mt-1">
                <div class="col-md-3">
                    <label class="form-label form-label-sm">Режим трейлинга</label>
                    <input type="text" name="trailing_mode" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= htmlspecialchars((string)($exCfg['trailing_mode'] ?? '')) ?>"
                           placeholder="e.g. step_roi">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">
                        Активационный ROI трейлинга
                        <i class="bi bi-info-circle text-secondary ms-1" title="ROI, начиная с которого включается трейлинг."></i>
                    </label>
                    <input type="number" step="0.1" min="0" name="trailing_activation_roi"
                           class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= round((float)($exCfg['trailing_activation_roi'] ?? 0), 2) ?>">
                    <small class="text-muted">ROI, начиная с которого включается трейлинг.</small>
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">
                        Фактор отката трейлинга
                        <i class="bi bi-info-circle text-warning ms-1" title="Насколько глубоко цена может откатиться от лучшего ROI до закрытия по трейлингу. Рабочий диапазон: 0.25–0.50."></i>
                    </label>
                    <input type="number" step="0.01" min="0.25" max="0.50" name="trailing_drawdown_factor"
                           class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= round((float)($exCfg['trailing_drawdown_factor'] ?? 0), 3) ?>">
                    <small class="text-warning">Рабочий диапазон: 0.25–0.50. Значения вне диапазона отклоняются.</small>
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">
                        Активационный ROI безубытка
                        <i class="bi bi-info-circle text-secondary ms-1" title="ROI, начиная с которого стоп можно подтянуть в безубыток."></i>
                    </label>
                    <input type="number" step="0.1" min="0" name="break_even_activation_roi"
                           class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= round((float)($exCfg['break_even_activation_roi'] ?? 0), 2) ?>">
                    <small class="text-muted">ROI, начиная с которого стоп подтягивается в безубыток.</small>
                </div>
            </div>
        </div>

        <!-- 5. НАСТРОЙКИ ИСТОЧНИКОВ -->
        <div class="settings-block">
            <h6><i class="bi bi-broadcast me-1"></i> Настройки источников</h6>
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <div class="form-check" title="Разрешить брать сигналы из Smart Brain.">
                        <input type="checkbox" class="form-check-input" name="sources_brain_source_enabled" id="chk_brain_src"
                            <?= !empty($srcCfg['brain_source_enabled']) ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="chk_brain_src">Brain Source включён <?php $_bsv = !empty($srcCfg['brain_source_enabled']); ?><span style="font-family:monospace;font-size:10px;color:<?= $_bsv ? '#22c55e' : '#ef4444' ?>">(render=<?= $_bsv ? 'true' : 'false' ?>)</span></label>
                    </div>
                    <small class="text-muted">Разрешить брать сигналы из Smart Brain.</small>
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm">Файл сигналов</label>
                    <input type="text" name="sources_signals_file" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= htmlspecialchars((string)($srcCfg['signals_file'] ?? 'signals.json')) ?>">
                </div>
            </div>
        </div>

        <!-- 6. БИРЖА / RUNTIME -->
        <div class="settings-block">
            <h6><i class="bi bi-hdd-stack me-1"></i> Биржа / Runtime</h6>
            <div class="row g-3">
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Категория</label>
                    <input type="text" name="exchange_category" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= htmlspecialchars((string)($exchCfg['category'] ?? 'linear')) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Тип аккаунта</label>
                    <input type="text" name="exchange_account_type" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= htmlspecialchars((string)($exchCfg['account_type'] ?? 'UNIFIED')) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Расчётная монета</label>
                    <input type="text" name="exchange_settle_coin" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= htmlspecialchars((string)($exchCfg['settle_coin'] ?? 'USDT')) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Режим TPSL</label>
                    <input type="text" name="exchange_tpsl_mode" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= htmlspecialchars((string)($exchCfg['tpsl_mode'] ?? 'Full')) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Триггер SL</label>
                    <input type="text" name="exchange_sl_trigger_by" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= htmlspecialchars((string)($exchCfg['sl_trigger_by'] ?? 'IndexPrice')) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Индекс позиции</label>
                    <input type="number" name="exchange_position_idx" min="0" step="1"
                           class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= (int)($exchCfg['position_idx'] ?? 0) ?>">
                </div>
            </div>
        </div>

        <div class="mt-2">
            <button type="button" class="btn btn-primary btn-sm" onclick="saveSettings()">
                <i class="bi bi-floppy me-1"></i> Сохранить настройки
            </button>
            <small class="text-muted ms-2">Сохраняет в <code>config/bot.json</code>. Логика исполнения не затрагивается.</small>
        </div>

        </form>
    </div>
</div>

<script>
// Init mode-aware visibility on load
(function() {
    const mode = '<?= $bot_mode ?>';
    const sel = document.querySelector('[name="mode"]');
    if (sel) { sel.value = mode; onModeChange(sel); }
})();
</script>
