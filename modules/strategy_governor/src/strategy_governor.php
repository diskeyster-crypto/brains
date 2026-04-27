<?php

declare(strict_types=1);

/**
 * Strategy Governor — Core Engine (V1 Shadow-Only)
 *
 * Observes strategy signals, bot queues, active positions and closed trades.
 * Writes shadow-only recommended decisions.  Does NOT touch bot queues,
 * strategy files, Profit Manager, Stop Manager or any exchange API.
 *
 * Entry points called by StrategyGovernorService:
 *   run(): array   — executes one Governor tick, returns last_run summary
 */

namespace Modules\StrategyGovernor;

final class StrategyGovernor
{
    // ── Decision state constants ──────────────────────────────────────────────
    private const STATE_OBSERVED            = 'observed';
    private const STATE_WAIT_CONFIRM        = 'wait_confirmation';
    private const STATE_APPROVE_DEMO_SHADOW = 'approve_demo_shadow';
    private const STATE_APPROVE_LIVE_SHADOW = 'approve_live_shadow';
    private const STATE_REJECT_SHADOW       = 'reject_shadow';
    private const STATE_EXPIRED_SHADOW      = 'expired_shadow';

    // ── Route constants ───────────────────────────────────────────────────────
    private const ROUTE_NONE  = 'none';
    private const ROUTE_DEMO  = 'demo';
    private const ROUTE_LIVE  = 'live';

    // ── Properties ───────────────────────────────────────────────────────────
    private string $moduleDir;
    private string $repoRoot;
    private array  $config;

    // ── Constructor ───────────────────────────────────────────────────────────

    public function __construct(string $moduleDir, array $config)
    {
        $this->moduleDir = rtrim($moduleDir, '/');
        $this->repoRoot  = dirname($this->moduleDir, 2);
        $this->config    = $config;
    }

    // =========================================================================
    // Public entry point
    // =========================================================================

