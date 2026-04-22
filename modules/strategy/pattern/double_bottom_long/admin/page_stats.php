<?php

declare(strict_types=1);

/**
 * Double Bottom Long Strategy — Admin Stats Page
 */

use Core\System\System;
use Core\System\SystemPaths;
use Core\Auth\Auth;

if (!Auth::check()) {
    header('Location: ' . System::adminUrl('login'));
    exit;
}

$moduleDir = SystemPaths::instance()->get('strategy.double_bottom_long');
require_once $moduleDir . '/service.php';

use Modules\Strategy\DoubleBottomLong\DoubleBottomLongService;

$service = DoubleBottomLongService::instance($moduleDir);
$stats   = $service->getStats();
$signals = $service->getSignals();
$regime  = $service->getMarketRegime();
$lastRun = $service->getLastRun();

$dblUrl = rtrim(System::web('admin/strategy/double_bottom_long'), '/');

$regimeRu = static function(?string $v): string {
    return match ($v ?? '') {
        'bullish'    => 'бычий',
        'bearish'    => 'медвежий',
        'mixed'      => 'смешанный',
        'transition' => 'переходный',
        'flat'       => 'боковик',
        'unknown'    => 'неизвестно',
        ''           => '—',
        default      => $v,
    };
};

$statGroups = [
    'Рыночный режим' => [
        'regime_bullish_total'    => 'Бычьих циклов',
        'regime_bearish_total'    => 'Медвежьих циклов',
        'regime_mixed_total'      => 'Смешанных циклов',
        'regime_transition_total' => 'Переходных циклов',
    ],
    'Фильтры пайплайна' => [
        'trend_pass_total'      => 'Тренд: прошло',
        'corridor_pass_total'   => 'Коридор: прошло',
        'bucket_allowed_total'  => 'Зона: разрешено',
        'wave_pass_total'       => 'Волна: прошло',
        'wave_rejected_total'   => 'Волна: отклонено',
    ],
    'Сброс по стадиям (stage-drop)' => [
        'rejected_by_trend_side_total'        => 'Отклонено: тренд/сторона',
        'rejected_by_bucket_total'            => 'Отклонено: зона (bucket)',
        'rejected_by_wave_total'              => 'Отклонено: волна',
        'rejected_by_pattern_total'           => 'Отклонено: паттерн не найден',
        'rejected_by_neckline_distance_total' => 'Отклонено: цена далеко от нек-лайна',
        'candidate_waiting_confirm_total'     => 'Кандидат: ожидает подтверждения',
        'candidate_expired_total'             => 'Кандидат: истёк TTL',
        'candidate_confirm_failed_total'      => 'Кандидат: подтверждение не пришло',
    ],
    'Double Bottom' => [
        'double_bottom_checked_total' => 'double_bottom проверено',
        'double_bottom_found_total'   => 'double_bottom найдено',
        'pattern_rejected_total'      => 'Паттерн отклонён',
    ],
    'Фильтр качества кандидатов' => [
        'candidates_before_quality_filter_total' => 'До фильтра качества',
        'candidates_after_quality_filter_total'  => 'После фильтра качества',
        'candidates_rejected_by_quality_total'   => 'Отклонено фильтром качества',
    ],
    'Подтверждение и сигналы' => [
        'control_check_pass_total'    => 'Контроль: прошло',
        'control_check_expired_total' => 'Кандидат устарел',
        'signals_emitted_total'       => 'Эмитировано сигналов',
        'signals_active_final_total'  => 'Активных сигналов',
    ],
    'Отбор победителей (winner selection)' => [
        'signals_before_winner_selection_total'   => 'До отбора победителей',
        'signals_after_winner_selection_total'    => 'После отбора победителей',
        'signals_rejected_missing_quality_total'  => 'Отклонено: нет блока качества',
        'signals_rejected_low_neckline_total'     => 'Отклонено: низкий neckline_score',
        'signals_rejected_loser_by_quality_total' => 'Отклонено: проигравший по качеству',
    ],
    'Финальная проверка допустимости сигналов (snapshot — последний тик)' => [
        'signals_before_final_eligibility_total'    => 'Всего сигналов на входе (тик)',
        'signals_after_final_eligibility_total'     => 'Прошло проверку допустимости (тик)',
        'signals_rejected_final_quality_total'      => 'Отклонено: неполный блок качества',
        'signals_rejected_final_low_neckline_total' => 'Отклонено: низкий neckline_score',
        'signals_rejected_final_low_quality_total'  => 'Отклонено: низкий candidate_quality_score',
        'signals_rejected_final_trend_total'        => 'Отклонено: тренд unknown/не бычий',
        'signals_rejected_final_context_total'      => 'Отклонено: контекст (волна/зона)',
    ],
    'Итоговая фильтрация — накопительные счётчики (весь прогон)' => [
        'signals_entered_final_eligibility_total'    => 'Итого вошло в фильтр (нарастающим итогом)',
        'signals_rejected_during_finalization_total' => 'Итого отклонено при финализации',
    ],
];
?>
<style>
.dbl-stats-page { max-width: 860px; }
.stats-section { background: var(--card-bg,#1e293b); border: 1px solid var(--border-color,#334155); border-radius: 8px; padding: 20px; margin-bottom: 20px; }
.stats-section h6 { color: #94a3b8; text-transform: uppercase; font-size: 11px; letter-spacing: .05em; margin-bottom: 12px; }
.stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px,1fr)); gap: 10px; }
.stat-cell  { background: rgba(255,255,255,.03); border: 1px solid var(--border-color,#334155); border-radius: 6px; padding: 10px 14px; }
.stat-num   { font-size: 22px; font-weight: 700; color: #e2e8f0; }
.stat-lbl   { font-size: 11px; color: #64748b; margin-top: 2px; }
</style>

<div class="dbl-stats-page">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h4 class="mb-0">Double Bottom Long — Статистика</h4>
            <div style="font-size: 12px; color: #64748b;">
                <span class="badge" style="background:#1d4ed8;font-size:10px;">LONG ONLY</span>
            </div>
        </div>
        <div>
            <a href="<?= $dblUrl ?>"        class="btn btn-sm btn-outline-secondary">← Главная</a>
            <a href="<?= $dblUrl ?>/config"  class="btn btn-sm btn-outline-secondary ms-1">Настройки</a>
        </div>
    </div>

    <!-- Current regime -->
    <div class="stats-section">
        <h6>Текущий рыночный режим</h6>
        <div class="stats-grid">
            <div class="stat-cell">
                <div class="stat-num"><?= htmlspecialchars($regimeRu($regime['regime'] ?? null)) ?></div>
                <div class="stat-lbl">Режим</div>
            </div>
            <div class="stat-cell">
                <div class="stat-num"><?= htmlspecialchars($regime['regime_reason'] ?? '—') ?></div>
                <div class="stat-lbl">Причина</div>
            </div>
            <div class="stat-cell">
                <div class="stat-num"><?= (int)($regime['bull_count'] ?? 0) ?></div>
                <div class="stat-lbl">Быков</div>
            </div>
            <div class="stat-cell">
                <div class="stat-num"><?= (int)($regime['bear_count'] ?? 0) ?></div>
                <div class="stat-lbl">Медведей</div>
            </div>
            <div class="stat-cell">
                <div class="stat-num"><?= (int)($regime['flat_count'] ?? 0) ?></div>
                <div class="stat-lbl">Флет</div>
            </div>
            <div class="stat-cell">
                <div class="stat-num"><?= (int)($regime['unknown_count'] ?? 0) ?></div>
                <div class="stat-lbl">Неизвестно</div>
            </div>
            <div class="stat-cell">
                <div class="stat-num"><?= number_format((float)($regime['bull_ratio'] ?? 0) * 100, 1) ?>%</div>
                <div class="stat-lbl">Доля быков</div>
            </div>
            <div class="stat-cell">
                <div class="stat-num"><?= number_format((float)($regime['bear_ratio'] ?? 0) * 100, 1) ?>%</div>
                <div class="stat-lbl">Доля медведей</div>
            </div>
            <div class="stat-cell">
                <div class="stat-num"><?= number_format((float)($regime['flat_ratio'] ?? 0) * 100, 1) ?>%</div>
                <div class="stat-lbl">Доля флета</div>
            </div>
            <div class="stat-cell">
                <div class="stat-num"><?= htmlspecialchars($regime['ts'] ?? '—') ?></div>
                <div class="stat-lbl">Обновлено</div>
            </div>
        </div>
    </div>

    <!-- Counter groups -->
    <?php foreach ($statGroups as $groupName => $keys): ?>
    <div class="stats-section">
        <h6><?= htmlspecialchars($groupName) ?></h6>
        <div class="stats-grid">
            <?php foreach ($keys as $k => $label): ?>
            <div class="stat-cell">
                <div class="stat-num"><?= (int)($stats[$k] ?? 0) ?></div>
                <div class="stat-lbl"><?= htmlspecialchars($label) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Pattern-stage reject reason distribution -->
    <?php
    $patternDist = (array)($stats['pattern_reject_reason_distribution'] ?? []);
    if (!empty($patternDist)):
        arsort($patternDist);
    ?>
    <div class="stats-section">
        <h6>Причины отказа детектора double_bottom (pattern_reject_reason_distribution)</h6>
        <table class="table table-sm" style="font-size: 12px;">
            <thead><tr><th>Причина</th><th>Кол-во</th></tr></thead>
            <tbody>
            <?php foreach ($patternDist as $reason => $cnt): ?>
            <tr>
                <td><code><?= htmlspecialchars((string)$reason) ?></code></td>
                <td><?= (int)$cnt ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="stats-section">
        <h6>Причины отказа детектора double_bottom (pattern_reject_reason_distribution)</h6>
        <p class="text-muted mb-0" style="font-size: 13px;">Нет данных. Данные появятся после запуска.</p>
    </div>
    <?php endif; ?>

    <!-- Quality reject reason distribution -->
    <?php
    $qualDist = (array)($stats['quality_reject_reason_distribution'] ?? []);
    if (!empty($qualDist)):
        arsort($qualDist);
    ?>
    <div class="stats-section">
        <h6>Причины отклонения фильтром качества (quality_reject_reason_distribution)</h6>
        <table class="table table-sm" style="font-size: 12px;">
            <thead><tr><th>Причина</th><th>Кол-во</th></tr></thead>
            <tbody>
            <?php foreach ($qualDist as $reason => $cnt): ?>
            <tr>
                <td><code><?= htmlspecialchars((string)$reason) ?></code></td>
                <td><?= (int)$cnt ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="stats-section">
        <h6>Причины отклонения фильтром качества</h6>
        <p class="text-muted mb-0" style="font-size: 13px;">Нет данных. Данные появятся после запуска при наличии кандидатов.</p>
    </div>
    <?php endif; ?>

    <!-- Final reject reason distribution -->
    <?php
    $finalRejDist = (array)($stats['final_reject_reason_distribution'] ?? []);
    if (!empty($finalRejDist)):
        arsort($finalRejDist);
    ?>
    <div class="stats-section">
        <h6>Причины отклонения при финализации (final_reject_reason_distribution)</h6>
        <table class="table table-sm" style="font-size: 12px;">
            <thead><tr><th>Причина</th><th>Кол-во</th></tr></thead>
            <tbody>
            <?php foreach ($finalRejDist as $reason => $cnt): ?>
            <tr>
                <td><code><?= htmlspecialchars((string)$reason) ?></code></td>
                <td><?= (int)$cnt ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="stats-section">
        <h6>Причины отклонения при финализации (final_reject_reason_distribution)</h6>
        <p class="text-muted mb-0" style="font-size: 13px;">Нет данных. Данные появятся после запуска при наличии активных кандидатов.</p>
    </div>
    <?php endif; ?>

    <!-- Active signals list -->
    <div class="stats-section">
        <h6>Активные сигналы (<?= count($signals) ?>) — только лонг</h6>
        <?php if (empty($signals)): ?>
        <p class="text-muted mb-0" style="font-size: 13px;">Нет активных сигналов.</p>
        <?php else: ?>
        <table class="table table-sm" style="font-size: 12px;">
            <thead>
                <tr>
                    <th>signal_id</th><th>Символ</th><th>Паттерн</th>
                    <th>Вход</th>
                    <th title="candidate_quality_score">Качество</th>
                    <th title="neckline_score">Nck</th>
                    <th title="confirmation_score">Conf</th>
                    <th>Q✓</th>
                    <th>Режим</th><th>Тренд</th><th>Обнаружен</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($signals as $sig):
                $qScore = (float)($sig['candidate_quality_score'] ?? 0.0);
                $qPass  = ($sig['quality_pass'] ?? null) === true;
                $qColor = $qPass ? '#22c55e' : '#ef4444';
                $fmtScore = static fn(?float $v): string => $v !== null ? number_format($v, 2) : '—';
            ?>
            <tr>
                <td><code><?= htmlspecialchars($sig['signal_id'] ?? '') ?></code></td>
                <td><?= htmlspecialchars($sig['symbol'] ?? '') ?></td>
                <td><?= htmlspecialchars($sig['primary_pattern'] ?? '') ?></td>
                <td><?= htmlspecialchars((string)($sig['entry_price'] ?? '')) ?></td>
                <td style="font-weight:700;color:<?= $qColor ?>;"><?= $fmtScore($sig['candidate_quality_score'] ?? null) ?></td>
                <td><?= $fmtScore($sig['neckline_score'] ?? null) ?></td>
                <td><?= $fmtScore($sig['confirmation_score'] ?? null) ?></td>
                <td style="color:<?= $qColor ?>;"><?= $qPass ? '✓' : '✗' ?></td>
                <td><?= htmlspecialchars($regimeRu($sig['market_regime'] ?? null)) ?></td>
                <td><?= htmlspecialchars($regimeRu($sig['trend_direction'] ?? null)) ?></td>
                <td><?= htmlspecialchars($sig['detected_at'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
