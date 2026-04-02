<?php
declare(strict_types=1);

/**
 * PatternDetector — base interface and raw detection data contract.
 *
 * Every detector returns an array of RawDetection arrays or an empty array.
 *
 * RawDetection shape:
 * {
 *   detector_id:         string   unique detector identifier (e.g. "double_bottom_contextual_v3")
 *   pattern_algorithm:   string   canonical algorithm name
 *   pattern_family:      string   e.g. "reversal", "continuation", "breakdown"
 *   pattern_version:     string   "v2"|"v3"|...
 *   symbol:              string
 *   side:                string   "long"|"short"
 *   detected_at:         string   ISO 8601
 *   time_window_minutes: int
 *   raw_score:           float    0-1
 *   entry_hint:          float|null
 *   invalidation_hint:   float|null
 *   expected_move_pct:   float|null
 *   context:             array    free-form detector context
 *   source_data_ref:     string   opaque reference to input data slice
 * }
 */
interface PatternDetectorInterface
{
    /**
     * @param  array<string,mixed>          $marketData  Normalized price/volume slice
     * @param  array<string,mixed>          $config      Detector-specific config
     * @return list<array<string,mixed>>                 Raw detections (may be empty)
     */
    public function detect(array $marketData, array $config): array;

    /** Return the canonical algorithm name this detector produces. */
    public function getAlgorithmName(): string;
}

// ---------------------------------------------------------------------------
// Double-Bottom Contextual V2
// ---------------------------------------------------------------------------

/**
 * Detects double_bottom_contextual_v2 patterns.
 *
 * Looks for two consecutive local lows within tolerance, with a recovery
 * bounce between them that does not exceed a configurable pullback ratio.
 */
final class DoubleBottomContextualV2Detector implements PatternDetectorInterface
{
    public function getAlgorithmName(): string
    {
        return 'double_bottom_contextual_v2';
    }

    public function detect(array $marketData, array $config): array
    {
        $candles     = $marketData['candles'] ?? [];
        $symbol      = (string)($marketData['symbol'] ?? '');
        $timeWindow  = (int)($marketData['time_window_minutes'] ?? 15);

        if (count($candles) < 20) {
            return [];
        }

        $tolerance    = (float)($config['tolerance_pct'] ?? 0.015);
        $minBounce    = (float)($config['min_bounce_pct'] ?? 0.005);
        $maxPullback  = (float)($config['max_pullback_ratio'] ?? 0.618);
        $detections   = [];

        $count = count($candles);
        for ($i = 5; $i < $count - 5; $i++) {
            $low1 = (float)($candles[$i]['low'] ?? 0);
            if ($low1 <= 0) {
                continue;
            }

            // Find local low at i
            if (!$this->isLocalLow($candles, $i, 3)) {
                continue;
            }

            // Scan for a second local low within $tolerance
            for ($j = $i + 4; $j < $count - 2; $j++) {
                $low2 = (float)($candles[$j]['low'] ?? 0);
                if ($low2 <= 0) {
                    continue;
                }

                if (!$this->isLocalLow($candles, $j, 2)) {
                    continue;
                }

                $diff = abs($low2 - $low1) / $low1;
                if ($diff > $tolerance) {
                    continue;
                }

                // Measure bounce between the two lows
                $peakBetween = $this->maxHighBetween($candles, $i, $j);
                $bounceRatio = $low1 > 0 ? ($peakBetween - $low1) / $low1 : 0;
                if ($bounceRatio < $minBounce) {
                    continue;
                }

                // Check that the bounce does not retrace more than maxPullback of recent move
                $latestClose = (float)($candles[$j + 1]['close'] ?? $candles[$j]['close'] ?? 0);
                $entryHint   = $peakBetween;
                $rawScore    = max(0.3, min(1.0, 0.5 + $bounceRatio * 2 - $diff * 10));

                $detections[] = $this->buildDetection(
                    $symbol, 'long', $timeWindow,
                    $rawScore, $entryHint, $low1 * (1 - $tolerance),
                    ($entryHint - $low1) * 1.5,
                    [
                        'low1'          => $low1,
                        'low2'          => $low2,
                        'peak_between'  => $peakBetween,
                        'bounce_ratio'  => $bounceRatio,
                        'diff_pct'      => $diff,
                        'i'             => $i,
                        'j'             => $j,
                    ],
                    sprintf('%s:candles[%d-%d]', $symbol, $i, $j)
                );

                // Only the earliest valid pair per anchor
                break;
            }
        }

        return $detections;
    }

