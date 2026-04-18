<?php
declare(strict_types=1);

/**
 * Canonical Context Adapter for Contextual Bottom Patterns
 *
 * Computes a normalized multi-factor market context object from price history.
 * Replaces the primitive first-vs-last price trend proxy with structural analysis.
 *
 * Output contract consumed by:
 *   - double_bottom_contextual_v2 (via trend_* and shared fields)
 *   - double_bottom_contextual_v3 (via regime_* canonical fields)
 *
 * All contextual patterns receive the SAME normalized contract.
 */
final class ContextAdapter
{
    /** @var string */
    public const VERSION = '1.0.0';

    /** Minimum number of price points required for context computation. */
    private const MIN_POINTS = 10;

    /** Window size for local structure analysis. */
    private const STRUCTURE_WINDOW = 50;

    // ── Adapter-level counters (reset per analyzer run) ──

    private int $adapterCalls = 0;
    private int $adapterValidCount = 0;
    private int $adapterInvalidCount = 0;
    private int $adapterBearishCount = 0;
    private int $adapterMatureBearishCount = 0;
    private int $adapterBullishCount = 0;

    /**
     * Compute canonical normalized context from price history.
     *
     * @param float[] $prices  Array of price values (chronological, oldest first)
     * @param float   $volatilityInput  Pre-computed volatility metric from analyzer
     * @return array<string,mixed>  Canonical context contract
     */
    public function computeContext(array $prices, float $volatilityInput): array
    {
        $this->adapterCalls++;
        $n = count($prices);

        if ($n < self::MIN_POINTS) {
            $this->adapterInvalidCount++;
            return $this->buildEmptyContext('insufficient_data');
        }

        // ── Multi-Factor Regime Classification ──

        $regimeDirection = $this->classifyRegimeDirection($prices);
        $regimeStrength  = $this->computeRegimeStrength($prices);
        $regimeDepthPct  = $this->computeRegimeDepth($prices);
        $regimeDuration  = $this->computeRegimeDuration($prices);

        $trendMaturity   = $this->computeTrendMaturityScore($prices, $regimeDepthPct, $regimeDuration);
        $noiseScore      = $this->computeNoiseScore($prices);
        $exhaustionScore = $this->computeExhaustionScore($prices);
        $volatilityScore = $this->computeVolatilityScore($prices, $volatilityInput);
        $stretchScore    = $this->computeStretchScore($prices);
        $localStructure  = $this->computeLocalStructureScore($prices);

        // ── Uptrend-side metrics (for short-side contextual detectors) ──
        $uptrendDuration = $this->computeUptrendDuration($prices);

        $contextQuality  = $this->computeContextQualityScore(
            $regimeStrength, $noiseScore, $trendMaturity, $regimeDepthPct, $exhaustionScore
        );

        $isBearish = in_array($regimeDirection, ['down', 'weak_down'], true);
        $isMatureBearish = $isBearish && $trendMaturity >= 0.3 && $exhaustionScore >= 0.15;
        $isBullish = in_array($regimeDirection, ['up', 'weak_up'], true);

        if ($isBearish) {
            $this->adapterBearishCount++;
        }
        if ($isMatureBearish) {
            $this->adapterMatureBearishCount++;
        }
        if ($isBullish) {
            $this->adapterBullishCount++;
        }
        $this->adapterValidCount++;

        $diagnostics = [
            'direction_subscore'  => round($regimeStrength, 4),
            'depth_subscore'      => round($regimeDepthPct, 4),
            'duration_subscore'   => round(min(1.0, (float) $regimeDuration / 60.0), 4),
            'noise_penalty'       => round($noiseScore, 4),
            'exhaustion_subscore' => round($exhaustionScore, 4),
            'stretch_subscore'    => round($stretchScore, 4),
            'structure_subscore'  => round($localStructure, 4),
            'uptrend_duration_bars' => $uptrendDuration,
        ];

        return [
            // Canonical V3 fields (regime_*)
            'regime_direction'       => $regimeDirection,
            'regime_strength'        => round($regimeStrength, 4),
            'regime_depth_pct'       => round($regimeDepthPct, 4),
            'regime_duration_bars'   => $regimeDuration,
            'trend_maturity_score'   => round($trendMaturity, 4),
            'noise_score'            => round($noiseScore, 4),
            'exhaustion_score'       => round($exhaustionScore, 4),
            'volatility_score'       => round($volatilityScore, 4),
            'stretch_score'          => round($stretchScore, 4),
            'local_structure_score'  => round($localStructure, 4),
            'context_quality_score'  => round($contextQuality, 4),

            // Uptrend-side metrics (for short-side contextual detectors needing bullish context)
            'uptrend_duration_bars'  => $uptrendDuration,

            // V2-compatible aliases
            'trend_direction'        => $regimeDirection === 'weak_down' ? 'down' : $regimeDirection,
            'trend_strength'         => round($regimeStrength, 4),
            'trend_duration_bars'    => $regimeDuration,
            'volatility'             => $volatilityInput,

            // Metadata
            'parser2_context_available' => true,
            'parser2_context_ts'     => time(),
            'source_version'         => self::VERSION,
            'context_available'      => true,

            // Diagnostics
            'diagnostics'            => $diagnostics,
        ];
    }

