<?php
/**
 * Coin Passport Module - Detail View
 * Shows full passport for a single symbol.
 *
 * @var array<string,mixed>|null $passport
 * @var string                   $symbol
 * @var string                   $baseUrl
 */

$pageTitle  = 'Coin Passport — ' . htmlspecialchars($symbol);
$activeView = 'detail';

$extraScripts = <<<JS
<script>
document.addEventListener('DOMContentLoaded', function () {
    const rebuildBtn = document.getElementById('rebuildSymbolBtn');
    if (rebuildBtn) {
        rebuildBtn.addEventListener('click', function () {
            const sym = rebuildBtn.dataset.symbol;
            rebuildBtn.disabled = true;
            rebuildBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Rebuilding…';
            fetch('/admin/coin_passport/rebuild/' + encodeURIComponent(sym), { method: 'POST' })
                .then(r => r.json())
                .then(data => {
                    rebuildBtn.disabled = false;
                    rebuildBtn.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i>Rebuild Passport';
                    if (data.ok) {
                        showFlash('Passport rebuilt for ' + sym, 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showFlash('Rebuild failed.', 'danger');
                    }
                })
                .catch(() => {
                    rebuildBtn.disabled = false;
                    rebuildBtn.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i>Rebuild Passport';
                    showFlash('Request error.', 'danger');
                });
        });
    }
});
</script>
JS;

$pageContent = function () use ($passport, $symbol, $baseUrl) {
    if ($passport === null): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-inbox display-4 text-secondary d-block mb-3"></i>
            <h5>No passport found for <?= htmlspecialchars($symbol) ?></h5>
            <p class="text-secondary">Use <strong>Rebuild</strong> to build a passport from available trade data.</p>
            <button id="rebuildSymbolBtn" data-symbol="<?= htmlspecialchars($symbol) ?>"
                    class="btn btn-primary">
                <i class="bi bi-arrow-clockwise me-1"></i>Rebuild Passport
            </button>
        </div>
    </div>
    <?php return; endif;

    // Shorthand helpers
    $fv = function ($key, int $dec = 2) use ($passport): string {
        $val = $passport[$key] ?? null;
        if ($val === null) return '—';
        return number_format((float)$val, $dec) . '%';
    };
    $fv0 = function ($key) use ($passport): string {
        $val = $passport[$key] ?? null;
        if ($val === null) return '—';
        return number_format((float)$val, 4);
    };
    $conf       = (string)($passport['data_confidence'] ?? 'none');
    $confBadges = ['high' => 'bg-success', 'medium' => 'bg-warning text-dark', 'low' => 'bg-danger', 'none' => 'bg-secondary'];
    $confBadge  = $confBadges[$conf] ?? 'bg-secondary';
    $sample     = (int)($passport['sample_size'] ?? 0);
    $updatedAt  = (string)($passport['updated_at'] ?? '—');
    $notes      = $passport['notes'] ?? [];
    $ladder     = (string)($passport['recommended_ladder_mode'] ?? '—');
    $harvest    = (string)($passport['recommended_harvest_aggressiveness'] ?? '—');

    $corrLow  = (float)($passport['corridor_low_roi']  ?? 0);
    $corrMid  = (float)($passport['corridor_mid_roi']  ?? 0);
    $corrHigh = (float)($passport['corridor_high_roi'] ?? 0);
    $p90      = (float)($passport['p90_max_roi']       ?? 0);
    $maxForBar = max($p90, $corrHigh, 15.0);
    ?>

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-1">
                <i class="bi bi-passport me-2 text-primary"></i>
                <?= htmlspecialchars($symbol) ?>
            </h4>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <span class="badge <?= $confBadge ?>">
                    Confidence: <?= ucfirst($conf) ?>
                </span>
                <span class="text-secondary small"><?= $sample ?> samples</span>
                <span class="text-secondary small">Updated: <?= htmlspecialchars(substr($updatedAt, 0, 16)) ?></span>
            </div>
        </div>
        <div class="d-flex gap-2">
            <button id="rebuildSymbolBtn" data-symbol="<?= htmlspecialchars($symbol) ?>"
                    class="btn btn-sm btn-outline-primary">
                <i class="bi bi-arrow-clockwise me-1"></i>Rebuild Passport
            </button>
            <a href="<?= htmlspecialchars($baseUrl . '/api/passport/' . $symbol) ?>"
               class="btn btn-sm btn-outline-secondary" target="_blank">
                <i class="bi bi-code-slash me-1"></i>JSON
            </a>
        </div>
    </div>

    <!-- ROI Corridor Visual -->
    <div class="card mb-4">
        <div class="card-header"><h6 class="mb-0"><i class="bi bi-bar-chart me-1"></i>ROI Corridor</h6></div>
        <div class="card-body">
            <div class="row g-4">
                <!-- Corridor bar -->
                <div class="col-12">
                    <?php
                    $barScale = $maxForBar > 0 ? 100 / $maxForBar : 1;
                    $lowPct   = min(100, $corrLow  * $barScale);
                    $midPct   = min(100, $corrMid  * $barScale);
                    $highPct  = min(100, $corrHigh * $barScale);
                    $p90Pct   = min(100, $p90      * $barScale);
                    ?>
                    <div class="mb-2 small text-secondary">
                        0% <span class="float-end"><?= number_format($maxForBar, 0) ?>% ROI</span>
                    </div>
                    <div style="position:relative; height:28px; background:#0f172a; border-radius:4px; overflow:hidden; border:1px solid #334155;">
                        <!-- Corridor band -->
                        <div style="position:absolute; left:<?= $lowPct ?>%; width:<?= max(0, $highPct - $lowPct) ?>%;
                                    height:100%; background: rgba(59,130,246,0.25);"></div>
                        <!-- Mid line -->
                        <div style="position:absolute; left:<?= $midPct ?>%; width:2px; height:100%;
                                    background:#3b82f6;"></div>
                        <!-- P90 line -->
                        <?php if ($p90Pct > 0): ?>
                        <div style="position:absolute; left:<?= $p90Pct ?>%; width:2px; height:100%;
                                    background:#f59e0b; opacity:0.7;"></div>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-3 mt-1" style="font-size:0.75rem;">
                        <span><span style="display:inline-block;width:10px;height:10px;background:rgba(59,130,246,0.25);border-radius:2px;"></span> Corridor</span>
                        <span><span style="display:inline-block;width:3px;height:10px;background:#3b82f6;"></span> Mid</span>
                        <span><span style="display:inline-block;width:3px;height:10px;background:#f59e0b;"></span> P90</span>
                    </div>
                </div>

                <!-- Corridor numbers -->
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-value text-warning"><?= number_format($corrLow, 1) ?>%</div>
                        <div class="stat-label">Corridor Low (P25)</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-value"><?= number_format($corrMid, 1) ?>%</div>
                        <div class="stat-label">Corridor Mid (P50)</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-value text-info"><?= number_format($corrHigh, 1) ?>%</div>
                        <div class="stat-label">Corridor High (P75)</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-value text-success"><?= number_format($p90, 1) ?>%</div>
                        <div class="stat-label">P90 Max ROI</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Two-column: Behavioral scores + Recommendations -->
    <div class="row g-4 mb-4">
        <!-- Behavioral scores -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0"><i class="bi bi-activity me-1"></i>Behavioral Scores</h6></div>
                <div class="card-body p-0">
                    <table class="table table-dark mb-0" style="font-size:0.85rem;">
                        <tbody>
                            <?php
                            $scoreRow = function (string $label, $val, bool $lowerIsBetter = false) {
                                $v = $val !== null ? (float)$val : null;
                                if ($v === null) { echo "<tr><td>{$label}</td><td class='text-end neutral'>—</td></tr>"; return; }
                                $pct = min(100, $v * 100);
                                $cls = $lowerIsBetter
                                    ? ($v <= 0.3 ? 'bg-success' : ($v <= 0.6 ? 'bg-warning' : 'bg-danger'))
                                    : ($v >= 0.6 ? 'bg-success' : ($v >= 0.3 ? 'bg-warning' : 'bg-danger'));
                                echo "<tr>
                                    <td>{$label}</td>
                                    <td class='text-end' style='width:55%'>
                                        <div class='d-flex align-items-center gap-2 justify-content-end'>
                                            <div style='flex:1;max-width:100px;height:4px;background:#334155;border-radius:2px;overflow:hidden;'>
                                                <div style='width:{$pct}%;height:100%;' class='{$cls}'></div>
                                            </div>
                                            <span style='min-width:3.5em;text-align:right;font-size:0.78rem;'>" . number_format($v, 3) . "</span>
                                        </div>
                                    </td>
                                </tr>";
                            };
                            $scoreRow('Runner Probability',      $passport['runner_probability']       ?? null);
                            $scoreRow('Short Suitability',       $passport['short_suitability_score']  ?? null);
                            $scoreRow('Trend Persistence',       $passport['trend_persistence_score']  ?? null);
                            $scoreRow('Volatility',              $passport['volatility_score']         ?? null, true);
                            $scoreRow('Noise Score',             $passport['noise_score']              ?? null, true);
                            $scoreRow('Fake Breakout Score',     $passport['fake_breakout_score']      ?? null, true);
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Recommendations -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0"><i class="bi bi-lightbulb me-1"></i>Recommendations</h6></div>
                <div class="card-body p-0">
                    <table class="table table-dark mb-0" style="font-size:0.85rem;">
                        <tbody>
                            <tr>
                                <td>Guaranteed Lock Start</td>
                                <td class="text-end">
                                    <span class="badge bg-primary bg-opacity-25 text-primary">
                                        <?= $fv('recommended_guaranteed_lock_start_roi') ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td>Guaranteed Lock Value</td>
                                <td class="text-end">
                                    <span class="badge bg-info bg-opacity-25 text-info">
                                        <?= $fv('recommended_guaranteed_lock_value_roi') ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td>Stage 1 Threshold</td>
                                <td class="text-end">
                                    <span class="badge bg-warning bg-opacity-25 text-warning">
                                        <?= $fv('recommended_stage1_threshold_roi') ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td>Stage 2 Threshold</td>
                                <td class="text-end">
                                    <span class="badge bg-success bg-opacity-25 text-success">
                                        <?= $fv('recommended_stage2_threshold_roi') ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td>Ladder Mode</td>
                                <td class="text-end">
                                    <?php
                                    $ladderBadge = match($ladder) {
                                        'aggressive_ladder' => 'bg-success bg-opacity-25 text-success',
                                        'soft_ladder'       => 'bg-warning bg-opacity-25 text-warning',
                                        default             => 'bg-secondary bg-opacity-25 text-secondary',
                                    };
                                    ?>
                                    <span class="badge <?= $ladderBadge ?>"><?= htmlspecialchars($ladder) ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td>Harvest Aggressiveness</td>
                                <td class="text-end">
                                    <?php
                                    $harvestBadge = match($harvest) {
                                        'aggressive' => 'bg-danger bg-opacity-25 text-danger',
                                        'moderate'   => 'bg-warning bg-opacity-25 text-warning',
                                        default      => 'bg-success bg-opacity-25 text-success',
                                    };
                                    ?>
                                    <span class="badge <?= $harvestBadge ?>"><?= htmlspecialchars($harvest) ?></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ROI Stats + Pullback -->
    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0"><i class="bi bi-graph-up me-1"></i>Max ROI Distribution</h6></div>
                <div class="card-body p-0">
                    <table class="table table-dark mb-0" style="font-size:0.85rem;">
                        <tbody>
                            <tr><td>Median Max ROI (P50)</td><td class="text-end positive"><?= $fv('median_max_roi') ?></td></tr>
                            <tr><td>P75 Max ROI</td>          <td class="text-end positive"><?= $fv('p75_max_roi') ?></td></tr>
                            <tr><td>P90 Max ROI</td>          <td class="text-end positive"><?= $fv('p90_max_roi') ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0"><i class="bi bi-arrow-down-short me-1"></i>Typical Pullback After Milestone</h6></div>
                <div class="card-body p-0">
                    <table class="table table-dark mb-0" style="font-size:0.85rem;">
                        <tbody>
                            <tr><td>After reaching 2% ROI</td><td class="text-end negative"><?= $fv('median_pullback_after_2_roi') ?></td></tr>
                            <tr><td>After reaching 3% ROI</td><td class="text-end negative"><?= $fv('median_pullback_after_3_roi') ?></td></tr>
                            <tr><td>After reaching 5% ROI</td><td class="text-end negative"><?= $fv('median_pullback_after_5_roi') ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Notes / Diagnostics -->
    <?php if (!empty($notes)): ?>
    <div class="card mb-4">
        <div class="card-header"><h6 class="mb-0"><i class="bi bi-chat-left-text me-1"></i>Diagnostics</h6></div>
        <div class="card-body">
            <?php foreach ($notes as $note): ?>
            <div class="d-flex align-items-start gap-2 mb-1">
                <i class="bi bi-info-circle text-info mt-1" style="flex-shrink:0;"></i>
                <span style="font-size:0.85rem;"><?= htmlspecialchars($note) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Raw JSON API link -->
    <div class="text-secondary" style="font-size:0.75rem;">
        <i class="bi bi-code-slash me-1"></i>
        API:
        <a href="<?= htmlspecialchars($baseUrl . '/api/passport/' . $symbol) ?>" target="_blank" class="text-info">
            /admin/coin_passport/api/passport/<?= htmlspecialchars($symbol) ?>
        </a>
        &nbsp;·&nbsp;
        <a href="<?= htmlspecialchars($baseUrl . '/api/guidance/' . $symbol) ?>" target="_blank" class="text-info">
            /admin/coin_passport/api/guidance/<?= htmlspecialchars($symbol) ?>
        </a>
    </div>
    <?php
};

require __DIR__ . '/_layout.php';
