<?php
declare(strict_types=1);

namespace Modules\System\TradingBot\Lib;

/**
 * BotVerdictEngine
 *
 * Generates a learning verdict record for every closed trade.
 *
 * A verdict answers: was the decision correct? What should the system learn?
 *
 * Required verdict fields (roadmap Phase 3):
 *  decision_id, signal_id, execution_mode, symbol, side, pattern_algorithm,
 *  entry_time, close_time, pnl, roi, peak_roi, max_drawdown, close_reason,
 *  stop_behavior, trailing_behavior, decision_correctness, learning_verdict
 *
 * Allowed learning_verdict values (roadmap):
 *  correct
 *  incorrect
 *  partially_correct
 *  missed_opportunity
 *  entry_right_management_wrong
 *  entry_wrong_skip_would_be_better
 */
final class BotVerdictEngine
{
    private string $storageDir;

    public function __construct(string $storageDir)
    {
        $this->storageDir = rtrim($storageDir, '/');
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Generate a verdict record from a closed trade and optional decision packet.
     *
     * @param string     $tradeId        Storage key for the trade
     * @param array      $closedTrade    Full closed trade record
     * @param array|null $decisionPacket Decision packet (loaded by decision_id, may be null)
     * @return array                     Verdict record
     */
    public function generateVerdict(string $tradeId, array $closedTrade, ?array $decisionPacket): array
    {
        $roi         = (float)($closedTrade['roi'] ?? 0.0);
        $peakRoi     = (float)($closedTrade['mfe'] ?? $closedTrade['best_roi_seen'] ?? 0.0);
        $maxDrawdown = (float)($closedTrade['mae'] ?? $closedTrade['worst_roi_seen'] ?? 0.0);
        $closeReason = (string)($closedTrade['close_reason_normalized']
            ?? $closedTrade['close_reason']
            ?? '');
        $executionMode  = (string)($closedTrade['mode'] ?? $closedTrade['execution_mode'] ?? 'demo');
        $symbol         = strtoupper((string)($closedTrade['symbol'] ?? ''));
        $side           = strtolower((string)($closedTrade['side'] ?? ''));
        $pattern        = (string)($closedTrade['pattern_algorithm'] ?? $closedTrade['pattern'] ?? '');
        $holdMinutes    = (int)($closedTrade['hold_minutes'] ?? 0);
        $entryTime      = (string)($closedTrade['opened_at'] ?? '');
        $closeTime      = (string)($closedTrade['closed_at'] ?? '');
        $decisionId     = (string)($closedTrade['decision_id']
            ?? $decisionPacket['decision_id']
            ?? '');
        $signalId       = (string)($closedTrade['signal_id']
            ?? $decisionPacket['signal_id']
            ?? '');
        $confidenceBand = (string)($closedTrade['confidence_band']
            ?? $decisionPacket['confidence_band']
            ?? 'gray');

        $learningVerdict     = $this->computeLearningVerdict($roi, $peakRoi, $closeReason, $confidenceBand);
        $decisionCorrectness = $this->isDecisionCorrect($learningVerdict);
        $stopBehavior        = $this->analyzeStopBehavior($closedTrade);
        $trailingBehavior    = $this->analyzeTrailingBehavior($closedTrade);

        return [
            // Identity
            'verdict_id'           => $this->generateVerdictId($tradeId),
            'trade_id'             => $tradeId,
            'decision_id'          => $decisionId,
            'signal_id'            => $signalId,

            // Context
            'execution_mode'       => $executionMode,
            'symbol'               => $symbol,
            'side'                 => $side,
            'pattern_algorithm'    => $pattern,
            'confidence_band'      => $confidenceBand,

            // Timeline
            'entry_time'           => $entryTime,
            'close_time'           => $closeTime,
            'hold_minutes'         => $holdMinutes,

            // Outcome
            'pnl'                  => round((float)($closedTrade['pnl'] ?? 0.0), 6),
            'roi'                  => round($roi, 4),
            'peak_roi'             => round($peakRoi, 4),
            'max_drawdown'         => round($maxDrawdown, 4),
            'close_reason'         => $closeReason,

            // Behavior analysis
            'stop_behavior'        => $stopBehavior,
            'trailing_behavior'    => $trailingBehavior,

            // Learning
            'decision_correctness' => $decisionCorrectness,
            'learning_verdict'     => $learningVerdict,

            // Metadata
            'created_at'           => date('c'),
            'ts'                   => time(),
        ];
    }

    /**
     * Save a verdict to storage/runtime/verdicts/{trade_id}.json
     */
    public function saveVerdict(string $tradeId, array $verdict): void
    {
        $dir = $this->storageDir . '/runtime/verdicts';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $path = $dir . '/' . $tradeId . '.json';
        @file_put_contents(
            $path,
            json_encode($verdict, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    /**
     * Load all verdicts for a given symbol (for passport update batch processing).
     *
     * @return list<array>
     */
    public function loadVerdictsForSymbol(string $symbol): array
    {
        $dir = $this->storageDir . '/runtime/verdicts';
        if (!is_dir($dir)) {
            return [];
        }
        $upper    = strtoupper($symbol);
        $verdicts = [];
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $data = json_decode((string)@file_get_contents($file), true);
            if (is_array($data) && strtoupper((string)($data['symbol'] ?? '')) === $upper) {
                $verdicts[] = $data;
            }
        }
        return $verdicts;
    }

    /**
     * Load all verdicts (used for batch passport update or audit).
     *
     * @return list<array>
     */
    public function loadAllVerdicts(): array
    {
        $dir = $this->storageDir . '/runtime/verdicts';
        if (!is_dir($dir)) {
            return [];
        }
        $verdicts = [];
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $data = json_decode((string)@file_get_contents($file), true);
            if (is_array($data)) {
                $verdicts[] = $data;
            }
        }
        return $verdicts;
    }

    // =========================================================================
    // Learning verdict logic
    // =========================================================================

    /**
     * Compute learning_verdict from outcome.
     *
     * Thresholds (pragmatic, adjustable):
     *   roi >= 0.5%       → correct
     *   0 <= roi < 0.5%   → partially_correct
     *   tiny loss (<0.3%) → partially_correct (within noise)
     *   good peak but closed at loss (peak >= 1%) → entry_right_management_wrong
     *   red confidence + loss → entry_wrong_skip_would_be_better
     *   force-closed early while peak was good → entry_right_management_wrong
     *   otherwise loss     → incorrect
     */
    private function computeLearningVerdict(
        float $roi,
        float $peakRoi,
        string $closeReason,
        string $confidenceBand
    ): string {
        $peakWasGood    = $peakRoi >= 1.0;
        $closedAtLoss   = $roi <= -0.30;
        $tinyLoss       = $roi < 0 && $roi > -0.30;
        $smallGain      = $roi >= 0 && $roi < 0.50;
        $goodGain       = $roi >= 0.50;

        // Force-closed by learning/turnover system while peak was solid
        $forceClosed = in_array($closeReason, [
            'learning_timeout_close',
            'turnover_pass_close',
            'healthy_bootstrap_timeout_exceeded',
            'healthy_bootstrap_stale',
            'healthy_bootstrap_turnover_priority',
            'learning_max_age_exceeded',
        ], true);

        if ($forceClosed && $peakRoi >= 1.50) {
            return 'entry_right_management_wrong';
        }

        // Good peak then lost (bad SL placement / trailing)
        if ($peakWasGood && $closedAtLoss) {
            return 'entry_right_management_wrong';
        }

        // Red confidence + loss = the entry was the problem
        if ($confidenceBand === 'red' && $closedAtLoss) {
            return 'entry_wrong_skip_would_be_better';
        }

        if ($goodGain) {
            return 'correct';
        }

        if ($smallGain || $tinyLoss) {
            return 'partially_correct';
        }

        // All remaining: clear loss
        return 'incorrect';
    }

    /**
     * Is the decision considered correct overall?
     */
    private function isDecisionCorrect(string $learningVerdict): bool
    {
        return in_array($learningVerdict, [
            'correct',
            'partially_correct',
            'entry_right_management_wrong',
        ], true);
    }

    // =========================================================================
    // Behavior analysis helpers
    // =========================================================================

    private function analyzeStopBehavior(array $trade): string
    {
        $reason = strtolower((string)($trade['close_reason_normalized'] ?? $trade['close_reason'] ?? ''));

        if (str_contains($reason, 'stop_loss') || str_contains($reason, 'sl_hit')
            || str_contains($reason, 'liquidation') || str_contains($reason, 'logical_stop')) {
            return 'sl_triggered';
        }
        if (str_contains($reason, 'take_profit') || str_contains($reason, 'tp_hit')) {
            return 'tp_triggered';
        }
        if (str_contains($reason, 'timeout') || str_contains($reason, 'stale')
            || str_contains($reason, 'turnover') || str_contains($reason, 'age')) {
            return 'time_based_close';
        }
        if (str_contains($reason, 'trailing')) {
            return 'trailing_triggered';
        }
        return 'other';
    }

    private function analyzeTrailingBehavior(array $trade): string
    {
        $reason = strtolower((string)($trade['close_reason_normalized'] ?? $trade['close_reason'] ?? ''));

        if (str_contains($reason, 'trailing') || !empty($trade['trailing_triggered'])) {
            return 'trailing_triggered';
        }
        if (!empty($trade['trailing_active']) || !empty($trade['trailing_enabled'])) {
            return 'trailing_was_active';
        }
        return 'not_applicable';
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function generateVerdictId(string $tradeId): string
    {
        return 'vrd_' . substr(md5($tradeId . microtime()), 0, 12) . '_' . time();
    }
}
