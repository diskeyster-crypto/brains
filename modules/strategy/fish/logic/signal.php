<?php

declare(strict_types=1);

/**
 * Fish Strategy — Signal Builder
 *
 * Assembles a fully-shaped signal object from the scanner pipeline outputs.
 *
 * The signal contract is designed to be execution-ready even though the
 * execution layer is not wired in v1.  When the Trading Bot is connected
 * it can consume `signals.json` as-is without reshaping the data.
 *
 * Core fields:
 *   strategy_id           — always 'fish'
 *   signal_id             — deterministic: fish_{symbol}_{bar_open_time_ms}_{side}
 *   symbol / side / entry_type / entry_price / stop_price / take_profit_price
 *   breakeven_trigger     — price at which the stop should be moved to entry
 *
 * Geometry diagnostics (added v1.1):
 *   pattern_range         — range_size of the consolidation pattern (used to derive TP/BE)
 *   risk_distance_abs     — |entry - stop|
 *   reward_distance_abs   — |entry - TP|
 *   rr_ratio              — reward / risk
 *   stop_side_valid       — true when stop is on the correct side of entry
 *   tp_side_valid         — true when TP is on the correct side of entry
 *   geometry_valid        — true when all geometry checks passed
 *   geometry_reject_reason— null on valid signals
 */

namespace Modules\Strategy\Fish\Logic;

final class FishSignal
{
    /**
     * Build a signal array.
     *
     * @param  string  $symbol           Instrument symbol
     * @param  string  $side             'long' | 'short'
     * @param  array   $entry            Output of FishEntry::calculate()
     * @param  array   $risk             Output of FishRisk::calculate()
     * @param  array   $level            Liquidity level from FishLiquidityLevel
     * @param  array   $structure        Structure result from FishStructure::analyse()
     * @param  array   $config           Effective strategy config
     * @param  string  $detectedAt       ISO-8601 timestamp
     * @return array                     Complete signal object
     */
    public function build(
        string $symbol,
        string $side,
        array  $entry,
        array  $risk,
        array  $level,
        array  $structure,
        array  $config,
        string $detectedAt
    ): array {
        // Stable signal identity: derived from the actual bar open timestamp of the
        // consolidation pattern.  Same setup → same signal_id across repeated runs.
        $barOpenTime = (int)($level['bar_open_time'] ?? $level['bar_start_idx'] ?? 0);
        $signalId    = sprintf(
            'fish_%s_%d_%s',
            strtolower($symbol),
            $barOpenTime,
            $side
        );

        $configSnapshotId = sprintf(
            'fish_%s',
            date('Ymd_His', strtotime($detectedAt) ?: time())
        );

        return [
            // Identity
            'strategy_id'            => 'fish',
            'signal_id'              => $signalId,
            'symbol'                 => $symbol,
            'side'                   => $side,

            // Entry / exit prices
            'entry_type'             => 'limit',
            'entry_price'            => $entry['entry_price'],
            'stop_price'             => $risk['stop_price'],
            'take_profit_price'      => $risk['take_profit_price'],
            'breakeven_trigger'      => $risk['breakeven_trigger'],

            // Geometry diagnostics
            'pattern_range'          => $level['range_size'],
            'risk_distance_abs'      => $risk['risk_distance_abs'],
            'reward_distance_abs'    => $risk['reward_distance_abs'],
            'rr_ratio'               => $risk['rr_ratio'],
            'stop_side_valid'        => $risk['stop_side_valid'],
            'tp_side_valid'          => $risk['tp_side_valid'],
            'geometry_valid'         => $risk['geometry_valid'],
            'geometry_reject_reason' => $risk['geometry_reject_reason'],

            // Metadata
            'config_snapshot_id'     => $configSnapshotId,
            'owner_strategy'         => $config['owner_strategy'] ?? 'fish',
            'detected_at'            => $detectedAt,

            // Level info
            'level_price'            => $level['level_price'],
            'level_age_bars'         => $level['age_bars'],
            'stop_anchor_price'      => $risk['stop_anchor_price'],

            // Structure info
            'trend_direction'        => $structure['trend_direction'],
            'structure_high'         => $structure['last_swing_high'],
            'structure_low'          => $structure['last_swing_low'],

            // Pattern info
            'liquidity_pattern_bars' => $level['bar_count'],
            'confirming_bar_status'  => $level['status'],

            // Status
            'signal_status'          => 'valid',
            'reject_reason'          => null,
        ];
    }
}