    /**
     * Execute one Governor tick.
     *
     * @return array  last_run summary
     */
    public function run(): array
    {
        $startedAt = date('Y-m-d H:i:s');
        $errors    = [];

        $strategiesSeen       = 0;
        $signalsSeen          = 0;
        $decisionsTotal       = 0;
        $approvedDemoShadow   = 0;
        $approvedLiveShadow   = 0;
        $rejectedShadow       = 0;
        $expiredShadow        = 0;

        try {
            // ── 1. Load config values ─────────────────────────────────────────
            $maxTicks           = max(1, (int)($this->config['pending_confirmation_ticks']  ?? 5));
            $minClosed          = (int)($this->config['min_closed_trades_for_live']         ?? 20);
            $minHourly          = (int)($this->config['min_hourly_trades_for_live']         ?? 5);
            $minWinrate         = (float)($this->config['min_winrate_for_live']             ?? 0.55);
            $minAvgRoi          = (float)($this->config['min_avg_roi_for_live']             ?? 1.0);
            $maxConsecLosses    = (int)($this->config['max_consecutive_losses_live']        ?? 3);
            $cooldownMin        = (int)($this->config['cooldown_minutes_after_block']       ?? 180);
            $mode               = (string)($this->config['mode']                            ?? 'shadow');

            // ── 2. Discover strategies ────────────────────────────────────────
            $strategyIds = $this->discoverStrategies();

            // ── 3. Build stats from closed trades ────────────────────────────
            $closedTrades = $this->loadClosedTrades();
            $stratStats   = $this->buildStrategyStats($closedTrades);
            $hourlyStats  = $this->buildHourlyStats($closedTrades);

            // ── 4. Collect active positions ───────────────────────────────────
            $activePositions = $this->loadJsonSafe(
                $this->repoRoot . '/modules/bot/storage/active_positions.json', []
            );

            // ── 5. Load pending signals state ─────────────────────────────────
            $pendingSignals = $this->loadJsonSafe(
                $this->moduleDir . '/storage/pending_signals.json', []
            );

            // ── 6. Collect signals from all strategies ────────────────────────
            $allSignals = [];
            foreach ($strategyIds as $stratId) {
                $strategiesSeen++;
                $signals = $this->loadStrategySignals($stratId);
                foreach ($signals as $sig) {
                    $sig['_governor_strategy_id'] = $stratId;
                    $allSignals[] = $sig;
                }
            }
            $signalsSeen = count($allSignals);

            // ── 7. Compute open-positions count per strategy ──────────────────
            $openPerStrategy = [];
            if (is_array($activePositions)) {
                foreach ($activePositions as $pos) {
                    $sid = (string)($pos['strategy_id'] ?? $pos['owner_strategy'] ?? '');
                    if ($sid !== '') {
                        $openPerStrategy[$sid] = ($openPerStrategy[$sid] ?? 0) + 1;
                    }
                }
            }

            // ── 8. Process each signal through the decision engine ────────────
            $newPending   = [];
            $decisionsBatch = [];

            foreach ($allSignals as $sig) {
                $stratId  = (string)($sig['_governor_strategy_id'] ?? '');
                $signalId = (string)($sig['signal_id'] ?? $sig['id'] ?? '');
                $symbol   = (string)($sig['symbol']    ?? '');
                $entryP   = $sig['entry_price'] ?? null;
                $detectedAt = $sig['detected_at'] ?? null;

                // Derive a stable pending key
                $pendingKey = $stratId . ':' . ($signalId !== '' ? $signalId : ($symbol . ':' . ($detectedAt ?? 'unknown')));

                // Retrieve or initialise pending entry
                $pEntry = $pendingSignals[$pendingKey] ?? [
                    'strategy_id'   => $stratId,
                    'signal_id'     => $signalId,
                    'symbol'        => $symbol,
                    'tick_count'    => 0,
                    'first_seen_at' => date('Y-m-d H:i:s'),
                ];
                $pEntry['tick_count'] = (int)($pEntry['tick_count'] ?? 0) + 1;
                $tickCount = $pEntry['tick_count'];

                // ── Decision logic ────────────────────────────────────────────
                [$decision, $route, $reason] = $this->decide(
                    sig:               $sig,
                    stratId:           $stratId,
                    signalId:          $signalId,
                    symbol:            $symbol,
                    entryPrice:        $entryP,
                    detectedAt:        $detectedAt,
                    tickCount:         $tickCount,
                    maxTicks:          $maxTicks,
                    stats:             $stratStats[$stratId] ?? [],
                    minClosed:         $minClosed,
                    minWinrate:        $minWinrate,
                    minAvgRoi:         $minAvgRoi,
                    maxConsecLosses:   $maxConsecLosses,
                    minHourly:         $minHourly,
                    hourlyStats:       $hourlyStats[$stratId] ?? [],
                );

                // ── Count outcomes ────────────────────────────────────────────
                match ($decision) {
                    self::STATE_APPROVE_DEMO_SHADOW => $approvedDemoShadow++,
                    self::STATE_APPROVE_LIVE_SHADOW => $approvedLiveShadow++,
                    self::STATE_REJECT_SHADOW       => $rejectedShadow++,
                    self::STATE_EXPIRED_SHADOW      => $expiredShadow++,
                    default                         => null,
                };
                $decisionsTotal++;

                // ── Build decision record ─────────────────────────────────────
                $decisionId = 'gd_' . substr(md5($pendingKey . $tickCount), 0, 12);
                $decisionRecord = [
                    'time'              => date('Y-m-d H:i:s'),
                    'decision_id'       => $decisionId,
                    'signal_id'         => $signalId,
                    'strategy_id'       => $stratId,
                    'symbol'            => $symbol,
                    'decision'          => $decision,
                    'recommended_route' => $route,
                    'reason'            => $reason,
                    'tick_count'        => $tickCount,
                    'mode'              => $mode,
                ];
                $decisionsBatch[] = $decisionRecord;

                // ── Keep in pending if still waiting ──────────────────────────
                if ($decision === self::STATE_WAIT_CONFIRM || $decision === self::STATE_OBSERVED) {
                    $newPending[$pendingKey] = $pEntry;
                }
                // Finalised decisions are not kept in pending
            }

            // ── 9. Persist state files ────────────────────────────────────────
            $this->savePendingSignals($newPending);
            $this->saveStrategyStats($stratStats, $openPerStrategy, $strategyIds,
                $minClosed, $minWinrate, $minAvgRoi, $maxConsecLosses);
            $this->saveHourlyStats($hourlyStats);
            $this->appendDecisions($decisionsBatch);

        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }

        $finishedAt = date('Y-m-d H:i:s');
        $summary = [
            'started_at'                  => $startedAt,
            'finished_at'                 => $finishedAt,
            'status'                      => empty($errors) ? 'completed' : 'completed_with_errors',
            'mode'                        => $this->config['mode'] ?? 'shadow',
            'strategies_seen'             => $strategiesSeen,
            'signals_seen'                => $signalsSeen,
            'pending_total'               => count($newPending ?? []),
            'decisions_total'             => $decisionsTotal,
            'approved_demo_shadow_total'  => $approvedDemoShadow,
            'approved_live_shadow_total'  => $approvedLiveShadow,
            'rejected_shadow_total'       => $rejectedShadow,
            'expired_shadow_total'        => $expiredShadow,
            'errors'                      => $errors,
        ];

        $this->writeJsonFile($this->moduleDir . '/storage/last_run.json', $summary);

        return $summary;
    }