    /**
     * Classify regime direction using multi-factor analysis.
     *
     * Uses segment slopes, lower-high/lower-low structure, and bearish persistence
     * instead of just first-vs-last price comparison.
     *
     * @param float[] $prices
     * @return string 'down'|'weak_down'|'flat'|'up'|'weak_up'
     */
    private function classifyRegimeDirection(array $prices): string
    {
        $n = count($prices);
        $window = min($n, self::STRUCTURE_WINDOW);
        $slice = array_slice($prices, $n - $window);
        $len = count($slice);

        // Factor 1: Overall slope (normalized)
        $overallReturn = ($slice[0] > 0) ? ($slice[$len - 1] - $slice[0]) / $slice[0] : 0.0;

        // Factor 2: Multi-segment slope analysis (split into 3 segments)
        $segCount = 3;
        $segSize = max(2, (int) ($len / $segCount));
        $bearishSegments = 0;
        $totalSegSlope = 0.0;
        for ($s = 0; $s < $segCount; $s++) {
            $start = $s * $segSize;
            $end = min($start + $segSize - 1, $len - 1);
            if ($end <= $start) {
                continue;
            }
            $segSlope = $slice[$end] - $slice[$start];
            $totalSegSlope += $segSlope;
            if ($segSlope < 0) {
                $bearishSegments++;
            }
        }
        $bearishPersistence = $bearishSegments / $segCount;

        // Factor 3: Lower-high / lower-low structure
        $structureScore = $this->assessBearishStructure($slice);

        // Factor 4: Depth of move
        $high = max($slice);
        $low = min($slice);
        $depthPct = $high > 0 ? ($high - $low) / $high : 0.0;

        // Combine factors for direction classification
        $bearishSignal = 0.0;

        // Overall return contributes
        if ($overallReturn < -0.005) {
            $bearishSignal += 0.30;
        } elseif ($overallReturn < 0.0) {
            $bearishSignal += 0.15;
        }

        // Bearish persistence (how many segments are bearish)
        $bearishSignal += $bearishPersistence * 0.30;

        // Structure (lower highs / lower lows)
        $bearishSignal += $structureScore * 0.25;

        // Depth contribution: only counted as a bearish signal when the net move is also downward.
        // A large range in an uptrend is NOT a bearish signal — excluding this avoids misclassifying
        // uptrend markets as 'flat' due to a wide high-low range.
        if ($overallReturn < 0.0 && $depthPct > 0.02) {
            $bearishSignal += 0.15;
        } elseif ($overallReturn < 0.0 && $depthPct > 0.01) {
            $bearishSignal += 0.08;
        }

        // Classification
        if ($bearishSignal >= 0.60) {
            return 'down';
        }
        if ($bearishSignal >= 0.40) {
            return 'weak_down';
        }
        if ($bearishSignal <= 0.15 && $overallReturn > 0.005) {
            return 'up';
        }
        if ($overallReturn > 0.0 && $bearishSignal <= 0.25) {
            return 'weak_up';
        }

        return 'flat';
    }

