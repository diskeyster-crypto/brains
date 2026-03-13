<?php
/**
 * Brain Module - Passports View
 * 
 * Displays strategy passports - metadata and configuration for each strategy.
 */

use Core\System\System;

// Variables: $strategies, $flash
$brainUrl = rtrim(System::web('admin/brain'), '/');
$pageTitle = 'Brain - Passports';
$activeTab = 'passports';

$extraStyles = <<<CSS
.passport-card { transition: transform 0.2s; }
.passport-card:hover { transform: translateY(-2px); }
CSS;

$pageContent = function() use ($strategies, $brainUrl) {
?>
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-card-checklist me-2"></i>Strategy Passports</h4>
            <p class="text-secondary mb-0">Complete configuration details for each strategy</p>
        </div>
        <div>
            <span class="badge bg-primary fs-6"><?= count($strategies) ?> Strategies</span>
        </div>
    </div>

    <!-- Passports Grid -->
    <?php if (empty($strategies)): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-inbox display-1 text-secondary"></i>
            <h5 class="mt-3">No Strategies Found</h5>
            <p class="text-secondary">Create a strategy from the Dashboard to see its passport here.</p>
            <a href="<?= $brainUrl ?>" class="btn btn-primary">
                <i class="bi bi-plus me-1"></i> Create Strategy
            </a>
        </div>
    </div>
    <?php else: ?>
    <div class="row g-4">
        <?php foreach ($strategies as $id => $strategy): 
            // Extract constraints
            $constraints = $strategy['constraints'] ?? [];
            $roiTarget = $constraints['target_roi_pct'] ?? null;
            $maxDrawdown = $constraints['max_drawdown_pct'] ?? null;
            $session = $constraints['session'] ?? 'any';
            $direction = $constraints['direction'] ?? 'both';
            
            // Extract flags
            $simEnabled = $strategy['enabled_for_simulator'] ?? false;
            $execEnabled = $strategy['enabled_for_executor'] ?? false;
            
            // Safe date formatting: prefer created_ts (int), fallback to created_at (string), then "—"
            if (!empty($strategy['created_ts']) && is_numeric($strategy['created_ts'])) {
                $createdDisplay = date('Y-m-d H:i', (int)$strategy['created_ts']);
            } elseif (!empty($strategy['created_at']) && is_string($strategy['created_at'])) {
                $parsedTime = strtotime($strategy['created_at']);
                $createdDisplay = $parsedTime !== false ? date('Y-m-d H:i', $parsedTime) : '—';
            } else {
                $createdDisplay = '—';
            }
        ?>
        <div class="col-md-6 col-lg-4">
            <div class="card passport-card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">
                        <i class="bi bi-file-earmark-text me-2"></i>
                        <?= htmlspecialchars($strategy['name'] ?? $id) ?>
                    </h6>
                    <div>
                        <?php if ($simEnabled): ?>
                        <span class="badge bg-info">SIM</span>
                        <?php endif; ?>
                        <?php if ($execEnabled): ?>
                        <span class="badge bg-success">EXEC</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <table class="table table-dark table-sm mb-0">
                        <tbody>
                            <tr>
                                <td class="text-secondary">ID</td>
                                <td><code><?= htmlspecialchars($id) ?></code></td>
                            </tr>
                            <tr>
                                <td class="text-secondary">ROI Target</td>
                                <td><?= $roiTarget !== null ? htmlspecialchars($roiTarget) . '%' : 'N/A' ?></td>
                            </tr>
                            <tr>
                                <td class="text-secondary">Max Drawdown</td>
                                <td><?= $maxDrawdown !== null ? htmlspecialchars($maxDrawdown) . '%' : 'N/A' ?></td>
                            </tr>
                            <tr>
                                <td class="text-secondary">Session</td>
                                <td><?= htmlspecialchars($session) ?></td>
                            </tr>
                            <tr>
                                <td class="text-secondary">Direction</td>
                                <td><?= htmlspecialchars($direction) ?></td>
                            </tr>
                            <tr>
                                <td class="text-secondary">Created</td>
                                <td><?= htmlspecialchars($createdDisplay) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
<?php
};

require \Core\System\SystemPaths::instance()->get('system.brain') . '/views/_layout.php';
?>

<?php /* RULES
 * Views must include partials via SystemPaths / PackMap ONLY (no local path computations)
 */ ?>
