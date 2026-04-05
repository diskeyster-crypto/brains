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
    btn.disabled = true; btn.textContent = 'Running…';
    showFlash('Starting bot execution cycle…', 'info');
    fetch(EXEC_URL + '/run', { method: 'POST' })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false; btn.textContent = 'Run Now';
            if (d.ok) {
                const s = d.summary ?? {};
                showFlash('Run OK — opened:' + (s.intents_opened ?? 0) + ' closed:' + (s.intents_closed ?? 0), 'success');
            } else {
                showFlash('Run error: ' + (d.error ?? JSON.stringify(d)), 'danger');
            }
            setTimeout(() => location.reload(), 2200);
        })
        .catch(() => { btn.disabled = false; btn.textContent = 'Run Now'; showFlash('Network error.', 'danger'); });
}

function reconcileBot() {
    const btn = document.getElementById('btn-reconcile');
    btn.disabled = true; btn.textContent = 'Reconciling…';
    showFlash('Forcing reconcile…', 'info');
    fetch(EXEC_URL + '/reconcile', { method: 'POST' })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false; btn.textContent = 'Reconcile Now';
            showFlash(d.ok ? 'Reconcile complete.' : 'Reconcile error: ' + (d.error ?? ''), d.ok ? 'success' : 'danger');
            if (d.ok) { setTimeout(() => location.reload(), 1800); }
        })
        .catch(() => { btn.disabled = false; btn.textContent = 'Reconcile Now'; showFlash('Network error.', 'danger'); });
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
            showFlash('Status refreshed.', 'success');
        })
        .catch(() => showFlash('Status refresh failed.', 'warning'));
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

    fetch(EXEC_URL + '/save_config', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(r => r.json())
    .then(d => {
        showFlash(d.ok ? 'Settings saved.' : 'Save failed: ' + (d.error ?? ''), d.ok ? 'success' : 'danger');
        if (d.ok) { setTimeout(() => location.reload(), 1600); }
    })
    .catch(() => showFlash('Network error saving settings.', 'danger'));
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
    <div style="color:#f97316;font-weight:700;margin-bottom:6px">⚠ DEBUG — Config Readback Proof (remove after investigation)</div>
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

<!-- ===== Mode Banner ===== -->
<?php if ($bot_mode === 'live'): ?>
<div class="alert alert-danger py-2 mb-3 d-flex align-items-center gap-2">
    <i class="bi bi-exclamation-octagon-fill fs-5"></i>
    <strong>LIVE MODE — Real exchange, real funds. All actions have real financial consequences.</strong>
</div>
<?php elseif ($bot_mode === 'demo'): ?>
<div class="alert alert-warning py-2 mb-3 d-flex align-items-center gap-2" style="border-color:#ea580c; background:#431407; color:#fdba74;">
    <i class="bi bi-info-circle-fill fs-5"></i>
    <strong>DEMO MODE — Bybit Demo API sandbox. No real funds involved.</strong>
</div>
<?php else: ?>
<div class="alert alert-secondary py-2 mb-3 d-flex align-items-center gap-2">
    <i class="bi bi-archive-fill fs-5"></i>
    <strong>PAPER MODE — Local simulation only. No exchange connection.</strong>
</div>
<?php endif; ?>

<!-- ===== Overview ===== -->
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
            <div>
                <h5 class="mb-1">
                    <i class="bi bi-cpu me-1"></i> Trading Bot
                    <?= $modeLabel ?>
                    <?= $enabledBadge ?>
                </h5>
                <small class="text-muted">Storage namespace: <strong id="storage-ns-display"><?= htmlspecialchars($storageNs) ?></strong></small>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <?php if (!$bot_available): ?>
                    <span class="text-danger small">Module unavailable</span>
                <?php else: ?>
                <button id="btn-run"       class="btn btn-success btn-sm" onclick="runBot()"><i class="bi bi-play-fill me-1"></i>Run Now</button>
                <button id="btn-reconcile" class="btn btn-outline-warning btn-sm" onclick="reconcileBot()"><i class="bi bi-arrow-repeat me-1"></i>Reconcile</button>
                <button class="btn btn-outline-secondary btn-sm" onclick="refreshStatus()"><i class="bi bi-arrow-clockwise me-1"></i>Refresh Status</button>
                <a href="/admin/trading_bot" class="btn btn-outline-light btn-sm" target="_blank"><i class="bi bi-box-arrow-up-right me-1"></i>Bot Dashboard</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$bot_available): ?>
            <div class="alert alert-danger mb-0">Bot module unavailable: <?= htmlspecialchars((string)($bot_error ?? 'unknown')) ?></div>
        <?php else: ?>
        <div class="row g-2">
            <?php
            $cards = [
                ['label' => 'Mode',             'value' => strtoupper($bot_mode)],
                ['label' => 'API Base URL',      'value' => $bot_api_base_url],
                ['label' => 'Storage Namespace', 'value' => $storageNs],
                ['label' => 'Exchange Mode',     'value' => $bot_is_real_exchange ? 'Real Exchange' : 'Paper (no exchange)'],
                ['label' => 'Last Run',          'value' => $lastRunTs ? date('Y-m-d H:i:s', (int)$lastRunTs) : 'Never'],
                ['label' => 'Last Run Status',   'value' => $lastRunOk === null ? 'n/a' : ($lastRunOk ? 'OK' : 'FAIL')],
                ['label' => 'Active Positions',  'value' => (string)$activeCount],
                ['label' => 'Closed Trades',     'value' => (string)$closedCount . ' (last 50)'],
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
            <div class="section-heading">Balance Snapshot</div>
            <div class="row g-2">
            <?php
            $bal = $bot_balance;
            $balItems = [
                'wallet_balance'     => 'Wallet Balance',
                'available_balance'  => 'Available Balance',
                'unrealised_pnl'     => 'Unrealised PnL',
                'margin_balance'     => 'Margin Balance',
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
        <div class="section-heading">Demo Credential Diagnostics</div>
        <div class="row g-2">
            <?php
            $diagCards = [
                ['label' => 'Mode',               'value' => strtoupper($bot_mode),         'ok' => null],
                ['label' => 'Storage Namespace',  'value' => $diagNs,                        'ok' => null],
                ['label' => 'API Key Present',    'value' => $diagKeyPresent ? 'YES' : 'NO', 'ok' => $diagKeyPresent],
                ['label' => 'API Secret Present', 'value' => $diagSecretPresent ? 'YES' : 'NO', 'ok' => $diagSecretPresent],
                ['label' => 'Demo Base URL',      'value' => $diagBaseUrl ?: 'default',       'ok' => null],
                ['label' => 'Real Exchange Mode', 'value' => $diagRealExchange ? 'YES' : 'NO', 'ok' => $diagRealExchange],
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
            <strong>Demo credentials are missing.</strong>
            Enter your Bybit Demo API Key and Secret in the Settings section below and save.
            Config path: <code><?= htmlspecialchars($diagCfgPath) ?></code>
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
$healthyActiveClosedRun  = $bot_last_run['healthy_active_closed_this_run']                        ?? null;
$healthyActiveFailRun    = $bot_last_run['healthy_active_close_failures_this_run']                ?? null;
$healthyActiveFailRsns   = (array)($bot_last_run['healthy_active_close_failure_reasons']          ?? []);
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
$auditClosedHealthy      = $bot_demo_truth_audit['closed_trades_healthy_total']                   ?? null;
$auditClosedOrphan       = $bot_demo_truth_audit['closed_trades_orphan_adopted_total']            ?? null;
$auditClosedTotal        = $bot_demo_truth_audit['closed_trades_total']                           ?? null;
$auditAiTotal            = $bot_demo_truth_audit['ai_dataset_total']                              ?? null;
$auditTargetPerRun       = $bot_demo_truth_audit['demo_closed_trades_target_per_run']             ?? null;
$auditTargetMet          = $bot_demo_truth_audit['demo_closed_trades_target_met']                 ?? null;
$auditTargetGap          = $bot_demo_truth_audit['demo_closed_trades_target_gap']                 ?? null;
?>
<?php if ($bot_mode === 'demo' && $demoSrcMode !== ''): ?>
<div class="card mb-4" style="border-color:#1e40af;">
    <div class="card-body">
        <div class="section-heading">Demo Pipeline Health</div>
        <?php if ($dlmEnabled !== null): ?>
        <div class="row g-2 mb-3">
            <?php
            $dlmCards = [
                ['label' => 'DLM Enabled',        'value' => $dlmEnabled ? 'YES' : 'NO',                                              'ok' => $dlmEnabled],
                ['label' => 'Max Signals/Run',     'value' => $dlmMaxSignals !== null ? (string)$dlmMaxSignals : 'n/a',               'ok' => ($dlmMaxSignals ?? 0) > 0 ? true : null],
                ['label' => 'Max Concurrent Pos',  'value' => $dlmMaxConcurrent !== null ? (string)$dlmMaxConcurrent : 'n/a',         'ok' => ($dlmMaxConcurrent ?? 0) > 0 ? true : null],
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
            <strong>Demo Learning Mode is disabled.</strong> Enable it in bot.json or via Settings to activate signal cap and stale-trade closure.
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <div class="row g-2 mb-2">
            <?php
            $srcCards = [
                ['label' => 'Source Mode',          'value' => htmlspecialchars($demoSrcMode),                       'ok' => null],
                ['label' => 'Storage Namespace',    'value' => $demoStorageNs,                                       'ok' => null],
                ['label' => 'Signals Loaded (PE)',  'value' => $demoSigLoaded >= 0 ? (string)$demoSigLoaded : 'n/a', 'ok' => null],
                ['label' => 'Signals Skipped (TTL/dup)', 'value' => $demoSigSkipped >= 0 ? (string)$demoSigSkipped : 'n/a', 'ok' => null],
                ['label' => 'Signals Attempted',   'value' => $demoSigAttempted !== null ? (string)$demoSigAttempted : 'n/a', 'ok' => null],
                ['label' => 'Signals Opened',      'value' => $demoSigOpened !== null ? (string)$demoSigOpened : 'n/a',       'ok' => $demoSigOpened > 0 ?: null],
                ['label' => 'Active Demo Positions','value' => (string)$demoActivePos,                                'ok' => null],
                ['label' => 'Blocked by Limits',   'value' => $demoSigBlockLimits !== null ? (string)$demoSigBlockLimits : 'n/a',   'ok' => $demoSigBlockLimits === 0 ? true : null],
                ['label' => 'Blocked Validation',  'value' => $demoSigBlockValid !== null ? (string)$demoSigBlockValid : 'n/a',     'ok' => null],
                ['label' => 'Blocked Exchange',    'value' => $demoSigBlockExchange !== null ? (string)$demoSigBlockExchange : 'n/a','ok' => $demoSigBlockExchange === 0 ? true : null],
                ['label' => 'Blocked Other',       'value' => $demoSigBlockOther !== null ? (string)$demoSigBlockOther : 'n/a',     'ok' => null],
                ['label' => 'Intents to Execute',  'value' => (string)$demoIntents,                                  'ok' => $demoIntents > 0],
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
            'execution_blocked_by_reconcile'       => 'Reconcile Failure (post-open position not found)',
            'execution_blocked_by_orphan_positions'=> 'Orphan Positions (no local trade for exchange position)',
            'execution_blocked_by_late_entry'      => 'Late Entry (price moved beyond threshold)',
            'execution_blocked_by_capacity'        => 'Capacity (max concurrent positions reached)',
            'execution_blocked_by_validation'      => 'Validation (intent field missing/invalid)',
            'execution_healthy_waiting_for_closure'=> 'Healthy — waiting for open positions to close',
            'none'                                 => 'None detected this run',
        ];
        $execBlockerText = $execBlockerLabel[$demoExecBlockerSpecific] ?? $demoExecBlockerSpecific;
        $hasExecData = ($demoBlockedByReconcile !== null || $demoBlockedByOrphan !== null);
        if ($hasExecData):
        ?>
        <div class="section-heading" style="font-size:.8rem;margin-top:.75rem;">Demo Execution Stage Diagnostics</div>
        <?php if ($demoExecBlockerSpecific !== '' && $demoExecBlockerSpecific !== 'none' && $demoExecBlockerSpecific !== 'execution_healthy_waiting_for_closure'): ?>
        <div class="alert alert-warning py-1 px-3 mb-2" style="font-size:.8rem;">
            <strong>Primary Execution Blocker:</strong> <?= htmlspecialchars($execBlockerText) ?>
        </div>
        <?php elseif ($demoExecBlockerSpecific === 'execution_healthy_waiting_for_closure'): ?>
        <div class="alert alert-success py-1 px-3 mb-2" style="font-size:.8rem;">
            <strong>Execution healthy</strong> — positions opened, waiting for closures.
        </div>
        <?php endif; ?>
        <div class="row g-2 mb-3">
            <?php
            $execStageCards = [
                ['label' => 'Feed Selected',         'value' => $demoFeedSelected !== null ? (string)$demoFeedSelected : 'n/a',
                    'ok' => ($demoFeedSelected ?? 0) > 0 ? true : null],
                ['label' => 'Attempted',             'value' => $demoSigAttempted !== null ? (string)$demoSigAttempted : 'n/a',
                    'ok' => null],
                ['label' => 'Opened',                'value' => $demoSigOpened !== null ? (string)$demoSigOpened : 'n/a',
                    'ok' => ($demoSigOpened ?? 0) > 0 ? true : null],
                ['label' => 'Blocked by Reconcile',  'value' => $demoBlockedByReconcile !== null ? (string)$demoBlockedByReconcile : 'n/a',
                    'ok' => $demoBlockedByReconcile === 0 ? true : ($demoBlockedByReconcile > 0 ? false : null)],
                ['label' => 'Blocked by Orphan',     'value' => $demoBlockedByOrphan !== null ? (string)$demoBlockedByOrphan : 'n/a',
                    'ok' => $demoBlockedByOrphan === 0 ? true : ($demoBlockedByOrphan > 0 ? false : null)],
                ['label' => 'Orphans Adopted',        'value' => $demoOrphansAdopted !== null ? (string)$demoOrphansAdopted : 'n/a',
                    'ok' => ($demoOrphansAdopted ?? 0) > 0 ? true : null],
                ['label' => 'Blocked Late Entry',    'value' => $demoBlockedByLateEntry !== null ? (string)$demoBlockedByLateEntry : 'n/a',
                    'ok' => $demoBlockedByLateEntry === 0 ? true : null],
                ['label' => 'Late Entry Near-Miss',  'value' => $lateEntryNearMiss !== null ? (string)$lateEntryNearMiss : 'n/a',
                    'ok' => null],
                ['label' => 'Late Entry Threshold',  'value' => $lateEntryThreshold !== null ? round($lateEntryThreshold, 2) . '%' : 'n/a',
                    'ok' => null],
                ['label' => 'Failed After Order',    'value' => $demoFailedAfterOrder !== null ? (string)$demoFailedAfterOrder : 'n/a',
                    'ok' => $demoFailedAfterOrder === 0 ? true : ($demoFailedAfterOrder > 0 ? false : null)],
                ['label' => 'Exec Blocker',          'value' => $demoExecBlockerSpecific ?: 'n/a',
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
        <div class="section-heading" style="font-size:.8rem;margin-top:.75rem;">Demo Intent Risk Limits</div>
        <div class="row g-2 mb-2">
            <?php
            $limitCards = [
                ['label' => 'Max Open Trades (intent)', 'value' => $demoEffRiskMaxOpen !== null ? (string)$demoEffRiskMaxOpen : 'n/a',
                    'ok' => ($demoEffRiskMaxOpen ?? 0) >= ($dlmMaxConcurrent ?? 0) ? true : ($demoEffRiskMaxOpen !== null ? false : null)],
                ['label' => 'Max/Symbol (intent)',      'value' => $demoEffRiskMaxPerSym !== null ? (string)$demoEffRiskMaxPerSym : 'n/a',
                    'ok' => null],
                ['label' => 'Limits Source',            'value' => $demoLimitsSource ?: 'n/a',
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
            <strong>Limit mismatch:</strong> intent max_open_trades (<?= (int)$demoEffRiskMaxOpen ?>) &lt; max_concurrent_demo_positions (<?= (int)$dlmMaxConcurrent ?>). Signals will be blocked by rejected_limits.
        </div>
        <?php endif; ?>
        <?php endif; // hasLimitData ?>
        <?php
        // ── Demo Effective Trailing / Break-even Proof ────────────────────
        if ($demoEffTrailingEnabled !== null):
        ?>
        <div class="section-heading" style="font-size:.8rem;margin-top:.75rem;">Demo Effective Trailing / Break-even</div>
        <div class="row g-2 mb-2">
            <?php
            $trailingCards = [
                ['label' => 'Trailing Enabled',    'value' => $demoEffTrailingEnabled  ? 'true' : 'false', 'ok' => $demoEffTrailingEnabled ? true : false],
                ['label' => 'Trailing Mode',       'value' => $demoEffTrailingMode ?: '(default)',          'ok' => null],
                ['label' => 'Activation ROI %',    'value' => $demoEffTrailingActivation !== null ? number_format((float)$demoEffTrailingActivation, 2) : 'n/a', 'ok' => null],
                ['label' => 'Drawdown Factor',     'value' => $demoEffTrailingDrawdown !== null ? number_format((float)$demoEffTrailingDrawdown, 4) : 'n/a',     'ok' => null],
                ['label' => 'Break-even Enabled',  'value' => $demoEffBreakEvenEnabled ? 'true' : 'false',  'ok' => $demoEffBreakEvenEnabled ? true : null],
                ['label' => 'Break-even Activ. %', 'value' => $demoEffBreakEvenActivation !== null ? number_format((float)$demoEffBreakEvenActivation, 2) : 'n/a', 'ok' => null],
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
        <div class="section-heading" style="font-size:.8rem;margin-top:.75rem;">Exchange vs Local Positions (Reconcile)</div>
        <div class="row g-2 mb-2">
            <?php
            $localActiveCount = $bot_demo_truth_audit['capacity_slots_used'] ?? null;
            $syncCards = [
                ['label' => 'Exchange Positions Synced', 'value' => $exchangePositionsSynced !== null ? (string)$exchangePositionsSynced : 'n/a', 'ok' => null],
                ['label' => 'Exchange Orders Synced',    'value' => $exchangeOrdersSynced !== null    ? (string)$exchangeOrdersSynced    : 'n/a', 'ok' => null],
                ['label' => 'Local Active Positions',    'value' => $localActiveCount !== null        ? (string)$localActiveCount        : 'n/a', 'ok' => null],
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
            <strong>Sync note:</strong> Exchange positions (<?= (int)$exchangePositionsSynced ?>) ≠ Local active (<?= (int)$localActiveCount ?>). Exchange orders and local positions are different concepts — orphans/pending orders may account for the difference.
        </div>
        <?php endif; ?>
        <?php endif; // exchange vs local ?>
        <?php
        // ── Demo Feed Pre-filter / Symbol Diversity ───────────────────────
        $hasPrefiltData = ($demoPrefiltInput !== null);
        if ($hasPrefiltData):
        ?>
        <div class="section-heading" style="font-size:.8rem;margin-top:.75rem;">Demo Feed Pre-filter / Symbol Diversity</div>
        <div class="row g-2 mb-2">
            <?php
            $prefiltCards = [
                ['label' => 'Prefilter Input',      'value' => $demoPrefiltInput !== null ? (string)$demoPrefiltInput : 'n/a',  'ok' => null],
                ['label' => 'Prefilter Output',     'value' => $demoPrefiltOutput !== null ? (string)$demoPrefiltOutput : 'n/a','ok' => ($demoPrefiltOutput ?? 0) > 0 ? true : null],
                ['label' => 'Unique Symbols Sel.',  'value' => $demoUniqueSymbols !== null ? (string)$demoUniqueSymbols : 'n/a','ok' => ($demoUniqueSymbols ?? 0) > 0 ? true : null],
                ['label' => 'Skipped Busy Symbol',  'value' => $demoPrefiltBusy !== null ? (string)$demoPrefiltBusy : 'n/a',    'ok' => $demoPrefiltBusy === 0 ? true : null],
                ['label' => 'Skipped Dup Symbol',   'value' => $demoPrefiltDup !== null ? (string)$demoPrefiltDup : 'n/a',      'ok' => null],
                ['label' => 'Exec: Symbol Busy',    'value' => $demoSkipSymBusy !== null ? (string)$demoSkipSymBusy : 'n/a',    'ok' => $demoSkipSymBusy === 0 ? true : null],
                ['label' => 'Exec: Late Entry',     'value' => $demoSkipLateEntry !== null ? (string)$demoSkipLateEntry : 'n/a','ok' => $demoSkipLateEntry === 0 ? true : null],
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
        <div class="section-heading" style="font-size:.8rem;margin-top:.75rem;">Demo Attempt / Open Budget (This Run)</div>
        <?php
        $stopReasonLabels = [
            'selected_feed_exhausted'      => 'All selected signals scanned (healthy)',
            'demo_open_budget_exhausted'   => 'Open budget exhausted (max_new_positions_per_run reached)',
            'demo_attempt_budget_exhausted'=> 'Attempt budget exhausted (max_demo_signals_per_run reached)',
            'fatal_exchange_blocker'       => 'Fatal exchange error / safety stop',
            'global_break_unexpected'      => 'Unexpected global break (deferred limit hit)',
        ];
        $stopLabel = $stopReasonLabels[$demoLoopStopReason] ?? ($demoLoopStopReason ?: 'n/a');
        $stopOk = ($demoLoopStopReason === 'selected_feed_exhausted' || $demoLoopStopReason === 'demo_open_budget_exhausted');
        $stopBad = in_array($demoLoopStopReason, ['fatal_exchange_blocker','global_break_unexpected']);
        ?>
        <div class="row g-2 mb-2">
            <?php
            $budgetCards = [
                ['label' => 'Attempt Budget',       'value' => $demoAttemptBudget !== null ? (string)$demoAttemptBudget : 'n/a',
                    'ok' => ($demoAttemptBudget ?? 0) > 1 ? true : null],
                ['label' => 'Open Budget',          'value' => $demoOpenBudget !== null ? (string)$demoOpenBudget : 'n/a',
                    'ok' => ($demoOpenBudget ?? 0) > 0 ? true : null],
                ['label' => 'Max New/Run (cfg)',    'value' => $dlmMaxNewPerRun !== null ? (string)$dlmMaxNewPerRun : 'n/a',
                    'ok' => null],
                ['label' => 'Selected Scanned',     'value' => $demoSelectedScanned !== null ? (string)$demoSelectedScanned : 'n/a',
                    'ok' => ($demoSelectedScanned ?? 0) > 0 ? true : null],
                ['label' => 'Skipped (non-fatal)',  'value' => $demoSkippedBefore !== null ? (string)$demoSkippedBefore : 'n/a',
                    'ok' => $demoSkippedBefore === 0 ? true : null],
                ['label' => 'Attempted',            'value' => $demoLoopAttempted !== null ? (string)$demoLoopAttempted : 'n/a',
                    'ok' => null],
                ['label' => 'Opened This Run',      'value' => $demoLoopOpened !== null ? (string)$demoLoopOpened : 'n/a',
                    'ok' => ($demoLoopOpened ?? 0) > 0 ? true : ($demoLoopOpened === 0 ? null : null)],
                ['label' => 'Loop Stop Reason',     'value' => $demoLoopStopReason ?: 'n/a',
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
            <strong>Loop stopped:</strong> <?= htmlspecialchars($stopLabel) ?>
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
            <strong>Selected (<?= $selectedCount ?>) &gt; Attempted (<?= $attemptedCount ?>):</strong>
            <?= $skippedCount ?> signal(s) were skipped (non-fatal) — symbol busy, idempotency, late entry, or validation rejects. Loop continued scanning through them.
        </div>
        <?php endif; ?>
        <?php endif; // hasBudgetData ?>
        <?php
        // ── Orphan Adoption Quality sub-section ──────────────────────────────
        $hasAdoptionData = ($orphanAdoptionAttempted !== null || $auditOrphanDeadShells !== null);
        if ($hasAdoptionData):
        ?>
        <div class="section-heading" style="font-size:.8rem;margin-top:.75rem;">Orphan Adoption Quality (This Run)</div>
        <?php if (($auditOrphanDeadShells ?? 0) > 0): ?>
        <div class="alert alert-danger py-1 px-3 mb-2" style="font-size:.8rem;">
            <strong>Dead Shells Detected:</strong> <?= (int)$auditOrphanDeadShells ?> adopted orphan trade(s) are missing entry_price or qty and cannot participate in close/AI pipeline.
        </div>
        <?php endif; ?>
        <div class="row g-2 mb-2">
            <?php
            $adoptCards = [
                ['label' => 'Adoption Attempted',   'value' => $orphanAdoptionAttempted !== null ? (string)$orphanAdoptionAttempted : 'n/a',
                    'ok' => null],
                ['label' => 'Adoption Succeeded',   'value' => $orphanAdoptionSucceeded !== null ? (string)$orphanAdoptionSucceeded : 'n/a',
                    'ok' => ($orphanAdoptionSucceeded ?? 0) > 0 ? true : null],
                ['label' => 'Adoption Failed',       'value' => $orphanAdoptionFailed !== null ? (string)$orphanAdoptionFailed : 'n/a',
                    'ok' => $orphanAdoptionFailed === 0 ? true : ($orphanAdoptionFailed > 0 ? false : null)],
                ['label' => 'Reusable Adopted',      'value' => $orphanAdoptionReusable !== null ? (string)$orphanAdoptionReusable : 'n/a',
                    'ok' => ($orphanAdoptionReusable ?? 0) > 0 ? true : null],
                ['label' => 'Active: Healthy',       'value' => $auditHealthyActive !== null ? (string)$auditHealthyActive : 'n/a',
                    'ok' => ($auditHealthyActive ?? 0) > 0 ? true : null],
                ['label' => 'Active: Orphan OK',     'value' => $auditOrphanAdopted !== null ? (string)$auditOrphanAdopted : 'n/a',
                    'ok' => null],
                ['label' => 'Active: Dead Shells',   'value' => $auditOrphanDeadShells !== null ? (string)$auditOrphanDeadShells : 'n/a',
                    'ok' => $auditOrphanDeadShells === 0 ? true : ($auditOrphanDeadShells > 0 ? false : null)],
                // Ownership resolution
                ['label' => 'Orphan→Local Owned',    'value' => $auditOrphanResolved !== null ? (string)$auditOrphanResolved : 'n/a',
                    'ok' => ($auditOrphanResolved ?? 0) > 0 ? true : null],
                ['label' => 'Orphan Unresolved',     'value' => $auditOrphanUnresolved !== null ? (string)$auditOrphanUnresolved : 'n/a',
                    'ok' => $auditOrphanUnresolved === 0 ? true : ($auditOrphanUnresolved > 0 ? false : null)],
                // This-run ownership resolution
                ['label' => 'Run: Resolved Local',   'value' => $orphanResolvedAsLocal !== null ? (string)$orphanResolvedAsLocal : 'n/a',
                    'ok' => ($orphanResolvedAsLocal ?? 0) > 0 ? true : null],
                ['label' => 'Run: Still Blocking',   'value' => $orphanStillBlocking !== null ? (string)$orphanStillBlocking : 'n/a',
                    'ok' => $orphanStillBlocking === 0 ? true : ($orphanStillBlocking > 0 ? false : null)],
                ['label' => 'Busy via Adopted',      'value' => $symbolsBusyAdopted !== null ? (string)$symbolsBusyAdopted : 'n/a',
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
        <div class="section-heading" style="font-size:.8rem;margin-top:.75rem;">Demo Close Pipeline (This Run)</div>
        <div class="row g-2 mb-2">
            <?php
            $closeCards = [
                ['label' => 'Active Before Run',    'value' => $demoTradesActiveBefore !== null ? (string)$demoTradesActiveBefore : 'n/a',  'ok' => null],
                ['label' => 'Opened This Run',      'value' => $demoTradesOpenedThisRun !== null ? (string)$demoTradesOpenedThisRun : 'n/a', 'ok' => $demoTradesOpenedThisRun > 0 ? true : null],
                ['label' => 'Closed This Run',      'value' => $demoTradesClosedThisRun !== null ? (string)$demoTradesClosedThisRun : 'n/a', 'ok' => $demoTradesClosedThisRun > 0 ? true : null],
                ['label' => 'Still Active After',   'value' => $demoTradesStillActive !== null ? (string)$demoTradesStillActive : 'n/a',     'ok' => null],
                ['label' => 'Stale Trades',         'value' => $demoTradesStaleThisRun !== null ? (string)$demoTradesStaleThisRun : 'n/a',   'ok' => $demoTradesStaleThisRun === 0 ? true : ($demoTradesStaleThisRun > 0 ? false : null)],
                ['label' => 'Reconciled',           'value' => $demoTradesReconciledThisRun !== null ? (string)$demoTradesReconciledThisRun : 'n/a', 'ok' => null],
                ['label' => 'Closed by Exchange',   'value' => $demoFinalizedExchange !== null ? (string)$demoFinalizedExchange : 'n/a',     'ok' => $demoFinalizedExchange > 0 ? true : null],
                ['label' => 'Closed Locally (SL)',  'value' => $demoFinalizedLocally !== null ? (string)$demoFinalizedLocally : 'n/a',       'ok' => $demoFinalizedLocally > 0 ? true : null],
                ['label' => 'Avg Active Age (min)', 'value' => $demoAvgAgeMinutes !== null ? (string)$demoAvgAgeMinutes : 'n/a',             'ok' => null],
                ['label' => 'Oldest Active (min)',  'value' => $demoOldestAgeMinutes !== null ? (string)$demoOldestAgeMinutes : 'n/a',       'ok' => null],
                ['label' => 'AI Records Written',   'value' => $demoAiWrittenThisRun !== null ? (string)$demoAiWrittenThisRun : 'n/a',       'ok' => $demoAiWrittenThisRun > 0 ? true : null],
                ['label' => 'Close Failures',       'value' => $demoCloseFailures !== null ? (string)$demoCloseFailures : 'n/a',             'ok' => $demoCloseFailures === 0 ? true : ($demoCloseFailures > 0 ? false : null)],
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
                <div class="section-heading" style="font-size:.75rem;">Close Failure Reasons (This Run)</div>
                <table class="table table-sm exec-table mb-0">
                    <thead><tr><th>Reason</th><th>Count</th></tr></thead>
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
                <div class="section-heading" style="font-size:.75rem;">Stale Trade Reasons (This Run)</div>
                <table class="table table-sm exec-table mb-0">
                    <thead><tr><th>Reason</th><th>Count</th></tr></thead>
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
        <div class="section-heading" style="font-size:.8rem;margin-top:.75rem;">Adopted Orphan Turnover</div>
        <?php if (($auditAdoptedOrphansStale ?? 0) > 0 && ($auditAdoptedOrphansClosedTotal ?? 0) === 0): ?>
        <div class="alert alert-warning py-1 px-3 mb-2" style="font-size:.8rem;">
            <strong>Stale Adopted Orphans:</strong> <?= (int)$auditAdoptedOrphansStale ?> adopted orphan trade(s) are stale but no closures yet. Verify <code>learning_close_timeout_minutes</code> is set and demo runs are cycling.
        </div>
        <?php elseif (($adoptedOrphansClosedThisRun ?? 0) > 0): ?>
        <div class="alert alert-success py-1 px-3 mb-2" style="font-size:.8rem;">
            <strong>Adopted Orphans Progressing:</strong> <?= (int)$adoptedOrphansClosedThisRun ?> adopted orphan trade(s) closed this run.
        </div>
        <?php endif; ?>
        <div class="row g-2 mb-2">
            <?php
            $aoCards = [
                ['label' => 'Active Before Run',     'value' => $adoptedOrphansActiveBefore !== null ? (string)$adoptedOrphansActiveBefore : 'n/a',
                    'ok' => null],
                ['label' => 'Stale (Audit)',          'value' => $auditAdoptedOrphansStale !== null ? (string)$auditAdoptedOrphansStale : 'n/a',
                    'ok' => $auditAdoptedOrphansStale === 0 ? true : ($auditAdoptedOrphansStale > 0 ? false : null)],
                ['label' => 'Closed This Run',        'value' => $adoptedOrphansClosedThisRun !== null ? (string)$adoptedOrphansClosedThisRun : 'n/a',
                    'ok' => ($adoptedOrphansClosedThisRun ?? 0) > 0 ? true : null],
                ['label' => 'Fin. via Exchange',      'value' => $adoptedOrphansFinalizedExchange !== null ? (string)$adoptedOrphansFinalizedExchange : 'n/a',
                    'ok' => ($adoptedOrphansFinalizedExchange ?? 0) > 0 ? true : null],
                ['label' => 'Fin. Locally (TO)',      'value' => $adoptedOrphansFinalizedLocally !== null ? (string)$adoptedOrphansFinalizedLocally : 'n/a',
                    'ok' => ($adoptedOrphansFinalizedLocally ?? 0) > 0 ? true : null],
                ['label' => 'Close Failures',         'value' => $adoptedOrphansCloseFailures !== null ? (string)$adoptedOrphansCloseFailures : 'n/a',
                    'ok' => $adoptedOrphansCloseFailures === 0 ? true : ($adoptedOrphansCloseFailures > 0 ? false : null)],
                ['label' => 'Closed Total (Audit)',   'value' => $auditAdoptedOrphansClosedTotal !== null ? (string)$auditAdoptedOrphansClosedTotal : 'n/a',
                    'ok' => ($auditAdoptedOrphansClosedTotal ?? 0) > 0 ? true : null],
                ['label' => 'Complete Rate (Audit)',  'value' => $auditAdoptedOrphansClosedCompleteRate !== null ? $auditAdoptedOrphansClosedCompleteRate . '%' : 'n/a',
                    'ok' => ($auditAdoptedOrphansClosedCompleteRate ?? 0) >= 80 ? true : (($auditAdoptedOrphansClosedTotal ?? 0) > 0 && ($auditAdoptedOrphansClosedCompleteRate ?? 0) < 50 ? false : null)],
                ['label' => 'Full Complete Rate',     'value' => $auditAdoptedOrphansClosedFullCompleteRate !== null ? $auditAdoptedOrphansClosedFullCompleteRate . '%' : 'n/a',
                    'ok' => ($auditAdoptedOrphansClosedFullCompleteRate ?? 0) >= 80 ? true : (($auditAdoptedOrphansClosedTotal ?? 0) > 0 && ($auditAdoptedOrphansClosedFullCompleteRate ?? 0) < 50 ? false : null)],
                ['label' => 'Without AI (Audit)',     'value' => $auditAdoptedOrphansWithoutAi !== null ? (string)$auditAdoptedOrphansWithoutAi : 'n/a',
                    'ok' => $auditAdoptedOrphansWithoutAi === 0 ? true : ($auditAdoptedOrphansWithoutAi > 0 ? false : null)],
                ['label' => 'Stale This Run',         'value' => $adoptedOrphansStaleThisRun !== null ? (string)$adoptedOrphansStaleThisRun : 'n/a',
                    'ok' => null],
                // Close quality counters (this run)
                ['label' => 'Complete (This Run)',    'value' => $adoptedOrphansClosedCompleteThisRun !== null ? (string)$adoptedOrphansClosedCompleteThisRun : 'n/a',
                    'ok' => ($adoptedOrphansClosedCompleteThisRun ?? 0) > 0 ? true : (($adoptedOrphansClosedThisRun ?? 0) > 0 && ($adoptedOrphansClosedCompleteThisRun ?? 0) === 0 ? false : null)],
                ['label' => 'AI Written (This Run)',  'value' => $adoptedOrphansAiWrittenThisRun !== null ? (string)$adoptedOrphansAiWrittenThisRun : 'n/a',
                    'ok' => ($adoptedOrphansAiWrittenThisRun ?? 0) > 0 ? true : (($adoptedOrphansClosedThisRun ?? 0) > 0 && ($adoptedOrphansAiWrittenThisRun ?? 0) === 0 ? false : null)],
                ['label' => 'Closed w/o AI (Run)',    'value' => $adoptedOrphansClosedNoAiThisRun !== null ? (string)$adoptedOrphansClosedNoAiThisRun : 'n/a',
                    'ok' => ($adoptedOrphansClosedNoAiThisRun ?? 0) === 0 ? true : (($adoptedOrphansClosedNoAiThisRun ?? 0) > 0 ? false : null)],
                ['label' => 'Repair Attempted',       'value' => $adoptedOrphansRepairAttempted !== null ? (string)$adoptedOrphansRepairAttempted : 'n/a',
                    'ok' => null],
                ['label' => 'Repair Succeeded',       'value' => $adoptedOrphansRepairSucceeded !== null ? (string)$adoptedOrphansRepairSucceeded : 'n/a',
                    'ok' => ($adoptedOrphansRepairAttempted ?? 0) > 0 ? (($adoptedOrphansRepairSucceeded ?? 0) === ($adoptedOrphansRepairAttempted ?? 0) ? true : null) : null],
                ['label' => 'Repair Failed',          'value' => $adoptedOrphansRepairFailed !== null ? (string)$adoptedOrphansRepairFailed : 'n/a',
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
            <div class="section-heading" style="font-size:.75rem;">Adopted Orphan Closed — Missing Field Counts (Audit)</div>
            <?php if ($orphanMfeMaeInflation): ?>
            <div class="alert alert-warning py-1 px-3 mb-2" style="font-size:.8rem;">
                <strong>Completeness Warning:</strong> Operational complete rate (<?= htmlspecialchars((string)$auditAdoptedOrphansClosedCompleteRate) ?>%) counts records as complete even though mfe/mae are missing. Full complete rate (requiring mfe+mae) is <?= htmlspecialchars((string)$auditAdoptedOrphansClosedFullCompleteRate) ?>%. Missing mfe: <?= (int)($auditOrphanMissingMfe ?? 0) ?>, missing mae: <?= (int)($auditOrphanMissingMae ?? 0) ?>.
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
            <div class="section-heading" style="font-size:.75rem;">Adopted Orphan Timing Health</div>
            <?php if (($adoptedOrphansMissingTiming ?? 0) > 0 || ($auditOrphanMissingTimingCount ?? 0) > 0): ?>
            <div class="alert alert-warning py-1 px-3 mb-2" style="font-size:.8rem;">
                <strong>Timing Warning:</strong> <?= (int)(max($adoptedOrphansMissingTiming ?? 0, $auditOrphanMissingTimingCount ?? 0)) ?> adopted orphan trade(s) lack a valid timing baseline. Age/stale/timeout logic may not fire for them. Check exchange <code>createdTime</code> availability.
            </div>
            <?php endif; ?>
            <div class="row g-2 mb-1">
            <?php
            $timingCards = [
                ['label' => 'Valid Timing (Run)',    'value' => $adoptedOrphansValidTiming !== null ? (string)$adoptedOrphansValidTiming : 'n/a',
                    'ok' => ($adoptedOrphansValidTiming ?? 0) > 0 ? true : (($adoptedOrphansMissingTiming ?? 0) > 0 ? false : null)],
                ['label' => 'Missing Timing (Run)',  'value' => $adoptedOrphansMissingTiming !== null ? (string)$adoptedOrphansMissingTiming : 'n/a',
                    'ok' => ($adoptedOrphansMissingTiming ?? 0) === 0 ? true : (($adoptedOrphansMissingTiming ?? 0) > 0 ? false : null)],
                ['label' => 'Stale Eligible (Run)',  'value' => $adoptedOrphansStaleEligible !== null ? (string)$adoptedOrphansStaleEligible : 'n/a',
                    'ok' => null],
                ['label' => 'TO Eligible (Run)',     'value' => $adoptedOrphansTimeoutEligible !== null ? (string)$adoptedOrphansTimeoutEligible : 'n/a',
                    'ok' => null],
                ['label' => 'Avg Age min (Run)',      'value' => $adoptedOrphansAvgAge !== null ? (string)$adoptedOrphansAvgAge . 'm' : 'n/a',
                    'ok' => null],
                ['label' => 'Oldest Age min (Run)',   'value' => $adoptedOrphansOldestAge !== null ? (string)$adoptedOrphansOldestAge . 'm' : 'n/a',
                    'ok' => null],
                ['label' => 'Valid Timing (Audit)',   'value' => $auditOrphanValidTiming !== null ? (string)$auditOrphanValidTiming : 'n/a',
                    'ok' => ($auditOrphanValidTiming ?? 0) > 0 ? true : null],
                ['label' => 'Missing Timing (Audit)','value' => $auditOrphanMissingTimingCount !== null ? (string)$auditOrphanMissingTimingCount : 'n/a',
                    'ok' => ($auditOrphanMissingTimingCount ?? 0) === 0 ? true : (($auditOrphanMissingTimingCount ?? 0) > 0 ? false : null)],
                ['label' => 'Avg Age min (Audit)',    'value' => $auditOrphanAvgAge !== null ? (string)$auditOrphanAvgAge . 'm' : 'n/a',
                    'ok' => null],
                ['label' => 'Oldest Age min (Audit)', 'value' => $auditOrphanOldestAge !== null ? (string)$auditOrphanOldestAge . 'm' : 'n/a',
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
            <div class="section-heading" style="font-size:.75rem;">Adopted Orphan Close Failure Reasons (This Run)</div>
            <table class="table table-sm exec-table mb-0" style="max-width:420px;">
                <thead><tr><th>Reason</th><th>Count</th></tr></thead>
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
            <div class="section-heading" style="font-size:.75rem;">Top Failure Reasons (This Run)</div>
            <table class="table table-sm exec-table mb-0" style="max-width:420px;">
                <thead><tr><th>Reason</th><th>Count</th></tr></thead>
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
            Source file: <code><?= htmlspecialchars($demoSrcPath) ?></code>
        </div>
        <?php endif; ?>

        <?php
        // ── Turnover Diagnostics sub-section (PART 7) ───────────────────────
        $hasTurnoverData = $demoFeedAvailable !== null || $demoCapAvail !== null
            || $demoStalePrioritized !== null || $demoClosedThisRun !== null
            || $demoClosureBottleneck !== '';
        ?>
        <?php if ($hasTurnoverData): ?>
        <div class="section-heading" style="font-size:.8rem;margin-top:.85rem;">Demo Turnover Diagnostics (This Run)</div>
        <div class="row g-2 mb-2">
            <?php
            $twCards = [
                ['label' => 'Feed Available',       'value' => $demoFeedAvailable !== null ? (string)$demoFeedAvailable : 'n/a', 'ok' => null],
                ['label' => 'Feed Selected',        'value' => $demoFeedSelected !== null ? (string)$demoFeedSelected : 'n/a',   'ok' => ($demoFeedSelected ?? 0) > 0 ? true : null],
                ['label' => 'Deferred by Cap',      'value' => $demoFeedCapSkip !== null ? (string)$demoFeedCapSkip : 'n/a',     'ok' => ($demoFeedCapSkip ?? 0) === 0 ? true : null],
                ['label' => 'Skipped Idempotency',  'value' => $demoFeedIdempSkip !== null ? (string)$demoFeedIdempSkip : 'n/a', 'ok' => null],
                ['label' => 'Rotation Mode',        'value' => $demoFeedRotMode !== '' ? htmlspecialchars($demoFeedRotMode) : 'n/a', 'ok' => null],
                ['label' => 'Deferred by Rotation', 'value' => $demoFeedDeferred !== null ? (string)$demoFeedDeferred : 'n/a',   'ok' => null],
                ['label' => 'Cap Available',        'value' => $demoCapAvail !== null ? ($demoCapAvail === -1 ? 'unlimited' : (string)$demoCapAvail) : 'n/a', 'ok' => null],
                ['label' => 'Cap Used',             'value' => $demoCapUsed !== null ? (string)$demoCapUsed : 'n/a',             'ok' => null],
                ['label' => 'Blocked by Cap',       'value' => $demoCapBlocked !== null ? (string)$demoCapBlocked : 'n/a',       'ok' => ($demoCapBlocked ?? 0) === 0 ? true : null],
                ['label' => 'Stale Prioritized',    'value' => $demoStalePrioritized !== null ? (string)$demoStalePrioritized : 'n/a', 'ok' => null],
                ['label' => 'Stale Finalized',      'value' => $demoStaleFinalized !== null ? (string)$demoStaleFinalized : 'n/a',     'ok' => ($demoStaleFinalized ?? 0) > 0 ? true : null],
                ['label' => 'Stale Remaining',      'value' => $demoStaleRemaining !== null ? (string)$demoStaleRemaining : 'n/a',     'ok' => ($demoStaleRemaining ?? 0) === 0 ? true : ($demoStaleRemaining > 3 ? false : null)],
                ['label' => 'Closed This Run',      'value' => $demoClosedThisRun !== null ? (string)$demoClosedThisRun : 'n/a', 'ok' => ($demoClosedThisRun ?? 0) > 0 ? true : null],
                ['label' => 'AI Written This Run',  'value' => $demoAiWrittenThisRun !== null ? (string)$demoAiWrittenThisRun : 'n/a', 'ok' => ($demoAiWrittenThisRun ?? 0) > 0 ? true : null],
                ['label' => 'Closed w/o AI (run)',  'value' => $demoClosedNoAiRun !== null ? (string)$demoClosedNoAiRun : 'n/a', 'ok' => ($demoClosedNoAiRun ?? 0) === 0 ? true : ($demoClosedNoAiRun > 0 ? false : null)],
                ['label' => 'AI Match Rate (run)',   'value' => $demoAiMatchRateRun !== null ? $demoAiMatchRateRun . '%' : 'n/a', 'ok' => ($demoAiMatchRateRun ?? 0) >= 100 ? true : (($demoAiMatchRateRun ?? 0) < 80 ? false : null)],
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
            <strong>Closure Bottleneck:</strong> <code><?= htmlspecialchars($demoClosureBottleneck) ?></code>
            <?php if ($demoClosureReason !== ''): ?>
            — <?= htmlspecialchars($demoClosureReason) ?>
            <?php endif; ?>
            <?php if ($demoTurnoverFix !== ''): ?>
            <br><strong>Fix Area:</strong> <code><?= htmlspecialchars($demoTurnoverFix) ?></code>
            <?php endif; ?>
        </div>
        <?php elseif ($demoClosureBottleneck === 'none_loop_is_cycling'): ?>
        <div class="alert alert-success py-1 px-3 mt-2 mb-0" style="font-size:.8rem;">
            <strong>Loop is cycling.</strong> <?= htmlspecialchars($demoClosureReason) ?>
        </div>
        <?php endif; ?>
        <?php if ($demoOrphanDetected !== null || $demoOrphanBlocking !== null): ?>
        <div class="section-heading" style="font-size:.8rem;margin-top:.85rem;">Orphan Exchange Position Diagnostics</div>
        <div class="row g-2 mb-2">
            <?php
            $orphanCards = [
                ['label' => 'Orphans Detected',    'value' => $demoOrphanDetected !== null ? (string)$demoOrphanDetected : 'n/a',  'ok' => ($demoOrphanDetected ?? 0) === 0 ? true : false],
                ['label' => 'Orphans Blocking',    'value' => $demoOrphanBlocking !== null ? (string)$demoOrphanBlocking : 'n/a',  'ok' => ($demoOrphanBlocking ?? 0) === 0 ? true : false],
                ['label' => 'Primary Exec Blocker','value' => $demoPrimaryExecBlocker !== '' ? htmlspecialchars($demoPrimaryExecBlocker) : 'none', 'ok' => ($demoPrimaryExecBlocker === '' || $demoPrimaryExecBlocker === 'none') ? true : false],
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
            <strong>Orphan Blocking:</strong> <?= (int)$demoOrphanBlocking ?> exchange position(s) are blocking new demo trades.
            These positions exist on the exchange but have no matching local trade record.
            Reconcile or finalize these orphan positions to unblock demo learning.
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
        <div class="section-heading" style="font-size:.8rem;margin-top:.85rem;">Capacity / Turnover</div>
        <div class="row g-2 mb-2">
            <?php
            $capSlotsSat = ($demoCapacityFullRun === true) ? false : ($demoCapacityFullRun === false ? true : null);
            $capCards = [
                ['label' => 'Capacity Full',         'value' => $demoCapacityFullRun !== null ? ($demoCapacityFullRun ? 'YES' : 'NO') : (($auditCapFull !== null) ? ($auditCapFull ? 'YES' : 'NO') : 'n/a'),
                 'ok' => $demoCapacityFullRun !== null ? !$demoCapacityFullRun : ($auditCapFull !== null ? !$auditCapFull : null)],
                ['label' => 'Slots Total',           'value' => ($demoCapSlotsTotalRun ?? $auditCapSlotsTotal) !== null ? (string)($demoCapSlotsTotalRun ?? $auditCapSlotsTotal) : 'n/a', 'ok' => null],
                ['label' => 'Slots Used (before)',   'value' => ($demoCapSlotsBeforeRun ?? $auditCapSlotsUsed) !== null ? (string)($demoCapSlotsBeforeRun ?? $auditCapSlotsUsed) : 'n/a', 'ok' => null],
                ['label' => 'Slots Freed This Run',  'value' => ($demoCapSlotsFreedRun ?? $auditCapSlotsFreed) !== null ? (string)($demoCapSlotsFreedRun ?? $auditCapSlotsFreed) : 'n/a',
                 'ok' => ($demoCapSlotsFreedRun ?? 0) > 0 ? true : (($demoCapacityFullRun === true && ($demoCapSlotsFreedRun ?? 0) === 0) ? false : null)],
                ['label' => 'Turnover Mode',         'value' => $demoTurnoverModeRun !== null ? ($demoTurnoverModeRun ? 'YES' : 'NO') : 'n/a',
                 'ok' => $demoTurnoverModeRun !== null ? $demoTurnoverModeRun : null],
                ['label' => 'Turnover Candidates',   'value' => ($demoTurnoverCandRun ?? $auditTurnoverCandCount) !== null ? (string)($demoTurnoverCandRun ?? $auditTurnoverCandCount) : 'n/a', 'ok' => null],
                ['label' => 'Turnover Processed',    'value' => $demoTurnoverProcRun !== null ? (string)$demoTurnoverProcRun : 'n/a', 'ok' => null],
                ['label' => 'Slots Freed by Pass',   'value' => $demoCapSlotsFreedRun !== null ? (string)$demoCapSlotsFreedRun : 'n/a',
                 'ok' => ($demoCapSlotsFreedRun ?? 0) > 0 ? true : (($demoTurnoverModeRun && ($demoCapSlotsFreedRun ?? 0) === 0) ? false : null)],
                ['label' => 'Recoverable Active',    'value' => $auditRecoverableCount !== null ? (string)$auditRecoverableCount : 'n/a',
                 'ok' => ($auditRecoverableCount ?? 0) > 0 ? null : true],
                ['label' => 'Turnover AI Written',   'value' => $demoTurnoverAiRun !== null ? (string)$demoTurnoverAiRun : 'n/a',
                 'ok' => ($demoTurnoverAiRun ?? 0) > 0 ? true : null],
                ['label' => 'Freed Cap This Run',    'value' => $demoTurnoverFreedRun !== null ? ($demoTurnoverFreedRun ? 'YES' : 'NO') : 'n/a',
                 'ok' => $demoTurnoverFreedRun !== null ? (bool)$demoTurnoverFreedRun : null],
                ['label' => 'Slots Used (after)',    'value' => $demoCapSlotsAfterRun !== null ? (string)$demoCapSlotsAfterRun : 'n/a', 'ok' => null],
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
            <strong>Turnover Block Reason:</strong> <code><?= htmlspecialchars($demoTurnoverBlockRun) ?></code>
        </div>
        <?php endif; ?>
        <?php if ($auditConsistencyOk === false && $auditConsistencyWarning !== ''): ?>
        <div class="alert alert-danger py-1 px-3 mt-1 mb-1" style="font-size:.8rem;">
            <strong>⚠ Capacity Consistency Warning:</strong> <?= htmlspecialchars($auditConsistencyWarning) ?>
        </div>
        <?php endif; ?>
        <?php if ($demoTurnoverModeRun && ($demoTurnoverCandStale !== null || $demoTurnoverCandTimeout !== null || $demoTurnoverCandDead !== null)): ?>
        <div class="mt-1 small text-muted">
            <strong>Candidate Breakdown:</strong>
            <?php if (($demoTurnoverCandDead ?? 0) > 0): ?><span class="badge bg-danger me-1">Dead Shells: <?= (int)$demoTurnoverCandDead ?></span><?php endif; ?>
            <?php if (($demoTurnoverCandTimeout ?? 0) > 0): ?><span class="badge bg-warning text-dark me-1">Timeout: <?= (int)$demoTurnoverCandTimeout ?></span><?php endif; ?>
            <?php if (($demoTurnoverCandStale ?? 0) > 0): ?><span class="badge bg-secondary me-1">Stale: <?= (int)$demoTurnoverCandStale ?></span><?php endif; ?>
            <?php if (($demoTurnoverCandFinElig ?? 0) > 0): ?><span class="badge bg-info text-dark me-1">Finalize-Eligible: <?= (int)$demoTurnoverCandFinElig ?></span><?php endif; ?>
            <?php if (($demoTurnoverCandOther ?? 0) > 0): ?><span class="badge bg-secondary me-1">Other: <?= (int)$demoTurnoverCandOther ?></span><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($demoTurnoverPriStats)): ?>
        <div class="mt-1 small text-muted">
            <strong>Priority Reasons:</strong>
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
        <div class="section-heading" style="font-size:.8rem;margin-top:.85rem;">Healthy Active Turnover</div>
        <div class="row g-2 mb-2">
            <?php
            $effHealthyActive  = $healthyActiveBefore ?? $auditHealthyActive;
            $effHealthyStale   = $healthyActiveStaleRun ?? $auditHealthyStale;
            $effHealthyTimeout = $healthyActiveTimeoutRun ?? $auditHealthyTimeout;
            $effClosedTotal    = $closedTotalRun ?? $auditClosedTotal;
            $effClosedHealthy  = $closedHealthyRun ?? $auditClosedHealthy;
            $effClosedOrphan   = $closedOrphanRun ?? $auditClosedOrphan;
            $effAiWritten      = $aiDatasetWrittenRun ?? $auditAiTotal;
            $effTargetPerRun   = $targetPerRun ?? $auditTargetPerRun;
            $effTargetMet      = $targetMet ?? $auditTargetMet;
            $effTargetGap      = $targetGap ?? $auditTargetGap;
            $htCards = [
                ['label' => 'Healthy Active',         'value' => $effHealthyActive !== null ? (string)$effHealthyActive : 'n/a', 'ok' => null],
                ['label' => 'Healthy Stale',          'value' => $effHealthyStale !== null ? (string)$effHealthyStale : 'n/a',
                 'ok' => ($effHealthyStale ?? 0) > 0 ? false : (($effHealthyActive ?? 0) > 0 ? true : null)],
                ['label' => 'Timeout Eligible',       'value' => $effHealthyTimeout !== null ? (string)$effHealthyTimeout : 'n/a',
                 'ok' => ($effHealthyTimeout ?? 0) > 0 ? null : true],
                ['label' => 'Closed (Total)',         'value' => $effClosedTotal !== null ? (string)$effClosedTotal : 'n/a',
                 'ok' => ($effClosedTotal ?? 0) > 0 ? true : null],
                ['label' => 'Closed (Healthy)',       'value' => $effClosedHealthy !== null ? (string)$effClosedHealthy : 'n/a',
                 'ok' => ($effClosedHealthy ?? 0) > 0 ? true : null],
                ['label' => 'Closed (Orphan)',        'value' => $effClosedOrphan !== null ? (string)$effClosedOrphan : 'n/a', 'ok' => null],
                ['label' => 'AI Dataset Written',     'value' => $effAiWritten !== null ? (string)$effAiWritten : 'n/a',
                 'ok' => ($effAiWritten ?? 0) > 0 ? true : null],
                ['label' => 'Healthy Fail',           'value' => $healthyActiveFailRun !== null ? (string)$healthyActiveFailRun : 'n/a',
                 'ok' => ($healthyActiveFailRun ?? 0) > 0 ? false : ($healthyActiveClosedRun !== null ? true : null)],
                ['label' => 'Target / Run',           'value' => $effTargetPerRun !== null ? (string)$effTargetPerRun : 'n/a', 'ok' => null],
                ['label' => 'Target Met',             'value' => $effTargetMet !== null ? ($effTargetMet ? 'YES' : 'NO') : 'n/a',
                 'ok' => $effTargetMet !== null ? (bool)$effTargetMet : null],
                ['label' => 'Target Gap',             'value' => $effTargetGap !== null ? (string)$effTargetGap : 'n/a',
                 'ok' => ($effTargetGap ?? 0) === 0 ? true : (($effTargetGap ?? 0) > 0 ? false : null)],
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
            <strong>Healthy Close Failures:</strong>
            <?php foreach ($healthyActiveFailRsns as $fr => $fc): ?>
            <span class="badge bg-warning text-dark me-1"><?= htmlspecialchars($fr) ?>: <?= (int)$fc ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endif; // $hasHealthyTurnoverData ?>
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
            Demo Data Readiness
            <?php if ($aiReady): ?>
            <span class="badge bg-success ms-2" style="font-size:.65rem;">READY</span>
            <?php else: ?>
            <span class="badge bg-secondary ms-2" style="font-size:.65rem;">BUILDING</span>
            <?php endif; ?>
        </div>
        <div class="row g-2 mb-2">
            <?php
            $nextMs = $nextMilestone !== null ? $demoClosedTotal . '/' . $nextMilestone : ($demoClosedTotal . ' (done)');
            $readCards = [
                ['label' => 'Closed Trades',        'value' => (string)$demoClosedTotal,
                 'ok' => $demoClosedTotal >= $aiMinSamples ? true : null],
                ['label' => 'Complete Records',     'value' => (string)$demoClosedComplete,
                 'ok' => null],
                ['label' => 'Completeness Rate',    'value' => $demoClosedTotal > 0 ? $demoCompleteRate . '%' : 'n/a',
                 'ok' => $demoCompleteRate >= 80 ? true : ($demoClosedTotal > 0 ? false : null)],
                ['label' => 'Active Trades',        'value' => (string)$demoActiveCount2, 'ok' => null],
                ['label' => 'AI Dataset Records',   'value' => (string)$aiDatasetRecords, 'ok' => null],
                ['label' => 'AI Records Complete',  'value' => (string)$aiDatasetComplete, 'ok' => null],
                ['label' => 'AI Ready',              'value' => $aiReady ? 'YES' : 'NO (' . $demoClosedTotal . '/' . $aiMinSamples . ')',
                 'ok' => $aiReady],
                ['label' => 'Next Milestone',       'value' => $nextMs, 'ok' => null],
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
                <div class="section-heading" style="font-size:.75rem;">Closed by Pattern</div>
                <table class="table table-sm exec-table mb-0">
                    <thead><tr><th>Pattern</th><th>Trades</th></tr></thead>
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
                <div class="section-heading" style="font-size:.75rem;">Closed by Side</div>
                <table class="table table-sm exec-table mb-0">
                    <thead><tr><th>Side</th><th>Trades</th></tr></thead>
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
                <div class="section-heading" style="font-size:.75rem;">Close Reason Distribution</div>
                <table class="table table-sm exec-table mb-0">
                    <thead><tr><th>Reason</th><th>Count</th></tr></thead>
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
                <div class="section-heading" style="font-size:.75rem;">Top Symbols by Demo Evidence</div>
                <table class="table table-sm exec-table mb-0">
                    <thead><tr><th>Symbol</th><th>Total</th><th>Complete</th></tr></thead>
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
        <div class="mt-2 small text-muted">Last computed: <?= htmlspecialchars($demoSuffAt) ?></div>
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
        <div class="section-heading">Demo Velocity &amp; Dataset Growth</div>
        <div class="row g-2 mb-2">
            <?php
            $velCards = [
                ['label' => 'Opened This Run',       'value' => $demoTradesOpenedThisRun !== null ? (string)$demoTradesOpenedThisRun : 'n/a',  'ok' => null],
                ['label' => 'Closed This Run',        'value' => $demoTradesClosedThisRun !== null ? (string)$demoTradesClosedThisRun : 'n/a',  'ok' => $demoTradesClosedThisRun > 0 ? true : null],
                ['label' => 'AI Records This Run',    'value' => $demoAiWrittenThisRun !== null ? (string)$demoAiWrittenThisRun : 'n/a',         'ok' => $demoAiWrittenThisRun > 0 ? true : null],
                ['label' => 'Total Closed (All Time)','value' => (string)$demoClosedTotal,                                                       'ok' => $demoClosedTotal >= 50 ? true : null],
                ['label' => 'AI Dataset Total',       'value' => (string)$aiDatasetRecords,                                                      'ok' => null],
                ['label' => 'Next Milestone',         'value' => $nextMilestone !== null ? $demoClosedTotal . '/' . $nextMilestone : $demoClosedTotal . ' ✓', 'ok' => $nextMilestone === null ? true : null],
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
                <div class="section-heading" style="font-size:.75rem;">Top Patterns by Closed Trades</div>
                <table class="table table-sm exec-table mb-0">
                    <thead><tr><th>Pattern</th><th>Closed</th></tr></thead>
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
                <div class="section-heading" style="font-size:.75rem;">Top Symbols by Closed Trades</div>
                <table class="table table-sm exec-table mb-0">
                    <thead><tr><th>Symbol</th><th>Total</th><th>Complete</th></tr></thead>
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
        <div class="section-heading">Demo Truth Audit <span class="badge bg-secondary ms-2" style="font-size:.6rem;">FROM STORAGE</span></div>
        <div class="row g-2 mb-2">
            <?php
            $auditCards = [
                ['label' => 'Active Trades',         'value' => $auditActive !== null ? (string)$auditActive : 'n/a',          'ok' => null],
                ['label' => 'Closed Trades',          'value' => $auditClosed !== null ? (string)$auditClosed : 'n/a',          'ok' => $auditClosed > 0 ? true : null],
                ['label' => 'AI Dataset Records',     'value' => $auditAiDataset !== null ? (string)$auditAiDataset : 'n/a',    'ok' => null],
                ['label' => 'Oldest Active (min)',    'value' => $auditOldestAge !== null ? (string)$auditOldestAge : 'n/a',    'ok' => null],
                ['label' => 'Stale Active',           'value' => $auditStaleCount !== null ? $auditStaleCount . ' (' . $auditPctStale . '%)' : 'n/a', 'ok' => ($auditPctStale ?? 0) < 30 ? true : (($auditPctStale ?? 0) > 60 ? false : null)],
                ['label' => 'Basic Complete Rate',    'value' => $auditCompleteRate !== null ? $auditCompleteRate . '%' : 'n/a', 'ok' => ($auditCompleteRate ?? 0) >= 80 ? true : ($auditCompleteRate !== null && $auditClosed > 3 ? false : null)],
                ['label' => 'Full Complete Rate',     'value' => $auditFullCompleteRate !== null ? $auditFullCompleteRate . '%' : 'n/a', 'ok' => ($auditFullCompleteRate ?? 0) >= 60 ? true : ($auditFullCompleteRate !== null && $auditClosed > 3 ? false : null)],
                ['label' => 'AI Dataset Match',      'value' => $auditMatchRate !== null ? $auditMatchRate . '%' : 'n/a',       'ok' => ($auditMatchRate ?? 0) >= 90 ? true : ($auditMatchRate !== null && $auditClosed > 0 ? false : null)],
                ['label' => 'Closed w/o AI Record',  'value' => $auditClosedNoAi !== null ? (string)$auditClosedNoAi : 'n/a',  'ok' => $auditClosedNoAi === 0 ? true : ($auditClosedNoAi > 0 ? false : null)],
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
                ['label' => 'Missing MFE %',       'value' => $auditMissingMfe !== null ? $auditMissingMfe . '%' : 'n/a',    'ok' => $auditMissingMfe === 0.0 ? true : ($auditMissingMfe !== null && $auditMissingMfe > 20 ? false : null)],
                ['label' => 'Missing MAE %',       'value' => $auditMissingMae !== null ? $auditMissingMae . '%' : 'n/a',    'ok' => $auditMissingMae === 0.0 ? true : ($auditMissingMae !== null && $auditMissingMae > 20 ? false : null)],
                ['label' => 'Missing MFE (count)', 'value' => $auditMissingMfeCount !== null ? (string)$auditMissingMfeCount : 'n/a', 'ok' => $auditMissingMfeCount === 0 ? true : ($auditMissingMfeCount > 0 ? false : null)],
                ['label' => 'Missing MAE (count)', 'value' => $auditMissingMaeCount !== null ? (string)$auditMissingMaeCount : 'n/a', 'ok' => $auditMissingMaeCount === 0 ? true : ($auditMissingMaeCount > 0 ? false : null)],
                ['label' => 'Missing Hold %',      'value' => $auditMissingHold !== null ? $auditMissingHold . '%' : 'n/a',  'ok' => $auditMissingHold === 0.0 ? true : ($auditMissingHold !== null && $auditMissingHold > 20 ? false : null)],
                ['label' => 'Missing Reason %',    'value' => $auditMissingReason !== null ? $auditMissingReason . '%' : 'n/a', 'ok' => $auditMissingReason === 0.0 ? true : ($auditMissingReason !== null && $auditMissingReason > 10 ? false : null)],
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
            <strong>MFE/MAE Gap:</strong> Basic complete rate is <?= $auditCompleteRate ?>% but full complete rate (with MFE+MAE) is only <?= $auditFullCompleteRate ?>%.
            <?= $auditMissingMfeCount ?> trades missing MFE, <?= $auditMissingMaeCount ?> missing MAE.
            Active demo trades are not yet accumulating MFE/MAE runtime evidence — trades may be closing too quickly, or price tracking started too recently.
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
            <strong>Bottleneck:</strong> <code><?= htmlspecialchars($auditBottleneck) ?></code><br>
            <?= htmlspecialchars($auditBottleneckReason) ?>
            <?php if ($auditNextFix !== '' && $auditBottleneck !== 'none_loop_is_cycling'): ?>
            <br><strong>Next Fix:</strong> <code><?= htmlspecialchars($auditNextFix) ?></code>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($auditOrphanDetected !== null): ?>
        <div class="row g-2 mb-2">
            <?php
            $orphanAuditCards = [
                ['label' => 'Orphans Blocking (run)', 'value' => (string)($auditOrphanDetected ?? 0), 'ok' => ($auditOrphanDetected ?? 0) === 0 ? true : false],
                ['label' => 'Orphan→Local Resolved',  'value' => $auditOrphanResolved !== null ? (string)$auditOrphanResolved : 'n/a', 'ok' => ($auditOrphanResolved ?? 0) > 0 ? true : null],
                ['label' => 'Orphan Unresolved',      'value' => $auditOrphanUnresolved !== null ? (string)$auditOrphanUnresolved : 'n/a', 'ok' => $auditOrphanUnresolved === 0 ? true : ($auditOrphanUnresolved > 0 ? false : null)],
                ['label' => 'Primary Exec Blocker',   'value' => $auditExecBlocker !== '' ? htmlspecialchars($auditExecBlocker) : 'none', 'ok' => ($auditExecBlocker === '' || $auditExecBlocker === 'none') ? true : false],
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
        <div class="mt-1 small text-muted">Audited from storage: <?= htmlspecialchars($auditAt) ?></div>
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
                    <span class="badge ms-1" style="background:#166534; font-size:0.65rem;">target met</span>
                <?php else: ?>
                    <span class="badge ms-1" style="background:#7f1d1d; font-size:0.65rem;">starved — <?= $peFeedBelow ?> below min</span>
                <?php endif; ?>
            </div>
            <div class="row g-1 mb-1">
                <div class="col-4 col-md-2">
                    <div class="small" style="background:#1e293b; padding:0.2rem 0.4rem; border-radius:4px;">
                        <span class="text-secondary">Exported:</span>
                        <span class="<?= $peFeedMet ? 'text-success' : 'text-danger' ?> ms-1 fw-bold"><?= $peFeedExport ?></span>
                    </div>
                </div>
                <div class="col-4 col-md-2">
                    <div class="small" style="background:#1e293b; padding:0.2rem 0.4rem; border-radius:4px;">
                        <span class="text-secondary">Target min:</span>
                        <span class="text-info ms-1"><?= $peFeedTarget ?></span>
                    </div>
                </div>
                <div class="col-4 col-md-2">
                    <div class="small" style="background:#1e293b; padding:0.2rem 0.4rem; border-radius:4px;">
                        <span class="text-secondary">Soft max:</span>
                        <span class="text-secondary ms-1"><?= $peFeedMax ?></span>
                    </div>
                </div>
                <div class="col-4 col-md-2">
                    <div class="small" style="background:#1e293b; padding:0.2rem 0.4rem; border-radius:4px;">
                        <span class="text-secondary">Candidates:</span>
                        <span class="text-secondary ms-1"><?= $peCandTotal ?></span>
                    </div>
                </div>
            </div>
            <?php if (!$peFeedMet && $peFeedBlock !== ''): ?>
            <div class="small text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Top block: <code><?= htmlspecialchars($peFeedBlock) ?></code></div>
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
            <div class="mt-1 small text-muted">PE last run: <?= htmlspecialchars(date('d M H:i', strtotime($peRunAt))) ?></div>
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
        <div class="section-heading">Stats</div>
        <div class="row g-2">
        <?php
        $statMap = [
            'total_trades'        => 'Total Trades',
            'win_rate'            => 'Win Rate',
            'avg_roi'             => 'Avg ROI',
            'expectancy'          => 'Expectancy',
            'stop_hit_rate'       => 'Stop Hit Rate',
            'tp_hit_rate'         => 'TP Hit Rate',
            'trailing_close_rate' => 'Trailing Close',
            'total_pnl'           => 'Total PnL',
            'wins'                => 'Wins',
            'losses'              => 'Losses',
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
            <div class="section-heading" style="margin-top:.5rem;">Per-Symbol Stats</div>
            <div class="table-responsive">
                <table class="table table-dark table-sm exec-table mb-0">
                    <thead><tr><th>Symbol</th><th>Trades</th><th>Win Rate</th><th>Avg ROI</th><th>Total PnL</th></tr></thead>
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
            <div class="section-heading">Per-Pattern Stats</div>
            <div class="table-responsive">
                <table class="table table-dark table-sm exec-table mb-0">
                    <thead><tr><th>Pattern</th><th>Trades</th><th>Win Rate</th><th>Avg ROI</th></tr></thead>
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
        <div class="section-heading">Active Positions (<?= $activeCount ?>)</div>
        <?php if (empty($bot_active_trades)): ?>
            <p class="text-muted mb-0">No active positions.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-dark table-sm exec-table mb-0">
                <thead>
                    <tr><th>Symbol</th><th>Side</th><th>Entry</th><th>Mark</th><th>ROI%</th><th>PnL</th><th>SL</th><th>MFE%</th><th>Since</th></tr>
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
        <div class="section-heading">Recent Closed Trades (last <?= count($bot_closed_trades) ?>)</div>
        <?php if (empty($bot_closed_trades)): ?>
            <p class="text-muted mb-0">No closed trades found.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-dark table-sm exec-table mb-0">
                <thead>
                    <tr><th>Symbol</th><th>Side</th><th>Pattern</th><th>Entry</th><th>Exit</th><th>ROI%</th><th>PnL</th><th>Reason</th><th>Hold</th><th>MFE%</th></tr>
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

<!-- ===== Full Settings ===== -->
<div class="card mb-4">
    <div class="card-body">
        <div class="section-heading">Bot Settings <small class="text-muted fw-normal text-lowercase">(saved to config/bot.json)</small></div>
        <form id="settings-form">

        <!-- 1. MODE -->
        <div class="settings-block">
            <h6><i class="bi bi-toggles me-1"></i> Mode</h6>
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label form-label-sm">Mode</label>
                    <select name="mode" class="form-select form-select-sm bg-dark text-light border-secondary"
                            onchange="onModeChange(this)">
                        <option value="demo"  <?= $bot_mode === 'demo'  ? 'selected' : '' ?>>Demo (Bybit Sandbox)</option>
                        <option value="live"  <?= $bot_mode === 'live'  ? 'selected' : '' ?>>Live (Real Exchange — DANGEROUS)</option>
                        <option value="paper" <?= ($bot_mode === 'paper' || $bot_mode === 'dry') ? 'selected' : '' ?>>Paper (local simulation)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <div class="form-check mt-4">
                        <input type="checkbox" class="form-check-input" name="enabled" id="chk_enabled"
                            <?= $bot_enabled ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="chk_enabled">Bot Enabled</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm">Account ID (live)</label>
                    <input type="text" name="account_id" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= htmlspecialchars((string)($modeCfg['account_id'] ?? $bot_config['account_id'] ?? 'trading_bot')) ?>">
                    <small class="text-muted">KeyCenter account for LIVE mode.</small>
                </div>
            </div>
        </div>

        <!-- 2. DEMO CREDENTIALS -->
        <div class="settings-block" id="section-demo-creds" style="<?= $bot_mode !== 'demo' ? 'display:none' : '' ?>">
            <h6><i class="bi bi-key me-1"></i> Demo Credentials <span class="text-muted fw-normal text-lowercase">(stored locally in bot config; not KeyCenter)</span></h6>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label form-label-sm">Demo API Key</label>
                    <input type="text" name="demo_api_key" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= htmlspecialchars((string)($demoCreds['api_key'] ?? '')) ?>"
                           placeholder="Enter Bybit Demo API Key">
                    <?php if ($demoKeySet): ?>
                        <small class="text-success"><i class="bi bi-check-circle me-1"></i>Key configured.</small>
                    <?php else: ?>
                        <small class="text-warning">Not configured.</small>
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label form-label-sm">Demo API Secret</label>
                    <input type="password" name="demo_api_secret" class="form-control form-control-sm bg-dark text-light border-secondary"
                           placeholder="<?= $demoSecretSet ? '(secret set — leave blank to keep)' : 'Enter Bybit Demo API Secret' ?>">
                    <?php if ($demoSecretSet): ?>
                        <small class="text-success"><i class="bi bi-check-circle me-1"></i>Secret configured. Leave blank to preserve.</small>
                    <?php else: ?>
                        <small class="text-warning">Not configured.</small>
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
            <h6><i class="bi bi-lightning-charge me-1"></i> Live Config <span class="text-muted fw-normal text-lowercase">(credentials from KeyCenter)</span></h6>
            <div class="row g-3">
                <div class="col-12">
                    <div class="alert alert-danger py-2 mb-0 small">
                        <strong>LIVE mode is active.</strong> Credentials for live trading are managed in
                        <a href="/admin/keys" class="alert-link">KeyCenter</a> under account ID
                        <strong><?= htmlspecialchars((string)($modeCfg['account_id'] ?? 'trading_bot')) ?></strong>.
                        Do not enter raw API keys here for live mode.
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
            <h6><i class="bi bi-archive me-1"></i> Paper Mode <span class="text-muted fw-normal text-lowercase">(local simulation, no exchange)</span></h6>
            <p class="text-muted small mb-0">Paper/dry mode runs a local simulation. No exchange connection is made. Storage namespace: <strong>storage_paper</strong>.</p>
        </div>

        <!-- 4. EXECUTION SETTINGS -->
        <div class="settings-block">
            <h6><i class="bi bi-sliders me-1"></i> Execution Settings</h6>
            <div class="row g-3">
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Max Positions</label>
                    <input type="number" name="max_positions" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= (int)($bot_config['max_positions'] ?? $modeCfg['max_concurrent_positions'] ?? 3) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Leverage Default</label>
                    <input type="number" name="leverage_default" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= (int)($exchCfg['leverage'] ?? $bot_config['leverage_default'] ?? 5) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Stop Loss %</label>
                    <input type="number" step="0.01" name="stop_loss_pct" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= round((float)($exCfg['stop_loss_pct'] ?? $bot_config['stop_loss_pct'] ?? 2.0), 2) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Take Profit %</label>
                    <input type="number" step="0.01" name="take_profit_pct" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= round((float)($exCfg['take_profit_pct'] ?? $bot_config['take_profit_pct'] ?? 5.0), 2) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Order Type</label>
                    <select name="order_type" class="form-select form-select-sm bg-dark text-light border-secondary">
                        <?php foreach (['Market', 'Limit'] as $ot): ?>
                        <option value="<?= $ot ?>" <?= ($exCfg['order_type'] ?? 'Market') === $ot ? 'selected' : '' ?>><?= $ot ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">EM. Stop Loss %</label>
                    <input type="number" step="0.01" name="emergency_stop_loss_pct" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= round((float)($exCfg['emergency_stop_loss_pct'] ?? $bot_config['emergency_stop_loss_pct'] ?? 5.0), 2) ?>">
                </div>
            </div>
            <div class="d-flex flex-wrap gap-4 mt-3">
                <?php
                $execChecks = [
                    ['name' => 'reconcile_before_action',   'label' => 'Reconcile Before Run',  'val' => (bool)($bot_config['reconcile_before_action'] ?? false)],
                    ['name' => 'execution_reverse_side_enabled', 'label' => 'Reverse Side',     'val' => (bool)($exCfg['reverse_side_enabled'] ?? false)],
                    ['name' => 'execution_trailing_enabled','label' => 'Trailing',               'val' => (bool)($exCfg['trailing_enabled'] ?? false)],
                    ['name' => 'execution_break_even_enabled','label' => 'Break Even',           'val' => (bool)($exCfg['break_even_enabled'] ?? false)],
                    ['name' => 'execution_emergency_stop_enabled','label' => 'Emergency Stop',   'val' => (bool)($exCfg['emergency_stop_enabled'] ?? false)],
                ];
                foreach ($execChecks as $ch): ?>
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" name="<?= $ch['name'] ?>" id="chk_<?= $ch['name'] ?>"
                        <?= $ch['val'] ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="chk_<?= $ch['name'] ?>"><?= htmlspecialchars($ch['label']) ?> <span style="font-family:monospace;font-size:10px;color:<?= $ch['val'] ? '#22c55e' : '#ef4444' ?>">(render=<?= $ch['val'] ? 'true' : 'false' ?>)</span></label>
                </div>
                <?php endforeach; ?>
            </div>
            <!-- Trailing sub-fields -->
            <div class="row g-3 mt-1">
                <div class="col-md-3">
                    <label class="form-label form-label-sm">Trailing Mode</label>
                    <input type="text" name="trailing_mode" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= htmlspecialchars((string)($exCfg['trailing_mode'] ?? '')) ?>"
                           placeholder="e.g. step_roi">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Trailing Activation ROI</label>
                    <input type="number" step="0.1" name="trailing_activation_roi" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= round((float)($exCfg['trailing_activation_roi'] ?? 0), 2) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Trailing Drawdown Factor</label>
                    <input type="number" step="0.01" name="trailing_drawdown_factor" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= round((float)($exCfg['trailing_drawdown_factor'] ?? 0), 3) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Break Even Activation ROI</label>
                    <input type="number" step="0.1" name="break_even_activation_roi" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= round((float)($exCfg['break_even_activation_roi'] ?? 0), 2) ?>">
                </div>
            </div>
        </div>

        <!-- 5. SOURCE SETTINGS -->
        <div class="settings-block">
            <h6><i class="bi bi-broadcast me-1"></i> Source Settings</h6>
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="sources_brain_source_enabled" id="chk_brain_src"
                            <?= !empty($srcCfg['brain_source_enabled']) ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="chk_brain_src">Brain Source Enabled <?php $_bsv = !empty($srcCfg['brain_source_enabled']); ?><span style="font-family:monospace;font-size:10px;color:<?= $_bsv ? '#22c55e' : '#ef4444' ?>">(render=<?= $_bsv ? 'true' : 'false' ?>)</span></label>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label form-label-sm">Signals File</label>
                    <input type="text" name="sources_signals_file" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= htmlspecialchars((string)($srcCfg['signals_file'] ?? 'signals.json')) ?>">
                </div>
            </div>
        </div>

        <!-- 6. EXCHANGE / RUNTIME SETTINGS -->
        <div class="settings-block">
            <h6><i class="bi bi-hdd-stack me-1"></i> Exchange / Runtime Settings</h6>
            <div class="row g-3">
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Category</label>
                    <input type="text" name="exchange_category" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= htmlspecialchars((string)($exchCfg['category'] ?? 'linear')) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Account Type</label>
                    <input type="text" name="exchange_account_type" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= htmlspecialchars((string)($exchCfg['account_type'] ?? 'UNIFIED')) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Settle Coin</label>
                    <input type="text" name="exchange_settle_coin" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= htmlspecialchars((string)($exchCfg['settle_coin'] ?? 'USDT')) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">TPSL Mode</label>
                    <input type="text" name="exchange_tpsl_mode" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= htmlspecialchars((string)($exchCfg['tpsl_mode'] ?? 'Full')) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">SL Trigger By</label>
                    <input type="text" name="exchange_sl_trigger_by" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= htmlspecialchars((string)($exchCfg['sl_trigger_by'] ?? 'IndexPrice')) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label form-label-sm">Position IDX</label>
                    <input type="number" name="exchange_position_idx" class="form-control form-control-sm bg-dark text-light border-secondary"
                           value="<?= (int)($exchCfg['position_idx'] ?? 0) ?>">
                </div>
            </div>
        </div>

        <div class="mt-2">
            <button type="button" class="btn btn-primary btn-sm" onclick="saveSettings()">
                <i class="bi bi-floppy me-1"></i> Save Settings
            </button>
            <small class="text-muted ms-2">Saves to bot <code>config/bot.json</code>. Execution logic is not affected — only config is written.</small>
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
