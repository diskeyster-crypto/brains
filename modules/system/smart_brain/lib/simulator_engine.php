<?php
declare(strict_types=1);

/**
 * Simulator Engine — Smart Brain Phase 7
 *
 * Real paper-trading engine with state transitions:
 *   WAITING → ACTIVE → CLOSED
 *
 * Tracks ROI, MAE, MFE per active trade.
 * Produces closed trades with duration and reason for Coin Passports.
 * Prevents duplicate waiting/active trades per symbol.
 */
final class SimulatorEngine
{
    /** @var array<string,mixed> */
    private array $cfg;
    private StateManager $state;

    /**
     * @param array<string,mixed> $cfg
     */
    public function __construct(array $cfg, StateManager $state)
    {
        $this->cfg = $cfg;
        $this->state = $state;
    }

    /**
     * Run one full simulator tick.
     *
     * @param array<int,array<string,mixed>> $signals   Signals from risk engine
     * @param array<string,float>            $prices    Symbol→current_price map
     */
    public function tick(array $signals, array $prices): void
    {
        if (($this->cfg['enabled'] ?? false) !== true) {
            return;
        }

        $waiting = $this->state->readJson('storage/simulator/waiting.json', []);
        $active  = $this->state->readJson('storage/simulator/active.json', []);
        $closed  = $this->state->readJson('storage/simulator/closed.json', []);

        // Build sets of symbols already in waiting/active to prevent duplicates
        $waitingSymbols = [];
        foreach ($waiting as $w) {
            $waitingSymbols[(string)($w['symbol'] ?? '')] = true;
        }
        $activeSymbols = [];
        foreach ($active as $a) {
            $activeSymbols[(string)($a['symbol'] ?? '')] = true;
        }

        // 1. Ingest new signals → WAITING (no duplicates)
        foreach ($signals as $signal) {
            $symbol = (string)($signal['symbol'] ?? '');
            if ($symbol === '') {
                continue;
            }
            if (isset($waitingSymbols[$symbol]) || isset($activeSymbols[$symbol])) {
                continue;
            }
            $waiting[] = [
                'symbol'         => $symbol,
                'entry_zone_low' => $signal['entry_zone_low'] ?? null,
                'entry_zone_high'=> $signal['entry_zone_high'] ?? null,
                'budget'         => $signal['budget'] ?? null,
                'leverage'       => $signal['leverage'] ?? null,
                'stoploss'       => $signal['stop_loss'] ?? $signal['stoploss'] ?? null,
                'takeprofit'     => $signal['take_profit'] ?? $signal['takeprofit'] ?? null,
                'status'         => 'waiting',
            ];
            $waitingSymbols[$symbol] = true;
        }

        // 2. WAITING → ACTIVE when price enters entry zone
        $newWaiting = [];
        foreach ($waiting as $w) {
            $symbol = (string)($w['symbol'] ?? '');
            $price  = $prices[$symbol] ?? 0.0;
            $low    = (float)($w['entry_zone_low'] ?? 0.0);
            $high   = (float)($w['entry_zone_high'] ?? 0.0);

            if ($price > 0.0 && $low > 0.0 && $high > 0.0
                && $price >= $low && $price <= $high
                && !isset($activeSymbols[$symbol])
            ) {
                // Activate
                $active[] = [
                    'symbol'        => $symbol,
                    'entry_price'   => $price,
                    'current_price' => $price,
                    'budget'        => $w['budget'] ?? null,
                    'leverage'      => $w['leverage'] ?? null,
                    'stoploss'      => $w['stoploss'] ?? null,
                    'takeprofit'    => $w['takeprofit'] ?? null,
                    'roi'           => 0.0,
                    'mae'           => 0.0,
                    'mfe'           => 0.0,
                    'opened_at'     => date('c'),
                    'status'        => 'active',
                ];
                $activeSymbols[$symbol] = true;
            } else {
                $newWaiting[] = $w;
            }
        }
        $waiting = $newWaiting;

        // 3. Update ACTIVE trades: ROI, MAE, MFE; check SL/TP → CLOSED
        $newActive = [];
        foreach ($active as $a) {
            $symbol     = (string)($a['symbol'] ?? '');
            $entryPrice = (float)($a['entry_price'] ?? 0.0);
            $price      = $prices[$symbol] ?? (float)($a['current_price'] ?? 0.0);
            $stoploss   = (float)($a['stoploss'] ?? 0.0);
            $takeprofit = (float)($a['takeprofit'] ?? 0.0);

            if ($entryPrice <= 0.0) {
                $newActive[] = $a;
                continue;
            }

            $roi = ($price - $entryPrice) / $entryPrice;
            $oldMae = (float)($a['mae'] ?? 0.0);
            $oldMfe = (float)($a['mfe'] ?? 0.0);

            // MAE = worst drawdown (most negative ROI seen)
            $mae = ($roi < 0.0)
                ? max($oldMae, abs($roi))
                : $oldMae;

            // MFE = best unrealised gain
            $mfe = ($roi > 0.0)
                ? max($oldMfe, $roi)
                : $oldMfe;

            $a['current_price'] = $price;
            $a['roi']           = round($roi, 6);
            $a['mae']           = round($mae, 6);
            $a['mfe']           = round($mfe, 6);

            // Check stop-loss
            $closedReason = null;
            if ($stoploss > 0.0 && $roi <= -$stoploss) {
                $closedReason = 'stop_loss';
            }
            // Check take-profit
            if ($closedReason === null && $takeprofit > 0.0 && $roi >= $takeprofit) {
                $closedReason = 'take_profit';
            }

            if ($closedReason !== null) {
                $openedAt = (string)($a['opened_at'] ?? '');
                $closedAt = date('c');
                $duration = 0;
                if ($openedAt !== '') {
                    $diff = strtotime($closedAt) - strtotime($openedAt);
                    if ($diff !== false && $diff > 0) {
                        $duration = (int)round($diff / 60); // minutes
                    }
                }
                $closed[] = [
                    'symbol'      => $symbol,
                    'entry_price' => $entryPrice,
                    'exit_price'  => $price,
                    'budget'      => $a['budget'] ?? null,
                    'leverage'    => $a['leverage'] ?? null,
                    'stoploss'    => $stoploss,
                    'takeprofit'  => $takeprofit,
                    'roi'         => round($roi, 6),
                    'mae'         => round($mae, 6),
                    'mfe'         => round($mfe, 6),
                    'duration'    => $duration,
                    'reason'      => $closedReason,
                    'opened_at'   => $openedAt,
                    'closed_at'   => $closedAt,
                    'status'      => 'closed',
                ];
                // Remove from activeSymbols so new signal can enter
                unset($activeSymbols[$symbol]);
            } else {
                $newActive[] = $a;
            }
        }

        $this->state->writeJson('storage/simulator/waiting.json', $waiting);
        $this->state->writeJson('storage/simulator/active.json', $newActive);
        $this->state->writeJson('storage/simulator/closed.json', $closed);
    }

