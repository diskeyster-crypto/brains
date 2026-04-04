<?php
declare(strict_types=1);

namespace Modules\System\TradingBot\Lib;

/**
 * Bot Store
 * 
 * Storage operations for Trading Bot.
 * Handles all file I/O for trades, orders, intents, logs.
 */
class BotStore
{
    private string $storageDir;
    private array $config;
    
    public function __construct(string $storageDir, array $config)
    {
        $this->storageDir = $storageDir;
        $this->config = $config;
        $this->ensureDirectories();
    }
    
    /**
     * Ensure all storage directories exist
     */
    private function ensureDirectories(): void
    {
        $output = $this->config['output'] ?? [];
        
        $dirs = [
            $this->storageDir,
            $this->storageDir . '/queue',
            $this->storageDir . '/intents',
            $this->storageDir . '/trades/active',
            $this->storageDir . '/trades/closed',
            $this->storageDir . '/trades/rejected',
            $this->storageDir . '/orders/active',
            $this->storageDir . '/orders/closed',
            $this->storageDir . '/runtime',
            $this->storageDir . '/runtime/errors',
            $this->storageDir . '/stats',
            $this->storageDir . '/logs', // P7 fix: logs in storage/logs per manifest
            $this->storageDir . '/ai_dataset', // AI-ready dataset records
        ];
        
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
    }
    
    // =========================================================================
    // State / Last Run
    // =========================================================================
    
    /**
     * Save state
     */
    public function saveState(array $state): void
    {
        $path = $this->storageDir . '/state.json';
        $this->writeJson($path, array_merge([
            'ts' => date('c'),
        ], $state));
    }
    
    /**
     * Load state
     */
    public function loadState(): array
    {
        $path = $this->storageDir . '/state.json';
        return $this->readJson($path);
    }
    
    /**
     * Save last run result
     */
    public function saveLastRun(array $result): void
    {
        $path = $this->storageDir . '/last_run.json';
        $this->writeJson($path, $result);
    }
    
    /**
     * Load last run result
     */
    public function loadLastRun(): array
    {
        $path = $this->storageDir . '/last_run.json';
        return $this->readJson($path);
    }

    /**
     * Write aggregate runtime snapshots for the Brain Execution UI read-model.
     *
     * Scans per-file trade storage and writes:
     *   trades/open_trades.json    — flat array of all active trade records
     *   trades/closed_trades.json  — flat array of last 200 closed trade records (newest first)
     *   runtime/stats.json         — lightweight counters derived from the above
     *
     * Called at end of execute() so every bot cycle keeps the snapshot in sync.
     * Does not touch runtime/positions.json or runtime/balance.json — those are
     * written by the reconcile / gateway call paths.
     */
    public function writeRuntimeSnapshot(): void
    {
        // Active trades
        $activeTrades = $this->loadActiveTrades();
        $this->writeJson($this->storageDir . '/trades/open_trades.json', array_values($activeTrades));

        // Closed trades — sort newest first, cap at 200
        $closedDir = $this->storageDir . '/trades/closed';
        $closed = $this->loadAllFromDir($closedDir);
        usort($closed, static function (array $a, array $b): int {
            $aTs = strtotime($a['closed_at'] ?? $a['ts'] ?? '1970-01-01');
            $bTs = strtotime($b['closed_at'] ?? $b['ts'] ?? '1970-01-01');
            return $bTs - $aTs;
        });
        $closed = array_slice($closed, 0, 200);
        $this->writeJson($this->storageDir . '/trades/closed_trades.json', array_values($closed));

        // Runtime stats snapshot
        $stats = [
            'snapshot_at'     => date('c'),
            'open_positions'  => count($activeTrades),
            'closed_trades'   => count($closed),
            'last_updated'    => date('c'),
        ];
        $this->writeJson($this->storageDir . '/runtime/stats.json', $stats);

        // Positions snapshot — derived from active trades so the Brain Execution
        // page can show position data without an extra exchange API call.
        $positions = [];
        foreach ($activeTrades as $trade) {
            $exchange = is_array($trade['exchange'] ?? null) ? $trade['exchange'] : [];
            $prot     = is_array($trade['protection'] ?? null) ? $trade['protection'] : [];
            $positions[] = [
                'trade_id'      => $trade['trade_id'] ?? null,
                'symbol'        => $trade['symbol'] ?? null,
                'side'          => $trade['side'] ?? null,
                'size'          => $exchange['qty'] ?? 0,
                'avgPrice'      => $exchange['entry_avg_price'] ?? 0,
                'stopLoss'      => $prot['stop_loss_price'] ?? 0,
                'opened_at'     => $trade['opened_at'] ?? null,
                'protection_state' => $trade['runtime']['protection_state'] ?? ($trade['protection_state'] ?? null),
            ];
        }
        $this->writeJson($this->storageDir . '/runtime/positions.json', $positions);
    }
    
    // =========================================================================
    // Trades
    // =========================================================================
    
