<?php
declare(strict_types=1);

/**
 * AiProviderOpenAi
 *
 * Real OpenAI provider for AI Shadow research module.
 *
 * Credentials are fetched from KeyCenter using credential_id as the account
 * name under service 'openai'. No raw API keys are stored in module config.
 *
 * Output is strict JSON only. Falls back to a disabled/no-decision state on
 * any provider error so live trading is never affected.
 */
final class AiProviderOpenAi implements AiProviderInterface
{
    private string $credentialId;
    private string $model;
    private bool   $available = false;
    private string $apiKey    = '';

    private const DEFAULT_MODEL    = 'gpt-4o-mini';
    private const REQUEST_TIMEOUT  = 20;
    private const MAX_TOKENS       = 512;

    /**
     * @param array<string,mixed> $config  Module config (provider, model, credential_id)
     */
    public function __construct(array $config)
    {
        $this->credentialId = (string)($config['credential_id'] ?? '');
        $this->model        = (string)($config['model']         ?? self::DEFAULT_MODEL);
        if ($this->model === '') {
            $this->model = self::DEFAULT_MODEL;
        }

        $this->apiKey    = $this->resolveApiKey();
        $this->available = $this->apiKey !== '';
    }

    public function getName(): string
    {
        return 'openai';
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * Evaluate a canonical AI input packet and return a structured decision.
     *
     * @param  array<string,mixed> $input
     * @return array{decision:string,confidence:float,quality_score:float,risk_penalty:float,reasons:array<int,string>,recommended_action:string,hold_or_close_bias:string,runner_probability_estimate:float,reject_risk_estimate:float,raw_response?:string,provider_error?:string}
     */
    public function evaluate(array $input): array
    {
        if (!$this->available) {
            return $this->noDecisionResult('provider_unavailable');
        }

        $prompt  = $this->buildPrompt($input);
        $rawText = '';

        try {
            $rawText = $this->callOpenAi($prompt);
        } catch (\Throwable $e) {
            return $this->noDecisionResult('api_error: ' . $e->getMessage());
        }

        $parsed = $this->parseStrictJson($rawText);
        if ($parsed === null) {
            return $this->noDecisionResult('parse_error', $rawText);
        }

        return $this->normalizeOutput($parsed, $rawText);
    }

    /**
     * Test provider connectivity with a minimal, harmless API call.
     * Validates credential lookup → provider init → model request path.
     * Stores nothing about live trading. Safe to call at any time.
     *
     * @return array{ok:bool,status:string,error?:string,latency_ms:int,model:string,provider:string}
     */
    public function testConnection(): array
    {
        $startMs = (int)round(microtime(true) * 1000);

        if ($this->credentialId === '') {
            return [
                'ok'      => false,
                'status'  => 'no_credential_id',
                'error'   => 'credential_id is not configured',
                'latency_ms' => 0,
                'model'   => $this->model,
                'provider'=> 'openai',
            ];
        }

        if (!$this->available || $this->apiKey === '') {
            return [
                'ok'      => false,
                'status'  => 'credential_resolve_failed',
                'error'   => 'API key could not be resolved from KeyCenter (credential_id=' . $this->credentialId . ')',
                'latency_ms' => 0,
                'model'   => $this->model,
                'provider'=> 'openai',
            ];
        }

        // Make a minimal, harmless test call (model list endpoint)
        try {
            $ch = curl_init('https://api.openai.com/v1/models/' . urlencode($this->model));
            if ($ch === false) {
                throw new \RuntimeException('curl_init failed');
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $this->apiKey,
                ],
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            $latencyMs = (int)round(microtime(true) * 1000) - $startMs;

            if ($response === false || $curlErr !== '') {
                return [
                    'ok'         => false,
                    'status'     => 'curl_error',
                    'error'      => $curlErr,
                    'latency_ms' => $latencyMs,
                    'model'      => $this->model,
                    'provider'   => 'openai',
                ];
            }

            if ($httpCode === 200) {
                return [
                    'ok'         => true,
                    'status'     => 'connected',
                    'latency_ms' => $latencyMs,
                    'model'      => $this->model,
                    'provider'   => 'openai',
                ];
            }

            $decoded = json_decode((string)$response, true);
            $errMsg  = (string)(($decoded['error']['message'] ?? null) ?: ('HTTP ' . $httpCode));
            return [
                'ok'         => false,
                'status'     => 'api_error',
                'error'      => $errMsg,
                'latency_ms' => $latencyMs,
                'model'      => $this->model,
                'provider'   => 'openai',
            ];
        } catch (\Throwable $e) {
            return [
                'ok'         => false,
                'status'     => 'exception',
                'error'      => $e->getMessage(),
                'latency_ms' => (int)round(microtime(true) * 1000) - $startMs,
                'model'      => $this->model,
                'provider'   => 'openai',
            ];
        }
    }

    // =========================================================================
    // Private
    // =========================================================================

    private function resolveApiKey(): string
    {
        if ($this->credentialId === '') {
            return '';
        }

        try {
            if (!class_exists('\Core\KeyCenter\KeyCenter')) {
                return '';
            }
            $kc   = \Core\KeyCenter\KeyCenter::instance();
            $creds = $kc->getCredentials('openai', $this->credentialId);
            return (string)($creds['api_key'] ?? $creds['key'] ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    private function buildPrompt(array $input): string
    {
        $symbol  = (string)($input['symbol']           ?? '');
        $side    = (string)($input['side']             ?? 'short');
        $pattern = (string)($input['pattern_algorithm'] ?? '');
        $conf    = number_format((float)($input['confidence_score'] ?? 0.0), 4);
        $quality = number_format((float)($input['entry_quality_score'] ?? 0.0), 4);
        $trend   = (string)($input['trend_bias']       ?? '');
        $regime  = (string)($input['signal_mode']      ?? '');

        // OHLCV summary if available
        $ohlcvSummary = '';
        if (!empty($input['ohlcv_window'])) {
            $candles = (array)$input['ohlcv_window'];
            $last    = end($candles);
            if (is_array($last)) {
                $ohlcvSummary = sprintf(
                    'Last candle: O=%.6f H=%.6f L=%.6f C=%.6f V=%.2f',
                    (float)($last['o'] ?? $last['open']  ?? 0),
                    (float)($last['h'] ?? $last['high']  ?? 0),
                    (float)($last['l'] ?? $last['low']   ?? 0),
                    (float)($last['c'] ?? $last['close'] ?? 0),
                    (float)($last['v'] ?? $last['volume'] ?? 0)
                );
            }
        }

        // Active trade context if post-entry
        $tradeSummary = '';
        if (!empty($input['live_trade_state'])) {
            $t = (array)$input['live_trade_state'];
            $tradeSummary = sprintf(
                'Live trade ROI so far: %.4f%%, MFE: %.4f%%, MAE: %.4f%%',
                (float)($t['roi'] ?? 0) * 100,
                (float)($t['mfe'] ?? 0) * 100,
                (float)($t['mae'] ?? 0) * 100
            );
        }

        $systemMsg = 'You are an AI trading research assistant for a crypto short-selling shadow module. '
            . 'You evaluate SHORT trade signals and decide whether to virtually enter, skip, hold, or close. '
            . 'You have ZERO real trading authority. Your output is stored for research only. '
            . 'Respond ONLY with valid JSON matching the required schema. No extra text.';

        $userMsg = "Evaluate this SHORT trade signal for research:\n\n"
            . "Symbol: {$symbol}\n"
            . "Side: {$side}\n"
            . "Pattern: {$pattern}\n"
            . "Confidence score: {$conf}\n"
            . "Quality score: {$quality}\n"
            . "Trend bias: {$trend}\n"
            . "Regime: {$regime}\n";

        if ($ohlcvSummary) {
            $userMsg .= "OHLCV: {$ohlcvSummary}\n";
        }
        if ($tradeSummary) {
            $userMsg .= "Trade context: {$tradeSummary}\n";
        }

        $userMsg .= "\nRespond with ONLY this JSON schema:\n"
            . "{\n"
            . '  "decision": "enter"|"skip"|"hold"|"close",' . "\n"
            . '  "confidence": 0.0-1.0,' . "\n"
            . '  "quality_score": 0.0-1.0,' . "\n"
            . '  "risk_penalty": 0.0-1.0,' . "\n"
            . '  "reasons": ["reason1", "reason2"],' . "\n"
            . '  "recommended_action": "enter"|"skip"|"hold"|"close",' . "\n"
            . '  "hold_or_close_bias": "hold"|"close"|"none",' . "\n"
            . '  "runner_probability_estimate": 0.0-1.0,' . "\n"
            . '  "reject_risk_estimate": 0.0-1.0' . "\n"
            . "}\n"
            . "No markdown, no extra text — pure JSON only.";

        return json_encode([
            'system' => $systemMsg,
            'user'   => $userMsg,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function callOpenAi(string $promptJson): string
    {
        $promptData = json_decode($promptJson, true);
        if (!is_array($promptData)) {
            throw new \RuntimeException('Invalid prompt JSON');
        }

        $payload = json_encode([
            'model'      => $this->model,
            'messages'   => [
                ['role' => 'system', 'content' => (string)($promptData['system'] ?? '')],
                ['role' => 'user',   'content' => (string)($promptData['user']   ?? '')],
            ],
            'max_tokens'  => self::MAX_TOKENS,
            'temperature' => 0.1,
        ]);

        if ($payload === false) {
            throw new \RuntimeException('JSON encode failed');
        }

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlErr !== '') {
            throw new \RuntimeException('curl error: ' . $curlErr);
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException('HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 200));
        }

        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Response JSON decode failed');
        }

        $content = (string)($decoded['choices'][0]['message']['content'] ?? '');
        if ($content === '') {
            throw new \RuntimeException('Empty content in response');
        }

        return $content;
    }

    private function parseStrictJson(string $rawText): ?array
    {
        // Strip markdown code fences if present
        $text = trim($rawText);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```\s*$/', '', $text) ?? $text;
        $text = trim($text);

        $data = json_decode($text, true);
        if (!is_array($data)) {
            return null;
        }

        // Require at minimum a 'decision' field
        if (!isset($data['decision'])) {
            return null;
        }

        return $data;
    }

    /**
     * @param  array<string,mixed> $parsed
     * @return array{decision:string,confidence:float,quality_score:float,risk_penalty:float,reasons:array<int,string>,recommended_action:string,hold_or_close_bias:string,runner_probability_estimate:float,reject_risk_estimate:float,raw_response:string}
     */
    private function normalizeOutput(array $parsed, string $rawText): array
    {
        $validDecisions = ['enter', 'skip', 'hold', 'close'];
        $decision = strtolower((string)($parsed['decision'] ?? 'skip'));
        if (!in_array($decision, $validDecisions, true)) {
            $decision = 'skip';
        }

        $reasons = [];
        if (isset($parsed['reasons']) && is_array($parsed['reasons'])) {
            foreach ($parsed['reasons'] as $r) {
                $reasons[] = (string)$r;
            }
        }

        $holdBias = strtolower((string)($parsed['hold_or_close_bias'] ?? 'none'));
        if (!in_array($holdBias, ['hold', 'close', 'none'], true)) {
            $holdBias = 'none';
        }

        return [
            'decision'                    => $decision,
            'confidence'                  => min(1.0, max(0.0, (float)($parsed['confidence']   ?? 0.5))),
            'quality_score'               => min(1.0, max(0.0, (float)($parsed['quality_score'] ?? 0.5))),
            'risk_penalty'                => min(1.0, max(0.0, (float)($parsed['risk_penalty']  ?? 0.0))),
            'reasons'                     => $reasons,
            'recommended_action'          => $decision,
            'hold_or_close_bias'          => $holdBias,
            'runner_probability_estimate' => min(1.0, max(0.0, (float)($parsed['runner_probability_estimate'] ?? 0.5))),
            'reject_risk_estimate'        => min(1.0, max(0.0, (float)($parsed['reject_risk_estimate']        ?? 0.5))),
            'raw_response'                => $rawText,
        ];
    }

    /**
     * @return array{decision:string,confidence:float,quality_score:float,risk_penalty:float,reasons:array<int,string>,recommended_action:string,hold_or_close_bias:string,runner_probability_estimate:float,reject_risk_estimate:float,provider_error:string}
     */
    private function noDecisionResult(string $reason, string $rawText = ''): array
    {
        $result = [
            'decision'                    => 'skip',
            'confidence'                  => 0.0,
            'quality_score'               => 0.0,
            'risk_penalty'                => 1.0,
            'reasons'                     => ['provider_unavailable', $reason],
            'recommended_action'          => 'skip',
            'hold_or_close_bias'          => 'none',
            'runner_probability_estimate' => 0.0,
            'reject_risk_estimate'        => 1.0,
            'provider_error'              => $reason,
        ];
        if ($rawText !== '') {
            $result['raw_response'] = $rawText;
        }
        return $result;
    }
}