    // =========================================================================
    // Decision engine
    // =========================================================================

    /**
     * Evaluate one signal and return [decision_state, route, reason].
     */
    private function decide(
        array   $sig,
        string  $stratId,
        string  $signalId,
        string  $symbol,
        mixed   $entryPrice,
        mixed   $detectedAt,
        int     $tickCount,
        int     $maxTicks,
        array   $stats,
        int     $minClosed,
        float   $minWinrate,
        float   $minAvgRoi,
        int     $maxConsecLosses,
        int     $minHourly,
        array   $hourlyStats,
    ): array {
        $route = self::ROUTE_NONE;

        // ── Hard-reject conditions ────────────────────────────────────────────
        if ($symbol === '') {
            return [self::STATE_REJECT_SHADOW, $route, 'missing_symbol'];
        }
        if ($entryPrice === null || (float)$entryPrice <= 0.0) {
            return [self::STATE_REJECT_SHADOW, $route, 'missing_entry_price'];
        }
        if ($detectedAt === null || $detectedAt === '') {
            return [self::STATE_REJECT_SHADOW, $route, 'missing_detected_at'];
        }

        // ── Staleness check ───────────────────────────────────────────────────
        $detectedTs = is_numeric($detectedAt) ? (int)$detectedAt : (int)@strtotime((string)$detectedAt);
        if ($detectedTs > 0 && (time() - $detectedTs) > 3600) {
            return [self::STATE_EXPIRED_SHADOW, $route, 'signal_too_old'];
        }

        // ── Handoff validity ──────────────────────────────────────────────────
        if (isset($sig['handoff_valid']) && $sig['handoff_valid'] === false) {
            return [self::STATE_REJECT_SHADOW, $route, 'handoff_signal_invalid'];
        }

        // ── Tick-based confirmation model ─────────────────────────────────────
        if ($tickCount < $maxTicks) {
            return [self::STATE_WAIT_CONFIRM, $route, 'pending_confirmation_tick'];
        }

        // ── Strategy performance gate ─────────────────────────────────────────
        $closedTotal   = (int)($stats['closed_trades_total']  ?? 0);
        $winrate       = (float)($stats['winrate']            ?? 0.0);
        $avgRoi        = (float)($stats['avg_roi']            ?? 0.0);
        $consecLosses  = (int)($stats['consecutive_losses']   ?? 0);

        if ($closedTotal < $minClosed) {
            return [self::STATE_APPROVE_DEMO_SHADOW, self::ROUTE_DEMO, 'not_enough_closed_trades'];
        }
        if ($consecLosses >= $maxConsecLosses) {
            return [self::STATE_REJECT_SHADOW, $route, 'too_many_consecutive_losses'];
        }

        // ── Live gate ─────────────────────────────────────────────────────────
        if ($winrate >= $minWinrate && $avgRoi >= $minAvgRoi) {
            return [self::STATE_APPROVE_LIVE_SHADOW, self::ROUTE_LIVE, 'live_gate_passed'];
        }

        // ── Default: approve demo ─────────────────────────────────────────────
        return [self::STATE_APPROVE_DEMO_SHADOW, self::ROUTE_DEMO, 'below_live_threshold'];
    }

