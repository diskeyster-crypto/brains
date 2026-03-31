<?php
declare(strict_types=1);

/**
 * AiShadowVirtualLifecycle
 *
 * Manages the lifecycle of virtual trades:
 * open → update (MFE/MAE) → close.
 *
 * Virtual trade id format: vt_{signal_id}
 */
final class AiShadowVirtualLifecycle
{
    private AiShadowStateManager $state;
    private AiShadowJournal      $journal;

    public function __construct(AiShadowStateManager $state, AiShadowJournal $journal)
    {
        $this->state   = $state;
        $this->journal = $journal;
    }

    /**
     * Open a new virtual trade based on an AI decision.
     *
     * @param  array<string,mixed> $signalData
     * @param  array<string,mixed> $aiDecision
     * @return array<string,mixed>
     */
    public function openVirtualTrade(
        string $virtualSignalId,
        array  $signalData,
        array  $aiDecision
    ): array {
        $vtId    = 'vt_' . $virtualSignalId;
        $relPath = 'storage/virtual_trades_active/' . $vtId . '.json';

        $entryPrice = (float)($signalData['entry']['price']
            ?? $signalData['entry_zone_low']
            ?? 0.0);

        $trade = [
            'virtual_trade_id'    => $vtId,
            'source_signal_id'    => $virtualSignalId,
            'live_trade_id'       => (string)($signalData['live_trade_id']        ?? $virtualSignalId),
            'symbol'              => (string)($signalData['symbol']               ?? ''),
            'side'                => (string)($signalData['side']                 ?? ''),
            'pattern_algorithm'   => (string)($signalData['pattern_algorithm']    ?? ''),
            'ai_decision'         => (string)($aiDecision['decision']             ?? 'enter'),
            'ai_confidence'       => (float)($aiDecision['confidence']            ?? 0.0),
            'ai_quality_score'    => (float)($aiDecision['quality_score']         ?? 0.0),
            'ai_reasons'          => (array)($aiDecision['reasons']               ?? []),
            'status'              => 'active',
            'entry_price'         => $entryPrice,
            'ai_entry_price'      => $entryPrice,
            'ai_entry_timestamp'  => time(),
            'live_entry_timestamp'=> (int)($signalData['live_entry_timestamp']    ?? 0),
            'live_entry_price'    => (float)($signalData['live_entry_price']      ?? $entryPrice),
            'current_price'       => $entryPrice,
            'mfe'                 => 0.0,
            'mae'                 => 0.0,
            'roi'                 => 0.0,
            'opened_at'           => time(),
            'closed_at'           => null,
            'close_reason'        => null,
            'exit_price'          => null,
        ];

        $this->state->writeJson($relPath, $trade);

        // Journal: virtual trade opened
        $this->journal->record($virtualSignalId, 'virtual_trade_opened', [
            'virtual_trade_id'   => $vtId,
            'symbol'             => $trade['symbol'],
            'side'               => $trade['side'],
            'pattern_algorithm'  => $trade['pattern_algorithm'],
            'ai_decision'        => $trade['ai_decision'],
            'ai_confidence'      => $trade['ai_confidence'],
            'ai_quality_score'   => $trade['ai_quality_score'],
            'entry_price'        => $entryPrice,
            'live_entry_price'   => $trade['live_entry_price'],
        ]);

        return $trade;
    }

