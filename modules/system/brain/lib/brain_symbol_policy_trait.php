<?php
declare(strict_types=1);

namespace Modules\System\Brain\Lib;

/**
 * Brain — Symbol Policy (Live stats driven)
 *
 * Goal:
 * - Stop doing "one temperature for the whole hospital".
 * - Apply safety gates per-symbol based on REAL closed trades (Trading Bot) and/or training data.
 *
 * This trait is intentionally conservative:
 * - By default it DOES NOT auto-invert long/short.
 * - It can (a) reject weak symbols, (b) enforce wait_retrace, (c) apply cooldown after loss streak.
 *
 * Data source (primary): Trading Bot closed trades files.
 * Expected location is resolved via SystemPaths key(s) discovered in BrainCoreTrait.
 *
 * @package Modules\System\Brain
 */
trait BrainSymbolPolicyTrait
{
    /** @var array{ts:int,stats:array<string,mixed>} Cached stats to avoid heavy IO on every signal */
    private array $symbolPolicyCache = [
        'ts' => 0,
        'stats' => [],
    ];

    /**
     * Get effective symbol policy config (with safe defaults)
     *
     * @return array<string,mixed>
     */
    private function getSymbolPolicyConfig(): array
    {
        $cfg = $this->getConfig('symbol_policy', []);
        if (!is_array($cfg)) {
            $cfg = [];
        }

        // Defaults (safe / conservative)
        $cfg += [
            'enabled' => true,

            // Live trades source
            'use_live_trades' => true,
            'live_trades_window' => 200, // total files to scan (most recent)
            'cache_ttl_sec' => 30,

            // Safety gates
            'min_trades' => 8,
            'min_win_rate' => 0.52,          // below → reject (if also avg_roi is bad)
            'min_avg_roi_pct' => 0.00,       // ROI on margin, %
            'max_loss_streak' => 3,          // consecutive losses → cooldown
            'cooldown_minutes' => 180,

            // Entry hardening
            'force_wait_retrace_on_weak' => true,
            'weak_win_rate_threshold' => 0.55, // below → prefer wait_retrace (even if not reject)
            'weak_entry_timeout_minutes' => 12, // retrace timeout clamp
            'weak_retrace_deepen_factor' => 0.50, // use part of typical MAE to deepen entry

            // Side preference (per-symbol) - default: do NOT invert automatically
            'prefer_side_by_roi' => true,
            'min_side_delta_roi_pct' => 0.30, // difference between avg ROI (pct) to consider side preference
            'side_mismatch_action' => 'reject', // reject|invert|allow (invert is optional)

            // Manual per-symbol overrides
            // overrides[SYMBOL] = ['disabled'=>bool, 'reverse_side'=>bool|null, 'force_side'=>'long|short|null', 'min_win_rate'=>float|null, ...]
            'overrides' => [],
        ];

        return $cfg;
    }

    /**
     * Load per-symbol live stats from Trading Bot closed trades.
     * Cached for short TTL.
     *
     * @return array<string,mixed> stats keyed by SYMBOL
     */
    private function loadLiveSymbolStats(): array
    {
        $cfg = $this->getSymbolPolicyConfig();
        if (!(bool)($cfg['enabled'] ?? true)) {
            return [];
        }
        if (!(bool)($cfg['use_live_trades'] ?? true)) {
            return [];
        }

        $ttl = (int)($cfg['cache_ttl_sec'] ?? 30);
        $now = time();

        if (!empty($this->symbolPolicyCache['stats']) && ($now - (int)$this->symbolPolicyCache['ts']) <= $ttl) {
            return (array)$this->symbolPolicyCache['stats'];
        }

        $stats = $this->computeLiveSymbolStatsFromTradingBot(
            (int)($cfg['live_trades_window'] ?? 200)
        );

        $this->symbolPolicyCache = [
            'ts' => $now,
            'stats' => $stats,
        ];

        // Save for UI/debug
        $this->persistSymbolStats($stats);

        return $stats;
    }

