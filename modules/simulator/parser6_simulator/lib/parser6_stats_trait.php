<?php

declare(strict_types=1);

/**
 * Parser6StatsTrait - Statistics, state, and summary generation
 * 
 * Methods for updating statistics and generating simulation summaries.
 */
trait Parser6StatsTrait
{
    /**
     * Update statistics for root storage
     */
    private function updateStatistics(): void
    {
        $closedTrades = $this->loadClosedTrades();
        $activeTrades = $this->loadActiveTrades();
        $rejectedTrades = $this->loadRejectedTrades(); // Part 3 of TZ: include rejected in stats

        $totalClosed = count($closedTrades);
        $totalRejected = count($rejectedTrades);
        $totalOpen = count($activeTrades);
        $wins = 0;
        $losses = 0;
        $roiSum = 0.0;
        $durationSum = 0;
        $grossProfit = 0.0;
        $grossLoss = 0.0;
        $maxDrawdown = 0.0;
        $closedBy = [
            'tp' => 0,
            'sl' => 0,
            'trailing_sl' => 0,
            'expired_signal' => 0,
            'expired_entry_timeout' => 0,
        ];

        foreach ($closedTrades as $trade) {
            $result = $trade['result'] ?? [];
            $labels = $trade['labels'] ?? [];
            $closeReason = $trade['close_reason'] ?? 'unknown';

            $roi = (float)($result['roi_realized'] ?? 0);
            $pnl = (float)($result['pnl_realized_usdt'] ?? 0);
            $roiSum += $roi;

            if ($labels['win'] ?? false) {
                $wins++;
                $grossProfit += $pnl;
            } else {
                $losses++;
                $grossLoss += abs($pnl);
            }
            
            // Track max drawdown
            $tradeDrawdown = (float)($result['max_drawdown_roi'] ?? 0);
            if ($tradeDrawdown < $maxDrawdown) {
                $maxDrawdown = $tradeDrawdown;
            }

            // P2.0: Calculate duration from actual timestamps (sim_trade_v1 + legacy fallback)
            $openedTs = (int)($trade['entry']['opened_ts'] ?? $trade['opened_ts'] ?? 0);
            $closeTs = (int)(($trade['close_result']['close_ts'] ?? null) ?? ($trade['closed_ts'] ?? 0));
            $durationMin = 0;
            if ($openedTs > 0 && $closeTs > $openedTs) {
                $durationMin = (int)floor(($closeTs - $openedTs) / 60);
            }
            $durationSum += $durationMin;

            if (isset($closedBy[$closeReason])) {
                $closedBy[$closeReason]++;
            }
        }

        $winrate = $totalClosed > 0 ? $wins / $totalClosed : 0;
        $roiAvg = $totalClosed > 0 ? $roiSum / $totalClosed : 0;
        $avgDurationMinutes = $totalClosed > 0 ? $durationSum / $totalClosed : 0;
        $profitFactor = $grossLoss > 0 ? ($grossProfit / $grossLoss) : ($grossProfit > 0 ? self::MAX_PROFIT_FACTOR_DISPLAY : 0);

        $summary = [
            'updated_at' => date('c'),
            'total_trades_opened' => $totalOpen + $totalClosed,
            'total_trades_closed' => $totalClosed,
            'wins' => $wins,
            'losses' => $losses,
            'winrate' => round($winrate, 4),
            'roi_sum' => round($roiSum, 4),
            'roi_avg' => round($roiAvg, 6),
            'max_drawdown_roi' => round($maxDrawdown, 6),
            'avg_duration_minutes' => round($avgDurationMinutes, 1),
            'profit_factor' => round(min($profitFactor, self::MAX_PROFIT_FACTOR_DISPLAY), 2),
            'closed_by' => $closedBy,
        ];

        $statsDir = $this->storageDir . '/stats';
        $this->ensureDir($statsDir);
        $this->writeJsonAtomic($statsDir . '/summary.json', $summary);
        
        // Also write stats_global.json per TZ specification (section 12)
        // Part 3 of TZ: only count completed trades (closed + rejected), not open
        $totalCompletedTrades = $totalClosed + $totalRejected;
        $statsGlobal = [
            'total_trades' => $totalCompletedTrades, // Completed trades only (closed + rejected)
            'total_completed' => $totalCompletedTrades, // Alias for clarity
            'total_closed' => $totalClosed,
            'total_rejected' => $totalRejected,
            'total_open' => $totalOpen, // Currently active trades (not included in total_trades)
            'wins' => $wins,
            'losses' => $losses,
            'winrate' => round($winrate, 4),
            'total_roi' => round($roiSum, 4),
            'avg_roi' => round($roiAvg, 6),
            'max_drawdown' => round($maxDrawdown, 6),
            'avg_duration' => round($avgDurationMinutes * 60, 0), // Convert to seconds per TZ
            'profit_factor' => round(min($profitFactor, self::MAX_PROFIT_FACTOR_DISPLAY), 2),
        ];
        $this->writeJsonAtomic($this->storageDir . '/stats_global.json', $statsGlobal);
    }
    
