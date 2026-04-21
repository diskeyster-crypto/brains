<?php

declare(strict_types=1);

/**
 * Pattern Strategy — Admin Stats Page
 */

use Core\System\System;
use Core\System\SystemPaths;
use Core\Auth\Auth;

if (!Auth::check()) {
    header('Location: ' . System::adminUrl('login'));
    exit;
}

$moduleDir = SystemPaths::instance()->get('strategy.pattern');
require_once $moduleDir . '/service.php';

use Modules\Strategy\Pattern\PatternService;

$service = PatternService::instance($moduleDir);
$stats   = $service->getStats();
$signals = $service->getSignals();
$regime  = $service->getMarketRegime();
$lastRun = $service->getLastRun();

$patternUrl = rtrim(System::web('admin/strategy/pattern'), '/');

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
    'Кандидаты на паттерн' => [
        'double_bottom_checked_total' => 'double_bottom проверено',
        'double_bottom_found_total'   => 'double_bottom найдено',
        'double_top_checked_total'    => 'double_top проверено',
        'double_top_found_total'      => 'double_top найдено',
        'pattern_rejected_total'      => 'Паттерн отклонён',
    ],
    'Подтверждение и сигналы' => [
        'control_check_pass_total'    => 'Контроль: прошло',
        'control_check_expired_total' => 'Кандидат устарел',
        'signals_emitted_total'       => 'Эмитировано сигналов',
        'signals_active_final_total'  => 'Активных сигналов',
    ],
];
?>
<style>
.pattern-stats-page { max-width: 860px; }
.stats-section { background: var(--card-bg,#1e293b); border: 1px solid var(--border-color,#334155); border-radius: 8px; padding: 20px; margin-bottom: 20px; }
.stats-section h6 { color: #94a3b8; text-transform: uppercase; font-size: 11px; letter-spacing: .05em; margin-bottom: 12px; }
.stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px,1fr)); gap: 10px; }
.stat-cell  { background: rgba(255,255,255,.03); border: 1px solid var(--border-color,#334155); border-radius: 6px; padding: 10px 14px; }
.stat-num   { font-size: 22px; font-weight: 700; color: #e2e8f0; }
.stat-lbl   { font-size: 11px; color: #64748b; margin-top: 2px; }
</style>

<div class="pattern-stats-page">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h4 class="mb-0">Паттерн — Статистика</h4>
        <div>
            <a href="<?= $patternUrl ?>"        class="btn btn-sm btn-outline-secondary">← Главная</a>
            <a href="<?= $patternUrl ?>/config"  class="btn btn-sm btn-outline-secondary ms-1">Настройки</a>
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

    <!-- Reject reason distribution -->
    <?php
    $dist = (array)($stats['reject_reason_distribution'] ?? []);
    if (!empty($dist)):
        arsort($dist);
    ?>
    <div class="stats-section">
        <h6>Распределение причин отклонения</h6>
        <table class="table table-sm" style="font-size: 12px;">
            <thead><tr><th>Причина</th><th>Кол-во</th></tr></thead>
            <tbody>
            <?php foreach ($dist as $reason => $cnt): ?>
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
        <h6>Распределение причин отклонения</h6>
        <p class="text-muted mb-0" style="font-size: 13px;">Нет данных об отклонениях. Данные появятся после первого запуска.</p>
    </div>
    <?php endif; ?>

    <!-- Active signals list -->
    <div class="stats-section">
        <h6>Активные сигналы (<?= count($signals) ?>)</h6>
        <?php if (empty($signals)): ?>
        <p class="text-muted mb-0" style="font-size: 13px;">Нет активных сигналов.</p>
        <?php else: ?>
        <table class="table table-sm" style="font-size: 12px;">
            <thead>
                <tr>
                    <th>signal_id</th><th>Символ</th><th>Сторона</th><th>Паттерн</th>
                    <th>Вход</th><th>Режим</th><th>Тренд</th><th>Обнаружен</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($signals as $sig): ?>
            <tr>
                <td><code><?= htmlspecialchars($sig['signal_id'] ?? '') ?></code></td>
                <td><?= htmlspecialchars($sig['symbol'] ?? '') ?></td>
                <td><?= htmlspecialchars($sig['side'] ?? '') ?></td>
                <td><?= htmlspecialchars($sig['primary_pattern'] ?? '') ?></td>
                <td><?= htmlspecialchars((string)($sig['entry_price'] ?? '')) ?></td>
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
