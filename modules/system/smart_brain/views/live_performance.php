<?php

/** @var array $analytics */
/** @var bool $bot_mirror_available */
/** @var string|null $bot_mirror_error */
/** @var int $closed_trades_count */
/** @var string $smartBrainUrl */

$pageTitle = 'Smart Brain - Live Performance';
$activeTab = 'live_performance';

$extraStyles = <<<'CSS'
.stat-card { text-align: center; padding: 1rem; }
.stat-card .stat-value { font-size: 1.5rem; font-weight: 700; }
.stat-card .stat-label { font-size: 0.8rem; color: #94a3b8; }
.roi-positive { color: #22c55e; }
.roi-negative { color: #ef4444; }
.badge-long { background: #22c55e; color: #fff; }
.badge-short { background: #ef4444; color: #fff; }
.severity-critical { background: rgba(239,68,68,0.15); }
.severity-warning { background: rgba(251,191,36,0.15); }
.severity-watch { background: rgba(56,189,248,0.15); }
.bar-indicator { display: inline-block; height: 8px; border-radius: 4px; background: #3b82f6; }
CSS;

$fmtRoi = function ($roi): string {
    $val = (float) $roi;
    $pct = number_format($val * 100, 2);
    $cls = $val >= 0 ? 'roi-positive' : 'roi-negative';
    return '<span class="' . $cls . '">' . ($val >= 0 ? '+' : '') . htmlspecialchars($pct) . '%</span>';
};

$fmtExpectancy = function ($exp): string {
    $val = (float) $exp;
    $pct = number_format($val * 100, 3);
    $cls = $val >= 0 ? 'roi-positive' : 'roi-negative';
    return '<span class="' . $cls . '">' . ($val >= 0 ? '+' : '') . htmlspecialchars($pct) . '%</span>';
};

$fmtWinrate = function ($wr): string {
    $val = (float) $wr;
    $cls = $val >= 40 ? 'roi-positive' : ($val >= 25 ? 'text-warning' : 'roi-negative');
    return '<span class="' . $cls . '">' . htmlspecialchars(number_format($val, 1)) . '%</span>';
};

$fmtRate = function ($rate): string {
    return htmlspecialchars(number_format((float) $rate, 1)) . '%';
};

$sideBadge = function ($side): string {
    $s = strtolower((string) ($side ?? ''));
    if ($s === 'long') {
        return '<span class="badge badge-long">▲ LONG</span>';
    }
    if ($s === 'short') {
        return '<span class="badge badge-short">▼ SHORT</span>';
    }
    return '<span class="badge bg-secondary">' . htmlspecialchars((string) $side) . '</span>';
};

$lowSampleBadge = function (array $row): string {
    if (!empty($row['low_sample'])) {
        return ' <span class="badge bg-warning text-dark" style="font-size:.7rem;">⚠ Low sample</span>';
    }
    return '';
};

$pageContent = function () use (
    $analytics,
    $bot_mirror_available,
    $bot_mirror_error,
    $closed_trades_count,
    $smartBrainUrl,
    $fmtRoi,
    $fmtExpectancy,
    $fmtWinrate,
    $fmtRate,
    $sideBadge,
    $lowSampleBadge
) {
    $overview             = $analytics['overview'] ?? [];
    $patternPerformance   = $analytics['pattern_performance'] ?? [];
    $symbolSidePerf       = $analytics['symbol_side_performance'] ?? [];
    $exitAnalysis         = $analytics['exit_analysis'] ?? [];
    $executionQuality     = $analytics['execution_quality'] ?? [];
    $damageRanking        = $analytics['damage_ranking'] ?? [];
    $whatifScenarios      = $analytics['whatif_scenarios'] ?? [];
    $recommendations      = $analytics['recommendations'] ?? [];
    $passportContext       = $analytics['passport_context'] ?? [];

    $dataAvailable = (bool) ($overview['data_available'] ?? false);
?>
<h4><i class="bi bi-activity me-2 text-primary"></i>Live Performance Analyzer</h4>
<p class="text-muted mb-4">Real-time analysis of live trading performance, pattern quality, execution health, and actionable recommendations.</p>

<?php if (!$dataAvailable): ?>
<div class="alert alert-warning d-flex align-items-center mb-4">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <div>Not enough live trade data available yet. Results below may be incomplete or missing.</div>
</div>
<?php endif; ?>

<!-- ============================================================ P1: Live Overview -->
<div class="row g-3 mb-4">
<?php
    $statCards = [
        ['label' => 'Total Trades',      'value' => (int) ($overview['total_trades'] ?? 0),     'fmt' => 'int'],
        ['label' => 'Wins',              'value' => (int) ($overview['wins'] ?? 0),              'fmt' => 'int',   'cls' => 'roi-positive'],
        ['label' => 'Losses',            'value' => (int) ($overview['losses'] ?? 0),            'fmt' => 'int',   'cls' => 'roi-negative'],
        ['label' => 'Winrate',           'value' => (float) ($overview['winrate'] ?? 0),         'fmt' => 'winrate'],
        ['label' => 'Avg ROI',           'value' => (float) ($overview['avg_roi'] ?? 0),         'fmt' => 'roi'],
        ['label' => 'Median ROI',        'value' => (float) ($overview['median_roi'] ?? 0),      'fmt' => 'roi'],
        ['label' => 'Expectancy',        'value' => (float) ($overview['expectancy'] ?? 0),      'fmt' => 'expectancy'],
        ['label' => 'Active Positions',  'value' => (int) ($overview['active_positions_count'] ?? 0),  'fmt' => 'int',   'cls' => 'text-info'],
        ['label' => 'Busy Skipped',      'value' => (int) ($overview['busy_skipped'] ?? 0),      'fmt' => 'int'],
        ['label' => 'Duplicate Skipped', 'value' => (int) ($overview['duplicate_skipped'] ?? 0), 'fmt' => 'int'],
        ['label' => 'Exchange Failed',   'value' => (int) ($overview['exchange_submit_failed'] ?? 0),   'fmt' => 'int',   'cls' => ($overview['exchange_submit_failed'] ?? 0) > 0 ? 'roi-negative' : ''],
        ['label' => 'Trailing Active',   'value' => (int) ($overview['trailing_active_count'] ?? 0),   'fmt' => 'int',   'cls' => 'text-info'],
        ['label' => 'Break-Even Applied','value' => (int) ($overview['break_even_applied_count'] ?? 0),        'fmt' => 'int'],
    ];
    foreach ($statCards as $sc):
        $rawVal = $sc['value'];
        $cls = $sc['cls'] ?? '';
        if ($sc['fmt'] === 'roi') {
            $display = ($rawVal >= 0 ? '+' : '') . number_format($rawVal * 100, 2) . '%';
            $cls = $cls ?: ($rawVal >= 0 ? 'roi-positive' : 'roi-negative');
        } elseif ($sc['fmt'] === 'expectancy') {
            $display = ($rawVal >= 0 ? '+' : '') . number_format($rawVal * 100, 3) . '%';
            $cls = $cls ?: ($rawVal >= 0 ? 'roi-positive' : 'roi-negative');
        } elseif ($sc['fmt'] === 'winrate') {
            $display = number_format($rawVal, 1) . '%';
            $cls = $rawVal >= 40 ? 'roi-positive' : ($rawVal >= 25 ? 'text-warning' : 'roi-negative');
        } else {
            $display = (string) $rawVal;
        }
?>
    <div class="col-6 col-md-3 col-lg-2">
        <div class="stat-card">
            <div class="stat-value <?= $cls ?>"><?= htmlspecialchars($display) ?></div>
            <div class="stat-label"><?= htmlspecialchars($sc['label']) ?></div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<!-- ============================================================ P2: Pattern Performance -->
<div class="card mb-4">
    <div class="card-header">
        <h5 style="margin:0;"><i class="bi bi-puzzle me-2 text-primary"></i>Pattern Performance</h5>
    </div>
    <div class="card-body p-0">
<?php if (empty($patternPerformance)): ?>
        <p class="text-secondary text-center py-4">No pattern performance data available.</p>
<?php else: ?>
        <p class="text-muted small px-3 pt-2 mb-1"><i class="bi bi-info-circle me-1"></i>Results with &lt;10 trades are marked as low confidence. Sorted by expectancy (best first).</p>
        <div class="table-responsive">
        <table class="table table-dark table-hover table-sm mb-0">
            <thead><tr>
                <th>Pattern</th>
                <th class="text-end">Trades</th>
                <th class="text-end">Wins</th>
                <th class="text-end">Losses</th>
                <th class="text-end">Winrate</th>
                <th class="text-end">Avg ROI</th>
                <th class="text-end">Median ROI</th>
                <th class="text-end">Expectancy</th>
                <th class="text-end">Stop Hit Rate</th>
                <th class="text-end">Trailing Close</th>
                <th class="text-end">BE Close</th>
            </tr></thead>
            <tbody>
<?php
    // Sort by expectancy descending
    usort($patternPerformance, fn($a, $b) => ($b['expectancy'] ?? 0) <=> ($a['expectancy'] ?? 0));
    foreach ($patternPerformance as $p):
        $exp = (float) ($p['expectancy'] ?? 0);
        $rowCls = $exp < 0 ? 'severity-critical' : ($exp > 0 ? 'severity-watch' : '');
?>
                <tr class="<?= $rowCls ?>">
                    <td class="fw-bold"><?= htmlspecialchars((string) ($p['pattern'] ?? '-')) ?><?= $lowSampleBadge($p) ?></td>
                    <td class="text-end"><?= (int) ($p['trades'] ?? 0) ?></td>
                    <td class="text-end roi-positive"><?= (int) ($p['wins'] ?? 0) ?></td>
                    <td class="text-end roi-negative"><?= (int) ($p['losses'] ?? 0) ?></td>
                    <td class="text-end"><?= $fmtWinrate((float) ($p['winrate'] ?? 0)) ?></td>
                    <td class="text-end"><?= $fmtRoi((float) ($p['avg_roi'] ?? 0)) ?></td>
                    <td class="text-end"><?= $fmtRoi((float) ($p['median_roi'] ?? 0)) ?></td>
                    <td class="text-end"><?= $fmtExpectancy($exp) ?></td>
                    <td class="text-end"><?= $fmtRate((float) ($p['stop_hit_rate'] ?? 0)) ?></td>
                    <td class="text-end"><?= $fmtRate((float) ($p['trailing_close_rate'] ?? 0)) ?></td>
                    <td class="text-end"><?= $fmtRate((float) ($p['be_close_rate'] ?? 0)) ?></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
        </div>
<?php endif; ?>
    </div>
</div>

<!-- ============================================================ P3: Symbol × Side Performance -->
<div class="card mb-4">
    <div class="card-header">
        <h5 style="margin:0;"><i class="bi bi-arrow-left-right me-2 text-primary"></i>Symbol × Side Performance</h5>
    </div>
    <div class="card-body p-0">
<?php if (empty($symbolSidePerf)): ?>
        <p class="text-secondary text-center py-4">No symbol-side performance data available.</p>
<?php else: ?>
        <p class="text-muted small px-3 pt-2 mb-1"><i class="bi bi-info-circle me-1"></i>Results with &lt;10 trades are marked as low confidence. Sorted by expectancy (worst first — problem areas at top).</p>
        <div class="table-responsive">
        <table class="table table-dark table-hover table-sm mb-0">
            <thead><tr>
                <th>Symbol</th>
                <th>Side</th>
                <th class="text-end">Trades</th>
                <th class="text-end">Winrate</th>
                <th class="text-end">Avg ROI</th>
                <th class="text-end">Median ROI</th>
                <th class="text-end">Expectancy</th>
                <th class="text-end">Stop Hits</th>
                <th class="text-end">BE Count</th>
                <th class="text-end">Trailing Close</th>
                <th class="text-end">MAE p75 Win</th>
                <th class="text-end">Suggested Stop</th>
                <th>Fallback</th>
            </tr></thead>
            <tbody>
<?php
    // Sort by expectancy ascending (worst first)
    usort($symbolSidePerf, fn($a, $b) => ($a['expectancy'] ?? 0) <=> ($b['expectancy'] ?? 0));
    foreach ($symbolSidePerf as $ss):
        $exp = (float) ($ss['expectancy'] ?? 0);
        $rowCls = $exp < 0 ? 'severity-critical' : '';
?>
                <tr class="<?= $rowCls ?>">
                    <td class="fw-bold"><?= htmlspecialchars((string) ($ss['symbol'] ?? '-')) ?><?= $lowSampleBadge($ss) ?></td>
                    <td><?= $sideBadge($ss['side'] ?? '') ?></td>
                    <td class="text-end"><?= (int) ($ss['trades'] ?? 0) ?></td>
                    <td class="text-end"><?= $fmtWinrate((float) ($ss['winrate'] ?? 0)) ?></td>
                    <td class="text-end"><?= $fmtRoi((float) ($ss['avg_roi'] ?? 0)) ?></td>
                    <td class="text-end"><?= $fmtRoi((float) ($ss['median_roi'] ?? 0)) ?></td>
                    <td class="text-end"><?= $fmtExpectancy($exp) ?></td>
                    <td class="text-end"><?= (int) ($ss['stop_hit_count'] ?? 0) ?></td>
                    <td class="text-end"><?= (int) ($ss['be_count'] ?? 0) ?></td>
                    <td class="text-end"><?= (int) ($ss['trailing_close_count'] ?? 0) ?></td>
                    <td class="text-end"><?= $fmtRoi((float) ($ss['mae_p75_winners'] ?? 0)) ?></td>
                    <td class="text-end"><?= $fmtRoi((float) ($ss['suggested_stop'] ?? 0)) ?></td>
                    <td><?= !empty($ss['fallback_flag']) ? '<span class="badge bg-warning text-dark">Fallback</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
        </div>
<?php endif; ?>
    </div>
</div>

<!-- ============================================================ P4: Exit / Protection Analyzer -->
<div class="card mb-4">
    <div class="card-header">
        <h5 style="margin:0;"><i class="bi bi-shield-check me-2 text-primary"></i>Exit / Protection Analyzer</h5>
    </div>
    <div class="card-body">
        <!-- Exit Reason Distribution -->
        <h6 class="mb-3"><i class="bi bi-door-open me-1"></i>Exit Reason Distribution</h6>
<?php
        $reasonDist = $exitAnalysis['close_reason_distribution'] ?? [];
        $reasonPct  = $exitAnalysis['close_reason_pct'] ?? [];
        if (empty($reasonDist)):
?>
        <p class="text-secondary">No exit reason data available.</p>
<?php else: ?>
        <div class="table-responsive mb-4">
        <table class="table table-dark table-sm mb-0">
            <thead><tr>
                <th>Reason</th>
                <th class="text-end">Count</th>
                <th class="text-end">Percentage</th>
                <th style="width:30%;">Distribution</th>
            </tr></thead>
            <tbody>
<?php foreach ($reasonDist as $reason => $count): $pct = (float)($reasonPct[$reason] ?? 0); ?>
                <tr>
                    <td><?= htmlspecialchars((string)$reason) ?></td>
                    <td class="text-end"><?= (int)$count ?></td>
                    <td class="text-end"><?= htmlspecialchars(number_format($pct, 1)) ?>%</td>
                    <td><span class="bar-indicator" style="width:<?= min(100, max(0, $pct)) ?>%;"></span></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
        </div>
<?php endif; ?>

        <!-- Protection Metrics -->
        <h6 class="mb-3"><i class="bi bi-shield-lock me-1"></i>Protection Metrics</h6>
<?php
        $protStats = [
            ['label' => 'Trailing Activation Rate', 'key' => 'trailing_activation_rate'],
            ['label' => 'BE Armed Rate',            'key' => 'be_armed_rate'],
            ['label' => 'BE Applied Rate',          'key' => 'be_applied_rate'],
            ['label' => 'Stop Moved Rate',          'key' => 'stop_moved_rate'],
        ];
?>
        <div class="row g-3">
<?php foreach ($protStats as $ps): ?>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-info"><?= htmlspecialchars(number_format((float)($exitAnalysis[$ps['key']] ?? 0), 1)) ?>%</div>
                    <div class="stat-label"><?= htmlspecialchars($ps['label']) ?></div>
                </div>
            </div>
<?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ============================================================ P5: Execution Quality -->
<div class="card mb-4">
    <div class="card-header">
        <h5 style="margin:0;"><i class="bi bi-cpu me-2 text-primary"></i>Execution Quality</h5>
    </div>
    <div class="card-body">
<?php
        $exchFailed = (int) ($executionQuality['exchange_submit_failed'] ?? 0);
        $execCards = [
            ['label' => 'Duplicate Skipped',        'key' => 'duplicate_skipped',          'cls' => ''],
            ['label' => 'Busy Skipped',              'key' => 'busy_skipped',               'cls' => ''],
            ['label' => 'Exchange Submit Attempted',  'key' => 'exchange_submit_attempted',  'cls' => 'text-info'],
            ['label' => 'Exchange Submit Failed',     'key' => 'exchange_submit_failed',     'cls' => $exchFailed > 0 ? 'roi-negative' : 'roi-positive'],
            ['label' => 'Exchange Submit Success',    'key' => 'exchange_submit_success',    'cls' => 'roi-positive'],
            ['label' => 'Position Open Confirmed',    'key' => 'position_open_confirmed',    'cls' => 'roi-positive'],
            ['label' => 'Execution Guard Blocked',    'key' => 'execution_guard_blocked',    'cls' => 'text-warning'],
            ['label' => 'Protection Apply Failed',    'key' => 'protection_apply_failed',    'cls' => $exchFailed > 0 ? 'roi-negative' : ''],
        ];
?>
        <div class="row g-3 mb-4">
<?php foreach ($execCards as $ec): ?>
            <div class="col-6 col-md-3">
                <div class="stat-card">
                    <div class="stat-value <?= $ec['cls'] ?>"><?= (int) ($executionQuality[$ec['key']] ?? 0) ?></div>
                    <div class="stat-label"><?= htmlspecialchars($ec['label']) ?></div>
                </div>
            </div>
<?php endforeach; ?>
        </div>

<?php
        $hasError = ($executionQuality['latest_exchange_error_code'] ?? null) !== null && ($executionQuality['latest_exchange_error_code'] ?? '') !== '';
        if ($hasError):
?>
        <div class="alert alert-danger d-flex align-items-start">
            <i class="bi bi-exclamation-octagon-fill me-2 mt-1"></i>
            <div>
                <strong>Latest Exchange Error</strong><br>
                <small>
                    Code: <code><?= htmlspecialchars((string) ($executionQuality['latest_exchange_error_code'] ?? '-')) ?></code> &middot;
                    Message: <?= htmlspecialchars((string) ($executionQuality['latest_exchange_error_message'] ?? '-')) ?> &middot;
                    Symbol: <?= htmlspecialchars((string) ($executionQuality['last_failed_symbol'] ?? '-')) ?> &middot;
                    Stage: <?= htmlspecialchars((string) ($executionQuality['last_failed_stage'] ?? '-')) ?>
                </small>
            </div>
        </div>
<?php endif; ?>
    </div>
</div>

<!-- ============================================================ P6: Damage Ranking -->
<div class="card mb-4">
    <div class="card-header">
        <h5 style="margin:0;"><i class="bi bi-bar-chart-line me-2 text-primary"></i>Damage Ranking</h5>
    </div>
    <div class="card-body p-0">
<?php if (empty($damageRanking)): ?>
        <p class="text-secondary text-center py-4">No damage ranking data available.</p>
<?php else: ?>
        <div class="table-responsive">
        <table class="table table-dark table-sm mb-0">
            <thead><tr>
                <th class="text-center" style="width:50px;">Rank</th>
                <th>Type</th>
                <th>Label</th>
                <th>Metric</th>
                <th class="text-end">Value</th>
                <th class="text-end">Trades</th>
                <th>Severity</th>
            </tr></thead>
            <tbody>
<?php foreach ($damageRanking as $i => $dr):
        $severity = strtolower((string) ($dr['severity'] ?? 'watch'));
        $sevIcon = match ($severity) {
            'critical' => 'bi-exclamation-triangle-fill text-danger',
            'warning'  => 'bi-exclamation-circle text-warning',
            default    => 'bi-info-circle text-info',
        };
        $sevBadge = match ($severity) {
            'critical' => 'bg-danger',
            'warning'  => 'bg-warning text-dark',
            default    => 'bg-info text-dark',
        };
        $rowCls = 'severity-' . $severity;
?>
                <tr class="<?= $rowCls ?>">
                    <td class="text-center fw-bold"><?= (int) ($dr['rank'] ?? ($i + 1)) ?></td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars((string) ($dr['type'] ?? '-')) ?></span></td>
                    <td><i class="bi <?= $sevIcon ?> me-1"></i><?= htmlspecialchars((string) ($dr['label'] ?? '-')) ?></td>
                    <td class="text-muted"><?= htmlspecialchars((string) ($dr['metric'] ?? '-')) ?></td>
                    <td class="text-end fw-bold"><?php
                        $drMetric = (string)($dr['metric'] ?? '');
                        $drValue = (float)($dr['value'] ?? 0);
                        if ($drMetric === 'expectancy') {
                            echo $fmtExpectancy($drValue);
                        } elseif ($drMetric === 'winrate') {
                            echo $fmtWinrate($drValue);
                        } elseif ($drMetric === 'avg_roi') {
                            echo $fmtRoi($drValue);
                        } else {
                            echo htmlspecialchars(number_format($drValue, 2));
                        }
                    ?></td>
                    <td class="text-end"><?= (int) ($dr['trades'] ?? 0) ?></td>
                    <td><span class="badge <?= $sevBadge ?>"><?= htmlspecialchars(ucfirst($severity)) ?></span></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
        </div>
<?php endif; ?>
    </div>
</div>

<!-- ============================================================ P7: What-if Scenarios -->
<div class="card mb-4">
    <div class="card-header">
        <h5 style="margin:0;"><i class="bi bi-lightbulb me-2 text-primary"></i>What-if Scenarios</h5>
    </div>
    <div class="card-body p-0">
<?php if (empty($whatifScenarios)): ?>
        <p class="text-secondary text-center py-4">No what-if scenario data available.</p>
<?php else: ?>
        <div class="table-responsive">
        <table class="table table-dark table-hover table-sm mb-0">
            <thead><tr>
                <th>Scenario</th>
                <th>Description</th>
                <th class="text-end">Remaining</th>
                <th class="text-end">Removed</th>
                <th class="text-end">Winrate After</th>
                <th class="text-end">Expectancy After</th>
                <th class="text-end">Winrate Δ</th>
                <th class="text-end">Expectancy Δ</th>
            </tr></thead>
            <tbody>
<?php foreach ($whatifScenarios as $ws):
        $expDelta = (float) ($ws['expectancy_delta'] ?? 0);
        $wrDelta  = (float) ($ws['winrate_delta'] ?? 0);
        $highlight = $expDelta > 0 ? 'severity-watch' : '';
?>
                <tr class="<?= $highlight ?>">
                    <td class="fw-bold"><?= htmlspecialchars((string) ($ws['name'] ?? '-')) ?></td>
                    <td class="text-muted"><?= htmlspecialchars((string) ($ws['description'] ?? '-')) ?></td>
                    <td class="text-end"><?= (int) ($ws['trades_remaining'] ?? 0) ?></td>
                    <td class="text-end"><?= (int) ($ws['trades_removed'] ?? 0) ?></td>
                    <td class="text-end"><?= $fmtWinrate((float) ($ws['winrate_after'] ?? 0)) ?></td>
                    <td class="text-end"><?= $fmtExpectancy((float) ($ws['expectancy_after'] ?? 0)) ?></td>
                    <td class="text-end"><span class="<?= $wrDelta >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= ($wrDelta >= 0 ? '+' : '') . htmlspecialchars(number_format($wrDelta, 1)) ?>%</span></td>
                    <td class="text-end"><span class="<?= $expDelta >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= ($expDelta >= 0 ? '+' : '') . htmlspecialchars(number_format($expDelta * 100, 3)) ?>%</span></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
        </div>
<?php endif; ?>
    </div>
</div>

<!-- ============================================================ P8: Recommended Actions -->
<div class="card mb-4">
    <div class="card-header">
        <h5 style="margin:0;"><i class="bi bi-check2-square me-2 text-primary"></i>Recommended Actions</h5>
    </div>
    <div class="card-body">
<?php if (empty($recommendations)): ?>
        <p class="text-secondary">No recommendations at this time. Keep trading!</p>
<?php else: ?>
<?php foreach ($recommendations as $rec):
        $msg = is_array($rec) ? (string) ($rec['message'] ?? $rec['text'] ?? '') : (string) $rec;
        $level = is_array($rec) ? (string) ($rec['level'] ?? 'info') : 'info';
        $alertCls = match ($level) {
            'warning' => 'alert-warning',
            'danger', 'critical' => 'alert-danger',
            'success' => 'alert-success',
            default => 'alert-info',
        };
        $iconCls = match ($level) {
            'warning' => 'bi-exclamation-triangle',
            'danger', 'critical' => 'bi-exclamation-octagon',
            'success' => 'bi-check-circle',
            default => 'bi-info-circle',
        };
?>
        <div class="alert <?= $alertCls ?> d-flex align-items-center py-2 mb-2">
            <i class="bi <?= $iconCls ?> me-2"></i>
            <div><?= htmlspecialchars($msg) ?></div>
        </div>
<?php endforeach; ?>
<?php endif; ?>
    </div>
</div>

<!-- ============================================================ P9: Passport / MAE Context (Collapsible) -->
<?php if (!empty($passportContext)): ?>
<div class="card mb-4">
    <div class="card-header">
        <h5 style="margin:0;">
            <a class="text-decoration-none" style="color:var(--heading-color);" data-bs-toggle="collapse" href="#passportContextCollapse" role="button" aria-expanded="false" aria-controls="passportContextCollapse">
                <i class="bi bi-passport me-2 text-primary"></i>Passport / MAE Context
                <i class="bi bi-chevron-down ms-2 small"></i>
            </a>
        </h5>
    </div>
    <div class="collapse" id="passportContextCollapse">
        <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-dark table-sm mb-0">
                <thead><tr>
                    <th>Symbol</th>
                    <th>Side</th>
                    <th class="text-end">Trades</th>
                    <th class="text-end">Winrate</th>
                    <th class="text-end">Avg ROI</th>
                    <th class="text-end">Suggested Stop</th>
                </tr></thead>
                <tbody>
<?php foreach ($passportContext as $pc):
    $symbol = (string)($pc['symbol'] ?? '-');
    $sides = $pc['sides'] ?? [];
    foreach (['long', 'short'] as $s):
        $sd = $sides[$s] ?? [];
?>
                    <tr>
                        <td class="fw-bold"><?= htmlspecialchars($symbol) ?></td>
                        <td><?= $sideBadge($s) ?></td>
                        <td class="text-end"><?= (int)($sd['trades'] ?? 0) ?></td>
                        <td class="text-end"><?= $fmtWinrate((float)($sd['winrate'] ?? 0)) ?></td>
                        <td class="text-end"><?= $fmtRoi((float)($sd['avg_roi'] ?? 0)) ?></td>
                        <td class="text-end"><?= ($sd['suggested_stop'] ?? null) !== null ? $fmtRoi((float)$sd['suggested_stop']) : '<span class="text-muted">N/A</span>' ?></td>
                    </tr>
<?php endforeach; endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
};

require __DIR__ . '/_layout.php';
