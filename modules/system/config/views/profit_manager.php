<?php
declare(strict_types=1);

/**
 * Менеджер прибыли — конфигурация и предпросмотр.
 * Только чтение — PM ещё не мигрирован в Центр Конфигурации.
 */

$params    = $data['params']              ?? [];
$pmPreview = ($preview['modules'] ?? [])['profit_manager'] ?? [];

$pmKeys = [
    'pm_enabled',
    'pm_trailing_owner',
    'trailing_enabled',
    'trailing_mode',
    'trailing_activation_roi',
    'trailing_activation_floor_roi',
    'break_even_enabled',
    'break_even_activation_roi',
];

ob_start();
?>
<div class="row g-3">

    <!-- Баннер: не мигрирован -->
    <div class="col-12">
        <div class="alert alert-secondary d-flex align-items-start gap-2 mb-0">
            <i class="bi bi-lock-fill mt-1 flex-shrink-0"></i>
            <div>
                <strong>Менеджер Прибыли — не мигрирован.</strong>
                Параметры PM на этом шаге доступны только для просмотра.
                Редактирование через Центр Конфигурации будет активировано в следующей волне миграции.
                PM по-прежнему читает собственный конфиг (через прокси Trading Bot).
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-cash-coin me-2 text-success"></i>Менеджер Прибыли — Операционная конфигурация (предпросмотр)</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th style="width:40%">Параметр</th><th>Значение</th><th>Файл источника</th></tr></thead>
                    <tbody>
                    <?php foreach ($pmKeys as $k):
                        if (!isset($params[$k])) continue;
                        $e      = $params[$k];
                        $val    = $e['value'];
                        $valStr = is_bool($val) ? ($val ? 'true' : 'false') : (string)$val;
                        $valStr = ($val === null || $valStr === '') ? '—' : $valStr;
                    ?>
                    <tr class="param-row">
                        <td><strong><?= htmlspecialchars($e['label'] ?? $k) ?></strong></td>
                        <td class="font-monospace"><?= htmlspecialchars($valStr) ?></td>
                        <td class="source-tag"><?= htmlspecialchars(basename($e['source_file'] ?? '')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-eye me-2 text-success"></i>Менеджер Прибыли — Текущий эффективный конфиг</div>
            <div class="card-body p-0">
                <?php if (empty($pmPreview)): ?>
                    <p class="p-3 text-muted">Нет данных — нажмите «Перечитать».</p>
                <?php else: ?>
                    <?php
                    $pmLayerMap = [
                        'module'    => ['label' => 'pm_config › bot_config', 'title' => 'Блок module PM — проксируется из config.php Trading Bot'],
                        'execution' => ['label' => 'pm_config › bot_runtime', 'title' => 'Блок execution PM — объединение bot.json + config.php'],
                    ];
                    ?>
                    <?php foreach ($pmPreview as $section => $block): ?>
                    <div class="p-2 border-bottom" style="border-color:var(--border-color)!important;">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <span class="text-muted small text-uppercase"><?= htmlspecialchars($section) ?></span>
                            <?php $layer = $pmLayerMap[$section] ?? ['label' => 'pm_config', 'title' => '']; ?>
                            <span class="badge bg-secondary" title="<?= htmlspecialchars($layer['title']) ?>"><?= htmlspecialchars($layer['label']) ?></span>
                        </div>
                        <?php if (is_array($block)): ?>
                            <?php foreach ($block as $k => $v): ?>
                            <div class="small d-flex gap-2">
                                <span class="text-muted" style="min-width:200px"><?= htmlspecialchars($k) ?></span>
                                <span class="font-monospace"><?= htmlspecialchars(is_bool($v) ? ($v ? 'true' : 'false') : (string)$v) ?></span>
                            </div>
                            <?php endforeach; ?>
                        <?php elseif ($block !== null): ?>
                            <span class="font-monospace small"><?= htmlspecialchars((string)$block) ?></span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <div class="p-2" style="font-size:0.78rem;color:#64748b;">
                        Источник: <code>pm_config</code> (profit_manager/config/config.php) проксируется из <code>bot_config</code> + <code>bot_runtime</code>.
                        Центр Конфигурации не управляет PM на данном этапе.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header"><i class="bi bi-info-circle me-2 text-muted"></i>Архитектура конфигурации Менеджера Прибыли</div>
            <div class="card-body small text-muted">
                Менеджер Прибыли читает конфигурацию через прокси
                <code>modules/system/profit_manager/config/config.php</code>,
                который делегирует в <code>modules/system/trading_bot/config/config.php</code>
                (блок profit_manager), объединённый с runtime-переопределениями из
                <code>modules/system/trading_bot/config/bot.json</code>.
                Центр Конфигурации читает все три файла, но не записывает их.
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';

