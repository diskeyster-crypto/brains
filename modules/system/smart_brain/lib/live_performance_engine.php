<?php
declare(strict_types=1);

/**
 * Live Performance Analyzer — read-only aggregation engine.
 *
 * Reads live trading bot mirror data, closed-trade records, and coin passports
 * to produce a comprehensive analytics array covering overview, per-pattern,
 * per-symbol/side, exit analysis, execution quality, damage ranking,
 * what-if scenarios, recommendations, and passport/MAE context.
 */
final class LivePerformanceEngine
{
    /** Minimum number of trades before a bucket is considered statistically meaningful. */
    private const MIN_SAMPLE_SIZE = 10;

    /** Severity thresholds. */
    private const CRITICAL_EXPECTANCY = -0.02;
    private const CRITICAL_WINRATE    = 20.0;
    private const WARNING_EXPECTANCY  = 0.0;
    private const WARNING_WINRATE     = 35.0;

    /**
     * Compute the full live-performance analytics payload.
     *
     * @param array<string,mixed>              $botMirror    Output of readBotExecutionMirror()
     * @param array<int,array<string,mixed>>   $closedTrades Individual closed-trade records
     * @param array<string,array<string,mixed>> $passports   Coin passports keyed by symbol
     *
     * @return array<string,mixed> Sections: overview, pattern_performance,
     *         symbol_side_performance, exit_analysis, execution_quality,
     *         damage_ranking, whatif_scenarios, recommendations, passport_context
     */
    public function compute(array $botMirror, array $closedTrades, array $passports): array
    {
        $normalizedTrades = $this->normalizeTrades($closedTrades);

        return [
            'overview'                => $this->buildOverview($botMirror, $normalizedTrades),
            'pattern_performance'     => $this->buildPatternPerformance($normalizedTrades),
            'symbol_side_performance' => $this->buildSymbolSidePerformance($normalizedTrades, $passports),
            'exit_analysis'           => $this->buildExitAnalysis($normalizedTrades),
            'execution_quality'       => $this->buildExecutionQuality($botMirror),
            'damage_ranking'          => $this->buildDamageRanking($normalizedTrades, $botMirror),
            'whatif_scenarios'        => $this->buildWhatIfScenarios($normalizedTrades),
            'recommendations'         => $this->buildRecommendations($normalizedTrades, $botMirror, $passports),
            'passport_context'        => $this->buildPassportContext($passports),
        ];
    }

    // ------------------------------------------------------------------
    //  Normalisation
    // ------------------------------------------------------------------

    /**
     * Normalise heterogeneous trade-record key names to a canonical form.
     *
     * @param array<int,array<string,mixed>> $trades
     * @return array<int,array<string,mixed>>
     */
    private function normalizeTrades(array $trades): array
    {
        $out = [];
        foreach ($trades as $t) {
            $t['roi'] = (float)($t['realized_roi'] ?? $t['roi_pct'] ?? $t['pnl_pct'] ?? 0);
            $t['pattern'] = (string)($t['pattern_algorithm'] ?? $t['pattern'] ?? '');
            $t['side'] = strtolower((string)($t['side'] ?? ''));
            $t['symbol'] = (string)($t['symbol'] ?? '');
            $t['close_reason'] = (string)($t['close_reason'] ?? '');
            $t['mae'] = (float)($t['mae_roi'] ?? $t['mae_pct'] ?? $t['mae'] ?? 0);
            $t['trailing_active'] = (bool)($t['trailing_active'] ?? false);
            $t['break_even_armed'] = (bool)($t['break_even_armed'] ?? false);
            $t['break_even_applied'] = (bool)($t['break_even_applied'] ?? false);
            $t['close_protection_state'] = (string)($t['close_protection_state'] ?? '');
            $out[] = $t;
        }
        return $out;
    }

    // ------------------------------------------------------------------
    //  P1 — Overview
    // ------------------------------------------------------------------

