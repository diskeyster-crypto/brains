<?php
declare(strict_types=1);

/**
 * Pattern Detector Interface — Smart Brain Analyzer V1
 *
 * Each pattern algorithm must implement this interface.
 */
interface PatternDetectorInterface
{
    /**
     * Get the algorithm name (e.g. 'double_bottom', 'double_top', 'pullback_trend_continue').
     */
    public function getName(): string;

    /**
     * Detect the pattern in the given price history.
     *
     * @param array<int,array{ts_unix:int,price:float}> $history  Price history points
     * @return array{detected:bool,confidence:float,trend_bias:string}|null
     *   - detected: whether the pattern was found
     *   - confidence: 0.0..1.0
     *   - trend_bias: 'up' or 'down'
     *   Returns null if pattern not detected (same as detected=false).
     */
    public function detect(array $history): ?array;
}
