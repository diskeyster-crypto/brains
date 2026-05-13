<?php

declare(strict_types=1);

namespace Modules\DynamicLearning\Analyzers\Candles;

use Modules\DynamicLearning\Analyzers\DlHelpers;
use Modules\DynamicLearning\Analyzers\DlMarketData;

final class CandleMicroAnalyzer
{
    /**
     * @param array<string,mixed> $ctx
     * @param array<string,mixed> $cfg
     * @return array<string,mixed>
     */
    public static function analyze(array $ctx, string $symbol, int $entryTs, DlMarketData $marketData, array $cfg): array
    {
        $windows = [5, 10, 15];
        $windowStats = [];
        $source = 'none';
        $realAvailable = false;
        $primaryWindowKey = self::normalizePrimaryWindowKey((string)($cfg['micro_primary_window'] ?? 'micro_window_10m'));

        foreach ($windows as $minutes) {
            $res = $marketData->getCandlesBeforeEntry($symbol, $entryTs, $minutes, max(3, min(10, $minutes)));
            /** @var list<array<string,mixed>> $candles */
            $candles = is_array($res['candles'] ?? null) ? $res['candles'] : [];
            if ($candles !== []) {
                $realAvailable = true;
                $source = (string)($res['source'] ?? $source);
                $windowStats['micro_window_' . $minutes . 'm'] = self::calcWindowStats($candles);
            } else {
                $windowStats['micro_window_' . $minutes . 'm'] = self::emptyWindowStats();
            }
        }

        if ($realAvailable) {
            $primary = (array)($windowStats[$primaryWindowKey] ?? self::emptyWindowStats());
            [$shape, $timing, $distribution, $rejection] = self::deriveLabels($primary);
            return [
                'micro_context_available' => true,
                'micro_proxy_available' => false,
                'micro_missing_reason' => null,
                'candle_micro_real' => true,
                'candle_micro_source' => $source === 'cache' ? 'bybit' : $source,
                'candle_micro_windows' => $windowStats,
                'micro_primary_window' => $primaryWindowKey,
                'micro_primary_candles_count' => $primary['candles_count'] ?? 0,
                'micro_total_change_pct' => $primary['total_change_pct'] ?? null,
                'single_candle_dominance_pct' => $primary['single_candle_dominance_pct'] ?? null,
                'largest_candle_share_pct' => $primary['largest_candle_share_pct'] ?? null,
                'largest_candle_change_pct' => $primary['largest_candle_change_pct'] ?? null,
                'higher_close_count' => $primary['higher_close_count'] ?? 0,
                'higher_low_count' => $primary['higher_low_count'] ?? 0,
                'lower_close_count' => $primary['lower_close_count'] ?? 0,
                'lower_low_count' => $primary['lower_low_count'] ?? 0,
                'direction_flip_count' => $primary['direction_flip_count'] ?? 0,
                'pullback_max_pct' => $primary['pullback_max_pct'] ?? null,
                'pullback_count' => $primary['pullback_count'] ?? 0,
                'avg_body_pct' => $primary['avg_body_pct'] ?? null,
                'avg_upper_wick_pct' => $primary['avg_upper_wick_pct'] ?? null,
                'avg_lower_wick_pct' => $primary['avg_lower_wick_pct'] ?? null,
                'max_upper_wick_pct' => $primary['max_upper_wick_pct'] ?? null,
                'max_lower_wick_pct' => $primary['max_lower_wick_pct'] ?? null,
                'smoothness_score' => $primary['smoothness_score'] ?? null,
                'acceleration_score' => $primary['acceleration_score'] ?? null,
                'impulse_birth_score' => $primary['impulse_birth_score'] ?? null,
                'late_spike_risk_score' => $primary['late_spike_risk_score'] ?? null,
                'micro_impulse_shape' => $shape,
                'micro_entry_timing' => $timing,
                'micro_growth_distribution' => $distribution,
                'micro_rejection_risk' => $rejection,
                'micro_higher_close_count' => $primary['higher_close_count'] ?? 0,
                'micro_higher_low_count' => $primary['higher_low_count'] ?? 0,
                'micro_largest_candle_share_pct' => $primary['largest_candle_share_pct'] ?? null,
                'micro_single_candle_dominance_pct' => $primary['single_candle_dominance_pct'] ?? null,
                'micro_pullback_max_pct' => $primary['pullback_max_pct'] ?? null,
                'micro_smoothness_score' => $primary['smoothness_score'] ?? null,
                'micro_impulse_birth_score' => $primary['impulse_birth_score'] ?? null,
                'micro_late_spike_risk_score' => $primary['late_spike_risk_score'] ?? null,
            ];
        }

        $proxy = self::buildProxyFallback($ctx);
        $proxy['micro_missing_reason'] = 'parser2_history_missing';
        $proxy['micro_primary_window'] = $primaryWindowKey;
        $proxy['candle_micro_windows'] = [
            'micro_window_5m' => self::emptyWindowStats(),
            'micro_window_10m' => self::emptyWindowStats(),
            'micro_window_15m' => self::emptyWindowStats(),
        ];
        return $proxy;
    }