    /**
     * Assess bearish structure quality (lower-highs, lower-lows).
     *
     * @param float[] $prices
     * @return float 0.0 (no bearish structure) to 1.0 (strong bearish structure)
     */
    private function assessBearishStructure(array $prices): float
    {
        $n = count($prices);
        if ($n < 6) {
            return 0.0;
        }

        // Find local maxima and minima in segments
        $segments = 4;
        $segSize = max(2, (int) ($n / $segments));
        $segHighs = [];
        $segLows = [];

        for ($s = 0; $s < $segments; $s++) {
            $start = $s * $segSize;
            $end = min($start + $segSize, $n);
            $seg = array_slice($prices, $start, $end - $start);
            if ($seg === []) {
                continue;
            }
            $segHighs[] = max($seg);
            $segLows[] = min($seg);
        }

        $lowerHighs = 0;
        $lowerLows = 0;
        $totalPairs = 0;

        for ($i = 1; $i < count($segHighs); $i++) {
            $totalPairs++;
            if ($segHighs[$i] < $segHighs[$i - 1]) {
                $lowerHighs++;
            }
            if (isset($segLows[$i]) && $segLows[$i] < $segLows[$i - 1]) {
                $lowerLows++;
            }
        }

        if ($totalPairs === 0) {
            return 0.0;
        }

        $lhRatio = $lowerHighs / $totalPairs;
        $llRatio = $lowerLows / $totalPairs;

        // Bearish structure: lower highs are most important for trend identification
        return ($lhRatio * 0.6) + ($llRatio * 0.4);
    }

    /**
     * Compute regime strength: how strong the directional bias is.
     *
     * Combines normalized slope, directional efficiency, and bearish fraction.
     *
     * @param float[] $prices
     * @return float 0.0 to 1.0
     */
    private function computeRegimeStrength(array $prices): float
    {
        $n = count($prices);
        $window = min($n, self::STRUCTURE_WINDOW);
        $slice = array_slice($prices, $n - $window);
        $len = count($slice);

        if ($len < 3) {
            return 0.0;
        }

        // Component 1: Net directional efficiency
        $netMove = abs($slice[$len - 1] - $slice[0]);
        $totalPath = 0.0;
        for ($i = 1; $i < $len; $i++) {
            $totalPath += abs($slice[$i] - $slice[$i - 1]);
        }
        $efficiency = ($totalPath > 0) ? $netMove / $totalPath : 0.0;

        // Component 2: Bearish fraction (how many bars are down)
        $downBars = 0;
        for ($i = 1; $i < $len; $i++) {
            if ($slice[$i] < $slice[$i - 1]) {
                $downBars++;
            }
        }
        $bearishFraction = $downBars / max(1, $len - 1);

        // Component 3: Normalized slope magnitude
        $avgPrice = ($slice[0] + $slice[$len - 1]) / 2.0;
        $normSlope = ($avgPrice > 0) ? abs($slice[$len - 1] - $slice[0]) / $avgPrice : 0.0;
        $slopeMagnitude = min(1.0, $normSlope / 0.10); // normalize: 10% move = 1.0

        // Weighted combination
        return min(1.0,
            ($efficiency * 0.35)
            + ($bearishFraction * 0.30)
            + ($slopeMagnitude * 0.35)
        );
    }

    /**
     * Compute actual trend depth as fraction of high price.
     *
     * Uses the high-to-current-price drop, not just the high-low range,
     * to better reflect the actual regime depth experienced.
     *
     * @param float[] $prices
     * @return float 0.0 to 1.0
     */
    private function computeRegimeDepth(array $prices): float
    {
        $n = count($prices);
        $window = min($n, self::STRUCTURE_WINDOW);
        $slice = array_slice($prices, $n - $window);
        $len = count($slice);

        if ($len < 2) {
            return 0.0;
        }

        // Find the high before the current price (not just the absolute high)
        $highPrice = max($slice);
        $currentPrice = $slice[$len - 1];

        if ($highPrice <= 0.0) {
            return 0.0;
        }

        // Depth = drop from high to current price
        $dropFromHigh = max(0.0, $highPrice - $currentPrice);
        return $dropFromHigh / $highPrice;
    }

