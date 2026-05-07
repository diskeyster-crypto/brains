<?php

declare(strict_types=1);

/**
 * Dynamic Strategies — ConfirmedContinuation Source Adapter
 *
 * Reads existing storage artifacts from the confirmed_continuation strategy module
 * and emits normalized Dynamic context records for directional signals.
 *
 * Reads (read-only — never modifies):
 *   1. {module_path}/storage/last_run.json
 *      Extracts: rejected_examples, obc_block_examples, late_entry_reject_examples,
 *                first_bounce_reject_examples, no_retest_reject_examples
 *
 *   2. {module_path}/storage/rejects.json
 *      Extracts: recent reject entries with directional context type mapping
 *
 *   3. {module_path}/storage/signals.json
 *      Extracts: stale signals for directional context
 *
 *   4. {bot_module_dir}/storage/trades/closed_trades.json
 *      Filter: strategy_id = 'confirmed_continuation'
 *      Extracts: deep_loss_closed (roi <= -30)
 *
 * Context type mappings for confirmed_continuation:
 *
 * Failed LONG continuation → short context:
 *   long_retest_failed         → dynamic_side_hint=short
 *   long_higher_low_broken     → dynamic_side_hint=short
 *   long_structure_lost        → dynamic_side_hint=short
 *   long_ask_wall_blocked      → dynamic_side_hint=short
 *   long_deep_loss_closed      → dynamic_side_hint=short
 *
 * Failed SHORT continuation → long context:
 *   short_retest_failed        → dynamic_side_hint=long
 *   short_lower_high_broken    → dynamic_side_hint=long
 *   short_structure_lost       → dynamic_side_hint=long
 *   short_bid_wall_blocked     → dynamic_side_hint=long
 *   short_deep_loss_closed     → dynamic_side_hint=long
 *
 * SAFETY:
 *   - Read-only. Does not write to any source strategy storage.
 *   - All returned contexts have diagnostic_only = true.
 *   - Does not open orders, does not call exchange APIs.
 */

namespace Modules\Strategy\DynamicStrategies\Sources;

final class ConfirmedContinuationSourceAdapter extends AbstractDynamicSourceAdapter
{
    private const SOURCE_STRATEGY = 'confirmed_continuation';
    private const SOURCE_MODULE   = 'modules/strategy/pattern/confirmed_continuation';

    private const MAX_PER_ARTIFACT = 50;
    private const DEEP_LOSS_ROI_THRESHOLD = -30.0;
    private const MAX_LAST_RUN_AGE_MINUTES = 480;

    // Map CC reject/fail reasons to Dynamic context types
    private const CC_REASON_TO_CONTEXT_TYPE = [
        // Long failures → short context
        'retest_not_held'                    => 'long_retest_failed',
        'no_higher_lows'                     => 'long_higher_low_broken',
        'entry_too_far_above_structure'      => 'long_structure_lost',
        'too_extended_from_local_base'       => 'long_structure_lost',
        'ob_soft_demote_wall_risk_long'      => 'long_ask_wall_blocked',
        'blowoff_candle_at_entry'            => 'long_structure_lost',
        // Short failures → long context
        'no_lower_highs'                     => 'short_lower_high_broken',
        'entry_too_far_below_structure'      => 'short_structure_lost',
        'too_extended_from_breakdown_base'   => 'short_structure_lost',
        'ob_soft_demote_wall_risk_short'     => 'short_bid_wall_blocked',
        'panic_dump_candle_at_entry'         => 'short_structure_lost',
    ];

    // Context types to dynamic side hints
    private const CC_CONTEXT_SIDE_HINTS = [
        'long_retest_failed'     => 'short',
        'long_higher_low_broken' => 'short',
        'long_structure_lost'    => 'short',
        'long_ask_wall_blocked'  => 'short',
        'long_deep_loss_closed'  => 'short',
        'short_retest_failed'    => 'long',
        'short_lower_high_broken'=> 'long',
        'short_structure_lost'   => 'long',
        'short_bid_wall_blocked' => 'long',
        'short_deep_loss_closed' => 'long',
    ];

