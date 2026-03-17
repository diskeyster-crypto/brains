<?php
/**
 * Smart Brain Module - Simulator Analytics View
 *
 * Simulator UI Clarity V3: Russian labels, side analytics, tooltips,
 * clickable Bybit links, close reason colors, LONG/SHORT summary.
 */

/** @var string $smartBrainUrl */
/** @var array<int,array<string,mixed>> $waiting */
/** @var array<int,array<string,mixed>> $active */
/** @var array<int,array<string,mixed>> $closed */
/** @var array<string,mixed> $stats */
/** @var array<string,int> $exit_reasons */
/** @var float $avg_roi */
/** @var float $median_roi */
/** @var float $avg_mae */
/** @var float $avg_mfe */
/** @var float $avg_duration */
/** @var int $wins */
/** @var int $losses */
/** @var array<string,int> $roi_buckets */
/** @var array<string,array<string,mixed>> $side_summary */
/** @var array<string,array<string,mixed>> $pattern_stats */
/** @var array<string,array<string,mixed>> $leverage_mode_stats */
/** @var array<string,array<string,mixed>> $stop_control_stats */
/** @var array<string,mixed> $symbol_intelligence */

$pageTitle = 'Smart Brain - Аналитика симулятора';
$activeTab = 'simulator_analytics';