    /**
     * Build the live overview section.
     *
     * @param array<string,mixed>            $mirror
     * @param array<int,array<string,mixed>> $trades
     * @return array<string,mixed>
     */
    private function buildOverview(array $mirror, array $trades): array
    {
        $total = count($trades);
        $rois  = array_column($trades, 'roi');
        $wins  = count(array_filter($rois, static fn(float $r): bool => $r > 0));
        $losses = $total - $wins;
        $winrate = $total > 0 ? round($wins / $total * 100, 2) : 0.0;
        $avgRoi  = $total > 0 ? round(array_sum($rois) / $total, 4) : 0.0;

        $winRois  = array_values(array_filter($rois, static fn(float $r): bool => $r > 0));
        $lossRois = array_values(array_filter($rois, static fn(float $r): bool => $r <= 0));
        $avgWin  = count($winRois) > 0 ? array_sum($winRois) / count($winRois) : 0.0;
        $avgLoss = count($lossRois) > 0 ? array_sum($lossRois) / count($lossRois) : 0.0;

        return [
            'total_trades'              => $total,
            'wins'                      => $wins,
            'losses'                    => $losses,
            'winrate'                   => $winrate,
            'avg_roi'                   => $avgRoi,
            'median_roi'                => $this->computeMedian($rois),
            'expectancy'                => $this->computeExpectancy($wins, $total, $avgWin, $avgLoss),
            'active_positions_count'    => (int)($mirror['active_positions_count'] ?? 0),
            'busy_skipped'              => (int)($mirror['busy_skipped'] ?? 0),
            'duplicate_skipped'         => (int)($mirror['duplicate_skipped'] ?? 0),
            'exchange_submit_failed'    => (int)($mirror['exchange_submit_failed_count'] ?? 0),
            'trailing_active_count'     => (int)($mirror['trailing_active_count'] ?? 0),
            'break_even_applied_count'  => (int)($mirror['break_even_applied_count'] ?? 0),
            'data_available'            => (bool)($mirror['available'] ?? false),
        ];
    }

    // ------------------------------------------------------------------
    //  P2 — Pattern Performance
    // ------------------------------------------------------------------

    /**
     * Build per-pattern performance buckets, sorted by expectancy descending.
     *
     * @param array<int,array<string,mixed>> $trades
     * @return array<string,array<string,mixed>>
     */
    private function buildPatternPerformance(array $trades): array
    {
        $buckets = $this->groupBy($trades, 'pattern');
        $result  = [];

        foreach ($buckets as $pattern => $group) {
            $result[$pattern] = $this->aggregatePatternBucket($pattern, $group);
        }

        uasort($result, static fn(array $a, array $b): int =>
            $b['expectancy'] <=> $a['expectancy']
        );

        return $result;
    }

    /**
     * Aggregate a single pattern bucket.
     *
     * @param string                          $pattern
     * @param array<int,array<string,mixed>>  $group
     * @return array<string,mixed>
     */
    private function aggregatePatternBucket(string $pattern, array $group): array
    {
        $total = count($group);
        $rois  = array_column($group, 'roi');
        $wins  = count(array_filter($rois, static fn(float $r): bool => $r > 0));
        $losses = $total - $wins;
        $winrate = $total > 0 ? round($wins / $total * 100, 2) : 0.0;
        $avgRoi  = $total > 0 ? round(array_sum($rois) / $total, 4) : 0.0;

        $winRois  = array_values(array_filter($rois, static fn(float $r): bool => $r > 0));
        $lossRois = array_values(array_filter($rois, static fn(float $r): bool => $r <= 0));
        $avgWin  = count($winRois) > 0 ? array_sum($winRois) / count($winRois) : 0.0;
        $avgLoss = count($lossRois) > 0 ? array_sum($lossRois) / count($lossRois) : 0.0;

        $reasons = array_column($group, 'close_reason');
        $stopHits = count(array_filter($reasons, static fn(string $r): bool =>
            stripos($r, 'stop') !== false
        ));
        $trailingCloses = count(array_filter($reasons, static fn(string $r): bool =>
            stripos($r, 'trailing') !== false
        ));
        $beCloses = count(array_filter($reasons, static fn(string $r): bool =>
            stripos($r, 'break_even') !== false || stripos($r, 'breakeven') !== false
        ));

        return [
            'pattern'             => $pattern,
            'trades'              => $total,
            'wins'                => $wins,
            'losses'              => $losses,
            'winrate'             => $winrate,
            'avg_roi'             => $avgRoi,
            'median_roi'          => $this->computeMedian($rois),
            'expectancy'          => $this->computeExpectancy($wins, $total, $avgWin, $avgLoss),
            'stop_hit_rate'       => $total > 0 ? round($stopHits / $total * 100, 2) : 0.0,
            'trailing_close_rate' => $total > 0 ? round($trailingCloses / $total * 100, 2) : 0.0,
            'be_close_rate'       => $total > 0 ? round($beCloses / $total * 100, 2) : 0.0,
            'low_sample'          => $total < self::MIN_SAMPLE_SIZE,
        ];
    }

