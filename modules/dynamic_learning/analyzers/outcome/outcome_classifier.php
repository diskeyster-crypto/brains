<?php

declare(strict_types=1);

namespace Modules\DynamicLearning\Analyzers\Outcome;

use Modules\DynamicLearning\Analyzers\DlHelpers;

/**
 * Classifies closed trade outcomes and resolves entry snapshot linking and timing.
 * Determines pattern mining eligibility based on link strength and timing confidence.
 *
 * Feature timing source logic:
 * - strategy_signal_context: features captured by strategy at signal time → always time-valid
 * - recomputed_history: features derived from historical data → only valid if data ≤ learning_opened_at
 * - unknown: no proven timing → exclude from pattern mining
 */
final class OutcomeClassifier
{
    /**
     * Classify a closed outcome based on normalized ROI values.
     *
     * @return array{0:string,1:string}
     */
    public static function classify(?float $closeRoi, ?float $normalizedMaxDd, ?float $normalizedMaxProfit, array $cfg): array
    {
        if ($closeRoi === null) {
            return ['outcome_incomplete', 'close_roi_missing'];
        }
        if ($closeRoi >= (float)$cfg['good_close_roi_threshold']) {
            return ['good_or_do_not_touch', 'close_roi_good'];
        }
        if ($normalizedMaxProfit !== null && $normalizedMaxProfit >= (float)$cfg['good_max_profit_roi_threshold']) {
            return ['entry_ok_exit_issue', 'max_profit_good_but_close_bad'];
        }
        if ($normalizedMaxDd === null) {
            return ['outcome_incomplete', 'max_drawdown_missing'];
        }
        if ($normalizedMaxDd <= (float)$cfg['bad_drawdown_roi_threshold']
            && $closeRoi < (float)$cfg['good_close_roi_threshold']
            && ($normalizedMaxProfit === null || $normalizedMaxProfit < (float)$cfg['good_max_profit_roi_threshold'])
        ) {
            return ['bad_entry', 'deep_drawdown_and_bad_close'];
        }
        if ($closeRoi >= (float)$cfg['neutral_close_roi_min'] && $closeRoi <= (float)$cfg['neutral_close_roi_max']) {
            return ['neutral', 'close_roi_neutral_range'];
        }
        return ['neutral', 'no_strong_signal'];
    }

