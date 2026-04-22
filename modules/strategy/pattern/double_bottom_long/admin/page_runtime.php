<?php

declare(strict_types=1);

/**
 * Double Bottom Long Strategy — Admin Runtime Snapshot Page
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
$config   = $service->getConfig();
$runState = $service->getRunState();
$snap     = $service->getRuntimeSnapshot();
$stats    = $service->getStats();
$signals  = $service->getSignals();

$dblUrl = rtrim(System::web('admin/strategy/double_bottom_long'), '/');

$continuousEnabled = (bool)($config['continuous_scan_enabled'] ?? true);
$cycleId           = (int)($runState['cycle_id']          ?? $snap['cycle_id']         ?? 0);
$cycleStartedAt    = $runState['cycle_started_at']         ?? null;
$cycleFinishedAt   = $runState['cycle_finished_at']        ?? null;
$nextCycleReady    = (bool)($runState['next_cycle_ready']  ?? false);
$runStatus         = $runState['status']                   ?? 'idle';
$processed         = (int)($runState['processed']          ?? 0);
$total             = (int)($runState['total']              ?? 0);
$pct               = $total > 0 ? round($processed / $total * 100) : 0;

$statusRu = match ($runStatus) {
    'queued'  => 'в очереди',
    'running' => 'выполняется',
    'done'    => 'завершено',
    'failed'  => 'ошибка',
    'idle'    => 'ожидание',
    default   => $runStatus,
};
$statusColor = match ($runStatus) {
    'running' => '#22c55e',
    'queued'  => '#f59e0b',
    'done'    => '#3b82f6',
    'failed'  => '#ef4444',
    default   => '#64748b',
};

$fmtVal = static function (mixed $v): string {
    if (is_bool($v)) {
        return $v
            ? '<span style="color:#22c55e">true</span>'
            : '<span style="color:#94a3b8">false</span>';
    }
    if (is_array($v)) {
        return '<code>' . htmlspecialchars(json_encode($v, JSON_UNESCAPED_UNICODE)) . '</code>';
    }
    return '<code>' . htmlspecialchars((string)$v) . '</code>';
};
?>
<style>
.dbl-rt-page { max-width: 860px; }
.rt-section { background: var(--card-bg,#1e293b); border: 1px solid var(--border-color,#334155); border-radius: 8px; padding: 20px; margin-bottom: 20px; }
.rt-section h6 { color: #94a3b8; text-transform: uppercase; font-size: 11px; letter-spacing: .05em; margin-bottom: 14px; }
.rt-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px,1fr)); gap: 10px; margin-bottom: 10px; }
.rt-box  { background: rgba(255,255,255,.03); border: 1px solid var(--border-color,#334155); border-radius: 6px; padding: 10px 14px; }
.rt-val  { font-size: 20px; font-weight: 700; color: #e2e8f0; word-break: break-all; }
.rt-lbl  { font-size: 11px; color: #64748b; margin-top: 2px; }
.rt-kv td { font-size: 12px; padding: 4px 8px; }
</style>

<div class="dbl-rt-page">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h4 class="mb-0">Double Bottom Long — Runtime</h4>
            <div style="font-size:12px;color:#64748b;">
                strategy_id: <code>double_bottom_long</code>
                &nbsp;|&nbsp;<span class="badge" style="background:#1d4ed8;font-size:10px;">LONG ONLY</span>
            </div>
        </div>
        <div>
            <a href="<?= $dblUrl ?>"        class="btn btn-sm btn-outline-secondary">← Главная</a>
            <a href="<?= $dblUrl ?>/config"  class="btn btn-sm btn-outline-secondary ms-1">Настройки</a>
            <a href="<?= $dblUrl ?>/stats"   class="btn btn-sm btn-outline-secondary ms-1">Статистика</a>
        </div>
    </div>

    <!-- Scan cycle status -->
    <div class="rt-section">
        <h6>Статус скана</h6>
        <div class="rt-grid">
            <div class="rt-box">
                <div class="rt-val" style="color:<?= $statusColor ?>;"><?= htmlspecialchars($statusRu) ?></div>
                <div class="rt-lbl">Статус</div>
            </div>
            <div class="rt-box">
                <div class="rt-val"><?= $cycleId ?></div>
                <div class="rt-lbl">Цикл №</div>
            </div>
            <div class="rt-box">
                <div class="rt-val"><?= $processed ?> / <?= $total ?></div>
                <div class="rt-lbl">Просканировано</div>
            </div>
            <div class="rt-box">
                <div class="rt-val"><?= $pct ?>%</div>
                <div class="rt-lbl">Прогресс цикла</div>
            </div>
            <div class="rt-box">
                <div class="rt-val" style="color:<?= $continuousEnabled ? '#22c55e' : '#94a3b8' ?>;">
                    <?= $continuousEnabled ? 'Да' : 'Нет' ?>
                </div>
                <div class="rt-lbl">Непрерывный скан</div>
            </div>
            <div class="rt-box">
                <div class="rt-val"><?= count($signals) ?></div>
                <div class="rt-lbl">Активных сигналов</div>
            </div>
        </div>
        <?php if ($cycleStartedAt || $cycleFinishedAt): ?>
        <div style="font-size:12px;color:#64748b;margin-top:8px;">
            <?php if ($cycleStartedAt): ?>
            Цикл начат: <code><?= htmlspecialchars($cycleStartedAt) ?></code>
            <?php endif; ?>
            <?php if ($cycleFinishedAt): ?>
            &nbsp;|&nbsp; Цикл завершён: <code><?= htmlspecialchars($cycleFinishedAt) ?></code>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($runStatus === 'done' && !$continuousEnabled): ?>
        <div class="alert alert-secondary py-2 mt-3 mb-0" style="font-size:12px;">
            Скан завершён (один цикл). Для запуска нового цикла нажмите <strong>Запустить</strong> или включите <strong>Непрерывный скан</strong> в настройках.
        </div>
        <?php elseif ($runStatus === 'running' && $continuousEnabled): ?>
        <div class="alert alert-success py-2 mt-3 mb-0" style="font-size:12px;">
            Непрерывный скан активен. Циклы перезапускаются автоматически каждые 60 с через cron.
        </div>
        <?php elseif ($runStatus === 'idle'): ?>
        <div class="alert alert-warning py-2 mt-3 mb-0" style="font-size:12px;">
            Скан не запущен. Нажмите <strong>Запустить</strong> на главной странице модуля.
        </div>
        <?php endif; ?>
    </div>

    <!-- Runtime snapshot from last completed cycle -->
    <?php if (!empty($snap)): ?>
    <div class="rt-section">
        <h6>Снимок конфигурации (последний завершённый цикл)</h6>
        <div style="font-size:11px;color:#64748b;margin-bottom:10px;">
            Записан: <code><?= htmlspecialchars($snap['snapshot_at'] ?? '—') ?></code>
            &nbsp;|&nbsp; Цикл: <code><?= (int)($snap['cycle_id'] ?? 0) ?></code>
        </div>
        <table class="table table-sm rt-kv">
            <thead><tr><th>Ключ</th><th>Значение</th></tr></thead>
            <tbody>
            <?php
            $snapDisplay = [
                'mode'                       => 'Режим',
                'enabled'                    => 'Включено',
                'universe_mode'              => 'Режим вселенной',
                'batch_size'                 => 'Размер батча',
                'max_symbols_per_run'        => 'Макс. символов',
                'continuous_scan_enabled'    => 'Непрерывный скан',
                'stop_mode'                  => 'Режим стопа',
                'stop_from_liq_buffer_value' => 'Буфер стопа',
                'stop_from_liq_buffer_type'  => 'Тип буфера',
                'bot_budget'                 => 'Бюджет (USDT)',
                'bot_leverage'               => 'Плечо',
                'trailing_enabled'           => 'Трейлинг',
                'trailing_profile'           => 'Профиль трейлинга',
                'tp_enabled'                 => 'TP включён',
                'tp_mode'                    => 'Режим TP',
                'tp_value'                   => 'Значение TP',
                'reverse_pattern_close_enabled' => 'Закрытие по реверс-паттерну',
            ];
            foreach ($snapDisplay as $key => $label):
                if (!array_key_exists($key, $snap)) continue;
            ?>
            <tr>
                <td><code><?= htmlspecialchars($key) ?></code></td>
                <td><?= htmlspecialchars($label) ?>&nbsp; <?= $fmtVal($snap[$key]) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="rt-section">
        <h6>Снимок конфигурации</h6>
        <p class="text-muted mb-0" style="font-size:13px;">
            Снимок появится после первого завершённого цикла скана.
            Запустите скан через главную страницу модуля.
        </p>
    </div>
    <?php endif; ?>

    <!-- Errors -->
    <?php $runErrors = (array)($runState['errors'] ?? []); ?>
    <?php if (!empty($runErrors)): ?>
    <div class="rt-section">
        <h6>Ошибки текущего запуска</h6>
        <ul class="mb-0" style="font-size:12px;">
            <?php foreach ($runErrors as $err): ?>
            <li><code><?= htmlspecialchars((string)$err) ?></code></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Actions -->
    <div class="d-flex gap-2">
        <form method="post" action="<?= $dblUrl ?>/ajax" class="d-inline-block">
            <input type="hidden" name="action" value="queue_run">
            <button class="btn btn-sm btn-primary" type="submit">Запустить</button>
        </form>
        <form method="post" action="<?= $dblUrl ?>/ajax" class="d-inline-block">
            <input type="hidden" name="action" value="tick_batch">
            <button class="btn btn-sm btn-outline-secondary" type="submit">Шаг батча</button>
        </form>
    </div>
</div>