    // ------------------------------------------------------------------
    //  P3 — Symbol × Side Performance
    // ------------------------------------------------------------------

    /**
     * Build symbol × side performance, sorted by expectancy ascending (worst first).
     *
     * @param array<int,array<string,mixed>>   $trades
     * @param array<string,array<string,mixed>> $passports
     * @return array<int,array<string,mixed>>
     */
    private function buildSymbolSidePerformance(array $trades, array $passports): array
    {
        $buckets = [];
        foreach ($trades as $t) {
            $key = $t['symbol'] . '|' . $t['side'];
            $buckets[$key][] = $t;
        }

        $result = [];
        foreach ($buckets as $key => $group) {
            [$symbol, $side] = explode('|', $key, 2);
            $total   = count($group);
            $rois    = array_column($group, 'roi');
            $wins    = count(array_filter($rois, static fn(float $r): bool => $r > 0));
            $winrate = $total > 0 ? round($wins / $total * 100, 2) : 0.0;
            $avgRoi  = $total > 0 ? round(array_sum($rois) / $total, 4) : 0.0;

            $winRois  = array_values(array_filter($rois, static fn(float $r): bool => $r > 0));
            $lossRois = array_values(array_filter($rois, static fn(float $r): bool => $r <= 0));
            $avgWin  = count($winRois) > 0 ? array_sum($winRois) / count($winRois) : 0.0;
            $avgLoss = count($lossRois) > 0 ? array_sum($lossRois) / count($lossRois) : 0.0;

            $reasons = array_column($group, 'close_reason');
            $stopHits = count(array_filter($reasons, static fn(string $r): bool =>
                stripos($r, 'stop') !== false
            ));
            $beCount = count(array_filter($reasons, static fn(string $r): bool =>
                stripos($r, 'break_even') !== false || stripos($r, 'breakeven') !== false
            ));
            $trailingCloses = count(array_filter($reasons, static fn(string $r): bool =>
                stripos($r, 'trailing') !== false
            ));

            $winnerMaes = array_map(
                static fn(array $t): float => (float)$t['mae'],
                array_filter($group, static fn(array $t): bool => $t['roi'] > 0)
            );
            $maeP75 = count($winnerMaes) >= 4 ? $this->computePercentile(array_values($winnerMaes), 75) : null;

            $passportStop  = null;
            $fallbackFlag  = false;
            $passport      = $passports[$symbol] ?? null;
            if ($passport !== null) {
                $profile = $passport['execution_profile']['mae_stop_profile'] ?? null;
                if (is_array($profile) && isset($profile[$side])) {
                    $passportStop = (float)$profile[$side];
                } elseif ($maeP75 !== null) {
                    $passportStop = $maeP75;
                    $fallbackFlag = true;
                }
            } elseif ($maeP75 !== null) {
                $passportStop = $maeP75;
                $fallbackFlag = true;
            }

            $result[] = [
                'symbol'               => $symbol,
                'side'                 => $side,
                'trades'               => $total,
                'winrate'              => $winrate,
                'avg_roi'              => $avgRoi,
                'median_roi'           => $this->computeMedian($rois),
                'expectancy'           => $this->computeExpectancy($wins, $total, $avgWin, $avgLoss),
                'stop_hit_count'       => $stopHits,
                'be_count'             => $beCount,
                'trailing_close_count' => $trailingCloses,
                'mae_p75_winners'      => $maeP75 !== null ? round($maeP75, 4) : null,
                'suggested_stop'       => $passportStop !== null ? round($passportStop, 4) : null,
                'fallback_flag'        => $fallbackFlag,
                'low_sample'           => $total < self::MIN_SAMPLE_SIZE,
            ];
        }

        usort($result, static fn(array $a, array $b): int =>
            $a['expectancy'] <=> $b['expectancy']
        );

        return $result;
    }

    // ------------------------------------------------------------------
    //  P4 — Exit / Protection Analysis
    // ------------------------------------------------------------------