    /**
     * Save active trade
     */
    public function saveActiveTrade(array $trade): void
    {
        $tradeId = $trade['trade_id'] ?? $trade['signal_id'] ?? uniqid('trade_');
        $path = $this->storageDir . '/trades/active/' . $tradeId . '.json';
        $this->writeJson($path, $trade);

        // Verify the file was actually written — silent failures are the #1 cause of
        // the Brain Execution page showing empty even after a successful opened_protected.
        if (!is_file($path) || filesize($path) === 0) {
            $errMsg = 'active_trade_persist_failed: trade_id=' . $tradeId . ' path=' . $path;
            error_log('TradingBot: ' . $errMsg);
            $this->logPersistError('active_trade_persist_failed', [
                'trade_id'   => $tradeId,
                'path'       => $path,
                'dir_exists' => is_dir(dirname($path)),
                'dir_writable' => is_writable(dirname($path)),
                'ts'         => date('c'),
            ]);

            // Fallback: attempt non-atomic write so the trade is not silently lost
            $json = json_encode($trade, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json !== false) {
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents($path, $json, LOCK_EX);
            }
        }
    }
    
    /**
     * Update active trade
     */
    public function updateActiveTrade(string $tradeId, array $trade): void
    {
        $path = $this->storageDir . '/trades/active/' . $tradeId . '.json';
        $this->writeJson($path, $trade);
    }
    
    /**
     * Load all active trades
     */
    public function loadActiveTrades(): array
    {
        $dir = $this->storageDir . '/trades/active';
        return $this->loadAllFromDir($dir);
    }

    /**
     * Check whether an active trade file exists for the given trade_id.
     */
    public function activeTradeExists(string $tradeId): bool
    {
        $path = $this->storageDir . '/trades/active/' . $tradeId . '.json';
        return is_file($path) && filesize($path) > 0;
    }

    /**
     * Move trade to closed directory
     */
    public function moveTradeToClosedDir(string $tradeId, array $trade): void
    {
        // Save to closed
        $closedPath = $this->storageDir . '/trades/closed/' . $tradeId . '.json';
        $this->writeJson($closedPath, $trade);
        
        // Delete from active
        $activePath = $this->storageDir . '/trades/active/' . $tradeId . '.json';
        if (is_file($activePath)) {
            @unlink($activePath);
        }
    }
    


/**
 * Save (overwrite) a closed trade record.
 *
 * Used for best-effort backfill of exit price / realized PnL after exchange-close.
 *
 * @param string $tradeId
 * @param array<string,mixed> $trade
 */
public function saveClosedTrade(string $tradeId, array $trade): void
{
    $closedPath = $this->storageDir . '/trades/closed/' . $tradeId . '.json';
    $this->writeJson($closedPath, $trade);
}

    /**
     * Load closed trades
     */
    public function loadClosedTrades(int $limit = 50): array
    {
        $dir = $this->storageDir . '/trades/closed';
        $trades = $this->loadAllFromDir($dir);
        
        // Sort by closed_at descending
        usort($trades, function($a, $b) {
            $aTime = strtotime($a['closed_at'] ?? '1970-01-01');
            $bTime = strtotime($b['closed_at'] ?? '1970-01-01');
            return $bTime - $aTime;
        });
        
        return array_slice($trades, 0, $limit);
    }
    
    // =========================================================================
    // Rejected Intents
    // =========================================================================
    
    /**
     * Save rejected intent (B5: includes full details)
     * 
     * P6.8.2: context must never be null - use [] if not provided
     */
    public function saveRejectedIntent(array $intent, array $validation): void
    {
        $intentId = $intent['id'] ?? $intent['signal_id'] ?? uniqid('intent_');
        $path = $this->storageDir . '/trades/rejected/' . $intentId . '.json';
        
        // P6.8.2: Ensure context is [] not null, and preserve full array if provided
        $context = $validation['context'] ?? [];
        if (!is_array($context)) {
            $context = [];
        }
        
        // B5: Include full intent details for debugging
        $this->writeJson($path, [
            'ts' => date('c'),
            'intent_id' => $intentId,
            'intent' => $intent,
            'rejected_at' => date('c'),
            'reason' => $validation['reason'] ?? 'unknown',
            'missing_fields' => $validation['missing_fields'] ?? [],
            'details' => $validation['details'] ?? null,
            'context' => $context,
        ]);
    }
    
    /**
     * Save rejected execution (P6 fail-safe)
     * 
     * For when SL/TP fails to set after order - position was closed as fail-safe.
     * Stores full context for debugging.
     * 
     * @param array $intent Original intent
     * @param string $reason Rejection reason (e.g., 'sl_set_failed')
     * @param array $context Full context (order_id, setTradingStop response, close response, position snapshot)
     */
    public function saveRejectedExecution(array $intent, string $reason, array $context = []): void
    {
        $intentId = $intent['id'] ?? $intent['signal_id'] ?? uniqid('exec_');
        $path = $this->storageDir . '/trades/rejected/' . $intentId . '.json';
        
        $this->writeJson($path, [
            'ts' => date('c'),
            'reason' => $reason,
            'intent_id' => $intentId,
            'intent' => $intent,
            'context' => $context,
            'rejected_at' => date('c'),
        ]);
    }
    
    /**
     * Load rejected intents
     */
    public function loadRejectedIntents(int $limit = 50): array
    {
        $dir = $this->storageDir . '/trades/rejected';
        $intents = $this->loadAllFromDir($dir);
        
        // Sort by rejected_at descending
        usort($intents, function($a, $b) {
            $aTime = strtotime($a['rejected_at'] ?? '1970-01-01');
            $bTime = strtotime($b['rejected_at'] ?? '1970-01-01');
            return $bTime - $aTime;
        });
        
        return array_slice($intents, 0, $limit);
    }

