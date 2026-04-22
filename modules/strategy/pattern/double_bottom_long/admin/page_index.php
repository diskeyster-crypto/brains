<?php

declare(strict_types=1);

/**
 * Double Bottom Long Strategy — Admin Index Page
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
require_once $moduleDir . '/bootstrap.php';

use Modules\Strategy\DoubleBottomLong\DoubleBottomLongService;

$service  = DoubleBottomLongService::instance($moduleDir);
$lastRun  = $service->getLastRun();
$config   = $service->getConfig();
$stats    = $service->getStats();
$signals  = $service->getSignals();
$regime   = $service->getMarketRegime();
$runState = $service->getRunState();

$strategyId = $config['strategy_id'] ?? 'double_bottom_long';
$mode       = $config['mode']         ?? 'passive';
$enabled    = $config['enabled']      ?? false;

$statusLabel = $enabled
    ? ($mode === 'active' ? 'active' : 'passive')
    : 'disabled';

$statusLabelRu = match ($statusLabel) {
    'active'  => 'АКТИВНО',
    'passive' => 'ПАССИВНО',
    default   => 'ОТКЛЮЧЕНО',
};

$statusColor = match ($statusLabel) {
    'active'  => '#22c55e',
    'passive' => '#f59e0b',
    default   => '#6b7280',
};

$lastRunTime    = $lastRun['started_at']  ?? $lastRun['updated_at'] ?? null;
$lastUpdatedAt  = $lastRun['updated_at']  ?? null;
$lastFinishedAt = $lastRun['finished_at'] ?? null;
$lastStatus     = $lastRun['status']      ?? '—';
$errCount       = $lastRun['errors_count'] ?? 0;

$lastStatusRu = match ($lastStatus) {
    'queued'  => 'в очереди',
    'running' => 'выполняется',
    'done'    => 'завершено',
    'failed'  => 'ошибка',
    default   => $lastStatus !== '—' ? $lastStatus : '—',
};

$regimeRaw = $regime['regime'] ?? null;
$regimeRu  = match ($regimeRaw) {
    'bullish'    => 'бычий',
    'bearish'    => 'медвежий',
    'mixed'      => 'смешанный',
    'transition' => 'переходный',
    'flat'       => 'боковик',
    'unknown'    => 'неизвестно',
    default      => $regimeRaw ?? '—',
};

$dblUrl = rtrim(System::web('admin/strategy/double_bottom_long'), '/');
?>
<style>
.dbl-page { max-width: 880px; }
.pt-card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px,1fr)); gap: 12px; margin: 16px 0; }
.pt-stat-box  { background: var(--card-bg,#1e293b); border: 1px solid var(--border-color,#334155); border-radius: 8px; padding: 16px; text-align: center; }
.pt-stat-val  { font-size: 28px; font-weight: 700; color: #e2e8f0; }
.pt-stat-lbl  { font-size: 11px; color: #94a3b8; text-transform: uppercase; margin-top: 4px; }
.pt-nav       { display: flex; gap: 8px; margin-bottom: 20px; }
.status-dot   { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: 6px; }
</style>

<div class="dbl-page">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h4 class="mb-0">
                <span class="status-dot" style="background: <?= $statusColor ?>;"></span>
                Double Bottom Long
                <span class="badge ms-2" style="background: <?= $statusColor ?>; font-size: 11px;"><?= htmlspecialchars($statusLabelRu) ?></span>
                <span class="badge ms-1" style="background: #1d4ed8; font-size: 11px;">LONG ONLY</span>
            </h4>
            <div style="font-size: 12px; color: #64748b;">
                strategy_id: <code><?= htmlspecialchars($strategyId) ?></code>
                &nbsp;|&nbsp; mode: <code><?= htmlspecialchars($mode) ?></code>
                &nbsp;|&nbsp; timeframe: <code><?= htmlspecialchars($config['timeframe'] ?? 'H4') ?></code>
            </div>
        </div>
        <div class="pt-nav">
            <a href="<?= $dblUrl ?>/config"   class="btn btn-sm btn-outline-secondary">Настройки</a>
            <a href="<?= $dblUrl ?>/stats"    class="btn btn-sm btn-outline-secondary">Статистика</a>
            <a href="<?= $dblUrl ?>/runtime"  class="btn btn-sm btn-outline-secondary">Runtime</a>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="pt-card-grid">
        <div class="pt-stat-box">
            <div class="pt-stat-val"><?= count($signals) ?></div>
            <div class="pt-stat-lbl">Активных сигналов</div>
        </div>
        <div class="pt-stat-box">
            <div class="pt-stat-val"><?= (int)($stats['signals_emitted_total'] ?? 0) ?></div>
            <div class="pt-stat-lbl">Найдено за цикл</div>
        </div>
        <div class="pt-stat-box">
            <div class="pt-stat-val"><?= (int)($stats['double_bottom_found_total'] ?? 0) ?></div>
            <div class="pt-stat-lbl">Двойных доньев</div>
        </div>
        <div class="pt-stat-box">
            <div class="pt-stat-val"><?= htmlspecialchars($regimeRu) ?></div>
            <div class="pt-stat-lbl">Рыночный режим</div>
        </div>
        <div class="pt-stat-box">
            <div class="pt-stat-val" style="color: <?= $errCount > 0 ? '#ef4444' : '#22c55e' ?>;"><?= $errCount ?></div>
            <div class="pt-stat-lbl">Ошибок при запуске</div>
        </div>
    </div>

    <!-- Last run info -->
    <div style="background: var(--card-bg,#1e293b); border: 1px solid var(--border-color,#334155); border-radius: 8px; padding: 16px; margin-bottom: 16px; font-size: 13px;">
        <?php if (empty($lastRun)): ?>
        <span style="color: #64748b;">Запусков ещё не было.</span>
        <?php else: ?>
        <?php
            $runState       = $service->getRunState();
            $cycleId        = (int)($runState['cycle_id']         ?? $lastRun['cycle_id']         ?? 0);
            $cycleFinished  = $runState['cycle_finished_at']      ?? null;
            $continuousScan = (bool)($config['continuous_scan_enabled'] ?? true);
            $isContinuous   = $lastRun['continuous_scan'] ?? $continuousScan;
        ?>
        <strong>Последний запуск</strong>
        <span class="ms-3">Статус: <code><?= htmlspecialchars($lastStatusRu) ?></code></span>
        <?php if ($isContinuous): ?>
        <span class="ms-2 badge" style="background:#1d4ed8;font-size:10px;">НЕПРЕРЫВНЫЙ СКАН</span>
        <?php endif; ?>
        <span class="ms-3">Цикл #<?= $cycleId ?></span>
        <?php if ($lastRunTime): ?>
        <span class="ms-3">Начало: <code><?= htmlspecialchars($lastRunTime) ?></code></span>
        <?php endif; ?>
        <?php if ($cycleFinished): ?>
        <span class="ms-3">Цикл завершён: <code><?= htmlspecialchars($cycleFinished) ?></code></span>
        <?php elseif ($lastFinishedAt): ?>
        <span class="ms-3">Завершено: <code><?= htmlspecialchars($lastFinishedAt) ?></code></span>
        <?php elseif ($lastUpdatedAt): ?>
        <span class="ms-3">Обновлено: <code><?= htmlspecialchars($lastUpdatedAt) ?></code></span>
        <?php endif; ?>
        <span class="ms-3">Просканировано: <code><?= htmlspecialchars((string)($lastRun['symbols_scanned'] ?? $runState['processed'] ?? '—')) ?></code></span>
        <span class="ms-3">Найдено: <code><?= htmlspecialchars((string)($lastRun['signals_emitted_total'] ?? '—')) ?></code></span>
        <?php endif; ?>
    </div>

    <!-- Actions -->
    <form method="post" action="<?= $dblUrl ?>/ajax" class="d-inline-block me-2">
        <input type="hidden" name="action" value="queue_run">
        <button class="btn btn-sm btn-primary" type="submit">Запустить</button>
    </form>
    <form method="post" action="<?= $dblUrl ?>/ajax" class="d-inline-block me-2">
        <input type="hidden" name="action" value="tick_batch">
        <button class="btn btn-sm btn-outline-secondary" type="submit">Шаг батча</button>
    </form>

    <?php
    $previewRows = array_reverse((array)($runState['preview_rows'] ?? []));
    if (!empty($previewRows)):
    ?>
    <div style="background: var(--card-bg,#1e293b); border: 1px solid var(--border-color,#334155); border-radius: 8px; padding: 16px; margin-top: 20px; overflow-x: auto;">
        <h6 style="color: #94a3b8; text-transform: uppercase; font-size: 11px; letter-spacing: .05em; margin-bottom: 12px;">
            Диагностика Double Bottom Long по символу (последних <?= count($previewRows) ?> строк, сначала новые)
        </h6>
        <table class="table table-sm" style="font-size: 11px; white-space: nowrap;">
            <thead>
                <tr>
                    <th>Символ</th>
                    <th>Тренд</th>
                    <th>Сторона✓</th>
                    <th>Bucket</th>
                    <th>Зона✓</th>
                    <th>Волна</th>
                    <th>Стадия</th>
                    <th>Паттерн</th>
                    <th>Кандидат</th>
                    <th>Некл.dist</th>
                    <th title="pattern_score">P</th>
                    <th title="structure_score">Str</th>
                    <th title="neckline_score">Nck</th>
                    <th title="confirmation_score">Conf</th>
                    <th title="context_score">Ctx</th>
                    <th title="candidate_quality_score">Качество</th>
                    <th>Q✓</th>
                    <th>Причина качества</th>
                    <th>Контроль</th>
                    <th>Статус сигнала</th>
                    <th>Победитель</th>
                    <th title="final_reject_reason">Fin.reject</th>
                    <th>Причина отклонения</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($previewRows as $row): ?>
            <?php
                $signalStatus   = $row['final_signal_status'] ?? null;
                $qualityPass    = $row['quality_pass'] ?? null;
                $winnerSelected = $row['winner_selected'] ?? null;
                $rowColor = match (true) {
                    $winnerSelected === true            => '#166534',
                    $winnerSelected === false           => '#7f1d1d',
                    $signalStatus === 'confirm_pending' => '#92400e',
                    default                             => '',
                };
                $qualColor   = $qualityPass === true ? '#22c55e' : ($qualityPass === false ? '#ef4444' : '#64748b');
                $winnerColor = $winnerSelected === true ? '#22c55e' : ($winnerSelected === false ? '#ef4444' : '#64748b');

                $fmtScore = static function(?float $v): string {
                    return $v !== null ? number_format($v, 2) : '—';
                };
                $fmtBool = static function(?bool $v): string {
                    return $v === true ? '<span style="color:#22c55e">✓</span>' : ($v === false ? '<span style="color:#ef4444">✗</span>' : '—');
                };
            ?>
            <tr style="<?= $rowColor ? "background: {$rowColor}22;" : '' ?>">
                <td><strong><?= htmlspecialchars($row['symbol'] ?? '') ?></strong></td>
                <td><?= htmlspecialchars($row['trend_direction'] ?? '—') ?></td>
                <td><?= $fmtBool($row['side_allowed'] ?? null) ?></td>
                <td><?= htmlspecialchars((string)($row['corridor_bucket'] ?? '—')) ?></td>
                <td><?= $fmtBool($row['bucket_allowed'] ?? null) ?></td>
                <td><?= htmlspecialchars(($row['wave_direction'] ?? '?') . '/' . ($row['wave_state'] ?? '?')) ?></td>
                <td><code style="font-size:10px;color:#94a3b8;"><?= htmlspecialchars($row['final_stage_reached'] ?? '—') ?></code></td>
                <td><?= htmlspecialchars($row['primary_pattern'] ?? '—') ?></td>
                <td><?= ($row['candidate_found'] ?? false) ? '<span style="color:#22c55e">да</span>' : 'нет' ?></td>
                <td><code style="font-size:10px;"><?= htmlspecialchars($row['neckline_distance_status'] ?? '—') ?></code></td>
                <td><?= $fmtScore($row['pattern_score']      ?? null) ?></td>
                <td><?= $fmtScore($row['structure_score']    ?? null) ?></td>
                <td><?= $fmtScore($row['neckline_score']     ?? null) ?></td>
                <td><?= $fmtScore($row['confirmation_score'] ?? null) ?></td>
                <td><?= $fmtScore($row['context_score']      ?? null) ?></td>
                <td style="font-weight:700;color:<?= $qualColor ?>;"><?= $fmtScore($row['candidate_quality_score'] ?? null) ?></td>
                <td style="color:<?= $qualColor ?>;"><?= $qualityPass === true ? '✓' : ($qualityPass === false ? '✗' : '—') ?></td>
                <td><code style="font-size:10px;color:#f59e0b;"><?= htmlspecialchars($row['quality_reject_reason'] ?? '') ?></code></td>
                <td><?= htmlspecialchars($row['control_check_status'] ?? '—') ?></td>
                <td><code style="font-size:10px;"><?= htmlspecialchars($signalStatus ?? '') ?></code></td>
                <td style="font-weight:700;color:<?= $winnerColor ?>;"><?= $winnerSelected === true ? '✓ победитель' : ($winnerSelected === false ? '✗ отклонён' : '—') ?></td>
                <td><code style="font-size:10px;color:<?= ($row['final_reject_reason'] ?? null) ? '#ef4444' : '#64748b' ?>;"><?= htmlspecialchars($row['final_reject_reason'] ?? '') ?></code></td>
                <td><code style="font-size:10px;"><?= htmlspecialchars($row['reject_reason'] ?? $row['pattern_reject_reason'] ?? '') ?></code></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div style="background: var(--card-bg,#1e293b); border: 1px solid var(--border-color,#334155); border-radius: 8px; padding: 24px; margin-top: 20px; text-align: center; color: #64748b; font-size: 13px;">
        Нет данных диагностики. Запустите скан для получения результатов.
    </div>
    <?php endif; ?>
</div>
