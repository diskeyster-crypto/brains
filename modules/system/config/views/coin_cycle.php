<?php
declare(strict_types=1);

/**
 * Coin / Cycle tab — Coin Passport config and cycle context preview.
 * READ-ONLY — shadow system.
 */

$passportPreview = ($preview['modules'] ?? [])['coin_passport'] ?? [];

ob_start();
?>
<div class="row g-3">
    <div class="col-12 col-xl-7">
        <div class="card">
            <div class="card-header"><i class="bi bi-coin me-2 text-warning"></i>Coin Passport — Config Context</div>
            <div class="card-body">
                <div class="shadow-notice">
                    <i class="bi bi-info-circle me-2"></i>
                    <?= htmlspecialchars($passportPreview['note'] ?? 'Coin Passport has no dedicated config file.') ?>
                </div>

                <p class="text-muted mb-1">Coin Passport operational parameters are governed by:</p>
                <ul class="small text-muted">
                    <li><strong>Smart Brain:</strong> passport gate thresholds, data sufficiency windows, trust-state logic (in <code>modules/system/coin_passport/lib/passport_engine.php</code>)</li>
                    <li><strong>Trading Bot:</strong> live routing policy, yellow/green/red thresholds (in <code>config/bot.json</code> — execution block)</li>
                    <li><strong>Coin Passport manifest:</strong> routes only, no operational config</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-5">
        <div class="card">
            <div class="card-header"><i class="bi bi-eye me-2 text-success"></i>Live Routing Policy (from Bot config)</div>
            <div class="card-body p-0">
                <?php
                // Surface the passport-related routing params from bot_runtime
                $passportRoutingKeys = [
                    'live_routing_policy'                     => 'Live Routing Policy',
                    'yellow_live_max_positions'               => 'Yellow Max Positions',
                    'yellow_live_max_leverage'                => 'Yellow Max Leverage',
                    'yellow_live_budget_multiplier'           => 'Yellow Budget Multiplier',
                    'no_passport_green_threshold'             => 'No-Passport Green Threshold',
                    'no_passport_yellow_threshold'            => 'No-Passport Yellow Threshold',
                    'no_passport_red_threshold'               => 'No-Passport Red Threshold',
                ];
                // These live in bot.json execution block — read from effective preview trading_bot
                $botExec = ($preview['modules'] ?? [])['trading_bot']['execution'] ?? [];
                $hasAny = false;
                ?>
                <table class="table table-sm mb-0">
                    <thead><tr><th>Parameter</th><th>Value</th></tr></thead>
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
                    <tr><td colspan="2" class="text-muted">No data — run Re-extract first.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header"><i class="bi bi-info-circle me-2 text-muted"></i>Future Migration Note</div>
            <div class="card-body small text-muted">
                When the Unified Config Module is promoted to runtime authority, Coin Passport
                routing policy (green/yellow/red thresholds, passport gate constants) will be
                extracted here and managed centrally. For now this is a preview-only view.
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_layout.php';