    /**
     * Build exit and protection analysis section.
     *
     * @param array<int,array<string,mixed>> $trades
     * @return array<string,mixed>
     */
    private function buildExitAnalysis(array $trades): array
    {
        $total = count($trades);

        $reasonDist = [];
        foreach ($trades as $t) {
            $reason = $t['close_reason'] !== '' ? $t['close_reason'] : 'unknown';
            $reasonDist[$reason] = ($reasonDist[$reason] ?? 0) + 1;
        }
        arsort($reasonDist);

        $reasonPct = [];
        foreach ($reasonDist as $reason => $count) {
            $reasonPct[$reason] = $total > 0 ? round($count / $total * 100, 2) : 0.0;
        }

        $trailingActive = count(array_filter($trades, static fn(array $t): bool =>
            (bool)($t['trailing_active'] ?? false)
        ));
        $beArmed = count(array_filter($trades, static fn(array $t): bool =>
            (bool)($t['break_even_armed'] ?? false)
        ));
        $beApplied = count(array_filter($trades, static fn(array $t): bool =>
            (bool)($t['break_even_applied'] ?? false)
        ));
        $stopMoved = count(array_filter($trades, static fn(array $t): bool =>
            ($t['close_protection_state'] ?? '') !== '' && ($t['close_protection_state'] ?? '') !== 'initial'
        ));

        return [
            'close_reason_distribution' => $reasonDist,
            'close_reason_pct'          => $reasonPct,
            'trailing_activation_rate'  => $total > 0 ? round($trailingActive / $total * 100, 2) : 0.0,
            'be_armed_rate'             => $total > 0 ? round($beArmed / $total * 100, 2) : 0.0,
            'be_applied_rate'           => $total > 0 ? round($beApplied / $total * 100, 2) : 0.0,
            'stop_moved_rate'           => $total > 0 ? round($stopMoved / $total * 100, 2) : 0.0,
            'total_trades_analyzed'     => $total,
        ];
    }

    // ------------------------------------------------------------------
    //  P5 — Execution Quality
    // ------------------------------------------------------------------

    /**
     * Build execution quality section from the bot mirror.
     *
     * @param array<string,mixed> $mirror
     * @return array<string,mixed>
     */
    private function buildExecutionQuality(array $mirror): array
    {
        return [
            'duplicate_skipped'             => (int)($mirror['duplicate_skipped'] ?? 0),
            'busy_skipped'                  => (int)($mirror['busy_skipped'] ?? 0),
            'exchange_submit_attempted'     => (int)($mirror['exchange_submit_attempted_count'] ?? 0),
            'exchange_submit_failed'        => (int)($mirror['exchange_submit_failed_count'] ?? 0),
            'exchange_submit_success'       => (int)($mirror['exchange_submit_success_count'] ?? 0),
            'position_open_confirmed'       => (int)($mirror['position_open_confirmed_count'] ?? 0),
            'execution_guard_blocked'       => (int)($mirror['execution_guard_blocked_count'] ?? 0),
            'protection_apply_failed'       => (int)($mirror['protection_apply_failed_count'] ?? 0),
            'latest_exchange_error_code'    => $mirror['latest_exchange_error_code'] ?? null,
            'latest_exchange_error_message' => (string)($mirror['latest_exchange_error_message'] ?? ''),
            'last_failed_symbol'            => (string)($mirror['last_failed_symbol'] ?? ''),
            'last_failed_stage'             => (string)($mirror['last_failed_stage'] ?? ''),
        ];
    }

    // ------------------------------------------------------------------
    //  P6 — Damage Ranking
    // ------------------------------------------------------------------

