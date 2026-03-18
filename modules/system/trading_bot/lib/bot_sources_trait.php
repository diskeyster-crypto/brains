<?php
declare(strict_types=1);

namespace Modules\System\TradingBot\Lib;

use Core\System\SystemPaths;

/**
 * Bot Sources Trait
 * 
 * Handles loading data from Brain signals and other sources.
 * BRAIN-CONTROLLED: Prefers Brain-approved live_intents.json over raw signals.
 * CLEAN signals only - risk from signal.risk block.
 */
trait BotSourcesTrait
{
    /**
     * Load Brain-approved live intents (preferred source).
     * Brain generates live_intents.json with only approved, filtered intents.
     * Bot must consume these instead of raw signals when available.
     *
     * CRITICAL V2: brain_controlled is determined from Brain effective config,
     * NOT from whether live_intents.json loaded successfully.
     * If Brain mode is ON and file is missing/invalid → safe no-trade, NOT legacy fallback.
     *
     * @return array{ok:bool,count:int,intents:list<array>,errors:list<string>,source:string,brain_controlled:bool,effective_live_config:array,source_status:string,source_error:string,fallback_allowed:bool}
     */
    protected function loadBrainLiveIntents(): array
    {
        $result = [
            'ok' => true,
            'count' => 0,
            'intents' => [],
            'errors' => [],
            'source' => 'brain_live_intents',
            'brain_controlled' => false,
            'effective_live_config' => [],
            'source_status' => 'unknown',
            'source_error' => '',
            'fallback_allowed' => true,
        ];

        try {
            $paths = SystemPaths::instance();

            // Brain storage key (same as signals_key — they share the same storage root)
            $brainKey = $this->config['sources']['signals_key'] ?? 'system.brain.storage';
            if (!$paths->has($brainKey)) {
                $result['ok'] = false;
                $result['errors'][] = "Brain storage path key not found: {$brainKey}";
                $result['source_status'] = 'missing';
                $result['source_error'] = "Brain storage path key not found: {$brainKey}";
                return $result;
            }

            $brainBase = $paths->get($brainKey);
            $liveIntentsPath = $brainBase . '/live_intents.json';

            // ================================================================
            // Determine brain_controlled mode from EFFECTIVE CONFIG,
            // not from file load success. detectBrainControlledMode() reads
            // effective_config.json / user_config.json independently.
            // ================================================================
            $brainControlledMode = $this->detectBrainControlledMode();

            if ($brainControlledMode) {
                $result['brain_controlled'] = true;
                $result['fallback_allowed'] = false;
            }

            if (!is_file($liveIntentsPath)) {
                // No live intents file — source not available
                if ($brainControlledMode) {
                    // V2: Brain mode is ON but file missing → safe no-trade
                    $result['source'] = 'none';
                    $result['source_status'] = 'missing';
                    $result['source_error'] = 'Brain-controlled mode active: Brain live intents source is missing. No trades executed. Legacy fallback disabled.';
                } else {
                    $result['source'] = 'no_brain_intents_file';
                    $result['source_status'] = 'missing';
                }
                return $result;
            }

            $content = @file_get_contents($liveIntentsPath);
            if ($content === false) {
                $result['ok'] = false;
                $result['errors'][] = "Failed to read live_intents.json";
                $result['source_status'] = 'invalid';
                $result['source_error'] = 'Failed to read live_intents.json';
                return $result;
            }

            $data = @json_decode($content, true);
            if (!is_array($data)) {
                $result['ok'] = false;
                $result['errors'][] = "Invalid JSON in live_intents.json";
                $result['source_status'] = 'invalid';
                $result['source_error'] = 'Invalid JSON in live_intents.json';
                return $result;
            }

            // Validate schema
            $schemaVersion = $data['schema_version'] ?? '';
            if ($schemaVersion !== 'live_intents_v1') {
                $result['ok'] = false;
                $result['errors'][] = "Unexpected schema_version in live_intents.json: {$schemaVersion}";
                $result['source_status'] = 'invalid';
                $result['source_error'] = "Unexpected schema_version: {$schemaVersion}";
                return $result;
            }

            // V2: Also check brain_controlled_live_mode from the file itself
            if (!empty($data['brain_controlled_live_mode'])) {
                $result['brain_controlled'] = true;
                $result['fallback_allowed'] = false;
            }

            $result['effective_live_config'] = $data['effective_live_config'] ?? [];
            $result['source_status'] = 'loaded';

            // If live trading is not enabled in Brain config, return empty intents
            if (!($data['live_trading_enabled'] ?? false)) {
                $result['source'] = 'brain_live_intents_disabled';
                $result['source_status'] = 'disabled';
                return $result;
            }

            $intents = $data['intents'] ?? [];
            if (!is_array($intents)) {
                $intents = [];
            }

            $executedIndex = $this->loadExecutedIndex();
            $validIntents = [];
            $duplicateSkipped = 0;
            $duplicateSkippedRecords = [];

            foreach ($intents as $intent) {
                // V2 FIX: Use intent_id as the authoritative identity key for Brain intents
                $intentId = $intent['intent_id'] ?? null;
                $signalId = $intent['signal_id'] ?? null;
                $executionKey = $intentId ?? $signalId ?? null;
                if (empty($executionKey)) {
                    continue;
                }

                // Skip if already executed (idempotency) — check BOTH intent_id and signal_id for safety
                $isDuplicate = false;
                if (isset($executedIndex[$executionKey])) {
                    $isDuplicate = true;
                }
                // Also check signal_id separately for backward compat with old executed_index entries
                if (!$isDuplicate && $intentId !== null && $signalId !== null && $intentId !== $signalId && isset($executedIndex[$signalId])) {
                    $isDuplicate = true;
                }

                if ($isDuplicate) {
                    $duplicateSkipped++;
                    // Build explicit result record for duplicate-skipped intent
                    $duplicateSkippedRecords[] = [
                        'intent_id' => $intentId ?? $executionKey,
                        'signal_id' => $signalId,
                        'symbol' => (string)($intent['symbol'] ?? ''),
                        'side' => (string)($intent['side'] ?? ''),
                        'brain_controlled' => true,
                        'execution_identity_key' => $executionKey,
                        'lifecycle_state' => 'skipped',
                        'processed_at' => date('c'),
                        'execution_result' => 'skipped',
                        'rejection_reason' => 'rejected_duplicate_execution_key',
                        'close_reason' => null,
                        'order_id' => null,
                        'position_id' => null,
                        'protection_status' => 'none',
                        'trailing_status' => 'disabled',
                        'source_status' => 'brain_live_intent',
                        'debug_message' => 'Already processed (execution key exists in executed_index)',
                    ];
                    continue;
                }

                // Skip if expired
                $expiresAt = $intent['expires_at'] ?? 0;
                if ($expiresAt > 0 && $expiresAt < time()) {
                    continue;
                }

                // V2: Normalize Brain trailing_contract into risk.trailing for bot execution engines
                $risk = $intent['risk'] ?? [];
                $brainTrailing = $intent['trailing'] ?? [];
                $risk = $this->normalizeBrainTrailingIntoRisk($risk, $brainTrailing);

                // Normalize intent for bot execution
                $normalized = [
                    'id' => $executionKey,
                    'signal_id' => $signalId ?? $executionKey,
                    'intent_id' => $intentId ?? $executionKey,
                    'schema_version' => 'intent_live_v1',
                    'symbol' => (string)($intent['symbol'] ?? ''),
                    'side' => (string)($intent['side'] ?? ''),
                    'entry_price' => (float)($intent['entry_price_reference'] ?? 0),
                    'entry_action' => (string)($intent['entry_action'] ?? 'enter_now'),
                    'entry_timeout_minutes' => $intent['entry_timeout_minutes'] ?? null,
                    'late_threshold_pct' => $this->config['execution']['default_late_threshold_pct'] ?? 0.5,
                    'created_ts' => $intent['created_ts'] ?? time(),
                    'expires_at' => $intent['expires_at'] ?? 0,
                    'risk' => $risk,
                    'trailing' => $brainTrailing,
                    'brain' => [],
                    'source' => 'brain_live_intent',
                    'intent_created_at' => $intent['created_ts'] ?? date('c'),
                    'brain_controlled' => true,
                    'selection_mode_used' => $intent['selection_mode_used'] ?? '',
                    'approval_reason' => $intent['approval_reason'] ?? '',
                    'execution_limits_snapshot' => $intent['execution_limits_snapshot'] ?? [],
                    'effective_trailing_contract_source' => !empty($brainTrailing) ? 'brain_intent' : 'risk_block',
                    'trailing_contract_normalized' => !empty($brainTrailing),
                    // V2: Execution identity and trailing debug visibility
                    'execution_identity_key' => $intentId ?? $executionKey,
                    'dedupe_basis' => 'intent_id',
                    'normalized_drawdown_factor_source' => $risk['trailing']['drawdown_factor_source'] ?? 'n/a',
                ];

                if (isset($intent['side_original'])) {
                    $normalized['side_original'] = $intent['side_original'];
                }

                $validIntents[] = $normalized;
            }

            $result['count'] = count($validIntents);
            $result['intents'] = $validIntents;
            $result['duplicate_skipped'] = $duplicateSkipped;
            $result['duplicate_skipped_records'] = $duplicateSkippedRecords;

            if (count($validIntents) === 0) {
                $result['source_status'] = 'empty';
            }

        } catch (\Throwable $e) {
            $result['ok'] = false;
            $result['errors'][] = 'Exception: ' . $e->getMessage();
            $result['source_status'] = 'invalid';
            $result['source_error'] = 'Exception: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Detect Brain-controlled live mode from Brain effective/user config.
     *
     * CRITICAL: This is determined from Brain config files (effective_config.json
     * or user_config.json), NOT from whether live_intents.json loaded successfully.
     * If Brain mode is active, it stays true even if the intents file is
     * missing, invalid, or empty — resulting in safe no-trade, NOT legacy fallback.
     *
     * Must be called BEFORE any source loading so service.php can branch
     * the execution flow explicitly.
     *
     * @return bool true when Brain-controlled live mode is active
     */
    protected function detectBrainControlledMode(): bool
    {
        try {
            $paths = SystemPaths::instance();
            $brainKey = $this->config['sources']['signals_key'] ?? 'system.brain.storage';
            if (!$paths->has($brainKey)) {
                return false;
            }
            $brainBase = $paths->get($brainKey);
        } catch (\Throwable $e) {
            return false;
        }

        // Method 1: Check effective_config.json (written by Smart Brain each cycle)
        $effectiveConfigPath = $brainBase . '/../runtime/effective_config.json';
        if (is_file($effectiveConfigPath)) {
            $content = @file_get_contents($effectiveConfigPath);
            if ($content !== false) {
                $data = @json_decode($content, true);
                if (is_array($data)) {
                    $liveEnabled = $data['live_trading']['live_trading_enabled']
                        ?? $data['live_trading_enabled']
                        ?? null;
                    if ($liveEnabled !== null) {
                        return (bool)$liveEnabled;
                    }
                }
            }
        }

        // Method 2: Check user_config.json directly
        $userConfigPath = $brainBase . '/../runtime/user_config.json';
        if (is_file($userConfigPath)) {
            $content = @file_get_contents($userConfigPath);
            if ($content !== false) {
                $data = @json_decode($content, true);
                if (is_array($data)) {
                    $liveEnabled = $data['live_trading_enabled'] ?? null;
                    if ($liveEnabled !== null) {
                        return (bool)$liveEnabled;
                    }
                }
            }
        }

        // If neither config provides a definitive answer, default to false.
        // Legacy fallback is allowed when mode cannot be determined.
        return false;
    }

    /**
     * V2: Normalize Brain trailing contract into risk.trailing format
     * that bot execution engines (BotRiskEngine, BotTrailingEngine) expect.
     *
     * Brain contract fields → Bot execution fields mapping:
     * - trailing.trailing_enabled → risk.trailing.enabled
     * - trailing.trailing_activation_roi → risk.trailing.activation_roi_pct (converted to %)
     * - trailing.trailing_min_lock_roi → risk.trailing.min_lock_roi
     * - trailing.trailing_min_step → risk.trailing.min_step
     * - trailing.break_even_enabled → risk.trailing.break_even_enabled
     * - trailing.break_even_activation_roi → risk.trailing.break_even_activation_roi
     * - trailing.exit_mode → risk.trailing.exit_mode
     * - trailing.fixed_take_profit_roi → risk.trailing.fixed_take_profit_roi
     * - trailing.hybrid_tp_share → risk.trailing.hybrid_tp_share
     *
     * @param array $risk Existing risk block from signal
     * @param array $brainTrailing Brain trailing contract
     * @return array Updated risk block with normalized trailing
     */
    private function normalizeBrainTrailingIntoRisk(array $risk, array $brainTrailing): array
    {
        if (empty($brainTrailing)) {
            return $risk;
        }

        // V2 FIX: drawdown_factor source must be explicit and semantically correct.
        // trailing_min_step is NOT the same as drawdown_factor.
        // Priority: 1) explicit drawdown_factor from Brain trailing contract
        //           2) existing risk.trailing.drawdown_factor (from Brain signal risk block)
        //           3) documented engine default (0.5 = normal mode)
        $drawdownFactor = (float)($brainTrailing['drawdown_factor']
            ?? $risk['trailing']['drawdown_factor']
            ?? 0.5);
        $drawdownFactorSource = isset($brainTrailing['drawdown_factor'])
            ? 'brain_trailing_contract'
            : (isset($risk['trailing']['drawdown_factor'])
                ? 'risk_block'
                : 'documented_default');

        $normalized = [
            'enabled' => (bool)($brainTrailing['trailing_enabled'] ?? false),
            // Brain uses ratio (e.g. 0.02 = 2%), bot expects percentage (e.g. 2.0 = 2%)
            'activation_roi_pct' => (float)($brainTrailing['trailing_activation_roi'] ?? 0) * 100,
            'drawdown_factor' => $drawdownFactor,
            'drawdown_factor_source' => $drawdownFactorSource,
            'min_lock_roi' => (float)($brainTrailing['trailing_min_lock_roi'] ?? 0),
            'min_step' => (float)($brainTrailing['trailing_min_step'] ?? 0),
            'break_even_enabled' => (bool)($brainTrailing['break_even_enabled'] ?? false),
            'break_even_activation_roi' => (float)($brainTrailing['break_even_activation_roi'] ?? 0),
            'exit_mode' => (string)($brainTrailing['exit_mode'] ?? 'fixed_tp'),
            'fixed_take_profit_roi' => (float)($brainTrailing['fixed_take_profit_roi'] ?? 0),
            'hybrid_tp_share' => (float)($brainTrailing['hybrid_tp_share'] ?? 0),
            'brain_trailing_applied' => true,
        ];

        $risk['trailing'] = $normalized;
        return $risk;
    }

    /**
     * Load intents from Brain signals (legacy fallback)
     * 
     * @return array Result with intents
     */
    protected function loadIntentsFromSignals(): array
    {
        $result = [
            'ok' => true,
            'count' => 0,
            'intents' => [],
            'errors' => [],
        ];
        
        try {
            $paths = SystemPaths::instance();
            
            // Get Brain signals path
            $signalsKey = $this->config['sources']['signals_key'] ?? 'system.brain.storage';
            $signalsFile = $this->config['sources']['signals_file'] ?? 'signals.json';
            
            if (!$paths->has($signalsKey)) {
                $result['ok'] = false;
                $result['errors'][] = "Signals path key not found: {$signalsKey}";
                return $result;
            }
            
            $signalsBase = $paths->get($signalsKey);
            $signalsPath = $signalsBase . '/' . $signalsFile;
            
            if (!is_file($signalsPath)) {
                // No signals file - not an error, just no intents
                return $result;
            }
            
            $content = @file_get_contents($signalsPath);
            if ($content === false) {
                $result['ok'] = false;
                $result['errors'][] = "Failed to read signals file: {$signalsPath}";
                return $result;
            }
            
            $data = @json_decode($content, true);
            if (!is_array($data)) {
                $result['ok'] = false;
                $result['errors'][] = "Invalid JSON in signals file";
                return $result;
            }
            
            // Extract signals array
            $signals = $data['signals'] ?? [];
            if (!is_array($signals)) {
                $signals = [];
            }
            
            // Convert signals to intents
            $intents = [];
            $executedIndex = $this->loadExecutedIndex();
            
            foreach ($signals as $signal) {
                $signalId = $signal['id'] ?? null;
                
                // Skip if no ID
                if (empty($signalId)) {
                    continue;
                }
                
                // Skip if already executed (idempotency)
                if (isset($executedIndex[$signalId])) {
                    continue;
                }
                
                // Skip if expired
                $expiresAt = $signal['expires_at'] ?? 0;
                if ($expiresAt > 0 && $expiresAt < time()) {
                    continue;
                }
                
                // Check entry action
                $entryAction = $signal['entry_action'] ?? 'enter_now';
                if ($entryAction === 'wait_retrace') {
                    // For wait_retrace, check if entry timeout passed
                    $createdTs = $signal['created_ts'] ?? 0;
                    $timeoutMinutes = $signal['entry_timeout_minutes'] ?? 
                                     $this->config['execution']['default_entry_timeout_minutes'] ?? 10;
                    
                    if ($createdTs > 0 && (time() - $createdTs) > ($timeoutMinutes * 60)) {
                        // Entry timeout - skip
                        continue;
                    }
                }
                
                // Create intent from signal
                $intent = $this->createIntentFromSignal($signal);
                if ($intent !== null) {
                    $intents[] = $intent;
                }
            }
            
            $result['count'] = count($intents);
            $result['intents'] = $intents;
            
        } catch (\Throwable $e) {
            $result['ok'] = false;
            $result['errors'][] = 'Exception: ' . $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Create intent from Brain signal
     * 
     * Phase-1: Intent does NOT copy TP/SL from signal.
     * SL is calculated from liquidation price after order fills.
     * Trailing is set once if risk.trailing.enabled=true.
     * 
     * P1.1 FIX: Strict schema validation - signal MUST have schema_version.
     * 
     * @param array $signal Signal data
     * @return array|null Intent or null if invalid
     */
    private function createIntentFromSignal(array $signal): ?array
    {
        // Extract required fields
        $id = $signal['id'] ?? null;
        $symbol = $signal['symbol'] ?? null;
        $side = $signal['side'] ?? null;
        $risk = $signal['risk'] ?? null;
        
        if (empty($id) || empty($symbol) || empty($side) || !is_array($risk)) {
            return null;
        }
        
        // P1.1: Strict schema validation - signal MUST have schema_version
        $schemaVersion = $signal['schema_version'] ?? null;
        $expectedSchema = $this->config['validation']['signal_schema_version'] 
            ?? $this->config['validation']['schema_version'] 
            ?? 'clean_signal_v1';
        
        // P1.1: If schema_version is missing → return null
        if ($schemaVersion === null) {
            return null;
        }
        
        // P1.1: If schema_version != expected → return null
        if ($schemaVersion !== $expectedSchema) {
            return null;
        }
        
        // Extract entry price
        $entryPrice = null;
        if (isset($signal['entry']['price'])) {
            $entryPrice = (float)$signal['entry']['price'];
        } elseif (isset($signal['entry_price'])) {
            $entryPrice = (float)$signal['entry_price'];
        }
        
        if ($entryPrice === null || $entryPrice <= 0) {
            return null;
        }
        
        // Build intent - Phase-1: NO take_profit/stop_loss copied from signal
        // SL calculated from liquidation after position opens
        $sideLower = strtolower((string)$side);

        // DEPRECATED: reverse side in legacy signal path.
        // Brain now handles reverse_side via live_reverse_side_enabled in live_intents.
        // This legacy path is kept for backward compatibility only.
        $reverseEnabled = (bool)($this->config['execution']['reverse_side_enabled'] ?? false);
        $sideOriginal = $sideLower;

        if ($reverseEnabled) {
            if ($sideLower === 'long') {
                $sideLower = 'short';
            } elseif ($sideLower === 'short') {
                $sideLower = 'long';
            }
        }

        $intent = [
            'id' => $id,
            'signal_id' => $id,
            'schema_version' => 'intent_live_v1',
            'symbol' => $symbol,
            'side' => $sideLower,
            'entry_price' => $entryPrice,
            'entry_action' => $signal['entry_action'] ?? 'enter_now',
            'entry_timeout_minutes' => $signal['entry_timeout_minutes'] ?? null,
            'late_threshold_pct' => $signal['late_threshold_pct'] ?? 
                                   $this->config['execution']['default_late_threshold_pct'] ?? 0.5,
            'created_ts' => $signal['created_ts'] ?? time(),
            'expires_at' => $signal['expires_at'] ?? 0,
            'risk' => $risk,
            // Phase-1: TP/SL NOT from signal - calculated from liquidation
            // 'take_profit' => removed
            // 'stop_loss' => removed
            'brain' => $signal['brain'] ?? [],
            'source' => 'brain_signal',
            'intent_created_at' => date('c'),
        ];

        if ($reverseEnabled && $sideOriginal !== $sideLower) {
            // Keep original side for transparency in logs/UI.
            $intent['side_original'] = $sideOriginal;
            $intent['reverse_side_enabled'] = true;
        }

        return $intent;
    }
    
    /**
     * Load executed signals index
     * 
     * @return array Executed index [signal_id => {...}]
     */
    private function loadExecutedIndex(): array
    {
        $path = $this->storageDir . '/executed_index.json';
        
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
     * Mark signal as executed (atomic with flock)
     * 
     * B7: Atomic executed_index with flock for concurrent safety.
     * Phase-1 status values:
     * - opened_protected: Successfully opened with SL set
     * - rejected_validation: Failed validation
     * - rejected_limits: Hit position limits
     * - rejected_late_entry: Price moved too far
     * - rejected_sl_failed: Failed to set SL (fail-safe closed)
     * 
     * V3 FIX: $signalId is now always the unified execution identity key
     * (intent_id for Brain intents, signal_id for legacy).
     * All callers must use getExecutionIdentityKey() to derive this value.
     * 
     * @param string $signalId Execution identity key (intent_id or legacy signal_id)
     * @param array $result Execution result
     * @param string $dedupeBasis 'intent_id' or 'legacy_signal_id' (for debug tracing)
     */
    protected function markSignalExecuted(string $signalId, array $result, string $dedupeBasis = ''): void
    {
        $path = $this->storageDir . '/executed_index.json';
        
        // B7: Use flock for atomic read-modify-write
        $fp = @fopen($path, 'c+');
        if ($fp === false) {
            // Fallback: try to create directory and file
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $fp = @fopen($path, 'c+');
        }
        
        if ($fp === false) {
            // Last resort: non-atomic write with error logging
            error_log("TradingBot: flock failed for executed_index.json, falling back to non-atomic write");
            $index = $this->loadExecutedIndex();
            $index[$signalId] = $this->buildExecutedEntry($result, $signalId, $dedupeBasis);
            @file_put_contents($path, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        
        // Acquire exclusive lock
        if (!flock($fp, LOCK_EX)) {
            error_log("TradingBot: flock(LOCK_EX) failed for executed_index.json");
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
            
            // Add/update entry
            $index[$signalId] = $this->buildExecutedEntry($result, $signalId, $dedupeBasis);
            
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
    
    /**
     * Build executed index entry
     *
     * @param array $result Execution result
     * @param string $executionKey The execution identity key used (for debug tracing)
     * @param string $dedupeBasis Whether the key is intent_id or legacy_signal_id
     */
    private function buildExecutedEntry(array $result, string $executionKey = '', string $dedupeBasis = ''): array
    {
        $entry = [
            'executed_at' => date('c'),
            'result' => $result['status'] ?? 'unknown',
            'order_id' => $result['order_id'] ?? null,
            'trade_id' => $result['trade_id'] ?? null,
            'error' => $result['error'] ?? null,
        ];

        if ($executionKey !== '') {
            $entry['execution_identity_key'] = $executionKey;
        }
        if ($dedupeBasis !== '') {
            $entry['dedupe_basis'] = $dedupeBasis;
        }

        return $entry;
    }
    
    /**
     * Resolve a normalized lifecycle state from an execution result.
     *
     * @param array $execResult Execution result array
     * @return string One of: pending, opened, rejected, failed, deferred, skipped, closed
     */
    protected function resolveIntentLifecycleState(array $execResult): string
    {
        // Explicit skipped state (duplicate suppression)
        if (($execResult['status'] ?? '') === 'skipped') {
            return 'skipped';
        }

        if (!empty($execResult['opened'])) {
            // Distinguish protected vs merely opened
            $status = $execResult['status'] ?? '';
            if ($status === 'opened_protected') {
                return 'protected';
            }
            return 'opened';
        }

        $status = $execResult['status'] ?? '';

        // Closed states
        if (strpos($status, 'closed_') === 0 || $status === 'exchange_closed') {
            return 'closed';
        }

        if (strpos($status, 'deferred_') === 0) {
            return 'deferred';
        }
        if (strpos($status, 'rejected_') === 0) {
            return 'rejected';
        }
        if ($status === 'critical_unprotected_position_close_failed') {
            return 'failed';
        }
        if ($status === 'error') {
            return 'failed';
        }

        return 'pending';
    }

    /**
     * Build a structured result record for a single processed intent.
     *
     * @param array $intent  The intent that was processed
     * @param array $execResult  The execution result
     * @return array Structured intent result record
     */
    protected function buildIntentResultRecord(array $intent, array $execResult): array
    {
        $lifecycleState = $this->resolveIntentLifecycleState($execResult);
        $trailingEnabled = (bool)($intent['risk']['trailing']['enabled'] ?? false);
        $execStatus = $execResult['status'] ?? 'unknown';

        // Richer protection_status
        $protectionStatus = 'none';
        if ($lifecycleState === 'protected') {
            $protectionStatus = 'protected';
        } elseif ($lifecycleState === 'opened') {
            $protectionStatus = 'opened_unprotected';
        } elseif ($lifecycleState === 'failed' && strpos($execStatus, 'unprotected') !== false) {
            $protectionStatus = 'protection_error';
        }

        // Richer trailing_status
        $trailingStatus = 'disabled';
        if ($trailingEnabled) {
            if ($lifecycleState === 'trailing_active') {
                $trailingStatus = 'active';
            } elseif (in_array($lifecycleState, ['opened', 'protected'], true)) {
                $trailingStatus = 'armed';
            } else {
                $trailingStatus = 'enabled';
            }
        }

        $record = [
            'intent_id' => $intent['intent_id'] ?? $intent['id'] ?? null,
            'signal_id' => $intent['signal_id'] ?? null,
            'symbol' => $intent['symbol'] ?? '',
            'side' => $intent['side'] ?? '',
            'brain_controlled' => !empty($intent['brain_controlled']),
            'execution_identity_key' => $intent['execution_identity_key'] ?? ($intent['intent_id'] ?? ($intent['signal_id'] ?? '')),
            'lifecycle_state' => $lifecycleState,
            'processed_at' => date('c'),
            'execution_result' => $execStatus,
            'rejection_reason' => null,
            'close_reason' => null,
            'order_id' => $execResult['order_id'] ?? null,
            'position_id' => $execResult['trade_id'] ?? null,
            'protection_status' => $protectionStatus,
            'trailing_status' => $trailingStatus,
            'source_status' => $intent['source'] ?? 'brain_live_intent',
            'debug_message' => $execResult['error'] ?? null,
        ];

        if ($lifecycleState === 'rejected') {
            $record['rejection_reason'] = $this->normalizeRejectionReason($execStatus);
        }
        if ($lifecycleState === 'failed') {
            $record['rejection_reason'] = $this->normalizeRejectionReason($execStatus);
            $record['close_reason'] = $this->normalizeCloseReason($execResult['error'] ?? $execStatus);
        }
        if ($lifecycleState === 'closed') {
            $record['close_reason'] = $this->normalizeCloseReason($execStatus);
        }

        return $record;
    }

    /**
     * Normalize a rejection reason to a stable machine-readable value.
     *
     * @param string $raw Raw rejection status/reason
     * @return string Normalized rejection reason
     */
    protected function normalizeRejectionReason(string $raw): string
    {
        // Already normalized — starts with rejected_
        if (strpos($raw, 'rejected_') === 0) {
            // Map known vague suffixes to stable categories
            $map = [
                'rejected_validation' => 'rejected_invalid_brain_intent',
                'rejected_entry_timeout' => 'rejected_late_entry',
                'rejected_order_failed' => 'rejected_exchange_error',
                'rejected_leverage_failed' => 'rejected_exchange_error',
                'rejected_balance_unavailable' => 'rejected_insufficient_balance',
                'rejected_balance_below_minimum' => 'rejected_insufficient_balance',
                'rejected_symbol_disabled' => 'rejected_disabled_by_mode',
            ];
            return $map[$raw] ?? $raw;
        }

        // Map non-prefixed reasons
        if ($raw === 'error' || $raw === 'unknown') {
            return 'rejected_unknown';
        }
        if (strpos($raw, 'critical_') === 0) {
            return 'rejected_exchange_error';
        }

        return 'rejected_' . $raw;
    }

    /**
     * Normalize a close reason to a stable machine-readable value.
     *
     * @param string $raw Raw close reason
     * @return string Normalized close reason
     */
    protected function normalizeCloseReason(string $raw): string
    {
        $map = [
            'stop_loss' => 'close_stop_loss',
            'trailing_stop' => 'close_trailing_stop',
            'take_profit' => 'close_take_profit',
            'hybrid_take_profit' => 'close_hybrid_take_profit',
            'break_even' => 'close_break_even',
            'manual_close' => 'close_manual',
            'exchange_closed' => 'close_exchange_forced',
            'reconcile_failed' => 'close_fail_safe',
            'sl_calculation_failed' => 'close_fail_safe',
            'sl_set_failed' => 'close_fail_safe',
            'critical_unprotected_position_close_failed' => 'close_protection_error',
        ];

        // Already normalized
        if (strpos($raw, 'close_') === 0) {
            return $raw;
        }

        return $map[$raw] ?? 'close_unknown';
    }

    /**
     * Load commands from Brain (P7)
     * 
     * Reads trading_commands.json from Brain storage.
     * Schema must be 'trading_commands_v1'.
     * Commands are validated for required fields.
     * 
     * P7.7: Strengthened validation - validates type, target, params.
     * Invalid commands do NOT fail the load - they are skipped with error logged.
     * 
     * @return array ['ok' => bool, 'count' => int, 'commands' => array, 'errors' => array]
     */
    protected function loadCommandsFromBrain(): array
    {
        $result = [
            'ok' => true,
            'count' => 0,
            'commands' => [],
            'errors' => [],
        ];
        
        try {
            $paths = SystemPaths::instance();
            
            // Get Brain commands path
            $commandsKey = $this->config['sources']['commands_key'] ?? 'system.brain.storage';
            $commandsFile = $this->config['sources']['commands_file'] ?? 'runtime/trading_commands.json';
            
            if (!$paths->has($commandsKey)) {
                // Not an error - commands source not configured
                return $result;
            }
            
            $commandsBase = $paths->get($commandsKey);
            $commandsPath = $commandsBase . '/' . $commandsFile;
            
            if (!is_file($commandsPath)) {
                // No commands file - not an error, just no commands
                return $result;
            }
            
            $content = @file_get_contents($commandsPath);
            if ($content === false) {
                $result['ok'] = false;
                $result['errors'][] = "Failed to read commands file: {$commandsPath}";
                return $result;
            }
            
            $data = @json_decode($content, true);
            if (!is_array($data)) {
                $result['ok'] = false;
                $result['errors'][] = "Invalid JSON in commands file";
                return $result;
            }
            
            // P7.2: Strict schema validation
            $schemaVersion = $data['schema_version'] ?? null;
            if ($schemaVersion !== 'trading_commands_v1') {
                $result['ok'] = false;
                $result['errors'][] = "Invalid schema_version: expected 'trading_commands_v1', got '{$schemaVersion}'";
                return $result;
            }
            
            // Extract commands array
            $commands = $data['commands'] ?? [];
            if (!is_array($commands)) {
                $result['ok'] = false;
                $result['errors'][] = "Commands field must be an array";
                return $result;
            }
            
            // P7.7: Validate each command with full contract check
            $validCommands = [];
            $commandIndex = 0;
            
            // P7.7: Allowed command types
            $allowedTypes = ['set_trading_stop', 'close_position'];
            
            foreach ($commands as $command) {
                $commandIndex++;
                
                if (!is_array($command)) {
                    $result['errors'][] = "Command at index {$commandIndex} is not an array";
                    continue;
                }
                
                // P7.7: Required field: id (string)
                $id = $command['id'] ?? null;
                if (empty($id) || !is_string($id)) {
                    $result['errors'][] = "Command at index {$commandIndex} missing or invalid 'id' (string required)";
                    continue;
                }
                
                // P7.7: Required field: type (string, must be allowed)
                $type = $command['type'] ?? null;
                if (empty($type) || !is_string($type)) {
                    $result['errors'][] = "Command '{$id}' missing or invalid 'type' (string required)";
                    continue;
                }
                if (!in_array($type, $allowedTypes, true)) {
                    $result['errors'][] = "Command '{$id}' has invalid type '{$type}'. Allowed: " . implode(', ', $allowedTypes);
                    continue;
                }
                
                // P7.7: Required field: target (array with at least one valid key)
                $target = $command['target'] ?? null;
                if (!is_array($target)) {
                    $result['errors'][] = "Command '{$id}' missing or invalid 'target' (array required)";
                    continue;
                }
                
                // P7.7: Validate target has at least one of: trade_id, signal_id, (symbol + side)
                $hasTradeId = !empty($target['trade_id']) && is_string($target['trade_id']);
                $hasSignalId = !empty($target['signal_id']) && is_string($target['signal_id']);
                $hasSymbolSide = !empty($target['symbol']) && is_string($target['symbol']) 
                              && !empty($target['side']) && is_string($target['side'])
                              && in_array(strtolower($target['side']), ['long', 'short'], true);
                
                if (!$hasTradeId && !$hasSignalId && !$hasSymbolSide) {
                    $result['errors'][] = "Command '{$id}' has invalid target. Must have: trade_id, signal_id, or (symbol + side where side is 'long'|'short')";
                    continue;
                }
                
                // P7.7: Optional field: params (array, validate keys for set_trading_stop)
                $params = $command['params'] ?? [];
                if (!is_array($params)) {
                    $params = [];
                }
                
                // P7.7: For set_trading_stop, params are optional but should contain valid keys
                // Note: empty params is valid (might just want to clear stops)
                // This validation is informational - we log but don't reject
                
                // P7.7: Command passed all validations
                $validCommands[] = $command;
            }
            
            $result['count'] = count($validCommands);
            $result['commands'] = $validCommands;
            
        } catch (\Throwable $e) {
            $result['ok'] = false;
            $result['errors'][] = 'Exception: ' . $e->getMessage();
        }
        
        return $result;
    }
}

/* RULES
- Sources loads intents from Brain signals
- Schema validation is STRICT: signal MUST have schema_version
- Only clean_signal_v1 signals are processed
- Intent created with intent_live_v1 schema (internal)
- Uses atomic executed_index.json with flock
- Idempotency: signals are executed once only
- P7: Commands loaded from trading_commands_v1 schema
- P7.7: Command validation - type must be set_trading_stop|close_position
- P7.7: Target must have trade_id, signal_id, or (symbol + side)
- P7.7: Invalid commands are skipped (not fatal)
*/
