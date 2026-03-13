<?php
/**
 * Trading Bot — Profit (Profit Manager UI)
 *
 * Displays Profit Manager runtime state and allows manual run.
 *
 * UI ONLY: does not place orders; it calls Profit Manager service via Trading Bot API.
 */

use Core\System\System;
use Core\System\SystemPaths;

$tab = 'profit';

// Include tabs via SystemPaths
$moduleBase = SystemPaths::instance()->get('system.trading_bot');
require $moduleBase . '/views/_tabs.php';

$profitData = $profitData ?? [];
$pmOk = (bool)($profitData['ok'] ?? false);
$pmError = (string)($profitData['error'] ?? '');
$pmBase = (string)($profitData['module_base'] ?? '');
$pmRuntimeDir = (string)($profitData['runtime_dir'] ?? '');

$lastRun = is_array($profitData['last_run'] ?? null) ? $profitData['last_run'] : [];
$statusFile = is_array($profitData['status'] ?? null) ? $profitData['status'] : [];
$appliedFile = is_array($profitData['applied_index'] ?? null) ? $profitData['applied_index'] : [];

$lastRunTs = (string)($lastRun['ts'] ?? '');
$mode = (string)($lastRun['mode'] ?? '');
$durationMs = (int)($lastRun['duration_ms'] ?? 0);

$positionsTotal = (int)($lastRun['positions_total'] ?? 0);
$positionsManaged = (int)($lastRun['positions_managed'] ?? 0);

$stepTrailing = is_array($lastRun['step_trailing'] ?? null) ? $lastRun['step_trailing'] : [];
$dumbTrailing = is_array($lastRun['dumb_trailing'] ?? null) ? $lastRun['dumb_trailing'] : [];

$errors = is_array($lastRun['errors'] ?? null) ? $lastRun['errors'] : [];
$warnings = is_array($lastRun['warnings'] ?? null) ? $lastRun['warnings'] : [];

$events = is_array($appliedFile['events'] ?? null) ? $appliedFile['events'] : [];
$events = array_values(array_reverse(array_slice($events, -30)));

$symbols = is_array($statusFile['symbols'] ?? null) ? $statusFile['symbols'] : [];
$symbolsCount = is_array($symbols) ? count($symbols) : 0;
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">
            <i class="bi bi-cash-coin me-2"></i>
            Profit Manager
            <?php if ($pmOk): ?>
                <span class="badge bg-success ms-2">OK</span>
            <?php else: ?>
                <span class="badge bg-danger ms-2">NOT READY</span>
            <?php endif; ?>
            <?php if ($mode !== ''): ?>
                <span class="badge bg-secondary ms-1"><?= htmlspecialchars(strtoupper($mode)) ?></span>
            <?php endif; ?>
        </h4>
        <div class="text-muted small">
            <?php if ($pmOk): ?>
                Runtime: <?= htmlspecialchars($pmRuntimeDir) ?>
            <?php else: ?>
                <?= htmlspecialchars($pmError !== '' ? $pmError : 'profit_manager_not_found') ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button class="btn btn-success" onclick="runProfitManager()">
            <i class="bi bi-play-fill me-1"></i> Run now
        </button>
        <button class="btn btn-outline-light" onclick="refreshProfitState()">
            <i class="bi bi-arrow-repeat me-1"></i> Refresh
        </button>
        <button class="btn btn-outline-info" onclick="openJsonModal('Profit: last_run.json', <?= htmlspecialchars(json_encode($lastRun, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>)">
            <i class="bi bi-code-slash me-1"></i> last_run.json
        </button>
    </div>
</div>

