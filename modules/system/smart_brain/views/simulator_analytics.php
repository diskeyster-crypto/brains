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
/** @var array<string,mixed> $reversal_comparison */
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

    <!-- ===== REVERSAL V1 vs V2 COMPARISON ===== -->
    <?php
    /** @var array<string,mixed> $reversal_comparison */
    $rc = $reversal_comparison ?? [];
    $rcV1 = (array)($rc['v1_aggregate'] ?? []);
    $rcV2 = (array)($rc['v2_aggregate'] ?? []);
    $rcCtxV2 = (array)($rc['contextual_v2_aggregate'] ?? []);
    $rcCtxV3 = (array)($rc['contextual_v3_aggregate'] ?? []);
    $rcPromo = (array)($rc['promotion_criteria'] ?? []);
    $rcBottomV1 = (array)(($rc['bottom_patterns'] ?? [])['v1'] ?? []);
    $rcBottomV2 = (array)(($rc['bottom_patterns'] ?? [])['v2'] ?? []);
    $rcBottomCtxV2 = (array)(($rc['bottom_patterns'] ?? [])['contextual_v2'] ?? []);
    $rcBottomCtxV3 = (array)(($rc['bottom_patterns'] ?? [])['contextual_v3'] ?? []);
    $rcTopV1 = (array)(($rc['top_patterns'] ?? [])['v1'] ?? []);
    $rcTopV2 = (array)(($rc['top_patterns'] ?? [])['v2'] ?? []);
    $compareActive = !empty($rc['compare_mode_active']);

    // V2 stage counters (setup → confirm funnel)
    $v2sc = (array)($rc['v2_stage_counters'] ?? []);
    $v2scAvailable = !empty($v2sc['available']);
    $v2scByAlgo = (array)($v2sc['by_algorithm'] ?? []);
    $v2scAggregate = (array)($v2sc['reversal_v2_aggregate'] ?? []);
    $v2scContextDiag = (array)($v2sc['context_diagnostics'] ?? []);
    ?>
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 style="margin:0;">
                <i class="bi bi-diagram-3 me-1"></i> Сравнение V1 vs V2 Разворотных паттернов
            </h5>
            <?php if ($compareActive): ?>
                <span class="badge bg-info">🔬 Shadow Eval Active</span>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <?php if (((int)($rcV1['trades_total'] ?? 0)) === 0 && ((int)($rcV2['trades_total'] ?? 0)) === 0): ?>
                <p class="text-secondary text-center py-4">Нет данных для сравнения разворотных паттернов</p>
            <?php else: ?>

            <!-- Family Aggregate Comparison Table -->
            <div class="table-responsive">
            <table class="table table-dark table-hover table-sm mb-0">
                <thead><tr>
                    <th>Семейство</th>
                    <th class="text-end">Сделок</th>
                    <th class="text-end">Винрейт</th>
                    <th class="text-end">Ср. ROI</th>
                    <th class="text-end">Мед. ROI</th>
                    <th class="text-end">Ожидание</th>
                    <th class="text-end" title="Ложный разворот: стоп-лосс или ранний провал">Ложн. разв.</th>
                    <th class="text-end" title="Доля ложных разворотов от общего числа">Ложн. %</th>
                    <th class="text-end">Стоп %</th>
                    <th class="text-end">Ср. MAE</th>
                    <th class="text-end">Ср. MFE</th>
                    <th class="text-end">Ср. длит.</th>
                </tr></thead>
                <tbody>
                <?php foreach ([
                    'Reversal V1 (baseline)' => $rcV1,
                    'Reversal V2 (confirm)' => $rcV2,
                    'Contextual V2' => $rcCtxV2,
                    'Contextual V3' => $rcCtxV3,
                ] as $label => $data): ?>
                    <?php $t = (int)($data['trades_total'] ?? 0); ?>
                    <tr>
                        <td class="fw-bold"><?= htmlspecialchars($label) ?></td>
                        <td class="text-end"><?= $t ?></td>
                        <td class="text-end"><?= number_format((float)($data['winrate'] ?? 0) * 100, 1) ?>%</td>
                        <td class="text-end <?= (float)($data['avg_roi'] ?? 0) >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format((float)($data['avg_roi'] ?? 0) * 100, 2) ?>%</td>
                        <td class="text-end <?= (float)($data['median_roi'] ?? 0) >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format((float)($data['median_roi'] ?? 0) * 100, 2) ?>%</td>
                        <td class="text-end <?= (float)($data['expectancy'] ?? 0) >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format((float)($data['expectancy'] ?? 0) * 100, 3) ?>%</td>
                        <td class="text-end roi-negative"><?= (int)($data['false_reversal_count'] ?? 0) ?></td>
                        <td class="text-end roi-negative"><?= number_format((float)($data['false_reversal_rate'] ?? 0) * 100, 1) ?>%</td>
                        <td class="text-end"><?= number_format((float)($data['stop_hit_rate'] ?? 0) * 100, 1) ?>%</td>
                        <td class="text-end"><?= number_format((float)($data['avg_mae'] ?? 0) * 100, 2) ?>%</td>
                        <td class="text-end"><?= number_format((float)($data['avg_mfe'] ?? 0) * 100, 2) ?>%</td>
                        <td class="text-end"><?= number_format((float)($data['avg_duration'] ?? 0), 1) ?> мин</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <!-- Per-Type Breakdown: Bottom V1 vs V2, Top V1 vs V2 -->
            <div class="px-3 py-2">
                <h6 class="mb-2"><i class="bi bi-arrow-down-up me-1"></i> Детализация по типам</h6>
            </div>
            <div class="table-responsive">
            <table class="table table-dark table-hover table-sm mb-0">
                <thead><tr>
                    <th>Паттерн</th>
                    <th class="text-end">Сделок</th>
                    <th class="text-end">Винрейт</th>
                    <th class="text-end">Ср. ROI</th>
                    <th class="text-end">Мед. ROI</th>
                    <th class="text-end">Ожидание</th>
                    <th class="text-end">Ложн. %</th>
                    <th class="text-end">Стоп %</th>
                </tr></thead>
                <tbody>
                <?php foreach ([
                    'double_bottom (V1)' => $rcBottomV1,
                    'double_bottom_confirm_v2 (V2)' => $rcBottomV2,
                    'double_bottom_contextual_v2 (Ctx)' => $rcBottomCtxV2,
                    'double_bottom_contextual_v3 (V3)' => $rcBottomCtxV3,
                    'double_top (V1)' => $rcTopV1,
                    'double_top_confirm_v2 (V2)' => $rcTopV2,
                ] as $label => $data): ?>
                    <?php $t = (int)($data['trades_total'] ?? 0); ?>
                    <tr>
                        <td class="fw-bold"><?= htmlspecialchars($label) ?></td>
                        <td class="text-end"><?= $t ?></td>
                        <td class="text-end"><?= number_format((float)($data['winrate'] ?? 0) * 100, 1) ?>%</td>
                        <td class="text-end <?= (float)($data['avg_roi'] ?? 0) >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format((float)($data['avg_roi'] ?? 0) * 100, 2) ?>%</td>
                        <td class="text-end <?= (float)($data['median_roi'] ?? 0) >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format((float)($data['median_roi'] ?? 0) * 100, 2) ?>%</td>
                        <td class="text-end <?= (float)($data['expectancy'] ?? 0) >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format((float)($data['expectancy'] ?? 0) * 100, 3) ?>%</td>
                        <td class="text-end roi-negative"><?= number_format((float)($data['false_reversal_rate'] ?? 0) * 100, 1) ?>%</td>
                        <td class="text-end"><?= number_format((float)($data['stop_hit_rate'] ?? 0) * 100, 1) ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <!-- V2 Setup → Confirm Funnel -->
            <?php if ($v2scAvailable): ?>
            <div class="px-3 py-2">
                <h6 class="mb-2"><i class="bi bi-funnel me-1"></i> V2 Воронка: Сетап → Подтверждение</h6>
                <p class="text-secondary mb-2" style="font-size:0.75rem;">
                    <em>Показывает сколько сетапов V2 найдено, сколько подтверждено, и сколько отклонено на этапе подтверждения.
                    Помогает ответить: V2 лучше потому что фильтрует мусор, или потому что почти не торгует?</em>
                </p>
            </div>
            <div class="table-responsive">
            <table class="table table-dark table-hover table-sm mb-0">
                <thead><tr>
                    <th>Алгоритм</th>
                    <th class="text-end" title="Кол-во раз когда контекст отклонён (не прошёл гейт)">Контекст откл.</th>
                    <th class="text-end" title="Кол-во раз когда контекст прошёл гейт">Контекст ОК</th>
                    <th class="text-end" title="Процент контекстных гейтов пройден">Конт. %</th>
                    <th class="text-end" title="Кол-во раз когда Stage 1 нашёл валидный сетап">Сетапов</th>
                    <th class="text-end" title="Кол-во подтверждённых сигналов (Stage 2 прошёл)">Подтверж.</th>
                    <th class="text-end" title="Сетапов не прошедших подтверждение">Отклонено</th>
                    <th class="text-end" title="Доля подтверждённых от общего числа сетапов">Подтв. %</th>
                </tr></thead>
                <tbody>
                <?php
                // Per-algorithm rows
                $v2AlgoRows = [
                    'double_bottom_confirm_v2' => 'double_bottom_confirm_v2',
                    'double_top_confirm_v2' => 'double_top_confirm_v2',
                    'double_bottom_contextual_v2' => 'double_bottom_contextual_v2',
                    'double_bottom_contextual_v3' => 'double_bottom_contextual_v3',
                ];
                foreach ($v2AlgoRows as $algoKey => $algoLabel):
                    $ac = (array)($v2scByAlgo[$algoKey] ?? []);
                    $acCtxReject = (int)($ac['context_rejected_count'] ?? 0);
                    $acCtxPassed = (int)($ac['context_passed_count'] ?? 0);
                    $acCtxPassRate = (float)($ac['context_pass_rate'] ?? 0);
                    $acSetup = (int)($ac['setup_candidates_count'] ?? 0);
                    $acConfirm = (int)($ac['confirmed_signals_count'] ?? 0);
                    $acReject = (int)($ac['confirm_rejected_count'] ?? 0);
                    $acConfRate = (float)($ac['confirmation_rate'] ?? 0);
                ?>
                    <tr>
                        <td class="fw-bold"><?= htmlspecialchars($algoLabel) ?></td>
                        <td class="text-end <?= $acCtxReject > 0 ? 'roi-negative' : '' ?>"><?= $acCtxReject ?></td>
                        <td class="text-end <?= $acCtxPassed > 0 ? 'roi-positive' : '' ?>"><?= $acCtxPassed ?></td>
                        <td class="text-end"><?= number_format($acCtxPassRate * 100, 1) ?>%</td>
                        <td class="text-end"><?= $acSetup ?></td>
                        <td class="text-end roi-positive"><?= $acConfirm ?></td>
                        <td class="text-end roi-negative"><?= $acReject ?></td>
                        <td class="text-end"><?= number_format($acConfRate * 100, 1) ?>%</td>
                    </tr>
                <?php endforeach; ?>
                <?php
                // Family aggregate row
                $aggCtxReject = (int)($v2scAggregate['context_rejected_count'] ?? 0);
                $aggCtxPassed = (int)($v2scAggregate['context_passed_count'] ?? 0);
                $aggCtxPassRate = (float)($v2scAggregate['context_pass_rate'] ?? 0);
                $aggSetup = (int)($v2scAggregate['setup_candidates_count'] ?? 0);
                $aggConfirm = (int)($v2scAggregate['confirmed_signals_count'] ?? 0);
                $aggReject = (int)($v2scAggregate['confirm_rejected_count'] ?? 0);
                $aggConfRate = (float)($v2scAggregate['confirmation_rate'] ?? 0);
                ?>
                    <tr class="table-active fw-bold">
                        <td>Reversal V2/V3 (итого)</td>
                        <td class="text-end <?= $aggCtxReject > 0 ? 'roi-negative' : '' ?>"><?= $aggCtxReject ?></td>
                        <td class="text-end <?= $aggCtxPassed > 0 ? 'roi-positive' : '' ?>"><?= $aggCtxPassed ?></td>
                        <td class="text-end"><?= number_format($aggCtxPassRate * 100, 1) ?>%</td>
                        <td class="text-end"><?= $aggSetup ?></td>
                        <td class="text-end roi-positive"><?= $aggConfirm ?></td>
                        <td class="text-end roi-negative"><?= $aggReject ?></td>
                        <td class="text-end"><?= number_format($aggConfRate * 100, 1) ?>%</td>
                    </tr>
                </tbody>
            </table>
            </div>

            <!-- Context Reject Distribution (contextual patterns only) -->
            <?php
            $ctxPatterns = ['double_bottom_contextual_v2', 'double_bottom_contextual_v3'];
            $hasCtxDiag = false;
            foreach ($ctxPatterns as $cp) {
                $diag = (array)($v2scContextDiag[$cp] ?? []);
                if (!empty($diag['reject_reason_distribution'])) {
                    $hasCtxDiag = true;
                    break;
                }
            }
            ?>
            <?php if ($hasCtxDiag): ?>
            <div class="px-3 py-2">
                <h6 class="mb-2"><i class="bi bi-bar-chart me-1"></i> Распределение причин отклонения контекста</h6>
                <div class="row">
                <?php foreach ($ctxPatterns as $cp):
                    $diag = (array)($v2scContextDiag[$cp] ?? []);
                    $dist = (array)($diag['reject_reason_distribution'] ?? []);
                    if (empty($dist)) continue;
                    arsort($dist);
                ?>
                    <div class="col-md-6 mb-3">
                        <h6 class="text-light mb-1" style="font-size:0.8rem;"><?= htmlspecialchars($cp) ?></h6>
                        <?php foreach ($dist as $reason => $count): ?>
                            <div class="d-flex justify-content-between mb-1" style="font-size:0.75rem;">
                                <span class="text-secondary"><?= htmlspecialchars((string)$reason) ?></span>
                                <span class="badge bg-danger"><?= (int)$count ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <div class="px-3 py-2">
                <p class="text-secondary mb-0" style="font-size:0.75rem;">
                    <i class="bi bi-info-circle me-1"></i>
                    Данные воронки V2 (сетап → подтверждение) будут доступны после следующего запуска анализатора.
                </p>
            </div>
            <?php endif; ?>

            <!-- Promotion Criteria Verdict -->
            <?php if (!empty($rcPromo)): ?>
            <div class="px-3 py-3">
                <h6 class="mb-2"><i class="bi bi-award me-1"></i> Критерии продвижения V2</h6>
                <?php
                $verdict = (string)($rcPromo['verdict'] ?? 'unknown');
                $verdictLabel = (string)($rcPromo['verdict_label'] ?? '');
                $verdictClass = match ($verdict) {
                    'v2_promoted' => 'success',
                    'v2_promising' => 'info',
                    'insufficient_data' => 'warning',
                    default => 'secondary',
                };
                ?>
                <div class="alert alert-<?= $verdictClass ?> py-2 mb-2">
                    <strong>Вердикт:</strong> <?= htmlspecialchars($verdictLabel) ?>
                    <small class="d-block mt-1 text-secondary">
                        V1: <?= (int)($rcPromo['v1_trades'] ?? 0) ?> сделок |
                        V2: <?= (int)($rcPromo['v2_trades'] ?? 0) ?> сделок |
                        Мин. выборка: <?= (int)($rcPromo['min_sample_required'] ?? 10) ?>
                    </small>
                </div>
                <div class="row g-2">
                    <?php
                    $criteriaItems = [
                        ['label' => 'Ожидание V2 ≥ V1', 'key' => 'expectancy_pass'],
                        ['label' => 'Ложн. разв. V2 ≤ V1', 'key' => 'false_reversal_pass'],
                        ['label' => 'Стоп-хит V2 ≤ V1', 'key' => 'stop_hit_pass'],
                        ['label' => 'Кол-во сигналов ≥ 25% V1', 'key' => 'signal_count_ok'],
                        ['label' => 'Мед. ROI V2 ≥ 80% V1', 'key' => 'median_roi_pass'],
                    ];
                    foreach ($criteriaItems as $ci):
                        $pass = !empty($rcPromo[$ci['key']]);
                        $hasSample = !empty($rcPromo['sufficient_sample']);
                    ?>
                    <div class="col-md-4 col-lg-3">
                        <div class="d-flex align-items-center gap-1">
                            <?php if (!$hasSample): ?>
                                <span class="badge bg-secondary">—</span>
                            <?php elseif ($pass): ?>
                                <span class="badge bg-success">✓</span>
                            <?php else: ?>
                                <span class="badge bg-danger">✗</span>
                            <?php endif; ?>
                            <small><?= htmlspecialchars($ci['label']) ?></small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p class="text-secondary mt-2 mb-0" style="font-size:0.75rem;">
                    <em>Оценка качества, а не количества: меньшее кол-во сигналов V2 допустимо при лучшем ожидании.
                    V2 не повышается автоматически — только по данным.</em>
                </p>
            </div>
            <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>

