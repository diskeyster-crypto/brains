<?php

declare(strict_types=1);

use Core\System\SystemPaths;

/**
 * Parser6IntegrationTrait - Mode-aware operations for RAW/CLEAN
 * 
 * Methods for mode-specific storage operations.
 */
trait Parser6IntegrationTrait
{
    /**
     * Load executed index for a specific mode
     */
    private function loadExecutedIndexForMode(string $modeStorageBase): array
    {
        $path = $modeStorageBase . '/executed_index.json';
        if (!is_file($path)) {
            return ['completed' => [], 'updated_at' => null];
        }
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : ['completed' => [], 'updated_at' => null];
    }
    
    /**
     * Save executed index for a specific mode
     */
    private function saveExecutedIndexForMode(array $index, string $modeStorageBase): void
    {
        $path = $modeStorageBase . '/executed_index.json';
        $this->writeJsonAtomic($path, $index);
    }
    
    /**
     * Load active trades for a specific mode
     */
    private function loadActiveTradesForMode(string $modeStorageBase): array
    {
        $dir = $modeStorageBase . '/trades/active';
        if (!is_dir($dir)) {
            return [];
        }
        $trades = [];
        foreach (glob($dir . '/*.json') as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (is_array($data) && isset($data['trade_id'])) {
                $trades[$data['trade_id']] = $data;
            }
        }
        return $trades;
    }
    
    /**
     * Load closed trades for a specific mode
     */
    private function loadClosedTradesForMode(string $modeStorageBase): array
    {
        $dir = $modeStorageBase . '/trades/closed';
        if (!is_dir($dir)) {
            return [];
        }
        $trades = [];
        foreach (glob($dir . '/*.json') as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (is_array($data)) {
                $trades[] = $data;
            }
        }
        return $trades;
    }
    
    /**
     * Load rejected trades for a specific mode
     */
    private function loadRejectedTradesForMode(string $modeStorageBase): array
    {
        $dir = $modeStorageBase . '/trades/rejected';
        if (!is_dir($dir)) {
            return [];
        }
        $trades = [];
        foreach (glob($dir . '/*.json') as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (is_array($data)) {
                $trades[] = $data;
            }
        }
        return $trades;
    }
    
    /**
     * Get active symbols for a specific mode
     */
    private function getActiveSymbolsForMode(string $modeStorageBase): array
    {
        $activeTrades = $this->loadActiveTradesForMode($modeStorageBase);
        $symbols = [];
        foreach ($activeTrades as $trade) {
            $symbol = $trade['symbol'] ?? '';
            if ($symbol !== '') {
                $symbols[$symbol] = true;
            }
        }
        return $symbols;
    }
    
    /**
     * Save rejected trade for a specific mode
     */
    private function saveRejectedTradeForMode(array $trade, array $rejectResult, string $modeStorageBase): void
    {
        $tradeId = $trade['trade_id'] ?? '';
        
        $trade['status'] = 'rejected';
        $trade['reject_result'] = $rejectResult;
        $trade['reject_reason'] = $rejectResult['close_reason'] ?? 'unknown';
        $trade['rejected_at'] = date('c');
        
        $path = $modeStorageBase . '/trades/rejected/' . $tradeId . '.json';
        $this->ensureDir(dirname($path));
        $this->writeJsonAtomic($path, $trade);
    }
    
    /**
     * Delete active trade for a specific mode
     */
    private function deleteActiveTradeForMode(string $tradeId, string $modeStorageBase): void
    {
        $path = $modeStorageBase . '/trades/active/' . $tradeId . '.json';
        if (is_file($path)) {
            @unlink($path);
        }
    }
    
    /**
     * Rebuild rejected trades index for a specific mode storage directory
     * FIX-4.1: Mode-aware version that takes $baseDir parameter
     * Reads from $baseDir/trades/rejected/*.json
     * Writes: $baseDir/trades_rejected.json
     *
     * @param string $baseDir Mode-specific storage directory
     */
    private function rebuildRejectedIndexFromDirForMode(string $baseDir): void
    {
        $dir = $baseDir . '/trades/rejected';
        $path = $baseDir . '/trades_rejected.json';
        
        if (!is_dir($dir)) {
            return;
        }
        
        $files = glob($dir . '/*.json');
        if ($files === false || empty($files)) {
            $this->writeJsonAtomic($path, []);
            return;
        }
        
        $map = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $trade = json_decode($content, true);
            if ($trade && isset($trade['trade_id'])) {
                $map[$trade['trade_id']] = $trade;
            }
        }
        
        $result = array_values($map);
        usort($result, function($a, $b) {
            return ($b['rejected_ts'] ?? 0) <=> ($a['rejected_ts'] ?? 0);
        });
        
        if (count($result) > 1000) {
            $result = array_slice($result, 0, 1000);
        }
        
