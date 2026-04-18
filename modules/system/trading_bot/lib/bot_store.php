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

    /**
     * Optional callback invoked after every trade is moved to the closed directory.
     * Signature: function(string $tradeId, array $closedTrade): void
     * Used by the verdict engine to generate and persist verdicts without coupling
     * the close paths in bot_executor_trait to a specific verdict implementation.
     */
    private $onTradeClosedHook = null;
    
    public function __construct(string $storageDir, array $config)
    {
        $this->storageDir = $storageDir;
        $this->config = $config;
        $this->ensureDirectories();
    }

    /**
     * Register a callback that fires after moveTradeToClosedDir persists the record.
     * Non-fatal: exceptions inside the hook are silently swallowed so close operations
     * are never blocked by verdict-write failures.
     */
    public function setOnTradeClosedHook(callable $hook): void
    {
        $this->onTradeClosedHook = $hook;
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

        // Fire post-close hook (verdict engine registration point)
        if ($this->onTradeClosedHook !== null) {
            try {
                ($this->onTradeClosedHook)($tradeId, $trade);
            } catch (\Throwable $e) {
                // Non-fatal: verdict write errors must never block a close operation
            }
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
    
    // =========================================================================
    // Run Journal (append-only NDJSON per storage namespace)
    // =========================================================================

    /**
     * Return the path to the append-only run journal for this storage namespace.
     */
    public function getRunJournalPath(): string
    {
        return $this->storageDir . '/runtime/run_journal.ndjson';
    }

    /**
     * Return basic info about the journal file (path, size, line count estimate).
     *
     * @return array{path:string,exists:bool,size_bytes:int,approx_events:int}
     */
    public function getRunJournalInfo(): array
    {
        $path = $this->getRunJournalPath();
        $exists = is_file($path);
        $sizeBytes = $exists ? (int)filesize($path) : 0;
        // Approximate event count: divide total bytes by an estimated line length (≈200 bytes each)
        $approxEvents = $sizeBytes > 0 ? max(1, (int)round($sizeBytes / 200)) : 0;
        return [
            'path'          => $path,
            'exists'        => $exists,
            'size_bytes'    => $sizeBytes,
            'approx_events' => $approxEvents,
        ];
    }

    /**
     * Read the last N lines from the run journal without loading the whole file.
     *
     * @param int $n Maximum lines to return (most recent first)
     * @return array<int,array<string,mixed>>
     */
    public function readRunJournalTail(int $n = 10): array
    {
        $path = $this->getRunJournalPath();
        if (!is_file($path) || filesize($path) === 0) {
            return [];
        }

        // Read last chunk — journals can be large; read only the tail
        $chunkSize = max(8192, $n * 400);
        $fp = @fopen($path, 'r');
        if ($fp === false) {
            return [];
        }

        $fileSize = filesize($path);
        $offset = max(0, $fileSize - $chunkSize);
        fseek($fp, $offset);
        $chunk = fread($fp, $chunkSize);
        fclose($fp);

        if ($chunk === false || $chunk === '') {
            return [];
        }

        $lines = explode("\n", trim($chunk));
        // If we seeked past the start, the first line may be partial — drop it
        if ($offset > 0 && count($lines) > 1) {
            array_shift($lines);
        }

        $lines = array_reverse($lines);
        $events = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = @json_decode($line, true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
            if (count($events) >= $n) {
                break;
            }
        }
        return $events;
    }

    /**
     * Append one structured event to the append-only run journal.
     *
     * The journal file grows monotonically. It is NEVER truncated during normal execution.
     * Each event is one JSON line (NDJSON format).
     *
     * Required keys in $event:
     *   - ts               (ISO 8601 timestamp)
     *   - run_id           (shared across all events in one tick)
     *   - mode             (live/demo/paper/dry)
     *   - storage_namespace (e.g. storage_demo)
     *   - event_type       (run_start, config_snapshot, reconcile_end, …)
     *   - step             (human label)
     *   - ok               (bool)
     *   - message          (string)
     *   - data             (array)
     */
    public function appendRunJournalEvent(array $event): void
    {
        $runtimeDir = $this->storageDir . '/runtime';
        if (!is_dir($runtimeDir)) {
            @mkdir($runtimeDir, 0755, true);
        }

        $path = $this->getRunJournalPath();

        // Ensure mandatory fields have defaults so the line is always valid JSON
        $event['ts']                ??= date('c');
        $event['run_id']            ??= 'unknown';
        $event['mode']              ??= 'unknown';
        $event['storage_namespace'] ??= basename($this->storageDir);
        $event['event_type']        ??= 'unknown';
        $event['step']              ??= '';
        $event['ok']                = (bool)($event['ok'] ?? true);
        $event['message']           ??= '';
        if (!array_key_exists('data', $event) || !is_array($event['data'])) {
            $event['data'] = [];
        }

        $line = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
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

        $isAdoptedOrphan = !empty($trade['is_orphan_adopted']) || !empty($trade['adopted_from_exchange_orphan']);

        // Prefer closed-trade mfe/mae fields; fall back to runtime
        $mfe = isset($trade['mfe']) && $trade['mfe'] !== null
            ? (float)$trade['mfe']
            : (isset($rt['best_roi_seen']) ? (float)$rt['best_roi_seen'] : null);
        $mae = isset($trade['mae']) && $trade['mae'] !== null
            ? (float)$trade['mae']
            : (isset($rt['worst_roi_seen']) ? (float)$rt['worst_roi_seen'] : null);

        $record = [
            'ai_dataset_record_version' => 'v1',
            'created_at'                => date('c'),
            'trade_id'                  => $tradeId,
            // Signal lineage — absent for adopted orphans; tolerated
            'signal_id'                 => (string)($trade['signal_id'] ?? ''),
            'symbol'                    => (string)($trade['symbol'] ?? ''),
            'side'                      => (string)($trade['side'] ?? ''),
            'pattern_algorithm'         => (string)($trade['pattern_algorithm'] ?? $trade['algo'] ?? ''),
            'scenario_id'               => $trade['scenario_id'] ?? null,
            'signal_strength'           => isset($trade['signal_strength']) ? (float)$trade['signal_strength'] : null,
            'quality_score'             => isset($trade['quality_score'])   ? (float)$trade['quality_score']   : null,
            // Core trade fields
            'entry_ts'                  => $trade['open_ts'] ?? (isset($trade['opened_at']) ? strtotime($trade['opened_at']) : null),
            'entry_price'               => isset($trade['entry_price']) ? (float)$trade['entry_price'] : null,
            'close_ts'                  => $trade['close_ts'] ?? $trade['closed_ts'] ?? null,
            'close_price'               => isset($trade['close_price']) ? (float)$trade['close_price'] : null,
            'roi'                       => isset($trade['roi'])  ? (float)$trade['roi']  : null,
            'pnl'                       => isset($trade['pnl'])  ? (float)$trade['pnl']  : null,
            'mfe'                       => $mfe,
            'mae'                       => $mae,
            'hold_minutes'              => isset($trade['hold_minutes']) ? (int)$trade['hold_minutes'] : null,
            'leverage'                  => isset($risk['leverage']) ? (int)$risk['leverage'] : null,
            'stop_loss_price'           => isset($prot['stop_loss_price']) ? (float)$prot['stop_loss_price'] : null,
            'trailing_applied'          => (bool)($rt['dumb_trailing_applied']  ?? false),
            'break_even_applied'        => (bool)($rt['break_even_applied']     ?? false),
            'close_reason_normalized'   => (string)($trade['close_reason_normalized'] ?? $trade['close_reason'] ?? ''),
            'close_result_source'       => (string)($trade['close_result_source'] ?? ''),
            'close_detection_result'    => (string)($trade['close_detection_result'] ?? ''),
            'passport_confidence'       => (string)($pp['data_confidence'] ?? ''),
            'passport_corridor_p75_roi' => isset($pp['corridor_p75_roi']) ? (float)$pp['corridor_p75_roi'] : null,
            'source'                    => $isAdoptedOrphan ? 'orphan_exchange_recovery' : 'demo',
            // Adopted-orphan lineage (null-safe — normal trades just get false/null)
            'adopted_from_exchange_orphan'    => $isAdoptedOrphan,
            'orphan_resolved_local_ownership' => (bool)($trade['orphan_resolved_local_ownership'] ?? false),
            'orphan_resolution_ts'            => $isAdoptedOrphan ? ($trade['orphan_resolution_ts'] ?? null) : null,
            'orphan_resolution_reason'        => $isAdoptedOrphan ? ($trade['orphan_resolution_reason'] ?? null) : null,
            // Missing-field reasons (populated by applyLocalCloseFinalize)
            'entry_price_missing_reason'  => $trade['entry_price_missing_reason']  ?? null,
            'close_price_missing_reason'  => $trade['close_price_missing_reason']  ?? null,
            'roi_missing_reason'          => $trade['roi_missing_reason']           ?? null,
            'pnl_missing_reason'          => $trade['pnl_missing_reason']           ?? null,
            'mfe_missing_reason'          => $trade['mfe_missing_reason']           ?? null,
            'mae_missing_reason'          => $trade['mae_missing_reason']           ?? null,
            'hold_minutes_missing_reason' => $trade['hold_minutes_missing_reason']  ?? null,
        ];

        $path = $this->storageDir . '/ai_dataset/' . $tradeId . '.json';
        $this->writeJson($path, $record);
        return is_file($path);
    }

    /**
     * Scan recently-closed adopted orphan trades and repair incomplete records.
     *
     * A closed adopted orphan record is considered incomplete if:
     *   - close_price is missing / zero
     *   - roi is null
     *   - AI dataset file does not exist
     *
     * For each incomplete record the method:
     *   1. Tags it with adopted_orphan_close_repair_attempted = true
     *   2. Attempts to re-write missing fields from what is now available
     *   3. Retries AI dataset write if missing
     *   4. Saves the patched closed file back in place
     *
     * Returns an array of counters suitable for emission into last_run.json.
     *
     * @return array<string, int|array>
     */
    public function repairIncompleteAdoptedOrphanClosedRecords(): array
    {
        $closedDir    = $this->storageDir . '/trades/closed';
        $aiDatasetDir = $this->storageDir . '/ai_dataset';

        $stats = [
            'adopted_orphans_close_repair_attempted_this_run' => 0,
            'adopted_orphans_close_repair_succeeded_this_run' => 0,
            'adopted_orphans_close_repair_failed_this_run'    => 0,
        ];

        if (!is_dir($closedDir)) {
            return $stats;
        }

        $files = glob($closedDir . '/*.json') ?: [];
        foreach ($files as $file) {
            $data = @json_decode((string)@file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }
            $isAdoptedOrphan = !empty($data['is_orphan_adopted']) || !empty($data['adopted_from_exchange_orphan']);
            if (!$isAdoptedOrphan) {
                continue;
            }

            $tradeId = (string)($data['trade_id'] ?? $data['id'] ?? basename($file, '.json'));
            $closePrice  = (float)($data['close_price'] ?? 0);
            $roi         = $data['roi'] ?? null;
            $aiPath      = $aiDatasetDir . '/' . $tradeId . '.json';
            $hasAiRecord = is_file($aiPath);

            $needsRepair = $closePrice <= 0 || $roi === null || !$hasAiRecord;
            if (!$needsRepair) {
                continue;
            }

            $stats['adopted_orphans_close_repair_attempted_this_run']++;
            $data['adopted_orphan_close_repair_attempted'] = true;

            $repaired = false;

            // Re-apply close finalization heuristics if close_price or roi is missing
            if ($closePrice <= 0 || $roi === null) {
                $closedAtTs = (int)($data['close_ts'] ?? $data['closed_ts'] ?? time());
                $entryPrice = (float)($data['entry_price'] ?? 0);
                $side       = strtolower((string)($data['side'] ?? 'long'));
                $qty        = (float)($data['position_size'] ?? $data['qty'] ?? 0);

                if ($closePrice <= 0 && $entryPrice > 0) {
                    // No close_price available — flag it but do not fabricate
                    $data['close_price_missing_reason'] = $data['close_price_missing_reason']
                        ?? 'adopted_orphan_repair_no_price_available';
                }

                if ($roi === null && $entryPrice > 0 && $closePrice > 0 && $qty > 0) {
                    $priceDiff = ($side === 'long') ? ($closePrice - $entryPrice) : ($entryPrice - $closePrice);
                    $data['pnl'] = round($priceDiff * $qty, 8);
                    $data['roi'] = round(($priceDiff / $entryPrice) * 100, 4);
                    unset($data['roi_missing_reason'], $data['pnl_missing_reason']);
                    $repaired = true;
                }
            }

            // Ensure orphan lineage fields are present
            if (!isset($data['orphan_resolution_ts'])) {
                $adoptedAt = $data['orphan_adopted_at'] ?? null;
                $data['orphan_resolution_ts'] = $adoptedAt
                    ? (int)strtotime((string)$adoptedAt)
                    : (int)($data['close_ts'] ?? time());
                $repaired = true;
            }
            if (!isset($data['orphan_resolution_reason']) || $data['orphan_resolution_reason'] === '') {
                $data['orphan_resolution_reason'] = 'orphan_adopted_as_local_demo_trade';
                $repaired = true;
            }
            $data['adopted_from_exchange_orphan']    = true;
            $data['orphan_resolved_local_ownership'] = (bool)($data['orphan_resolved_local_ownership'] ?? false);

            // Retry AI dataset write if missing
            if (!$hasAiRecord) {
                $aiWritten = $this->appendAiDatasetRecord($tradeId, $data);
                $data['ai_dataset_record_written']   = $aiWritten;
                if ($aiWritten) {
                    unset($data['ai_dataset_write_fail_reason']);
                    $repaired = true;
                } else {
                    $data['ai_dataset_write_fail_reason'] = 'repair_write_failed';
                }
            }

            $data['adopted_orphan_close_repair_ts'] = time();
            if ($repaired) {
                $data['adopted_orphan_close_repair_succeeded'] = true;
                $data['adopted_orphan_close_repair_failed']    = false;
                $data['adopted_orphan_close_repair_reason']    = 'repair_pass_applied';
                $stats['adopted_orphans_close_repair_succeeded_this_run']++;
            } else {
                $data['adopted_orphan_close_repair_succeeded'] = false;
                $data['adopted_orphan_close_repair_failed']    = true;
                $data['adopted_orphan_close_repair_reason']    = 'repair_pass_no_new_data_available';
                $stats['adopted_orphans_close_repair_failed_this_run']++;
            }

            // Write patched record back in place
            $this->writeJson($file, $data);
        }

        return $stats;
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
    public function computeDemoTruthAudit(int $learningMaxActiveAgeMinutes = 240, int $orphanBlockingCount = 0, int $feedAvailableCount = 0, int $feedSelectedCount = 0, int $positionsOpenedThisRun = 0, int $closedThisRun = 0, int $reconcileBlockedThisRun = 0, bool $capacityFull = false, int $capacitySlotsTotal = 0, int $capacitySlotsUsed = 0, int $capacitySlotsFreed = 0): array
    {
        $closedDir    = $this->storageDir . '/trades/closed';
        $activeDir    = $this->storageDir . '/trades/active';
        $aiDatasetDir = $this->storageDir . '/ai_dataset';
        $now          = time();

        // ── Active trades ────────────────────────────────────────────────────
        $activeFiles          = glob($activeDir . '/*.json') ?: [];
        $activeCount          = count($activeFiles);
        $activeAges           = [];
        $staleCount           = 0;
        $orphanAdoptedCount   = 0;  // adopted orphan trades (reusable)
        $orphanDeadShellCount = 0;  // adopted orphan trades missing critical fields (dead shells)
        $healthyActiveCount   = 0;  // normal active trades (not orphan-adopted)
        $orphanResolvedCount  = 0;  // adopted orphan trades with orphan_resolved_local_ownership=true
        $adoptedOrphanStaleCount = 0;  // adopted orphans that are stale (age >= threshold)
        // Timing health counters for adopted orphans
        $orphanAdoptedValidTimingCount   = 0;
        $orphanAdoptedMissingTimingCount = 0;
        $orphanAdoptedStaleEligibleCount = 0;
        $orphanAdoptedTimeoutEligibleCount = 0;
        $orphanAdoptedAgeAccum = 0;
        $orphanAdoptedAgeCount = 0;
        $orphanAdoptedMaxAge   = 0;
        $healthyActiveStaleCount          = 0;
        $healthyActiveTimeoutEligibleCount= 0;

        // Read close-timeout from store-level config if available
        $storeDlm      = is_array($this->config['demo_learning_mode'] ?? null) ? $this->config['demo_learning_mode'] : [];
        $storeTimeout  = ($storeDlm['enabled'] ?? false) && ($storeDlm['learning_close_timeout_minutes'] ?? 0) > 0
            ? (int)$storeDlm['learning_close_timeout_minutes'] : 0;

        foreach ($activeFiles as $af) {
            $d = @json_decode((string)@file_get_contents($af), true);
            if (!is_array($d)) {
                continue;
            }
            // Determine opened_at timestamp — for adopted orphans also try opened_ts / adoption_ts fallbacks
            $openTs = null;
            if (!empty($d['opened_at'])) {
                $openTs = strtotime($d['opened_at']) ?: null;
            }
            if (($openTs === null || $openTs <= 0) && !empty($d['opened_ts'])) {
                $openTs = (int)$d['opened_ts'];
            }
            if (($openTs === null || $openTs <= 0) && !empty($d['open_ts'])) {
                $openTs = (int)$d['open_ts'];
            }
            if ($openTs !== null && $openTs > 0) {
                $ageMin = (int)round(($now - $openTs) / 60);
                $activeAges[] = $ageMin;
                if ($ageMin >= $learningMaxActiveAgeMinutes) {
                    $staleCount++;
                }
            }

            // Classify trade as healthy, orphan-adopted-reusable, orphan dead shell,
            // or orphan-resolved (adopted with explicit local-ownership flag).
            $isOrphanAdopted = !empty($d['is_orphan_adopted']) || !empty($d['adopted_from_exchange_orphan']);
            if ($isOrphanAdopted) {
                $hasEntryPrice = (float)($d['entry_price'] ?? 0) > 0;
                $hasQty        = (float)($d['position_size'] ?? $d['qty'] ?? 0) > 0;
                $hasSide       = in_array($d['side'] ?? '', ['long', 'short'], true);
                if ($hasEntryPrice && $hasQty && $hasSide) {
                    $orphanAdoptedCount++;
                    // Count as resolved if it carries the local-ownership marker.
                    if (!empty($d['orphan_resolved_local_ownership'])) {
                        $orphanResolvedCount++;
                    }
                    // Timing health classification
                    // Resolve best timing baseline: opened_ts → adoption_ts → orphan_resolution_ts
                    $aoOpenTs = $openTs;
                    if (($aoOpenTs === null || $aoOpenTs <= 0) && !empty($d['adoption_ts'])) {
                        $aoOpenTs = (int)$d['adoption_ts'];
                    }
                    if (($aoOpenTs === null || $aoOpenTs <= 0) && !empty($d['orphan_resolution_ts'])) {
                        $aoOpenTs = (int)$d['orphan_resolution_ts'];
                    }
                    $hasMissingTimingReason = !empty($d['timing_missing_reason']);
                    if ($aoOpenTs !== null && $aoOpenTs > 0 && !$hasMissingTimingReason) {
                        $orphanAdoptedValidTimingCount++;
                        $aoAgeMin = (int)round(($now - $aoOpenTs) / 60);
                        $orphanAdoptedAgeAccum += $aoAgeMin;
                        $orphanAdoptedAgeCount++;
                        if ($aoAgeMin > $orphanAdoptedMaxAge) {
                            $orphanAdoptedMaxAge = $aoAgeMin;
                        }
                        if ($learningMaxActiveAgeMinutes > 0 && $aoAgeMin >= $learningMaxActiveAgeMinutes) {
                            $adoptedOrphanStaleCount++;
                            $orphanAdoptedStaleEligibleCount++;
                        }
                        if ($storeTimeout > 0 && $aoAgeMin >= $storeTimeout) {
                            $orphanAdoptedTimeoutEligibleCount++;
                        }
                    } else {
                        $orphanAdoptedMissingTimingCount++;
                        // Still count stale if any timing baseline found (even fallback)
                        if ($aoOpenTs !== null && $aoOpenTs > 0) {
                            $aoAgeMin = (int)round(($now - $aoOpenTs) / 60);
                            if ($learningMaxActiveAgeMinutes > 0 && $aoAgeMin >= $learningMaxActiveAgeMinutes) {
                                $adoptedOrphanStaleCount++;
                            }
                        }
                    }
                } else {
                    $orphanDeadShellCount++;
                }
            } else {
                $healthyActiveCount++;
                // Track stale and timeout eligibility for healthy trades
                if ($openTs !== null && $openTs > 0) {
                    $healthyAgeMin = (int)round(($now - $openTs) / 60);
                    if ($learningMaxActiveAgeMinutes > 0 && $healthyAgeMin >= $learningMaxActiveAgeMinutes) {
                        $healthyActiveStaleCount++;
                    }
                    if ($storeTimeout > 0 && $healthyAgeMin >= $storeTimeout) {
                        $healthyActiveTimeoutEligibleCount++;
                    }
                }
            }
        }

        $orphanAdoptedAvgAge    = $orphanAdoptedAgeCount > 0
            ? (int)round($orphanAdoptedAgeAccum / $orphanAdoptedAgeCount) : null;
        $orphanAdoptedOldestAge = $orphanAdoptedAgeCount > 0 ? $orphanAdoptedMaxAge : null;

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
        $completeClosedCount     = 0;
        $fullCompleteClosedCount = 0;
        $adoptedOrphanClosedCount        = 0;
        $adoptedOrphanClosedComplete     = 0;
        $adoptedOrphanClosedFullComplete = 0;
        // Adopted orphan missing-field detail counts
        $adoptedOrphanMissingClosePrice  = 0;
        $adoptedOrphanMissingRoi         = 0;
        $adoptedOrphanMissingMfe         = 0;
        $adoptedOrphanMissingMae         = 0;
        $adoptedOrphanMissingHoldMinutes = 0;
        $healthyClosedCount = 0;
        // Healthy-specific quality counters
        $healthyClosedCompleteCount     = 0;
        $healthyClosedFullCompleteCount = 0;
        $healthyClosedMissingMfe        = 0;
        $healthyClosedMissingMae        = 0;
        $healthyClosedMissingClosePrice = 0;
        $healthyClosedMissingHoldMinutes= 0;

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
            // A record is missing MFE if mfe is null OR an explicit mfe_missing_reason is set.
            // This mirrors the $mfeMissing/$maeMissing contract used for full-completeness below.
            if ($mfe === null || !empty($d['mfe_missing_reason'])) {
                $missingMfe++;
            }
            if ($mae === null || !empty($d['mae_missing_reason'])) {
                $missingMae++;
            }
            if ($holdMin === null || (int)$holdMin < 0) {
                $missingHoldMinutes++;
            }

            $isComplete = $closePrice > 0 && $roi !== null && $closeReason !== '';
            // mfe_missing_reason / mae_missing_reason are explicit signals that mfe/mae are absent
            // (Part 3 contract). Check both the null value AND the missing-reason flag so records
            // carrying an explicit missing-reason flag are never silently counted as fully complete.
            $mfeMissing = ($mfe === null) || !empty($d['mfe_missing_reason']);
            $maeMissing = ($mae === null) || !empty($d['mae_missing_reason']);
            if ($isComplete) {
                $completeClosedCount++;
            }
            // Full completeness: basic fields + mfe + mae both present and valid
            $isFullComplete = $isComplete && !$mfeMissing && !$maeMissing;
            if ($isFullComplete) {
                $fullCompleteClosedCount++;
            }

            // Count adopted orphan closed trades separately
            if (!empty($d['adopted_from_exchange_orphan']) || !empty($d['is_orphan_adopted'])) {
                $adoptedOrphanClosedCount++;
                // Adopted-orphan operational completeness: requires the core close fields
                // plus entry_price, hold_minutes, and ai_dataset_record_written (Part 1 contract).
                $aoEntryPrice  = (float)($d['entry_price'] ?? 0);
                $aoAiWritten   = !empty($d['ai_dataset_record_written']);
                $isAdoptedOrphanComplete = $closePrice > 0
                    && $roi !== null
                    && $closeReason !== ''
                    && $aoEntryPrice > 0
                    && $holdMin !== null && (int)$holdMin >= 0
                    && $aoAiWritten;

                // Adopted-orphan full completeness: additionally requires mfe+mae with no
                // missing-reason flags (Part 3 contract).
                $isAdoptedOrphanFullComplete = $isAdoptedOrphanComplete && !$mfeMissing && !$maeMissing;

                if ($isAdoptedOrphanComplete) {
                    $adoptedOrphanClosedComplete++;
                }
                if ($isAdoptedOrphanFullComplete) {
                    $adoptedOrphanClosedFullComplete++;
                }
                // Track per-field incompleteness for adopted orphans.
                // Use mfe_missing_reason / mae_missing_reason as an explicit signal (Part 3).
                if ($closePrice <= 0) {
                    $adoptedOrphanMissingClosePrice++;
                }
                if ($roi === null) {
                    $adoptedOrphanMissingRoi++;
                }
                if ($mfeMissing) {
                    $adoptedOrphanMissingMfe++;
                }
                if ($maeMissing) {
                    $adoptedOrphanMissingMae++;
                }
                if ($holdMin === null || (int)$holdMin < 0) {
                    $adoptedOrphanMissingHoldMinutes++;
                }
            } else {
                $healthyClosedCount++;
                // Healthy-specific quality tracking
                $healthyIsComplete = $closePrice > 0 && $roi !== null && $closeReason !== '';
                if ($healthyIsComplete) { $healthyClosedCompleteCount++; }
                if ($healthyIsComplete && !$mfeMissing && !$maeMissing) { $healthyClosedFullCompleteCount++; }
                if ($mfeMissing)         { $healthyClosedMissingMfe++; }
                if ($maeMissing)         { $healthyClosedMissingMae++; }
                if ($closePrice <= 0)    { $healthyClosedMissingClosePrice++; }
                if ($holdMin === null || (int)$holdMin < 0) { $healthyClosedMissingHoldMinutes++; }
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
        $adoptedOrphanWithoutAi = 0;
        foreach (array_keys($closedIds) as $cid) {
            if (!isset($aiIds[$cid])) {
                $closedWithoutAiDataset++;
            }
        }
        // For adopted orphan AI cross-check we need to match closed adopted orphan IDs to AI IDs.
        // Re-scan closed dir for adopted orphan tradeIds.
        $adoptedOrphanClosedIds = [];
        foreach ($closedFiles as $cf) {
            $d = @json_decode((string)@file_get_contents($cf), true);
            if (!is_array($d)) {
                continue;
            }
            if (!empty($d['adopted_from_exchange_orphan']) || !empty($d['is_orphan_adopted'])) {
                $tid = (string)($d['trade_id'] ?? $d['id'] ?? basename($cf, '.json'));
                if ($tid !== '') {
                    $adoptedOrphanClosedIds[$tid] = true;
                    if (!isset($aiIds[$tid])) {
                        $adoptedOrphanWithoutAi++;
                    }
                }
            }
        }
        $aiWithoutClosedTrade = 0;
        foreach (array_keys($aiIds) as $aid) {
            if (!isset($closedIds[$aid])) {
                $aiWithoutClosedTrade++;
            }
        }
        $matchRate = $closedCount > 0 ? round(($closedCount - $closedWithoutAiDataset) / $closedCount * 100, 1) : 0.0;
        $adoptedOrphanClosedCompleteRate = $adoptedOrphanClosedCount > 0
            ? round($adoptedOrphanClosedComplete / $adoptedOrphanClosedCount * 100, 1)
            : 0.0;
        $adoptedOrphanClosedFullCompleteRate = $adoptedOrphanClosedCount > 0
            ? round($adoptedOrphanClosedFullComplete / $adoptedOrphanClosedCount * 100, 1)
            : 0.0;

        // ── Field completeness rates ─────────────────────────────────────────
        $pctMissingMfe         = $closedCount > 0 ? round($missingMfe / $closedCount * 100, 1) : 0.0;
        $pctMissingMae         = $closedCount > 0 ? round($missingMae / $closedCount * 100, 1) : 0.0;
        $pctMissingHoldMinutes = $closedCount > 0 ? round($missingHoldMinutes / $closedCount * 100, 1) : 0.0;
        $pctMissingCloseReason = $closedCount > 0 ? round($missingCloseReason / $closedCount * 100, 1) : 0.0;
        $pctMissingClosePrice  = $closedCount > 0 ? round($missingClosePrice / $closedCount * 100, 1) : 0.0;
        $pctMissingRoi         = $closedCount > 0 ? round($missingRoi / $closedCount * 100, 1) : 0.0;
        $completenessRate      = $closedCount > 0 ? round($completeClosedCount / $closedCount * 100, 1) : 0.0;
        $fullCompletenessRate  = $closedCount > 0 ? round($fullCompleteClosedCount / $closedCount * 100, 1) : 0.0;

        // Resolve configured max capacity
        $storeMaxCap = ($storeDlm['enabled'] ?? false) ? (int)($storeDlm['max_concurrent_demo_positions'] ?? 0) : 0;

        // ── Composition config (PART 6) ──────────────────────────────────────
        $compOrphanMaxSlots  = ($storeDlm['enabled'] ?? false) ? (int)($storeDlm['orphan_max_active_slots'] ?? 0) : 0;
        $compHealthyMinSlots = ($storeDlm['enabled'] ?? false) ? (int)($storeDlm['healthy_min_active_slots'] ?? 0) : 0;
        $compShareTargetPct  = ($storeDlm['enabled'] ?? false) ? (float)($storeDlm['healthy_share_target_pct'] ?? 0) : 0.0;

        // ── Bootstrap config (healthy-close accelerator) ─────────────────────
        $bootstrapEnabled      = ($storeDlm['enabled'] ?? false) && !empty($storeDlm['healthy_close_bootstrap_enabled']);
        $bootstrapShareTarget  = $bootstrapEnabled ? (float)($storeDlm['healthy_closed_share_target_pct'] ?? 0) : 0.0;
        $bootstrapTimeoutMin   = $bootstrapEnabled ? (int)($storeDlm['healthy_close_timeout_minutes_bootstrap'] ?? 0) : 0;
        $bootstrapStaleMin     = $bootstrapEnabled ? (int)($storeDlm['healthy_stale_age_minutes_bootstrap']   ?? 0) : 0;

        // ── Composition metrics (PART 4) ─────────────────────────────────────
        $healthyShareActivePct = $activeCount > 0
            ? round($healthyActiveCount / $activeCount * 100, 1) : 0.0;
        $healthyShareClosedPct = $closedCount > 0
            ? round($healthyClosedCount / $closedCount * 100, 1) : 0.0;
        $orphanSlotCapReached  = $compOrphanMaxSlots > 0 && $orphanAdoptedCount >= $compOrphanMaxSlots;
        $orphanSlotPressure    = $compOrphanMaxSlots > 0
            ? round($orphanAdoptedCount / $compOrphanMaxSlots * 100, 1) : 0.0;
        $healthySlotReserveAvailable = $compHealthyMinSlots > 0
            ? max(0, $compHealthyMinSlots - $healthyActiveCount) : 0;

        // ── Bootstrap metrics (healthy-close accelerator) ────────────────────
        $bootstrapActive      = $bootstrapEnabled && $bootstrapShareTarget > 0
            && $healthyShareClosedPct < $bootstrapShareTarget;
        $bootstrapShareGap    = $bootstrapShareTarget > 0
            ? round(max(0.0, $bootstrapShareTarget - $healthyShareClosedPct), 1) : 0.0;

        // Resolve capacity_slots_total (use runtime when provided, fall back to config)
        if ($capacitySlotsTotal === 0 && $storeMaxCap > 0) {
            $capacitySlotsTotal = $storeMaxCap;
        }
        // capacity_slots_used always reflects the current storage-derived active count.
        // The runtime $capacitySlotsUsed param is the pre-execution snapshot which may be
        // stale by audit time (e.g., new trades opened during the run). Using $activeCount
        // ensures the reported used count and capacity_full are always consistent.
        $capacitySlotsUsed = $activeCount;

        // capacity_full is derived exclusively from the resolved slot values.
        // This enforces the invariant: capacity_full=true iff used >= total, and prevents
        // the impossible state where full=true but used < total (or full=false but used >= total).
        $capacityFullEffective = $capacitySlotsTotal > 0 && $capacitySlotsUsed >= $capacitySlotsTotal;

        // Recoverable = stale + timeout-eligible + dead shells (can be freed by turnover pass)
        $recoverableCount = $staleCount + $orphanDeadShellCount + $orphanAdoptedTimeoutEligibleCount;

        // ── Consistency check: runtime reported state vs audit-derived state ──
        // If last_run reported a different full/not-full state vs what the current slot counts show,
        // surface it as an explicit diagnostic warning.
        $consistencyOk      = true;
        $consistencyWarning = null;
        if ($capacityFull !== $capacityFullEffective) {
            $runtimeStr = $capacityFull ? 'true' : 'false';
            $auditStr   = $capacityFullEffective ? 'true' : 'false';
            $consistencyOk      = false;
            $consistencyWarning = "Runtime demo_capacity_full={$runtimeStr} but audit capacity_full={$auditStr} ({$capacitySlotsUsed}/{$capacitySlotsTotal} slots used). Capacity state changed between execution start and audit write.";
        }

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
        } elseif ($orphanDeadShellCount > 0 && $orphanDeadShellCount >= $healthyActiveCount && $closedCount === 0) {
            // Orphan dead shells (adopted with null entry_price/qty) dominate active trades
            $primaryBottleneck       = 'orphan_dead_shells_blocking_truth_loop';
            $primaryBottleneckReason = "{$orphanDeadShellCount} active trade(s) are orphan dead shells (adopted without valid entry_price or qty). They inflate the active count but cannot close or generate AI data. They should be repaired or cleared.";
            $recommendedNextFixArea  = 'audit_orphan_adopted_dead_shells';
            $primaryExecutionBlocker = 'execution_blocked_by_orphan_positions';
        } elseif ($orphanAdoptedMissingTimingCount > 0 && $orphanAdoptedMissingTimingCount >= $orphanAdoptedCount && $orphanAdoptedCount > 0) {
            // All adopted orphans lack valid timing — they cannot age out and will not close
            $primaryBottleneck       = 'adopted_orphans_missing_timing';
            $primaryBottleneckReason = "{$orphanAdoptedMissingTimingCount} adopted orphan trade(s) have no valid timing baseline. They cannot age out or be marked stale/timeout-eligible. Check exchange position createdTime availability.";
            $recommendedNextFixArea  = 'audit_adopted_orphan_timing_fields';
        } elseif ($capacityFullEffective && $positionsOpenedThisRun === 0 && $feedIsAvailable) {
            // Capacity is saturated — classify by recoverability
            if ($capacitySlotsFreed > 0) {
                $primaryBottleneck       = 'demo_capacity_recovered_waiting_for_next_cycle';
                $primaryBottleneckReason = "Demo capacity full ({$capacitySlotsUsed}/{$capacitySlotsTotal}): {$capacitySlotsFreed} slot(s) freed by turnover pass this run. New opens should proceed in next cycle.";
                $recommendedNextFixArea  = 'wait_for_demo_trades_to_close';
                $primaryExecutionBlocker = 'awaiting_more_cycles_for_closure';
            } elseif ($recoverableCount > 0) {
                $primaryBottleneck       = 'demo_capacity_full_turnover_needed';
                $primaryBottleneckReason = "Demo capacity full ({$capacitySlotsUsed}/{$capacitySlotsTotal}): {$recoverableCount} recoverable slot(s) identified (stale/timeout-eligible/dead-shells). Turnover pass ran but did not free slots — verify learning_close_timeout_minutes and prefer_close_stale_when_learning config.";
                $recommendedNextFixArea  = 'check_turnover_config_and_exchange_close';
                $primaryExecutionBlocker = 'execution_blocked_by_capacity';
            } else {
                $primaryBottleneck       = 'demo_capacity_full_no_recoverable_slots';
                $primaryBottleneckReason = "Demo capacity full ({$capacitySlotsUsed}/{$capacitySlotsTotal}): no stale or timeout-eligible trades found. Active positions are still within allowed age. Wait for natural closes or reduce learning_close_timeout_minutes.";
                $recommendedNextFixArea  = 'wait_for_active_trades_to_close_naturally';
                $primaryExecutionBlocker = 'execution_blocked_by_capacity';
            }
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
            if ($orphanDeadShellCount > 0 && $orphanDeadShellCount >= $healthyActiveCount) {
                // Most actives are dead shells — close pipeline can't run on them
                $primaryBottleneck       = 'orphan_dead_shells_blocking_truth_loop';
                $primaryBottleneckReason = "{$orphanDeadShellCount} orphan dead shells dominate active trades ({$activeCount} total, {$healthyActiveCount} healthy). Dead shells cannot close or generate AI data.";
                $recommendedNextFixArea  = 'audit_orphan_adopted_dead_shells';
            } elseif ($orphanAdoptedCount > 0 && $orphanDeadShellCount === 0
                      && ($healthyActiveCount + $orphanAdoptedCount) === $activeCount) {
                // All actives are valid adopted orphan trades — distinguish waiting vs stale/stuck
                if ($adoptedOrphanStaleCount > 0) {
                    $primaryBottleneck       = 'adopted_orphans_stale_not_closing';
                    $primaryBottleneckReason = "{$orphanAdoptedCount} active trade(s) are adopted orphan positions ({$adoptedOrphanStaleCount} stale ≥{$learningMaxActiveAgeMinutes}min, {$orphanResolvedCount} marked resolved). Stale adopted orphans should be force-closed by the learning timeout. Verify learning_close_timeout_minutes is configured.";
                    $recommendedNextFixArea  = 'verify_learning_close_timeout_for_adopted_orphans';
                } else {
                    $primaryBottleneck       = 'adopted_orphans_awaiting_close';
                    $primaryBottleneckReason = "{$orphanAdoptedCount} active trade(s) are adopted orphan positions (local ownership resolved, {$orphanResolvedCount} marked resolved). They are in normal active lifecycle but no closures recorded yet. Waiting for exchange close events or stale-timeout.";
                    $recommendedNextFixArea  = 'wait_for_adopted_orphan_trades_to_close';
                }
            } elseif ($closedThisRun === 0 && $staleCount > 0) {
                $primaryBottleneck       = 'close_detection_too_weak';
                $primaryBottleneckReason = "Active trades exist ({$activeCount}, {$staleCount} stale ≥{$learningMaxActiveAgeMinutes}min) but none have closed. Check learning_close_timeout_minutes (force-close stale trades when exceeded) and reconcile pipeline.";
                $recommendedNextFixArea  = 'audit_reconcile_and_close_pipeline';
            } elseif ($closedThisRun === 0) {
                $primaryBottleneck       = 'close_detection_too_weak';
                $primaryBottleneckReason = "Active trades exist ({$activeCount}) but no closures recorded in storage or this run. Positions may still be open on the exchange. Verify reconcile is running (force_reconcile_each_run_demo) and that learning_close_timeout_minutes is set low enough for demo holds.";
                $recommendedNextFixArea  = 'audit_reconcile_and_close_pipeline';
            } else {
                $primaryBottleneck       = 'healthy_loop_waiting_for_more_cycles';
                $primaryBottleneckReason = "Active trades exist ({$activeCount}), {$closedThisRun} closed this run but not yet counted in storage snapshot. Loop is cycling.";
                $recommendedNextFixArea  = 'wait_for_demo_trades_to_close';
            }
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
        } elseif ($orphanSlotCapReached && $closedCount > 0 && $activeCount > 0) {
            // PART 5: Orphan cap reached — orphans are dominating the active slots
            $primaryBottleneck       = 'orphan_positions_dominating_capacity';
            $primaryBottleneckReason = "Orphan slot cap reached ({$orphanAdoptedCount}/{$compOrphanMaxSlots} orphan/adopted actives). "
                . "{$healthyActiveCount} healthy active trade(s). Orphan-adopted positions are occupying most demo capacity. "
                . "Healthy trades have limited room to enter the loop.";
            $recommendedNextFixArea  = 'reduce_orphan_occupancy_free_healthy_slots';
        } elseif ($compShareTargetPct > 0 && $healthyShareActivePct < ($compShareTargetPct * 0.5) && $activeCount > 0 && $closedCount > 0) {
            // Healthy share is critically below target
            $primaryBottleneck       = 'healthy_share_too_low';
            $primaryBottleneckReason = "Healthy active share is {$healthyShareActivePct}% (target ≥{$compShareTargetPct}%). "
                . "{$healthyActiveCount} healthy vs {$orphanAdoptedCount} orphan/adopted actives ({$activeCount} total). "
                . "Loop composition is too recovery-heavy. Need more healthy signal-born trades.";
            $recommendedNextFixArea  = 'improve_healthy_demo_signal_throughput';
        } elseif ($compHealthyMinSlots > 0 && $healthyActiveCount < $compHealthyMinSlots && $feedIsAvailable && $closedCount > 0) {
            // Healthy slot reserve is not satisfied but feed exists
            $primaryBottleneck       = 'healthy_slots_reserved_waiting_for_feed';
            $primaryBottleneckReason = "Healthy slot reserve not satisfied ({$healthyActiveCount}/{$compHealthyMinSlots} healthy minimum). "
                . "Feed is available but healthy trades are not filling reserved slots. Check signal quality and late-entry guards.";
            $recommendedNextFixArea  = 'check_healthy_demo_feed_quality_and_guards';
        } elseif ($compShareTargetPct > 0 && $healthyShareClosedPct < ($compShareTargetPct * 0.5) && $closedCount >= 5) {
            // Closed composition is too orphan-heavy
            $primaryBottleneck       = 'orphan_recovery_overweight';
            $primaryBottleneckReason = "Healthy share of closed trades is {$healthyShareClosedPct}% (target ≥{$compShareTargetPct}%). "
                . "{$healthyClosedCount} healthy vs {$adoptedOrphanClosedCount} orphan/adopted closed trades. "
                . "AI training dataset is dominated by orphan recovery trades rather than fresh signal-born trades.";
            $recommendedNextFixArea  = 'increase_healthy_signal_opens_reduce_orphan_dominance';
        } elseif ($closedCount > 0 && $closedCount < 10) {
            $primaryBottleneck       = 'turnover_too_low';
            $primaryBottleneckReason = "Only {$closedCount} closed demo trades. Loop is functioning but accumulation is too slow. "
                . "{$healthyActiveCount} healthy actives ({$healthyActiveStaleCount} stale, {$healthyActiveTimeoutEligibleCount} timeout-eligible). "
                . "Reduce learning_close_timeout_minutes or learning_max_active_age_minutes to accelerate turnover.";
            $recommendedNextFixArea  = 'increase_demo_signal_throughput';
        } elseif ($closedCount >= 10) {
            // Check if composition is balanced at this point
            $isBalancedMix = $compShareTargetPct <= 0
                || ($healthyShareActivePct >= $compShareTargetPct * 0.75 && !$orphanSlotCapReached);
            if ($isBalancedMix) {
                $primaryBottleneck       = 'balanced_demo_mix';
                $primaryBottleneckReason = "{$closedCount} closed trades: {$healthyClosedCount} healthy ({$healthyShareClosedPct}%), {$adoptedOrphanClosedCount} orphan/adopted. "
                    . "Active: {$healthyActiveCount} healthy, {$orphanAdoptedCount} orphan/adopted. "
                    . "Completeness {$completenessRate}%, AI match {$matchRate}%. Loop composition is balanced.";
                $recommendedNextFixArea  = 'maintain_current_config';
            } else {
                $primaryBottleneck       = 'none_loop_is_cycling';
                $primaryBottleneckReason = "{$closedCount} closed trades with {$completenessRate}% completeness and {$matchRate}% AI dataset match rate. Loop is cycling.";
                $recommendedNextFixArea  = 'maintain_current_config';
            }
        }

        // ── Velocity target diagnostics ──────────────────────────────────────
        $targetPerRun  = max(1, (int)($storeDlm['demo_closed_per_run_target'] ?? 1));
        $targetMet     = $closedThisRun >= $targetPerRun;
        $targetGap     = max(0, $targetPerRun - $closedThisRun);

        return [
            'active_trades_count'                      => $activeCount,
            'healthy_active_trades_count'              => $healthyActiveCount,
            'healthy_active_trades_stale_count'        => $healthyActiveStaleCount,
            'healthy_active_trades_timeout_eligible_count' => $healthyActiveTimeoutEligibleCount,
            'healthy_active_turnover_candidates_count' => $healthyActiveStaleCount + $healthyActiveTimeoutEligibleCount,
            'orphan_adopted_active_trades_count'       => $orphanAdoptedCount,
            'orphan_resolved_active_trades_count'      => $orphanResolvedCount,
            'orphan_unresolved_blocking_count'         => $orphanBlockingCount,
            'orphan_dead_shells_count'                 => $orphanDeadShellCount,
            // Adopted orphan timing health (active trades)
            'orphan_adopted_with_valid_timing_count'   => $orphanAdoptedValidTimingCount,
            'orphan_adopted_with_missing_timing_count' => $orphanAdoptedMissingTimingCount,
            'orphan_adopted_stale_eligible_count'      => $orphanAdoptedStaleEligibleCount,
            'orphan_adopted_timeout_eligible_count'    => $orphanAdoptedTimeoutEligibleCount,
            'orphan_adopted_average_age_minutes'       => $orphanAdoptedAvgAge,
            'orphan_adopted_oldest_age_minutes'        => $orphanAdoptedOldestAge,
            // Adopted orphan close pipeline metrics
            'adopted_orphans_stale_count'                    => $adoptedOrphanStaleCount,
            'adopted_orphans_closed_total'                   => $adoptedOrphanClosedCount,
            'adopted_orphans_closed_complete_count'          => $adoptedOrphanClosedComplete,
            'adopted_orphans_closed_complete_rate'           => $adoptedOrphanClosedCompleteRate,
            // Full completeness requires mfe+mae in addition to basic operational fields
            'adopted_orphans_closed_full_complete_count'     => $adoptedOrphanClosedFullComplete,
            'adopted_orphans_closed_full_complete_rate'      => $adoptedOrphanClosedFullCompleteRate,
            'adopted_orphans_without_ai_dataset_count'       => $adoptedOrphanWithoutAi,
            // Adopted orphan missing-field detail counts
            'adopted_orphans_closed_missing_close_price_count'  => $adoptedOrphanMissingClosePrice,
            'adopted_orphans_closed_missing_roi_count'          => $adoptedOrphanMissingRoi,
            'adopted_orphans_closed_missing_mfe_count'          => $adoptedOrphanMissingMfe,
            'adopted_orphans_closed_missing_mae_count'          => $adoptedOrphanMissingMae,
            'adopted_orphans_closed_missing_hold_minutes_count' => $adoptedOrphanMissingHoldMinutes,
            'closed_trades_count'                      => $closedCount,
            'closed_trades_total'                      => $closedCount,
            'closed_trades_healthy_total'              => $healthyClosedCount,
            'closed_trades_orphan_adopted_total'       => $adoptedOrphanClosedCount,
            // Healthy closed quality metrics
            'closed_trades_healthy_complete_count'     => $healthyClosedCompleteCount,
            'closed_trades_healthy_complete_rate'      => $healthyClosedCount > 0 ? round($healthyClosedCompleteCount / $healthyClosedCount * 100, 1) : 0.0,
            'closed_trades_healthy_full_complete_count'=> $healthyClosedFullCompleteCount,
            'closed_trades_healthy_full_complete_rate' => $healthyClosedCount > 0 ? round($healthyClosedFullCompleteCount / $healthyClosedCount * 100, 1) : 0.0,
            'closed_trades_healthy_missing_mfe_count'  => $healthyClosedMissingMfe,
            'closed_trades_healthy_missing_mae_count'  => $healthyClosedMissingMae,
            'closed_trades_healthy_missing_close_price_count'   => $healthyClosedMissingClosePrice,
            'closed_trades_healthy_missing_hold_minutes_count'  => $healthyClosedMissingHoldMinutes,
            'ai_dataset_records'                       => $aiCount,
            'ai_dataset_count'                         => $aiCount,
            'ai_dataset_total'                         => $aiCount,
            // ── PART 4: Demo composition metrics ─────────────────────────────
            'healthy_share_active_pct'                 => $healthyShareActivePct,
            'healthy_share_closed_pct'                 => $healthyShareClosedPct,
            'orphan_slot_cap'                          => $compOrphanMaxSlots,
            'orphan_slot_cap_reached'                  => $orphanSlotCapReached,
            'orphan_slot_pressure'                     => $orphanSlotPressure,
            'healthy_slot_reserve_total'               => $compHealthyMinSlots,
            'healthy_slot_reserve_available'           => $healthySlotReserveAvailable,
            'healthy_share_target_pct'                 => $compShareTargetPct,
            // ── PART 5: Composition bottleneck labels ────────────────────────
            'primary_composition_bottleneck'           => $primaryBottleneck,
            'primary_composition_bottleneck_reason'    => $primaryBottleneckReason,
            'oldest_active_trade_age_minutes'          => $oldestActiveAgeMin,
            'avg_active_trade_age_minutes'             => $avgActiveAgeMin,
            'stale_active_count'                       => $staleCount,
            'active_trades_stale_count'                => $staleCount,   // canonical alias
            'pct_active_trades_stale'                  => $pctStaleActive,
            'stale_threshold_minutes'                  => $learningMaxActiveAgeMinutes,
            'closed_trades_complete_count'             => $completeClosedCount,
            'closed_trades_completeness_rate'          => $completenessRate,
            'closed_trades_complete_rate'              => $completenessRate,  // alias
            // Full completeness: basic fields + mfe + mae
            'closed_trades_full_complete_count'        => $fullCompleteClosedCount,
            'closed_trades_full_complete_rate'         => $fullCompletenessRate,
            // Count-based missing-field metrics for all closed trades
            'closed_trades_missing_close_price_count'  => $missingClosePrice,
            'closed_trades_missing_hold_minutes_count' => $missingHoldMinutes,
            'closed_trades_missing_mfe_count'          => $missingMfe,
            'closed_trades_missing_mae_count'          => $missingMae,
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
            // Capacity saturation fields
            'capacity_full'                            => $capacityFullEffective,
            'capacity_slots_total'                     => $capacitySlotsTotal,
            'capacity_slots_used'                      => $capacitySlotsUsed,
            'capacity_slots_freed_this_run'            => $capacitySlotsFreed,
            'recoverable_active_trades_count'          => $recoverableCount,
            'stale_active_trades_count'                => $staleCount,
            'turnover_candidates_count'                => $staleCount + $orphanAdoptedTimeoutEligibleCount + $healthyActiveTimeoutEligibleCount,
            // Velocity target diagnostics (per-run)
            'demo_closed_trades_target_per_run'        => $targetPerRun,
            'demo_closed_trades_target_met'            => $targetMet,
            'demo_closed_trades_target_gap'            => $targetGap,
            // Consistency check
            'capacity_runtime_consistency_ok'          => $consistencyOk,
            'capacity_runtime_consistency_warning'     => $consistencyWarning,
            // Bootstrap healthy-close accelerator diagnostics
            'healthy_close_bootstrap_enabled'          => $bootstrapEnabled,
            'healthy_close_bootstrap_active'           => $bootstrapActive,
            'healthy_closed_share_target_pct'          => $bootstrapShareTarget,
            'healthy_closed_share_gap_pct'             => $bootstrapShareGap,
            'healthy_bootstrap_timeout_minutes_effective' => $bootstrapActive ? $bootstrapTimeoutMin : 0,
            'healthy_bootstrap_stale_minutes_effective'   => $bootstrapActive ? $bootstrapStaleMin   : 0,
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

    /**
     * Load healthy_share_closed_pct from the previously saved audit (lightweight, no file scanning).
     * Returns 0.0 if no previous audit exists.
     */
    public function getPreviousHealthyShareClosedPct(): float
    {
        $path = $this->storageDir . '/runtime/demo_truth_audit.json';
        if (!file_exists($path)) {
            return 0.0;
        }
        $data = @json_decode((string)file_get_contents($path), true);
        return is_array($data) ? (float)($data['healthy_share_closed_pct'] ?? 0.0) : 0.0;
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