    /**
     * Generate simulation.json summary for Brain integration
     * 
     * Creates a standardized summary file that Brain reads to display simulation status.
     * Uses existing data from stats_global.json and trades without recalculation.
     */
    private function generateSimulationSummary(): void
    {
        $closedTrades = $this->loadClosedTrades();
        $rejectedTrades = $this->loadRejectedTrades();
        
        // Load stats_global.json if available
        $statsPath = $this->storageDir . '/stats_global.json';
        $stats = is_file($statsPath) ? json_decode(file_get_contents($statsPath), true) : [];
        
        // Calculate total signals from trades
        $totalClosed = count($closedTrades);
        $totalRejected = count($rejectedTrades);
        
        $summary = [
            'updated_at' => date('c'),
            'total_signals' => $totalClosed + $totalRejected,
            'closed' => $totalClosed,
            'rejected' => $totalRejected,
            'win_rate' => (float)($stats['winrate'] ?? 0),
            'avg_roi' => (float)($stats['avg_roi'] ?? 0),
            'max_drawdown' => (float)($stats['max_drawdown'] ?? 0),
            'profit_factor' => (float)($stats['profit_factor'] ?? 0),
            'source' => 'parser6_simulator',
        ];
        
        // Write atomically (tmp → rename)
        $this->writeJsonAtomic($this->storageDir . '/simulation.json', $summary);
    }

    /**
     * Build state for a specific mode storage directory
     * P3.0: Mode-aware state building - reads from mode-specific directories only
     *
     * @param string $ts Current timestamp
     * @param string $baseDir Mode-specific storage base directory (e.g., storage/raw or storage/clean)
     * @return array State array
     */
    private function buildState(string $ts, string $baseDir): array
    {
        // P3.0: Use mode-specific loading (no root storage)
        $activeTrades = $this->loadActiveTradesForMode($baseDir);
        $closedTrades = $this->loadClosedTradesForMode($baseDir);

        // Load summary for stats from mode-specific directory
        $summaryPath = $baseDir . '/stats/summary.json';
        $summary = is_file($summaryPath) ? json_decode(file_get_contents($summaryPath), true) : [];

        return [
            'ts' => $ts,
            'ok' => empty($this->errors),
            'status' => empty($this->errors) ? 'ok' : 'ok_with_errors',
            'open_trades' => count($activeTrades),
            'closed_trades' => count($closedTrades),
            'signals_seen' => 0,
            'signals_skipped' => 0,
            'winrate' => $summary['winrate'] ?? 0,
            'roi_sum' => $summary['roi_sum'] ?? 0,
            'errors_count' => count($this->errors),
            // P6.2: Contract versioning block
            'contract' => [
                'signals_schema' => 'clean_signal_v1',
                'risk_schema' => 'risk_active_v1',
                'trade_schema' => 'sim_trade_v1',
            ],
        ];
    }
    
    /**
     * Write state.json to root storage
     */
    private function writeState(array $state): void
    {
        $path = $this->storageDir . '/state.json';
        $this->writeJsonAtomic($path, $state);
    }

    /**
     * Write last_run.json to root storage
     */
    private function writeLastRun(array $data): void
    {
        $path = $this->storageDir . '/last_run.json';
        $this->writeJsonAtomic($path, $data);
    }
    
