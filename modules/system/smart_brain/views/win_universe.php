<?php
/**
 * Smart Brain — Win Universe Shadow Analytics Page
 *
 * Mounted at /admin/smart_brain/win_universe.
 * Reads runtime data from the standalone win_universe module.
 * SHADOW ONLY — no trading influence.
 *
 * @var string                   $smartBrainUrl
 * @var array<string,mixed>|null $universe          win_universe.json or null
 * @var array<string,mixed>|null $status            win_universe_status.json or null
 * @var array<string,mixed>|null $pool              win_universe_pool.json or null
 * @var array<string,mixed>|null $promotions        win_universe_promotions.json or null
 * @var array<string,mixed>|null $demotions         win_universe_demotions.json or null
 * @var array<string,mixed>      $config            win_universe module config
 * @var array<string,mixed>|null $evalData          win_universe_eval.json or null
 * @var array<string,mixed>|null $evalByEntryStatus win_universe_eval_by_entry_status.json or null
 * @var array<string,mixed>|null $bonusEvalAtEntry  win_universe_bonus_eval_at_entry.json or null
 */

$pageTitle = 'Win Universe — Выигрышные монеты';
$activeTab = 'win_universe';

// Null-safe initialization for variables that may not be set in older controller versions
$pool              ??= null;
$promotions        ??= null;
$demotions         ??= null;
$evalData          ??= null;
$evalByEntryStatus ??= null;
$bonusEvalAtEntry  ??= null;

$extraStyles = '
.wu-stat-card { background: #1e293b; border: 1px solid #334155; border-radius: 8px;
                padding: 1rem; text-align: center; }
