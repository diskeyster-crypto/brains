<?php

declare(strict_types=1);

namespace Modules\DynamicLearning\Analyzers\Patterns;

use Modules\DynamicLearning\Analyzers\DlHelpers;

/**
 * Mines bad/good entry patterns from a set of pattern-mining-eligible outcomes.
 * All outcomes passed here must already have link_strength=strong and timing_confidence!=low.
 */
final class PatternMiner
{
    /** @return array{0:string,1:mixed,2:mixed}[] Pattern definitions as [id, label, conditions] */
    private static function definitions(): array
    {
        return [
            ['id' => 'context_phase_chaotic', 'label' => 'context_phase = chaotic', 'f' => 'context_phase', 'op' => 'eq', 'v' => 'chaotic'],
            ['id' => 'context_quality_bad', 'label' => 'context_quality = bad', 'f' => 'context_quality', 'op' => 'eq', 'v' => 'bad'],
            ['id' => 'wave_regime_fast_flip_chop', 'label' => 'wave_regime = fast_flip_chop', 'f' => 'wave_regime', 'op' => 'eq', 'v' => 'fast_flip_chop'],
            ['id' => 'ask_wall_risk_high', 'label' => 'ask_wall_risk = high', 'f' => 'ask_wall_risk', 'op' => 'eq', 'v' => 'high'],
            ['id' => 'bid_support_quality_weak_or_none', 'label' => 'bid_support_quality weak/none', 'f' => 'bid_support_quality', 'op' => 'in', 'v' => ['weak', 'none']],
            ['id' => 'oi_not_confirmed', 'label' => 'open_interest_confirmed = false', 'f' => 'open_interest_confirmed', 'op' => 'eq', 'v' => false],
            ['id' => 'single_candle_dominance_ge_65', 'label' => 'single_candle_dominance >= 65', 'f' => 'single_candle_dominance_pct', 'op' => 'gte', 'v' => 65],
            ['id' => 'micro_impulse_shape_single_spike', 'label' => 'micro_impulse_shape = single_spike', 'f' => 'micro_impulse_shape', 'op' => 'eq', 'v' => 'single_spike'],
            ['id' => 'micro_impulse_shape_smooth_birth', 'label' => 'micro_impulse_shape = smooth_birth', 'f' => 'micro_impulse_shape', 'op' => 'eq', 'v' => 'smooth_birth'],
            ['id' => 'micro_impulse_shape_choppy_birth', 'label' => 'micro_impulse_shape = choppy_birth', 'f' => 'micro_impulse_shape', 'op' => 'eq', 'v' => 'choppy_birth'],
            ['id' => 'micro_growth_distribution_concentrated', 'label' => 'micro_growth_distribution = concentrated', 'f' => 'micro_growth_distribution', 'op' => 'eq', 'v' => 'concentrated'],
            ['id' => 'micro_growth_distribution_distributed', 'label' => 'micro_growth_distribution = distributed', 'f' => 'micro_growth_distribution', 'op' => 'eq', 'v' => 'distributed'],
            ['id' => 'micro_entry_timing_after_spike', 'label' => 'micro_entry_timing = after_spike', 'f' => 'micro_entry_timing', 'op' => 'eq', 'v' => 'after_spike'],
            ['id' => 'micro_rejection_risk_high', 'label' => 'micro_rejection_risk = high', 'f' => 'micro_rejection_risk', 'op' => 'eq', 'v' => 'high'],
            ['id' => 'largest_candle_share_ge_70', 'label' => 'largest_candle_share_pct >= 70', 'f' => 'largest_candle_share_pct', 'op' => 'gte', 'v' => 70],
            ['id' => 'higher_close_count_ge_3', 'label' => 'higher_close_count >= 3', 'f' => 'higher_close_count', 'op' => 'gte', 'v' => 3],
            ['id' => 'higher_low_count_ge_2', 'label' => 'higher_low_count >= 2', 'f' => 'higher_low_count', 'op' => 'gte', 'v' => 2],
            ['id' => 'pullback_max_pct_ge_0_8', 'label' => 'pullback_max_pct >= 0.8', 'f' => 'pullback_max_pct', 'op' => 'gte', 'v' => 0.8],
            ['id' => 'direction_flip_count_ge_3', 'label' => 'direction_flip_count >= 3', 'f' => 'direction_flip_count', 'op' => 'gte', 'v' => 3],
            ['id' => 'dump_shape_vertical_liquidation', 'label' => 'dump_shape = vertical_liquidation', 'f' => 'dump_shape', 'op' => 'eq', 'v' => 'vertical_liquidation'],
            ['id' => 'dump_shape_choppy_dump', 'label' => 'dump_shape = choppy_dump', 'f' => 'dump_shape', 'op' => 'eq', 'v' => 'choppy_dump'],
            ['id' => 'dump_shape_controlled_dump', 'label' => 'dump_shape = controlled_dump', 'f' => 'dump_shape', 'op' => 'eq', 'v' => 'controlled_dump'],
            ['id' => 'post_dump_state_knife_bounce', 'label' => 'post_dump_state = knife_bounce', 'f' => 'post_dump_state', 'op' => 'eq', 'v' => 'knife_bounce'],
            ['id' => 'post_dump_state_stabilized', 'label' => 'post_dump_state = stabilized', 'f' => 'post_dump_state', 'op' => 'eq', 'v' => 'stabilized'],
            ['id' => 'bounce_only_risk_score_ge_0_65', 'label' => 'bounce_only_risk_score >= 0.65', 'f' => 'bounce_only_risk_score', 'op' => 'gte', 'v' => 0.65],
            ['id' => 'impulse_birth_after_dump_score_ge_0_60', 'label' => 'impulse_birth_after_dump_score >= 0.60', 'f' => 'impulse_birth_after_dump_score', 'op' => 'gte', 'v' => 0.60],
            ['id' => 'combo_bad_context_high_ask_wall', 'label' => 'context_quality bad + ask_wall_risk high', 'combo' => [['f' => 'context_quality', 'op' => 'eq', 'v' => 'bad'], ['f' => 'ask_wall_risk', 'op' => 'eq', 'v' => 'high']]],
            ['id' => 'combo_fast_flip_weak_bid', 'label' => 'wave fast_flip_chop + bid weak/none', 'combo' => [['f' => 'wave_regime', 'op' => 'eq', 'v' => 'fast_flip_chop'], ['f' => 'bid_support_quality', 'op' => 'in', 'v' => ['weak', 'none']]]],
            ['id' => 'combo_single_spike_ask_wall_high', 'label' => 'single_spike + ask_wall_risk_high', 'combo' => [['f' => 'micro_impulse_shape', 'op' => 'eq', 'v' => 'single_spike'], ['f' => 'ask_wall_risk', 'op' => 'eq', 'v' => 'high']]],
            ['id' => 'combo_concentrated_fast_flip_chop', 'label' => 'concentrated_growth + fast_flip_chop', 'combo' => [['f' => 'micro_growth_distribution', 'op' => 'eq', 'v' => 'concentrated'], ['f' => 'wave_regime', 'op' => 'eq', 'v' => 'fast_flip_chop']]],
            ['id' => 'combo_after_spike_oi_not_confirmed', 'label' => 'after_spike + oi_not_confirmed', 'combo' => [['f' => 'micro_entry_timing', 'op' => 'eq', 'v' => 'after_spike'], ['f' => 'open_interest_confirmed', 'op' => 'eq', 'v' => false]]],
            ['id' => 'combo_knife_bounce_high_single_candle_dominance', 'label' => 'knife_bounce + high_single_candle_dominance', 'combo' => [['f' => 'post_dump_state', 'op' => 'eq', 'v' => 'knife_bounce'], ['f' => 'single_candle_dominance_pct', 'op' => 'gte', 'v' => 65]]],
            ['id' => 'combo_smooth_birth_bid_support_strong', 'label' => 'smooth_birth + bid_support_strong', 'combo' => [['f' => 'micro_impulse_shape', 'op' => 'eq', 'v' => 'smooth_birth'], ['f' => 'bid_support_quality', 'op' => 'in', 'v' => ['strong', 'medium']]]],
            ['id' => 'combo_controlled_dump_smooth_birth', 'label' => 'controlled_dump + smooth_birth', 'combo' => [['f' => 'dump_shape', 'op' => 'eq', 'v' => 'controlled_dump'], ['f' => 'micro_impulse_shape', 'op' => 'eq', 'v' => 'smooth_birth']]],
            ['id' => 'combo_choppy_dump_fast_flip_chop', 'label' => 'choppy_dump + fast_flip_chop', 'combo' => [['f' => 'dump_shape', 'op' => 'eq', 'v' => 'choppy_dump'], ['f' => 'wave_regime', 'op' => 'eq', 'v' => 'fast_flip_chop']]],
        ];
    }

