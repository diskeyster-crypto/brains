<?php
/**
 * Smart Brain — Patterns Control Page
 *
 * Brain reads from the new Pattern Engine (the active upstream pattern source).
 * All pattern detection / signal normalisation / scenario decisions are produced
 * by modules/system/pattern_engine/. Brain is control-plane only.
 *
 * The legacy Brain pattern path (parser4_analyzer) remains in the codebase but
 * is no longer the active upstream source for downstream execution.
 *
 * @var string                          $smartBrainUrl
 * @var bool                            $pe_available
 * @var string|null                     $pe_error
 * @var array<string,mixed>             $pe_config
 * @var array<string,mixed>             $pe_last_run
 * @var array<string,mixed>             $pe_stats
 * @var list<array<string,mixed>>       $pe_candidates
 * @var list<array<string,mixed>>       $pe_signals
 * @var list<array<string,mixed>>       $pe_scenarios
 */

$pageTitle = 'Smart Brain — Patterns';
$activeTab = 'patterns';

$pe_available  = $pe_available  ?? false;
$pe_error      = $pe_error      ?? null;
$pe_config     = $pe_config     ?? [];
$pe_last_run   = $pe_last_run   ?? [];
$pe_stats      = $pe_stats      ?? [];
$pe_candidates = $pe_candidates ?? [];
$pe_signals    = $pe_signals    ?? [];
$pe_scenarios  = $pe_scenarios  ?? [];

$liveOutputEnabled = (bool)($pe_config['live_output_enabled'] ?? false);

