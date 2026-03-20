<?php
/**
 * Smart Brain Module - Coin Passports View
 * 
 * Phase 5: Coin Passports display.
 * Shows per-symbol statistics from storage/passports/*.json
 */

/** @var string $smartBrainUrl */
/** @var array<int,array<string,mixed>> $passports */
/** @var int $count */

$pageTitle = 'Smart Brain - Coin Passports';
$activeTab = 'passports';

$pageContent = function() use ($passports, $count, $smartBrainUrl) {
?>
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-card-checklist me-2 text-primary"></i>Coin Passports</h4>
            <p class="text-secondary mb-0">Long-term statistics per symbol — updated after each simulator cycle</p>
        </div>
        <div>
            <span class="badge bg-primary fs-6"><?= $count ?> Passports</span>
        </div>
    </div>

    <!-- Passports Table -->
    <div class="card mb-4">
        <div class="card-header"><h5 style="margin: 0;"><i class="bi bi-card-checklist me-1"></i> Passports (<?= $count ?>)</h5></div>
        <div class="card-body p-0">
            <table class="table table-dark table-hover mb-0" style="font-size:0.85rem;">
                <thead>
                    <tr>
                        <th>Symbol</th>
                        <th>Trades</th>
                        <th>Winrate</th>
                        <th>Avg MAE</th>
                        <th>Avg MFE</th>
                        <th>Avg Duration</th>
                        <th>Speed</th>
                        <th>Reliability</th>
                        <th class="text-center">Trail Score</th>
                        <th class="text-center">Stop Sens</th>
                        <th class="text-center">Exec Profile</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($passports)): ?>
                        <tr><td colspan="11" class="text-center text-secondary py-4">No passports yet — data accumulates after simulator cycles</td></tr>
                    <?php else: ?>
                        <?php foreach ($passports as $p): ?>
                        <?php $ep = $p['execution_profile'] ?? null; ?>
                        <tr>
                            <td><strong><?= htmlspecialchars((string)($p['symbol'] ?? '')) ?></strong></td>
                            <td><?= htmlspecialchars((string)($p['trades_total'] ?? '0')) ?></td>
                            <td>
                                <?php $wr = $p['winrate'] ?? null; ?>
                                <?php if ($wr !== null): ?>
                                    <span class="badge <?= (float)$wr >= 0.7 ? 'bg-success' : ((float)$wr >= 0.5 ? 'bg-warning' : 'bg-danger') ?>">
                                        <?= number_format((float)$wr * 100, 1) ?>%
                                    </span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars((string)($p['avg_mae'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($p['avg_mfe'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($p['avg_duration'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($p['speed_class'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars((string)($p['reliability_score'] ?? '-')) ?></td>
                            <td class="text-center">
                                <?php if ($ep && isset($ep['trailing_friendliness_score'])): ?>
                                    <?php $ts = (float)$ep['trailing_friendliness_score']; ?>
                                    <span class="badge <?= $ts >= 0.6 ? 'bg-success bg-opacity-25 text-success' : ($ts >= 0.3 ? 'bg-warning bg-opacity-25 text-warning' : 'bg-danger bg-opacity-25 text-danger') ?>"><?= number_format($ts, 2) ?></span>
                                <?php else: ?>-<?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($ep && isset($ep['stop_sensitivity_score'])): ?>
                                    <?php $ss = (float)$ep['stop_sensitivity_score']; ?>
                                    <span class="badge <?= $ss >= 0.5 ? 'bg-danger bg-opacity-25 text-danger' : ($ss >= 0.25 ? 'bg-warning bg-opacity-25 text-warning' : 'bg-success bg-opacity-25 text-success') ?>"><?= number_format($ss, 2) ?></span>
                                <?php else: ?>-<?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($ep): ?>
                                    <?php $actionable = !empty($ep['actionable']); ?>
                                    <span class="badge <?= $actionable ? 'bg-info bg-opacity-25 text-info' : 'bg-secondary bg-opacity-25 text-secondary' ?>"><?= (int)($ep['sample_size'] ?? 0) ?> trades<?= !$actionable ? ' (low)' : '' ?></span>
                                <?php else: ?>
                                    <span class="text-secondary">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Execution Profile Legend -->
    <div class="card mb-4">
        <div class="card-header"><h5 style="margin: 0;"><i class="bi bi-info-circle me-1"></i> Как читать профиль исполнения</h5></div>
        <div class="card-body small" style="font-size:0.82rem;">
            <div class="row">
                <div class="col-md-4">
                    <strong class="text-info">Trail Score</strong> — насколько символу подходит трейлинг<br>
                    <span class="text-secondary">Высокий = часто активируется трейлинг, мало стопов. Низкий = стопы срабатывают раньше.</span>
                </div>
                <div class="col-md-4">
                    <strong class="text-danger">Stop Sens</strong> — чувствительность к стопу<br>
                    <span class="text-secondary">Высокий = часто бьёт стоп (шумный символ). Низкий = редко достигает стопа.</span>
                </div>
                <div class="col-md-4">
                    <strong class="text-secondary">Exec Profile</strong> — кол-во live-сделок в профиле<br>
                    <span class="text-secondary">Менее 10 = "low" (мало данных для выводов). ≥10 = профиль actionable.</span>
                </div>
            </div>
        </div>
    </div>
<?php
};

require __DIR__ . '/_layout.php';