.wu-stat-value { font-size: 1.6rem; font-weight: 700; }
.wu-stat-label { font-size: 0.75rem; color: #94a3b8; margin-top: 0.2rem; }
.positive { color: #4ade80; }
.negative { color: #f87171; }
.neutral  { color: #94a3b8; }
.text-purple { color: #a78bfa; }
.badge-qualified    { background: rgba(16,185,129,0.2); color: #34d399; border: 1px solid rgba(16,185,129,0.4); }
.badge-near         { background: rgba(245,158,11,0.2); color: #fbbf24; border: 1px solid rgba(245,158,11,0.4); }
.badge-rejected     { background: rgba(100,116,139,0.2); color: #94a3b8; border: 1px solid rgba(100,116,139,0.3); }
.badge-pool         { background: rgba(139,92,246,0.2);  color: #a78bfa; border: 1px solid rgba(139,92,246,0.4); }
.badge-promoted     { background: rgba(16,185,129,0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.3); }
.badge-demoted      { background: rgba(239,68,68,0.15);  color: #f87171; border: 1px solid rgba(239,68,68,0.3); }
.shadow-banner { background: rgba(245,158,11,0.08); border: 1px solid rgba(245,158,11,0.3);
                 border-radius: 6px; padding: 0.5rem 0.9rem; font-size: 0.82rem; color: #fbbf24; }
';

$extraScripts = <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', function () {
    const runBtn = document.getElementById('wuRunBtn');
    if (runBtn) {
        runBtn.addEventListener('click', function () {
            runBtn.disabled = true;
            runBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Вычисление…';
            fetch('/admin/smart_brain/win_universe/run', { method: 'POST' })
                .then(r => r.json())
                .then(data => {
                    runBtn.disabled = false;
                    runBtn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Запустить';
                    if (data.ok) {
                        location.reload();
                    } else {
                        alert('Ошибка: ' + (data.error || 'unknown'));
                    }
                })
                .catch(() => {
                    runBtn.disabled = false;
                    runBtn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Запустить';
                    alert('Ошибка запроса.');
                });
        });
    }

    const searchInput = document.getElementById('wuSearch');
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

$pageContent = function () use ($universe, $status, $config, $smartBrainUrl, $pool, $promotions, $demotions, $evalData, $evalByEntryStatus, $bonusEvalAtEntry) {

    $wuCfg      = $config['win_universe'] ?? [];
    $minRoi     = $wuCfg['min_roi_threshold'] ?? 1.5;
    $minAvgRoi  = $wuCfg['min_avg_roi']       ?? 0.0;
    $lookback   = $wuCfg['lookback_days']      ?? 30;
    $minTrades  = $wuCfg['min_closed_trades']  ?? 3;
    $minWinrate = $wuCfg['min_winrate']        ?? 0.0;
    $expiryDays = $wuCfg['qualification_expiry_days'] ?? 90;
    $lossStreak = $wuCfg['demotion_loss_streak'] ?? 0;
    $mode       = $wuCfg['win_universe_mode']  ?? 'shadow';

    $qualified     = $universe['qualified']         ?? [];
    $nearQualified = $universe['near_qualified']    ?? [];
    $rejected      = $universe['rejected']          ?? [];
    $symbols       = $universe['symbols']           ?? [];
    $winPool       = $universe['win_pool']          ?? ($pool['win_pool'] ?? []);
    $sourcesUsed   = $universe['sources_used']      ?? [];
    $tradeTotal    = $universe['trade_count_total'] ?? 0;
    $computedAt    = $universe['computed_at']       ?? null;
    $sensitivity   = $universe['threshold_sensitivity'] ?? ($status['threshold_sensitivity'] ?? []);

    $lastRun = $status['run_at']  ?? null;
    $lastOk  = $status['ok']      ?? null;

    $promotionEvents = $promotions['events'] ?? [];
    $demotionEvents  = $demotions['events']  ?? [];

    $roiFmt = static function ($v): string {
        if ($v === null) {
            return '<span class="neutral">—</span>';
        }
        $cls = (float)$v >= 0 ? 'positive' : 'negative';
        // ROI values are stored as decimal fractions (0.01 = 1%). Multiply by 100 for display.
        return '<span class="' . $cls . '">' . number_format((float)$v * 100, 2) . '%</span>';
    };

    $wrFmt = static function ($v): string {
        if ($v === null) {
            return '<span class="neutral">—</span>';
        }
        $cls = (float)$v >= 0.5 ? 'positive' : 'negative';
        return '<span class="' . $cls . '">' . number_format((float)$v * 100, 1) . '%</span>';
    };

    $statusBadge = static function (string $s, bool $inPool = false): string {
        if ($inPool) {
            return '<span class="badge badge-pool">🏆 Win Pool</span>';
        }
        return match ($s) {
            'qualified'      => '<span class="badge badge-qualified">✅ Квалифицирован</span>',
            'near_qualified' => '<span class="badge badge-near">🔶 Почти</span>',
            default          => '<span class="badge badge-rejected">❌ Отклонён</span>',
        };
    };

    $symbolRow = static function (string $sym, array $rec) use ($roiFmt, $wrFmt, $statusBadge, $winPool): void {
        $reason  = htmlspecialchars($rec['qualification_reason'] ?? '');
        $lastT   = ($rec['last_trade_time'] ?? '') ? htmlspecialchars(substr((string)$rec['last_trade_time'], 0, 10)) : '—';
        $qStatus = (string)($rec['qualification_status'] ?? 'rejected');
        $inPool  = isset($winPool[$sym]);
        $promoReason = htmlspecialchars($rec['promotion_reason'] ?? '');
        echo '<tr data-symbol="' . htmlspecialchars($sym) . '">';
        echo '<td><strong>' . htmlspecialchars($sym) . '</strong></td>';
        echo '<td>' . $statusBadge($qStatus, $inPool) . '</td>';
        echo '<td>' . (int)($rec['closed_trades_window'] ?? 0) . '</td>';
        echo '<td>' . $roiFmt($rec['recent_avg_roi'] ?? null) . '</td>';
        echo '<td>' . $roiFmt($rec['best_roi'] ?? null) . '</td>';
        echo '<td>' . $wrFmt($rec['recent_winrate'] ?? null) . '</td>';
        echo '<td>' . (int)($rec['wins_above_threshold'] ?? 0) . '</td>';
        echo '<td>' . $lastT . '</td>';
        echo '<td><small class="text-secondary">' . $reason . ($promoReason ? '<br><span class="text-purple">🏆 ' . $promoReason . '</span>' : '') . '</small></td>';
        echo '</tr>';
    };

    ?>

    <!-- Shadow-mode banner -->
    <div class="shadow-banner mb-4">
        <i class="bi bi-shield-check me-1"></i>
        <strong>Теневой режим (Shadow Only)</strong> — Win Universe работает только для наблюдения.
        Данный модуль не влияет на торговлю, приоритеты монет и маршрутизацию сигналов.
        Текущий режим: <code><?= htmlspecialchars($mode) ?></code>
    </div>

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">
                <i class="bi bi-trophy text-warning me-2"></i>Win Universe
                <span class="badge bg-warning text-dark ms-2" style="font-size:0.65rem;">SHADOW</span>
            </h4>
            <p class="text-secondary mb-0">
                Квалификация монет по историческим сделкам — только аналитика, без влияния на торговлю
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="/admin/smart_brain/config_all/win_universe" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-gear me-1"></i>Настройки
            </a>
            <button id="wuRunBtn" class="btn btn-primary btn-sm">
                <i class="bi bi-play-fill me-1"></i>Запустить
            </button>
        </div>
    </div>

    <!-- Last run status bar -->
    <?php if ($lastRun): ?>
    <div class="alert alert-secondary py-2 mb-3" style="font-size:0.82rem;">
        <i class="bi bi-clock me-1"></i>
        Последний запуск: <strong><?= htmlspecialchars($lastRun) ?></strong>
        <?php if ($lastOk === true): ?>
            <span class="badge bg-success ms-2">OK</span>
        <?php elseif ($lastOk === false): ?>
            <span class="badge bg-danger ms-2">Ошибка</span>
        <?php endif; ?>
        &nbsp;|&nbsp; Источники: <?= htmlspecialchars(implode(', ', $sourcesUsed) ?: '—') ?>
        &nbsp;|&nbsp; Сделок в окне: <strong><?= (int)$tradeTotal ?></strong>
    </div>
    <?php endif; ?>

    <!-- Thresholds card -->
    <div class="card mb-4">
        <div class="card-header py-2">
            <small class="fw-semibold text-secondary">Текущие пороги квалификации</small>
        </div>
        <div class="card-body py-2">
            <div class="d-flex flex-wrap gap-4">
                <div>
                    <span class="text-secondary">Мин. ROI (порог):</span>
                    <strong><?= number_format((float)$minRoi * 100, 2) ?>%</strong>
                </div>
                <div>
                    <span class="text-secondary">Мин. средний ROI:</span>
                    <strong><?= $minAvgRoi > 0.0 ? number_format((float)$minAvgRoi * 100, 2) . '%' : 'выкл.' ?></strong>
                </div>
                <div>
                    <span class="text-secondary">Окно:</span>
                    <strong><?= (int)$lookback ?> дн.</strong>
                </div>
                <div>
                    <span class="text-secondary">Мин. сделок:</span>
                    <strong><?= (int)$minTrades ?></strong>
                </div>
                <div>
                    <span class="text-secondary">Мин. winrate:</span>
                    <strong><?= $minWinrate > 0.0 ? number_format((float)$minWinrate * 100, 1) . '%' : 'выкл.' ?></strong>
                </div>
                <?php if ($expiryDays > 0): ?>
                <div>
                    <span class="text-secondary">Срок действия квалификации:</span>
                    <strong><?= (int)$expiryDays ?> дн.</strong>
                </div>
                <?php endif; ?>
                <?php if ($lossStreak > 0): ?>
                <div>
                    <span class="text-secondary">Деквалификация при серии убытков:</span>
                    <strong><?= (int)$lossStreak ?></strong>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Summary stat cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-2">
            <div class="wu-stat-card">
                <div class="wu-stat-value text-success"><?= count($qualified) ?></div>
                <div class="wu-stat-label">✅ Квалифицировано</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="wu-stat-card">
                <div class="wu-stat-value text-warning"><?= count($nearQualified) ?></div>
                <div class="wu-stat-label">🔶 Почти</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="wu-stat-card">
                <div class="wu-stat-value text-secondary"><?= count($rejected) ?></div>
                <div class="wu-stat-label">❌ Отклонено</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="wu-stat-card">
                <div class="wu-stat-value" style="color:#a78bfa;"><?= count($winPool) ?></div>
                <div class="wu-stat-label">🏆 Win Pool</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="wu-stat-card">
                <div class="wu-stat-value text-success"><?= count($promotionEvents) ?></div>
                <div class="wu-stat-label">⬆ Повышений</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="wu-stat-card">
                <div class="wu-stat-value text-danger"><?= count($demotionEvents) ?></div>
                <div class="wu-stat-label">⬇ Понижений</div>
            </div>
        </div>
    </div>

    <!-- Evaluation / Attribution Panel -->
    <?php
    $evalGroups = $evalData['groups'] ?? null;
    if ($evalGroups !== null):
        $evalAttr = $evalData['attribution_note'] ?? '';
        $evalTs   = $evalData['computed_at'] ?? null;

        $evalRoiFmt = static function ($v): string {
            if ($v === null) return '<span class="neutral">—</span>';
            $cls = (float)$v >= 0 ? 'positive' : 'negative';
            return '<span class="' . $cls . '">' . number_format((float)$v * 100, 2) . '%</span>';
        };
        $evalWrFmt = static function ($v): string {
            if ($v === null) return '<span class="neutral">—</span>';
            $cls = (float)$v >= 0.5 ? 'positive' : 'negative';
            return '<span class="' . $cls . '">' . number_format((float)$v * 100, 1) . '%</span>';
        };

        $renderGroup = static function (string $key, string $label, string $colorClass) use ($evalGroups, $evalRoiFmt, $evalWrFmt): void {
            $g = $evalGroups[$key] ?? null;
            if ($g === null) return;
            $tc  = (int)($g['trade_count'] ?? 0);
            $wc  = (int)($g['win_count']   ?? 0);
            $lc  = (int)($g['loss_count']  ?? 0);
            $wr  = $g['winrate']   ?? null;
            $ar  = $g['avg_roi']   ?? null;
            $mr  = $g['median_roi'] ?? null;
            $sym = (int)($g['symbol_count'] ?? 0);
            $warn = (bool)($g['small_sample_warning'] ?? false);
            echo '<td class="' . $colorClass . ' fw-semibold">' . htmlspecialchars($label) . '</td>';
            echo '<td>' . $sym . '</td>';
            echo '<td>' . $tc . ($warn ? ' <span class="badge bg-warning text-dark" title="Мало данных">!</span>' : '') . '</td>';
            echo '<td>' . $wc . '</td>';
            echo '<td>' . $lc . '</td>';
            echo '<td>' . $evalWrFmt($wr) . '</td>';
            echo '<td>' . $evalRoiFmt($ar) . '</td>';
            echo '<td>' . $evalRoiFmt($mr) . '</td>';
        };
    ?>
    <div class="card mb-4">
        <div class="card-header py-2 d-flex align-items-center justify-content-between">
            <span class="fw-semibold text-info">
                <i class="bi bi-bar-chart-line me-1"></i>
                Оценка по <strong>текущему</strong> статусу квалификации
                <span class="badge bg-secondary ms-1" style="font-size:0.65rem; font-weight:400;">ПРИБЛИЖЕНИЕ</span>
            </span>
            <?php if ($evalTs): ?>
            <small class="text-secondary"><?= htmlspecialchars($evalTs) ?></small>
            <?php endif; ?>
        </div>
        <div class="card-body pb-2 pt-2">
            <p class="text-secondary mb-2" style="font-size:0.78rem;">
                <i class="bi bi-info-circle me-1"></i>
                <?= htmlspecialchars($evalAttr) ?>
                Значок <span class="badge bg-warning text-dark">!</span> означает &lt;10 сделок — данные ненадёжны.
            </p>
            <div class="table-responsive">
                <table class="table table-dark table-sm mb-0">
                    <thead>
                        <tr class="table-dark">
                            <th>Класс</th>
                            <th>Монет</th>
                            <th>Сделок</th>
                            <th>Побед</th>
                            <th>Потерь</th>
                            <th>Winrate</th>
                            <th>Ср. ROI</th>
                            <th>Медиан. ROI</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><?php $renderGroup('qualified',      '✅ Квалифицированные', 'text-success'); ?></tr>
                        <tr><?php $renderGroup('near_qualified', '🔶 Почти', 'text-warning'); ?></tr>
                        <tr><?php $renderGroup('rejected',       '❌ Отклонённые', 'text-secondary'); ?></tr>
                        <tr style="border-top:2px solid #334155;">
                            <?php $renderGroup('in_win_pool', '🏆 Win Pool', 'text-purple'); ?>
                        </tr>
                        <tr><?php $renderGroup('not_in_pool',    '— Вне пула', 'text-secondary'); ?></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; // evalGroups ?>

    <!-- Оценка по статусу НА МОМЕНТ ВХОДА (entry-status attribution) -->
    <?php
    $entryGroups      = $evalByEntryStatus['groups']       ?? null;
    $entryBonusGroups = $bonusEvalAtEntry['bonus_groups']  ?? null;
    $entryAttrNote    = $evalByEntryStatus['attribution_note']
                     ?? ($bonusEvalAtEntry['attribution_note'] ?? '');
    $entryTs          = $evalByEntryStatus['computed_at']
                     ?? ($bonusEvalAtEntry['computed_at'] ?? null);
    $entryLogSize     = (int)($bonusEvalAtEntry['intent_log_size']
                     ?? ($evalByEntryStatus['intent_log_size'] ?? 0));

    if ($entryGroups !== null || $entryBonusGroups !== null):
        $entryRoiFmt = static function ($v): string {
            if ($v === null) return '<span class="neutral">—</span>';
            $cls = (float)$v >= 0 ? 'positive' : 'negative';
            return '<span class="' . $cls . '">' . number_format((float)$v * 100, 2) . '%</span>';
        };
        $entryWrFmt = static function ($v): string {
            if ($v === null) return '<span class="neutral">—</span>';
            $cls = (float)$v >= 0.5 ? 'positive' : 'negative';
            return '<span class="' . $cls . '">' . number_format((float)$v * 100, 1) . '%</span>';
        };
        $renderEntryGroup = static function (string $key, string $label, string $colorClass, array $groups) use ($entryRoiFmt, $entryWrFmt): void {
            $g = $groups[$key] ?? null;
            if ($g === null) return;
            $tc   = (int)($g['trade_count'] ?? 0);
            $wc   = (int)($g['win_count']   ?? 0);
            $lc   = (int)($g['loss_count']  ?? 0);
            $wr   = $g['winrate']   ?? null;
            $ar   = $g['avg_roi']   ?? null;
            $mr   = $g['median_roi'] ?? null;
            $warn = (bool)($g['small_sample_warning'] ?? ($tc < 10));
            echo '<td class="' . $colorClass . ' fw-semibold">' . htmlspecialchars($label) . '</td>';
            echo '<td>' . $tc . ($warn ? ' <span class="badge bg-warning text-dark" title="' . htmlspecialchars($g['sample_note'] ?? 'Мало данных') . '">!</span>' : '') . '</td>';
            echo '<td>' . $wc . '</td>';
            echo '<td>' . $lc . '</td>';
            echo '<td>' . $entryWrFmt($wr) . '</td>';
            echo '<td>' . $entryRoiFmt($ar) . '</td>';
            echo '<td>' . $entryRoiFmt($mr) . '</td>';
        };
    ?>
    <div class="card mb-4">
        <div class="card-header py-2 d-flex align-items-center justify-content-between">
            <span class="fw-semibold" style="color:#7dd3fc;">
                <i class="bi bi-pin-angle me-1"></i>
                Оценка по статусу <strong>на момент входа</strong> (entry-time attribution)
            </span>
            <div class="d-flex align-items-center gap-3">
                <?php if ($entryLogSize > 0): ?>
                <small class="text-secondary">Журнал атрибуции: <?= $entryLogSize ?> записей</small>
                <?php endif; ?>
                <?php if ($entryTs): ?>
                <small class="text-secondary"><?= htmlspecialchars($entryTs) ?></small>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body pb-2 pt-2">
            <p class="text-secondary mb-2" style="font-size:0.78rem;">
                <i class="bi bi-info-circle me-1"></i>
                <?= htmlspecialchars($entryAttrNote) ?>
                Значок <span class="badge bg-warning text-dark">!</span> — &lt;10 сделок, данные ненадёжны.
            </p>

            <?php if ($entryLogSize === 0): ?>
            <div class="alert alert-warning py-2 mb-2" style="font-size:0.82rem;">
                <i class="bi bi-exclamation-triangle me-1"></i>
                Журнал атрибуции пуст. Данные появятся после следующего запуска Smart Brain с новой версией кода.
            </div>
            <?php endif; ?>

            <?php if ($entryGroups !== null): ?>
            <p class="fw-semibold mb-1" style="font-size:0.82rem; color:#7dd3fc;">По статусу квалификации на момент входа:</p>
            <div class="table-responsive mb-3">
                <table class="table table-dark table-sm mb-0">
                    <thead>
                        <tr class="table-dark">
                            <th>Статус при входе</th>
                            <th>Сделок</th>
                            <th>Побед</th>
                            <th>Потерь</th>
                            <th>Winrate</th>
                            <th>Ср. ROI</th>
                            <th>Медиан. ROI</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><?php $renderEntryGroup('qualified_at_entry',    '✅ Квалифицирован при входе',    'text-success',   $entryGroups); ?></tr>
                        <tr style="border-top:1px solid #334155;">
                            <?php $renderEntryGroup('nonqualified_at_entry', '❌ Не квалифицирован (итого)', 'text-secondary', $entryGroups); ?>
                        </tr>
                        <?php
                        // Granular sub-buckets (shown only if present and non-zero)
                        $subBuckets = [
                            'not_in_pool_at_entry'  => '&nbsp;&nbsp;↳ Вне пула',
                            'pool_empty_at_entry'   => '&nbsp;&nbsp;↳ Пул пуст при входе',
                            'shadow_mode_at_entry'  => '&nbsp;&nbsp;↳ Теневой режим / бонус выкл.',
                        ];
                        foreach ($subBuckets as $bKey => $bLabel):
                            $bG = $entryGroups[$bKey] ?? null;
                            if ($bG === null || ($bG['trade_count'] ?? 0) === 0) continue;
                        ?>
                        <tr style="opacity:0.75;">
                            <?php
                            $bTc  = (int)($bG['trade_count'] ?? 0);
                            $bWc  = (int)($bG['win_count']   ?? 0);
                            $bLc  = (int)($bG['loss_count']  ?? 0);
                            $bWr  = $bG['winrate']    ?? null;
                            $bAr  = $bG['avg_roi']    ?? null;
                            $bMr  = $bG['median_roi'] ?? null;
                            $bWarn = (bool)($bG['small_sample_warning'] ?? ($bTc < 10));
                            echo '<td class="text-secondary" style="padding-left:1.2rem;">' . $bLabel . '</td>';
                            echo '<td>' . $bTc . ($bWarn ? ' <span class="badge bg-warning text-dark" title="Мало данных">!</span>' : '') . '</td>';
                            echo '<td>' . $bWc . '</td>';
                            echo '<td>' . $bLc . '</td>';
                            echo '<td>' . $entryWrFmt($bWr) . '</td>';
                            echo '<td>' . $entryRoiFmt($bAr) . '</td>';
                            echo '<td>' . $entryRoiFmt($bMr) . '</td>';
                            ?>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="border-top:2px solid #334155;">
                            <?php $renderEntryGroup('no_attribution', '❓ Без атрибуции', 'text-secondary', $entryGroups); ?>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if ($entryBonusGroups !== null): ?>
            <p class="fw-semibold mb-1" style="font-size:0.82rem; color:#c084fc;">По применению бонуса при входе:</p>
            <div class="table-responsive">
                <table class="table table-dark table-sm mb-0">
                    <thead>
                        <tr class="table-dark">
                            <th>Бонус при входе</th>
                            <th>Сделок</th>
                            <th>Побед</th>
                            <th>Потерь</th>
                            <th>Winrate</th>
                            <th>Ср. ROI</th>
                            <th>Медиан. ROI</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><?php $renderEntryGroup('bonus_applied',          '🎯 Бонус применён',               'text-purple',    $entryBonusGroups); ?></tr>
                        <tr><?php $renderEntryGroup('bonus_not_applied',      '— Бонус не применён',             'text-secondary', $entryBonusGroups); ?></tr>
                        <?php
                        $bUnG = $entryBonusGroups['bonus_used_not_applied'] ?? null;
                        if ($bUnG !== null && ($bUnG['trade_count'] ?? 0) > 0):
                        ?>
                        <tr><?php $renderEntryGroup('bonus_used_not_applied', '⚡ Бонус рассчитан, не применён', 'text-warning',   $entryBonusGroups); ?></tr>
                        <?php endif; ?>
                        <tr style="border-top:2px solid #334155;">
                            <?php $renderEntryGroup('no_attribution',         '❓ Без атрибуции',                'text-secondary', $entryBonusGroups); ?>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; // entryGroups or entryBonusGroups ?>

    <!-- Win Pool -->
    <?php if (!empty($winPool)): ?>
    <div class="card mb-4">
        <div class="card-header py-2">
            <span class="fw-semibold" style="color:#a78bfa;">
                <i class="bi bi-trophy-fill me-1"></i>
                Win Pool — монеты в пуле победителей (<?= count($winPool) ?>)
            </span>
            <small class="text-secondary ms-2">— продвинуты на основании квалификации</small>
        </div>
        <div class="table-responsive">
            <table class="table table-dark table-sm table-hover mb-0">
                <thead><tr class="table-dark">
                    <th>Монета</th>
                    <th>Дата добавления</th>
                    <th>Последняя проверка</th>
                    <th>Ср. ROI</th>
                    <th>Причина добавления</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($winPool as $sym => $entry): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($sym) ?></strong>
                            <span class="badge badge-pool ms-1">🏆 Win</span>
                        </td>
                        <td><?= htmlspecialchars(substr((string)($entry['promoted_at'] ?? '—'), 0, 19)) ?></td>
                        <td><?= htmlspecialchars(substr((string)($entry['last_revalidated_at'] ?? '—'), 0, 19)) ?></td>
                        <td><?php
                            $ar = $entry['last_avg_roi'] ?? null;
                            if ($ar !== null) {
                                $cls = (float)$ar >= 0 ? 'positive' : 'negative';
                                echo '<span class="' . $cls . '">' . number_format((float)$ar * 100, 2) . '%</span>';
                            } else { echo '—'; }
                        ?></td>
                        <td><small class="text-secondary"><?= htmlspecialchars($entry['promotion_reason'] ?? '') ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recent promotions / demotions -->
    <?php if (!empty($promotionEvents) || !empty($demotionEvents)): ?>
    <div class="row g-3 mb-4">
        <?php if (!empty($promotionEvents)): ?>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header py-2">
                    <span class="fw-semibold" style="color:#34d399;">
                        <i class="bi bi-arrow-up-circle me-1"></i>История повышений (последние <?= min(10, count($promotionEvents)) ?>)
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table table-dark table-sm mb-0">
                        <thead><tr><th>Монета</th><th>Дата</th><th>Причина</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($promotionEvents, 0, 10) as $ev): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($ev['symbol'] ?? '') ?></strong>
                                    <span class="badge badge-promoted ms-1">↑</span>
                                </td>
                                <td><?= htmlspecialchars(substr((string)($ev['timestamp'] ?? ''), 0, 10)) ?></td>
                                <td><small class="text-secondary"><?= htmlspecialchars($ev['reason'] ?? '') ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($demotionEvents)): ?>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header py-2">
                    <span class="fw-semibold" style="color:#f87171;">
                        <i class="bi bi-arrow-down-circle me-1"></i>История понижений (последние <?= min(10, count($demotionEvents)) ?>)
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table table-dark table-sm mb-0">
                        <thead><tr><th>Монета</th><th>Дата</th><th>Причина</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($demotionEvents, 0, 10) as $ev): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($ev['symbol'] ?? '') ?></strong>
                                    <span class="badge badge-demoted ms-1">↓</span>
                                </td>
                                <td><?= htmlspecialchars(substr((string)($ev['timestamp'] ?? ''), 0, 10)) ?></td>
                                <td><small class="text-secondary"><?= htmlspecialchars($ev['reason'] ?? '') ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Threshold sensitivity breakdown -->
    <?php if (!empty($sensitivity)): ?>
    <div class="card mb-4">
        <div class="card-header py-2">
            <small class="fw-semibold text-secondary">
                <i class="bi bi-bar-chart me-1"></i>Разбивка по критериям
            </small>
        </div>
        <div class="card-body py-2">
            <div class="row g-3" style="font-size:0.85rem;">
                <div class="col-auto">
                    <span class="text-secondary">Не прошли по кол-ву сделок:</span>
                    <strong class="text-warning"><?= (int)($sensitivity['fail_by_trade_count'] ?? 0) ?></strong>
                </div>
                <div class="col-auto">
                    <span class="text-secondary">Не прошли по ROI:</span>
                    <strong class="text-warning"><?= (int)($sensitivity['fail_by_roi_threshold'] ?? 0) ?></strong>
                </div>
                <?php if (($sensitivity['fail_by_avg_roi'] ?? 0) > 0): ?>
                <div class="col-auto">
                    <span class="text-secondary">Не прошли по ср. ROI:</span>
                    <strong class="text-warning"><?= (int)$sensitivity['fail_by_avg_roi'] ?></strong>
                </div>
                <?php endif; ?>
                <?php if (($sensitivity['fail_by_winrate'] ?? 0) > 0): ?>
                <div class="col-auto">
                    <span class="text-secondary">Не прошли по winrate:</span>
                    <strong class="text-warning"><?= (int)$sensitivity['fail_by_winrate'] ?></strong>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (empty($symbols)): ?>
    <div class="alert alert-secondary">
        <i class="bi bi-info-circle me-1"></i>
        Нет данных. Нажмите <strong>Запустить</strong>, чтобы выполнить первое вычисление.
        Данные берутся из закрытых сделок Trading Bot и симулятора Smart Brain.
    </div>
    <?php else: ?>

    <!-- Symbol search -->
    <div class="mb-3">
        <input type="text" id="wuSearch" class="form-control form-control-sm d-inline-block"
               style="max-width:220px;" placeholder="Поиск монеты…">
    </div>

    <?php
    $tableHeader = static function (): void {
        echo '<thead><tr class="table-dark">';
        echo '<th>Монета</th>';
        echo '<th>Статус</th>';
        echo '<th>Сделок (окно)</th>';
        echo '<th>Ср. ROI</th>';
        echo '<th>Лучший ROI</th>';
        echo '<th>Winrate</th>';
        echo '<th>Побед &gt; порога</th>';
        echo '<th>Посл. сделка</th>';
        echo '<th>Причина</th>';
        echo '</tr></thead>';
    };

    // Qualified
    if (!empty($qualified)): ?>
    <div class="card mb-4">
        <div class="card-header py-2">
            <span class="fw-semibold" style="color:#34d399;">
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
    <?php endif;

    // Near-qualified
    if (!empty($nearQualified)): ?>
    <div class="card mb-4">
        <div class="card-header py-2">
            <span class="fw-semibold" style="color:#fbbf24;">
                <i class="bi bi-exclamation-circle-fill me-1"></i>
                Почти квалифицировано (<?= count($nearQualified) ?>)
            </span>
            <small class="text-secondary ms-2">— не прошли один мягкий критерий</small>
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
    <?php endif;

    // Rejected
    if (!empty($rejected)): ?>
    <div class="card mb-4">
        <div class="card-header py-2">
            <span class="fw-semibold text-secondary">
                <i class="bi bi-x-circle-fill me-1"></i>
                Отклонённые монеты (<?= count($rejected) ?>)
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-dark table-sm table-hover mb-0" style="opacity:0.85;">
                <?php $tableHeader(); ?>
                <tbody>
                    <?php foreach ($rejected as $sym): ?>
                        <?php $symbolRow($sym, $symbols[$sym] ?? []); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // empty($symbols) ?>

    <?php if ($computedAt): ?>
    <p class="text-secondary mt-2" style="font-size:0.8rem;">
        <i class="bi bi-info-circle me-1"></i>
        Данные вычислены: <?= htmlspecialchars($computedAt) ?>.
        Модуль работает в теневом режиме — торговля не затронута.
    </p>
    <?php endif; ?>

    <?php
};

require __DIR__ . '/_layout.php';
