<?php
/**
 * Win Universe Module — Index View
 *
 * Shadow-mode dashboard showing qualified, near-qualified, and rejected symbols.
 *
 * @var array<string,mixed>|null $universe  Loaded win_universe.json or null
 * @var array<string,mixed>|null $status    Loaded win_universe_status.json or null
 * @var array<string,mixed>      $config    Module config
 * @var string                   $baseUrl
 */

$pageTitle = 'Win Universe — Теневой режим';

$extraScripts = <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', function () {
    const runBtn = document.getElementById('runBtn');
    if (runBtn) {
        runBtn.addEventListener('click', function () {
            runBtn.disabled = true;
            runBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Вычисление…';
            fetch('/admin/win_universe/run', { method: 'POST' })
                .then(r => r.json())
                .then(data => {
                    runBtn.disabled = false;
                    runBtn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Запустить';
                    if (data.ok) {
                        showFlash('Готово. Квалифицировано: ' + (data.qualified_count || 0) + ' монет.', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showFlash('Ошибка: ' + (data.error || 'unknown'), 'danger');
                    }
                })
                .catch(() => {
                    runBtn.disabled = false;
                    runBtn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Запустить';
                    showFlash('Ошибка запроса.', 'danger');
                });
        });
    }

    const searchInput = document.getElementById('symbolSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            const q = this.value.toLowerCase();
            document.querySelectorAll('tr[data-symbol]').forEach(function (row) {
                row.style.display = row.dataset.symbol.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    }
});
</script>
JS;

$pageContent = function () use ($universe, $status, $config, $baseUrl) {

    $wuCfg       = $config['win_universe'] ?? [];
    $minRoi      = $wuCfg['min_roi_threshold']        ?? 1.5;
    $lookback    = $wuCfg['lookback_days']             ?? 30;
    $minTrades   = $wuCfg['min_closed_trades']         ?? 3;
    $minWinrate  = $wuCfg['min_winrate']               ?? 0.0;
    $minTargetRoi = $wuCfg['min_target_roi']           ?? 0.0;
    $minWinsAboveTarget  = (int)($wuCfg['min_wins_above_target']      ?? 0);
    $maxTimeToTarget     = (int)($wuCfg['max_time_to_target_minutes'] ?? 0);

    $computedAt    = $universe['computed_at']        ?? null;
    $qualified     = $universe['qualified']           ?? [];
    $nearQualified = $universe['near_qualified']      ?? [];
    $rejected      = $universe['rejected']            ?? [];
    $symbols       = $universe['symbols']             ?? [];
    $sourcesUsed   = $universe['sources_used']        ?? [];
    $tradeTotal    = $universe['trade_count_total']   ?? 0;
    $sensitivity   = $universe['threshold_sensitivity']         ?? [];
    $candPreview   = $universe['candidate_sensitivity_preview'] ?? [];

    $lastRun  = $status['run_at']  ?? null;
    $lastOk   = $status['ok']      ?? null;
    $mode     = $status['mode']    ?? 'shadow';

    $roiFmt = static function ($v): string {
        if ($v === null) return '<span class="neutral">—</span>';
        // ROI values are stored as decimal fractions (0.01 = 1%). Multiply by 100 for display.
        $pct = (float)$v * 100;
        $cls = $pct >= 0 ? 'positive' : 'negative';
        return '<span class="' . $cls . '">' . number_format($pct, 2) . '%</span>';
    };

    $wrFmt = static function ($v): string {
        if ($v === null) return '<span class="neutral">—</span>';
        $cls = ($v >= 0.5) ? 'positive' : 'negative';
        return '<span class="' . $cls . '">' . number_format((float)$v * 100, 1) . '%</span>';
    };

    $symbolRow = static function (string $sym, array $rec, string $rowClass = '') use ($roiFmt, $wrFmt): void {
        $reason = htmlspecialchars($rec['qualification_reason'] ?? '');
        $lastT  = $rec['last_trade_time'] ? htmlspecialchars(substr($rec['last_trade_time'], 0, 10)) : '—';
        $codes  = $rec['qualification_failure_codes'] ?? [];
        $codesStr = !empty($codes) ? '<small style="color:#f87171; display:block;">' . htmlspecialchars(implode(', ', $codes)) . '</small>' : '';
        $avgSpeed    = $rec['avg_time_to_target_minutes']     ?? null;
        $fastestSpeed = $rec['fastest_time_to_target_minutes'] ?? null;
        $speedStatus = $rec['speed_to_target_status'] ?? 'not_evaluated';
        // Build speed cell: show avg and fastest, color-code by status
        if ($avgSpeed !== null) {
            $speedColor = ($speedStatus === 'fast_enough') ? '#34d399' : '#f87171';
            $speedStr   = '<span style="color:' . $speedColor . ';">' . number_format((float)$avgSpeed, 0) . ' мин.</span>';
            if ($fastestSpeed !== null) {
                $speedStr .= '<small style="color:#64748b;"> (быстрейшая: ' . number_format((float)$fastestSpeed, 0) . ')</small>';
            }
        } elseif ($speedStatus === 'no_valid_samples') {
            $speedStr = '<small style="color:#f87171;">нет данных</small>';
        } elseif ($speedStatus === 'gate_disabled') {
            $speedStr = '<small style="color:#475569;">выкл.</small>';
        } else {
            $speedStr = '—';
        }
        $winsAboveTarget = $rec['wins_above_target'] ?? null;
        echo '<tr data-symbol="' . htmlspecialchars($sym) . '" class="' . $rowClass . '">';
        echo '<td><strong>' . htmlspecialchars($sym) . '</strong></td>';
        echo '<td>' . (int)($rec['closed_trades_window'] ?? 0) . '</td>';
        echo '<td>' . $roiFmt($rec['recent_avg_roi']) . '</td>';
        echo '<td>' . $roiFmt($rec['best_roi']) . '</td>';
        echo '<td>' . $wrFmt($rec['recent_winrate']) . '</td>';
        echo '<td>' . (int)($rec['wins_above_threshold'] ?? 0) . '</td>';
        echo '<td>' . ($winsAboveTarget !== null ? (int)$winsAboveTarget : '—') . '</td>';
        echo '<td>' . $speedStr . '</td>';
        echo '<td>' . $lastT . '</td>';
        echo '<td><small class="text-muted">' . $reason . '</small>' . $codesStr . '</td>';
        echo '</tr>';
    };

    // Near-qualified row includes distance-to-qualify fields
    $nearRow = static function (string $sym, array $rec) use ($roiFmt, $wrFmt): void {
        $reason = htmlspecialchars($rec['qualification_reason'] ?? '');
        $lastT  = $rec['last_trade_time'] ? htmlspecialchars(substr($rec['last_trade_time'], 0, 10)) : '—';

        $distRoi = $rec['distance_to_min_roi_threshold'] ?? null;
        $distWr  = $rec['distance_to_min_winrate']       ?? null;
        $distAvg = $rec['distance_to_min_avg_roi']       ?? null;
        $missT   = (int)($rec['missing_trade_count']     ?? 0);
        $winsNd  = (int)($rec['wins_needed_above_threshold'] ?? 0);
        $tgtWinsNd = (int)($rec['target_wins_needed'] ?? 0);
        $avgSpeed    = $rec['avg_time_to_target_minutes']     ?? null;
        $fastestSpeed = $rec['fastest_time_to_target_minutes'] ?? null;
        $speedStatus = $rec['speed_to_target_status'] ?? 'not_evaluated';
        if ($avgSpeed !== null) {
            $speedColor = ($speedStatus === 'fast_enough') ? '#34d399' : '#f87171';
            $speedStr   = '<span style="color:' . $speedColor . ';">' . number_format((float)$avgSpeed, 0) . ' мин.</span>';
            if ($fastestSpeed !== null) {
                $speedStr .= '<small style="color:#64748b;"> (быстрейшая: ' . number_format((float)$fastestSpeed, 0) . ')</small>';
            }
        } elseif ($speedStatus === 'no_valid_samples') {
            $speedStr = '<small style="color:#f87171;">нет данных</small>';
        } elseif ($speedStatus === 'gate_disabled') {
            $speedStr = '<small style="color:#475569;">выкл.</small>';
        } else {
            $speedStr = '—';
        }
        $winsAboveTarget = $rec['wins_above_target'] ?? null;
        $codes = $rec['qualification_failure_codes'] ?? [];
        $codesStr = !empty($codes) ? '<small style="color:#fbbf24; display:block;">' . htmlspecialchars(implode(', ', $codes)) . '</small>' : '';

        $distParts = [];
        if ($distRoi !== null && $distRoi > 0) {
            $distParts[] = 'ROI+' . number_format((float)$distRoi * 100, 2) . '%';
        }
        if ($distAvg !== null && $distAvg > 0) {
            $distParts[] = 'avgROI+' . number_format((float)$distAvg * 100, 2) . '%';
        }
        if ($distWr !== null && $distWr > 0) {
            $distParts[] = 'WR+' . number_format((float)($distWr * 100), 1) . '%';
        }
        if ($missT > 0) {
            $distParts[] = '+' . $missT . ' сд.';
        }
        if ($winsNd > 0) {
            $distParts[] = '+' . $winsNd . ' побед';
        }
        if ($tgtWinsNd > 0) {
            $distParts[] = '+' . $tgtWinsNd . ' цел.побед';
        }
        $distStr = empty($distParts) ? '—' : implode(', ', $distParts);

        echo '<tr data-symbol="' . htmlspecialchars($sym) . '">';
        echo '<td><strong>' . htmlspecialchars($sym) . '</strong></td>';
        echo '<td>' . (int)($rec['closed_trades_window'] ?? 0) . '</td>';
        echo '<td>' . $roiFmt($rec['recent_avg_roi']) . '</td>';
        echo '<td>' . $roiFmt($rec['best_roi']) . '</td>';
        echo '<td>' . $wrFmt($rec['recent_winrate']) . '</td>';
        echo '<td>' . (int)($rec['wins_above_threshold'] ?? 0) . '</td>';
        echo '<td>' . ($winsAboveTarget !== null ? (int)$winsAboveTarget : '—') . '</td>';
        echo '<td>' . $speedStr . '</td>';
        echo '<td>' . $lastT . '</td>';
        echo '<td><small class="text-muted">' . $reason . '</small>' . $codesStr . '</td>';
        echo '<td><small style="color:#fbbf24;">' . htmlspecialchars($distStr) . '</small></td>';
        echo '</tr>';
    };

    ?>
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">
                <i class="bi bi-trophy text-warning me-2"></i>
                Win Universe
                <span class="badge bg-secondary ms-2"><?= htmlspecialchars($mode) ?></span>
                <span class="badge bg-warning text-dark ms-1">SHADOW</span>
            </h4>
            <p class="text-muted mb-0">
                Теневой наблюдатель — не влияет на торговлю. Только анализ результатов.
            </p>
        </div>
        <div class="d-flex gap-2">
            <button id="runBtn" class="btn btn-primary btn-sm">
                <i class="bi bi-play-fill me-1"></i>Запустить
            </button>
        </div>
    </div>

    <!-- Status bar -->
    <?php if ($lastRun): ?>
    <div class="alert alert-secondary py-2 mb-3">
        <small>
            <i class="bi bi-clock me-1"></i>
            Последний запуск: <strong><?= htmlspecialchars($lastRun) ?></strong>
            <?php if ($lastOk === true): ?>
                <span class="badge bg-success ms-2">OK</span>
            <?php elseif ($lastOk === false): ?>
                <span class="badge bg-danger ms-2">Ошибка</span>
            <?php endif; ?>
            &nbsp;|&nbsp; Источники: <?= htmlspecialchars(implode(', ', $sourcesUsed) ?: '—') ?>
            &nbsp;|&nbsp; Сделок в окне: <?= (int)$tradeTotal ?>
        </small>
    </div>
    <?php endif; ?>

    <!-- Config thresholds -->
    <div class="card mb-4">
        <div class="card-header py-2">
            <small class="fw-semibold text-muted">Текущие пороги квалификации</small>
        </div>
        <div class="card-body py-2">
            <div class="row g-3">
                <div class="col-auto">
                    <span class="text-muted">Мин. ROI:</span>
                    <strong><?= number_format((float)$minRoi * 100, 2) ?>%</strong>
                </div>
                <div class="col-auto">
                    <span class="text-muted">Окно:</span>
                    <strong><?= (int)$lookback ?> дн.</strong>
                </div>
                <div class="col-auto">
                    <span class="text-muted">Мин. сделок:</span>
                    <strong><?= (int)$minTrades ?></strong>
                </div>
                <div class="col-auto">
                    <span class="text-muted">Мин. winrate:</span>
                    <strong><?= $minWinrate > 0 ? number_format((float)$minWinrate * 100, 1) . '%' : 'выкл.' ?></strong>
                </div>
                <?php if ($minTargetRoi > 0): ?>
                <div class="col-auto">
                    <span class="text-muted">Цель ROI:</span>
                    <strong><?= number_format((float)$minTargetRoi * 100, 2) ?>%</strong>
                    <?php if ($minWinsAboveTarget > 0): ?>
                    <span class="text-secondary">(мин. <?= $minWinsAboveTarget ?> побед)</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if ($maxTimeToTarget > 0): ?>
                <div class="col-auto">
                    <span class="text-muted">Макс. скорость:</span>
                    <strong><?= $maxTimeToTarget ?> мин.</strong>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Stats cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-success"><?= count($qualified) ?></div>
                <div class="stat-label">✅ Квалифицировано</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-warning"><?= count($nearQualified) ?></div>
                <div class="stat-label">🔶 Почти квалифицировано</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-secondary"><?= count($rejected) ?></div>
                <div class="stat-label">❌ Отклонено</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value"><?= count($symbols) ?></div>
                <div class="stat-label">Всего монет</div>
            </div>
        </div>
    </div>

    <?php if (empty($symbols)): ?>
    <div class="alert alert-secondary">
        Нет данных. Нажмите <strong>Запустить</strong> для первого вычисления.
    </div>
    <?php else: ?>

    <!-- Threshold diagnostics panel -->
    <?php if (!empty($sensitivity)): ?>
    <div class="card mb-4">
        <div class="card-header py-2">
            <small class="fw-semibold" style="color:#94a3b8; text-transform:uppercase; letter-spacing:.05em;">
                <i class="bi bi-bar-chart-fill me-1 text-info"></i>Диагностика порогов — почему квалифицировано = <?= count($qualified) ?>
            </small>
        </div>
        <div class="card-body py-2">
            <div class="row g-2 mb-2">
                <?php
                $diagItems = [
                    ['label' => 'Мало сделок',      'key' => 'failed_by_min_trades_count',    'color' => '#f87171'],
                    ['label' => 'Мало ROI',          'key' => 'failed_by_roi_threshold_count', 'color' => '#fb923c'],
                    ['label' => 'Мало avg ROI',      'key' => 'failed_by_avg_roi_count',       'color' => '#facc15'],
                    ['label' => 'Мало winrate',      'key' => 'failed_by_winrate_count',       'color' => '#a78bfa'],
                    ['label' => 'Мало цел.побед',    'key' => 'failed_by_target_wins_count',   'color' => '#f472b6'],
                    ['label' => 'Медленно к цели',   'key' => 'failed_by_speed_count',         'color' => '#94a3b8'],
                ];
                foreach ($diagItems as $di):
                    $val = (int)($sensitivity[$di['key']] ?? 0);
                    if ($val === 0) continue;
                ?>
                <div class="col-auto">
                    <div style="background:rgba(30,41,59,0.7); border:1px solid #334155; border-radius:6px; padding:6px 12px; text-align:center;">
                        <div style="font-size:1.3rem; font-weight:700; color:<?= $di['color'] ?>;"><?= $val ?></div>
                        <div style="font-size:0.72rem; color:#94a3b8;"><?= htmlspecialchars($di['label']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php
                $onlyRoi = (int)($sensitivity['fail_by_roi_only'] ?? 0);
                if ($onlyRoi > 0):
                ?>
                <div class="col-auto">
                    <div style="background:rgba(30,41,59,0.7); border:1px solid #334155; border-radius:6px; padding:6px 12px; text-align:center;">
                        <div style="font-size:1.3rem; font-weight:700; color:#34d399;"><?= $onlyRoi ?></div>
                        <div style="font-size:0.72rem; color:#94a3b8;">Только ROI мешает</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php if (!empty($candPreview)): ?>
            <div class="mt-2">
                <small class="fw-semibold" style="color:#94a3b8;">Чувствительность порогов:</small>
                <div class="d-flex flex-wrap gap-2 mt-1">
                <?php foreach ($candPreview as $key => $sc): ?>
                    <span class="badge" style="background:rgba(51,65,85,0.9); border:1px solid #475569; font-size:0.75rem; padding:4px 8px;">
                        <?= htmlspecialchars($sc['label'] ?? $key) ?>:
                        <strong style="color:<?= ((int)($sc['qualified_count'] ?? 0)) > 0 ? '#34d399' : '#f87171' ?>;">
                            <?= (int)($sc['qualified_count'] ?? 0) ?>
                        </strong> квал.
                        (ROI≥<?= number_format((float)($sc['min_roi_threshold'] ?? 0) * 100, 2) ?>%,
                         ≥<?= (int)($sc['min_closed_trades'] ?? 0) ?> сд.)
                    </span>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; // sensitivity ?>

    <!-- Symbol search -->
    <div class="mb-3">
        <input type="text" id="symbolSearch" class="form-control form-control-sm w-auto d-inline-block"
               placeholder="Поиск монеты…" style="min-width:180px">
    </div>

    <!-- Table header helper -->
    <?php
    $tableHeader = static function (bool $withDist = false): void {
        echo '<thead><tr class="table-dark">';
        echo '<th>Монета</th>';
        echo '<th>Сделок (окно)</th>';
        echo '<th>Ср. ROI</th>';
        echo '<th>Лучший ROI</th>';
        echo '<th>Winrate</th>';
        echo '<th>Побед &gt; порога</th>';
        echo '<th>Побед &gt; цели</th>';
        echo '<th>Avg скорость</th>';
        echo '<th>Последняя сделка</th>';
        echo '<th>Причина</th>';
        if ($withDist) {
            echo '<th style="color:#fbbf24;">До порога</th>';
        }
        echo '</tr></thead>';
    };
    ?>

    <!-- Qualified -->
    <?php if (!empty($qualified)): ?>
    <div class="card mb-4">
        <div class="card-header py-2">
            <span class="fw-semibold text-success">
                <i class="bi bi-check-circle-fill me-1"></i>
                Квалифицированные монеты (<?= count($qualified) ?>)
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-dark table-sm table-hover mb-0">
                <?php $tableHeader(); ?>
                <tbody>
                    <?php foreach ($qualified as $sym): ?>
                        <?php $symbolRow($sym, $symbols[$sym] ?? []); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Near-qualified -->
    <?php if (!empty($nearQualified)): ?>
    <div class="card mb-4">
        <div class="card-header py-2">
            <span class="fw-semibold text-warning">
                <i class="bi bi-exclamation-circle-fill me-1"></i>
                Почти квалифицировано (<?= count($nearQualified) ?>)
            </span>
            <small class="text-muted ms-2">— не прошли один мягкий критерий</small>
        </div>
        <div class="table-responsive">
            <table class="table table-dark table-sm table-hover mb-0">
                <?php $tableHeader(true); ?>
                <tbody>
                    <?php foreach ($nearQualified as $sym): ?>
                        <?php $nearRow($sym, $symbols[$sym] ?? []); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Rejected -->
    <?php if (!empty($rejected)): ?>
    <div class="card mb-4">
        <div class="card-header py-2">
            <span class="fw-semibold text-secondary">
                <i class="bi bi-x-circle-fill me-1"></i>
                Отклонённые монеты (<?= count($rejected) ?>)
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-dark table-sm table-hover mb-0">
                <?php $tableHeader(); ?>
                <tbody>
                    <?php foreach ($rejected as $sym): ?>
                        <?php $symbolRow($sym, $symbols[$sym] ?? [], 'opacity-75'); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // empty($symbols) ?>

    <?php if ($computedAt): ?>
    <p class="text-muted small mt-2">
        <i class="bi bi-info-circle me-1"></i>
        Данные вычислены: <?= htmlspecialchars($computedAt) ?>.
        Модуль работает в теневом режиме — торговля не затронута.
    </p>
    <?php endif; ?>

    <?php
};

require __DIR__ . '/_layout.php';