    /**
     * Compute per-symbol stats from Trading Bot closed trade json files.
     *
     * @param int $window Max number of most-recent closed trades to scan (global)
     * @return array<string,mixed>
     */
    private function computeLiveSymbolStatsFromTradingBot(int $window = 200): array
    {
        $window = max(10, min(5000, $window));

        $tbBase = $this->modulePaths['trading_bot'] ?? null;
        if (!is_string($tbBase) || $tbBase === '') {
            return [];
        }

        $closedDir = $this->resolveTradingBotClosedDir($tbBase);
        if ($closedDir === null) {
            return [];
        }

        $files = glob($closedDir . '/*.json') ?: [];
        if (empty($files)) {
            return [];
        }

        // Most recent files first
        usort($files, static fn($a, $b) => (int)@filemtime($b) <=> (int)@filemtime($a));
        $files = array_slice($files, 0, $window);

        $per = []; // symbol => list of trades
        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $t = json_decode($raw, true);
            if (!is_array($t)) {
                continue;
            }
            $symbol = (string)($t['symbol'] ?? '');
            if ($symbol === '') {
                continue;
            }

            $side = (string)($t['side'] ?? '');
            $realized = (float)($t['realized_pnl'] ?? $t['pnl'] ?? 0.0);

            $risk = $t['risk'] ?? [];
            $budget = 0.0;
            if (is_array($risk)) {
                $budget = (float)($risk['budget_usdt_per_trade'] ?? 0.0);
            }
            $roiPct = 0.0;
            if ($budget > 0.000001) {
                $roiPct = ($realized / $budget) * 100.0;
            }

            $entry = (float)($t['entry_price'] ?? 0.0);
            $hi = (float)($t['high_watermark'] ?? 0.0);
            $lo = (float)($t['low_watermark'] ?? 0.0);

            $maePct = 0.0;
            $mfePct = 0.0;
            if ($entry > 0.0 && $hi > 0.0 && $lo > 0.0) {
                if ($side === 'long') {
                    $maePct = (($lo - $entry) / $entry) * 100.0;   // negative
                    $mfePct = (($hi - $entry) / $entry) * 100.0;   // positive
                } elseif ($side === 'short') {
                    $maePct = (($entry - $hi) / $entry) * 100.0;   // negative
                    $mfePct = (($entry - $lo) / $entry) * 100.0;   // positive
                }
            }

            $closedTs = (int)($t['closed_ts'] ?? 0);
            if ($closedTs <= 0) {
                $closedTs = (int)@filemtime($file);
            }

            $per[$symbol][] = [
                'closed_ts' => $closedTs,
                'side' => $side,
                'realized_pnl' => $realized,
                'roi_pct' => $roiPct,
                'mae_pct' => $maePct,
                'mfe_pct' => $mfePct,
            ];
        }