    /**
     * Write config error to last_run.json using alternative path discovery
     * Only used when moduleBase is null (config error state)
     */
    private function writeConfigErrorLastRun(array $data): void
    {
        // Try to get storage path via SystemPaths for error logging
        try {
            $paths = \Core\System\SystemPaths::instance();
            $storagePath = $paths->get('simulator.parser6_simulator.storage');
            if (is_string($storagePath) && $storagePath !== '' && is_dir($storagePath)) {
                $path = rtrim($storagePath, '/') . '/last_run.json';
                file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                return;
            }
        } catch (\Throwable $e) {
            // Cannot write last_run.json - config truly broken
        }
        // Silently fail if we can't write - this is a config error state
    }
    
    /**
     * Write mode-specific last_run.json
     */
    private function writeModeLastRun(array $data, string $modeStorageBase): void
    {
        $path = $modeStorageBase . '/last_run.json';
        $this->ensureDir(dirname($path));
        $this->writeJsonAtomic($path, $data);
    }
    
    /**
     * Update statistics for a specific mode storage directory
     * FIX-4.1: Mode-aware version that takes $baseDir parameter
     * Builds summary from $baseDir/trades/closed + $baseDir/trades/rejected
     * Writes: $baseDir/stats/summary.json and $baseDir/stats_global.json
     *
     * @param string $baseDir Mode-specific storage directory (e.g., storage/raw or storage/clean)
     */
    private function updateStatisticsForMode(string $baseDir): void
    {
        $closedTrades = $this->loadTradesFromDir($baseDir . '/trades/closed');
        $activeTrades = $this->loadTradesFromDir($baseDir . '/trades/active');
        $rejectedTrades = $this->loadTradesFromDir($baseDir . '/trades/rejected');

        $totalClosed = count($closedTrades);
        $totalRejected = count($rejectedTrades);
        $totalOpen = count($activeTrades);
        $wins = 0;
        $winsNet = 0; // Wins based on net PnL
        $losses = 0;
        $roiSum = 0.0;
        $roiNetSum = 0.0; // Net ROI sum
        $durationSum = 0;
        $grossProfit = 0.0;
        $grossLoss = 0.0;
        $maxDrawdown = 0.0;
        
        // Per TZ: Track trailing statistics
        $trailingActivatedCount = 0;
        $closedByTrailingCount = 0;
        $leverageSum = 0;
        $slippageSum = 0;
        $profileIds = [];
        
        // Fee statistics
        $totalFeesUsdt = 0.0;
        $feesBpsSum = 0;
        
        $closedBy = [
            'tp' => 0,
            'sl' => 0,
            'take_profit' => 0,
            'stop_loss' => 0,
            'trailing_stop' => 0,
            'trailing_sl' => 0,
            'expired_signal' => 0,
            'expired_entry_timeout' => 0,
        ];

        foreach ($closedTrades as $trade) {
            // P4.2-D3: close_result-first with legacy fallback
            $closeResult = $trade['close_result'] ?? [];
            $result = $trade['result'] ?? [];
            $labels = $trade['labels'] ?? [];
            $closeReason = $closeResult['close_reason'] ?? $trade['close_reason'] ?? 'unknown';

            // P4.2-D3: Use close_result fields first, then legacy
            $roi = (float)($closeResult['roi_margin_pct'] ?? $result['roi_realized'] ?? 0);
            $pnl = (float)($closeResult['pnl_realized_usdt'] ?? $result['pnl_realized_usdt'] ?? 0);
            $roiSum += $roi;
            
            // Net PnL/ROI tracking
            $pnlNet = (float)($closeResult['pnl_net_usdt'] ?? $result['pnl_net_usdt'] ?? $pnl);
            $roiNetPct = (float)($closeResult['roi_net_pct'] ?? $result['roi_net_pct'] ?? ($roi * 100));
            $roiNetSum += $roiNetPct;
            $feesUsdt = (float)($closeResult['fees_total_usdt'] ?? $result['fees_total_usdt'] ?? 0);
            $totalFeesUsdt += $feesUsdt;
            $feesBpsSum += $this->getEffectiveFeesBps($result, $trade);

            // P4.2-D3: Win check from close_result first
            $isWin = $closeResult['win'] ?? $labels['win'] ?? false;
            if ($isWin) {
                $wins++;
                $grossProfit += $pnl;
            } else {
                $losses++;
                $grossLoss += abs($pnl);
            }
            
            // Net win tracking
            if ($pnlNet > 0) {
                $winsNet++;
            }
            
            $tradeDrawdown = (float)($closeResult['max_drawdown_roi'] ?? $result['max_drawdown_roi'] ?? 0);
            if ($tradeDrawdown < $maxDrawdown) {
                $maxDrawdown = $tradeDrawdown;
            }

            // P4.2-D: Calculate duration in SECONDS (not minutes) for precision
            $openedTs = (int)($trade['entry']['opened_ts'] ?? $trade['opened_ts'] ?? 0);
            $closeTs = (int)($closeResult['close_ts'] ?? $trade['closed_ts'] ?? 0);
            $durationSec = 0;
            if ($openedTs > 0 && $closeTs > $openedTs) {
                $durationSec = max(0, $closeTs - $openedTs);
            }
            $durationSum += $durationSec;

            if (isset($closedBy[$closeReason])) {
                $closedBy[$closeReason]++;
            }
            
            // Per TZ: Track trailing statistics
            $trailing = $trade['trailing'] ?? [];
            if ($trailing['active'] ?? false) {
                $trailingActivatedCount++;
            }
            if ($closeReason === 'trailing_stop') {
                $closedByTrailingCount++;
            }
            
            // Track leverage and slippage
            $leverageSum += (int)($trade['entry']['leverage'] ?? $trade['risk']['leverage'] ?? 10);
            $slippageSum += (int)($trade['risk']['slippage_bps'] ?? $trade['entry']['slippage_bps'] ?? 20);
            
            // Track profile IDs
            $profileId = $trade['profile_id'] ?? 'default';
            $profileIds[$profileId] = ($profileIds[$profileId] ?? 0) + 1;
        }

        $winrate = $totalClosed > 0 ? $wins / $totalClosed : 0;
        $winrateNet = $totalClosed > 0 ? $winsNet / $totalClosed : 0;
        $roiAvg = $totalClosed > 0 ? $roiSum / $totalClosed : 0;
        $avgRoiNetPct = $totalClosed > 0 ? $roiNetSum / $totalClosed : 0;
        // P4.2-D: Duration in seconds, convert to minutes for display
        $avgDurationSec = $totalClosed > 0 ? $durationSum / $totalClosed : 0;
        $avgDurationMinutes = $avgDurationSec / 60; // e.g., 26 sec = 0.43 min
        $profitFactor = $grossLoss > 0 ? ($grossProfit / $grossLoss) : ($grossProfit > 0 ? self::MAX_PROFIT_FACTOR_DISPLAY : 0);
        $avgLeverage = $totalClosed > 0 ? round($leverageSum / $totalClosed, 1) : 0;
        $avgSlippageBps = $totalClosed > 0 ? round($slippageSum / $totalClosed, 0) : 0;
        $avgFeesBps = $totalClosed > 0 ? round($feesBpsSum / $totalClosed, 0) : 0;
        
        // Determine primary profile_id
        arsort($profileIds);
        $primaryProfileId = !empty($profileIds) ? array_key_first($profileIds) : null;

        $summary = [
            'updated_at' => date('c'),
            'total_trades_opened' => $totalOpen + $totalClosed,
            'total_trades_closed' => $totalClosed,
            'wins' => $wins,
            'losses' => $losses,
            'winrate' => round($winrate, 4),
            'roi_sum' => round($roiSum, 4),
            'roi_avg' => round($roiAvg, 6),
            'max_drawdown_roi' => round($maxDrawdown, 6),
            'avg_duration_minutes' => round($avgDurationMinutes, 1),
            'profit_factor' => round(min($profitFactor, self::MAX_PROFIT_FACTOR_DISPLAY), 2),
            'closed_by' => $closedBy,
            // Per TZ: Risk Profile v1 statistics
            'profile_id' => $primaryProfileId,
            'avg_leverage' => $avgLeverage,
            'avg_slippage_bps' => $avgSlippageBps,
            'trailing_activated_count' => $trailingActivatedCount,
            'closed_by_trailing_count' => $closedByTrailingCount,
            // Fee statistics
            'avg_fees_bps' => $avgFeesBps,
            'total_fees_usdt' => round($totalFeesUsdt, 4),
            'avg_roi_net_pct' => round($avgRoiNetPct, 4),
            'win_rate_net' => round($winrateNet, 4),
        ];

        $statsDir = $baseDir . '/stats';
        $this->ensureDir($statsDir);
        $this->writeJsonAtomic($statsDir . '/summary.json', $summary);
        
        // Also write stats_global.json
        $totalCompletedTrades = $totalClosed + $totalRejected;
        $statsGlobal = [
            'total_trades' => $totalCompletedTrades,
            'total_completed' => $totalCompletedTrades,
            'total_closed' => $totalClosed,
            'total_rejected' => $totalRejected,
            'total_open' => $totalOpen,
            'wins' => $wins,
            'losses' => $losses,
            'winrate' => round($winrate, 4),
            'total_roi' => round($roiSum, 4),
            'avg_roi' => round($roiAvg, 6),
            'max_drawdown' => round($maxDrawdown, 6),
            // P4.2-D: avg_duration in SECONDS (integer)
            'avg_duration' => (int)round($avgDurationSec, 0),
            'profit_factor' => round(min($profitFactor, self::MAX_PROFIT_FACTOR_DISPLAY), 2),
            // Per TZ: Risk Profile v1 statistics
            'profile_id' => $primaryProfileId,
            'avg_leverage' => $avgLeverage,
            'avg_slippage_bps' => $avgSlippageBps,
            'trailing_activated_count' => $trailingActivatedCount,
            'closed_by_trailing_count' => $closedByTrailingCount,
            // Fee statistics
            'avg_fees_bps' => $avgFeesBps,
            'total_fees_usdt' => round($totalFeesUsdt, 4),
            'avg_roi_net_pct' => round($avgRoiNetPct, 4),
            'win_rate_net' => round($winrateNet, 4),
        ];
        $this->writeJsonAtomic($baseDir . '/stats_global.json', $statsGlobal);
    }
    
