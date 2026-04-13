<?php
declare(strict_types=1);

/**
 * Монета / Цикл — предпросмотр конфигурации Coin Passport.
 * Только чтение — Coin Passport не мигрирован.
 */

$passportPreview = ($preview['modules'] ?? [])['coin_passport'] ?? [];

ob_start();
?>
<div class="row g-3">

    <!-- Баннер: не мигрирован -->
    <div class="col-12">
        <div class="alert alert-secondary d-flex align-items-start gap-2 mb-0">
            <i class="bi bi-lock-fill mt-1 flex-shrink-0"></i>
            <div>
                <strong>Coin Passport — не мигрирован.</strong>
                Параметры Coin Passport на этом шаге доступны только для просмотра.
                Редактирование через Центр Конфигурации будет активировано в следующей волне миграции.
                Passport gate логика и пороговые значения по-прежнему управляются через Smart Brain и Trading Bot.
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-7">
        <div class="card">
            <div class="card-header"><i class="bi bi-coin me-2 text-warning"></i>Coin Passport — Контекст конфигурации</div>
            <div class="card-body">
                <div class="migration-notice">
                    <i class="bi bi-info-circle me-2"></i>
                    <?= htmlspecialchars($passportPreview['note'] ?? 'Coin Passport не имеет выделенного файла конфигурации.') ?>
                </div>

                <p class="text-muted mb-1">Операционные параметры Coin Passport управляются через:</p>
                <ul class="small text-muted">
                    <li><strong>Smart Brain:</strong> пороги passport gate, окна достаточности данных, логика trust-state (в <code>modules/system/coin_passport/lib/passport_engine.php</code>)</li>
                    <li><strong>Trading Bot:</strong> политика live-маршрутизации, пороги yellow/green/red (в <code>config/bot.json</code> — блок execution)</li>
                    <li><strong>Манифест Coin Passport:</strong> только маршруты, без операционной конфигурации</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-5">
        <div class="card">
            <div class="card-header"><i class="bi bi-eye me-2 text-success"></i>Политика live-маршрутизации (из конфига бота)</div>
            <div class="card-body p-0">
                <?php
                $passportRoutingKeys = [
                    'live_routing_policy'           => 'Политика маршрутизации (live)',
                    'yellow_live_max_positions'     => 'Yellow — макс. позиций',
                    'yellow_live_max_leverage'      => 'Yellow — макс. плечо',
                    'yellow_live_budget_multiplier' => 'Yellow — множитель бюджета',
                    'no_passport_green_threshold'   => 'Без паспорта — порог Green',
                    'no_passport_yellow_threshold'  => 'Без паспорта — порог Yellow',
                    'no_passport_red_threshold'     => 'Без паспорта — порог Red',
                ];
                $botExec = ($preview['modules'] ?? [])['trading_bot']['execution'] ?? [];
                $hasAny = false;
                ?>
                <table class="table table-sm mb-0">
                    <thead><tr><th>Параметр</th><th>Значение</th></tr></thead>
                    <tbody>
                    <?php foreach ($passportRoutingKeys as $k => $label):
                        if (!array_key_exists($k, $botExec)) continue;
                        $hasAny = true;
                        $v = $botExec[$k];
                        $vStr = is_bool($v) ? ($v ? 'true' : 'false') : (string)$v;
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($label) ?></td>
                        <td class="font-monospace"><?= htmlspecialchars($vStr) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$hasAny): ?>
                    <tr><td colspan="2" class="text-muted">Нет данных — нажмите «Перечитать».</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header"><i class="bi bi-info-circle me-2 text-muted"></i>Планируемая миграция</div>
            <div class="card-body small text-muted">
                Когда Центр Конфигурации будет переведён в режим runtime authority для Coin Passport,
                политика маршрутизации (пороги green/yellow/red, константы passport gate) будет
                извлечена сюда и управляться централизованно.
                На данный момент это предпросмотр без права редактирования.
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