    /** @return list<string> */
    private static function numericFeaturePaths(): array
    {
        return [
            'single_candle_dominance_pct', 'largest_candle_share_pct', 'largest_candle_change_pct', 'micro_total_change_pct',
            'higher_close_count', 'higher_low_count', 'lower_close_count', 'lower_low_count', 'direction_flip_count',
            'pullback_max_pct', 'pullback_count', 'avg_body_pct', 'avg_upper_wick_pct', 'avg_lower_wick_pct',
            'max_upper_wick_pct', 'max_lower_wick_pct', 'smoothness_score', 'acceleration_score', 'impulse_birth_score',
            'late_spike_risk_score',
            'micro_window_5m.single_candle_dominance_pct', 'micro_window_10m.single_candle_dominance_pct', 'micro_window_15m.single_candle_dominance_pct',
            'micro_window_5m.total_change_pct', 'micro_window_10m.total_change_pct', 'micro_window_15m.total_change_pct',
            'micro_window_5m.direction_flip_count', 'micro_window_10m.direction_flip_count', 'micro_window_15m.direction_flip_count',
            'micro_window_5m.pullback_max_pct', 'micro_window_10m.pullback_max_pct', 'micro_window_15m.pullback_max_pct',
            'dump_depth_pct', 'dump_duration_minutes', 'dump_speed_pct_per_min', 'dump_single_candle_dominance_pct',
            'dump_largest_red_candle_share_pct', 'dump_red_candle_count', 'dump_green_candle_count', 'dump_verticality_score',
            'dump_exhaustion_score', 'dump_rebound_after_low_pct', 'dump_new_low_count', 'bounce_only_risk_score',
            'impulse_birth_after_dump_score', 'soft_growth_after_dump_score', 'entry_quality_micro_score',
            'trend_flip_count_2h', 'avg_time_between_flips_minutes', 'trend_persistence_score', 'nearest_ask_wall_distance_pct',
            'nearest_ask_wall_notional', 'bid_support_score', 'bid_ask_notional_ratio', 'open_interest_growth_pct',
        ];
    }

