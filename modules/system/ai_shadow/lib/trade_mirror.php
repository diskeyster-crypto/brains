<?php
declare(strict_types=1);

/**
 * AiShadowTradeMirror
 *
 * Mirrors live active trades → virtual trades for AI shadow evaluation.
 * READ-ONLY access to trading_bot storage.
 */
final class AiShadowTradeMirror
{
    private AiShadowStateManager   $state;
    private AiShadowVirtualLifecycle $lifecycle;
    /** @var array<string,mixed> */
    private array $config;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(
        AiShadowStateManager     $state,
        AiShadowVirtualLifecycle $lifecycle,
        array                    $config
    ) {
        $this->state     = $state;
        $this->lifecycle = $lifecycle;
        $this->config    = $config;
    }

    /**
     * Mirror live active trades from trading_bot/storage/trades/active/.
     *
     * @param  string $liveTradesDir  Absolute path to trading_bot/storage (read-only)
     * @return array{processed:int,skipped_pattern:int,newly_tracked:int,updated:int,ai_skipped:int,errors:int}
     */
    public function mirrorTrades(string $liveTradesDir): array
    {
        $allowedPatterns = (array)($this->config['allowed_patterns'] ?? []);
        $allowedSides    = (array)($this->config['allowed_sides']    ?? []);
        $maxPerRun       = (int)($this->config['max_trades_per_run'] ?? 20);

        $counts = [
            'processed'       => 0,
            'skipped_pattern' => 0,
            'newly_tracked'   => 0,
            'updated'         => 0,
            'ai_skipped'      => 0,
            'errors'          => 0,
        ];

        $activeDir = rtrim($liveTradesDir, '/') . '/trades/active';
        if (!is_dir($activeDir)) {
            return $counts;
        }

        $files     = glob($activeDir . '/*.json') ?: [];
        $processed = 0;

        foreach ($files as $file) {
            if ($processed >= $maxPerRun) {
                break;
            }

            $trade = json_decode((string)file_get_contents($file), true);
            if (!is_array($trade)) {
                $counts['errors']++;
                continue;
            }

            $signalId = (string)($trade['signal_id'] ?? $trade['trade_id'] ?? '');
            $pattern  = (string)($trade['pattern_algorithm']
                ?? $trade['signal']['pattern_algorithm']
                ?? '');
            $side     = (string)($trade['side'] ?? $trade['signal']['side'] ?? '');

            if ($signalId === '') {
                $counts['errors']++;
                continue;
            }

            // Filter by allowed patterns and sides
            if (!in_array($pattern, $allowedPatterns, true) || !in_array($side, $allowedSides, true)) {
                $counts['skipped_pattern']++;
                continue;
            }

            try {
                $result = $this->processTrade($signalId, $trade);
                if ($result === 'new') {
                    $counts['newly_tracked']++;
                } elseif ($result === 'updated') {
                    $counts['updated']++;
                } elseif ($result === 'ai_skipped') {
                    $counts['ai_skipped']++;
                }
            } catch (\Throwable $e) {
                $counts['errors']++;
            }

            $counts['processed']++;
            $processed++;
        }

        return $counts;
    }

