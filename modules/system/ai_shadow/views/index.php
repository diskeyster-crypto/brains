<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Shadow — Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary: #3b82f6;
            --card-bg: #1e293b;
            --border-color: #334155;
        }
        body { background: #0f172a; color: #e2e8f0; font-size: 0.9rem; }
        .card { background: var(--card-bg); border: 1px solid var(--border-color); }
        .card-header { background: rgba(0,0,0,0.2); border-bottom: 1px solid var(--border-color); }
        .table-dark { --bs-table-bg: transparent; }
        .form-control, .form-select {
            background: #1e293b; border-color: var(--border-color); color: #e2e8f0;
        }
        .form-control:focus, .form-select:focus {
            background: #1e293b; border-color: var(--primary); color: #e2e8f0;
        }
        .stat-card { background: var(--card-bg); border: 1px solid var(--border-color);
                     border-radius: 8px; padding: 1rem; text-align: center; }
        .stat-value { font-size: 1.5rem; font-weight: 700; color: var(--primary); }
        .stat-label { font-size: 0.75rem; color: #94a3b8; margin-top: 0.2rem; }
        .badge-enter { background: #16a34a; }
        .badge-skip  { background: #6b7280; }
        .badge-agree { background: #0ea5e9; }
        .badge-disagree { background: #f59e0b; }
        .positive { color: #4ade80; }
        .negative { color: #f87171; }
        .neutral  { color: #94a3b8; }
        #flash-msg { display: none; position: fixed; top: 1rem; right: 1rem;
                     z-index: 9999; min-width: 260px; }
    </style>
</head>
<body>
<div class="container-fluid py-4">

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">
                <i class="bi bi-robot text-primary me-2"></i>
                AI Shadow
                <span class="badge bg-secondary ms-2"><?= htmlspecialchars((string)($status['mode'] ?? 'shadow')) ?></span>
                <?php if ($status['enabled'] ?? false): ?>
                    <span class="badge bg-success ms-1">ENABLED</span>
                <?php else: ?>
                    <span class="badge bg-secondary ms-1">DISABLED</span>
                <?php endif; ?>
            </h4>
            <p class="text-muted mb-0">
                Read-only AI simulation overlay — no real trading authority
            </p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary btn-sm" onclick="runMirror()">
                <i class="bi bi-play-fill me-1"></i> Run Mirror
            </button>
            <a href="/admin/smart_brain/ai_shadow" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-gear me-1"></i> Settings
            </a>
        </div>
    </div>

    <!-- Flash message -->
    <div id="flash-msg" class="alert alert-info alert-dismissible" role="alert">
        <span id="flash-text"></span>
        <button type="button" class="btn-close" onclick="document.getElementById('flash-msg').style.display='none'"></button>
    </div>

    <!-- Stats Summary -->
    <div class="row g-3 mb-4">
        <?php
        $statCards = [
            ['label' => 'Mirrored Signals',  'key' => 'total_mirrored_signals', 'fmt' => 'int'],
            ['label' => 'Virtual Trades',     'key' => 'total_virtual_trades',   'fmt' => 'int'],
            ['label' => 'AI Win Rate',         'key' => 'ai_win_rate',            'fmt' => 'pct'],
            ['label' => 'AI Avg ROI',          'key' => 'ai_avg_roi',             'fmt' => 'roi'],
            ['label' => 'AI Expectancy',       'key' => 'ai_expectancy',          'fmt' => 'roi'],
            ['label' => 'Live Avg ROI',        'key' => 'live_avg_roi',           'fmt' => 'roi'],
            ['label' => 'Live vs AI Δ',        'key' => 'live_vs_ai_delta',       'fmt' => 'roi'],
            ['label' => 'Agreement Rate',      'key' => 'agreement_rate',         'fmt' => 'pct'],
        ];
        foreach ($statCards as $sc):
            $raw = $stats[$sc['key']] ?? 0;
            if ($sc['fmt'] === 'pct') {
                $display = number_format((float)$raw * 100, 1) . '%';
            } elseif ($sc['fmt'] === 'roi') {
                $val = (float)$raw * 100;
                $cls = $val > 0 ? 'positive' : ($val < 0 ? 'negative' : 'neutral');
                $display = '<span class="' . $cls . '">' . ($val >= 0 ? '+' : '') . number_format($val, 2) . '%</span>';
            } else {
                $display = (string)(int)$raw;
                $cls     = '';
            }
        ?>
        <div class="col-6 col-sm-4 col-md-3 col-xl-1-5">
            <div class="stat-card">
                <div class="stat-value"><?= $display ?></div>
                <div class="stat-label"><?= htmlspecialchars($sc['label']) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Tabs Navigation -->
    <ul class="nav nav-tabs mb-3" id="shadowTabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-signals" type="button">
                <i class="bi bi-broadcast me-1"></i> Signals
                <span class="badge bg-secondary ms-1"><?= count($virtualSignals) ?></span>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-active" type="button">
                <i class="bi bi-play-circle me-1"></i> Active Trades
                <span class="badge bg-success ms-1"><?= count($activeTrades) ?></span>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-closed" type="button">
                <i class="bi bi-check-circle me-1"></i> Closed Trades
                <span class="badge bg-secondary ms-1"><?= count($closedTrades) ?></span>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-journal" type="button">
                <i class="bi bi-journal-text me-1"></i> Journal
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-analytics" type="button">
                <i class="bi bi-bar-chart me-1"></i> Analytics
            </button>
        </li>
    </ul>

    <div class="tab-content">

    <!-- ===== TAB: SIGNALS ===== -->
    <div class="tab-pane fade show active" id="tab-signals">

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-header py-2">
            <i class="bi bi-funnel me-1"></i> Filters
        </div>
        <div class="card-body py-3">
            <div class="row g-2">
                <div class="col-12 col-sm-6 col-md-2">
                    <input type="text" id="f-symbol" class="form-control form-control-sm"
                           placeholder="Symbol (e.g. BTCUSDT)" oninput="applyFilters()">
                </div>
                <div class="col-12 col-sm-6 col-md-2">
                    <select id="f-pattern" class="form-select form-select-sm" onchange="applyFilters()">
                        <option value="">All Patterns</option>
                        <?php foreach ((array)($config['allowed_patterns'] ?? []) as $p): ?>
                        <option value="<?= htmlspecialchars((string)$p) ?>"><?= htmlspecialchars((string)$p) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-4 col-md-2">
                    <select id="f-decision" class="form-select form-select-sm" onchange="applyFilters()">
                        <option value="">All AI Decisions</option>
                        <option value="enter">Enter</option>
                        <option value="skip">Skip</option>
                    </select>
                </div>
                <div class="col-12 col-sm-4 col-md-2">
                    <select id="f-agreement" class="form-select form-select-sm" onchange="applyFilters()">
                        <option value="">All</option>
                        <option value="agree">Agree</option>
                        <option value="disagree">Disagree</option>
                        <option value="pending">Pending</option>
                    </select>
                </div>
                <div class="col-12 col-sm-4 col-md-2">
                    <select id="f-profitable" class="form-select form-select-sm" onchange="applyFilters()">
                        <option value="">All Results</option>
                        <option value="profitable">Profitable</option>
                        <option value="failed">Failed</option>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-md-2">
                    <button class="btn btn-outline-secondary btn-sm w-100" onclick="clearFilters()">
                        <i class="bi bi-x-circle me-1"></i> Clear
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Comparison Table -->
    <div class="card">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <span><i class="bi bi-table me-1"></i> Virtual Signals</span>
            <span class="text-muted" id="row-count"></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-dark table-hover table-sm mb-0" id="signals-table">
                    <thead>
                        <tr>
                            <th>Symbol</th>
                            <th>Pattern</th>
                            <th>Live Decision</th>
                            <th>AI Decision</th>
                            <th>Confidence</th>
                            <th>Quality</th>
                            <th>Agreement</th>
                            <th>Mirrored At</th>
                            <th>Provider</th>
                        </tr>
                    </thead>
                    <tbody id="signals-tbody">
                        <?php if (empty($virtualSignals)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                                No virtual signals yet. Click <strong>Run Mirror</strong> to start.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($virtualSignals as $vs): ?>
                        <?php
                            $aiDec = (string)($vs['ai_decision'] ?? '');
                            $agree = (string)($vs['agreement']   ?? '');
                            $conf  = number_format((float)($vs['ai_confidence']   ?? 0), 2);
                            $qual  = number_format((float)($vs['ai_quality_score']?? 0), 2);
                            $ts    = isset($vs['mirrored_at']) ? date('Y-m-d H:i', (int)$vs['mirrored_at']) : '—';
                        ?>
                        <tr data-symbol="<?= htmlspecialchars((string)($vs['symbol'] ?? '')) ?>"
                            data-pattern="<?= htmlspecialchars((string)($vs['pattern_algorithm'] ?? '')) ?>"
                            data-decision="<?= htmlspecialchars($aiDec) ?>"
                            data-agreement="<?= htmlspecialchars($agree) ?>">
                            <td><strong><?= htmlspecialchars((string)($vs['symbol'] ?? '—')) ?></strong></td>
                            <td><small><?= htmlspecialchars((string)($vs['pattern_algorithm'] ?? '—')) ?></small></td>
                            <td>
                                <span class="badge bg-secondary"><?= htmlspecialchars((string)($vs['live_decision'] ?? '—')) ?></span>
                            </td>
                            <td>
                                <span class="badge <?= $aiDec === 'enter' ? 'badge-enter' : 'badge-skip' ?>">
                                    <?= htmlspecialchars($aiDec ?: '—') ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($conf) ?></td>
                            <td><?= htmlspecialchars($qual) ?></td>
                            <td>
                                <span class="badge <?= $agree === 'agree' ? 'badge-agree' : ($agree === 'disagree' ? 'badge-disagree' : 'bg-secondary') ?>">
                                    <?= htmlspecialchars($agree ?: '—') ?>
                                </span>
                            </td>
                            <td><small><?= htmlspecialchars($ts) ?></small></td>
                            <td><small class="text-muted"><?= htmlspecialchars((string)($vs['provider'] ?? '—')) ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <!-- Pagination -->
        <div class="card-footer d-flex justify-content-between align-items-center py-2">
            <div class="text-muted" id="page-info">Page 1</div>
            <div>
                <button class="btn btn-outline-secondary btn-sm me-1" id="btn-prev" onclick="changePage(-1)" disabled>
                    <i class="bi bi-chevron-left"></i>
                </button>
                <button class="btn btn-outline-secondary btn-sm" id="btn-next" onclick="changePage(1)" disabled>
                    <i class="bi bi-chevron-right"></i>
                </button>
            </div>
        </div>
    </div><!-- /tab-pane signals -->

    <!-- ===== TAB: ACTIVE TRADES ===== -->
    <div class="tab-pane fade" id="tab-active">
        <div class="card">
            <div class="card-header py-2"><i class="bi bi-play-circle me-1"></i> Active Virtual Trades</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-dark table-hover table-sm mb-0">
                        <thead><tr>
                            <th>Symbol</th><th>Pattern</th><th>AI Decision</th>
                            <th>Entry Price</th><th>Current ROI</th><th>MFE</th><th>MAE</th>
                            <th>Opened</th>
                        </tr></thead>
                        <tbody>
                            <?php if (empty($activeTrades)): ?>
                            <tr><td colspan="8" class="text-center text-muted py-3">No active virtual trades.</td></tr>
                            <?php else: ?>
                            <?php foreach ($activeTrades as $t): ?>
                            <?php
                                $roi = isset($t['roi']) ? (float)$t['roi'] : null;
                                $roiCls = $roi === null ? 'neutral' : ($roi > 0 ? 'positive' : 'negative');
                                $roiStr = $roi !== null ? (($roi >= 0 ? '+' : '') . number_format($roi * 100, 2) . '%') : '—';
                                $mfe = isset($t['mfe']) ? number_format((float)$t['mfe'] * 100, 2) . '%' : '—';
                                $mae = isset($t['mae']) ? number_format((float)$t['mae'] * 100, 2) . '%' : '—';
                                $openedAt = isset($t['opened_at']) ? date('Y-m-d H:i', (int)$t['opened_at']) : '—';
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars((string)($t['symbol'] ?? '—')) ?></strong></td>
                                <td><small><?= htmlspecialchars((string)($t['pattern_algorithm'] ?? '—')) ?></small></td>
                                <td><span class="badge <?= (string)($t['ai_decision'] ?? '') === 'enter' ? 'badge-enter' : 'badge-skip' ?>"><?= htmlspecialchars((string)($t['ai_decision'] ?? '—')) ?></span></td>
                                <td><?= number_format((float)($t['entry_price'] ?? 0), 4) ?></td>
                                <td class="<?= $roiCls ?>"><?= $roiStr ?></td>
                                <td class="positive"><?= $mfe ?></td>
                                <td class="negative"><?= $mae ?></td>
                                <td><small><?= $openedAt ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div><!-- /tab-pane active -->

    <!-- ===== TAB: CLOSED TRADES ===== -->
    <div class="tab-pane fade" id="tab-closed">
        <div class="card">
            <div class="card-header py-2"><i class="bi bi-check-circle me-1"></i> Closed Virtual Trades — Live vs AI Comparison</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-dark table-hover table-sm mb-0">
                        <thead><tr>
                            <th>Symbol</th><th>Pattern</th><th>AI Dec.</th>
                            <th>AI Entry</th><th>AI Exit</th><th>AI ROI</th>
                            <th>Live Entry</th><th>Live Close</th><th>Live ROI</th>
                            <th>Δ ROI</th><th>Live Reason</th><th>AI Reason</th>
                            <th>Agreement</th><th>Conf</th><th>MFE</th><th>MAE</th>
                        </tr></thead>
                        <tbody>
                            <?php if (empty($closedTrades)): ?>
                            <tr><td colspan="16" class="text-center text-muted py-3">No closed virtual trades yet.</td></tr>
                            <?php else: ?>
                            <?php foreach ($closedTrades as $ct): ?>
                            <?php
                                $aiRoi  = isset($ct['roi'])      ? (float)$ct['roi']      : null;
                                $liveRoi= isset($ct['live_roi']) ? (float)$ct['live_roi'] : null;
                                $delta  = isset($ct['delta_roi'])? (float)$ct['delta_roi']: null;
                                $aiRoiStr  = $aiRoi   !== null ? ($aiRoi   >= 0 ? '+' : '') . number_format($aiRoi   * 100, 2) . '%' : '—';
                                $liveRoiStr= $liveRoi !== null ? ($liveRoi >= 0 ? '+' : '') . number_format($liveRoi * 100, 2) . '%' : '—';
                                $deltaStr  = $delta   !== null ? ($delta   >= 0 ? '+' : '') . number_format($delta   * 100, 2) . '%' : '—';
                                $aiRoiCls   = $aiRoi   === null ? 'neutral' : ($aiRoi   > 0 ? 'positive' : 'negative');
                                $liveRoiCls = $liveRoi === null ? 'neutral' : ($liveRoi > 0 ? 'positive' : 'negative');
                                $deltaCls   = $delta   === null ? 'neutral' : ($delta   > 0 ? 'positive' : 'negative');
                                $agree = (string)($ct['agreement'] ?? '');
                                $mfe = isset($ct['mfe']) ? number_format((float)$ct['mfe'] * 100, 2) . '%' : '—';
                                $mae = isset($ct['mae']) ? number_format((float)$ct['mae'] * 100, 2) . '%' : '—';
                                $aiEntryTs  = isset($ct['ai_entry_timestamp']) ? date('m-d H:i', (int)$ct['ai_entry_timestamp']) : '—';
                                $aiExitTs   = isset($ct['ai_exit_timestamp'])  ? date('m-d H:i', (int)$ct['ai_exit_timestamp'])  : '—';
                                $liveEntryTs= isset($ct['live_entry_timestamp']) && (int)$ct['live_entry_timestamp'] > 0 ? date('m-d H:i', (int)$ct['live_entry_timestamp']) : '—';
                                $liveCloseTs= isset($ct['live_closed_at'])      && (int)$ct['live_closed_at']      > 0 ? date('m-d H:i', (int)$ct['live_closed_at'])      : '—';
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars((string)($ct['symbol'] ?? '—')) ?></strong></td>
                                <td><small><?= htmlspecialchars((string)($ct['pattern_algorithm'] ?? '—')) ?></small></td>
                                <td><span class="badge <?= (string)($ct['ai_decision'] ?? '') === 'enter' ? 'badge-enter' : 'badge-skip' ?>"><?= htmlspecialchars((string)($ct['ai_decision'] ?? '—')) ?></span></td>
                                <td><small><?= $aiEntryTs ?><br><?= number_format((float)($ct['ai_entry_price'] ?? 0), 4) ?></small></td>
                                <td><small><?= $aiExitTs ?><br><?= number_format((float)($ct['ai_exit_price'] ?? 0), 4) ?></small></td>
                                <td class="<?= $aiRoiCls ?>"><?= $aiRoiStr ?></td>
                                <td><small><?= $liveEntryTs ?><br><?= number_format((float)($ct['live_entry_price'] ?? 0), 4) ?></small></td>
                                <td><small><?= $liveCloseTs ?></small></td>
                                <td class="<?= $liveRoiCls ?>"><?= $liveRoiStr ?></td>
                                <td class="<?= $deltaCls ?>"><?= $deltaStr ?></td>
                                <td><small><?= htmlspecialchars((string)($ct['live_close_reason'] ?? '—')) ?></small></td>
                                <td><small><?= htmlspecialchars((string)($ct['ai_exit_reason']   ?? '—')) ?></small></td>
                                <td><span class="badge <?= $agree === 'agree' ? 'badge-agree' : ($agree === 'disagree' ? 'badge-disagree' : 'bg-secondary') ?>"><?= htmlspecialchars($agree ?: '—') ?></span></td>
                                <td><?= number_format((float)($ct['ai_confidence'] ?? 0), 2) ?></td>
                                <td class="positive"><?= $mfe ?></td>
                                <td class="negative"><?= $mae ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div><!-- /tab-pane closed -->

    <!-- ===== TAB: JOURNAL ===== -->
    <div class="tab-pane fade" id="tab-journal">
        <div class="card">
            <div class="card-header py-2">
                <i class="bi bi-journal-text me-1"></i> Decision Journal
                <small class="text-muted ms-2">Recent AI decision events (newest first)</small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-dark table-hover table-sm mb-0">
                        <thead><tr>
                            <th>Time</th><th>Signal ID</th><th>Event</th><th>Symbol</th><th>Pattern</th>
                            <th>Decision</th><th>Confidence</th><th>Details</th>
                        </tr></thead>
                        <tbody id="journal-tbody">
                            <tr><td colspan="8" class="text-center text-muted py-3">
                                <button class="btn btn-outline-secondary btn-sm" onclick="loadJournal()">
                                    <i class="bi bi-arrow-clockwise me-1"></i> Load Journal
                                </button>
                            </td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div><!-- /tab-pane journal -->

    <!-- ===== TAB: ANALYTICS ===== -->
    <div class="tab-pane fade" id="tab-analytics">
        <?php
        $patternBreakdown = (array)($stats['pattern_breakdown'] ?? []);
        $symbolBreakdown  = (array)($stats['symbol_breakdown']  ?? []);
        ?>

        <!-- Research metrics row -->
        <div class="row g-3 mb-4">
            <?php
            $researchCards = [
                ['label' => 'Avg Conf (Winners)', 'key' => 'ai_avg_confidence_winners', 'fmt' => 'dec'],
                ['label' => 'Avg Conf (Losers)',  'key' => 'ai_avg_confidence_losers',  'fmt' => 'dec'],
                ['label' => 'Runner Catch Rate',  'key' => 'runner_catch_rate',          'fmt' => 'pct'],
                ['label' => 'Premature Close',    'key' => 'premature_close_rate',       'fmt' => 'pct'],
                ['label' => 'False Reject Rate',  'key' => 'false_reject_rate',          'fmt' => 'pct'],
                ['label' => 'False Allow Rate',   'key' => 'false_allow_rate',           'fmt' => 'pct'],
                ['label' => 'Live Winrate',       'key' => 'live_win_rate',              'fmt' => 'pct'],
                ['label' => 'Live Expectancy',    'key' => 'live_expectancy',            'fmt' => 'roi'],
            ];
            foreach ($researchCards as $rc):
                $raw = $stats[$rc['key']] ?? 0;
                if ($rc['fmt'] === 'pct') {
                    $display = number_format((float)$raw * 100, 1) . '%';
                } elseif ($rc['fmt'] === 'roi') {
                    $val = (float)$raw * 100;
                    $cls = $val > 0 ? 'positive' : ($val < 0 ? 'negative' : 'neutral');
                    $display = '<span class="' . $cls . '">' . ($val >= 0 ? '+' : '') . number_format($val, 2) . '%</span>';
                } else {
                    $display = number_format((float)$raw, 3);
                }
            ?>
            <div class="col-6 col-sm-4 col-md-3 col-xl-2">
                <div class="stat-card">
                    <div class="stat-value"><?= $display ?></div>
                    <div class="stat-label"><?= htmlspecialchars($rc['label']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Per-Pattern Stats -->
        <?php if (!empty($patternBreakdown)): ?>
        <div class="card mb-4">
            <div class="card-header py-2"><i class="bi bi-grid me-1"></i> Per-Pattern Breakdown</div>
            <div class="card-body p-0">
                <table class="table table-dark table-sm mb-0">
                    <thead><tr><th>Pattern</th><th>Count</th><th>Wins</th><th>Win Rate</th><th>Avg ROI</th></tr></thead>
                    <tbody>
                        <?php foreach ($patternBreakdown as $pat => $pb): ?>
                        <?php
                            $wr = number_format((float)($pb['win_rate'] ?? 0) * 100, 1) . '%';
                            $ar = (float)($pb['avg_roi'] ?? 0) * 100;
                            $arCls = $ar > 0 ? 'positive' : ($ar < 0 ? 'negative' : 'neutral');
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$pat) ?></td>
                            <td><?= (int)($pb['count']  ?? 0) ?></td>
                            <td><?= (int)($pb['wins']   ?? 0) ?></td>
                            <td><?= $wr ?></td>
                            <td class="<?= $arCls ?>"><?= ($ar >= 0 ? '+' : '') . number_format($ar, 2) ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Per-Symbol Stats -->
        <?php if (!empty($symbolBreakdown)): ?>
        <div class="card">
            <div class="card-header py-2"><i class="bi bi-currency-bitcoin me-1"></i> Per-Symbol Breakdown</div>
            <div class="card-body p-0">
                <table class="table table-dark table-sm mb-0">
                    <thead><tr>
                        <th>Symbol</th>
                        <th>AI Count</th><th>AI Win%</th><th>AI Avg ROI</th>
                        <th>Live Count</th><th>Live Win%</th><th>Live Avg ROI</th>
                        <th>Agree%</th><th>Disagree%</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($symbolBreakdown as $sym => $sb): ?>
                        <?php
                            $aiAr = (float)($sb['ai_avg_roi'] ?? 0) * 100;
                            $lvAr = (float)($sb['live_avg_roi'] ?? 0) * 100;
                            $aiArCls = $aiAr > 0 ? 'positive' : ($aiAr < 0 ? 'negative' : 'neutral');
                            $lvArCls = $lvAr > 0 ? 'positive' : ($lvAr < 0 ? 'negative' : 'neutral');
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars((string)$sym) ?></strong></td>
                            <td><?= (int)($sb['ai_count'] ?? 0) ?></td>
                            <td><?= number_format((float)($sb['ai_win_rate'] ?? 0) * 100, 1) ?>%</td>
                            <td class="<?= $aiArCls ?>"><?= ($aiAr >= 0 ? '+' : '') . number_format($aiAr, 2) ?>%</td>
                            <td><?= (int)($sb['live_count'] ?? 0) ?></td>
                            <td><?= number_format((float)($sb['live_win_rate'] ?? 0) * 100, 1) ?>%</td>
                            <td class="<?= $lvArCls ?>"><?= ($lvAr >= 0 ? '+' : '') . number_format($lvAr, 2) ?>%</td>
                            <td><?= number_format((float)($sb['agree_rate']    ?? 0) * 100, 1) ?>%</td>
                            <td><?= number_format((float)($sb['disagree_rate'] ?? 0) * 100, 1) ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-secondary">
            No per-symbol data yet. Run a mirror cycle to populate analytics.
        </div>
        <?php endif; ?>

    </div><!-- /tab-pane analytics -->

    </div><!-- /tab-content -->

</div><!-- /container-fluid -->

<script>
// ---- Mirror action ----
function runMirror() {
    showFlash('Running mirror cycle…', 'info');
    fetch('/admin/smart_brain/ai_shadow/run_mirror', { method: 'POST' })
        .then(r => r.json())
        .then(d => {
            if (d.skipped) {
                showFlash('Module is disabled. Enable it in Settings first.', 'warning');
            } else {
                const sc = d.signal_counts || {};
                showFlash(
                    'Mirror done. Newly mirrored: ' + (sc.newly_mirrored ?? 0) +
                    ' signals. Errors: ' + (sc.errors ?? 0), 'success'
                );
                setTimeout(() => location.reload(), 1500);
            }
        })
        .catch(() => showFlash('Error calling mirror API.', 'danger'));
}

function showFlash(msg, type) {
    const el = document.getElementById('flash-msg');
    el.className = 'alert alert-' + type + ' alert-dismissible';
    document.getElementById('flash-text').textContent = msg;
    el.style.display = 'block';
}

// ---- Filters & pagination ----
const PAGE_SIZE = 25;
let currentPage = 1;
let allRows = [];

function initTable() {
    allRows = Array.from(document.querySelectorAll('#signals-tbody tr[data-symbol]'));
    applyFilters();
}

function applyFilters() {
    const symbol   = document.getElementById('f-symbol').value.trim().toUpperCase();
    const pattern  = document.getElementById('f-pattern').value;
    const decision = document.getElementById('f-decision').value;
    const agreement= document.getElementById('f-agreement').value;
    const profit   = document.getElementById('f-profitable').value;

    const filtered = allRows.filter(row => {
        if (symbol && !row.dataset.symbol.includes(symbol)) return false;
        if (pattern && row.dataset.pattern !== pattern) return false;
        if (decision && row.dataset.decision !== decision) return false;
        if (agreement && row.dataset.agreement !== agreement) return false;
        return true;
    });

    currentPage = 1;
    renderPage(filtered);
}

function renderPage(rows) {
    const total = rows.length;
    const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
    const start = (currentPage - 1) * PAGE_SIZE;
    const end   = start + PAGE_SIZE;

    allRows.forEach(r => r.style.display = 'none');
    rows.slice(start, end).forEach(r => r.style.display = '');

    document.getElementById('row-count').textContent = total + ' signals';
    document.getElementById('page-info').textContent = 'Page ' + currentPage + ' / ' + totalPages;
    document.getElementById('btn-prev').disabled = currentPage <= 1;
    document.getElementById('btn-next').disabled = currentPage >= totalPages;

    // Store for pagination
    window._filteredRows = rows;
}

function changePage(delta) {
    const total = window._filteredRows ? window._filteredRows.length : 0;
    const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
    currentPage = Math.min(totalPages, Math.max(1, currentPage + delta));
    renderPage(window._filteredRows || []);
}

function clearFilters() {
    document.getElementById('f-symbol').value   = '';
    document.getElementById('f-pattern').value  = '';
    document.getElementById('f-decision').value = '';
    document.getElementById('f-agreement').value= '';
    document.getElementById('f-profitable').value='';
    applyFilters();
}

// ---- Journal loader ----
function loadJournal(limit) {
    limit = limit || 100;
    const tbody = document.getElementById('journal-tbody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-2">Loading…</td></tr>';

    fetch('/admin/smart_brain/ai_shadow/journal?limit=' + limit)
        .then(r => r.json())
        .then(data => {
            const events = data.events || [];
            if (!events.length) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-2">No journal events yet.</td></tr>';
                return;
            }
            const eventColors = {
                'signal_seen': 'bg-secondary',
                'ai_input_built': 'bg-secondary',
                'ai_request_sent': 'bg-info text-dark',
                'ai_response_received': 'bg-primary',
                'ai_decision_enter': 'bg-success',
                'ai_decision_skip': 'bg-secondary',
                'ai_decision_hold': 'bg-warning text-dark',
                'ai_decision_close': 'bg-danger',
                'virtual_trade_opened': 'bg-success',
                'virtual_trade_updated': 'bg-secondary',
                'virtual_trade_closed': 'bg-warning text-dark',
                'comparison_finalized': 'bg-primary',
                'provider_error': 'bg-danger',
            };
            tbody.innerHTML = events.map(ev => {
                const ts      = ev.ts     ? new Date(ev.ts * 1000).toISOString().replace('T', ' ').slice(0, 16) : '—';
                const sigId   = ev.signal_id  || '—';
                const evType  = ev.event_type || '—';
                const sym     = ev.symbol          || '—';
                const pat     = ev.pattern_algorithm || '—';
                const dec     = ev.decision || ev.ai_decision || '—';
                const conf    = ev.confidence !== undefined ? Number(ev.confidence).toFixed(2) : '—';
                const badgeCls= eventColors[evType] || 'bg-secondary';
                const shortSig= sigId.length > 12 ? sigId.slice(0, 12) + '…' : sigId;
                const detail  = ev.error
                    ? '<span class="text-danger">' + ev.error.slice(0, 40) + '</span>'
                    : (ev.reasons ? ev.reasons.slice(0, 2).join(', ').slice(0, 40) : '');
                return '<tr>'
                    + '<td><small>' + ts + '</small></td>'
                    + '<td><small title="' + sigId + '">' + shortSig + '</small></td>'
                    + '<td><span class="badge ' + badgeCls + '" style="font-size:0.7rem">' + evType + '</span></td>'
                    + '<td><strong>' + sym + '</strong></td>'
                    + '<td><small>' + pat + '</small></td>'
                    + '<td><small>' + dec + '</small></td>'
                    + '<td>' + conf + '</td>'
                    + '<td><small class="text-muted">' + detail + '</small></td>'
                    + '</tr>';
            }).join('');
        })
        .catch(() => {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger py-2">Error loading journal.</td></tr>';
        });
}

document.addEventListener('DOMContentLoaded', initTable);
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
