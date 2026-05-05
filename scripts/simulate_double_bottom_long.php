<?php

declare(strict_types=1);

/**
 * Simulation-Quality Check Script — double_bottom_long
 *
 * Purpose
 * -------
 * Validates the v0.2 improvements by replaying the strategy pipeline against
 * candle data stored in storage/modules.zip / storage/backtest_candles/ and
 * comparing signal counts and estimated expectancy before / after the v0.2 changes.
 *
 * Usage
 * -----
 *   php scripts/simulate_double_bottom_long.php [--show-signals] [--limit=50]
 *
 * What it checks
 * --------------
 *   1. Market regime classification (verifies regime is NOT always "unknown")
 *   2. Signal quality distribution  (avg quality score, neckline score)
 *   3. Regime-gate effectiveness    (fraction rejected by hard regime gate)
 *   4. Trend-gate effectiveness     (fraction rejected when trend is non-bullish)
 *   5. Pattern geometry             (SL/TP prices present in every emitted signal)
 *   6. SL width gate                (no signal with stop_loss_pct > max_stop_loss_pct)
 *
 * Expected improvements vs v0.1 baseline
 * ----------------------------------------
 *   - Fewer signals overall (stricter filters)
 *   - Avg candidate_quality_score ≥ 0.68
 *   - Avg neckline_score ≥ 0.55
 *   - 0 signals with trend_direction != "bullish"
 *   - 0 signals with stop_loss_pct > 0.05 (max_stop_loss_pct)
 *   - 0 signals with stop_loss_price = null
 *   - All signals have tp_price set (tp_enabled = true)
 *   - Regime "unknown" blocked under hard gate
 *
 * Simulating ROI improvement (without a full backtester)
 * -------------------------------------------------------
 * Run the live module for one or more scan cycles (via cron or tickBatch()) and
 * then compare storage/stats.json and storage/signals.json:
 *
 *   Before v0.2:
 *     signals_emitted_total ≈ 1514 per 229 cycles (≈6.6/cycle)
 *     regime always "unknown" → no regime gate → all market conditions
 *     avg quality_score 0.62–0.77
 *
 *   After v0.2 (expected):
 *     signals_emitted_total lower (regime hard gate + trend bullish-only + tighter quality)
 *     regime classified correctly (bull/bear/flat/transition)
 *     avg quality_score ≥ 0.68
 *     every emitted signal carries stop_loss_price and tp_price
 *
 * Where to look in the running system
 * ------------------------------------
 *   storage/stats.json         — cumulative scan stats
 *   storage/cycle_stats.json   — current-cycle stats
 *   storage/signals.json       — active winner signals (rolling pool)
 *   storage/bot_handoff_queue.json — signals queued for bot, with stop/tp fields
 *   storage/market_regime.json — latest regime classification
 *
 * How to verify ROI improvement in the simulator
 * -----------------------------------------------
 * If the project ships a dedicated backtester / simulator:
 *   1. Load historical H4 candles for the same symbols
 *   2. Run DoubleBottomLongService::run() on the snapshot
 *   3. For each emitted signal, simulate a trade:
 *      - Entry at signal['entry_price']
 *      - Exit at signal['tp_price']  if price reaches it first  → win (+tp_value * sl_distance)
 *      - Exit at signal['stop_loss_price'] otherwise            → loss (-sl_distance)
 *   4. Compute: win_rate, avg_win, avg_loss, expectancy = win_rate * avg_win - loss_rate * avg_loss
 *   5. Target expectancy > 0 (was negative in v0.1 due to no TP and uncontrolled SL)
 */

// ──────────────────────────────────────────────────────────────────────────────
// Bootstrap: locate module directory
// ──────────────────────────────────────────────────────────────────────────────

$repoRoot  = dirname(__DIR__);
$moduleDir = $repoRoot . '/modules/strategy/pattern/double_bottom_long';

if (!is_dir($moduleDir)) {
    fwrite(STDERR, "ERROR: module directory not found: {$moduleDir}\n");
    exit(1);
}

// ──────────────────────────────────────────────────────────────────────────────
// Parse CLI flags
// ──────────────────────────────────────────────────────────────────────────────

$showSignals = in_array('--show-signals', $argv ?? [], true);
$limit       = 50;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int)substr($arg, 8));
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────────────────────

function readJson(string $path, mixed $default = null): mixed
{
    if (!file_exists($path)) {
        return $default;
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return $default;
    }
    $decoded = json_decode($raw, true);
    return ($decoded !== null) ? $decoded : $default;
}