    /** @return list<string> */
    private static function categoricalFeaturePaths(): array
    {
        return [
            'micro_impulse_shape', 'micro_entry_timing', 'micro_growth_distribution', 'micro_rejection_risk',
            'dump_shape', 'post_dump_state', 'post_dump_impulse_type', 'context_phase', 'context_quality',
            'wave_regime', 'ask_wall_risk', 'bid_support_quality', 'open_interest_confirmed',
        ];
    }

    /**
     * @param array<array<string,mixed>> $outcomes
     * @param array<string,mixed> $cfg
     * @param array<string,array<string,mixed>> $featureBySnapshot
     * @return array{all:list<array<string,mixed>>,bad_patterns:list<array<string,mixed>>,micro_feature_distributions:array<string,mixed>,micro_label_purity:array<string,mixed>,micro_threshold_candidates:array<string,mixed>,suspicious_micro_labels:array<string,mixed>,top_micro_bad_separators:list<array<string,mixed>>,top_micro_good_separators:list<array<string,mixed>>,micro_mixed_label_examples:list<array<string,mixed>>,micro_suspicious_label_examples:list<array<string,mixed>>,micro_numeric_features_analyzed_total:int,micro_label_values_analyzed_total:int,micro_high_separation_features_total:int,micro_medium_separation_features_total:int,micro_mixed_labels_total:int,micro_suspicious_labels_total:int}
     */
    public static function mine(array $outcomes, array $cfg, array $featureBySnapshot = []): array
    {
        $rows = [];
        $enriched = [];
        foreach ($outcomes as $o) {
            if (!is_array($o)) {
                continue;
            }
            $f = self::extractFeaturesFromOutcome($o, $featureBySnapshot);
            $o['_dl_features'] = $f;
            $enriched[] = $o;
        }

        foreach (self::definitions() as $d) {
            $bad = 0;
            $good = 0;
            $neutral = 0;
            $badClose = [];
            $badDd = [];
            $goodClose = [];

            foreach ($enriched as $o) {
                $f = (array)($o['_dl_features'] ?? []);
                $match = isset($d['combo'])
                    ? DlHelpers::matchCombo($f, (array)$d['combo'])
                    : DlHelpers::cond($f[$d['f']] ?? null, (string)$d['op'], $d['v']);
                if (!$match) {
                    continue;
                }
                $c = (string)($o['outcome_class'] ?? '');
                if ($c === 'bad_entry') {
                    $bad++;
                    if (is_numeric($o['close_roi'] ?? null)) {
                        $badClose[] = (float)$o['close_roi'];
                    }
                    if (is_numeric($o['max_drawdown_roi'] ?? null)) {
                        $badDd[] = (float)$o['max_drawdown_roi'];
                    }
                } elseif ($c === 'good_or_do_not_touch') {
                    $good++;
                    if (is_numeric($o['close_roi'] ?? null)) {
                        $goodClose[] = (float)$o['close_roi'];
                    }
                } elseif ($c === 'neutral') {
                    $neutral++;
                }
            }

            if (($bad + $good + $neutral) === 0) {
                continue;
            }

            $confidence = ($bad >= 5 && $good === 0) ? 'high' : (($bad >= 3 && $bad > $good) ? 'medium' : 'low');
            $avgBadDd = DlHelpers::avg($badDd);
            $goodOverlapHigh = $good > 0 && $good >= $bad;
            $suggested = 'observe_only';
            if ($bad >= 2 && $bad > $good && !$goodOverlapHigh && $avgBadDd !== null && $avgBadDd <= (float)$cfg['bad_drawdown_roi_threshold']) {
                $suggested = $confidence === 'high' ? 'candidate_hard_block_demo' : 'candidate_soft_block';
            }

            $rows[] = [
                'pattern_id' => $d['id'],
                'pattern_label' => $d['label'],
                'bad_count' => $bad,
                'good_count' => $good,
                'neutral_count' => $neutral,
                'bad_share' => round($bad / max(1, $bad + $good + $neutral), 4),
                'good_overlap_count' => $good,
                'avg_bad_close_roi' => DlHelpers::avg($badClose),
                'avg_bad_drawdown_roi' => $avgBadDd,
                'avg_good_close_roi' => DlHelpers::avg($goodClose),
                'confidence' => $confidence,
                'suggested_action' => $suggested,
                'good_overlap_note' => $goodOverlapHigh ? 'do_not_use_for_hard_block' : '',
                'conditions' => isset($d['combo'])
                    ? array_values((array)$d['combo'])
                    : [['field' => $d['f'], 'op' => $d['op'], 'value' => $d['v']]],
            ];
        }

        usort($rows, static fn(array $a, array $b): int => (int)$b['bad_count'] <=> (int)$a['bad_count']);

        $micro = self::buildMicroDiagnostics($enriched);

        return [
            'all' => $rows,
            'bad_patterns' => array_values(array_filter($rows, static fn(array $r): bool => (int)$r['bad_count'] > 0)),
            'micro_feature_distributions' => $micro['micro_feature_distributions'],
            'micro_label_purity' => $micro['micro_label_purity'],
            'micro_threshold_candidates' => $micro['micro_threshold_candidates'],
            'suspicious_micro_labels' => $micro['suspicious_micro_labels'],
            'top_micro_bad_separators' => $micro['top_micro_bad_separators'],
            'top_micro_good_separators' => $micro['top_micro_good_separators'],
            'micro_mixed_label_examples' => $micro['micro_mixed_label_examples'],
            'micro_suspicious_label_examples' => $micro['micro_suspicious_label_examples'],
            'micro_numeric_features_analyzed_total' => $micro['micro_numeric_features_analyzed_total'],
            'micro_label_values_analyzed_total' => $micro['micro_label_values_analyzed_total'],
            'micro_high_separation_features_total' => $micro['micro_high_separation_features_total'],
            'micro_medium_separation_features_total' => $micro['micro_medium_separation_features_total'],
            'micro_mixed_labels_total' => $micro['micro_mixed_labels_total'],
            'micro_suspicious_labels_total' => $micro['micro_suspicious_labels_total'],
        ];
    }