$extraStyles = '
.pe-badge-allow_live    { background: #dc2626; }
.pe-badge-allow_demo    { background: #2563eb; }
.pe-badge-shadow_only   { background: #7c3aed; }
.pe-badge-sim_only      { background: #d97706; }
.pe-badge-rejected      { background: #6b7280; }
.pe-badge-pending       { background: #374151; }
.pe-stat-card { background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 1rem; text-align: center; }
.pe-stat-value { font-size: 1.5rem; font-weight: 700; color: #3b82f6; }
.pe-stat-label { font-size: 0.72rem; color: #94a3b8; margin-top: 0.25rem; }
.live-disabled-badge { background: rgba(220,38,38,0.15); color: #f87171; border: 1px solid rgba(220,38,38,0.3); border-radius: 6px; padding: 0.25rem 0.65rem; font-size: 0.75rem; }
';

$pageContent = function() use (
    $smartBrainUrl,
    $pe_available, $pe_error, $pe_config, $pe_last_run, $pe_stats,
    $pe_candidates, $pe_signals, $pe_scenarios, $liveOutputEnabled
) {
    $candidatesCount = count($pe_candidates);
    $signalsCount    = count($pe_signals);
    $scenariosCount  = count($pe_scenarios);
    $lastRunAt       = $pe_last_run['generated_at'] ?? $pe_stats['last_run']['generated_at'] ?? null;

    $scenarioStatusBadge = static function(string $status): string {
        $map = [
            'allow_live'  => 'pe-badge-allow_live',
            'allow_demo'  => 'pe-badge-allow_demo',
            'shadow_only' => 'pe-badge-shadow_only',
            'sim_only'    => 'pe-badge-sim_only',
            'rejected'    => 'pe-badge-rejected',
        ];
        $cls = $map[$status] ?? 'pe-badge-pending';
        return '<span class="badge ' . $cls . '">' . htmlspecialchars($status) . '</span>';
    };
?>
<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1"><i class="bi bi-grid-3x3-gap me-2 text-primary"></i>Pattern Engine</h4>
        <p class="text-secondary mb-0">Normalized signals and scenario decisions from the new upstream pattern source</p>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <?php if (!$liveOutputEnabled): ?>
            <span class="live-disabled-badge"><i class="bi bi-lock me-1"></i>Live Output Disabled</span>
        <?php endif; ?>
        <button class="btn btn-sm btn-outline-secondary" onclick="refreshPatterns()">
            <i class="bi bi-arrow-clockwise me-1"></i>Refresh
        </button>
        <button class="btn btn-sm btn-outline-secondary" onclick="runSmokeTest()" id="btn-smoke-test" title="Synthetic smoke test — no real detections">
            <i class="bi bi-bug me-1"></i>Smoke Test
        </button>
        <button class="btn btn-sm btn-primary" onclick="runPatternEngine()" id="btn-run-now">
            <i class="bi bi-play-fill me-1"></i>Run Now
        </button>
    </div>
</div>

<?php if (!$pe_available): ?>
<div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle me-2"></i>
    Pattern Engine unavailable: <?= htmlspecialchars((string)$pe_error) ?>
</div>
<?php else: ?>

<!-- Run result banner (hidden by default) -->
<div id="run-result-banner" class="alert alert-success d-none mb-3">
    <i class="bi bi-check-circle me-2"></i>
    <span id="run-result-text"></span>
</div>
<div id="run-error-banner" class="alert alert-danger d-none mb-3">
    <i class="bi bi-x-circle me-2"></i>
    <span id="run-error-text"></span>
</div>

<?php
$runSource    = $pe_last_run['run_source']    ?? $pe_stats['last_run']['run_source']    ?? null;
$primarySrc   = $pe_last_run['primary_data_source'] ?? $pe_stats['last_run']['primary_data_source'] ?? $runSource;
$fallbackUsed = !empty($pe_last_run['fallback_data_source_used']) || !empty($pe_stats['last_run']['fallback_data_source_used']);
$symScanned   = $pe_last_run['symbols_scanned']   ?? $pe_stats['last_run']['symbols_scanned']   ?? null;
$symSkipped   = $pe_last_run['symbols_skipped_insufficient_data'] ?? $pe_stats['last_run']['symbols_skipped_insufficient_data'] ?? null;
$symTotal     = $pe_last_run['symbols_total']  ?? $pe_stats['last_run']['symbols_total']  ?? null;
$runTimeframe = $pe_last_run['timeframe']      ?? $pe_stats['last_run']['timeframe']      ?? null;

$rawCandidates  = $pe_last_run['raw_candidates_total'] ?? $pe_stats['last_run']['raw_candidates_total'] ?? null;
$rawSignals     = $pe_last_run['raw_signals_total']    ?? $pe_stats['last_run']['raw_signals_total']    ?? null;
$rawScenarios   = $pe_last_run['raw_scenarios_total']  ?? $pe_stats['last_run']['raw_scenarios_total']  ?? null;
$afterDedupSig  = $pe_last_run['after_dedup_signals']  ?? $pe_stats['last_run']['after_dedup_signals']  ?? null;
$candTruncated  = !empty($pe_last_run['candidates_truncated']) || !empty($pe_stats['last_run']['candidates_truncated']);
$sigTruncated   = !empty($pe_last_run['signals_truncated'])    || !empty($pe_stats['last_run']['signals_truncated']);
$scenTruncated  = !empty($pe_last_run['scenarios_truncated'])  || !empty($pe_stats['last_run']['scenarios_truncated']);
$avgPerSym      = $pe_last_run['avg_signals_per_symbol'] ?? $pe_stats['last_run']['avg_signals_per_symbol'] ?? null;
$symUsedInternal = (int)($pe_last_run['symbols_used_internal'] ?? $pe_stats['last_run']['symbols_used_internal'] ?? 0);
$symUsedBybit   = (int)($pe_last_run['symbols_used_bybit_fallback'] ?? $pe_stats['last_run']['symbols_used_bybit_fallback'] ?? 0);
?>
<!-- Stats row -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-2">
        <div class="pe-stat-card">
            <div class="pe-stat-value"><?= $candidatesCount ?></div>
            <?php if ($rawCandidates !== null && $rawCandidates > $candidatesCount): ?>
                <div class="pe-stat-label text-warning" title="<?= $rawCandidates ?> raw, <?= $candidatesCount ?> stored after dedup+cap">
                    Candidates <small>(of <?= (int)$rawCandidates ?> raw)</small>
                </div>
            <?php else: ?>
                <div class="pe-stat-label">Candidates</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="pe-stat-card<?= $sigTruncated ? ' border-warning' : '' ?>">
            <div class="pe-stat-value"><?= $signalsCount ?></div>
            <?php if ($rawSignals !== null && $rawSignals > $signalsCount): ?>
                <div class="pe-stat-label text-warning" title="<?= $rawSignals ?> raw, <?= $afterDedupSig ?? '?' ?> after dedup, <?= $signalsCount ?> stored">
                    Signals <small>(of <?= (int)$rawSignals ?> raw)</small>
                </div>
            <?php else: ?>
                <div class="pe-stat-label">Signals</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="pe-stat-card<?= $scenTruncated ? ' border-warning' : '' ?>">
            <div class="pe-stat-value"><?= $scenariosCount ?></div>
            <?php if ($rawScenarios !== null && $rawScenarios > $scenariosCount): ?>
                <div class="pe-stat-label text-warning">Scenarios <small>(of <?= (int)$rawScenarios ?> raw)</small></div>
            <?php else: ?>
                <div class="pe-stat-label">Scenarios</div>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($symScanned !== null): ?>
    <div class="col-6 col-md-2">
        <div class="pe-stat-card">
            <div class="pe-stat-value" style="color:#22c55e;"><?= (int)$symScanned ?></div>
            <div class="pe-stat-label">Symbols Scanned</div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="pe-stat-card">
            <div class="pe-stat-value" style="color:#f59e0b;"><?= (int)$symSkipped ?></div>
            <div class="pe-stat-label">Skipped (data)</div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($avgPerSym !== null && $symScanned > 0): ?>
    <div class="col-6 col-md-2">
        <div class="pe-stat-card">
            <div class="pe-stat-value" style="font-size:1.2rem; color:#a78bfa;"><?= number_format((float)$avgPerSym, 1) ?></div>
            <div class="pe-stat-label">Avg Signals/Symbol</div>
        </div>
    </div>
    <?php endif; ?>
    <div class="col-6 col-md-2">
        <div class="pe-stat-card">
            <div class="pe-stat-value" style="font-size:0.85rem; color:#94a3b8;">
                <?= $lastRunAt ? htmlspecialchars(date('d M H:i', strtotime($lastRunAt))) : '—' ?>
            </div>
            <div class="pe-stat-label">Last Run</div>
        </div>
    </div>
</div>

<?php if ($sigTruncated || $candTruncated || $scenTruncated): ?>
<div class="alert alert-warning alert-sm py-2 mb-3 small">
    <i class="bi bi-exclamation-triangle me-1"></i>
    <strong>Output truncated by global storage cap.</strong>
    Raw: <?= (int)$rawSignals ?> signals → <?= $afterDedupSig !== null ? (int)$afterDedupSig . ' after dedup → ' : '' ?><?= $signalsCount ?> stored.
    Results shown are best-ranked only. Consider increasing <code>storage.max_signals_stored</code> or reducing <code>anti_flood.max_signals_per_symbol_per_run</code>.
</div>
<?php endif; ?>

<?php if ($primarySrc): ?>
<div class="mb-3 small text-secondary">
    Source: <code><?= htmlspecialchars((string)$primarySrc) ?></code>
    <?php if ($fallbackUsed): ?>
        <span class="badge bg-warning text-dark ms-1" title="<?= (int)$symUsedInternal ?> internal, <?= (int)$symUsedBybit ?> Bybit fallback">
            <i class="bi bi-arrow-repeat me-1"></i>Bybit fallback used
        </span>
    <?php elseif ($symUsedInternal > 0): ?>
        <span class="badge bg-success ms-1" title="All symbols used internal Parser2 history">
            <i class="bi bi-database me-1"></i>Internal
        </span>
    <?php endif; ?>
    <?php if ($runTimeframe): ?> &nbsp;·&nbsp; Timeframe: <code><?= htmlspecialchars($runTimeframe) ?></code><?php endif; ?>
    <?php if ($symTotal !== null): ?> &nbsp;·&nbsp; <?= (int)$symTotal ?> symbols requested<?php endif; ?>
    <?php if ($rawSignals !== null): ?> &nbsp;·&nbsp; <?= (int)$rawSignals ?> raw detections<?php endif; ?>
</div>
<?php endif; ?>

<!-- Per-pattern / per-status counts -->
<?php
$perPattern  = $pe_last_run['per_pattern']  ?? $pe_stats['last_run']['per_pattern']  ?? [];
$perSymbol   = $pe_last_run['per_symbol']   ?? $pe_stats['last_run']['per_symbol']   ?? [];
$statusCounts= $pe_last_run['status_counts']?? $pe_stats['last_run']['status_counts']?? [];
?>
<?php if (!empty($perPattern) || !empty($statusCounts)): ?>
<div class="row g-3 mb-4">
    <?php if (!empty($perPattern)): ?>
    <div class="col-md-6">
        <div class="card bg-dark border-secondary">
            <div class="card-header text-secondary small py-2">Candidates per Pattern</div>
            <div class="card-body p-0">
                <table class="table table-dark table-sm mb-0">
                    <thead><tr><th>Pattern</th><th class="text-end">Count</th></tr></thead>
                    <tbody>
                    <?php foreach ($perPattern as $algo => $cnt): ?>
                        <tr><td><?= htmlspecialchars($algo) ?></td><td class="text-end"><?= (int)$cnt ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if (!empty($statusCounts)): ?>
    <div class="col-md-6">
        <div class="card bg-dark border-secondary">
            <div class="card-header text-secondary small py-2">Scenarios by Status</div>
            <div class="card-body p-0">
                <table class="table table-dark table-sm mb-0">
                    <thead><tr><th>Status</th><th class="text-end">Count</th></tr></thead>
                    <tbody>
                    <?php foreach ($statusCounts as $st => $cnt): ?>
                        <tr><td><?= htmlspecialchars($st) ?></td><td class="text-end"><?= (int)$cnt ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Tabs: Signals / Scenarios -->
<ul class="nav nav-tabs mb-3" id="patternSubTabs">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-signals">
        <i class="bi bi-lightning-charge me-1"></i>Signals (<?= $signalsCount ?>)
    </a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-scenarios">
        <i class="bi bi-diagram-3 me-1"></i>Scenarios (<?= $scenariosCount ?>)
    </a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-engine-config">
        <i class="bi bi-gear me-1"></i>Engine Config
    </a></li>
</ul>

<div class="tab-content">

<!-- Signals tab -->
<div class="tab-pane fade show active" id="tab-signals">
<?php if (empty($pe_signals)): ?>
    <div class="alert alert-secondary">
        No normalized signals yet.
        <?php if (!empty($pe_last_run['run_source']) && $pe_last_run['run_source'] === 'bybit_klines'): ?>
            Real data was scanned — no chart patterns matched in this run.
        <?php elseif (!empty($pe_last_run['run_source'])): ?>
            <a href="javascript:void(0)" onclick="runPatternEngine()">Run Now</a> to scan real market data.
        <?php else: ?>
            <a href="javascript:void(0)" onclick="runPatternEngine()">Run Now</a> to scan real market data.
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="table-responsive">
    <table class="table table-dark table-sm table-hover">
        <thead>
            <tr>
                <th>Symbol</th><th>Side</th><th>Pattern</th><th>Strength</th><th>Quality</th><th>Signal ID</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach (array_slice($pe_signals, 0, 200) as $sig): ?>
            <tr>
                <td><strong><?= htmlspecialchars($sig['symbol'] ?? '—') ?></strong></td>
                <td><?php
                    $side = $sig['side'] ?? '';
                    $sideCls = $side === 'long' ? 'text-success' : ($side === 'short' ? 'text-danger' : 'text-secondary');
                    echo '<span class="' . $sideCls . '">' . htmlspecialchars(strtoupper($side)) . '</span>';
                ?></td>
                <td><span class="text-secondary small"><?= htmlspecialchars($sig['pattern_algorithm'] ?? $sig['pattern_family'] ?? '—') ?></span></td>
                <td><?= number_format((float)($sig['signal_strength'] ?? 0), 2) ?></td>
                <td><?= number_format((float)($sig['quality_score'] ?? 0), 2) ?></td>
                <td><code class="small text-muted"><?= htmlspecialchars(substr($sig['signal_id'] ?? '—', 0, 20)) ?></code></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if (count($pe_signals) > 200): ?>
        <p class="text-secondary small">Showing 200 of <?= count($pe_signals) ?> signals.</p>
    <?php endif; ?>
<?php endif; ?>
</div>

<!-- Scenarios tab -->
<div class="tab-pane fade" id="tab-scenarios">
<?php if (empty($pe_scenarios)): ?>
    <div class="alert alert-secondary">No scenario decisions yet.</div>
<?php else: ?>
    <div class="table-responsive">
    <table class="table table-dark table-sm table-hover">
        <thead>
            <tr>
                <th>Symbol</th><th>Side</th><th>Status</th><th>Reason</th>
                <th>Demo</th><th>Shadow</th><th>Sim</th><th>Live</th>
                <th>P75 ROI</th><th>Runner Prob</th><th>Noise</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach (array_slice($pe_scenarios, 0, 200) as $sc): ?>
            <tr>
                <td><strong><?= htmlspecialchars($sc['symbol'] ?? '—') ?></strong></td>
                <td><?php
                    $side = $sc['side'] ?? '';
                    $sideCls = $side === 'long' ? 'text-success' : ($side === 'short' ? 'text-danger' : 'text-secondary');
                    echo '<span class="' . $sideCls . '">' . htmlspecialchars(strtoupper($side)) . '</span>';
                ?></td>
                <td><?= $scenarioStatusBadge($sc['final_scenario_status'] ?? $sc['scenario_status'] ?? 'pending') ?></td>
                <td><small class="text-secondary"><?= htmlspecialchars(substr((string)($sc['final_scenario_reason'] ?? $sc['scenario_reason'] ?? '—'), 0, 60)) ?></small></td>
                <td><?= !empty($sc['allowed_for_demo'])   ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-secondary"></i>' ?></td>
                <td><?= !empty($sc['allowed_for_shadow']) ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-secondary"></i>' ?></td>
                <td><?= !empty($sc['allowed_for_sim'])    ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-secondary"></i>' ?></td>
                <td><?php
                    if (!empty($sc['allowed_for_live'])) {
                        echo '<i class="bi bi-check-circle text-warning"></i>';
                    } elseif (!empty($sc['diagnostics']['engine_live_output_enabled']) === false && isset($sc['diagnostics'])) {
                        echo '<i class="bi bi-lock text-danger" title="live_output_disabled_by_engine_policy"></i>';
                    } else {
                        echo '<i class="bi bi-x-circle text-secondary"></i>';
                    }
                ?></td>
                <td><?= isset($sc['diagnostics']['passport_corridor_p75_roi']) ? number_format((float)$sc['diagnostics']['passport_corridor_p75_roi'], 2) : '—' ?></td>
                <td><?= isset($sc['diagnostics']['passport_runner_probability']) ? number_format((float)$sc['diagnostics']['passport_runner_probability'], 3) : '—' ?></td>
                <td><?= isset($sc['diagnostics']['passport_noise_score']) ? number_format((float)$sc['diagnostics']['passport_noise_score'], 2) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if (count($pe_scenarios) > 200): ?>
        <p class="text-secondary small">Showing 200 of <?= count($pe_scenarios) ?> scenarios.</p>
    <?php endif; ?>
<?php endif; ?>
</div>

<!-- Engine Config tab -->
<div class="tab-pane fade" id="tab-engine-config">
<div class="row g-3 mt-1">
    <div class="col-md-6">
        <div class="card bg-dark border-secondary">
            <div class="card-header d-flex justify-content-between align-items-center py-2">
                <span class="text-secondary small">Pattern Engine Settings</span>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label small text-secondary">Engine Enabled</label>
                    <div><?= !empty($pe_config['enabled']) ? '<span class="badge bg-success">Enabled</span>' : '<span class="badge bg-secondary">Disabled</span>' ?></div>
                </div>
                <div class="mb-3">
                    <label class="form-label small text-secondary">Live Output Enabled</label>
                    <div>
                        <?php if ($liveOutputEnabled): ?>
                            <span class="badge bg-warning text-dark">LIVE ENABLED — use with caution</span>
                        <?php else: ?>
                            <span class="badge" style="background:#374151; color:#94a3b8;">Disabled (safe default)</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small text-secondary">Default Time Window</label>
                    <div><?= (int)($pe_config['default_time_window_minutes'] ?? 15) ?> minutes</div>
                </div>
                <?php $rr = (array)($pe_config['real_run'] ?? []); ?>
                <div class="mb-3">
                    <label class="form-label small text-secondary">Real Run — Timeframe</label>
                    <div><?= htmlspecialchars((string)($rr['timeframe'] ?? '15')) ?> min</div>
                </div>
                <div class="mb-3">
                    <label class="form-label small text-secondary">Real Run — Max Symbols / Run</label>
                    <div><?= (int)($rr['max_symbols_per_run'] ?? 30) ?></div>
                </div>
                <div class="mb-3">
                    <label class="form-label small text-secondary">Real Run — Lookback Candles</label>
                    <div><?= (int)($rr['lookback_candles'] ?? 100) ?></div>
                </div>
                <div class="mb-0">
                    <label class="form-label small text-secondary">Scenario Profiles</label>
                    <div><?= count((array)($pe_config['scenario_profiles'] ?? [])) ?> profile(s) configured</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card bg-dark border-secondary h-100">
            <div class="card-header py-2">
                <span class="text-secondary small">Quick Actions</span>
            </div>
            <div class="card-body d-flex flex-column gap-2">
                <button class="btn btn-primary" onclick="runPatternEngine()">
                    <i class="bi bi-play-fill me-1"></i>Run Pattern Engine Now
                </button>
                <a href="<?= $smartBrainUrl ?>/patterns/api/signals" class="btn btn-outline-secondary" target="_blank">
                    <i class="bi bi-code-slash me-1"></i>API: Signals JSON
                </a>
                <a href="<?= $smartBrainUrl ?>/patterns/api/demo_signals" class="btn btn-outline-secondary" target="_blank">
                    <i class="bi bi-code-slash me-1"></i>API: Demo Signals JSON
                </a>
            </div>
        </div>
    </div>
</div>
</div><!-- /tab-engine-config -->

</div><!-- /tab-content -->

<?php endif; // pe_available ?>

<script>
function runPatternEngine() {
    const btn = document.getElementById('btn-run-now');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Running…';
    }
    document.getElementById('run-result-banner')?.classList.add('d-none');
    document.getElementById('run-error-banner')?.classList.add('d-none');

    fetch('<?= $smartBrainUrl ?>/patterns/run', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: '{}'})
        .then(r => r.json())
        .then(data => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Run Now';
            }
            if (data.ok !== false) {
                const stats = data.stats ?? {};
                const src = stats.run_source ? ' [' + stats.run_source + ']' : '';
                const scanned = stats.symbols_scanned != null ? ', ' + stats.symbols_scanned + ' symbols scanned' : '';
                const txt = document.getElementById('run-result-text');
                if (txt) txt.textContent = 'Run complete' + src + ' — ' + (data.candidates_count ?? 0) + ' candidates, ' + (data.signals_count ?? 0) + ' signals, ' + (data.scenarios_count ?? 0) + ' scenarios' + scanned + '.';
                document.getElementById('run-result-banner')?.classList.remove('d-none');
                setTimeout(() => location.reload(), 1400);
            } else {
                const txt = document.getElementById('run-error-text');
                if (txt) txt.textContent = data.error ?? 'Run failed.';
                document.getElementById('run-error-banner')?.classList.remove('d-none');
            }
        })
        .catch(e => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Run Now';
            }
            const txt = document.getElementById('run-error-text');
            if (txt) txt.textContent = 'Network error: ' + e.message;
            document.getElementById('run-error-banner')?.classList.remove('d-none');
        });
}

function runSmokeTest() {
    const btn = document.getElementById('btn-smoke-test');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Testing…';
    }
    document.getElementById('run-result-banner')?.classList.add('d-none');
    document.getElementById('run-error-banner')?.classList.add('d-none');

    fetch('<?= $smartBrainUrl ?>/patterns/run', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: '{"smoke_test":true}'})
        .then(r => r.json())
        .then(data => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-bug me-1"></i>Smoke Test';
            }
            const txt = document.getElementById('run-result-text');
            if (txt) txt.textContent = '[Smoke] Pipeline ok — ' + (data.candidates_count ?? 0) + ' candidates (synthetic, expected 0).';
            document.getElementById('run-result-banner')?.classList.remove('d-none');
            setTimeout(() => location.reload(), 1400);
        })
        .catch(e => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-bug me-1"></i>Smoke Test';
            }
            const txt = document.getElementById('run-error-text');
            if (txt) txt.textContent = 'Network error: ' + e.message;
            document.getElementById('run-error-banner')?.classList.remove('d-none');
        });
}

function refreshPatterns() { location.reload(); }
</script>

<?php
};
require_once __DIR__ . '/_layout.php';
