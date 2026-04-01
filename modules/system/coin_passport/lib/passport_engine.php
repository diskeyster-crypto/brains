<?php
declare(strict_types=1);

/**
 * CoinPassportEngine
 *
 * Builds and updates persistent per-symbol coin passports.
 * Reads trade data from trading_bot storage and computes deep per-symbol
 * analytics including ROI corridor, reach rates, SL rates, hold times,
 * data sufficiency, and live eligibility decisions.
 *
 * Storage: modules/system/coin_passport/storage/passports/{SYMBOL}.json
 * This storage is NEVER wiped by bot/brain runtime clears.
 */
final class CoinPassportEngine
{
    private string $passportsDir;
    private string $tradingBotStorageDir;

    /** Minimum trades to compute meaningful confidence */
    private const MIN_SAMPLE_MEDIUM = 5;
    private const MIN_SAMPLE_HIGH   = 20;

    /** Data sufficiency thresholds */
    private const MIN_TOTAL_SAMPLES        = 10;
    private const MIN_PATTERN_V2_SAMPLES   = 5;
    private const MIN_PATTERN_V3_SAMPLES   = 5;
    private const MIN_RECENT_SAMPLES       = 3;

    /** Live eligibility gate thresholds (defaults — override via config if needed) */
    private const LIVE_GATE_CORRIDOR_P75_MIN     = 3.0;   // corridor_p75_roi >= this
    private const LIVE_GATE_RUNNER_PROB_MIN       = 0.05;  // runner_probability >= this
    private const LIVE_GATE_SUITABILITY_MIN       = 0.3;   // short_suitability_score >= this
    private const LIVE_GATE_NOISE_MAX             = 0.65;  // noise_score <= this
    private const LIVE_GATE_CONFIDENCE_MIN        = 'low'; // data_confidence: none→low→medium→high
    private const LIVE_GATE_REGIME_HEALTH_MIN     = 0.3;   // market_regime_health_score >= this

