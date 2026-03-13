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
            <table class="table table-dark table-hover mb-0">
                <thead>
                    <tr>
                        <th>Symbol</th>
                        <th>Trades</th>
                        <th>Winrate</th>
                        <th>Avg MAE</th>
                        <th>Avg MFE</th>
                        <th>Avg Duration</th>
                        <th>Speed Class</th>
                        <th>Reliability</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($passports)): ?>
                        <tr><td colspan="8" class="text-center text-secondary py-4">No passports yet — data accumulates after simulator cycles</td></tr>
                    <?php else: ?>
                        <?php foreach ($passports as $p): ?>
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
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php
};

require __DIR__ . '/_layout.php';