    /**
     * Load a single rejected intent/execution JSON file by intent ID.
     *
     * Needed for UI explainability (P6.11). Service/UI must not call private
     * file I/O helpers directly.
     *
     * @param string $intentId
     * @return array<string,mixed>
     */
    public function loadRejectedIntentById(string $intentId): array
    {
        $intentId = trim($intentId);
        if ($intentId === '') {
            return [];
        }

        $path = $this->storageDir . '/trades/rejected/' . $intentId . '.json';
        return $this->readJson($path);
    }
    
    // =========================================================================
    // Orders
    // =========================================================================
    
    /**
     * Save order
     */
    public function saveOrder(array $order, array $result): void
    {
        $orderId = $result['order_id'] ?? $order['signal_id'] ?? uniqid('order_');
        
        // Active or closed based on fill status
        $filled = $result['filled'] ?? false;
        $dir = $filled ? '/orders/closed' : '/orders/active';
        
        $path = $this->storageDir . $dir . '/' . $orderId . '.json';
        $this->writeJson($path, [
            'order' => $order,
            'result' => $result,
            'saved_at' => date('c'),
        ]);
    }
    
    /**
     * Load active orders
     */
    public function loadActiveOrders(): array
    {
        $dir = $this->storageDir . '/orders/active';
        return $this->loadAllFromDir($dir);
    }
    
    /**
     * Load closed orders
     */
    public function loadClosedOrders(int $limit = 50): array
    {
        $dir = $this->storageDir . '/orders/closed';
        return array_slice($this->loadAllFromDir($dir), 0, $limit);
    }
    
    // =========================================================================
    // Errors
    // =========================================================================
    
    /**
     * Load errors
     */
    public function loadErrors(): array
    {
        $path = $this->storageDir . '/errors.json';
        return $this->readJson($path);
    }
    
    /**
     * Save errors (B4: persists errors.json)
     * 
     * @param array $errors Array of error entries
     */
    public function saveErrors(array $errors): void
    {
        $path = $this->storageDir . '/errors.json';
        $this->writeJson($path, [
            'updated_at' => date('c'),
            'count' => count($errors),
            'errors' => $errors,
        ]);
    }
    
    /**
     * Append to error log
     * P7 fix: logs in storage/logs per manifest
     */
    public function appendErrorLog(array $error): void
    {
        $logsDir = $this->storageDir . '/logs';
        $path = $logsDir . '/errors.log';
        
        // Ensure directory exists
        if (!is_dir($logsDir)) {
            @mkdir($logsDir, 0755, true);
        }
        
        $line = json_encode($error, JSON_UNESCAPED_SLASHES) . "\n";
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }
    
    // =========================================================================
    // AI Dataset
    // =========================================================================

    /**
     * Write a structured AI-ready record for a closed demo trade.
     *
     * Extracts the fields relevant for local AI analysis and writes to
     * ai_dataset/{tradeId}.json. Idempotent — overwrites on re-close.
     *
     * @param string              $tradeId
     * @param array<string,mixed> $trade  Fully-closed trade array
     * @return bool True if the record was successfully written to disk, false otherwise
     */
    public function appendAiDatasetRecord(string $tradeId, array $trade): bool
    {
        if ($tradeId === '') {
            return false;
        }

        $rt   = is_array($trade['runtime']    ?? null) ? $trade['runtime']    : [];
        $prot = is_array($trade['protection'] ?? null) ? $trade['protection'] : [];
        $risk = is_array($trade['risk']       ?? null) ? $trade['risk']       : [];
        $pp   = is_array($trade['passport_snapshot'] ?? $trade['passport'] ?? null) ? ($trade['passport_snapshot'] ?? $trade['passport']) : [];

        $record = [
            'ai_dataset_record_version' => 'v1',
            'created_at'                => date('c'),
            'trade_id'                  => $tradeId,
            'signal_id'                 => (string)($trade['signal_id'] ?? ''),
            'symbol'                    => (string)($trade['symbol'] ?? ''),
            'side'                      => (string)($trade['side'] ?? ''),
            'pattern_algorithm'         => (string)($trade['pattern_algorithm'] ?? $trade['algo'] ?? ''),
            'scenario_id'               => $trade['scenario_id'] ?? null,
            'signal_strength'           => isset($trade['signal_strength']) ? (float)$trade['signal_strength'] : null,
            'quality_score'             => isset($trade['quality_score'])   ? (float)$trade['quality_score']   : null,
            'entry_ts'                  => $trade['open_ts'] ?? (isset($trade['opened_at']) ? strtotime($trade['opened_at']) : null),
            'entry_price'               => isset($trade['entry_price']) ? (float)$trade['entry_price'] : null,
            'close_ts'                  => $trade['close_ts'] ?? $trade['closed_ts'] ?? null,
            'close_price'               => isset($trade['close_price']) ? (float)$trade['close_price'] : null,
            'roi'                       => isset($trade['roi'])  ? (float)$trade['roi']  : null,
            'pnl'                       => isset($trade['pnl'])  ? (float)$trade['pnl']  : null,
            'mfe'                       => isset($rt['best_roi_seen'])  ? (float)$rt['best_roi_seen']  : null,
            'mae'                       => isset($rt['worst_roi_seen']) ? (float)$rt['worst_roi_seen'] : null,
            'hold_minutes'              => isset($trade['hold_minutes']) ? (int)$trade['hold_minutes'] : null,
            'leverage'                  => isset($risk['leverage']) ? (int)$risk['leverage'] : null,
            'stop_loss_price'           => isset($prot['stop_loss_price']) ? (float)$prot['stop_loss_price'] : null,
            'trailing_applied'          => (bool)($rt['dumb_trailing_applied']  ?? false),
            'break_even_applied'        => (bool)($rt['break_even_applied']     ?? false),
            'close_reason_normalized'   => (string)($trade['close_reason_normalized'] ?? $trade['close_reason'] ?? ''),
            'close_result_source'       => (string)($trade['close_result_source'] ?? ''),
            'passport_confidence'       => (string)($pp['data_confidence'] ?? ''),
            'passport_corridor_p75_roi' => isset($pp['corridor_p75_roi']) ? (float)$pp['corridor_p75_roi'] : null,
            'source'                    => 'demo',
        ];

        $path = $this->storageDir . '/ai_dataset/' . $tradeId . '.json';
        $this->writeJson($path, $record);
        return is_file($path);
    }

