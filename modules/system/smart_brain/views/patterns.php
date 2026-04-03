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

$demoSignalsCount   = (int)($pe_last_run['demo_signals_count']               ?? $pe_stats['last_run']['demo_signals_count']               ?? 0);
$shadowSignalsCount = (int)($pe_last_run['shadow_signals_count']             ?? $pe_stats['last_run']['shadow_signals_count']             ?? 0);
$simSignalsCount    = (int)($pe_last_run['sim_signals_count']                ?? $pe_stats['last_run']['sim_signals_count']                ?? 0);
$symNormCount       = (int)($pe_last_run['symbols_normalized_count']         ?? $pe_stats['last_run']['symbols_normalized_count']         ?? 0);
$symNormFailed      = (int)($pe_last_run['symbols_normalization_failed_count']?? $pe_stats['last_run']['symbols_normalization_failed_count']?? 0);
$passportOkCount    = (int)($pe_last_run['passport_lookup_success_count']    ?? $pe_stats['last_run']['passport_lookup_success_count']    ?? 0);
$passportFailCount  = (int)($pe_last_run['passport_lookup_failed_count']     ?? $pe_stats['last_run']['passport_lookup_failed_count']     ?? 0);

$allowDemoCount       = (int)($pe_last_run['allow_demo_count']              ?? $pe_stats['last_run']['allow_demo_count']              ?? 0);
$allowDemoLcCount     = (int)($pe_last_run['allow_demo_low_confidence_count'] ?? $pe_stats['last_run']['allow_demo_low_confidence_count'] ?? 0);
$allowSimCount        = (int)($pe_last_run['allow_sim_count']               ?? $pe_stats['last_run']['allow_sim_count']               ?? 0);
$shadowOnlyCount      = (int)($pe_last_run['shadow_only_count']             ?? $pe_stats['last_run']['shadow_only_count']             ?? 0);
$rejectCount          = (int)($pe_last_run['reject_count']                  ?? $pe_stats['last_run']['reject_count']                  ?? 0);
$demoBlockCounts      = (array)($pe_last_run['demo_block_counts']           ?? $pe_stats['last_run']['demo_block_counts']             ?? []);
$demoNearMissCount    = (int)($pe_last_run['demo_near_miss_count']          ?? $pe_stats['last_run']['demo_near_miss_count']          ?? 0);
$demoCandidateCount   = (int)($pe_last_run['demo_candidate_signals_count']  ?? $pe_stats['last_run']['demo_candidate_signals_count']  ?? 0);
$topDemoBlockReasons  = (array)($pe_last_run['top_demo_block_reasons']      ?? $pe_stats['last_run']['top_demo_block_reasons']        ?? []);
$demoLcBlockCount     = (int)($pe_last_run['demo_low_confidence_block_count']   ?? $pe_stats['last_run']['demo_low_confidence_block_count']   ?? 0);
$demoLcBlockReasons   = (array)($pe_last_run['demo_low_confidence_block_reasons'] ?? $pe_stats['last_run']['demo_low_confidence_block_reasons'] ?? []);

// Paper pre-classification stats
$paperRejectCount      = (int)($pe_last_run['paper_reject_count']               ?? $pe_stats['last_run']['paper_reject_count']               ?? 0);
$paperCandidateCount   = (int)($pe_last_run['paper_candidate_count']            ?? $pe_stats['last_run']['paper_candidate_count']            ?? 0);
$paperStrongCount      = (int)($pe_last_run['paper_strong_candidate_count']     ?? $pe_stats['last_run']['paper_strong_candidate_count']     ?? 0);
$demoFromPaper         = (int)($pe_last_run['demo_export_count_from_paper']     ?? $pe_stats['last_run']['demo_export_count_from_paper']     ?? 0);
$simFromPaper          = (int)($pe_last_run['sim_export_count_from_paper']      ?? $pe_stats['last_run']['sim_export_count_from_paper']      ?? 0);
$shadowFromPaper       = (int)($pe_last_run['shadow_export_count_from_paper']   ?? $pe_stats['last_run']['shadow_export_count_from_paper']   ?? 0);
$topPaperRejectReasons = (array)($pe_last_run['top_paper_reject_reasons']       ?? $pe_stats['last_run']['top_paper_reject_reasons']        ?? []);
$topDemoBlockedByPaper = (array)($pe_last_run['top_demo_blocked_by_paper_reasons'] ?? $pe_stats['last_run']['top_demo_blocked_by_paper_reasons'] ?? []);
$paperPolicyCfg        = (array)($pe_config['paper_policy'] ?? []);