$extraStyles = '
    .roi-positive { color: #22c55e; }
    .roi-negative { color: #ef4444; }
    .badge-win { background: #22c55e; }
    .badge-loss { background: #ef4444; }
    .badge-active-trade { background: #3b82f6; }
    .badge-closed-trade { background: #64748b; }
    .badge-long { background: #22c55e; color: #fff; }
    .badge-short { background: #ef4444; color: #fff; }
    .reason-stop_loss { background: #ef4444; }
    .reason-early_failure { background: #f97316; }
    .reason-trailing_stop { background: #22c55e; }
    .reason-break_even_stop { background: #64748b; }
    .reason-take_profit { background: #4ade80; }
    .stat-card { text-align: center; padding: 1rem; }
    .stat-card .stat-value { font-size: 1.5rem; font-weight: 700; }
    .stat-card .stat-label { font-size: 0.8rem; color: #94a3b8; }
    .chart-bar { display: inline-block; min-width: 4px; border-radius: 2px; vertical-align: bottom; }
    .reason-bar { height: 20px; border-radius: 3px; display: inline-block; }
';

/**
 * Helper: format ROI as colored percentage
 */
$fmtRoi = function($roi): string {
    $val = (float)$roi;
    $pct = number_format($val * 100, 2);
    $cls = $val >= 0 ? 'roi-positive' : 'roi-negative';
    return '<span class="' . $cls . '">' . ($val >= 0 ? '+' : '') . htmlspecialchars($pct) . '%</span>';
};

/**
 * Helper: Bybit symbol link
 */
$symbolLink = function(string $symbol): string {
    $safe = htmlspecialchars($symbol);
    return '<a href="https://www.bybit.com/trade/usdt/' . $safe . '" target="_blank" rel="noopener noreferrer" class="text-info text-decoration-none fw-bold">' . $safe . '</a>';
};

/**
 * Helper: side badge
 */
$sideBadge = function($side): string {
    $s = strtolower((string)($side ?? ''));
    if ($s === 'short') {
        return '<span class="badge badge-short">▼ SHORT</span>';
    }
    if ($s === 'long') {
        return '<span class="badge badge-long">▲ LONG</span>';
    }
    return '<span class="badge bg-secondary">—</span>';
};

/**
 * Helper: close reason badge with color
 */
$reasonBadgeFn = function($reason): string {
    $r = (string)($reason ?? '-');
    $cls = match($r) {
        'stop_loss' => 'reason-stop_loss',
        'early_failure' => 'reason-early_failure',
        'trailing_stop' => 'reason-trailing_stop',
        'break_even_stop' => 'reason-break_even_stop',
        'take_profit' => 'reason-take_profit',
        default => 'bg-secondary',
    };
    return '<span class="badge ' . $cls . '">' . htmlspecialchars($r) . '</span>';
};

$totalClosed = count($closed);
$totalActive = count($active);
$totalWaiting = count($waiting);
$totalAll = $totalClosed + $totalActive + $totalWaiting;
$winrate = $totalClosed > 0 ? ($wins / $totalClosed) : 0;

$pageContent = function() use (
    $waiting, $active, $closed, $stats, $exit_reasons,
    $avg_roi, $median_roi, $avg_mae, $avg_mfe, $avg_duration,
    $wins, $losses, $roi_buckets, $side_summary, $pattern_stats, $leverage_mode_stats, $stop_control_stats,
    $symbol_intelligence,
    $fmtRoi, $symbolLink, $sideBadge, $reasonBadgeFn,
    $totalClosed, $totalActive, $totalWaiting, $totalAll, $winrate,
    $smartBrainUrl
) {
?>
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-graph-up-arrow me-2 text-primary"></i>Аналитика симулятора</h4>
            <p class="text-secondary mb-0">Детальная аналитика бумажной торговли</p>
        </div>
        <div>
            <a href="<?= htmlspecialchars($smartBrainUrl) ?>/simulator" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-joystick me-1"></i>Назад к Симулятору
            </a>
        </div>
    </div>

    <!-- ===== A. GLOBAL STATS ===== -->
    <div class="card mb-4">
        <div class="card-header"><h5 style="margin:0;"><i class="bi bi-speedometer2 me-1"></i> Общая статистика</h5></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="stat-card"><div class="stat-value"><?= $totalAll ?></div><div class="stat-label">Всего сигналов</div></div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="stat-card"><div class="stat-value"><?= $totalWaiting ?></div><div class="stat-label">Ожидание</div></div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="stat-card"><div class="stat-value text-primary"><?= $totalActive ?></div><div class="stat-label">Активные</div></div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="stat-card"><div class="stat-value text-success"><?= $totalClosed ?></div><div class="stat-label">Закрытые</div></div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="stat-card"><div class="stat-value"><?= number_format($winrate * 100, 1) ?>%</div><div class="stat-label">Винрейт</div></div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="stat-card"><div class="stat-value <?= $avg_roi >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format($avg_roi * 100, 2) ?>%</div><div class="stat-label">Средний ROI</div></div>
                </div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="stat-card"><div class="stat-value <?= $median_roi >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format($median_roi * 100, 2) ?>%</div><div class="stat-label">Медиана ROI</div></div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="stat-card"><div class="stat-value"><?= number_format($avg_mae * 100, 2) ?>%</div><div class="stat-label">Средний MAE</div></div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="stat-card"><div class="stat-value"><?= number_format($avg_mfe * 100, 2) ?>%</div><div class="stat-label">Средний MFE</div></div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="stat-card"><div class="stat-value"><?= number_format($avg_duration, 0) ?> мин</div><div class="stat-label">Средняя длит.</div></div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="stat-card"><div class="stat-value roi-positive"><?= $wins ?></div><div class="stat-label">Выигрыши</div></div>
                </div>
                <div class="col-6 col-md-3 col-lg-2">
                    <div class="stat-card"><div class="stat-value roi-negative"><?= $losses ?></div><div class="stat-label">Проигрыши</div></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== SIDE SUMMARY ===== -->
    <div class="card mb-4">
        <div class="card-header"><h5 style="margin:0;"><i class="bi bi-arrow-left-right me-1"></i> Аналитика по направлению (LONG / SHORT)</h5></div>
        <div class="card-body">
            <div class="row g-3">
                <?php
                $longData = $side_summary['long'] ?? ['count' => 0, 'wins' => 0, 'roi_sum' => 0.0];
                $shortData = $side_summary['short'] ?? ['count' => 0, 'wins' => 0, 'roi_sum' => 0.0];
                $longWr = $longData['count'] > 0 ? ($longData['wins'] / $longData['count']) * 100 : 0;
                $shortWr = $shortData['count'] > 0 ? ($shortData['wins'] / $shortData['count']) * 100 : 0;
                $longAvgRoi = $longData['count'] > 0 ? ($longData['roi_sum'] / $longData['count']) * 100 : 0;
                $shortAvgRoi = $shortData['count'] > 0 ? ($shortData['roi_sum'] / $shortData['count']) * 100 : 0;
                ?>
                <div class="col-md-6">
                    <div class="card h-100" style="border-left: 4px solid #22c55e;">
                        <div class="card-body">
                            <h6 class="mb-3"><span class="badge badge-long">▲ LONG</span></h6>
                            <div class="row text-center">
                                <div class="col"><strong><?= $longData['count'] ?></strong><br><small class="text-secondary">Сделок</small></div>
                                <div class="col"><strong><?= $longData['wins'] ?></strong><br><small class="text-secondary">Выигрышей</small></div>
                                <div class="col"><strong><?= number_format($longWr, 1) ?>%</strong><br><small class="text-secondary">Винрейт</small></div>
                                <div class="col"><strong class="<?= $longAvgRoi >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format($longAvgRoi, 2) ?>%</strong><br><small class="text-secondary">Средний ROI</small></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card h-100" style="border-left: 4px solid #ef4444;">
                        <div class="card-body">
                            <h6 class="mb-3"><span class="badge badge-short">▼ SHORT</span></h6>
                            <div class="row text-center">
                                <div class="col"><strong><?= $shortData['count'] ?></strong><br><small class="text-secondary">Сделок</small></div>
                                <div class="col"><strong><?= $shortData['wins'] ?></strong><br><small class="text-secondary">Выигрышей</small></div>
                                <div class="col"><strong><?= number_format($shortWr, 1) ?>%</strong><br><small class="text-secondary">Винрейт</small></div>
                                <div class="col"><strong class="<?= $shortAvgRoi >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format($shortAvgRoi, 2) ?>%</strong><br><small class="text-secondary">Средний ROI</small></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== PATTERN STATISTICS ===== -->
    <div class="card mb-4">
        <div class="card-header"><h5 style="margin:0;"><i class="bi bi-puzzle me-1"></i> Статистика по паттернам</h5></div>
        <div class="card-body p-0">
            <?php if (empty($pattern_stats)): ?>
                <p class="text-secondary text-center py-4">Нет данных по паттернам</p>
            <?php else: ?>
                <div class="table-responsive">
                <table class="table table-dark table-hover table-sm mb-0">
                    <thead><tr>
                        <th>Паттерн</th>
                        <th class="text-end">Сделок</th>
                        <th class="text-end">Выигр.</th>
                        <th class="text-end">Проигр.</th>
                        <th class="text-end">Винрейт</th>
                        <th class="text-end">Ср. ROI</th>
                        <th class="text-end">Ср. MAE</th>
                        <th class="text-end">Ср. MFE</th>
                        <th class="text-end">Ср. длит.</th>
                        <th class="text-end">Long</th>
                        <th class="text-end">Short</th>
                        <th class="text-end">SL</th>
                        <th class="text-end">EF</th>
                        <th class="text-end">TS</th>
                        <th class="text-end">BE</th>
                        <th class="text-end">TP</th>
                        <th class="text-end">Ср. плечо</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($pattern_stats as $pName => $pData): ?>
                        <tr>
                            <td class="fw-bold"><?= htmlspecialchars((string)$pName) ?></td>
                            <td class="text-end"><?= (int)($pData['trades_total'] ?? 0) ?></td>
                            <td class="text-end roi-positive"><?= (int)($pData['wins'] ?? 0) ?></td>
                            <td class="text-end roi-negative"><?= (int)($pData['losses'] ?? 0) ?></td>
                            <td class="text-end"><?= number_format((float)($pData['winrate'] ?? 0) * 100, 1) ?>%</td>
                            <td class="text-end <?= (float)($pData['average_roi'] ?? 0) >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format((float)($pData['average_roi'] ?? 0) * 100, 2) ?>%</td>
                            <td class="text-end"><?= number_format((float)($pData['average_mae'] ?? 0) * 100, 2) ?>%</td>
                            <td class="text-end"><?= number_format((float)($pData['average_mfe'] ?? 0) * 100, 2) ?>%</td>
                            <td class="text-end"><?= number_format((float)($pData['average_duration'] ?? 0), 1) ?> мин</td>
                            <td class="text-end"><?= (int)($pData['long_count'] ?? 0) ?></td>
                            <td class="text-end"><?= (int)($pData['short_count'] ?? 0) ?></td>
                            <td class="text-end"><?= (int)($pData['stop_loss_count'] ?? 0) ?></td>
                            <td class="text-end"><?= (int)($pData['early_failure_count'] ?? 0) ?></td>
                            <td class="text-end"><?= (int)($pData['trailing_stop_count'] ?? 0) ?></td>
                            <td class="text-end"><?= (int)($pData['break_even_stop_count'] ?? 0) ?></td>
                            <td class="text-end"><?= (int)($pData['take_profit_count'] ?? 0) ?></td>
                            <td class="text-end"><?= number_format((float)($pData['average_leverage'] ?? 0), 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== LEVERAGE MODE STATS ===== -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h5 style="margin:0;"><i class="bi bi-speedometer me-1"></i> Режим плеча (Leverage)</h5></div>
                <div class="card-body">
                    <?php
                    $lmAuto = $leverage_mode_stats['auto'] ?? ['count' => 0, 'wins' => 0, 'roi_sum' => 0.0];
                    $lmManual = $leverage_mode_stats['manual'] ?? ['count' => 0, 'wins' => 0, 'roi_sum' => 0.0];
                    $lmAutoWr = $lmAuto['count'] > 0 ? ($lmAuto['wins'] / $lmAuto['count']) * 100 : 0;
                    $lmManualWr = $lmManual['count'] > 0 ? ($lmManual['wins'] / $lmManual['count']) * 100 : 0;
                    $lmAutoAvg = $lmAuto['count'] > 0 ? ($lmAuto['roi_sum'] / $lmAuto['count']) * 100 : 0;
                    $lmManualAvg = $lmManual['count'] > 0 ? ($lmManual['roi_sum'] / $lmManual['count']) * 100 : 0;
                    ?>
                    <div class="row text-center mb-3">
                        <div class="col-6">
                            <div class="card" style="border-left: 4px solid #3b82f6;">
                                <div class="card-body py-2">
                                    <h6 class="mb-2"><span class="badge bg-primary">AUTO</span></h6>
                                    <div class="row">
                                        <div class="col"><strong><?= $lmAuto['count'] ?></strong><br><small class="text-secondary">Сделок</small></div>
                                        <div class="col"><strong><?= number_format($lmAutoWr, 1) ?>%</strong><br><small class="text-secondary">Винрейт</small></div>
                                        <div class="col"><strong class="<?= $lmAutoAvg >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format($lmAutoAvg, 2) ?>%</strong><br><small class="text-secondary">Ср. ROI</small></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="card" style="border-left: 4px solid #f59e0b;">
                                <div class="card-body py-2">
                                    <h6 class="mb-2"><span class="badge bg-warning text-dark">MANUAL</span></h6>
                                    <div class="row">
                                        <div class="col"><strong><?= $lmManual['count'] ?></strong><br><small class="text-secondary">Сделок</small></div>
                                        <div class="col"><strong><?= number_format($lmManualWr, 1) ?>%</strong><br><small class="text-secondary">Винрейт</small></div>
                                        <div class="col"><strong class="<?= $lmManualAvg >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format($lmManualAvg, 2) ?>%</strong><br><small class="text-secondary">Ср. ROI</small></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== STOP CONTROL STATS ===== -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><h5 style="margin:0;"><i class="bi bi-shield-minus me-1"></i> Режим стоп-лосса (Stop Control)</h5></div>
                <div class="card-body">
                    <?php
                    $scAuto = $stop_control_stats['auto'] ?? ['count' => 0, 'wins' => 0, 'mae_sum' => 0.0];
                    $scManual = $stop_control_stats['manual'] ?? ['count' => 0, 'wins' => 0, 'mae_sum' => 0.0];
                    $scAutoWr = $scAuto['count'] > 0 ? ($scAuto['wins'] / $scAuto['count']) * 100 : 0;
                    $scManualWr = $scManual['count'] > 0 ? ($scManual['wins'] / $scManual['count']) * 100 : 0;
                    $scAutoMae = $scAuto['count'] > 0 ? ($scAuto['mae_sum'] / $scAuto['count']) * 100 : 0;
                    $scManualMae = $scManual['count'] > 0 ? ($scManual['mae_sum'] / $scManual['count']) * 100 : 0;
                    ?>
                    <div class="row text-center mb-3">
                        <div class="col-6">
                            <div class="card" style="border-left: 4px solid #3b82f6;">
                                <div class="card-body py-2">
                                    <h6 class="mb-2"><span class="badge bg-primary">AUTO</span></h6>
                                    <div class="row">
                                        <div class="col"><strong><?= $scAuto['count'] ?></strong><br><small class="text-secondary">Сделок</small></div>
                                        <div class="col"><strong><?= number_format($scAutoWr, 1) ?>%</strong><br><small class="text-secondary">Винрейт</small></div>
                                        <div class="col"><strong><?= number_format($scAutoMae, 2) ?>%</strong><br><small class="text-secondary">Ср. MAE</small></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="card" style="border-left: 4px solid #f59e0b;">
                                <div class="card-body py-2">
                                    <h6 class="mb-2"><span class="badge bg-warning text-dark">MANUAL</span></h6>
                                    <div class="row">
                                        <div class="col"><strong><?= $scManual['count'] ?></strong><br><small class="text-secondary">Сделок</small></div>
                                        <div class="col"><strong><?= number_format($scManualWr, 1) ?>%</strong><br><small class="text-secondary">Винрейт</small></div>
                                        <div class="col"><strong><?= number_format($scManualMae, 2) ?>%</strong><br><small class="text-secondary">Ср. MAE</small></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== SYMBOL INTELLIGENCE ===== -->
    <?php
    $si = $symbol_intelligence ?? [];
    $siStats = (array)($si['symbol_stats'] ?? []);
    $siWhitelist = (array)($si['whitelist'] ?? []);
    $siSoftWhitelist = (array)($si['soft_whitelist'] ?? []);
    $siBlacklist = (array)($si['blacklist'] ?? []);
    $siWatchlist = (array)($si['watchlist'] ?? []);
    $siWhitelistCount = (int)($si['whitelist_count'] ?? count($siWhitelist));
    $siSoftWhitelistCount = (int)($si['soft_whitelist_count'] ?? count($siSoftWhitelist));
    $siBlacklistCount = (int)($si['blacklist_count'] ?? count($siBlacklist));
    $siWatchlistCount = (int)($si['watchlist_count'] ?? count($siWatchlist));
    $siTotalSymbols = (int)($si['total_symbols'] ?? count($siStats));
    ?>
    <div class="card mb-4">
        <div class="card-header"><h5 style="margin:0;"><i class="bi bi-stars me-1"></i> Symbol Intelligence</h5></div>
        <div class="card-body">
            <!-- Summary cards -->
            <div class="row g-3 mb-3">
                <div class="col-6 col-md-2">
                    <div class="stat-card"><div class="stat-value"><?= $siTotalSymbols ?></div><div class="stat-label">Всего символов</div></div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="stat-card"><div class="stat-value roi-positive"><?= $siWhitelistCount ?></div><div class="stat-label">Whitelist</div></div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="stat-card"><div class="stat-value" style="color:#38bdf8;"><?= $siSoftWhitelistCount ?></div><div class="stat-label">Soft Whitelist</div></div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="stat-card"><div class="stat-value roi-negative"><?= $siBlacklistCount ?></div><div class="stat-label">Blacklist</div></div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="stat-card"><div class="stat-value text-warning"><?= $siWatchlistCount ?></div><div class="stat-label">Watchlist</div></div>
                </div>
            </div>

            <!-- Per-symbol table -->
            <?php if (empty($siStats)): ?>
                <p class="text-secondary text-center py-3">Нет данных по символам. Запустите симуляцию для сбора статистики.</p>
            <?php else: ?>
                <div class="table-responsive">
                <table class="table table-dark table-hover table-sm mb-0">
                    <thead><tr>
                        <th>Символ</th>
                        <th>Статус</th>
                        <th class="text-end">Score</th>
                        <th class="text-end">Сделок</th>
                        <th class="text-end">Выигр.</th>
                        <th class="text-end">Проигр.</th>
                        <th class="text-end">Винрейт</th>
                        <th class="text-end">Ср. ROI</th>
                        <th class="text-end">Ср. MAE</th>
                        <th class="text-end">Ср. MFE</th>
                        <th class="text-end">Посл. ROI</th>
                        <th class="text-end">Недавн. WR</th>
                        <th class="text-end">Недавн. ROI</th>
                        <th>Посл. сделка</th>
                    </tr></thead>
                    <tbody>
                    <?php
                    // Sort by symbol_score descending
                    $sortedStats = $siStats;
                    uasort($sortedStats, fn($a, $b) => ($b['symbol_score'] ?? 0) <=> ($a['symbol_score'] ?? 0));
                    foreach ($sortedStats as $sym => $sd):
                        $statusBadge = match ((string)($sd['status'] ?? 'unknown')) {
                            'whitelist' => '<span class="badge bg-success">whitelist</span>',
                            'soft_whitelist' => '<span class="badge" style="background:#0ea5e9;">soft_whitelist</span>',
                            'blacklist' => '<span class="badge bg-danger">blacklist</span>',
                            'watchlist' => '<span class="badge bg-warning text-dark">watchlist</span>',
                            default => '<span class="badge bg-secondary">unknown</span>',
                        };
                    ?>
                        <tr>
                            <td><?= $symbolLink((string)$sym) ?></td>
                            <td><?= $statusBadge ?></td>
                            <td class="text-end"><?= number_format((float)($sd['symbol_score'] ?? 0), 3) ?></td>
                            <td class="text-end"><?= (int)($sd['trades_total'] ?? 0) ?></td>
                            <td class="text-end roi-positive"><?= (int)($sd['wins'] ?? 0) ?></td>
                            <td class="text-end roi-negative"><?= (int)($sd['losses'] ?? 0) ?></td>
                            <td class="text-end"><?= number_format((float)($sd['winrate'] ?? 0) * 100, 1) ?>%</td>
                            <td class="text-end <?= (float)($sd['average_roi'] ?? 0) >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format((float)($sd['average_roi'] ?? 0) * 100, 2) ?>%</td>
                            <td class="text-end"><?= number_format((float)($sd['average_mae'] ?? 0) * 100, 2) ?>%</td>
                            <td class="text-end"><?= number_format((float)($sd['average_mfe'] ?? 0) * 100, 2) ?>%</td>
                            <td class="text-end <?= (float)($sd['last_roi'] ?? 0) >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format((float)($sd['last_roi'] ?? 0) * 100, 2) ?>%</td>
                            <td class="text-end"><?= number_format((float)($sd['recent_winrate'] ?? 0) * 100, 1) ?>%</td>
                            <td class="text-end <?= (float)($sd['recent_avg_roi'] ?? 0) >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format((float)($sd['recent_avg_roi'] ?? 0) * 100, 2) ?>%</td>
                            <td><?= htmlspecialchars((string)($sd['last_trade_at'] ?? '-')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== WHITELIST / SOFT WHITELIST / BLACKLIST / WATCHLIST GROUPED ===== -->
    <?php if (!empty($siStats)): ?>
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card h-100" style="border-left: 4px solid #22c55e;">
                <div class="card-header"><h6 style="margin:0;"><span class="badge bg-success">WHITELIST</span> (<?= $siWhitelistCount ?>)</h6></div>
                <div class="card-body p-0">
                    <?php if (empty($siWhitelist)): ?>
                        <p class="text-secondary text-center py-3">Пусто</p>
                    <?php else: ?>
                        <table class="table table-dark table-sm mb-0">
                            <thead><tr><th>Символ</th><th class="text-end">Сделок</th><th class="text-end">Винрейт</th><th class="text-end">Ср. ROI</th><th class="text-end">Score</th></tr></thead>
                            <tbody>
                            <?php foreach ($siWhitelist as $sym):
                                $sd = $siStats[$sym] ?? [];
                            ?>
                            <tr>
                                <td><?= $symbolLink((string)$sym) ?></td>
                                <td class="text-end"><?= (int)($sd['trades_total'] ?? 0) ?></td>
                                <td class="text-end"><?= number_format((float)($sd['winrate'] ?? 0) * 100, 1) ?>%</td>
                                <td class="text-end <?= (float)($sd['average_roi'] ?? 0) >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format((float)($sd['average_roi'] ?? 0) * 100, 2) ?>%</td>
                                <td class="text-end"><?= number_format((float)($sd['symbol_score'] ?? 0), 3) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100" style="border-left: 4px solid #0ea5e9;">
                <div class="card-header"><h6 style="margin:0;"><span class="badge" style="background:#0ea5e9;">SOFT WHITELIST</span> (<?= $siSoftWhitelistCount ?>)</h6></div>
                <div class="card-body p-0">
                    <?php if (empty($siSoftWhitelist)): ?>
                        <p class="text-secondary text-center py-3">Пусто</p>
                    <?php else: ?>
                        <table class="table table-dark table-sm mb-0">
                            <thead><tr><th>Символ</th><th class="text-end">Сделок</th><th class="text-end">Винрейт</th><th class="text-end">Ср. ROI</th><th class="text-end">Score</th></tr></thead>
                            <tbody>
                            <?php foreach ($siSoftWhitelist as $sym):
                                $sd = $siStats[$sym] ?? [];
                            ?>
                            <tr>
                                <td><?= $symbolLink((string)$sym) ?></td>
                                <td class="text-end"><?= (int)($sd['trades_total'] ?? 0) ?></td>
                                <td class="text-end"><?= number_format((float)($sd['winrate'] ?? 0) * 100, 1) ?>%</td>
                                <td class="text-end <?= (float)($sd['average_roi'] ?? 0) >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format((float)($sd['average_roi'] ?? 0) * 100, 2) ?>%</td>
                                <td class="text-end"><?= number_format((float)($sd['symbol_score'] ?? 0), 3) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100" style="border-left: 4px solid #ef4444;">
                <div class="card-header"><h6 style="margin:0;"><span class="badge bg-danger">BLACKLIST</span> (<?= $siBlacklistCount ?>)</h6></div>
                <div class="card-body p-0">
                    <?php if (empty($siBlacklist)): ?>
                        <p class="text-secondary text-center py-3">Пусто</p>
                    <?php else: ?>
                        <table class="table table-dark table-sm mb-0">
                            <thead><tr><th>Символ</th><th class="text-end">Сделок</th><th class="text-end">Винрейт</th><th class="text-end">Ср. ROI</th><th class="text-end">Score</th></tr></thead>
                            <tbody>
                            <?php foreach ($siBlacklist as $sym):
                                $sd = $siStats[$sym] ?? [];
                            ?>
                            <tr>
                                <td><?= $symbolLink((string)$sym) ?></td>
                                <td class="text-end"><?= (int)($sd['trades_total'] ?? 0) ?></td>
                                <td class="text-end"><?= number_format((float)($sd['winrate'] ?? 0) * 100, 1) ?>%</td>
                                <td class="text-end <?= (float)($sd['average_roi'] ?? 0) >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format((float)($sd['average_roi'] ?? 0) * 100, 2) ?>%</td>
                                <td class="text-end"><?= number_format((float)($sd['symbol_score'] ?? 0), 3) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card h-100" style="border-left: 4px solid #f59e0b;">
                <div class="card-header"><h6 style="margin:0;"><span class="badge bg-warning text-dark">WATCHLIST</span> (<?= $siWatchlistCount ?>)</h6></div>
                <div class="card-body p-0">
                    <?php if (empty($siWatchlist)): ?>
                        <p class="text-secondary text-center py-3">Пусто</p>
                    <?php else: ?>
                        <table class="table table-dark table-sm mb-0">
                            <thead><tr><th>Символ</th><th class="text-end">Сделок</th><th class="text-end">Винрейт</th><th class="text-end">Ср. ROI</th><th class="text-end">Score</th></tr></thead>
                            <tbody>
                            <?php foreach ($siWatchlist as $sym):
                                $sd = $siStats[$sym] ?? [];
                            ?>
                            <tr>
                                <td><?= $symbolLink((string)$sym) ?></td>
                                <td class="text-end"><?= (int)($sd['trades_total'] ?? 0) ?></td>
                                <td class="text-end"><?= number_format((float)($sd['winrate'] ?? 0) * 100, 1) ?>%</td>
                                <td class="text-end <?= (float)($sd['average_roi'] ?? 0) >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format((float)($sd['average_roi'] ?? 0) * 100, 2) ?>%</td>
                                <td class="text-end"><?= number_format((float)($sd['symbol_score'] ?? 0), 3) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ===== D. EXIT REASONS SUMMARY + CHARTS ===== -->
    <div class="row mb-4">
        <!-- Exit Reasons Card -->
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header"><h5 style="margin:0;"><i class="bi bi-door-open me-1"></i> Причины закрытия</h5></div>
                <div class="card-body">
                    <?php if (empty($exit_reasons)): ?>
                        <p class="text-secondary text-center py-3">Нет закрытых сделок</p>
                    <?php else: ?>
                        <table class="table table-dark table-sm mb-0">
                            <thead><tr><th>Причина</th><th class="text-end">Кол-во</th><th class="text-end">%</th></tr></thead>
                            <tbody>
                            <?php
                            $reasonColors = [
                                'stop_loss' => '#ef4444',
                                'early_failure' => '#f97316',
                                'trailing_stop' => '#22c55e',
                                'break_even_stop' => '#64748b',
                                'take_profit' => '#4ade80',
                            ];
                            foreach ($exit_reasons as $reason => $count):
                                $pct = $totalClosed > 0 ? ($count / $totalClosed) * 100 : 0;
                                $color = $reasonColors[$reason] ?? '#94a3b8';
                            ?>
                            <tr>
                                <td><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= $color ?>;margin-right:6px;"></span><?= htmlspecialchars($reason) ?></td>
                                <td class="text-end"><?= $count ?></td>
                                <td class="text-end"><?= number_format($pct, 1) ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- State Distribution Chart -->
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header"><h5 style="margin:0;"><i class="bi bi-pie-chart me-1"></i> Распределение состояний</h5></div>
                <div class="card-body d-flex flex-column justify-content-center">
                    <?php if ($totalAll === 0): ?>
                        <p class="text-secondary text-center py-3">Нет данных</p>
                    <?php else: ?>
                        <?php
                        $barTotal = max($totalAll, 1);
                        $wPct = ($totalWaiting / $barTotal) * 100;
                        $aPct = ($totalActive / $barTotal) * 100;
                        $cPct = ($totalClosed / $barTotal) * 100;
                        ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1"><small>Ожидание</small><small><?= $totalWaiting ?></small></div>
                            <div class="progress" style="height:12px;background:#1e293b;">
                                <div class="progress-bar" style="width:<?= $wPct ?>%;background:#64748b;"></div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1"><small>Активные</small><small><?= $totalActive ?></small></div>
                            <div class="progress" style="height:12px;background:#1e293b;">
                                <div class="progress-bar" style="width:<?= $aPct ?>%;background:#3b82f6;"></div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1"><small>Закрытые</small><small><?= $totalClosed ?></small></div>
                            <div class="progress" style="height:12px;background:#1e293b;">
                                <div class="progress-bar" style="width:<?= $cPct ?>%;background:#22c55e;"></div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ROI Distribution Chart -->
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header"><h5 style="margin:0;"><i class="bi bi-bar-chart me-1"></i> Распределение ROI</h5></div>
                <div class="card-body d-flex flex-column justify-content-center">
                    <?php if ($totalClosed === 0): ?>
                        <p class="text-secondary text-center py-3">Нет закрытых сделок</p>
                    <?php else: ?>
                        <?php
                        $maxBucket = max(1, max($roi_buckets));
                        $bucketColors = ['#ef4444', '#f97316', '#fbbf24', '#86efac', '#22c55e', '#15803d'];
                        $i = 0;
                        ?>
                        <div class="d-flex align-items-end justify-content-around" style="height:120px;">
                            <?php foreach ($roi_buckets as $label => $count):
                                $h = $maxBucket > 0 ? max(4, ($count / $maxBucket) * 100) : 4;
                                $color = $bucketColors[$i % count($bucketColors)];
                                $i++;
                            ?>
                            <div class="text-center" style="flex:1;max-width:60px;">
                                <div class="chart-bar" style="height:<?= $h ?>px;width:80%;background:<?= $color ?>;margin:0 auto;"></div>
                                <div style="font-size:0.65rem;color:#94a3b8;margin-top:4px;"><?= htmlspecialchars($label) ?></div>
                                <div style="font-size:0.7rem;"><?= $count ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== B. ACTIVE TRADES SUMMARY ===== -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 style="margin:0;"><i class="bi bi-lightning me-1 text-warning"></i> Активные сделки (<?= $totalActive ?>)</h5>
        </div>
        <div class="card-body p-0">
            <table class="table table-dark table-hover mb-0">
                <thead><tr>
                    <th>Символ</th>
                    <th>Сторона</th>
                    <th>Цена входа</th>
                    <th>Текущая цена</th>
                    <th title="Доходность сделки от цены входа. Положительное значение — прибыль, отрицательное — убыток.">ROI %</th>
                    <th title="Максимальная просадка сделки с момента входа. Показывает, насколько сильно цена шла против позиции.">MAE</th>
                    <th title="Максимальная прибыль сделки с момента входа. Показывает лучший нереализованный результат сделки.">MFE</th>
                    <th>Тип выхода</th>
                    <th>Режим стопа</th>
                    <th>Трейлинг</th>
                    <th>Безубыток</th>
                    <th>Плечо</th>
                    <th>Время входа</th>
                    <th>Состояние</th>
                </tr></thead>
                <tbody>
                    <?php if (empty($active)): ?>
                        <tr><td colspan="14" class="text-center text-secondary py-4">Нет активных позиций</td></tr>
                    <?php else: ?>
                        <?php foreach ($active as $row): ?>
                        <tr>
                            <td><?= $symbolLink((string)($row['symbol'] ?? '')) ?></td>
                            <td><?= $sideBadge($row['side'] ?? '') ?></td>
                            <td><?= htmlspecialchars((string)($row['entry_price'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['current_price'] ?? '-')) ?></td>
                            <td><?= $fmtRoi($row['roi'] ?? 0) ?></td>
                            <td><?= htmlspecialchars((string)($row['mae'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['mfe'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['exit_mode'] ?? '-')) ?></td>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars((string)($row['stop_mode'] ?? '-')) ?></span></td>
                            <td><?= !empty($row['trailing_active']) ? '<span class="badge bg-info">ON</span>' : '-' ?></td>
                            <td><?= !empty($row['break_even_active']) ? '<span class="badge bg-success">ON</span>' : '-' ?></td>
                            <td><?= htmlspecialchars((string)($row['leverage'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['opened_at'] ?? '-')) ?></td>
                            <td><span class="badge badge-active-trade">ACTIVE</span></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ===== C. CLOSED TRADES SUMMARY ===== -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 style="margin:0;"><i class="bi bi-check-circle me-1 text-success"></i> Закрытые сделки (<?= $totalClosed ?>)</h5>
        </div>
        <div class="card-body p-0">
            <table class="table table-dark table-hover mb-0">
                <thead><tr>
                    <th>Символ</th>
                    <th>Сторона</th>
                    <th>Цена входа</th>
                    <th>Цена выхода</th>
                    <th title="Доходность сделки от цены входа. Положительное значение — прибыль, отрицательное — убыток.">ROI %</th>
                    <th title="Максимальная просадка сделки с момента входа.">MAE</th>
                    <th title="Максимальная прибыль сделки с момента входа.">MFE</th>
                    <th>Тип выхода</th>
                    <th>Режим стопа</th>
                    <th title="Показывает, по какому правилу сделка была закрыта: стоп-лосс, ранний сбой входа, трейлинг, безубыток, тейк-профит.">Причина закрытия</th>
                    <th>Плечо</th>
                    <th>Длительность</th>
                    <th>Время входа</th>
                    <th>Время выхода</th>
                    <th>Результат</th>
                </tr></thead>
                <tbody>
                    <?php if (empty($closed)): ?>
                        <tr><td colspan="15" class="text-center text-secondary py-4">Нет закрытых позиций</td></tr>
                    <?php else: ?>
                        <?php foreach ($closed as $row):
                            $roi = (float)($row['roi'] ?? 0);
                            $isWin = $roi > 0;
                        ?>
                        <tr>
                            <td><?= $symbolLink((string)($row['symbol'] ?? '')) ?></td>
                            <td><?= $sideBadge($row['side'] ?? '') ?></td>
                            <td><?= htmlspecialchars((string)($row['entry_price'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['exit_price'] ?? '-')) ?></td>
                            <td><?= $fmtRoi($roi) ?></td>
                            <td><?= htmlspecialchars((string)($row['mae'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['mfe'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['exit_mode'] ?? '-')) ?></td>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars((string)($row['stop_mode'] ?? '-')) ?></span></td>
                            <td><?= $reasonBadgeFn($row['reason'] ?? '-') ?></td>
                            <td><?= htmlspecialchars((string)($row['leverage'] ?? '-')) ?></td>
                            <td><?= ($row['duration'] ?? null) !== null ? htmlspecialchars((string)$row['duration']) . ' мин' : '-' ?></td>
                            <td><?= htmlspecialchars((string)($row['opened_at'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['closed_at'] ?? '-')) ?></td>
                            <td><span class="badge <?= $isWin ? 'badge-win' : 'badge-loss' ?>"><?= $isWin ? 'WIN' : 'LOSS' ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php
};

require __DIR__ . '/_layout.php';