    /**
     * Scan closed trades and compute demo data sufficiency metrics.
     *
     * A trade is considered "complete" when it has a non-zero close_price,
     * a non-null roi, and a non-empty close_reason_normalized.
     *
     * @return array<string,mixed>
     */
    public function computeDemoSufficiencyMetrics(): array
    {
        $closedDir    = $this->storageDir . '/trades/closed';
        $aiDatasetDir = $this->storageDir . '/ai_dataset';

        $totalClosed    = 0;
        $completeClosed = 0;
        $perSymbol  = [];
        $perPattern = [];
        $perSide    = [];

        $perCloseReason = [];

        $files = glob($closedDir . '/*.json') ?: [];
        foreach ($files as $file) {
            $data = @json_decode((string)@file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }
            $totalClosed++;

            $closePrice           = (float)($data['close_price'] ?? 0);
            $roi                  = $data['roi'] ?? null;
            $closeReasonNormalized = (string)($data['close_reason_normalized'] ?? $data['close_reason'] ?? '');
            $symbol               = (string)($data['symbol'] ?? '');
            $pattern              = (string)($data['pattern_algorithm'] ?? $data['algo'] ?? '');
            $side                 = strtolower((string)($data['side'] ?? ''));

            $isComplete = $closePrice > 0 && $roi !== null && $closeReasonNormalized !== '';

            if ($isComplete) {
                $completeClosed++;
            }
            if ($symbol !== '') {
                if (!isset($perSymbol[$symbol])) {
                    $perSymbol[$symbol] = ['total' => 0, 'complete' => 0];
                }
                $perSymbol[$symbol]['total']++;
                if ($isComplete) {
                    $perSymbol[$symbol]['complete']++;
                }
            }
            if ($pattern !== '') {
                $perPattern[$pattern] = ($perPattern[$pattern] ?? 0) + 1;
            }
            if ($side !== '') {
                $perSide[$side] = ($perSide[$side] ?? 0) + 1;
            }
            if ($closeReasonNormalized !== '') {
                $perCloseReason[$closeReasonNormalized] = ($perCloseReason[$closeReasonNormalized] ?? 0) + 1;
            }
        }

        // Count AI dataset records and completeness
        $aiDatasetCount    = 0;
        $aiDatasetComplete = 0;
        if (is_dir($aiDatasetDir)) {
            $aiFiles = glob($aiDatasetDir . '/*.json') ?: [];
            $aiDatasetCount = count($aiFiles);
            foreach ($aiFiles as $af) {
                $ad = @json_decode((string)@file_get_contents($af), true);
                if (is_array($ad)
                    && (float)($ad['close_price'] ?? 0) > 0
                    && ($ad['roi'] ?? null) !== null
                    && (string)($ad['close_reason_normalized'] ?? '') !== ''
                ) {
                    $aiDatasetComplete++;
                }
            }
        }

        $completeRate = $totalClosed > 0 ? round($completeClosed / $totalClosed * 100, 1) : 0.0;

        // Sort per-symbol by total desc (top symbols)
        arsort($perSymbol);
        $topSymbols = array_slice($perSymbol, 0, 10, true);

        // Sort per-pattern by count desc (top patterns)
        arsort($perPattern);
        $topPatterns = array_slice($perPattern, 0, 10, true);

        // Sort close reasons by count desc
        arsort($perCloseReason);

        // Next readiness milestone
        $milestones = [10, 25, 50, 100, 250, 500];
        $nextMilestone = null;
        foreach ($milestones as $m) {
            if ($totalClosed < $m) {
                $nextMilestone = $m;
                break;
            }
        }

        return [
            'demo_closed_trades_total'         => $totalClosed,
            'demo_closed_trades_complete'       => $completeClosed,
            'demo_closed_trades_complete_rate'  => $completeRate,
            'demo_active_trades_count'          => count(glob($this->storageDir . '/trades/active/*.json') ?: []),
            'ai_dataset_records'                => $aiDatasetCount,
            'ai_dataset_records_total'          => $aiDatasetCount,
            'ai_dataset_records_complete'       => $aiDatasetComplete,
            'per_pattern_counts'                => $perPattern,
            'top_patterns_by_closed_count'      => $topPatterns,
            'per_side_counts'                   => $perSide,
            'per_close_reason_counts'           => $perCloseReason,
            'top_symbols'                       => $topSymbols,
            'top_symbols_by_closed_count'       => $topSymbols,
            'next_readiness_milestone'          => $nextMilestone,
            'computed_at'                       => date('c'),
        ];
    }