$patternSymsTotal   = (int)($pe_last_run['pattern_symbols_total']                  ?? $pe_stats['last_run']['pattern_symbols_total']                  ?? 0);
$passportSymsTotal  = (int)($pe_last_run['passport_symbols_total']                 ?? $pe_stats['last_run']['passport_symbols_total']                 ?? 0);
$universeOverlapCnt = (int)($pe_last_run['symbol_universe_overlap_count']          ?? $pe_stats['last_run']['symbol_universe_overlap_count']          ?? 0);
$universeOverlapRate= (float)($pe_last_run['symbol_universe_overlap_rate']         ?? $pe_stats['last_run']['symbol_universe_overlap_rate']         ?? 0.0);
$symsWithPassport   = (int)($pe_last_run['pattern_symbols_with_passport_count']    ?? $pe_stats['last_run']['pattern_symbols_with_passport_count']    ?? 0);
$symsWithoutPassport= (int)($pe_last_run['pattern_symbols_without_passport_count'] ?? $pe_stats['last_run']['pattern_symbols_without_passport_count'] ?? 0);
$activePolicy       = (string)($pe_last_run['active_universe_policy']              ?? $pe_stats['last_run']['active_universe_policy']              ?? '');
$coverageCandidates = (array)($pe_last_run['passport_coverage_candidates']         ?? $pe_stats['last_run']['passport_coverage_candidates']         ?? []);
$patternSymsTotal   = (int)($pe_last_run['pattern_symbols_total']                  ?? $pe_stats['last_run']['pattern_symbols_total']                  ?? 0);
$passportSymsTotal  = (int)($pe_last_run['passport_symbols_total']                 ?? $pe_stats['last_run']['passport_symbols_total']                 ?? 0);
$universeOverlapCnt = (int)($pe_last_run['symbol_universe_overlap_count']          ?? $pe_stats['last_run']['symbol_universe_overlap_count']          ?? 0);
$universeOverlapRate= (float)($pe_last_run['symbol_universe_overlap_rate']         ?? $pe_stats['last_run']['symbol_universe_overlap_rate']         ?? 0.0);
$symsWithPassport   = (int)($pe_last_run['pattern_symbols_with_passport_count']    ?? $pe_stats['last_run']['pattern_symbols_with_passport_count']    ?? 0);
$symsWithoutPassport= (int)($pe_last_run['pattern_symbols_without_passport_count'] ?? $pe_stats['last_run']['pattern_symbols_without_passport_count'] ?? 0);
$activePolicy       = (string)($pe_last_run['active_universe_policy']              ?? $pe_stats['last_run']['active_universe_policy']              ?? '');
$coverageCandidates = (array)($pe_last_run['passport_coverage_candidates']         ?? $pe_stats['last_run']['passport_coverage_candidates']         ?? []);
$symsNormUnmatched  = (array)($pe_last_run['symbols_normalized_but_unmatched']     ?? $pe_stats['last_run']['symbols_normalized_but_unmatched']     ?? []);

// Demo feed diagnostics
$feedExportTotal  = (int)($pe_last_run['demo_feed_export_total']                ?? $pe_stats['last_run']['demo_feed_export_total']                ?? $demoSignalsCount);
$feedCandTotal    = (int)($pe_last_run['demo_feed_candidate_total']             ?? $pe_stats['last_run']['demo_feed_candidate_total']             ?? 0);
$feedTargetMin    = (int)($pe_last_run['demo_feed_target_min_per_run']          ?? $pe_stats['last_run']['demo_feed_target_min_per_run']          ?? 3);
$feedTargetMax    = (int)($pe_last_run['demo_feed_target_soft_max_per_run']     ?? $pe_stats['last_run']['demo_feed_target_soft_max_per_run']     ?? 10);
$feedMetTarget    = (bool)($pe_last_run['demo_feed_met_target']                 ?? $pe_stats['last_run']['demo_feed_met_target']                  ?? false);
$feedBelowBy      = (int)($pe_last_run['demo_feed_below_target_by']             ?? $pe_stats['last_run']['demo_feed_below_target_by']             ?? 0);
$feedTopBlock     = (string)($pe_last_run['demo_feed_top_block_preventing_target'] ?? $pe_stats['last_run']['demo_feed_top_block_preventing_target'] ?? '');
$topFeedBlocks    = (array)($pe_last_run['top_demo_feed_block_reasons']         ?? $pe_stats['last_run']['top_demo_feed_block_reasons']          ?? []);
$feedStarved      = !$feedMetTarget && $lastRunAt !== null;
?>
<?php if ($lastRunAt !== null && $feedStarved): ?>
<!-- Demo Feed Health Banner (shown when feed is below target) -->
<div class="alert py-2 mb-3" style="background:#1c0a0a; border:1px solid #dc2626;">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <span class="fw-bold" style="color:#f87171;"><i class="bi bi-broadcast me-1"></i>Demo Feed Starved</span>
            <span class="badge ms-2" style="background:#7f1d1d; font-size:0.7rem;">exported <?= $feedExportTotal ?> / min <?= $feedTargetMin ?></span>
            <?php if ($feedBelowBy > 0): ?><span class="text-muted small ms-2"><?= $feedBelowBy ?> below target</span><?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-1">
            <span class="pe-stat-card" style="padding:0.2rem 0.5rem; font-size:0.75rem; min-width:0;">
                <span class="text-secondary">Candidates:</span> <span class="text-white"><?= $feedCandTotal ?></span>
            </span>
            <span class="pe-stat-card" style="padding:0.2rem 0.5rem; font-size:0.75rem; min-width:0;">
                <span class="text-secondary">Strong:</span> <span style="color:#3b82f6;"><?= $paperStrongCount ?></span>
            </span>
            <span class="pe-stat-card" style="padding:0.2rem 0.5rem; font-size:0.75rem; min-width:0;">
                <span class="text-secondary">allow_demo:</span> <span style="color:#3b82f6;"><?= $allowDemoCount ?></span>
            </span>
        </div>
    </div>
    <?php if ($feedTopBlock !== ''): ?>
    <div class="small mt-1" style="color:#fca5a5;"><i class="bi bi-exclamation-triangle me-1"></i>Top blocker: <code><?= htmlspecialchars($feedTopBlock) ?></code></div>
    <?php endif; ?>
    <?php if (!empty($topFeedBlocks)): ?>
    <div class="d-flex flex-wrap gap-1 mt-1">
        <?php foreach (array_slice($topFeedBlocks, 0, 5) as $blk): ?>
        <span class="badge" style="background:#1e293b; border:1px solid #475569; font-size:0.7rem;">
            <?= htmlspecialchars(str_replace('demo_feed_blocked_by_', '', (string)($blk['reason'] ?? ''))) ?>
            <span class="text-warning ms-1"><?= (int)($blk['count'] ?? 0) ?></span>
        </span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php elseif ($lastRunAt !== null && $feedMetTarget): ?>
<!-- Demo Feed Health Banner (target met) -->
<div class="alert py-2 mb-3" style="background:#052e16; border:1px solid #22c55e;">
    <span class="fw-bold" style="color:#22c55e;"><i class="bi bi-broadcast me-1"></i>Demo Feed OK</span>
    <span class="badge ms-2" style="background:#14532d; font-size:0.7rem;"><?= $feedExportTotal ?> exported / target min <?= $feedTargetMin ?></span>
    <span class="text-secondary small ms-2">soft max: <?= $feedTargetMax ?></span>