    /**
     * Mirror live closed trades from trading_bot/storage/trades/closed/.
     * For each live closed trade that has a matching active virtual trade,
     * close the virtual trade and store full comparison fields.
     *
     * @param  string $liveTradesDir  Absolute path to trading_bot/storage (read-only)
     * @return array{processed:int,skipped_no_virtual:int,closed:int,already_closed:int,errors:int}
     */
    public function mirrorClosedLiveTrades(string $liveTradesDir): array
    {
        $allowedPatterns = (array)($this->config['allowed_patterns'] ?? []);
        $allowedSides    = (array)($this->config['allowed_sides']    ?? []);

        $counts = [
            'processed'      => 0,
            'skipped_no_virtual' => 0,
            'closed'         => 0,
            'already_closed' => 0,
            'errors'         => 0,
        ];

        $closedDir = rtrim($liveTradesDir, '/') . '/trades/closed';
        if (!is_dir($closedDir)) {
            return $counts;
        }

        $files = glob($closedDir . '/*.json') ?: [];

        foreach ($files as $file) {
            $liveTrade = json_decode((string)file_get_contents($file), true);
            if (!is_array($liveTrade)) {
                $counts['errors']++;
                continue;
            }

            $signalId = (string)($liveTrade['signal_id'] ?? $liveTrade['trade_id'] ?? '');
            $pattern  = (string)($liveTrade['pattern_algorithm']
                ?? $liveTrade['signal']['pattern_algorithm']
                ?? '');
            $side     = (string)($liveTrade['side'] ?? '');

            if ($signalId === '') {
                $counts['errors']++;
                continue;
            }

            // Only mirror allowed patterns/sides
            if (!in_array($pattern, $allowedPatterns, true) || !in_array($side, $allowedSides, true)) {
                continue;
            }

            $vtId      = 'vt_' . $signalId;
            $activeRel = 'storage/virtual_trades_active/' . $vtId . '.json';
            $closedRel = 'storage/virtual_trades_closed/' . $vtId . '.json';

            $counts['processed']++;

            // Already closed virtually
            if ($this->state->fileExists($closedRel)) {
                $counts['already_closed']++;
                continue;
            }

            // No active virtual trade to close (AI skipped or never mirrored)
            if (!$this->state->fileExists($activeRel)) {
                // Still create a closed record for skipped trades so comparison is stored
                $vSigRel = 'storage/virtual_signals/' . $signalId . '.json';
                if ($this->state->fileExists($vSigRel)) {
                    $vSig = $this->state->readJson($vSigRel);
                    $aiDecision = (string)($vSig['ai_decision'] ?? '');
                    if ($aiDecision === 'skip') {
                        try {
                            $this->closeSkippedVirtualTrade($vtId, $signalId, $vSig, $liveTrade);
                            $counts['closed']++;
                        } catch (\Throwable $e) {
                            $counts['errors']++;
                        }
                    } else {
                        $counts['skipped_no_virtual']++;
                    }
                } else {
                    $counts['skipped_no_virtual']++;
                }
                continue;
            }

            // Close the active virtual trade with full comparison fields
            try {
                $exitPrice   = $this->extractExitPrice($liveTrade);
                $closeReason = (string)($liveTrade['close_reason'] ?? 'live_closed');

                $this->lifecycle->closeVirtualTrade($vtId, $closeReason, $exitPrice, $liveTrade);
                $counts['closed']++;
            } catch (\Throwable $e) {
                $counts['errors']++;
            }
        }

        return $counts;
    }

    // =========================================================================
    // Private
    // =========================================================================

    /**
     * Process a single live active trade.
     *
     * @param  array<string,mixed> $liveTrade
     * @return string  'new'|'updated'|'ai_skipped'|'no_virtual_signal'
     */
    private function processTrade(string $signalId, array $liveTrade): string
    {
        $vtId      = 'vt_' . $signalId;
        $activeRel = 'storage/virtual_trades_active/' . $vtId . '.json';
        $closedRel = 'storage/virtual_trades_closed/' . $vtId . '.json';

        // Check if already closed
        if ($this->state->fileExists($closedRel)) {
            return 'updated';
        }

        // Look up corresponding virtual signal
        $vSigRel = 'storage/virtual_signals/' . $signalId . '.json';
        if (!$this->state->fileExists($vSigRel)) {
            return 'no_virtual_signal';
        }

        $virtualSignal = $this->state->readJson($vSigRel);
        $aiDecision    = (string)($virtualSignal['ai_decision'] ?? '');

        // Already tracked and active – update MFE/MAE
        if ($this->state->fileExists($activeRel)) {
            $currentPrice = $this->extractCurrentPrice($liveTrade);
            if ($currentPrice > 0.0) {
                $this->lifecycle->updateVirtualTrade($vtId, $currentPrice);
            }
            return 'updated';
        }

        // New trade – create virtual trade
        if ($aiDecision === 'skip') {
            // AI said skip: record a skipped virtual trade (active slot, status=skipped)
            $this->createSkippedActiveRecord($vtId, $signalId, $virtualSignal, $liveTrade);
            return 'ai_skipped';
        }

        // AI said enter: open virtual trade
        $aiData = [
            'decision'      => $aiDecision,
            'confidence'    => (float)($virtualSignal['ai_confidence']    ?? 0.0),
            'quality_score' => (float)($virtualSignal['ai_quality_score'] ?? 0.0),
            'reasons'       => (array)($virtualSignal['ai_reasons']       ?? []),
        ];

        $signalData = $this->buildSignalDataFromTrade($liveTrade);
        $signalData['live_trade_id']         = $signalId;
        $signalData['live_entry_timestamp']  = $this->extractEntryTs($liveTrade);
        $signalData['live_entry_price']      = (float)($liveTrade['entry_price'] ?? $liveTrade['avg_entry_price'] ?? 0.0);

        $this->lifecycle->openVirtualTrade($signalId, $signalData, $aiData);
        return 'new';
    }