    /** @param array<array<string,mixed>> $outcomes @return array<string,mixed> */
    private static function buildMicroDiagnostics(array $outcomes): array
    {
        $classes = ['bad_entry', 'good_or_do_not_touch', 'entry_ok_exit_issue', 'neutral'];
        $numeric = [];
        $label = [];
        $thresholdCandidates = [];
        $highSep = 0;
        $mediumSep = 0;

        foreach (self::numericFeaturePaths() as $featurePath) {
            $valuesByClass = [
                'bad_entry' => [],
                'good_or_do_not_touch' => [],
                'entry_ok_exit_issue' => [],
                'neutral' => [],
            ];
            foreach ($outcomes as $o) {
                $class = (string)($o['outcome_class'] ?? '');
                if (!isset($valuesByClass[$class])) {
                    continue;
                }
                $value = self::extractNumericFeature($o, $featurePath);
                if ($value === null) {
                    continue;
                }
                $valuesByClass[$class][] = $value;
            }

            $statsByClass = [];
            foreach ($classes as $class) {
                $statsByClass[$class] = self::numericStats($valuesByClass[$class]);
            }

            $badStats = $statsByClass['bad_entry'];
            $goodStats = $statsByClass['good_or_do_not_touch'];
            $badCount = (int)$badStats['count'];
            $goodCount = (int)$goodStats['count'];
            $badMedian = $badStats['median'];
            $goodMedian = $goodStats['median'];
            $delta = ($badMedian !== null && $goodMedian !== null) ? ($badMedian - $goodMedian) : null;

            $direction = 'unclear';
            if ($delta !== null) {
                if ($delta > 0) {
                    $direction = 'higher_bad_risk';
                } elseif ($delta < 0) {
                    $direction = 'lower_bad_risk';
                }
            }

            $confidence = 'low';
            if ($badCount >= 3 && $goodCount >= 3) {
                $confidence = 'high';
            } elseif ($badCount >= 2 && $goodCount >= 2) {
                $confidence = 'medium';
            }

            $overlapScore = null;
            $separationScore = null;
            if ($badStats['p25'] !== null && $badStats['p75'] !== null && $goodStats['p25'] !== null && $goodStats['p75'] !== null) {
                $overlapMin = max((float)$badStats['p25'], (float)$goodStats['p25']);
                $overlapMax = min((float)$badStats['p75'], (float)$goodStats['p75']);
                $overlapWidth = max(0.0, $overlapMax - $overlapMin);
                $spanMin = min((float)$badStats['p25'], (float)$goodStats['p25']);
                $spanMax = max((float)$badStats['p75'], (float)$goodStats['p75']);
                $spanWidth = max(0.000001, $spanMax - $spanMin);
                $overlapScore = round($overlapWidth / $spanWidth, 6);

                $deltaNorm = 0.0;
                if ($delta !== null) {
                    $deltaNorm = min(1.0, abs($delta) / max(0.1, abs((float)$goodMedian ?: 0.0) + 0.1));
                }
                $separationScore = round(((1.0 - $overlapScore) * 0.7) + ($deltaNorm * 0.3), 6);
            }

            $candidateThreshold = null;
            $badCapturedCount = 0;
            $goodBlockedCount = 0;
            $netScore = null;
            if ($confidence !== 'low' && $direction !== 'unclear' && $badMedian !== null && $goodMedian !== null) {
                $candidateThreshold = round(($badMedian + $goodMedian) / 2.0, 6);
                foreach ($valuesByClass['bad_entry'] as $v) {
                    if (($direction === 'higher_bad_risk' && $v >= $candidateThreshold) || ($direction === 'lower_bad_risk' && $v <= $candidateThreshold)) {
                        $badCapturedCount++;
                    }
                }
                foreach ($valuesByClass['good_or_do_not_touch'] as $v) {
                    if (($direction === 'higher_bad_risk' && $v >= $candidateThreshold) || ($direction === 'lower_bad_risk' && $v <= $candidateThreshold)) {
                        $goodBlockedCount++;
                    }
                }
                $netScore = round(($badCapturedCount * 1.0) - ($goodBlockedCount * 1.0), 6);
                $thresholdCandidates[] = [
                    'feature' => $featurePath,
                    'direction' => $direction,
                    'candidate_threshold' => $candidateThreshold,
                    'bad_captured_count' => $badCapturedCount,
                    'good_blocked_count' => $goodBlockedCount,
                    'net_score' => $netScore,
                    'suggested_action' => 'observe_only',
                    'confidence' => $confidence,
                ];
            }

            if ($confidence === 'high' && ($separationScore ?? 0.0) > 0.0) {
                $highSep++;
            } elseif ($confidence === 'medium' && ($separationScore ?? 0.0) > 0.0) {
                $mediumSep++;
            }

            $numeric[] = [
                'feature' => $featurePath,
                'classes' => $statsByClass,
                'bad_median' => $badMedian,
                'good_median' => $goodMedian,
                'median_delta' => $delta,
                'bad_p75' => $badStats['p75'],
                'good_p25' => $goodStats['p25'],
                'overlap_score' => $overlapScore,
                'separation_score' => $separationScore,
                'direction' => $direction,
                'confidence' => $confidence,
                'candidate_threshold' => $candidateThreshold,
                'bad_captured_count' => $badCapturedCount,
                'good_blocked_count' => $goodBlockedCount,
            ];
        }

        usort($numeric, static fn(array $a, array $b): int => ((float)($b['separation_score'] ?? -1.0) <=> (float)($a['separation_score'] ?? -1.0)));
        $numeric = array_slice($numeric, 0, 50);

        usort($thresholdCandidates, static fn(array $a, array $b): int => ((float)($b['net_score'] ?? -9999.0) <=> (float)($a['net_score'] ?? -9999.0)));
        $thresholdCandidates = array_slice($thresholdCandidates, 0, 50);

        $labelRows = [];
        $mixedLabelRows = [];
        $labelValuesAnalyzed = 0;
        foreach (self::categoricalFeaturePaths() as $labelPath) {
            $bucket = [];
            foreach ($outcomes as $o) {
                $class = (string)($o['outcome_class'] ?? '');
                if (!in_array($class, $classes, true)) {
                    continue;
                }
                $value = self::extractCategoricalFeature($o, $labelPath);
                if ($value === '') {
                    $value = 'unknown';
                }
                if (!isset($bucket[$value])) {
                    $bucket[$value] = [
                        'bad_entry' => 0,
                        'good_or_do_not_touch' => 0,
                        'entry_ok_exit_issue' => 0,
                        'neutral' => 0,
                    ];
                }
                $bucket[$value][$class]++;
            }

            foreach ($bucket as $value => $counts) {
                $labelValuesAnalyzed++;
                $bad = (int)$counts['bad_entry'];
                $good = (int)$counts['good_or_do_not_touch'];
                $exitIssue = (int)$counts['entry_ok_exit_issue'];
                $neutral = (int)$counts['neutral'];
                $total = max(1, $bad + $good + $exitIssue + $neutral);
                $badShare = round($bad / $total, 6);
                $goodShare = round($good / $total, 6);

                $status = 'mixed';
                if (($bad + $good) < 2) {
                    $status = 'insufficient_data';
                } elseif ($bad > 0 && $good === 0) {
                    $status = 'useful_bad_signal';
                } elseif ($good > 0 && $bad === 0) {
                    $status = 'useful_good_signal';
                } elseif ($bad > 0 && $good > 0) {
                    $status = 'mixed';
                }

                $purity = round(abs($bad - $good) / max(1, $bad + $good), 6);
                $row = [
                    'label' => $labelPath,
                    'value' => $value,
                    'bad_count' => $bad,
                    'good_count' => $good,
                    'entry_ok_exit_issue_count' => $exitIssue,
                    'neutral_count' => $neutral,
                    'bad_share' => $badShare,
                    'good_share' => $goodShare,
                    'good_overlap_count' => $good,
                    'label_purity_score' => $purity,
                    'suggested_label_status' => $status,
                ];
                $labelRows[] = $row;
                if ($status === 'mixed') {
                    $mixedLabelRows[] = $row;
                }
            }
        }

        usort($labelRows, static fn(array $a, array $b): int => ((float)$b['label_purity_score'] <=> (float)$a['label_purity_score']));
        $labelRows = array_slice($labelRows, 0, 50);

        $suspiciousDefs = [
            ['label' => 'micro_impulse_shape', 'value' => 'smooth_birth'],
            ['label' => 'micro_growth_distribution', 'value' => 'distributed'],
            ['label' => 'post_dump_state', 'value' => 'stabilized'],
            ['label' => 'post_dump_impulse_type', 'value' => 'real_impulse_birth'],
        ];

        $suspicious = [];
        $suspiciousExampleRows = [];
        foreach ($suspiciousDefs as $def) {
            $bad = 0;
            $good = 0;
            $examples = [];
            foreach ($outcomes as $o) {
                $class = (string)($o['outcome_class'] ?? '');
                $value = self::extractCategoricalFeature($o, (string)$def['label']);
                if ($value !== (string)$def['value']) {
                    continue;
                }
                if ($class === 'bad_entry') {
                    $bad++;
                } elseif ($class === 'good_or_do_not_touch') {
                    $good++;
                }
                if (count($examples) < 20) {
                    $f = (array)($o['_dl_features'] ?? []);
                    $examples[] = [
                        'symbol' => (string)($o['symbol'] ?? ''),
                        'outcome_class' => $class,
                        'close_roi' => $o['close_roi'] ?? null,
                        'normalized_max_drawdown_roi' => $o['normalized_max_drawdown_roi'] ?? null,
                        'normalized_max_profit_roi' => $o['normalized_max_profit_roi'] ?? null,
                        'micro_impulse_shape' => self::extractCategoricalFeature($o, 'micro_impulse_shape'),
                        'micro_entry_timing' => self::extractCategoricalFeature($o, 'micro_entry_timing'),
                        'micro_growth_distribution' => self::extractCategoricalFeature($o, 'micro_growth_distribution'),
                        'single_candle_dominance_pct' => self::extractNumericFeature($o, 'single_candle_dominance_pct'),
                        'smoothness_score' => self::extractNumericFeature($o, 'smoothness_score'),
                        'acceleration_score' => self::extractNumericFeature($o, 'acceleration_score'),
                        'impulse_birth_score' => self::extractNumericFeature($o, 'impulse_birth_score'),
                        'dump_shape' => self::extractCategoricalFeature($o, 'dump_shape'),
                        'post_dump_state' => self::extractCategoricalFeature($o, 'post_dump_state'),
                        'post_dump_impulse_type' => self::extractCategoricalFeature($o, 'post_dump_impulse_type'),
                        'ask_wall_risk' => self::extractCategoricalFeature($o, 'ask_wall_risk'),
                        'bid_support_quality' => self::extractCategoricalFeature($o, 'bid_support_quality'),
                        'wave_regime' => self::extractCategoricalFeature($o, 'wave_regime'),
                    ];
                }
            }
            if ($bad > $good) {
                $suspicious[] = [
                    'label' => (string)$def['label'],
                    'value' => (string)$def['value'],
                    'bad_count' => $bad,
                    'good_count' => $good,
                    'examples' => $examples,
                ];
                $suspiciousExampleRows[] = [
                    'label' => (string)$def['label'],
                    'value' => (string)$def['value'],
                    'bad_count' => $bad,
                    'good_count' => $good,
                ];
            }
        }

        $badSeparators = array_values(array_filter($numeric, static fn(array $r): bool => (string)($r['direction'] ?? '') === 'higher_bad_risk'));
        $goodSeparators = array_values(array_filter($numeric, static fn(array $r): bool => (string)($r['direction'] ?? '') === 'lower_bad_risk'));

        return [
            'micro_feature_distributions' => [
                'generated_at' => date('c'),
                'features' => $numeric,
            ],
            'micro_label_purity' => [
                'generated_at' => date('c'),
                'labels' => $labelRows,
            ],
            'micro_threshold_candidates' => [
                'generated_at' => date('c'),
                'candidates' => $thresholdCandidates,
            ],
            'suspicious_micro_labels' => [
                'generated_at' => date('c'),
                'labels' => $suspicious,
            ],
            'top_micro_bad_separators' => array_slice($badSeparators, 0, 10),
            'top_micro_good_separators' => array_slice($goodSeparators, 0, 10),
            'micro_mixed_label_examples' => array_slice($mixedLabelRows, 0, 10),
            'micro_suspicious_label_examples' => array_slice($suspiciousExampleRows, 0, 10),
            'micro_numeric_features_analyzed_total' => count(self::numericFeaturePaths()),
            'micro_label_values_analyzed_total' => $labelValuesAnalyzed,
            'micro_high_separation_features_total' => $highSep,
            'micro_medium_separation_features_total' => $mediumSep,
            'micro_mixed_labels_total' => count($mixedLabelRows),
            'micro_suspicious_labels_total' => count($suspicious),
        ];
    }