</div>
<?php endif; ?>
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

<!-- Downstream eligibility stats row -->
<?php if ($demoSignalsCount > 0 || $shadowSignalsCount > 0 || $simSignalsCount > 0 || $passportOkCount > 0): ?>
<div class="row g-2 mb-3">
    <div class="col-6 col-md-2">
        <div class="pe-stat-card" style="border-color:#2563eb;">
            <div class="pe-stat-value" style="color:#3b82f6;"><?= $demoSignalsCount ?></div>
            <div class="pe-stat-label">Demo-Ready</div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="pe-stat-card" style="border-color:#7c3aed;">
            <div class="pe-stat-value" style="color:#a78bfa;"><?= $shadowSignalsCount ?></div>
            <div class="pe-stat-label">Shadow-Ready</div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="pe-stat-card" style="border-color:#d97706;">
            <div class="pe-stat-value" style="color:#fbbf24;"><?= $simSignalsCount ?></div>
            <div class="pe-stat-label">Sim-Ready</div>
        </div>
    </div>
    <?php if ($passportOkCount > 0 || $passportFailCount > 0): ?>
    <div class="col-6 col-md-2">
        <div class="pe-stat-card" style="border-color:<?= $passportFailCount > $passportOkCount ? '#dc2626' : '#22c55e' ?>;">
            <div class="pe-stat-value" style="color:#22c55e; font-size:1rem;"><?= $passportOkCount ?><span class="text-secondary" style="font-size:0.7rem;"> / <?= $passportOkCount + $passportFailCount ?></span></div>
            <div class="pe-stat-label">Passport Hits</div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($symNormCount > 0 || $symNormFailed > 0): ?>
    <div class="col-6 col-md-2">
        <div class="pe-stat-card<?= $symNormFailed > 0 ? ' border-warning' : '' ?>">
            <div class="pe-stat-value" style="font-size:1rem; color:#94a3b8;"><?= $symNormCount ?><?= $symNormFailed > 0 ? '<span class="text-warning ms-1" style="font-size:0.7rem;">⚠ ' . $symNormFailed . ' fail</span>' : '' ?></div>
            <div class="pe-stat-label">Symbols Normalized</div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($allowDemoCount > 0 || $allowSimCount > 0 || $shadowOnlyCount > 0 || $rejectCount > 0): ?>
