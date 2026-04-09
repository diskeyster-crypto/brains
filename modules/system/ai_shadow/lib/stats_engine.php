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
    /** @var array<string,mixed> */
    private array $config;

    /**
     * @param array<string,mixed> $config  Module config (used for store_prototypes flag)
     */
    public function __construct(AiShadowStateManager $state, array $config = [])
    {
        $this->state  = $state;
        $this->config = $config;
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
        $aiWins              = 0;
        $aiRoiSum            = 0.0;
        $aiCount             = 0;
        $aiConfOnWinners     = 0.0;
        $aiConfOnLosers      = 0.0;
        $aiWinnersWithConf   = 0;
        $aiLosersWithConf    = 0;
        $aiRunnerCount       = 0; // ROI > runner threshold (> 2x avg or > 2%)
        $aiPrematureCloses   = 0; // closed at loss when MFE > 0.02

        // Per-pattern breakdown
        /** @var array<string,array{wins:int,losses:int,roi_sum:float,count:int}> */
        $patternStats = [];
        // Per-symbol breakdown
        /** @var array<string,array{ai_wins:int,ai_losses:int,ai_roi_sum:float,ai_count:int,live_wins:int,live_losses:int,live_roi_sum:float,live_count:int,agree:int,disagree:int}> */
        $symbolStats = [];

        foreach ($closedTrades as $ct) {
            if ((string)($ct['ai_decision'] ?? '') === 'skip') {
                continue;
            }
            $roi     = isset($ct['roi']) ? (float)$ct['roi'] : null;
            $conf    = (float)($ct['ai_confidence'] ?? 0.0);
            $mfe     = (float)($ct['mfe'] ?? 0.0);
            $pattern = (string)($ct['pattern_algorithm'] ?? '');
            $symbol  = (string)($ct['symbol'] ?? '');

            if ($roi === null) {
                continue;
            }

            $aiCount++;
            $aiRoiSum += $roi;
            $isWin = $roi > 0.0;
            if ($isWin) {
                $aiWins++;
                $aiConfOnWinners += $conf;
                $aiWinnersWithConf++;
                if ($roi > 0.02) {
                    $aiRunnerCount++;
                }
            } else {
                $aiConfOnLosers += $conf;
                $aiLosersWithConf++;
                if ($mfe > 0.02 && $roi < 0.0) {
                    $aiPrematureCloses++;
                }
            }

            // Per-pattern
            if ($pattern !== '') {
                if (!isset($patternStats[$pattern])) {
                    $patternStats[$pattern] = ['wins' => 0, 'losses' => 0, 'roi_sum' => 0.0, 'count' => 0];
                }
                $patternStats[$pattern]['count']++;
                $patternStats[$pattern]['roi_sum'] += $roi;
                if ($isWin) {
                    $patternStats[$pattern]['wins']++;
                } else {
                    $patternStats[$pattern]['losses']++;
                }
            }

            // Per-symbol
            if ($symbol !== '') {
                if (!isset($symbolStats[$symbol])) {
                    $symbolStats[$symbol] = [
                        'ai_wins' => 0, 'ai_losses' => 0, 'ai_roi_sum' => 0.0, 'ai_count' => 0,
                        'live_wins' => 0, 'live_losses' => 0, 'live_roi_sum' => 0.0, 'live_count' => 0,
                        'agree' => 0, 'disagree' => 0,
                    ];
                }
                $symbolStats[$symbol]['ai_count']++;
                $symbolStats[$symbol]['ai_roi_sum'] += $roi;
                if ($isWin) {
                    $symbolStats[$symbol]['ai_wins']++;
                } else {
                    $symbolStats[$symbol]['ai_losses']++;
                }
                $agree = (string)($ct['agreement'] ?? '');
                if ($agree === 'agree') {
                    $symbolStats[$symbol]['agree']++;
                } elseif ($agree === 'disagree') {
                    $symbolStats[$symbol]['disagree']++;
                }
            }
        }

        $aiWinRate    = $aiCount > 0 ? round($aiWins / $aiCount, 4) : 0.0;
        $aiAvgRoi     = $aiCount > 0 ? round($aiRoiSum / $aiCount, 6) : 0.0;
        $aiExpectancy = $aiCount > 0
            ? round(($aiWinRate * $aiAvgRoi) - ((1 - $aiWinRate) * abs($aiAvgRoi)), 6)
            : 0.0;
        $aiAvgConfWinners = $aiWinnersWithConf > 0 ? round($aiConfOnWinners / $aiWinnersWithConf, 4) : 0.0;
        $aiAvgConfLosers  = $aiLosersWithConf  > 0 ? round($aiConfOnLosers  / $aiLosersWithConf,  4) : 0.0;
        $runnerCatchRate  = $aiCount > 0 ? round($aiRunnerCount / $aiCount, 4) : 0.0;
        $prematureCloseRate = ($aiCount - $aiWins) > 0 ? round($aiPrematureCloses / max(1, $aiCount - $aiWins), 4) : 0.0;

        // Live performance (from matching live closed trades)
        $liveWins   = 0;
        $liveRoiSum = 0.0;
        $liveCount  = 0;
        foreach ($liveClosedTrades as $lt) {
            $roi = isset($lt['roi']) ? (float)$lt['roi'] : null;
            if ($roi === null) {
                $entryPx = (float)($lt['entry_price'] ?? $lt['avg_entry_price'] ?? 0.0);
                $closePx = (float)($lt['close_price'] ?? $lt['avg_exit_price']  ?? 0.0);
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

            // Accumulate per-symbol live stats
            $symbol = (string)($lt['symbol'] ?? '');
            if ($symbol !== '' && isset($symbolStats[$symbol])) {
                $symbolStats[$symbol]['live_count']++;
                $symbolStats[$symbol]['live_roi_sum'] += $roi;
                if ($roi > 0.0) {
                    $symbolStats[$symbol]['live_wins']++;
                } else {
                    $symbolStats[$symbol]['live_losses']++;
                }
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

        // False reject rate: AI skipped but live trade was profitable
        $falseRejects = 0;
        $falseAllows  = 0;
        foreach ($closedTrades as $ct) {
            $aiDecision = (string)($ct['ai_decision'] ?? '');
            $liveRoi    = isset($ct['live_roi']) ? (float)$ct['live_roi'] : null;
            $aiRoi      = isset($ct['roi'])      ? (float)$ct['roi']      : null;
            if ($aiDecision === 'skip' && $liveRoi !== null && $liveRoi > 0.0) {
                $falseRejects++;
            }
            if ($aiDecision === 'enter' && $aiRoi !== null && $aiRoi < 0.0) {
                $falseAllows++;
            }
        }
        $totalDecisioned = count($closedTrades);
        $falseRejectRate = $totalDecisioned > 0 ? round($falseRejects / $totalDecisioned, 4) : 0.0;
        $falseAllowRate  = $totalDecisioned > 0 ? round($falseAllows  / $totalDecisioned, 4) : 0.0;

        // Finalize per-pattern stats
        $patternBreakdown = [];
        foreach ($patternStats as $p => $ps) {
            $cnt = $ps['count'];
            $patternBreakdown[$p] = [
                'count'    => $cnt,
                'wins'     => $ps['wins'],
                'losses'   => $ps['losses'],
                'win_rate' => $cnt > 0 ? round($ps['wins'] / $cnt, 4) : 0.0,
                'avg_roi'  => $cnt > 0 ? round($ps['roi_sum'] / $cnt, 6) : 0.0,
            ];
        }

        // Finalize per-symbol stats
        $symbolBreakdown = [];
        foreach ($symbolStats as $sym => $ss) {
            $ac = $ss['ai_count'];
            $lc = $ss['live_count'];
            $tot = $ss['agree'] + $ss['disagree'];
            $symbolBreakdown[$sym] = [
                'ai_count'    => $ac,
                'ai_win_rate' => $ac > 0 ? round($ss['ai_wins'] / $ac, 4) : 0.0,
                'ai_avg_roi'  => $ac > 0 ? round($ss['ai_roi_sum'] / $ac, 6) : 0.0,
                'live_count'  => $lc,
                'live_win_rate' => $lc > 0 ? round($ss['live_wins'] / $lc, 4) : 0.0,
                'live_avg_roi'  => $lc > 0 ? round($ss['live_roi_sum'] / $lc, 6) : 0.0,
                'agree_rate'    => $tot > 0 ? round($ss['agree'] / $tot, 4) : 0.0,
                'disagree_rate' => $tot > 0 ? round($ss['disagree'] / $tot, 4) : 0.0,
            ];
        }

        $stats = [
            'computed_at'               => time(),
            'total_mirrored_signals'    => $totalMirroredSignals,
            'total_virtual_trades'      => $totalVirtualTrades,
            'total_active_trades'       => count($activeTrades),
            'ai_entered'                => $aiEntered,
            'ai_skipped'                => $aiSkipped,
            'ai_win_rate'               => $aiWinRate,
            'ai_avg_roi'                => $aiAvgRoi,
            'ai_expectancy'             => $aiExpectancy,
            'ai_avg_confidence_winners' => $aiAvgConfWinners,
            'ai_avg_confidence_losers'  => $aiAvgConfLosers,
            'runner_catch_rate'         => $runnerCatchRate,
            'premature_close_rate'      => $prematureCloseRate,
            'live_win_rate'             => $liveWinRate,
            'live_avg_roi'              => $liveAvgRoi,
            'live_expectancy'           => $liveExpectancy,
            'live_vs_ai_delta'          => $liveVsAiDelta,
            'agreement_rate'            => $agreementRate,
            'disagreement_rate'         => $disagreementRate,
            'false_reject_rate'         => $falseRejectRate,
            'false_allow_rate'          => $falseAllowRate,
            'pattern_breakdown'         => $patternBreakdown,
            'symbol_breakdown'          => $symbolBreakdown,
        ];

        $this->state->writeJson('storage/stats.json', $stats);

        // Store prototypes if enabled
        if (!empty($this->config['store_prototypes'])) {
            $this->writePrototypes($closedTrades);
        }

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
     * Write prototype artifacts for each closed virtual trade.
     * Trades with positive ROI → storage/prototypes/good/
     * Trades with negative/zero ROI → storage/prototypes/bad/
     * Skipped decisions → storage/prototypes/bad/ (missed opportunity assessment)
     *
     * Each prototype is a structured JSON snapshot (no image required).
     *
     * @param array<int,array<string,mixed>> $closedTrades
     */
    private function writePrototypes(array $closedTrades): void
    {
        foreach ($closedTrades as $ct) {
            $vtId       = (string)($ct['virtual_trade_id'] ?? '');
            $aiDecision = (string)($ct['ai_decision']      ?? '');
            $roi        = isset($ct['roi']) ? (float)$ct['roi'] : null;

            if ($vtId === '') {
                continue;
            }

            // Determine label: good (positive roi for entered, or correctly skipped losing trade)
            // bad (negative roi for entered, or missed profitable trade when skipped)
            if ($aiDecision === 'skip') {
                $liveRoi = isset($ct['live_roi']) ? (float)$ct['live_roi'] : null;
                // skipped a losing live trade = correct skip = good prototype
                // skipped a winning live trade = false reject = bad prototype
                $label  = ($liveRoi !== null && $liveRoi > 0.0) ? 'bad' : 'good';
                $folder = $label;
            } else {
                // entered: positive roi = good, negative = bad
                $folder = ($roi !== null && $roi > 0.0) ? 'good' : 'bad';
                $label  = $folder;
            }

            $relPath = 'storage/prototypes/' . $folder . '/' . $vtId . '.json';

            // Only write if not already written (idempotent)
            if ($this->state->fileExists($relPath)) {
                continue;
            }

            $prototype = [
                'virtual_trade_id'   => $vtId,
                'source_signal_id'   => (string)($ct['source_signal_id']   ?? ''),
                'symbol'             => (string)($ct['symbol']             ?? ''),
                'side'               => (string)($ct['side']               ?? ''),
                'pattern_algorithm'  => (string)($ct['pattern_algorithm']  ?? ''),
                'ai_decision'        => $aiDecision,
                'result_label'       => $label,
                'roi_outcome'        => $roi,
                'live_roi'           => isset($ct['live_roi'])            ? (float)$ct['live_roi']        : null,
                'ai_confidence'      => (float)($ct['ai_confidence']      ?? 0.0),
                'ai_quality_score'   => (float)($ct['ai_quality_score']   ?? 0.0),
                'ai_reasons'         => (array)($ct['ai_reasons']         ?? []),
                'mfe'                => isset($ct['mfe'])                  ? (float)$ct['mfe']             : null,
                'mae'                => isset($ct['mae'])                  ? (float)$ct['mae']             : null,
                'delta_roi'          => isset($ct['delta_roi'])            ? (float)$ct['delta_roi']       : null,
                'agreement'          => (string)($ct['agreement']         ?? ''),
                'live_close_reason'  => (string)($ct['live_close_reason'] ?? ''),
                'ai_exit_reason'     => (string)($ct['ai_exit_reason']    ?? ''),
                'input_snapshot_ref' => (string)($ct['source_signal_id']  ?? ''),
                'stored_at'          => time(),
            ];

            $this->state->writeJson($relPath, $prototype);
        }
    }

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