    // =========================================================================
    // Statistics builders
    // =========================================================================

    /**
     * Build per-strategy stats from a closed-trades array.
     *
     * @param array $closedTrades  array of trade records
     * @return array<string, array>  keyed by strategy_id
     */
    private function buildStrategyStats(array $closedTrades): array
    {
        $stats = [];
        foreach ($closedTrades as $trade) {
            $sid = (string)($trade['strategy_id'] ?? $trade['owner_strategy'] ?? '');
            if ($sid === '') {
                continue;
            }
            if (!isset($stats[$sid])) {
                $stats[$sid] = [
                    'closed_trades_total'  => 0,
                    'wins_total'           => 0,
                    'losses_total'         => 0,
                    'roi_sum'              => 0.0,
                    'total_pnl'            => 0.0,
                    'consecutive_losses'   => 0,
                    'consecutive_wins'     => 0,
                    '_last_results'        => [],
                    'last_trade_at'        => null,
                ];
            }
            $s   = &$stats[$sid];
            $roi = isset($trade['roi']) ? (float)$trade['roi'] : null;
            $pnl = isset($trade['pnl']) ? (float)$trade['pnl'] : 0.0;

            $s['closed_trades_total']++;
            $s['total_pnl'] += $pnl;
            if ($roi !== null) {
                $s['roi_sum'] += $roi;
            }
            $win = ($roi !== null && $roi > 0.0);
            if ($win) {
                $s['wins_total']++;
                $s['_last_results'][] = 'win';
            } else {
                $s['losses_total']++;
                $s['_last_results'][] = 'loss';
            }
            $closedAt = (string)($trade['closed_at'] ?? '');
            if ($closedAt !== '') {
                if ($s['last_trade_at'] === null || $closedAt > $s['last_trade_at']) {
                    $s['last_trade_at'] = $closedAt;
                }
            }
            unset($s);
        }

        // Compute derived fields
        foreach ($stats as $sid => &$s) {
            $total = $s['closed_trades_total'];
            $s['winrate'] = $total > 0 ? round($s['wins_total'] / $total, 4) : 0.0;
            $s['avg_roi'] = $total > 0 ? round($s['roi_sum'] / $total, 4) : 0.0;
            // Consecutive losses/wins from the end of the result list
            $results = $s['_last_results'];
            $cl = 0;
            $cw = 0;
            for ($i = count($results) - 1; $i >= 0; $i--) {
                if ($results[$i] === 'loss') {
                    if ($cw === 0) {
                        $cl++;
                    } else {
                        break;
                    }
                } else {
                    if ($cl === 0) {
                        $cw++;
                    } else {
                        break;
                    }
                }
            }
            $s['consecutive_losses'] = $cl;
            $s['consecutive_wins']   = $cw;
            unset($s['roi_sum'], $s['_last_results']);
        }
        unset($s);

        return $stats;
    }

