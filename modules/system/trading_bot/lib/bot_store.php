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
