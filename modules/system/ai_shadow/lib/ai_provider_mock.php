<?php
declare(strict_types=1);

/**
 * AiProviderMock
 *
 * Deterministic stub provider for Phase 1.
 * Same symbol+signal_ts always yields the same output.
 */
final class AiProviderMock implements AiProviderInterface
{
    public function getName(): string
    {
        return 'mock';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * @param  array<string,mixed> $input
     * @return array{decision:string,confidence:float,quality_score:float,risk_penalty:float,reasons:array<int,string>,recommended_action:string}
     */
    public function evaluate(array $input): array
    {
        $symbol    = (string)($input['symbol']    ?? '');
        $signalTs  = (string)($input['signal_ts'] ?? '');
        $seedStr   = $symbol . '|' . $signalTs;

        // Deterministic seed from hash
        $hash = crc32($seedStr);
        // Normalize to unsigned 32-bit range
        $seed = $hash & 0x7FFFFFFF;

        // confidence: 0.40 – 0.90  (spread = 0.50)
        $confidence   = round(0.40 + ($seed % 1000) / 2000.0, 4);

        // quality_score: 0.30 – 0.85  (spread = 0.55)
        $hashQ        = crc32($seedStr . '_q') & 0x7FFFFFFF;
        $qualityScore = round(0.30 + ($hashQ % 1000) / 1818.0, 4);

        // Decision is based on the input confidence_score when present,
        // falling back to computed confidence
        $inputConfidence = isset($input['confidence_score'])
            ? (float)$input['confidence_score']
            : $confidence;

        $decision = $inputConfidence > 0.55 ? 'enter' : 'skip';

        return [
            'decision'           => $decision,
            'confidence'         => $confidence,
            'quality_score'      => $qualityScore,
            'risk_penalty'       => 0.0,
            'reasons'            => ['mock_provider', 'pattern_match_detected'],
            'recommended_action' => $decision,
        ];
    }
}
