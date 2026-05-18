<?php

declare(strict_types=1);

namespace Modules\DynamicLearning\Analyzers\Dump;

use Modules\DynamicLearning\Analyzers\DlHelpers;
use Modules\DynamicLearning\Analyzers\DlMarketData;

final class DumpMicroAnalyzer
{
    /** @param array<string,mixed> $ctx @param array<string,mixed> $cfg @return array<string,mixed> */
    public static function analyze(array $ctx, string $symbol, int $entryTs, DlMarketData $marketData, array $cfg): array
    {
        $lookback = max(20, (int)($cfg['dump_micro_lookback_minutes'] ?? 60));
        $minCandles = max(5, (int)($cfg['dump_micro_min_candles'] ?? 10));
        $res = $marketData->getCandlesBeforeEntry($symbol, $entryTs, $lookback, $minCandles);
        /** @var list<array<string,mixed>> $candles */
        $candles = is_array($res['candles'] ?? null) ? $res['candles'] : [];

        if ($candles === []) {
            $proxy = self::proxyFallback($ctx);
            $proxy['dump_missing_reason'] = 'parser2_history_missing';
            return $proxy;
        }

        $segment = $candles;
        $lowIdx = self::findLowIndex($segment);
        $postLow = array_slice($segment, $lowIdx + 1);
        $segment = array_slice($segment, 0, $lowIdx + 1);

        $depth = self::dumpDepthPct($segment);
        $durationMin = max(1.0, (count($segment) - 1));
        $speed = $depth !== null ? abs($depth) / $durationMin : null;
        $red = 0;
        $green = 0;
        $newLow = 0;
        $largestRed = 0.0;
        $sumRed = 0.0;
        $lowerWicks = [];
        $runningLow = null;
        foreach ($segment as $c) {
            $o = DlHelpers::toFloat($c['open'] ?? null);
            $l = DlHelpers::toFloat($c['low'] ?? null);
            $cl = DlHelpers::toFloat($c['close'] ?? null);
            if ($o === null || $l === null || $cl === null || $o <= 0.0) {
                continue;
            }
            $move = (($cl - $o) / $o) * 100.0;
            if ($move < 0) {
                $red++;
                $abs = abs($move);
                $sumRed += $abs;
                $largestRed = max($largestRed, $abs);
            } elseif ($move > 0) {
                $green++;
            }
            $lowerWicks[] = max(0.0, ((min($o, $cl) - $l) / $o) * 100.0);
            if ($runningLow === null || $l < $runningLow) {
                $runningLow = $l;
                $newLow++;
            }
        }

        $singleDom = $sumRed > 0.0 ? ($largestRed / $sumRed) * 100.0 : null;
        $lowClose = self::segmentLow($segment);
        $reboundPct = null;
        if ($lowClose !== null && $lowClose > 0.0 && $postLow !== []) {
            $last = DlHelpers::toFloat($postLow[count($postLow) - 1]['close'] ?? null);
            if ($last !== null) {
                $reboundPct = (($last - $lowClose) / $lowClose) * 100.0;
            }
        }

        $verticality = self::clamp(
            (($speed ?? 0.0) * 0.5)
            + ((($singleDom ?? 0.0) / 100.0) * 0.3)
            + (min(1.0, abs((float)($depth ?? 0.0)) / 8.0) * 0.2)
        );
        $exhaustion = self::clamp(
            (min(1.0, (float)(DlHelpers::avg($lowerWicks) ?? 0.0) / 1.2) * 0.45)
            + (min(1.0, max(0.0, (float)($reboundPct ?? 0.0)) / 2.0) * 0.35)
            + (min(1.0, $newLow / max(1, count($segment))) * 0.2)
        );
        $bounceRisk = self::clamp(
            ($verticality * 0.45)
            + (min(1.0, max(0.0, (float)($reboundPct ?? 0.0)) / 3.0) * 0.35)
            + ((1.0 - $exhaustion) * 0.2)
        );

        [$dumpShape, $postDumpState] = self::deriveLabels($depth, $singleDom, $verticality, $bounceRisk, $newLow, $reboundPct);

        return [
            'dump_micro_available' => true,
            'dump_micro_proxy_available' => false,
            'dump_micro_real' => true,
            'dump_source' => (string)(($res['source'] ?? 'none') === 'cache' ? 'bybit' : ($res['source'] ?? 'none')),
            'dump_missing_reason' => null,
            'dump_depth_pct' => self::round($depth),
            'dump_duration_minutes' => self::round($durationMin),
            'dump_speed_pct_per_min' => self::round($speed),
            'dump_red_candle_count' => $red,
            'dump_green_candle_count' => $green,
            'dump_single_candle_dominance_pct' => self::round($singleDom),
            'dump_largest_red_candle_share_pct' => self::round($singleDom),
            'dump_lower_wick_avg_pct' => self::round(DlHelpers::avg($lowerWicks)),
            'dump_rebound_after_low_pct' => self::round($reboundPct),
            'dump_new_low_count' => $newLow,
            'dump_verticality_score' => self::round($verticality),
            'dump_exhaustion_score' => self::round($exhaustion),
            'bounce_only_risk_score' => self::round($bounceRisk),
            'dump_shape' => $dumpShape,
            'post_dump_state' => $postDumpState,
        ];
    }

