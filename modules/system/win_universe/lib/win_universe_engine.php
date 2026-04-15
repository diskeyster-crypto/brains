<?php
declare(strict_types=1);

/**
 * WinUniverseEngine
 *
 * Core computation engine for the Win Universe module.
 *
 * Responsibilities:
 *   1. Load closed trade records from Trading Bot and Smart Brain simulator sources.
 *   2. Filter trades to the configured lookback window.
 *   3. Compute per-symbol statistics (closed_trades_count, wins_count, losses_count,
 *      recent_avg_roi, best_roi, recent_winrate, last_trade_time).
 *   4. Run qualification logic and produce per-symbol qualification records.
 *
 * Hard constraints (MUST NOT violate):
 *   - Read-only: never writes to Smart Brain, Trading Bot, or Profit Manager storage.
 *   - No exchange calls, no gateway access.
 *   - No AI logic.
 */
final class WinUniverseEngine
{
    /** @var string Absolute path to Trading Bot module root (sibling of this module) */
    private string $botModuleBase;

    /** @var string Absolute path to Smart Brain module root (sibling of this module) */
    private string $brainModuleBase;

    public function __construct(string $moduleBase)
    {
        $this->botModuleBase   = dirname($moduleBase) . '/trading_bot';
        $this->brainModuleBase = dirname($moduleBase) . '/smart_brain';
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Compute per-symbol statistics, qualification results, and win-pool lifecycle.
     *
     * @param array<string,mixed> $config      Merged module config (win_universe block)
     * @param array<string,mixed> $prevPool    Previously persisted win pool state (from win_universe_pool.json)
     * @return array{
     *   symbols: array<string,array<string,mixed>>,
     *   qualified: string[],
     *   near_qualified: string[],
     *   rejected: string[],
     *   win_pool: array<string,array<string,mixed>>,
     *   promotions: array<int,array<string,mixed>>,
     *   demotions: array<int,array<string,mixed>>,
     *   config_used: array<string,mixed>,
     *   sources_used: string[],
     *   computed_at: string,
     *   trade_count_total: int,
     * }
     */
    public function compute(array $config, array $prevPool = []): array
    {
        $ts = date('c');

        $minRoi                  = (float)($config['min_roi_threshold']           ?? 1.5);
        $minAvgRoi               = (float)($config['min_avg_roi']                 ?? 0.0);
        $lookbackDays            = max(1, (int)($config['lookback_days']          ?? 30));
        $minTrades               = max(1, (int)($config['min_closed_trades']      ?? 3));
        $minWinrate              = (float)($config['min_winrate']                 ?? 0.0);
        $expiryDays              = (int)($config['qualification_expiry_days']      ?? 90);
        $demotionStreak          = (int)($config['demotion_loss_streak']          ?? 0);
        $minTargetRoi            = (float)($config['min_target_roi']              ?? 0.0);
        $minWinsAboveTarget      = (int)($config['min_wins_above_target']         ?? 0);
        $maxTimeToTargetMinutes  = (int)($config['max_time_to_target_minutes']    ?? 0);

        $cutoff = time() - ($lookbackDays * 86400);

        // Load raw closed trades from all sources
        [$rawTrades, $sourcesUsed] = $this->loadAllClosedTrades();

        // Compute per-symbol stats from trades within lookback window
        $symbolStats = $this->aggregateBySymbol($rawTrades, $cutoff, $minRoi, $minTargetRoi);

        // Run qualification engine on each symbol
        $results       = [];
        $qualified     = [];
        $nearQualified = [];
        $rejected      = [];

        foreach ($symbolStats as $symbol => $stats) {
            $rec = $this->qualify(
                $symbol, $stats,
                $minRoi, $minAvgRoi, $minTrades, $minWinrate,
                $lookbackDays,
                $minTargetRoi, $minWinsAboveTarget, $maxTimeToTargetMinutes
            );
            $results[$symbol] = $rec;
            if ($rec['qualified']) {
                $qualified[] = $symbol;
            } elseif ($rec['near_qualified']) {
                $nearQualified[] = $symbol;
            } else {
                $rejected[] = $symbol;
            }
        }

        // Sort qualified list by recent_avg_roi descending
        usort($qualified, static function (string $a, string $b) use ($results): int {
            return ($results[$b]['recent_avg_roi'] ?? 0.0) <=> ($results[$a]['recent_avg_roi'] ?? 0.0);
        });
        usort($nearQualified, static function (string $a, string $b) use ($results): int {
            return ($results[$b]['recent_avg_roi'] ?? 0.0) <=> ($results[$a]['recent_avg_roi'] ?? 0.0);
        });
        sort($rejected);

        $tradeCountTotal = array_sum(array_column($symbolStats, 'closed_trades_count_window'));

        // Threshold sensitivity: count how many symbols fail by each criterion
        $sensitivity = $this->buildSensitivityBreakdown($results, $minTrades, $minRoi, $minAvgRoi, $minWinrate);

        // Candidate sensitivity preview: how many would qualify under softer thresholds
        $candidatePreview = $this->buildCandidateSensitivityPreview(
            $symbolStats, $minRoi, $minAvgRoi, $minTrades, $minWinrate, $lookbackDays
        );

        // Two-pool lifecycle: compute promotions, demotions, new win pool state
        [$newPool, $promotions, $demotions] = $this->computeLifecycle(
            $results,
            $prevPool,
            $ts,
            $expiryDays,
            $demotionStreak,
            $minRoi,
            $rawTrades,
            $cutoff
        );

        // Build demotion metadata map from this run's events (symbol → event record)
        $demotionMap = [];
        foreach ($demotions as $ev) {
            $demotionMap[(string)($ev['symbol'] ?? '')] = $ev;
        }

        // Annotate each symbol with pool membership, eligibility, and lifecycle metadata
        foreach ($results as $sym => &$rec) {
            $inPool                    = isset($newPool[$sym]);
            $rec['in_win_pool']        = $inPool;
            $rec['promotion_eligible'] = !$inPool && $rec['qualified'];
            // demotion_eligible = true when the symbol currently sits in the win pool
            // (it is by definition eligible to be demoted if it fails maintenance rules)
            $rec['demotion_eligible']  = $inPool;
            $rec['win_pool_entry']     = $inPool ? $newPool[$sym] : null;

            // Promotion metadata comes from the pool entry (written on promotion)
            if ($inPool) {
                $rec['promotion_reason']    = $newPool[$sym]['promotion_reason'] ?? null;
                $rec['last_promotion_time'] = $newPool[$sym]['promoted_at'] ?? null;
            }

            // Demotion metadata for symbols demoted in this run
            if (isset($demotionMap[$sym])) {
                $rec['demotion_reason']    = $demotionMap[$sym]['reason'];
                $rec['last_demotion_time'] = $demotionMap[$sym]['timestamp'];
            }
        }
        unset($rec);

        // Safety cap diagnostic: warn if an excessive fraction of the universe qualifies.
        // This is purely observational — it does NOT block qualification or change thresholds.
        $totalSeen          = count($results);
        $qualifiedCount     = count($qualified);
        $excessiveThreshold = 0.30; // warn if >30% of seen symbols qualify
        $excessiveQualificationWarning = false;
        $excessiveQualificationNote    = null;
        if ($totalSeen > 0 && $qualifiedCount > 0) {
            $qualifiedFraction = $qualifiedCount / $totalSeen;
            if ($qualifiedFraction > $excessiveThreshold) {
                $excessiveQualificationWarning = true;
                $excessiveQualificationNote = sprintf(
                    'предупреждение: %d из %d символов квалифицированы (%.0f%% > %.0f%% порога) — проверьте пороги',
                    $qualifiedCount,
                    $totalSeen,
                    $qualifiedFraction * 100,
                    $excessiveThreshold * 100
                );
            }
        }

        return [
            'symbols'               => $results,
            'qualified'             => $qualified,
            'near_qualified'        => $nearQualified,
            'rejected'              => $rejected,
            'win_pool'              => $newPool,
            'promotions'            => $promotions,
            'demotions'             => $demotions,
            'config_used'           => [
                'min_roi_threshold'           => $minRoi,
                'min_avg_roi'                 => $minAvgRoi,
                'lookback_days'               => $lookbackDays,
                'min_closed_trades'           => $minTrades,
                'min_winrate'                 => $minWinrate,
                'min_target_roi'              => $minTargetRoi,
                'min_wins_above_target'       => $minWinsAboveTarget,
                'max_time_to_target_minutes'  => $maxTimeToTargetMinutes,
                'qualification_expiry_days'   => $expiryDays,
                'demotion_loss_streak'        => $demotionStreak,
                'win_universe_mode'           => (string)($config['win_universe_mode'] ?? 'shadow'),
                'priority_bonus_enabled'      => (bool)($config['priority_bonus_enabled'] ?? false),
                'priority_bonus_strength'     => (float)($config['priority_bonus_strength'] ?? 0.1),
                // Human-readable display values (ROI thresholds × 100 → %)
                'min_roi_threshold_pct'       => round($minRoi * 100, 2),
                'min_avg_roi_pct'             => round($minAvgRoi * 100, 2),
                'min_target_roi_pct'          => round($minTargetRoi * 100, 2),
            ],
            'sources_used'                         => $sourcesUsed,
            'computed_at'                          => $ts,
            'trade_count_total'                    => (int)$tradeCountTotal,
            'threshold_sensitivity'                => $sensitivity,
            'candidate_sensitivity_preview'        => $candidatePreview,
            'excessive_qualification_warning'      => $excessiveQualificationWarning,
            'excessive_qualification_note'         => $excessiveQualificationNote,
        ];
    }

    // =========================================================================
    // Two-pool lifecycle
    // =========================================================================

    /**
     * Compute promotion/demotion events and update the win pool state.
     *
     * Promotion rules (general → win):
     *   - Symbol is currently qualified AND is NOT already in the win pool.
     *
     * Demotion rules (win → general, any one triggers):
     *   1. Symbol is no longer qualified in the current window.
     *   2. qualification_expiry_days > 0 AND the promotion timestamp is older
     *      than expiry days AND symbol is not currently qualified.
     *   3. demotion_loss_streak > 0 AND the symbol's last N trades within the
     *      lookback window are all losses (roi < min_roi_threshold).
     *
     * @param array<string,array<string,mixed>> $results      Current qualification results
     * @param array<string,array<string,mixed>> $prevPool     Previous win pool state
     * @param string  $ts            ISO timestamp for this run
     * @param int     $expiryDays   Days before expiry demotion triggers (0 = off)
     * @param int     $demotionStreak Consecutive losses to trigger demotion (0 = off)
     * @param float   $minRoi       ROI threshold (defines a "loss")
     * @param array<int,array<string,mixed>> $rawTrades All raw trades (for streak check)
     * @param int     $cutoff       Unix timestamp for lookback window start
     * @return array{0: array<string,array<string,mixed>>, 1: list<array<string,mixed>>, 2: list<array<string,mixed>>}
     */
    private function computeLifecycle(
        array $results,
        array $prevPool,
        string $ts,
        int $expiryDays,
        int $demotionStreak,
        float $minRoi,
        array $rawTrades,
        int $cutoff
    ): array {
        $newPool    = $prevPool;
        $promotions = [];
        $demotions  = [];
        $nowTs      = time();

        // ── Promotions ─────────────────────────────────────────────────────────
        foreach ($results as $sym => $rec) {
            if (!$rec['qualified']) {
                continue;
            }
            if (isset($newPool[$sym])) {
                // Already in pool — update last_revalidated_at
                $newPool[$sym]['last_revalidated_at'] = $ts;
                $newPool[$sym]['last_avg_roi']         = $rec['recent_avg_roi'];
                continue;
            }
            // Promote
            $reason = sprintf(
                'квалифицирован: %d сделок в окне, avg ROI %.2f%%, winrate %.1f%%',
                (int)($rec['closed_trades_window'] ?? 0),
                (float)($rec['recent_avg_roi'] ?? 0) * 100,
                (float)($rec['recent_winrate'] ?? 0) * 100
            );
            $newPool[$sym] = [
                'symbol'              => $sym,
                'promoted_at'         => $ts,
                'last_revalidated_at' => $ts,
                'promotion_reason'    => $reason,
                'last_avg_roi'        => $rec['recent_avg_roi'],
            ];
            $promotions[] = [
                'symbol'    => $sym,
                'event'     => 'promoted',
                'timestamp' => $ts,
                'reason'    => $reason,
                'avg_roi'   => $rec['recent_avg_roi'],
            ];
        }

        // ── Demotions ──────────────────────────────────────────────────────────
        foreach (array_keys($newPool) as $sym) {
            $rec         = $results[$sym] ?? null;
            $poolEntry   = $newPool[$sym];
            $demoteWhy   = null;

            // Rule 1: no longer qualified
            if ($rec === null || !$rec['qualified']) {
                $demoteWhy = $rec !== null
                    ? ('не квалифицирован: ' . ($rec['qualification_reason'] ?? 'критерии не выполнены'))
                    : 'символ не найден в текущем расчёте';
            }

            // Rule 2: expiry
            if ($demoteWhy === null && $expiryDays > 0) {
                $promotedTs = strtotime((string)($poolEntry['promoted_at'] ?? ''));
                if ($promotedTs !== false && $promotedTs > 0) {
                    $ageSeconds = $nowTs - $promotedTs;
                    if ($ageSeconds > ($expiryDays * 86400)) {
                        $demoteWhy = sprintf(
                            'истёк срок квалификации: в пуле %d дн. (лимит %d дн.)',
                            (int)($ageSeconds / 86400),
                            $expiryDays
                        );
                    }
                }
            }

            // Rule 3: loss streak
            if ($demoteWhy === null && $demotionStreak > 0 && $rec !== null) {
                $streak = $this->computeLossStreak($sym, $rawTrades, $cutoff, $minRoi);
                if ($streak >= $demotionStreak) {
                    $demoteWhy = sprintf(
                        '%d убыточных сделок подряд (порог: %d)',
                        $streak,
                        $demotionStreak
                    );
                }
            }

            if ($demoteWhy !== null) {
                $demotions[] = [
                    'symbol'             => $sym,
                    'event'              => 'demoted',
                    'timestamp'          => $ts,
                    'reason'             => $demoteWhy,
                    'was_in_pool_since'  => $poolEntry['promoted_at'] ?? null,
                    'last_avg_roi'       => $poolEntry['last_avg_roi'] ?? null,
                ];
                unset($newPool[$sym]);
            }
        }

        return [$newPool, $promotions, $demotions];
    }

    /**
     * Compute the trailing loss streak for a symbol within the lookback window.
     * Trades are sorted most-recent-first; streak ends when a winning trade is found.
     */
    private function computeLossStreak(string $sym, array $rawTrades, int $cutoff, float $minRoi): int
    {
        $windowTrades = [];
        foreach ($rawTrades as $trade) {
            if (strtoupper((string)($trade['symbol'] ?? '')) !== $sym) {
                continue;
            }
            $closedAt = (int)($trade['closed_at'] ?? 0);
            if ($closedAt < $cutoff) {
                continue;
            }
            $roi = is_numeric($trade['roi'] ?? null) ? (float)$trade['roi'] : null;
            $windowTrades[] = ['closed_at' => $closedAt, 'roi' => $roi];
        }

        if (empty($windowTrades)) {
            return 0;
        }

        // Sort most-recent-first
        usort($windowTrades, static function (array $a, array $b): int {
            return $b['closed_at'] <=> $a['closed_at'];
        });

        $streak = 0;
        foreach ($windowTrades as $t) {
            $roi = $t['roi'];
            if ($roi === null || $roi < $minRoi) {
                $streak++;
            } else {
                break;
            }
        }

        return $streak;
    }

    // =========================================================================
    // Data loading
    // =========================================================================

    /**
     * Load all closed trades from available sources.
     *
     * Sources tried (in order):
     *   1. Trading Bot trades/closed_trades.json (aggregated snapshot)
     *   2. Trading Bot trades/closed/*.json (individual per-trade files)
     *   3. Smart Brain simulator/closed.json
     *
     * @return array{0: array<int,array<string,mixed>>, 1: string[]}
     */
    private function loadAllClosedTrades(): array
    {
        $trades      = [];
        $sourcesUsed = [];

        // ── Source 1 & 2: Trading Bot closed trades ───────────────────────────
        try {
            $botStorageDir = $this->resolveBotStorageDir();
            if ($botStorageDir !== null) {
                $raw = [];

                // Primary: aggregated snapshot
                $aggregatedPath = $botStorageDir . '/trades/closed_trades.json';
                if (is_file($aggregatedPath)) {
                    $content = @file_get_contents($aggregatedPath);
                    if ($content !== false && $content !== '') {
                        $decoded = json_decode($content, true);
                        if (is_array($decoded) && !empty($decoded)) {
                            $raw           = $decoded;
                            $sourcesUsed[] = 'bot_closed_trades_aggregated';
                        }
                    }
                }

                // Secondary: individual per-trade files
                if (empty($raw)) {
                    $closedDir = $botStorageDir . '/trades/closed';
                    if (is_dir($closedDir)) {
                        foreach (glob($closedDir . '/*.json') ?: [] as $path) {
                            $content = @file_get_contents($path);
                            if ($content === false || $content === '') {
                                continue;
                            }
                            $trade = json_decode($content, true);
                            if (is_array($trade) && !empty($trade)) {
                                $raw[] = $trade;
                            }
                        }
                        if (!empty($raw)) {
                            $sourcesUsed[] = 'bot_closed_trades_individual';
                        }
                    }
                }

                foreach ($raw as $trade) {
                    $normalised = $this->normaliseBotTrade($trade, 'bot_closed_trades');
                    if ($normalised !== null) {
                        $trades[] = $normalised;
                    }
                }
            }
        } catch (\Throwable $e) {
            // non-fatal
        }

        // ── Source 3: Smart Brain simulator closed trades ─────────────────────
        try {
            $simPath = $this->brainModuleBase . '/storage/simulator/closed.json';
            if (is_file($simPath)) {
                $content = @file_get_contents($simPath);
                if ($content !== false && $content !== '') {
                    $decoded = json_decode($content, true);
                    if (is_array($decoded) && !empty($decoded)) {
                        $added = 0;
                        foreach ($decoded as $trade) {
                            $normalised = $this->normaliseSimulatorTrade($trade);
                            if ($normalised !== null) {
                                $trades[] = $normalised;
                                $added++;
                            }
                        }
                        if ($added > 0) {
                            $sourcesUsed[] = 'simulator_closed';
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // non-fatal
        }

        // ── Source 4: Smart Brain simulator active trades (positive unrealised ROI) ─
        // Active positions with positive unrealised ROI are current evidence of a winning
        // symbol. Including them allows the win pool to reflect live simulator performance
        // even in bootstrap environments where closed-trade history is sparse.
        // Only positive-ROI active positions are included to keep the pool meaningful.
        try {
            $activeSimPath = $this->brainModuleBase . '/storage/simulator/active.json';
            if (is_file($activeSimPath)) {
                $content = @file_get_contents($activeSimPath);
                if ($content !== false && $content !== '') {
                    $decoded = json_decode($content, true);
                    if (is_array($decoded) && !empty($decoded)) {
                        $added = 0;
                        foreach ($decoded as $trade) {
                            $normalised = $this->normaliseSimulatorActiveTrade($trade);
                            if ($normalised !== null) {
                                $trades[] = $normalised;
                                $added++;
                            }
                        }
                        if ($added > 0) {
                            $sourcesUsed[] = 'simulator_active_winning';
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // non-fatal
        }

        return [$trades, $sourcesUsed];
    }

    /**
     * Normalise a Trading Bot closed trade record.
     *
     * @param array<string,mixed> $trade
     * @return array<string,mixed>|null  null if record is unusable
     */
    private function normaliseBotTrade(array $trade, string $source): ?array
    {
        $sym = (string)($trade['symbol'] ?? '');
        if ($sym === '') {
            return null;
        }

        // Require a closed timestamp
        if (!isset($trade['closed_at']) && !isset($trade['closed_ts'])) {
            return null;
        }

        if (isset($trade['closed_ts']) && is_numeric($trade['closed_ts'])) {
            $closedAt = (int)$trade['closed_ts'];
        } elseif (isset($trade['closed_at'])) {
            $closedAt = is_numeric($trade['closed_at'])
                ? (int)$trade['closed_at']
                : (int)strtotime((string)$trade['closed_at']);
        } else {
            $closedAt = 0;
        }

        if ($closedAt <= 0) {
            return null;
        }

        $roi = isset($trade['roi']) && is_numeric($trade['roi']) ? (float)$trade['roi'] : null;

        // Extract trade duration in minutes from bot trade record.
        $durationMinutes = null;
        if (isset($trade['duration_minutes']) && is_numeric($trade['duration_minutes'])) {
            $durationMinutes = max(0.0, (float)$trade['duration_minutes']);
        } elseif (isset($trade['duration']) && is_numeric($trade['duration'])) {
            $durationMinutes = max(0.0, (float)$trade['duration']);
        } elseif (isset($trade['opened_at']) || isset($trade['opened_ts'])) {
            $openedTs = 0;
            if (isset($trade['opened_ts']) && is_numeric($trade['opened_ts'])) {
                $openedTs = (int)$trade['opened_ts'];
            } elseif (isset($trade['opened_at'])) {
                $openedTs = is_numeric($trade['opened_at'])
                    ? (int)$trade['opened_at']
                    : (int)strtotime((string)$trade['opened_at']);
            }
            if ($openedTs > 0 && $closedAt > $openedTs) {
                $durationMinutes = round(($closedAt - $openedTs) / 60.0, 1);
            }
        }

        return [
            'symbol'           => strtoupper($sym),
            'roi'              => $roi,
            'closed_at'        => $closedAt,
            'duration_minutes' => $durationMinutes,
            'source'           => $source,
        ];
    }

    /**
     * Normalise a Smart Brain simulator closed trade record.
     *
     * @param array<string,mixed> $trade
     * @return array<string,mixed>|null
     */
    private function normaliseSimulatorTrade(array $trade): ?array
    {
        if (!is_array($trade)) {
            return null;
        }
        if (($trade['status'] ?? '') !== 'closed') {
            return null;
        }
        $sym = (string)($trade['symbol'] ?? '');
        if ($sym === '') {
            return null;
        }

        $closedAtRaw = $trade['closed_at'] ?? null;
        if ($closedAtRaw === null) {
            return null;
        }
        $closedAt = is_numeric($closedAtRaw)
            ? (int)$closedAtRaw
            : (int)strtotime((string)$closedAtRaw);
        if ($closedAt <= 0) {
            return null;
        }

        $roi = isset($trade['roi']) && is_numeric($trade['roi']) ? (float)$trade['roi'] : null;

        // Extract trade duration in minutes.
        // The simulator stores 'duration' as integer minutes; fall back to timestamp diff.
        $durationMinutes = null;
        if (isset($trade['duration']) && is_numeric($trade['duration'])) {
            $durationMinutes = max(0.0, (float)$trade['duration']);
        } elseif (isset($trade['opened_at'])) {
            $openedTs = is_numeric($trade['opened_at'])
                ? (int)$trade['opened_at']
                : (int)strtotime((string)$trade['opened_at']);
            if ($openedTs > 0 && $closedAt > $openedTs) {
                $durationMinutes = round(($closedAt - $openedTs) / 60.0, 1);
            }
        }

        return [
            'symbol'           => strtoupper($sym),
            'roi'              => $roi,
            'closed_at'        => $closedAt,
            'duration_minutes' => $durationMinutes,
            'source'           => 'simulator',
        ];
    }

    /**
     * Normalise a Smart Brain simulator active trade record for win-universe evidence.
     *
     * Only active positions with positive unrealised ROI are included. The current
     * timestamp is used as the "closed_at" anchor so the record always falls within
     * the lookback window. This represents current winning performance rather than
     * realised profit, and is intentionally a secondary source behind closed trades.
     *
     * @param array<string,mixed> $trade
     * @return array<string,mixed>|null  null if record is unusable or ROI is non-positive
     */
    private function normaliseSimulatorActiveTrade(array $trade): ?array
    {
        if (!is_array($trade)) {
            return null;
        }
        // Must be an active position, not closed/cancelled
        $status = (string)($trade['status'] ?? '');
        if ($status !== 'active' && $status !== '') {
            // Accept both explicit 'active' and records with no status field
        }
        if ($status !== '' && $status !== 'active') {
            return null;
        }

        $sym = (string)($trade['symbol'] ?? '');
        if ($sym === '') {
            return null;
        }

        $roi = isset($trade['roi']) && is_numeric($trade['roi']) ? (float)$trade['roi'] : null;
        // Only include positions currently winning (positive unrealised ROI)
        if ($roi === null || $roi <= 0.0) {
            return null;
        }

        return [
            'symbol'           => strtoupper($sym),
            'roi'              => $roi,
            'closed_at'        => time(), // Use current timestamp — always within lookback window
            'duration_minutes' => null,   // Active trades have no closed duration
            'source'           => 'simulator_active',
        ];
    }

    // =========================================================================
    // Statistics aggregation
    // =========================================================================

    /**
     * Aggregate closed trades into per-symbol statistics within the lookback window.
     *
     * @param array<int,array<string,mixed>> $trades
     * @param int   $cutoff  Unix timestamp; trades older than this are excluded
     * @param float $minRoi  ROI threshold used to count "wins above threshold"
     * @return array<string,array<string,mixed>>
     */
    private function aggregateBySymbol(array $trades, int $cutoff, float $minRoi, float $minTargetRoi = 0.0): array
    {
        $bySymbol = [];

        foreach ($trades as $trade) {
            $sym      = (string)($trade['symbol'] ?? '');
            $closedAt = (int)($trade['closed_at'] ?? 0);
            $roi      = $trade['roi'];  // float|null

            if ($sym === '' || $closedAt <= 0) {
                continue;
            }

            // Count all-time trades (before cutoff filter)
            if (!isset($bySymbol[$sym])) {
                $bySymbol[$sym] = [
                    'closed_trades_count'        => 0,
                    'closed_trades_count_window' => 0,
                    'wins_count'                 => 0,
                    'losses_count'               => 0,
                    'wins_above_threshold'        => 0,
                    'wins_above_target'           => 0,
                    'target_roi_durations'        => [],  // duration_minutes for target-winning trades
                    'roi_sum'                    => 0.0,
                    'roi_values'                 => [],
                    'best_roi'                   => null,
                    'last_trade_time'            => 0,
                ];
            }

            $bySymbol[$sym]['closed_trades_count']++;

            // Only include in window stats if within lookback
            if ($closedAt < $cutoff) {
                continue;
            }

            $bySymbol[$sym]['closed_trades_count_window']++;

            if ($closedAt > $bySymbol[$sym]['last_trade_time']) {
                $bySymbol[$sym]['last_trade_time'] = $closedAt;
            }

            if ($roi !== null) {
                $bySymbol[$sym]['roi_sum']   += $roi;
                $bySymbol[$sym]['roi_values'][] = $roi;

                if ($bySymbol[$sym]['best_roi'] === null || $roi > $bySymbol[$sym]['best_roi']) {
                    $bySymbol[$sym]['best_roi'] = $roi;
                }

                if ($roi >= $minRoi) {
                    $bySymbol[$sym]['wins_count']++;
                    $bySymbol[$sym]['wins_above_threshold']++;
                } else {
                    $bySymbol[$sym]['losses_count']++;
                }

                // Track wins above the target ROI (higher bar than min_roi_threshold)
                if ($minTargetRoi > 0.0 && $roi >= $minTargetRoi) {
                    $bySymbol[$sym]['wins_above_target']++;
                    $durationMin = $trade['duration_minutes'] ?? null;
                    if ($durationMin !== null && $durationMin > 0) {
                        $bySymbol[$sym]['target_roi_durations'][] = (float)$durationMin;
                    }
                }
            }
        }

        // Compute derived fields
        $result = [];
        foreach ($bySymbol as $sym => $raw) {
            $windowCount = $raw['closed_trades_count_window'];
            $roiValues   = $raw['roi_values'];
            $recentAvgRoi = $windowCount > 0 && !empty($roiValues)
                ? round($raw['roi_sum'] / count($roiValues), 4)
                : null;

            $recentWinrate = $windowCount > 0
                ? round($raw['wins_count'] / $windowCount, 4)
                : null;

            $lastTradeTime = $raw['last_trade_time'] > 0
                ? date('c', $raw['last_trade_time'])
                : null;

            // Time-to-target metrics (only available when target ROI durations were tracked)
            $durations = $raw['target_roi_durations'];
            $avgTimeToTarget    = null;
            $medianTimeToTarget = null;
            $fastestTimeToTarget = null;
            if (!empty($durations)) {
                $avgTimeToTarget     = round(array_sum($durations) / count($durations), 1);
                sort($durations);
                $mid = (int)(count($durations) / 2);
                $medianTimeToTarget  = count($durations) % 2 === 0
                    ? round(($durations[$mid - 1] + $durations[$mid]) / 2.0, 1)
                    : round($durations[$mid], 1);
                $fastestTimeToTarget = round($durations[0], 1);
            }

            $result[$sym] = [
                'symbol'                       => $sym,
                'closed_trades_count'          => $raw['closed_trades_count'],
                'closed_trades_count_window'   => $windowCount,
                'wins_count'                   => $raw['wins_count'],
                'losses_count'                 => $raw['losses_count'],
                'wins_above_threshold'         => $raw['wins_above_threshold'],
                'wins_above_target'            => $raw['wins_above_target'],
                'avg_time_to_target_minutes'   => $avgTimeToTarget,
                'median_time_to_target_minutes' => $medianTimeToTarget,
                'fastest_time_to_target_minutes' => $fastestTimeToTarget,
                'recent_avg_roi'               => $recentAvgRoi,
                'best_roi'                     => $raw['best_roi'],
                'recent_winrate'               => $recentWinrate,
                'last_trade_time'              => $lastTradeTime,
            ];
        }

        return $result;
    }


    // =========================================================================
    // Qualification engine
    // =========================================================================

    /**
     * Determine whether a symbol qualifies for the Win Universe.
     *
     * Qualification criteria (ALL must pass — hard AND logic):
     *   1. min_closed_trades           : symbol must have >= min_closed_trades in the window
     *   2. min_roi_threshold           : recent_avg_roi must be >= min_roi_threshold
     *   3. min_avg_roi                 : recent_avg_roi must be >= min_avg_roi (0 = gate off)
     *   4. min_winrate                 : recent_winrate must be >= min_winrate  (0 = gate off)
     *   5. min_target_roi + min_wins_above_target : wins_above_target >= min_wins_above_target (0 = gate off)
     *   6. max_time_to_target_minutes  : avg time to reach target ROI <= max (0 = gate off)
     *
     * Near-qualified: meets trade count but fails exactly one soft criterion.
     *
     * @param array<string,mixed> $stats
     * @param float               $minRoi                 Per-trade win threshold (defines "win")
     * @param float               $minAvgRoi              Minimum average ROI (0 = off)
     * @param int                 $minTrades              Min closed trades in window
     * @param float               $minWinrate             Min win-rate (0 = disabled)
     * @param int                 $lookbackDays
     * @param float               $minTargetRoi           Target ROI threshold (0 = off)
     * @param int                 $minWinsAboveTarget     Min wins above target (0 = off)
     * @param int                 $maxTimeToTargetMinutes Max avg minutes to reach target (0 = off)
     * @return array<string,mixed>
     */
    private function qualify(
        string $symbol,
        array $stats,
        float $minRoi,
        float $minAvgRoi,
        int $minTrades,
        float $minWinrate,
        int $lookbackDays,
        float $minTargetRoi = 0.0,
        int $minWinsAboveTarget = 0,
        int $maxTimeToTargetMinutes = 0
    ): array {
        $windowCount          = (int)($stats['closed_trades_count_window'] ?? 0);
        $recentAvgRoi         = $stats['recent_avg_roi'];
        $recentWinrate        = $stats['recent_winrate'];
        $winsAbove            = (int)($stats['wins_above_threshold'] ?? 0);
        $winsAboveTarget      = (int)($stats['wins_above_target'] ?? 0);
        $avgTimeToTarget      = $stats['avg_time_to_target_minutes'] ?? null;
        $medianTimeToTarget   = $stats['median_time_to_target_minutes'] ?? null;
        $fastestTimeToTarget  = $stats['fastest_time_to_target_minutes'] ?? null;

        // Gate 1: sufficient trade count (hard gate — must pass to be near-qualified)
        $meetsTradeCount = $windowCount >= $minTrades;

        // Gate 2: average ROI >= per-trade win threshold (main quality gate)
        $meetsRoi = ($recentAvgRoi !== null) && ($recentAvgRoi >= $minRoi);

        // Gate 3: average ROI >= separate min_avg_roi threshold (0 = gate off)
        $avgRoiGateEnabled = $minAvgRoi > 0.0;
        $meetsAvgRoi       = !$avgRoiGateEnabled || (($recentAvgRoi !== null) && ($recentAvgRoi >= $minAvgRoi));

        // Gate 4: win-rate (optional gate, only active if minWinrate > 0)
        $winrateGateEnabled = $minWinrate > 0.0;
        $meetsWinrate       = !$winrateGateEnabled || (($recentWinrate !== null) && ($recentWinrate >= $minWinrate));

        // Gate 5: wins above target ROI (only active if minTargetRoi > 0 AND minWinsAboveTarget > 0)
        $targetRoiGateEnabled  = $minTargetRoi > 0.0 && $minWinsAboveTarget > 0;
        $meetsWinsAboveTarget  = !$targetRoiGateEnabled || ($winsAboveTarget >= $minWinsAboveTarget);

        // Gate 6: speed-to-target (only active if maxTimeToTargetMinutes > 0)
        // When the gate is active:
        //   - null avg_time means no valid duration samples for target hits → FAIL (not a pass)
        //   - avg_time > max → FAIL too_slow_to_target
        //   - avg_time <= max → PASS
        $speedGateEnabled    = $maxTimeToTargetMinutes > 0;
        $meetsSpeedToTarget  = true;
        $speedToTargetStatus = 'not_evaluated';
        $speedToTargetReason = null;
        if ($speedGateEnabled) {
            if ($avgTimeToTarget === null) {
                // Gate is on but no valid duration data for target-hitting trades.
                // Cannot verify speed compliance — treat as a failure so this is explicit.
                $meetsSpeedToTarget  = false;
                $speedToTargetStatus = 'no_valid_samples';
                $speedToTargetReason = 'no_valid_time_to_target_samples';
            } elseif ($avgTimeToTarget > $maxTimeToTargetMinutes) {
                $meetsSpeedToTarget  = false;
                $speedToTargetStatus = 'too_slow';
                $speedToTargetReason = 'too_slow_to_target';
            } else {
                $speedToTargetStatus = 'fast_enough';
            }
        } else {
            $speedToTargetStatus = 'gate_disabled';
        }

        $qualified = $meetsTradeCount
            && $meetsRoi
            && $meetsAvgRoi
            && $meetsWinrate
            && $meetsWinsAboveTarget
            && $meetsSpeedToTarget;

        // Near-qualified: meets trade count but fails exactly one soft criterion
        $softFailCount = 0;
        if (!$meetsRoi) {
            $softFailCount++;
        }
        if ($avgRoiGateEnabled && !$meetsAvgRoi) {
            $softFailCount++;
        }
        if ($winrateGateEnabled && !$meetsWinrate) {
            $softFailCount++;
        }
        if ($targetRoiGateEnabled && !$meetsWinsAboveTarget) {
            $softFailCount++;
        }
        if ($speedGateEnabled && !$meetsSpeedToTarget) {
            $softFailCount++;
        }
        $nearQualified = !$qualified && $meetsTradeCount && $softFailCount === 1;

        // ── Machine-readable failure codes ───────────────────────────────────
        $failureCodes = [];
        if (!$meetsTradeCount) {
            $failureCodes[] = 'insufficient_trade_count';
        }
        if (!$meetsRoi) {
            $failureCodes[] = 'below_min_roi_threshold';
        }
        if ($avgRoiGateEnabled && !$meetsAvgRoi) {
            $failureCodes[] = 'below_min_avg_roi';
        }
        if ($winrateGateEnabled && !$meetsWinrate) {
            $failureCodes[] = 'below_min_winrate';
        }
        if ($targetRoiGateEnabled && !$meetsWinsAboveTarget) {
            $failureCodes[] = 'insufficient_target_wins';
        }
        if ($speedGateEnabled && !$meetsSpeedToTarget) {
            $failureCodes[] = $speedToTargetReason ?? 'too_slow_to_target';
        }

        // ── Distance-to-qualify metrics ──────────────────────────────────────
        // Positive value = still needs this much more to pass gate.
        // Negative or zero = gate already passing (or gate disabled → null).
        $distToMinRoi    = $recentAvgRoi !== null ? round($minRoi - $recentAvgRoi, 4) : null;
        $distToMinAvgRoi = ($avgRoiGateEnabled && $recentAvgRoi !== null)
            ? round($minAvgRoi - $recentAvgRoi, 4)
            : null;
        $distToMinWr = ($winrateGateEnabled && $recentWinrate !== null)
            ? round($minWinrate - $recentWinrate, 4)
            : null;
        $missingTrades = max(0, $minTrades - $windowCount);

        // Wins needed to satisfy min_winrate given current trade count.
        $winsNeeded = 0;
        if ($winrateGateEnabled && $windowCount > 0 && !$meetsWinrate) {
            $winsNeeded = max(0, (int)ceil($minWinrate * $windowCount) - $winsAbove);
        }

        // Additional target wins still needed
        $targetWinsNeeded = $targetRoiGateEnabled
            ? max(0, $minWinsAboveTarget - $winsAboveTarget)
            : 0;

        // ── Human-readable missing requirements (Russian) ──────────────────────
        $missing = [];
        if (!$meetsTradeCount) {
            $missing[] = sprintf(
                'min_closed_trades: нужно %d, есть %d в окне %dд.',
                $minTrades,
                $windowCount,
                $lookbackDays
            );
        }
        if (!$meetsRoi) {
            $roiDisplay = $recentAvgRoi !== null ? number_format($recentAvgRoi * 100, 2) . '%' : 'н/д';
            $missing[] = sprintf(
                'min_roi_threshold: нужно >= %.2f%%, есть %s',
                $minRoi * 100,
                $roiDisplay
            );
        }
        if ($avgRoiGateEnabled && !$meetsAvgRoi) {
            $roiDisplay = $recentAvgRoi !== null ? number_format($recentAvgRoi * 100, 2) . '%' : 'н/д';
            $missing[] = sprintf(
                'min_avg_roi: нужно >= %.2f%%, есть %s',
                $minAvgRoi * 100,
                $roiDisplay
            );
        }
        if ($winrateGateEnabled && !$meetsWinrate) {
            $wrDisplay = $recentWinrate !== null ? number_format($recentWinrate * 100, 1) . '%' : 'н/д';
            $missing[] = sprintf(
                'min_winrate: нужно >= %.1f%%, есть %s',
                $minWinrate * 100,
                $wrDisplay
            );
        }
        if ($targetRoiGateEnabled && !$meetsWinsAboveTarget) {
            $missing[] = sprintf(
                'min_wins_above_target: нужно >= %d побед ROI>= %.2f%%, есть %d',
                $minWinsAboveTarget,
                $minTargetRoi * 100,
                $winsAboveTarget
            );
        }
        if ($speedGateEnabled && !$meetsSpeedToTarget) {
            if ($speedToTargetStatus === 'no_valid_samples') {
                $missing[] = sprintf(
                    'max_time_to_target: нет данных длительности для целевых побед (побед выше цели: %d, но длительность не определена — нет закрытых сделок с временными метками)',
                    $winsAboveTarget
                );
            } else {
                $missing[] = sprintf(
                    'max_time_to_target: нужно <= %d мин., avg %.1f мин.',
                    $maxTimeToTargetMinutes,
                    $avgTimeToTarget ?? 0.0
                );
            }
        }

        // ── Reason string (Russian) ───────────────────────────────────────────
        if ($qualified) {
            $reason = sprintf(
                'квалифицирован: %d сд., avg ROI %.2f%%, winrate %.0f%%%s%s',
                $windowCount,
                ($recentAvgRoi ?? 0.0) * 100,
                ($recentWinrate ?? 0.0) * 100,
                $targetRoiGateEnabled ? sprintf(', побед>цели: %d', $winsAboveTarget) : '',
                ($speedGateEnabled && $avgTimeToTarget !== null) ? sprintf(', avg скорость: %.0f мин.', $avgTimeToTarget) : ''
            );
            $status = 'qualified';
        } elseif ($nearQualified) {
            $reason = 'почти квалифицирован: ' . implode('; ', $missing);
            $status = 'near_qualified';
        } elseif (!$meetsTradeCount) {
            $reason = sprintf(
                'недостаточно сделок: %d из %d в окне %dд.',
                $windowCount,
                $minTrades,
                $lookbackDays
            );
            $status = 'rejected';
        } else {
            $reason = 'отклонён: ' . implode('; ', $missing);
            $status = 'rejected';
        }

        return [
            'symbol'                         => $symbol,
            'qualification_status'           => $status,
            'qualified'                      => $qualified,
            'near_qualified'                 => $nearQualified,
            'qualification_reason'           => $reason,
            'missing_requirements'           => $missing,
            'qualification_failure_codes'    => $failureCodes,
            'wins_above_threshold'           => $winsAbove,
            'wins_above_target'              => $winsAboveTarget,
            'avg_time_to_target_minutes'     => $avgTimeToTarget,
            'median_time_to_target_minutes'  => $medianTimeToTarget,
            'fastest_time_to_target_minutes' => $fastestTimeToTarget,
            'speed_to_target_status'         => $speedToTargetStatus,
            'speed_to_target_reason'         => $speedToTargetReason,
            'recent_trade_count'             => $windowCount,
            'closed_trades_count'            => (int)($stats['closed_trades_count'] ?? 0),
            'closed_trades_window'           => $windowCount,
            'recent_avg_roi'                 => $recentAvgRoi,
            'best_roi'                       => $stats['best_roi'],
            'recent_winrate'                 => $recentWinrate,
            'last_trade_time'                => $stats['last_trade_time'],
            'lookback_window_used'           => $lookbackDays,
            'thresholds_used'                => [
                'min_roi_threshold'          => $minRoi,
                'min_avg_roi'                => $minAvgRoi,
                'min_winrate'                => $minWinrate,
                'min_closed_trades'          => $minTrades,
                'min_target_roi'             => $minTargetRoi,
                'min_wins_above_target'      => $minWinsAboveTarget,
                'max_time_to_target_minutes' => $maxTimeToTargetMinutes,
            ],
            // Distance-to-qualify metrics (positive = still needs this much more to pass)
            // ROI distances are in decimal fraction units (0.01 = 1%).
            'distance_to_min_roi_threshold'  => $distToMinRoi,
            'distance_to_min_avg_roi'        => $distToMinAvgRoi,
            'distance_to_min_winrate'        => $distToMinWr,
            'missing_trade_count'            => $missingTrades,
            'wins_needed_above_threshold'    => $winsNeeded,
            'target_wins_needed'             => $targetWinsNeeded,
            // Legacy flat fields (kept for backwards compatibility)
            'threshold_used'                 => $minRoi,
            'winrate_threshold_used'         => $minWinrate,
            'min_trades_required'            => $minTrades,
        ];
    }

    // =========================================================================
    // Threshold sensitivity breakdown
    // =========================================================================

    /**
     * Build a summary of how many symbols fail by each individual criterion.
     *
     * Useful for diagnosing why qualified_count may be zero.
     *
     * @param array<string,array<string,mixed>> $results
     */
    private function buildSensitivityBreakdown(
        array $results,
        int $minTrades,
        float $minRoi,
        float $minAvgRoi,
        float $minWinrate
    ): array {
        $failByTradeCount      = 0;
        $failByRoi             = 0;
        $failByAvgRoi          = 0;
        $failByWinrate         = 0;
        $failByTargetWins      = 0;
        $failBySpeedToTarget   = 0;
        $failByMissingTimeSamples = 0;
        $failByTradeCountOnly  = 0;
        $failByRoiOnly         = 0;
        $failByAvgRoiOnly      = 0;
        $failByWinrateOnly     = 0;
        $totalRejected         = 0;
        $totalNear             = 0;

        foreach ($results as $rec) {
            if ($rec['qualified']) {
                continue;
            }

            $codes = $rec['qualification_failure_codes'] ?? [];

            if (in_array('insufficient_trade_count', $codes, true)) {
                $failByTradeCount++;
            }
            if (in_array('below_min_roi_threshold', $codes, true)) {
                $failByRoi++;
            }
            if (in_array('below_min_avg_roi', $codes, true)) {
                $failByAvgRoi++;
            }
            if (in_array('below_min_winrate', $codes, true)) {
                $failByWinrate++;
            }
            if (in_array('insufficient_target_wins', $codes, true)) {
                $failByTargetWins++;
            }
            // Both too_slow_to_target and no_valid_time_to_target_samples are speed failures
            if (in_array('too_slow_to_target', $codes, true) || in_array('no_valid_time_to_target_samples', $codes, true)) {
                $failBySpeedToTarget++;
            }
            if (in_array('no_valid_time_to_target_samples', $codes, true)) {
                $failByMissingTimeSamples++;
            }

            // "only" counts: fails exactly that criterion
            $failTC  = in_array('insufficient_trade_count', $codes, true);
            $failRoi = in_array('below_min_roi_threshold', $codes, true);
            $failAvg = in_array('below_min_avg_roi', $codes, true);
            $failWr  = in_array('below_min_winrate', $codes, true);
            $otherFails = in_array('insufficient_target_wins', $codes, true)
                || in_array('too_slow_to_target', $codes, true)
                || in_array('no_valid_time_to_target_samples', $codes, true);

            if ($failTC && !$failRoi && !$failAvg && !$failWr && !$otherFails) {
                $failByTradeCountOnly++;
            }
            if (!$failTC && $failRoi && !$failAvg && !$failWr && !$otherFails) {
                $failByRoiOnly++;
            }
            if (!$failTC && !$failRoi && $failAvg && !$failWr && !$otherFails) {
                $failByAvgRoiOnly++;
            }
            if (!$failTC && !$failRoi && !$failAvg && $failWr && !$otherFails) {
                $failByWinrateOnly++;
            }

            if ($rec['near_qualified']) {
                $totalNear++;
            } else {
                $totalRejected++;
            }
        }

        return [
            'fail_by_trade_count'           => $failByTradeCount,
            'fail_by_roi_threshold'         => $failByRoi,
            'fail_by_avg_roi'               => $failByAvgRoi,
            'fail_by_winrate'               => $failByWinrate,
            'fail_by_target_wins'           => $failByTargetWins,
            'fail_by_speed_to_target'       => $failBySpeedToTarget,
            'fail_by_missing_time_samples'  => $failByMissingTimeSamples,
            'fail_by_trade_count_only'      => $failByTradeCountOnly,
            'fail_by_roi_only'              => $failByRoiOnly,
            'fail_by_avg_roi_only'          => $failByAvgRoiOnly,
            'fail_by_winrate_only'          => $failByWinrateOnly,
            'near_qualified_total'          => $totalNear,
            'rejected_total'                => $totalRejected,
            // Standardized naming convention (problem-statement aligned)
            'failed_by_min_trades_count'    => $failByTradeCount,
            'failed_by_roi_threshold_count' => $failByRoi,
            'failed_by_avg_roi_count'       => $failByAvgRoi,
            'failed_by_winrate_count'       => $failByWinrate,
            'failed_by_target_wins_count'   => $failByTargetWins,
            'failed_by_speed_count'         => $failBySpeedToTarget,
            'failed_by_missing_time_count'  => $failByMissingTimeSamples,
        ];
    }

    // =========================================================================
    // Candidate sensitivity preview
    // =========================================================================

    /**
     * Compute read-only preview counts showing how many symbols would qualify
     * under the current thresholds and under two progressively softer scenarios.
     *
     * This is SHADOW-ONLY diagnostic data — it does NOT change active thresholds.
     *
     * @param array<string,array<string,mixed>> $symbolStats  Output of aggregateBySymbol()
     * @param float $minRoi          Active min_roi_threshold
     * @param float $minAvgRoi       Active min_avg_roi
     * @param int   $minTrades       Active min_closed_trades
     * @param float $minWinrate      Active min_winrate
     * @param int   $lookbackDays    Active lookback_days
     * @return array<string,array<string,mixed>>
     */
    private function buildCandidateSensitivityPreview(
        array $symbolStats,
        float $minRoi,
        float $minAvgRoi,
        int $minTrades,
        float $minWinrate,
        int $lookbackDays
    ): array {
        // Helper: count how many symbols qualify under given thresholds
        // New gates (target ROI, speed) are disabled here to keep the sensitivity scenarios simple.
        $countQ = function (float $roi, float $avgRoi, int $trades, float $wr) use ($symbolStats, $lookbackDays): int {
            $n = 0;
            foreach ($symbolStats as $sym => $stats) {
                $rec = $this->qualify($sym, $stats, $roi, $avgRoi, $trades, $wr, $lookbackDays, 0.0, 0, 0);
                if ($rec['qualified']) {
                    $n++;
                }
            }
            return $n;
        };

        // Scenario A — current active thresholds
        $scenarios['current'] = [
            'label'               => 'текущие пороги',
            'min_roi_threshold'   => $minRoi,
            'min_avg_roi'         => $minAvgRoi,
            'min_closed_trades'   => $minTrades,
            'min_winrate'         => $minWinrate,
            'qualified_count'     => $countQ($minRoi, $minAvgRoi, $minTrades, $minWinrate),
        ];

        // Scenario B — softer: ROI threshold ×0.75, min_trades −1 (min 1)
        $roi1    = round($minRoi * 0.75, 2);
        $trades1 = max(1, $minTrades - 1);
        $avg1    = $minAvgRoi > 0.0 ? round($minAvgRoi * 0.75, 2) : 0.0;
        $wr1     = $minWinrate > 0.0 ? round($minWinrate * 0.8, 2) : 0.0;
        $scenarios['softer_1'] = [
            'label'               => 'мягче: ROI×0.75, сделок-1',
            'min_roi_threshold'   => $roi1,
            'min_avg_roi'         => $avg1,
            'min_closed_trades'   => $trades1,
            'min_winrate'         => $wr1,
            'qualified_count'     => $countQ($roi1, $avg1, $trades1, $wr1),
        ];

        // Scenario C — softer: ROI threshold ×0.50, min_trades max(1, ×0.5)
        $roi2    = round($minRoi * 0.50, 2);
        $trades2 = max(1, (int)floor($minTrades * 0.5));
        $avg2    = $minAvgRoi > 0.0 ? round($minAvgRoi * 0.50, 2) : 0.0;
        $wr2     = $minWinrate > 0.0 ? round($minWinrate * 0.6, 2) : 0.0;
        $scenarios['softer_2'] = [
            'label'               => 'мягче: ROI×0.50',
            'min_roi_threshold'   => $roi2,
            'min_avg_roi'         => $avg2,
            'min_closed_trades'   => $trades2,
            'min_winrate'         => $wr2,
            'qualified_count'     => $countQ($roi2, $avg2, $trades2, $wr2),
        ];

        return $scenarios;
    }

    // =========================================================================
    // Bot storage resolution (read-only, mirrors profit_manager approach)
    // =========================================================================

    /**
     * Resolve the Trading Bot's mode-specific storage directory.
     *
     * Tries storage_live, storage_demo, storage_paper, then legacy storage.
     * Reads bot.json to determine the preferred mode.
     *
     * @return string|null
     */
    private function resolveBotStorageDir(): ?string
    {
        $base = $this->botModuleBase;
        if (!is_dir($base)) {
            return null;
        }

        $botMode = $this->readBotMode($base);
        $ordered = $this->botStorageSuffixOrder($botMode);

        foreach ($ordered as $suffix) {
            $sd = $base . '/' . $suffix;
            if (is_dir($sd . '/trades/active')) {
                return $sd;
            }
        }
        foreach ($ordered as $suffix) {
            $sd = $base . '/' . $suffix;
            if (is_dir($sd)) {
                return $sd;
            }
        }

        return null;
    }

    /**
     * Read bot mode from bot.json (defaults to 'demo').
     */
    private function readBotMode(string $botBase): string
    {
        try {
            $path = $botBase . '/config/bot.json';
            if (!is_file($path)) {
                return 'demo';
            }
            $json = @file_get_contents($path);
            if ($json === false || $json === '') {
                return 'demo';
            }
            $data = json_decode($json, true);
            if (!is_array($data)) {
                return 'demo';
            }
            $mode = (string)($data['module']['mode'] ?? $data['mode'] ?? 'demo');
            return $mode !== '' ? $mode : 'demo';
        } catch (\Throwable $e) {
            return 'demo';
        }
    }

    /**
     * Return ordered list of storage directory suffixes to try.
     *
     * @return string[]
     */
    private function botStorageSuffixOrder(string $botMode): array
    {
        $modeMap   = ['live' => 'storage_live', 'demo' => 'storage_demo', 'paper' => 'storage_paper'];
        $preferred = $modeMap[$botMode] ?? 'storage_demo';
        $all       = [$preferred];
        foreach ($modeMap as $suffix) {
            if ($suffix !== $preferred) {
                $all[] = $suffix;
            }
        }
        $all[] = 'storage';
        return $all;
    }
}
