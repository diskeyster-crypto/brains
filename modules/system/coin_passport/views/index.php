<?php
/**
 * Coin Passport Module - Index View
 * Lists all coin passports with key stats.
 *
 * @var array<string,array<string,mixed>> $passports
 * @var int                               $count
 * @var string                            $baseUrl
 */

$pageTitle  = 'Coin Passports';
$activeView = 'index';

$extraScripts = <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Rebuild all passports
    const rebuildBtn = document.getElementById('rebuildAllBtn');
    if (rebuildBtn) {
        rebuildBtn.addEventListener('click', function () {
            rebuildBtn.disabled = true;
            rebuildBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Rebuilding…';

            fetch('/admin/coin_passport/rebuild', { method: 'POST' })
                .then(r => r.json())
                .then(data => {
                    rebuildBtn.disabled = false;
                    rebuildBtn.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i>Rebuild All';
                    if (data.ok) {
                        showFlash('Rebuilt ' + data.updated + ' passport(s).', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showFlash('Rebuild failed.', 'danger');
                    }
                })
                .catch(() => {
                    rebuildBtn.disabled = false;
                    rebuildBtn.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i>Rebuild All';
                    showFlash('Request error.', 'danger');
                });
        });
    }

    // Symbol filter
    const searchInput = document.getElementById('symbolSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            const q = this.value.toLowerCase();
            document.querySelectorAll('tbody tr[data-symbol]').forEach(function (row) {
                row.style.display = row.dataset.symbol.includes(q) ? '' : 'none';
            });
        });
    }
});
</script>
JS;

