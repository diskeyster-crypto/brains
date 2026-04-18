<?php
declare(strict_types=1);

require_once __DIR__ . '/pattern_detector_interface.php';

/**
 * Double Top Detector — Smart Brain Analyzer V1
 *
 * Detects two nearby highs after an upward movement.
 * Output: trend_bias = down, pattern_algorithm = double_top
 */
final class DoubleTopDetector implements PatternDetectorInterface
{
    /** Maximum allowed difference between two highs (as fraction of price) */
    private float $highTolerance;

    public function __construct(float $highTolerance = 0.015)
    {
        $this->highTolerance = $highTolerance;
    }

    public function getName(): string
    {
        return 'double_top';
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

        // Look at the last 60% of data for the pattern
        $lookback = max(20, (int)($n * 0.6));
        $segment = array_slice($prices, $n - $lookback);
        $segLen = count($segment);

        // Find local maxima (highs) in the segment
        $highs = $this->findLocalMaxima($segment);

        if (count($highs) < 2) {
            return null;
        }

        // Try pairs of highs (latest first)
        $bestConfidence = 0.0;
        $detected = false;

        for ($i = count($highs) - 1; $i >= 1; $i--) {
            $high2Idx = $highs[$i];
            $high1Idx = $highs[$i - 1];

            $high1Price = $segment[$high1Idx];
            $high2Price = $segment[$high2Idx];

            // Highs must be separated by at least 3 bars
            if ($high2Idx - $high1Idx < 3) {
                continue;
            }

            // Check tolerance: highs should be close
            $avgHigh = ($high1Price + $high2Price) / 2.0;
            if ($avgHigh <= 0.0) {
                continue;
            }
            $diff = abs($high1Price - $high2Price) / $avgHigh;
            if ($diff > $this->highTolerance) {
                continue;
            }

            // Check prior upward movement (prices before high1 should be lower)
            $priorStart = max(0, $high1Idx - 5);
            $priorPrices = array_slice($segment, $priorStart, $high1Idx - $priorStart);
            if (count($priorPrices) < 2) {
                continue;
            }
            $priorAvg = array_sum($priorPrices) / count($priorPrices);
            if ($priorAvg >= $high1Price) {
                continue;
            }

            // Check mid pullback between highs
            $midSlice = array_slice($segment, $high1Idx, $high2Idx - $high1Idx + 1);
            $neckline = min($midSlice);
            $pullbackStrength = 0.0;
            if ($avgHigh > 0.0) {
                $pullbackStrength = ($avgHigh - $neckline) / $avgHigh;
            }

            if ($pullbackStrength < 0.002) {
                continue;
            }

            // Optional: check post-second-high decline
            $postDecline = 0.0;
            if ($high2Idx < $segLen - 1) {
                $postPrices = array_slice($segment, $high2Idx);
                $postLow = min($postPrices);
                if ($high2Price > 0.0) {
                    $postDecline = ($high2Price - $postLow) / $high2Price;
                }
            }

            // Calculate confidence
            $closenessScore = max(0.0, 1.0 - ($diff / $this->highTolerance));
            $pullbackScore = min(1.0, $pullbackStrength / 0.03);
            $declineScore = min(1.0, $postDecline / 0.02);

            $confidence = ($closenessScore * 0.4) + ($pullbackScore * 0.35) + ($declineScore * 0.25);
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
            'trend_bias' => 'down',
        ];
    }

    /**
     * Find indices of local maxima in a price series.
     *
     * @param array<int,float> $prices
     * @return array<int,int>
     */
    private function findLocalMaxima(array $prices): array
    {
        $n = count($prices);
        if ($n < 3) {
            return [];
        }

        $maxima = [];
        $window = max(2, (int)($n / 10));

        for ($i = $window; $i < $n - $window; $i++) {
            $isMax = true;
            for ($j = $i - $window; $j <= $i + $window; $j++) {
                if ($j !== $i && $prices[$j] > $prices[$i]) {
                    $isMax = false;
                    break;
                }
            }
            if ($isMax) {
                $maxima[] = $i;
            }
        }

        return $maxima;
    }
}