    /**
     * Resolve which entry snapshot a closed trade links to, using strict matching tiers.
     *
     * Allowed strong link types (in priority order):
     * 1. exact signal_id match
     * 2. exact strategy_signal_key match
     * 3. exact position_id match
     * 4. symbol+side+entry_price+time proximity within tolerance
     *
     * Symbol-only match is classified as weak and excluded from pattern mining.
     *
     * @return array{snapshot_id:?string,snapshot:?array<string,mixed>,link_strength:string,match_type:string}
     */
    public static function resolveLink(array $row, array $snapshotIndex, array $cfg): array
    {
        $signalId = trim((string)($row['signal_id'] ?? ''));
        $ssk = trim((string)($row['strategy_signal_key'] ?? ''));
        $pid = trim((string)($row['position_id'] ?? ''));
        $sym = strtoupper(trim((string)($row['symbol'] ?? '')));
        $side = strtolower(trim((string)($row['side'] ?? 'long')));
        $entryPrice = DlHelpers::toFloat($row['entry_price'] ?? null);
        $openedAt = DlHelpers::extractOpenedAt($row);
        $timeToleranceSec = max(60, (int)($cfg['outcome_opened_at_mismatch_tolerance_minutes'] ?? 15) * 60);
        $weakSymbolOnly = false;

        foreach ($snapshotIndex as $id => $snap) {
            if (!is_array($snap)) {
                continue;
            }
            if ($signalId !== '' && $signalId === (string)($snap['signal_id'] ?? '')) {
                return ['snapshot_id' => (string)$id, 'snapshot' => $snap, 'link_strength' => 'strong', 'match_type' => 'signal_id'];
            }
        }

        foreach ($snapshotIndex as $id => $snap) {
            if (!is_array($snap)) {
                continue;
            }
            if ($ssk !== '' && $ssk === (string)($snap['strategy_signal_key'] ?? '')) {
                return ['snapshot_id' => (string)$id, 'snapshot' => $snap, 'link_strength' => 'strong', 'match_type' => 'strategy_signal_key'];
            }
        }

        foreach ($snapshotIndex as $id => $snap) {
            if (!is_array($snap)) {
                continue;
            }
            if ($pid !== '' && $pid === (string)($snap['position_id'] ?? '')) {
                return ['snapshot_id' => (string)$id, 'snapshot' => $snap, 'link_strength' => 'strong', 'match_type' => 'position_id'];
            }
        }

        $bestId = null;
        $bestSnap = null;
        $bestDiff = null;
        foreach ($snapshotIndex as $id => $snap) {
            if (!is_array($snap)) {
                continue;
            }
            $snapSym = strtoupper(trim((string)($snap['symbol'] ?? '')));
            $snapSide = strtolower(trim((string)($snap['side'] ?? 'long')));
            if ($sym !== '' && $sym === $snapSym) {
                $weakSymbolOnly = true;
            }
            if ($sym === '' || $side === '' || $entryPrice === null || $openedAt === '') {
                continue;
            }
            if ($sym !== $snapSym || $side !== $snapSide) {
                continue;
            }
            $snapEntryPrice = DlHelpers::toFloat($snap['entry_price'] ?? null);
            if ($snapEntryPrice === null || round($snapEntryPrice, 8) !== round($entryPrice, 8)) {
                continue;
            }
            $diff = DlHelpers::smallestTimestampDiffSeconds($openedAt, [
                DlHelpers::extractSnapshotOpenedAt($snap),
                DlHelpers::extractSnapshotDetectedAt($snap),
            ]);
            if ($diff === null || $diff > $timeToleranceSec) {
                continue;
            }
            if ($bestDiff === null || $diff < $bestDiff) {
                $bestDiff = $diff;
                $bestId = (string)$id;
                $bestSnap = $snap;
            }
        }

        if ($bestId !== null && is_array($bestSnap)) {
            return ['snapshot_id' => $bestId, 'snapshot' => $bestSnap, 'link_strength' => 'strong', 'match_type' => 'price_time'];
        }

        if ($weakSymbolOnly) {
            return ['snapshot_id' => null, 'snapshot' => null, 'link_strength' => 'weak', 'match_type' => 'symbol_only'];
        }

        return ['snapshot_id' => null, 'snapshot' => null, 'link_strength' => 'none', 'match_type' => 'none'];
    }

    /**
     * Resolve timing metadata for a closed outcome, correcting stale opened_at using entry snapshot
     * when linked by exact signal_id or strategy_signal_key and mismatch exceeds tolerance.
     *
     * @return array{raw_closed_opened_at:string,learning_opened_at:string,opened_at_source:string,opened_at_corrected:bool,opened_at_mismatch_minutes:?float,timing_confidence:string,duration_sec:int}
     */
    public static function resolveTiming(array $row, ?array $snapshot, array $link, array $cfg): array
    {
        $rawClosedOpenedAt = DlHelpers::extractOpenedAt($row);
        $closedAt = DlHelpers::extractClosedAt($row);
        $learningOpenedAt = $rawClosedOpenedAt;
        $openedAtSource = 'closed_trade';
        $openedAtCorrected = false;
        $openedAtMismatchMinutes = null;
        $timingConfidence = in_array((string)($link['match_type'] ?? ''), ['signal_id', 'strategy_signal_key'], true) ? 'high' : 'low';
        $durationSec = max(0, (int)($row['duration_sec'] ?? 0));

        if (!is_array($snapshot)) {
            return [
                'raw_closed_opened_at' => $rawClosedOpenedAt,
                'learning_opened_at' => $learningOpenedAt,
                'opened_at_source' => $openedAtSource,
                'opened_at_corrected' => $openedAtCorrected,
                'opened_at_mismatch_minutes' => $openedAtMismatchMinutes,
                'timing_confidence' => $timingConfidence,
                'duration_sec' => $durationSec,
            ];
        }

        $snapshotOpenedAt = DlHelpers::extractSnapshotOpenedAt($snapshot);
        $snapshotDetectedAt = DlHelpers::extractSnapshotDetectedAt($snapshot);
        $referenceOpenedAt = $snapshotOpenedAt !== '' ? $snapshotOpenedAt : $snapshotDetectedAt;
        $exactSignalMatch = in_array((string)($link['match_type'] ?? ''), ['signal_id', 'strategy_signal_key'], true);

        if ($rawClosedOpenedAt === '' && $referenceOpenedAt !== '') {
            $learningOpenedAt = $referenceOpenedAt;
            $openedAtSource = 'entry_snapshot';
            $openedAtCorrected = true;
            $timingConfidence = $exactSignalMatch ? 'medium' : 'low';
        } elseif ($exactSignalMatch && (bool)($cfg['prefer_entry_snapshot_time_on_signal_match'] ?? true) && $rawClosedOpenedAt !== '' && $referenceOpenedAt !== '') {
            $diffSeconds = DlHelpers::smallestTimestampDiffSeconds($rawClosedOpenedAt, [$snapshotOpenedAt, $snapshotDetectedAt]);
            $toleranceSeconds = max(60, (int)($cfg['outcome_opened_at_mismatch_tolerance_minutes'] ?? 15) * 60);
            if ($diffSeconds !== null && $diffSeconds > $toleranceSeconds) {
                $learningOpenedAt = $referenceOpenedAt;
                $openedAtSource = 'entry_snapshot';
                $openedAtCorrected = true;
                $openedAtMismatchMinutes = round($diffSeconds / 60, 3);
                $timingConfidence = 'medium';
            }
        }

        if ($closedAt !== '' && $learningOpenedAt !== '') {
            $computedDuration = DlHelpers::signedTimestampDiffSeconds($learningOpenedAt, $closedAt);
            if ($computedDuration !== null) {
                $durationSec = max(0, $computedDuration);
            }
        }

        return [
            'raw_closed_opened_at' => $rawClosedOpenedAt,
            'learning_opened_at' => $learningOpenedAt,
            'opened_at_source' => $openedAtSource,
            'opened_at_corrected' => $openedAtCorrected,
            'opened_at_mismatch_minutes' => $openedAtMismatchMinutes,
            'timing_confidence' => $timingConfidence,
            'duration_sec' => $durationSec,
        ];
    }

