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

$statGroups = [
    'Market Regime' => [
        'regime_bullish_total'    => 'Bullish cycles',
        'regime_bearish_total'    => 'Bearish cycles',
        'regime_mixed_total'      => 'Mixed cycles',
        'regime_transition_total' => 'Transition cycles',
    ],
    'Pipeline Gates' => [
        'trend_pass_total'      => 'Trend pass',
        'corridor_pass_total'   => 'Corridor pass',
        'bucket_allowed_total'  => 'Bucket allowed',
        'wave_pass_total'       => 'Wave pass',
        'wave_rejected_total'   => 'Wave rejected',
    ],
    'Pattern Candidates' => [
        'double_bottom_found_total' => 'Double bottoms found',
        'double_top_found_total'    => 'Double tops found',
    ],
    'Confirmation & Signals' => [
        'control_check_pass_total'    => 'Control check pass',
        'control_check_expired_total' => 'Candidate expired',
        'final_signals_total'         => 'Final signals emitted',
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
        <h4 class="mb-0">Pattern — Stats</h4>
        <div>
            <a href="<?= $patternUrl ?>"        class="btn btn-sm btn-outline-secondary">← Index</a>
            <a href="<?= $patternUrl ?>/config"  class="btn btn-sm btn-outline-secondary ms-1">Config</a>
        </div>
    </div>

    <!-- Current regime -->
    <div class="stats-section">
        <h6>Current Market Regime</h6>
        <div class="stats-grid">
            <div class="stat-cell">
                <div class="stat-num"><?= htmlspecialchars($regime['regime'] ?? '—') ?></div>
                <div class="stat-lbl">Regime</div>
            </div>
            <div class="stat-cell">
                <div class="stat-num"><?= (int)($regime['bull_count'] ?? 0) ?></div>
                <div class="stat-lbl">Bull votes</div>
            </div>
            <div class="stat-cell">
                <div class="stat-num"><?= (int)($regime['bear_count'] ?? 0) ?></div>
                <div class="stat-lbl">Bear votes</div>
            </div>
            <div class="stat-cell">
                <div class="stat-num"><?= (int)($regime['flat_count'] ?? 0) ?></div>
                <div class="stat-lbl">Flat votes</div>
            </div>
            <div class="stat-cell">
                <div class="stat-num"><?= htmlspecialchars($regime['ts'] ?? '—') ?></div>
                <div class="stat-lbl">Last updated</div>
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

    <!-- Active signals list -->
    <div class="stats-section">
        <h6>Active Signals (<?= count($signals) ?>)</h6>
        <?php if (empty($signals)): ?>
        <p class="text-muted mb-0" style="font-size: 13px;">No active signals.</p>
        <?php else: ?>
        <table class="table table-sm" style="font-size: 12px;">
            <thead>
                <tr>
                    <th>Signal ID</th><th>Symbol</th><th>Side</th><th>Pattern</th>
                    <th>Entry</th><th>Regime</th><th>Trend</th><th>Detected</th>
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
                <td><?= htmlspecialchars($sig['market_regime'] ?? '') ?></td>
                <td><?= htmlspecialchars($sig['trend_direction'] ?? '') ?></td>
                <td><?= htmlspecialchars($sig['detected_at'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