<!-- Downstream graduation policy panel -->
<div class="card bg-dark border-secondary mb-3">
    <div class="card-header d-flex justify-content-between align-items-center py-2">
        <span class="text-secondary small"><i class="bi bi-diagram-3 me-1"></i>Downstream Graduation Policy — Bucket Decisions</span>
        <?php
            $dpBlock  = (array)($pe_config['downstream_policy'] ?? []);
            $dpDemo   = !empty($dpBlock['allow_demo_enabled'])   ? 'allow_demo' : 'demo disabled';
            $dpSim    = !empty($dpBlock['allow_sim_enabled'])    ? 'allow_sim'  : 'sim disabled';
            $dpPpReq  = !empty($dpBlock['demo_require_passport']) ? 'passport required' : 'passport optional';
        ?>
        <span class="badge bg-secondary" style="font-size:0.7rem;"><?= htmlspecialchars($dpPpReq) ?></span>
    </div>
    <div class="card-body p-3">
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-2">
                <div class="pe-stat-card" style="border-color:<?= $allowDemoCount > 0 ? '#2563eb' : '#334155' ?>;">
                    <div class="pe-stat-value" style="color:<?= $allowDemoCount > 0 ? '#3b82f6' : '#6b7280' ?>;"><?= $allowDemoCount ?></div>
                    <div class="pe-stat-label">allow_demo</div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="pe-stat-card" style="border-color:<?= $allowDemoLcCount > 0 ? '#0d9488' : '#334155' ?>;" title="Signals promoted via low-confidence demo policy (passport present, data low/insufficient, passes quality/noise gates)">
                    <div class="pe-stat-value" style="color:<?= $allowDemoLcCount > 0 ? '#2dd4bf' : '#6b7280' ?>;"><?= $allowDemoLcCount ?></div>
                    <div class="pe-stat-label">demo (low-conf)</div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="pe-stat-card" style="border-color:<?= $allowSimCount > 0 ? '#d97706' : '#334155' ?>;">
                    <div class="pe-stat-value" style="color:<?= $allowSimCount > 0 ? '#fbbf24' : '#6b7280' ?>;"><?= $allowSimCount ?></div>
                    <div class="pe-stat-label">allow_sim</div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="pe-stat-card" style="border-color:<?= $shadowOnlyCount > 0 ? '#7c3aed' : '#334155' ?>;">
                    <div class="pe-stat-value" style="color:<?= $shadowOnlyCount > 0 ? '#a78bfa' : '#6b7280' ?>;"><?= $shadowOnlyCount ?></div>
                    <div class="pe-stat-label">shadow_only</div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="pe-stat-card" style="border-color:<?= $demoCandidateCount > 0 ? '#0891b2' : '#334155' ?>;" title="Signals with passport that reached sim/shadow — were evaluated for demo">
                    <div class="pe-stat-value" style="color:<?= $demoCandidateCount > 0 ? '#22d3ee' : '#6b7280' ?>;"><?= $demoCandidateCount ?></div>
                    <div class="pe-stat-label">demo candidates</div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="pe-stat-card" style="border-color:<?= $demoNearMissCount > 0 ? '#b45309' : '#334155' ?>;" title="Near-miss: passport present, blocked by 1–2 modest checks only">
                    <div class="pe-stat-value" style="color:<?= $demoNearMissCount > 0 ? '#f59e0b' : '#6b7280' ?>;"><?= $demoNearMissCount ?></div>
                    <div class="pe-stat-label">near misses</div>
                </div>
            </div>
        </div>
        <?php if (!empty($topDemoBlockReasons)): ?>
        <div class="mb-2">
            <div class="text-secondary small mb-1"><i class="bi bi-exclamation-circle me-1 text-warning"></i>Top demo block reasons (why signals did not graduate to demo):</div>
            <div class="d-flex flex-wrap gap-1">
            <?php foreach ($topDemoBlockReasons as $item): ?>
                <span class="badge" style="background:#1e293b; border:1px solid #475569; font-size:0.7rem;" title="<?= (int)($item['count'] ?? 0) ?> scenario(s) blocked by this reason">
                    <?= htmlspecialchars(str_replace('demo_blocked_', '', (string)($item['reason'] ?? ''))) ?>
                    <span class="text-warning ms-1"><?= (int)($item['count'] ?? 0) ?></span>
                </span>
            <?php endforeach; ?>
            </div>
        </div>
        <?php elseif (!empty($demoBlockCounts)): ?>
        <?php
        $activeBlockReasons = array_filter($demoBlockCounts, fn($v) => $v > 0);
        arsort($activeBlockReasons);
        if (!empty($activeBlockReasons)):
        ?>
        <div class="mb-2">
            <div class="text-secondary small mb-1"><i class="bi bi-exclamation-circle me-1 text-warning"></i>Demo block reasons (why signals did not graduate to demo):</div>
            <div class="d-flex flex-wrap gap-1">
            <?php foreach ($activeBlockReasons as $reason => $cnt): ?>
                <span class="badge" style="background:#1e293b; border:1px solid #475569; font-size:0.7rem;" title="<?= $cnt ?> scenario(s) blocked by this reason">
                    <?= htmlspecialchars($reason) ?> <span class="text-warning ms-1"><?= $cnt ?></span>
                </span>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; endif; ?>
        <?php if (!empty($dpBlock)): ?>
        <div class="mt-2">
            <div class="text-secondary small mb-1"><i class="bi bi-sliders me-1"></i>Current demo graduation thresholds:</div>
            <div class="row g-1">
                <?php
                $thresholdItems = [
                    ['demo_min_signal_strength',    'Min Signal Strength'],
                    ['demo_min_quality_score',      'Min Quality Score'],
                    ['demo_min_corridor_p75_roi',   'Min Corridor P75 ROI'],
                    ['demo_min_runner_probability', 'Min Runner Probability'],
                    ['demo_max_noise_score',        'Max Noise Score'],
                    ['demo_min_confidence',         'Min Confidence'],
                ];
                foreach ($thresholdItems as [$key, $label]):
                    $val = $dpBlock[$key] ?? null;
                    if ($val === null) continue;
                ?>
                <div class="col-6 col-md-4">
                    <div class="small" style="background:#0f172a; padding:0.3rem 0.5rem; border-radius:4px;">
                        <span class="text-secondary"><?= $label ?>:</span>
                        <span class="text-info ms-1"><?= is_array($val) ? implode(', ', $val) : htmlspecialchars((string)$val) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        $lcPolicyCfg = (array)($dpBlock['demo_low_confidence_policy'] ?? []);
        if (!empty($lcPolicyCfg)):
        ?>
        <div class="mt-2 p-2" style="background:#0f172a; border:1px solid <?= !empty($lcPolicyCfg['enabled']) ? '#0d9488' : '#475569' ?>; border-radius:6px;">
            <div class="small mb-1">
                <span class="<?= !empty($lcPolicyCfg['enabled']) ? 'text-teal' : 'text-secondary' ?>" style="color:<?= !empty($lcPolicyCfg['enabled']) ? '#2dd4bf' : '#6b7280' ?>;">
                    <i class="bi bi-shield-check me-1"></i><strong>Low-confidence demo policy:</strong>
                    <?= !empty($lcPolicyCfg['enabled']) ? '<span class="badge" style="background:#0d9488;font-size:0.65rem;">enabled</span>' : '<span class="badge bg-secondary" style="font-size:0.65rem;">disabled</span>' ?>
                </span>
                <?php if ($allowDemoLcCount > 0): ?>
                <span class="badge ms-2" style="background:#0d9488; font-size:0.65rem;"><?= $allowDemoLcCount ?> promoted this run</span>
                <?php endif; ?>
            </div>
            <div class="row g-1">
                <?php
                $lcThresholdItems = [
                    ['require_signal_strength_min', 'Min Strength'],
                    ['require_quality_score_min',   'Min Quality'],
                    ['require_noise_score_max',     'Max Noise'],
                    ['max_demo_low_confidence_signals_per_run', 'Cap/run'],
                ];
                foreach ($lcThresholdItems as [$k, $lbl]):
                    $v = $lcPolicyCfg[$k] ?? null;
                    if ($v === null) continue;
                ?>
                <div class="col-6 col-md-3">
                    <div class="small" style="background:#1e293b; padding:0.2rem 0.4rem; border-radius:4px;">
                        <span class="text-secondary"><?= $lbl ?>:</span>
                        <span class="text-info ms-1"><?= htmlspecialchars((string)$v) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($demoLcBlockReasons)): ?>
            <div class="mt-1 small text-secondary">Blocked by low-conf policy (<?= $demoLcBlockCount ?>):
                <?php foreach (array_slice($demoLcBlockReasons, 0, 3, true) as $r => $c): ?>
                    <span class="badge ms-1" style="background:#1e293b; border:1px solid #475569; font-size:0.65rem;"><?= htmlspecialchars(str_replace('lc_demo_', '', $r)) ?> <span class="text-warning"><?= $c ?></span></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; endif; ?>
        <?php if ($demoNearMissCount > 0): ?>
        <div class="mt-2 p-2" style="background:#1c1917; border:1px solid #b45309; border-radius:6px;">
            <div class="text-warning small"><i class="bi bi-bullseye me-1"></i><strong><?= $demoNearMissCount ?> near-miss signal(s)</strong> — passport present, blocked by only 1–2 checks. These are the closest candidates for demo promotion.</div>
            <?php if (!empty($topDemoBlockReasons)): ?>
            <div class="text-secondary small mt-1">Top blocking checks: <?= implode(', ', array_map(fn($i) => htmlspecialchars(str_replace('demo_blocked_', '', (string)($i['reason'] ?? ''))), array_slice($topDemoBlockReasons, 0, 3))) ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php
