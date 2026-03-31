<?php
declare(strict_types=1);

/**
 * AiShadowSignalMirror
 *
 * Mirrors live signals → virtual signals for AI shadow evaluation.
 * READ-ONLY access to smart_brain signal data.
 */
final class AiShadowSignalMirror
{
    private AiShadowStateManager $state;
    private AiProviderInterface  $provider;
    /** @var array<string,mixed> */
    private array $config;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(
        AiShadowStateManager $state,
        AiProviderInterface  $provider,
        array                $config
    ) {
        $this->state    = $state;
        $this->provider = $provider;
        $this->config   = $config;
    }

    /**
     * Mirror an array of live signals through the AI provider.
     *
     * @param  array<int,array<string,mixed>> $liveSignals
     * @return array{processed:int,skipped_pattern:int,skipped_already_mirrored:int,newly_mirrored:int,errors:int}
     */
    public function mirrorSignals(array $liveSignals): array
    {
        $allowedPatterns = (array)($this->config['allowed_patterns'] ?? []);
        $allowedSides    = (array)($this->config['allowed_sides']    ?? []);
        $maxPerRun       = (int)($this->config['max_signals_per_run'] ?? 50);

        $counts = [
            'processed'                => 0,
            'skipped_pattern'          => 0,
            'skipped_already_mirrored' => 0,
            'newly_mirrored'           => 0,
            'errors'                   => 0,
        ];

        $processed = 0;

        foreach ($liveSignals as $signal) {
            if ($processed >= $maxPerRun) {
                break;
            }

            $signalId  = (string)($signal['id'] ?? '');
            $pattern   = (string)($signal['pattern_algorithm'] ?? '');
            $side      = (string)($signal['side'] ?? '');

            if ($signalId === '') {
                $counts['errors']++;
                continue;
            }

            // Filter by allowed patterns and sides
            if (!in_array($pattern, $allowedPatterns, true) || !in_array($side, $allowedSides, true)) {
                $counts['skipped_pattern']++;
                continue;
            }

            $relPath = 'storage/virtual_signals/' . $signalId . '.json';

            // Skip if already mirrored with a real AI decision
            if ($this->state->fileExists($relPath)) {
                $existing = $this->state->readJson($relPath);
                if (isset($existing['ai_decision']) && $existing['ai_decision'] !== null) {
                    $counts['skipped_already_mirrored']++;
                    $counts['processed']++;
                    $processed++;
                    continue;
                }
            }

            try {
                $virtualSignal = $this->buildVirtualSignal($signal);
                $this->state->writeJson($relPath, $virtualSignal);
                $counts['newly_mirrored']++;
            } catch (\Throwable $e) {
                $counts['errors']++;
            }

            $counts['processed']++;
            $processed++;
        }

        return $counts;
    }

    /**
     * Build a virtual signal record and evaluate it via the AI provider.
     *
     * @param  array<string,mixed> $signal
     * @return array<string,mixed>
     */
    private function buildVirtualSignal(array $signal): array
    {
        $signalId  = (string)($signal['id'] ?? '');
        $symbol    = (string)($signal['symbol'] ?? '');
        $side      = (string)($signal['side'] ?? '');
        $pattern   = (string)($signal['pattern_algorithm'] ?? '');
        $signalTs  = isset($signal['created_ts']) ? (int)$signal['created_ts'] : 0;

        // Build canonical input for AI evaluation
        $input = [
            'symbol'             => $symbol,
            'side'               => $side,
            'pattern_algorithm'  => $pattern,
            'signal_ts'          => $signalTs,
            'confidence_score'   => (float)($signal['pattern_confidence'] ?? $signal['analyzer_score'] ?? 0.0),
            'entry_quality_score'=> (float)($signal['entry_quality_score'] ?? 0.0),
            'analyzer_score'     => (float)($signal['analyzer_score'] ?? 0.0),
            'confirmation_score' => (float)($signal['confirmation_score'] ?? 0.0),
            'trend_bias'         => (string)($signal['trend_bias'] ?? ''),
            'signal_mode'        => (string)($signal['signal_mode'] ?? ''),
        ];

        $aiResult = $this->provider->evaluate($input);

        // Determine what the live system decided
        $liveDecision = $this->resolveLiveDecision($signal);

        $agreement = ($liveDecision !== 'waiting')
            ? ($aiResult['decision'] === ($liveDecision === 'entered' ? 'enter' : 'skip') ? 'agree' : 'disagree')
            : 'pending';

        return [
            'source_signal_id'   => $signalId,
            'symbol'             => $symbol,
            'side'               => $side,
            'pattern_algorithm'  => $pattern,
            'signal_ts'          => $signalTs,
            'ai_decision'        => $aiResult['decision'],
            'ai_confidence'      => $aiResult['confidence'],
            'ai_quality_score'   => $aiResult['quality_score'],
            'ai_reasons'         => $aiResult['reasons'],
            'live_decision'      => $liveDecision,
            'agreement'          => $agreement,
            'mirrored_at'        => time(),
            'provider'           => $this->provider->getName(),
            'input_snapshot'     => $input,
        ];
    }

    private function resolveLiveDecision(array $signal): string
    {
        $status = (string)($signal['status'] ?? '');
        if (in_array($status, ['active', 'open', 'entered'], true)) {
            return 'entered';
        }
        if (in_array($status, ['skipped', 'rejected', 'expired'], true)) {
            return 'skipped';
        }
        return 'waiting';
    }
}
