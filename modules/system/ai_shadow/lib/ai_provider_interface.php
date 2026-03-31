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
     *   recommended_action: string
     * }
     */
    public function evaluate(array $input): array;
}
