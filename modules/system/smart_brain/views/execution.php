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
 */

$pageTitle = 'Smart Brain — Execution';
$activeTab = 'execution';

$extraStyles = '
.stat-card { background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 1rem; text-align: center; }
.stat-value { font-size: 1.4rem; font-weight: 700; color: #3b82f6; }
.stat-label { font-size: 0.72rem; color: #94a3b8; margin-top: 0.2rem; }
.positive { color: #4ade80; }
.negative { color: #f87171; }
.neutral  { color: #94a3b8; }
.badge-live  { background: var(--bs-danger,#dc3545); }
.badge-demo  { background: #fd7e14; color: #000; }
.badge-paper { background: var(--bs-secondary,#6c757d); }
#flash-msg { display:none; position:fixed; top:1rem; right:1rem; z-index:9999; min-width:280px; }
.section-heading { border-bottom: 1px solid #334155; padding-bottom: .4rem; margin-bottom: 1rem; font-size: 1rem; font-weight: 600; color: #94a3b8; }
table.exec-table td, table.exec-table th { font-size: 0.8rem; vertical-align: middle; }
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
    if (type === 'success') { setTimeout(() => { el.style.display = 'none'; }, 4000); }
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
            setTimeout(() => location.reload(), 2000);
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
    // Checkboxes
    ['enabled','reconcile_before_action','brain_source_enabled','trailing_enabled','break_even_enabled','emergency_stop_enabled'].forEach(k => {
        data[k] = form.querySelector('[name="'+k+'"]')?.checked ?? false;
    });

    fetch(EXEC_URL + '/save_config', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(r => r.json())
    .then(d => {
        showFlash(d.ok ? 'Settings saved.' : 'Save failed: ' + (d.error ?? ''), d.ok ? 'success' : 'danger');
        if (d.ok) { setTimeout(() => location.reload(), 1500); }
    })
    .catch(() => showFlash('Network error saving settings.', 'danger'));
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
    'live'  => '<span class="badge badge-live">LIVE</span>',
    'demo'  => '<span class="badge badge-demo">DEMO</span>',
    default => '<span class="badge badge-paper">PAPER</span>',
};
$enabledBadge = $bot_enabled
    ? '<span class="badge bg-success" id="status-badge">ENABLED</span>'
    : '<span class="badge bg-secondary" id="status-badge">DISABLED</span>';

$lastRunTs  = $bot_last_run['timestamp'] ?? ($bot_last_run['ts'] ?? null);
$lastRunOk  = $bot_last_run['ok'] ?? null;
$activeCount = count($bot_active_trades);
$closedCount = count($bot_closed_trades);
?>

<!-- ===== Overview ===== -->
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <h5 class="mb-1">
                    <i class="bi bi-cpu me-1"></i> Trading Bot
                    <?= $modeLabel ?>
                    <?= $enabledBadge ?>
                </h5>
                <?php if ($bot_mode === 'live'): ?>
                    <small class="text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i><strong>LIVE mode — real funds on real exchange.</strong></small>
                <?php elseif ($bot_mode === 'demo'): ?>
                    <small class="text-warning"><i class="bi bi-info-circle me-1"></i>Demo mode — Bybit Demo API sandbox, no real funds.</small>
                <?php else: ?>
                    <small class="text-secondary"><i class="bi bi-archive me-1"></i>Paper mode — local simulation, no exchange connection.</small>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <button id="btn-run"       class="btn btn-success btn-sm" onclick="runBot()"><i class="bi bi-play-fill me-1"></i>Run Now</button>
                <button id="btn-reconcile" class="btn btn-outline-warning btn-sm" onclick="reconcileBot()"><i class="bi bi-arrow-repeat me-1"></i>Reconcile</button>
                <button class="btn btn-outline-secondary btn-sm" onclick="refreshStatus()"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button>
                <a href="/admin/trading_bot" class="btn btn-outline-light btn-sm" target="_blank"><i class="bi bi-box-arrow-up-right me-1"></i>Bot Dashboard</a>
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
                ['label' => 'Storage Namespace', 'value' => basename($bot_storage_dir ?? 'n/a')],
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
            <span class="section-heading">Balance Snapshot</span>
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
            'total_trades'       => 'Total Trades',
            'win_rate'           => 'Win Rate',
            'avg_roi'            => 'Avg ROI',
            'expectancy'         => 'Expectancy',
            'stop_hit_rate'      => 'Stop Hit Rate',
            'tp_hit_rate'        => 'TP Hit Rate',
            'trailing_close_rate'=> 'Trailing Close',
            'total_pnl'          => 'Total PnL',
            'wins'               => 'Wins',
            'losses'             => 'Losses',
        ];
        foreach ($statMap as $sKey => $sLabel):
            if (!array_key_exists($sKey, $bot_stats)) continue;
            $sVal = $bot_stats[$sKey];
            if (is_float($sVal)) { $sVal = round($sVal, 2); }
        ?>
        <div class="col-6 col-md-2">
            <div class="stat-card">
                <div class="stat-value"><?= htmlspecialchars((string)$sVal) ?></div>
                <div class="stat-label"><?= htmlspecialchars($sLabel) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
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
                    <tr>
                        <th>Symbol</th><th>Side</th><th>Entry</th><th>Mark</th>
                        <th>ROI%</th><th>PnL</th><th>SL</th><th>Since</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($bot_active_trades as $t): ?>
                    <?php
                    $roi = (float)($t['roi_pct'] ?? $t['roi'] ?? 0);
                    $pnl = (float)($t['unrealised_pnl'] ?? $t['pnl'] ?? 0);
                    $roiClass = $roi >= 0 ? 'positive' : 'negative';
                    $pnlClass = $pnl >= 0 ? 'positive' : 'negative';
                    $since = isset($t['opened_at']) ? date('m-d H:i', (int)$t['opened_at']) : '—';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string)($t['symbol'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars((string)($t['side'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars((string)($t['entry_price'] ?? $t['avg_price'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars((string)($t['mark_price'] ?? $t['active_price'] ?? '—')) ?></td>
                        <td class="<?= $roiClass ?>"><?= round($roi, 2) ?>%</td>
                        <td class="<?= $pnlClass ?>"><?= round($pnl, 4) ?></td>
                        <td><?= htmlspecialchars((string)($t['stop_loss'] ?? '—')) ?></td>
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
                    <tr>
                        <th>Symbol</th><th>Side</th><th>Pattern</th>
                        <th>Entry</th><th>Exit</th><th>ROI%</th><th>PnL</th>
                        <th>Reason</th><th>Hold</th><th>MFE</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($bot_closed_trades as $t): ?>
                    <?php
                    $roi = (float)($t['roi_pct'] ?? $t['roi'] ?? 0);
                    $pnl = (float)($t['realised_pnl'] ?? $t['pnl'] ?? 0);
                    $roiClass = $roi >= 0 ? 'positive' : 'negative';
                    $pnlClass = $pnl >= 0 ? 'positive' : 'negative';
                    $holdSec  = (int)($t['hold_seconds'] ?? $t['hold_time'] ?? 0);
                    $holdStr  = $holdSec > 0 ? gmdate('H:i:s', $holdSec) : '—';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string)($t['symbol'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars((string)($t['side'] ?? '—')) ?></td>
                        <td><small><?= htmlspecialchars((string)($t['pattern_algorithm'] ?? $t['pattern'] ?? '—')) ?></small></td>
                        <td><?= htmlspecialchars((string)($t['entry_price'] ?? '—')) ?></td>
                        <td><?= htmlspecialchars((string)($t['exit_price'] ?? '—')) ?></td>
                        <td class="<?= $roiClass ?>"><?= round($roi, 2) ?>%</td>
                        <td class="<?= $pnlClass ?>"><?= round($pnl, 4) ?></td>
                        <td><small><?= htmlspecialchars((string)($t['close_reason'] ?? '—')) ?></small></td>
                        <td><?= htmlspecialchars($holdStr) ?></td>
                        <td><?= round((float)($t['mfe_roi'] ?? $t['mfe'] ?? 0), 2) ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===== Settings ===== -->
<div class="card mb-4">
    <div class="card-body">
        <div class="section-heading">Settings <small class="text-muted fw-normal">(saved to config/bot.json)</small></div>
        <form id="settings-form">
        <div class="row g-3">

            <div class="col-md-3">
                <label class="form-label form-label-sm">Mode</label>
                <select name="mode" class="form-select form-select-sm bg-dark text-light border-secondary">
                    <option value="demo"  <?= $bot_mode === 'demo'  ? 'selected' : '' ?>>Demo (sandbox, recommended test mode)</option>
                    <option value="live"  <?= $bot_mode === 'live'  ? 'selected' : '' ?>>Live (real exchange — DANGEROUS)</option>
                    <option value="paper" <?= $bot_mode === 'paper' ? 'selected' : '' ?>>Paper (legacy local simulation)</option>
                </select>
                <small class="text-muted">⚠ Change mode with care.</small>
            </div>

            <div class="col-md-2">
                <label class="form-label form-label-sm">Max Positions</label>
                <input type="number" name="max_positions" class="form-control form-control-sm bg-dark text-light border-secondary"
                       value="<?= (int)($modeCfg['max_concurrent_positions'] ?? $bot_config['max_positions'] ?? 3) ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label form-label-sm">Leverage Default</label>
                <input type="number" name="leverage_default" class="form-control form-control-sm bg-dark text-light border-secondary"
                       value="<?= (int)($exchCfg['leverage'] ?? $bot_config['leverage_default'] ?? 5) ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label form-label-sm">Stop Loss %</label>
                <input type="number" step="0.1" name="stop_loss_pct" class="form-control form-control-sm bg-dark text-light border-secondary"
                       value="<?= round((float)($exCfg['stop_loss_pct'] ?? $bot_config['stop_loss_pct'] ?? 2.0), 2) ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label form-label-sm">Take Profit %</label>
                <input type="number" step="0.1" name="take_profit_pct" class="form-control form-control-sm bg-dark text-light border-secondary"
                       value="<?= round((float)($exCfg['take_profit_pct'] ?? $bot_config['take_profit_pct'] ?? 5.0), 2) ?>">
            </div>

            <div class="col-12 col-md-9">
                <div class="d-flex flex-wrap gap-4 mt-1">
                    <?php
                    $checks = [
                        ['name' => 'enabled',               'label' => 'Enabled',            'val' => $bot_enabled],
                        ['name' => 'reconcile_before_action','label' => 'Reconcile Before Run','val' => (bool)($modeCfg['reconcile_before_action'] ?? false)],
                        ['name' => 'brain_source_enabled',  'label' => 'Brain Source',        'val' => (bool)($srcCfg['brain_source_enabled'] ?? true)],
                        ['name' => 'trailing_enabled',      'label' => 'Trailing',            'val' => (bool)($exCfg['trailing_enabled'] ?? false)],
                        ['name' => 'break_even_enabled',    'label' => 'Break Even',          'val' => (bool)($exCfg['break_even_enabled'] ?? false)],
                        ['name' => 'emergency_stop_enabled','label' => 'Emergency Stop',      'val' => (bool)($valCfg['emergency_stop_enabled'] ?? false)],
                    ];
                    foreach ($checks as $ch): ?>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="<?= $ch['name'] ?>" id="chk_<?= $ch['name'] ?>"
                            <?= $ch['val'] ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="chk_<?= $ch['name'] ?>"><?= htmlspecialchars($ch['label']) ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="col-12 mt-2">
                <button type="button" class="btn btn-primary btn-sm" onclick="saveSettings()">
                    <i class="bi bi-floppy me-1"></i> Save Settings
                </button>
                <small class="text-muted ms-2">Only safe fields are written here. Full config remains in bot.json.</small>
            </div>
        </div>
        </form>
    </div>
</div>

<?php endif; ?>