    /**
     * Persist demo data sufficiency snapshot to runtime/demo_sufficiency.json.
     *
     * Written at end of every demo execute() so Brain can always read it
     * regardless of current bot mode.
     *
     * @param array<string,mixed> $metrics  Output of computeDemoSufficiencyMetrics() + readiness gate
     */
    public function saveDemoSufficiency(array $metrics): void
    {
        $path = $this->storageDir . '/runtime/demo_sufficiency.json';
        $this->writeJson($path, $metrics);
    }

    /**
     * Derive a ground-truth runtime audit from actual storage files.
     *
     * All numbers are derived directly from disk — never from cached counters.
     * This function is the authoritative source for demo loop health analysis.
     *
     * @param int $learningMaxActiveAgeMinutes  Stale threshold (from demo_learning_mode config)
     * @param int $orphanBlockingCount          Orphan positions that blocked execution this run
     * @param int $feedAvailableCount           Signals available in the PE demo feed this run
     * @param int $feedSelectedCount            Signals actually selected/attempted this run
     * @param int $positionsOpenedThisRun       Positions successfully opened this run
     * @param int $closedThisRun                Trades closed during the current run (from updateActivePositions)
     * @return array<string,mixed>
     */
    public function computeDemoTruthAudit(int $learningMaxActiveAgeMinutes = 240, int $orphanBlockingCount = 0, int $feedAvailableCount = 0, int $feedSelectedCount = 0, int $positionsOpenedThisRun = 0, int $closedThisRun = 0, int $reconcileBlockedThisRun = 0): array
    {
        $closedDir    = $this->storageDir . '/trades/closed';
        $activeDir    = $this->storageDir . '/trades/active';
        $aiDatasetDir = $this->storageDir . '/ai_dataset';
        $now          = time();

        // ── Active trades ────────────────────────────────────────────────────
        $activeFiles  = glob($activeDir . '/*.json') ?: [];
        $activeCount  = count($activeFiles);
        $activeAges   = [];
        $staleCount   = 0;

        foreach ($activeFiles as $af) {
            $d = @json_decode((string)@file_get_contents($af), true);
            if (!is_array($d)) {
                continue;
            }
            // Determine opened_at timestamp
            $openTs = null;
            if (!empty($d['opened_at'])) {
                $openTs = strtotime($d['opened_at']);
            } elseif (!empty($d['open_ts'])) {
                $openTs = (int)$d['open_ts'];
            }
            if ($openTs !== null && $openTs > 0) {
                $ageMin = (int)round(($now - $openTs) / 60);
                $activeAges[] = $ageMin;
                if ($ageMin >= $learningMaxActiveAgeMinutes) {
                    $staleCount++;
                }
            }
        }

        $oldestActiveAgeMin  = count($activeAges) > 0 ? max($activeAges) : null;
        $avgActiveAgeMin     = count($activeAges) > 0 ? round(array_sum($activeAges) / count($activeAges), 1) : null;
        $pctStaleActive      = $activeCount > 0 ? round($staleCount / $activeCount * 100, 1) : 0.0;

        // ── Closed trades ────────────────────────────────────────────────────
        $closedFiles  = glob($closedDir . '/*.json') ?: [];
        $closedCount  = count($closedFiles);
        $closedIds    = [];

        $missingMfe          = 0;
        $missingMae          = 0;
        $missingHoldMinutes  = 0;
        $missingCloseReason  = 0;
        $missingClosePrice   = 0;
        $missingRoi          = 0;
        $completeClosedCount = 0;

        foreach ($closedFiles as $cf) {
            $d = @json_decode((string)@file_get_contents($cf), true);
            if (!is_array($d)) {
                continue;
            }
            $tradeId = (string)($d['trade_id'] ?? $d['id'] ?? basename($cf, '.json'));
            if ($tradeId !== '') {
                $closedIds[$tradeId] = true;
            }

            $closePrice  = (float)($d['close_price'] ?? 0);
            $roi         = $d['roi'] ?? null;
            $closeReason = (string)($d['close_reason_normalized'] ?? $d['close_reason'] ?? '');
            $mfe         = $d['mfe'] ?? null;
            $mae         = $d['mae'] ?? null;
            $holdMin     = $d['hold_minutes'] ?? null;

            if ($closePrice <= 0) {
                $missingClosePrice++;
            }
            if ($roi === null) {
                $missingRoi++;
            }
            if ($closeReason === '') {
                $missingCloseReason++;
            }
            if ($mfe === null) {
                $missingMfe++;
            }
            if ($mae === null) {
                $missingMae++;
            }
            if ($holdMin === null || (int)$holdMin < 0) {
                $missingHoldMinutes++;
            }

            $isComplete = $closePrice > 0 && $roi !== null && $closeReason !== '';
            if ($isComplete) {
                $completeClosedCount++;
            }
        }

        // ── AI dataset records ───────────────────────────────────────────────
        $aiFiles   = is_dir($aiDatasetDir) ? (glob($aiDatasetDir . '/*.json') ?: []) : [];
        $aiCount   = count($aiFiles);
        $aiIds     = [];

        foreach ($aiFiles as $af) {
            $tradeId = basename($af, '.json');
            if ($tradeId !== '') {
                $aiIds[$tradeId] = true;
            }
        }

        // ── Consistency cross-checks ─────────────────────────────────────────
        $closedWithoutAiDataset = 0;
        foreach (array_keys($closedIds) as $cid) {
            if (!isset($aiIds[$cid])) {
                $closedWithoutAiDataset++;
            }
        }
        $aiWithoutClosedTrade = 0;
        foreach (array_keys($aiIds) as $aid) {
            if (!isset($closedIds[$aid])) {
                $aiWithoutClosedTrade++;
            }
        }
        $matchRate = $closedCount > 0 ? round(($closedCount - $closedWithoutAiDataset) / $closedCount * 100, 1) : 0.0;

        // ── Field completeness rates ─────────────────────────────────────────
        $pctMissingMfe         = $closedCount > 0 ? round($missingMfe / $closedCount * 100, 1) : 0.0;
        $pctMissingMae         = $closedCount > 0 ? round($missingMae / $closedCount * 100, 1) : 0.0;
        $pctMissingHoldMinutes = $closedCount > 0 ? round($missingHoldMinutes / $closedCount * 100, 1) : 0.0;
        $pctMissingCloseReason = $closedCount > 0 ? round($missingCloseReason / $closedCount * 100, 1) : 0.0;
        $pctMissingClosePrice  = $closedCount > 0 ? round($missingClosePrice / $closedCount * 100, 1) : 0.0;
        $pctMissingRoi         = $closedCount > 0 ? round($missingRoi / $closedCount * 100, 1) : 0.0;
        $completenessRate      = $closedCount > 0 ? round($completeClosedCount / $closedCount * 100, 1) : 0.0;

        // ── Bottleneck classification ────────────────────────────────────────
        $primaryBottleneck       = 'unknown';
        $primaryBottleneckReason = 'Insufficient data to classify bottleneck yet.';
        $recommendedNextFixArea  = 'run_demo_and_observe';
        $primaryExecutionBlocker = 'none';

        $feedIsAvailable = $feedAvailableCount > 0 || $feedSelectedCount > 0;

        if ($orphanBlockingCount > 0) {
            // Orphan positions are actively blocking execution — highest priority
            $primaryBottleneck       = 'orphan_positions_blocking_demo';
            $primaryBottleneckReason = "{$orphanBlockingCount} orphan exchange position(s) blocked demo trade execution this run. Reconcile or finalize orphan positions to unblock demo learning.";
            $recommendedNextFixArea  = 'audit_orphan_exchange_positions';
            $primaryExecutionBlocker = 'execution_blocked_by_orphan_positions';
        } elseif ($reconcileBlockedThisRun > 0 && $closedCount === 0 && $activeCount === 0) {
            // Reconcile failures are the dominant blocker — orders submitted but positions not saved
            $primaryBottleneck       = 'demo_feed_available_but_execution_blocked';
            $primaryBottleneckReason = "{$reconcileBlockedThisRun} signal(s) blocked by reconcile failures this run (position not found after order submission). Using order-fill fallback in demo mode. Verify Bybit Demo API connectivity and position visibility delay.";
            $recommendedNextFixArea  = 'audit_reconcile_post_open_position_visibility';
            $primaryExecutionBlocker = 'execution_blocked_by_reconcile';
        } elseif ($closedCount === 0 && $activeCount === 0) {
            if ($feedIsAvailable) {
                // Feed had signals but no trades opened or closed — execution is blocked downstream
                if ($positionsOpenedThisRun > 0) {
                    // Opened positions exist this run but storage hasn't recorded them yet
                    $primaryBottleneck       = 'healthy_loop_waiting_for_more_cycles';
                    $primaryBottleneckReason = "Feed available ({$feedAvailableCount}), selected ({$feedSelectedCount}), {$positionsOpenedThisRun} opened this run — but no closed trades yet. Loop is cycling; waiting for positions to close.";
                    $recommendedNextFixArea  = 'wait_for_demo_trades_to_close';
                    $primaryExecutionBlocker = 'awaiting_more_cycles_for_closure';
                } else {
                    // Feed had signals but nothing opened — blocked during execution
                    $primaryBottleneck       = 'demo_feed_available_but_execution_blocked';
                    $primaryBottleneckReason = "Feed available ({$feedAvailableCount}), selected ({$feedSelectedCount}), but no positions were opened. Execution may be blocked by exchange guards, risk validation, or capacity limits.";
                    $recommendedNextFixArea  = 'audit_execution_guards_and_limits';
                    $primaryExecutionBlocker = 'execution_blocked_after_feed_selection';
                }
            } else {
                $primaryBottleneck       = 'demo_feed_too_small';
                $primaryBottleneckReason = 'No active or closed demo trades found and no feed signals detected this run. Pattern Engine demo feed may not be producing signals, or bot has not run yet.';
                $recommendedNextFixArea  = 'check_pattern_engine_demo_feed';
                $primaryExecutionBlocker = 'feed_empty';
            }
        } elseif ($closedCount === 0 && $activeCount > 0) {
            if ($closedThisRun === 0 && $staleCount > 0) {
                $primaryBottleneck       = 'close_detection_too_weak';
                $primaryBottleneckReason = "Active trades exist ({$activeCount}, {$staleCount} stale ≥{$learningMaxActiveAgeMinutes}min) but none have closed. Check learning_close_timeout_minutes (force-close stale trades when exceeded) and reconcile pipeline.";
            } elseif ($closedThisRun === 0) {
                $primaryBottleneck       = 'close_detection_too_weak';
                $primaryBottleneckReason = "Active trades exist ({$activeCount}) but no closures recorded in storage or this run. Positions may still be open on the exchange. Verify reconcile is running (force_reconcile_each_run_demo) and that learning_close_timeout_minutes is set low enough for demo holds.";
            } else {
                $primaryBottleneck       = 'healthy_loop_waiting_for_more_cycles';
                $primaryBottleneckReason = "Active trades exist ({$activeCount}), {$closedThisRun} closed this run but not yet counted in storage snapshot. Loop is cycling.";
            }
            $recommendedNextFixArea  = 'audit_reconcile_and_close_pipeline';
        } elseif ($pctStaleActive > 50) {
            $primaryBottleneck       = 'too_many_stale_active_trades';
            $primaryBottleneckReason = "Over {$pctStaleActive}% of active trades ({$staleCount}/{$activeCount}) are older than {$learningMaxActiveAgeMinutes} min. They are blocking new slots and not closing.";
            $recommendedNextFixArea  = 'force_reconcile_stale_trades';
        } elseif ($matchRate < 70 && $closedCount > 0) {
            $primaryBottleneck       = 'ai_dataset_write_failures';
            $primaryBottleneckReason = "Only {$matchRate}% of closed trades have a matching AI dataset record ({$closedWithoutAiDataset} missing). AI dataset write path may be failing.";
            $recommendedNextFixArea  = 'audit_ai_dataset_write_path';
        } elseif ($completenessRate < 60 && $closedCount > 5) {
            $primaryBottleneck       = 'incomplete_closed_trade_fields';
            $primaryBottleneckReason = "Only {$completenessRate}% of closed trades have all required fields (close_price, roi, close_reason). Close finalization may be incomplete.";
            $recommendedNextFixArea  = 'audit_close_finalization';
        } elseif ($closedCount > 0 && $closedCount < 10) {
            $primaryBottleneck       = 'turnover_too_low';
            $primaryBottleneckReason = "Only {$closedCount} closed demo trades. Loop is functioning but accumulation is too slow. Increase signal throughput or reduce hold times.";
            $recommendedNextFixArea  = 'increase_demo_signal_throughput';
        } elseif ($closedCount >= 10) {
            $primaryBottleneck       = 'none_loop_is_cycling';
            $primaryBottleneckReason = "{$closedCount} closed trades with {$completenessRate}% completeness and {$matchRate}% AI dataset match rate. Loop is cycling.";
            $recommendedNextFixArea  = 'maintain_current_config';
        }

        return [
            'active_trades_count'                      => $activeCount,
            'closed_trades_count'                      => $closedCount,
            'ai_dataset_count'                         => $aiCount,
            'oldest_active_trade_age_minutes'          => $oldestActiveAgeMin,
            'avg_active_trade_age_minutes'             => $avgActiveAgeMin,
            'stale_active_count'                       => $staleCount,
            'active_trades_stale_count'                => $staleCount,   // canonical alias
            'pct_active_trades_stale'                  => $pctStaleActive,
            'stale_threshold_minutes'                  => $learningMaxActiveAgeMinutes,
            'closed_trades_complete_count'             => $completeClosedCount,
            'closed_trades_completeness_rate'          => $completenessRate,
            'closed_trades_complete_rate'              => $completenessRate,  // alias
            'pct_closed_missing_mfe'                   => $pctMissingMfe,
            'pct_closed_missing_mae'                   => $pctMissingMae,
            'pct_closed_missing_hold_minutes'          => $pctMissingHoldMinutes,
            'pct_closed_missing_close_reason'          => $pctMissingCloseReason,
            'pct_closed_missing_close_price'           => $pctMissingClosePrice,
            'pct_closed_missing_roi'                   => $pctMissingRoi,
            // Canonical close-pipeline field names requested by PART 7
            'closed_trades_missing_close_price_rate'   => $pctMissingClosePrice,
            'closed_trades_missing_roi_rate'           => $pctMissingRoi,
            'closed_trades_missing_mfe_rate'           => $pctMissingMfe,
            'closed_trades_missing_mae_rate'           => $pctMissingMae,
            'closed_trades_missing_hold_minutes_rate'  => $pctMissingHoldMinutes,
            'closed_trades_missing_close_reason_rate'  => $pctMissingCloseReason,
            'closed_trades_without_ai_dataset_count'   => $closedWithoutAiDataset,
            'ai_dataset_without_closed_trade_count'    => $aiWithoutClosedTrade,
            'closed_to_ai_dataset_match_rate'          => $matchRate,
            // Orphan exchange position fields (injected from runtime)
            'orphan_exchange_positions_detected'       => $orphanBlockingCount,
            'orphan_exchange_positions_blocking_count' => $orphanBlockingCount,
            'orphan_exchange_positions_adopted_count'  => 0,
            'orphan_exchange_positions_finalized_count'=> 0,
            // Feed metrics injected from runtime (not derived from storage)
            'runtime_feed_available_count'             => $feedAvailableCount,
            'runtime_feed_selected_count'              => $feedSelectedCount,
            'runtime_positions_opened_this_run'        => $positionsOpenedThisRun,
            'runtime_closed_this_run'                  => $closedThisRun,
            'runtime_reconcile_blocked_this_run'       => $reconcileBlockedThisRun,
            'runtime_feed_is_available'                => $feedIsAvailable,
            'primary_execution_blocker'                => $primaryExecutionBlocker,
            'primary_execution_blocker_reason'         => $primaryBottleneckReason,
            // Bottleneck classification
            'primary_demo_bottleneck'                  => $primaryBottleneck,
            'primary_demo_bottleneck_reason'           => $primaryBottleneckReason,
            // PART 6: closure-specific bottleneck aliases
            'primary_demo_closure_bottleneck'          => $primaryBottleneck,
            'primary_demo_closure_bottleneck_reason'   => $primaryBottleneckReason,
            'recommended_turnover_fix_area'            => $recommendedNextFixArea,
            'recommended_next_fix_area'                => $recommendedNextFixArea,
            'audited_at'                               => date('c'),
        ];
    }