    /**
     * Determine if an outcome is eligible for pattern mining.
     *
     * Feature timing source logic:
     * - feature_source = strategy_signal_context: features were captured at signal generation time
     *   by the strategy. These are always temporally valid regardless of snapshot file creation time.
     * - feature_source = recomputed_history: features derived from historical data after the fact.
     *   Only valid if data is restricted to <= learning_opened_at.
     * - feature_source = unknown/missing: timing cannot be proven. Exclude unless clearly safe.
     *
     * @return array{used_for_pattern_mining:bool,exclude_reason:?string}
     */
    public static function determineEligibility(?array $snapshot, array $timing, string $linkStrength): array
    {
        if (!is_array($snapshot)) {
            return [
                'used_for_pattern_mining' => false,
                'exclude_reason' => $linkStrength === 'weak' ? 'weak_symbol_only_match' : 'unlinked_closed_outcome',
            ];
        }
        if ($linkStrength !== 'strong') {
            return ['used_for_pattern_mining' => false, 'exclude_reason' => 'link_not_strong'];
        }
        if ((string)($timing['timing_confidence'] ?? 'low') === 'low') {
            return ['used_for_pattern_mining' => false, 'exclude_reason' => 'timing_confidence_low'];
        }

        $entryFeatures = is_array($snapshot['entry_features'] ?? null) ? (array)$snapshot['entry_features'] : [];
        if ($entryFeatures === []) {
            $entryFeatures = self::extractEntryFeatures((array)($snapshot['strategy_signal_context'] ?? []));
        }
        if ($entryFeatures === []) {
            return ['used_for_pattern_mining' => false, 'exclude_reason' => 'entry_features_missing'];
        }

        // Feature source determines timing validity.
        // strategy_signal_context features are always valid at signal generation time —
        // do NOT apply snapshot file timing check.
        $featureSource = (string)($snapshot['feature_source'] ?? 'unknown');
        if ($featureSource === 'strategy_signal_context') {
            return ['used_for_pattern_mining' => true, 'exclude_reason' => null];
        }

        // For recomputed_history or unknown sources, verify timing.
        $featureAvailableAt = DlHelpers::extractSnapshotFeatureAvailableAt($snapshot);
        $learningOpenedAt = trim((string)($timing['learning_opened_at'] ?? ''));
        if ($featureAvailableAt !== '' && $learningOpenedAt !== '') {
            $featureDelay = DlHelpers::signedTimestampDiffSeconds($featureAvailableAt, $learningOpenedAt);
            if ($featureDelay !== null && $featureDelay > 0) {
                return ['used_for_pattern_mining' => false, 'exclude_reason' => 'entry_features_after_learning_opened_at'];
            }
        }

        if ($featureSource === 'unknown') {
            // Cannot prove timing — allow if featureAvailableAt check passed or was not applicable
            return ['used_for_pattern_mining' => true, 'exclude_reason' => null];
        }

        return ['used_for_pattern_mining' => true, 'exclude_reason' => null];
    }

