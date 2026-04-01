<?php
declare(strict_types=1);

/**
 * AiProviderInterface
 *
 * Contract that every AI provider must fulfill.
 * Phase 1 uses AiProviderMock; real provider plugged in later.
 */
interface AiProviderInterface
{
    public function getName(): string;

    public function isAvailable(): bool;

    /**
     * Evaluate a structured input and return a decision.
     *
     * @param  array<string,mixed> $input  Canonical structured input
     * @return array{
     *   decision: string,
     *   confidence: float,
     *   quality_score: float,
     *   risk_penalty: float,
     *   reasons: array<int,string>,
     *   recommended_action: string,
     *   hold_or_close_bias: string,
     *   runner_probability_estimate: float,
     *   reject_risk_estimate: float
     * }
     */
    public function evaluate(array $input): array;

    /**
     * Test provider connectivity and credential validity.
     * Performs a minimal, harmless test call (no trading).
     *
     * @return array{ok:bool,status:string,error?:string,latency_ms?:int,model?:string,provider:string}
     */
    public function testConnection(): array;
}
