<?php
declare(strict_types=1);

require_once __DIR__ . '/pattern_detector_interface.php';

/**
 * Double Bottom Detector — Smart Brain Analyzer V1
 *
 * Detects two nearby lows after a downward movement.
 * Output: trend_bias = up, pattern_algorithm = double_bottom
 */
final class DoubleBottomDetector implements PatternDetectorInterface
{
    /** Maximum allowed difference between two lows (as fraction of price) */
    private float $lowTolerance;

    public function __construct(float $lowTolerance = 0.015)
    {
        $this->lowTolerance = $lowTolerance;
    }

    public function getName(): string
    {
        return 'double_bottom';
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

        // Split history into segments for analysis
        // Look at the last 60% of data for the pattern
        $lookback = max(20, (int)($n * 0.6));
        $segment = array_slice($prices, $n - $lookback);
        $segLen = count($segment);

        // Find local minima (lows) in the segment
        $lows = $this->findLocalMinima($segment);

        if (count($lows) < 2) {
            return null;
        }

        // Try pairs of lows (latest first)
        $bestConfidence = 0.0;
        $detected = false;

        for ($i = count($lows) - 1; $i >= 1; $i--) {
            $low2Idx = $lows[$i];
            $low1Idx = $lows[$i - 1];

            $low1Price = $segment[$low1Idx];
            $low2Price = $segment[$low2Idx];

            // Lows must be separated by at least 3 bars
            if ($low2Idx - $low1Idx < 3) {
                continue;
            }

            // Check tolerance: lows should be close
            $avgLow = ($low1Price + $low2Price) / 2.0;
            if ($avgLow <= 0.0) {
                continue;
            }
            $diff = abs($low1Price - $low2Price) / $avgLow;
            if ($diff > $this->lowTolerance) {
                continue;
            }

            // Check prior downward movement (prices before low1 should be higher)
            $priorStart = max(0, $low1Idx - 5);
            $priorPrices = array_slice($segment, $priorStart, $low1Idx - $priorStart);
            if (count($priorPrices) < 2) {
                continue;
            }
            $priorAvg = array_sum($priorPrices) / count($priorPrices);
            if ($priorAvg <= $low1Price) {
                continue;
            }

            // Check mid rebound between lows
            $midSlice = array_slice($segment, $low1Idx, $low2Idx - $low1Idx + 1);
            $neckline = max($midSlice);
            $reboundStrength = 0.0;
            if ($avgLow > 0.0) {
                $reboundStrength = ($neckline - $avgLow) / $avgLow;
            }

            if ($reboundStrength < 0.002) {
                continue;
            }

            // Optional: check post-second-low recovery
            $postRecovery = 0.0;
            if ($low2Idx < $segLen - 1) {
                $postPrices = array_slice($segment, $low2Idx);
                $postHigh = max($postPrices);
                if ($low2Price > 0.0) {
                    $postRecovery = ($postHigh - $low2Price) / $low2Price;
                }
            }

            // Calculate confidence
            // Higher confidence when: lows are closer, rebound is stronger, recovery is stronger
            $closenessScore = max(0.0, 1.0 - ($diff / $this->lowTolerance));
            $reboundScore = min(1.0, $reboundStrength / 0.03);
            $recoveryScore = min(1.0, $postRecovery / 0.02);

            $confidence = ($closenessScore * 0.4) + ($reboundScore * 0.35) + ($recoveryScore * 0.25);
            $confidence = max(0.10, min(0.98, $confidence));

            if ($confidence > $bestConfidence) {
                $bestConfidence = $confidence;
                $detected = true;
            }
        }

        if (!$detected) {
            return null;
        }

        return [
            'detected' => true,
            'confidence' => round($bestConfidence, 2),
            'trend_bias' => 'up',
        ];
    }

    /**
     * Find indices of local minima in a price series.
     *
     * @param array<int,float> $prices
     * @return array<int,int>
     */
    private function findLocalMinima(array $prices): array
    {
        $n = count($prices);
        if ($n < 3) {
            return [];
        }

        $minima = [];
        $window = max(2, (int)($n / 10));

        for ($i = $window; $i < $n - $window; $i++) {
            $isMin = true;
            for ($j = $i - $window; $j <= $i + $window; $j++) {
                if ($j !== $i && $prices[$j] < $prices[$i]) {
                    $isMin = false;
                    break;
                }
            }
            if ($isMin) {
                $minima[] = $i;
            }
        }

        return $minima;
    }
}