    /**
     * Build a ranked list of the worst-performing dimensions.
     *
     * @param array<int,array<string,mixed>> $trades
     * @param array<string,mixed>            $mirror
     * @return array<int,array<string,mixed>>
     */
    private function buildDamageRanking(array $trades, array $mirror): array
    {
        $items = [];

        // --- patterns ---
        $patternBuckets = $this->groupBy($trades, 'pattern');
        foreach ($patternBuckets as $pattern => $group) {
            $agg = $this->aggregatePatternBucket($pattern, $group);
            if ($agg['expectancy'] < 0) {
                $items[] = [
                    'type'   => 'pattern',
                    'label'  => $pattern,
                    'metric' => 'expectancy',
                    'value'  => $agg['expectancy'],
                    'trades' => $agg['trades'],
                ];
            }
            if ($agg['winrate'] < self::WARNING_WINRATE && $agg['trades'] >= self::MIN_SAMPLE_SIZE) {
                $items[] = [
                    'type'   => 'pattern',
                    'label'  => $pattern,
                    'metric' => 'winrate',
                    'value'  => $agg['winrate'],
                    'trades' => $agg['trades'],
                ];
            }
        }

        // --- pattern × side ---
        $patternSideBuckets = [];
        foreach ($trades as $t) {
            $key = $t['pattern'] . '|' . $t['side'];
            $patternSideBuckets[$key][] = $t;
        }
        foreach ($patternSideBuckets as $key => $group) {
            [$pat, $side] = explode('|', $key, 2);
            $stats = $this->quickStats($group);
            if ($stats['expectancy'] < 0) {
                $items[] = [
                    'type'   => 'pattern_side',
                    'label'  => $pat . ' ' . $side,
                    'metric' => 'expectancy',
                    'value'  => $stats['expectancy'],
                    'trades' => $stats['total'],
                ];
            }
        }

        // --- symbol × side ---
        $symSideBuckets = [];
        foreach ($trades as $t) {
            $key = $t['symbol'] . '|' . $t['side'];
            $symSideBuckets[$key][] = $t;
        }
        foreach ($symSideBuckets as $key => $group) {
            [$sym, $side] = explode('|', $key, 2);
            $stats = $this->quickStats($group);
            if ($stats['expectancy'] < 0) {
                $items[] = [
                    'type'   => 'symbol_side',
                    'label'  => $sym . ' ' . $side,
                    'metric' => 'expectancy',
                    'value'  => $stats['expectancy'],
                    'trades' => $stats['total'],
                ];
            }
        }

        // --- exit-reason damage ---
        $reasonBuckets = $this->groupBy($trades, 'close_reason');
        foreach ($reasonBuckets as $reason => $group) {
            $stats = $this->quickStats($group);
            if ($stats['avg_roi'] < 0) {
                $items[] = [
                    'type'   => 'exit_reason',
                    'label'  => $reason !== '' ? $reason : 'unknown',
                    'metric' => 'avg_roi',
                    'value'  => $stats['avg_roi'],
                    'trades' => $stats['total'],
                ];
            }
        }

        // --- execution blockers ---
        $executionGuardBlocked = (int)($mirror['execution_guard_blocked_count'] ?? 0);
        if ($executionGuardBlocked > 0) {
            $items[] = [
                'type'   => 'execution_blocker',
                'label'  => 'execution_guard_blocked',
                'metric' => 'count',
                'value'  => (float)$executionGuardBlocked,
                'trades' => 0,
            ];
        }
        $exchangeFailed = (int)($mirror['exchange_submit_failed_count'] ?? 0);
        if ($exchangeFailed > 0) {
            $items[] = [
                'type'   => 'execution_blocker',
                'label'  => 'exchange_submit_failed',
                'metric' => 'count',
                'value'  => (float)$exchangeFailed,
                'trades' => 0,
            ];
        }

        // assign severity and rank
        foreach ($items as &$item) {
            $item['severity'] = $this->classifySeverity($item);
        }
        unset($item);

        $severityOrder = ['critical' => 0, 'warning' => 1, 'watch' => 2];
        usort($items, static function (array $a, array $b) use ($severityOrder): int {
            $cmp = ($severityOrder[$a['severity']] ?? 3) <=> ($severityOrder[$b['severity']] ?? 3);
            return $cmp !== 0 ? $cmp : $a['value'] <=> $b['value'];
        });

        $ranked = [];
        foreach ($items as $i => $item) {
            $item['rank'] = $i + 1;
            $ranked[] = $item;
        }

        return $ranked;
    }

    /**
     * Classify a damage item into a severity level.
     *
     * @param array<string,mixed> $item
     * @return string
     */
    private function classifySeverity(array $item): string
    {
        $metric = $item['metric'] ?? '';
        $value  = (float)($item['value'] ?? 0);

        if ($metric === 'expectancy' && $value < self::CRITICAL_EXPECTANCY) {
            return 'critical';
        }
        if ($metric === 'winrate' && $value < self::CRITICAL_WINRATE) {
            return 'critical';
        }
        if ($metric === 'count' && $value >= 5) {
            return 'critical';
        }

        if ($metric === 'expectancy' && $value < self::WARNING_EXPECTANCY) {
            return 'warning';
        }
        if ($metric === 'winrate' && $value < self::WARNING_WINRATE) {
            return 'warning';
        }
        if ($metric === 'avg_roi' && $value < 0) {
            return 'warning';
        }
        if ($metric === 'count' && $value > 0) {
            return 'warning';
        }

        return 'watch';
    }