    /**
     * Compute effective regime duration (bars of consistent bearish direction).
     *
     * Does not just return the window length. Instead, counts how many of
     * the recent bars are part of a consistent bearish structure.
     *
     * @param float[] $prices
     * @return int Number of bars in the current bearish regime
     */
    private function computeRegimeDuration(array $prices): int
    {
        $n = count($prices);
        $window = min($n, self::STRUCTURE_WINDOW);
        $slice = array_slice($prices, $n - $window);
        $len = count($slice);

        if ($len < 3) {
            return $len;
        }

        // Walk backward from the end to find where the bearish regime started
        // A regime break is defined as: price exceeds a recent rolling high
        $rollingHigh = $slice[$len - 1];
        $regimeBars = 0;

        for ($i = $len - 1; $i >= 0; $i--) {
            // If price goes above the rolling high by more than 1%, regime breaks
            if ($i < $len - 1 && $slice[$i] > $rollingHigh * 1.01) {
                break;
            }
            $rollingHigh = max($rollingHigh, $slice[$i]);
            $regimeBars++;
        }

        return $regimeBars;
    }

    /**
     * Compute effective uptrend duration (bars of consistent bullish direction).
     *
     * Finds the recent price peak and walks backward from it, counting how many
     * bars were part of the upward move that created the peak.
     * Used by short-side contextual detectors to validate prior uptrend length.
     *
     * @param float[] $prices
     * @return int Number of bars in the recent uptrend
     */
    private function computeUptrendDuration(array $prices): int
    {
        $n = count($prices);
        $window = min($n, self::STRUCTURE_WINDOW);
        $slice = array_slice($prices, $n - $window);
        $len = count($slice);

        if ($len < 3) {
            return $len;
        }

        // Find the index of the recent high (peak) in the window
        $peakIdx = 0;
        $peakPrice = $slice[0];
        for ($i = 1; $i < $len; $i++) {
            if ($slice[$i] > $peakPrice) {
                $peakPrice = $slice[$i];
                $peakIdx = $i;
            }
        }

        if ($peakIdx === 0) {
            return 1;
        }

        // Walk backward from the peak, counting bars in the upward regime.
        // A regime break is: price drops more than 1% below the rolling low from peak.
        $rollingLow = $slice[$peakIdx];
        $regimeBars = 0;

        for ($i = $peakIdx; $i >= 0; $i--) {
            // If price went far below rolling low, regime started there
            if ($i < $peakIdx && $slice[$i] < $rollingLow * 0.99) {
                break;
            }
            $rollingLow = min($rollingLow, $slice[$i]);
            $regimeBars++;
        }

        return $regimeBars;
    }

    /**
     *
     * A mature trend has lasted long enough and moved deep enough to
     * plausibly reverse.
     *
     * @param float[] $prices
     * @param float $depthPct
     * @param int $duration
     * @return float 0.0 to 1.0
     */
    private function computeTrendMaturityScore(array $prices, float $depthPct, int $duration): float
    {
        // Duration component: normalized to 60 bars as reference maturity
        $durationScore = min(1.0, (float) $duration / 60.0);

        // Depth component: deeper moves are more mature
        $depthScore = min(1.0, $depthPct / 0.10); // 10% drop = fully mature

        // Impulse count: count significant down swings
        $impulseCount = $this->countBearishImpulses($prices);
        $impulseScore = min(1.0, $impulseCount / 3.0); // 3 impulses = fully mature

        // Directional efficiency decline: mature trends lose efficiency
        $efficiencyDecline = $this->measureEfficiencyDecline($prices);

        // Composite maturity
        return min(1.0,
            ($durationScore * 0.25)
            + ($depthScore * 0.30)
            + ($impulseScore * 0.20)
            + ($efficiencyDecline * 0.25)
        );
    }

    /**
     * Count distinct bearish impulses (down swings followed by bounces).
     *
     * @param float[] $prices
     * @return int
     */
    private function countBearishImpulses(array $prices): int
    {
        $n = count($prices);
        $window = min($n, self::STRUCTURE_WINDOW);
        $slice = array_slice($prices, $n - $window);
        $len = count($slice);

        if ($len < 6) {
            return 0;
        }

        $impulses = 0;
        $inDownSwing = false;
        $swingHigh = $slice[0];
        $swingLow = $slice[0];
        $minImpulseSize = 0.005; // 0.5% minimum impulse size

        for ($i = 1; $i < $len; $i++) {
            $price = $slice[$i];

            if ($price < $swingLow) {
                $swingLow = $price;
                $inDownSwing = true;
            }

            if ($inDownSwing && $price > $swingLow) {
                // Check if the down swing was significant
                $swingSize = ($swingHigh > 0) ? ($swingHigh - $swingLow) / $swingHigh : 0.0;
                if ($swingSize >= $minImpulseSize) {
                    $impulses++;
                }
                $inDownSwing = false;
                $swingHigh = $price;
                $swingLow = $price;
            }

            if ($price > $swingHigh) {
                $swingHigh = $price;
            }
        }

        return $impulses;
    }