    public function __construct(string $passportsDir, string $tradingBotStorageDir)
    {
        $this->passportsDir          = $passportsDir;
        $this->tradingBotStorageDir  = $tradingBotStorageDir;

        if (!is_dir($this->passportsDir)) {
            @mkdir($this->passportsDir, 0755, true);
        }
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Return all passports, sorted by symbol.
     *
     * @return array<string,array<string,mixed>>
     */
    public function loadAll(): array
    {
        $passports = [];
        if (!is_dir($this->passportsDir)) {
            return $passports;
        }

        foreach (glob($this->passportsDir . '/*.json') ?: [] as $file) {
            $symbol = basename($file, '.json');
            $data   = $this->readJson($file);
            if (is_array($data) && !empty($data)) {
                $passports[$symbol] = $data;
            }
        }

        ksort($passports);
        return $passports;
    }

    /**
     * Load a single passport by symbol.
     *
     * @return array<string,mixed>|null
     */
    public function load(string $symbol): ?array
    {
        $file = $this->passportPath($symbol);
        if (!is_file($file)) {
            return null;
        }
        $data = $this->readJson($file);
        return is_array($data) ? $data : null;
    }

    /**
     * Rebuild passports for all symbols found in trade data.
     *
     * @return array{updated:int,symbols:list<string>,errors:list<string>}
     */
    public function rebuildAll(): array
    {
        $tradesBySymbol = $this->collectTradesBySymbol();
        $result = ['updated' => 0, 'symbols' => [], 'errors' => []];

        foreach ($tradesBySymbol as $symbol => $trades) {
            try {
                $passport = $this->buildPassport($symbol, $trades);
                $this->save($symbol, $passport);
                $result['updated']++;
                $result['symbols'][] = $symbol;
            } catch (\Throwable $e) {
                $result['errors'][] = $symbol . ': ' . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Rebuild passport for a single symbol.
     *
     * @return array<string,mixed>
     */
    public function rebuildSymbol(string $symbol): array
    {
        $tradesBySymbol = $this->collectTradesBySymbol();
        $trades = $tradesBySymbol[$symbol] ?? [];
        $passport = $this->buildPassport($symbol, $trades);
        $this->save($symbol, $passport);
        return $passport;
    }

    // =========================================================================
    // Data collection
    // =========================================================================

    /**
     * Collect all available trades grouped by symbol.
     *
     * Sources (in priority order):
     *   1. trades/closed/*.json  (completed trades with final ROI)
     *   2. sig_*.json in storage root (active/legacy trades)
     *
     * @return array<string,list<array<string,mixed>>>
     */
    private function collectTradesBySymbol(): array
    {
        $bySymbol = [];

        // Source 1: closed trades
        $closedDir = $this->tradingBotStorageDir . '/trades/closed';
        if (is_dir($closedDir)) {
            foreach (glob($closedDir . '/*.json') ?: [] as $file) {
                $trade = $this->readJson($file);
                if (is_array($trade) && !empty($trade['symbol'])) {
                    $sym = strtoupper((string)$trade['symbol']);
                    $bySymbol[$sym][] = $trade;
                }
            }
        }

        // Source 2: root sig_*.json files (active / legacy trades)
        foreach (glob($this->tradingBotStorageDir . '/sig_*.json') ?: [] as $file) {
            $trade = $this->readJson($file);
            if (is_array($trade) && !empty($trade['symbol'])) {
                $sym = strtoupper((string)$trade['symbol']);
                // Avoid duplicating if already seen in closed
                $bySymbol[$sym][] = $trade;
            }
        }

        return $bySymbol;
    }

    // =========================================================================
    // Passport building
    // =========================================================================

    /**
     * Build a complete deep-analytics passport for one symbol from its trades.
     *
     * @param list<array<string,mixed>> $trades
     * @return array<string,mixed>
     */
    private function buildPassport(string $symbol, array $trades): array
    {
        $sampleSizeTotal = count($trades);

        // Per-pattern and per-side sample counts
        $sampleShortV2 = 0;
        $sampleShortV3 = 0;

        // Core metric arrays
        $maxRois      = [];
        $finalRois    = [];
        $adverseRois  = [];
        $pullbacksAt2 = [];
        $pullbacksAt3 = [];
        $pullbacksAt5 = [];
        $holdMinutes  = [];
        $timeTo2Roi   = [];
        $timeTo5Roi   = [];
        $timeTo10Roi  = [];
        $pricePctMoves = [];

        $runners  = 0;
        $shorts   = 0;
        $longs    = 0;
        $slHits   = 0;
        $reach5   = 0;
        $reach10  = 0;
        $reach15  = 0;
        $failBefore3 = 0;

        // Recent trades: last 30 days
        $recentCutoff = time() - 30 * 86400;
        $recentSamples = 0;

        foreach ($trades as $trade) {
            $finalRoi = $this->extractFinalRoi($trade);
            $maxRoi   = $this->extractPeakRoi($trade, $finalRoi);
            $adverseRoi = $this->extractAdverseRoi($trade);
            $side     = strtolower((string)($trade['side'] ?? ''));
            $patternAlgo = (string)($trade['pattern_algorithm'] ?? '');
            $closedTs = (int)($trade['closed_ts'] ?? 0);

            // Pattern/side counts
            if ($side === 'short') {
                $shorts++;
                if ($patternAlgo === 'double_top_contextual_v2') {
                    $sampleShortV2++;
                } elseif ($patternAlgo === 'double_top_contextual_v3') {
                    $sampleShortV3++;
                }
            } elseif ($side === 'long') {
                $longs++;
                if ($patternAlgo === 'double_bottom_contextual_v2') {
                    $sampleShortV2++;
                } elseif ($patternAlgo === 'double_bottom_contextual_v3') {
                    $sampleShortV3++;
                }
            }

            if ($closedTs >= $recentCutoff) {
                $recentSamples++;
            }

            if ($finalRoi !== null) {
                $finalRois[] = $finalRoi;
            }
            if ($adverseRoi !== null) {
                $adverseRois[] = $adverseRoi;
            }

            // Stop-loss detection: finalRoi <= -SL threshold or flagged
            $isSlHit = $this->detectStopLossHit($trade, $finalRoi, $maxRoi);
            if ($isSlHit) {
                $slHits++;
            }

            if ($maxRoi !== null) {
                $maxRois[] = $maxRoi;

                if ($maxRoi >= 5.0)  $reach5++;
                if ($maxRoi >= 10.0) { $reach10++; $runners++; }
                if ($maxRoi >= 15.0) $reach15++;

                if ($finalRoi !== null) {
                    // Failure before 3 ROI: peak never broke 3 and final <= 0
                    if ($maxRoi < 3.0 && $finalRoi <= 0.0) {
                        $failBefore3++;
                    }

                    // Pullback analysis
                    if ($maxRoi >= 2.0) {
                        $pullbacksAt2[] = max(0.0, $maxRoi - $finalRoi);
                    }
                    if ($maxRoi >= 3.0) {
                        $pullbacksAt3[] = max(0.0, $maxRoi - $finalRoi);
                    }
                    if ($maxRoi >= 5.0) {
                        $pullbacksAt5[] = max(0.0, $maxRoi - $finalRoi);
                    }
                }
            }

            // Hold time
            $holdMin = $this->extractHoldMinutes($trade);
            if ($holdMin !== null && $holdMin > 0) {
                $holdMinutes[] = $holdMin;
            }

            // Time-to-target
            [$tt2, $tt5, $tt10] = $this->extractTimeToTargets($trade);
            if ($tt2 !== null) $timeTo2Roi[]  = $tt2;
            if ($tt5 !== null) $timeTo5Roi[]  = $tt5;
            if ($tt10 !== null) $timeTo10Roi[] = $tt10;

            // Price-pct move (if available)
            $pricePct = $this->extractPricePct($trade);
            if ($pricePct !== null) {
                $pricePctMoves[] = $pricePct;
            }
        }

        // Sort arrays for percentile computation
        $totalSides = $shorts + $longs;
        sort($maxRois);
        sort($finalRois);
        sort($adverseRois);
        sort($pricePctMoves);

        // ── Corridor (on favorable move = maxRoi) ──────────────────────────
        $corridorP50 = $this->percentile($maxRois, 50);
        $corridorP75 = $this->percentile($maxRois, 75);
        $corridorP90 = $this->percentile($maxRois, 90);

        // ── Price pct corridors ─────────────────────────────────────────────
        $corridorPricePctP50 = $this->percentile($pricePctMoves, 50);
        $corridorPricePctP75 = $this->percentile($pricePctMoves, 75);
        $corridorPricePctP90 = $this->percentile($pricePctMoves, 90);

        // ── Median adverse ──────────────────────────────────────────────────
        $medianMaxAdverseRoi = $this->percentile($adverseRois, 50);

        // ── Pullbacks ───────────────────────────────────────────────────────
        $medianPullback2 = $this->median($pullbacksAt2);
        $medianPullback3 = $this->median($pullbacksAt3);
        $medianPullback5 = $this->median($pullbacksAt5);

        // ── Reach rates ─────────────────────────────────────────────────────
        $reach5Rate  = $sampleSizeTotal > 0 ? round($reach5  / $sampleSizeTotal, 4) : 0.0;
        $reach10Rate = $sampleSizeTotal > 0 ? round($reach10 / $sampleSizeTotal, 4) : 0.0;
        $reach15Rate = $sampleSizeTotal > 0 ? round($reach15 / $sampleSizeTotal, 4) : 0.0;
        $failBefore3Rate = $sampleSizeTotal > 0 ? round($failBefore3 / $sampleSizeTotal, 4) : 0.0;
        $slHitRate   = $sampleSizeTotal > 0 ? round($slHits   / $sampleSizeTotal, 4) : 0.0;

        // ── Timing ──────────────────────────────────────────────────────────
        $avgHoldMinutes = count($holdMinutes) > 0 ? round(array_sum($holdMinutes) / count($holdMinutes), 1) : null;
        $avgTimeTo2Roi  = count($timeTo2Roi)  > 0 ? round(array_sum($timeTo2Roi)  / count($timeTo2Roi),  1) : null;
        $avgTimeTo5Roi  = count($timeTo5Roi)  > 0 ? round(array_sum($timeTo5Roi)  / count($timeTo5Roi),  1) : null;
        $avgTimeTo10Roi = count($timeTo10Roi) > 0 ? round(array_sum($timeTo10Roi) / count($timeTo10Roi), 1) : null;

        // ── Scores ──────────────────────────────────────────────────────────
        $runnerProb           = $sampleSizeTotal > 0 ? round($runners / $sampleSizeTotal, 4) : 0.0;
        $shortSuitabilityScore = $totalSides > 0 ? round($shorts / $totalSides, 4) : 0.5;
        $noiseScore           = $this->computeNoiseScore($maxRois, $finalRois);
        $volatilityScore      = $this->computeVolatilityScore($maxRois);
        $trendPersistenceScore = $this->computeTrendPersistenceScore($maxRois, $pullbacksAt3);
        $fakeBreakoutScore    = $this->computeFakeBreakoutScore($maxRois, $finalRois);
        $slSurvivalScore      = $sampleSizeTotal > 0 ? round(1.0 - $slHitRate, 4) : 0.5;
        $marketRegimeHealth   = $this->computeMarketRegimeHealthScore($corridorP75, $runnerProb, $recentSamples);

        // ── Data confidence ─────────────────────────────────────────────────
        $dataConfidence = $this->computeConfidence($sampleSizeTotal);

        // ── Data sufficiency ────────────────────────────────────────────────
        [$insufficientFlag, $insufficientReason, $fallbackMode] = $this->computeDataSufficiency(
            $sampleSizeTotal, $sampleShortV2, $sampleShortV3, $recentSamples, $dataConfidence
        );
        $lastDataGapWarning = $insufficientFlag ? $insufficientReason : null;

        // ── Recommendations ─────────────────────────────────────────────────
        $recLockStart  = $this->recommendLockStart($corridorP50, $corridorP75, $this->percentile($maxRois, 25), $dataConfidence);
        $recLockValue  = max(0.0, $recLockStart - 0.5);
        $recStage1     = $this->recommendStage1($corridorP50, $medianPullback3);
        $recStage2     = $this->recommendStage2($corridorP75, $corridorP90);
        $recLadderMode = $this->recommendLadderMode($runnerProb, $corridorP90, $dataConfidence);
        $recHarvest    = $this->recommendHarvestAggressiveness($medianPullback3, $medianPullback5, $noiseScore);
        $recLiveFloor  = $this->recommendLiveFloorRoi($corridorP75, $dataConfidence);

        // ── Live eligibility gate ────────────────────────────────────────────
        [$liveEligibility, $liveBlockReason] = $this->computeLiveEligibility(
            $corridorP75,
            $runnerProb,
            $shortSuitabilityScore,
            $noiseScore,
            $dataConfidence,
            $marketRegimeHealth,
            $insufficientFlag,
            $fallbackMode
        );

        // ── Diagnostic notes ─────────────────────────────────────────────────
        $notes = $this->buildDiagnosticNotes(
            $sampleSizeTotal,
            $dataConfidence,
            $runnerProb,
            $corridorP50,
            $corridorP75,
            $corridorP90,
            $this->percentile($maxRois, 25),
            $corridorP75,
            $liveEligibility,
            $liveBlockReason,
            $insufficientFlag
        );

        return [
            // ── Identity ─────────────────────────────────────────────────────
            'symbol'                        => $symbol,
            'updated_at'                    => date('Y-m-d H:i:s'),

            // ── Sample sizes ──────────────────────────────────────────────────
            'sample_size_total'             => $sampleSizeTotal,
            'sample_size_short_v2'          => $sampleShortV2,
            'sample_size_short_v3'          => $sampleShortV3,

            // ── Data confidence / sufficiency ─────────────────────────────────
            'data_confidence'               => $dataConfidence,
            'last_data_gap_warning'         => $lastDataGapWarning,
            'minimum_required_samples'      => self::MIN_TOTAL_SAMPLES,
            'current_usable_samples'        => $sampleSizeTotal,
            'insufficient_data_flag'        => $insufficientFlag,
            'insufficient_data_reason'      => $insufficientReason,
            'fallback_mode'                 => $fallbackMode,

            // ── Behavioral scores (0.0–1.0) ───────────────────────────────────
            'short_suitability_score'       => round($shortSuitabilityScore, 4),
            'runner_probability'            => round($runnerProb, 4),
            'noise_score'                   => round($noiseScore, 4),
            'volatility_score'              => round($volatilityScore, 4),
            'trend_persistence_score'       => round($trendPersistenceScore, 4),
            'fake_breakout_score'           => round($fakeBreakoutScore, 4),
            'sl_survival_score'             => round($slSurvivalScore, 4),
            'market_regime_health_score'    => round($marketRegimeHealth, 4),

            // ── Corridor — favorable ROI (max/peak) ───────────────────────────
            'corridor_p50_roi'              => round($corridorP50, 2),
            'corridor_p75_roi'              => round($corridorP75, 2),
            'corridor_p90_roi'              => round($corridorP90, 2),

            // ── Corridor — price pct move ─────────────────────────────────────
            'corridor_price_pct_p50'        => round($corridorPricePctP50, 4),
            'corridor_price_pct_p75'        => round($corridorPricePctP75, 4),
            'corridor_price_pct_p90'        => round($corridorPricePctP90, 4),

            // ── Max favorable / adverse ───────────────────────────────────────
            'median_max_favorable_roi'      => round($corridorP50, 2),
            'median_max_adverse_roi'        => round($medianMaxAdverseRoi, 2),

            // ── Pullback after milestone ──────────────────────────────────────
            'median_pullback_after_2_roi'   => round($medianPullback2, 2),
            'median_pullback_after_3_roi'   => round($medianPullback3, 2),
            'median_pullback_after_5_roi'   => round($medianPullback5, 2),

            // ── Reach rates ───────────────────────────────────────────────────
            'reach_5_roi_rate'              => $reach5Rate,
            'reach_10_roi_rate'             => $reach10Rate,
            'reach_15_roi_rate'             => $reach15Rate,
            'failure_before_3_roi_rate'     => $failBefore3Rate,
            'stop_loss_hit_rate'            => $slHitRate,

            // ── Timing ────────────────────────────────────────────────────────
            'avg_hold_minutes'              => $avgHoldMinutes,
            'avg_time_to_2_roi'             => $avgTimeTo2Roi,
            'avg_time_to_5_roi'             => $avgTimeTo5Roi,
            'avg_time_to_10_roi'            => $avgTimeTo10Roi,

            // ── Live eligibility ──────────────────────────────────────────────
            'recommended_live_eligibility'  => $liveEligibility,
            'live_block_reason'             => $liveBlockReason,

            // ── Recommendations ───────────────────────────────────────────────
            'recommended_live_floor_roi'         => round($recLiveFloor, 2),
            'recommended_stage1_start_roi'        => round($recStage1, 2),
            'recommended_stage2_start_roi'        => round($recStage2, 2),
            'recommended_harvest_aggressiveness'  => $recHarvest,

            // ── Legacy field aliases (kept for backward compat with Brain/UI) ──
            'sample_size'                        => $sampleSizeTotal,
            'median_max_roi'                     => round($corridorP50, 2),
            'p75_max_roi'                        => round($corridorP75, 2),
            'p90_max_roi'                        => round($corridorP90, 2),
            'corridor_low_roi'                   => round($this->percentile($maxRois, 25), 2),
            'corridor_mid_roi'                   => round($corridorP50, 2),
            'corridor_high_roi'                  => round($corridorP75, 2),
            'recommended_guaranteed_lock_start_roi' => round($recLockStart, 2),
            'recommended_guaranteed_lock_value_roi' => round($recLockValue, 2),
            'recommended_stage1_threshold_roi'      => round($recStage1, 2),
            'recommended_stage2_threshold_roi'      => round($recStage2, 2),
            'recommended_ladder_mode'               => $recLadderMode,

            // ── Diagnostics ───────────────────────────────────────────────────
            'notes'                         => $notes,
        ];
    }

    // =========================================================================
    // Field extraction helpers
    // =========================================================================

    /**
     * Extract final ROI (in %) from a trade record.
     */
    private function extractFinalRoi(array $trade): ?float
    {
        // Try common field names
        foreach (['roi_margin', 'roi_percent', 'roi_final', 'roi'] as $key) {
            if (isset($trade[$key]) && is_numeric($trade[$key])) {
                $val = (float)$trade[$key];
                // Values stored as ratio (e.g. 0.05 = 5%) → convert to %
                if (abs($val) < 2.0 && $val !== 0.0) {
                    $val *= 100.0;
                }
                return $val;
            }
        }

        // Look inside runtime sub-array
        $runtime = $trade['runtime'] ?? [];
        if (is_array($runtime)) {
            foreach (['roi_percent', 'roi_margin', 'final_roi'] as $key) {
                if (isset($runtime[$key]) && is_numeric($runtime[$key])) {
                    $val = (float)$runtime[$key];
                    if (abs($val) < 2.0 && $val !== 0.0) {
                        $val *= 100.0;
                    }
                    return $val;
                }
            }
        }

        return null;
    }

    /**
     * Extract peak/max ROI (in %) from a trade record.
     * Falls back to final ROI if no peak data available.
     */
    private function extractPeakRoi(array $trade, ?float $fallback): ?float
    {
        foreach (['trailing_peak_roi', 'peak_roi', 'max_roi', 'mfe', 'mfe_roi'] as $key) {
            if (isset($trade[$key]) && is_numeric($trade[$key])) {
                $val = (float)$trade[$key];
                if (abs($val) < 2.0 && $val !== 0.0) {
                    $val *= 100.0;
                }
                return $val;
            }
        }

        // Check runtime
        $runtime = $trade['runtime'] ?? [];
        if (is_array($runtime)) {
            foreach (['trailing_peak_roi', 'peak_roi', 'mfe', 'max_roi_reached'] as $key) {
                if (isset($runtime[$key]) && is_numeric($runtime[$key])) {
                    $val = (float)$runtime[$key];
                    if (abs($val) < 2.0 && $val !== 0.0) {
                        $val *= 100.0;
                    }
                    return $val;
                }
            }
        }

        return $fallback;
    }

    /**
     * Extract maximum adverse excursion (worst drawdown) in %, as a positive value.
     */
    private function extractAdverseRoi(array $trade): ?float
    {
        foreach (['mae', 'mae_roi', 'max_adverse_roi', 'max_drawdown_roi'] as $key) {
            if (isset($trade[$key]) && is_numeric($trade[$key])) {
                $val = abs((float)$trade[$key]);
                if ($val < 2.0 && $val !== 0.0) {
                    $val *= 100.0;
                }
                return $val;
            }
        }
        $runtime = $trade['runtime'] ?? [];
        if (is_array($runtime)) {
            foreach (['mae', 'mae_roi', 'max_adverse_roi'] as $key) {
                if (isset($runtime[$key]) && is_numeric($runtime[$key])) {
                    $val = abs((float)$runtime[$key]);
                    if ($val < 2.0 && $val !== 0.0) {
                        $val *= 100.0;
                    }
                    return $val;
                }
            }
        }
        return null;
    }

    /**
     * Detect whether a trade was closed by stop-loss.
     */
    private function detectStopLossHit(array $trade, ?float $finalRoi, ?float $maxRoi): bool
    {
        // Explicit close_reason field
        $closeReason = strtolower((string)($trade['close_reason'] ?? $trade['exit_reason'] ?? ''));
        if (str_contains($closeReason, 'stop_loss') || str_contains($closeReason, 'sl_hit')) {
            return true;
        }
        $runtime = $trade['runtime'] ?? [];
        if (is_array($runtime)) {
            $rr = strtolower((string)($runtime['close_reason'] ?? ''));
            if (str_contains($rr, 'stop_loss') || str_contains($rr, 'sl_hit')) {
                return true;
            }
        }
        // Heuristic: final ROI <= -3% and final roughly equals some stop value
        if ($finalRoi !== null && $finalRoi <= -3.0) {
            return true;
        }
        return false;
    }

    /**
     * Extract hold time in minutes from a trade record.
     */
    private function extractHoldMinutes(array $trade): ?float
    {
        foreach (['hold_minutes', 'duration_minutes', 'trade_duration_minutes'] as $key) {
            if (isset($trade[$key]) && is_numeric($trade[$key])) {
                return (float)$trade[$key];
            }
        }
        // Derive from open/close timestamps
        $openTs  = (int)($trade['open_ts']   ?? $trade['created_ts']  ?? 0);
        $closeTs = (int)($trade['closed_ts'] ?? $trade['close_ts']    ?? 0);
        if ($openTs > 0 && $closeTs > $openTs) {
            return round(($closeTs - $openTs) / 60.0, 1);
        }
        return null;
    }

    /**
     * Extract time-to-target fields (minutes to reach 2/5/10 ROI).
     *
     * @return array{?float, ?float, ?float}
     */
    private function extractTimeToTargets(array $trade): array
    {
        $runtime = $trade['runtime'] ?? [];
        if (!is_array($runtime)) {
            $runtime = [];
        }

        $t2  = null;
        $t5  = null;
        $t10 = null;

        foreach (['time_to_2_roi_minutes',  'minutes_to_2_roi']  as $k) {
            if (isset($runtime[$k]) && is_numeric($runtime[$k])) { $t2  = (float)$runtime[$k]; break; }
            if (isset($trade[$k])   && is_numeric($trade[$k]))   { $t2  = (float)$trade[$k];   break; }
        }
        foreach (['time_to_5_roi_minutes',  'minutes_to_5_roi']  as $k) {
            if (isset($runtime[$k]) && is_numeric($runtime[$k])) { $t5  = (float)$runtime[$k]; break; }
            if (isset($trade[$k])   && is_numeric($trade[$k]))   { $t5  = (float)$trade[$k];   break; }
        }
        foreach (['time_to_10_roi_minutes', 'minutes_to_10_roi'] as $k) {
            if (isset($runtime[$k]) && is_numeric($runtime[$k])) { $t10 = (float)$runtime[$k]; break; }
            if (isset($trade[$k])   && is_numeric($trade[$k]))   { $t10 = (float)$trade[$k];   break; }
        }

        return [$t2, $t5, $t10];
    }

    /**
     * Extract raw price-pct move (abs value) if available.
     */
    private function extractPricePct(array $trade): ?float
    {
        foreach (['price_pct_move', 'price_change_pct', 'entry_to_peak_pct'] as $key) {
            if (isset($trade[$key]) && is_numeric($trade[$key])) {
                return abs((float)$trade[$key]);
            }
        }
        return null;
    }

    // =========================================================================
    // Statistical helpers
    // =========================================================================

    /**
     * Compute Nth percentile from a sorted array of floats.
     *
     * @param list<float> $sorted
     */
    private function percentile(array $sorted, int $n): float
    {
        if (empty($sorted)) {
            return 0.0;
        }
        $count = count($sorted);
        $idx   = ($n / 100) * ($count - 1);
        $lower = (int)floor($idx);
        $upper = (int)ceil($idx);
        if ($lower === $upper) {
            return (float)$sorted[$lower];
        }
        $frac = $idx - $lower;
        return (float)$sorted[$lower] + $frac * ((float)$sorted[$upper] - (float)$sorted[$lower]);
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }
        $sorted = $values;
        sort($sorted);
        return $this->percentile($sorted, 50);
    }

    // =========================================================================
    // Score computation
    // =========================================================================

    /**
     * Volatility score: how wide is the ROI spread?
     * High score = large spread = volatile.
     *
     * @param list<float> $maxRois
     */
    private function computeVolatilityScore(array $maxRois): float
    {
        if (count($maxRois) < 2) {
            return 0.5;
        }
        $range = max($maxRois) - min($maxRois);
        // Normalise: 0-30% range maps to 0-1
        return min(1.0, $range / 30.0);
    }

    /**
     * Noise score: fraction of trades where final ROI << peak ROI.
     * High noise = price peaks then reverses badly.
     *
     * @param list<float> $maxRois
     * @param list<float> $finalRois
     */
    private function computeNoiseScore(array $maxRois, array $finalRois): float
    {
        if (empty($maxRois) || count($maxRois) !== count($finalRois)) {
            return 0.5;
        }
        $noisy = 0;
        foreach ($maxRois as $i => $peak) {
            if ($peak > 0 && isset($finalRois[$i])) {
                $giveback = $peak - $finalRois[$i];
                if ($giveback / $peak > 0.5) {
                    $noisy++;
                }
            }
        }
        return round($noisy / count($maxRois), 4);
    }

    /**
     * Trend persistence: fraction of trades that hold >=60% of peak ROI at close.
     *
     * @param list<float> $maxRois
     * @param list<float> $pullbacksAt3
     */
    private function computeTrendPersistenceScore(array $maxRois, array $pullbacksAt3): float
    {
        if (empty($pullbacksAt3)) {
            return 0.5;
        }
        $persistent = 0;
        foreach ($pullbacksAt3 as $pullback) {
            if ($pullback < 1.5) {
                $persistent++;
            }
        }
        return round($persistent / count($pullbacksAt3), 4);
    }

    /**
     * Fake breakout score: fraction of trades that peaked quickly then failed.
     *
     * @param list<float> $maxRois
     * @param list<float> $finalRois
     */
    private function computeFakeBreakoutScore(array $maxRois, array $finalRois): float
    {
        if (empty($maxRois) || count($maxRois) !== count($finalRois)) {
            return 0.3;
        }
        $fakes = 0;
        foreach ($maxRois as $i => $peak) {
            if ($peak >= 3.0 && isset($finalRois[$i]) && $finalRois[$i] <= 0.0) {
                $fakes++;
            }
        }
        return round($fakes / count($maxRois), 4);
    }

    /**
     * Data confidence label based on sample size.
     */
    private function computeConfidence(int $sampleSize): string
    {
        if ($sampleSize >= self::MIN_SAMPLE_HIGH) {
            return 'high';
        }
        if ($sampleSize >= self::MIN_SAMPLE_MEDIUM) {
            return 'medium';
        }
        if ($sampleSize > 0) {
            return 'low';
        }
        return 'none';
    }

    /**
     * Compute data sufficiency flags.
     *
     * @return array{bool, string|null, string}  [insufficient_flag, reason, fallback_mode]
     */
    private function computeDataSufficiency(
        int    $total,
        int    $shortV2,
        int    $shortV3,
        int    $recent,
        string $confidence
    ): array {
        if ($total === 0) {
            return [true, 'no_trade_data', 'shadow_only'];
        }
        if ($total < self::MIN_TOTAL_SAMPLES) {
            return [true, "insufficient_total_samples:{$total}<" . self::MIN_TOTAL_SAMPLES, 'sim_only'];
        }
        if ($recent < self::MIN_RECENT_SAMPLES) {
            return [true, "insufficient_recent_samples:{$recent}<" . self::MIN_RECENT_SAMPLES, 'sim_only'];
        }
        if ($confidence === 'none' || $confidence === 'low') {
            return [true, "low_data_confidence:{$confidence}", 'sim_only'];
        }
        return [false, null, 'live_eligible'];
    }

    /**
     * Market regime health score based on corridor quality and recent activity.
     * 0.0 = dead/unknown, 1.0 = strong.
     */
    private function computeMarketRegimeHealthScore(
        float $corridorP75,
        float $runnerProb,
        int   $recentSamples
    ): float {
        $score = 0.0;

        // Corridor quality component (0–0.5)
        if ($corridorP75 >= 8.0) {
            $score += 0.5;
        } elseif ($corridorP75 >= 5.0) {
            $score += 0.35;
        } elseif ($corridorP75 >= 3.0) {
            $score += 0.2;
        } elseif ($corridorP75 >= 1.0) {
            $score += 0.1;
        }

        // Runner probability component (0–0.3)
        if ($runnerProb >= 0.15) {
            $score += 0.3;
        } elseif ($runnerProb >= 0.07) {
            $score += 0.15;
        } elseif ($runnerProb >= 0.03) {
            $score += 0.07;
        }

        // Recent activity component (0–0.2)
        if ($recentSamples >= 10) {
            $score += 0.2;
        } elseif ($recentSamples >= 5) {
            $score += 0.12;
        } elseif ($recentSamples >= self::MIN_RECENT_SAMPLES) {
            $score += 0.05;
        }

        return min(1.0, round($score, 4));
    }

    /**
     * Compute live eligibility decision based on all passport metrics.
     *
     * @return array{string, string|null}  [eligibility, block_reason]
     */
    private function computeLiveEligibility(
        float  $corridorP75,
        float  $runnerProb,
        float  $shortSuitability,
        float  $noiseScore,
        string $dataConfidence,
        float  $regimeHealth,
        bool   $insufficientFlag,
        string $fallbackMode
    ): array {
        // Insufficient data → forced fallback
        if ($insufficientFlag) {
            return [$fallbackMode === 'shadow_only' ? 'shadow_only' : 'sim_only',
                    "insufficient_data:{$fallbackMode}"];
        }

        // Data confidence gate
        $confRank = ['none' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];
        $minRank  = $confRank[self::LIVE_GATE_CONFIDENCE_MIN] ?? 1;
        $curRank  = $confRank[$dataConfidence] ?? 0;
        if ($curRank < $minRank) {
            return ['sim_only', "data_confidence_too_low:{$dataConfidence}"];
        }

        // Corridor P75 gate
        if ($corridorP75 < self::LIVE_GATE_CORRIDOR_P75_MIN) {
            return ['sim_only', "corridor_p75_too_low:{$corridorP75}<" . self::LIVE_GATE_CORRIDOR_P75_MIN];
        }

        // Noise gate (high noise → sim only)
        if ($noiseScore > self::LIVE_GATE_NOISE_MAX) {
            return ['sim_only', "noise_score_too_high:{$noiseScore}>" . self::LIVE_GATE_NOISE_MAX];
        }

        // Suitability gate
        if ($shortSuitability < self::LIVE_GATE_SUITABILITY_MIN) {
            return ['sim_only', "short_suitability_too_low:{$shortSuitability}<" . self::LIVE_GATE_SUITABILITY_MIN];
        }

        // Runner probability gate
        if ($runnerProb < self::LIVE_GATE_RUNNER_PROB_MIN) {
            return ['sim_only', "runner_prob_too_low:{$runnerProb}<" . self::LIVE_GATE_RUNNER_PROB_MIN];
        }

        // Regime health gate
        if ($regimeHealth < self::LIVE_GATE_REGIME_HEALTH_MIN) {
            return ['sim_only', "regime_health_too_low:{$regimeHealth}<" . self::LIVE_GATE_REGIME_HEALTH_MIN];
        }

        return ['allow_live', null];
    }

    // =========================================================================
    // Recommendation helpers
    // =========================================================================

    private function recommendLockStart(float $medianMax, float $p75, float $corridorLow, string $confidence): float
    {
        if ($confidence === 'none') {
            return 3.0;
        }
        // Conservative: lock at corridor low, capped between 1.5 and 5
        $base = max(1.5, $corridorLow * 0.6);
        return min(5.0, round($base, 1));
    }

    private function recommendStage1(float $corridorMid, float $medianPullback3): float
    {
        $base = max(3.0, $corridorMid * 0.5);
        return min(7.0, round($base, 1));
    }

    private function recommendStage2(float $corridorHigh, float $p90MaxRoi): float
    {
        $base = max(5.0, $corridorHigh * 0.7);
        return min(15.0, round($base, 1));
    }

    private function recommendLiveFloorRoi(float $corridorP75, string $confidence): float
    {
        if ($confidence === 'none' || $confidence === 'low') {
            return 0.0;
        }
        // Minimum live target: at least 40% of P75 corridor, floored at 2.0
        return max(2.0, round($corridorP75 * 0.4, 1));
    }

    private function recommendLadderMode(float $runnerProb, float $p90MaxRoi, string $confidence): string
    {
        if ($confidence === 'none' || $confidence === 'low') {
            return 'conservative';
        }
        if ($runnerProb >= 0.15 && $p90MaxRoi >= 10.0) {
            return 'aggressive_ladder';
        }
        if ($runnerProb >= 0.05 || $p90MaxRoi >= 7.0) {
            return 'soft_ladder';
        }
        return 'conservative';
    }

    private function recommendHarvestAggressiveness(float $medianPullback3, float $medianPullback5, float $noiseScore): string
    {
        if ($noiseScore >= 0.6 || $medianPullback3 >= 3.0) {
            return 'aggressive';
        }
        if ($noiseScore >= 0.3 || $medianPullback3 >= 1.5) {
            return 'moderate';
        }
        return 'patient';
    }

    // =========================================================================
    // Diagnostic notes
    // =========================================================================

    /** @return list<string> */
    private function buildDiagnosticNotes(
        int    $sampleSize,
        string $confidence,
        float  $runnerProb,
        float  $medianMaxRoi,
        float  $p75MaxRoi,
        float  $p90MaxRoi,
        float  $corridorLow,
        float  $corridorHigh,
        string $liveEligibility = 'unknown',
        ?string $liveBlockReason = null,
        bool   $insufficientFlag = false
    ): array {
        $notes = [];

        if ($confidence === 'none') {
            $notes[] = 'No trade data available — passport is theoretical defaults only.';
        } elseif ($confidence === 'low') {
            $notes[] = "Low confidence: only {$sampleSize} sample(s). Accumulate more trades for accuracy.";
        }

        if ($insufficientFlag) {
            $notes[] = "Insufficient data: symbol pushed to sim/shadow-only until more evidence accumulates.";
        }

        if ($runnerProb >= 0.15) {
            $notes[] = "Runner coin: " . round($runnerProb * 100, 1) . "% chance of reaching 10+ ROI.";
        } elseif ($runnerProb >= 0.05) {
            $notes[] = "Occasional runner: some trades reach 10+ ROI.";
        } else {
            $notes[] = "Low runner probability: 10+ ROI is rare for this symbol.";
        }

        if ($medianMaxRoi > 0) {
            $notes[] = "Typical trade peaks at {$medianMaxRoi}% ROI (median). 75th pct: {$p75MaxRoi}%.";
        }

        if ($corridorHigh - $corridorLow > 8) {
            $notes[] = "Wide ROI corridor ({$corridorLow}–{$corridorHigh}%) — high variability.";
        }

        if ($liveEligibility === 'allow_live') {
            $notes[] = "Live eligibility: ALLOWED — all passport gates passed.";
        } elseif ($liveBlockReason !== null) {
            $notes[] = "Live eligibility: {$liveEligibility} — blocked: {$liveBlockReason}.";
        }

        return $notes;
    }

    // =========================================================================
    // Persistence
    // =========================================================================

    /** @param array<string,mixed> $passport */
    private function save(string $symbol, array $passport): void
    {
        $path = $this->passportPath($symbol);
        file_put_contents(
            $path,
            json_encode($passport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    private function passportPath(string $symbol): string
    {
        $safe = preg_replace('/[^A-Z0-9_\-]/', '', strtoupper($symbol));
        return $this->passportsDir . '/' . $safe . '.json';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}