    /**
     * Create a closed virtual trade record for a live trade that AI had skipped.
     *
     * @param array<string,mixed> $vSig
     * @param array<string,mixed> $liveTrade
     */
    private function closeSkippedVirtualTrade(
        string $vtId,
        string $signalId,
        array  $vSig,
        array  $liveTrade
    ): void {
        $closedRel = 'storage/virtual_trades_closed/' . $vtId . '.json';

        $liveEntryPrice = (float)($liveTrade['entry_price'] ?? $liveTrade['avg_entry_price'] ?? 0.0);
        $liveExitPrice  = $this->extractExitPrice($liveTrade);
        $liveEntryTs    = $this->extractEntryTs($liveTrade);
        $liveClosedTs   = $this->extractClosedTs($liveTrade);
        $liveCloseReason= (string)($liveTrade['close_reason'] ?? '');
        $liveSide       = (string)($liveTrade['side'] ?? 'short');
        $liveRoi        = $liveEntryPrice > 0.0 && $liveExitPrice > 0.0
            ? $this->calcRoi($liveEntryPrice, $liveExitPrice, $liveSide)
            : 0.0;

        $record = [
            'virtual_trade_id'    => $vtId,
            'source_signal_id'    => $signalId,
            'live_trade_id'       => $signalId,
            'symbol'              => (string)($vSig['symbol']            ?? $liveTrade['symbol'] ?? ''),
            'side'                => $liveSide,
            'pattern_algorithm'   => (string)($vSig['pattern_algorithm'] ?? $liveTrade['pattern_algorithm'] ?? ''),
            'ai_decision'         => 'skip',
            'ai_confidence'       => (float)($vSig['ai_confidence']      ?? 0.0),
            'ai_quality_score'    => (float)($vSig['ai_quality_score']   ?? 0.0),
            'ai_reasons'          => (array)($vSig['ai_reasons']         ?? []),
            'status'              => 'closed',
            // AI never entered — no AI trade data
            'entry_price'         => 0.0,
            'ai_entry_timestamp'  => null,
            'ai_entry_price'      => null,
            'ai_exit_timestamp'   => null,
            'ai_exit_price'       => null,
            'ai_exit_reason'      => 'ai_skipped',
            'roi'                 => null,
            'mfe'                 => null,
            'mae'                 => null,
            // Live data
            'live_entry_timestamp'=> $liveEntryTs,
            'live_entry_price'    => $liveEntryPrice,
            'live_closed_at'      => $liveClosedTs,
            'live_close_reason'   => $liveCloseReason,
            'live_roi'            => $liveRoi,
            'live_roi_reference'  => $liveRoi,
            // Comparison
            'agreement'           => 'disagree',
            'delta_roi'           => -$liveRoi,
            'opened_at'           => $liveEntryTs ?: time(),
            'closed_at'           => $liveClosedTs ?: time(),
            'close_reason'        => 'ai_skipped',
        ];

        $this->state->writeJson($closedRel, $record);
    }

