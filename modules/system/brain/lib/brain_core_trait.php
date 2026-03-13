<?php
declare(strict_types=1);

namespace Modules\System\Brain\Lib;

use Core\System\SystemPaths;

/**
 * BrainCoreTrait - Core methods for BrainService
 * 
 * Contains: singleton, directories, config, state management, logging, runtime status
 */
trait BrainCoreTrait
{
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Ensure storage directories exist
     */
    private function ensureDirectories(): void
    {
        $dirs = [
            $this->storageDir,
            $this->strategiesDir,
            $this->runsDir,
            $this->feedbackDir,
            $this->passportsDir,
            $this->profilesDir,  // Risk Profiles v1
            $this->executorDir,
            $this->storageDir . '/logs',
            $this->storageDir . '/tmp',
        ];
        
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
    
    /**
     * Load configuration
     */
    private function loadConfig(): void
    {
        // SystemPaths: Use moduleBase (NEVER __DIR__)
        $configFile = $this->moduleBase . '/config/config.php';
        $this->config = is_file($configFile) ? require $configFile : [];
    }
    
    /**
     * Discover paths to other module storage locations
     * SystemPaths: Use SystemPaths keys instead of hardcoded paths
     */
    private function discoverModulePaths(): void
    {
        $paths = SystemPaths::instance();
        
        // Parser4 candidates storage via SystemPaths
        // Keys: parser.parser4_analyzer.storage, parser.parser4_candidates.storage
        // PATCH-DEBUG-v1.3: Use ONLY try/get pattern (no $paths->has())
        $parser4Keys = [
            'parser.parser4_analyzer.storage',
            'parser.parser4_candidates.storage',
        ];
        foreach ($parser4Keys as $key) {
            try {
                $path = $paths->get($key);
                if (!empty($path)) {
                    $this->modulePaths['parser4'] = $path;
                    break;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        
        // Parser5 signals storage via SystemPaths
        // Primary key: parser.parser5_signal_monitor.storage (per ТЗ)
        // PATCH-DEBUG-v1.3: Use ONLY try/get pattern (no $paths->has())
        $parser5Keys = [
            'parser.parser5_signal_monitor.storage',
        ];
        foreach ($parser5Keys as $key) {
            try {
                $path = $paths->get($key);
                if (!empty($path)) {
                    $this->modulePaths['parser5'] = $path;
                    break;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        
        // Brain storage (for signal gateway output)
        // Try system.brain.storage first, then fallback to storageDir
        // PATCH-DEBUG-v1.3: Use ONLY try/get pattern (no $paths->has())
        try {
            $brainStorage = $paths->get('system.brain.storage');
            if (!empty($brainStorage)) {
                $this->modulePaths['brain'] = $brainStorage;
            } else {
                $this->modulePaths['brain'] = $this->storageDir;
                $this->log("Using storageDir fallback for brain.storage: {$this->storageDir}", 'info');
            }
        } catch (\Throwable $e) {
            // Fallback: use own storageDir (already built from SystemPaths::get('system.brain'))
            $this->modulePaths['brain'] = $this->storageDir;
            $this->log("Using storageDir fallback for brain.storage: {$this->storageDir}", 'info');
        }
        
        // Parser6/Simulator storage via SystemPaths (separate from brain.storage!)
        // PATCH-DEBUG-v1.3: Use ONLY try/get pattern (no $paths->has())
        $simulatorKeys = [
            'parser.parser6_simulator.storage',
            'simulator.parser6_simulator.storage',
        ];
        foreach ($simulatorKeys as $key) {
            try {
                $path = $paths->get($key);
                if (!empty($path)) {
                    $this->modulePaths['simulator'] = $path;
                    break;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        
        // Executor storage via SystemPaths
        // PATCH-DEBUG-v1.3: Use ONLY try/get pattern (no $paths->has())
        $executorKeys = [
            'signal.executor.storage',
            'trading.executor.storage',
        ];
        foreach ($executorKeys as $key) {
            try {
                $path = $paths->get($key);
                if (!empty($path)) {
                    $this->modulePaths['executor'] = $path;
                    break;
                }
            } catch (\Throwable $e) {
                continue;
            }


        // Trading Bot storage via SystemPaths (optional)
        // Used by Symbol Policy to read live closed trades.
        $tradingBotKeys = [
            'system.trading_bot.storage',
            'system.trading_bot',
            'trading_bot.storage',
        ];
        foreach ($tradingBotKeys as $key) {
            try {
                $path = $paths->get($key);
                if (!empty($path)) {
                    $this->modulePaths['trading_bot'] = $path;
                    break;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

    }
    }
    
    /**
     * Initialize state file (FIX 5 - State Engine + v2.2 State Machine)
     * Brain becomes the single source of truth for system state
     */
    private function initializeState(): void
    {
        if (!is_file($this->stateFile)) {
            $initialState = [
                'version' => '2.2', // v2.2: Updated version
                'initialized_at' => date('Y-m-d H:i:s'),
                'last_pipeline_run' => null,
                'active_strategies' => [],
                'module_status' => [
                    'parser5' => ['status' => 'idle', 'last_run' => null],
                    'simulator' => ['status' => 'idle', 'last_run' => null],
                    'executor' => ['status' => 'idle', 'last_export' => null],
                ],
                'executor_export' => [
                    'last_export' => null,
                    'strategies_exported' => 0,
                    'signals_exported' => 0,
                ],
                'metrics' => [
                    'total_pipeline_runs' => 0,
                    'successful_runs' => 0,
                    'failed_runs' => 0,
                ],
                // v2.2 State Machine (Блок 2)
                'status' => self::STATUS_IDLE,
                'active_run_id' => null,
                'started_at' => null,
                'pid' => null,
                'strategies_queue' => [],
            ];
            file_put_contents($this->stateFile, json_encode($initialState, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }
    
    /**
     * Get current system state (FIX 5)
     */
    public function getState(): array
    {
        if (!is_file($this->stateFile)) {
            $this->initializeState();
        }
        
        $content = @file_get_contents($this->stateFile);
        if ($content === false) {
            $this->log("Failed to read state file: {$this->stateFile}", 'error');
            return [];
        }
        
        $state = json_decode($content, true);
        return is_array($state) ? $state : [];
    }
    
    /**
     * Update system state (FIX 5)
     */
    public function updateState(array $updates): array
    {
        $state = $this->getState();
        $state = array_replace_recursive($state, $updates);
        $state['updated_at'] = date('Y-m-d H:i:s');
        
        file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        return $state;
    }
    
    /**
     * Update config_overrides.json based on strategy performance
     * 
     * Config overrides allow dynamic adjustment of system behavior
     * based on strategy performance metrics.
     * 
     * @return array Result
     */
    public function updateConfigOverrides(): array
    {
        $result = [
            'success' => true,
            'overrides_applied' => 0,
        ];
        
        $overridesFile = $this->storageDir . '/config_overrides.json';
        
        // Calculate aggregate metrics across all strategies
        $strategies = $this->getStrategies();
        $activeStrategies = array_filter($strategies, fn($s) => $s['enabled'] ?? false);
        
        $overrides = [
            'updated_at' => date('Y-m-d H:i:s'),
            'active_strategies_count' => count($activeStrategies),
            'total_strategies_count' => count($strategies),
            'recommendations' => [],
        ];
        
        // Analyze performance and create recommendations
        $fitnessScores = $this->calculateAllFitnessScores();
        $avgFitness = 0;
        if (!empty($fitnessScores['scores'])) {
            $avgFitness = array_sum(array_column($fitnessScores['scores'], 'score')) / count($fitnessScores['scores']);
        }
        
        $overrides['avg_fitness_score'] = round($avgFitness, 4);
        
        // Generate recommendations based on performance
        if ($avgFitness < 0.3) {
            $overrides['recommendations'][] = [
                'type' => 'warning',
                'message' => 'Average fitness is low. Consider reviewing strategy parameters.',
            ];
        }
        
        if (count($activeStrategies) === 0) {
            $overrides['recommendations'][] = [
                'type' => 'info',
                'message' => 'No active strategies. Enable at least one strategy to start trading.',
            ];
        }
        
        // Save overrides atomically
        $tmpFile = $overridesFile . '.tmp';
        file_put_contents($tmpFile, json_encode($overrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        rename($tmpFile, $overridesFile);
        
        $result['overrides_applied'] = count($overrides['recommendations']);
        
        return $result;
    }
    
    /**
     * Log a decision to decisions.jsonl
     * 
     * @param string $type Decision type
     * @param array $data Decision data
     */
    private function logDecision(string $type, array $data): void
    {
        $decisionsFile = $this->storageDir . '/decisions.jsonl';
        
        $entry = [
            'ts' => date('Y-m-d\TH:i:s.') . substr((string)microtime(true), -3) . 'Z',
            'type' => $type,
            'data' => $data,
        ];
        
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($decisionsFile, $line, FILE_APPEND);
    }
    
    /**
     * Get config value
     */
    public function getConfig(string $key = '', mixed $default = null): mixed
    {
        if (empty($key)) {
            return $this->config;
        }
        
        $keys = explode('.', $key);
        $value = $this->config;
        
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        
        return $value;
    }
    
    /**
     * Get discovered module paths
     */
    public function getModulePaths(): array
    {
        return $this->modulePaths;
    }
    
    /**
     * Log message
     */
    private function log(string $message, string $level = 'info'): void
    {
        // SystemPaths: Use storageDir + logs path instead of dirname()
        $logDir = $this->storageDir . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $line = "[{$timestamp}] [{$level}] {$message}\n";
        file_put_contents($this->logFile, $line, FILE_APPEND);
    }
    
    /**
     * Generate ISO 8601 timestamp with milliseconds (v2.2 shared utility)
     * 
     * Used by: process telemetry, event journal, NDJSON records
     * 
     * @return string ISO 8601 timestamp with milliseconds (e.g., "2024-01-15T10:30:45.123Z")
     */
    public static function isoTimestamp(): string
    {
        $microtime = microtime(true);
        $milliseconds = sprintf('%03d', ($microtime - floor($microtime)) * 1000);
        return date('Y-m-d\TH:i:s.', (int)$microtime) . $milliseconds . 'Z';
    }
    
    /**
     * Sanitize ID for filename
     */
    private function sanitizeId(string $id): string
    {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', $id) ?: 'unknown';
    }
    
    /**
     * Generate random float in range
     * 
     * @param float $min Minimum value
     * @param float $max Maximum value
     * @return float Random float
     */
    private function randomFloat(float $min, float $max): float
    {
        return $min + mt_rand() / mt_getrandmax() * ($max - $min);
    }
    
    /**
     * Calculate median value from array
     * 
     * @param array $values Array of numeric values
     * @return float Median value
     */
    private function calculateMedian(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }
        
        sort($values);
        $count = count($values);
        $middle = (int)floor($count / 2);
        
        if ($count % 2 === 0) {
            // Even number of elements: average of two middle values
            return round(($values[$middle - 1] + $values[$middle]) / 2, 4);
        }
        
        // Odd number of elements: middle value
        return round($values[$middle], 4);
    }
    
    /**
     * Get current runtime status (Блок 2 - State Machine)
     * 
     * @return string Current status (IDLE|RUNNING|ERROR|STOPPED)
     */
    public function getRuntimeStatus(): string
    {
        $state = $this->getState();
        return $state['status'] ?? self::STATUS_IDLE;
    }
    
    /**
     * Check if pipeline is currently running (Блок 2 - State Machine)
     * 
     * @return bool True if running
     */
    public function isRunning(): bool
    {
        return $this->getRuntimeStatus() === self::STATUS_RUNNING;
    }
    
    /**
     * Set runtime status (Блок 2 - State Machine)
     * 
     * @param string $status New status
     * @param string|null $runId Run ID (for RUNNING status)
     * @return array Updated state
     */
    private function setRuntimeStatus(string $status, ?string $runId = null): array
    {
        $updates = [
            'status' => $status,
            'status_updated_at' => date('Y-m-d H:i:s'),
        ];
        
        if ($status === self::STATUS_RUNNING) {
            $updates['active_run_id'] = $runId;
            $updates['started_at'] = date('Y-m-d H:i:s');
            $updates['pid'] = getmypid();
        } elseif ($status === self::STATUS_IDLE || $status === self::STATUS_STOPPED) {
            $updates['active_run_id'] = null;
            $updates['started_at'] = null;
            $updates['pid'] = null;
        }
        
        return $this->updateState($updates);
    }
    
    /**
     * Start pipeline run with state machine check (Блок 2 - State Machine)
     * 
     * Prevents double pipeline execution.
     * 
     * @param string $runId Run ID
     * @return array Result with success/error
     */
    public function startPipelineRun(string $runId): array
    {
        // Check if already running
        if ($this->isRunning()) {
            $state = $this->getState();
            return [
                'success' => false,
                'error' => 'Pipeline already running',
                'active_run_id' => $state['active_run_id'] ?? null,
                'started_at' => $state['started_at'] ?? null,
                'pid' => $state['pid'] ?? null,
            ];
        }
        
        // Set running status
        $this->setRuntimeStatus(self::STATUS_RUNNING, $runId);
        
        // Log event
        $this->logEvent(self::EVENT_RUN_START, [
            'run_id' => $runId,
            'pid' => getmypid(),
        ]);
        
        return [
            'success' => true,
            'run_id' => $runId,
            'status' => self::STATUS_RUNNING,
        ];
    }
    
    /**
     * End pipeline run (Блок 2 - State Machine)
     * 
     * @param string $runId Run ID
     * @param bool $success Whether run was successful
     * @param string|null $error Error message if failed
     * @return array Updated state
     */
    public function endPipelineRun(string $runId, bool $success, ?string $error = null): array
    {
        $status = $success ? self::STATUS_IDLE : self::STATUS_ERROR;
        $this->setRuntimeStatus($status);
        
        // Log event
        $this->logEvent(self::EVENT_RUN_END, [
            'run_id' => $runId,
            'success' => $success,
            'error' => $error,
        ]);
        
        return $this->getState();
    }
    
    /**
     * Stop/kill current pipeline run (Блок 2 - State Machine)
     * 
     * @return array Result
     */
    public function stopPipelineRun(): array
    {
        $state = $this->getState();
        
        if (!$this->isRunning()) {
            return [
                'success' => false,
                'error' => 'No pipeline currently running',
            ];
        }
        
        $runId = $state['active_run_id'] ?? null;
        $pid = $state['pid'] ?? null;
        
        // Set stopped status
        $this->setRuntimeStatus(self::STATUS_STOPPED);
        
        // Kill associated processes
        $killed = $this->killHungProcesses();
        
        // Log event
        $this->logEvent(self::EVENT_RUN_END, [
            'run_id' => $runId,
            'stopped' => true,
            'killed_processes' => $killed,
        ]);
        
        $this->log("Pipeline stopped: run_id={$runId}, pid={$pid}", 'warning');
        
        return [
            'success' => true,
            'stopped_run_id' => $runId,
            'killed_processes' => $killed,
        ];
    }
    
    /**
     * Get runtime info for UI (Блок 2 + Блок 5 UI)
     * 
     * @return array Runtime info
     */
    public function getRuntimeInfo(): array
    {
        $state = $this->getState();
        
        return [
            'status' => $state['status'] ?? self::STATUS_IDLE,
            'active_run_id' => $state['active_run_id'] ?? null,
            'started_at' => $state['started_at'] ?? null,
            'pid' => $state['pid'] ?? null,
            'strategies_queue' => $state['strategies_queue'] ?? [],
            'strategies_queue_count' => count($state['strategies_queue'] ?? []),
            'is_running' => ($state['status'] ?? self::STATUS_IDLE) === self::STATUS_RUNNING,
        ];
    }
    
    /**
     * Write process telemetry to NDJSON journal (Блок 3 - Process Telemetry)
     * 
     * @param array $telemetry Process telemetry data
     * @return bool Success
     */
    public function writeProcessTelemetry(array $telemetry): bool
    {
        $entry = [
            'ts' => self::isoTimestamp(),
            'pid' => $telemetry['pid'] ?? getmypid(),
            'module' => $telemetry['module'] ?? 'unknown',
            'start' => $telemetry['start'] ?? null,
            'end' => $telemetry['end'] ?? date('Y-m-d H:i:s'),
            'exit_code' => $telemetry['exit_code'] ?? null,
            'memory_peak_mb' => $telemetry['memory_peak_mb'] ?? 0,
            'duration_ms' => $telemetry['duration_ms'] ?? 0,
            'success' => $telemetry['success'] ?? false,
            'killed' => $telemetry['killed'] ?? false,
            'killed_reason' => $telemetry['killed_reason'] ?? null,
        ];
        
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";
        return file_put_contents($this->processJournalFile, $line, FILE_APPEND) !== false;
    }
    
    /**
     * C3 FIX: Write process journal entry (start/finish phases)
     * 
     * Per ТЗ: process_journal.ndjson should have minimum 2 entries per run:
     * - start: {ts, run_id, phase:"start", module, status, pid}
     * - finish: {ts, run_id, phase:"finish", module, status, ok, duration_ms, signals_out, sim_closed, sim_rejected}
     * 
     * C-3: UI expects fields: module, status, duration_ms, pid, ts, success
     * 
     * @param array $data Entry data
     * @return bool Success
     */
    public function writeProcessJournalEntry(array $data): bool
    {
        $phase = $data['phase'] ?? 'unknown';
        
        // TASK 3: Map phase to status that UI expects
        $statusMap = [
            'start' => 'start',
            'finish' => 'finish', 
            'error' => 'error',
        ];
        
        // TASK 3: Ensure module is never empty - default to 'brain_pipeline'
        $module = $data['module'] ?? '';
        if (empty($module)) {
            $module = 'brain_pipeline';
        }
        
        // TASK 3: Required fields in unified format (all entries)
        $entry = [
            // TASK 3: Required fields in unified format (all entries)
            'ts' => $data['ts'] ?? self::isoTimestamp(),
            'module' => $module,
            'pid' => (int)($data['pid'] ?? getmypid()),
            'duration_ms' => (int)($data['duration_ms'] ?? 0),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
            'exit_code' => null,
            // TASK 3: success must always be present (for start: default true)
            'success' => (bool)($data['ok'] ?? ($phase === 'start')),
            'status' => $statusMap[$phase] ?? $phase,
            // Additional context
            'run_id' => $data['run_id'] ?? null,
            'phase' => $phase,
        ];
        
        // Add additional fields for finish phase
        if ($phase === 'finish') {
            $entry['signals_out'] = $data['signals_out'] ?? 0;
            $entry['sim_closed'] = $data['sim_closed'] ?? 0;
            $entry['sim_rejected'] = $data['sim_rejected'] ?? 0;
            // Use entry['success'] for consistency
            $entry['exit_code'] = $entry['success'] ? 0 : 1;
        } elseif ($phase === 'error') {
            $entry['exit_code'] = 1;
            $entry['success'] = false;
        }
        
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";
        return file_put_contents($this->processJournalFile, $line, FILE_APPEND) !== false;
    }
    
    /**
     * Read process telemetry from journal (Блок 3)
     * TASK 4: Backward compatibility with old format
     * 
     * @param int $limit Max entries to read (0 = all)
     * @return array Telemetry entries
     */
    public function readProcessTelemetry(int $limit = 100): array
    {
        if (!is_file($this->processJournalFile)) {
            return [];
        }
        
        $entries = [];
        foreach ($this->readFeedbackStream($this->processJournalFile) as $entry) {
            // TASK 4: Apply backward compatibility mappings for old format entries
            
            // If no module → set module='legacy'
            if (!isset($entry['module']) || empty($entry['module'])) {
                $entry['module'] = 'legacy';
            }
            
            // If no success but has ok → success=ok
            if (!isset($entry['success']) && isset($entry['ok'])) {
                $entry['success'] = (bool)$entry['ok'];
            } elseif (!isset($entry['success'])) {
                // Default success to false if completely missing
                $entry['success'] = false;
            }
            
            // If no duration_ms but has execution_time_ms → mapping
            if (!isset($entry['duration_ms']) && isset($entry['execution_time_ms'])) {
                $entry['duration_ms'] = (int)$entry['execution_time_ms'];
            } elseif (!isset($entry['duration_ms'])) {
                $entry['duration_ms'] = 0;
            }
            
            // Ensure pid is int or null
            if (!isset($entry['pid'])) {
                $entry['pid'] = null;
            }
            
            // Ensure memory_peak_mb exists
            if (!isset($entry['memory_peak_mb'])) {
                $entry['memory_peak_mb'] = 0;
            }
            
            // Ensure exit_code exists
            if (!isset($entry['exit_code'])) {
                $entry['exit_code'] = null;
            }
            
            // Ensure status exists
            if (!isset($entry['status'])) {
                // Try to derive from phase
                $entry['status'] = $entry['phase'] ?? 'unknown';
            }
            
            $entries[] = $entry;
            if ($limit > 0 && count($entries) >= $limit) {
                break;
            }
        }
        
        // Return most recent first
        return array_reverse($entries);
    }
    
    /**
     * Log event to NDJSON event journal (Блок 4 - Event Journal)
     * 
     * @param string $type Event type (RUN_START, RUN_END, etc.)
     * @param array $meta Event metadata
     * @param string|null $strategyId Related strategy ID
     * @param string|null $runId Related run ID
     * @return bool Success
     */
    public function logEvent(string $type, array $meta = [], ?string $strategyId = null, ?string $runId = null): bool
    {
        $event = [
            'ts' => self::isoTimestamp(),
            'type' => $type,
            'strategy_id' => $strategyId,
            'run_id' => $runId ?? ($meta['run_id'] ?? null),
            'meta' => $meta,
        ];
        
        $line = json_encode($event, JSON_UNESCAPED_UNICODE) . "\n";
        return file_put_contents($this->eventsFile, $line, FILE_APPEND) !== false;
    }
    
    /**
     * Read events from journal (Блок 4)
     * 
     * @param int $limit Max events to read
     * @param string|null $typeFilter Filter by event type
     * @return array Events (most recent first)
     */
    public function readEvents(int $limit = 100, ?string $typeFilter = null): array
    {
        if (!is_file($this->eventsFile)) {
            return [];
        }
        
        $events = [];
        foreach ($this->readFeedbackStream($this->eventsFile) as $event) {
            if ($typeFilter !== null && ($event['type'] ?? '') !== $typeFilter) {
                continue;
            }
            $events[] = $event;
        }
        
        // Return most recent first, limited
        $events = array_reverse($events);
        if ($limit > 0) {
            $events = array_slice($events, 0, $limit);
        }
        
        return $events;
    }
    
    /**
     * Get timeline of events for UI (Блок 5 - UI Timeline)
     * 
     * @param int $limit Max events
     * @return array Timeline data
     */
    public function getTimeline(int $limit = 50): array
    {
        return $this->readEvents($limit);
    }
    
    /**
     * A3: Test write permission by actually writing a temp file
     */
    private function testWritePermission(string $dir): bool
    {
        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }
        
        // Ensure tmp subdirectory exists
        $tmpDir = $dir . '/tmp';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }
        
        // Try to write a test file using cryptographically secure random suffix
        $randomSuffix = bin2hex(random_bytes(8));
        $testFile = $tmpDir . '/selftest_' . $randomSuffix . '.tmp';
        $written = @file_put_contents($testFile, 'test');
        
        if ($written !== false) {
            @unlink($testFile);
            return true;
        }
        
        return false;
    }
}

/* ==========================================================
   RULES (Tredercopis)
   1) CONFIG FIRST / ZERO-HARDCODE.
   2) Источник истины — код и файлы storage (не слова/описания).
   3) Любые пути — только через SystemPaths/PackMap; без жёстких относительных путей.
   4) LF-only.
========================================================== */

/* RULES
- BrainCoreTrait discovers module paths only via SystemPaths.
- Added optional discovery for Trading Bot storage key(s).
- LF only
*/
