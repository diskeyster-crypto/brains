<?php
/**
 * Coin Passport Module - Index View
 * Lists all coin passports ranked by live eligibility, confidence, corridor quality.
 *
 * @var array<string,array<string,mixed>> $passports
 * @var int                               $count
 * @var string                            $baseUrl
 * @var array<string,mixed>               $status
 * @var array<string,mixed>               $health
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

    // Sort by eligibility column
    document.querySelectorAll('th[data-sort]').forEach(function(th) {
        th.style.cursor = 'pointer';
        th.addEventListener('click', function() {
            const col = th.dataset.sort;
            const tbody = document.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr[data-symbol]'));
            const asc = th.dataset.dir !== 'asc';
            th.dataset.dir = asc ? 'asc' : 'desc';
            rows.sort((a, b) => {
                const av = parseFloat(a.dataset[col] || 0);
                const bv = parseFloat(b.dataset[col] || 0);
                return asc ? av - bv : bv - av;
            });
            rows.forEach(r => tbody.appendChild(r));
        });
    });
});
</script>
JS;

$pageContent = function () use ($passports, $count, $baseUrl, $status, $health) {
    $eligBadge = function (string $elig): string {
        $map = [
            'allow_live'  => ['bg-success', 'bi-check-circle', 'Live'],
            'sim_only'    => ['bg-warning text-dark', 'bi-cpu', 'Sim Only'],
            'shadow_only' => ['bg-secondary', 'bi-eye-slash', 'Shadow'],
            'reject'      => ['bg-danger', 'bi-x-circle', 'Reject'],
        ];
        [$cls, $icon, $label] = $map[$elig] ?? ['bg-secondary', 'bi-question', $elig];
        return '<span class="badge ' . $cls . '"><i class="bi ' . $icon . ' me-1"></i>' . htmlspecialchars($label) . '</span>';
    };

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

    $pctCell = function (?float $val): string {
        if ($val === null) return '<span class="neutral">—</span>';
        $cls = $val >= 0.1 ? 'positive' : ($val >= 0.05 ? 'text-warning' : 'neutral');
        return '<span class="' . $cls . '">' . number_format($val * 100, 1) . '%</span>';
    };

    // Sort passports: allow_live first, then sim_only, then shadow_only/reject
    $eligOrder = ['allow_live' => 0, 'sim_only' => 1, 'shadow_only' => 2, 'reject' => 3];
    uasort($passports, function ($a, $b) use ($eligOrder) {
        $ea = $eligOrder[$a['recommended_live_eligibility'] ?? 'sim_only'] ?? 2;
        $eb = $eligOrder[$b['recommended_live_eligibility'] ?? 'sim_only'] ?? 2;
        if ($ea !== $eb) return $ea - $eb;
        // Secondary: data_confidence (high > medium > low > none)
        $confRank = ['high' => 3, 'medium' => 2, 'low' => 1, 'none' => 0];
        $ca = $confRank[$a['data_confidence'] ?? 'none'] ?? 0;
        $cb = $confRank[$b['data_confidence'] ?? 'none'] ?? 0;
        return $cb - $ca;
    });

    ?>
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-passport me-2 text-primary"></i>Coin Passports</h4>
            <p class="text-secondary mb-0">Microscopic per-symbol analytics &amp; live eligibility gate</p>
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

    <!-- Market Health Summary (Phase 6) -->
    <?php if ($count > 0): ?>
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-2">
            <div class="stat-card">
                <div class="stat-value text-success"><?= (int)($health['allow_live_count'] ?? 0) ?></div>
                <div class="stat-label">Live Eligible</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="stat-card">
                <div class="stat-value text-warning"><?= (int)($health['sim_only_count'] ?? 0) ?></div>
                <div class="stat-label">Sim Only</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="stat-card">
                <div class="stat-value text-secondary"><?= (int)($health['shadow_only_count'] ?? 0) ?></div>
                <div class="stat-label">Shadow Only</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="stat-card">
                <div class="stat-value text-info"><?= (int)($health['corridor_p75_healthy_count'] ?? 0) ?></div>
                <div class="stat-label">P75 Corridor ≥3%</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="stat-card">
                <div class="stat-value"><?= (int)($health['runner_healthy_count'] ?? 0) ?></div>
                <div class="stat-label">Runner Coins</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="stat-card">
                <div class="stat-value text-danger"><?= (int)($health['low_confidence_only_count'] ?? 0) ?></div>
                <div class="stat-label">Low Confidence</div>
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
            <h5 class="mb-0"><i class="bi bi-table me-1"></i>Passports (<?= $count ?>)
                <span class="text-secondary small ms-2" style="font-size:0.75rem;">Ranked by live eligibility → confidence → corridor</span>
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-dark table-hover mb-0" style="font-size:0.82rem;">
                    <thead>
                        <tr>
                            <th>Symbol</th>
                            <th class="text-center">Eligibility</th>
                            <th class="text-center">Confidence</th>
                            <th class="text-center">Samples</th>
                            <th class="text-end" data-sort="p75">Corridor P75</th>
                            <th class="text-end" data-sort="p90">Corridor P90</th>
                            <th class="text-end" data-sort="runner">Runner %</th>
                            <th class="text-end" data-sort="impulse">Impulse</th>
                            <th class="text-end" data-sort="noise">Noise</th>
                            <th class="text-end" data-sort="reach5">Reach 5%</th>
                            <th class="text-end" data-sort="slrate">SL Rate</th>
                            <th class="text-end" data-sort="v2succ">V2 Succ</th>
                            <th class="text-end" data-sort="v3succ">V3 Succ</th>
                            <th class="text-end">Regime</th>
                            <th class="text-center">Harvest</th>
                            <th class="text-center">Updated</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($passports)): ?>
                        <tr>
                            <td colspan="17" class="text-center text-secondary py-5">
                                <i class="bi bi-inbox display-5 d-block mb-2"></i>
                                No passports yet — click <strong>Rebuild All</strong> to scan available trades.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($passports as $sym => $p):
                            $elig       = (string)($p['recommended_live_eligibility'] ?? 'sim_only');
                            $conf       = $p['data_confidence'] ?? 'none';
                            $sample     = (int)($p['sample_size_total'] ?? $p['sample_size'] ?? 0);
                            $p75        = (float)($p['corridor_p75_roi'] ?? $p['corridor_high_roi'] ?? 0);
                            $p90        = (float)($p['corridor_p90_roi'] ?? $p['p90_max_roi'] ?? 0);
                            $runnerProb = (float)($p['runner_probability'] ?? 0);
                            $impulse    = (float)($p['impulse_strength_score'] ?? 0);
                            $noise      = (float)($p['noise_score'] ?? 0);
                            $reach5     = (float)($p['reach_5_roi_rate'] ?? 0);
                            $slRate     = (float)($p['stop_loss_hit_rate'] ?? 0);
                            $regime     = (float)($p['market_regime_health_score'] ?? 0);
                            $harvest    = (string)($p['recommended_harvest_aggressiveness'] ?? '—');
                            $updatedAt  = (string)($p['updated_at'] ?? '—');
                            $blockReason = (string)($p['live_block_reason'] ?? '');
                            $insuffFlag = !empty($p['insufficient_data_flag']);
                            $pb         = is_array($p['pattern_behavior'] ?? null) ? $p['pattern_behavior'] : [];
                            $v2Succ     = $pb['v2_success_rate'] ?? null;
                            $v3Succ     = $pb['v3_success_rate'] ?? null;
                        ?>
                        <tr data-symbol="<?= strtolower(htmlspecialchars($sym)) ?>"
                            data-p75="<?= $p75 ?>" data-p90="<?= $p90 ?>"
                            data-runner="<?= $runnerProb ?>" data-impulse="<?= $impulse ?>"
                            data-noise="<?= $noise ?>" data-reach5="<?= $reach5 ?>" data-slrate="<?= $slRate ?>"
                            data-v2succ="<?= $v2Succ ?? 0 ?>" data-v3succ="<?= $v3Succ ?? 0 ?>">
                            <td>
                                <strong><?= htmlspecialchars($sym) ?></strong>
                                <?php if ($insuffFlag): ?>
                                <i class="bi bi-exclamation-triangle text-warning ms-1" title="Insufficient data"></i>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?= $eligBadge($elig) ?>
                                <?php if ($blockReason): ?>
                                <div style="font-size:0.65rem; color:#64748b; max-width:120px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($blockReason) ?>"><?= htmlspecialchars($blockReason) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><?= $confidenceBadge($conf) ?></td>
                            <td class="text-center"><?= $sample ?></td>
                            <td class="text-end"><?= $roiCell($p75) ?></td>
                            <td class="text-end"><?= $roiCell($p90) ?></td>
                            <td class="text-end"><?= $pctCell($runnerProb) ?></td>
                            <td class="text-end">
                                <?php $impCls = $impulse >= 0.5 ? 'positive' : ($impulse >= 0.25 ? 'text-warning' : 'neutral'); ?>
                                <span class="<?= $impCls ?>"><?= number_format($impulse, 3) ?></span>
                            </td>
                            <td class="text-end">
                                <?php
                                $noiseCls = $noise <= 0.3 ? 'positive' : ($noise <= 0.6 ? 'text-warning' : 'negative');
                                ?>
                                <span class="<?= $noiseCls ?>"><?= number_format($noise, 3) ?></span>
                            </td>
                            <td class="text-end"><?= $pctCell($reach5) ?></td>
                            <td class="text-end">
                                <?php $slCls = $slRate <= 0.2 ? 'positive' : ($slRate <= 0.4 ? 'text-warning' : 'negative'); ?>
                                <span class="<?= $slCls ?>"><?= number_format($slRate * 100, 1) ?>%</span>
                            </td>
                            <td class="text-end">
                                <?= $v2Succ !== null ? '<span class="' . ($v2Succ >= 0.5 ? 'positive' : 'negative') . '">' . number_format($v2Succ * 100, 0) . '%</span>' : '<span class="neutral">—</span>' ?>
                            </td>
                            <td class="text-end">
                                <?= $v3Succ !== null ? '<span class="' . ($v3Succ >= 0.5 ? 'positive' : 'negative') . '">' . number_format($v3Succ * 100, 0) . '%</span>' : '<span class="neutral">—</span>' ?>
                            </td>
                            <td class="text-end">
                                <?php $regimeCls = $regime >= 0.6 ? 'positive' : ($regime >= 0.3 ? 'text-warning' : 'negative'); ?>
                                <span class="<?= $regimeCls ?>"><?= number_format($regime, 2) ?></span>
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
        API: <code class="text-info">/admin/coin_passport/api/passports</code> ·
        <code class="text-info">/admin/coin_passport/api/passport/{SYMBOL}</code> ·
        <code class="text-info">/admin/coin_passport/api/guidance/{SYMBOL}</code> ·
        <code class="text-info">/admin/coin_passport/api/market_health</code>
    </div>
    <?php
};

require __DIR__ . '/_layout.php';