    /** @param list<array<string,mixed>> $candles @return array<string,mixed> */
    private static function calcWindowStats(array $candles): array
    {
        $count = count($candles);
        if ($count === 0) {
            return self::emptyWindowStats();
        }

        $first = (array)$candles[0];
        $last = (array)$candles[$count - 1];
        $firstClose = DlHelpers::toFloat($first['close'] ?? null);
        $lastClose = DlHelpers::toFloat($last['close'] ?? null);
        $totalChangePct = ($firstClose !== null && $lastClose !== null && $firstClose > 0)
            ? (($lastClose - $firstClose) / $firstClose) * 100.0
            : null;

        $green = 0;
        $red = 0;
        $flat = 0;
        $higherClose = 0;
        $higherLow = 0;
        $lowerClose = 0;
        $lowerLow = 0;
        $largestChangePct = 0.0;
        $largestSharePct = 0.0;
        $singleDominancePct = 0.0;
        $avgBody = [];
        $avgUpperWick = [];
        $avgLowerWick = [];
        $maxUpperWick = 0.0;
        $maxLowerWick = 0.0;
        $pullbackMaxPct = 0.0;
        $pullbackCount = 0;
        $directionFlipCount = 0;
        $returns = [];
        $largestPositiveMovePct = 0.0;
        $sumPositiveMovePct = 0.0;
        $sumAbsMovePct = 0.0;
        $runningMaxClose = null;
        $prevDirection = 0;
        $prevClose = null;
        $prevLow = null;

        foreach ($candles as $c) {
            $open = DlHelpers::toFloat($c['open'] ?? null);
            $high = DlHelpers::toFloat($c['high'] ?? null);
            $low = DlHelpers::toFloat($c['low'] ?? null);
            $close = DlHelpers::toFloat($c['close'] ?? null);
            if ($open === null || $high === null || $low === null || $close === null || $open <= 0.0) {
                continue;
            }
            $bodyPctSigned = (($close - $open) / $open) * 100.0;
            $bodyPctAbs = abs($bodyPctSigned);
            $upperWickPct = max(0.0, (($high - max($open, $close)) / $open) * 100.0);
            $lowerWickPct = max(0.0, ((min($open, $close) - $low) / $open) * 100.0);

            if ($bodyPctSigned > 0.0) {
                $green++;
                $sumPositiveMovePct += $bodyPctSigned;
                $largestPositiveMovePct = max($largestPositiveMovePct, $bodyPctSigned);
            } elseif ($bodyPctSigned < 0.0) {
                $red++;
            } else {
                $flat++;
            }

            if ($prevClose !== null) {
                if ($close > $prevClose) {
                    $higherClose++;
                } elseif ($close < $prevClose) {
                    $lowerClose++;
                    $pullbackCount++;
                }
                if ($prevClose > 0.0) {
                    $returns[] = (($close - $prevClose) / $prevClose) * 100.0;
                }
            }
            if ($prevLow !== null) {
                if ($low > $prevLow) {
                    $higherLow++;
                } elseif ($low < $prevLow) {
                    $lowerLow++;
                }
            }

            $direction = $bodyPctSigned > 0 ? 1 : ($bodyPctSigned < 0 ? -1 : 0);
            if ($direction !== 0 && $prevDirection !== 0 && $direction !== $prevDirection) {
                $directionFlipCount++;
            }
            if ($direction !== 0) {
                $prevDirection = $direction;
            }

            $largestChangePct = max($largestChangePct, $bodyPctAbs);
            $sumAbsMovePct += $bodyPctAbs;
            $avgBody[] = $bodyPctAbs;
            $avgUpperWick[] = $upperWickPct;
            $avgLowerWick[] = $lowerWickPct;
            $maxUpperWick = max($maxUpperWick, $upperWickPct);
            $maxLowerWick = max($maxLowerWick, $lowerWickPct);

            if ($runningMaxClose === null || $close > $runningMaxClose) {
                $runningMaxClose = $close;
            }
            if ($runningMaxClose !== null && $runningMaxClose > 0.0) {
                $pullback = (($runningMaxClose - $close) / $runningMaxClose) * 100.0;
                $pullbackMaxPct = max($pullbackMaxPct, max(0.0, $pullback));
            }

            $prevClose = $close;
            $prevLow = $low;
        }

        $largestSharePct = $sumAbsMovePct > 0.0 ? ($largestChangePct / $sumAbsMovePct) * 100.0 : 0.0;
        $singleDominancePct = $sumPositiveMovePct > 0.0 ? ($largestPositiveMovePct / $sumPositiveMovePct) * 100.0 : 0.0;

        $retCount = count($returns);
        $firstHalf = $retCount > 1 ? array_slice($returns, 0, (int)floor($retCount / 2)) : [];
        $secondHalf = $retCount > 1 ? array_slice($returns, (int)floor($retCount / 2)) : [];
        $firstAvg = DlHelpers::avg($firstHalf);
        $secondAvg = DlHelpers::avg($secondHalf);
        $accelerationScore = 0.0;
        if ($firstAvg !== null && $secondAvg !== null) {
            $accelerationScore = max(0.0, min(1.0, 0.5 + (($secondAvg - $firstAvg) / 2.0)));
        }

        $smoothnessScore = max(
            0.0,
            min(
                1.0,
                (($higherClose + $higherLow) / max(1, ($count - 1) * 2))
                - (($directionFlipCount + $pullbackCount) / max(1, $count * 2))
            )
        );
        $impulseBirthScore = max(
            0.0,
            min(
                1.0,
                ($smoothnessScore * 0.4)
                + ((max(0.0, (float)($totalChangePct ?? 0.0)) / 4.0) * 0.2)
                + ((1.0 - min(1.0, $singleDominancePct / 100.0)) * 0.2)
                + ((1.0 - min(1.0, $pullbackMaxPct / 5.0)) * 0.2)
            )
        );
        $lateSpikeRiskScore = max(
            0.0,
            min(
                1.0,
                (($singleDominancePct / 100.0) * 0.45)
                + (($largestSharePct / 100.0) * 0.25)
                + (min(1.0, $maxUpperWick / 2.0) * 0.2)
                + (min(1.0, $directionFlipCount / max(1, $count - 1)) * 0.1)
            )
        );

        return [
            'candles_count' => $count,
            'first_close' => $firstClose,
            'last_close' => $lastClose,
            'total_change_pct' => self::round($totalChangePct),
            'green_candle_count' => $green,
            'red_candle_count' => $red,
            'flat_candle_count' => $flat,
            'higher_close_count' => $higherClose,
            'higher_low_count' => $higherLow,
            'lower_close_count' => $lowerClose,
            'lower_low_count' => $lowerLow,
            'largest_candle_change_pct' => self::round($largestChangePct),
            'largest_candle_share_pct' => self::round($largestSharePct),
            'single_candle_dominance_pct' => self::round($singleDominancePct),
            'avg_body_pct' => self::round(DlHelpers::avg($avgBody)),
            'avg_upper_wick_pct' => self::round(DlHelpers::avg($avgUpperWick)),
            'avg_lower_wick_pct' => self::round(DlHelpers::avg($avgLowerWick)),
            'max_upper_wick_pct' => self::round($maxUpperWick),
            'max_lower_wick_pct' => self::round($maxLowerWick),
            'pullback_max_pct' => self::round($pullbackMaxPct),
            'pullback_count' => $pullbackCount,
            'direction_flip_count' => $directionFlipCount,
            'smoothness_score' => self::round($smoothnessScore),
            'acceleration_score' => self::round($accelerationScore),
            'impulse_birth_score' => self::round($impulseBirthScore),
            'late_spike_risk_score' => self::round($lateSpikeRiskScore),
        ];
    }