    /** @param array<string,mixed> $ctx @return array<string,mixed> */
    private static function proxyFallback(array $ctx): array
    {
        $dumpPct = DlHelpers::toFloat($ctx['dump_pct'] ?? null);
        return [
            'dump_micro_available' => false,
            'dump_micro_proxy_available' => true,
            'dump_micro_real' => false,
            'dump_source' => 'strategy_signal_context_proxy',
            'dump_depth_pct' => self::round($dumpPct),
            'dump_duration_minutes' => DlHelpers::toFloat($ctx['stabilization_duration_minutes'] ?? null),
            'dump_speed_pct_per_min' => null,
            'dump_red_candle_count' => null,
            'dump_green_candle_count' => null,
            'dump_single_candle_dominance_pct' => null,
            'dump_largest_red_candle_share_pct' => null,
            'dump_lower_wick_avg_pct' => null,
            'dump_rebound_after_low_pct' => null,
            'dump_new_low_count' => null,
            'dump_verticality_score' => $dumpPct !== null ? self::round(min(1.0, abs($dumpPct) / 8.0)) : null,
            'dump_exhaustion_score' => null,
            'bounce_only_risk_score' => null,
            'dump_shape' => 'unknown',
            'post_dump_state' => 'unknown',
        ];
    }

    /** @param list<array<string,mixed>> $segment */
    private static function dumpDepthPct(array $segment): ?float
    {
        $peak = null;
        $low = null;
        foreach ($segment as $c) {
            $h = DlHelpers::toFloat($c['high'] ?? null);
            $l = DlHelpers::toFloat($c['low'] ?? null);
            if ($h !== null) {
                $peak = $peak === null ? $h : max($peak, $h);
            }
            if ($l !== null) {
                $low = $low === null ? $l : min($low, $l);
            }
        }
        if ($peak === null || $low === null || $peak <= 0.0) {
            return null;
        }
        return (($low - $peak) / $peak) * 100.0;
    }

    /** @param list<array<string,mixed>> $segment */
    private static function findLowIndex(array $segment): int
    {
        $idx = 0;
        $best = INF;
        foreach ($segment as $i => $c) {
            $low = DlHelpers::toFloat($c['low'] ?? null);
            if ($low !== null && $low < $best) {
                $best = $low;
                $idx = (int)$i;
            }
        }
        return $idx;
    }

    /** @param list<array<string,mixed>> $segment */
    private static function segmentLow(array $segment): ?float
    {
        $v = null;
        foreach ($segment as $c) {
            $low = DlHelpers::toFloat($c['low'] ?? null);
            if ($low !== null) {
                $v = $v === null ? $low : min($v, $low);
            }
        }
        return $v;
    }

    /** @return array{0:string,1:string} */
    private static function deriveLabels(?float $depth, ?float $singleDom, float $verticality, float $bounceRisk, int $newLow, ?float $reboundPct): array
    {
        $shape = 'unknown';
        if ($depth === null) {
            $shape = 'unknown';
        } elseif (abs($depth) < 2.0) {
            $shape = 'weak_pullback';
        } elseif ($verticality >= 0.7 || ($singleDom ?? 0.0) >= 65.0) {
            $shape = 'vertical_liquidation';
        } elseif ($newLow >= 4) {
            $shape = 'choppy_dump';
        } else {
            $shape = 'controlled_dump';
        }

        $post = 'unknown';
        if ($reboundPct !== null && $bounceRisk >= 0.65 && $reboundPct > 1.2) {
            $post = 'knife_bounce';
        } elseif ($reboundPct !== null && $reboundPct < 0.2 && $newLow >= 3) {
            $post = 'continued_down';
        } elseif ($bounceRisk < 0.65) {
            $post = 'stabilized';
        }
        return [$shape, $post];
    }

    private static function clamp(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }

    private static function round(?float $v): ?float
    {
        return $v === null ? null : round($v, 6);
    }
}