    // ------------------------------------------------------------------

    /** @param list<array<string,mixed>> $candles */
    private function isLocalLow(array $candles, int $idx, int $window): bool
    {
        $low = (float)($candles[$idx]['low'] ?? 0);
        if ($low <= 0) {
            return false;
        }
        $count = count($candles);
        for ($k = max(0, $idx - $window); $k <= min($count - 1, $idx + $window); $k++) {
            if ($k === $idx) {
                continue;
            }
            if ((float)($candles[$k]['low'] ?? INF) < $low) {
                return false;
            }
        }
        return true;
    }

    /** @param list<array<string,mixed>> $candles */
    private function maxHighBetween(array $candles, int $from, int $to): float
    {
        $max = 0.0;
        for ($k = $from; $k <= $to; $k++) {
            $h = (float)($candles[$k]['high'] ?? 0);
            if ($h > $max) {
                $max = $h;
            }
        }
        return $max;
    }

    /** @return array<string,mixed> */
    private function buildDetection(
        string $symbol,
        string $side,
        int $timeWindow,
        float $rawScore,
        ?float $entryHint,
        ?float $invalidationHint,
        ?float $expectedMovePct,
        array $context,
        string $sourceRef
    ): array {
        return [
            'detector_id'         => $this->getAlgorithmName(),
            'pattern_algorithm'   => $this->getAlgorithmName(),
            'pattern_family'      => 'reversal',
            'pattern_version'     => 'v2',
            'symbol'              => $symbol,
            'side'                => $side,
            'detected_at'         => date('c'),
            'time_window_minutes' => $timeWindow,
            'raw_score'           => $rawScore,
            'entry_hint'          => $entryHint,
            'invalidation_hint'   => $invalidationHint,
            'expected_move_pct'   => $expectedMovePct,
            'context'             => $context,
            'source_data_ref'     => $sourceRef,
        ];
    }
}

// ---------------------------------------------------------------------------
// Double-Bottom Contextual V3
// ---------------------------------------------------------------------------

/**
 * Detects double_bottom_contextual_v3 patterns.
 *
 * V3 adds volume confirmation and contextual reclaim requirement vs V2.
 */
final class DoubleBottomContextualV3Detector implements PatternDetectorInterface
{
    public function getAlgorithmName(): string
    {
        return 'double_bottom_contextual_v3';
    }

    public function detect(array $marketData, array $config): array
    {
        $candles    = $marketData['candles'] ?? [];
        $symbol     = (string)($marketData['symbol'] ?? '');
        $timeWindow = (int)($marketData['time_window_minutes'] ?? 15);

        if (count($candles) < 25) {
            return [];
        }

        $tolerance     = (float)($config['tolerance_pct'] ?? 0.012);
        $minBounce     = (float)($config['min_bounce_pct'] ?? 0.006);
        $volumeBonus   = (bool)($config['require_volume_confirm'] ?? true);
        $detections    = [];
        $count         = count($candles);

        for ($i = 6; $i < $count - 6; $i++) {
            $low1 = (float)($candles[$i]['low'] ?? 0);
            if ($low1 <= 0 || !$this->isLocalLow($candles, $i, 4)) {
                continue;
            }

            for ($j = $i + 5; $j < $count - 2; $j++) {
                $low2 = (float)($candles[$j]['low'] ?? 0);
                if ($low2 <= 0 || !$this->isLocalLow($candles, $j, 3)) {
                    continue;
                }

                $diff = abs($low2 - $low1) / $low1;
                if ($diff > $tolerance) {
                    continue;
                }

                $peakBetween = $this->maxHighBetween($candles, $i, $j);
                $bounceRatio = $low1 > 0 ? ($peakBetween - $low1) / $low1 : 0;
                if ($bounceRatio < $minBounce) {
                    continue;
                }

                // V3: volume at low2 must be >= volume at low1 (accumulation confirmation)
                $vol1    = (float)($candles[$i]['volume'] ?? 0);
                $vol2    = (float)($candles[$j]['volume'] ?? 0);
                $volOk   = !$volumeBonus || $vol2 >= $vol1 * 0.8;
                if (!$volOk) {
                    continue;
                }

                $rawScore = max(0.35, min(1.0,
                    0.55 + $bounceRatio * 2 - $diff * 12 + ($vol2 > $vol1 ? 0.05 : 0)
                ));

                $detections[] = $this->buildDetection(
                    $symbol, 'long', $timeWindow,
                    $rawScore,
                    $peakBetween,
                    $low1 * (1 - $tolerance),
                    ($peakBetween - $low1) * 1.618,
                    [
                        'low1'          => $low1,
                        'low2'          => $low2,
                        'peak_between'  => $peakBetween,
                        'bounce_ratio'  => $bounceRatio,
                        'diff_pct'      => $diff,
                        'vol1'          => $vol1,
                        'vol2'          => $vol2,
                        'volume_ok'     => $volOk,
                        'i'             => $i,
                        'j'             => $j,
                    ],
                    sprintf('%s:candles[%d-%d]', $symbol, $i, $j)
                );
                break;
            }
        }

        return $detections;
    }