        $out = [];
        foreach ($per as $symbol => $items) {
            if (empty($items)) {
                continue;
            }

            // Sort by close time ASC for streak
            usort($items, static fn($a, $b) => (int)$a['closed_ts'] <=> (int)$b['closed_ts']);

            $total = count($items);
            $wins = 0;
            $sumRoi = 0.0;

            $roiLong = [];
            $roiShort = [];
            $maeAbs = [];

            foreach ($items as $it) {
                $roi = (float)($it['roi_pct'] ?? 0.0);
                $sumRoi += $roi;

                $pnl = (float)($it['realized_pnl'] ?? 0.0);
                if ($pnl > 0.0) {
                    $wins++;
                }

                $side = (string)($it['side'] ?? '');
                if ($side === 'long') {
                    $roiLong[] = $roi;
                } elseif ($side === 'short') {
                    $roiShort[] = $roi;
                }

                $mae = (float)($it['mae_pct'] ?? 0.0);
                if ($mae < 0.0) {
                    $maeAbs[] = abs($mae);
                }
            }

            $winRate = ($total > 0) ? ($wins / $total) : 0.0;
            $avgRoi = ($total > 0) ? ($sumRoi / $total) : 0.0;

            // Current loss streak (from last trades)
            $lossStreak = 0;
            for ($i = $total - 1; $i >= 0; $i--) {
                $pnl = (float)($items[$i]['realized_pnl'] ?? 0.0);
                if ($pnl < 0.0) {
                    $lossStreak++;
                } else {
                    break;
                }
            }

            $avgRoiLong = !empty($roiLong) ? (array_sum($roiLong) / count($roiLong)) : 0.0;
            $avgRoiShort = !empty($roiShort) ? (array_sum($roiShort) / count($roiShort)) : 0.0;

            $medianMaeAbs = 0.0;
            if (!empty($maeAbs)) {
                sort($maeAbs);
                $mid = (int)floor((count($maeAbs) - 1) / 2);
                if (count($maeAbs) % 2 === 1) {
                    $medianMaeAbs = (float)$maeAbs[$mid];
                } else {
                    $medianMaeAbs = ((float)$maeAbs[$mid] + (float)$maeAbs[$mid + 1]) / 2.0;
                }
            }

            $out[$symbol] = [
                'symbol' => $symbol,
                'trades_total' => $total,
                'wins' => $wins,
                'win_rate' => $winRate,
                'avg_roi_pct' => $avgRoi,
                'avg_roi_long_pct' => $avgRoiLong,
                'avg_roi_short_pct' => $avgRoiShort,
                'loss_streak' => $lossStreak,
                'median_mae_abs_pct' => $medianMaeAbs,
                'last_closed_ts' => (int)($items[$total - 1]['closed_ts'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Resolve Trading Bot closed trades directory from discovered base path.
     *
     * @param string $tbBase Discovered base dir (could be module base or storage base)
     * @return string|null
     */
    private function resolveTradingBotClosedDir(string $tbBase): ?string
    {
        $candidates = [
            rtrim($tbBase, '/') . '/trades/closed',
            rtrim($tbBase, '/') . '/storage/trades/closed',
        ];

        foreach ($candidates as $dir) {
            if (is_dir($dir)) {
                return $dir;
            }
        }

        return null;
    }

    /**
     * Persist live symbol stats into Brain storage runtime for UI/debug.
     *
     * @param array<string,mixed> $stats
     * @return void
     */
    private function persistSymbolStats(array $stats): void
    {
        $runtimeDir = $this->storageDir . '/runtime';
        if (!is_dir($runtimeDir)) {
            @mkdir($runtimeDir, 0755, true);
        }

        $file = $runtimeDir . '/symbol_stats_live.json';
        $payload = [
            'schema_version' => 'symbol_stats_live_v1',
            'generated_at' => date('c'),
            'generated_ts' => time(),
            'count' => count($stats),
            'stats' => $stats,
        ];

        @file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Apply symbol policy to normalized signal (clean_signal_v1).
     * Returns null to reject the signal.
     *
     * @param array<string,mixed> $signal Normalized signal (will be copied/modified)
     * @param array<int,array<string,mixed>> $liveStats Live stats cache
     * @param array<int,array<string,mixed>> &$gatewayRejections Reference rejections list
     * @return array<string,mixed>|null
     */
    private function applySymbolPolicyToSignal(array $signal, array $liveStats, array &$gatewayRejections): ?array
    {
        $cfg = $this->getSymbolPolicyConfig();
        if (!(bool)($cfg['enabled'] ?? true)) {
            return $signal;
        }

        $symbol = (string)($signal['symbol'] ?? '');
        $side = (string)($signal['side'] ?? '');

        if ($symbol === '' || ($side !== 'long' && $side !== 'short')) {
            return $signal;
        }

        // Manual overrides (highest priority)
        $overrides = $cfg['overrides'] ?? [];
        $ov = (is_array($overrides) && isset($overrides[$symbol]) && is_array($overrides[$symbol])) ? $overrides[$symbol] : [];

        if (!empty($ov['disabled'])) {
            $gatewayRejections[] = [
                'signal_id' => $signal['trade_id'] ?? $signal['id'] ?? 'unknown',
                'symbol' => $symbol,
                'reason' => 'symbol_policy_disabled',
                'details' => 'Manual override: disabled',
                'rejected_at' => date('c'),
            ];
            return null;
        }

        $st = $liveStats[$symbol] ?? null;
        if (!is_array($st)) {
            // No live stats yet → allow (do not block new symbols)
            $signal['brain']['symbol_policy'] = [
                'source' => 'live_stats',
                'status' => 'no_stats',
            ];
            return $signal;
        }

        $minTrades = (int)($ov['min_trades'] ?? $cfg['min_trades'] ?? 8);
        $minWinRate = (float)($ov['min_win_rate'] ?? $cfg['min_win_rate'] ?? 0.52);
        $minAvgRoi = (float)($ov['min_avg_roi_pct'] ?? $cfg['min_avg_roi_pct'] ?? 0.0);

        $total = (int)($st['trades_total'] ?? 0);
        if ($total < $minTrades) {
            $signal['brain']['symbol_policy'] = [
                'source' => 'live_stats',
                'status' => 'not_enough_trades',
                'trades_total' => $total,
                'min_trades' => $minTrades,
            ];
            return $signal;
        }

        $winRate = (float)($st['win_rate'] ?? 0.0);
        $avgRoi = (float)($st['avg_roi_pct'] ?? 0.0);
        $lossStreak = (int)($st['loss_streak'] ?? 0);

        // Cooldown after loss streak
        $maxLossStreak = (int)($ov['max_loss_streak'] ?? $cfg['max_loss_streak'] ?? 3);
        $cooldownMin = (int)($ov['cooldown_minutes'] ?? $cfg['cooldown_minutes'] ?? 180);

        if ($maxLossStreak > 0 && $lossStreak >= $maxLossStreak) {
            $gatewayRejections[] = [
                'signal_id' => $signal['trade_id'] ?? $signal['id'] ?? 'unknown',
                'symbol' => $symbol,
                'reason' => 'symbol_policy_cooldown_loss_streak',
                'details' => "loss_streak={$lossStreak} >= {$maxLossStreak} (cooldown {$cooldownMin}m)",
                'rejected_at' => date('c'),
            ];

            // Keep short info for UI
            $signal['brain']['symbol_policy'] = [
                'source' => 'live_stats',
                'status' => 'cooldown',
                'loss_streak' => $lossStreak,
                'max_loss_streak' => $maxLossStreak,
                'cooldown_minutes' => $cooldownMin,
                'win_rate' => $winRate,
                'avg_roi_pct' => $avgRoi,
            ];

            return null;
        }

        // Side preference (optional)
        $bestSide = null;
        if ((bool)($cfg['prefer_side_by_roi'] ?? true)) {
            $avgLong = (float)($st['avg_roi_long_pct'] ?? 0.0);
            $avgShort = (float)($st['avg_roi_short_pct'] ?? 0.0);
            $delta = abs($avgLong - $avgShort);
            $minDelta = (float)($cfg['min_side_delta_roi_pct'] ?? 0.30);

            if ($delta >= $minDelta) {
                $bestSide = ($avgLong >= $avgShort) ? 'long' : 'short';
            }
        }

        // Underperform reject gate (strict)
        if ($winRate < $minWinRate && $avgRoi < $minAvgRoi) {
            $gatewayRejections[] = [
                'signal_id' => $signal['trade_id'] ?? $signal['id'] ?? 'unknown',
                'symbol' => $symbol,
                'reason' => 'symbol_policy_underperform',
                'details' => sprintf('win_rate=%.3f (<%.3f), avg_roi=%.3f (<%.3f), trades=%d', $winRate, $minWinRate, $avgRoi, $minAvgRoi, $total),
                'rejected_at' => date('c'),
            ];
            return null;
        }

        // Side mismatch action (default reject)
        if ($bestSide !== null && $bestSide !== $side) {
            $act = (string)($cfg['side_mismatch_action'] ?? 'reject');
            if ($act === 'reject') {
                $gatewayRejections[] = [
                    'signal_id' => $signal['trade_id'] ?? $signal['id'] ?? 'unknown',
                    'symbol' => $symbol,
                    'reason' => 'symbol_policy_side_mismatch',
                    'details' => "best_side={$bestSide}, signal_side={$side}",
                    'rejected_at' => date('c'),
                ];
                return null;
            }
            if ($act === 'invert') {
                // Optional (dangerous): Only invert if explicitly enabled globally or per-symbol override
                $allowInvert = (bool)($ov['allow_invert'] ?? false);
                if ($allowInvert) {
                    $signal['brain']['side_original'] = $side;
                    $signal['side'] = ($side === 'long') ? 'short' : 'long';
                }
            }
        }

        // Weak symbols: enforce wait_retrace (hardening)
        if ((bool)($cfg['force_wait_retrace_on_weak'] ?? true)) {
            $weakThr = (float)($cfg['weak_win_rate_threshold'] ?? 0.55);
            if ($winRate < $weakThr) {
                $signal['entry_action'] = 'wait_retrace';

                // Deepen entry price by typical MAE portion (optional)
                $deepenFactor = (float)($cfg['weak_retrace_deepen_factor'] ?? 0.50);
                $maeAbs = (float)($st['median_mae_abs_pct'] ?? 0.0);
                $entryPrice = (float)($signal['entry_price'] ?? ($signal['entry']['price'] ?? 0.0));
                if ($entryPrice > 0.0 && $maeAbs > 0.0 && $deepenFactor > 0.0) {
                    $shiftPct = ($maeAbs * $deepenFactor) / 100.0;
                    if ($side === 'long') {
                        $entryPrice = $entryPrice * (1.0 - $shiftPct);
                    } else {
                        $entryPrice = $entryPrice * (1.0 + $shiftPct);
                    }
                    $signal['entry_price'] = $entryPrice;
                    if (isset($signal['entry']) && is_array($signal['entry'])) {
                        $signal['entry']['price'] = $entryPrice;
                    }
                }

                // Clamp timeout to "weak" value if larger not set by passport
                $weakTimeout = (int)($cfg['weak_entry_timeout_minutes'] ?? 12);
                $signal['entry_timeout_minutes'] = max((int)($signal['entry_timeout_minutes'] ?? 0), $weakTimeout);
            }
        }

        // Attach short debug block (no huge payload)
        $signal['brain']['symbol_policy'] = [
            'source' => 'live_stats',
            'status' => 'applied',
            'trades_total' => $total,
            'win_rate' => $winRate,
            'avg_roi_pct' => $avgRoi,
            'loss_streak' => $lossStreak,
            'best_side' => $bestSide,
        ];

        return $signal;
    }
}

/* RULES
- Purpose: Per-symbol policy gates based on live closed-trades stats (Trading Bot).
- Uses SystemPaths-discovered Trading Bot storage; no hardcoded paths.
- Conservative defaults: reject weak symbols, enforce wait_retrace for weak symbols, cooldown after loss streak.
- Does NOT auto-invert side by default; optional per-symbol allow_invert exists but is OFF by default.
- LF only
*/
