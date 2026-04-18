<?php
/**
 * Smart Brain Module - Simulator View
 *
 * Simulator UI Clarity V3: Russian labels, side badges, tooltips,
 * clickable Bybit links, close reason colors.
 */

/** @var string $smartBrainUrl */
/** @var array<int,array<string,mixed>> $waiting */
/** @var array<int,array<string,mixed>> $active */
/** @var array<int,array<string,mixed>> $closed */
/** @var array<string,mixed> $stats */
/** @var array<string,mixed> $last_run */

$pageTitle = 'Smart Brain - Симулятор';
$activeTab = 'simulator';

$extraStyles = '
    .roi-positive { color: #22c55e; }
    .roi-negative { color: #ef4444; }
    .badge-long { background: #22c55e; color: #fff; }
    .badge-short { background: #ef4444; color: #fff; }
    .reason-stop_loss { background: #ef4444; }
    .reason-early_failure { background: #f97316; }
    .reason-trailing_stop { background: #22c55e; }
    .reason-break_even_stop { background: #64748b; }
    .reason-take_profit { background: #4ade80; }
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
$reasonBadge = function($reason): string {
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

$pageContent = function() use ($waiting, $active, $closed, $stats, $last_run, $smartBrainUrl, $fmtRoi, $symbolLink, $sideBadge, $reasonBadge) {
    $totalPositions = count($waiting) + count($active) + count($closed);
?>
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-joystick me-2 text-primary"></i>Симулятор</h4>
            <p class="text-secondary mb-0">Бумажная торговля — отслеживание ROI, MAE, MFE по каждой сделке</p>
        </div>
        <div>
            <span class="badge bg-secondary fs-6"><?= count($waiting) ?> Ожидание</span>
            <span class="badge bg-warning fs-6 ms-1"><?= count($active) ?> Активные</span>
            <span class="badge bg-success fs-6 ms-1"><?= count($closed) ?> Закрытые</span>
        </div>
    </div>

    <!-- Stats Summary -->
    <?php if (!empty($stats) && ($stats['total_trades'] ?? 0) > 0): ?>
    <div class="card mb-4">
        <div class="card-header"><h5 style="margin: 0;"><i class="bi bi-bar-chart me-1"></i> Статистика</h5></div>
        <div class="card-body">
            <div class="row text-center">
                <div class="col"><strong><?= htmlspecialchars((string)($stats['total_trades'] ?? 0)) ?></strong><br><small class="text-secondary">Всего сделок</small></div>
                <div class="col"><strong><?= number_format((float)($stats['winrate'] ?? 0) * 100, 1) ?>%</strong><br><small class="text-secondary">Винрейт</small></div>
                <div class="col"><strong><?= number_format((float)($stats['average_roi'] ?? 0) * 100, 2) ?>%</strong><br><small class="text-secondary">Средний ROI</small></div>
                <div class="col"><strong><?= htmlspecialchars((string)($stats['median_mae'] ?? '-')) ?></strong><br><small class="text-secondary">Медиана MAE</small></div>
                <div class="col"><strong><?= htmlspecialchars((string)($stats['median_mfe'] ?? '-')) ?></strong><br><small class="text-secondary">Медиана MFE</small></div>
                <div class="col"><strong><?= htmlspecialchars((string)($stats['median_duration'] ?? '-')) ?> мин</strong><br><small class="text-secondary">Медиана длит.</small></div>
                <div class="col"><strong><?= number_format((float)($stats['signal_to_entry_conversion'] ?? 0) * 100, 1) ?>%</strong><br><small class="text-secondary">Конверсия</small></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Waiting Table -->
    <div class="card mb-4">
        <div class="card-header"><h5 style="margin: 0;"><i class="bi bi-hourglass-split me-1"></i> Ожидание (<?= count($waiting) ?>)</h5></div>
        <div class="card-body p-0">
            <table class="table table-dark table-hover mb-0">
                <thead><tr>
                    <th>Символ</th>
                    <th>Сторона</th>
                    <th>Зона входа</th>
                    <th>Бюджет</th>
                    <th>Плечо</th>
                    <th>Причина плеча</th>
                    <th>Стоп-лосс</th>
                    <th>Тейк-профит</th>
                </tr></thead>
                <tbody>
                    <?php if (empty($waiting)): ?>
                        <tr><td colspan="8" class="text-center text-secondary py-4">Нет ожидающих позиций</td></tr>
                    <?php else: ?>
                        <?php foreach ($waiting as $row): ?>
                        <tr>
                            <td><?= $symbolLink((string)($row['symbol'] ?? '')) ?></td>
                            <td><?= $sideBadge($row['side'] ?? '') ?></td>
                            <td><?= htmlspecialchars((string)($row['entry_zone_low'] ?? '')) ?> → <?= htmlspecialchars((string)($row['entry_zone_high'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string)($row['budget'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['leverage'] ?? '-')) ?></td>
                            <td><small class="text-secondary"><?= htmlspecialchars((string)($row['leverage_reason'] ?? '-')) ?></small></td>
                            <td><?= htmlspecialchars((string)($row['stoploss'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['takeprofit'] ?? '-')) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Active Table -->
    <div class="card mb-4">
        <div class="card-header"><h5 style="margin: 0;"><i class="bi bi-lightning me-1 text-warning"></i> Активные (<?= count($active) ?>)</h5></div>
        <div class="card-body p-0">
            <table class="table table-dark table-hover mb-0">
                <thead><tr>
                    <th>Символ</th>
                    <th>Сторона</th>
                    <th>Цена входа</th>
                    <th>Текущая цена</th>
                    <th title="Доходность сделки от цены входа. Положительное значение — прибыль, отрицательное — убыток.">ROI %</th>
                    <th title="Максимальная просадка сделки с момента входа. Показывает, насколько сильно цена шла против позиции.">Макс просадка (MAE)</th>
                    <th title="Максимальная прибыль сделки с момента входа. Показывает лучший нереализованный результат сделки.">Макс прибыль (MFE)</th>
                    <th>Режим стопа</th>
                    <th title="Показывает, по какому правилу сделка будет закрыта.">Тип выхода</th>
                    <th>Трейлинг</th>
                    <th>Безубыток</th>
                    <th>Плечо</th>
                    <th>Причина плеча</th>
                    <th>Время входа</th>
                    <th>Состояние</th>
                </tr></thead>
                <tbody>
                    <?php if (empty($active)): ?>
                        <tr><td colspan="15" class="text-center text-secondary py-4">Нет активных позиций</td></tr>
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
                            <td><span class="badge bg-secondary"><?= htmlspecialchars((string)($row['stop_mode'] ?? '-')) ?></span></td>
                            <td><?= htmlspecialchars((string)($row['exit_mode'] ?? '-')) ?></td>
                            <td><?= !empty($row['trailing_active']) ? '<span class="badge bg-info">ON</span>' : '-' ?></td>
                            <td><?= !empty($row['break_even_active']) ? '<span class="badge bg-success">ON</span>' : '-' ?></td>
                            <td><?= htmlspecialchars((string)($row['leverage'] ?? '-')) ?></td>
                            <td><small class="text-secondary"><?= htmlspecialchars((string)($row['leverage_reason'] ?? '-')) ?></small></td>
                            <td><?= htmlspecialchars((string)($row['opened_at'] ?? '-')) ?></td>
                            <td><span class="badge bg-primary">ACTIVE</span></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Closed Table -->
    <div class="card mb-4">
        <div class="card-header"><h5 style="margin: 0;"><i class="bi bi-check-circle me-1 text-success"></i> Закрытые (<?= count($closed) ?>)</h5></div>
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
                    <th title="Показывает, по какому правилу сделка была закрыта: стоп-лосс, ранний сбой входа, трейлинг, безубыток, тейк-профит.">Причина закрытия</th>
                    <th>Тип выхода</th>
                    <th>Режим стопа</th>
                    <th>Плечо</th>
                    <th>Длительность</th>
                    <th>Время входа</th>
                    <th>Время выхода</th>
                </tr></thead>
                <tbody>
                    <?php if (empty($closed)): ?>
                        <tr><td colspan="14" class="text-center text-secondary py-4">Нет закрытых позиций</td></tr>
                    <?php else: ?>
                        <?php foreach ($closed as $row): ?>
                        <tr>
                            <td><?= $symbolLink((string)($row['symbol'] ?? '')) ?></td>
                            <td><?= $sideBadge($row['side'] ?? '') ?></td>
                            <td><?= htmlspecialchars((string)($row['entry_price'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['exit_price'] ?? '-')) ?></td>
                            <td><?= $fmtRoi($row['roi'] ?? 0) ?></td>
                            <td><?= htmlspecialchars((string)($row['mae'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['mfe'] ?? '-')) ?></td>
                            <td><?= $reasonBadge($row['reason'] ?? '-') ?></td>
                            <td><?= htmlspecialchars((string)($row['exit_mode'] ?? '-')) ?></td>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars((string)($row['stop_mode'] ?? '-')) ?></span></td>
                            <td><?= htmlspecialchars((string)($row['leverage'] ?? '-')) ?></td>
                            <td><?= ($row['duration'] ?? null) !== null ? htmlspecialchars((string)$row['duration']) . ' мин' : '-' ?></td>
                            <td><?= htmlspecialchars((string)($row['opened_at'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($row['closed_at'] ?? '-')) ?></td>
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