    /**
     * Generate simulation.json and stats/summary.json for mode storage
     * FIX-4.1: Mode-aware version that takes $baseDir parameter
     * P0.4: Updated to use sim_trade_v1 schema (close_result.close_reason)
     * Writes: $baseDir/simulation.json and $baseDir/stats/summary.json
     *
     * @param string $baseDir Mode-specific storage directory
     */
    private function generateSimulationSummaryForMode(string $baseDir): void
    {
        $closedTrades = $this->loadTradesFromDir($baseDir . '/trades/closed');
        $rejectedTrades = $this->loadTradesFromDir($baseDir . '/trades/rejected');
        
        // Load stats_global.json if available (with null coalescing for JSON decode errors)
        $statsPath = $baseDir . '/stats_global.json';
        $stats = is_file($statsPath) ? (json_decode(file_get_contents($statsPath), true) ?? []) : [];
        
        $totalClosed = count($closedTrades);
        $totalRejected = count($rejectedTrades);
        
        // P0.4: Calculate closed_by statistics and duration using sim_trade_v1 schema
        // close_reason: close_result.close_reason (NOT trade.close_reason)
        // duration: entry.opened_ts -> close_result.close_ts
        $closedBy = [
            'tp' => 0,
            'sl' => 0,
            'trailing_sl' => 0,
            'expired_signal' => 0,
            'expired_entry_timeout' => 0,
        ];
        $durationSum = 0;
        
        // P0.4: Mapping from sim_trade_v1 close_reason to UI keys
        $reasonMapping = [
            'take_profit' => 'tp',
            'stop_loss' => 'sl',
            'trailing_stop' => 'trailing_sl',
            'expired_signal' => 'expired_signal',
            'expired_entry_timeout' => 'expired_entry_timeout',
        ];
        
        foreach ($closedTrades as $trade) {
            // P0.4: Read from sim_trade_v1 close_result, fallback to legacy fields
            $closeResult = $trade['close_result'] ?? [];
            $closeReason = $closeResult['close_reason'] ?? $trade['close_reason'] ?? 'unknown';
            
            // Map close_reason to UI key
            $uiKey = $reasonMapping[$closeReason] ?? null;
            if ($uiKey !== null && isset($closedBy[$uiKey])) {
                $closedBy[$uiKey]++;
            }
            
            // P4.2-D: Calculate duration in SECONDS for precision (not floor minutes)
            $openedTs = (int)($trade['entry']['opened_ts'] ?? $trade['opened_ts'] ?? 0);
            $closeTs = (int)($closeResult['close_ts'] ?? $trade['closed_ts'] ?? 0);
            if ($openedTs > 0 && $closeTs > $openedTs) {
                $durationSum += max(0, $closeTs - $openedTs); // seconds
            }
        }
        
        // P4.2-D: Duration in seconds, convert to minutes with decimal precision
        $avgDurationMinutes = $totalClosed > 0 ? ($durationSum / $totalClosed) / 60 : 0;
        
        // Write simulation.json for Brain integration
        $simulation = [
            'updated_at' => date('c'),
            'total_signals' => $totalClosed + $totalRejected,
            'closed' => $totalClosed,
            'rejected' => $totalRejected,
            'win_rate' => (float)($stats['winrate'] ?? 0),
            'avg_roi' => (float)($stats['avg_roi'] ?? 0),
            'max_drawdown' => (float)($stats['max_drawdown'] ?? 0),
            'profit_factor' => (float)($stats['profit_factor'] ?? 0),
            'source' => 'parser6_simulator',
        ];
        
        $this->writeJsonAtomic($baseDir . '/simulation.json', $simulation);
        
        // Write stats/summary.json for Simulator UI (includes closed_by for Close Reasons)
        $summary = [
            'updated_at' => date('c'),
            'total_trades_closed' => $totalClosed,
            'wins' => (int)($stats['wins'] ?? 0),
            'losses' => (int)($stats['losses'] ?? 0),
            'winrate' => (float)($stats['winrate'] ?? 0),
            'roi_avg' => (float)($stats['avg_roi'] ?? 0),
            'avg_duration_minutes' => round($avgDurationMinutes, 1),
            'profit_factor' => (float)($stats['profit_factor'] ?? 0),
            'closed_by' => $closedBy,
        ];
        
        $statsDir = $baseDir . '/stats';
        $this->ensureDir($statsDir);
        $this->writeJsonAtomic($statsDir . '/summary.json', $summary);
    }
    
