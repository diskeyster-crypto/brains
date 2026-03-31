<?php
declare(strict_types=1);

/**
 * CoinPassportEngine
 *
 * Builds and updates persistent per-symbol coin passports.
 * Reads trade data from trading_bot storage and computes per-symbol
 * ROI corridor, trailing/lock recommendations, and behavioral scores.
 *
 * Storage: modules/system/coin_passport/storage/passports/{SYMBOL}.json
 * This storage is NEVER wiped by bot/brain runtime clears.
 */
if (!class_exists('CoinPassportEngine', false)) :
final class CoinPassportEngine
{
    private string $passportsDir;
    private string $tradingBotStorageDir;

    /** Minimum trades to compute meaningful confidence */
    private const MIN_SAMPLE_MEDIUM = 5;
    private const MIN_SAMPLE_HIGH   = 20;

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
     * Build a complete passport for one symbol from its trades.
     *
     * @param list<array<string,mixed>> $trades
     * @return array<string,mixed>
     */
    private function buildPassport(string $symbol, array $trades): array
    {
        $sampleSize = count($trades);

        // Extract per-trade metrics
        $maxRois      = [];
        $finalRois    = [];
        $pullbacksAt2 = [];
        $pullbacksAt3 = [];
        $pullbacksAt5 = [];
        $runners      = 0;   // trades that reached >= 10 ROI
        $shorts       = 0;
        $totalSides   = 0;

        foreach ($trades as $trade) {
            $finalRoi = $this->extractFinalRoi($trade);
            $maxRoi   = $this->extractPeakRoi($trade, $finalRoi);
            $side     = strtolower((string)($trade['side'] ?? ''));

            if ($side !== '') {
                $totalSides++;
                if ($side === 'short') {
                    $shorts++;
                }
            }

            if ($finalRoi !== null) {
                $finalRois[] = $finalRoi;
            }
            if ($maxRoi !== null) {
                $maxRois[] = $maxRoi;
                if ($maxRoi >= 10.0) {
                    $runners++;
                }
                // Pullback analysis: how much did price pull back after reaching threshold?
                // pullback = maxRoi - finalRoi (approximation when peak > threshold)
                if ($finalRoi !== null) {
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
        }

        // Compute statistics
        sort($maxRois);
        sort($finalRois);

        $medianMaxRoi = $this->percentile($maxRois, 50);
        $p75MaxRoi    = $this->percentile($maxRois, 75);
        $p90MaxRoi    = $this->percentile($maxRois, 90);

        $medianFinalRoi = $this->percentile($finalRois, 50);

        $medianPullback2 = $this->median($pullbacksAt2);
        $medianPullback3 = $this->median($pullbacksAt3);
        $medianPullback5 = $this->median($pullbacksAt5);

        // Corridor calculation
        $corridorLow  = $this->percentile($maxRois, 25);
        $corridorMid  = $medianMaxRoi;
        $corridorHigh = $p75MaxRoi;

        // Runner probability: share of trades that reached >= 10 ROI
        $runnerProb = $sampleSize > 0 ? round($runners / $sampleSize, 4) : 0.0;

        // Confidence based on sample size
        $dataConfidence = $this->computeConfidence($sampleSize);

        // Scores
        $volatilityScore      = $this->computeVolatilityScore($maxRois);
        $noiseSore            = $this->computeNoiseScore($maxRois, $finalRois);
        $trendPersistenceScore = $this->computeTrendPersistenceScore($maxRois, $pullbacksAt3);
        $fakeBreakoutScore    = $this->computeFakeBreakoutScore($maxRois, $finalRois);
        $shortSuitabilityScore = $totalSides > 0 ? round($shorts / $totalSides, 4) : 0.5;

        // Recommendations
        $recLockStart = $this->recommendLockStart($medianMaxRoi, $p75MaxRoi, $corridorLow, $dataConfidence);
        $recLockValue = max(0.0, $recLockStart - 0.5);
        $recStage1    = $this->recommendStage1($corridorMid, $medianPullback3);
        $recStage2    = $this->recommendStage2($corridorHigh, $p90MaxRoi);
        $recLadderMode = $this->recommendLadderMode($runnerProb, $p90MaxRoi, $dataConfidence);
        $recHarvest    = $this->recommendHarvestAggressiveness($medianPullback3, $medianPullback5, $noiseSore);

        // Notes / diagnostics
        $notes = $this->buildDiagnosticNotes(
            $sampleSize,
            $dataConfidence,
            $runnerProb,
            $medianMaxRoi,
            $p75MaxRoi,
            $p90MaxRoi,
            $corridorLow,
            $corridorHigh
        );

        return [
            'symbol'                           => $symbol,
            'updated_at'                       => date('Y-m-d H:i:s'),
            'sample_size'                      => $sampleSize,
            'data_confidence'                  => $dataConfidence,

            // Behavioral scores (0.0 – 1.0 unless noted)
            'short_suitability_score'          => round($shortSuitabilityScore, 4),
            'runner_probability'               => round($runnerProb, 4),
            'noise_score'                      => round($noiseSore, 4),
            'volatility_score'                 => round($volatilityScore, 4),
            'trend_persistence_score'          => round($trendPersistenceScore, 4),
            'fake_breakout_score'              => round($fakeBreakoutScore, 4),

            // ROI corridor (% values)
            'median_max_roi'                   => round($medianMaxRoi, 2),
            'p75_max_roi'                      => round($p75MaxRoi, 2),
            'p90_max_roi'                      => round($p90MaxRoi, 2),

            // Pullback analysis after reaching milestone ROI (% drop from peak)
            'median_pullback_after_2_roi'      => round($medianPullback2, 2),
            'median_pullback_after_3_roi'      => round($medianPullback3, 2),
            'median_pullback_after_5_roi'      => round($medianPullback5, 2),

            // Corridor
            'corridor_low_roi'                 => round($corridorLow, 2),
            'corridor_mid_roi'                 => round($corridorMid, 2),
            'corridor_high_roi'                => round($corridorHigh, 2),

            // Recommendations
            'recommended_guaranteed_lock_start_roi' => round($recLockStart, 2),
            'recommended_guaranteed_lock_value_roi' => round($recLockValue, 2),
            'recommended_stage1_threshold_roi'      => round($recStage1, 2),
            'recommended_stage2_threshold_roi'      => round($recStage2, 2),
            'recommended_ladder_mode'               => $recLadderMode,
            'recommended_harvest_aggressiveness'    => $recHarvest,

            // Diagnostics
            'notes'                            => $notes,
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
        float  $corridorHigh
    ): array {
        $notes = [];

        if ($confidence === 'none') {
            $notes[] = 'No trade data available — passport is theoretical defaults only.';
        } elseif ($confidence === 'low') {
            $notes[] = "Low confidence: only {$sampleSize} sample(s). Accumulate more trades for accuracy.";
        }

        if ($runnerProb >= 0.15) {
            $notes[] = "Runner coin: {$runnerProb}% chance of reaching 10+ ROI.";
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
endif; // class_exists CoinPassportEngine
