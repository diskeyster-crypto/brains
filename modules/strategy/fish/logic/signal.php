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
 * Signal fields:
 *   strategy_id           — always 'fish'
 *   signal_id             — unique deterministic ID: fish_{symbol}_{level_time}_{side}
 *   symbol                — instrument symbol
 *   side                  — 'long' | 'short'
 *   entry_type            — always 'limit' in v1
 *   entry_price           — limit entry price
 *   stop_price            — stop-loss anchor price
 *   take_profit_price     — take-profit price
 *   breakeven_trigger     — price at which the stop should be moved to entry
 *   config_snapshot_id    — identifier for the config version that produced this signal
 *   owner_strategy        — always 'fish'
 *   detected_at           — ISO-8601 timestamp of this scanner run
 *   level_price           — liquidity level midpoint
 *   level_age_bars        — how many H4 bars old the level is
 *   stop_anchor_price     — same as stop_price (explicit field for transparency)
 *   trend_direction       — structure trend at detection time
 *   structure_high        — last confirmed swing high price
 *   structure_low         — last confirmed swing low price
 *   liquidity_pattern_bars — number of bars in the consolidation
 *   confirming_bar_status — 'confirmed' | 'unconfirmed'
 *   signal_status         — 'valid' (only valid signals are written)
 *   reject_reason         — null for valid signals
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
        $levelTime = (int)($level['bar_start_idx'] ?? 0);
        $signalId  = sprintf(
            'fish_%s_%d_%s',
            strtolower($symbol),
            $levelTime,
            $side
        );

        $configSnapshotId = sprintf(
            'fish_%s',
            date('Ymd_His', strtotime($detectedAt) ?: time())
        );

        return [
            'strategy_id'            => 'fish',
            'signal_id'              => $signalId,
            'symbol'                 => $symbol,
            'side'                   => $side,
            'entry_type'             => 'limit',
            'entry_price'            => $entry['entry_price'],
            'stop_price'             => $risk['stop_price'],
            'take_profit_price'      => $risk['take_profit_price'],
            'breakeven_trigger'      => $risk['breakeven_trigger'],
            'config_snapshot_id'     => $configSnapshotId,
            'owner_strategy'         => $config['owner_strategy'] ?? 'fish',
            'detected_at'            => $detectedAt,
            'level_price'            => $level['level_price'],
            'level_age_bars'         => $level['age_bars'],
            'stop_anchor_price'      => $risk['stop_anchor_price'],
            'trend_direction'        => $structure['trend_direction'],
            'structure_high'         => $structure['last_swing_high'],
            'structure_low'          => $structure['last_swing_low'],
            'liquidity_pattern_bars' => $level['bar_count'],
            'confirming_bar_status'  => $level['status'],
            'signal_status'          => 'valid',
            'reject_reason'          => null,
        ];
    }
}