$paperTotal = $paperRejectCount + $paperCandidateCount + $paperStrongCount;
if ($paperTotal > 0 || !empty($paperPolicyCfg)):
?>
<!-- Paper pre-classification panel -->
<div class="card bg-dark border-secondary mb-3">
    <div class="card-header d-flex justify-content-between align-items-center py-2">
        <span class="text-secondary small"><i class="bi bi-funnel me-1"></i>Paper Pre-Classification — Demo Export Gate</span>
        <span class="badge" style="background:<?= !empty($paperPolicyCfg['enabled']) ? '#0d9488' : '#475569' ?>; font-size:0.7rem;">
            <?= !empty($paperPolicyCfg['enabled']) ? 'enabled' : 'disabled' ?>
        </span>
    </div>
    <div class="card-body p-3">
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-2">
                <div class="pe-stat-card" style="border-color:<?= $paperRejectCount > 0 ? '#6b7280' : '#334155' ?>;" title="Signals that failed both strong and candidate checks — routed to shadow">
                    <div class="pe-stat-value" style="color:<?= $paperRejectCount > 0 ? '#9ca3af' : '#6b7280' ?>;"><?= $paperRejectCount ?></div>
                    <div class="pe-stat-label">Paper Reject</div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="pe-stat-card" style="border-color:<?= $paperCandidateCount > 0 ? '#d97706' : '#334155' ?>;" title="Signals that passed candidate but not strong checks — routed to sim">
                    <div class="pe-stat-value" style="color:<?= $paperCandidateCount > 0 ? '#fbbf24' : '#6b7280' ?>;"><?= $paperCandidateCount ?></div>
                    <div class="pe-stat-label">Paper Candidate</div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="pe-stat-card" style="border-color:<?= $paperStrongCount > 0 ? '#2563eb' : '#334155' ?>;" title="Signals that passed all strong checks — eligible for demo export">
                    <div class="pe-stat-value" style="color:<?= $paperStrongCount > 0 ? '#3b82f6' : '#6b7280' ?>;"><?= $paperStrongCount ?></div>
                    <div class="pe-stat-label">Paper Strong</div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="pe-stat-card" style="border-color:<?= $demoFromPaper > 0 ? '#22c55e' : '#334155' ?>;" title="Signals actually exported to demo (paper_strong_candidate only)">
                    <div class="pe-stat-value" style="color:<?= $demoFromPaper > 0 ? '#22c55e' : '#6b7280' ?>;"><?= $demoFromPaper ?></div>
                    <div class="pe-stat-label">→ Demo Export</div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="pe-stat-card" style="border-color:<?= $simFromPaper > 0 ? '#d97706' : '#334155' ?>;" title="allow_demo scenarios rerouted to sim as paper_candidate">
                    <div class="pe-stat-value" style="color:<?= $simFromPaper > 0 ? '#fbbf24' : '#6b7280' ?>;"><?= $simFromPaper ?></div>
                    <div class="pe-stat-label">→ Sim (from demo)</div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="pe-stat-card" style="border-color:<?= $shadowFromPaper > 0 ? '#7c3aed' : '#334155' ?>;" title="allow_demo scenarios rerouted to shadow as paper_reject">
                    <div class="pe-stat-value" style="color:<?= $shadowFromPaper > 0 ? '#a78bfa' : '#6b7280' ?>;"><?= $shadowFromPaper ?></div>
                    <div class="pe-stat-label">→ Shadow (from demo)</div>
                </div>
            </div>
        </div>
        <?php if (!empty($topDemoBlockedByPaper)): ?>
        <div class="mb-2">
            <div class="text-secondary small mb-1"><i class="bi bi-funnel me-1 text-warning"></i>Why allow_demo signals were blocked from demo by paper policy:</div>
            <div class="d-flex flex-wrap gap-1">
            <?php foreach ($topDemoBlockedByPaper as $item): ?>
                <span class="badge" style="background:#1e293b; border:1px solid #475569; font-size:0.7rem;">
                    <?= htmlspecialchars(str_replace('paper_', '', (string)($item['reason'] ?? ''))) ?>
                    <span class="text-warning ms-1"><?= (int)($item['count'] ?? 0) ?></span>
                </span>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if (!empty($topPaperRejectReasons)): ?>
        <div class="mb-2">
            <div class="text-secondary small mb-1"><i class="bi bi-x-circle me-1 text-danger"></i>Top paper reject reasons:</div>
            <div class="d-flex flex-wrap gap-1">
            <?php foreach ($topPaperRejectReasons as $item): ?>
                <span class="badge" style="background:#1e293b; border:1px solid #475569; font-size:0.7rem;">
                    <?= htmlspecialchars(str_replace('paper_', '', (string)($item['reason'] ?? ''))) ?>
                    <span class="text-danger ms-1"><?= (int)($item['count'] ?? 0) ?></span>
                </span>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if (!empty($paperPolicyCfg)): ?>
        <div class="mt-2">
            <div class="text-secondary small mb-1"><i class="bi bi-sliders me-1"></i>Paper policy thresholds:</div>
            <div class="row g-1">
                <?php
                $paperThresholdItems = [
                    ['paper_strong_min_signal_strength',    'Strong Min Strength'],
                    ['paper_strong_min_quality_score',      'Strong Min Quality'],
                    ['paper_strong_min_corridor_p75_roi',   'Strong Min P75 ROI'],
                    ['paper_strong_min_runner_probability', 'Strong Min Runner'],
                    ['paper_strong_max_noise_score',        'Strong Max Noise'],
                    ['paper_candidate_min_signal_strength', 'Candidate Min Strength'],
                    ['paper_candidate_min_quality_score',   'Candidate Min Quality'],
                    ['paper_max_strong_per_run',            'Max Strong/run'],
                    ['paper_max_candidates_per_run',        'Max Candidates/run'],
                ];
                foreach ($paperThresholdItems as [$key, $label]):
                    $val = $paperPolicyCfg[$key] ?? null;
                    if ($val === null) continue;
                ?>
                <div class="col-6 col-md-4">
                    <div class="small" style="background:#0f172a; padding:0.3rem 0.5rem; border-radius:4px;">
                        <span class="text-secondary"><?= $label ?>:</span>
                        <span class="text-info ms-1"><?= htmlspecialchars((string)$val) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($patternSymsTotal > 0 || $passportSymsTotal > 0): ?>
