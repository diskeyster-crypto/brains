<?php
declare(strict_types=1);

namespace PatternEngine;

if (defined('PATTERN_ENGINE_SIGNAL_ADAPTER_LOADED')) {
    return;
}
define('PATTERN_ENGINE_SIGNAL_ADAPTER_LOADED', true);

/**
 * UniversalSignalAdapter
 *
 * Converts any raw detection array (output of PatternDetectorInterface::detect)
 * into one normalized signal contract.
 *
 * Normalized signal contract (all downstream modules consume this):
 * {
 *   signal_id:              string   UUID-like unique identifier
 *   symbol:                 string
 *   side:                   string   "long"|"short"
 *   pattern_algorithm:      string
 *   pattern_family:         string   "reversal"|"continuation"|"breakdown"
 *   pattern_version:        string   "v2"|"v3"|...
 *   source_module:          string   "pattern_engine"
 *   detected_at:            string   ISO 8601
 *   time_window_minutes:    int
 *   signal_strength:        float    0-1  (derived from raw_score)
 *   quality_score:          float    0-1  (composite)
 *   entry_hint:             float|null
 *   entry_context:          array
 *   invalidation_hint:      float|null
 *   expected_move_hint:     float|null
 *   ttl_seconds:            int
 *   pattern_diagnostics:    array
 *   market_context:         array
 *   source_version:         string   "1.0"
 *   raw_detection_ref:      string   opaque reference back to the raw detection
 * }
 */
final class UniversalSignalAdapter
{
    private const SOURCE_MODULE   = 'pattern_engine';
    private const SOURCE_VERSION  = '1.0';
    private const DEFAULT_TTL_MAP = [
        1   => 300,
        5   => 900,
        15  => 2700,
        30  => 5400,
        60  => 10800,
        240 => 43200,
    ];

    /**
     * Convert a raw detection into a normalized signal.
     *
     * @param  array<string,mixed>  $rawDetection   Direct output from a PatternDetectorInterface
     * @param  array<string,mixed>  $marketContext  Optional market context to embed
     * @return array<string,mixed>
     */
    public function normalize(array $rawDetection, array $marketContext = []): array
    {
        $symbol       = (string)($rawDetection['symbol'] ?? '');
        $side         = (string)($rawDetection['side'] ?? 'long');
        $algorithm    = (string)($rawDetection['pattern_algorithm'] ?? '');
        $family       = (string)($rawDetection['pattern_family'] ?? 'unknown');
        $version      = (string)($rawDetection['pattern_version'] ?? 'v1');
        $detectedAt   = (string)($rawDetection['detected_at'] ?? date('c'));
        $timeWindow   = (int)($rawDetection['time_window_minutes'] ?? 15);
        $rawScore     = (float)($rawDetection['raw_score'] ?? 0.5);
        $entryHint    = isset($rawDetection['entry_hint']) ? (float)$rawDetection['entry_hint'] : null;
        $invalidHint  = isset($rawDetection['invalidation_hint']) ? (float)$rawDetection['invalidation_hint'] : null;
        $expectedMove = isset($rawDetection['expected_move_pct']) ? (float)$rawDetection['expected_move_pct'] : null;
        $context      = (array)($rawDetection['context'] ?? []);
        $sourceRef    = (string)($rawDetection['source_data_ref'] ?? '');

        $signalStrength = $this->computeSignalStrength($rawScore, $family, $version);
        $qualityScore   = $this->computeQualityScore($rawScore, $context, $timeWindow);
        $ttl            = $this->computeTtl($timeWindow, $rawScore);

        // Symbol normalization fields — injected by service via marketContext
        $normInfo = isset($marketContext['symbol_normalization']) && is_array($marketContext['symbol_normalization'])
            ? $marketContext['symbol_normalization']
            : [];

        return [
            'signal_id'                   => $this->generateSignalId($symbol, $algorithm, $detectedAt),
            'symbol'                      => $symbol,
            'symbol_raw'                  => $normInfo['symbol_raw']                  ?? $symbol,
            'symbol_normalized'           => $normInfo['symbol_normalized']           ?? strtoupper($symbol),
            'symbol_canonical'            => $normInfo['symbol_canonical']            ?? strtoupper($symbol),
            'symbol_normalization_status' => $normInfo['symbol_normalization_status'] ?? 'unchanged',
            'symbol_normalization_reason' => $normInfo['symbol_normalization_reason'] ?? 'no_normalization_context',
            'side'                        => $side,
            'pattern_algorithm'           => $algorithm,
            'pattern_family'              => $family,
            'pattern_version'             => $version,
            'source_module'               => self::SOURCE_MODULE,
            'detected_at'                 => $detectedAt,
            'time_window_minutes'         => $timeWindow,
            'signal_strength'             => round($signalStrength, 4),
            'quality_score'               => round($qualityScore, 4),
            'entry_hint'                  => $entryHint,
            'entry_context'               => $this->buildEntryContext($context, $side),
            'invalidation_hint'           => $invalidHint,
            'expected_move_hint'          => $expectedMove,
            'ttl_seconds'                 => $ttl,
            'pattern_diagnostics'         => $this->buildDiagnostics($rawDetection),
            'market_context'              => $marketContext,
            'source_version'              => self::SOURCE_VERSION,
            'raw_detection_ref'           => $sourceRef,
        ];
    }

