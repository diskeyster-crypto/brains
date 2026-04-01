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
                    $mfe = (float)($t['mfe_roi'] ?? $t['mfe'] ?? 0);
                    $holdSec = (int)($t['hold_seconds'] ?? $t['hold_time'] ?? 0);
                    $holdStr = $holdSec > 0 ? gmdate('H:i:s', $holdSec) : '—';
                    $rc = $roi >= 0 ? 'positive' : 'negative';
                    $pc = $pnl >= 0 ? 'positive' : 'negative';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string)($t['symbol'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars((string)($t['side'] ?? '—')) ?></td>
                        <td><small><?= htmlspecialchars((string)($t['pattern_algorithm'] ?? $t['pattern'] ?? '—')) ?></small></td>
                        <td><?= htmlspecialchars((string)($t['entry_price'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars((string)($t['exit_price'] ?? '—')) ?></td>
                        <td class="<?= $rc ?>"><?= round($roi, 2) ?>%</td>
                        <td class="<?= $pc ?>"><?= round($pnl, 4) ?></td>
                        <td><small><?= htmlspecialchars((string)($t['close_reason'] ?? '—')) ?></small></td>
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
                    <label class="form-check-label small" for="chk_<?= $ch['name'] ?>"><?= htmlspecialchars($ch['label']) ?></label>
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
                        <label class="form-check-label small" for="chk_brain_src">Brain Source Enabled</label>
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

<?php endif; ?>

<script>
// Init mode-aware visibility on load
(function() {
    const mode = '<?= $bot_mode ?>';
    const sel = document.querySelector('[name="mode"]');
    if (sel) { sel.value = mode; onModeChange(sel); }
})();
</script>