<?php if (!$pmOk): ?>
<div class="alert alert-danger">
    Profit Manager module not available. Check SystemPaths key <code>system.profit_manager</code> and module installation.
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-value text-info"><?= $positionsTotal ?></div>
            <div class="stat-label">Positions total</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-value text-success"><?= $positionsManaged ?></div>
            <div class="stat-label">Positions managed</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-value"><?= (int)($stepTrailing['applied'] ?? 0) ?></div>
            <div class="stat-label">Step trailing applied</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-value"><?= (int)($dumbTrailing['applied'] ?? 0) ?></div>
            <div class="stat-label">Dumb trailing applied</div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-exclamation-triangle me-2"></i>Warnings</span>
                <span class="badge bg-secondary"><?= count($warnings) ?></span>
            </div>
            <div class="card-body">
                <?php if (empty($warnings)): ?>
                    <div class="text-muted">No warnings.</div>
                <?php else: ?>
                    <ul class="mb-0">
                        <?php foreach (array_slice($warnings, 0, 20) as $w): ?>
                            <li class="small"><?= htmlspecialchars(is_string($w) ? $w : json_encode($w, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-bug me-2"></i>Errors</span>
                <span class="badge bg-secondary"><?= count($errors) ?></span>
            </div>
            <div class="card-body">
                <?php if (empty($errors)): ?>
                    <div class="text-muted">No errors.</div>
                <?php else: ?>
                    <ul class="mb-0">
                        <?php foreach (array_slice($errors, 0, 20) as $e): ?>
                            <li class="small text-danger"><?= htmlspecialchars(is_string($e) ? $e : json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-lightning-charge me-2"></i>Applied events (last <?= count($events) ?>)</span>
        <button class="btn btn-sm btn-outline-light" onclick="openJsonModal('Profit: applied_index.json', <?= htmlspecialchars(json_encode($appliedFile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>)">
            <i class="bi bi-code-slash me-1"></i> RAW
        </button>
    </div>
    <div class="card-body p-0">
        <?php if (empty($events)): ?>
            <div class="p-3 text-muted">No events yet.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th style="width: 170px;">TS</th>
                            <th>Symbol</th>
                            <th>Action</th>
                            <th>ROI</th>
                            <th>Details</th>
                            <th style="width: 90px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $ev): ?>
                            <?php
                                $ts = (string)($ev['ts'] ?? '');
                                $symbol = (string)($ev['symbol'] ?? '');
                                $action = (string)($ev['action'] ?? ($ev['type'] ?? ''));
                                $roi = $ev['roi_pct'] ?? ($ev['roi'] ?? null);
                                $roiStr = (is_numeric($roi)) ? number_format((float)$roi, 2) . '%' : '—';
                                $details = $ev['reason'] ?? ($ev['note'] ?? '');
                                if (!is_string($details)) {
                                    $details = '';
                                }
                            ?>
                            <tr>
                                <td class="text-muted small"><?= htmlspecialchars($ts) ?></td>
                                <td><strong><?= htmlspecialchars($symbol) ?></strong></td>
                                <td><?= htmlspecialchars($action) ?></td>
                                <td><?= htmlspecialchars($roiStr) ?></td>
                                <td class="text-muted small"><?= htmlspecialchars($details) ?></td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-info" onclick="openJsonModal('Profit event', <?= htmlspecialchars(json_encode($ev, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>)">
                                        RAW
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-ul me-2"></i>Status (symbols: <?= $symbolsCount ?>)</span>
        <button class="btn btn-sm btn-outline-light" onclick="openJsonModal('Profit: status.json', <?= htmlspecialchars(json_encode($statusFile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>)">
            <i class="bi bi-code-slash me-1"></i> RAW
        </button>
    </div>
    <div class="card-body">
        <?php if (empty($symbols)): ?>
            <div class="text-muted">No symbol status yet.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Symbol</th>
                            <th>Last ROI</th>
                            <th>Last Update</th>
                            <th>Locked Until</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($symbols as $sym => $row): ?>
                            <?php
                                $rowArr = is_array($row) ? $row : [];
                                $lastRoi = $rowArr['last_roi_pct'] ?? ($rowArr['roi_pct'] ?? null);
                                $lastRoiStr = is_numeric($lastRoi) ? number_format((float)$lastRoi, 2) . '%' : '—';
                                $lastSeen = $rowArr['last_seen_ts'] ?? null;
                                $lastSeenStr = is_numeric($lastSeen) ? date('c', (int)$lastSeen) : '—';
                                $lockedUntil = $rowArr['locked_until_ts'] ?? null;
                                $lockedUntilStr = is_numeric($lockedUntil) ? date('c', (int)$lockedUntil) : '—';
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars((string)$sym) ?></strong></td>
                                <td><?= htmlspecialchars($lastRoiStr) ?></td>
                                <td class="text-muted small"><?= htmlspecialchars($lastSeenStr) ?></td>
                                <td class="text-muted small"><?= htmlspecialchars($lockedUntilStr) ?></td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-info" onclick="openJsonModal('Symbol status: <?= htmlspecialchars((string)$sym) ?>', <?= htmlspecialchars(json_encode($rowArr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>)">
                                        RAW
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    // Auto reload profit page every 15s (pauses when JSON modal is open)
    window.TRADING_BOT_AUTO_RELOAD_SEC = 15;

    async function refreshProfitState() {
        try {
            const res = await apiCall('api/profit/state', 'GET');
            if (!res || res.ok === false) {
                alert('Profit state failed: ' + ((res && (res.error || res.message)) || 'unknown'));
                return;
            }
            // easiest: reload to refresh rendered tables
            location.reload();
        } catch (e) {
            alert('Profit state request failed: ' + e.message);
        }
    }

    async function runProfitManager() {
        const btn = event.target.closest('button');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Running...';

        try {
            const res = await apiCall('api/profit/run', 'POST');
            if (!res || res.ok !== true) {
                const msg = (res && (res.error || res.message || (res.run && (res.run.error || res.run.status)))) || 'run_failed';
                alert('Run failed: ' + msg);
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-play-fill me-1"></i> Run now';
                return;
            }
            location.reload();
        } catch (e) {
            alert('Run request failed: ' + e.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-play-fill me-1"></i> Run now';
        }
    }
</script>

<?php
/* RULES
- Profit Manager UI is a TAB under Trading Bot (/admin/trading/profit)
- Manual run only: user clicks 'Run now' button (no auto-run on page load)
- API endpoints: /admin/trading/api/profit/state (GET), /admin/trading/api/profit/run (POST)
- LF only
*/
?>
