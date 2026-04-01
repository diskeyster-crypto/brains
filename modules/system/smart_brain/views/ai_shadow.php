<?php
/**
 * Smart Brain — AI Shadow Control Page
 *
 * Brain acts as the control-plane only.
 * All simulation/storage/provider logic stays in modules/system/ai_shadow/.
 * No live trading authority is granted to AI.
 *
 * @var string                            $smartBrainUrl
 * @var array<string,mixed>               $ai_shadow_config
 * @var array<string,mixed>               $ai_shadow_stats
 * @var array<string,mixed>               $ai_shadow_status
 * @var array<int,array<string,mixed>>    $ai_shadow_closed_trades
 * @var array<int,array<string,mixed>>    $ai_shadow_active_trades
 * @var array<int,array<string,mixed>>    $ai_shadow_signals
 */

$pageTitle    = 'Smart Brain — AI Shadow';
$activeTab    = 'ai_shadow';

$extraStyles = '
.stat-card { background: #1e293b; border: 1px solid #334155; border-radius: 8px;
             padding: 1rem; text-align: center; }
.stat-value { font-size: 1.4rem; font-weight: 700; color: #3b82f6; }
.stat-label { font-size: 0.72rem; color: #94a3b8; margin-top: 0.2rem; }
.positive   { color: #4ade80; }
.negative   { color: #f87171; }
.neutral    { color: #94a3b8; }
.badge-enter    { background: #16a34a; }
.badge-skip     { background: #6b7280; }
.badge-agree    { background: #0ea5e9; }
.badge-disagree { background: #f59e0b; }
#flash-msg { display: none; position: fixed; top: 1rem; right: 1rem;
             z-index: 9999; min-width: 280px; }
pre.cfg-preview { background: #0d1117; color: #58a6ff; border: 1px solid #334155;
                  border-radius: 6px; padding: 1rem; font-size: 0.8rem;
                  white-space: pre-wrap; max-height: 340px; overflow: auto; }
';

$extraScripts = <<<'JS'
<script>
// ---------- flash ----------
function showFlash(msg, type) {
    const el = document.getElementById('flash-msg');
    el.className = 'alert alert-' + type + ' alert-dismissible shadow';
    document.getElementById('flash-text').textContent = msg;
    el.style.display = 'block';
    if (type === 'success') { setTimeout(() => { el.style.display = 'none'; }, 4000); }
}

// ---------- test connection ----------
function testConnection() {
    const btn = document.getElementById('btn-test-conn');
    btn.disabled = true;
    btn.textContent = 'Testing…';
    fetch(AI_SHADOW_URL + '/test_connection', { method: 'POST' })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            btn.textContent = 'Test Connection';
            if (d.ok) {
                showFlash('Connection OK — provider: ' + d.provider + ', model: ' + (d.model || 'n/a') + ', latency: ' + (d.latency_ms || 0) + 'ms', 'success');
            } else {
                showFlash('Connection FAILED: ' + (d.error || d.status || 'unknown'), 'danger');
            }
            setTimeout(() => location.reload(), 2500);
        })
        .catch(() => {
            btn.disabled = false;
            btn.textContent = 'Test Connection';
            showFlash('Network error testing connection.', 'danger');
        });
}

function runMirror() {
    showFlash('Starting live mirror cycle…', 'info');
    fetch(AI_SHADOW_URL + '/run_mirror', { method: 'POST' })
        .then(r => r.json())
        .then(d => {
            if (d.skipped) {
                showFlash('Module is disabled — enable it below first.', 'warning');
            } else {
                const sc = d.signal_counts || {};
                showFlash(
                    'Mirror done. Newly mirrored: ' + (sc.newly_mirrored ?? 0) +
                    ' signal(s). Errors: ' + (sc.errors ?? 0), 'success'
                );
                setTimeout(() => location.reload(), 1800);
            }
        })
        .catch(() => showFlash('Error calling run_mirror.', 'danger'));
}

// ---------- run replay ----------
function runReplay() {
    showFlash('Starting replay…', 'info');
    fetch(AI_SHADOW_URL + '/run_replay', { method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({signals: []}) })
        .then(r => r.json())
        .then(d => {
            const sc = d.signal_counts || {};
            showFlash(
                'Replay done. Newly mirrored: ' + (sc.newly_mirrored ?? 0) + ' signal(s).', 'success'
            );
            setTimeout(() => location.reload(), 1800);
        })
        .catch(() => showFlash('Error calling run_replay.', 'danger'));
}

// ---------- save settings ----------
function saveSettings() {
    const cfg = {
        enabled:                   document.getElementById('cfg-enabled').checked,
        mode:                      'shadow',
        provider:                  document.getElementById('cfg-provider').value,
        model:                     document.getElementById('cfg-model').value.trim(),
        credential_id:             document.getElementById('cfg-credential-id').value.trim(),
        simulate_on_live_signals:  document.getElementById('cfg-sim-signals').checked,
        simulate_on_live_trades:   document.getElementById('cfg-sim-trades').checked,
        max_signals_per_run:       parseInt(document.getElementById('cfg-max-signals').value, 10),
        max_trades_per_run:        parseInt(document.getElementById('cfg-max-trades').value, 10),
        confidence_threshold_enter:parseFloat(document.getElementById('cfg-thresh-enter').value),
        confidence_threshold_skip: parseFloat(document.getElementById('cfg-thresh-skip').value),
        quality_score_threshold:   parseFloat(document.getElementById('cfg-thresh-quality').value),
        log_enabled:               document.getElementById('cfg-log-enabled').checked,
        log_decisions:             document.getElementById('cfg-log-decisions').checked,
        log_rejections:            document.getElementById('cfg-log-rejections').checked,
    };

    fetch(AI_SHADOW_URL + '/save_settings', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(cfg)
    })
    .then(r => r.json())
    .then(d => {
        if (d.ok) {
            showFlash('AI Shadow settings saved.', 'success');
            setTimeout(() => location.reload(), 1200);
        } else {
            showFlash('Save error: ' + (d.error || 'unknown'), 'danger');
        }
    })
    .catch(() => showFlash('Network error saving settings.', 'danger'));
}

// ---------- clear storage ----------
function clearStorage() {
    if (!confirm('Clear all virtual signals and trades? Cannot be undone.')) return;
    fetch(AI_SHADOW_URL + '/clear_storage', { method: 'POST' })
        .then(r => r.json())
        .then(d => {
            showFlash(d.ok ? 'Storage cleared.' : 'Error clearing storage.', d.ok ? 'warning' : 'danger');
            if (d.ok) { setTimeout(() => location.reload(), 1200); }
        })
        .catch(() => showFlash('Network error.', 'danger'));
}

// ---------- comparison table filter ----------
function applyFilters() {
    const sym   = document.getElementById('f-symbol').value.trim().toUpperCase();
    const pat   = document.getElementById('f-pattern').value;
    const dec   = document.getElementById('f-decision').value;
    const agree = document.getElementById('f-agreement').value;

    document.querySelectorAll('#comparison-tbody tr[data-symbol]').forEach(row => {
        let show = true;
        if (sym   && !row.dataset.symbol.includes(sym))      show = false;
        if (pat   && row.dataset.pattern !== pat)            show = false;
        if (dec   && row.dataset.decision !== dec)           show = false;
        if (agree && row.dataset.agreement !== agree)        show = false;
        row.style.display = show ? '' : 'none';
    });
}
function clearFilters() {
    ['f-symbol','f-pattern','f-decision','f-agreement'].forEach(id => {
        document.getElementById(id).value = '';
    });
    applyFilters();
}
</script>
JS;

$pageContent = function()
    use (
        $smartBrainUrl,
        $ai_shadow_config,
        $ai_shadow_stats,
        $ai_shadow_status,
        $ai_shadow_closed_trades,
        $ai_shadow_active_trades,
        $ai_shadow_signals
    ) {
    $enabled  = (bool)($ai_shadow_status['enabled']  ?? false);
    $provider = (string)($ai_shadow_status['provider'] ?? 'mock');
    $mode     = (string)($ai_shadow_status['mode']    ?? 'shadow');
    $patterns = (array)($ai_shadow_status['allowed_patterns'] ?? []);
    $cfg      = $ai_shadow_config;
?>
<!-- Inject base URL for JS -->
<script>
const AI_SHADOW_URL = '<?= htmlspecialchars($smartBrainUrl) ?>/ai_shadow';
</script>

<!-- Flash -->
<div id="flash-msg" class="alert alert-dismissible" role="alert">
    <span id="flash-text"></span>
    <button type="button" class="btn-close" onclick="document.getElementById('flash-msg').style.display='none'"></button>
</div>

<!-- Page header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">
            <i class="bi bi-robot text-primary me-2"></i>AI Shadow
            <span class="badge bg-secondary ms-2"><?= htmlspecialchars($mode) ?></span>
            <?php if ($enabled): ?>
                <span class="badge bg-success ms-1">ENABLED</span>
            <?php else: ?>
                <span class="badge bg-secondary ms-1">DISABLED</span>
            <?php endif; ?>
        </h4>
        <p class="text-secondary mb-0">
            Read-only AI simulation overlay — observer &amp; evaluator only — <strong>no live trading authority</strong>
        </p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-primary btn-sm" onclick="runMirror()">
            <i class="bi bi-play-fill me-1"></i> Run Mirror
        </button>
        <button class="btn btn-outline-info btn-sm" onclick="runReplay()">
            <i class="bi bi-clock-history me-1"></i> Run Replay
        </button>
        <button id="btn-test-conn" class="btn btn-outline-warning btn-sm" onclick="testConnection()">
            <i class="bi bi-plug me-1"></i> Test Connection
        </button>
    </div>
</div>

<!-- Status bar -->
<div class="alert <?= $enabled ? 'alert-success' : 'alert-warning' ?> d-flex align-items-center mb-3 py-2">
    <i class="bi <?= $enabled ? 'bi-check-circle-fill' : 'bi-pause-circle-fill' ?> me-2"></i>
    <span>
        AI Shadow is <strong><?= $enabled ? 'active' : 'disabled' ?></strong>.
        Provider: <strong><?= htmlspecialchars($provider) ?></strong>.
        Patterns: <?= implode(', ', array_map('htmlspecialchars', $patterns)) ?: '—' ?>.
    </span>
</div>

<!-- Runtime state panel -->
<?php
$status    = $ai_shadow_status;
$lastRunAt = isset($status['last_run_at']) ? date('Y-m-d H:i:s', (int)$status['last_run_at']) : '—';
$lastRunStatus = (string)($status['last_run_status'] ?? '');
$lastConnAt = isset($status['last_connection_test_at']) ? date('Y-m-d H:i:s', (int)$status['last_connection_test_at']) : '—';
$lastConnOk = $status['last_connection_test_ok'] ?? null;
$lastConnStatus = (string)($status['last_connection_test_status'] ?? '');
$lastConnErr = (string)($status['last_connection_test_error'] ?? '');
$lastProvErr = (string)($status['last_provider_error'] ?? '');
$journalAvail = !empty($status['journal_available']);
$protosAvail  = !empty($status['prototypes_available']);
$credId       = (string)($status['credential_id'] ?? '');
$model        = (string)($status['model'] ?? '');
$mirroredCnt  = (int)($status['mirrored_signals_count'] ?? 0);
$vtActive     = (int)($status['virtual_trades_active_count'] ?? 0);
$vtClosed     = (int)($status['virtual_trades_closed_count'] ?? 0);
$vtTotal      = (int)($status['virtual_trades_total'] ?? 0);
?>
<div class="card mb-4" style="border-color:#334155;">
    <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <span><i class="bi bi-info-circle me-1"></i> Runtime State</span>
        <small class="text-muted">live data from storage/runtime_state.json</small>
    </div>
    <div class="card-body py-2">
        <div class="row g-2">
            <div class="col-6 col-md-3">
                <div class="text-muted" style="font-size:0.72rem;">Enabled</div>
                <div><?= $enabled ? '<span class="badge bg-success">YES</span>' : '<span class="badge bg-secondary">NO</span>' ?></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted" style="font-size:0.72rem;">Provider / Model</div>
                <div><strong><?= htmlspecialchars($provider) ?></strong><?= $model !== '' ? ' / ' . htmlspecialchars($model) : '' ?></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted" style="font-size:0.72rem;">Credential ID</div>
                <div><?= $credId !== '' ? '<code class="text-info">' . htmlspecialchars($credId) . '</code>' : '<span class="text-muted">—</span>' ?></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted" style="font-size:0.72rem;">Last Run</div>
                <div>
                    <?php if ($lastRunStatus !== ''): ?>
                        <span class="badge <?= $lastRunStatus === 'ok' ? 'bg-success' : 'bg-warning text-dark' ?>">
                            <?= htmlspecialchars($lastRunStatus) ?>
                        </span>
                        <small class="text-muted ms-1"><?= htmlspecialchars($lastRunAt) ?></small>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted" style="font-size:0.72rem;">Connection Test</div>
                <div>
                    <?php if ($lastConnStatus !== ''): ?>
                        <span class="badge <?= $lastConnOk ? 'bg-success' : 'bg-danger' ?>">
                            <?= $lastConnOk ? 'OK' : 'FAIL' ?>
                        </span>
                        <small class="text-muted ms-1"><?= htmlspecialchars($lastConnAt) ?></small>
                        <?php if (!$lastConnOk && $lastConnErr !== ''): ?>
                            <div style="font-size:0.7rem;" class="text-danger mt-1"><?= htmlspecialchars(substr($lastConnErr, 0, 80)) ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted" style="font-size:0.72rem;">Last Provider Error</div>
                <div><?= $lastProvErr !== '' ? '<span class="text-warning">' . htmlspecialchars(substr($lastProvErr, 0, 60)) . '</span>' : '<span class="text-muted">none</span>' ?></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted" style="font-size:0.72rem;">Mirrored Signals / VTrades</div>
                <div>
                    <strong><?= $mirroredCnt ?></strong> signals &nbsp;·&nbsp;
                    <strong><?= $vtTotal ?></strong> vt (<span class="text-success"><?= $vtActive ?> active</span>, <?= $vtClosed ?> closed)
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted" style="font-size:0.72rem;">Journal / Prototypes</div>
                <div>
                    <span class="badge <?= $journalAvail ? 'bg-success' : 'bg-secondary' ?>">
                        <?= $journalAvail ? 'Journal: data' : 'Journal: empty' ?>
                    </span>
                    <span class="badge <?= $protosAvail ? 'bg-success' : 'bg-secondary' ?> ms-1">
                        <?= $protosAvail ? 'Protos: data' : 'Protos: empty' ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Stats row -->
<?php
$statDefs = [
    ['label' => 'Mirrored Signals', 'key' => 'total_mirrored_signals', 'fmt' => 'int'],
    ['label' => 'Virtual Trades',   'key' => 'total_virtual_trades',   'fmt' => 'int'],
    ['label' => 'Active Trades',    'key' => 'total_active_trades',    'fmt' => 'int'],
    ['label' => 'AI Win Rate',      'key' => 'ai_win_rate',            'fmt' => 'pct'],
    ['label' => 'AI Avg ROI',       'key' => 'ai_avg_roi',             'fmt' => 'roi'],
    ['label' => 'AI Expectancy',    'key' => 'ai_expectancy',          'fmt' => 'roi'],
    ['label' => 'Live Avg ROI',     'key' => 'live_avg_roi',           'fmt' => 'roi'],
    ['label' => 'Live vs AI Δ',     'key' => 'live_vs_ai_delta',       'fmt' => 'roi'],
    ['label' => 'Agreement Rate',   'key' => 'agreement_rate',         'fmt' => 'pct'],
    ['label' => 'Disagreement Rate','key' => 'disagreement_rate',      'fmt' => 'pct'],
];
?>
<div class="row g-2 mb-4">
    <?php foreach ($statDefs as $sd):
        $raw = $ai_shadow_stats[$sd['key']] ?? 0;
        if ($sd['fmt'] === 'pct') {
            $display = number_format((float)$raw * 100, 1) . '%';
            $cls     = '';
        } elseif ($sd['fmt'] === 'roi') {
            $pct = (float)$raw * 100;
            $cls = $pct > 0 ? 'positive' : ($pct < 0 ? 'negative' : 'neutral');
            $display = '<span class="' . $cls . '">' . ($pct >= 0 ? '+' : '') . number_format($pct, 2) . '%</span>';
        } else {
            $display = (string)(int)$raw;
            $cls = '';
        }
    ?>
    <div class="col-6 col-sm-4 col-lg-2 col-xl-1">
        <div class="stat-card">
            <div class="stat-value"><?= $display ?></div>
            <div class="stat-label"><?= htmlspecialchars($sd['label']) ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-4">

    <!-- ===== Settings panel ===== -->
    <div class="col-12 col-xl-5">
        <div class="card h-100">
            <div class="card-header py-2">
                <i class="bi bi-sliders me-1"></i> AI Shadow Settings
                <span class="text-muted ms-2" style="font-size:0.75rem;">
                    (secrets via KeyCenter only — no raw keys stored here)
                </span>
            </div>
            <div class="card-body">

                <!-- Enable / provider row -->
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label">Module</label>
                        <div class="form-check form-switch mt-1">
                            <input class="form-check-input" type="checkbox" id="cfg-enabled"
                                   <?= ($cfg['enabled'] ?? false) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="cfg-enabled">Enabled</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="cfg-provider">AI Provider</label>
                        <select class="form-select form-select-sm" id="cfg-provider">
                            <option value="mock"  <?= ($cfg['provider'] ?? 'mock') === 'mock'  ? 'selected' : '' ?>>mock (Phase 1 stub)</option>
                            <option value="openai"<?= ($cfg['provider'] ?? '') === 'openai' ? 'selected' : '' ?>>OpenAI (future)</option>
                            <option value="claude"<?= ($cfg['provider'] ?? '') === 'claude' ? 'selected' : '' ?>>Anthropic Claude (future)</option>
                        </select>
                    </div>
                </div>

                <!-- Model / credential_id -->
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label" for="cfg-model">Model</label>
                        <input type="text" class="form-control form-control-sm" id="cfg-model"
                               placeholder="e.g. gpt-4o"
                               value="<?= htmlspecialchars((string)($cfg['model'] ?? '')) ?>">
                        <div class="form-text">Leave blank for mock provider.</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="cfg-credential-id">
                            Credential ID
                            <i class="bi bi-lock-fill text-warning ms-1" title="References KeyCenter — no raw key stored here"></i>
                        </label>
                        <input type="text" class="form-control form-control-sm" id="cfg-credential-id"
                               placeholder="e.g. openai_main"
                               value="<?= htmlspecialchars((string)($cfg['credential_id'] ?? '')) ?>">
                        <div class="form-text text-warning">
                            <i class="bi bi-shield-lock me-1"></i>
                            Enter a KeyCenter account ID — raw API keys are never stored here.
                        </div>
                    </div>
                </div>

                <!-- Simulate toggles -->
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="cfg-sim-signals"
                                   <?= ($cfg['simulate_on_live_signals'] ?? true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="cfg-sim-signals">Mirror Live Signals</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="cfg-sim-trades"
                                   <?= ($cfg['simulate_on_live_trades'] ?? true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="cfg-sim-trades">Mirror Live Trades</label>
                        </div>
                    </div>
                </div>

                <!-- Limits -->
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label" for="cfg-max-signals">Max Signals / Run</label>
                        <input type="number" class="form-control form-control-sm" id="cfg-max-signals"
                               value="<?= (int)($cfg['max_signals_per_run'] ?? 50) ?>" min="1" max="500">
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="cfg-max-trades">Max Trades / Run</label>
                        <input type="number" class="form-control form-control-sm" id="cfg-max-trades"
                               value="<?= (int)($cfg['max_trades_per_run'] ?? 20) ?>" min="1" max="200">
                    </div>
                </div>

                <!-- Thresholds -->
                <div class="row g-3 mb-3">
                    <div class="col-4">
                        <label class="form-label" for="cfg-thresh-enter">Confidence Enter</label>
                        <input type="number" class="form-control form-control-sm" id="cfg-thresh-enter"
                               value="<?= number_format((float)($cfg['confidence_threshold_enter'] ?? 0.55), 2) ?>"
                               step="0.01" min="0" max="1">
                    </div>
                    <div class="col-4">
                        <label class="form-label" for="cfg-thresh-skip">Confidence Skip</label>
                        <input type="number" class="form-control form-control-sm" id="cfg-thresh-skip"
                               value="<?= number_format((float)($cfg['confidence_threshold_skip'] ?? 0.35), 2) ?>"
                               step="0.01" min="0" max="1">
                    </div>
                    <div class="col-4">
                        <label class="form-label" for="cfg-thresh-quality">Quality Score</label>
                        <input type="number" class="form-control form-control-sm" id="cfg-thresh-quality"
                               value="<?= number_format((float)($cfg['quality_score_threshold'] ?? 0.40), 2) ?>"
                               step="0.01" min="0" max="1">
                    </div>
                </div>

                <!-- Logging -->
                <div class="row g-2 mb-4">
                    <div class="col-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="cfg-log-enabled"
                                   <?= ($cfg['log_enabled'] ?? true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="cfg-log-enabled">Log Enabled</label>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="cfg-log-decisions"
                                   <?= ($cfg['log_decisions'] ?? true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="cfg-log-decisions">Log Decisions</label>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="cfg-log-rejections"
                                   <?= ($cfg['log_rejections'] ?? false) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="cfg-log-rejections">Log Rejections</label>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button class="btn btn-primary btn-sm" onclick="saveSettings()">
                        <i class="bi bi-floppy me-1"></i> Save Settings
                    </button>
                    <button class="btn btn-outline-danger btn-sm" onclick="clearStorage()">
                        <i class="bi bi-trash me-1"></i> Clear Storage
                    </button>
                </div>

            </div><!-- /card-body -->
        </div><!-- /card -->
    </div><!-- /col settings -->

    <!-- ===== Active trades mini panel ===== -->
    <div class="col-12 col-xl-7">
        <div class="card">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <span><i class="bi bi-activity me-1"></i> Virtual Active Trades
                    <span class="badge bg-primary ms-1"><?= count($ai_shadow_active_trades) ?></span>
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-dark table-hover table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Symbol</th>
                                <th>Pattern</th>
                                <th>Entered At</th>
                                <th>Entry Price</th>
                                <th>AI Decision</th>
                                <th>Confidence</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($ai_shadow_active_trades)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">No active virtual trades</td></tr>
                        <?php else: ?>
                            <?php foreach ($ai_shadow_active_trades as $t): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars((string)($t['symbol'] ?? '—')) ?></strong></td>
                                <td><small><?= htmlspecialchars((string)($t['pattern_algorithm'] ?? '—')) ?></small></td>
                                <td><small><?= isset($t['ai_entry_timestamp']) ? date('Y-m-d H:i', (int)$t['ai_entry_timestamp']) : '—' ?></small></td>
                                <td><?= isset($t['ai_entry_price']) ? number_format((float)$t['ai_entry_price'], 4) : '—' ?></td>
                                <td><span class="badge badge-enter"><?= htmlspecialchars((string)($t['ai_decision'] ?? '—')) ?></span></td>
                                <td><?= isset($t['ai_confidence']) ? number_format((float)$t['ai_confidence'], 2) : '—' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div><!-- /active trades card -->

        <!-- Config preview -->
        <div class="card mt-3">
            <div class="card-header py-2">
                <i class="bi bi-code-slash me-1"></i> Current ai_shadow.json
                <span class="text-muted ms-2" style="font-size:0.72rem;">
                    (credential_id references KeyCenter — no raw keys stored)
                </span>
            </div>
            <div class="card-body p-3">
                <pre class="cfg-preview mb-0"><?= htmlspecialchars(
                    json_encode($ai_shadow_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}'
                ) ?></pre>
            </div>
        </div>

    </div><!-- /col right -->
</div><!-- /row -->

<!-- Comparison table filters -->
<div class="card mt-4">
    <div class="card-header py-2">
        <i class="bi bi-funnel me-1"></i> Filters — Closed Trades Comparison
    </div>
    <div class="card-body py-2">
        <div class="row g-2">
            <div class="col-12 col-sm-6 col-md-3">
                <input type="text" id="f-symbol" class="form-control form-control-sm"
                       placeholder="Symbol" oninput="applyFilters()">
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <select id="f-pattern" class="form-select form-select-sm" onchange="applyFilters()">
                    <option value="">All Patterns</option>
                    <?php foreach ((array)($cfg['allowed_patterns'] ?? []) as $p): ?>
                    <option value="<?= htmlspecialchars((string)$p) ?>"><?= htmlspecialchars((string)$p) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm-4 col-md-2">
                <select id="f-decision" class="form-select form-select-sm" onchange="applyFilters()">
                    <option value="">All Decisions</option>
                    <option value="enter">Enter</option>
                    <option value="skip">Skip</option>
                </select>
            </div>
            <div class="col-12 col-sm-4 col-md-2">
                <select id="f-agreement" class="form-select form-select-sm" onchange="applyFilters()">
                    <option value="">All</option>
                    <option value="agree">Agree</option>
                    <option value="disagree">Disagree</option>
                    <option value="pending">Pending</option>
                </select>
            </div>
            <div class="col-12 col-sm-4 col-md-2">
                <button class="btn btn-outline-secondary btn-sm w-100" onclick="clearFilters()">
                    <i class="bi bi-x-circle me-1"></i> Clear
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Comparison table -->
<div class="card mt-2 mb-4">
    <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <span>
            <i class="bi bi-table me-1"></i> Live vs AI — Closed Trades
            <span class="badge bg-secondary ms-1"><?= count($ai_shadow_closed_trades) ?></span>
        </span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-dark table-hover table-sm mb-0">
                <thead>
                    <tr>
                        <th>Symbol</th>
                        <th>Pattern</th>
                        <th>Live Entry</th>
                        <th>AI Entry / Skip</th>
                        <th>Live Close</th>
                        <th>AI Close</th>
                        <th>Live ROI</th>
                        <th>AI ROI</th>
                        <th>Live Reason</th>
                        <th>AI Reason</th>
                        <th>Agreement</th>
                        <th>MFE / MAE</th>
                        <th>Conf / Quality</th>
                    </tr>
                </thead>
                <tbody id="comparison-tbody">
                <?php if (empty($ai_shadow_closed_trades)): ?>
                    <tr>
                        <td colspan="13" class="text-center text-muted py-4">
                            No closed virtual trades yet. Run a mirror cycle first.
                        </td>
                    </tr>
                <?php else: ?>
                <?php foreach ($ai_shadow_closed_trades as $t):
                    $aiDec  = (string)($t['ai_decision'] ?? '');
                    $agree  = (string)($t['agreement']   ?? 'pending');
                    $liveRoi= (float)($t['live_roi_reference'] ?? 0) * 100;
                    $aiRoi  = (float)($t['ai_roi']            ?? 0) * 100;
                    $liveEntTs = isset($t['live_entered_at']) ? date('m-d H:i', (int)$t['live_entered_at']) : '—';
                    $aiEntTs   = isset($t['ai_entry_timestamp']) ? date('m-d H:i', (int)$t['ai_entry_timestamp']) : ($aiDec === 'skip' ? 'skipped' : '—');
                    $liveClTs  = isset($t['live_closed_at'])  ? date('m-d H:i', (int)$t['live_closed_at'])  : '—';
                    $aiClTs    = isset($t['ai_exit_timestamp'])? date('m-d H:i', (int)$t['ai_exit_timestamp']): '—';
                    $livePr    = isset($t['live_entry_price']) ? number_format((float)$t['live_entry_price'], 4) : '—';
                    $aiPr      = isset($t['ai_entry_price'])   ? number_format((float)$t['ai_entry_price'],  4) : '—';
                    $mfe       = isset($t['mfe']) ? number_format((float)$t['mfe'] * 100, 2) . '%' : '—';
                    $mae       = isset($t['mae']) ? number_format((float)$t['mae'] * 100, 2) . '%' : '—';
                    $conf      = isset($t['ai_confidence'])   ? number_format((float)$t['ai_confidence'],   2) : '—';
                    $qual      = isset($t['ai_quality_score'])? number_format((float)$t['ai_quality_score'],2) : '—';
                ?>
                <tr data-symbol="<?= htmlspecialchars((string)($t['symbol'] ?? '')) ?>"
                    data-pattern="<?= htmlspecialchars((string)($t['pattern_algorithm'] ?? '')) ?>"
                    data-decision="<?= htmlspecialchars($aiDec) ?>"
                    data-agreement="<?= htmlspecialchars($agree) ?>">
                    <td><strong><?= htmlspecialchars((string)($t['symbol'] ?? '—')) ?></strong></td>
                    <td><small><?= htmlspecialchars((string)($t['pattern_algorithm'] ?? '—')) ?></small></td>
                    <td><small><?= htmlspecialchars($liveEntTs) ?><br><?= htmlspecialchars($livePr) ?></small></td>
                    <td>
                        <span class="badge <?= $aiDec === 'enter' ? 'badge-enter' : 'badge-skip' ?>">
                            <?= htmlspecialchars($aiDec ?: '—') ?>
                        </span>
                        <?php if ($aiDec === 'enter'): ?><br><small><?= htmlspecialchars($aiEntTs) ?><br><?= htmlspecialchars($aiPr) ?></small><?php endif; ?>
                    </td>
                    <td><small><?= htmlspecialchars($liveClTs) ?></small></td>
                    <td><small><?= htmlspecialchars($aiClTs) ?></small></td>
                    <td class="<?= $liveRoi >= 0 ? 'positive' : 'negative' ?>">
                        <?= ($liveRoi >= 0 ? '+' : '') . number_format($liveRoi, 2) ?>%
                    </td>
                    <td class="<?= $aiRoi >= 0 ? 'positive' : 'negative' ?>">
                        <?= ($aiRoi >= 0 ? '+' : '') . number_format($aiRoi, 2) ?>%
                    </td>
                    <td><small class="text-muted"><?= htmlspecialchars((string)($t['live_close_reason'] ?? '—')) ?></small></td>
                    <td><small class="text-muted"><?= htmlspecialchars((string)($t['ai_exit_reason']    ?? '—')) ?></small></td>
                    <td>
                        <span class="badge <?= $agree === 'agree' ? 'badge-agree' : ($agree === 'disagree' ? 'badge-disagree' : 'bg-secondary') ?>">
                            <?= htmlspecialchars($agree) ?>
                        </span>
                    </td>
                    <td><small><?= htmlspecialchars($mfe) ?> / <?= htmlspecialchars($mae) ?></small></td>
                    <td><small><?= htmlspecialchars($conf) ?> / <?= htmlspecialchars($qual) ?></small></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
};

require __DIR__ . '/_layout.php';