    /**
     * Close a virtual trade and move it to the closed store.
     * Optionally accepts the live trade data to store comparison fields.
     *
     * @param  array<string,mixed> $liveTrade  Live closed trade data (read-only, for comparison fields)
     * @return array<string,mixed>
     */
    public function closeVirtualTrade(
        string $virtualTradeId,
        string $reason,
        float  $exitPrice,
        array  $liveTrade = []
    ): array {
        $activeRel = 'storage/virtual_trades_active/' . $virtualTradeId . '.json';
        $closedRel = 'storage/virtual_trades_closed/' . $virtualTradeId . '.json';

        $trade = $this->state->readJson($activeRel);
        if (empty($trade)) {
            return ['error' => 'virtual_trade_not_found', 'id' => $virtualTradeId];
        }

        $entryPrice = (float)($trade['entry_price'] ?? 0.0);
        $side       = (string)($trade['side'] ?? 'short');

        $roi = $entryPrice > 0.0
            ? $this->calcRoi($entryPrice, $exitPrice, $side)
            : 0.0;

        $now = time();

        $trade['status']            = 'closed';
        $trade['exit_price']        = $exitPrice;
        $trade['close_reason']      = $reason;
        $trade['roi']               = $roi;
        $trade['closed_at']         = $now;

        // AI trade timing fields
        $trade['ai_entry_timestamp'] = (int)($trade['opened_at'] ?? $now);
        $trade['ai_entry_price']     = $entryPrice;
        $trade['ai_exit_timestamp']  = $now;
        $trade['ai_exit_price']      = $exitPrice;
        $trade['ai_exit_reason']     = $reason;

        // Live trade comparison fields
        if (!empty($liveTrade)) {
            $liveEntryPrice  = (float)($liveTrade['entry_price'] ?? $liveTrade['avg_entry_price'] ?? 0.0);
            $liveExitPrice   = (float)($liveTrade['close_price']
                ?? $liveTrade['avg_exit_price']
                ?? $liveTrade['exit_price']
                ?? 0.0);
            $liveCloseReason = (string)($liveTrade['close_reason'] ?? '');
            $liveEntryTs     = $this->extractTs($liveTrade['opened_at'] ?? null)
                ?: (int)($liveTrade['entry_ts'] ?? 0);
            $liveClosedTs    = $this->extractTs($liveTrade['closed_at'] ?? null)
                ?: (int)($liveTrade['closed_ts'] ?? 0);

            $liveRoi = $liveEntryPrice > 0.0 && $liveExitPrice > 0.0
                ? $this->calcRoi($liveEntryPrice, $liveExitPrice, $side)
                : 0.0;

            $trade['live_trade_id']        = (string)($liveTrade['trade_id'] ?? $liveTrade['signal_id'] ?? '');
            $trade['live_entry_timestamp'] = $liveEntryTs;
            $trade['live_entry_price']     = $liveEntryPrice;
            $trade['live_closed_at']       = $liveClosedTs;
            $trade['live_close_reason']    = $liveCloseReason;
            $trade['live_roi']             = $liveRoi;
            $trade['live_roi_reference']   = $liveRoi;

            // Agreement: both decided the same way (both entered)
            $aiDecision = (string)($trade['ai_decision'] ?? 'enter');
            $trade['agreement']  = ($aiDecision === 'enter') ? 'agree' : 'disagree';
            $trade['delta_roi']  = round($roi - $liveRoi, 6);
        }

        $this->state->writeJson($closedRel, $trade);

        // Journal: virtual trade closed + comparison finalized
        $signalId = (string)($trade['source_signal_id'] ?? $virtualTradeId);
        $this->journal->record($signalId, 'virtual_trade_closed', [
            'virtual_trade_id'  => $virtualTradeId,
            'symbol'            => (string)($trade['symbol'] ?? ''),
            'side'              => (string)($trade['side']   ?? ''),
            'pattern_algorithm' => (string)($trade['pattern_algorithm'] ?? ''),
            'ai_decision'       => (string)($trade['ai_decision']  ?? ''),
            'ai_exit_reason'    => $reason,
            'ai_exit_price'     => $exitPrice,
            'roi'               => $roi,
            'mfe'               => (float)($trade['mfe'] ?? 0.0),
            'mae'               => (float)($trade['mae'] ?? 0.0),
        ]);

        if (!empty($liveTrade)) {
            $this->journal->record($signalId, 'comparison_finalized', [
                'virtual_trade_id'  => $virtualTradeId,
                'symbol'            => (string)($trade['symbol'] ?? ''),
                'ai_roi'            => $roi,
                'live_roi'          => $trade['live_roi']          ?? null,
                'delta_roi'         => $trade['delta_roi']         ?? null,
                'agreement'         => $trade['agreement']         ?? 'unknown',
                'live_close_reason' => $trade['live_close_reason'] ?? '',
                'ai_exit_reason'    => $reason,
            ]);
        }

        // Remove from active
        $activePath = $this->state->resolvePath($activeRel);
        if (is_file($activePath)) {
            unlink($activePath);
        }

        return $trade;
    }

    /**
     * Update MFE/MAE for an active virtual trade.
     *
     * @return array<string,mixed>
     */
    public function updateVirtualTrade(string $virtualTradeId, float $currentPrice): array
    {
        $relPath = 'storage/virtual_trades_active/' . $virtualTradeId . '.json';
        $trade   = $this->state->readJson($relPath);

        if (empty($trade)) {
            return ['error' => 'virtual_trade_not_found', 'id' => $virtualTradeId];
        }

        $entryPrice = (float)($trade['entry_price'] ?? 0.0);
        $side       = (string)($trade['side'] ?? 'short');

        $roi = $entryPrice > 0.0 ? $this->calcRoi($entryPrice, $currentPrice, $side) : 0.0;

        $prevMfe = (float)($trade['mfe'] ?? 0.0);
        $prevMae = (float)($trade['mae'] ?? 0.0);

        // MFE = best profit reached; MAE = worst drawdown reached
        $trade['mfe']           = max($prevMfe, $roi);
        $trade['mae']           = min($prevMae, $roi);
        $trade['current_price'] = $currentPrice;
        $trade['roi']           = $roi;

        $this->state->writeJson($relPath, $trade);

        // Journal update (periodic — only if ROI moved significantly)
        $signalId = (string)($trade['source_signal_id'] ?? $virtualTradeId);
        if ($signalId !== '') {
            $this->journal->record($signalId, 'virtual_trade_updated', [
                'virtual_trade_id' => $virtualTradeId,
                'current_price'    => $currentPrice,
                'roi'              => $roi,
                'mfe'              => $trade['mfe'],
                'mae'              => $trade['mae'],
            ]);
        }

        return $trade;
    }
    {
        return $this->listJsonDir('storage/virtual_trades_active');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getClosedTrades(): array
    {
        return $this->listJsonDir('storage/virtual_trades_closed');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function calcRoi(float $entry, float $current, string $side): float
    {
        if ($entry === 0.0) {
            return 0.0;
        }
        return $side === 'short'
            ? round(($entry - $current) / $entry, 6)
            : round(($current - $entry) / $entry, 6);
    }

    /**
     * Extract a unix timestamp from either an int or an ISO date string.
     */
    private function extractTs(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }
        if (is_int($value)) {
            return $value;
        }
        $ts = (int)strtotime((string)$value);
        return $ts > 0 ? $ts : 0;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function listJsonDir(string $relDir): array
    {
        $dir    = $this->state->resolvePath($relDir);
        $result = [];

        if (!is_dir($dir)) {
            return $result;
        }

        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $data = json_decode((string)file_get_contents($file), true);
            if (is_array($data)) {
                $result[] = $data;
            }
        }

        return $result;
    }
}
