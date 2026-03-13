<?php
/**
 * Brain Module - Runtime Status Panel (v2.2 Блок 5)
 * 
 * Shows:
 * - Current status (IDLE|RUNNING|ERROR|STOPPED)
 * - Active PID
 * - Strategies in queue
 * - Kill Run button
 * - Last Run Summary
 * - Process telemetry
 * 
 * C1.2: Read ok/success and finished_at/timestamp with fallbacks for backward compatibility
 */

use Core\System\System;

// Variables: $runtimeInfo, $processTelemetry, $lastRun, $stats, $flash
$status = $runtimeInfo['status'] ?? 'IDLE';
$isRunning = $runtimeInfo['is_running'] ?? false;
$activeRunId = $runtimeInfo['active_run_id'] ?? null;
$activePid = $runtimeInfo['pid'] ?? null;
$startedAt = $runtimeInfo['started_at'] ?? null;
$queueCount = $runtimeInfo['strategies_queue_count'] ?? 0;

$statusColors = [
    'IDLE' => '#10b981',
    'RUNNING' => '#f59e0b',
    'ERROR' => '#ef4444',
    'STOPPED' => '#64748b',
];
$statusColor = $statusColors[$status] ?? '#64748b';

$brainUrl = rtrim(System::web('admin/brain'), '/');
$pageTitle = 'Brain - Runtime';
$activeTab = 'runtime';

$extraScripts = <<<SCRIPT
<script>
// C-3: Unified fetchJson helper for safe JSON response handling
async function fetchJson(url, options) {
    const resp = await fetch(url, options || {});
    const text = await resp.text();

    if (!resp.ok) {
        throw new Error('HTTP ' + resp.status + ': ' + text);
    }

    try {
        return JSON.parse(text);
    } catch (e) {
        throw new Error('Invalid JSON (' + resp.status + '): ' + text);
    }
}

