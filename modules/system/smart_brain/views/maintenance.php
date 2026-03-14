<?php
/**
 * Smart Brain Module - Maintenance View
 *
 * Three cleanup actions:
 *   Soft Cleanup        — clear temporary runtime artifacts
 *   Reset Simulator     — reset simulator state only
 *   Full Runtime Reset  — clear all runtime + simulator data
 */

/** @var string $smartBrainUrl */
/** @var array{type:string,message:string}|null $flash */

$pageTitle = 'Smart Brain - Maintenance';
$activeTab = 'maintenance';

$pageContent = function() use ($smartBrainUrl) {
?>
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-wrench-adjustable me-2 text-primary"></i>Maintenance</h4>
            <p class="text-secondary mb-0">Controlled cleanup of runtime artifacts — Brain knowledge is always preserved</p>
        </div>
    </div>

    <!-- Protected Notice -->
    <div class="alert alert-info mb-4">
        <i class="bi bi-shield-check me-1"></i>
        <strong>Protected:</strong> Coin passports, user configuration, and base config files are <strong>never</strong> deleted by any cleanup operation.
    </div>

    <div class="row">
        <!-- Soft Cleanup -->
        <div class="col-md-4 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center">
                    <i class="bi bi-brush me-2 text-info"></i>
                    <h5 style="margin: 0;">Soft Cleanup</h5>
                </div>
                <div class="card-body">
                    <p class="text-secondary">Clears temporary runtime artifacts.</p>
                    <ul class="text-secondary small mb-3">
                        <li>candidates.json, monitors.json, signals.json</li>
                        <li>last_run.json, brain.lock</li>
                        <li>All log files</li>
                        <li>Simulator waiting queue</li>
                    </ul>
                    <p class="text-secondary small mb-3"><strong>Keeps:</strong> active/closed simulator trades, stats, passports, config</p>
                    <form method="POST" action="<?= htmlspecialchars($smartBrainUrl) ?>/cleanup/soft"
                          onsubmit="return confirm('Run Soft Cleanup? This will clear temporary runtime files.');">
                        <button type="submit" class="btn btn-outline-info w-100">
                            <i class="bi bi-brush me-1"></i> Soft Cleanup
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Reset Simulator -->
        <div class="col-md-4 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center">
                    <i class="bi bi-arrow-counterclockwise me-2 text-warning"></i>
                    <h5 style="margin: 0;">Reset Simulator</h5>
                </div>
                <div class="card-body">
                    <p class="text-secondary">Resets simulator state without touching passports.</p>
                    <ul class="text-secondary small mb-3">
                        <li>waiting.json, active.json</li>
                        <li>closed.json, stats.json</li>
                    </ul>
                    <p class="text-secondary small mb-3"><strong>Keeps:</strong> all runtime files, passports, config</p>
                    <form method="POST" action="<?= htmlspecialchars($smartBrainUrl) ?>/cleanup/simulator"
                          onsubmit="return confirm('Reset Simulator? All simulator trades and stats will be deleted.');">
                        <button type="submit" class="btn btn-outline-warning w-100">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> Reset Simulator
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Full Runtime Reset -->
        <div class="col-md-4 mb-4">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center">
                    <i class="bi bi-exclamation-triangle me-2 text-danger"></i>
                    <h5 style="margin: 0;">Full Runtime Reset</h5>
                </div>
                <div class="card-body">
                    <p class="text-secondary">Resets all runtime data but keeps Brain knowledge.</p>
                    <ul class="text-secondary small mb-3">
                        <li>All temporary runtime files</li>
                        <li>All log files</li>
                        <li>All simulator state</li>
                    </ul>
                    <p class="text-secondary small mb-3"><strong>Keeps:</strong> passports, user config, base config</p>
                    <form method="POST" action="<?= htmlspecialchars($smartBrainUrl) ?>/cleanup/full"
                          onsubmit="return confirm('Full Runtime Reset? This will delete ALL runtime and simulator data.');">
                        <button type="submit" class="btn btn-outline-danger w-100">
                            <i class="bi bi-exclamation-triangle me-1"></i> Full Runtime Reset
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php
};

require __DIR__ . '/_layout.php';