        $this->writeJsonAtomic($path, $result);
    }
    
    /**
     * Copy CLEAN mode artifacts to root storage for Brain compatibility
     * FIX-4.2: Ensures Brain UI sees valid simulation data
     * Copies: simulation.json, state.json, stats/summary.json, stats_global.json
     */
    private function copyCleanArtifactsToRoot(): void
    {
        $cleanBase = $this->storageDir . '/clean';
        
        // Files to copy from clean to root (expanded per P4.2-C)
        $filesToCopy = [
            '/simulation.json',
            '/state.json',
            '/last_run.json',
            '/executed_index.json',
            '/trades_rejected.json',
            '/stats_global.json',
            // Copy training datasets to root for Brain compatibility
            '/training_dataset.ndjson',
            '/training_trailing_sim.ndjson',
        ];
        
        foreach ($filesToCopy as $relPath) {
            $src = $cleanBase . $relPath;
            $dst = $this->storageDir . $relPath;
            if (is_file($src)) {
                $this->ensureDir(dirname($dst));
                copy($src, $dst);
            }
        }
        
        // Copy stats/summary.json separately (nested directory)
        $statsSrc = $cleanBase . '/stats/summary.json';
        $statsDst = $this->storageDir . '/stats/summary.json';
        if (is_file($statsSrc)) {
            $this->ensureDir(dirname($statsDst));
            copy($statsSrc, $statsDst);
        }
    }
    
    /**
     * Apply Brain management commands to active trades
     * Adjusts trailing.drawdown_factor based on Brain analysis
     * 
     * @param string $baseDir Mode-specific storage directory
     * @param array &$state Simulator state (modified in place)
     * @param int $now Current timestamp
     * @return array Counters for applied/expired/ignored commands
     */
    private function applyBrainManagementCommandsForMode(string $baseDir, array &$state, int $now): array
    {
        $counters = [
            'management_commands_loaded' => 0,
            'management_commands_applied' => 0,
            'management_commands_expired' => 0,
            'management_commands_ignored_not_found_trade' => 0,
        ];
        
        // Check if management is enabled
        $cfg = $this->config['management'] ?? [];
        if (!($cfg['enabled'] ?? true)) {
            return $counters;
        }
        
        // Load commands from Brain storage via SystemPaths - NO dirname() fallback
        $commandsStorageKey = $cfg['commands_storage_key'] ?? 'system.brain.storage';
        $commandsFilename = $cfg['commands_filename'] ?? 'management/commands.json';
        
        // Get Brain storage path from SystemPaths only
        $brainStoragePath = null;
        try {
            $brainStoragePath = SystemPaths::instance()->get($commandsStorageKey);
        } catch (\Throwable $e) {
            // Key not found - return without applying commands, add warning
            $counters['management_commands_path_key_missing'] = 1;
            return $counters;
        }
        
        if (!$brainStoragePath) {
            return $counters;
        }
        
        $commandsPath = $brainStoragePath . '/' . $commandsFilename;
        
        if (!is_file($commandsPath)) {
            return $counters;
        }
        
        $content = @file_get_contents($commandsPath);
        if ($content === false) {
            return $counters;
        }
        
        $data = @json_decode($content, true);
        if (!is_array($data)) {
            return $counters;
        }
        
        $commands = $data['commands'] ?? [];
        $generatedTs = $data['generated_ts_unix'] ?? 0;
        $ttlSec = $data['ttl_sec'] ?? 900;
        $expiresTs = $data['expires_ts_unix'] ?? ($generatedTs + $ttlSec);
        
        // Check if commands file is expired
        if ($expiresTs < $now) {
            $counters['management_commands_expired'] = count($commands);
            return $counters;
        }
        
        $counters['management_commands_loaded'] = count($commands);
        
        // Build trade index from files, NOT from state
        // Scan trades/active/*.json files directly
        $activeTradesDir = $baseDir . '/trades/active';
        $tradeIndex = [];
        
        if (is_dir($activeTradesDir)) {
            $files = glob($activeTradesDir . '/*.json') ?: [];
            foreach ($files as $tradeFile) {
                $content = @file_get_contents($tradeFile);
                if ($content === false) {
                    continue;
                }
                $trade = @json_decode($content, true);
                if (!is_array($trade)) {
                    continue;
                }
                $tradeId = $trade['trade_id'] ?? $trade['id'] ?? null;
                if ($tradeId) {
                    $tradeIndex[$tradeId] = [
                        'file' => $tradeFile,
                        'trade' => $trade,
                    ];
                }
            }
        }
        
        // Trailing limits from config management section
        $factorMin = (float)($cfg['drawdown_factor_min'] ?? 0.20);
        $factorMax = (float)($cfg['drawdown_factor_max'] ?? 0.80);
        
        // Process commands
        foreach ($commands as $cmd) {
            $tradeId = $cmd['trade_id'] ?? null;
            $action = $cmd['action'] ?? '';
            $value = $cmd['value'] ?? null;
            $cmdExpiresTs = $cmd['expires_ts_unix'] ?? 0;
            $reason = $cmd['reason'] ?? 'unknown';
            
            // Skip expired commands
            if ($cmdExpiresTs > 0 && $cmdExpiresTs < $now) {
                $counters['management_commands_expired']++;
                continue;
            }
            
            // Only support set_trailing_drawdown_factor action
            if ($action !== 'set_trailing_drawdown_factor') {
                continue;
            }
            
            // Find trade from file-based index
            if (!isset($tradeIndex[$tradeId])) {
                $counters['management_commands_ignored_not_found_trade']++;
                continue;
            }
            
            $tradeEntry = $tradeIndex[$tradeId];
            $trade = $tradeEntry['trade'];
            $tradeFile = $tradeEntry['file'];
            
            // Get old value
            $oldFactor = $trade['trailing']['drawdown_factor'] ?? 0.5;
            
            // Clamp new value
            $newFactor = max($factorMin, min($factorMax, (float)$value));
            
            // Apply change
            if (!isset($trade['trailing'])) {
                $trade['trailing'] = [];
            }
            $trade['trailing']['drawdown_factor'] = $newFactor;
            $trade['brain_management_last_ts'] = $now;
            
            // Add event
            if (!isset($trade['events'])) {
                $trade['events'] = [];
            }
            $trade['events'][] = [
                'type' => 'brain_management_applied',
                'ts' => $now,
                'ts_iso' => date('c', $now),
                'command_id' => $cmd['command_id'] ?? null,
                'reason' => $reason,
                'old_factor' => $oldFactor,
                'new_factor' => $newFactor,
                'expires_ts_unix' => $cmdExpiresTs,
            ];
            
            $counters['management_commands_applied']++;
            
            // Save updated trade back to its file
            $this->writeJsonAtomic($tradeFile, $trade);
        }
        
        return $counters;
    }
    
    /**
     * Get effective contract summary for last_run
     * Returns mode, signals_source, management counters, dataset counters
     * 
     * @param string $mode Mode name ('raw' or 'clean')
     * @param string $modeStorageBase Mode storage directory
     * @param array $managementCounters Management command counters
     * @return array Effective contract summary
     */
    public function getEffectiveContract(string $mode, string $modeStorageBase, array $managementCounters = []): array
    {
        $contract = [
            'mode' => $mode,
            'signals_source' => $mode === 'clean' ? 'brain_clean' : 'parser5_raw',
            'management_commands_loaded' => $managementCounters['management_commands_loaded'] ?? 0,
            'management_commands_applied' => $managementCounters['management_commands_applied'] ?? 0,
            'management_commands_expired' => $managementCounters['management_commands_expired'] ?? 0,
            'management_commands_ignored_not_found_trade' => $managementCounters['management_commands_ignored_not_found_trade'] ?? 0,
            'dataset_training_appended_count' => 0,
            'dataset_trailing_appended_count' => 0,
        ];
        
        // Count lines in training datasets
        $trainingDatasetPath = $modeStorageBase . '/training_dataset.ndjson';
        if (is_file($trainingDatasetPath)) {
            $lineCount = 0;
            $fp = @fopen($trainingDatasetPath, 'r');
            if ($fp) {
                while (fgets($fp) !== false) {
                    $lineCount++;
                }
                fclose($fp);
            }
            $contract['dataset_training_appended_count'] = $lineCount;
        }
        
        $trailingDatasetPath = $modeStorageBase . '/training_trailing_sim.ndjson';
        if (is_file($trailingDatasetPath)) {
            $lineCount = 0;
            $fp = @fopen($trailingDatasetPath, 'r');
            if ($fp) {
                while (fgets($fp) !== false) {
                    $lineCount++;
                }
                fclose($fp);
            }
            $contract['dataset_trailing_appended_count'] = $lineCount;
        }
        
        return $contract;
    }
    
    /**
     * Run smoke check for Parser6 Simulator
     * Validates datasets exist and trade records have required fields
     * 
     * @param string $modeStorageBase Mode storage directory (defaults to clean)
     * @return array Smoke check result
     */
    public function runSmokeCheck(?string $modeStorageBase = null): array
    {
        $result = [
            'ok' => true,
            'issues' => [],
            'datasets' => [],
            'trades_validated' => 0,
            'trades_with_issues' => 0,
        ];
        
        // Default to clean mode
        if ($modeStorageBase === null) {
            $modeStorageBase = $this->storageDir . '/clean';
        }
        
        // Check state.json exists
        $stateFile = $modeStorageBase . '/state.json';
        if (!is_file($stateFile)) {
            $result['issues'][] = 'state.json not found at ' . $stateFile;
            // Try module root
            $stateFile = $this->storageDir . '/state.json';
            if (!is_file($stateFile)) {
                $result['ok'] = false;
                $result['issues'][] = 'state.json not found in module storage either';
            }
        }
        
        // Check stats/summary.json exists
        $summaryFile = $modeStorageBase . '/stats/summary.json';
        if (!is_file($summaryFile)) {
            $result['issues'][] = 'stats/summary.json not found (OK if no trades yet)';
        }
        
        // Check training datasets
        $trainingDataset = $modeStorageBase . '/training_dataset.ndjson';
        $trailingDataset = $modeStorageBase . '/training_trailing_sim.ndjson';
        
        $result['datasets']['training_dataset_exists'] = is_file($trainingDataset);
        $result['datasets']['training_trailing_sim_exists'] = is_file($trailingDataset);
        
        if (!$result['datasets']['training_dataset_exists']) {
            $result['issues'][] = 'training_dataset.ndjson not found (OK if no closed trades yet)';
        }
        if (!$result['datasets']['training_trailing_sim_exists']) {
            $result['issues'][] = 'training_trailing_sim.ndjson not found (OK if no closed trades yet)';
        }
        
        // Check last 5 closed trades for required fields
        $closedTradesDir = $modeStorageBase . '/trades/closed';
        if (is_dir($closedTradesDir)) {
            $tradeFiles = glob($closedTradesDir . '/*.json') ?: [];
            // Sort by modification time, newest first
            usort($tradeFiles, fn($a, $b) => filemtime($b) <=> filemtime($a));
            $tradeFiles = array_slice($tradeFiles, 0, 5);
            
            foreach ($tradeFiles as $tradeFile) {
                $result['trades_validated']++;
                $trade = @json_decode(file_get_contents($tradeFile), true);
                if (!is_array($trade)) {
                    $result['ok'] = false;
                    $result['issues'][] = 'Invalid JSON in trade file: ' . basename($tradeFile);
                    $result['trades_with_issues']++;
                    continue;
                }
                
                $tradeId = $trade['trade_id'] ?? basename($tradeFile, '.json');
                $tradeIssues = [];
                
                // Check schema_version
                if (($trade['schema_version'] ?? null) !== 'sim_trade_v1') {
                    $tradeIssues[] = 'missing schema_version=sim_trade_v1';
                }
                
                // Check pnl_net_usdt (should be in close_result or top level)
                if (!isset($trade['pnl_net_usdt']) && !isset($trade['close_result']['pnl_net_usdt'])) {
                    $tradeIssues[] = 'missing pnl_net_usdt';
                }
                
                // Check fees_total_usdt
                if (!isset($trade['fees_total_usdt']) && !isset($trade['close_result']['fees_total_usdt'])) {
                    $tradeIssues[] = 'missing fees_total_usdt';
                }
                
                // Check trailing.mode and trailing.drawdown_factor
                $trailing = $trade['trailing'] ?? [];
                if (!isset($trailing['mode'])) {
                    $tradeIssues[] = 'missing trailing.mode';
                }
                if (!isset($trailing['drawdown_factor'])) {
                    $tradeIssues[] = 'missing trailing.drawdown_factor';
                }
                
                if (!empty($tradeIssues)) {
                    $result['trades_with_issues']++;
                    $result['issues'][] = "Trade {$tradeId}: " . implode(', ', $tradeIssues);
                }
            }
        }
        
        return $result;
    }

    /**
     * Open new trade for a specific mode
     */
    private function openNewTradeForMode(array $signal, int $nowTs, string $modeStorageBase): ?array
    {
        $tradeId = $signal['id'] ?? $this->generateSignalId($signal);
        $symbol = $signal['symbol'] ?? '';
        $side = strtolower($signal['side'] ?? 'long');
        
        // Get live price
        $livePrice = $this->fetchLivePriceFromBybit($symbol);
        if ($livePrice === null) {
            $this->errors[] = "live_price_unavailable: $symbol";
            return null;
        }
        
        // Check reject_late (ТЗ Task C) - only for enter_now signals with late_threshold
        $entryAction = $signal['entry_action'] ?? 'enter_now';
        $lateThreshold = $signal['late_threshold_pct'] ?? null;
        $entryPrice = (float)($signal['entry_price'] ?? $livePrice);
        
        if ($entryAction === 'enter_now' && $lateThreshold !== null && $lateThreshold > 0) {
            $thresholdMult = 1 + ($lateThreshold / 100);
            $thresholdMultDown = 1 - ($lateThreshold / 100);
            
            if ($side === 'long' && $livePrice > ($entryPrice * $thresholdMult)) {
                $this->rejections[] = "reject_late: $symbol livePrice={$livePrice} > entry+threshold";
                $rejectTrade = [
                    'trade_id' => $tradeId,
                    'signal_id' => $tradeId,
                    'symbol' => $symbol,
                    'side' => $side,
                    'entry_action' => 'reject_late',
                    'entry_action_applied' => 'rejected_late',
                    'reject_reason' => 'reject_late_entry',
                    'live_price' => $livePrice,
                    'entry_price' => $entryPrice,
                    'late_threshold_pct' => $lateThreshold,
                    'rejected_at' => date('c'),
                    'rejected_ts' => $nowTs,
                ];
                $this->saveRejectedTradeForMode($rejectTrade, ['close_reason' => 'reject_late'], $modeStorageBase);
                return null;
            }
            if ($side === 'short' && $livePrice < ($entryPrice * $thresholdMultDown)) {
                $this->rejections[] = "reject_late: $symbol livePrice={$livePrice} < entry-threshold";
                $rejectTrade = [
                    'trade_id' => $tradeId,
                    'signal_id' => $tradeId,
                    'symbol' => $symbol,
                    'side' => $side,
                    'entry_action' => 'reject_late',
                    'entry_action_applied' => 'rejected_late',
                    'reject_reason' => 'reject_late_entry',
                    'live_price' => $livePrice,
                    'entry_price' => $entryPrice,
                    'late_threshold_pct' => $lateThreshold,
                    'rejected_at' => date('c'),
                    'rejected_ts' => $nowTs,
                ];
                $this->saveRejectedTradeForMode($rejectTrade, ['close_reason' => 'reject_late'], $modeStorageBase);
                return null;
            }
        }
        
        // Determine strict entry based on entry_action
        $strictEntry = $entryAction === 'wait_retrace';
        $entryActionApplied = $strictEntry ? 'waiting_for_entry_touch' : 'opened_immediately';
        
        // C1.2: Brain is the ONLY source of risk parameters - NO DEFAULTS
        // CLEAN mode: risk comes from signal.risk (strict validation)
        // RAW mode: risk comes from active Brain profile
        $signalRisk = $signal['risk'] ?? null;
        $profileId = $signal['profile_id'] ?? null;
        $riskSource = null;
        $riskBlock = [];
        $riskValidationErrors = [];
        
        if ($signalRisk !== null) {
            // C1.2: CLEAN mode - validate signal.risk block
            $riskSource = 'brain_signal';
            $riskBlock = $signalRisk;
            $riskValidationErrors = $this->validateRiskBlock($signalRisk);
            
            if (!empty($riskValidationErrors)) {
                // C1.2: reject_invalid_risk_block
                $this->rejections[] = "reject_invalid_risk_block: {$symbol} - " . implode(', ', $riskValidationErrors);
                $rejectTrade = [
                    'trade_id' => $tradeId,
                    'signal_id' => $tradeId,
                    'symbol' => $symbol,
                    'side' => $side,
                    'reject_reason' => 'reject_invalid_risk_block',
                    'reject_details' => implode(', ', $riskValidationErrors),
                    'rejected_at' => date('c'),
                    'rejected_ts' => $nowTs,
                ];
                $this->saveRejectedTradeForMode($rejectTrade, ['close_reason' => 'reject_invalid_risk_block'], $modeStorageBase);
                return null;
            }
            
            // Extract values from validated signal.risk
            $budget = (float)$signalRisk['budget_usdt_per_trade'];
            $leverage = (int)$signalRisk['leverage'];
            $stopFromLiqPct = (float)$signalRisk['stop_from_liq_range_pct'];
            $slippageBps = (int)$signalRisk['slippage_bps'];
            $feesBps = (int)$signalRisk['fees_bps'];
            $trailingConfig = $signalRisk['trailing'];
            $tpConfig = $signalRisk['take_profit'] ?? ['enabled' => false, 'roi_pct' => null];
            if ($profileId === null) {
                $profileId = $signalRisk['profile_id'] ?? 'brain_signal';
            }
        } else {
            // C1.2: RAW mode - Use active Brain profile (no defaults!)
            $brainRisk = $this->loadBrainActiveRiskProfile();
            
            // C1.2: RAW mode MUST have valid Brain risk profile - reject if fallback
            if (!empty($brainRisk['is_fallback'])) {
                $this->rejections[] = "reject_missing_active_risk_profile: {$symbol} - RAW mode requires active Brain risk profile";
                $rejectTrade = [
                    'trade_id' => $tradeId,
                    'signal_id' => $tradeId,
                    'symbol' => $symbol,
                    'side' => $side,
                    'reject_reason' => 'reject_missing_active_risk_profile',
                    'reject_details' => 'RAW mode requires active Brain risk profile but none was found',
                    'rejected_at' => date('c'),
                    'rejected_ts' => $nowTs,
                ];
                $this->saveRejectedTradeForMode($rejectTrade, ['close_reason' => 'reject_missing_active_risk_profile'], $modeStorageBase);
                return null;
            }
            
            // C1.2: Validate Brain profile risk block
            $riskValidationErrors = $this->validateRiskBlock($brainRisk);
            
            if (!empty($riskValidationErrors)) {
                // C1.2: reject_invalid_active_risk_profile
                $this->rejections[] = "reject_invalid_active_risk_profile: {$symbol} - " . implode(', ', $riskValidationErrors);
                $rejectTrade = [
                    'trade_id' => $tradeId,
                    'signal_id' => $tradeId,
                    'symbol' => $symbol,
                    'side' => $side,
                    'reject_reason' => 'reject_invalid_active_risk_profile',
                    'reject_details' => implode(', ', $riskValidationErrors),
                    'rejected_at' => date('c'),
                    'rejected_ts' => $nowTs,
                ];
                $this->saveRejectedTradeForMode($rejectTrade, ['close_reason' => 'reject_invalid_active_risk_profile'], $modeStorageBase);
                return null;
            }
            
            $riskSource = 'brain_active_profile';
            $riskBlock = $brainRisk;
            $profileId = $brainRisk['profile_id'] ?? 'brain_active';
            $budget = (float)$brainRisk['budget_usdt_per_trade'];
            $leverage = (int)$brainRisk['leverage'];
            $stopFromLiqPct = (float)$brainRisk['stop_from_liq_range_pct'];
            $slippageBps = (int)$brainRisk['slippage_bps'];
            $feesBps = (int)$brainRisk['fees_bps'];
            $trailingConfig = $brainRisk['trailing'];
            $tpConfig = $brainRisk['take_profit'] ?? ['enabled' => false, 'roi_pct' => null];
        }
        
        // Per ТЗ: Slippage (MARKET order, adverse direction)
        $slippagePct = $slippageBps / 10000.0;
        $livePriceNoSlippage = $livePrice;
        
        if ($side === 'long') {
            // LONG buy: price moves up against us
            $fillEntryPrice = $livePrice * (1 + $slippagePct);
        } else {
            // SHORT sell: price moves down against us
            $fillEntryPrice = $livePrice * (1 - $slippagePct);
        }
        
        // Per ТЗ: Calculate position size
        $marginUsdt = $budget;
        $notionalUsdt = $marginUsdt * $leverage;
        $qty = $notionalUsdt / $fillEntryPrice;
        
        // Per ТЗ: Calculate liquidation price (v1 approximation for simulator)
        if ($side === 'long') {
            $liqPrice = $fillEntryPrice * (1 - 1.0 / $leverage);
        } else {
            $liqPrice = $fillEntryPrice * (1 + 1.0 / $leverage);
        }
        
        // Per ТЗ: Calculate SL from stop_from_liq_range_pct
        $entryLiqRange = abs($fillEntryPrice - $liqPrice);
        $slRangePct = $stopFromLiqPct / 100.0;
        
        // Initialize events array for SL clamping logging
        $slEvents = [];
        
        if ($side === 'long') {
            // LONG: SL below entry, towards liq
            $slPriceInitial = $fillEntryPrice - ($entryLiqRange * $slRangePct);
        } else {
            // SHORT: SL above entry, towards liq
            $slPriceInitial = $fillEntryPrice + ($entryLiqRange * $slRangePct);
        }
        
        // STEP 1.1: Use dynamic offset and clamp SL to valid range
        $slPriceInitial = $this->clampSlPriceToValidRange(
            $slPriceInitial,
            $fillEntryPrice,
            $liqPrice,
            $side,
            $slEvents
        );
        
        // Per ТЗ: Calculate TP from roi_pct if enabled
        $tpPrice = null;
        if ($tpConfig['enabled'] && isset($tpConfig['roi_pct']) && $tpConfig['roi_pct'] > 0) {
            $tpRoiPct = (float)$tpConfig['roi_pct'];
            // ROI on margin = (price_move_pct * leverage)
            // price_move_pct = tp_roi_pct / leverage
            $movePct = $tpRoiPct / $leverage / 100.0;
            
            if ($side === 'long') {
                $tpPrice = $fillEntryPrice * (1 + $movePct);
            } else {
                $tpPrice = $fillEntryPrice * (1 - $movePct);
            }
        }
        
        // Use profile TP if set, otherwise use signal TP (with slippage consideration for fills)
        $signalTp = (float)($signal['take_profit'] ?? 0);
        $effectiveTpPrice = $tpPrice ?? ($signalTp > 0 ? $signalTp : null);
        
        // Override signal SL with profile-calculated SL (per ТЗ: profile SL overrides signal SL in simulator)
        $slPriceCurrent = $slPriceInitial;
        
        // Build risk record for trade - must contain FULL risk-block v1
        // Source: $riskBlock which comes from signal.risk (CLEAN) or risk_active.json (RAW)
        $riskRecord = [
            'profile_id' => $profileId,
            'budget_usdt_per_trade' => $budget,
            'leverage' => $leverage,
            'stop_from_liq_range_pct' => $stopFromLiqPct,
            'slippage_bps' => $slippageBps,
            'fees_bps' => $feesBps, // STEP 1.3: Fee bps for trade
            'order_type' => $riskBlock['order_type'], // P2.1: no fallback, risk already validated
            'trailing' => $trailingConfig,
            'take_profit' => $tpConfig,
            'limits' => $riskBlock['limits'] ?? [], // P0.1: MUST include limits for full risk-block v1
        ];
        
        // C-5: Calculate risk_hash for tracking risk block integrity
        $riskHash = sha1(json_encode($riskRecord));
        
        // Build trade record
        // STEP 7: Add schema_version for sim_trade_v1 contract
        $trade = [
            'schema_version' => 'sim_trade_v1',  // STEP 7: Trade record schema version
            'trade_id' => $tradeId,
            'signal_id' => $tradeId,
            'symbol' => $symbol,
            'side' => $side,
            'status' => $strictEntry ? 'pending_entry' : 'open',
            'entry_action' => $entryAction,
            'entry_action_applied' => $entryActionApplied,
            'entry_timeout_minutes_signal' => $signal['entry_timeout_minutes'] ?? null,
            'entry_timeout_minutes_effective' => $signal['entry_timeout_minutes'] 
                ?? ($this->config['execution']['entry_timeout_minutes'] ?? 10),
            'entry_timeout_source' => isset($signal['entry_timeout_minutes']) ? 'brain' : 'config',
            'entry_timeout_deadline_ts' => $nowTs + (($signal['entry_timeout_minutes'] 
                ?? ($this->config['execution']['entry_timeout_minutes'] ?? 10)) * 60),
            'late_threshold_pct' => $lateThreshold,
            'created_ts' => $nowTs,
            'created_at' => date('c'),
            
            // Per ТЗ: Risk Profile v1 fields
            'profile_id' => $profileId,
            'risk' => $riskRecord,
            // C-5: Track risk source and hash for audit trail
            'risk_source' => $riskSource,
            'risk_hash' => $riskHash,
            
            'entry' => [
                'target_price' => $entryPrice,
                'opened_price' => $strictEntry ? null : $fillEntryPrice,
                'opened_price_no_slippage' => $strictEntry ? null : $livePriceNoSlippage,
                'opened_ts' => $strictEntry ? null : $nowTs,
                'opened_at' => $strictEntry ? null : date('c'),
                'budget_usdt' => $marginUsdt,
                'leverage' => $leverage,
                'qty' => $qty,
                'margin_usdt' => $marginUsdt,
                'notional_usdt' => $notionalUsdt,
                'slippage_bps' => $slippageBps,
            ],
            
            // Per ТЗ: Liquidation and SL fields
            'liq_price' => $liqPrice,
            'sl_price_initial' => $slPriceInitial,
            'sl_price_current' => $slPriceCurrent,
            
            'targets' => [
                'take_profit' => $effectiveTpPrice,
                'take_profit_enabled' => $tpConfig['enabled'] ?? false,
                'take_profit_roi_pct' => $tpConfig['roi_pct'] ?? null,
                'stop_loss_original' => (float)($signal['stop_loss'] ?? 0),
                'stop_loss_current' => $slPriceCurrent,
            ],
            
            // Per ТЗ: Smart Trailing fields
            // STEP 2: Include mode and drawdown_factor from signal risk
            'trailing' => [
                'enabled' => $trailingConfig['enabled'] ?? false,
                'activation_roi_pct' => $trailingConfig['activation_roi_pct'] ?? 6,
                'mode' => $trailingConfig['mode'] ?? 'normal',                    // STEP 2
                'drawdown_factor' => (float)($trailingConfig['drawdown_factor'] ?? 0.50), // STEP 2
                'active' => false,
                'activated_ts' => null,
                'activated_at' => null,
                'peak_roi_pct' => 0.0,
                'peak_price' => null,
                'sl_trailing_last' => null,
            ],
            
            'signal_score' => (float)($signal['score'] ?? 0),
            'signal_confirmations' => (int)($signal['confirmations'] ?? 0),
            
            // C2.2: Market data for UI (updated on each tick)
            'market' => [
                'last_price' => $strictEntry ? null : $livePrice,
                'last_ts_unix' => $strictEntry ? null : $nowTs,
            ],
            
            // C2.2: PnL fields with explicit naming for UI
            'pnl' => [
                'unrealized' => 0,
                'realized' => 0,
                'max_drawdown_roi' => 0,
                'max_favorable_roi' => 0,
                'roi_margin' => 0,
                // C2.2: Explicit fields for UI display
                'roi_unrealized_pct' => 0.0,        // ROI as percentage (e.g., 2.5 for 2.5%)
                'pnl_unrealized_usdt' => 0.0,       // PnL in USDT
            ],
            'events' => array_merge([
                [
                    'ts' => date('c'),
                    'type' => $strictEntry ? 'created_pending' : 'opened',
                    'price' => $livePrice,
                    'fill_price' => $fillEntryPrice,
                    'slippage_bps' => $slippageBps,
                    'entry_action_applied' => $entryActionApplied,
                ],
            ], $slEvents), // Include any SL clamping events
        ];
        
        // Save trade
        $tradePath = $modeStorageBase . '/trades/active/' . $tradeId . '.json';
        $this->ensureDir(dirname($tradePath));
        $this->writeJsonAtomic($tradePath, $trade);
        
        return $trade;
    }
    /**
     * Update active trade for a specific mode
     * Per ТЗ: Risk Profile v1 + Smart Trailing implementation
     */
    private function updateActiveTradeForMode(array $trade, int $nowTs, string $modeStorageBase): array
    {
        $tradeId = $trade['trade_id'] ?? '';
        $symbol = $trade['symbol'] ?? '';
        $side = strtolower($trade['side'] ?? 'long');
        $status = $trade['status'] ?? 'open';
        
        $livePrice = $this->fetchLivePriceFromBybit($symbol);
        if ($livePrice === null) {
            return ['status' => 'active', 'reason' => 'price_unavailable'];
        }
        
        // Handle pending entry (wait_retrace)
        if ($status === 'pending_entry') {
            $targetEntry = (float)($trade['entry']['target_price'] ?? 0);
            $entryTimeoutDeadline = (int)($trade['entry_timeout_deadline_ts'] ?? 0);
            
            // Check entry timeout
            if ($entryTimeoutDeadline > 0 && $nowTs > $entryTimeoutDeadline) {
                // Update entry_action_applied before rejecting
                $trade['entry_action_applied'] = 'timeout_wait_entry';
                $trade['events'][] = [
                    'ts' => date('c'),
                    'type' => 'entry_timeout',
                    'entry_action_applied' => 'timeout_wait_entry',
                ];
                $tradePath = $modeStorageBase . '/trades/active/' . $tradeId . '.json';
                $this->writeJsonAtomic($tradePath, $trade);
                
                return [
                    'status' => 'rejected',
                    'close_reason' => 'reject_entry_not_reached',
                    'closed_at' => date('c'),
                    'closed_ts' => $nowTs,
                    'trade' => $trade,
                ];
            }
            
            // Check if entry price touched
            $entryTouched = ($side === 'long' && $livePrice <= $targetEntry) 
                || ($side === 'short' && $livePrice >= $targetEntry);
            
            if ($entryTouched) {
                // Per ТЗ: Apply slippage on entry fill
                $slippageBps = (int)($trade['risk']['slippage_bps'] ?? $trade['entry']['slippage_bps'] ?? 20);
                $slippagePct = $slippageBps / 10000.0;
                
                if ($side === 'long') {
                    $fillPrice = $livePrice * (1 + $slippagePct);
                } else {
                    $fillPrice = $livePrice * (1 - $slippagePct);
                }
                
                $trade['status'] = 'open';
                $trade['entry']['opened_price'] = $fillPrice;
                $trade['entry']['opened_price_no_slippage'] = $livePrice;
                $trade['entry']['opened_ts'] = $nowTs;
                $trade['entry']['opened_at'] = date('c');
                $trade['entry_action_applied'] = 'opened_by_entry_touch';
                
                // Recalculate position based on fill price
                $budget = (float)($trade['entry']['budget_usdt'] ?? $trade['entry']['margin_usdt'] ?? 50);
                $leverage = (int)($trade['entry']['leverage'] ?? 10);
                $trade['entry']['qty'] = ($budget * $leverage) / $fillPrice;
                $trade['entry']['notional_usdt'] = $budget * $leverage;
                
                // Recalculate liquidation price based on fill
                if ($side === 'long') {
                    $trade['liq_price'] = $fillPrice * (1 - 1.0 / $leverage);
                } else {
                    $trade['liq_price'] = $fillPrice * (1 + 1.0 / $leverage);
                }
                
                // Recalculate SL based on new entry price
                $stopFromLiqPct = (float)($trade['risk']['stop_from_liq_range_pct'] ?? 20);
                $entryLiqRange = abs($fillPrice - $trade['liq_price']);
                $slRangePct = $stopFromLiqPct / 100.0;
                
                if ($side === 'long') {
                    $newSlPrice = $fillPrice - ($entryLiqRange * $slRangePct);
                } else {
                    $newSlPrice = $fillPrice + ($entryLiqRange * $slRangePct);
                }
                
                // STEP 1.1: Use dynamic offset and clamp SL to valid range
                $slClampEvents = [];
                $newSlPrice = $this->clampSlPriceToValidRange(
                    $newSlPrice,
                    $fillPrice,
                    $trade['liq_price'],
                    $side,
                    $slClampEvents
                );
                
                $trade['sl_price_initial'] = $newSlPrice;
                $trade['sl_price_current'] = $newSlPrice;
                $trade['targets']['stop_loss_current'] = $newSlPrice;
                
                $trade['events'][] = [
                    'ts' => date('c'),
                    'type' => 'opened',
                    'price' => $livePrice,
                    'fill_price' => $fillPrice,
                    'slippage_bps' => $slippageBps,
                    'entry_action_applied' => 'opened_by_entry_touch',
                ];
                
                // Add any SL clamping events
                foreach ($slClampEvents as $clampEvent) {
                    $trade['events'][] = $clampEvent;
                }
                
                $tradePath = $modeStorageBase . '/trades/active/' . $tradeId . '.json';
                $this->writeJsonAtomic($tradePath, $trade);
            }
            
            return ['status' => 'active'];
        }
        
        // Trade is open, check TP/SL and update trailing
        $entryPrice = (float)($trade['entry']['opened_price'] ?? 0);
        $tp = (float)($trade['targets']['take_profit'] ?? 0);
        $slCurrent = (float)($trade['sl_price_current'] ?? $trade['targets']['stop_loss_current'] ?? 0);
        $leverage = (int)($trade['entry']['leverage'] ?? $trade['risk']['leverage'] ?? 10);
        $budget = (float)($trade['entry']['budget_usdt'] ?? $trade['entry']['margin_usdt'] ?? 50);
        
        // Calculate ROI on margin (unrealized)
        $priceMoveRatio = $side === 'long' 
            ? ($livePrice - $entryPrice) / $entryPrice
            : ($entryPrice - $livePrice) / $entryPrice;
        $roiMarginPct = $priceMoveRatio * $leverage * 100; // ROI as percentage
        
        // C2.2: Calculate PnL in USDT
        $pnlUnrealizedUsdt = $budget * ($roiMarginPct / 100);
        
        // C2.2: Update market data for UI
        $trade['market'] = [
            'last_price' => $livePrice,
            'last_ts_unix' => $nowTs,
        ];
        
        // Update PnL fields
        $trade['pnl']['unrealized'] = $priceMoveRatio;
        $trade['pnl']['roi_margin'] = $roiMarginPct;
        // C2.2: Explicit fields for UI display
        $trade['pnl']['roi_unrealized_pct'] = $roiMarginPct;
        $trade['pnl']['pnl_unrealized_usdt'] = $pnlUnrealizedUsdt;
        
        if ($priceMoveRatio < ($trade['pnl']['max_drawdown_roi'] ?? 0)) {
            $trade['pnl']['max_drawdown_roi'] = $priceMoveRatio;
        }
        if ($priceMoveRatio > ($trade['pnl']['max_favorable_roi'] ?? 0)) {
            $trade['pnl']['max_favorable_roi'] = $priceMoveRatio;
        }
        
        // Per ТЗ: Smart Trailing Stop Logic
        $trailingConfig = $trade['trailing'] ?? [];
        $trailingEnabled = $trailingConfig['enabled'] ?? false;
        $activationRoiPct = (float)($trailingConfig['activation_roi_pct'] ?? 6);
        $trailingActive = $trailingConfig['active'] ?? false;
        
        if ($trailingEnabled) {
            // Check if trailing should activate
            if (!$trailingActive && $roiMarginPct >= $activationRoiPct) {
                // Activate trailing
                $trade['trailing']['active'] = true;
                $trade['trailing']['activated_ts'] = $nowTs;
                $trade['trailing']['activated_at'] = date('c');
                $trade['trailing']['peak_roi_pct'] = $roiMarginPct;
                $trade['trailing']['peak_price'] = $livePrice;
                // STEP 3: Initialize min_roi_after_activation_pct when trailing activates
                $trade['trailing']['min_roi_after_activation_pct'] = $roiMarginPct;
                $trade['trailing']['roi_at_activation'] = $roiMarginPct;
                $trade['trailing']['sl_updates_count'] = 0;
                $trailingActive = true;
                
                $trade['events'][] = [
                    'ts' => date('c'),
                    'type' => 'trailing_activated',
                    'roi_pct' => $roiMarginPct,
                    'price' => $livePrice,
                    'activation_threshold' => $activationRoiPct,
                    // STEP 2: Include mode and factor in activation event
                    'mode' => $trailingConfig['mode'] ?? 'normal',
                    'drawdown_factor' => (float)($trailingConfig['drawdown_factor'] ?? 0.50),
                ];
            }
            
            // If trailing is active, update trailing stop
            if ($trailingActive) {
                $peakRoiPct = (float)($trade['trailing']['peak_roi_pct'] ?? $roiMarginPct);
                
                // STEP 3: Track minimum ROI after activation (for worst drawdown calculation)
                // This field should exist if trailing activated properly, but use current ROI as safe fallback
                $minRoiAfterActivation = $trade['trailing']['min_roi_after_activation_pct'] ?? null;
                if ($minRoiAfterActivation === null) {
                    // Initialize if missing (defensive code)
                    $trade['trailing']['min_roi_after_activation_pct'] = $roiMarginPct;
                    $minRoiAfterActivation = $roiMarginPct;
                } elseif ($roiMarginPct < $minRoiAfterActivation) {
                    $trade['trailing']['min_roi_after_activation_pct'] = $roiMarginPct;
                }
                
                // Update peak if new high
                if ($roiMarginPct > $peakRoiPct) {
                    $trade['trailing']['peak_roi_pct'] = $roiMarginPct;
                    $trade['trailing']['peak_price'] = $livePrice;
                    $peakRoiPct = $roiMarginPct;
                }
                
                // STEP 2: Get drawdown factor from trade (priority) or risk block or defaults
                $drawdownFactor = (float)($trade['trailing']['drawdown_factor'] 
                    ?? $trade['risk']['trailing']['drawdown_factor'] 
                    ?? 0.50);
                
                // Per ТЗ: Calculate protected ROI (smart trailing algorithm v1)
                // STEP 2: protected_roi = peak_roi - (activation_roi * drawdown_factor)
                $protectedRoiPct = $peakRoiPct - ($activationRoiPct * $drawdownFactor);
                
                // STEP 2: Clamp protected_roi to >= 0 (sanity check)
                if ($protectedRoiPct < 0) {
                    $protectedRoiPct = 0;
                }
                
                // Convert protected ROI to trailing SL price
                // protected_move_pct = protected_roi / leverage
                $protectedMovePct = $protectedRoiPct / $leverage / 100.0;
                
                // Get trailing mode for event logging
                $trailingMode = $trailingConfig['mode'] ?? 'normal';
                
                if ($side === 'long') {
                    $slTrailing = $entryPrice * (1 + $protectedMovePct);
                    // SL only tightens (moves up for long)
                    if ($slTrailing > $slCurrent) {
                        $trade['sl_price_current'] = $slTrailing;
                        $trade['targets']['stop_loss_current'] = $slTrailing;
                        $trade['trailing']['sl_trailing_last'] = $slTrailing;
                        // STEP 3: Increment SL updates count
                        $trade['trailing']['sl_updates_count'] = ($trade['trailing']['sl_updates_count'] ?? 0) + 1;
                        
                        $trade['events'][] = [
                            'ts' => date('c'),
                            'type' => 'trailing_updated',
                            'peak_roi_pct' => $peakRoiPct,
                            'protected_roi_pct' => $protectedRoiPct,
                            'sl_trailing' => $slTrailing,
                            'sl_previous' => $slCurrent,
                            // STEP 2: Add mode and factor to event
                            'mode' => $trailingMode,
                            'drawdown_factor' => $drawdownFactor,
                        ];
                        
                        $slCurrent = $slTrailing;
                    }
                } else {
                    $slTrailing = $entryPrice * (1 - $protectedMovePct);
                    // SL only tightens (moves down for short)
                    if ($slTrailing < $slCurrent || $slCurrent <= 0) {
                        $trade['sl_price_current'] = $slTrailing;
                        $trade['targets']['stop_loss_current'] = $slTrailing;
                        $trade['trailing']['sl_trailing_last'] = $slTrailing;
                        // STEP 3: Increment SL updates count
                        $trade['trailing']['sl_updates_count'] = ($trade['trailing']['sl_updates_count'] ?? 0) + 1;
                        
                        $trade['events'][] = [
                            'ts' => date('c'),
                            'type' => 'trailing_updated',
                            'peak_roi_pct' => $peakRoiPct,
                            'protected_roi_pct' => $protectedRoiPct,
                            'sl_trailing' => $slTrailing,
                            'sl_previous' => $slCurrent,
                            // STEP 2: Add mode and factor to event
                            'mode' => $trailingMode,
                            'drawdown_factor' => $drawdownFactor,
                        ];
                        
                        $slCurrent = $slTrailing;
                    }
                }
            }
        }
        
        // Check TP hit (only if TP enabled)
        $tpEnabled = $trade['targets']['take_profit_enabled'] ?? ($tp > 0);
        $tpHit = $tpEnabled && ($tp > 0) && (
            ($side === 'long' && $livePrice >= $tp) ||
            ($side === 'short' && $livePrice <= $tp)
        );
        
        // Check SL hit
        $slHit = ($slCurrent > 0) && (
            ($side === 'long' && $livePrice <= $slCurrent) ||
            ($side === 'short' && $livePrice >= $slCurrent)
        );
        
        // Determine close reason
        $closeReason = null;
        if ($tpHit) {
            $closeReason = 'take_profit';
        } elseif ($slHit) {
            // Distinguish between trailing stop and regular stop loss
            $closeReason = $trailingActive ? 'trailing_stop' : 'stop_loss';
        }
        
        if ($closeReason !== null) {
            return $this->buildCloseResult($trade, $livePrice, $nowTs, $closeReason, $modeStorageBase);
        }
        
        // Update trade file
        $tradePath = $modeStorageBase . '/trades/active/' . $tradeId . '.json';
        $this->writeJsonAtomic($tradePath, $trade);
        
        return ['status' => 'active'];
    }
    
    /**
     * Build close result for a trade
     * This is a mode-aware wrapper that prepares close data
     */
    private function buildCloseResult(array $trade, float $closePrice, int $closeTs, string $closeReason, string $modeStorageBase): array
    {
        $side = $trade['side'] ?? 'long';
        $openedPrice = (float)($trade['entry']['opened_price'] ?? $trade['entry']['target_price'] ?? 0);
        $qty = (float)($trade['entry']['qty'] ?? 0);
        $budget = (float)($trade['entry']['budget_usdt'] ?? 0);
        $leverage = (int)($trade['risk']['leverage'] ?? 10);
        $feesBps = (float)($trade['risk']['fees_bps'] ?? 6);
        
        // Calculate raw PnL (before fees)
        if ($side === 'long') {
            $pnlGross = ($closePrice - $openedPrice) * $qty;
        } else {
            $pnlGross = ($openedPrice - $closePrice) * $qty;
        }
        
        // Calculate fees (entry + exit)
        $feeRate = $feesBps / 10000;
        $notional = $budget * $leverage;
        $entryFee = $notional * $feeRate;
        $exitFee = $notional * $feeRate;
        $totalFees = $entryFee + $exitFee;
        
        // Net PnL (after fees)
        $pnlNet = $pnlGross - $totalFees;
        
        // ROI calculations (margin-based)
        $roiGross = $budget > 0 ? $pnlGross / $budget : 0;
        $roiNet = $budget > 0 ? $pnlNet / $budget : 0;
        
        $closeResult = [
            'status' => 'closed',
            'close_reason' => $closeReason,
            'close_note' => "Closed by {$closeReason}",
            'close_price' => $closePrice,
            'close_ts' => $closeTs,
            'closed_at' => date('c', $closeTs),
            'pnl_realized_usdt' => round($pnlNet, 4),
            'pnl_gross_usdt' => round($pnlGross, 4),
            'pnl_net_usdt' => round($pnlNet, 4),
            'roi' => round($roiNet, 6),
            'roi_gross_pct' => round($roiGross * 100, 4),
            'roi_net_pct' => round($roiNet * 100, 4),
            'roi_margin_pct' => round($roiNet * 100, 4),
            'fees_bps' => $feesBps,
            'entry_fee_usdt' => round($entryFee, 4),
            'exit_fee_usdt' => round($exitFee, 4),
            'fees_total_usdt' => round($totalFees, 4),
            'fee_paid_usdt' => round($totalFees, 4),
            'win' => $pnlNet > 0,
        ];
        
        // Close the trade
        $this->closeTradeForMode($trade, $closeResult, $modeStorageBase);
        
        return $closeResult;
    }
    
    /**
     * Close trade for a specific mode (move to closed dir)
     * STEP 1.2: Writes to mode-specific training_dataset
     * STEP 1.3: Includes fee fields in result
     */
    private function closeTradeForMode(array $trade, array $closeResult, string $modeStorageBase): void
    {
        $tradeId = $trade['trade_id'] ?? '';
        
        // Add close result to trade
        $trade['status'] = 'closed';
        $trade['close_result'] = $closeResult;
        $trade['result'] = [
            'roi_realized' => $closeResult['roi'] ?? 0,
            'pnl_realized_usdt' => $closeResult['pnl_realized_usdt'] ?? 0,
            // STEP 1.3: Gross/Net PnL and ROI
            'pnl_gross_usdt' => $closeResult['pnl_gross_usdt'] ?? 0,
            'pnl_net_usdt' => $closeResult['pnl_net_usdt'] ?? 0,
            'roi_gross_pct' => $closeResult['roi_gross_pct'] ?? 0,
            'roi_net_pct' => $closeResult['roi_net_pct'] ?? 0,
            // STEP 1.3: Fee breakdown
            'fees_bps' => $closeResult['fees_bps'] ?? 0,
            'entry_fee_usdt' => $closeResult['entry_fee_usdt'] ?? 0,
            'exit_fee_usdt' => $closeResult['exit_fee_usdt'] ?? 0,
            'fees_total_usdt' => $closeResult['fees_total_usdt'] ?? 0,
            'max_drawdown_roi' => $trade['pnl']['max_drawdown_roi'] ?? 0,
        ];
        $trade['close_reason'] = $closeResult['close_reason'] ?? 'unknown';
        
        // Determine win status:
        // 1. Use explicit 'win' field from closeResult if present
        // 2. Otherwise, calculate from net PnL (after fees)
        $win = $closeResult['win'] ?? (($closeResult['pnl_net_usdt'] ?? 0) > 0);
        $trade['labels'] = [
            'win' => $win,
        ];
        
        $trade['events'][] = [
            'ts' => date('c'),
            'type' => 'closed',
            'price' => $closeResult['close_price'] ?? 0,
            'reason' => $closeResult['close_reason'] ?? 'unknown',
            'fees_total_usdt' => $closeResult['fees_total_usdt'] ?? 0,
        ];
        
        // Save to closed
        $closedPath = $modeStorageBase . '/trades/closed/' . $tradeId . '.json';
        $this->ensureDir(dirname($closedPath));
        $this->writeJsonAtomic($closedPath, $trade);
        
        // Delete from active
        $activePath = $modeStorageBase . '/trades/active/' . $tradeId . '.json';
        if (is_file($activePath)) {
            @unlink($activePath);
        }
        
        // STEP 1.2: Append to mode-specific training_dataset.ndjson
        // Determine mode from storage path - explicit check for both modes
        $mode = 'clean'; // default fallback
        if (strpos($modeStorageBase, '/raw') !== false) {
            $mode = 'raw';
        } elseif (strpos($modeStorageBase, '/clean') !== false) {
            $mode = 'clean';
        }
        // Note: If path contains neither '/raw' nor '/clean', 'clean' is used as safe default
        
        $this->appendTrainingDatasetForMode($trade, $closeResult, $modeStorageBase, $mode);
        
        // STEP 3: Append trailing episode dataset for ML training
        $this->appendTrailingEpisodeForMode($trade, $closeResult, $modeStorageBase, $mode);
    }
    
}

/* RULES
 * NO HARDCODE. CONFIG FIRST. SystemPaths ONLY.
 * Integration methods handle mode-specific (RAW/CLEAN) storage operations.
 * All file operations must use $modeStorageBase parameter for storage path isolation.
 * Methods must not access $this->storageDir directly except for copyCleanArtifactsToRoot.
 */
