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
            [
                'id' => 'context_phase_chaotic',
                'label' => 'context_phase = chaotic',
                'f' => 'context_phase',
                'op' => 'eq',
                'v' => 'chaotic',
            ],
            [
                'id' => 'context_quality_bad',
                'label' => 'context_quality = bad',
                'f' => 'context_quality',
                'op' => 'eq',
                'v' => 'bad',
            ],
            [
                'id' => 'wave_regime_fast_flip_chop',
                'label' => 'wave_regime = fast_flip_chop',
                'f' => 'wave_regime',
                'op' => 'eq',
                'v' => 'fast_flip_chop',
            ],
            [
                'id' => 'ask_wall_risk_high',
                'label' => 'ask_wall_risk = high',
                'f' => 'ask_wall_risk',
                'op' => 'eq',
                'v' => 'high',
            ],
            [
                'id' => 'bid_support_quality_weak_or_none',
                'label' => 'bid_support_quality weak/none',
                'f' => 'bid_support_quality',
                'op' => 'in',
                'v' => ['weak', 'none'],
            ],
            [
                'id' => 'oi_not_confirmed',
                'label' => 'open_interest_confirmed = false',
                'f' => 'open_interest_confirmed',
                'op' => 'eq',
                'v' => false,
            ],
            [
                'id' => 'single_candle_dominance_ge_65',
                'label' => 'single_candle_dominance >= 65',
                'f' => 'single_candle_dominance_pct',
                'op' => 'gte',
                'v' => 65,
            ],
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
            [
                'id' => 'combo_bad_context_high_ask_wall',
                'label' => 'context_quality bad + ask_wall_risk high',
                'combo' => [['f' => 'context_quality', 'op' => 'eq', 'v' => 'bad'], ['f' => 'ask_wall_risk', 'op' => 'eq', 'v' => 'high']],
            ],
            [
                'id' => 'combo_fast_flip_weak_bid',
                'label' => 'wave fast_flip_chop + bid weak/none',
                'combo' => [['f' => 'wave_regime', 'op' => 'eq', 'v' => 'fast_flip_chop'], ['f' => 'bid_support_quality', 'op' => 'in', 'v' => ['weak', 'none']]],
            ],
            ['id' => 'combo_single_spike_ask_wall_high', 'label' => 'single_spike + ask_wall_risk_high', 'combo' => [['f' => 'micro_impulse_shape', 'op' => 'eq', 'v' => 'single_spike'], ['f' => 'ask_wall_risk', 'op' => 'eq', 'v' => 'high']]],
            ['id' => 'combo_concentrated_fast_flip_chop', 'label' => 'concentrated_growth + fast_flip_chop', 'combo' => [['f' => 'micro_growth_distribution', 'op' => 'eq', 'v' => 'concentrated'], ['f' => 'wave_regime', 'op' => 'eq', 'v' => 'fast_flip_chop']]],
            ['id' => 'combo_after_spike_oi_not_confirmed', 'label' => 'after_spike + oi_not_confirmed', 'combo' => [['f' => 'micro_entry_timing', 'op' => 'eq', 'v' => 'after_spike'], ['f' => 'open_interest_confirmed', 'op' => 'eq', 'v' => false]]],
            ['id' => 'combo_knife_bounce_high_single_candle_dominance', 'label' => 'knife_bounce + high_single_candle_dominance', 'combo' => [['f' => 'post_dump_state', 'op' => 'eq', 'v' => 'knife_bounce'], ['f' => 'single_candle_dominance_pct', 'op' => 'gte', 'v' => 65]]],
            ['id' => 'combo_smooth_birth_bid_support_strong', 'label' => 'smooth_birth + bid_support_strong', 'combo' => [['f' => 'micro_impulse_shape', 'op' => 'eq', 'v' => 'smooth_birth'], ['f' => 'bid_support_quality', 'op' => 'in', 'v' => ['strong', 'medium']]]],
            ['id' => 'combo_controlled_dump_smooth_birth', 'label' => 'controlled_dump + smooth_birth', 'combo' => [['f' => 'dump_shape', 'op' => 'eq', 'v' => 'controlled_dump'], ['f' => 'micro_impulse_shape', 'op' => 'eq', 'v' => 'smooth_birth']]],
            ['id' => 'combo_choppy_dump_fast_flip_chop', 'label' => 'choppy_dump + fast_flip_chop', 'combo' => [['f' => 'dump_shape', 'op' => 'eq', 'v' => 'choppy_dump'], ['f' => 'wave_regime', 'op' => 'eq', 'v' => 'fast_flip_chop']]],
        ];
    }

    /**
     * Mine patterns from eligible outcomes.
     *
     * @param array<array<string,mixed>> $outcomes Pattern-mining-eligible outcome records
     * @param array<string,array<string,mixed>> $featureBySnapshot
     * @param array<string,mixed> $cfg Dynamic learning config
     * @return array{all:list<array<string,mixed>>,bad_patterns:list<array<string,mixed>>}
     */
    public static function mine(array $outcomes, array $cfg, array $featureBySnapshot = []): array
    {
        $rows = [];
        foreach (self::definitions() as $d) {
            $bad = 0;
            $good = 0;
            $neutral = 0;
            $badClose = [];
            $badDd = [];
            $goodClose = [];

            foreach ($outcomes as $o) {
                $f = self::extractFeaturesFromOutcome($o, $featureBySnapshot);
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

        return [
            'all' => $rows,
            'bad_patterns' => array_values(array_filter($rows, static fn(array $r): bool => (int)$r['bad_count'] > 0)),
        ];
    }

    /** @return array<string,mixed> */
    private static function extractFeaturesFromOutcome(array $o, array $featureBySnapshot): array
    {
        $f = (array)($o['entry_snapshot']['entry_features'] ?? []);
        $sid = trim((string)($o['snapshot_id'] ?? $o['entry_snapshot']['snapshot_id'] ?? ''));
        if ($sid !== '' && isset($featureBySnapshot[$sid]) && is_array($featureBySnapshot[$sid])) {
            $f = array_merge($f, (array)$featureBySnapshot[$sid]);
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
            // Re-extract features from context if not pre-computed
            require_once dirname(__DIR__) . '/outcome/outcome_classifier.php';
            return \Modules\DynamicLearning\Analyzers\Outcome\OutcomeClassifier::extractEntryFeatures($ctx);
        }
        return [];
    }
}