    // ------------------------------------------------------------------
    //  P7 — What-if Scenarios
    // ------------------------------------------------------------------

    /**
     * Build what-if scenarios showing the impact of excluding specific patterns/sides.
     *
     * @param array<int,array<string,mixed>> $trades
     * @return array<int,array<string,mixed>>
     */
    private function buildWhatIfScenarios(array $trades): array
    {
        $baseline = $this->quickStats($trades);

        $scenarios = [
            [
                'name'              => 'exclude_double_top',
                'description'       => 'Exclude double_top pattern',
                'excluded_patterns' => ['double_top'],
                'excluded_sides'    => null,
            ],
            [
                'name'              => 'exclude_pullback_trend_continue',
                'description'       => 'Exclude pullback_trend_continue pattern',
                'excluded_patterns' => ['pullback_trend_continue'],
                'excluded_sides'    => null,
            ],
            [
                'name'              => 'exclude_double_top_and_pullback',
                'description'       => 'Exclude both double_top and pullback_trend_continue',
                'excluded_patterns' => ['double_top', 'pullback_trend_continue'],
                'excluded_sides'    => null,
            ],
            [
                'name'              => 'keep_only_v2_confirmed',
                'description'       => 'Keep only double_bottom, double_bottom_confirm_v2, double_top_confirm_v2',
                'keep_patterns'     => ['double_bottom', 'double_bottom_confirm_v2', 'double_top_confirm_v2'],
                'excluded_patterns' => [],
                'excluded_sides'    => null,
            ],
            [
                'name'              => 'exclude_v1_short',
                'description'       => 'Exclude V1 short (double_top or pullback_trend_continue with side=short)',
                'excluded_patterns' => ['double_top', 'pullback_trend_continue'],
                'excluded_sides'    => ['short'],
            ],
        ];

        $result = [];
        foreach ($scenarios as $scenario) {
            $filtered = $this->applyScenarioFilter($trades, $scenario);
            $stats    = $this->quickStats($filtered);

            $result[] = [
                'name'              => $scenario['name'],
                'description'       => $scenario['description'],
                'excluded_patterns' => $scenario['excluded_patterns'],
                'excluded_sides'    => $scenario['excluded_sides'] ?? null,
                'trades_remaining'  => $stats['total'],
                'winrate_after'     => $stats['winrate'],
                'avg_roi_after'     => $stats['avg_roi'],
                'expectancy_after'  => $stats['expectancy'],
                'trades_removed'    => $baseline['total'] - $stats['total'],
                'winrate_delta'     => round($stats['winrate'] - $baseline['winrate'], 2),
                'expectancy_delta'  => round($stats['expectancy'] - $baseline['expectancy'], 4),
            ];
        }

        return $result;
    }

    /**
     * Apply a what-if scenario filter to the trade list.
     *
     * @param array<int,array<string,mixed>> $trades
     * @param array<string,mixed>            $scenario
     * @return array<int,array<string,mixed>>
     */
    private function applyScenarioFilter(array $trades, array $scenario): array
    {
        // "keep only" mode — include only trades matching the whitelist
        if (!empty($scenario['keep_patterns'])) {
            $keep = array_flip($scenario['keep_patterns']);
            return array_values(array_filter($trades, static fn(array $t): bool =>
                isset($keep[$t['pattern']])
            ));
        }

        $excludedPatterns = array_flip($scenario['excluded_patterns'] ?? []);
        $excludedSides    = $scenario['excluded_sides'] !== null
            ? array_flip($scenario['excluded_sides'])
            : null;

        return array_values(array_filter($trades, static function (array $t) use ($excludedPatterns, $excludedSides): bool {
            $patternMatch = isset($excludedPatterns[$t['pattern']]);
            if (!$patternMatch) {
                return true;
            }
            // If excluded_sides is null, exclude all matching patterns regardless of side
            if ($excludedSides === null) {
                return false;
            }
            // Only exclude when both pattern AND side match
            return !isset($excludedSides[$t['side']]);
        }));
    }