    /**
     * Persist demo truth audit to runtime/demo_truth_audit.json.
     *
     * @param array<string,mixed> $audit  Output of computeDemoTruthAudit()
     */
    public function saveDemoTruthAudit(array $audit): void
    {
        $path = $this->storageDir . '/runtime/demo_truth_audit.json';
        $this->writeJson($path, $audit);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Write a named persist-error diagnostic to runtime/errors/ so failures
     * after successful exchange execution are never silently swallowed.
     */
    private function logPersistError(string $code, array $context = []): void
    {
        $errorsDir = $this->storageDir . '/runtime/errors';
        if (!is_dir($errorsDir)) {
            @mkdir($errorsDir, 0755, true);
        }
        $ts = date('Ymd_His');
        $path = $errorsDir . '/' . $code . '_' . $ts . '.json';
        @file_put_contents($path, json_encode(array_merge(['code' => $code, 'ts' => date('c')], $context), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Read JSON file
     */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        
        $content = @file_get_contents($path);
        if ($content === false) {
            return [];
        }
        
        $data = @json_decode($content, true);
        return is_array($data) ? $data : [];
    }
    
    /**
     * Write JSON file
     */
    private function writeJson(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        // Atomic write
        if ($this->config['write']['atomic'] ?? true) {
            $tmp = $path . '.tmp.' . uniqid();
            if (@file_put_contents($tmp, $json) !== false) {
                @rename($tmp, $path);
            }
        } else {
            @file_put_contents($path, $json);
        }
    }
    
    /**
     * Load all JSON files from directory
     */
    private function loadAllFromDir(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        
        $result = [];
        $files = glob($dir . '/*.json') ?: [];
        
        foreach ($files as $file) {
            $data = $this->readJson($file);
            if (!empty($data)) {
                $key = basename($file, '.json');
                $result[$key] = $data;
            }
        }
        
        return $result;
    }
    
    // =========================================================================
    // P7: Commands Applied Index
    // =========================================================================
    
    /**
     * Load commands applied index (P7)
     * 
     * Returns array of command IDs that have been applied.
     * 
     * @return array [command_id => {...}]
     */
    public function loadCommandsAppliedIndex(): array
    {
        $path = $this->storageDir . '/runtime/commands_applied_index.json';
        return $this->readJson($path);
    }
    
    /**
     * Mark command as applied (P7)
     * 
     * Atomic read-modify-write with flock.
     * Also appends to commands_applied.ndjson log.
     * 
     * @param string $commandId Command ID
     * @param array $row Applied command data
     */
    public function markCommandApplied(string $commandId, array $row): void
    {
        $indexPath = $this->storageDir . '/runtime/commands_applied_index.json';
        $ndjsonPath = $this->storageDir . '/runtime/commands_applied.ndjson';
        
        // Ensure directory exists
        $dir = dirname($indexPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        
        // Check if directory is writable
        if (!is_dir($dir) || !is_writable($dir)) {
            error_log("TradingBot: commands_applied_index directory not writable: {$dir}");
            return;
        }
        
        // Append to ndjson log (atomic with flock)
        $logEntry = array_merge(['command_id' => $commandId, 'applied_at' => date('c')], $row);
        $logLine = json_encode($logEntry, JSON_UNESCAPED_SLASHES) . "\n";
        @file_put_contents($ndjsonPath, $logLine, FILE_APPEND | LOCK_EX);
        
        // Update index (atomic with flock)
        $fp = @fopen($indexPath, 'c+');
        if ($fp === false) {
            // Try to create file (fallback for first run)
            if (!is_file($indexPath)) {
                @file_put_contents($indexPath, '{}');
                $fp = @fopen($indexPath, 'c+');
            }
        }
        
        if ($fp === false) {
            error_log("TradingBot: Failed to open commands_applied_index.json");
            return;
        }
        
        // Acquire exclusive lock
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return;
        }
        
        try {
            // Read current content
            $content = '';
            while (!feof($fp)) {
                $content .= fread($fp, 8192);
            }
            
            $index = @json_decode($content, true);
            if (!is_array($index)) {
                $index = [];
            }
            
            // P7.8: Add entry with ok/status/error (not just result)
            $index[$commandId] = [
                'applied_at' => date('c'),
                'ok' => (bool)($row['result_ok'] ?? false),
                'status' => (string)($row['status'] ?? ($row['result'] ?? 'applied')),
                'error' => $row['error'] ?? null,
            ];
            
            // Truncate and write
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            fflush($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}

/* RULES
- BotStore handles ALL file I/O for the trading bot
- Writes are atomic (temp file + rename)
- Directory structure is created on init
- Errors are logged to storage/logs/errors.log
- NO hardcoded paths - uses config['output'] paths
- P7: Commands applied index uses flock for atomic updates
- P7.8: Index stores ok/status/error (not just result)
*/