    /**
     * Build hourly stats grouped by strategy_id + hour_of_day + weekday.
     *
     * @return array<string, array>  keyed by strategy_id; nested by hour and weekday
     */
    private function buildHourlyStats(array $closedTrades): array
    {
        $hourly = [];
        foreach ($closedTrades as $trade) {
            $sid      = (string)($trade['strategy_id'] ?? $trade['owner_strategy'] ?? '');
            $closedAt = (string)($trade['closed_at'] ?? '');
            if ($sid === '' || $closedAt === '') {
                continue;
            }
            $ts      = is_numeric($closedAt) ? (int)$closedAt : (int)@strtotime($closedAt);
            if ($ts <= 0) {
                continue;
            }
            $hour    = (int)date('G', $ts);   // 0-23
            $weekday = (int)date('N', $ts);   // 1=Mon…7=Sun
            $key     = $hour . '_' . $weekday;

            if (!isset($hourly[$sid][$key])) {
                $hourly[$sid][$key] = [
                    'hour_of_day'  => $hour,
                    'weekday'      => $weekday,
                    'trades_total' => 0,
                    'wins_total'   => 0,
                    'losses_total' => 0,
                    'roi_sum'      => 0.0,
                    'total_pnl'    => 0.0,
                ];
            }
            $h   = &$hourly[$sid][$key];
            $roi = isset($trade['roi']) ? (float)$trade['roi'] : null;
            $pnl = isset($trade['pnl']) ? (float)$trade['pnl'] : 0.0;

            $h['trades_total']++;
            $h['total_pnl'] += $pnl;
            if ($roi !== null) {
                $h['roi_sum'] += $roi;
                if ($roi > 0.0) {
                    $h['wins_total']++;
                } else {
                    $h['losses_total']++;
                }
            } else {
                $h['losses_total']++;
            }
            unset($h);
        }

        // Compute derived fields
        foreach ($hourly as $sid => &$buckets) {
            foreach ($buckets as $key => &$h) {
                $t = $h['trades_total'];
                $h['winrate'] = $t > 0 ? round($h['wins_total'] / $t, 4) : 0.0;
                $h['avg_roi'] = $t > 0 ? round($h['roi_sum']    / $t, 4) : 0.0;
                unset($h['roi_sum']);
            }
            unset($h);
        }
        unset($buckets);

        return $hourly;
    }

    // =========================================================================
    // Strategy-state writer
    // =========================================================================

    private function saveStrategyStats(
        array $stratStats,
        array $openPerStrategy,
        array $strategyIds,
        int   $minClosed,
        float $minWinrate,
        float $minAvgRoi,
        int   $maxConsecLosses,
    ): void {
        $stateMap = [];
        foreach ($strategyIds as $sid) {
            $s           = $stratStats[$sid] ?? [];
            $total       = (int)($s['closed_trades_total'] ?? 0);
            $winrate     = (float)($s['winrate']           ?? 0.0);
            $avgRoi      = (float)($s['avg_roi']           ?? 0.0);
            $consecLoss  = (int)($s['consecutive_losses']  ?? 0);

            $liveAllowed = false;
            $reason      = 'not_enough_closed_trades';
            $state       = 'insufficient_data';

            if ($total >= $minClosed) {
                if ($consecLoss >= $maxConsecLosses) {
                    $reason = 'too_many_consecutive_losses';
                    $state  = 'shadow_blocked';
                } elseif ($winrate >= $minWinrate && $avgRoi >= $minAvgRoi) {
                    $reason      = 'live_gate_passed';
                    $state       = 'shadow_live_ready';
                    $liveAllowed = true;
                } else {
                    $reason = 'below_live_threshold';
                    $state  = 'shadow_observe';
                }
            }

            $entry = [
                'state'                   => $state,
                'recommended_live_allowed'=> $liveAllowed,
                'recommended_route'       => $liveAllowed ? 'live' : 'demo',
                'reason'                  => $reason,
                'closed_trades_total'     => $total,
                'winrate'                 => $winrate,
                'avg_roi'                 => $avgRoi,
                'consecutive_losses'      => $consecLoss,
                'consecutive_wins'        => (int)($s['consecutive_wins'] ?? 0),
                'total_pnl'               => round((float)($s['total_pnl'] ?? 0.0), 6),
                'current_open_positions'  => $openPerStrategy[$sid] ?? 0,
                'last_trade_at'           => $s['last_trade_at'] ?? null,
                'updated_at'              => date('Y-m-d H:i:s'),
            ];
            $stateMap[$sid] = $entry;
        }

        $this->writeJsonFile($this->moduleDir . '/storage/strategy_state.json', $stateMap);
        $this->writeJsonFile($this->moduleDir . '/storage/strategy_stats.json', $stratStats);
    }