    /** @param list<array<string,mixed>> $candles */
    private function isLocalLow(array $candles, int $idx, int $window): bool
    {
        $low   = (float)($candles[$idx]['low'] ?? 0);
        $count = count($candles);
        if ($low <= 0) {
            return false;
        }
        for ($k = max(0, $idx - $window); $k <= min($count - 1, $idx + $window); $k++) {
            if ($k === $idx) {
                continue;
            }
            if ((float)($candles[$k]['low'] ?? INF) < $low) {
                return false;
            }
        }
        return true;
    }

    /** @param list<array<string,mixed>> $candles */
    private function maxHighBetween(array $candles, int $from, int $to): float
    {
        $max = 0.0;
        for ($k = $from; $k <= $to; $k++) {
            $h = (float)($candles[$k]['high'] ?? 0);
            if ($h > $max) {
                $max = $h;
            }
        }
        return $max;
    }

    /** @return array<string,mixed> */
    private function buildDetection(
        string $symbol,
        string $side,
        int $timeWindow,
        float $rawScore,
        ?float $entryHint,
        ?float $invalidationHint,
        ?float $expectedMovePct,
        array $context,
        string $sourceRef
    ): array {
        return [
            'detector_id'         => $this->getAlgorithmName(),
            'pattern_algorithm'   => $this->getAlgorithmName(),
            'pattern_family'      => 'reversal',
            'pattern_version'     => 'v3',
            'symbol'              => $symbol,
            'side'                => $side,
            'detected_at'         => date('c'),
            'time_window_minutes' => $timeWindow,
            'raw_score'           => $rawScore,
            'entry_hint'          => $entryHint,
            'invalidation_hint'   => $invalidationHint,
            'expected_move_pct'   => $expectedMovePct,
            'context'             => $context,
            'source_data_ref'     => $sourceRef,
        ];
    }
}

// ---------------------------------------------------------------------------
// Double-Top Contextual V2
// ---------------------------------------------------------------------------

/**
 * Detects double_top_contextual_v2 patterns (mirror of double-bottom for shorts).
 */
final class DoubleTopContextualV2Detector implements PatternDetectorInterface
{
    public function getAlgorithmName(): string
    {
        return 'double_top_contextual_v2';
    }