    /**
     * Compute simulator statistics (Phase 8).
     *
     * @return array<string,mixed>
     */
    public function computeStats(): array
    {
        $active  = $this->state->readJson('storage/simulator/active.json', []);
        $closed  = $this->state->readJson('storage/simulator/closed.json', []);
        $signals = $this->state->readJson('storage/signals.json', []);
        $waiting = $this->state->readJson('storage/simulator/waiting.json', []);

        $totalClosed = count($closed);
        $wins = 0;
        $rois = [];
        $maes = [];
        $mfes = [];
        $durations = [];

        foreach ($closed as $trade) {
            $roi = (float)($trade['roi'] ?? 0.0);
            $rois[] = $roi;
            if ($roi >= 0) {
                $wins++;
            }
            $maes[]      = (float)($trade['mae'] ?? 0.0);
            $mfes[]      = (float)($trade['mfe'] ?? 0.0);
            $durations[] = (float)($trade['duration'] ?? 0.0);
        }

        $winrate    = ($totalClosed > 0) ? round($wins / $totalClosed, 4) : 0.0;
        $averageRoi = ($totalClosed > 0) ? round(array_sum($rois) / $totalClosed, 6) : 0.0;
        $medianMae  = $this->median($maes);
        $medianMfe  = $this->median($mfes);
        $medianDur  = $this->median($durations);

        $totalSignals = count($signals);
        $enteredCount = count($active) + $totalClosed;
        $conversion   = ($totalSignals > 0)
            ? round($enteredCount / $totalSignals, 4)
            : 0.0;

        $stats = [
            'total_trades'               => $totalClosed,
            'winrate'                    => $winrate,
            'average_roi'                => $averageRoi,
            'median_mae'                 => $medianMae,
            'median_mfe'                 => $medianMfe,
            'median_duration'            => $medianDur,
            'signal_to_entry_conversion' => $conversion,
            'waiting_count'              => count($waiting),
            'active_count'               => count($active),
            'closed_count'               => $totalClosed,
            'updated_at'                 => date('c'),
        ];

        $this->state->writeJson('storage/simulator/stats.json', $stats);

        return $stats;
    }

    /**
     * @param array<int,float> $values
     */
    private function median(array $values): float
    {
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        sort($values);
        $mid = intdiv($n, 2);
        if ($n % 2 === 0) {
            return round(($values[$mid - 1] + $values[$mid]) / 2.0, 6);
        }
        return round($values[$mid], 6);
    }
}
