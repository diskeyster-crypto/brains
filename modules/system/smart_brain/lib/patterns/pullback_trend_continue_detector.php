<?php
declare(strict_types=1);

require_once __DIR__ . '/pattern_detector_interface.php';

/**
 * Pullback Trend Continue Detector — Smart Brain Analyzer V1
 *
 * Detects continuation setups (not reversal).
 *
 * Long version: uptrend → pullback → resume upward
 * Short version: downtrend → pullback up → resume downward
 *
 * Output: pattern_algorithm = pullback_trend_continue, trend_bias = up or down
 */
final class PullbackTrendContinueDetector implements PatternDetectorInterface
{
    public function getName(): string
    {
        return 'pullback_trend_continue';
    }

    /**
     * @param array<int,array{ts_unix:int,price:float}> $history
     * @return array{detected:bool,confidence:float,trend_bias:string}|null
     */
    public function detect(array $history): ?array
    {
        $n = count($history);
        if ($n < 20) {
            return null;
        }

        $prices = array_column($history, 'price');

        // Determine the dominant trend from the first half
        $halfLen = (int)($n / 2);
        $firstHalf = array_slice($prices, 0, $halfLen);
        $secondHalf = array_slice($prices, $halfLen);

        $firstAvg = array_sum($firstHalf) / count($firstHalf);
        $firstEnd = $firstHalf[count($firstHalf) - 1];
        $firstStart = $firstHalf[0];

        if ($firstStart <= 0.0) {
            return null;
        }

        $trendReturn = ($firstEnd - $firstStart) / $firstStart;

        // Need a clear trend (at least 1% move)
        if (abs($trendReturn) < 0.01) {
            return null;
        }

        $isUptrend = ($trendReturn > 0);

        // Analyze pullback in the recent portion
        $recentLen = count($secondHalf);
        if ($recentLen < 5) {
            return null;
        }

        // Find the pullback extreme in the second half
        if ($isUptrend) {
            // Uptrend: look for a pullback low, then resumption upward
            $result = $this->detectLongPullback($secondHalf, $firstEnd);
        } else {
            // Downtrend: look for a pullback high, then resumption downward
            $result = $this->detectShortPullback($secondHalf, $firstEnd);
        }

        if ($result === null) {
            return null;
        }

        // Boost confidence based on trend cleanliness
        $trendClean = min(1.0, abs($trendReturn) / 0.05);
        $finalConfidence = ($result['confidence'] * 0.65) + ($trendClean * 0.35);
        $finalConfidence = max(0.10, min(0.98, $finalConfidence));

        return [
            'detected' => true,
            'confidence' => round($finalConfidence, 2),
            'trend_bias' => $isUptrend ? 'up' : 'down',
        ];
    }

    /**
     * Detect pullback in an uptrend (long setup).
     *
     * Looks for: segment starts with continuation, then dips (pullback), then resumes.
     *
     * @param array<int,float> $segment   Recent prices (second half of history)
     * @param float            $trendEnd  Last price of the trend segment
     * @return array{confidence:float}|null
     */
    private function detectLongPullback(array $segment, float $trendEnd): ?array
    {
        $n = count($segment);
        if ($n < 5 || $trendEnd <= 0.0) {
            return null;
        }

        // Find the peak in the first 2/3 of the segment (continuation high before pullback)
        $scanEnd = max(3, (int)($n * 0.67));
        $maxPrice = 0.0;
        $maxIdx = 0;
        for ($i = 0; $i < $scanEnd; $i++) {
            if ($segment[$i] > $maxPrice) {
                $maxPrice = $segment[$i];
                $maxIdx = $i;
            }
        }

        if ($maxPrice <= 0.0 || $maxIdx >= $n - 3) {
            return null;
        }

        // Find the lowest point after the peak (pullback bottom)
        $minPrice = $maxPrice;
        $minIdx = $maxIdx;
        for ($i = $maxIdx + 1; $i < $n; $i++) {
            if ($segment[$i] < $minPrice) {
                $minPrice = $segment[$i];
                $minIdx = $i;
            }
        }

        // Pullback bottom must not be at the very end (need resumption)
        if ($minIdx >= $n - 1) {
            return null;
        }

        // Pullback depth: how much it dropped from the peak
        $pullbackDepth = ($maxPrice - $minPrice) / $maxPrice;

        // Must pull back at least 0.5% but not more than 50% (structure break)
        if ($pullbackDepth < 0.005 || $pullbackDepth > 0.50) {
            return null;
        }

        // Check resumption after the pullback
        $lastPrice = $segment[$n - 1];
        $resumptionStrength = 0.0;
        if ($minPrice > 0.0) {
            $resumptionStrength = ($lastPrice - $minPrice) / $minPrice;
        }

        if ($resumptionStrength < 0.002) {
            return null;
        }

        // Confidence based on pullback moderateness and resumption
        $depthScore = 1.0 - abs($pullbackDepth - 0.05) / 0.05; // ideal around 5%
        $depthScore = max(0.1, min(1.0, $depthScore));
        $resumeScore = min(1.0, $resumptionStrength / 0.02);

        $confidence = ($depthScore * 0.5) + ($resumeScore * 0.5);

        return ['confidence' => $confidence];
    }

    /**
     * Detect pullback in a downtrend (short setup).
     *
     * Looks for: segment starts with continuation down, then bounces (pullback), then resumes down.
     *
     * @param array<int,float> $segment   Recent prices (second half of history)
     * @param float            $trendEnd  Last price of the trend segment
     * @return array{confidence:float}|null
     */
    private function detectShortPullback(array $segment, float $trendEnd): ?array
    {
        $n = count($segment);
        if ($n < 5 || $trendEnd <= 0.0) {
            return null;
        }

        // Find the trough in the first 2/3 of the segment (continuation low before pullback)
        $scanEnd = max(3, (int)($n * 0.67));
        $minPrice = PHP_FLOAT_MAX;
        $minIdx = 0;
        for ($i = 0; $i < $scanEnd; $i++) {
            if ($segment[$i] < $minPrice) {
                $minPrice = $segment[$i];
                $minIdx = $i;
            }
        }

        if ($minPrice <= 0.0 || $minIdx >= $n - 3) {
            return null;
        }

        // Find the highest point after the trough (pullback top)
        $maxPrice = $minPrice;
        $maxIdx = $minIdx;
        for ($i = $minIdx + 1; $i < $n; $i++) {
            if ($segment[$i] > $maxPrice) {
                $maxPrice = $segment[$i];
                $maxIdx = $i;
            }
        }

        // Pullback top must not be at the very end
        if ($maxIdx >= $n - 1) {
            return null;
        }

        // Pullback depth (upward bounce from the trough)
        $pullbackDepth = ($maxPrice - $minPrice) / $minPrice;

        if ($pullbackDepth < 0.005 || $pullbackDepth > 0.50) {
            return null;
        }

        // Check resumption after the pullback (price drops again)
        $lastPrice = $segment[$n - 1];
        $resumptionStrength = 0.0;
        if ($maxPrice > 0.0) {
            $resumptionStrength = ($maxPrice - $lastPrice) / $maxPrice;
        }

        if ($resumptionStrength < 0.002) {
            return null;
        }

        $depthScore = 1.0 - abs($pullbackDepth - 0.05) / 0.05;
        $depthScore = max(0.1, min(1.0, $depthScore));
        $resumeScore = min(1.0, $resumptionStrength / 0.02);

        $confidence = ($depthScore * 0.5) + ($resumeScore * 0.5);

        return ['confidence' => $confidence];
    }
}
