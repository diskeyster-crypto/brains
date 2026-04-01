<?php
/**
 * Coin Passport Module - Detail View (Microscopic Symbol Analytics)
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
    $fv = function ($key, int $dec = 2, string $suffix = '%') use ($passport): string {
        $val = $passport[$key] ?? null;
        if ($val === null) return '—';
        return number_format((float)$val, $dec) . $suffix;
    };
    $fv0 = function ($key, int $dec = 4) use ($passport): string {
        $val = $passport[$key] ?? null;
        if ($val === null) return '—';
        return number_format((float)$val, $dec);
    };
    $fvPct = function ($key) use ($passport): string {
        $val = $passport[$key] ?? null;
        if ($val === null) return '—';
        return number_format((float)$val * 100, 1) . '%';
    };
    $fvMin = function ($key) use ($passport): string {
        $val = $passport[$key] ?? null;
        if ($val === null) return '—';
        return number_format((float)$val, 1) . ' min';
    };

    $conf       = (string)($passport['data_confidence'] ?? 'none');
    $confBadges = ['high' => 'bg-success', 'medium' => 'bg-warning text-dark', 'low' => 'bg-danger', 'none' => 'bg-secondary'];
    $confBadge  = $confBadges[$conf] ?? 'bg-secondary';
    $sample     = (int)($passport['sample_size_total'] ?? $passport['sample_size'] ?? 0);
    $updatedAt  = (string)($passport['updated_at'] ?? '—');
    $notes      = $passport['notes'] ?? [];
    $harvest    = (string)($passport['recommended_harvest_aggressiveness'] ?? '—');
    $elig       = (string)($passport['recommended_live_eligibility'] ?? 'sim_only');
    $blockReason = (string)($passport['live_block_reason'] ?? '');
    $insuffFlag = !empty($passport['insufficient_data_flag']);

    $eligBadges = [
        'allow_live'  => ['bg-success', 'bi-check-circle', 'Live Eligible'],
        'sim_only'    => ['bg-warning text-dark', 'bi-cpu', 'Sim Only'],
        'shadow_only' => ['bg-secondary', 'bi-eye-slash', 'Shadow Only'],
        'reject'      => ['bg-danger', 'bi-x-circle', 'Reject'],
    ];
    [$eligCls, $eligIcon, $eligLabel] = $eligBadges[$elig] ?? ['bg-secondary', 'bi-question', $elig];

    $corridorP75 = (float)($passport['corridor_p75_roi'] ?? $passport['corridor_high_roi'] ?? 0);
    $corridorP90 = (float)($passport['corridor_p90_roi'] ?? $passport['p90_max_roi'] ?? 0);
    $corridorP50 = (float)($passport['corridor_p50_roi'] ?? $passport['corridor_mid_roi'] ?? 0);
    $corridorLow = (float)($passport['corridor_low_roi'] ?? 0);
    $maxForBar   = max($corridorP90, $corridorP75, 15.0);
    ?>

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-1">
                <i class="bi bi-passport me-2 text-primary"></i>
                <?= htmlspecialchars($symbol) ?>
            </h4>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <span class="badge <?= $eligCls ?>">
                    <i class="bi <?= $eligIcon ?> me-1"></i><?= htmlspecialchars($eligLabel) ?>
                </span>
                <span class="badge <?= $confBadge ?>">Confidence: <?= ucfirst($conf) ?></span>
                <span class="text-secondary small"><?= $sample ?> samples</span>
                <span class="text-secondary small">Updated: <?= htmlspecialchars(substr($updatedAt, 0, 16)) ?></span>
                <?php if ($insuffFlag): ?>
                <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>Insufficient Data</span>
                <?php endif; ?>
            </div>
            <?php if ($blockReason): ?>
            <div class="mt-1 text-secondary small"><i class="bi bi-info-circle me-1"></i>Block reason: <code class="text-warning"><?= htmlspecialchars($blockReason) ?></code></div>
            <?php endif; ?>
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

    <!-- Data Sufficiency Alert -->
    <?php if ($insuffFlag): ?>
    <div class="alert alert-warning mb-4" style="font-size:0.85rem;">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <strong>Insufficient Data Warning:</strong>
        <?= htmlspecialchars((string)($passport['insufficient_data_reason'] ?? 'Low sample count')) ?>.
        Symbol is in <strong><?= htmlspecialchars((string)($passport['fallback_mode'] ?? 'sim_only')) ?></strong> mode.
        Minimum required: <?= (int)($passport['minimum_required_samples'] ?? 10) ?> samples,
        current: <?= (int)($passport['current_usable_samples'] ?? 0) ?>.
    </div>
    <?php endif; ?>

    <!-- ROI Corridor Visual -->
    <div class="card mb-4">
        <div class="card-header"><h6 class="mb-0"><i class="bi bi-bar-chart me-1"></i>ROI Corridor (Favorable Move)</h6></div>
        <div class="card-body">
            <div class="row g-4">
                <div class="col-12">
                    <?php
                    $barScale = $maxForBar > 0 ? 100 / $maxForBar : 1;
                    $lowPct   = min(100, $corridorLow * $barScale);
                    $midPct   = min(100, $corridorP50 * $barScale);
                    $highPct  = min(100, $corridorP75 * $barScale);
                    $p90Pct   = min(100, $corridorP90 * $barScale);
                    ?>
                    <div class="mb-2 small text-secondary">
                        0% <span class="float-end"><?= number_format($maxForBar, 0) ?>% ROI</span>
                    </div>
                    <div style="position:relative; height:28px; background:#0f172a; border-radius:4px; overflow:hidden; border:1px solid #334155;">
                        <div style="position:absolute; left:<?= $lowPct ?>%; width:<?= max(0, $highPct - $lowPct) ?>%;
                                    height:100%; background: rgba(59,130,246,0.25);"></div>
                        <div style="position:absolute; left:<?= $midPct ?>%; width:2px; height:100%; background:#3b82f6;"></div>
                        <?php if ($p90Pct > 0): ?>
                        <div style="position:absolute; left:<?= $p90Pct ?>%; width:2px; height:100%; background:#f59e0b; opacity:0.7;"></div>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-3 mt-1" style="font-size:0.75rem;">
                        <span><span style="display:inline-block;width:10px;height:10px;background:rgba(59,130,246,0.25);border-radius:2px;"></span> Corridor P25–P75</span>
                        <span><span style="display:inline-block;width:3px;height:10px;background:#3b82f6;"></span> P50 (Mid)</span>
                        <span><span style="display:inline-block;width:3px;height:10px;background:#f59e0b;"></span> P90</span>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-value text-warning"><?= number_format($corridorLow, 1) ?>%</div>
                        <div class="stat-label">Corridor P25</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-value"><?= number_format($corridorP50, 1) ?>%</div>
                        <div class="stat-label">Corridor P50 (Mid)</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-value text-info"><?= number_format($corridorP75, 1) ?>%</div>
                        <div class="stat-label">Corridor P75</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="stat-card">
                        <div class="stat-value text-success"><?= number_format($corridorP90, 1) ?>%</div>
                        <div class="stat-label">Corridor P90</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Sample Sizes + Sufficiency -->
    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0"><i class="bi bi-database me-1"></i>Sample Sizes &amp; Data Sufficiency</h6></div>
                <div class="card-body p-0">
                    <table class="table table-dark mb-0" style="font-size:0.85rem;">
                        <tbody>
                            <tr><td>Total Samples</td><td class="text-end"><strong><?= $sample ?></strong></td></tr>
                            <tr><td>Pattern V2 Samples</td><td class="text-end"><?= (int)($passport['sample_size_short_v2'] ?? 0) ?></td></tr>
                            <tr><td>Pattern V3 Samples</td><td class="text-end"><?= (int)($passport['sample_size_short_v3'] ?? 0) ?></td></tr>
                            <tr><td>Minimum Required</td><td class="text-end text-secondary"><?= (int)($passport['minimum_required_samples'] ?? 10) ?></td></tr>
                            <tr><td>Data Confidence</td>
                                <td class="text-end">
                                    <span class="badge <?= $confBadge ?>"><?= ucfirst($conf) ?></span>
                                </td>
                            </tr>
                            <tr><td>Insufficient Data Flag</td>
                                <td class="text-end">
                                    <?php if ($insuffFlag): ?>
                                    <span class="badge bg-warning text-dark">Yes — <?= htmlspecialchars((string)($passport['insufficient_data_reason'] ?? '')) ?></span>
                                    <?php else: ?>
                                    <span class="badge bg-success">No</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr><td>Fallback Mode</td>
                                <td class="text-end"><code class="text-info"><?= htmlspecialchars((string)($passport['fallback_mode'] ?? 'live_eligible')) ?></code></td>
                            </tr>
                            <?php if ($passport['last_data_gap_warning'] ?? null): ?>
                            <tr><td colspan="2" class="text-warning small"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars((string)$passport['last_data_gap_warning']) ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Live Eligibility Gate -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0"><i class="bi bi-shield-check me-1"></i>Live Eligibility Gate</h6></div>
                <div class="card-body p-0">
                    <table class="table table-dark mb-0" style="font-size:0.85rem;">
                        <tbody>
                            <tr>
                                <td>Live Eligibility</td>
                                <td class="text-end">
                                    <span class="badge <?= $eligCls ?>"><i class="bi <?= $eligIcon ?> me-1"></i><?= htmlspecialchars($eligLabel) ?></span>
                                </td>
                            </tr>
                            <?php if ($blockReason): ?>
                            <tr>
                                <td>Block Reason</td>
                                <td class="text-end text-warning small"><?= htmlspecialchars($blockReason) ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr><td>Corridor P75 (gate ≥3.0%)</td>
                                <td class="text-end <?= $corridorP75 >= 3.0 ? 'positive' : 'negative' ?>"><?= number_format($corridorP75, 2) ?>%</td>
                            </tr>
                            <tr><td>Runner Prob (gate ≥5%)</td>
                                <td class="text-end <?= (float)($passport['runner_probability'] ?? 0) >= 0.05 ? 'positive' : 'negative' ?>"><?= $fvPct('runner_probability') ?></td>
                            </tr>
                            <tr><td>Short Suitability (gate ≥0.30)</td>
                                <td class="text-end <?= (float)($passport['short_suitability_score'] ?? 0) >= 0.3 ? 'positive' : 'negative' ?>"><?= $fv0('short_suitability_score') ?></td>
                            </tr>
                            <tr><td>Noise Score (gate ≤0.65)</td>
                                <td class="text-end <?= (float)($passport['noise_score'] ?? 1) <= 0.65 ? 'positive' : 'negative' ?>"><?= $fv0('noise_score') ?></td>
                            </tr>
                            <tr><td>Regime Health (gate ≥0.30)</td>
                                <td class="text-end <?= (float)($passport['market_regime_health_score'] ?? 0) >= 0.3 ? 'positive' : 'negative' ?>"><?= $fv0('market_regime_health_score') ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Behavioral Scores + Reach Rates -->
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
                            $scoreRow('Runner Probability',       $passport['runner_probability']        ?? null);
                            $scoreRow('Short Suitability',        $passport['short_suitability_score']   ?? null);
                            $scoreRow('Trend Persistence',        $passport['trend_persistence_score']   ?? null);
                            $scoreRow('SL Survival',              $passport['sl_survival_score']         ?? null);
                            $scoreRow('Market Regime Health',     $passport['market_regime_health_score']?? null);
                            $scoreRow('Volatility',               $passport['volatility_score']          ?? null, true);
                            $scoreRow('Noise Score',              $passport['noise_score']               ?? null, true);
                            $scoreRow('Fake Breakout Score',      $passport['fake_breakout_score']       ?? null, true);
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Reach Rates + SL -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0"><i class="bi bi-bullseye me-1"></i>Reach Rates &amp; Stop-Loss</h6></div>
                <div class="card-body p-0">
                    <table class="table table-dark mb-0" style="font-size:0.85rem;">
                        <tbody>
                            <tr><td>Reach 5% ROI Rate</td>   <td class="text-end positive"><?= $fvPct('reach_5_roi_rate') ?></td></tr>
                            <tr><td>Reach 10% ROI Rate</td>  <td class="text-end positive"><?= $fvPct('reach_10_roi_rate') ?></td></tr>
                            <tr><td>Reach 15% ROI Rate</td>  <td class="text-end positive"><?= $fvPct('reach_15_roi_rate') ?></td></tr>
                            <tr><td>Fail Before 3% ROI</td>  <td class="text-end negative"><?= $fvPct('failure_before_3_roi_rate') ?></td></tr>
                            <tr><td>Stop-Loss Hit Rate</td>   <td class="text-end negative"><?= $fvPct('stop_loss_hit_rate') ?></td></tr>
                            <tr><td>Median Max Favorable ROI</td><td class="text-end positive"><?= $fv('median_max_favorable_roi') ?></td></tr>
                            <tr><td>Median Max Adverse ROI</td>  <td class="text-end negative"><?= $fv('median_max_adverse_roi') ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Hold Times + Pullback -->
    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0"><i class="bi bi-clock me-1"></i>Timing Analytics</h6></div>
                <div class="card-body p-0">
                    <table class="table table-dark mb-0" style="font-size:0.85rem;">
                        <tbody>
                            <tr><td>Avg Hold Duration</td>       <td class="text-end"><?= $fvMin('avg_hold_minutes') ?></td></tr>
                            <tr><td>Avg Time to 2% ROI</td>      <td class="text-end"><?= $fvMin('avg_time_to_2_roi') ?></td></tr>
                            <tr><td>Avg Time to 5% ROI</td>      <td class="text-end"><?= $fvMin('avg_time_to_5_roi') ?></td></tr>
                            <tr><td>Avg Time to 10% ROI</td>     <td class="text-end"><?= $fvMin('avg_time_to_10_roi') ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0"><i class="bi bi-arrow-down-short me-1"></i>Pullback After Milestone ROI</h6></div>
                <div class="card-body p-0">
                    <table class="table table-dark mb-0" style="font-size:0.85rem;">
                        <tbody>
                            <tr><td>Median pullback after 2% ROI</td><td class="text-end negative"><?= $fv('median_pullback_after_2_roi') ?></td></tr>
                            <tr><td>Median pullback after 3% ROI</td><td class="text-end negative"><?= $fv('median_pullback_after_3_roi') ?></td></tr>
                            <tr><td>Median pullback after 5% ROI</td><td class="text-end negative"><?= $fv('median_pullback_after_5_roi') ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Recommendations -->
    <div class="card mb-4">
        <div class="card-header"><h6 class="mb-0"><i class="bi bi-lightbulb me-1"></i>Recommended Stage Thresholds &amp; Harvest</h6></div>
        <div class="card-body p-0">
            <div class="row g-0">
                <div class="col-md-6">
                    <table class="table table-dark mb-0" style="font-size:0.85rem;">
                        <tbody>
                            <tr>
                                <td>Live Floor ROI (min live target)</td>
                                <td class="text-end">
                                    <span class="badge bg-info bg-opacity-25 text-info"><?= $fv('recommended_live_floor_roi') ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td>Stage 1 Start ROI</td>
                                <td class="text-end">
                                    <span class="badge bg-warning bg-opacity-25 text-warning"><?= $fv('recommended_stage1_start_roi') ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td>Stage 2 Start ROI</td>
                                <td class="text-end">
                                    <span class="badge bg-success bg-opacity-25 text-success"><?= $fv('recommended_stage2_start_roi') ?></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-dark mb-0" style="font-size:0.85rem;">
                        <tbody>
                            <tr>
                                <td>Guaranteed Lock Start</td>
                                <td class="text-end">
                                    <span class="badge bg-primary bg-opacity-25 text-primary"><?= $fv('recommended_guaranteed_lock_start_roi') ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td>Ladder Mode</td>
                                <td class="text-end">
                                    <?php
                                    $ladder = (string)($passport['recommended_ladder_mode'] ?? '—');
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

    <!-- Diagnostics / Notes -->
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
