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
    $minRoi      = $wuCfg['min_roi_threshold'] ?? 1.5;
    $lookback    = $wuCfg['lookback_days']     ?? 30;
    $minTrades   = $wuCfg['min_closed_trades'] ?? 3;
    $minWinrate  = $wuCfg['min_winrate']       ?? 0.0;

    $computedAt    = $universe['computed_at']        ?? null;
    $qualified     = $universe['qualified']           ?? [];
    $nearQualified = $universe['near_qualified']      ?? [];
    $rejected      = $universe['rejected']            ?? [];
    $symbols       = $universe['symbols']             ?? [];
    $sourcesUsed   = $universe['sources_used']        ?? [];
    $tradeTotal    = $universe['trade_count_total']   ?? 0;

    $lastRun  = $status['run_at']  ?? null;
    $lastOk   = $status['ok']      ?? null;
    $mode     = $status['mode']    ?? 'shadow';

    $roiFmt = static function ($v): string {
        if ($v === null) return '<span class="neutral">—</span>';
        $cls = $v >= 0 ? 'positive' : 'negative';
        return '<span class="' . $cls . '">' . number_format((float)$v, 2) . '%</span>';
    };

    $wrFmt = static function ($v): string {
        if ($v === null) return '<span class="neutral">—</span>';
        $cls = ($v >= 0.5) ? 'positive' : 'negative';
        return '<span class="' . $cls . '">' . number_format((float)$v * 100, 1) . '%</span>';
    };

    $symbolRow = static function (string $sym, array $rec, string $rowClass = '') use ($roiFmt, $wrFmt): void {
        $reason = htmlspecialchars($rec['qualification_reason'] ?? '');
        $lastT  = $rec['last_trade_time'] ? htmlspecialchars(substr($rec['last_trade_time'], 0, 10)) : '—';
        echo '<tr data-symbol="' . htmlspecialchars($sym) . '" class="' . $rowClass . '">';
        echo '<td><strong>' . htmlspecialchars($sym) . '</strong></td>';
        echo '<td>' . (int)($rec['closed_trades_window'] ?? 0) . '</td>';
        echo '<td>' . $roiFmt($rec['recent_avg_roi']) . '</td>';
        echo '<td>' . $roiFmt($rec['best_roi']) . '</td>';
        echo '<td>' . $wrFmt($rec['recent_winrate']) . '</td>';
        echo '<td>' . (int)($rec['wins_above_threshold'] ?? 0) . '</td>';
        echo '<td>' . $lastT . '</td>';
        echo '<td><small class="text-muted">' . $reason . '</small></td>';
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
                    <strong><?= number_format((float)$minRoi, 2) ?>%</strong>
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

    <!-- Symbol search -->
    <div class="mb-3">
        <input type="text" id="symbolSearch" class="form-control form-control-sm w-auto d-inline-block"
               placeholder="Поиск монеты…" style="min-width:180px">
    </div>

    <!-- Table header helper -->
    <?php
    $tableHeader = static function (): void {
        echo '<thead><tr class="table-dark">';
        echo '<th>Монета</th>';
        echo '<th>Сделок (окно)</th>';
        echo '<th>Ср. ROI</th>';
        echo '<th>Лучший ROI</th>';
        echo '<th>Winrate</th>';
        echo '<th>Побед &gt; порога</th>';
        echo '<th>Последняя сделка</th>';
        echo '<th>Причина</th>';
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
                <?php $tableHeader(); ?>
                <tbody>
                    <?php foreach ($nearQualified as $sym): ?>
                        <?php $symbolRow($sym, $symbols[$sym] ?? []); ?>
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