// Kill current run
async function killRun() {
    if (!confirm('⚠️ Stop the current pipeline run?\\n\\nThis will kill all associated processes.')) {
        return;
    }
    
    const btn = document.getElementById('killRunBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Stopping...';
    
    try {
        const result = await fetchJson('{$brainUrl}/api/stop', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        if (result.success) {
            location.reload();
        } else {
            alert('Failed to stop: ' + (result.error || 'Unknown error'));
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-stop-fill"></i> Kill Run';
        }
    } catch (e) {
        alert('Error: ' + e.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-stop-fill"></i> Kill Run';
    }
}

// B1: Selftest function
async function runSelftest() {
    const btn = document.getElementById('selftestBtn');
    const resultDiv = document.getElementById('selftestResult');
    
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Testing...';
    resultDiv.style.display = 'none';
    
    try {
        const result = await fetchJson('{$brainUrl}/api/selftest', {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        resultDiv.innerHTML = '<pre style="font-size: 0.85rem; max-height: 400px; overflow: auto;">' + JSON.stringify(result, null, 2) + '</pre>';
        resultDiv.style.display = 'block';
        
        if (result.ok) {
            resultDiv.className = 'alert alert-success mt-3';
        } else {
            resultDiv.className = 'alert alert-danger mt-3';
        }
    } catch (e) {
        resultDiv.innerHTML = 'Error: ' + e.message;
        resultDiv.className = 'alert alert-danger mt-3';
        resultDiv.style.display = 'block';
    }
    
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-check2-circle"></i> Selftest';
}

// B1: Reset ALL function
async function resetAll() {
    if (!confirm('⚠️ DANGER: Reset ALL data?\\n\\nThis will DELETE:\\n- All Brain storage (signals, runs, learning, profiles)\\n- All Simulator storage (trades, datasets, stats)\\n\\nThis action cannot be undone!')) {
        return;
    }
    
    const confirmation = prompt('Type "yes" to confirm reset:');
    if (confirmation !== 'yes') {
        alert('Reset cancelled.');
        return;
    }
    
    const btn = document.getElementById('resetAllBtn');
    const resultDiv = document.getElementById('resetResult');
    
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Resetting...';
    resultDiv.style.display = 'none';
    
    try {
        const result = await fetchJson('{$brainUrl}/api/reset_all?confirm=yes', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        resultDiv.innerHTML = '<pre style="font-size: 0.85rem;">' + JSON.stringify(result, null, 2) + '</pre>';
        resultDiv.style.display = 'block';
        
        if (result.success) {
            resultDiv.className = 'alert alert-success mt-3';
            setTimeout(() => location.reload(), 2000);
        } else {
            resultDiv.className = 'alert alert-danger mt-3';
        }
    } catch (e) {
        resultDiv.innerHTML = 'Error: ' + e.message;
        resultDiv.className = 'alert alert-danger mt-3';
        resultDiv.style.display = 'block';
    }
    
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-trash3"></i> Reset ALL';
}
SCRIPT;

if ($isRunning) {
    $extraScripts .= "\nsetTimeout(function() { location.reload(); }, 10000);";
}
$extraScripts .= "\n</script>";

$pageContent = function() use ($status, $statusColor, $isRunning, $activeRunId, $activePid, $startedAt, $queueCount, $lastRun, $processTelemetry, $brainUrl) {
?>
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-activity me-2"></i>Runtime Monitor</h4>
            <p class="text-secondary mb-0">Live status of Brain orchestration pipeline</p>
        </div>
        <div>
            <span class="badge fs-6" style="background: <?= $statusColor ?>; padding: 8px 16px;">
                <?= htmlspecialchars($status) ?>
            </span>
        </div>
    </div>

    <!-- Current State Card -->
    <div class="card mb-4">
        <div class="card-header">
            <h6 class="mb-0"><i class="bi bi-cpu me-2"></i>Current State</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="p-3 rounded text-center" style="background: rgba(59, 130, 246, 0.1);">
                        <h4 class="mb-0" style="color: <?= $statusColor ?>;"><?= htmlspecialchars($status) ?></h4>
                        <small class="text-secondary">Status</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 rounded text-center" style="background: rgba(16, 185, 129, 0.1);">
                        <h4 class="mb-0"><?= $activePid ?? '—' ?></h4>
                        <small class="text-secondary">Active PID</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 rounded text-center" style="background: rgba(245, 158, 11, 0.1);">
                        <h4 class="mb-0"><?= $queueCount ?></h4>
                        <small class="text-secondary">Queue</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 rounded text-center" style="background: rgba(239, 68, 68, 0.1);">
                        <?php if ($isRunning): ?>
                            <button type="button" class="btn btn-danger" onclick="killRun()" id="killRunBtn">
                                <i class="bi bi-stop-fill"></i> Kill Run
                            </button>
                        <?php else: ?>
                            <span class="text-secondary">No active run</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <?php if ($activeRunId): ?>
            <hr style="border-color: var(--border-color);">
            <div class="row">
                <div class="col-md-6">
                    <strong>Active Run ID:</strong> <code><?= htmlspecialchars($activeRunId) ?></code>
                </div>
                <div class="col-md-6">
                    <strong>Started At:</strong> <code><?= htmlspecialchars($startedAt ?? 'N/A') ?></code>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Last Run Summary Card -->
    <div class="card mb-4">
        <div class="card-header">
            <h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>Last Run Summary</h6>
        </div>
        <div class="card-body">
            <?php if ($lastRun): 
                // C1.2: Backward compatibility fallbacks for old run records
                // Read ok/success with fallback (prefer success, fallback to ok)
                $isSuccessful = $lastRun['success'] ?? $lastRun['ok'] ?? false;
                
                // Read finished_at/timestamp with fallback
                $finishedAt = $lastRun['finished_at'] ?? $lastRun['timestamp'] ?? '—';
                
                // Read numeric fields with ?? 0 fallback
                $strategiesApplied = $lastRun['strategies_applied'] 
                    ?? count($lastRun['strategies'] ?? []) 
                    ?? ($lastRun['strategies_count'] ?? 0);
                $candidatesLoaded = $lastRun['candidates_loaded'] 
                    ?? ($lastRun['candidates_count'] ?? 0);
                $signalsGenerated = $lastRun['signals_generated'] 
                    ?? ($lastRun['signals_count'] ?? 0);
                $durationMs = $lastRun['duration_ms'] ?? 0;
            ?>
            <div class="row g-3">
                <div class="col-md-2">
                    <div class="p-3 rounded text-center" style="background: rgba(16, 185, 129, 0.1);">
                        <?php if ($isSuccessful): ?>
                        <span class="badge bg-success fs-6">OK</span>
                        <?php else: ?>
                        <span class="badge bg-danger fs-6">ERROR</span>
                        <?php endif; ?>
                        <br><small class="text-secondary">Status</small>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="p-3 rounded text-center" style="background: rgba(59, 130, 246, 0.1);">
                        <h4 class="mb-0"><?= (int)$strategiesApplied ?></h4>
                        <small class="text-secondary">Strategies</small>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="p-3 rounded text-center" style="background: rgba(245, 158, 11, 0.1);">
                        <h4 class="mb-0"><?= (int)$candidatesLoaded ?></h4>
                        <small class="text-secondary">Candidates</small>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="p-3 rounded text-center" style="background: rgba(16, 185, 129, 0.1);">
                        <h4 class="mb-0 text-success"><?= (int)$signalsGenerated ?></h4>
                        <small class="text-secondary">Signals</small>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="p-3 rounded text-center" style="background: rgba(100, 116, 139, 0.1);">
                        <h4 class="mb-0"><?= number_format((int)$durationMs) ?>ms</h4>
                        <small class="text-secondary">Duration</small>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="p-3 rounded text-center" style="background: rgba(100, 116, 139, 0.1);">
                        <code style="font-size: 0.8rem;"><?= htmlspecialchars((string)$finishedAt) ?></code>
                        <br><small class="text-secondary">Finished</small>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="text-center py-4 text-secondary">
                <i class="bi bi-inbox display-4 opacity-50"></i>
                <p class="mt-2 mb-0">No pipeline runs yet. Run the pipeline to see results here.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Process Telemetry Card -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-graph-up me-2"></i>Process Telemetry</h6>
            <span class="badge bg-secondary"><?= count($processTelemetry) ?> records</span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($processTelemetry)): ?>
            <div class="text-center py-5 text-secondary">
                <i class="bi bi-inbox display-4 opacity-50"></i>
                <p class="mt-2 mb-0">No telemetry records available.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                <table class="table table-dark table-hover mb-0">
                    <thead style="position: sticky; top: 0; background: var(--card-bg);">
                        <tr>
                            <th>Timestamp</th>
                            <th>Module</th>
                            <th>PID</th>
                            <th>Duration</th>
                            <th>Memory</th>
                            <th>Exit</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($processTelemetry as $entry): ?>
                        <?php 
                        $success = $entry['success'] ?? false;
                        $killed = $entry['killed'] ?? false;
                        $statusBadge = $killed ? 'warning' : ($success ? 'success' : 'danger');
                        $statusText = $killed ? 'KILLED' : ($success ? 'OK' : 'FAILED');
                        ?>
                        <tr>
                            <td><code style="font-size: 0.85rem;"><?= htmlspecialchars($entry['ts'] ?? 'N/A') ?></code></td>
                            <td><strong><?= htmlspecialchars($entry['module'] ?? 'unknown') ?></strong></td>
                            <td><?= htmlspecialchars((string)($entry['pid'] ?? '—')) ?></td>
                            <td><span style="color: #3b82f6;"><?= number_format((int)($entry['duration_ms'] ?? 0)) ?>ms</span></td>
                            <td><span style="color: #f59e0b;"><?= number_format((int)($entry['memory_peak_mb'] ?? 0)) ?>MB</span></td>
                            <td><code><?= htmlspecialchars((string)($entry['exit_code'] ?? '—')) ?></code></td>
                            <td>
                                <span class="badge bg-<?= $statusBadge ?>"><?= $statusText ?></span>
                                <?php if ($killed && !empty($entry['killed_reason'])): ?>
                                <br><small style="color: #f59e0b;"><?= htmlspecialchars($entry['killed_reason']) ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- B1: Diagnostic Tools Card -->
    <div class="card mb-4">
        <div class="card-header">
            <h6 class="mb-0"><i class="bi bi-tools me-2"></i>Diagnostic Tools</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="p-3 rounded" style="background: rgba(59, 130, 246, 0.1);">
                        <h6 class="mb-2"><i class="bi bi-check2-circle me-1"></i>Self-Test</h6>
                        <p class="text-secondary small mb-2">Check SystemPaths keys, files, and permissions</p>
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="runSelftest()" id="selftestBtn">
                            <i class="bi bi-check2-circle"></i> Selftest
                        </button>
                        <div id="selftestResult" style="display: none;" class="mt-3"></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 rounded" style="background: rgba(239, 68, 68, 0.1);">
                        <h6 class="mb-2"><i class="bi bi-trash3 me-1"></i>Reset ALL</h6>
                        <p class="text-secondary small mb-2">Clear Brain + Simulator storage (DANGER!)</p>
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="resetAll()" id="resetAllBtn">
                            <i class="bi bi-trash3"></i> Reset ALL
                        </button>
                        <div id="resetResult" style="display: none;" class="mt-3"></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 rounded" style="background: rgba(16, 185, 129, 0.1);">
                        <h6 class="mb-2"><i class="bi bi-info-circle me-1"></i>Info</h6>
                        <p class="text-secondary small mb-0">
                            <strong>Selftest:</strong> Validates configuration paths and file permissions.<br>
                            <strong>Reset ALL:</strong> Deletes all Brain and Simulator data.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php
};

require \Core\System\SystemPaths::instance()->get('system.brain') . '/views/_layout.php';
?>

<?php /* RULES
 * Views must include partials via SystemPaths / PackMap ONLY (no local path computations)
 */ ?>
