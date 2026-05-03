<?php

declare(strict_types=1);

/**
 * Dynamic Strategies — Abstract Source Adapter
 *
 * Shared helpers for all source adapters. Concrete adapters extend this class
 * and implement id(), enabled(), and collect().
 *
 * SAFETY: Read-only. Must not modify source strategy storage.
 */

namespace Modules\Strategy\DynamicStrategies\Sources;

abstract class AbstractDynamicSourceAdapter implements DynamicSourceAdapterInterface
{
    protected string $repoRoot;

    // Directional context types recognised by the Dynamic pipeline.
    protected const USEFUL_CONTEXT_TYPES = [
        'active_downtrend_no_stabilization',
        'trend_bearish_side_long_mismatch',
        'active_falling_knife',
        'falling_knife_reject',
        'fresh_dump_after_reclaim',
        'fresh_lower_low_after_reclaim',
        'fast_red_candle_after_setup',
        'failed_reclaim',
        'reclaim_lost',
        'neckline_lost',
        'reclaim_after_flat_not_confirmed',
        'base_support_broken',
        'pending_invalidated_fresh_dump',
        'pending_invalidated_reclaim_lost',
        'pending_invalidated_base_support_broken',
        'pending_invalidated_falling_knife',
        'pending_invalidated_context_deteriorated',
        'early_fail_closed',
        'emergency_stop_closed',
        'deep_loss_closed',
        'missed_early_fail',
    ];

    // Reject / invalidation reason → canonical Dynamic context_type
    protected const REASON_TO_CONTEXT_TYPE = [
        'active_downtrend_no_stabilization'       => 'active_downtrend_no_stabilization',
        'trend_bearish_side_long_mismatch'        => 'trend_bearish_side_long_mismatch',
        'active_falling_knife'                    => 'active_falling_knife',
        'falling_knife_reject'                    => 'falling_knife_reject',
        'falling_knife'                           => 'falling_knife_reject',
        'fresh_dump_after_reclaim'                => 'fresh_dump_after_reclaim',
        'fresh_dump'                              => 'fresh_dump_after_reclaim',
        'fresh_lower_low_after_reclaim'           => 'fresh_lower_low_after_reclaim',
        'fresh_lower_low'                         => 'fresh_lower_low_after_reclaim',
        'fast_red_candle_after_setup'             => 'fast_red_candle_after_setup',
        'fast_red_candle'                         => 'fast_red_candle_after_setup',
        'failed_reclaim'                          => 'failed_reclaim',
        'reclaim_lost'                            => 'reclaim_lost',
        'neckline_lost'                           => 'neckline_lost',
        'reclaim_after_flat_not_confirmed'        => 'reclaim_after_flat_not_confirmed',
        'base_support_broken'                     => 'base_support_broken',
        'support_broken'                          => 'base_support_broken',
        'pending_invalidated_fresh_dump'          => 'pending_invalidated_fresh_dump',
        'pending_invalidated_reclaim_lost'        => 'pending_invalidated_reclaim_lost',
        'pending_invalidated_base_support_broken' => 'pending_invalidated_base_support_broken',
        'pending_invalidated_falling_knife'       => 'pending_invalidated_falling_knife',
        'pending_invalidated_context_deteriorated' => 'pending_invalidated_context_deteriorated',
        'early_fail_closed'                       => 'early_fail_closed',
        'emergency_stop_closed'                   => 'emergency_stop_closed',
        'deep_loss_closed'                        => 'deep_loss_closed',
        'missed_early_fail'                       => 'missed_early_fail',
    ];

    public function __construct(string $repoRoot)
    {
        $this->repoRoot = rtrim($repoRoot, '/');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Read a JSON file; return $default on any failure.
     */
    protected function readJsonFile(string $absPath, mixed $default = []): mixed
    {
        if (!is_file($absPath) || !is_readable($absPath)) {
            return $default;
        }
        $raw = @file_get_contents($absPath);
        if ($raw === false || trim($raw) === '') {
            return $default;
        }
        $decoded = @json_decode($raw, true);
        return ($decoded !== null) ? $decoded : $default;
    }

    /**
     * Map a reject / invalidation reason string to a canonical Dynamic context_type.
     * Returns null when the reason does not match any directional context.
     */
    protected function reasonToContextType(string $reason): ?string
    {
        return self::REASON_TO_CONTEXT_TYPE[$reason] ?? null;
    }

    /**
     * Whether a context_type is recognised as directionally useful.
     */
    protected function isUsefulContextType(string $ctxType): bool
    {
        return in_array($ctxType, self::USEFUL_CONTEXT_TYPES, true);
    }

    /**
     * Build a deterministic context_id so the same logical event is not
     * double-counted across multiple Dynamic ticks.
     *
     * Primary key when source_signal_id is available:
     *   md5(source_strategy + ':' + source_signal_id + ':' + context_type)
     *
     * Fallback key (minute-bucketed):
     *   md5(source_strategy + ':' + symbol + ':' + context_type + ':' + minuteBucket)
     */
    protected function buildContextId(
        string  $sourceStrategy,
        string  $symbol,
        string  $contextType,
        ?string $sourceSignalId,
        ?string $observedAt
    ): string {
        if ($sourceSignalId !== null && $sourceSignalId !== '') {
            return 'dctx_' . substr(md5($sourceStrategy . ':' . $sourceSignalId . ':' . $contextType), 0, 16);
        }
        // Minute-bucket: truncate to nearest minute
        $ts = $observedAt !== null ? (int)strtotime($observedAt) : 0;
        $bucket = $ts > 0 ? (int)($ts / 60) : 0;
        return 'dctx_' . substr(md5($sourceStrategy . ':' . $symbol . ':' . $contextType . ':' . $bucket), 0, 16);
    }

    /**
     * Build the base context record skeleton common to all sources.
     */
    protected function makeContext(
        string  $contextType,
        string  $symbol,
        string  $sourceStrategy,
        string  $sourceModule,
        string  $sourceArtifact,
        string  $observedAt,
        ?string $sourceSignalId  = null,
        string  $sourceSide      = 'long',
        string  $dynamicSideHint = 'short'
    ): array {
        return [
            'context_id'         => $this->buildContextId($sourceStrategy, $symbol, $contextType, $sourceSignalId, $observedAt),
            'source_strategy'    => $sourceStrategy,
            'source_module'      => $sourceModule,
            'source_artifact'    => $sourceArtifact,
            'source_signal_id'   => $sourceSignalId,
            'symbol'             => $symbol,
            'source_side'        => $sourceSide,
            'observed_at'        => $observedAt,
            'context_type'       => $contextType,
            'dynamic_side_hint'  => $dynamicSideHint,
            'current_price'      => null,
            'entry_price'        => null,
            'neckline_level'     => null,
            'reclaim_level'      => null,
            'base_low'           => null,
            'base_high'          => null,
            'setup_class'        => null,
            'synthetic_quality_score' => null,
            'candidate_quality_score' => null,
            'warnings'           => [],
            'reason_codes'       => [],
            'failed_stage'       => null,
            'reject_reason'      => null,
            'close_roi'          => null,
            'close_reason'       => null,
            'close_guard'        => null,
            'diagnostic_only'    => true,
        ];
    }
}