    /** @return array<string,mixed> */
    private static function emptyWindowStats(): array
    {
        return [
            'candles_count' => 0,
            'first_close' => null,
            'last_close' => null,
            'total_change_pct' => null,
            'green_candle_count' => 0,
            'red_candle_count' => 0,
            'flat_candle_count' => 0,
            'higher_close_count' => 0,
            'higher_low_count' => 0,
            'lower_close_count' => 0,
            'lower_low_count' => 0,
            'largest_candle_change_pct' => null,
            'largest_candle_share_pct' => null,
            'single_candle_dominance_pct' => null,
            'avg_body_pct' => null,
            'avg_upper_wick_pct' => null,
            'avg_lower_wick_pct' => null,
            'max_upper_wick_pct' => null,
            'max_lower_wick_pct' => null,
            'pullback_max_pct' => null,
            'pullback_count' => 0,
            'direction_flip_count' => 0,
            'smoothness_score' => null,
            'acceleration_score' => null,
            'impulse_birth_score' => null,
            'late_spike_risk_score' => null,
        ];
    }

    /** @param array<string,mixed> $w @return array{0:string,1:string,2:string,3:string} */
    private static function deriveLabels(array $w): array
    {
        $candles = (int)($w['candles_count'] ?? 0);
        if ($candles < 3) {
            return ['unknown', 'unknown', 'unknown', 'unknown'];
        }
        $dominance = (float)($w['single_candle_dominance_pct'] ?? 0.0);
        $largestShare = (float)($w['largest_candle_share_pct'] ?? 0.0);
        $higherClose = (int)($w['higher_close_count'] ?? 0);
        $higherLow = (int)($w['higher_low_count'] ?? 0);
        $flips = (int)($w['direction_flip_count'] ?? 0);
        $smoothness = (float)($w['smoothness_score'] ?? 0.0);
        $change = (float)($w['total_change_pct'] ?? 0.0);
        $lateSpike = (float)($w['late_spike_risk_score'] ?? 0.0);
        $maxUpperWick = (float)($w['max_upper_wick_pct'] ?? 0.0);

        $distribution = 'unknown';
        if ($dominance >= 65.0 || $largestShare >= 70.0) {
            $distribution = 'concentrated';
        } elseif ($dominance > 0.0) {
            $distribution = 'distributed';
        }

        $shape = 'unknown';
        if ($change <= 0.0) {
            $shape = 'weak_birth';
        } elseif ($dominance >= 70.0 || $lateSpike >= 0.75) {
            $shape = 'single_spike';
        } elseif ($smoothness >= 0.55 && $higherClose >= 3 && $higherLow >= 2 && $flips <= 2) {
            $shape = 'smooth_birth';
        } elseif ($flips >= 3 || $smoothness < 0.45) {
            $shape = 'choppy_birth';
        } else {
            $shape = 'weak_birth';
        }

        $timing = 'unknown';
        if ($lateSpike >= 0.75 || ($dominance >= 65.0 && $largestShare >= 65.0)) {
            $timing = 'after_spike';
        } elseif ($shape === 'smooth_birth' && $change > 0.4) {
            $timing = 'early';
        } elseif ($change > 1.8) {
            $timing = 'late';
        } else {
            $timing = 'early';
        }

        $rejection = 'unknown';
        if ($maxUpperWick >= 1.2 || $lateSpike >= 0.8) {
            $rejection = 'high';
        } elseif ($maxUpperWick >= 0.7 || $lateSpike >= 0.45) {
            $rejection = 'medium';
        } else {
            $rejection = 'low';
        }

        return [$shape, $timing, $distribution, $rejection];
    }