    private function saveHourlyStats(array $hourlyStats): void
    {
        $this->writeJsonFile($this->moduleDir . '/storage/hourly_stats.json', $hourlyStats);
    }

    // =========================================================================
    // Decision journal
    // =========================================================================

    /**
     * Append a batch of decision records to decisions.ndjson (one JSON object per line).
     */
    private function appendDecisions(array $decisions): void
    {
        if (empty($decisions)) {
            return;
        }
        $path = $this->moduleDir . '/storage/decisions.ndjson';
        $lines = '';
        foreach ($decisions as $d) {
            $lines .= json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        }
        @file_put_contents($path, $lines, FILE_APPEND | LOCK_EX);
    }

    // =========================================================================
    // Data loaders
    // =========================================================================

    /** Discover strategy IDs from the pattern directory. */
    private function discoverStrategies(): array
    {
        $patternDir = $this->repoRoot . '/modules/strategy/pattern';
        if (!is_dir($patternDir)) {
            return [];
        }
        $ids = [];
        foreach (scandir($patternDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($patternDir . '/' . $entry)) {
                $ids[] = $entry;
            }
        }
        return $ids;
    }

    /**
     * Load all signals for a strategy.
     *
     * Reads signals.json and bot_handoff_queue.json, merges, deduplicates by signal_id.
     */
    private function loadStrategySignals(string $stratId): array
    {
        $base  = $this->repoRoot . '/modules/strategy/pattern/' . $stratId . '/storage';
        $sigs  = [];
        $seen  = [];

        foreach (['signals.json', 'bot_handoff_queue.json'] as $fname) {
            $arr = $this->loadJsonSafe($base . '/' . $fname, []);
            if (!is_array($arr)) {
                continue;
            }
            foreach ($arr as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = (string)($item['signal_id'] ?? $item['id'] ?? '');
                $k  = $id !== '' ? $id : json_encode($item);
                if (!isset($seen[$k])) {
                    $seen[$k] = true;
                    $sigs[]   = $item;
                }
            }
        }
        return $sigs;
    }

    /** Load closed trades from bot storage (may not exist). */
    private function loadClosedTrades(): array
    {
        $path = $this->repoRoot . '/modules/bot/storage/trades/closed_trades.json';
        $data = $this->loadJsonSafe($path, []);
        return is_array($data) ? $data : [];
    }

    // =========================================================================
    // Persistence helpers
    // =========================================================================

    private function savePendingSignals(array $pending): void
    {
        $this->writeJsonFile($this->moduleDir . '/storage/pending_signals.json', $pending);
    }

    /**
     * Safely decode a JSON file; return $default on any failure.
     */
    private function loadJsonSafe(string $path, mixed $default): mixed
    {
        if (!is_file($path)) {
            return $default;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return $default;
        }
        $decoded = @json_decode($raw, true);
        return ($decoded !== null) ? $decoded : $default;
    }

    /**
     * Write data as pretty-printed JSON, creating directories as needed.
     */
    private function writeJsonFile(string $path, mixed $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            LOCK_EX
        );
    }
}