    /**
     * Create an active placeholder for AI-skipped trades (status=skipped).
     *
     * @param array<string,mixed> $virtualSignal
     * @param array<string,mixed> $liveTrade
     */
    private function createSkippedActiveRecord(
        string $vtId,
        string $signalId,
        array  $virtualSignal,
        array  $liveTrade
    ): void {
        $relPath = 'storage/virtual_trades_active/' . $vtId . '.json';

        $trade = [
            'virtual_trade_id'    => $vtId,
            'source_signal_id'    => $signalId,
            'live_trade_id'       => $signalId,
            'symbol'              => (string)($virtualSignal['symbol']            ?? $liveTrade['symbol'] ?? ''),
            'side'                => (string)($liveTrade['side']                  ?? ''),
            'pattern_algorithm'   => (string)($virtualSignal['pattern_algorithm'] ?? $liveTrade['pattern_algorithm'] ?? ''),
            'ai_decision'         => 'skip',
            'ai_confidence'       => (float)($virtualSignal['ai_confidence']      ?? 0.0),
            'ai_quality_score'    => (float)($virtualSignal['ai_quality_score']   ?? 0.0),
            'ai_reasons'          => (array)($virtualSignal['ai_reasons']         ?? []),
            'status'              => 'skipped',
            'entry_price'         => (float)($liveTrade['entry_price'] ?? $liveTrade['avg_entry_price'] ?? 0.0),
            'live_entry_timestamp'=> $this->extractEntryTs($liveTrade),
            'live_entry_price'    => (float)($liveTrade['entry_price'] ?? $liveTrade['avg_entry_price'] ?? 0.0),
            'current_price'       => 0.0,
            'mfe'                 => 0.0,
            'mae'                 => 0.0,
            'roi'                 => null,
            'opened_at'           => time(),
            'closed_at'           => null,
            'close_reason'        => 'ai_skipped',
            'exit_price'          => null,
        ];

        $this->state->writeJson($relPath, $trade);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function extractCurrentPrice(array $liveTrade): float
    {
        return (float)($liveTrade['last_price']
            ?? $liveTrade['current_price']
            ?? $liveTrade['mark_price']
            ?? 0.0);
    }

    private function extractExitPrice(array $liveTrade): float
    {
        return (float)($liveTrade['close_price']
            ?? $liveTrade['avg_exit_price']
            ?? $liveTrade['exit_price']
            ?? 0.0);
    }

    private function extractEntryTs(array $liveTrade): int
    {
        if (isset($liveTrade['opened_at'])) {
            $ts = is_int($liveTrade['opened_at'])
                ? $liveTrade['opened_at']
                : (int)strtotime((string)$liveTrade['opened_at']);
            if ($ts > 0) {
                return $ts;
            }
        }
        return (int)($liveTrade['entry_ts'] ?? $liveTrade['created_ts'] ?? 0);
    }

    private function extractClosedTs(array $liveTrade): int
    {
        if (isset($liveTrade['closed_at'])) {
            $ts = is_int($liveTrade['closed_at'])
                ? $liveTrade['closed_at']
                : (int)strtotime((string)$liveTrade['closed_at']);
            if ($ts > 0) {
                return $ts;
            }
        }
        return (int)($liveTrade['closed_ts'] ?? 0);
    }

    private function calcRoi(float $entry, float $exit, string $side): float
    {
        if ($entry === 0.0) {
            return 0.0;
        }
        return $side === 'short'
            ? round(($entry - $exit) / $entry, 6)
            : round(($exit - $entry) / $entry, 6);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildSignalDataFromTrade(array $liveTrade): array
    {
        return [
            'symbol'            => (string)($liveTrade['symbol'] ?? ''),
            'side'              => (string)($liveTrade['side']   ?? ''),
            'pattern_algorithm' => (string)($liveTrade['pattern_algorithm'] ?? ''),
            'entry'             => [
                'price' => (float)($liveTrade['entry_price'] ?? $liveTrade['avg_entry_price'] ?? 0.0),
            ],
        ];
    }
}