<?php
// ── V2 Downstream Funnel (Monitor → Signal) ──
$v2DownstreamFunnel = $rc['v2_downstream_funnel'] ?? [];
$v2DfByPattern = $v2DownstreamFunnel['by_pattern'] ?? [];
$v2DfPreview = $v2DownstreamFunnel['failed_monitor_preview'] ?? [];
if (!empty($v2DfByPattern)):
?>
<div class="card mb-3">
    <div class="card-header bg-info text-white">
        <strong>V2 Downstream Funnel — Monitor → Signal</strong>
    </div>
    <div class="card-body p-2">
        <table class="table table-sm table-bordered mb-2">
            <thead class="table-light">
                <tr>
                    <th>Pattern</th>
                    <th>Candidates</th>
                    <th>Monitors</th>
                    <th>Entry Zone</th>
                    <th>Monitoring</th>
                    <th>Invalidated</th>
                    <th>Signals</th>
                    <th>EZ Rate</th>
                    <th>Conversion</th>
                    <th>Avg Zone W%</th>
                    <th>Top Reject</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($v2DfByPattern as $dfAlgo => $dfData): ?>
                <tr>
                    <td><code><?= htmlspecialchars((string)$dfAlgo) ?></code></td>
                    <td><?= (int)($dfData['candidates_count'] ?? 0) ?></td>
                    <td><?= (int)($dfData['monitors_count'] ?? 0) ?></td>
                    <td class="<?= ((int)($dfData['entry_zone_count'] ?? 0)) > 0 ? 'text-success fw-bold' : 'text-danger' ?>"><?= (int)($dfData['entry_zone_count'] ?? 0) ?></td>
                    <td><?= (int)($dfData['monitoring_count'] ?? (int)($dfData['monitoring_stalled_count'] ?? 0)) ?></td>
                    <td><?= (int)($dfData['invalidated_count'] ?? 0) ?></td>
                    <td class="<?= ((int)($dfData['signals_count'] ?? 0)) > 0 ? 'text-success fw-bold' : 'text-danger' ?>"><?= (int)($dfData['signals_count'] ?? 0) ?></td>
                    <td><?= number_format((float)($dfData['entry_zone_rate'] ?? 0) * 100, 1) ?>%</td>
                    <td><?= number_format((float)($dfData['overall_conversion_rate'] ?? 0) * 100, 1) ?>%</td>
                    <td><?= number_format((float)($dfData['avg_zone_width_pct'] ?? 0) * 100, 3) ?>%</td>
                    <td><small><?= htmlspecialchars((string)($dfData['top_reject_reason'] ?? 'none')) ?></small></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!empty($v2DfPreview)): ?>
        <details>
            <summary class="text-muted small">Failed Monitor Preview (first <?= count($v2DfPreview) ?>)</summary>
            <table class="table table-sm table-bordered mt-1 mb-0" style="font-size: 0.78rem;">
                <thead class="table-light">
                    <tr>
                        <th>Symbol</th>
                        <th>Pattern</th>
                        <th>Status</th>
                        <th>Price Pos</th>
                        <th>EZ Low</th>
                        <th>EZ High</th>
                        <th>EZ%</th>
                        <th>Widened</th>
                        <th>Confidence</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($v2DfPreview as $fp): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)($fp['symbol'] ?? '')) ?></td>
                        <td><code><?= htmlspecialchars((string)($fp['pattern_algorithm'] ?? '')) ?></code></td>
                        <td><?= htmlspecialchars((string)($fp['status'] ?? '')) ?></td>
                        <td><?= number_format((float)($fp['price_position'] ?? 0), 4) ?></td>
                        <td><?= number_format((float)($fp['entry_zone_low'] ?? 0), 8) ?></td>
                        <td><?= number_format((float)($fp['entry_zone_high'] ?? 0), 8) ?></td>
                        <td><?= number_format((float)($fp['entry_zone_percent'] ?? 0) * 100, 1) ?>%</td>
                        <td><?= !empty($fp['entry_zone_widened']) ? '✓' : '' ?></td>
                        <td><?= number_format((float)($fp['pattern_confidence'] ?? 0), 3) ?></td>
                        <td><small><?= htmlspecialchars((string)($fp['reject_reason'] ?? '')) ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </details>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

    <!-- ===== REGRESSION AUDIT: SHORT-SIDE COLLAPSE ===== -->
    <?php
    $ra = (array)($stats['regression_audit'] ?? []);
    $raHasData = !empty($ra['has_data']);
    $raMatrix = (array)($ra['matrix'] ?? []);
    $raPatternTotals = (array)($ra['pattern_totals'] ?? []);
    $raSideTotals = (array)($ra['side_totals'] ?? []);
    $raOverall = (array)($ra['overall'] ?? []);
    $raScenarios = (array)($ra['what_if_scenarios'] ?? []);
    $raSeverity = (array)($ra['severity_ranking'] ?? []);
    ?>
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 style="margin:0;">
                <i class="bi bi-bug me-1"></i> Регрессионный аудит: анализ просадки
            </h5>
            <?php if ($raHasData): ?>
                <span class="badge bg-danger">🔍 Regression Audit</span>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <?php if (!$raHasData): ?>
                <p class="text-secondary text-center py-4">Нет закрытых сделок для регрессионного аудита</p>
            <?php else: ?>

            <!-- Per-side totals -->
            <div class="px-3 py-2">
                <h6 class="mb-2"><i class="bi bi-arrow-left-right me-1"></i> Long vs Short</h6>
            </div>
            <div class="table-responsive">
            <table class="table table-dark table-hover table-sm mb-0">
                <thead><tr>
                    <th>Сторона</th>
                    <th class="text-end">Сделок</th>
                    <th class="text-end">Винрейт</th>
                    <th class="text-end">Ср. ROI</th>
                    <th class="text-end">Ложн. разв. %</th>
                    <th class="text-end">Стоп %</th>
                    <th class="text-end">Ранний провал %</th>
                    <th class="text-end">Ср. MAE</th>
                    <th class="text-end">Ср. MFE</th>
                </tr></thead>
                <tbody>
                <?php foreach (['long' => 'LONG', 'short' => 'SHORT'] as $sideKey => $sideLabel): ?>
                    <?php $sd = (array)($raSideTotals[$sideKey] ?? []); $sdt = (int)($sd['trades'] ?? 0); ?>
                    <tr>
                        <td class="fw-bold"><span class="badge <?= $sideKey === 'long' ? 'badge-long' : 'badge-short' ?>"><?= $sideLabel ?></span></td>
                        <td class="text-end"><?= $sdt ?></td>
                        <td class="text-end"><?= number_format((float)($sd['winrate'] ?? 0) * 100, 1) ?>%</td>
                        <td class="text-end <?= (float)($sd['avg_roi'] ?? 0) >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format((float)($sd['avg_roi'] ?? 0) * 100, 2) ?>%</td>
                        <td class="text-end roi-negative"><?= number_format((float)($sd['false_reversal_rate'] ?? 0) * 100, 1) ?>%</td>
                        <td class="text-end"><?= number_format((float)($sd['stop_hit_rate'] ?? 0) * 100, 1) ?>%</td>
                        <td class="text-end"><?= number_format((float)($sd['early_failure_rate'] ?? 0) * 100, 1) ?>%</td>
                        <td class="text-end"><?= number_format((float)($sd['avg_mae'] ?? 0) * 100, 2) ?>%</td>
                        <td class="text-end"><?= number_format((float)($sd['avg_mfe'] ?? 0) * 100, 2) ?>%</td>
                    </tr>
                <?php endforeach; ?>
                    <?php $ov = $raOverall; $ovt = (int)($ov['trades'] ?? 0); ?>
                    <tr class="table-active fw-bold">
                        <td>ИТОГО</td>
                        <td class="text-end"><?= $ovt ?></td>
                        <td class="text-end"><?= number_format((float)($ov['winrate'] ?? 0) * 100, 1) ?>%</td>
                        <td class="text-end <?= (float)($ov['avg_roi'] ?? 0) >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format((float)($ov['avg_roi'] ?? 0) * 100, 2) ?>%</td>
                        <td class="text-end roi-negative"><?= number_format((float)($ov['false_reversal_rate'] ?? 0) * 100, 1) ?>%</td>
                        <td class="text-end"><?= number_format((float)($ov['stop_hit_rate'] ?? 0) * 100, 1) ?>%</td>
                        <td class="text-end"><?= number_format((float)($ov['early_failure_rate'] ?? 0) * 100, 1) ?>%</td>
                        <td class="text-end"><?= number_format((float)($ov['avg_mae'] ?? 0) * 100, 2) ?>%</td>
                        <td class="text-end"><?= number_format((float)($ov['avg_mfe'] ?? 0) * 100, 2) ?>%</td>
                    </tr>
                </tbody>
            </table>
            </div>

            <!-- Per-pattern × per-side matrix -->
            <div class="px-3 py-2">
                <h6 class="mb-2"><i class="bi bi-grid-3x3 me-1"></i> Матрица: Паттерн × Сторона</h6>
                <p class="text-secondary mb-2" style="font-size:0.75rem;">
                    <em>Помогает локализовать просадку: какой именно паттерн на какой стороне теряет больше всего.</em>
                </p>
            </div>
            <div class="table-responsive">
            <table class="table table-dark table-hover table-sm mb-0">
                <thead><tr>
                    <th>Паттерн / Сторона</th>
                    <th class="text-end">Сделок</th>
                    <th class="text-end">Винрейт</th>
                    <th class="text-end">Ср. ROI</th>
                    <th class="text-end">Ложн. разв. %</th>
                    <th class="text-end">Стоп %</th>
                    <th class="text-end">Ранний провал %</th>
                </tr></thead>
                <tbody>
                <?php
                $matrixPatterns = ['double_bottom', 'double_top', 'pullback_trend_continue', 'double_bottom_confirm_v2', 'double_top_confirm_v2', 'double_bottom_contextual_v2', 'double_bottom_contextual_v3'];
                $matrixSides = ['long', 'short'];
                foreach ($matrixPatterns as $mp):
                    foreach ($matrixSides as $ms):
                        $cellKey = $mp . '/' . $ms;
                        $cell = (array)($raMatrix[$cellKey] ?? []);
                        $ct = (int)($cell['trades'] ?? 0);
                        if ($ct === 0) { continue; }
                        $cellWr = (float)($cell['winrate'] ?? 0);
                        $cellRoi = (float)($cell['avg_roi'] ?? 0);
                ?>
                    <tr>
                        <td class="fw-bold"><?= htmlspecialchars($mp) ?> <span class="badge <?= $ms === 'long' ? 'badge-long' : 'badge-short' ?>" style="font-size:0.65rem;"><?= strtoupper($ms) ?></span></td>
                        <td class="text-end"><?= $ct ?></td>
                        <td class="text-end <?= $cellWr < 0.3 ? 'roi-negative' : '' ?>"><?= number_format($cellWr * 100, 1) ?>%</td>
                        <td class="text-end <?= $cellRoi >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format($cellRoi * 100, 2) ?>%</td>
                        <td class="text-end roi-negative"><?= number_format((float)($cell['false_reversal_rate'] ?? 0) * 100, 1) ?>%</td>
                        <td class="text-end"><?= number_format((float)($cell['stop_hit_rate'] ?? 0) * 100, 1) ?>%</td>
                        <td class="text-end"><?= number_format((float)($cell['early_failure_rate'] ?? 0) * 100, 1) ?>%</td>
                    </tr>
                <?php endforeach; endforeach; ?>
                </tbody>
            </table>
            </div>

            <!-- What-if exclusion scenarios -->
            <?php if (!empty($raScenarios)): ?>
            <div class="px-3 py-2">
                <h6 class="mb-2"><i class="bi bi-toggles me-1"></i> Сценарии «Что если» (отключение паттернов)</h6>
                <p class="text-secondary mb-2" style="font-size:0.75rem;">
                    <em>Показывает как бы изменились результаты при отключении подозрительных паттернов.
                    Помогает найти минимальный откат для восстановления качества.</em>
                </p>
            </div>
            <div class="table-responsive">
            <table class="table table-dark table-hover table-sm mb-0">
                <thead><tr>
                    <th>Сценарий</th>
                    <th class="text-end">Сделок</th>
                    <th class="text-end">Винрейт</th>
                    <th class="text-end">Ср. ROI</th>
                    <th class="text-end">Ложн. разв. %</th>
                    <th class="text-end">Стоп %</th>
                </tr></thead>
                <tbody>
                    <!-- Current baseline -->
                    <tr class="table-active">
                        <td class="fw-bold">Текущий базовый (все паттерны)</td>
                        <td class="text-end"><?= (int)($raOverall['trades'] ?? 0) ?></td>
                        <td class="text-end"><?= number_format((float)($raOverall['winrate'] ?? 0) * 100, 1) ?>%</td>
                        <td class="text-end <?= (float)($raOverall['avg_roi'] ?? 0) >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format((float)($raOverall['avg_roi'] ?? 0) * 100, 2) ?>%</td>
                        <td class="text-end roi-negative"><?= number_format((float)($raOverall['false_reversal_rate'] ?? 0) * 100, 1) ?>%</td>
                        <td class="text-end"><?= number_format((float)($raOverall['stop_hit_rate'] ?? 0) * 100, 1) ?>%</td>
                    </tr>
                <?php foreach ($raScenarios as $scenKey => $scen):
                    $scen = (array)$scen;
                    $st = (int)($scen['trades'] ?? 0);
                    $scenLabel = (string)($scen['label'] ?? $scen['description'] ?? $scenKey);
                    $scenWr = (float)($scen['winrate'] ?? 0);
                    $scenRoi = (float)($scen['avg_roi'] ?? 0);
                    $baseWr = (float)($raOverall['winrate'] ?? 0);
                    $wrImproved = $scenWr > $baseWr;
                ?>
                    <tr>
                        <td class="fw-bold"><?= htmlspecialchars($scenLabel) ?></td>
                        <td class="text-end"><?= $st ?></td>
                        <td class="text-end <?= $wrImproved ? 'roi-positive' : '' ?>"><?= number_format($scenWr * 100, 1) ?>%<?= $wrImproved ? ' ↑' : '' ?></td>
                        <td class="text-end <?= $scenRoi >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format($scenRoi * 100, 2) ?>%</td>
                        <td class="text-end roi-negative"><?= number_format((float)($scen['false_reversal_rate'] ?? 0) * 100, 1) ?>%</td>
                        <td class="text-end"><?= number_format((float)($scen['stop_hit_rate'] ?? 0) * 100, 1) ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>

            <!-- Severity ranking -->
            <?php if (!empty($raSeverity)): ?>
            <div class="px-3 py-2">
                <h6 class="mb-2"><i class="bi bi-exclamation-triangle me-1"></i> Рейтинг урона (наиболее вредные ячейки)</h6>
                <p class="text-secondary mb-2" style="font-size:0.75rem;">
                    <em>Ранжировано по степени ущерба общим результатам. Первая строка — главный подозреваемый в просадке.</em>
                </p>
            </div>
            <div class="table-responsive">
            <table class="table table-dark table-hover table-sm mb-0">
                <thead><tr>
                    <th>#</th>
                    <th>Паттерн / Сторона</th>
                    <th class="text-end">Сделок</th>
                    <th class="text-end">Винрейт</th>
                    <th class="text-end">Ср. ROI</th>
                    <th class="text-end">Ложн. разв. %</th>
                    <th class="text-end" title="Чем выше — тем больше вклад в общую просадку">Балл урона</th>
                </tr></thead>
                <tbody>
                <?php foreach ($raSeverity as $idx => $sev):
                    $sev = (array)$sev;
                    $sevCell = (string)($sev['cell'] ?? '');
                    $sevParts = explode('/', $sevCell);
                    $sevPattern = $sevParts[0] ?? '';
                    $sevSide = $sevParts[1] ?? '';
                    $sevDamage = (float)($sev['damage_score'] ?? 0);
                ?>
                    <tr <?= $idx === 0 ? 'class="table-danger"' : '' ?>>
                        <td><?= $idx + 1 ?></td>
                        <td class="fw-bold"><?= htmlspecialchars($sevPattern) ?> <span class="badge <?= $sevSide === 'long' ? 'badge-long' : 'badge-short' ?>" style="font-size:0.65rem;"><?= strtoupper($sevSide) ?></span></td>
                        <td class="text-end"><?= (int)($sev['trades'] ?? 0) ?></td>
                        <td class="text-end <?= (float)($sev['winrate'] ?? 0) < 0.3 ? 'roi-negative' : '' ?>"><?= number_format((float)($sev['winrate'] ?? 0) * 100, 1) ?>%</td>
                        <td class="text-end <?= (float)($sev['avg_roi'] ?? 0) >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format((float)($sev['avg_roi'] ?? 0) * 100, 2) ?>%</td>
                        <td class="text-end roi-negative"><?= number_format((float)($sev['false_reversal_rate'] ?? 0) * 100, 1) ?>%</td>
                        <td class="text-end"><strong><?= number_format($sevDamage, 2) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>

            <div class="px-3 py-2">
                <p class="text-secondary mb-0" style="font-size:0.75rem;">
                    <em><i class="bi bi-info-circle me-1"></i>
                    Регрессионный аудит — только объяснительный инструмент. Не отключайте паттерны автоматически.
                    Используйте данные для оценки: просадка от плохих паттернов или от рыночного режима.</em>
                </p>
            </div>

            <?php endif; ?>
        </div>
    </div>

    <!-- ===== FOCUSED AUDIT: double_bottom / long ===== -->
    <?php
    $dbla = (array)($stats['double_bottom_long_audit'] ?? []);
    $dblaHasData = !empty($dbla['has_data']);
    $dblaLowSample = !empty($dbla['low_sample']);
    $dblaCore = (array)($dbla['core_stats'] ?? []);
    $dblaExit = (array)($dbla['exit_breakdown'] ?? []);
    $dblaExitRates = (array)($dbla['exit_rates'] ?? []);
    $dblaEntry = (array)($dbla['entry_quality'] ?? []);
    $dblaSymbols = (array)($dbla['symbol_performance'] ?? []);
    $dblaBaseline = (array)($dbla['baseline_comparison'] ?? []);
    $dblaWhatIf = (array)($dbla['what_if_scenarios'] ?? []);
    $dblaHypotheses = (array)($dbla['hypotheses'] ?? []);
    $dblaMitigation = (array)($dbla['mitigation'] ?? []);
    $dblaSeverity = (string)($dblaMitigation['severity'] ?? 'info');
    ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-<?= $dblaSeverity === 'critical' ? 'danger text-white' : ($dblaSeverity === 'warning' ? 'warning' : 'info text-white') ?>">
                    <h5 style="margin:0;">
                        <i class="bi bi-bullseye me-1"></i>
                        Фокус-аудит: double_bottom / long
                        <?php if ($dblaLowSample): ?>
                            <span class="badge bg-secondary ms-2" style="font-size:0.65rem;">⚠ мало данных</span>
                        <?php endif; ?>
                        <?php if ($dblaSeverity === 'critical'): ?>
                            <span class="badge bg-dark ms-2" style="font-size:0.65rem;">CRITICAL</span>
                        <?php elseif ($dblaSeverity === 'warning'): ?>
                            <span class="badge bg-dark ms-2" style="font-size:0.65rem;">WARNING</span>
                        <?php endif; ?>
                    </h5>
                </div>
                <div class="card-body">
                <?php if (!$dblaHasData): ?>
                    <p class="text-muted">Нет данных для double_bottom/long.</p>
                <?php else: ?>

                    <!-- Mitigation Recommendation -->
                    <?php if (!empty($dblaMitigation['actions'])): ?>
                    <div class="alert alert-<?= $dblaSeverity === 'critical' ? 'danger' : ($dblaSeverity === 'warning' ? 'warning' : 'info') ?> mb-3">
                        <strong><i class="bi bi-shield-exclamation me-1"></i> Рекомендация:</strong>
                        <ul class="mb-0 mt-1">
                        <?php foreach ((array)($dblaMitigation['actions'] ?? []) as $action): ?>
                            <li><?= htmlspecialchars((string)$action) ?></li>
                        <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <!-- Core Metrics -->
                    <div class="row mb-3">
                        <?php
                        $dblaMetrics = [
                            ['label' => 'Сделки', 'value' => (int)($dblaCore['trades'] ?? 0), 'fmt' => 'int'],
                            ['label' => 'Winrate', 'value' => (float)($dblaCore['winrate'] ?? 0) * 100, 'fmt' => 'pct', 'warn' => (float)($dblaCore['winrate'] ?? 0) < 0.35],
                            ['label' => 'Avg ROI', 'value' => (float)($dblaCore['avg_roi'] ?? 0) * 100, 'fmt' => 'roi'],
                            ['label' => 'Median ROI', 'value' => (float)($dblaCore['median_roi'] ?? 0) * 100, 'fmt' => 'roi'],
                            ['label' => 'False Rev %', 'value' => (float)($dblaCore['false_reversal_rate'] ?? 0) * 100, 'fmt' => 'pct', 'warn' => (float)($dblaCore['false_reversal_rate'] ?? 0) > 0.4],
                            ['label' => 'Stop Hit %', 'value' => (float)($dblaCore['stop_hit_rate'] ?? 0) * 100, 'fmt' => 'pct', 'warn' => (float)($dblaCore['stop_hit_rate'] ?? 0) > 0.3],
                            ['label' => 'Avg MAE', 'value' => (float)($dblaCore['avg_mae'] ?? 0) * 100, 'fmt' => 'pct2'],
                            ['label' => 'Avg MFE', 'value' => (float)($dblaCore['avg_mfe'] ?? 0) * 100, 'fmt' => 'pct2'],
                            ['label' => 'Avg Duration', 'value' => (float)($dblaCore['avg_duration'] ?? 0), 'fmt' => 'min'],
                        ];
                        foreach ($dblaMetrics as $m): ?>
                            <div class="col-auto mb-2">
                                <div class="stat-card border rounded p-2" style="min-width:100px;">
                                    <div class="stat-value <?= !empty($m['warn']) ? 'text-danger' : '' ?>" style="font-size:1.1rem;">
                                    <?php
                                    if ($m['fmt'] === 'int') echo (int)$m['value'];
                                    elseif ($m['fmt'] === 'roi') echo '<span class="' . ($m['value'] >= 0 ? 'roi-positive' : 'roi-negative') . '">' . number_format($m['value'], 2) . '%</span>';
                                    elseif ($m['fmt'] === 'pct') echo number_format($m['value'], 1) . '%';
                                    elseif ($m['fmt'] === 'pct2') echo number_format($m['value'], 3) . '%';
                                    elseif ($m['fmt'] === 'min') echo number_format($m['value'], 0) . ' мин';
                                    ?>
                                    </div>
                                    <div class="stat-label"><?= htmlspecialchars($m['label']) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Baseline Comparison -->
                    <h6 class="mt-3"><i class="bi bi-arrow-left-right me-1"></i> Сравнение с базой</h6>
                    <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered align-middle" style="font-size:0.8rem;">
                    <thead class="table-light">
                        <tr>
                            <th>Сегмент</th><th class="text-end">Сделки</th><th class="text-end">Winrate</th>
                            <th class="text-end">Avg ROI</th><th class="text-end">False Rev %</th><th class="text-end">Stop Hit %</th>
                            <th class="text-end">Avg MAE</th><th class="text-end">Avg MFE</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $blLabels = [
                        'double_bottom_long' => 'double_bottom / long',
                        'double_bottom_short' => 'double_bottom / short',
                        'double_bottom_all' => 'double_bottom (все)',
                        'overall' => 'ИТОГО (все паттерны)',
                    ];
                    foreach ($blLabels as $blKey => $blLabel):
                        $bl = (array)($dblaBaseline[$blKey] ?? []);
                        $blTrades = (int)($bl['trades'] ?? 0);
                        if ($blTrades === 0) continue;
                        $blWr = (float)($bl['winrate'] ?? 0);
                        $blRoi = (float)($bl['avg_roi'] ?? 0);
                        $blFr = (float)($bl['false_reversal_rate'] ?? 0);
                        $blSr = (float)($bl['stop_hit_rate'] ?? 0);
                        $isFocused = ($blKey === 'double_bottom_long');
                    ?>
                        <tr class="<?= $isFocused ? 'table-warning' : '' ?>">
                            <td class="<?= $isFocused ? 'fw-bold' : '' ?>"><?= htmlspecialchars($blLabel) ?></td>
                            <td class="text-end"><?= $blTrades ?></td>
                            <td class="text-end <?= $blWr < 0.3 ? 'roi-negative' : '' ?>"><?= number_format($blWr * 100, 1) ?>%</td>
                            <td class="text-end <?= $blRoi >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format($blRoi * 100, 2) ?>%</td>
                            <td class="text-end"><?= number_format($blFr * 100, 1) ?>%</td>
                            <td class="text-end"><?= number_format($blSr * 100, 1) ?>%</td>
                            <td class="text-end"><?= number_format((float)($bl['avg_mae'] ?? 0) * 100, 3) ?>%</td>
                            <td class="text-end"><?= number_format((float)($bl['avg_mfe'] ?? 0) * 100, 3) ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    </table>
                    </div>

                    <div class="row mb-3">
                        <!-- Exit Breakdown -->
                        <div class="col-md-6">
                            <h6><i class="bi bi-door-open me-1"></i> Распределение выходов</h6>
                            <table class="table table-sm table-bordered" style="font-size:0.8rem;">
                            <tbody>
                            <?php
                            $exitLabels = [
                                'stop_loss' => ['Стоп-лосс', 'reason-stop_loss'],
                                'early_failure' => ['Ранний выход', 'reason-early_failure'],
                                'trailing_stop' => ['Трейлинг', 'reason-trailing_stop'],
                                'break_even_stop' => ['Безубыток', 'reason-break_even_stop'],
                                'take_profit' => ['Тейк-профит', 'reason-take_profit'],
                                'other' => ['Прочие', ''],
                            ];
                            $dblaTargetTotal = (int)($dblaCore['trades'] ?? 0);
                            foreach ($exitLabels as $exKey => $exInfo):
                                $exCount = (int)($dblaExit[$exKey] ?? 0);
                                $exPct = $dblaTargetTotal > 0 ? ($exCount / $dblaTargetTotal) * 100 : 0;
                            ?>
                                <tr>
                                    <td><span class="badge <?= $exInfo[1] ?>" style="font-size:0.7rem;"><?= $exInfo[0] ?></span></td>
                                    <td class="text-end"><?= $exCount ?></td>
                                    <td class="text-end"><?= number_format($exPct, 1) ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            </table>
                            <p class="text-muted" style="font-size:0.72rem;">
                                Trailing activation: <?= number_format((float)($dblaExitRates['trailing_activation_rate'] ?? 0) * 100, 1) ?>% |
                                BE activation: <?= number_format((float)($dblaExitRates['break_even_activation_rate'] ?? 0) * 100, 1) ?>%
                            </p>
                        </div>

                        <!-- Entry Quality -->
                        <div class="col-md-6">
                            <h6><i class="bi bi-crosshair me-1"></i> Качество входа</h6>
                            <table class="table table-sm table-bordered" style="font-size:0.8rem;">
                            <tbody>
                                <tr>
                                    <td>Немедленный провал (≤5мин)</td>
                                    <td class="text-end <?= (float)($dblaEntry['immediate_failure_rate'] ?? 0) > 0.15 ? 'text-danger fw-bold' : '' ?>">
                                        <?= number_format((float)($dblaEntry['immediate_failure_rate'] ?? 0) * 100, 1) ?>%
                                    </td>
                                </tr>
                                <tr>
                                    <td>Быстрый стоп (≤15мин, убыток)</td>
                                    <td class="text-end <?= (float)($dblaEntry['quick_stop_rate'] ?? 0) > 0.3 ? 'text-danger fw-bold' : '' ?>">
                                        <?= number_format((float)($dblaEntry['quick_stop_rate'] ?? 0) * 100, 1) ?>%
                                    </td>
                                </tr>
                                <tr>
                                    <td>Никогда не в плюсе (MFE&lt;0.5%)</td>
                                    <td class="text-end <?= (float)($dblaEntry['never_positive_rate'] ?? 0) > 0.4 ? 'text-danger fw-bold' : '' ?>">
                                        <?= number_format((float)($dblaEntry['never_positive_rate'] ?? 0) * 100, 1) ?>%
                                    </td>
                                </tr>
                                <tr>
                                    <td>Avg MAE проигравших</td>
                                    <td class="text-end"><?= number_format((float)($dblaEntry['avg_loser_mae'] ?? 0) * 100, 3) ?>%</td>
                                </tr>
                                <tr>
                                    <td>Avg MFE проигравших</td>
                                    <td class="text-end"><?= number_format((float)($dblaEntry['avg_loser_mfe'] ?? 0) * 100, 3) ?>%</td>
                                </tr>
                                <tr>
                                    <td>Avg MFE победителей</td>
                                    <td class="text-end roi-positive"><?= number_format((float)($dblaEntry['avg_winner_mfe'] ?? 0) * 100, 3) ?>%</td>
                                </tr>
                            </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Per-Symbol Breakdown -->
                    <?php if (!empty($dblaSymbols)): ?>
                    <h6><i class="bi bi-list-ol me-1"></i> По символам (худшие первые)</h6>
                    <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered align-middle" style="font-size:0.8rem;">
                    <thead class="table-light">
                        <tr><th>Символ</th><th class="text-end">Сделки</th><th class="text-end">Winrate</th><th class="text-end">Avg ROI</th><th class="text-end">Avg MAE</th><th class="text-end">Avg MFE</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_slice($dblaSymbols, 0, 10) as $symIdx => $symRow):
                        $symLowSample = !empty($symRow['low_sample']);
                    ?>
                        <tr class="<?= $symIdx === 0 ? 'table-danger' : '' ?>">
                            <td><?= htmlspecialchars((string)($symRow['symbol'] ?? '')) ?></td>
                            <td class="text-end"><?= (int)($symRow['trades'] ?? 0) ?></td>
                            <td class="text-end <?= (float)($symRow['winrate'] ?? 0) < 0.3 ? 'roi-negative' : '' ?>">
                                <?= number_format((float)($symRow['winrate'] ?? 0) * 100, 1) ?>%
                            </td>
                            <td class="text-end <?= (float)($symRow['avg_roi'] ?? 0) >= 0 ? 'roi-positive' : 'roi-negative' ?>">
                                <?= number_format((float)($symRow['avg_roi'] ?? 0) * 100, 2) ?>%
                            </td>
                            <td class="text-end"><?= number_format((float)($symRow['avg_mae'] ?? 0) * 100, 3) ?>%</td>
                            <td class="text-end"><?= number_format((float)($symRow['avg_mfe'] ?? 0) * 100, 3) ?>%</td>
                            <td><?= $symLowSample ? '<span class="badge bg-secondary" style="font-size:0.6rem;">мало</span>' : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    </table>
                    </div>
                    <?php endif; ?>

                    <!-- What-if Scenarios -->
                    <?php if (!empty($dblaWhatIf)): ?>
                    <h6><i class="bi bi-toggles me-1"></i> Что-если (double_bottom/long)</h6>
                    <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered align-middle" style="font-size:0.8rem;">
                    <thead class="table-light">
                        <tr><th>Сценарий</th><th class="text-end">Сделки</th><th class="text-end">Winrate</th><th class="text-end">Avg ROI</th><th class="text-end">False Rev %</th></tr>
                    </thead>
                    <tbody>
                    <?php
                    // Current baseline first
                    $overallBl = (array)($dblaBaseline['overall'] ?? []);
                    $overallBlWr = (float)($overallBl['winrate'] ?? 0);
                    ?>
                    <tr class="table-light">
                        <td><em>Текущее состояние (все паттерны)</em></td>
                        <td class="text-end"><?= (int)($overallBl['trades'] ?? 0) ?></td>
                        <td class="text-end"><?= number_format($overallBlWr * 100, 1) ?>%</td>
                        <td class="text-end <?= (float)($overallBl['avg_roi'] ?? 0) >= 0 ? 'roi-positive' : 'roi-negative' ?>"><?= number_format((float)($overallBl['avg_roi'] ?? 0) * 100, 2) ?>%</td>
                        <td class="text-end"><?= number_format((float)($overallBl['false_reversal_rate'] ?? 0) * 100, 1) ?>%</td>
                    </tr>
                    <?php foreach ($dblaWhatIf as $scKey => $sc):
                        $scWr = (float)($sc['winrate'] ?? 0);
                        $scWrImproved = $scWr > $overallBlWr;
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string)($sc['description'] ?? $scKey)) ?></td>
                        <td class="text-end"><?= (int)($sc['trades'] ?? 0) ?></td>
                        <td class="text-end <?= $scWrImproved ? 'roi-positive fw-bold' : '' ?>">
                            <?= number_format($scWr * 100, 1) ?>%
                            <?= $scWrImproved ? ' ↑' : '' ?>
                        </td>
                        <td class="text-end <?= (float)($sc['avg_roi'] ?? 0) >= 0 ? 'roi-positive' : 'roi-negative' ?>">
                            <?= number_format((float)($sc['avg_roi'] ?? 0) * 100, 2) ?>%
                        </td>
                        <td class="text-end"><?= number_format((float)($sc['false_reversal_rate'] ?? 0) * 100, 1) ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    </table>
                    </div>
                    <?php endif; ?>

                    <!-- Ranked Root-Cause Hypotheses -->
                    <?php if (!empty($dblaHypotheses)): ?>
                    <h6><i class="bi bi-diagram-3 me-1"></i> Ранжированные гипотезы причин</h6>
                    <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered align-middle" style="font-size:0.8rem;">
                    <thead class="table-light">
                        <tr><th style="width:30px;">#</th><th>Гипотеза</th><th>Обоснование</th><th class="text-end">Вес</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($dblaHypotheses as $hIdx => $hyp): ?>
                        <tr class="<?= $hIdx === 0 ? 'table-danger' : ($hIdx === 1 ? 'table-warning' : '') ?>">
                            <td class="fw-bold"><?= (int)($hyp['rank'] ?? $hIdx + 1) ?></td>
                            <td><?= htmlspecialchars((string)($hyp['label'] ?? '')) ?></td>
                            <td class="text-muted" style="font-size:0.72rem;"><?= htmlspecialchars((string)($hyp['evidence'] ?? '')) ?></td>
                            <td class="text-end"><strong><?= number_format((float)($hyp['score'] ?? 0), 0) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    </table>
                    </div>
                    <?php endif; ?>

                    <div class="px-3 py-2">
                        <p class="text-secondary mb-0" style="font-size:0.75rem;">
                            <em><i class="bi bi-info-circle me-1"></i>
                            Фокус-аудит double_bottom/long — только наблюдение и рекомендации. Не отключайте паттерны автоматически.
                            Сначала локализуйте причину, затем тестируйте минимальное изменение.</em>
                        </p>
                    </div>

                <?php endif; ?>
                </div>
            </div>
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