$pageContent = function () use ($passports, $count, $baseUrl, $status) {
    $confidenceBadge = function (string $conf): string {
        $classes = [
            'high'   => 'badge-conf-high',
            'medium' => 'badge-conf-medium',
            'low'    => 'badge-conf-low',
            'none'   => 'badge-conf-none',
        ];
        $cls = $classes[$conf] ?? 'badge-conf-none';
        return '<span class="badge ' . $cls . '">' . htmlspecialchars(ucfirst($conf)) . '</span>';
    };

    $roiCell = function (?float $val): string {
        if ($val === null || $val === 0.0) {
            return '<span class="neutral">—</span>';
        }
        $cls = $val >= 0 ? 'positive' : 'negative';
        return '<span class="' . $cls . '">' . number_format($val, 1) . '%</span>';
    };
    ?>
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-passport me-2 text-primary"></i>Coin Passports</h4>
            <p class="text-secondary mb-0">Persistent per-symbol ROI corridor &amp; trailing intelligence</p>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge bg-primary fs-6"><?= $count ?> Passports</span>
            <button id="rebuildAllBtn" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-arrow-clockwise me-1"></i>Rebuild All
            </button>
        </div>
    </div>

    <!-- Rebuild Status Bar -->
    <?php
        $lastAt    = $status['last_rebuild_at'] ?? null;
        $lastMode  = $status['last_rebuild_mode'] ?? null;
        $lastStat  = $status['last_rebuild_status'] ?? 'never';
        $lastErr   = $status['last_rebuild_error'] ?? null;
        $lastCount = (int)($status['last_updated_count'] ?? 0);
        $storagePath = $status['storage_path'] ?? '';
        $statusCls = match($lastStat) {
            'ok'            => 'text-success',
            'partial_error' => 'text-warning',
            default         => 'text-secondary',
        };
    ?>
    <div class="mb-4 p-3 rounded" style="background:#1e293b; border:1px solid #334155; font-size:0.8rem;">
        <div class="d-flex flex-wrap gap-3 align-items-center">
            <span class="text-secondary"><i class="bi bi-hdd me-1"></i>Source of truth:
                <code class="text-info ms-1"><?= htmlspecialchars($storagePath ?: __DIR__ . '/../storage/passports') ?></code>
            </span>
            <span class="<?= $statusCls ?>">
                <i class="bi bi-circle-fill me-1" style="font-size:0.6rem;"></i>
                Status: <strong><?= htmlspecialchars($lastStat) ?></strong>
            </span>
            <?php if ($lastAt): ?>
            <span class="text-secondary">Last rebuild: <strong class="text-light"><?= htmlspecialchars($lastAt) ?></strong></span>
            <span class="text-secondary">Mode: <code class="text-info"><?= htmlspecialchars((string)$lastMode) ?></code></span>
            <span class="text-secondary">Updated: <strong class="text-light"><?= $lastCount ?></strong> symbol(s)</span>
            <?php endif; ?>
            <?php if ($lastErr): ?>
            <span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars((string)$lastErr) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Summary stats -->
    <?php if ($count > 0):
        $highConf   = count(array_filter($passports, fn($p) => ($p['data_confidence'] ?? '') === 'high'));
        $runners    = count(array_filter($passports, fn($p) => (float)($p['runner_probability'] ?? 0) >= 0.1));
        $avgMedian  = count($passports) > 0 ? array_sum(array_column($passports, 'median_max_roi')) / count($passports) : 0;
    ?>
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-value"><?= $count ?></div>
                <div class="stat-label">Symbols tracked</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-value"><?= $highConf ?></div>
                <div class="stat-label">High confidence</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-value"><?= $runners ?></div>
                <div class="stat-label">Runner coins (≥10%)</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-value"><?= number_format($avgMedian, 1) ?>%</div>
                <div class="stat-label">Avg median max ROI</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filter -->
    <div class="mb-3">
        <input type="text" id="symbolSearch" class="form-control form-control-sm"
               style="max-width:260px; background:#1e293b; border-color:#334155; color:#f1f5f9;"
               placeholder="Filter by symbol…">
    </div>

    <!-- Passports Table -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-table me-1"></i>Passports (<?= $count ?>)</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-dark table-hover mb-0" style="font-size:0.82rem;">
                    <thead>
                        <tr>
                            <th>Symbol</th>
                            <th class="text-center">Confidence</th>
                            <th class="text-center">Samples</th>
                            <th class="text-end">Corridor Low</th>
                            <th class="text-end">Corridor Mid</th>
                            <th class="text-end">Corridor High</th>
                            <th class="text-end">Median Max</th>
                            <th class="text-end">P75 Max</th>
                            <th class="text-end">P90 Max</th>
                            <th class="text-end">Runner %</th>
                            <th class="text-center">Rec. Lock Start</th>
                            <th class="text-center">Ladder Mode</th>
                            <th class="text-center">Harvest</th>
                            <th class="text-center">Updated</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($passports)): ?>
                        <tr>
                            <td colspan="15" class="text-center text-secondary py-5">
                                <i class="bi bi-inbox display-5 d-block mb-2"></i>
                                No passports yet — click <strong>Rebuild All</strong> to scan available trades.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($passports as $sym => $p): ?>
                        <?php
                            $conf       = $p['data_confidence'] ?? 'none';
                            $sample     = (int)($p['sample_size'] ?? 0);
                            $corrLow    = (float)($p['corridor_low_roi'] ?? 0);
                            $corrMid    = (float)($p['corridor_mid_roi'] ?? 0);
                            $corrHigh   = (float)($p['corridor_high_roi'] ?? 0);
                            $medMax     = (float)($p['median_max_roi'] ?? 0);
                            $p75        = (float)($p['p75_max_roi'] ?? 0);
                            $p90        = (float)($p['p90_max_roi'] ?? 0);
                            $runnerProb = (float)($p['runner_probability'] ?? 0);
                            $lockStart  = (float)($p['recommended_guaranteed_lock_start_roi'] ?? 0);
                            $ladder     = (string)($p['recommended_ladder_mode'] ?? '—');
                            $harvest    = (string)($p['recommended_harvest_aggressiveness'] ?? '—');
                            $updatedAt  = (string)($p['updated_at'] ?? '—');
                        ?>
                        <tr data-symbol="<?= strtolower(htmlspecialchars($sym)) ?>">
                            <td>
                                <strong><?= htmlspecialchars($sym) ?></strong>
                            </td>
                            <td class="text-center"><?= $confidenceBadge($conf) ?></td>
                            <td class="text-center"><?= $sample ?></td>
                            <td class="text-end"><?= $roiCell($corrLow) ?></td>
                            <td class="text-end"><?= $roiCell($corrMid) ?></td>
                            <td class="text-end"><?= $roiCell($corrHigh) ?></td>
                            <td class="text-end"><?= $roiCell($medMax) ?></td>
                            <td class="text-end"><?= $roiCell($p75) ?></td>
                            <td class="text-end"><?= $roiCell($p90) ?></td>
                            <td class="text-end">
                                <?php if ($runnerProb >= 0.1): ?>
                                    <span class="positive"><?= number_format($runnerProb * 100, 1) ?>%</span>
                                <?php else: ?>
                                    <span class="neutral"><?= number_format($runnerProb * 100, 1) ?>%</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($lockStart > 0): ?>
                                    <span class="badge bg-primary bg-opacity-25 text-primary"><?= number_format($lockStart, 1) ?>%</span>
                                <?php else: ?>
                                    <span class="neutral">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php
                                $ladderBadge = match($ladder) {
                                    'aggressive_ladder' => 'bg-success bg-opacity-25 text-success',
                                    'soft_ladder'       => 'bg-warning bg-opacity-25 text-warning',
                                    default             => 'bg-secondary bg-opacity-25 text-secondary',
                                };
                                ?>
                                <span class="badge <?= $ladderBadge ?>"><?= htmlspecialchars($ladder) ?></span>
                            </td>
                            <td class="text-center">
                                <?php
                                $harvestBadge = match($harvest) {
                                    'aggressive' => 'bg-danger bg-opacity-25 text-danger',
                                    'moderate'   => 'bg-warning bg-opacity-25 text-warning',
                                    default      => 'bg-success bg-opacity-25 text-success',
                                };
                                ?>
                                <span class="badge <?= $harvestBadge ?>"><?= htmlspecialchars($harvest) ?></span>
                            </td>
                            <td class="text-center text-secondary" style="font-size:0.75rem; white-space:nowrap;">
                                <?= htmlspecialchars(substr($updatedAt, 0, 16)) ?>
                            </td>
                            <td class="text-end">
                                <a href="<?= htmlspecialchars($baseUrl . '/symbol/' . $sym) ?>"
                                   class="btn btn-sm btn-outline-primary py-0 px-2">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- API reference note -->
    <div class="mt-3 text-secondary" style="font-size:0.75rem;">
        <i class="bi bi-info-circle me-1"></i>
        API endpoints: <code class="text-info">/admin/coin_passport/api/passports</code> ·
        <code class="text-info">/admin/coin_passport/api/passport/{SYMBOL}</code> ·
        <code class="text-info">/admin/coin_passport/api/guidance/{SYMBOL}</code>
    </div>
    <?php
};

require __DIR__ . '/_layout.php';