    /** @return array<string,mixed> */
    private static function extractFeaturesFromOutcome(array $o, array $featureBySnapshot): array
    {
        $f = (array)($o['entry_snapshot']['entry_features'] ?? []);
        $lookupKey = trim((string)($o['feature_lookup_key'] ?? ''));
        if ($lookupKey !== '' && isset($featureBySnapshot[$lookupKey]) && is_array($featureBySnapshot[$lookupKey])) {
            $f = array_merge($f, (array)$featureBySnapshot[$lookupKey]);
        }
        $sid = trim((string)($o['snapshot_id'] ?? $o['entry_snapshot']['snapshot_id'] ?? ''));
        if ($sid !== '' && isset($featureBySnapshot[$sid]) && is_array($featureBySnapshot[$sid])) {
            $f = array_merge($f, (array)$featureBySnapshot[$sid]);
        }

        $windows = (array)($f['candle_micro_windows'] ?? $f['micro_candle']['candle_micro_windows'] ?? []);
        foreach ($windows as $window => $stats) {
            if (!is_array($stats)) {
                continue;
            }
            $f[(string)$window] = $stats;
        }

        if ($f !== []) {
            if (!array_key_exists('single_candle_dominance_pct', $f) && array_key_exists('micro_single_candle_dominance_pct', $f)) {
                $f['single_candle_dominance_pct'] = $f['micro_single_candle_dominance_pct'];
            }
            if (!array_key_exists('largest_candle_share_pct', $f) && array_key_exists('micro_largest_candle_share_pct', $f)) {
                $f['largest_candle_share_pct'] = $f['micro_largest_candle_share_pct'];
            }
            if (!array_key_exists('higher_close_count', $f) && array_key_exists('micro_higher_close_count', $f)) {
                $f['higher_close_count'] = $f['micro_higher_close_count'];
            }
            if (!array_key_exists('higher_low_count', $f) && array_key_exists('micro_higher_low_count', $f)) {
                $f['higher_low_count'] = $f['micro_higher_low_count'];
            }
            if (!array_key_exists('pullback_max_pct', $f) && array_key_exists('micro_pullback_max_pct', $f)) {
                $f['pullback_max_pct'] = $f['micro_pullback_max_pct'];
            }
            if (!array_key_exists('direction_flip_count', $f) && array_key_exists('micro_direction_flip_count', $f)) {
                $f['direction_flip_count'] = $f['micro_direction_flip_count'];
            }
            return $f;
        }

        $ctx = (array)($o['entry_snapshot']['strategy_signal_context'] ?? []);
        if ($ctx !== []) {
            require_once dirname(__DIR__) . '/outcome/outcome_classifier.php';
            return \Modules\DynamicLearning\Analyzers\Outcome\OutcomeClassifier::extractEntryFeatures($ctx);
        }
        return [];
    }