    /**
     * Measure decline in directional efficiency (mature trends lose momentum).
     *
     * Compares first-half efficiency to second-half efficiency.
     *
     * @param float[] $prices
     * @return float 0.0 (no decline) to 1.0 (strong efficiency decline)
     */
    private function measureEfficiencyDecline(array $prices): float
    {
        $n = count($prices);
        $window = min($n, self::STRUCTURE_WINDOW);
        $slice = array_slice($prices, $n - $window);
        $len = count($slice);
        $half = (int) ($len / 2);

        if ($half < 3) {
            return 0.0;
        }

        $firstHalf = array_slice($slice, 0, $half);
        $secondHalf = array_slice($slice, $half);

        $eff1 = $this->pathEfficiency($firstHalf);
        $eff2 = $this->pathEfficiency($secondHalf);

        // If first half was more efficient than second, efficiency declined
        if ($eff1 <= 0.0) {
            return 0.0;
        }

        $decline = max(0.0, ($eff1 - $eff2) / $eff1);
        return min(1.0, $decline);
    }

    /**
     * Compute path efficiency (net move / total path).
     *
     * @param float[] $prices
     * @return float 0.0 to 1.0
     */
    private function pathEfficiency(array $prices): float
    {
        $n = count($prices);
        if ($n < 2) {
            return 0.0;
        }

        $netMove = abs($prices[$n - 1] - $prices[0]);
        $totalPath = 0.0;
        for ($i = 1; $i < $n; $i++) {
            $totalPath += abs($prices[$i] - $prices[$i - 1]);
        }

        return ($totalPath > 0) ? $netMove / $totalPath : 0.0;
    }

    /**
     * Compute noise score from price path efficiency.
     *
     * High noise = many reversals relative to net movement.
     * Uses multi-window analysis for stability.
     *
     * @param float[] $prices
     * @return float 0.0 (clean trend) to 1.0 (pure noise)
     */
    private function computeNoiseScore(array $prices): float
    {
        $n = count($prices);
        if ($n < 3) {
            return 0.0;
        }

        $window = min($n, self::STRUCTURE_WINDOW);
        $slice = array_slice($prices, $n - $window);
        $len = count($slice);

        // Component 1: Path efficiency (inverted = noise)
        $efficiency = $this->pathEfficiency($slice);
        $pathNoise = 1.0 - $efficiency;

        // Component 2: Bar-to-bar reversal frequency
        $reversals = 0;
        for ($i = 2; $i < $len; $i++) {
            $prev = $slice[$i - 1] - $slice[$i - 2];
            $curr = $slice[$i] - $slice[$i - 1];
            if (($prev > 0 && $curr < 0) || ($prev < 0 && $curr > 0)) {
                $reversals++;
            }
        }
        $reversalRate = ($len > 2) ? $reversals / ($len - 2) : 0.0;

        // Combine: path noise 60%, reversal rate 40%
        return max(0.0, min(1.0, ($pathNoise * 0.60) + ($reversalRate * 0.40)));
    }

