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
    private AiShadowJournal      $journal;
    /** @var array<string,mixed> */
    private array $config;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(
        AiShadowStateManager $state,
        AiProviderInterface  $provider,
        AiShadowJournal      $journal,
        array                $config
    ) {
        $this->state    = $state;
        $this->provider = $provider;
        $this->journal  = $journal;
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
                $this->journal->record($signalId, 'provider_error', [
                    'symbol'            => (string)($signal['symbol'] ?? ''),
                    'pattern_algorithm' => $pattern,
                    'error'             => $e->getMessage(),
                    'provider'          => $this->provider->getName(),
                ]);
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

        // Journal: signal seen
        $this->journal->record($signalId, 'signal_seen', [
            'symbol'            => $symbol,
            'side'              => $side,
            'pattern_algorithm' => $pattern,
            'signal_ts'         => $signalTs,
        ]);

        // Build canonical input packet for AI evaluation
        $input = $this->buildCanonicalInput($signal);

        // Journal: input built
        $this->journal->record($signalId, 'ai_input_built', [
            'symbol'            => $symbol,
            'pattern_algorithm' => $pattern,
            'provider'          => $this->provider->getName(),
            'input_keys'        => array_keys($input),
        ]);

        // Journal: request sent
        $this->journal->record($signalId, 'ai_request_sent', [
            'symbol'            => $symbol,
            'pattern_algorithm' => $pattern,
            'provider'          => $this->provider->getName(),
            'model'             => (string)($input['model'] ?? ''),
        ]);

        $aiResult = $this->provider->evaluate($input);

        // Strip raw_response from the input snapshot (too large) — keep it only in the journal
        $inputSnapshot = $input;
        unset($inputSnapshot['ohlcv_window']); // large, omit from virtual signal file

        // Journal: response received
        $journalPayload = [
            'symbol'            => $symbol,
            'pattern_algorithm' => $pattern,
            'provider'          => $this->provider->getName(),
            'decision'          => $aiResult['decision'],
            'confidence'        => $aiResult['confidence'],
            'quality_score'     => $aiResult['quality_score'],
            'risk_penalty'      => $aiResult['risk_penalty'],
            'reasons'           => $aiResult['reasons'],
        ];
        if (isset($aiResult['provider_error'])) {
            $journalPayload['provider_error'] = $aiResult['provider_error'];
        }
        $this->journal->record($signalId, 'ai_response_received', $journalPayload);

        // Journal: decision event
        $decisionEvent = 'ai_decision_' . $aiResult['decision'];
        $this->journal->record($signalId, $decisionEvent, [
            'symbol'            => $symbol,
            'side'              => $side,
            'pattern_algorithm' => $pattern,
            'confidence'        => $aiResult['confidence'],
            'quality_score'     => $aiResult['quality_score'],
            'reasons'           => $aiResult['reasons'],
            'recommended_action'=> $aiResult['recommended_action'] ?? $aiResult['decision'],
            'hold_or_close_bias'=> $aiResult['hold_or_close_bias'] ?? 'none',
            'runner_probability_estimate' => $aiResult['runner_probability_estimate'] ?? 0.0,
            'reject_risk_estimate'        => $aiResult['reject_risk_estimate']        ?? 0.0,
        ]);

        // Determine what the live system decided
        $liveDecision = $this->resolveLiveDecision($signal);

        $agreement = ($liveDecision !== 'waiting')
            ? ($aiResult['decision'] === ($liveDecision === 'entered' ? 'enter' : 'skip') ? 'agree' : 'disagree')
            : 'pending';

        return [
            'source_signal_id'            => $signalId,
            'symbol'                      => $symbol,
            'side'                        => $side,
            'pattern_algorithm'           => $pattern,
            'signal_ts'                   => $signalTs,
            'ai_decision'                 => $aiResult['decision'],
            'ai_confidence'               => $aiResult['confidence'],
            'ai_quality_score'            => $aiResult['quality_score'],
            'ai_risk_penalty'             => $aiResult['risk_penalty'],
            'ai_reasons'                  => $aiResult['reasons'],
            'ai_recommended_action'       => $aiResult['recommended_action'] ?? $aiResult['decision'],
            'ai_hold_or_close_bias'       => $aiResult['hold_or_close_bias'] ?? 'none',
            'ai_runner_probability'       => $aiResult['runner_probability_estimate'] ?? 0.0,
            'ai_reject_risk'              => $aiResult['reject_risk_estimate'] ?? 0.0,
            'live_decision'               => $liveDecision,
            'agreement'                   => $agreement,
            'mirrored_at'                 => time(),
            'provider'                    => $this->provider->getName(),
            'provider_error'              => $aiResult['provider_error'] ?? null,
            'input_snapshot'              => $inputSnapshot,
        ];
    }

    /**
     * Build a canonical structured input packet for the AI provider.
     *
     * @param  array<string,mixed> $signal
     * @return array<string,mixed>
     */
    private function buildCanonicalInput(array $signal): array
    {
        $input = [
            'symbol'              => (string)($signal['symbol']            ?? ''),
            'side'                => (string)($signal['side']              ?? ''),
            'pattern_algorithm'   => (string)($signal['pattern_algorithm'] ?? ''),
            'signal_ts'           => isset($signal['created_ts']) ? (int)$signal['created_ts'] : 0,

            // Scores
            'confidence_score'    => (float)($signal['pattern_confidence']  ?? $signal['analyzer_score']    ?? 0.0),
            'entry_quality_score' => (float)($signal['entry_quality_score'] ?? 0.0),
            'analyzer_score'      => (float)($signal['analyzer_score']      ?? 0.0),
            'confirmation_score'  => (float)($signal['confirmation_score']  ?? 0.0),

            // Context / regime
            'trend_bias'          => (string)($signal['trend_bias']         ?? ''),
            'signal_mode'         => (string)($signal['signal_mode']        ?? ''),
            'regime'              => (string)($signal['regime']             ?? ''),
            'market_phase'        => (string)($signal['market_phase']       ?? ''),

            // Volatility/noise
            'atr'                 => (float)($signal['atr']                 ?? 0.0),
            'volatility'          => (float)($signal['volatility']          ?? 0.0),
            'noise_score'         => (float)($signal['noise_score']         ?? 0.0),

            // Parser diagnostics if available
            'parser_diagnostics'  => is_array($signal['diagnostics'] ?? null)
                ? $signal['diagnostics']
                : [],

            // Entry zone if available
            'entry_zone_low'      => (float)($signal['entry_zone_low']      ?? 0.0),
            'entry_zone_high'     => (float)($signal['entry_zone_high']     ?? 0.0),
            'entry_price'         => (float)($signal['entry']['price']      ?? $signal['entry_price'] ?? 0.0),

            // OHLCV window (last N candles) if available
            'ohlcv_window'        => is_array($signal['ohlcv'] ?? null)
                ? $signal['ohlcv']
                : [],
        ];

        // Coin passport guidance if available
        if (is_array($signal['passport'] ?? null)) {
            $input['coin_passport'] = [
                'trend_bias'     => (string)($signal['passport']['trend_bias']     ?? ''),
                'volatility_rank'=> (float)($signal['passport']['volatility_rank'] ?? 0.0),
                'guidance'       => (string)($signal['passport']['guidance']       ?? ''),
            ];
        }

        return $input;
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