    /**
     * Batch-normalize a list of raw detections.
     *
     * @param  list<array<string,mixed>>   $rawDetections
     * @param  array<string,mixed>         $marketContext
     * @return list<array<string,mixed>>
     */
    public function normalizeAll(array $rawDetections, array $marketContext = []): array
    {
        $result = [];
        foreach ($rawDetections as $raw) {
            $result[] = $this->normalize($raw, $marketContext);
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function generateSignalId(string $symbol, string $algorithm, string $detectedAt): string
    {
        $entropy = $symbol . $algorithm . $detectedAt . microtime(true) . random_int(0, PHP_INT_MAX);
        $hash    = substr(hash('sha256', $entropy), 0, 16);
        return 'pe_' . $hash;
    }

    private function computeSignalStrength(float $rawScore, string $family, string $version): float
    {
        // V3 patterns are rewarded for volume confirmation
        $versionBonus = ($version === 'v3') ? 0.05 : 0.0;
        return min(1.0, $rawScore + $versionBonus);
    }

    private function computeQualityScore(float $rawScore, array $context, int $timeWindow): float
    {
        $base = $rawScore;

        // Prefer higher time windows (more noise-resistant)
        $twBonus = match (true) {
            $timeWindow >= 240 => 0.10,
            $timeWindow >= 60  => 0.07,
            $timeWindow >= 30  => 0.04,
            $timeWindow >= 15  => 0.02,
            default            => 0.0,
        };

        // Volume confirmation bonus
        $volBonus = !empty($context['volume_ok']) ? 0.05 : 0.0;

        return min(1.0, $base + $twBonus + $volBonus);
    }

    private function computeTtl(int $timeWindow, float $rawScore): int
    {
        $base = self::DEFAULT_TTL_MAP[$timeWindow]
             ?? ($timeWindow * 60 * 3);

        // Higher confidence extends TTL slightly (up to 50%)
        $multiplier = 1.0 + ($rawScore - 0.5) * 1.0;
        return (int)max(60, $base * $multiplier);
    }

    /** @return array<string,mixed> */
    private function buildEntryContext(array $context, string $side): array
    {
        $result = [];
        if ($side === 'long') {
            foreach (['low1', 'low2', 'peak_between', 'bounce_ratio', 'diff_pct'] as $k) {
                if (isset($context[$k])) {
                    $result[$k] = $context[$k];
                }
            }
        } else {
            foreach (['high1', 'high2', 'trough_between', 'pullback', 'diff_pct'] as $k) {
                if (isset($context[$k])) {
                    $result[$k] = $context[$k];
                }
            }
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function buildDiagnostics(array $rawDetection): array
    {
        return [
            'raw_score'        => $rawDetection['raw_score'] ?? null,
            'detector_id'      => $rawDetection['detector_id'] ?? null,
            'context_keys'     => array_keys((array)($rawDetection['context'] ?? [])),
            'source_data_ref'  => $rawDetection['source_data_ref'] ?? null,
        ];
    }
}
