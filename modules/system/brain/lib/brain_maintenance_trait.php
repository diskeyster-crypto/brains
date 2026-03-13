<?php

declare(strict_types=1);

namespace Modules\System\Brain\Lib;

use Core\System\SystemPaths;

/**
 * BrainMaintenanceTrait - Reset/Clear/Selftest methods
 * 
 * This trait is part of the BrainService refactor into traits.
 * No behavior changes are allowed (refactor-only).
 */
trait BrainMaintenanceTrait
{
    /**
     * Reset all brain and simulator storage
     */
    public function resetAll(): array
    {
        $result = [
            'success' => true,
            'brain_deleted' => 0,
            'simulator_deleted' => 0,
            'errors' => [],
        ];
        
        try {
            // Clear Brain storage files
            $brainFiles = [
                $this->storageDir . '/signals.json',
                $this->storageDir . '/last_run.json',
                $this->storageDir . '/state.json',
                $this->storageDir . '/process_journal.ndjson',
                $this->storageDir . '/events.ndjson',
            ];
            
            foreach ($brainFiles as $file) {
                if (is_file($file)) {
                    @unlink($file);
                    $result['brain_deleted']++;
                }
            }
            
            // Clear Brain directories
            $brainDirs = [
                $this->runsDir,
                $this->passportsDir,
                $this->storageDir . '/learning',
                $this->storageDir . '/management',
                $this->profilesDir,
            ];
            
            foreach ($brainDirs as $dir) {
                if (is_dir($dir)) {
                    $result['brain_deleted'] += $this->clearDirectory($dir);
                }
            }
            
            // Clear Simulator storage via SystemPaths
            // TASK 2: Use modulePaths['simulator'] instead of 'parser6_simulator'
            $simulatorStorage = $this->modulePaths['simulator'] ?? null;
            if (!$simulatorStorage) {
                // Try SystemPaths keys in priority order
                $simulatorStorageKeys = [
                    'simulator.parser6_simulator.storage',
                    'parser.parser6_simulator.storage',
                ];
                foreach ($simulatorStorageKeys as $key) {
                    try {
                        $simulatorStorage = SystemPaths::instance()->get($key);
                        if ($simulatorStorage) {
                            break;
                        }
                    } catch (\Throwable $e) {
                        // Key not found, try next
                    }
                }
            }
            
            if (!$simulatorStorage) {
                // TASK 2: Return success for Brain-cleanup even if simulator_storage_not_found
                $result['errors'][] = 'simulator_storage_not_found';
            }
            
            if ($simulatorStorage) {
                // Clear root, raw, and clean directories
                $simDirs = [
                    $simulatorStorage,
                    $simulatorStorage . '/raw',
                    $simulatorStorage . '/clean',
                ];
                
                foreach ($simDirs as $dir) {
                    if (is_dir($dir)) {
                        $result['simulator_deleted'] += $this->clearSimulatorDirectory($dir);
                    }
                }
            }
            
            // C-6: After clearing, create baseline files so UI doesn't break
            // 1. Reinitialize state.json (IDLE state)
            // Delete stateFile first so initializeState() recreates it fresh
            if (is_file($this->stateFile)) {
                @unlink($this->stateFile);
            }
            $this->initializeState();
            
            // 2. C-6: Create baseline last_run.json (idle status, success=true)
            $lastRunFile = $this->storageDir . '/last_run.json';
            $resetFinishedAt = date('Y-m-d H:i:s');
            $baselineLastRun = [
                'id' => 'reset_' . date('Ymd_His') . '_' . substr(md5(uniqid((string)microtime(true), true)), 0, 6),
                'success' => true,
                'ok' => true,
                'status' => 'idle',
                'started_at' => $resetFinishedAt,  // Reset is instantaneous, so start=finish
                'finished_at' => $resetFinishedAt,
                'timestamp' => time(),
                'duration_ms' => 0,
                'strategies_applied' => 0,
                'candidates_loaded' => 0,
                'signals_generated' => 0,
                'steps' => [],
                'errors' => [],
                'message' => 'Reset completed - system in idle state',
            ];
            file_put_contents($lastRunFile, json_encode($baselineLastRun, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            // 3. C-6: Create empty process_journal.ndjson
            if (!is_file($this->processJournalFile)) {
                file_put_contents($this->processJournalFile, '');
            }
            
            // 4. C-6: Also recreate Simulator baseline files if storage exists
            if ($simulatorStorage && is_dir($simulatorStorage)) {
                // Create baseline state.json for simulator
                $simStateFile = $simulatorStorage . '/state.json';
                $simBaselineState = [
                    'status' => 'idle',
                    'last_run' => null,
                    'mode' => 'clean',
                    'trades_active' => [],
                    'initialized_at' => date('c'),
                ];
                file_put_contents($simStateFile, json_encode($simBaselineState, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                
                // Create baseline last_run.json for simulator
                $simLastRunFile = $simulatorStorage . '/last_run.json';
                $simBaselineLastRun = [
                    'ts' => date('c'),
                    'ok' => true,
                    'status' => 'idle',
                    'duration_ms' => 0,
                    'message' => 'Reset completed - simulator in idle state',
                ];
                file_put_contents($simLastRunFile, json_encode($simBaselineLastRun, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                
                // P1.6: Create baseline summary.json for stats
                $simBaselineSummary = [
                    'updated_at' => date('c'),
                    'total_trades_closed' => 0,
                    'wins' => 0,
                    'losses' => 0,
                    'winrate' => 0,
                    'roi_avg' => 0,
                    'avg_duration_minutes' => 0,
                    'profit_factor' => 0,
                    'closed_by' => [
                        'tp' => 0,
                        'sl' => 0,
                        'trailing_sl' => 0,
                        'expired_signal' => 0,
                        'expired_entry_timeout' => 0,
                    ],
                ];
                
                // P1.6: Create CLEAN mode baseline directories and files
                $cleanDir = $simulatorStorage . '/clean';
                $this->ensureDirectoryExists($cleanDir . '/trades/active');
                $this->ensureDirectoryExists($cleanDir . '/trades/closed');
                $this->ensureDirectoryExists($cleanDir . '/trades/rejected');
                $this->ensureDirectoryExists($cleanDir . '/stats');
                file_put_contents($cleanDir . '/state.json', json_encode($simBaselineState, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                file_put_contents($cleanDir . '/last_run.json', json_encode($simBaselineLastRun, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                file_put_contents($cleanDir . '/stats/summary.json', json_encode($simBaselineSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                
                // P1.6: Create RAW mode baseline directories and files
                $rawDir = $simulatorStorage . '/raw';
                $this->ensureDirectoryExists($rawDir . '/trades/active');
                $this->ensureDirectoryExists($rawDir . '/trades/closed');
                $this->ensureDirectoryExists($rawDir . '/trades/rejected');
                $this->ensureDirectoryExists($rawDir . '/stats');
                file_put_contents($rawDir . '/state.json', json_encode($simBaselineState, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                file_put_contents($rawDir . '/last_run.json', json_encode($simBaselineLastRun, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                file_put_contents($rawDir . '/stats/summary.json', json_encode($simBaselineSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
            
            // P1.6: Create baseline signals.json for Brain (always)
            $signalsFile = $this->storageDir . '/signals.json';
            $baselineSignals = [
                'schema_version' => 'clean_signal_v1',
                'generated_at' => date('c'),
                'generated_ts' => time(),
                'count' => 0,
                'signals' => [],
            ];
            file_put_contents($signalsFile, json_encode($baselineSignals, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
        } catch (\Throwable $e) {
            $result['success'] = false;
            $result['errors'][] = $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Clear all files in a directory (recursively)
     */
    private function clearDirectory(string $dir): int
    {
        $deleted = 0;
        
        if (!is_dir($dir)) {
            return $deleted;
        }
        
        $files = glob($dir . '/*') ?: [];
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
                $deleted++;
            } elseif (is_dir($file)) {
                $deleted += $this->clearDirectory($file);
            }
        }
        
        return $deleted;
    }
    
    /**
     * Clear simulator directory (specific structure)
     */
    private function clearSimulatorDirectory(string $dir): int
    {
        $deleted = 0;
        
        if (!is_dir($dir)) {
            return $deleted;
        }
        
        // Files to delete
        $files = [
            'state.json',
            'simulation.json',
            'stats/summary.json',
            'stats_global.json',
            'last_run.json',
            'training_dataset.ndjson',
            'training_trailing_sim.ndjson',
            'events.ndjson',
            'executed_index.json',
        ];
        
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_file($path)) {
                @unlink($path);
                $deleted++;
            }
        }
        
        // Directories to clear
        $subDirs = [
            'trades/active',
            'trades/closed',
            'trades/rejected',
            'dataset',
        ];
        
        foreach ($subDirs as $subDir) {
            $path = $dir . '/' . $subDir;
            if (is_dir($path)) {
                $deleted += $this->clearDirectory($path);
            }
        }
        
        return $deleted;
    }
    
    /**
     * Ensure directory exists (creates recursively if needed)
     */
    private function ensureDirectoryExists(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }
    
    /**
     * A3: Brain selftest endpoint (FIXED - correct keys + correct paths)
     * TASK 3: Support alternative keys and fix ok condition
     * 
     * Returns:
     * - missing_path_keys[]
     * - files{candidates, parser5_signals, brain_signals, simulator_state, commands}
     * - perms{brain_storage_writable, sim_storage_writable}
     * - ok
     * 
     * @return array Selftest result
     */
    public function selftest(): array
    {
        $result = [
            'ok' => true,
            'missing_path_keys' => [],
            'files' => [],
            'perms' => [],
        ];
        
        $paths = SystemPaths::instance();
        
        // TASK 1: Check required keys using ONLY try/get pattern (no $paths->has())
        // Single required keys (system.brain, parser5)
        $singleRequiredKeys = [
            'system.brain',
            'parser.parser5_signal_monitor.storage',
        ];
        
        foreach ($singleRequiredKeys as $key) {
            try {
                $path = $paths->get($key);
                if (empty($path)) {
                    $result['missing_path_keys'][] = $key . ' (key returns empty path)';
                } elseif (!is_dir($path)) {
                    $result['missing_path_keys'][] = $key . ' (directory not found: ' . $path . ')';
                }
            } catch (\Throwable $e) {
                $result['missing_path_keys'][] = $key . ' (key not configured or error: ' . $e->getMessage() . ')';
            }
        }
        
        // TASK 1: Parser4 storage - check OR keys group using try/get pattern
        $parser4Keys = [
            'parser.parser4_candidates.storage',
            'parser.parser4_analyzer.storage',
        ];
        $parser4Found = false;
        foreach ($parser4Keys as $key) {
            try {
                $path = $paths->get($key);
                if (!empty($path) && is_dir($path)) {
                    $parser4Found = true;
                    break;
                }
            } catch (\Throwable $e) {
                // Ignore, try next key in OR-group
            }
        }
        if (!$parser4Found) {
            $result['missing_path_keys'][] = 'parser4_storage_keys_missing';
        }
        
        // TASK 1: Simulator storage - check OR keys group using try/get pattern
        $simulatorKeys = [
            'simulator.parser6_simulator.storage',
            'parser.parser6_simulator.storage',
        ];
        $simulatorFound = false;
        foreach ($simulatorKeys as $key) {
            try {
                $path = $paths->get($key);
                if (!empty($path) && is_dir($path)) {
                    $simulatorFound = true;
                    break;
                }
            } catch (\Throwable $e) {
                // Ignore, try next key in OR-group
            }
        }
        if (!$simulatorFound) {
            $result['missing_path_keys'][] = 'simulator_storage_keys_missing';
        }
        
        // TASK 1: Check optional base key using try/get pattern (for service.php/runner.php location)
        $optionalBaseKeys = [
            'simulator.parser6_simulator',
            'parser.parser6_simulator',
        ];
        
        $simulatorBasePath = null;
        foreach ($optionalBaseKeys as $key) {
            try {
                $path = $paths->get($key);
                if (!empty($path)) {
                    $simulatorBasePath = $path;
                    break;
                }
            } catch (\Throwable $e) {
                // Ignore - these are optional
            }
        }
        
        // A3: Check files using DISCOVERED STORAGE PATHS (NOT modulePaths with double /storage/)
        // modulePaths['parser4'] is ALREADY storage path, so NO /storage/ suffix needed
        $candidatesPath = $this->modulePaths['parser4'] ?? null;
        $parser5Path = $this->modulePaths['parser5'] ?? null;
        $simulatorPath = $this->modulePaths['simulator'] ?? null;
        
        // A3: candidates.json is directly in parser4 storage (NOT /storage/candidates.json)
        $result['files']['candidates'] = $candidatesPath 
            ? is_file($candidatesPath . '/candidates.json')
            : false;
        
        // A3: signals.json is directly in parser5 storage (NOT /storage/signals.json)    
        $result['files']['parser5_signals'] = $parser5Path 
            ? is_file($parser5Path . '/signals.json')
            : false;
            
        $result['files']['brain_signals'] = is_file($this->storageDir . '/signals.json');
        
        // A3: simulator state - check root and clean subdirectory
        if ($simulatorPath) {
            $rootState = $simulatorPath . '/state.json';
            $cleanState = $simulatorPath . '/clean/state.json';
            $result['files']['simulator_state'] = is_file($rootState) || is_file($cleanState);
        } else {
            $result['files']['simulator_state'] = false;
        }
            
        $result['files']['commands'] = is_file($this->storageDir . '/management/commands.json');
        
        // A3: Check permissions with test write
        $brainWritable = $this->testWritePermission($this->storageDir);
        $result['perms']['brain_storage_writable'] = $brainWritable;
        
        if ($simulatorPath) {
            $simWritable = $this->testWritePermission($simulatorPath);
            $result['perms']['sim_storage_writable'] = $simWritable;
        } else {
            $result['perms']['sim_storage_writable'] = false;
        }
        
        // TASK 3: ok=false if brain_storage_writable==false OR sim_storage_writable==false OR missing_path_keys not empty
        if (!$result['perms']['brain_storage_writable']) {
            $result['ok'] = false;
        }
        if (!$result['perms']['sim_storage_writable']) {
            $result['ok'] = false;
        }
        if (!empty($result['missing_path_keys'])) {
            $result['ok'] = false;
        }
        
        // Add discovered paths info for debugging
        $result['paths'] = [
            'brain_storage' => $this->storageDir,
            'parser4_storage' => $candidatesPath,
            'parser5_storage' => $parser5Path,
            'simulator_storage' => $simulatorPath,
            'simulator_base' => $simulatorBasePath,
        ];
        
        // C-7: Selftest Deep - validate JSON decode + minimum schema
        $result['json_ok'] = [];
        $result['json_errors'] = [];
        $result['json_schema'] = [];
        
        // C-7.1: Validate candidates.json
        if ($candidatesPath && is_file($candidatesPath . '/candidates.json')) {
            $candidatesContent = @file_get_contents($candidatesPath . '/candidates.json');
            $candidatesJson = @json_decode($candidatesContent, true);
            if ($candidatesJson === null && json_last_error() !== JSON_ERROR_NONE) {
                $result['json_ok']['candidates'] = false;
                $result['json_errors']['candidates'] = json_last_error_msg();
            } else {
                $result['json_ok']['candidates'] = true;
                // Check minimum schema: should be array
                $result['json_schema']['candidates'] = is_array($candidatesJson) ? 'array' : gettype($candidatesJson);
            }
        } else {
            $result['json_ok']['candidates'] = null;
        }
        
        // C-7.2: Validate parser5 signals.json
        if ($parser5Path && is_file($parser5Path . '/signals.json')) {
            $p5Content = @file_get_contents($parser5Path . '/signals.json');
            $p5Json = @json_decode($p5Content, true);
            if ($p5Json === null && json_last_error() !== JSON_ERROR_NONE) {
                $result['json_ok']['parser5_signals'] = false;
                $result['json_errors']['parser5_signals'] = json_last_error_msg();
            } else {
                $result['json_ok']['parser5_signals'] = true;
            }
        } else {
            $result['json_ok']['parser5_signals'] = null;
        }
        
        // C-7.3: Validate brain signals.json and check for risk + entry_action
        $brainSignalsFile = $this->storageDir . '/signals.json';
        if (is_file($brainSignalsFile)) {
            $brainContent = @file_get_contents($brainSignalsFile);
            $brainJson = @json_decode($brainContent, true);
            if ($brainJson === null && json_last_error() !== JSON_ERROR_NONE) {
                $result['json_ok']['brain_signals'] = false;
                $result['json_errors']['brain_signals'] = json_last_error_msg();
            } else {
                $result['json_ok']['brain_signals'] = true;
                // C-7: Check first element contains risk + entry_action
                // C-7: Determine first signal. Support wrapper schema clean_signal_v1: {schema_version, signals:[...]}
                $firstSignal = null;
                if (is_array($brainJson)) {
                    if (
                        isset($brainJson['schema_version'])
                        && ($brainJson['schema_version'] ?? '') === 'clean_signal_v1'
                        && isset($brainJson['signals'])
                        && is_array($brainJson['signals'])
                    ) {
                        $firstSignal = $brainJson['signals'][0] ?? null;
                        $result['json_schema']['brain_signals_schema_version'] = $brainJson['schema_version'];
                        $result['json_schema']['brain_signals_count'] = count($brainJson['signals']);
                    } elseif (isset($brainJson[0])) {
                        // Legacy format: [ ...signals... ]
                        $firstSignal = $brainJson[0] ?? null;
                    }
                }
                if ($firstSignal) {
                    $result['json_schema']['brain_signals_has_risk'] = isset($firstSignal['risk']);
                    $result['json_schema']['brain_signals_has_entry_action'] = isset($firstSignal['entry_action']);
                    // Check risk block structure
                    if (isset($firstSignal['risk'])) {
                        $risk = $firstSignal['risk'];
                        $result['json_schema']['brain_signals_risk_has_budget'] = isset($risk['budget_usdt_per_trade']) || isset($risk['budget_usdt']);
                        $result['json_schema']['brain_signals_risk_has_leverage'] = isset($risk['leverage']);
                    }
                }
            }
        } else {
            $result['json_ok']['brain_signals'] = null;
        }
        
        return $result;
    }
}

/* RULES
 * NO HARDCODE. CONFIG FIRST. SystemPaths ONLY.
 * This file is part of Brain refactor into traits.
 * No behavior changes allowed (refactor-only).
 */
