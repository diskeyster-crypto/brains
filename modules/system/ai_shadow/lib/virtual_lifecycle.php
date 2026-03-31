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

    public function __construct(AiShadowStateManager $state)
    {
        $this->state = $state;
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
            'virtual_trade_id'   => $vtId,
            'source_signal_id'   => $virtualSignalId,
            'symbol'             => (string)($signalData['symbol'] ?? ''),
            'side'               => (string)($signalData['side'] ?? ''),
            'pattern_algorithm'  => (string)($signalData['pattern_algorithm'] ?? ''),
            'ai_decision'        => (string)($aiDecision['decision'] ?? 'enter'),
            'ai_confidence'      => (float)($aiDecision['confidence'] ?? 0.0),
            'ai_quality_score'   => (float)($aiDecision['quality_score'] ?? 0.0),
            'ai_reasons'         => (array)($aiDecision['reasons'] ?? []),
            'status'             => 'active',
            'entry_price'        => $entryPrice,
            'current_price'      => $entryPrice,
            'mfe'                => 0.0,
            'mae'                => 0.0,
            'roi'                => 0.0,
            'opened_at'          => time(),
            'closed_at'          => null,
            'close_reason'       => null,
            'exit_price'         => null,
        ];

        $this->state->writeJson($relPath, $trade);
        return $trade;
    }

    /**
     * Close a virtual trade and move it to the closed store.
     *
     * @return array<string,mixed>
     */
    public function closeVirtualTrade(
        string $virtualTradeId,
        string $reason,
        float  $exitPrice
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

        $trade['status']      = 'closed';
        $trade['exit_price']  = $exitPrice;
        $trade['close_reason']= $reason;
        $trade['roi']         = $roi;
        $trade['closed_at']   = time();

        $this->state->writeJson($closedRel, $trade);

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
        return $trade;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getActiveTrades(): array
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
