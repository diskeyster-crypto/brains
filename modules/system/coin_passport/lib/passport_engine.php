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
    private string $evidenceDir;
    private string $aiShadowStorageDir;

    /** Minimum trades to compute meaningful confidence */
    private const MIN_SAMPLE_MEDIUM = 5;
    private const MIN_SAMPLE_HIGH   = 20;

    /** Data sufficiency thresholds */
    private const MIN_TOTAL_SAMPLES        = 10;
    private const MIN_PATTERN_V2_SAMPLES   = 5;
    private const MIN_PATTERN_V3_SAMPLES   = 5;
    private const MIN_RECENT_SAMPLES       = 3;

    /** Live eligibility gate thresholds (defaults — override via config if needed) */
    private const LIVE_GATE_CORRIDOR_P75_MIN      = 3.0;   // corridor_p75_roi >= this
    private const LIVE_GATE_RUNNER_PROB_MIN        = 0.05;  // runner_probability >= this
    private const LIVE_GATE_SUITABILITY_MIN        = 0.3;   // short_suitability_score >= this
    private const LIVE_GATE_NOISE_MAX              = 0.65;  // noise_score <= this
    private const LIVE_GATE_CONFIDENCE_MIN         = 'low'; // data_confidence: none→low→medium→high
    private const LIVE_GATE_REGIME_HEALTH_MIN      = 0.3;   // market_regime_health_score >= this
    private const LIVE_GATE_IMPULSE_STRENGTH_MIN   = 0.2;   // impulse_strength_score >= this
    private const LIVE_GATE_PULLBACK_SEVERITY_MAX  = 0.75;  // pullback_severity_score <= this

    /** Evidence timeline config */
    private const MAX_EVIDENCE_ITEMS = 100;

    public function __construct(string $passportsDir, string $tradingBotStorageDir, string $aiShadowStorageDir = '')
    {
        $this->passportsDir         = $passportsDir;
        $this->tradingBotStorageDir = $tradingBotStorageDir;
        $this->aiShadowStorageDir   = $aiShadowStorageDir;
        $this->evidenceDir          = dirname($passportsDir) . '/evidence';

        if (!is_dir($this->passportsDir)) {
            @mkdir($this->passportsDir, 0755, true);
        }
        if (!is_dir($this->evidenceDir)) {
            @mkdir($this->evidenceDir, 0755, true);
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
                $this->rebuildEvidenceTimeline($symbol, $trades);
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
        $this->rebuildEvidenceTimeline($symbol, $trades);
        return $passport;
    }

    /**
     * Load the evidence timeline for a symbol (last MAX_EVIDENCE_ITEMS items).
     *
     * @return list<array<string,mixed>>
     */
    public function loadEvidence(string $symbol): array
    {
        $path = $this->evidencePath($symbol);
        $data = $this->readJson($path);
        if (!is_array($data) || !isset($data['items'])) {
            return [];
        }
        return is_array($data['items']) ? $data['items'] : [];
    }

    /**
     * Append a single evidence event for a symbol (called on trade close etc.).
     *
     * @param array<string,mixed> $event
     */
    public function appendEvidence(string $symbol, array $event): void
    {
        $path  = $this->evidencePath($symbol);
        $data  = $this->readJson($path) ?? ['symbol' => $symbol, 'items' => []];
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];

        // Prepend newest events first
        array_unshift($items, array_merge(['ts' => time(), 'symbol' => $symbol], $event));

        // Cap to MAX_EVIDENCE_ITEMS
        if (count($items) > self::MAX_EVIDENCE_ITEMS) {
            $items = array_slice($items, 0, self::MAX_EVIDENCE_ITEMS);
        }

        $data['symbol']     = $symbol;
        $data['items']      = $items;
        $data['updated_at'] = date('Y-m-d H:i:s');

        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    // =========================================================================
    // Data collection
    // =========================================================================

    /**
     * Collect all available trades grouped by symbol.
     *
     * Sources:
     *   1. trades/closed/*.json       (live closed trades with final ROI)
     *   2. trades/active/*.json        (active live trades — partial signal)
     *   3. sig_*.json in storage root  (legacy active/live trades)
     *   4. ai_shadow/virtual_trades_closed/*.json  (shadow sim outcomes)
     *
     * @return array<string,list<array<string,mixed>>>
     */
    private function collectTradesBySymbol(): array
    {
        $bySymbol = [];

        // Source 1: live closed trades
        $closedDir = $this->tradingBotStorageDir . '/trades/closed';
        if (is_dir($closedDir)) {
            foreach (glob($closedDir . '/*.json') ?: [] as $file) {
                $trade = $this->readJson($file);
                if (is_array($trade) && !empty($trade['symbol'])) {
                    $sym = strtoupper((string)$trade['symbol']);
                    $trade['_source'] = 'live_closed';
                    $bySymbol[$sym][] = $trade;
                }
            }
        }

        // Source 2: active live trades (tag as active so we know they're open)
        $activeDir = $this->tradingBotStorageDir . '/trades/active';
        if (is_dir($activeDir)) {
            foreach (glob($activeDir . '/*.json') ?: [] as $file) {
                $trade = $this->readJson($file);
                if (is_array($trade) && !empty($trade['symbol'])) {
                    $sym = strtoupper((string)$trade['symbol']);
                    $trade['_source'] = 'live_active';
                    $bySymbol[$sym][] = $trade;
                }
            }
        }

        // Source 3: root sig_*.json files (active / legacy trades)
        foreach (glob($this->tradingBotStorageDir . '/sig_*.json') ?: [] as $file) {
            $trade = $this->readJson($file);
            if (is_array($trade) && !empty($trade['symbol'])) {
                $sym = strtoupper((string)$trade['symbol']);
                $trade['_source'] = 'live_legacy';
                $bySymbol[$sym][] = $trade;
            }
        }

        // Source 4: AI shadow virtual closed trades (additional signal)
        if ($this->aiShadowStorageDir !== '' && is_dir($this->aiShadowStorageDir)) {
            $shadowClosedDir = $this->aiShadowStorageDir . '/virtual_trades_closed';
            if (is_dir($shadowClosedDir)) {
                foreach (glob($shadowClosedDir . '/*.json') ?: [] as $file) {
                    $trade = $this->readJson($file);
                    if (is_array($trade) && !empty($trade['symbol'])) {
                        $sym = strtoupper((string)$trade['symbol']);
                        $trade['_source'] = 'shadow_closed';
                        $bySymbol[$sym][] = $trade;
                    }
                }
            }

            // Source 5: AI shadow virtual active trades (partial signal — adds recency evidence)
            $shadowActiveDir = $this->aiShadowStorageDir . '/virtual_trades_active';
            if (is_dir($shadowActiveDir)) {
                foreach (glob($shadowActiveDir . '/*.json') ?: [] as $file) {
                    $trade = $this->readJson($file);
                    if (is_array($trade) && !empty($trade['symbol'])) {
                        $sym = strtoupper((string)$trade['symbol']);
                        $trade['_source'] = 'shadow_active';
                        $bySymbol[$sym][] = $trade;
                    }
                }
            }
        }

        return $bySymbol;
    }

    // =========================================================================
    // Passport building
    // =========================================================================

    /**
     * Build a complete deep behavioral profile passport for one symbol from its trades.
     *
     * @param list<array<string,mixed>> $trades
     * @return array<string,mixed>
     */
    private function buildPassport(string $symbol, array $trades): array
    {
        $sampleSizeTotal = count($trades);

        // Per-pattern sample counts (V2/V3, both sides)
        $sampleV2 = 0;
        $sampleV3 = 0;

        // Core metric arrays (all trades)
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

        // Pullback-from-peak arrays
        $pullbacksFromPeak = []; // max_roi - final_roi for every trade
        $deepRetraces      = 0;  // gave back >70% of peak

        // V2 / V3 pattern-specific arrays
        $v2MaxRois   = []; $v2FinalRois = []; $v2SlHits = 0; $v2Runners = 0;
        $v3MaxRois   = []; $v3FinalRois = []; $v3SlHits = 0; $v3Runners = 0;

        // Session / hour-of-day stats: hour (0–23) → [count, roi_sum, runner_count, sl_count, fake_count]
        $hourStats = [];

        // Counters
        $runners  = 0;
        $shorts   = 0;
        $longs    = 0;
        $slHits   = 0;
        $reach5   = 0;
        $reach10  = 0;
        $reach15  = 0;
        $failBefore3 = 0;
        $shadowSamples       = 0;  // from AI shadow source (closed + active)
        $shadowClosedSamples = 0;  // shadow_closed only
        $shadowActiveSamples = 0;  // shadow_active only
        $liveClosedSamples   = 0;  // live_closed only
        $liveActiveSamples   = 0;  // live_active + live_legacy

        // Initial burst: hit 2 ROI within the first half of hold time
        $burstCount = 0;
        $burstTotal = 0;

        // Recent trades: last 30 days
        $recentCutoff  = time() - 30 * 86400;
        $recentSamples = 0;

        foreach ($trades as $trade) {
            $source  = (string)($trade['_source'] ?? 'live_closed');
            $isLive  = strncmp($source, 'live', 4) === 0;
            $isShadow = $source === 'shadow_closed' || $source === 'shadow_active';
            $isShadowClosed = $source === 'shadow_closed';
            $isShadowActive = $source === 'shadow_active';

            if ($isShadowClosed) {
                $shadowSamples++;
                $shadowClosedSamples++;
            } elseif ($isShadowActive) {
                $shadowSamples++;
                $shadowActiveSamples++;
            } elseif ($source === 'live_closed') {
                $liveClosedSamples++;
            } elseif ($source === 'live_active' || $source === 'live_legacy') {
                $liveActiveSamples++;
            }

            $finalRoi   = $this->extractFinalRoi($trade);
            $maxRoi     = $this->extractPeakRoi($trade, $finalRoi);
            $adverseRoi = $this->extractAdverseRoi($trade);
            $side       = strtolower((string)($trade['side'] ?? ''));
            $patternAlgo = (string)($trade['pattern_algorithm'] ?? '');
            $closedTs   = (int)($trade['closed_ts'] ?? $trade['closed_at'] ?? 0);
            $openTs     = (int)($trade['open_ts'] ?? $trade['opened_at'] ?? $trade['created_ts'] ?? 0);

            // Pattern counts (V2/V3, both sides)
            if ($side === 'short') {
                $shorts++;
                if ($patternAlgo === 'double_top_contextual_v2') {
                    $sampleV2++;
                } elseif ($patternAlgo === 'double_top_contextual_v3') {
                    $sampleV3++;
                }
            } elseif ($side === 'long') {
                $longs++;
                if ($patternAlgo === 'double_bottom_contextual_v2') {
                    $sampleV2++;
                } elseif ($patternAlgo === 'double_bottom_contextual_v3') {
                    $sampleV3++;
                }
            }

            if ($closedTs >= $recentCutoff || ($openTs >= $recentCutoff && $openTs > 0)) {
                $recentSamples++;
            }

            if ($finalRoi !== null) {
                $finalRois[] = $finalRoi;
            }
            if ($adverseRoi !== null) {
                $adverseRois[] = $adverseRoi;
            }

            // Stop-loss detection
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
                    // Failure before 3 ROI
                    if ($maxRoi < 3.0 && $finalRoi <= 0.0) {
                        $failBefore3++;
                    }

                    // Pullback-from-peak (all trades)
                    $pbFromPeak = max(0.0, $maxRoi - $finalRoi);
                    $pullbacksFromPeak[] = $pbFromPeak;

                    // Deep retrace: gave back >70% of peak
                    if ($maxRoi > 0 && $pbFromPeak / $maxRoi > 0.70) {
                        $deepRetraces++;
                    }

                    // Milestone pullbacks
                    if ($maxRoi >= 2.0) {
                        $pullbacksAt2[] = $pbFromPeak;
                    }
                    if ($maxRoi >= 3.0) {
                        $pullbacksAt3[] = $pbFromPeak;
                    }
                    if ($maxRoi >= 5.0) {
                        $pullbacksAt5[] = $pbFromPeak;
                    }
                }

                // Pattern-specific tracking (live trades only for clean stats)
                if ($isLive) {
                    if ($patternAlgo === 'double_top_contextual_v2' || $patternAlgo === 'double_bottom_contextual_v2') {
                        $v2MaxRois[]   = $maxRoi;
                        if ($finalRoi !== null) $v2FinalRois[] = $finalRoi;
                        if ($isSlHit)  $v2SlHits++;
                        if ($maxRoi >= 10.0) $v2Runners++;
                    } elseif ($patternAlgo === 'double_top_contextual_v3' || $patternAlgo === 'double_bottom_contextual_v3') {
                        $v3MaxRois[]   = $maxRoi;
                        if ($finalRoi !== null) $v3FinalRois[] = $finalRoi;
                        if ($isSlHit)  $v3SlHits++;
                        if ($maxRoi >= 10.0) $v3Runners++;
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

            // Price-pct move
            $pricePct = $this->extractPricePct($trade);
            if ($pricePct !== null) {
                $pricePctMoves[] = $pricePct;
            }

            // Hour-of-day from open_ts
            if ($openTs > 0) {
                $hour = (int)gmdate('G', $openTs);
                if (!isset($hourStats[$hour])) {
                    $hourStats[$hour] = ['count' => 0, 'roi_sum' => 0.0, 'runner_count' => 0, 'sl_count' => 0, 'fake_count' => 0];
                }
                $hourStats[$hour]['count']++;
                if ($finalRoi !== null) {
                    $hourStats[$hour]['roi_sum'] += $finalRoi;
                }
                if ($maxRoi !== null && $maxRoi >= 10.0) {
                    $hourStats[$hour]['runner_count']++;
                }
                if ($isSlHit) {
                    $hourStats[$hour]['sl_count']++;
                }
                // Fake: peaked >= 3 then closed <= 0
                if ($maxRoi !== null && $maxRoi >= 3.0 && $finalRoi !== null && $finalRoi <= 0.0) {
                    $hourStats[$hour]['fake_count']++;
                }
            }

            // Initial burst: did trade reach 2 ROI within first 40% of hold time?
            if ($tt2 !== null && $holdMin !== null && $holdMin > 0) {
                $burstTotal++;
                if ($tt2 / $holdMin <= 0.4) {
                    $burstCount++;
                }
            }
        }

        // ── Sort arrays ──────────────────────────────────────────────────────
        $totalSides = $shorts + $longs;
        sort($maxRois);
        sort($finalRois);
        sort($adverseRois);
        sort($pricePctMoves);
        sort($v2MaxRois);
        sort($v3MaxRois);

        // ── Corridor (on favorable move = maxRoi) ────────────────────────────
        $corridorP50 = $this->percentile($maxRois, 50);
        $corridorP75 = $this->percentile($maxRois, 75);
        $corridorP90 = $this->percentile($maxRois, 90);

        // ── Price pct corridors ──────────────────────────────────────────────
        $corridorPricePctP50 = $this->percentile($pricePctMoves, 50);
        $corridorPricePctP75 = $this->percentile($pricePctMoves, 75);
        $corridorPricePctP90 = $this->percentile($pricePctMoves, 90);

        // ── Median adverse ───────────────────────────────────────────────────
        $medianMaxAdverseRoi = $this->percentile($adverseRois, 50);

        // ── Pullbacks ────────────────────────────────────────────────────────
        $medianPullback2    = $this->median($pullbacksAt2);
        $medianPullback3    = $this->median($pullbacksAt3);
        $medianPullback5    = $this->median($pullbacksAt5);
        $medianPullbackPeak = $this->median($pullbacksFromPeak);

        // ── Reach rates ──────────────────────────────────────────────────────
        $reach5Rate      = $sampleSizeTotal > 0 ? round($reach5      / $sampleSizeTotal, 4) : 0.0;
        $reach10Rate     = $sampleSizeTotal > 0 ? round($reach10     / $sampleSizeTotal, 4) : 0.0;
        $reach15Rate     = $sampleSizeTotal > 0 ? round($reach15     / $sampleSizeTotal, 4) : 0.0;
        $failBefore3Rate = $sampleSizeTotal > 0 ? round($failBefore3 / $sampleSizeTotal, 4) : 0.0;
        $slHitRate       = $sampleSizeTotal > 0 ? round($slHits      / $sampleSizeTotal, 4) : 0.0;
        $deepRetraceProb = count($pullbacksFromPeak) > 0 ? round($deepRetraces / count($pullbacksFromPeak), 4) : 0.0;

        // ── Timing ───────────────────────────────────────────────────────────
        $avgHoldMinutes = count($holdMinutes) > 0 ? round(array_sum($holdMinutes) / count($holdMinutes), 1) : null;
        $avgTimeTo2Roi  = count($timeTo2Roi)  > 0 ? round(array_sum($timeTo2Roi)  / count($timeTo2Roi),  1) : null;
        $avgTimeTo5Roi  = count($timeTo5Roi)  > 0 ? round(array_sum($timeTo5Roi)  / count($timeTo5Roi),  1) : null;
        $avgTimeTo10Roi = count($timeTo10Roi) > 0 ? round(array_sum($timeTo10Roi) / count($timeTo10Roi), 1) : null;

        // ── Core behavioral scores ───────────────────────────────────────────
        $runnerProb            = $sampleSizeTotal > 0 ? round($runners / $sampleSizeTotal, 4) : 0.0;
        $shortSuitabilityScore = $totalSides > 0 ? round($shorts / $totalSides, 4) : 0.5;
        $noiseScore            = $this->computeNoiseScore($maxRois, $finalRois);
        $volatilityScore       = $this->computeVolatilityScore($maxRois);
        $trendPersistenceScore = $this->computeTrendPersistenceScore($maxRois, $pullbacksAt3);
        $fakeBreakoutScore     = $this->computeFakeBreakoutScore($maxRois, $finalRois);
        $slSurvivalScore       = $sampleSizeTotal > 0 ? round(1.0 - $slHitRate, 4) : 0.5;
        $marketRegimeHealth    = $this->computeMarketRegimeHealthScore($corridorP75, $runnerProb, $recentSamples);

        // ── Impulse behavior ─────────────────────────────────────────────────
        $impulse = $this->computeImpulseScores(
            $corridorP75, $corridorP90, $avgTimeTo2Roi, $avgTimeTo5Roi,
            $avgHoldMinutes, $reach5Rate, $reach10Rate, $trendPersistenceScore,
            $fakeBreakoutScore, $noiseScore, $burstCount, $burstTotal
        );

        // ── Pullback behavior ────────────────────────────────────────────────
        $pullbackBehavior = $this->computePullbackBehavior(
            $medianPullback3, $medianPullback5, $medianPullbackPeak,
            $deepRetraceProb, $noiseScore
        );

        // ── Session / hour-of-day behavior ───────────────────────────────────
        $sessionBehavior = $this->computeSessionBehavior($hourStats);

        // ── Pattern-specific behavior ─────────────────────────────────────────
        $patternBehavior = $this->computePatternSpecificBehavior(
            $v2MaxRois, $v2FinalRois, $v2SlHits, $v2Runners, $sampleV2,
            $v3MaxRois, $v3FinalRois, $v3SlHits, $v3Runners, $sampleV3
        );

        // ── Regime behavior ───────────────────────────────────────────────────
        $regimeBehavior = $this->computeRegimeBehavior(
            $maxRois, $finalRois, $shorts, $longs,
            $corridorP75, $corridorP90, $runnerProb, $slHitRate
        );

        // ── Data confidence ───────────────────────────────────────────────────
        [$dataConfidence, $confidenceScoreNumeric, $confidenceReasonSummary] = $this->computeConfidenceDetailed(
            $sampleSizeTotal, $liveClosedSamples, $shadowClosedSamples,
            $recentSamples, $sampleV2, $sampleV3
        );

        // ── Data sufficiency ──────────────────────────────────────────────────
        [$insufficientFlag, $insufficientReason, $fallbackMode] = $this->computeDataSufficiency(
            $sampleSizeTotal, $sampleV2, $sampleV3, $recentSamples, $dataConfidence
        );
        $lastDataGapWarning = $insufficientFlag ? $insufficientReason : null;

        // Pattern-specific insufficiency flags
        $patternInsufficient = [
            'v2' => $sampleV2 < self::MIN_PATTERN_V2_SAMPLES,
            'v3' => $sampleV3 < self::MIN_PATTERN_V3_SAMPLES,
        ];

        // ── Recommendations ───────────────────────────────────────────────────
        $recLockStart      = $this->recommendLockStart($corridorP50, $corridorP75, $this->percentile($maxRois, 25), $dataConfidence);
        $recLockValue      = max(0.0, $recLockStart - 0.5);
        $recStage1         = $this->recommendStage1($corridorP50, $medianPullback3);
        $recStage2         = $this->recommendStage2($corridorP75, $corridorP90);
        $recLadderMode     = $this->recommendLadderMode($runnerProb, $corridorP90, $dataConfidence);
        $recHarvest        = $this->recommendHarvestAggressiveness($medianPullback3, $medianPullback5, $noiseScore);
        $recLiveFloor      = $this->recommendLiveFloorRoi($corridorP75, $dataConfidence);
        $recMaxHold        = $this->recommendMaxHoldMinutes($avgHoldMinutes, $runnerProb, $corridorP75);
        $recRunnerExpect   = $this->recommendRunnerExpectation($runnerProb, $reach10Rate, $corridorP90);

        // ── Live eligibility gate ─────────────────────────────────────────────
        [$liveEligibility, $liveBlockReason] = $this->computeLiveEligibility(
            $corridorP75,
            $runnerProb,
            $shortSuitabilityScore,
            $noiseScore,
            $dataConfidence,
            $marketRegimeHealth,
            $insufficientFlag,
            $fallbackMode,
            $impulse['impulse_strength_score'],
            $pullbackBehavior['pullback_severity_score'],
            $patternBehavior['v2_success_rate'] ?? null
        );

        // ── Diagnostic notes ──────────────────────────────────────────────────
        $notes = $this->buildDiagnosticNotes(
            $sampleSizeTotal, $dataConfidence, $runnerProb,
            $corridorP50, $corridorP75, $corridorP90,
            $this->percentile($maxRois, 25), $corridorP75,
            $liveEligibility, $liveBlockReason, $insufficientFlag
        );

        return [
            // ── Identity ──────────────────────────────────────────────────────
            'symbol'                        => $symbol,
            'updated_at'                    => date('Y-m-d H:i:s'),

            // ── Sample sizes ───────────────────────────────────────────────────
            'sample_size_total'             => $sampleSizeTotal,
            'sample_size_short_v2'          => $sampleV2,
            'sample_size_short_v3'          => $sampleV3,
            'sample_size_shadow'            => $shadowSamples,
            'sample_size_live_closed'       => $liveClosedSamples,
            'sample_size_live_active'       => $liveActiveSamples,
            'sample_size_shadow_closed'     => $shadowClosedSamples,
            'sample_size_shadow_active'     => $shadowActiveSamples,

            // ── Data confidence / sufficiency ──────────────────────────────────
            'data_confidence'               => $dataConfidence,
            'confidence_score_numeric'      => $confidenceScoreNumeric,
            'confidence_reason_summary'     => $confidenceReasonSummary,
            'last_data_gap_warning'         => $lastDataGapWarning,
            'minimum_required_samples'      => self::MIN_TOTAL_SAMPLES,
            'current_usable_samples'        => $sampleSizeTotal,
            'insufficient_data_flag'        => $insufficientFlag,
            'insufficient_data_reason'      => $insufficientReason,
            'fallback_mode'                 => $fallbackMode,
            'pattern_specific_insufficient_data' => $patternInsufficient,
            'timing_insufficient_data'      => count($hourStats) < 3,
            'regime_insufficient_data'      => $sampleSizeTotal < 15,

            // ── Core behavioral scores (0.0–1.0) ──────────────────────────────
            'short_suitability_score'       => round($shortSuitabilityScore, 4),
            'runner_probability'            => round($runnerProb, 4),
            'noise_score'                   => round($noiseScore, 4),
            'volatility_score'              => round($volatilityScore, 4),
            'trend_persistence_score'       => round($trendPersistenceScore, 4),
            'fake_breakout_score'           => round($fakeBreakoutScore, 4),
            'sl_survival_score'             => round($slSurvivalScore, 4),
            'market_regime_health_score'    => round($marketRegimeHealth, 4),

            // ── Impulse behavior ───────────────────────────────────────────────
            'impulse_strength_score'        => $impulse['impulse_strength_score'],
            'impulse_speed_score'           => $impulse['impulse_speed_score'],
            'impulse_decay_score'           => $impulse['impulse_decay_score'],
            'runner_extension_score'        => $impulse['runner_extension_score'],
            'time_to_peak_score'            => $impulse['time_to_peak_score'],
            'initial_burst_score'           => $impulse['initial_burst_score'],
            'sustained_move_score'          => $impulse['sustained_move_score'],
            'late_failure_score'            => $impulse['late_failure_score'],

            // ── Pullback behavior ──────────────────────────────────────────────
            'pullback_severity_score'       => $pullbackBehavior['pullback_severity_score'],
            'post_impulse_retrace_habit'    => $pullbackBehavior['post_impulse_retrace_habit'],
            'deep_retrace_probability'      => $deepRetraceProb,
            'median_pullback_after_peak'    => round($medianPullbackPeak, 2),
            'median_pullback_after_2_roi'   => round($medianPullback2, 2),
            'median_pullback_after_3_roi'   => round($medianPullback3, 2),
            'median_pullback_after_5_roi'   => round($medianPullback5, 2),

            // ── Corridor — favorable ROI (max/peak) ────────────────────────────
            'corridor_p50_roi'              => round($corridorP50, 2),
            'corridor_p75_roi'              => round($corridorP75, 2),
            'corridor_p90_roi'              => round($corridorP90, 2),

            // ── Corridor — price pct move ──────────────────────────────────────
            'corridor_price_pct_p50'        => round($corridorPricePctP50, 4),
            'corridor_price_pct_p75'        => round($corridorPricePctP75, 4),
            'corridor_price_pct_p90'        => round($corridorPricePctP90, 4),

            // ── Max favorable / adverse ────────────────────────────────────────
            'median_max_favorable_roi'      => round($corridorP50, 2),
            'median_max_adverse_roi'        => round($medianMaxAdverseRoi, 2),

            // ── Reach rates ────────────────────────────────────────────────────
            'reach_5_roi_rate'              => $reach5Rate,
            'reach_10_roi_rate'             => $reach10Rate,
            'reach_15_roi_rate'             => $reach15Rate,
            'failure_before_3_roi_rate'     => $failBefore3Rate,
            'stop_loss_hit_rate'            => $slHitRate,
            'deep_retrace_rate'             => $deepRetraceProb,

            // ── Timing ────────────────────────────────────────────────────────
            'avg_hold_minutes'              => $avgHoldMinutes,
            'avg_time_to_2_roi'             => $avgTimeTo2Roi,
            'avg_time_to_5_roi'             => $avgTimeTo5Roi,
            'avg_time_to_10_roi'            => $avgTimeTo10Roi,

            // ── Session / timing behavior ──────────────────────────────────────
            'best_hours_utc'                => $sessionBehavior['best_hours_utc'],
            'worst_hours_utc'               => $sessionBehavior['worst_hours_utc'],
            'session_behavior_score'        => $sessionBehavior['session_behavior_score'],
            'time_of_day_runner_rate'       => $sessionBehavior['time_of_day_runner_rate'],
            'time_of_day_fake_move_rate'    => $sessionBehavior['time_of_day_fake_move_rate'],
            'time_of_day_stop_rate'         => $sessionBehavior['time_of_day_stop_rate'],

            // ── Pattern-specific behavior ──────────────────────────────────────
            'pattern_behavior'              => $patternBehavior,

            // ── Regime behavior ────────────────────────────────────────────────
            'bull_regime_behavior_score'    => $regimeBehavior['bull_regime_behavior_score'],
            'bear_regime_behavior_score'    => $regimeBehavior['bear_regime_behavior_score'],
            'sideways_regime_behavior_score' => $regimeBehavior['sideways_regime_behavior_score'],
            'high_vol_regime_behavior_score' => $regimeBehavior['high_vol_regime_behavior_score'],
            'fear_regime_behavior_score'    => $regimeBehavior['fear_regime_behavior_score'],
            'regime_sensitivity_score'      => $regimeBehavior['regime_sensitivity_score'],

            // ── Live eligibility ───────────────────────────────────────────────
            'recommended_live_eligibility'  => $liveEligibility,
            'live_block_reason'             => $liveBlockReason,

            // ── Recommendations ────────────────────────────────────────────────
            'recommended_live_floor_roi'          => round($recLiveFloor, 2),
            'recommended_stage1_start_roi'        => round($recStage1, 2),
            'recommended_stage2_start_roi'        => round($recStage2, 2),
            'recommended_harvest_aggressiveness'  => $recHarvest,
            'recommended_max_hold_minutes'        => $recMaxHold,
            'recommended_runner_expectation'      => $recRunnerExpect,

            // ── Legacy field aliases (kept for backward compat with Brain/UI) ───
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

            // ── Diagnostics ────────────────────────────────────────────────────
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
        // Heuristic: assume stop-loss if final ROI <= -3%
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
    // Impulse behavior
    // =========================================================================

    /**
     * Compute impulse behavior scores.
     *
     * @return array<string,float>
     */
    private function computeImpulseScores(
        float   $corridorP75,
        float   $corridorP90,
        ?float  $avgTimeTo2Roi,
        ?float  $avgTimeTo5Roi,
        ?float  $avgHoldMinutes,
        float   $reach5Rate,
        float   $reach10Rate,
        float   $trendPersistenceScore,
        float   $fakeBreakoutScore,
        float   $noiseScore,
        int     $burstCount,
        int     $burstTotal
    ): array {
        // impulse_strength_score: how far typical good trades go (corridor-based)
        $impulseStrength = min(1.0, $corridorP75 / 10.0);

        // impulse_speed_score: inversely proportional to avg_time_to_2_roi (fast = high)
        $impulseSpeed = 0.5; // default
        if ($avgTimeTo2Roi !== null && $avgTimeTo2Roi > 0) {
            // Fast: < 15 min → 1.0, Slow: > 120 min → 0.0
            $impulseSpeed = max(0.0, min(1.0, 1.0 - ($avgTimeTo2Roi - 15) / 105));
        }

        // impulse_decay_score: how quickly gains evaporate after peak (lower = better)
        // Derived from noise_score and fake_breakout_score
        $impulseDecay = min(1.0, ($noiseScore + $fakeBreakoutScore) / 1.5);

        // runner_extension_score: how often runners go well beyond initial impulse
        $runnerExtension = min(1.0, $reach10Rate * 5.0 + ($corridorP90 / 20.0) * 0.3);

        // time_to_peak_score: how quickly trades reach peak (faster = better)
        $timeToPeakScore = 0.5;
        if ($avgTimeTo5Roi !== null && $avgHoldMinutes !== null && $avgHoldMinutes > 0) {
            $peakRatio = $avgTimeTo5Roi / $avgHoldMinutes;
            // Low ratio (reaches peak quickly) = high score
            $timeToPeakScore = max(0.0, min(1.0, 1.0 - $peakRatio));
        }

        // initial_burst_score: fraction of trades that burst to 2 ROI within 40% of hold time
        $initialBurstScore = $burstTotal > 0 ? round($burstCount / $burstTotal, 4) : 0.3;

        // sustained_move_score: how well moves persist (trend persistence)
        $sustainedMoveScore = round($trendPersistenceScore, 4);

        // late_failure_score: trades that peaked high but closed badly
        // High value = many late failures = bad
        $lateFailureScore = round($fakeBreakoutScore * 1.2 + $noiseScore * 0.3, 4);
        $lateFailureScore = min(1.0, $lateFailureScore);

        return [
            'impulse_strength_score'  => round($impulseStrength, 4),
            'impulse_speed_score'     => round($impulseSpeed, 4),
            'impulse_decay_score'     => round($impulseDecay, 4),
            'runner_extension_score'  => round($runnerExtension, 4),
            'time_to_peak_score'      => round($timeToPeakScore, 4),
            'initial_burst_score'     => round($initialBurstScore, 4),
            'sustained_move_score'    => round($sustainedMoveScore, 4),
            'late_failure_score'      => round($lateFailureScore, 4),
        ];
    }

    // =========================================================================
    // Pullback behavior
    // =========================================================================

    /**
     * @return array<string,mixed>
     */
    private function computePullbackBehavior(
        float $medianPullback3,
        float $medianPullback5,
        float $medianPullbackPeak,
        float $deepRetraceProb,
        float $noiseScore
    ): array {
        // pullback_severity_score: 0 = mild pullbacks, 1 = severe
        // Based on median pullback from peak relative to expected
        $severity = min(1.0, $medianPullbackPeak / 8.0 + $deepRetraceProb * 0.5 + $noiseScore * 0.2);

        // post_impulse_retrace_habit: qualitative label
        $habit = 'mild';
        if ($medianPullback3 >= 3.0 || $deepRetraceProb >= 0.5) {
            $habit = 'deep';
        } elseif ($medianPullback3 >= 1.5 || $deepRetraceProb >= 0.25) {
            $habit = 'moderate';
        }

        return [
            'pullback_severity_score'    => round(min(1.0, $severity), 4),
            'post_impulse_retrace_habit' => $habit,
        ];
    }

    // =========================================================================
    // Session / timing behavior
    // =========================================================================

    /**
     * Compute session/hour-of-day behavioral metrics from hourly trade stats.
     *
     * @param array<int,array<string,mixed>> $hourStats  hour(0-23) → {count, roi_sum, runner_count, sl_count, fake_count}
     * @return array<string,mixed>
     */
    private function computeSessionBehavior(array $hourStats): array
    {
        if (empty($hourStats)) {
            return [
                'best_hours_utc'             => [],
                'worst_hours_utc'            => [],
                'session_behavior_score'     => 0.5,
                'time_of_day_runner_rate'    => [],
                'time_of_day_fake_move_rate' => [],
                'time_of_day_stop_rate'      => [],
            ];
        }

        $hourAvgRoi    = [];
        $hourRunnerRate = [];
        $hourFakeRate  = [];
        $hourStopRate  = [];

        foreach ($hourStats as $hour => $stat) {
            $cnt = (int)($stat['count'] ?? 0);
            if ($cnt === 0) continue;
            $avgRoi  = round((float)($stat['roi_sum'] ?? 0) / $cnt, 2);
            $hourAvgRoi[$hour]    = $avgRoi;
            $hourRunnerRate[$hour] = round((int)($stat['runner_count'] ?? 0) / $cnt, 4);
            $hourFakeRate[$hour]  = round((int)($stat['fake_count']   ?? 0) / $cnt, 4);
            $hourStopRate[$hour]  = round((int)($stat['sl_count']     ?? 0) / $cnt, 4);
        }

        if (empty($hourAvgRoi)) {
            return [
                'best_hours_utc'             => [],
                'worst_hours_utc'            => [],
                'session_behavior_score'     => 0.5,
                'time_of_day_runner_rate'    => $hourRunnerRate,
                'time_of_day_fake_move_rate' => $hourFakeRate,
                'time_of_day_stop_rate'      => $hourStopRate,
            ];
        }

        // Sort hours by avg ROI
        arsort($hourAvgRoi);
        $best  = array_slice(array_keys($hourAvgRoi), 0, 3);
        asort($hourAvgRoi);
        $worst = array_slice(array_keys($hourAvgRoi), 0, 3);

        // session_behavior_score: 1.0 = consistent across hours, 0.0 = highly variable
        $allRois = array_values($hourAvgRoi);
        $rng     = count($allRois) > 1 ? (max($allRois) - min($allRois)) : 0.0;
        $sessionScore = max(0.0, min(1.0, 1.0 - $rng / 15.0));

        return [
            'best_hours_utc'             => array_values($best),
            'worst_hours_utc'            => array_values($worst),
            'session_behavior_score'     => round($sessionScore, 4),
            'time_of_day_runner_rate'    => $hourRunnerRate,
            'time_of_day_fake_move_rate' => $hourFakeRate,
            'time_of_day_stop_rate'      => $hourStopRate,
        ];
    }

    // =========================================================================
    // Pattern-specific behavior
    // =========================================================================

    /**
     * Compute per-pattern (V2/V3) behavioral statistics.
     *
     * @param list<float> $v2MaxRois
     * @param list<float> $v2FinalRois
     * @param list<float> $v3MaxRois
     * @param list<float> $v3FinalRois
     * @return array<string,mixed>
     */
    private function computePatternSpecificBehavior(
        array $v2MaxRois,   array $v2FinalRois,   int $v2SlHits,   int $v2Runners,   int $v2Total,
        array $v3MaxRois,   array $v3FinalRois,   int $v3SlHits,   int $v3Runners,   int $v3Total
    ): array {
        $patternStats = function (
            array $maxRois, array $finalRois, int $slHits, int $runners, int $total
        ): array {
            if ($total === 0) {
                return [
                    'sample_count'      => 0,
                    'success_rate'      => null,
                    'runner_rate'       => null,
                    'avg_roi'           => null,
                    'stop_rate'         => null,
                    'corridor_p75_roi'  => null,
                    'data_confidence'   => 'none',
                ];
            }
            $successCount = 0;
            $roiSum       = 0.0;
            foreach ($finalRois as $roi) {
                if ($roi > 0) $successCount++;
                $roiSum += $roi;
            }
            sort($maxRois);
            $conf = $total >= 20 ? 'high' : ($total >= 5 ? 'medium' : ($total > 0 ? 'low' : 'none'));

            return [
                'sample_count'      => $total,
                'success_rate'      => $total > 0 ? round($successCount / $total, 4) : null,
                'runner_rate'       => $total > 0 ? round($runners / $total, 4) : null,
                'avg_roi'           => count($finalRois) > 0 ? round($roiSum / count($finalRois), 2) : null,
                'stop_rate'         => $total > 0 ? round($slHits / $total, 4) : null,
                'corridor_p75_roi'  => count($maxRois) > 0 ? round($this->percentile($maxRois, 75), 2) : null,
                'data_confidence'   => $conf,
            ];
        };

        $v2Stats = $patternStats($v2MaxRois, $v2FinalRois, $v2SlHits, $v2Runners, $v2Total);
        $v3Stats = $patternStats($v3MaxRois, $v3FinalRois, $v3SlHits, $v3Runners, $v3Total);

        return [
            // V2 pattern stats (double_top_contextual_v2 / double_bottom_contextual_v2)
            'v2_sample_count'       => $v2Stats['sample_count'],
            'v2_success_rate'       => $v2Stats['success_rate'],
            'short_v2_success_rate' => $v2Stats['success_rate'],  // alias
            'v2_runner_rate'        => $v2Stats['runner_rate'],
            'v2_avg_roi'            => $v2Stats['avg_roi'],
            'v2_stop_rate'          => $v2Stats['stop_rate'],
            'v2_corridor_p75_roi'   => $v2Stats['corridor_p75_roi'],
            'v2_data_confidence'    => $v2Stats['data_confidence'],
            // V3 pattern stats (double_top_contextual_v3 / double_bottom_contextual_v3)
            'v3_sample_count'       => $v3Stats['sample_count'],
            'v3_success_rate'       => $v3Stats['success_rate'],
            'short_v3_success_rate' => $v3Stats['success_rate'],  // alias
            'v3_runner_rate'        => $v3Stats['runner_rate'],
            'v3_avg_roi'            => $v3Stats['avg_roi'],
            'v3_stop_rate'          => $v3Stats['stop_rate'],
            'v3_corridor_p75_roi'   => $v3Stats['corridor_p75_roi'],
            'v3_data_confidence'    => $v3Stats['data_confidence'],
        ];
    }

    // =========================================================================
    // Regime behavior
    // =========================================================================

    /**
     * Compute simplified regime behavior scores derived from trade data.
     * Since no external regime labels are available, we infer regime proxies.
     *
     * @param list<float> $maxRois
     * @param list<float> $finalRois
     * @return array<string,float>
     */
    private function computeRegimeBehavior(
        array $maxRois,
        array $finalRois,
        int   $shorts,
        int   $longs,
        float $corridorP75,
        float $corridorP90,
        float $runnerProb,
        float $slHitRate
    ): array {
        $n = count($maxRois);

        // bear_regime_behavior_score: short-side performance proxy
        // Short trades = adversarial market for price = "bear" for underlying
        $totalSides = $shorts + $longs;
        $bearScore = $totalSides > 0
            ? min(1.0, ($shorts / $totalSides) * ($corridorP75 / 8.0 + $runnerProb * 2.0))
            : 0.3;

        // bull_regime_behavior_score: long-side performance proxy
        $bullScore = $totalSides > 0
            ? min(1.0, ($longs / $totalSides) * ($corridorP75 / 8.0 + $runnerProb * 2.0))
            : 0.3;

        // sideways_regime_behavior_score: performance when moves are modest (< 5%)
        $sidewaysTotal = 0; $sidewaysSuccess = 0;
        foreach ($maxRois as $i => $maxRoi) {
            if ($maxRoi < 5.0) {
                $sidewaysTotal++;
                if (isset($finalRois[$i]) && $finalRois[$i] > 0) {
                    $sidewaysSuccess++;
                }
            }
        }
        $sidewaysScore = $sidewaysTotal > 0 ? round($sidewaysSuccess / $sidewaysTotal, 4) : 0.3;

        // high_vol_regime_behavior_score: performance when moves are very wide (> 8%)
        $highVolTotal = 0; $highVolSuccess = 0;
        foreach ($maxRois as $i => $maxRoi) {
            if ($maxRoi >= 8.0) {
                $highVolTotal++;
                if (isset($finalRois[$i]) && $finalRois[$i] > 0) {
                    $highVolSuccess++;
                }
            }
        }
        $highVolScore = $highVolTotal > 0 ? round($highVolSuccess / $highVolTotal, 4) : 0.3;

        // fear_regime_behavior_score: performance under high SL pressure
        // Low SL rate = coin handles stress = high fear score
        $fearScore = round(1.0 - min(1.0, $slHitRate * 2.0), 4);

        // regime_sensitivity_score: how much performance varies across regimes
        $scores = array_filter([$bearScore, $bullScore, $sidewaysScore, $highVolScore]);
        if (count($scores) >= 2) {
            $spread = max($scores) - min($scores);
            $regimeSensitivity = min(1.0, $spread * 2.0);
        } else {
            $regimeSensitivity = 0.3;
        }

        return [
            'bull_regime_behavior_score'    => round(min(1.0, $bullScore), 4),
            'bear_regime_behavior_score'    => round(min(1.0, $bearScore), 4),
            'sideways_regime_behavior_score' => round($sidewaysScore, 4),
            'high_vol_regime_behavior_score' => round($highVolScore, 4),
            'fear_regime_behavior_score'    => round($fearScore, 4),
            'regime_sensitivity_score'      => round($regimeSensitivity, 4),
        ];
    }

    // =========================================================================
    // Evidence timeline
    // =========================================================================

    /**
     * Rebuild the evidence timeline for a symbol from its trade list.
     * Generates up to MAX_EVIDENCE_ITEMS events from the most recent trades.
     *
     * @param list<array<string,mixed>> $trades
     */
    private function rebuildEvidenceTimeline(string $symbol, array $trades): void
    {
        if (empty($trades)) {
            return;
        }

        // Sort trades by close/open time descending (most recent first)
        usort($trades, function ($a, $b) {
            $ta = (int)($a['closed_ts'] ?? $a['closed_at'] ?? $a['open_ts'] ?? $a['opened_at'] ?? 0);
            $tb = (int)($b['closed_ts'] ?? $b['closed_at'] ?? $b['open_ts'] ?? $b['opened_at'] ?? 0);
            return $tb <=> $ta;
        });

        $items = [];
        foreach ($trades as $trade) {
            if (count($items) >= self::MAX_EVIDENCE_ITEMS) {
                break;
            }

            $source  = (string)($trade['_source'] ?? 'live_closed');
            $finalRoi = $this->extractFinalRoi($trade);
            $maxRoi   = $this->extractPeakRoi($trade, $finalRoi);
            $ts       = (int)($trade['closed_ts'] ?? $trade['closed_at'] ?? $trade['open_ts'] ?? $trade['opened_at'] ?? 0);
            $patternAlgo = (string)($trade['pattern_algorithm'] ?? '');
            $side        = (string)($trade['side'] ?? '');
            $closeReason = strtolower((string)($trade['close_reason'] ?? $trade['exit_reason'] ?? ''));
            $isSlHit     = $this->detectStopLossHit($trade, $finalRoi, $maxRoi);

            $type = 'trade_closed';
            $notes = '';

            if ($source === 'shadow_active') {
                $type = 'shadow_active';
            } elseif ($source === 'shadow_closed') {
                $type = 'shadow_outcome';
            } elseif ($source === 'live_active') {
                $type = 'trade_active';
            } elseif ($isSlHit) {
                $type = 'stop_hit';
            } elseif ($maxRoi !== null && $maxRoi >= 15.0) {
                $type = 'runner_case';
                $notes = 'Reached ' . round((float)$maxRoi, 1) . '% peak ROI';
            } elseif ($maxRoi !== null && $maxRoi >= 10.0) {
                $type = 'reached_10_roi';
            } elseif ($maxRoi !== null && $maxRoi >= 5.0) {
                $type = 'reached_5_roi';
            } elseif ($maxRoi !== null && $maxRoi >= 2.0) {
                $type = 'reached_2_roi';
            } elseif ($maxRoi !== null && $maxRoi < 3.0 && $finalRoi !== null && $finalRoi <= 0.0) {
                $type = 'fakeout_case';
            }

            $items[] = [
                'ts'              => $ts ?: time(),
                'symbol'          => $symbol,
                'type'            => $type,
                'source'          => $source,
                'pattern_algorithm' => $patternAlgo,
                'side'            => $side,
                'final_roi'       => $finalRoi !== null ? round($finalRoi, 2) : null,
                'peak_roi'        => $maxRoi   !== null ? round($maxRoi, 2)   : null,
                'close_reason'    => $closeReason ?: null,
                'notes'           => $notes ?: null,
            ];
        }

        $data = [
            'symbol'     => $symbol,
            'updated_at' => date('Y-m-d H:i:s'),
            'items'      => $items,
        ];

        $path = $this->evidencePath($symbol);
        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

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
     * Data confidence label based on sample size (legacy helper, kept for pattern-level use).
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
     * Rich data confidence computation that accounts for source diversity, recency,
     * and pattern-level coverage.
     *
     * Returns [label, numeric_score (0.0–1.0), reason_summary].
     *
     * @return array{string, float, string}
     */
    private function computeConfidenceDetailed(
        int $total,
        int $liveClosed,
        int $shadowClosed,
        int $recent,
        int $v2Samples,
        int $v3Samples
    ): array {
        $score = 0.0;
        $reasons = [];

        // Component 1: raw sample volume (0–0.35)
        if ($total >= 50) {
            $score += 0.35;
            $reasons[] = "volume:50+({$total})";
        } elseif ($total >= 20) {
            $score += 0.25;
            $reasons[] = "volume:20+({$total})";
        } elseif ($total >= 10) {
            $score += 0.15;
            $reasons[] = "volume:10+({$total})";
        } elseif ($total >= 5) {
            $score += 0.08;
            $reasons[] = "volume:5+({$total})";
        } elseif ($total > 0) {
            $score += 0.03;
            $reasons[] = "volume:sparse({$total})";
        }

        // Component 2: live closed data quality (0–0.25)
        if ($liveClosed >= 20) {
            $score += 0.25;
            $reasons[] = "live_closed:rich({$liveClosed})";
        } elseif ($liveClosed >= 10) {
            $score += 0.18;
            $reasons[] = "live_closed:good({$liveClosed})";
        } elseif ($liveClosed >= 5) {
            $score += 0.10;
            $reasons[] = "live_closed:some({$liveClosed})";
        } elseif ($liveClosed >= 1) {
            $score += 0.04;
            $reasons[] = "live_closed:minimal({$liveClosed})";
        }

        // Component 3: shadow data supplement (0–0.15)
        if ($shadowClosed >= 15) {
            $score += 0.15;
            $reasons[] = "shadow:strong({$shadowClosed})";
        } elseif ($shadowClosed >= 5) {
            $score += 0.10;
            $reasons[] = "shadow:moderate({$shadowClosed})";
        } elseif ($shadowClosed >= 1) {
            $score += 0.04;
            $reasons[] = "shadow:some({$shadowClosed})";
        }

        // Component 4: recency (0–0.15)
        if ($recent >= 10) {
            $score += 0.15;
            $reasons[] = "recent:strong({$recent})";
        } elseif ($recent >= 5) {
            $score += 0.10;
            $reasons[] = "recent:ok({$recent})";
        } elseif ($recent >= 1) {
            $score += 0.04;
            $reasons[] = "recent:sparse({$recent})";
        } else {
            $reasons[] = "recent:none";
        }

        // Component 5: pattern-specific depth (0–0.10)
        $patternDepth = min($v2Samples, $v3Samples);
        if ($patternDepth >= 10) {
            $score += 0.10;
            $reasons[] = "pattern_depth:rich";
        } elseif ($patternDepth >= 5) {
            $score += 0.06;
            $reasons[] = "pattern_depth:ok";
        } elseif ($patternDepth >= 1) {
            $score += 0.02;
            $reasons[] = "pattern_depth:sparse";
        }

        $score = min(1.0, round($score, 4));

        // Map score to label
        $label = 'none';
        if ($score >= 0.65) {
            $label = 'high';
        } elseif ($score >= 0.35) {
            $label = 'medium';
        } elseif ($score > 0.0) {
            $label = 'low';
        }

        $summary = $label . ':' . implode(',', $reasons);

        return [$label, $score, $summary];
    }

    /**
     * Compute data sufficiency flags.
     * Uses numeric confidence score so medium/high applies even without live-only data.
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
        if ($confidence === 'none') {
            return [true, "no_data_confidence", 'shadow_only'];
        }
        if ($confidence === 'low') {
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
        float   $corridorP75,
        float   $runnerProb,
        float   $shortSuitability,
        float   $noiseScore,
        string  $dataConfidence,
        float   $regimeHealth,
        bool    $insufficientFlag,
        string  $fallbackMode,
        float   $impulseStrength = 0.5,
        float   $pullbackSeverity = 0.5,
        ?float  $patternSuccessRate = null
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

        // Noise gate
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

        // Impulse strength gate (weak impulse = coin doesn't move meaningfully)
        if ($impulseStrength < self::LIVE_GATE_IMPULSE_STRENGTH_MIN) {
            return ['sim_only', "impulse_strength_too_low:{$impulseStrength}<" . self::LIVE_GATE_IMPULSE_STRENGTH_MIN];
        }

        // Pullback severity gate (extreme retracing = dangerous for live)
        if ($pullbackSeverity > self::LIVE_GATE_PULLBACK_SEVERITY_MAX) {
            return ['sim_only', "pullback_severity_too_high:{$pullbackSeverity}>" . self::LIVE_GATE_PULLBACK_SEVERITY_MAX];
        }

        // Pattern-specific success rate gate (if we have pattern data)
        if ($patternSuccessRate !== null && $patternSuccessRate < 0.35) {
            return ['sim_only', "pattern_success_rate_too_low:{$patternSuccessRate}<0.35"];
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

    private function recommendMaxHoldMinutes(?float $avgHoldMinutes, float $runnerProb, float $corridorP75): ?float
    {
        if ($avgHoldMinutes === null || $avgHoldMinutes <= 0) {
            return null;
        }
        // Runner coins: allow longer holds; weak corridor: tighter hold limit
        $multiplier = 1.5;
        if ($runnerProb >= 0.15 || $corridorP75 >= 8.0) {
            $multiplier = 2.5;
        } elseif ($runnerProb >= 0.05 || $corridorP75 >= 4.0) {
            $multiplier = 2.0;
        }
        return round($avgHoldMinutes * $multiplier, 0);
    }

    private function recommendRunnerExpectation(float $runnerProb, float $reach10Rate, float $corridorP90): string
    {
        if ($runnerProb >= 0.2 || $reach10Rate >= 0.2 || $corridorP90 >= 15.0) {
            return 'high_runner';
        }
        if ($runnerProb >= 0.07 || $reach10Rate >= 0.07 || $corridorP90 >= 8.0) {
            return 'occasional_runner';
        }
        return 'scalp_coin';
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

    private function evidencePath(string $symbol): string
    {
        $safe = preg_replace('/[^A-Z0-9_\-]/', '', strtoupper($symbol));
        return $this->evidenceDir . '/' . $safe . '.json';
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