    /**
     * Compute exhaustion score: detect seller weakening / slope deceleration.
     *
     * Uses multi-segment analysis (3 segments) for more robust detection
     * than simple first-half vs second-half comparison.
     *
     * @param float[] $prices
     * @return float 0.0 (no exhaustion) to 1.0 (strong exhaustion)
     */
    private function computeExhaustionScore(array $prices): float
    {
        $n = count($prices);
        if ($n < 9) {
            return 0.0;
        }

        $window = min($n, self::STRUCTURE_WINDOW);
        $slice = array_slice($prices, $n - $window);
        $len = count($slice);

        // Split into 3 segments for progressive slope analysis
        $segSize = (int) ($len / 3);
        if ($segSize < 3) {
            return 0.0;
        }

        $seg1 = array_slice($slice, 0, $segSize);
        $seg2 = array_slice($slice, $segSize, $segSize);
        $seg3 = array_slice($slice, 2 * $segSize);

        $refPrice = $seg1[0] > 0.0 ? $seg1[0] : 1.0;

        $slope1 = ($seg1[count($seg1) - 1] - $seg1[0]) / ($refPrice * count($seg1));
        $slope2 = ($seg2[count($seg2) - 1] - $seg2[0]) / ($refPrice * count($seg2));
        $slope3 = ($seg3[count($seg3) - 1] - $seg3[0]) / ($refPrice * count($seg3));

        // If first segment is declining: measure downtrend exhaustion (seller weakening)
        if ($slope1 < 0.0) {
            // Progressive deceleration: slope should become less negative
            $decel12 = $slope2 - $slope1; // positive if second less steep
            $decel23 = $slope3 - $slope2; // positive if third less steep

            // Rebound quality: is the final segment actually recovering?
            $reboundBonus = ($slope3 > 0.0) ? 0.15 : 0.0;

            // Combine deceleration signals
            $exhaustion = 0.0;
            if ($decel12 > 0) {
                $exhaustion += min(0.5, $decel12 * 50.0);
            }
            if ($decel23 > 0) {
                $exhaustion += min(0.5, $decel23 * 50.0);
            }
            $exhaustion += $reboundBonus;

            return max(0.0, min(1.0, $exhaustion));
        }

        // If first segment is rising: measure uptrend exhaustion (buyer weakening / momentum loss)
        // A weakening uptrend has: slope2 < slope1 (second segment rises more slowly)
        if ($slope1 > 0.0) {
            $decel12 = $slope1 - $slope2; // positive if second segment gained less
            $decel23 = $slope2 - $slope3; // positive if third segment gained less

            // Reversal bonus: if final segment is declining, uptrend exhaustion is stronger
            $reversalBonus = ($slope3 < 0.0) ? 0.20 : 0.0;

            $exhaustion = 0.0;
            if ($decel12 > 0) {
                $exhaustion += min(0.5, $decel12 * 50.0);
            }
            if ($decel23 > 0) {
                $exhaustion += min(0.5, $decel23 * 50.0);
            }
            $exhaustion += $reversalBonus;

            return max(0.0, min(1.0, $exhaustion));
        }

        return 0.0;
    }

    /**
     * Compute normalized volatility score.
     *
     * @param float[] $prices
     * @param float $volatilityInput Pre-computed volatility metric
     * @return float 0.0 (low vol) to 1.0 (extreme vol)
     */
    private function computeVolatilityScore(array $prices, float $volatilityInput): float
    {
        $n = count($prices);
        if ($n < 5) {
            return 0.0;
        }

        // Use recent bar-to-bar returns standard deviation
        $window = min($n, 30);
        $slice = array_slice($prices, $n - $window);
        $returns = [];
        for ($i = 1; $i < count($slice); $i++) {
            if ($slice[$i - 1] > 0) {
                $returns[] = ($slice[$i] - $slice[$i - 1]) / $slice[$i - 1];
            }
        }

        if (count($returns) < 3) {
            return 0.0;
        }

        $mean = array_sum($returns) / count($returns);
        $variance = 0.0;
        foreach ($returns as $r) {
            $variance += ($r - $mean) * ($r - $mean);
        }
        $stddev = sqrt($variance / (count($returns) - 1));

        // Normalize: 5% bar-to-bar stddev = 1.0 (extreme)
        return min(1.0, $stddev / 0.05);
    }

    /**
     * Compute stretch score: how oversold/extended the downward move is.
     *
     * @param float[] $prices
     * @return float 0.0 (not stretched) to 1.0 (highly oversold/stretched)
     */
    private function computeStretchScore(array $prices): float
    {
        $n = count($prices);
        if ($n < 10) {
            return 0.0;
        }

        $window = min($n, self::STRUCTURE_WINDOW);
        $slice = array_slice($prices, $n - $window);
        $len = count($slice);

        // Compute recent baseline range (typical move size)
        $barMoves = [];
        for ($i = 1; $i < $len; $i++) {
            $barMoves[] = abs($slice[$i] - $slice[$i - 1]);
        }
        sort($barMoves);
        $medianMove = $barMoves[(int) (count($barMoves) / 2)] ?? 0.0;

        if ($medianMove <= 0.0) {
            return 0.0;
        }

        // Net downward extension relative to typical bar size
        $netDown = $slice[0] - $slice[$len - 1]; // positive if price went down
        if ($netDown <= 0.0) {
            return 0.0; // Not oversold if net move is up
        }

        $extensionRatio = $netDown / ($medianMove * $len);

        // Also factor in how far current price is below the recent median price
        $medianPrice = $slice[(int) ($len / 2)];
        $belowMedian = ($medianPrice > 0) ? max(0.0, $medianPrice - $slice[$len - 1]) / $medianPrice : 0.0;

        return min(1.0, ($extensionRatio * 0.50) + ($belowMedian * 5.0 * 0.50));
    }