    public function detect(array $marketData, array $config): array
    {
        $candles    = $marketData['candles'] ?? [];
        $symbol     = (string)($marketData['symbol'] ?? '');
        $timeWindow = (int)($marketData['time_window_minutes'] ?? 15);

        if (count($candles) < 20) {
            return [];
        }

        $tolerance  = (float)($config['tolerance_pct'] ?? 0.015);
        $minBounce  = (float)($config['min_bounce_pct'] ?? 0.005);
        $detections = [];
        $count      = count($candles);

        for ($i = 5; $i < $count - 5; $i++) {
            $high1 = (float)($candles[$i]['high'] ?? 0);
            if ($high1 <= 0 || !$this->isLocalHigh($candles, $i, 3)) {
                continue;
            }

            for ($j = $i + 4; $j < $count - 2; $j++) {
                $high2 = (float)($candles[$j]['high'] ?? 0);
                if ($high2 <= 0 || !$this->isLocalHigh($candles, $j, 2)) {
                    continue;
                }

                $diff = abs($high2 - $high1) / $high1;
                if ($diff > $tolerance) {
                    continue;
                }

                $troughBetween = $this->minLowBetween($candles, $i, $j);
                $pullback      = $high1 > 0 ? ($high1 - $troughBetween) / $high1 : 0;
                if ($pullback < $minBounce) {
                    continue;
                }

                $rawScore = max(0.3, min(1.0, 0.5 + $pullback * 2 - $diff * 10));

                $detections[] = [
                    'detector_id'         => $this->getAlgorithmName(),
                    'pattern_algorithm'   => $this->getAlgorithmName(),
                    'pattern_family'      => 'reversal',
                    'pattern_version'     => 'v2',
                    'symbol'              => $symbol,
                    'side'                => 'short',
                    'detected_at'         => date('c'),
                    'time_window_minutes' => $timeWindow,
                    'raw_score'           => $rawScore,
                    'entry_hint'          => $troughBetween,
                    'invalidation_hint'   => $high1 * (1 + $tolerance),
                    'expected_move_pct'   => ($high1 - $troughBetween) * 1.5,
                    'context'             => [
                        'high1'           => $high1,
                        'high2'           => $high2,
                        'trough_between'  => $troughBetween,
                        'pullback'        => $pullback,
                        'diff_pct'        => $diff,
                        'i'               => $i,
                        'j'               => $j,
                    ],
                    'source_data_ref' => sprintf('%s:candles[%d-%d]', $symbol, $i, $j),
                ];
                break;
            }
        }

        return $detections;
    }

    /** @param list<array<string,mixed>> $candles */
    private function isLocalHigh(array $candles, int $idx, int $window): bool
    {
        $high  = (float)($candles[$idx]['high'] ?? 0);
        $count = count($candles);
        if ($high <= 0) {
            return false;
        }
        for ($k = max(0, $idx - $window); $k <= min($count - 1, $idx + $window); $k++) {
            if ($k === $idx) {
                continue;
            }
            if ((float)($candles[$k]['high'] ?? 0) > $high) {
                return false;
            }
        }
        return true;
    }

    /** @param list<array<string,mixed>> $candles */
    private function minLowBetween(array $candles, int $from, int $to): float
    {
        $min = INF;
        for ($k = $from; $k <= $to; $k++) {
            $l = (float)($candles[$k]['low'] ?? INF);
            if ($l < $min) {
                $min = $l;
            }
        }
        return $min === INF ? 0.0 : $min;
    }
}

// ---------------------------------------------------------------------------
// Double-Top Contextual V3
// ---------------------------------------------------------------------------

/**
 * Detects double_top_contextual_v3 patterns — adds volume confirmation.
 */
final class DoubleTopContextualV3Detector implements PatternDetectorInterface
{
    public function getAlgorithmName(): string
    {
        return 'double_top_contextual_v3';
    }