    /**
     * Write state.json for a specific mode storage directory
     * FIX-4.1: Mode-aware version that takes $baseDir parameter
     * Writes: $baseDir/state.json
     *
     * @param string $baseDir Mode-specific storage directory
     * @param array $state State data to write
     */
    private function writeStateForMode(string $baseDir, array $state): void
    {
        $path = $baseDir . '/state.json';
        $this->ensureDir(dirname($path));
        $this->writeJsonAtomic($path, $state);
    }
    
    /**
     * Load state.json for a specific mode storage directory
     * C1: Mode-aware state loading for management commands
     *
     * @param string $baseDir Mode-specific storage directory
     * @return array State data or empty default state
     */
    private function loadStateForMode(string $baseDir): array
    {
        $path = $baseDir . '/state.json';
        
        if (!is_file($path)) {
            // Return empty default state with active trades array
            return [
                'trades_active' => [],
                'active_trades' => [],
                'ts' => date('c'),
            ];
        }
        
        $content = @file_get_contents($path);
        if ($content === false) {
            return [
                'trades_active' => [],
                'active_trades' => [],
                'ts' => date('c'),
            ];
        }
        
        $state = @json_decode($content, true);
        if (!is_array($state)) {
            return [
                'trades_active' => [],
                'active_trades' => [],
                'ts' => date('c'),
            ];
        }
        
        return $state;
    }
}

/* RULES
 * NO HARDCODE. CONFIG FIRST. SystemPaths ONLY.
 * Stats methods handle simulation statistics and state management.
 * All statistics are computed from actual trade data, not cached values.
 * Mode-aware methods take $baseDir parameter for storage path isolation.
 */
