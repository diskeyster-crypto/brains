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
     * Mirror live active trades from the trading_bot storage directory.
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

        if (!is_dir($liveTradesDir)) {
            return $counts;
        }

        $files     = glob($liveTradesDir . '/sig_*.json') ?: [];
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
     * Process a single live trade.
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
            // AI said skip: record the skipped virtual trade
            $this->createSkippedVirtualTrade($vtId, $signalId, $virtualSignal, $liveTrade);
            return 'ai_skipped';
        }

        // AI said enter: open virtual trade
        $aiData = [
            'decision'     => $aiDecision,
            'confidence'   => (float)($virtualSignal['ai_confidence']    ?? 0.0),
            'quality_score'=> (float)($virtualSignal['ai_quality_score'] ?? 0.0),
            'reasons'      => (array)($virtualSignal['ai_reasons']       ?? []),
        ];

        $this->lifecycle->openVirtualTrade($signalId, $this->buildSignalDataFromTrade($liveTrade), $aiData);
        return 'new';
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

    /**
     * @param array<string,mixed> $virtualSignal
     * @param array<string,mixed> $liveTrade
     */
    private function createSkippedVirtualTrade(
        string $vtId,
        string $signalId,
        array  $virtualSignal,
        array  $liveTrade
    ): void {
        $relPath = 'storage/virtual_trades_active/' . $vtId . '.json';

        $trade = [
            'virtual_trade_id'  => $vtId,
            'source_signal_id'  => $signalId,
            'symbol'            => (string)($virtualSignal['symbol']            ?? ''),
            'side'              => (string)($virtualSignal['side']              ?? ''),
            'pattern_algorithm' => (string)($virtualSignal['pattern_algorithm'] ?? ''),
            'ai_decision'       => 'skip',
            'ai_confidence'     => (float)($virtualSignal['ai_confidence']      ?? 0.0),
            'ai_quality_score'  => (float)($virtualSignal['ai_quality_score']   ?? 0.0),
            'ai_reasons'        => (array)($virtualSignal['ai_reasons']         ?? []),
            'status'            => 'skipped',
            'entry_price'       => (float)($liveTrade['entry_price'] ?? $liveTrade['avg_entry_price'] ?? 0.0),
            'current_price'     => 0.0,
            'mfe'               => 0.0,
            'mae'               => 0.0,
            'roi'               => null,
            'opened_at'         => time(),
            'closed_at'         => null,
            'close_reason'      => 'ai_skipped',
            'exit_price'        => null,
        ];

        $this->state->writeJson($relPath, $trade);
    }
}