    public function detect(array $marketData, array $config): array
    {
        $candles    = $marketData['candles'] ?? [];
        $symbol     = (string)($marketData['symbol'] ?? '');
        $timeWindow = (int)($marketData['time_window_minutes'] ?? 15);

        if (count($candles) < 25) {
            return [];
        }

        $tolerance     = (float)($config['tolerance_pct'] ?? 0.012);
        $minBounce     = (float)($config['min_bounce_pct'] ?? 0.006);
        $requireVolume = (bool)($config['require_volume_confirm'] ?? true);
        $detections    = [];
        $count         = count($candles);

        for ($i = 6; $i < $count - 6; $i++) {
            $high1 = (float)($candles[$i]['high'] ?? 0);
            if ($high1 <= 0 || !$this->isLocalHigh($candles, $i, 4)) {
                continue;
            }

            for ($j = $i + 5; $j < $count - 2; $j++) {
                $high2 = (float)($candles[$j]['high'] ?? 0);
                if ($high2 <= 0 || !$this->isLocalHigh($candles, $j, 3)) {
                    continue;
                }

                $diff = abs($high2 - $high1) / $high1;
                if ($diff > $tolerance) {
                    continue;
                }

                $troughBetween = $this->minLowBetween($candles, $i, $j);
                $pullback      = $high1 > 0 ? ($high1 - $troughBetween) / $high1 : 0;
                if ($pullback < $minBounce) {
                    continue;
                }

                $vol1  = (float)($candles[$i]['volume'] ?? 0);
                $vol2  = (float)($candles[$j]['volume'] ?? 0);
                $volOk = !$requireVolume || $vol2 >= $vol1 * 0.8;
                if (!$volOk) {
                    continue;
                }

                $rawScore = max(0.35, min(1.0,
                    0.55 + $pullback * 2 - $diff * 12 + ($vol2 > $vol1 ? 0.05 : 0)
                ));

                $detections[] = [
                    'detector_id'         => $this->getAlgorithmName(),
                    'pattern_algorithm'   => $this->getAlgorithmName(),
                    'pattern_family'      => 'reversal',
                    'pattern_version'     => 'v3',
                    'symbol'              => $symbol,
                    'side'                => 'short',
                    'detected_at'         => date('c'),
                    'time_window_minutes' => $timeWindow,
                    'raw_score'           => $rawScore,
                    'entry_hint'          => $troughBetween,
                    'invalidation_hint'   => $high1 * (1 + $tolerance),
                    'expected_move_pct'   => ($high1 - $troughBetween) * 1.618,
                    'context'             => [
                        'high1'           => $high1,
                        'high2'           => $high2,
                        'trough_between'  => $troughBetween,
                        'pullback'        => $pullback,
                        'diff_pct'        => $diff,
                        'vol1'            => $vol1,
                        'vol2'            => $vol2,
                        'volume_ok'       => $volOk,
                        'i'               => $i,
                        'j'               => $j,
                    ],
                    'source_data_ref' => sprintf('%s:candles[%d-%d]', $symbol, $i, $j),
                ];
                break;
            }
        }

        return $detections;
    }

    /** @param list<array<string,mixed>> $candles */
    private function isLocalHigh(array $candles, int $idx, int $window): bool
    {
        $high  = (float)($candles[$idx]['high'] ?? 0);
        $count = count($candles);
        if ($high <= 0) {
            return false;
        }
        for ($k = max(0, $idx - $window); $k <= min($count - 1, $idx + $window); $k++) {
            if ($k === $idx) {
                continue;
            }
            if ((float)($candles[$k]['high'] ?? 0) > $high) {
                return false;
            }
        }
        return true;
    }

    /** @param list<array<string,mixed>> $candles */
    private function minLowBetween(array $candles, int $from, int $to): float
    {
        $min = INF;
        for ($k = $from; $k <= $to; $k++) {
            $l = (float)($candles[$k]['low'] ?? INF);
            if ($l < $min) {
                $min = $l;
            }
        }
        return $min === INF ? 0.0 : $min;
    }
}

// ---------------------------------------------------------------------------
// Detector Registry — add new detectors here as the library grows
// ---------------------------------------------------------------------------

final class PatternDetectorRegistry
{
    /** @var array<string,PatternDetectorInterface> */
    private static array $detectors = [];
    private static bool  $initialized = false;

    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        self::register(new DoubleBottomContextualV2Detector());
        self::register(new DoubleBottomContextualV3Detector());
        self::register(new DoubleTopContextualV2Detector());
        self::register(new DoubleTopContextualV3Detector());
        self::$initialized = true;
    }

    public static function register(PatternDetectorInterface $detector): void
    {
        self::$detectors[$detector->getAlgorithmName()] = $detector;
    }

    /** @return array<string,PatternDetectorInterface> */
    public static function all(): array
    {
        return self::$detectors;
    }

    public static function get(string $algorithmName): ?PatternDetectorInterface
    {
        return self::$detectors[$algorithmName] ?? null;
    }
}