    // These are the useful context types this adapter can emit
    private const CC_USEFUL_CONTEXT_TYPES = [
        'long_retest_failed',
        'long_higher_low_broken',
        'long_structure_lost',
        'long_ask_wall_blocked',
        'long_deep_loss_closed',
        'short_retest_failed',
        'short_lower_high_broken',
        'short_structure_lost',
        'short_bid_wall_blocked',
        'short_deep_loss_closed',
    ];

    public function id(): string
    {
        return self::SOURCE_STRATEGY;
    }

    public function enabled(array $config): bool
    {
        $srcConfig = $config['sources'][self::SOURCE_STRATEGY] ?? [];
        return (bool)($srcConfig['enabled'] ?? true);
    }

    public function collect(array $config): array
    {
        $srcConfig = $config['sources'][self::SOURCE_STRATEGY] ?? [];

        $modulePath   = $this->repoRoot . '/' . ltrim(
            (string)($srcConfig['module_path'] ?? self::SOURCE_MODULE), '/'
        );
        $botModuleDir = $this->repoRoot . '/' . ltrim(
            (string)($config['bot_module_dir'] ?? 'modules/bot'), '/'
        );

        $maxAgeMin = (int)($srcConfig['context_max_age_minutes'] ?? self::MAX_LAST_RUN_AGE_MINUTES);
        $maxItems  = (int)($srcConfig['max_items_per_run']       ?? 200);

        $contexts = [];
        $errors   = [];
        $stats    = [
            'enabled'                      => true,
            'files_read_total'             => 0,
            'files_missing_total'          => 0,
            'contexts_loaded_total'        => 0,
            'contexts_useful_total'        => 0,
            'contexts_skipped_noise_total' => 0,
            'errors_total'                 => 0,
        ];

        $now = time();

        // ── 1. confirmed_continuation/storage/last_run.json ───────────────────
        if ((bool)($srcConfig['read_last_run'] ?? true)) {
            $path = $modulePath . '/storage/last_run.json';
            $lr   = $this->readJsonFile($path, null);
            if ($lr === null) {
                $stats['files_missing_total']++;
            } else {
                $stats['files_read_total']++;
                $tickAt  = (string)($lr['finished_at'] ?? $lr['started_at'] ?? date('c'));
                $tickTs  = (int)strtotime($tickAt);
                $tooOld  = $maxAgeMin > 0 && $tickTs > 0 && ($now - $tickTs) > ($maxAgeMin * 60);

                if (!$tooOld) {
                    foreach ([
                        'rejected_examples'         => null,
                        'obc_block_examples'        => 'ob_soft_demote_wall_risk',
                        'late_entry_reject_examples'=> null,
                        'first_bounce_reject_examples' => null,
                        'no_retest_reject_examples' => null,
                    ] as $key => $forceReason) {
                        $examples = (array)($lr[$key] ?? []);
                        $new = $this->extractRejectExamples($examples, $tickAt, "last_run.{$key}", $forceReason);
                        $contexts = array_merge($contexts, $new);
                        $stats['contexts_loaded_total'] += count($new);
                    }
                }
            }
        }

        // ── 2. confirmed_continuation/storage/rejects.json ────────────────────
        if ((bool)($srcConfig['read_rejects'] ?? true)) {
            $path    = $modulePath . '/storage/rejects.json';
            $rejects = $this->readJsonFile($path, null);
            if ($rejects === null) {
                $stats['files_missing_total']++;
            } else {
                $stats['files_read_total']++;
                $new = $this->extractRejectFileContexts((array)$rejects, $now, $maxAgeMin);
                $contexts = array_merge($contexts, $new);
                $stats['contexts_loaded_total'] += count($new);
            }
        }

        // ── 3. confirmed_continuation/storage/signals.json — stale signals ───
        if ((bool)($srcConfig['read_signals'] ?? true)) {
            $path    = $modulePath . '/storage/signals.json';
            $signals = $this->readJsonFile($path, null);
            if ($signals === null) {
                $stats['files_missing_total']++;
            } else {
                $stats['files_read_total']++;
                $new = $this->extractStaleSignalContexts((array)$signals, $now, $maxAgeMin);
                $contexts = array_merge($contexts, $new);
                $stats['contexts_loaded_total'] += count($new);
            }
        }

        // ── 4. bot/storage/trades/closed_trades.json ──────────────────────────
        if ((bool)($srcConfig['read_closed_trades'] ?? true)) {
            $path   = $botModuleDir . '/storage/trades/closed_trades.json';
            $trades = $this->readJsonFile($path, null);
            if ($trades === null) {
                $stats['files_missing_total']++;
            } else {
                $stats['files_read_total']++;
                $new = $this->extractClosedTradeContexts((array)$trades, $maxAgeMin, $now);
                $contexts = array_merge($contexts, $new);
                $stats['contexts_loaded_total'] += count($new);
            }
        }

        // ── Filter to useful only + cap ───────────────────────────────────────
        $usefulContexts = [];
        $noiseSkipped   = 0;
        foreach ($contexts as $ctx) {
            if (in_array((string)($ctx['context_type'] ?? ''), self::CC_USEFUL_CONTEXT_TYPES, true)) {
                $usefulContexts[] = $ctx;
            } else {
                $noiseSkipped++;
            }
        }
        $stats['contexts_skipped_noise_total'] = $noiseSkipped;

        if (count($usefulContexts) > $maxItems) {
            usort($usefulContexts, static function (array $a, array $b): int {
                $ta = isset($a['observed_at']) ? (int)strtotime((string)$a['observed_at']) : 0;
                $tb = isset($b['observed_at']) ? (int)strtotime((string)$b['observed_at']) : 0;
                return $tb - $ta;
            });
            $usefulContexts = array_slice($usefulContexts, 0, $maxItems);
        }

        $stats['contexts_useful_total'] = count($usefulContexts);
        $stats['errors_total']          = count($errors);

        return [
            'contexts' => $usefulContexts,
            'stats'    => $stats,
            'errors'   => $errors,
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Extract contexts from reject example arrays (last_run.json arrays).
     */
    private function extractRejectExamples(
        array   $examples,
        string  $tickAt,
        string  $artifact,
        ?string $forceReason = null
    ): array {
        $out = [];
        foreach (array_slice($examples, 0, self::MAX_PER_ARTIFACT) as $ex) {
            if (!is_array($ex) || empty($ex['symbol'])) {
                continue;
            }
            $symbol = (string)$ex['symbol'];
            $side   = (string)($ex['side'] ?? 'long');
            $reason = $forceReason ?? (string)($ex['reject_reason'] ?? $ex['reason'] ?? '');

            // Determine context type from reason + side
            $ctxType = $this->ccReasonToContextType($reason, $side);
            if ($ctxType === null) {
                continue;
            }

            $sideHint = self::CC_CONTEXT_SIDE_HINTS[$ctxType] ?? ($side === 'long' ? 'short' : 'long');

            $ctx = $this->makeCCContext(
                $ctxType, $symbol, $side, $sideHint, $tickAt, null, $artifact
            );
            $ctx['reject_reason']         = $reason;
            $ctx['failed_stage']          = $ex['failed_stage']           ?? null;
            $ctx['setup_class']           = $ex['setup_class']            ?? null;
            $ctx['candidate_quality_score'] = isset($ex['final_candidate_score']) ? (float)$ex['final_candidate_score'] : null;
            $out[] = $ctx;
        }
        return $out;
    }

    /**
     * Extract contexts from storage/rejects.json (full reject records).
     */
    private function extractRejectFileContexts(array $rejects, int $now, int $maxAgeMin): array
    {
        $out    = [];
        $cutoff = $maxAgeMin > 0 ? $now - ($maxAgeMin * 60) : 0;

        foreach (array_slice(array_reverse($rejects), 0, self::MAX_PER_ARTIFACT) as $rec) {
            if (!is_array($rec) || empty($rec['symbol'])) {
                continue;
            }
            $symbol     = (string)$rec['symbol'];
            $side       = (string)($rec['side'] ?? 'long');
            $rejectedAt = (string)($rec['rejected_at'] ?? '');
            $ts         = $rejectedAt !== '' ? (int)strtotime($rejectedAt) : 0;
            if ($cutoff > 0 && $ts > 0 && $ts < $cutoff) {
                continue;
            }

            $reason  = (string)($rec['reject_reason'] ?? '');
            $ctxType = $this->ccReasonToContextType($reason, $side);
            if ($ctxType === null) {
                continue;
            }

            $sideHint = self::CC_CONTEXT_SIDE_HINTS[$ctxType] ?? ($side === 'long' ? 'short' : 'long');
            $observedAt = $rejectedAt !== '' ? $rejectedAt : date('c');

            $ctx = $this->makeCCContext(
                $ctxType, $symbol, $side, $sideHint, $observedAt, null, 'rejects.json'
            );
            $ctx['reject_reason']           = $reason;
            $ctx['failed_stage']            = $rec['failed_stage']          ?? null;
            $ctx['setup_class']             = $rec['setup_class']           ?? null;
            $out[] = $ctx;
        }
        return $out;
    }

    /**
     * Extract contexts from stale signals (signals that have been marked stale).
     */
    private function extractStaleSignalContexts(array $signals, int $now, int $maxAgeMin): array
    {
        $out    = [];
        $cutoff = $maxAgeMin > 0 ? $now - ($maxAgeMin * 60) : 0;

        foreach (array_slice($signals, 0, self::MAX_PER_ARTIFACT) as $sig) {
            if (!is_array($sig)) {
                continue;
            }
            if (!($sig['stale'] ?? false)) {
                continue; // Only stale signals
            }

            $symbol     = (string)($sig['symbol']       ?? '');
            $side       = (string)($sig['side']         ?? 'long');
            $staleReason= (string)($sig['stale_reason'] ?? '');
            $detectedAt = (string)($sig['detected_at']  ?? '');
            $ts         = $detectedAt !== '' ? (int)strtotime($detectedAt) : 0;

            if ($symbol === '') {
                continue;
            }
            if ($cutoff > 0 && $ts > 0 && $ts < $cutoff) {
                continue;
            }

            // Stale signals = structure lost
            $ctxType  = $side === 'long' ? 'long_structure_lost' : 'short_structure_lost';
            $sideHint = $side === 'long' ? 'short' : 'long';

            $signalId   = (string)($sig['signal_id'] ?? '');
            $observedAt = $detectedAt !== '' ? $detectedAt : date('c');

            $ctx = $this->makeCCContext(
                $ctxType, $symbol, $side, $sideHint, $observedAt,
                $signalId !== '' ? $signalId : null,
                'signals.json'
            );
            $ctx['reject_reason']            = 'stale:' . $staleReason;
            $ctx['setup_class']              = $sig['setup_class']             ?? null;
            $ctx['candidate_quality_score']  = isset($sig['candidate_quality_score']) ? (float)$sig['candidate_quality_score'] : null;
            $ctx['entry_price']              = isset($sig['entry_price'])       ? (float)$sig['entry_price']       : null;
            $out[] = $ctx;
        }
        return $out;
    }

    /**
     * Extract contexts from bot closed trades for confirmed_continuation strategy.
     */
    private function extractClosedTradeContexts(array $trades, int $maxAgeMin, int $now): array
    {
        $out    = [];
        $cutoff = $maxAgeMin > 0 ? $now - ($maxAgeMin * 60) : 0;

        foreach ($trades as $trade) {
            if (!is_array($trade)) {
                continue;
            }
            $stratId = (string)($trade['strategy_id'] ?? $trade['owner_strategy'] ?? '');
            if ($stratId !== self::SOURCE_STRATEGY) {
                continue;
            }

            $symbol   = (string)($trade['symbol']    ?? '');
            $side     = (string)($trade['side']       ?? 'long');
            $roi      = isset($trade['roi'])           ? (float)$trade['roi']           : null;
            $closedAt = (string)($trade['closed_at']  ?? $trade['exit_time'] ?? '');
            $ts       = $closedAt !== '' ? (int)strtotime($closedAt) : 0;

            if ($symbol === '') {
                continue;
            }
            if ($cutoff > 0 && $ts > 0 && $ts < $cutoff) {
                continue;
            }
            if ($roi === null || $roi > self::DEEP_LOSS_ROI_THRESHOLD) {
                continue; // Only deep losses
            }

            $ctxType  = $side === 'long' ? 'long_deep_loss_closed' : 'short_deep_loss_closed';
            $sideHint = $side === 'long' ? 'short' : 'long';
            $signalId = (string)($trade['signal_id'] ?? '');
            $observedAt = $closedAt !== '' ? $closedAt : date('c');

            $ctx = $this->makeCCContext(
                $ctxType, $symbol, $side, $sideHint, $observedAt,
                $signalId !== '' ? $signalId : null,
                'closed_trades.json'
            );
            $ctx['entry_price']  = isset($trade['entry_price']) ? (float)$trade['entry_price'] : null;
            $ctx['current_price']= isset($trade['exit_price'])  ? (float)$trade['exit_price']  : null;
            $ctx['close_roi']    = $roi;
            $ctx['close_reason'] = $trade['close_reason'] ?? null;
            $out[] = $ctx;
        }
        return $out;
    }

    /**
     * Map a CC reject reason + side to a Dynamic context type.
     */
    private function ccReasonToContextType(string $reason, string $side): ?string
    {
        // Direct map
        if (isset(self::CC_REASON_TO_CONTEXT_TYPE[$reason])) {
            return self::CC_REASON_TO_CONTEXT_TYPE[$reason];
        }

        // Side-aware fallback
        if ($side === 'long') {
            if (str_contains($reason, 'retest')) {
                return 'long_retest_failed';
            }
            if (str_contains($reason, 'higher_low') || str_contains($reason, 'structure')) {
                return 'long_higher_low_broken';
            }
            if (str_contains($reason, 'wall') || str_contains($reason, 'ob_')) {
                return 'long_ask_wall_blocked';
            }
        } else {
            if (str_contains($reason, 'retest')) {
                return 'short_retest_failed';
            }
            if (str_contains($reason, 'lower_high') || str_contains($reason, 'structure')) {
                return 'short_lower_high_broken';
            }
            if (str_contains($reason, 'wall') || str_contains($reason, 'ob_')) {
                return 'short_bid_wall_blocked';
            }
        }

        return null;
    }

    /**
     * Build a CC-specific context record.
     */
    private function makeCCContext(
        string  $contextType,
        string  $symbol,
        string  $sourceSide,
        string  $dynamicSideHint,
        string  $observedAt,
        ?string $sourceSignalId,
        string  $artifact
    ): array {
        $ctx = $this->makeContext(
            $contextType,
            $symbol,
            self::SOURCE_STRATEGY,
            self::SOURCE_MODULE,
            $artifact,
            $observedAt,
            $sourceSignalId,
            $sourceSide,
            $dynamicSideHint
        );
        // Additional CC-specific fields
        $ctx['source_strategy']   = self::SOURCE_STRATEGY;
        $ctx['source_signal_id']  = $sourceSignalId;
        $ctx['diagnostic_only']   = true;
        return $ctx;
    }
}