    /** @param list<float> $values @return array<string,mixed> */
    private static function numericStats(array $values): array
    {
        sort($values);
        $count = count($values);
        return [
            'count' => $count,
            'min' => $count > 0 ? $values[0] : null,
            'p10' => self::percentile($values, 10),
            'p25' => self::percentile($values, 25),
            'median' => self::percentile($values, 50),
            'p75' => self::percentile($values, 75),
            'p90' => self::percentile($values, 90),
            'max' => $count > 0 ? $values[$count - 1] : null,
            'avg' => $count > 0 ? round(array_sum($values) / $count, 6) : null,
        ];
    }

    /** @param list<float> $values */
    private static function percentile(array $values, float $percent): ?float
    {
        $n = count($values);
        if ($n === 0) {
            return null;
        }
        if ($n === 1) {
            return $values[0];
        }
        $rank = ($percent / 100.0) * ($n - 1);
        $low = (int)floor($rank);
        $high = (int)ceil($rank);
        if ($low === $high) {
            return round($values[$low], 6);
        }
        $weight = $rank - $low;
        return round($values[$low] + (($values[$high] - $values[$low]) * $weight), 6);
    }

    private static function extractNumericFeature(array $o, string $path): ?float
    {
        $f = (array)($o['_dl_features'] ?? []);
        $value = self::getPathValue($f, $path);
        if ($value === null) {
            return null;
        }
        return is_numeric($value) ? (float)$value : null;
    }

    private static function extractCategoricalFeature(array $o, string $path): string
    {
        $f = (array)($o['_dl_features'] ?? []);
        $value = self::getPathValue($f, $path);
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return '';
        }
        return trim((string)$value);
    }

    private static function getPathValue(array $data, string $path): mixed
    {
        if ($path === '') {
            return null;
        }
        if (array_key_exists($path, $data)) {
            return $data[$path];
        }
        $parts = explode('.', $path);
        $cur = $data;
        foreach ($parts as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                return null;
            }
            $cur = $cur[$part];
        }
        return $cur;
    }
}
