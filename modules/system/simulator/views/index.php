<?php
/**
 * Parser6 Simulator Dashboard
 *
 * Displays trades, statistics, and provides run/reset controls.
 */

$pageTitle = 'Панель управления симулятором';
$state = $state ?? [];
$lastRun = $last_run ?? [];
$summary = $summary ?? [];
$statsGlobal = $stats_global ?? [];
$activeTrades = $active_trades ?? [];
$closedTrades = $closed_trades ?? [];
$rejectedTrades = $rejected_trades ?? [];

// P3-FIX: $currentMode должен быть определён ДО первого использования (export/buttons/JS).
$modeRaw = $mode ?? ($_GET['mode'] ?? 'clean');
$currentMode = is_string($modeRaw) ? strtolower(trim($modeRaw)) : 'clean';
if (!in_array($currentMode, ['raw', 'clean', 'compare'], true)) {
    $currentMode = 'clean';
}

$comparisonData = $comparison ?? null;
$comparisonEnabled = $comparisonData !== null || (($lastRun['comparison_enabled'] ?? false) === true);


// P3-FIX: comparison данные (для режима compare).

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* Dark Theme - matching ui.css design system */
        :root {
            --primary: #3b82f6;
            --card-bg: #1e293b;
            --border-color: #334155;
            --bg-main: #0f172a;
            --bg-code: #0d1117;
            --chart-bg: #1a1f2e;
            --text-main: #e2e8f0;
            --text-muted: #94a3b8;
            --success: #3fb950;
            --danger: #f85149;
            --warning: #f0883e;
            --info: #58a6ff;
        }
        body { background: var(--bg-main); color: var(--text-main); }
        .text-muted { color: var(--text-muted) !important; }
        
        /* Cards */
        .card { 
            background: var(--card-bg); 
            border: 1px solid var(--border-color); 
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        .card-header { 
            background: rgba(0,0,0,0.2); 
            border-bottom: 1px solid var(--border-color); 
            color: var(--text-main);
        }
        .card-body { color: var(--text-main); }
        
        /* Stat Cards */
        .stat-card { 
            transition: transform 0.2s, box-shadow 0.2s; 
            background: var(--card-bg);
            border: 1px solid var(--border-color);
        }
        .stat-card:hover { 
            transform: translateY(-4px); 
            box-shadow: 0 8px 30px rgba(0,0,0,0.4);
        }
        
        /* Trade Cards */
        .trade-card { 
            border-left: 4px solid #6c757d; 
            background: var(--card-bg);
        }
        .trade-card.win { border-left-color: var(--success); }
        .trade-card.loss { border-left-color: var(--danger); }
        .trade-card.active { border-left-color: var(--info); }
        
        /* Badges */
        .badge-long { background: var(--success); }
        .badge-short { background: var(--danger); }
        
        /* ROI Colors */
        .roi-positive { color: var(--success); font-weight: 600; }
        .roi-negative { color: var(--danger); font-weight: 600; }
        
        /* Status Indicators */
        .status-indicator { width: 12px; height: 12px; border-radius: 50%; display: inline-block; }
        .status-ok { background: var(--success); }
        .status-error { background: var(--danger); }
        .status-disabled { background: #6c757d; }
        
        /* Modal */
        #tradeDetailModal .modal-dialog { max-width: 800px; }
        #tradeDetailModal .modal-content {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-main);
        }
        #tradeDetailModal .modal-header {
            border-bottom: 1px solid var(--border-color);
        }
        #tradeDetailModal .modal-footer {
            border-top: 1px solid var(--border-color);
        }
        .btn-close { filter: invert(1); }
        
        /* Chart Container */
        .chart-container { height: 300px; background: var(--chart-bg); border-radius: 8px; border: 1px solid var(--border-color); }
        
        /* Tables - Dark Theme */
        .table { 
            color: var(--text-main); 
            background: transparent;
            --bs-table-bg: transparent;
            --bs-table-color: var(--text-main);
        }
        .table th { 
            background: rgba(0,0,0,0.3); 
            color: var(--text-muted); 
            border-color: var(--border-color);
            font-weight: 600;
        }
        .table td { 
            border-color: var(--border-color); 
            color: var(--text-main);
            background: transparent;
        }
        .table-hover tbody tr:hover { background: rgba(255,255,255,0.05); }
        .table-sm th, .table-sm td { padding: 0.4rem 0.5rem; }
        
        /* Compare Mode Table */
        .table-striped > tbody > tr:nth-of-type(odd) > * {
            background: rgba(255,255,255,0.02);
            --bs-table-accent-bg: rgba(255,255,255,0.02);
        }
        
        /* Alerts */
        .alert-success { 
            background: rgba(63, 185, 80, 0.15); 
            border: 1px solid rgba(63, 185, 80, 0.3); 
            color: var(--success); 
        }
        .alert-warning { 
            background: rgba(240, 136, 62, 0.15); 
            border: 1px solid rgba(240, 136, 62, 0.3); 
            color: var(--warning); 
        }
        .alert-danger { 
            background: rgba(248, 81, 73, 0.15); 
            border: 1px solid rgba(248, 81, 73, 0.3); 
            color: var(--danger); 
        }
        .alert-info { 
            background: rgba(88, 166, 255, 0.15); 
            border: 1px solid rgba(88, 166, 255, 0.3); 
            color: var(--info); 
        }
        
        /* Buttons */
        .btn-primary { 
            background: linear-gradient(180deg, #238636, #2ea043); 
            border: none; 
            color: white;
        }
        .btn-primary:hover { 
            background: linear-gradient(180deg, #2ea043, #3fb950); 
        }
        .btn-outline-secondary { 
            border-color: var(--border-color); 
            color: var(--text-main); 
        }
        .btn-outline-secondary:hover { 
            background: rgba(255,255,255,0.1); 
            border-color: var(--text-muted);
            color: var(--text-main);
        }
        .btn-outline-danger { 
            border-color: var(--danger); 
            color: var(--danger); 
        }
        .btn-outline-danger:hover { 
            background: var(--danger); 
            color: white; 
        }
        .btn-outline-success { 
            border-color: var(--success); 
            color: var(--success); 
        }
        .btn-outline-success:hover { 
            background: var(--success); 
            color: white; 
        }
        .btn-outline-primary { 
            border-color: var(--info); 
            color: var(--info); 
        }
        .btn-outline-primary:hover { 
            background: var(--info); 
            color: white; 
        }
        .btn-outline-info { 
            border-color: var(--info); 
            color: var(--info); 
        }
        .btn-outline-info:hover { 
            background: var(--info); 
            color: white; 
        }
        .btn-success { 
            background: linear-gradient(180deg, #238636, #2ea043); 
            border: none; 
        }
        .btn-info { 
            background: var(--info); 
            border: none; 
            color: white;
        }
        
        /* List Groups - Dark Theme */
        .list-group-item { 
            background: var(--card-bg); 
            border-color: var(--border-color); 
            color: var(--text-main);
        }
        .list-group-item:hover { 
            background: rgba(255,255,255,0.05); 
        }
        .list-group-flush > .list-group-item {
            border-width: 0 0 1px;
        }
        
        /* Trade Cards - Fix blue/active background */
        .trade-card {
            background: var(--card-bg) !important;
            transition: background 0.2s;
        }
        .trade-card:hover {
            background: rgba(255,255,255,0.08) !important;
        }
        .trade-card.active {
            background: var(--card-bg) !important;
            border-left-color: var(--info) !important;
        }
        .trade-card.win {
            border-left-color: var(--success) !important;
        }
        .trade-card.loss {
            border-left-color: var(--danger) !important;
        }
        
        /* Form Controls */
        .form-control, .form-select { 
            background: var(--card-bg); 
            border-color: var(--border-color); 
            color: var(--text-main); 
        }
        .form-control:focus, .form-select:focus { 
            background: var(--card-bg); 
            border-color: var(--primary); 
            color: var(--text-main);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }
        
        /* Typography */
        h1, h2, h3, h4, h5, h6 { color: var(--text-main); }
        a { color: var(--info); }
        a:hover { color: var(--success); }
        
        /* Code blocks */
        code { 
            background: rgba(0,0,0,0.3); 
            color: var(--warning); 
            padding: 2px 6px; 
            border-radius: 4px; 
        }
        
        /* JSON display */
        .json-display { 
            background: var(--bg-code); 
            border: 1px solid var(--border-color); 
            border-radius: 8px; 
            padding: 12px; 
            font-family: monospace; 
            font-size: 13px; 
            color: var(--text-main);
            max-height: 400px;
            overflow-y: auto;
        }
        
        /* Simulator Navigation Bar */
        .simulator-nav {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 12px 16px;
        }
        
        .simulator-nav .nav-links .btn {
            font-size: 0.85rem;
        }
        
        .parser-status {
            background: rgba(0,0,0,0.2);
            padding: 6px 12px;
            border-radius: 6px;
        }
        
        .parser-status .badge {
            transition: transform 0.2s;
        }
        
        .parser-status .badge:hover {
            transform: scale(1.1);
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <?php
        // FIX-4.3: Display config error if moduleBase is not configured
        $configError = $config_error ?? null;
        if ($configError !== null):
        ?>
        <div class="alert alert-danger" role="alert">
            <h4 class="alert-heading">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                Ошибка конфигурации
            </h4>
            <p>Базовый путь модуля симулятора не настроен. Пожалуйста, настройте SystemPaths, включив:</p>
            <code>simulator.parser6_simulator</code> или <code>parser.parser6_simulator</code>
            <hr>
            <p class="mb-0">Код ошибки: <code><?= htmlspecialchars($configError) ?></code></p>
        </div>
    </div>
</body>
</html>
        <?php else: ?>
        
        <!-- Top Navigation Bar -->
        <nav class="simulator-nav mb-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <!-- Main Navigation Links -->
                <div class="nav-links d-flex gap-2 flex-wrap">
                    <a href="/admin" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-house me-1"></i> Главная
                    </a>
                    <a href="/admin/brain" class="btn btn-outline-info btn-sm">
                        <i class="bi bi-cpu me-1"></i> Мозг
                    </a>
                    <a href="/admin/simulator" class="btn btn-primary btn-sm">
                        <i class="bi bi-graph-up-arrow me-1"></i> Симулятор
                    </a>
                    <a href="/admin/parser" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-repeat me-1"></i> Парсер
                    </a>
                    <a href="/admin/system" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-gear me-1"></i> Система
                    </a>
                </div>
                
                <!-- Parser Status Block -->
                <?php $parserStatuses = $parser_statuses ?? []; ?>
                <?php if (!empty($parserStatuses)): ?>
                <div class="parser-status d-flex align-items-center gap-2 flex-wrap">
                    <span class="text-muted small me-1">Парсеры:</span>
                    <?php foreach ($parserStatuses as $parserId => $parserStatus): ?>
                        <?php 
                        $isOk = $parserStatus['ok'] ?? false;
                        $statusClass = $isOk ? 'bg-success' : 'bg-danger';
                        $statusTitle = $parserStatus['display_name'] . ' - ' . ($isOk ? 'OK' : 'Error');
                        if (!empty($parserStatus['last_run'])) {
                            $statusTitle .= ' (' . $parserStatus['last_run'] . ')';
                        }
                        // Abbreviate parser name for compact display
                        $shortName = '';
                        if (preg_match('/parser(\d+)/i', $parserId, $m)) {
                            $shortName = 'P' . $m[1];
                        } elseif (stripos($parserId, 'delist') !== false) {
                            $shortName = 'DL';
                        } else {
                            $shortName = substr($parserId, 0, 2);
                        }
                        ?>
                        <span class="badge <?= $statusClass ?>" title="<?= htmlspecialchars($statusTitle) ?>" 
                              style="cursor: help; font-size: 0.7rem; padding: 4px 6px;">
                            <?= htmlspecialchars($shortName) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </nav>
        
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-1">
                    <i class="bi bi-graph-up-arrow text-primary me-2"></i>
                    Parser 6: Симулятор
                </h1>
                <p class="text-muted mb-0">Симулирует торговые сигналы от Parser5 используя поток цен Parser2</p>
            </div>
            <div class="btn-group">
                <a href="/admin" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Назад
                </a>
                <button class="btn btn-primary" onclick="runSimulation()">
                    <i class="bi bi-play-fill me-1"></i> Запустить
                </button>
                <button class="btn btn-outline-danger" onclick="confirmReset()">
                    <i class="bi bi-arrow-counterclockwise me-1"></i> Сбросить
                </button>
                <!-- P3.3: Mode-aware export buttons -->
                <div class="btn-group">
                    <a href="/admin/simulator/api/export_dataset?mode=<?= htmlspecialchars($currentMode !== 'compare' ? $currentMode : 'clean') ?>" class="btn btn-outline-success">
                        <i class="bi bi-download me-1"></i> Экспорт <?= strtoupper($currentMode !== 'compare' ? $currentMode : 'CLEAN') ?>
                    </a>
                    <?php if ($currentMode === 'compare'): ?>
                    <a href="/admin/simulator/api/export_dataset?mode=raw" class="btn btn-outline-success">
                        <i class="bi bi-download me-1"></i> Экспорт RAW
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Status Alert -->
        <div class="alert <?= ($state['ok'] ?? true) ? 'alert-success' : 'alert-warning' ?> d-flex align-items-center mb-4">
            <span class="status-indicator <?= ($state['ok'] ?? true) ? 'status-ok' : 'status-error' ?> me-2"></span>
            <div>
                <strong>Статус:</strong> <?= htmlspecialchars($state['status'] ?? 'неизвестно') ?>
                <?php if (!empty($lastRun['ts'])): ?>
                    — Последний запуск: <?= htmlspecialchars($lastRun['ts']) ?>
                    (<?= $lastRun['duration_ms'] ?? 0 ?>мс)
                <?php endif; ?>
            </div>
        </div>

        <!-- Mode Switcher (RAW vs CLEAN comparison) -->
        <?php /* $currentMode / $comparisonData подготовлены в начале файла */ ?>
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-toggles text-info me-2"></i>
                    Режим симуляции
                </h5>
                <div class="btn-group btn-group-sm">
                    <a href="?mode=raw" class="btn <?= $currentMode === 'raw' ? 'btn-primary' : 'btn-outline-primary' ?>">
                        RAW
                    </a>
                    <a href="?mode=clean" class="btn <?= $currentMode === 'clean' ? 'btn-success' : 'btn-outline-success' ?>">
                        CLEAN
                    </a>
                    <a href="?mode=compare" class="btn <?= $currentMode === 'compare' ? 'btn-info' : 'btn-outline-info' ?>">
                        COMPARE
                    </a>
                </div>
            </div>
            <?php if ($currentMode === 'compare' && $comparisonData): ?>
                <?php
                $rawData = $comparisonData['raw'] ?? [];
                $cleanData = $comparisonData['clean'] ?? [];
                $deltaData = $comparisonData['delta'] ?? [];
                ?>
                <div class="card-body">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Метрика</th>
                                <th class="text-end">RAW</th>
                                <th class="text-end">CLEAN</th>
                                <th class="text-end">Разница</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $metrics = [
                                'signals_loaded' => 'Сигналов загружено',
                                'signals_eligible' => 'Сигналов подходящих',
                                'opened_now' => 'Открыто в этом запуске',
                                'total_closed' => 'Всего закрыто',
                                'rejected_count' => 'Отклонено',
                                'win_rate' => 'Винрейт',
                                'avg_roi' => 'Средний ROI',
                                'median_roi' => 'Медианный ROI',
                                'duration_avg_min' => 'Средняя длительность (мин)',
                            ];
                            foreach ($metrics as $key => $label):
                                $rawVal = $rawData[$key] ?? 0;
                                $cleanVal = $cleanData[$key] ?? 0;
                                $deltaVal = $deltaData[$key] ?? 0;
                                $isPercent = in_array($key, ['win_rate', 'avg_roi', 'median_roi']);
                                $deltaClass = $deltaVal > 0 ? 'text-success' : ($deltaVal < 0 ? 'text-danger' : '');
                            ?>
                                <tr>
                                    <td><?= $label ?></td>
                                    <td class="text-end"><?= $isPercent ? number_format($rawVal * 100, 2) . '%' : htmlspecialchars((string)$rawVal) ?></td>
                                    <td class="text-end"><?= $isPercent ? number_format($cleanVal * 100, 2) . '%' : htmlspecialchars((string)$cleanVal) ?></td>
                                    <td class="text-end <?= $deltaClass ?>">
                                        <?= $deltaVal > 0 ? '+' : '' ?><?= $isPercent ? number_format($deltaVal * 100, 2) . '%' : htmlspecialchars((string)$deltaVal) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if (!empty($deltaData['closed_by_reason'])): ?>
                        <h6 class="mt-3 mb-2">Разница закрытий по причинам</h6>
                        <div class="row">
                            <?php foreach ($deltaData['closed_by_reason'] as $reason => $count): ?>
                                <div class="col-md-4 mb-2">
                                    <span class="badge <?= $count > 0 ? 'bg-success' : ($count < 0 ? 'bg-danger' : 'bg-secondary') ?>">
                                        <?= htmlspecialchars($reason) ?>: <?= $count > 0 ? '+' : '' ?><?= htmlspecialchars((string)$count) ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($deltaData['rejected_by_reason'])): ?>
                        <h6 class="mt-3 mb-2">Разница отклонений по причинам</h6>
                        <div class="row">
                            <?php foreach ($deltaData['rejected_by_reason'] as $reason => $count): ?>
                                <div class="col-md-4 mb-2">
                                    <span class="badge <?= $count > 0 ? 'bg-success' : ($count < 0 ? 'bg-danger' : 'bg-secondary') ?>">
                                        <?= htmlspecialchars($reason) ?>: <?= $count > 0 ? '+' : '' ?><?= htmlspecialchars((string)$count) ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php elseif ($currentMode !== 'compare'): ?>
                <div class="card-body">
                    <p class="mb-0 text-muted">
                        Просмотр режима <strong><?= strtoupper($currentMode) ?></strong>.
                        <?php if ($currentMode === 'raw'): ?>
                            Режим RAW показывает сигналы напрямую от Parser5 без обработки Brain gateway.
                        <?php else: ?>
                            Режим CLEAN показывает сигналы после обработки Brain gateway (entry_action, late_threshold и т.д.).
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="card-body">
                    <p class="text-muted mb-0">
                        Режим сравнения не включён или данные сравнения недоступны.
                        Включите <code>comparison.enabled</code> и <code>comparison.run_both</code> в конфигурации.
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-2">
                <div class="card stat-card h-100">
                    <div class="card-body text-center">
                        <div class="h2 mb-1 text-primary"><?= $state['open_trades'] ?? 0 ?></div>
                        <div class="text-muted small">Активные сделки</div>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card stat-card h-100">
                    <div class="card-body text-center">
                        <div class="h2 mb-1"><?= $summary['total_trades_closed'] ?? 0 ?></div>
                        <div class="text-muted small">Закрытые сделки</div>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card stat-card h-100">
                    <div class="card-body text-center">
                        <div class="h2 mb-1 text-success"><?= $summary['wins'] ?? 0 ?></div>
                        <div class="text-muted small">Прибыльные</div>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card stat-card h-100">
                    <div class="card-body text-center">
                        <div class="h2 mb-1 text-danger"><?= $summary['losses'] ?? 0 ?></div>
                        <div class="text-muted small">Убыточные</div>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card stat-card h-100">
                    <div class="card-body text-center">
                        <?php $winrate = ($summary['winrate'] ?? 0) * 100; ?>
                        <div class="h2 mb-1 <?= $winrate >= 50 ? 'text-success' : 'text-danger' ?>">
                            <?= number_format($winrate, 1) ?>%
                        </div>
                        <div class="text-muted small">Винрейт</div>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card stat-card h-100">
                    <div class="card-body text-center">
                        <?php 
                        // P0.5: Use stats_global.total_roi (in fraction) or summary.roi_sum
                        // stats_global.total_roi = -0.1083 means -10.83%
                        $totalRoiFraction = $statsGlobal['total_roi'] ?? $summary['roi_sum'] ?? 0;
                        $roi = $totalRoiFraction * 100; // Convert fraction to percent
                        ?>
                        <div class="h2 mb-1 <?= $roi >= 0 ? 'roi-positive' : 'roi-negative' ?>">
                            <?= ($roi >= 0 ? '+' : '') . number_format($roi, 2) ?>%
                        </div>
                        <div class="text-muted small">Общий ROI</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- C2.3: Active Trades split into Open and Pending Entry -->
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-lightning-fill text-warning me-2"></i>
                            Активные сделки
                        </h5>
                        <span class="badge bg-primary"><?= count($activeTrades) ?></span>
                    </div>
                    <div class="card-body p-0" style="max-height: 450px; overflow-y: auto;">
                        <?php 
                        // C2.3: Split trades by status (open vs pending_entry)
                        $openTrades = array_filter($activeTrades, fn($t) => ($t['status'] ?? 'open') === 'open');
                        $pendingTrades = array_filter($activeTrades, fn($t) => ($t['status'] ?? 'open') === 'pending_entry');
                        ?>
                        <?php if (empty($activeTrades)): ?>
                            <div class="text-center text-muted py-5">
                                <i class="bi bi-inbox h1"></i>
                                <p class="mb-0">Нет активных сделок</p>
                            </div>
                        <?php else: ?>
                            <?php if (!empty($openTrades)): ?>
                            <div class="px-3 py-2 bg-success bg-opacity-10 border-bottom">
                                <small class="text-success fw-bold"><i class="bi bi-circle-fill me-1"></i>Открытые (<?= count($openTrades) ?>)</small>
                            </div>
                            <div class="list-group list-group-flush">
                                <?php foreach ($openTrades as $trade): ?>
                                    <?php
                                    // C2.2: Use roi_unrealized_pct if available, fallback to roi_margin or calculate
                                    $roi = $trade['pnl']['roi_unrealized_pct'] ?? ($trade['pnl']['roi_margin'] ?? (($trade['pnl']['roi_unrealized'] ?? 0) * 100));
                                    $side = $trade['side'] ?? 'long';
                                    $lastPrice = $trade['market']['last_price'] ?? 0;
                                    ?>
                                    <div class="list-group-item trade-card active" onclick="showTradeDetail('<?= htmlspecialchars($trade['trade_id'] ?? '') ?>')" style="cursor: pointer;">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <span class="badge badge-<?= $side ?>"><?= strtoupper($side) ?></span>
                                                <strong class="ms-2"><?= htmlspecialchars($trade['symbol'] ?? '') ?></strong>
                                            </div>
                                            <div class="<?= $roi >= 0 ? 'roi-positive' : 'roi-negative' ?>">
                                                <?= ($roi >= 0 ? '+' : '') . number_format($roi, 2) ?>%
                                            </div>
                                        </div>
                                        <div class="small text-muted mt-1">
                                            Вход: <?= number_format($trade['entry']['opened_price'] ?? 0, 6) ?>
                                            | Текущая: <?= number_format($lastPrice, 6) ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($pendingTrades)): ?>
                            <div class="px-3 py-2 bg-warning bg-opacity-10 border-bottom">
                                <small class="text-warning fw-bold"><i class="bi bi-hourglass-split me-1"></i>Ожидающие входа (<?= count($pendingTrades) ?>)</small>
                            </div>
                            <div class="list-group list-group-flush">
                                <?php foreach ($pendingTrades as $trade): ?>
                                    <?php
                                    $side = $trade['side'] ?? 'long';
                                    $targetEntry = $trade['entry']['target_price'] ?? 0;
                                    $entryActionApplied = $trade['entry_action_applied'] ?? 'waiting';
                                    ?>
                                    <div class="list-group-item trade-card" onclick="showTradeDetail('<?= htmlspecialchars($trade['trade_id'] ?? '') ?>')" style="cursor: pointer; border-left-color: #ffc107 !important;">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <span class="badge badge-<?= $side ?>"><?= strtoupper($side) ?></span>
                                                <strong class="ms-2"><?= htmlspecialchars($trade['symbol'] ?? '') ?></strong>
                                                <span class="badge bg-warning text-dark ms-1">ОЖИДАНИЕ</span>
                                            </div>
                                            <div class="text-muted">
                                                <small>Ожидание</small>
                                            </div>
                                        </div>
                                        <div class="small text-muted mt-1">
                                            Цель: <?= number_format($targetEntry, 6) ?>
                                            | <?= htmlspecialchars($entryActionApplied) ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Closed Trades -->
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-check-circle text-success me-2"></i>
                            Недавно закрытые сделки
                        </h5>
                        <span class="badge bg-secondary"><?= count($closedTrades) ?></span>
                    </div>
                    <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                        <?php if (empty($closedTrades)): ?>
                            <div class="text-center text-muted py-5">
                                <i class="bi bi-inbox h1"></i>
                                <p class="mb-0">Нет закрытых сделок</p>
                            </div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($closedTrades as $trade): ?>
                                    <?php
                                    // P0.5: Use sim_trade_v1 schema (close_result.*)
                                    $closeResult = $trade['close_result'] ?? [];
                                    // ROI: close_result.roi_net_pct or roi_margin_pct (already in %, don't multiply)
                                    $roiPct = $closeResult['roi_net_pct'] ?? $closeResult['roi_margin_pct'] ?? (($trade['result']['roi_realized'] ?? 0) * 100);
                                    $win = $closeResult['win'] ?? $trade['labels']['win'] ?? false;
                                    $side = $trade['side'] ?? 'long';
                                    $reason = $closeResult['close_reason'] ?? $trade['close_reason'] ?? 'unknown';
                                    $pnl = $closeResult['pnl_realized_usdt'] ?? $trade['result']['pnl_realized_usdt'] ?? 0;
                                    // P0.5: Calculate duration from close_result.close_ts and entry.opened_ts
                                    $openedTs = (int)($trade['entry']['opened_ts'] ?? $trade['opened_ts'] ?? 0);
                                    $closeTs = (int)($closeResult['close_ts'] ?? $trade['closed_ts'] ?? 0);
                                    $durationMin = ($openedTs > 0 && $closeTs > $openedTs) ? (int)floor(($closeTs - $openedTs) / 60) : 0;
                                    ?>
                                    <div class="list-group-item trade-card <?= $win ? 'win' : 'loss' ?>" onclick="showTradeDetail('<?= htmlspecialchars($trade['trade_id'] ?? '') ?>')" style="cursor: pointer;">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <span class="badge badge-<?= $side ?>"><?= strtoupper($side) ?></span>
                                                <strong class="ms-2"><?= htmlspecialchars($trade['symbol'] ?? '') ?></strong>
                                                <span class="badge bg-<?= $win ? 'success' : 'danger' ?> ms-2"><?= strtoupper($reason) ?></span>
                                            </div>
                                            <div class="<?= $roiPct >= 0 ? 'roi-positive' : 'roi-negative' ?>">
                                                <?= ($roiPct >= 0 ? '+' : '') . number_format($roiPct, 2) ?>%
                                            </div>
                                        </div>
                                        <div class="small text-muted mt-1">
                                            Длительность: <?= $durationMin ?> мин
                                            | PnL: $<?= number_format($pnl, 2) ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Close Reasons Chart -->
        <div class="row">
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Причины закрытия</h5>
                    </div>
                    <div class="card-body">
                        <?php $closedBy = $summary['closed_by'] ?? []; ?>
                        <div class="row text-center">
                            <div class="col">
                                <div class="h4 text-success"><?= $closedBy['tp'] ?? 0 ?></div>
                                <small class="text-muted">Тейк профит</small>
                            </div>
                            <div class="col">
                                <div class="h4 text-danger"><?= $closedBy['sl'] ?? 0 ?></div>
                                <small class="text-muted">Стоп лосс</small>
                            </div>
                            <div class="col">
                                <div class="h4 text-warning"><?= $closedBy['trailing_sl'] ?? 0 ?></div>
                                <small class="text-muted">Трейлинг SL</small>
                            </div>
                            <div class="col">
                                <div class="h4 text-secondary"><?= $closedBy['expired_signal'] ?? 0 ?></div>
                                <small class="text-muted">Истекшие</small>
                            </div>
                            <div class="col">
                                <div class="h4 text-info"><?= $closedBy['expired_entry_timeout'] ?? 0 ?></div>
                                <small class="text-muted">Таймаут входа</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-shield-check me-2"></i>Активный риск (только чтение)</h5>
                        <span class="badge bg-info">От Brain</span>
                    </div>
                    <div class="card-body">
                        <?php 
                        $effectiveRisk = $effective_risk ?? null;
                        $riskData = $effectiveRisk['risk'] ?? null;
                        ?>
                        <?php if ($riskData !== null): ?>
                            <div class="mb-2">
                                <small class="text-muted">ID профиля:</small>
                                <div><strong class="text-primary"><?= htmlspecialchars($effectiveRisk['profile_id'] ?? 'неизвестно') ?></strong></div>
                            </div>
                            <div class="row">
                                <div class="col-6">
                                    <small class="text-muted">Бюджет на сделку:</small>
                                    <div><strong>$<?= $riskData['budget_usdt_per_trade'] ?? 50 ?></strong></div>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted">Плечо:</small>
                                    <div><strong><?= $riskData['leverage'] ?? 10 ?>x</strong></div>
                                </div>
                                <div class="col-6 mt-2">
                                    <small class="text-muted">Проскальзывание:</small>
                                    <div><strong><?= $riskData['slippage_bps'] ?? 20 ?> bps</strong></div>
                                </div>
                                <div class="col-6 mt-2">
                                    <small class="text-muted">Комиссии:</small>
                                    <div><strong><?= $riskData['fees_bps'] ?? 6 ?> bps</strong></div>
                                </div>
                                <div class="col-6 mt-2">
                                    <small class="text-muted">SL от ликвидации:</small>
                                    <div><strong><?= $riskData['stop_from_liq_range_pct'] ?? 20 ?>%</strong></div>
                                </div>
                                <div class="col-6 mt-2">
                                    <small class="text-muted">Трейлинг:</small>
                                    <div><strong><?= ($riskData['trailing']['enabled'] ?? true) ? 'Включён' : 'Выключен' ?></strong></div>
                                </div>
                                <?php if (!empty($riskData['trailing']['mode'])): ?>
                                <div class="col-6 mt-2">
                                    <small class="text-muted">Режим трейлинга:</small>
                                    <div><strong><?= htmlspecialchars($riskData['trailing']['mode'] ?? 'normal') ?></strong></div>
                                </div>
                                <div class="col-6 mt-2">
                                    <small class="text-muted">Фактор просадки:</small>
                                    <div><strong><?= number_format(($riskData['trailing']['drawdown_factor'] ?? 0.5) * 100, 0) ?>%</strong></div>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($riskData['take_profit']['enabled'])): ?>
                                <div class="col-6 mt-2">
                                    <small class="text-muted">Тейк профит:</small>
                                    <div><strong><?= $riskData['take_profit']['roi_pct'] ?? 0 ?>% ROI</strong></div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="mt-2 pt-2 border-top">
                                <small class="text-muted">Источник: <span class="badge bg-success"><?= htmlspecialchars($effectiveRisk['risk_source'] ?? 'brain_signal') ?></span></small>
                            </div>
                        <?php else: ?>
                            <div class="text-center text-muted py-3">
                                <i class="bi bi-info-circle h3"></i>
                                <p class="mb-0">Активный риск недоступен</p>
                                <small>Запустите симулятор в режиме CLEAN, чтобы увидеть профиль риска Brain</small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Execution Config (non-risk settings only) -->
        <div class="row">
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-gear me-2"></i>Настройки исполнения</h5>
                    </div>
                    <div class="card-body">
                        <?php 
                        $exec = $config['execution'] ?? []; 
                        // C2.3: Limits come from Brain via effective_risk, NOT from config
                        $limits = $effectiveRisk['risk']['limits'] ?? [];
                        ?>
                        <div class="row">
                            <div class="col-6">
                                <small class="text-muted">Макс. открытых сделок:</small>
                                <div><strong><?php 
                                    $maxOpen = $limits['max_open_trades'] ?? 0;
                                    echo $maxOpen <= 0 ? 'Без ограничений' : $maxOpen;
                                ?></strong></div>
                            </div>
                            <div class="col-6">
                                <small class="text-muted">Одна сделка на символ:</small>
                                <div><strong><?= ($limits['one_trade_per_symbol'] ?? true) ? 'Да' : 'Нет' ?></strong></div>
                            </div>
                            <div class="col-6 mt-2">
                                <small class="text-muted">Таймаут входа:</small>
                                <div><strong><?= $exec['entry_timeout_minutes'] ?? 10 ?> мин</strong></div>
                            </div>
                            <div class="col-6 mt-2">
                                <small class="text-muted">Строгий вход:</small>
                                <div><strong><?= ($exec['strict_entry'] ?? true) ? 'Да' : 'Нет' ?></strong></div>
                            </div>
                        </div>
                        <div class="mt-2 pt-2 border-top">
                            <small class="text-muted">Источник: <span class="badge bg-info">Профиль Brain</span></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Global Stats (per ТЗ spec section 12) & Rejected Trades -->
        <div class="row">
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Глобальная статистика (stats_global.json)</h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col">
                                <div class="h5"><?= $statsGlobal['total_trades'] ?? 0 ?></div>
                                <small class="text-muted">Всего сделок</small>
                            </div>
                            <div class="col">
                                <?php $profitFactor = $statsGlobal['profit_factor'] ?? 0; ?>
                                <div class="h5 <?= $profitFactor >= 1 ? 'text-success' : 'text-danger' ?>">
                                    <?= number_format($profitFactor, 2) ?>
                                </div>
                                <small class="text-muted">Профит-фактор</small>
                            </div>
                            <div class="col">
                                <?php $maxDrawdown = ($statsGlobal['max_drawdown'] ?? 0) * 100; ?>
                                <div class="h5 text-danger"><?= number_format($maxDrawdown, 2) ?>%</div>
                                <small class="text-muted">Макс. просадка</small>
                            </div>
                            <div class="col">
                                <?php $avgDuration = $statsGlobal['avg_duration'] ?? 0; ?>
                                <div class="h5"><?= number_format($avgDuration / 60, 1) ?> мин</div>
                                <small class="text-muted">Сред. длительность</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-6 mb-4">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-x-circle text-warning me-2"></i>
                            Отклонённые сделки
                        </h5>
                        <span class="badge bg-warning"><?= count($rejectedTrades) ?></span>
                    </div>
                    <div class="card-body p-0" style="max-height: 200px; overflow-y: auto;">
                        <?php if (empty($rejectedTrades)): ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-check-circle h4"></i>
                                <p class="mb-0 small">Нет отклонённых сделок</p>
                            </div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($rejectedTrades as $trade): ?>
                                    <?php
                                    $side = $trade['side'] ?? 'long';
                                    $reason = $trade['reject_reason'] ?? 'unknown';
                                    ?>
                                    <div class="list-group-item py-2">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <span class="badge badge-<?= $side ?>"><?= strtoupper($side) ?></span>
                                                <strong class="ms-2"><?= htmlspecialchars($trade['symbol'] ?? '') ?></strong>
                                            </div>
                                            <span class="badge bg-warning text-dark"><?= strtoupper($reason) ?></span>
                                        </div>
                                        <small class="text-muted"><?= htmlspecialchars($trade['rejected_at'] ?? '') ?></small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Trade Detail Modal -->
    <div class="modal fade" id="tradeDetailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Детали сделки</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="tradeDetailBody">
                    <div class="text-center py-5">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Загрузка...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Configuration Modal (Part 1 of TZ) - Execution Settings Only (Risk managed by Brain) -->
    <div class="modal fade" id="configModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-gear me-2"></i>Редактировать настройки исполнения</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Примечание:</strong> Параметры риска (бюджет, плечо, трейлинг, SL, TP) управляются профилями риска Brain.
                        <a href="/admin/brain/profiles" class="alert-link">Редактировать профили риска в Brain →</a>
                    </div>
                    <form id="configForm">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-primary mb-3">Лимиты сделок</h6>
                                <div class="mb-3">
                                    <label class="form-label">Макс. открытых сделок (0 = без ограничений)</label>
                                    <input type="number" class="form-control" name="risk.max_open_trades" step="1" min="0">
                                </div>
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" id="oneTradePerSymbol" name="risk.one_trade_per_symbol">
                                    <label class="form-check-label" for="oneTradePerSymbol">Одна сделка на символ</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-primary mb-3">Исполнение</h6>
                                <div class="mb-3">
                                    <label class="form-label">Таймаут входа (минуты)</label>
                                    <input type="number" class="form-control" name="execution.entry_timeout_minutes" step="1" min="1">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Макс. длительность сделки (минуты, 0 = без ограничений)</label>
                                    <input type="number" class="form-control" name="execution.max_duration_minutes" step="1" min="0">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Макс. возраст сигнала (минуты)</label>
                                    <input type="number" class="form-control" name="execution.signal_max_age_min" step="1" min="1">
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" onclick="resetConfig()">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Сбросить по умолчанию
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-primary" onclick="saveConfig()">
                        <i class="bi bi-check-lg me-1"></i>Сохранить настройки
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div class="toast-container position-fixed bottom-0 end-0 p-3">
        <div id="toast" class="toast" role="alert">
            <div class="toast-header">
                <strong class="me-auto" id="toastTitle">Уведомление</strong>
                <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
            </div>
            <div class="toast-body" id="toastBody"></div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showToast(title, message, isError = false) {
            const toast = document.getElementById('toast');
            document.getElementById('toastTitle').textContent = title;
            document.getElementById('toastBody').textContent = message;
            toast.classList.toggle('bg-danger', isError);
            toast.classList.toggle('text-white', isError);
            new bootstrap.Toast(toast).show();
        }

        async function runSimulation() {
            try {
                const response = await fetch('/admin/simulator/api/run', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' }
                });
                const data = await response.json();
                if (data.success) {
                    showToast('Успех', `Шаг симуляции завершён за ${data.result?.duration_ms || 0}мс`);
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('Ошибка', data.error || 'Неизвестная ошибка', true);
                }
            } catch (e) {
                showToast('Ошибка', e.message, true);
            }
        }

        function confirmReset() {
            if (confirm('Вы уверены, что хотите сбросить симулятор? Это удалит все сделки и статистику.')) {
                resetSimulator();
            }
        }

        async function resetSimulator() {
            try {
                const formData = new FormData();
                formData.append('confirm', 'yes');
                const response = await fetch('/admin/simulator/api/reset', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    showToast('Успех', 'Симулятор был сброшен');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('Ошибка', data.error || 'Неизвестная ошибка', true);
                }
            } catch (e) {
                showToast('Ошибка', e.message, true);
            }
        }

        async function showTradeDetail(tradeId) {
            const modal = new bootstrap.Modal(document.getElementById('tradeDetailModal'));
            document.getElementById('tradeDetailBody').innerHTML = '<div class="text-center py-5"><div class="spinner-border"></div></div>';
            modal.show();

            try {
                const response = await fetch(`/admin/simulator/api/trade?id=${encodeURIComponent(tradeId)}`);
                const data = await response.json();
                if (data.success && data.trade) {
                    renderTradeDetail(data.trade);
                } else {
                    document.getElementById('tradeDetailBody').innerHTML = '<div class="alert alert-danger">Сделка не найдена</div>';
                }
            } catch (e) {
                document.getElementById('tradeDetailBody').innerHTML = `<div class="alert alert-danger">${e.message}</div>`;
            }
        }

        function renderTradeDetail(trade) {
            const isActive = trade._status === 'active';
            const side = trade.side || 'long';
            
            // P0.5: Use sim_trade_v1 schema (close_result.*)
            const closeResult = trade.close_result || {};
            // For closed trades: use close_result.roi_net_pct (already in %), fallback to roi_margin_pct
            // For active trades: use pnl.roi_unrealized_pct (already in %)
            const roi = isActive 
                ? (trade.pnl?.roi_unrealized_pct || (trade.pnl?.roi_unrealized || 0) * 100)
                : (closeResult.roi_net_pct || closeResult.roi_margin_pct || (trade.result?.roi_realized || 0) * 100);
            
            // P0.5: Calculate duration from close_result.close_ts and entry.opened_ts
            const openedTs = trade.entry?.opened_ts || trade.opened_ts || 0;
            const closeTs = closeResult.close_ts || trade.closed_ts || 0;
            const durationMin = (openedTs > 0 && closeTs > openedTs) ? Math.floor((closeTs - openedTs) / 60) : 0;
            
            // P0.5: Get close reason and PnL from close_result
            const closeReason = closeResult.close_reason || trade.close_reason || 'unknown';
            const pnlRealized = closeResult.pnl_realized_usdt || trade.result?.pnl_realized_usdt || 0;
            const closePrice = closeResult.close_price || trade.closed_price || 0;

            let html = `
                <div class="row">
                    <div class="col-md-6">
                        <h6>Информация о сделке</h6>
                        <table class="table table-sm">
                            <tr><td>ID</td><td><code>${trade.trade_id || ''}</code></td></tr>
                            <tr><td>Символ</td><td><strong>${trade.symbol || ''}</strong></td></tr>
                            <tr><td>Направление</td><td><span class="badge badge-${side}">${side.toUpperCase()}</span></td></tr>
                            <tr><td>Статус</td><td><span class="badge bg-${isActive ? 'primary' : 'secondary'}">${isActive ? 'АКТИВНА' : 'ЗАКРЫТА'}</span></td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6>Позиция</h6>
                        <table class="table table-sm">
                            <tr><td>Цена входа</td><td>${(trade.entry?.opened_price || trade.opened_price || 0).toFixed(6)}</td></tr>
                            <tr><td>Тейк профит</td><td>${(trade.targets?.take_profit || 0).toFixed(6)}</td></tr>
                            <tr><td>Стоп лосс</td><td>${(trade.targets?.stop_loss_current || trade.targets?.stop_loss_initial || 0).toFixed(6)}</td></tr>
                            <tr><td>Количество</td><td>${(trade.entry?.qty || trade.qty || 0).toFixed(2)}</td></tr>
                        </table>
                    </div>
                </div>
                
                <!-- Candles Chart Section (Part 2 of TZ) -->
                <div class="mt-3">
                    <h6><i class="bi bi-graph-up me-2"></i>График цены</h6>
                    <div id="candleChartContainer" class="chart-container d-flex align-items-center justify-content-center">
                        <div class="spinner-border spinner-border-sm text-secondary me-2"></div>
                        <span class="text-muted">Загрузка графика...</span>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-6">
                        <h6>Результаты</h6>
                        <table class="table table-sm">
                            <tr>
                                <td>ROI</td>
                                <td class="${roi >= 0 ? 'roi-positive' : 'roi-negative'}">${(roi >= 0 ? '+' : '')}${roi.toFixed(2)}%</td>
                            </tr>
                            ${isActive ? `
                                <tr><td>Нереализ. PnL</td><td>$${(trade.pnl?.pnl_unrealized_usdt || 0).toFixed(2)}</td></tr>
                                <tr><td>Текущая цена</td><td>${(trade.market?.last_price || 0).toFixed(6)}</td></tr>
                            ` : `
                                <tr><td>Реализ. PnL</td><td>$${pnlRealized.toFixed(2)}</td></tr>
                                <tr><td>Цена закрытия</td><td>${closePrice.toFixed(6)}</td></tr>
                                <tr><td>Причина закрытия</td><td><span class="badge bg-secondary">${closeReason.toUpperCase()}</span></td></tr>
                                <tr><td>Длительность</td><td>${durationMin} мин</td></tr>
                            `}
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6>Трейлинг</h6>
                        <table class="table table-sm">
                            <tr><td>Включён</td><td>${trade.trailing?.enabled ? 'Да' : 'Нет'}</td></tr>
                            <tr><td>Активен</td><td>${trade.trailing?.active ? 'Да' : 'Нет'}</td></tr>
                            ${trade.trailing?.peak_price ? `<tr><td>Пиковая цена</td><td>${trade.trailing.peak_price.toFixed(6)}</td></tr>` : ''}
                            ${trade.trailing?.stop_price ? `<tr><td>Трейл стоп</td><td>${trade.trailing.stop_price.toFixed(6)}</td></tr>` : ''}
                        </table>
                    </div>
                </div>
            `;

            // Show events if available
            if (trade.events && trade.events.length > 0) {
                html += `
                    <div class="mt-3">
                        <h6>События</h6>
                        <div class="list-group list-group-flush" style="max-height: 200px; overflow-y: auto;">
                            ${trade.events.map(e => `
                                <div class="list-group-item py-1 px-2">
                                    <small class="text-muted">${e.iso || ''}</small>
                                    <span class="badge bg-info ms-2">${e.type || ''}</span>
                                    <span class="ms-2">${e.note || ''}</span>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            }

            document.getElementById('tradeDetailBody').innerHTML = html;
            
            // Load candle chart after rendering
            loadTradeCandles(trade.trade_id);
        }
        
        // Configuration Functions (Part 1 of TZ)
        let currentConfig = null;
        
        async function showConfigModal() {
            const modal = new bootstrap.Modal(document.getElementById('configModal'));
            
            try {
                const response = await fetch('/admin/simulator/api/get_config');
                const data = await response.json();
                if (data.success && data.config) {
                    currentConfig = data.config;
                    populateConfigForm(data.config);
                }
            } catch (e) {
                showToast('Ошибка', 'Не удалось загрузить конфигурацию: ' + e.message, true);
            }
            
            modal.show();
        }
        
        function populateConfigForm(config) {
            const form = document.getElementById('configForm');
            
            // Trade Limits (non-risk settings)
            setFormValue(form, 'risk.max_open_trades', config.risk?.max_open_trades ?? 10);
            setFormCheckbox(form, 'risk.one_trade_per_symbol', config.risk?.one_trade_per_symbol ?? true);
            
            // Execution
            setFormValue(form, 'execution.entry_timeout_minutes', config.execution?.entry_timeout_minutes ?? 10);
            setFormValue(form, 'execution.max_duration_minutes', config.execution?.max_duration_minutes ?? 1440);
            setFormValue(form, 'execution.signal_max_age_min', config.execution?.signal_max_age_min ?? 30);
        }
        
        function setFormValue(form, name, value) {
            const input = form.querySelector(`[name="${name}"]`);
            if (input) input.value = value;
        }
        
        function setFormCheckbox(form, name, value) {
            const input = form.querySelector(`[name="${name}"]`);
            if (input) input.checked = !!value;
        }
        
        function getFormValue(form, name) {
            const input = form.querySelector(`[name="${name}"]`);
            if (!input) return undefined;
            if (input.type === 'checkbox') return input.checked;
            return input.type === 'number' ? parseFloat(input.value) : input.value;
        }
        
        async function saveConfig() {
            const form = document.getElementById('configForm');
            
            // Only save execution settings, not risk parameters (those are from Brain)
            const newConfig = {
                risk: {
                    max_open_trades: getFormValue(form, 'risk.max_open_trades'),
                    one_trade_per_symbol: getFormValue(form, 'risk.one_trade_per_symbol'),
                },
                execution: {
                    entry_timeout_minutes: getFormValue(form, 'execution.entry_timeout_minutes'),
                    max_duration_minutes: getFormValue(form, 'execution.max_duration_minutes'),
                    signal_max_age_min: getFormValue(form, 'execution.signal_max_age_min'),
                },
            };
            
            try {
                const response = await fetch('/admin/simulator/api/save_config', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ config: newConfig })
                });
                const data = await response.json();
                if (data.success) {
                    showToast('Успех', 'Настройки сохранены');
                    bootstrap.Modal.getInstance(document.getElementById('configModal')).hide();
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('Ошибка', data.error || 'Не удалось сохранить настройки', true);
                }
            } catch (e) {
                showToast('Ошибка', e.message, true);
            }
        }
        
        async function resetConfig() {
            if (!confirm('Сбросить настройки по умолчанию?')) return;
            
            try {
                const response = await fetch('/admin/simulator/api/reset_config', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' }
                });
                const data = await response.json();
                if (data.success) {
                    showToast('Успех', 'Настройки сброшены по умолчанию');
                    if (data.config) {
                        populateConfigForm(data.config);
                    }
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('Ошибка', data.error || 'Не удалось сбросить настройки', true);
                }
            } catch (e) {
                showToast('Ошибка', e.message, true);
            }
        }
        
        // Candle Chart Functions (Part 2 of TZ)
        async function loadTradeCandles(tradeId) {
            const container = document.getElementById('candleChartContainer');
            if (!container) return;
            
            try {
                const response = await fetch(`/admin/simulator/api/trade_candles?id=${encodeURIComponent(tradeId)}`);
                const data = await response.json();
                
                if (data.success && data.candles && data.candles.length > 0) {
                    renderCandleChart(container, data.candles, data.markers);
                } else {
                    container.innerHTML = '<div class="text-center text-muted"><i class="bi bi-bar-chart h4"></i><br>Данные графика недоступны</div>';
                }
            } catch (e) {
                container.innerHTML = '<div class="text-center text-danger"><i class="bi bi-exclamation-triangle h4"></i><br>Не удалось загрузить график</div>';
            }
        }
        
        function renderCandleChart(container, candles, markers) {
            // Simple SVG-based candlestick chart
            const width = container.clientWidth || 700;
            const height = 280;
            const padding = { top: 20, right: 60, bottom: 30, left: 10 };
            
            const prices = candles.flatMap(c => [c.high, c.low]);
            // Add 0.1% padding to price range for visual clarity (prevents candles touching edges)
            const minPrice = Math.min(...prices) * 0.999;
            const maxPrice = Math.max(...prices) * 1.001;
            const priceRange = maxPrice - minPrice;
            
            const candleWidth = Math.max(2, (width - padding.left - padding.right) / candles.length - 1);
            
            const scaleY = (price) => height - padding.bottom - ((price - minPrice) / priceRange * (height - padding.top - padding.bottom));
            const scaleX = (index) => padding.left + index * ((width - padding.left - padding.right) / candles.length) + candleWidth / 2;
            
            let svg = `<svg width="${width}" height="${height}" style="background:#1e1e2e;border-radius:8px;">`;
            
            // Draw price lines for markers
            if (markers) {
                markers.filter(m => m.type.includes('line')).forEach(m => {
                    const y = scaleY(m.price);
                    svg += `<line x1="${padding.left}" y1="${y}" x2="${width - padding.right}" y2="${y}" 
                            stroke="${m.color}" stroke-dasharray="4,4" opacity="0.7"/>`;
                    svg += `<text x="${width - padding.right + 5}" y="${y + 4}" fill="${m.color}" font-size="10">${m.label} ${m.price.toFixed(4)}</text>`;
                });
            }
            
            // Draw candles
            candles.forEach((c, i) => {
                const x = scaleX(i);
                const isGreen = c.close >= c.open;
                const color = isGreen ? '#22c55e' : '#ef4444';
                
                // Wick
                svg += `<line x1="${x}" y1="${scaleY(c.high)}" x2="${x}" y2="${scaleY(c.low)}" stroke="${color}" stroke-width="1"/>`;
                
                // Body
                const bodyTop = scaleY(Math.max(c.open, c.close));
                const bodyBottom = scaleY(Math.min(c.open, c.close));
                const bodyHeight = Math.max(1, bodyBottom - bodyTop);
                svg += `<rect x="${x - candleWidth/2}" y="${bodyTop}" width="${candleWidth}" height="${bodyHeight}" fill="${color}"/>`;
            });
            
            // Draw markers (entry, filled, close)
            if (markers) {
                markers.filter(m => !m.type.includes('line')).forEach(m => {
                    // Find candle index closest to marker time
                    const idx = candles.findIndex(c => c.time >= m.time);
                    if (idx >= 0) {
                        const x = scaleX(idx);
                        const y = scaleY(m.price);
                        
                        if (m.type === 'entry' || m.type === 'filled') {
                            svg += `<circle cx="${x}" cy="${y}" r="5" fill="${m.color}" stroke="white" stroke-width="1"/>`;
                            svg += `<text x="${x + 8}" y="${y + 4}" fill="${m.color}" font-size="10">${m.label}</text>`;
                        } else if (m.type === 'close') {
                            svg += `<rect x="${x - 4}" y="${y - 4}" width="8" height="8" fill="${m.color}" stroke="white" stroke-width="1"/>`;
                            svg += `<text x="${x + 8}" y="${y + 4}" fill="${m.color}" font-size="10">${m.label}</text>`;
                        }
                    }
                });
            }
            
            svg += '</svg>';
            container.innerHTML = svg;
        }
    </script>
<?php endif; // End of configError check ?>
</body>
</html>

<?php
/*
RULES (Tredercopis):
1) Код = истина: правки делаются только по актуальным файлам/архиву.
2) Никаких фрагментов: при фиксе файла всегда выдаётся полный файл целиком.
3) Переменные UI должны быть определены до первого использования (без Notice/Deprecated).
4) LF-only.
*/
?>