    // ------------------------------------------------------------------
    //  P8 — Recommendations
    // ------------------------------------------------------------------

    /**
     * Generate context-sensitive recommendations.
     *
     * @param array<int,array<string,mixed>>   $trades
     * @param array<string,mixed>              $mirror
     * @param array<string,array<string,mixed>> $passports
     * @return array<int,string>
     */
    private function buildRecommendations(array $trades, array $mirror, array $passports): array
    {
        $recs = [];
        $total = count($trades);

        // --- pattern-level recommendations ---
        $patternBuckets = $this->groupBy($trades, 'pattern');
        foreach ($patternBuckets as $pattern => $group) {
            $stats = $this->quickStats($group);
            if ($stats['expectancy'] < 0 && $stats['total'] >= self::MIN_SAMPLE_SIZE) {
                $recs[] = sprintf(
                    'Pattern "%s" has negative expectancy (%.2f%%) over %d trades — consider disabling.',
                    $pattern,
                    $stats['expectancy'] * 100,
                    $stats['total']
                );
            }
        }

        // --- side-level recommendations ---
        $sideBuckets = $this->groupBy($trades, 'side');
        foreach ($sideBuckets as $side => $group) {
            $stats = $this->quickStats($group);
            if ($stats['winrate'] < self::WARNING_WINRATE && $stats['total'] >= self::MIN_SAMPLE_SIZE) {
                $recs[] = sprintf(
                    '%s side winrate is %.1f%% over %d trades — consider restricting to V2 %s only.',
                    ucfirst($side),
                    $stats['winrate'],
                    $stats['total'],
                    $side
                );
            }
        }

        // --- symbol-side recommendations ---
        $symSideBuckets = [];
        foreach ($trades as $t) {
            $key = $t['symbol'] . '|' . $t['side'];
            $symSideBuckets[$key][] = $t;
        }
        foreach ($symSideBuckets as $key => $group) {
            [$sym, $side] = explode('|', $key, 2);
            $stats = $this->quickStats($group);
            if ($stats['expectancy'] < self::CRITICAL_EXPECTANCY && $stats['total'] >= self::MIN_SAMPLE_SIZE) {
                $recs[] = sprintf(
                    'Symbol %s %s has poor performance (expectancy %.2f%%, winrate %.1f%%) — consider excluding.',
                    $sym,
                    $side,
                    $stats['expectancy'] * 100,
                    $stats['winrate']
                );
            }
        }

        // --- trailing activation rate ---
        if ($total >= self::MIN_SAMPLE_SIZE) {
            $trailingActive = count(array_filter($trades, static fn(array $t): bool =>
                (bool)($t['trailing_active'] ?? false)
            ));
            $trailingRate = $total > 0 ? round($trailingActive / $total * 100, 1) : 0.0;
            if ($trailingRate < 15.0) {
                $recs[] = sprintf(
                    'Trailing activation rate is only %.1f%% — check trailing config.',
                    $trailingRate
                );
            }
        }

        // --- exchange errors ---
        $exchangeFailed = (int)($mirror['exchange_submit_failed_count'] ?? 0);
        if ($exchangeFailed > 0) {
            $recs[] = sprintf(
                'Exchange submit failures: %d — check connectivity/config.',
                $exchangeFailed
            );
        }

        // --- execution guard blocks ---
        $guardBlocked = (int)($mirror['execution_guard_blocked_count'] ?? 0);
        if ($guardBlocked > 0) {
            $recs[] = sprintf(
                'Execution guard blocked %d submissions — review guard thresholds.',
                $guardBlocked
            );
        }

        // --- protection apply failures ---
        $protectionFailed = (int)($mirror['protection_apply_failed_count'] ?? 0);
        if ($protectionFailed > 0) {
            $recs[] = sprintf(
                'Protection apply failed %d times — check protection logic and exchange limits.',
                $protectionFailed
            );
        }

        return $recs;
    }

    // ------------------------------------------------------------------
    //  P9 — Passport / MAE Context
    // ------------------------------------------------------------------