    /**
     * Extract normalized feature set from strategy_signal_context.
     *
     * @return array<string,mixed>
     */
    public static function extractEntryFeatures(array $ctx): array
    {
        $coin = is_array($ctx['coin_context'] ?? null) ? (array)$ctx['coin_context'] : [];
        $wave = is_array($ctx['wave_context'] ?? null) ? (array)$ctx['wave_context'] : [];
        $ob = is_array($ctx['orderbook_context'] ?? null) ? (array)$ctx['orderbook_context'] : [];
        $f = static fn(string $k, mixed $d = null) => $ctx[$k] ?? $coin[$k] ?? $wave[$k] ?? $ob[$k] ?? $d;
        return [
            'dump_pct' => $f('dump_pct'),
            'stabilization_duration_minutes' => $f('stabilization_duration_minutes'),
            'stabilization_range_pct' => $f('stabilization_range_pct'),
            'stabilization_price_change_pct' => $f('stabilization_price_change_pct'),
            'smooth_growth_pct' => $f('smooth_growth_pct'),
            'smooth_growth_duration_minutes' => $f('smooth_growth_duration_minutes'),
            'smooth_growth_higher_close_count' => $f('smooth_growth_higher_close_count'),
            'smooth_growth_higher_low_count' => $f('smooth_growth_higher_low_count'),
            'smooth_growth_single_candle_dominance_pct' => $f('smooth_growth_single_candle_dominance_pct'),
            'open_interest_growth_pct' => $f('open_interest_growth_pct'),
            'open_interest_confirmed' => $f('open_interest_confirmed'),
            'recovery_phase' => $f('recovery_phase'),
            'entry_timing' => $f('entry_timing'),
            'late_spike_detected' => $f('late_spike_detected'),
            'extended_recovery_detected' => $f('extended_recovery_detected'),
            'context_phase' => $f('context_phase'),
            'context_quality' => $f('context_quality'),
            'context_reasons' => is_array($f('context_reasons', [])) ? $f('context_reasons', []) : [],
            'trend_1h_direction' => $f('trend_1h_direction'),
            'trend_2h_direction' => $f('trend_2h_direction'),
            'trend_4h_direction' => $f('trend_4h_direction'),
            'price_change_1h_pct' => $f('price_change_1h_pct'),
            'corridor_position_pct' => $f('corridor_position_pct'),
            'room_to_recent_high_pct' => $f('room_to_recent_high_pct'),
            'distance_from_recent_low_pct' => $f('distance_from_recent_low_pct'),
            'wave_regime' => $f('wave_regime'),
            'trend_flip_count_2h' => $f('trend_flip_count_2h'),
            'avg_time_between_flips_minutes' => $f('avg_time_between_flips_minutes'),
            'wave_amplitude_avg_pct' => $f('wave_amplitude_avg_pct'),
            'wave_noise_score' => $f('wave_noise_score'),
            'trend_persistence_score' => $f('trend_persistence_score'),
            'ask_wall_risk' => $f('ask_wall_risk'),
            'nearest_ask_wall_distance_pct' => $f('nearest_ask_wall_distance_pct'),
            'nearest_ask_wall_notional' => $f('nearest_ask_wall_notional'),
            'ask_wall_strength_score' => $f('ask_wall_strength_score'),
            'bid_support_quality' => $f('bid_support_quality'),
            'bid_support_score' => $f('bid_support_score'),
            'bid_ask_notional_ratio' => $f('bid_ask_notional_ratio'),
            'filter_results' => is_array($f('filter_results', [])) ? $f('filter_results', []) : [],
            'would_have_blocked_by_filters' => is_array($f('would_have_blocked_by_filters', [])) ? $f('would_have_blocked_by_filters', []) : [],
            'handoff_block_reason' => $f('handoff_block_reason'),
        ];
    }
}