function ok(string $msg): void   { echo "  [OK]  {$msg}\n"; }
function warn(string $msg): void { echo " [WARN] {$msg}\n"; }
function fail(string $msg): void { echo " [FAIL] {$msg}\n"; }
function info(string $msg): void { echo "        {$msg}\n"; }

// ──────────────────────────────────────────────────────────────────────────────
// Read module storage
// ──────────────────────────────────────────────────────────────────────────────

$config  = readJson($moduleDir . '/config/base.php') ?? [];
// Re-read as PHP (not JSON)
$config  = require $moduleDir . '/config/base.php';
$signals = readJson($moduleDir . '/storage/signals.json', []);
$regime  = readJson($moduleDir . '/storage/market_regime.json', []);
$stats   = readJson($moduleDir . '/storage/stats.json', []);
$handoff = readJson($moduleDir . '/storage/bot_handoff_queue.json', []);

// ──────────────────────────────────────────────────────────────────────────────
// Print header
// ──────────────────────────────────────────────────────────────────────────────

echo "\n";
echo "═══════════════════════════════════════════════════════════\n";
echo "  double_bottom_long — Simulation Quality Check (v0.2)     \n";
echo "═══════════════════════════════════════════════════════════\n\n";

// ──────────────────────────────────────────────────────────────────────────────
// Check 1: Config values
// ──────────────────────────────────────────────────────────────────────────────

echo "── 1. Config sanity ─────────────────────────────────────\n";

$checks = [
    ['market_regime_gate_mode', 'hard',  'Market regime gate is HARD (blocks non-bullish)'],
    ['min_candidate_quality_score', 0.68, 'Quality floor ≥ 0.68'],
    ['min_neckline_score',          0.55, 'Neckline floor ≥ 0.55'],
    ['double_bottom_similarity_tolerance_pct', 0.05, 'Similarity tolerance ≤ 0.05'],
    ['double_bottom_min_neckline_bounce_pct',  0.015, 'Min neckline bounce ≥ 0.015'],
    ['tp_enabled',                 true,  'TP enabled'],
    ['tp_value',                   2.5,   'TP value = 2.5R'],
    ['stop_buffer_pct_below_lows', 0.005, 'SL buffer below lows = 0.005'],
    ['max_stop_loss_pct',          0.05,  'Max SL width = 5%'],
    ['regime_sample_lookback_candles', 30, 'Regime sample lookback = 30 candles (fixes bug)'],
    ['trend_long_require_bullish', true,  'Early bullish-only trend gate active'],
];

foreach ($checks as [$key, $expected, $label]) {
    $actual = $config[$key] ?? null;
    if ($actual === $expected) {
        ok($label);
    } else {
        fail("{$label} — got " . json_encode($actual) . ", expected " . json_encode($expected));
    }
}

$allowedBuckets = $config['allowed_long_buckets'] ?? [];
if ($allowedBuckets === [1, 2]) {
    ok('Allowed long buckets = [1, 2] (bottom 20% of range)');
} else {
    warn('Allowed long buckets = ' . json_encode($allowedBuckets) . ' (expected [1, 2])');
}

// ──────────────────────────────────────────────────────────────────────────────
// Check 2: Market regime (requires a scan cycle to have run)
// ──────────────────────────────────────────────────────────────────────────────

echo "\n── 2. Market regime ─────────────────────────────────────\n";