    /** @param array<string,mixed> $ctx @return array<string,mixed> */
    private static function buildProxyFallback(array $ctx): array
    {
        $dominance = DlHelpers::toFloat($ctx['smooth_growth_single_candle_dominance_pct'] ?? null);
        $higherClose = (int)($ctx['smooth_growth_higher_close_count'] ?? 0);
        $higherLow = (int)($ctx['smooth_growth_higher_low_count'] ?? 0);
        $growth = DlHelpers::toFloat($ctx['smooth_growth_pct'] ?? null);
        $smooth = null;
        if ($higherClose > 0 || $higherLow > 0) {
            $smooth = self::round(min(1.0, ($higherClose + $higherLow) / 10.0));
        }
        $shape = 'unknown';
        $distribution = 'unknown';
        if ($dominance !== null && $dominance >= 65.0) {
            $shape = 'single_spike';
            $distribution = 'concentrated';
        } elseif ($growth !== null && $growth > 0.0) {
            $shape = 'smooth_birth';
            $distribution = 'distributed';
        }
        return [
            'micro_context_available' => false,
            'micro_proxy_available' => true,
            'candle_micro_real' => false,
            'candle_micro_source' => 'strategy_signal_context_proxy',
            'micro_primary_candles_count' => 0,
            'micro_total_change_pct' => self::round($growth),
            'single_candle_dominance_pct' => self::round($dominance),
            'largest_candle_share_pct' => null,
            'largest_candle_change_pct' => null,
            'higher_close_count' => $higherClose,
            'higher_low_count' => $higherLow,
            'lower_close_count' => 0,
            'lower_low_count' => 0,
            'direction_flip_count' => null,
            'pullback_max_pct' => null,
            'pullback_count' => null,
            'avg_body_pct' => null,
            'avg_upper_wick_pct' => null,
            'avg_lower_wick_pct' => null,
            'max_upper_wick_pct' => null,
            'max_lower_wick_pct' => null,
            'smoothness_score' => $smooth,
            'acceleration_score' => null,
            'impulse_birth_score' => $growth !== null ? self::round(min(1.0, max(0.0, $growth / 3.0))) : null,
            'late_spike_risk_score' => $dominance !== null ? self::round(min(1.0, $dominance / 100.0)) : null,
            'micro_impulse_shape' => $shape,
            'micro_entry_timing' => 'unknown',
            'micro_growth_distribution' => $distribution,
            'micro_rejection_risk' => 'unknown',
            'micro_higher_close_count' => $higherClose,
            'micro_higher_low_count' => $higherLow,
            'micro_largest_candle_share_pct' => null,
            'micro_single_candle_dominance_pct' => self::round($dominance),
            'micro_pullback_max_pct' => null,
            'micro_smoothness_score' => $smooth,
            'micro_impulse_birth_score' => $growth !== null ? self::round(min(1.0, max(0.0, $growth / 3.0))) : null,
            'micro_late_spike_risk_score' => $dominance !== null ? self::round(min(1.0, $dominance / 100.0)) : null,
        ];
    }

    private static function normalizePrimaryWindowKey(string $window): string
    {
        $w = strtolower(trim($window));
        if ($w === '') {
            return 'micro_window_10m';
        }
        if (preg_match('/^micro_window_(5|10|15)m$/', $w) === 1) {
            return $w;
        }
        if (in_array($w, ['5m', '10m', '15m'], true)) {
            return 'micro_window_' . str_replace('m', '', $w) . 'm';
        }
        return 'micro_window_10m';
    }

    private static function round(?float $v): ?float
    {
        return $v === null ? null : round($v, 6);
    }
}
