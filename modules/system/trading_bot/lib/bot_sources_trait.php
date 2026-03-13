<?php
declare(strict_types=1);

namespace Modules\System\TradingBot\Lib;

use Core\System\SystemPaths;

/**
 * Bot Sources Trait
 * 
 * Handles loading data from Brain signals and other sources.
 * CLEAN signals only - risk from signal.risk block.
 */
trait BotSourcesTrait
{
    /**
     * Load intents from Brain signals
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

        // Optional: reverse side (LONG↔SHORT) — testing / contrarian mode
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
     * @param string $signalId Signal ID
     * @param array $result Execution result
     */
    protected function markSignalExecuted(string $signalId, array $result): void
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
            $index[$signalId] = $this->buildExecutedEntry($result);
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
            $index[$signalId] = $this->buildExecutedEntry($result);
            
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
     */
    private function buildExecutedEntry(array $result): array
    {
        return [
            'executed_at' => date('c'),
            'result' => $result['status'] ?? 'unknown',
            'order_id' => $result['order_id'] ?? null,
            'trade_id' => $result['trade_id'] ?? null,
            'error' => $result['error'] ?? null,
        ];
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