    /**
     * Compute local structure score: whether the window structurally
     * behaves like a downtrend rather than random chop.
     *
     * @param float[] $prices
     * @return float 0.0 (random chop) to 1.0 (clear downtrend structure)
     */
    private function computeLocalStructureScore(array $prices): float
    {
        $n = count($prices);
        $window = min($n, self::STRUCTURE_WINDOW);
        $slice = array_slice($prices, $n - $window);

        // Component 1: Bearish structure (lower highs / lower lows)
        $bearishStructure = $this->assessBearishStructure($slice);

        // Component 2: Directional efficiency
        $efficiency = $this->pathEfficiency($slice);

        // Component 3: Price below moving average for most of the window
        $len = count($slice);
        $avgPrice = array_sum($slice) / $len;
        $belowAvg = 0;
        for ($i = (int)($len / 2); $i < $len; $i++) {
            if ($slice[$i] < $avgPrice) {
                $belowAvg++;
            }
        }
        $belowAvgRatio = $belowAvg / max(1, $len - (int)($len / 2));

        return min(1.0,
            ($bearishStructure * 0.40)
            + ($efficiency * 0.30)
            + ($belowAvgRatio * 0.30)
        );
    }

    /**
     * Compute composite context quality score.
     *
     * @return float 0.0 to 1.0
     */
    private function computeContextQualityScore(
        float $strength,
        float $noise,
        float $maturity,
        float $depth,
        float $exhaustion
    ): float {
        $noiseQuality = max(0.0, 1.0 - $noise);
        $depthNorm = min(1.0, $depth / 0.05); // 5% depth = max score

        return min(1.0,
            ($strength * 0.20)
            + ($noiseQuality * 0.20)
            + ($maturity * 0.20)
            + ($depthNorm * 0.20)
            + ($exhaustion * 0.20)
        );
    }

    /**
     * Build an empty context for insufficient data.
     *
     * @return array<string,mixed>
     */
    private function buildEmptyContext(string $reason): array
    {
        return [
            'regime_direction'       => 'unknown',
            'regime_strength'        => 0.0,
            'regime_depth_pct'       => 0.0,
            'regime_duration_bars'   => 0,
            'trend_maturity_score'   => 0.0,
            'noise_score'            => 0.0,
            'exhaustion_score'       => 0.0,
            'volatility_score'       => 0.0,
            'stretch_score'          => 0.0,
            'local_structure_score'  => 0.0,
            'context_quality_score'  => 0.0,
            'uptrend_duration_bars'  => 0,

            'trend_direction'        => 'unknown',
            'trend_strength'         => 0.0,
            'trend_duration_bars'    => 0,
            'volatility'             => 0.0,

            'parser2_context_available' => false,
            'parser2_context_ts'     => time(),
            'source_version'         => self::VERSION,
            'context_available'      => false,

            'diagnostics'            => ['reason' => $reason],
        ];
    }

    /**
     * Get adapter-level counters.
     *
     * @return array<string,int>
     */
    public function getAdapterCounters(): array
    {
        return [
            'context_adapter_calls'              => $this->adapterCalls,
            'context_adapter_valid_count'         => $this->adapterValidCount,
            'context_adapter_invalid_count'       => $this->adapterInvalidCount,
            'context_adapter_bearish_count'       => $this->adapterBearishCount,
            'context_adapter_mature_bearish_count' => $this->adapterMatureBearishCount,
            'context_adapter_bullish_count'       => $this->adapterBullishCount,
        ];
    }

    /**
     * Reset adapter counters (call before a new analyzer run).
     */
    public function resetCounters(): void
    {
        $this->adapterCalls = 0;
        $this->adapterValidCount = 0;
        $this->adapterInvalidCount = 0;
        $this->adapterBearishCount = 0;
        $this->adapterMatureBearishCount = 0;
        $this->adapterBullishCount = 0;
    }
}
