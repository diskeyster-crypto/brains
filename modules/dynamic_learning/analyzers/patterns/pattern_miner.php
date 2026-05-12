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
                'f' => 'smooth_growth_single_candle_dominance_pct',
                'op' => 'gte',
                'v' => 65,
            ],
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
        ];
    }

    /**
     * Mine patterns from eligible outcomes.
     *
     * @param array<array<string,mixed>> $outcomes Pattern-mining-eligible outcome records
     * @param array<string,mixed> $cfg Dynamic learning config
     * @return array{all:list<array<string,mixed>>,bad_patterns:list<array<string,mixed>>}
     */
    public static function mine(array $outcomes, array $cfg): array
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
                $f = self::extractFeaturesFromOutcome($o);
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
    private static function extractFeaturesFromOutcome(array $o): array
    {
        $f = (array)($o['entry_snapshot']['entry_features'] ?? []);
        if ($f !== []) {
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