    /**
     * Build passport context summaries with MAE data and suggested stops.
     *
     * @param array<string,array<string,mixed>> $passports
     * @return array<string,array<string,mixed>>
     */
    private function buildPassportContext(array $passports): array
    {
        $result = [];
        foreach ($passports as $symbol => $passport) {
            $profile     = $passport['execution_profile'] ?? [];
            $maeProfile  = $profile['mae_stop_profile'] ?? [];
            $bySide      = $passport['by_side'] ?? [];

            $entry = [
                'symbol'           => (string)$symbol,
                'mae_stop_profile' => $maeProfile,
            ];

            foreach (['long', 'short'] as $side) {
                $sideData = $bySide[$side] ?? [];
                $entry['sides'][$side] = [
                    'suggested_stop' => $maeProfile[$side] ?? null,
                    'trades'         => (int)($sideData['trades'] ?? 0),
                    'winrate'        => (float)($sideData['winrate'] ?? 0),
                    'avg_roi'        => (float)($sideData['avg_roi'] ?? 0),
                ];
            }

            $result[$symbol] = $entry;
        }

        return $result;
    }

    // ------------------------------------------------------------------
    //  Private Helpers
    // ------------------------------------------------------------------

    /**
     * Compute the median of a numeric array.
     *
     * @param array<int,float|int> $values
     * @return float
     */
    private function computeMedian(array $values): float
    {
        if (count($values) === 0) {
            return 0.0;
        }

        sort($values);
        $count = count($values);
        $mid   = (int)floor($count / 2);

        if ($count % 2 === 0) {
            return round(($values[$mid - 1] + $values[$mid]) / 2, 4);
        }

        return round($values[$mid], 4);
    }

    /**
     * Compute expectancy: winrate × avgWin + lossrate × avgLoss.
     *
     * @param int   $wins
     * @param int   $total
     * @param float $avgWin  Average win ROI (positive)
     * @param float $avgLoss Average loss ROI (negative or zero)
     * @return float
     */
    private function computeExpectancy(int $wins, int $total, float $avgWin, float $avgLoss): float
    {
        if ($total === 0) {
            return 0.0;
        }

        $wr = $wins / $total;
        $lr = 1.0 - $wr;

        return round($wr * $avgWin + $lr * $avgLoss, 4);
    }

    /**
     * Compute the Nth percentile of a sorted numeric array.
     *
     * @param array<int,float|int> $values
     * @param int                  $percentile 0–100
     * @return float
     */
    private function computePercentile(array $values, int $percentile): float
    {
        if (count($values) === 0) {
            return 0.0;
        }

        sort($values);
        $index = ($percentile / 100) * (count($values) - 1);
        $lower = (int)floor($index);
        $upper = (int)ceil($index);

        if ($lower === $upper) {
            return (float)$values[$lower];
        }

        $fraction = $index - $lower;
        return (float)$values[$lower] + $fraction * ((float)$values[$upper] - (float)$values[$lower]);
    }

    /**
     * Group trades by a given key.
     *
     * @param array<int,array<string,mixed>> $trades
     * @param string                         $key
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function groupBy(array $trades, string $key): array
    {
        $groups = [];
        foreach ($trades as $t) {
            $bucket = (string)($t[$key] ?? '');
            $groups[$bucket][] = $t;
        }
        return $groups;
    }

    /**
     * Quick stats helper for a set of trades.
     *
     * @param array<int,array<string,mixed>> $group
     * @return array{total: int, wins: int, losses: int, winrate: float, avg_roi: float, expectancy: float}
     */
    private function quickStats(array $group): array
    {
        $total = count($group);
        $rois  = array_column($group, 'roi');
        $wins  = count(array_filter($rois, static fn(float $r): bool => $r > 0));

        $winRois  = array_values(array_filter($rois, static fn(float $r): bool => $r > 0));
        $lossRois = array_values(array_filter($rois, static fn(float $r): bool => $r <= 0));
        $avgWin  = count($winRois) > 0 ? array_sum($winRois) / count($winRois) : 0.0;
        $avgLoss = count($lossRois) > 0 ? array_sum($lossRois) / count($lossRois) : 0.0;

        return [
            'total'      => $total,
            'wins'       => $wins,
            'losses'     => $total - $wins,
            'winrate'    => $total > 0 ? round($wins / $total * 100, 2) : 0.0,
            'avg_roi'    => $total > 0 ? round(array_sum($rois) / $total, 4) : 0.0,
            'expectancy' => $this->computeExpectancy($wins, $total, $avgWin, $avgLoss),
        ];
    }
}
