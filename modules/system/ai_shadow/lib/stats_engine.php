<?php
declare(strict_types=1);

/**
 * AiShadowStatsEngine
 *
 * Reads all virtual signals and virtual trades, computes comparative stats,
 * and persists them to storage/stats.json.
 */
final class AiShadowStatsEngine
{
    private AiShadowStateManager $state;

    public function __construct(AiShadowStateManager $state)
    {
        $this->state = $state;
    }

    /**
     * Compute and persist stats. Returns the computed stats array.
     *
     * @param  array<int,array<string,mixed>> $liveClosedTrades  Matching live closed trades (read-only)
     * @return array<string,mixed>
     */
    public function compute(array $liveClosedTrades = []): array
    {
        $virtualSignals = $this->loadJsonDir('storage/virtual_signals');
        $activeTrades   = $this->loadJsonDir('storage/virtual_trades_active');
        $closedTrades   = $this->loadJsonDir('storage/virtual_trades_closed');

        $totalMirroredSignals = count($virtualSignals);
        $totalVirtualTrades   = count($activeTrades) + count($closedTrades);

        // AI decisions
        $aiEntered = 0;
        $aiSkipped = 0;
        foreach ($virtualSignals as $vs) {
            $dec = (string)($vs['ai_decision'] ?? '');
            if ($dec === 'enter') {
                $aiEntered++;
            } elseif ($dec === 'skip') {
                $aiSkipped++;
            }
        }

        // AI performance from closed virtual trades
        $aiWins   = 0;
        $aiRoiSum = 0.0;
        $aiCount  = 0;
        foreach ($closedTrades as $ct) {
            if ((string)($ct['ai_decision'] ?? '') === 'skip') {
                continue;
            }
            $roi = isset($ct['roi']) ? (float)$ct['roi'] : null;
            if ($roi === null) {
                continue;
            }
            $aiCount++;
            $aiRoiSum += $roi;
            if ($roi > 0.0) {
                $aiWins++;
            }
        }

        $aiWinRate = $aiCount > 0 ? round($aiWins / $aiCount, 4) : 0.0;
        $aiAvgRoi  = $aiCount > 0 ? round($aiRoiSum / $aiCount, 6) : 0.0;
        $aiExpectancy = $aiCount > 0
            ? round(($aiWinRate * $aiAvgRoi) - ((1 - $aiWinRate) * abs($aiAvgRoi)), 6)
            : 0.0;

        // Live performance (from matching live closed trades)
        $liveWins   = 0;
        $liveRoiSum = 0.0;
        $liveCount  = 0;
        foreach ($liveClosedTrades as $lt) {
            $roi = isset($lt['roi']) ? (float)$lt['roi'] : null;
            if ($roi === null) {
                // Attempt to compute from close_price / entry_price
                $entryPx = (float)($lt['entry_price'] ?? $lt['avg_entry_price'] ?? 0.0);
                $closePx = (float)($lt['close_price'] ?? $lt['avg_exit_price'] ?? 0.0);
                $side    = (string)($lt['side'] ?? 'short');
                if ($entryPx > 0.0 && $closePx > 0.0) {
                    $roi = $side === 'short'
                        ? ($entryPx - $closePx) / $entryPx
                        : ($closePx - $entryPx) / $entryPx;
                }
            }
            if ($roi === null) {
                continue;
            }
            $liveRoiSum += $roi;
            $liveCount++;
            if ($roi > 0.0) {
                $liveWins++;
            }
        }
        $liveWinRate    = $liveCount > 0 ? round($liveWins / $liveCount, 4) : 0.0;
        $liveAvgRoi     = $liveCount > 0 ? round($liveRoiSum / $liveCount, 6) : 0.0;
        $liveExpectancy = $liveCount > 0
            ? round(($liveWinRate * $liveAvgRoi) - ((1 - $liveWinRate) * abs($liveAvgRoi)), 6)
            : 0.0;

        $liveVsAiDelta = round($liveAvgRoi - $aiAvgRoi, 6);

        // Agreement rate
        $agreementCount    = 0;
        $disagreementCount = 0;
        foreach ($virtualSignals as $vs) {
            $agreement = (string)($vs['agreement'] ?? '');
            if ($agreement === 'agree') {
                $agreementCount++;
            } elseif ($agreement === 'disagree') {
                $disagreementCount++;
            }
        }
        $totalResolved   = $agreementCount + $disagreementCount;
        $agreementRate   = $totalResolved > 0 ? round($agreementCount    / $totalResolved, 4) : 0.0;
        $disagreementRate= $totalResolved > 0 ? round($disagreementCount / $totalResolved, 4) : 0.0;

        $stats = [
            'computed_at'              => time(),
            'total_mirrored_signals'   => $totalMirroredSignals,
            'total_virtual_trades'     => $totalVirtualTrades,
            'total_active_trades'      => count($activeTrades),
            'ai_entered'               => $aiEntered,
            'ai_skipped'               => $aiSkipped,
            'ai_win_rate'              => $aiWinRate,
            'ai_avg_roi'               => $aiAvgRoi,
            'ai_expectancy'            => $aiExpectancy,
            'live_win_rate'            => $liveWinRate,
            'live_avg_roi'             => $liveAvgRoi,
            'live_expectancy'          => $liveExpectancy,
            'live_vs_ai_delta'         => $liveVsAiDelta,
            'agreement_rate'           => $agreementRate,
            'disagreement_rate'        => $disagreementRate,
        ];

        $this->state->writeJson('storage/stats.json', $stats);
        return $stats;
    }

    /**
     * Return the last computed stats from disk.
     *
     * @return array<string,mixed>
     */
    public function getStats(): array
    {
        return $this->state->readJson('storage/stats.json', []);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return array<int,array<string,mixed>>
     */
    private function loadJsonDir(string $relDir): array
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