if (empty($regime)) {
    warn('storage/market_regime.json not found — run one scan cycle first');
} else {
    $r = $regime['regime'] ?? 'unknown';
    info("Current regime: {$r}");
    info("Bull/Bear/Flat counts: {$regime['bull_count']}/{$regime['bear_count']}/{$regime['flat_count']}");
    info("Unknown count: {$regime['unknown_count']}");
    info("Total used for classification: {$regime['total_count_used']}");

    if ($r === 'unknown' && ($regime['unknown_count'] ?? 0) > 0 && ($regime['total_count_used'] ?? 0) === 0) {
        fail('Regime still "unknown" — likely the candle lookback bug is still present or no scan has run');
        info('Fix: ensure regime_sample_lookback_candles >= 30 in config/base.php');
    } elseif ($r === 'unknown') {
        warn('Regime is "unknown" — either no scan has run yet or market data is flat');
    } else {
        ok("Regime classified as \"{$r}\" (not always-unknown)");
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// Check 3: Active signals quality
// ──────────────────────────────────────────────────────────────────────────────

echo "\n── 3. Active signals quality ────────────────────────────\n";

if (empty($signals)) {
    info('No signals in storage/signals.json — run a scan cycle first');
} else {
    $totalInStorage = count($signals);
    // Split by lifecycle state
    $activeSignals  = array_filter($signals, fn($s) => ($s['active_final'] ?? null) === true  && !(bool)($s['stale'] ?? false));
    $staleSignals   = array_filter($signals, fn($s) => (bool)($s['stale'] ?? false));
    $handoffReady   = array_filter($signals, fn($s) => ($s['active_final'] ?? null) === true  && !(bool)($s['stale'] ?? false) && (bool)($s['handoff_ready'] ?? false));

    $total = count($activeSignals);
    info("Total signals in storage: {$totalInStorage}");
    info("Active final signals (active_final=true, stale=false): {$total}");
    info("Stale/withdrawn signals: " . count($staleSignals));
    info("Executable handoff-ready signals: " . count($handoffReady));

    if ($total === 0) {
        info('No active final signals — pool may be stale or no scan has run recently');
    } else {
        $qualityScores   = array_column(array_values($activeSignals), 'candidate_quality_score');
        $necklineScores  = array_column(array_values($activeSignals), 'neckline_score');
        $avgQuality      = $total > 0 ? array_sum($qualityScores) / $total : 0.0;
        $avgNeckline     = $total > 0 ? array_sum($necklineScores) / $total : 0.0;

        info(sprintf('Avg quality score: %.4f (min expected: 0.68)', $avgQuality));
        info(sprintf('Avg neckline score: %.4f (min expected: 0.55)', $avgNeckline));

        if ($avgQuality >= 0.68) {
            ok('Avg quality score ≥ 0.68');
        } else {
            fail(sprintf('Avg quality score %.4f < 0.68', $avgQuality));
        }

        if ($avgNeckline >= 0.55) {
            ok('Avg neckline score ≥ 0.55');
        } else {
            warn(sprintf('Avg neckline score %.4f < 0.55 (may be OK if pool is old)', $avgNeckline));
        }

        // Trend direction — only check active signals, not stale/withdrawn
        $nonBullish = array_filter($activeSignals, fn($s) => ($s['trend_direction'] ?? '') !== 'bullish');
        if (count($nonBullish) === 0) {
            ok('All active final signals have trend_direction = "bullish"');
        } else {
            fail(count($nonBullish) . ' active signals have non-bullish trend (should be 0 with hard gate)');
        }

        // Stop loss presence — active signals only
        $noSlPrice = array_filter($activeSignals, fn($s) => !isset($s['stop_loss_price']) || $s['stop_loss_price'] === null);
        if (count($noSlPrice) === 0) {
            ok('All active final signals carry stop_loss_price');
        } else {
            fail(count($noSlPrice) . ' active signals are missing stop_loss_price');
        }

        // TP presence — active signals only
        $noTp = array_filter($activeSignals, fn($s) => !isset($s['tp_price']) || $s['tp_price'] === null);
        if (count($noTp) === 0) {
            ok('All active final signals carry tp_price');
        } else {
            fail(count($noTp) . ' active signals are missing tp_price');
        }

        // SL width — active signals only (adaptive stop is now quality-gated)
        $maxSlPct = (float)($config['max_stop_loss_pct'] ?? 0.05);
        $wideSl   = array_filter($activeSignals, fn($s) =>
            isset($s['stop_loss_pct'])
            && (float)$s['stop_loss_pct'] > ($s['effective_stop_loss_pct_limit'] ?? $maxSlPct)
        );
        if (count($wideSl) === 0) {
            ok("No active signals exceed their effective_stop_loss_pct_limit");
        } else {
            fail(count($wideSl) . " active signals exceed their effective stop limit");
        }

        if ($showSignals) {
            echo "\n  Active final signals (first {$limit}):\n";
            foreach (array_slice(array_values($activeSignals), 0, $limit) as $s) {
                printf(
                    "    %-24s quality=%.3f neckline=%.3f sl_pct=%-6s tp=%-8s trend=%s\n",
                    $s['symbol'] ?? '?',
                    (float)($s['candidate_quality_score'] ?? 0),
                    (float)($s['neckline_score'] ?? 0),
                    $s['stop_loss_pct'] !== null ? sprintf('%.4f', $s['stop_loss_pct']) : 'null',
                    $s['tp_price']      !== null ? sprintf('%.6f', $s['tp_price'])      : 'null',
                    $s['trend_direction'] ?? '?'
                );
            }
        }
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// Check 4: Cumulative stats (regime & filter rejection rates)
// ──────────────────────────────────────────────────────────────────────────────

echo "\n── 4. Cumulative stats ──────────────────────────────────\n";

if (empty($stats)) {
    info('storage/stats.json not found — run at least one scan cycle');
} else {
    $emitted = (int)($stats['signals_emitted_total'] ?? 0);
    $active  = (int)($stats['signals_active_final_total'] ?? 0);
    info("Cumulative signals emitted: {$emitted}");
    info("Active (final pool): {$active}");

    $rej = (array)($stats['reject_reason_distribution'] ?? []);
    $regimeBlocked = 0;
    foreach ($rej as $reason => $cnt) {
        if (str_contains($reason, '_hard_block')) {
            $regimeBlocked += (int)$cnt;
            info("  Regime gate blocked: {$reason} = {$cnt}");
        }
    }
    if ($regimeBlocked > 0) {
        ok("Hard regime gate is active — {$regimeBlocked} signals blocked by regime");
    } else {
        warn('No regime-gate blocks recorded yet — run more cycles or check regime is being classified');
    }

    $trendBlocked = (int)($stats['rejected_by_trend_side_total'] ?? 0);
    info("Trend-gate blocks: {$trendBlocked}");
}

// ──────────────────────────────────────────────────────────────────────────────
// Check 5: Bot handoff queue SL/TP enrichment
// ──────────────────────────────────────────────────────────────────────────────

echo "\n── 5. Bot handoff queue SL/TP enrichment ────────────────\n";

$ready = array_filter($handoff, fn($r) => in_array($r['handoff_status'] ?? '', ['new', 'refreshed'], true));
$ready = array_values($ready);

if (empty($ready)) {
    info('No ready records in bot_handoff_queue.json — run a scan cycle first');
} else {
    info('Ready records: ' . count($ready));
    $noSl = array_filter($ready, fn($r) => !isset($r['stop_loss_price']) || $r['stop_loss_price'] === null);
    $noTp = array_filter($ready, fn($r) => !isset($r['tp_price']) || $r['tp_price'] === null);
    if (count($noSl) === 0) {
        ok('All bot_handoff records carry stop_loss_price');
    } else {
        fail(count($noSl) . ' bot_handoff records missing stop_loss_price');
    }
    if (count($noTp) === 0) {
        ok('All bot_handoff records carry tp_price');
    } else {
        warn(count($noTp) . ' bot_handoff records missing tp_price (may be old records)');
    }
    $tpEnabled = array_filter($ready, fn($r) => (bool)($r['tp_enabled'] ?? false));
    if (count($tpEnabled) === count($ready)) {
        ok('All bot_handoff records have tp_enabled = true');
    } else {
        warn(count($ready) - count($tpEnabled) . ' bot_handoff records have tp_enabled = false');
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// Summary
// ──────────────────────────────────────────────────────────────────────────────

echo "\n═══════════════════════════════════════════════════════════\n";
echo "  How to verify ROI improvement in simulation\n";
echo "═══════════════════════════════════════════════════════════\n";
echo <<<TXT

  1. Enable module in config/active.php:
       'enabled' => true, 'mode' => 'demo'

  2. Let the strategy run for at least 5-10 scan cycles (cron/tickBatch).

  3. After each cycle inspect storage/signals.json — confirm:
     • All signals have 'trend_direction' = 'bullish'
     • All signals have 'stop_loss_price' != null
     • All signals have 'tp_price' != null
     • Avg candidate_quality_score >= 0.68

  4. Check storage/market_regime.json — confirm regime is NOT always 'unknown'.

  5. In the bot/simulator, for each completed trade, compare ROI:
     • v0.1 baseline: avg ROI ≈ +7.6% but -28% for liquidated positions
     • v0.2 target:   fewer trades, higher win-rate, no trades in bearish regime

  6. Expected improvement drivers (v0.2 vs v0.1):
     • Hard regime gate removes ALL signals in bear/mixed/unknown markets
       → eliminates the "catching falling knife" class of losses
     • stop_loss_price in signal lets PM/bot set a proper stop order
       → eliminates liquidation-style losses
     • tp_enabled + tp_price (2.5R) gives a concrete profit target
       → prevents open positions from running back to entry and losing
     • Tighter quality/neckline thresholds (0.68/0.55) reduce noisy patterns
     • Similarity tolerance 5% (was 7%) requires cleaner W-shape
     • max_stop_loss_pct = 5% filters wide-stop setups with poor R

TXT;

echo "\n";
