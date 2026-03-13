<?php
/**
 * Brain Module - Coin Passports View
 * 
 * Displays coin passports - aggregated trading statistics per symbol
 * from simulator training data.
 */

use Core\System\System;

// Variables: $passports, $flash
$brainUrl = rtrim(System::web('admin/brain'), '/');
$pageTitle = 'Brain - Coin Passports';
$activeTab = 'coin-passports';

$extraStyles = <<<CSS
.metric-good { color: #28a745; }
.metric-bad { color: #dc3545; }
.metric-neutral { color: #6c757d; }
CSS;

$pageContent = function() use ($passports, $brainUrl) {
    $totalSymbols = count($passports);
    $totalTrades = array_sum(array_column($passports, 'trades_total'));
?>
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-coin me-2"></i>Coin Passports</h4>
            <p class="text-secondary mb-0">Aggregated trading statistics per symbol from simulator</p>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge bg-primary fs-6"><?= $totalSymbols ?> Symbols</span>
            <span class="badge bg-secondary fs-6"><?= number_format($totalTrades) ?> Trades</span>
            <button type="button" class="btn btn-outline-primary" id="rebuildBtn">
                <i class="bi bi-arrow-clockwise me-1"></i> Rebuild Passports
            </button>
        </div>
    </div>

    <!-- Rebuild Result Alert -->
    <div id="rebuildAlert" class="alert alert-dismissible d-none" role="alert">
        <span id="rebuildMessage"></span>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>

    <!-- Passports Table -->
    <?php if (empty($passports)): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-inbox display-1 text-secondary"></i>
            <h5 class="mt-3">No Coin Passports Found</h5>
            <p class="text-secondary">Run the pipeline with simulator enabled to generate training data.</p>
            <p class="text-secondary small">Passports will be built automatically from <code>training_dataset.ndjson</code></p>
            <button type="button" class="btn btn-primary" id="rebuildBtnEmpty">
                <i class="bi bi-arrow-clockwise me-1"></i> Build Passports Now
            </button>
        </div>
    </div>
    <?php else: ?>
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-dark table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Symbol</th>
                            <th class="text-end">Trades</th>
                            <th class="text-end">Win Rate</th>
                            <th class="text-end">Avg ROI</th>
                            <th class="text-end">Med. MAE</th>
                            <th class="text-end">Med. MFE</th>
                            <th class="text-end">Med. Duration</th>
                            <th class="text-end">Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Sort passports by trades_total descending
                        uasort($passports, fn($a, $b) => ($b['trades_total'] ?? 0) <=> ($a['trades_total'] ?? 0));
                        
                        foreach ($passports as $symbol => $passport): 
                            $winRate = $passport['win_rate'] ?? 0;
                            $avgRoi = $passport['avg_roi_margin'] ?? 0;
                            $medMae = $passport['median_mae'] ?? 0;
                            $medMfe = $passport['median_mfe'] ?? 0;
                            $medDuration = $passport['median_duration_min'] ?? 0;
                            $trades = $passport['trades_total'] ?? 0;
                            
                            // Format updated time
                            if (!empty($passport['updated_ts'])) {
                                $updatedDisplay = date('Y-m-d H:i', (int)$passport['updated_ts']);
                            } elseif (!empty($passport['updated_at'])) {
                                $parsedTime = strtotime($passport['updated_at']);
                                $updatedDisplay = $parsedTime !== false ? date('Y-m-d H:i', $parsedTime) : '—';
                            } else {
                                $updatedDisplay = '—';
                            }
                            
                            // Color classes based on values
                            $winRateClass = $winRate >= 50 ? 'metric-good' : ($winRate >= 40 ? 'metric-neutral' : 'metric-bad');
                            $roiClass = $avgRoi > 0 ? 'metric-good' : ($avgRoi < 0 ? 'metric-bad' : 'metric-neutral');
                        ?>
                        <tr>
                            <td>
                                <i class="bi bi-currency-bitcoin me-1 text-warning"></i>
                                <strong><?= htmlspecialchars($symbol) ?></strong>
                            </td>
                            <td class="text-end"><?= number_format($trades) ?></td>
                            <td class="text-end <?= $winRateClass ?>"><?= number_format($winRate, 1) ?>%</td>
                            <td class="text-end <?= $roiClass ?>"><?= number_format($avgRoi, 2) ?>%</td>
                            <td class="text-end text-danger"><?= number_format($medMae, 2) ?>%</td>
                            <td class="text-end text-success"><?= number_format($medMfe, 2) ?>%</td>
                            <td class="text-end"><?= number_format($medDuration, 1) ?> min</td>
                            <td class="text-end text-secondary"><?= htmlspecialchars($updatedDisplay) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Metrics Legend -->
    <div class="mt-3 text-secondary small">
        <strong>Legend:</strong>
        MAE = Maximum Adverse Excursion (worst drawdown during trade),
        MFE = Maximum Favorable Excursion (best unrealized gain during trade)
    </div>
    <?php endif; ?>

    <script>
    // C-3: Unified fetchJson helper for safe JSON response handling
    async function fetchJson(url, options) {
        const resp = await fetch(url, options || {});
        const text = await resp.text();

        if (!resp.ok) {
            throw new Error('HTTP ' + resp.status + ': ' + text);
        }

        try {
            return JSON.parse(text);
        } catch (e) {
            throw new Error('Invalid JSON (' + resp.status + '): ' + text);
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const rebuildBtns = document.querySelectorAll('#rebuildBtn, #rebuildBtnEmpty');
        const alertEl = document.getElementById('rebuildAlert');
        const messageEl = document.getElementById('rebuildMessage');
        
        rebuildBtns.forEach(btn => {
            btn.addEventListener('click', async function() {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Rebuilding...';
                
                try {
                    const data = await fetchJson('<?= $brainUrl ?>/coin-passports/rebuild', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' }
                    });
                    
                    alertEl.classList.remove('d-none', 'alert-success', 'alert-danger', 'alert-warning');
                    
                    if (data.success) {
                        alertEl.classList.add('alert-success');
                        messageEl.innerHTML = `<i class="bi bi-check-circle me-1"></i> Passports rebuilt: ${data.passports_created} symbols, ${data.total_trades} trades processed.`;
                        setTimeout(() => location.reload(), 1500);
                    } else if (data.total_trades === 0) {
                        alertEl.classList.add('alert-warning');
                        messageEl.innerHTML = `<i class="bi bi-exclamation-triangle me-1"></i> No training data found. Run the simulator to generate trade data.`;
                    } else {
                        alertEl.classList.add('alert-danger');
                        messageEl.innerHTML = `<i class="bi bi-x-circle me-1"></i> Error: ${data.errors?.join(', ') || 'Unknown error'}`;
                    }
                } catch (error) {
                    alertEl.classList.remove('d-none', 'alert-success', 'alert-warning');
                    alertEl.classList.add('alert-danger');
                    messageEl.innerHTML = `<i class="bi bi-x-circle me-1"></i> Request failed: ${error.message}`;
                } finally {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i> Rebuild Passports';
                }
            });
        });
    });
    </script>
<?php
};

require \Core\System\SystemPaths::instance()->get('system.brain') . '/views/_layout.php';
?>

<?php /* RULES
 * Views must include partials via SystemPaths / PackMap ONLY (no local path computations)
 */ ?>