<!-- Universe overlap panel -->
<div class="card bg-dark border-secondary mb-3">
    <div class="card-header d-flex justify-content-between align-items-center py-2">
        <span class="text-secondary small"><i class="bi bi-intersect me-1"></i>Symbol Universe &amp; Passport Coverage</span>
        <?php if ($activePolicy !== ''): ?>
            <?php
                $policyColor = match($activePolicy) {
                    'passport_only'      => '#22c55e',
                    'passport_preferred' => '#3b82f6',
                    default              => '#94a3b8',
                };
            ?>
            <span class="badge" style="background:<?= $policyColor ?>; font-size:0.7rem;"><?= htmlspecialchars($activePolicy) ?></span>
        <?php endif; ?>
    </div>
    <div class="card-body p-3">
        <div class="row g-3 mb-2">
            <div class="col-6 col-md-3">
                <div class="pe-stat-card">
                    <div class="pe-stat-value" style="color:#94a3b8;"><?= $patternSymsTotal ?></div>
                    <div class="pe-stat-label">Symbols in Run</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="pe-stat-card">
                    <div class="pe-stat-value" style="color:#94a3b8;"><?= $passportSymsTotal ?></div>
                    <div class="pe-stat-label">Passport Universe</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <?php
                    $overlapPct = $patternSymsTotal > 0 ? round($universeOverlapRate * 100, 1) : 0;
                    $overlapColor = $overlapPct >= 50 ? '#22c55e' : ($overlapPct >= 20 ? '#f59e0b' : '#ef4444');
                ?>
                <div class="pe-stat-card" style="border-color:<?= $overlapColor ?>;">
                    <div class="pe-stat-value" style="color:<?= $overlapColor ?>;"><?= $overlapPct ?>%</div>
                    <div class="pe-stat-label">Overlap Rate <small>(<?= $universeOverlapCnt ?>/<?= $patternSymsTotal ?>)</small></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <?php $missingCount = count($coverageCandidates); ?>
                <div class="pe-stat-card<?= $missingCount > 0 ? ' border-warning' : '' ?>">
                    <div class="pe-stat-value" style="color:#f59e0b;"><?= $missingCount ?></div>
                    <div class="pe-stat-label">Coverage Candidates</div>
                </div>
            </div>
        </div>
        <?php if ($symsWithPassport > 0 || $symsWithoutPassport > 0): ?>
        <div class="small text-secondary mb-2">
            <i class="bi bi-check-circle text-success me-1"></i><?= $symsWithPassport ?> symbols with passport &nbsp;·&nbsp;
            <i class="bi bi-x-circle text-danger me-1"></i><?= $symsWithoutPassport ?> without passport
            <?php if (!empty($symsNormUnmatched)): ?>
                &nbsp;·&nbsp; <i class="bi bi-arrow-right-circle text-warning me-1"></i><?= count($symsNormUnmatched) ?> normalized-but-unmatched
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($coverageCandidates)): ?>
        <div class="mt-2">
            <div class="text-secondary small mb-1"><i class="bi bi-binoculars me-1"></i>Passport Coverage Candidates — symbols with detections but no passport (by frequency):</div>
            <div class="d-flex flex-wrap gap-1">
            <?php foreach (array_slice($coverageCandidates, 0, 15) as $cc): ?>
                <span class="badge bg-secondary" style="font-size:0.7rem;" title="<?= (int)($cc['detection_count'] ?? 0) ?> detection(s)">
                    <?= htmlspecialchars($cc['symbol'] ?? '') ?>
                    <span class="text-warning ms-1"><?= (int)($cc['detection_count'] ?? 0) ?></span>
                </span>
            <?php endforeach; ?>
            <?php if (count($coverageCandidates) > 15): ?>
                <span class="text-secondary small">&hellip; +<?= count($coverageCandidates) - 15 ?> more</span>
            <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if (!empty($symsNormUnmatched)): ?>
        <div class="mt-2">
            <div class="text-secondary small mb-1"><i class="bi bi-exclamation-triangle text-warning me-1"></i>Normalized-but-unmatched symbols (canonical form not in passport):</div>
            <div class="d-flex flex-wrap gap-1">
            <?php foreach (array_slice($symsNormUnmatched, 0, 10) as $u): ?>
                <span class="badge" style="background:#78350f; font-size:0.7rem;"><?= htmlspecialchars($u) ?></span>
            <?php endforeach; ?>
            <?php if (count($symsNormUnmatched) > 10): ?>
                <span class="text-secondary small">&hellip; +<?= count($symsNormUnmatched) - 10 ?> more</span>
            <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

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
                <th>Symbol</th><th>Side</th><th>Pattern</th><th>Strength</th><th>Quality</th><th>Norm</th><th>Signal ID</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach (array_slice($pe_signals, 0, 200) as $sig): ?>
            <?php
                $symRaw       = $sig['symbol'] ?? '—';
                $symCanonical = $sig['symbol_canonical'] ?? null;
                $normStatus   = $sig['symbol_normalization_status'] ?? null;
                $showCanon    = $symCanonical && $symCanonical !== $symRaw;
            ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($symRaw) ?></strong>
                    <?php if ($showCanon): ?>
                        <br><small class="text-info" title="Canonical: <?= htmlspecialchars($symCanonical) ?>"><?= htmlspecialchars($symCanonical) ?></small>
                    <?php endif; ?>
                </td>
                <td><?php
                    $side = $sig['side'] ?? '';
                    $sideCls = $side === 'long' ? 'text-success' : ($side === 'short' ? 'text-danger' : 'text-secondary');
                    echo '<span class="' . $sideCls . '">' . htmlspecialchars(strtoupper($side)) . '</span>';
                ?></td>
                <td><span class="text-secondary small"><?= htmlspecialchars($sig['pattern_algorithm'] ?? $sig['pattern_family'] ?? '—') ?></span></td>
                <td><?= number_format((float)($sig['signal_strength'] ?? 0), 2) ?></td>
                <td><?= number_format((float)($sig['quality_score'] ?? 0), 2) ?></td>
                <td><?php
                    $ns = $normStatus ?? '';
                    if ($ns === 'unchanged' || $ns === '') {
                        echo '<span class="text-secondary" style="font-size:0.7rem;">—</span>';
                    } elseif ($ns === 'normalized') {
                        echo '<span class="badge" style="background:#1e40af; font-size:0.65rem;">norm</span>';
                    } elseif ($ns === 'normalized_no_passport') {
                        echo '<span class="badge bg-warning text-dark" style="font-size:0.65rem;" title="No passport for this symbol">no-pp</span>';
                    } elseif ($ns === 'normalized_fallback_to_original') {
                        echo '<span class="badge bg-secondary" style="font-size:0.65rem;" title="Canonical not in passport; using original">orig</span>';
                    } elseif ($ns === 'failed') {
                        echo '<span class="badge bg-danger" style="font-size:0.65rem;">fail</span>';
                    } else {
                        echo '<span class="text-muted" style="font-size:0.65rem;">' . htmlspecialchars($ns) . '</span>';
                    }
                ?></td>
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
                <th>Bucket</th><th>Paper</th><th>Live</th>
                <th>Passport</th><th>P75 ROI</th><th>Runner Prob</th><th>Noise</th><th>Demo Block</th><th>Near Miss</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach (array_slice($pe_scenarios, 0, 200) as $sc): ?>
            <?php $diag = (array)($sc['diagnostics'] ?? []); ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($sc['symbol'] ?? '—') ?></strong>
                    <?php
                        $ppSym = $diag['passport_lookup_symbol'] ?? null;
                        if ($ppSym && strtoupper($ppSym) !== strtoupper($sc['symbol'] ?? '')) {
                            echo '<br><small class="text-info" title="Passport looked up as: ' . htmlspecialchars($ppSym) . '">' . htmlspecialchars($ppSym) . '</small>';
                        }
                    ?>
                </td>
                <td><?php
                    $side = $sc['side'] ?? '';
                    $sideCls = $side === 'long' ? 'text-success' : ($side === 'short' ? 'text-danger' : 'text-secondary');
                    echo '<span class="' . $sideCls . '">' . htmlspecialchars(strtoupper($side)) . '</span>';
                ?></td>
                <td><?= $scenarioStatusBadge($sc['final_scenario_status'] ?? $sc['scenario_status'] ?? 'pending') ?></td>
                <td><small class="text-secondary"><?= htmlspecialchars(substr((string)($sc['final_scenario_reason'] ?? $sc['scenario_reason'] ?? '—'), 0, 60)) ?></small></td>
                <td><?php
                    $fb = $diag['final_downstream_bucket'] ?? $sc['scenario_status'] ?? '—';
                    $fbColors = [
                        'allow_demo'  => '#3b82f6',
                        'shadow_only' => '#a78bfa',
                        'allow_shadow'=> '#a78bfa',
                        'allow_sim'   => '#fbbf24',
                        'sim_only'    => '#fbbf24',
                        'reject'      => '#ef4444',
                    ];
                    $fbColor = $fbColors[$fb] ?? '#6b7280';
                    echo '<span class="badge" style="background:' . $fbColor . ';font-size:0.65rem;" title="final_downstream_bucket">' . htmlspecialchars($fb) . '</span>';
                    if (!empty($diag['demo_low_confidence_policy_used'])) {
                        echo '<span class="badge ms-1" style="background:#0d9488;font-size:0.6rem;" title="Graduated via low-confidence demo policy">lc</span>';
                    }
                ?></td>
                <td><?php
                    // Read from top-level first (set directly on scenario); fall back to diagnostics
                    $pb        = $sc['paper_bucket'] ?? $diag['paper_bucket'] ?? null;
                    $pr        = $sc['paper_reason'] ?? $diag['paper_reason'] ?? '';
                    $ps        = $sc['paper_score']  ?? $diag['paper_score']  ?? 0;
                    $ptip      = htmlspecialchars((string)$pr) . ' | score:' . number_format((float)$ps, 3);
                    if ($pb === 'paper_strong_candidate') {
                        echo '<span class="badge" style="background:#1d4ed8;font-size:0.65rem;" title="' . $ptip . '">strong</span>';
                    } elseif ($pb === 'paper_candidate') {
                        echo '<span class="badge" style="background:#92400e;font-size:0.65rem;" title="' . $ptip . '">cand.</span>';
                    } elseif ($pb === 'paper_reject') {
                        echo '<span class="badge" style="background:#374151;font-size:0.65rem;" title="' . $ptip . '">reject</span>';
                    } elseif ($pb === null && $pr === 'paper_policy_disabled') {
                        echo '<span class="text-secondary" style="font-size:0.65rem;">off</span>';
                    } else {
                        echo '<span class="text-secondary">—</span>';
                    }
                ?></td>
                <td><?php
                    if (!empty($sc['allowed_for_live'])) {
                        echo '<i class="bi bi-check-circle text-warning"></i>';
                    } elseif (!empty($sc['diagnostics']['engine_live_output_enabled']) === false && isset($sc['diagnostics'])) {
                        echo '<i class="bi bi-lock text-danger" title="live_output_disabled_by_engine_policy"></i>';
                    } else {
                        echo '<i class="bi bi-x-circle text-secondary"></i>';
                    }
                ?></td>
                <td><?php
                    $ppStatus = $diag['passport_lookup_status'] ?? null;
                    if ($ppStatus === 'found') {
                        echo '<i class="bi bi-check-circle text-success" title="Passport found: ' . htmlspecialchars($diag['passport_lookup_symbol'] ?? '') . '"></i>';
                    } elseif ($ppStatus === 'not_found') {
                        echo '<i class="bi bi-x-circle text-secondary" title="' . htmlspecialchars($diag['passport_lookup_reason'] ?? 'no passport') . '"></i>';
                    } else {
                        echo '<span class="text-secondary">—</span>';
                    }
                ?></td>
                <td><?= isset($diag['passport_corridor_p75_roi']) ? number_format((float)$diag['passport_corridor_p75_roi'], 2) : '—' ?></td>
                <td><?= isset($diag['passport_runner_probability']) ? number_format((float)$diag['passport_runner_probability'], 3) : '—' ?></td>
                <td><?= isset($diag['passport_noise_score']) ? number_format((float)$diag['passport_noise_score'], 2) : '—' ?></td>
                <td><small class="text-warning" style="font-size:0.65rem;"><?= $diag['demo_block_reason'] ? htmlspecialchars(str_replace('demo_blocked_', '', $diag['demo_block_reason'])) : '' ?><?php
                    if (!empty($diag['demo_low_confidence_block_reason'])) {
                        echo '<br><span style="color:#94a3b8;font-size:0.6rem;">' . htmlspecialchars(str_replace('lc_demo_', '', (string)$diag['demo_low_confidence_block_reason'])) . '</span>';
                    }
                ?></small></td>
                <td><?php
                    if (!empty($diag['demo_near_miss'])) {
                        $failedChecks = (array)($diag['demo_failed_checks'] ?? []);
                        $failedStr = !empty($failedChecks) ? implode(', ', $failedChecks) : '';
                        echo '<span class="badge" style="background:#92400e; font-size:0.65rem;" title="Near miss: failed ' . htmlspecialchars($failedStr) . '">⚠ near</span>';
                    } else {
                        echo '<span class="text-secondary">—</span>';
                    }
                ?></td>
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
                <?php
                    $rrPolicy = (array)(($pe_config['real_run']['symbol_universe_policy'] ?? []));
                    $pMode    = (string)($rrPolicy['mode'] ?? 'all_active');
                    $pMaxNoPP = (int)($rrPolicy['max_symbols_without_passport'] ?? 10);
                ?>
                <div class="mb-0 mt-3 pt-2 border-top border-secondary">
                    <label class="form-label small text-secondary">Symbol Universe Policy</label>
                    <div>
                        <?php
                            $pmColor = match($pMode) {
                                'passport_only'      => 'bg-success',
                                'passport_preferred' => 'bg-primary',
                                default              => 'bg-secondary',
                            };
                        ?>
                        <span class="badge <?= $pmColor ?>"><?= htmlspecialchars($pMode) ?></span>
                        <?php if ($pMode === 'passport_preferred'): ?>
                            <small class="text-secondary ms-1">max <?= $pMaxNoPP ?> without passport</small>
                        <?php endif; ?>
                    </div>
                </div>
                <?php
                    $dpCfg = (array)($pe_config['downstream_policy'] ?? []);
                    if (!empty($dpCfg)):
                ?>
                <div class="mb-0 mt-3 pt-2 border-top border-secondary">
                    <label class="form-label small text-secondary">Downstream Graduation Policy</label>
                    <div class="d-flex flex-wrap gap-1 mt-1">
                        <span class="badge <?= !empty($dpCfg['allow_demo_enabled']) ? 'bg-primary' : 'bg-secondary' ?>">demo: <?= !empty($dpCfg['allow_demo_enabled']) ? 'enabled' : 'disabled' ?></span>
                        <span class="badge <?= !empty($dpCfg['allow_sim_enabled']) ? 'bg-warning text-dark' : 'bg-secondary' ?>">sim: <?= !empty($dpCfg['allow_sim_enabled']) ? 'enabled' : 'disabled' ?></span>
                        <span class="badge <?= !empty($dpCfg['demo_require_passport']) ? 'bg-success' : 'bg-secondary' ?>">passport: <?= !empty($dpCfg['demo_require_passport']) ? 'required' : 'optional' ?></span>
                    </div>
                    <div class="small text-secondary mt-1">
                        Str ≥ <?= htmlspecialchars((string)($dpCfg['demo_min_signal_strength'] ?? '?')) ?> &nbsp;·&nbsp;
                        Qual ≥ <?= htmlspecialchars((string)($dpCfg['demo_min_quality_score'] ?? '?')) ?> &nbsp;·&nbsp;
                        P75 ≥ <?= htmlspecialchars((string)($dpCfg['demo_min_corridor_p75_roi'] ?? '?')) ?> &nbsp;·&nbsp;
                        Runner ≥ <?= htmlspecialchars((string)($dpCfg['demo_min_runner_probability'] ?? '?')) ?>
                    </div>
                </div>
                <?php endif; ?>
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
                <a href="<?= $smartBrainUrl ?>/patterns/api/shadow_signals" class="btn btn-outline-secondary" target="_blank">
                    <i class="bi bi-code-slash me-1"></i>API: Shadow Signals JSON
                </a>
                <a href="<?= $smartBrainUrl ?>/patterns/api/sim_signals" class="btn btn-outline-secondary" target="_blank">
                    <i class="bi bi-code-slash me-1"></i>API: Sim Signals JSON
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
