<?php
/**
 * Smart Brain — Full Simulation Audit Script
 *
 * Analyzes simulator data, collects pattern statistics,
 * audits side/leverage/stop logic, and produces a JSON report.
 *
 * Usage:
 *   php simulation_audit.php
 *
 * Output:
 *   storage/simulation_audit_report.json
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/state_manager.php';

final class SimulationAudit
{
    private StateManager $state;
    private string $moduleBase;

    public function __construct(string $moduleBase)
    {
        $this->moduleBase = rtrim($moduleBase, '/');
        $this->state = new StateManager($this->moduleBase);
    }

    /**
     * Run the full audit and return the report array.
     *
     * @return array<string,mixed>
     */
    public function run(): array
    {
        $waiting = $this->state->readJson('storage/simulator/waiting.json', []);
        $active  = $this->state->readJson('storage/simulator/active.json', []);
        $closed  = $this->state->readJson('storage/simulator/closed.json', []);
        $signals = $this->state->readJson('storage/signals.json', []);
        $monitors = $this->state->readJson('storage/monitors.json', []);
        $candidates = $this->state->readJson('storage/candidates.json', []);
        $lastRun = $this->state->readJson('storage/last_run.json', []);
        $effectiveCfg = $this->state->readJson('runtime/effective_config.json', []);
        $userCfg = $this->state->readJson('runtime/user_config.json', []);

        $report = [];
        $report['audit_timestamp'] = date('c');
        $report['data_summary'] = [
            'candidates_count' => count($candidates),
            'monitors_count' => count($monitors),
            'signals_count' => count($signals),
            'waiting_count' => count($waiting),
            'active_count' => count($active),
            'closed_count' => count($closed),
            'has_last_run' => !empty($lastRun),
            'has_effective_config' => !empty($effectiveCfg),
            'has_user_config' => !empty($userCfg),
        ];

        // Part 1: Pattern Statistics
        $report['pattern_statistics'] = $this->computePatternStats($closed, $signals, $waiting, $active);

        // Part 2: Side Audit
        $report['side_audit'] = $this->auditSide($candidates, $signals, $closed, $active);

        // Part 3: Leverage Audit
        $report['leverage_audit'] = $this->auditLeverage($signals, $closed);

        // Part 4: Stop Engine Audit
        $report['stop_engine_audit'] = $this->auditStopEngine($closed);

        // Part 5: Simulator Lifecycle Audit
        $report['lifecycle_audit'] = $this->auditLifecycle($waiting, $active, $closed, $signals);

        // Part 6: Long vs Short
        $report['long_vs_short'] = $this->auditLongVsShort($closed);

        // Part 7: Bootstrap vs Normal
        $report['bootstrap_vs_normal'] = $this->auditBootstrapVsNormal($signals, $closed);

        // Part 8: Analyzer / Gating Audit
        $report['analyzer_audit'] = $this->auditAnalyzer($lastRun, $effectiveCfg);

        // Part 9: Stats Engine Audit
        $report['stats_engine_audit'] = $this->auditStatsEngine($waiting, $active, $closed, $signals);

        // Part 10: Config Audit
        $report['config_audit'] = $this->auditConfig($effectiveCfg, $userCfg);

        // Part 11: Reversal V1 vs V2 Comparison
        $report['reversal_comparison'] = $this->auditReversalComparison($closed);

        // Part 12: Regression Audit — short-side collapse analysis
        $report['regression_audit'] = $this->auditRegression($closed);

        // Part 13: Focused double_bottom/long regression audit
        $report['double_bottom_long_audit'] = $this->auditDoubleBottomLong($closed);

        return $report;
    }

    /**
     * Part 1: Pattern Statistics per algorithm
     *
     * @return array<string,mixed>
     */
    private function computePatternStats(array $closed, array $signals, array $waiting, array $active): array
    {
        $algorithms = ['double_bottom', 'double_top', 'pullback_trend_continue', 'double_bottom_confirm_v2', 'double_top_confirm_v2', '_unknown'];

        $stats = [];
        foreach ($algorithms as $algo) {
            $stats[$algo] = [
                'signals_total' => 0,
                'waiting_total' => 0,
                'active_total' => 0,
                'closed_total' => 0,
                'wins' => 0,
                'losses' => 0,
                'winrate' => 0.0,
                'average_roi' => 0.0,
                'average_mae' => 0.0,
                'average_mfe' => 0.0,
                'average_duration' => 0.0,
                'long_count' => 0,
                'short_count' => 0,
                'stop_loss_count' => 0,
                'early_failure_count' => 0,
                'trailing_stop_count' => 0,
                'break_even_stop_count' => 0,
                'take_profit_count' => 0,
                'bootstrap_signals_count' => 0,
                'normal_signals_count' => 0,
                'average_leverage' => 0.0,
            ];
        }

        // Count signals by pattern
        foreach ($signals as $s) {
            $algo = $this->getAlgorithm($s);
            $stats[$algo]['signals_total']++;
            $mode = (string)($s['signal_mode'] ?? '');
            if ($mode === 'bootstrap') {
                $stats[$algo]['bootstrap_signals_count']++;
            } else {
                $stats[$algo]['normal_signals_count']++;
            }
        }

        // Count waiting by pattern
        foreach ($waiting as $w) {
            $algo = $this->getAlgorithm($w);
            $stats[$algo]['waiting_total']++;
        }

        // Count active by pattern
        foreach ($active as $a) {
            $algo = $this->getAlgorithm($a);
            $stats[$algo]['active_total']++;
        }

        // Analyze closed trades
        foreach ($algorithms as $algo) {
            $algoTrades = array_filter($closed, fn($t) => $this->getAlgorithm($t) === $algo);
            $count = count($algoTrades);
            $stats[$algo]['closed_total'] = $count;

            if ($count === 0) {
                continue;
            }

            $rois = [];
            $maes = [];
            $mfes = [];
            $durations = [];
            $leverages = [];

            foreach ($algoTrades as $t) {
                $roi = (float)($t['roi'] ?? 0.0);
                $rois[] = $roi;
                $maes[] = (float)($t['mae'] ?? 0.0);
                $mfes[] = (float)($t['mfe'] ?? 0.0);
                $durations[] = (float)($t['duration'] ?? 0.0);
                $leverages[] = (float)($t['leverage'] ?? 0.0);

                if ($roi >= 0) {
                    $stats[$algo]['wins']++;
                } else {
                    $stats[$algo]['losses']++;
                }

                $side = (string)($t['side'] ?? '');
                if ($side === 'long') {
                    $stats[$algo]['long_count']++;
                } elseif ($side === 'short') {
                    $stats[$algo]['short_count']++;
                }

                $reason = (string)($t['reason'] ?? '');
                match ($reason) {
                    'stop_loss' => $stats[$algo]['stop_loss_count']++,
                    'early_failure' => $stats[$algo]['early_failure_count']++,
                    'trailing_stop' => $stats[$algo]['trailing_stop_count']++,
                    'break_even_stop' => $stats[$algo]['break_even_stop_count']++,
                    'take_profit' => $stats[$algo]['take_profit_count']++,
                    default => null,
                };
            }

            $stats[$algo]['winrate'] = round($stats[$algo]['wins'] / $count, 4);
            $stats[$algo]['average_roi'] = round(array_sum($rois) / $count, 6);
            $stats[$algo]['average_mae'] = round(array_sum($maes) / $count, 6);
            $stats[$algo]['average_mfe'] = round(array_sum($mfes) / $count, 6);
            $stats[$algo]['average_duration'] = round(array_sum($durations) / $count, 2);
            $nonZeroLeverages = array_filter($leverages, fn($l) => $l > 0);
            $stats[$algo]['average_leverage'] = count($nonZeroLeverages) > 0
                ? round(array_sum($nonZeroLeverages) / count($nonZeroLeverages), 2)
                : 0.0;
        }

        // Remove _unknown if empty
        if ($stats['_unknown']['closed_total'] === 0
            && $stats['_unknown']['signals_total'] === 0
            && $stats['_unknown']['waiting_total'] === 0
            && $stats['_unknown']['active_total'] === 0
        ) {
            unset($stats['_unknown']);
        }

        return $stats;
    }

    /**
     * Part 2: Side Audit
     *
     * @return array<string,mixed>
     */
    private function auditSide(array $candidates, array $signals, array $closed, array $active): array
    {
        $result = [
            'side_by_pattern' => [],
            'wrong_side_examples' => [],
            'unresolved_side_count' => 0,
        ];

        // Expected side mapping
        $expectedSide = [
            'double_bottom' => 'long',
            'double_top' => 'short',
            'double_bottom_confirm_v2' => 'long',
            'double_top_confirm_v2' => 'short',
        ];

        // Count sides by pattern for closed trades
        $sideByPattern = [];
        foreach ($closed as $t) {
            $algo = $this->getAlgorithm($t);
            $side = (string)($t['side'] ?? 'unknown');
            if (!isset($sideByPattern[$algo])) {
                $sideByPattern[$algo] = [];
            }
            $sideByPattern[$algo][$side] = ($sideByPattern[$algo][$side] ?? 0) + 1;
        }

        // Also count from signals
        foreach ($signals as $s) {
            $algo = $this->getAlgorithm($s);
            $side = (string)($s['side'] ?? 'unknown');
            if (!isset($sideByPattern[$algo])) {
                $sideByPattern[$algo] = [];
            }
            // Prefix signal counts separately
            $key = $side . '_signals';
            $sideByPattern[$algo][$key] = ($sideByPattern[$algo][$key] ?? 0) + 1;
        }

        $result['side_by_pattern'] = $sideByPattern;

        // Check for wrong side (e.g., double_bottom should be long)
        foreach (array_merge($closed, $active) as $t) {
            $algo = (string)($t['pattern_algorithm'] ?? '');
            $side = (string)($t['side'] ?? '');
            if (isset($expectedSide[$algo]) && $side !== '' && $side !== $expectedSide[$algo]) {
                $result['wrong_side_examples'][] = [
                    'symbol' => (string)($t['symbol'] ?? ''),
                    'pattern' => $algo,
                    'expected_side' => $expectedSide[$algo],
                    'actual_side' => $side,
                ];
            }
            if ($side === '' || $side === 'unknown') {
                $result['unresolved_side_count']++;
            }
        }

        return $result;
    }

    /**
     * Part 3: Leverage Audit
     *
     * @return array<string,mixed>
     */
    private function auditLeverage(array $signals, array $closed): array
    {
        $leverages = [];
        $bootstrapLeverages = [];
        $normalLeverages = [];

        foreach (array_merge($signals, $closed) as $item) {
            $lev = (float)($item['leverage'] ?? 0);
            if ($lev <= 0) {
                continue;
            }
            $leverages[] = $lev;

            $mode = (string)($item['signal_mode'] ?? '');
            if ($mode === 'bootstrap') {
                $bootstrapLeverages[] = $lev;
            } else {
                $normalLeverages[] = $lev;
            }
        }

        $distribution = [];
        foreach ($leverages as $lev) {
            $key = (string)(int)$lev . 'x';
            $distribution[$key] = ($distribution[$key] ?? 0) + 1;
        }
        ksort($distribution);

        return [
            'total_samples' => count($leverages),
            'min_leverage' => $leverages !== [] ? min($leverages) : 0,
            'max_leverage' => $leverages !== [] ? max($leverages) : 0,
            'average_leverage' => $leverages !== [] ? round(array_sum($leverages) / count($leverages), 2) : 0,
            'bootstrap_average' => $bootstrapLeverages !== []
                ? round(array_sum($bootstrapLeverages) / count($bootstrapLeverages), 2) : 0,
            'normal_average' => $normalLeverages !== []
                ? round(array_sum($normalLeverages) / count($normalLeverages), 2) : 0,
            'distribution' => $distribution,
        ];
    }

    /**
     * Part 4: Stop Engine Audit
     *
     * @return array<string,mixed>
     */
    private function auditStopEngine(array $closed): array
    {
        $modes = ['simple_liq_percent', 'brain_managed', '_unknown'];
        $result = [];

        foreach ($modes as $mode) {
            $modeTrades = array_filter($closed, function ($t) use ($mode) {
                $m = (string)($t['stop_mode'] ?? '');
                return $mode === '_unknown' ? ($m === '') : ($m === $mode);
            });

            $count = count($modeTrades);
            $stats = [
                'closed_total' => $count,
                'stop_loss_count' => 0,
                'early_failure_count' => 0,
                'trailing_stop_count' => 0,
                'break_even_stop_count' => 0,
                'take_profit_count' => 0,
                'average_roi' => 0.0,
                'average_mae' => 0.0,
                'average_mfe' => 0.0,
                'winrate' => 0.0,
            ];

            if ($count > 0) {
                $rois = [];
                $maes = [];
                $mfes = [];
                $wins = 0;

                foreach ($modeTrades as $t) {
                    $roi = (float)($t['roi'] ?? 0.0);
                    $rois[] = $roi;
                    $maes[] = (float)($t['mae'] ?? 0.0);
                    $mfes[] = (float)($t['mfe'] ?? 0.0);
                    if ($roi >= 0) {
                        $wins++;
                    }

                    $reason = (string)($t['reason'] ?? '');
                    match ($reason) {
                        'stop_loss' => $stats['stop_loss_count']++,
                        'early_failure' => $stats['early_failure_count']++,
                        'trailing_stop' => $stats['trailing_stop_count']++,
                        'break_even_stop' => $stats['break_even_stop_count']++,
                        'take_profit' => $stats['take_profit_count']++,
                        default => null,
                    };
                }

                $stats['winrate'] = round($wins / $count, 4);
                $stats['average_roi'] = round(array_sum($rois) / $count, 6);
                $stats['average_mae'] = round(array_sum($maes) / $count, 6);
                $stats['average_mfe'] = round(array_sum($mfes) / $count, 6);
            }

            $result[$mode] = $stats;
        }

        // Remove _unknown if empty
        if ($result['_unknown']['closed_total'] === 0) {
            unset($result['_unknown']);
        }

        return $result;
    }

    /**
     * Part 5: Simulator Lifecycle Audit
     *
     * @return array<string,mixed>
     */
    private function auditLifecycle(array $waiting, array $active, array $closed, array $signals): array
    {
        $stuckInActive = [];
        $now = time();

        foreach ($active as $a) {
            $activated = strtotime((string)($a['activated_at'] ?? ''));
            if ($activated !== false && ($now - $activated) > 86400) { // 24h+
                $stuckInActive[] = [
                    'symbol' => (string)($a['symbol'] ?? ''),
                    'activated_at' => (string)($a['activated_at'] ?? ''),
                    'hours_active' => round(($now - $activated) / 3600, 1),
                ];
            }
        }

        // Compute average time to first close
        $firstCloseTime = null;
        foreach ($closed as $t) {
            $closedAt = strtotime((string)($t['closed_at'] ?? ''));
            if ($closedAt !== false) {
                if ($firstCloseTime === null || $closedAt < $firstCloseTime) {
                    $firstCloseTime = $closedAt;
                }
            }
        }

        return [
            'waiting_count' => count($waiting),
            'active_count' => count($active),
            'closed_count' => count($closed),
            'stuck_trades' => $stuckInActive,
            'stuck_count' => count($stuckInActive),
            'signals_in_current_cycle' => count($signals),
            'first_close_time' => $firstCloseTime !== null ? date('c', $firstCloseTime) : null,
        ];
    }

    /**
     * Part 6: Long vs Short performance
     *
     * @return array<string,mixed>
     */
    private function auditLongVsShort(array $closed): array
    {
        $sides = ['long', 'short'];
        $result = [];

        foreach ($sides as $side) {
            $sideTrades = array_filter($closed, fn($t) => (string)($t['side'] ?? '') === $side);
            $count = count($sideTrades);
            $entry = [
                'trades_total' => $count,
                'winrate' => 0.0,
                'avg_roi' => 0.0,
                'avg_mae' => 0.0,
                'avg_mfe' => 0.0,
            ];

            if ($count > 0) {
                $wins = 0;
                $rois = [];
                $maes = [];
                $mfes = [];
                foreach ($sideTrades as $t) {
                    $roi = (float)($t['roi'] ?? 0.0);
                    $rois[] = $roi;
                    $maes[] = (float)($t['mae'] ?? 0.0);
                    $mfes[] = (float)($t['mfe'] ?? 0.0);
                    if ($roi >= 0) {
                        $wins++;
                    }
                }
                $entry['winrate'] = round($wins / $count, 4);
                $entry['avg_roi'] = round(array_sum($rois) / $count, 6);
                $entry['avg_mae'] = round(array_sum($maes) / $count, 6);
                $entry['avg_mfe'] = round(array_sum($mfes) / $count, 6);
            }

            $result[$side] = $entry;
        }

        return $result;
    }

    /**
     * Part 7: Bootstrap vs Normal
     *
     * @return array<string,mixed>
     */
    private function auditBootstrapVsNormal(array $signals, array $closed): array
    {
        $modes = ['bootstrap', 'normal'];
        $result = [];

        foreach ($modes as $mode) {
            $modeSignals = array_filter($signals, function ($s) use ($mode) {
                $m = (string)($s['signal_mode'] ?? '');
                return $mode === 'normal' ? ($m !== 'bootstrap') : ($m === 'bootstrap');
            });
            $modeClosed = array_filter($closed, function ($t) use ($mode) {
                $m = (string)($t['signal_mode'] ?? '');
                return $mode === 'normal' ? ($m !== 'bootstrap') : ($m === 'bootstrap');
            });

            $count = count($modeClosed);
            $entry = [
                'signals_total' => count($modeSignals),
                'closed_total' => $count,
                'winrate' => 0.0,
                'avg_leverage' => 0.0,
                'avg_roi' => 0.0,
            ];

            if ($count > 0) {
                $wins = 0;
                $rois = [];
                $leverages = [];
                foreach ($modeClosed as $t) {
                    $roi = (float)($t['roi'] ?? 0.0);
                    $rois[] = $roi;
                    $leverages[] = (float)($t['leverage'] ?? 0.0);
                    if ($roi >= 0) {
                        $wins++;
                    }
                }
                $entry['winrate'] = round($wins / $count, 4);
                $entry['avg_roi'] = round(array_sum($rois) / $count, 6);
                $nonZero = array_filter($leverages, fn($l) => $l > 0);
                $entry['avg_leverage'] = count($nonZero) > 0
                    ? round(array_sum($nonZero) / count($nonZero), 2) : 0.0;
            }

            $result[$mode] = $entry;
        }

        return $result;
    }

    /**
     * Part 8: Analyzer / Gating Audit
     *
     * @return array<string,mixed>
     */
    private function auditAnalyzer(array $lastRun, array $effectiveCfg): array
    {
        $parser4 = (array)($effectiveCfg['parser4'] ?? []);
        $analyzerDecision = (array)($parser4['analyzer_decision'] ?? []);
        $patternAlgorithms = (array)($parser4['pattern_algorithms'] ?? []);

        return [
            'last_run_candidates' => (int)($lastRun['candidates'] ?? 0),
            'last_run_signals' => (int)($lastRun['signals'] ?? 0),
            'last_run_monitors' => (int)($lastRun['monitors'] ?? 0),
            'rejection_counters' => [
                'rejected_not_entry_zone' => (int)($lastRun['rejected_not_entry_zone'] ?? 0),
                'rejected_low_reliability' => (int)($lastRun['rejected_low_reliability'] ?? 0),
                'rejected_missing_passport' => (int)($lastRun['rejected_missing_passport'] ?? 0),
                'rejected_missing_price' => (int)($lastRun['rejected_missing_price'] ?? 0),
                'rejected_side_unresolved' => (int)($lastRun['rejected_side_unresolved'] ?? 0),
            ],
            'signal_mode_counters' => [
                'bootstrap_signals_count' => (int)($lastRun['bootstrap_signals_count'] ?? 0),
                'warmup_symbols_count' => (int)($lastRun['warmup_symbols_count'] ?? 0),
                'normal_signals_count' => (int)($lastRun['normal_signals_count'] ?? 0),
            ],
            'effective_pattern_config' => $patternAlgorithms,
            'effective_analyzer_decision' => $analyzerDecision,
        ];
    }

    /**
     * Part 9: Stats Engine Audit
     *
     * @return array<string,mixed>
     */
    private function auditStatsEngine(array $waiting, array $active, array $closed, array $signals): array
    {
        $totalClosed = count($closed);
        $wins = 0;
        $rois = [];
        $maes = [];
        $mfes = [];
        $durations = [];

        foreach ($closed as $t) {
            $roi = (float)($t['roi'] ?? 0.0);
            $rois[] = $roi;
            if ($roi >= 0) {
                $wins++;
            }
            $maes[] = (float)($t['mae'] ?? 0.0);
            $mfes[] = (float)($t['mfe'] ?? 0.0);
            $durations[] = (float)($t['duration'] ?? 0.0);
        }

        $winrate = $totalClosed > 0 ? round($wins / $totalClosed, 4) : 0.0;
        $avgRoi = $totalClosed > 0 ? round(array_sum($rois) / $totalClosed, 6) : 0.0;
        $avgMae = $totalClosed > 0 ? round(array_sum($maes) / $totalClosed, 6) : 0.0;
        $avgMfe = $totalClosed > 0 ? round(array_sum($mfes) / $totalClosed, 6) : 0.0;
        $avgDur = $totalClosed > 0 ? round(array_sum($durations) / $totalClosed, 2) : 0.0;

        // signal_to_entry_conversion: use the FIXED formula (same as simulator_engine.php)
        // Denominator = waiting + active + closed (all cumulative), not signals.json count
        $totalEntries = count($waiting) + count($active) + $totalClosed;
        $enteredCount = count($active) + $totalClosed;
        $conversionFixed = ($totalEntries > 0)
            ? round($enteredCount / $totalEntries, 4)
            : 0.0;

        // Diagnostic: also compute with the OLD (buggy) formula for comparison
        $totalSignalsCurrent = count($signals);
        $conversionOld = ($totalSignalsCurrent > 0)
            ? round($enteredCount / $totalSignalsCurrent, 4)
            : 0.0;
        $oldFormulaBug = ($conversionOld > 1.0);

        return [
            'total_trades' => $totalClosed,
            'winrate' => $winrate,
            'average_roi' => $avgRoi,
            'average_mae' => $avgMae,
            'average_mfe' => $avgMfe,
            'average_duration' => $avgDur,
            'signal_to_entry_conversion' => $conversionFixed,
            'old_formula_conversion' => $conversionOld,
            'old_formula_bug_detected' => $oldFormulaBug,
            'old_formula_bug_explanation' => $oldFormulaBug
                ? 'Old formula (signals.json / active+closed) exceeds 1.0 — FIXED in simulator_engine.php using cumulative denominator'
                : 'Old formula bug not triggered (either no data or single cycle)',
        ];
    }

    /**
     * Config audit - check effective config state
     *
     * @return array<string,mixed>
     */
    private function auditConfig(array $effectiveCfg, array $userCfg): array
    {
        $patternSelection = (array)($effectiveCfg['pattern_selection'] ?? []);
        $parser4Patterns = (array)(($effectiveCfg['parser4'] ?? [])['pattern_algorithms'] ?? []);

        return [
            'effective_pattern_selection' => $patternSelection,
            'parser4_pattern_algorithms' => $parser4Patterns,
            'user_config_patterns' => (array)($userCfg['patterns'] ?? []),
            'pattern_merge_consistent' => $this->checkPatternConsistency($patternSelection, $parser4Patterns),
        ];
    }

    /**
     * Check pattern selection consistency between effective snapshot and parser4
     */
    private function checkPatternConsistency(array $selection, array $parser4): bool
    {
        $selEnabled = (array)($selection['enabled'] ?? []);
        $p4Enabled = (array)($parser4['enabled'] ?? []);
        sort($selEnabled);
        sort($p4Enabled);
        return $selEnabled === $p4Enabled
            && (string)($selection['mode'] ?? '') === (string)($parser4['mode'] ?? '');
    }

    /**
     * Part 11: Reversal V1 vs V2 Comparison
     *
     * Computes side-by-side metrics for reversal pattern families
     * including false reversal proxy, expectancy, and promotion criteria.
     *
     * False reversal proxy definition:
     *   A closed trade is classified as a "false reversal" if exit reason
     *   is stop_loss or early_failure — indicating the reversal hypothesis
     *   was invalidated without meaningful favorable movement.
     *
     * @param array<int,array<string,mixed>> $closed
     * @return array<string,mixed>
     */
    private function auditReversalComparison(array $closed): array
    {
        $v1Patterns = ['double_bottom', 'double_top'];
        $v2Patterns = ['double_bottom_confirm_v2', 'double_top_confirm_v2'];

        $v1Stats = $this->computeReversalFamilyStats($closed, $v1Patterns);
        $v2Stats = $this->computeReversalFamilyStats($closed, $v2Patterns);

        // Per-type breakdown
        $bottomV1 = $this->computeReversalFamilyStats($closed, ['double_bottom']);
        $bottomV2 = $this->computeReversalFamilyStats($closed, ['double_bottom_confirm_v2']);
        $topV1 = $this->computeReversalFamilyStats($closed, ['double_top']);
        $topV2 = $this->computeReversalFamilyStats($closed, ['double_top_confirm_v2']);

        // V2 stage counters (setup → confirm funnel)
        $v2StageCounters = $this->loadV2StageCounters();

        return [
            'v1_aggregate' => $v1Stats,
            'v2_aggregate' => $v2Stats,
            'bottom_patterns' => ['v1' => $bottomV1, 'v2' => $bottomV2],
            'top_patterns' => ['v1' => $topV1, 'v2' => $topV2],
            'v2_stage_counters' => $v2StageCounters,
            'compare_mode_active' => true,
            'evaluation_note' => 'V2 is under shadow evaluation. Promotion requires statistical evidence.',
        ];
    }

    /**
     * Load V2 stage counters persisted by parser4_analyzer.
     *
     * @return array<string,mixed>
     */
    private function loadV2StageCounters(): array
    {
        $data = $this->state->readJson('storage/v2_stage_counters.json', []);
        if ($data === []) {
            return [
                'by_algorithm' => [],
                'reversal_v2_aggregate' => [
                    'setup_candidates_count' => 0,
                    'confirmed_signals_count' => 0,
                    'confirm_rejected_count' => 0,
                    'confirmation_rate' => 0.0,
                    'rejection_rate' => 0.0,
                ],
                'available' => false,
            ];
        }
        $data['available'] = true;
        return $data;
    }

    /**
     * Compute aggregate stats for a reversal family.
     *
     * @param array<int,array<string,mixed>> $closed
     * @param array<int,string> $patterns
     * @return array<string,mixed>
     */
    private function computeReversalFamilyStats(array $closed, array $patterns): array
    {
        $trades = array_filter($closed, fn($t) => in_array((string)($t['pattern_algorithm'] ?? ''), $patterns, true));
        $trades = array_values($trades);
        $count = count($trades);

        $result = [
            'patterns' => $patterns,
            'trades_total' => 0,
            'wins' => 0,
            'losses' => 0,
            'winrate' => 0.0,
            'avg_roi' => 0.0,
            'median_roi' => 0.0,
            'avg_win' => 0.0,
            'avg_loss' => 0.0,
            'expectancy' => 0.0,
            'false_reversal_count' => 0,
            'false_reversal_rate' => 0.0,
            'stop_hit_count' => 0,
            'stop_hit_rate' => 0.0,
            'avg_mae' => 0.0,
            'avg_mfe' => 0.0,
            'avg_duration' => 0.0,
        ];

        if ($count === 0) {
            return $result;
        }

        $rois = [];
        $winRois = [];
        $lossRois = [];
        $maes = [];
        $mfes = [];
        $durations = [];
        $wins = 0;
        $falseReversals = 0;
        $stopHits = 0;

        foreach ($trades as $t) {
            $roi = (float)($t['roi'] ?? 0.0);
            $rois[] = $roi;
            $maes[] = (float)($t['mae'] ?? 0.0);
            $mfes[] = (float)($t['mfe'] ?? 0.0);
            $durations[] = (float)($t['duration'] ?? 0.0);

            if ($roi >= 0) {
                $wins++;
                $winRois[] = $roi;
            } else {
                $lossRois[] = $roi;
            }

            $reason = (string)($t['reason'] ?? '');
            if ($reason === 'stop_loss' || $reason === 'early_failure') {
                $falseReversals++;
            }
            if ($reason === 'stop_loss') {
                $stopHits++;
            }
        }

        $losses = $count - $wins;
        $avgWin = count($winRois) > 0 ? round(array_sum($winRois) / count($winRois), 6) : 0.0;
        $avgLoss = count($lossRois) > 0 ? round(array_sum($lossRois) / count($lossRois), 6) : 0.0;
        $winrate = round($wins / $count, 4);
        $expectancy = round(($winrate * $avgWin) + ((1 - $winrate) * $avgLoss), 6);

        sort($rois);
        $mid = intdiv($count, 2);
        $medianRoi = ($count % 2 === 0)
            ? round(($rois[$mid - 1] + $rois[$mid]) / 2.0, 6)
            : round($rois[$mid], 6);

        $result['trades_total'] = $count;
        $result['wins'] = $wins;
        $result['losses'] = $losses;
        $result['winrate'] = $winrate;
        $result['avg_roi'] = round(array_sum($rois) / $count, 6);
        $result['median_roi'] = $medianRoi;
        $result['avg_win'] = $avgWin;
        $result['avg_loss'] = $avgLoss;
        $result['expectancy'] = $expectancy;
        $result['false_reversal_count'] = $falseReversals;
        $result['false_reversal_rate'] = round($falseReversals / $count, 4);
        $result['stop_hit_count'] = $stopHits;
        $result['stop_hit_rate'] = round($stopHits / $count, 4);
        $result['avg_mae'] = round(array_sum($maes) / $count, 6);
        $result['avg_mfe'] = round(array_sum($mfes) / $count, 6);
        $result['avg_duration'] = round(array_sum($durations) / $count, 2);

        return $result;
    }

    /**
     * Part 12: Regression Audit — short-side collapse analysis.
     *
     * Produces per-pattern × per-side breakdown, what-if exclusion
     * scenarios, and severity ranking to localize regression.
     *
     * @param array<int,array<string,mixed>> $closed
     * @return array<string,mixed>
     */
    private function auditRegression(array $closed): array
    {
        $allPatterns = ['double_bottom', 'double_top', 'pullback_trend_continue', 'double_bottom_confirm_v2', 'double_top_confirm_v2'];
        $sides = ['long', 'short'];

        // Per-pattern × per-side matrix
        $matrix = [];
        foreach ($allPatterns as $pattern) {
            foreach ($sides as $side) {
                $key = $pattern . '/' . $side;
                $matrix[$key] = $this->computeRegressionCellStats($closed, $pattern, $side);
            }
        }

        // Per-pattern totals
        $patternTotals = [];
        foreach ($allPatterns as $pattern) {
            $patternTotals[$pattern] = $this->computeRegressionCellStats($closed, $pattern, null);
        }

        // Per-side totals
        $sideTotals = [];
        foreach ($sides as $side) {
            $sideTotals[$side] = $this->computeRegressionCellStats($closed, null, $side);
        }

        // Overall
        $overallTotal = $this->computeRegressionCellStats($closed, null, null);

        // What-if scenarios
        $scenarios = [];

        // Scenario 1: disable double_top
        $scenarios['disable_double_top'] = $this->computeRegressionCellStats(
            array_values(array_filter($closed, fn($t) => $this->getAlgorithm($t) !== 'double_top')),
            null, null
        );
        $scenarios['disable_double_top']['label'] = 'Без double_top';

        // Scenario 2: disable pullback_trend_continue
        $scenarios['disable_pullback'] = $this->computeRegressionCellStats(
            array_values(array_filter($closed, fn($t) => $this->getAlgorithm($t) !== 'pullback_trend_continue')),
            null, null
        );
        $scenarios['disable_pullback']['label'] = 'Без pullback_trend_continue';

        // Scenario 3: disable both
        $excludeBoth = ['double_top', 'pullback_trend_continue'];
        $scenarios['disable_top_and_pullback'] = $this->computeRegressionCellStats(
            array_values(array_filter($closed, fn($t) => !in_array($this->getAlgorithm($t), $excludeBoth, true))),
            null, null
        );
        $scenarios['disable_top_and_pullback']['label'] = 'Без double_top и pullback';

        // Scenario 4: only bottom + V2
        $keepOnly = ['double_bottom', 'double_bottom_confirm_v2', 'double_top_confirm_v2'];
        $scenarios['bottom_plus_v2_only'] = $this->computeRegressionCellStats(
            array_values(array_filter($closed, fn($t) => in_array($this->getAlgorithm($t), $keepOnly, true))),
            null, null
        );
        $scenarios['bottom_plus_v2_only']['label'] = 'Только double_bottom + V2';

        // Scenario 5: V1 long only + V2 any
        $v2 = ['double_bottom_confirm_v2', 'double_top_confirm_v2'];
        $scenarios['v1_long_v2_any'] = $this->computeRegressionCellStats(
            array_values(array_filter($closed, function ($t) use ($v2) {
                $algo = $this->getAlgorithm($t);
                if (in_array($algo, $v2, true)) { return true; }
                return (string)($t['side'] ?? '') === 'long';
            })),
            null, null
        );
        $scenarios['v1_long_v2_any']['label'] = 'V1 только long + V2 любой';

        // Severity ranking
        $severity = [];
        $overallWinrate = (float)($overallTotal['winrate'] ?? 0);
        foreach ($matrix as $key => $cell) {
            $cellTrades = (int)($cell['trades'] ?? 0);
            if ($cellTrades === 0) { continue; }
            $cellWinrate = (float)($cell['winrate'] ?? 0);
            $cellAvgRoi = (float)($cell['avg_roi'] ?? 0);
            $cellFalseRate = (float)($cell['false_reversal_rate'] ?? 0);

            $damage = 0.0;
            if ($cellAvgRoi < 0) {
                $damage += abs($cellAvgRoi * $cellTrades) * 100;
            }
            if ($overallWinrate > 0 && $cellWinrate < $overallWinrate) {
                $damage += (1 - $cellWinrate / max(0.01, $overallWinrate)) * $cellTrades;
            }
            $damage += $cellFalseRate * $cellTrades;

            $severity[] = [
                'cell' => $key,
                'trades' => $cellTrades,
                'winrate' => $cellWinrate,
                'avg_roi' => $cellAvgRoi,
                'false_reversal_rate' => $cellFalseRate,
                'damage_score' => round($damage, 4),
            ];
        }
        usort($severity, fn($a, $b) => $b['damage_score'] <=> $a['damage_score']);

        return [
            'matrix' => $matrix,
            'pattern_totals' => $patternTotals,
            'side_totals' => $sideTotals,
            'overall' => $overallTotal,
            'what_if_scenarios' => $scenarios,
            'severity_ranking' => $severity,
            'has_data' => $overallTotal['trades'] > 0,
        ];
    }

    /**
     * Compute stats for a regression matrix cell.
     *
     * @param array<int,array<string,mixed>> $closed
     * @param string|null $pattern
     * @param string|null $side
     * @return array<string,mixed>
     */
    private function computeRegressionCellStats(array $closed, ?string $pattern, ?string $side): array
    {
        $trades = array_filter($closed, function ($t) use ($pattern, $side) {
            if ($pattern !== null && $this->getAlgorithm($t) !== $pattern) { return false; }
            if ($side !== null && (string)($t['side'] ?? '') !== $side) { return false; }
            return true;
        });
        $trades = array_values($trades);
        $count = count($trades);

        $result = [
            'trades' => 0, 'wins' => 0, 'losses' => 0, 'winrate' => 0.0,
            'avg_roi' => 0.0, 'false_reversal_count' => 0, 'false_reversal_rate' => 0.0,
            'stop_hit_count' => 0, 'stop_hit_rate' => 0.0,
            'early_failure_count' => 0, 'early_failure_rate' => 0.0,
            'avg_mae' => 0.0, 'avg_mfe' => 0.0, 'avg_duration' => 0.0,
        ];

        if ($count === 0) { return $result; }

        $rois = []; $maes = []; $mfes = []; $durations = [];
        $wins = 0; $falseReversals = 0; $stopHits = 0; $earlyFailures = 0;

        foreach ($trades as $t) {
            $roi = (float)($t['roi'] ?? 0.0);
            $rois[] = $roi;
            $maes[] = (float)($t['mae'] ?? 0.0);
            $mfes[] = (float)($t['mfe'] ?? 0.0);
            $durations[] = (float)($t['duration'] ?? 0.0);
            if ($roi >= 0) { $wins++; }
            $reason = (string)($t['reason'] ?? '');
            if ($reason === 'stop_loss' || $reason === 'early_failure') { $falseReversals++; }
            if ($reason === 'stop_loss') { $stopHits++; }
            if ($reason === 'early_failure') { $earlyFailures++; }
        }

        $result['trades'] = $count;
        $result['wins'] = $wins;
        $result['losses'] = $count - $wins;
        $result['winrate'] = round($wins / $count, 4);
        $result['avg_roi'] = round(array_sum($rois) / $count, 6);
        $result['false_reversal_count'] = $falseReversals;
        $result['false_reversal_rate'] = round($falseReversals / $count, 4);
        $result['stop_hit_count'] = $stopHits;
        $result['stop_hit_rate'] = round($stopHits / $count, 4);
        $result['early_failure_count'] = $earlyFailures;
        $result['early_failure_rate'] = round($earlyFailures / $count, 4);
        $result['avg_mae'] = round(array_sum($maes) / $count, 6);
        $result['avg_mfe'] = round(array_sum($mfes) / $count, 6);
        $result['avg_duration'] = round(array_sum($durations) / $count, 2);

        return $result;
    }

    /**
     * Extract algorithm name from a trade/signal record.
     */
    private function getAlgorithm(array $item): string
    {
        $algo = (string)($item['pattern_algorithm'] ?? '');
        if ($algo === '') {
            return '_unknown';
        }
        return $algo;
    }

    // =========================================================================
    // Part 13: Focused double_bottom / long regression audit
    // =========================================================================

    /**
     * Dedicated deep-dive audit for double_bottom / long path.
     *
     * Covers: core metrics, exit breakdown, entry quality, per-symbol analysis,
     * baseline comparison, what-if scenarios, ranked hypotheses, mitigation.
     *
     * @param array<int,array<string,mixed>> $closed
     * @return array<string,mixed>
     */
    private function auditDoubleBottomLong(array $closed): array
    {
        $target = array_values(array_filter($closed, function ($t) {
            return $this->getAlgorithm($t) === 'double_bottom'
                && (string)($t['side'] ?? '') === 'long';
        }));

        $overallStats = $this->computeRegressionCellStats($closed, null, null);
        $dbLongStats = $this->computeRegressionCellStats($closed, 'double_bottom', 'long');
        $dbShortStats = $this->computeRegressionCellStats($closed, 'double_bottom', 'short');
        $dbAllStats = $this->computeRegressionCellStats($closed, 'double_bottom', null);

        $targetCount = count($target);
        $hasData = $targetCount > 0;

        // ── Exit behavior ──
        $exitBreakdown = [
            'stop_loss' => 0, 'early_failure' => 0, 'trailing_stop' => 0,
            'break_even_stop' => 0, 'take_profit' => 0, 'other' => 0,
        ];
        $trailingActiveCount = 0;
        $breakEvenActiveCount = 0;
        $immediateFailures = 0;
        $neverPositiveCount = 0;
        $quickStopCount = 0;
        $winnerMfes = [];
        $loserMaes = [];
        $loserMfes = [];
        $durations = [];
        $perSymbol = [];

        foreach ($target as $t) {
            $reason = (string)($t['reason'] ?? '');
            if (isset($exitBreakdown[$reason])) {
                $exitBreakdown[$reason]++;
            } else {
                $exitBreakdown['other']++;
            }

            if (!empty($t['trailing_active'])) { $trailingActiveCount++; }
            if (!empty($t['break_even_active'])) { $breakEvenActiveCount++; }

            $roi = (float)($t['roi'] ?? 0.0);
            $mfe = (float)($t['mfe'] ?? 0.0);
            $mae = (float)($t['mae'] ?? 0.0);
            $dur = (float)($t['duration'] ?? 0.0);
            $durations[] = $dur;

            if ($dur <= 5) { $immediateFailures++; }
            if ($dur <= 15 && $roi < 0) { $quickStopCount++; }
            if ($mfe < 0.005 && $roi < 0) { $neverPositiveCount++; }

            if ($roi >= 0) {
                $winnerMfes[] = $mfe;
            } else {
                $loserMaes[] = $mae;
                $loserMfes[] = $mfe;
            }

            $sym = (string)($t['symbol'] ?? '');
            if ($sym !== '') {
                if (!isset($perSymbol[$sym])) {
                    $perSymbol[$sym] = ['trades' => 0, 'wins' => 0, 'rois' => []];
                }
                $perSymbol[$sym]['trades']++;
                if ($roi >= 0) { $perSymbol[$sym]['wins']++; }
                $perSymbol[$sym]['rois'][] = $roi;
            }
        }

        $entryQuality = [
            'immediate_failure_rate' => $targetCount > 0 ? round($immediateFailures / $targetCount, 4) : 0.0,
            'quick_stop_rate' => $targetCount > 0 ? round($quickStopCount / $targetCount, 4) : 0.0,
            'never_positive_rate' => $targetCount > 0 ? round($neverPositiveCount / $targetCount, 4) : 0.0,
            'avg_loser_mae' => count($loserMaes) > 0 ? round(array_sum($loserMaes) / count($loserMaes), 6) : 0.0,
            'avg_loser_mfe' => count($loserMfes) > 0 ? round(array_sum($loserMfes) / count($loserMfes), 6) : 0.0,
            'avg_winner_mfe' => count($winnerMfes) > 0 ? round(array_sum($winnerMfes) / count($winnerMfes), 6) : 0.0,
        ];

        // ── Per-symbol ranking (worst first) ──
        $symbolRanking = [];
        foreach ($perSymbol as $sym => $bucket) {
            $symT = $bucket['trades'];
            $symbolRanking[] = [
                'symbol' => $sym,
                'trades' => $symT,
                'winrate' => $symT > 0 ? round($bucket['wins'] / $symT, 4) : 0.0,
                'avg_roi' => $symT > 0 ? round(array_sum($bucket['rois']) / $symT, 6) : 0.0,
            ];
        }
        usort($symbolRanking, fn($a, $b) => $a['avg_roi'] <=> $b['avg_roi']);

        // ── Baseline comparison ──
        $baseline = [
            'double_bottom_long' => $dbLongStats,
            'double_bottom_short' => $dbShortStats,
            'double_bottom_all' => $dbAllStats,
            'overall' => $overallStats,
        ];

        // ── What-if: disable double_bottom/long ──
        $withoutDbLong = array_values(array_filter($closed, function ($t) {
            return !($this->getAlgorithm($t) === 'double_bottom' && (string)($t['side'] ?? '') === 'long');
        }));
        $scenarioDisable = $this->computeRegressionCellStats($withoutDbLong, null, null);
        $scenarioDisable['description'] = 'Без double_bottom/long';

        // ── Ranked hypotheses ──
        $hypotheses = [];

        $dbWr = (float)($dbLongStats['winrate'] ?? 0);
        $dbFr = (float)($dbLongStats['false_reversal_rate'] ?? 0);
        $dbSr = (float)($dbLongStats['stop_hit_rate'] ?? 0);
        $oWr = (float)($overallStats['winrate'] ?? 0);
        $immRate = (float)($entryQuality['immediate_failure_rate'] ?? 0);
        $npRate = (float)($entryQuality['never_positive_rate'] ?? 0);

        // H1: Entry too early
        $s1 = ($immRate > 0.15 ? 30 : 0) + ($npRate > 0.4 ? 25 : 0) + ($dbFr > 0.5 ? 15 : 0);
        $hypotheses[] = ['id' => 'entry_too_early', 'label' => 'Вход слишком ранний', 'score' => $s1];

        // H2: Weak rebound
        $s2 = ($dbFr > 0.5 ? 30 : 0) + ($dbWr < 0.35 ? 20 : 0);
        $hypotheses[] = ['id' => 'weak_rebound', 'label' => 'Слабый отскок / нэкляйн', 'score' => $s2];

        // H3: Hostile regime
        $s3 = ($dbWr < $oWr * 0.7 ? 25 : 0) + ($dbSr > 0.4 ? 20 : 0);
        $hypotheses[] = ['id' => 'hostile_regime', 'label' => 'Рыночный режим враждебен', 'score' => $s3];

        // H4: Stop/exit mismatch
        $s4 = ($dbSr > 0.35 ? 25 : 0);
        $slCount = (int)($exitBreakdown['stop_loss'] ?? 0);
        if ($targetCount > 0 && ($slCount / $targetCount) > 0.4) { $s4 += 20; }
        $hypotheses[] = ['id' => 'stop_exit_mismatch', 'label' => 'Стоп/выход не подходит', 'score' => $s4];

        // H5: Replace with V2
        $s5 = ($dbFr > 0.45 ? 25 : 0) + ($dbWr < 0.35 ? 15 : 0);
        $hypotheses[] = ['id' => 'replace_with_v2', 'label' => 'Заменить на V2-подтверждённый путь', 'score' => $s5];

        usort($hypotheses, fn($a, $b) => $b['score'] <=> $a['score']);
        foreach ($hypotheses as $i => &$h) { $h['rank'] = $i + 1; }
        unset($h);

        // ── Severity ──
        $severity = 'ok';
        if ($targetCount < 5) {
            $severity = 'info';
        } elseif ($dbWr < 0.25 && (float)($dbLongStats['avg_roi'] ?? 0) < -0.01) {
            $severity = 'critical';
        } elseif ($dbWr < 0.35 && $dbFr > 0.4) {
            $severity = 'warning';
        } elseif ($dbWr < $oWr * 0.8) {
            $severity = 'watch';
        }

        return [
            'has_data' => $hasData,
            'low_sample' => $targetCount < 10,
            'trades' => $targetCount,
            'core_stats' => $dbLongStats,
            'exit_breakdown' => $exitBreakdown,
            'entry_quality' => $entryQuality,
            'symbol_ranking' => $symbolRanking,
            'baseline' => $baseline,
            'what_if_disable' => $scenarioDisable,
            'hypotheses' => $hypotheses,
            'severity' => $severity,
        ];
    }
}

// CLI execution
if (PHP_SAPI === 'cli') {
    $moduleBase = __DIR__;
    $audit = new SimulationAudit($moduleBase);
    $report = $audit->run();

    $outputPath = $moduleBase . '/storage/simulation_audit_report.json';
    file_put_contents($outputPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    echo "Simulation Audit Report generated: $outputPath\n";
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}
