<?php

declare(strict_types=1);

namespace Modules\System\Brain;

use Core\System\SystemPaths;

/**
 * Brain Process Runner - Process Wrapper Layer (Блок 1 + v2.1 Block A)
 * 
 * Provides isolated process execution with:
 * - Timeout handling
 * - PID tracking
 * - Execution time logging
 * - Exit code monitoring
 * - Hung process termination
 * - Memory limit enforcement (Block A: Resource Guard)
 * - Peak memory logging (Block A)
 * 
 * @see TЗ Brain v2.0 - Блок 1: Изоляция исполнения (Process Layer)
 * @see TЗ Brain v2.1 - Block A: Resource Guard
 */
final class BrainProcessRunner
{
    private static ?self $instance = null;
    
    private string $logFile;
    private array $runningProcesses = [];
    private int $defaultTimeout = 300; // 5 minutes default
    
    // ========================================================================
    // Block A: Resource Guard Constants (v2.1)
    // ========================================================================
    
    /** Default memory limit in MB for child processes */
    private const DEFAULT_MEMORY_LIMIT_MB = 256;
    
    /** Memory check interval in microseconds (500ms) */
    private const MEMORY_CHECK_INTERVAL_US = 500000;
    
    /** Exit code for timeout kills */
    private const EXIT_CODE_TIMEOUT = -1;
    
    /** Exit code for memory limit kills */
    private const EXIT_CODE_MEMORY_KILL = -2;
    
    /** Memory limit in MB (configurable) */
    private int $memoryLimitMb;
    
    private function __construct()
    {
        $brainStorage = SystemPaths::instance()->get('system.brain.storage');
        $this->logFile = $brainStorage . '/logs/process.log';
        $this->memoryLimitMb = self::DEFAULT_MEMORY_LIMIT_MB;
        $this->ensureLogDir();
    }
    
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Ensure log directory exists
     */
    private function ensureLogDir(): void
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
    
    /**
     * Set memory limit for child processes (Block A: Resource Guard)
     * 
     * @param int $mb Memory limit in megabytes
     */
    public function setMemoryLimit(int $mb): void
    {
        $this->memoryLimitMb = max(32, $mb); // Minimum 32MB
    }
    
    /**
     * Get current memory limit
     * 
     * @return int Memory limit in MB
     */
    public function getMemoryLimit(): int
    {
        return $this->memoryLimitMb;
    }
    
    /**
     * Run a module in an isolated process
     * 
     * Block A: Resource Guard - includes memory monitoring
     * 
     * @param string $module Module name (parser5, simulator, etc.)
     * @param array $args Command arguments
     * @param int|null $timeout Timeout in seconds (null = default)
     * @param int|null $memoryLimitMb Memory limit in MB (null = default)
     * @return array Execution result
     */
    public function run(string $module, array $args = [], ?int $timeout = null, ?int $memoryLimitMb = null): array
    {
        $timeout = $timeout ?? $this->defaultTimeout;
        $memoryLimit = $memoryLimitMb ?? $this->memoryLimitMb;
        $startTime = microtime(true);
        $processId = uniqid('proc_', true);
        
        $result = [
            'process_id' => $processId,
            'module' => $module,
            'started_at' => date('Y-m-d H:i:s'),
            'timeout' => $timeout,
            'memory_limit_mb' => $memoryLimit, // Block A: Resource Guard
            'success' => false,
            'pid' => null,
            'exit_code' => null,
            'output' => '',
            'error' => null,
            'execution_time_ms' => 0,
            'peak_memory_mb' => 0, // Block A: Resource Guard
            'killed' => false,
            'killed_reason' => null, // Block A: 'timeout' or 'memory'
        ];
        
        $this->log("Starting process: {$module} [{$processId}] (memory_limit={$memoryLimit}MB)", 'info');
        
        // Build command
        $scriptPath = $this->findModuleScript($module);
        if ($scriptPath === null) {
            $result['error'] = "Module script not found: {$module}";
            $this->log("Error: {$result['error']}", 'error');
            return $result;
        }
        
        $command = $this->buildCommand($scriptPath, $args, $memoryLimit);
        
        try {
            // Execute with process isolation and memory monitoring (Block A)
            $execResult = $this->executeWithTimeout($command, $timeout, $memoryLimit);
            
            $result['pid'] = $execResult['pid'];
            $result['exit_code'] = $execResult['exit_code'];
            $result['output'] = $execResult['output'];
            $result['killed'] = $execResult['killed'];
            $result['killed_reason'] = $execResult['killed_reason'] ?? null;
            $result['peak_memory_mb'] = $execResult['peak_memory_mb'] ?? 0;
            $result['success'] = $execResult['exit_code'] === 0 && !$execResult['killed'];
            
            if ($execResult['killed']) {
                if ($execResult['killed_reason'] === 'memory') {
                    $result['error'] = "Process killed due to memory limit exceeded ({$memoryLimit}MB, peak: {$execResult['peak_memory_mb']}MB)";
                } else {
                    $result['error'] = "Process killed due to timeout ({$timeout}s)";
                }
            } elseif ($execResult['exit_code'] !== 0) {
                $result['error'] = "Process exited with code: {$execResult['exit_code']}";
            }
            
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
            $this->log("Exception: {$e->getMessage()}", 'error');
        }
        
        $result['execution_time_ms'] = round((microtime(true) - $startTime) * 1000);
        $result['finished_at'] = date('Y-m-d H:i:s');
        
        // Log completion with peak memory (Block A)
        $status = $result['success'] ? 'ok' : 'failed';
        $this->log(
            "Process completed: {$module} [{$processId}] - " .
            "status={$status}, pid={$result['pid']}, " .
            "exit_code={$result['exit_code']}, " .
            "time={$result['execution_time_ms']}ms, " .
            "peak_memory={$result['peak_memory_mb']}MB" .
            ($result['killed'] ? " [KILLED:{$result['killed_reason']}]" : ''),
            $result['success'] ? 'info' : 'error'
        );
        
        // v2.2 Блок 3: Write process telemetry to NDJSON journal
        $this->writeProcessJournal($result);
        
        return $result;
    }
    
    /**
     * Write process telemetry to NDJSON journal (v2.2 Блок 3 - Process Telemetry)
     * TASK 3: Unified format with all required fields
     * 
     * @param array $result Process execution result
     */
    private function writeProcessJournal(array $result): void
    {
        $brainStorage = SystemPaths::instance()->get('system.brain.storage');
        $journalFile = $brainStorage . '/process_journal.ndjson';
        
        // TASK 3: Ensure module is never 'unknown' - default to 'brainprocessrunner'
        $module = $result['module'] ?? '';
        if (empty($module) || $module === 'unknown') {
            $module = 'brainprocessrunner';
        }
        
        $entry = [
            // TASK 3: Required fields in unified format
            'ts' => BrainService::isoTimestamp(),
            'module' => $module,
            'pid' => $result['pid'] ?? null,
            // Note: Input uses 'execution_time_ms', output uses 'duration_ms' for UI compatibility
            'duration_ms' => (int)($result['execution_time_ms'] ?? 0),
            'memory_peak_mb' => (float)($result['peak_memory_mb'] ?? 0),
            'exit_code' => $result['exit_code'] ?? null,
            'success' => (bool)($result['success'] ?? false),
            // TASK 3: Status for process executions is 'process'
            'status' => 'process',
            // Additional context fields
            'start' => $result['started_at'] ?? null,
            'end' => $result['finished_at'] ?? date('Y-m-d H:i:s'),
            'killed' => $result['killed'] ?? false,
            'killed_reason' => $result['killed_reason'] ?? null,
            'memory_limit_mb' => $result['memory_limit_mb'] ?? null,
        ];
        
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($journalFile, $line, FILE_APPEND);
    }
    
    /**
     * Execute command with timeout and memory monitoring (Block A: Resource Guard)
     * 
     * @param string $command Command to execute
     * @param int $timeout Timeout in seconds
     * @param int $memoryLimitMb Memory limit in MB
     * @return array Execution result with pid, exit_code, output, killed, killed_reason, peak_memory_mb
     */
    private function executeWithTimeout(string $command, int $timeout, int $memoryLimitMb): array
    {
        $result = [
            'pid' => null,
            'exit_code' => null,
            'output' => '',
            'killed' => false,
            'killed_reason' => null,
            'peak_memory_mb' => 0,
        ];
        
        // Create process with proc_open for better control
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];
        
        $process = proc_open($command, $descriptors, $pipes);
        
        if (!is_resource($process)) {
            throw new \RuntimeException("Failed to start process: {$command}");
        }
        
        // Get process status
        $status = proc_get_status($process);
        $result['pid'] = $status['pid'];
        
        $this->runningProcesses[$status['pid']] = [
            'process' => $process,
            'command' => $command,
            'started_at' => time(),
            'timeout' => $timeout,
        ];
        
        // Close stdin
        fclose($pipes[0]);
        
        // Set streams to non-blocking
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        
        $startTime = time();
        $output = '';
        $errorOutput = '';
        $peakMemoryMb = 0;
        
        // Poll for completion, timeout, or memory limit (Block A)
        while (true) {
            $status = proc_get_status($process);
            
            // Read available output
            $output .= stream_get_contents($pipes[1]);
            $errorOutput .= stream_get_contents($pipes[2]);
            
            // Check if process has exited
            if (!$status['running']) {
                $result['exit_code'] = $status['exitcode'];
                break;
            }
            
            // Block A: Check memory usage
            $currentMemoryMb = $this->getProcessMemory($status['pid']);
            if ($currentMemoryMb > $peakMemoryMb) {
                $peakMemoryMb = $currentMemoryMb;
            }
            
            // Block A: Kill if memory limit exceeded
            if ($currentMemoryMb > $memoryLimitMb) {
                $this->killProcess($status['pid']);
                $result['killed'] = true;
                $result['killed_reason'] = 'memory';
                $result['exit_code'] = self::EXIT_CODE_MEMORY_KILL;
                $this->log("Process killed due to memory limit: PID={$status['pid']}, memory={$currentMemoryMb}MB, limit={$memoryLimitMb}MB", 'warning');
                break;
            }
            
            // Check timeout
            if ((time() - $startTime) >= $timeout) {
                // Kill the process
                $this->killProcess($status['pid']);
                $result['killed'] = true;
                $result['killed_reason'] = 'timeout';
                $result['exit_code'] = self::EXIT_CODE_TIMEOUT;
                $this->log("Process killed due to timeout: PID={$status['pid']}", 'warning');
                break;
            }
            
            // Small sleep to avoid busy waiting (use constant interval)
            usleep(self::MEMORY_CHECK_INTERVAL_US);
        }
        
        // Store peak memory
        $result['peak_memory_mb'] = $peakMemoryMb;
        
        // Close pipes
        fclose($pipes[1]);
        fclose($pipes[2]);
        
        // Close process
        proc_close($process);
        
        // Combine output
        $result['output'] = trim($output . ($errorOutput ? "\n[STDERR]\n{$errorOutput}" : ''));
        
        // Remove from running processes
        unset($this->runningProcesses[$result['pid']]);
        
        // Log peak memory (Block A)
        $this->log("Process peak memory: PID={$result['pid']}, peak_memory={$peakMemoryMb}MB", 'info');
        
        return $result;
    }
    
    /**
     * Get memory usage of a process in MB (Block A: Resource Guard)
     * 
     * @param int $pid Process ID
     * @return int Memory usage in MB
     */
    private function getProcessMemory(int $pid): int
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows: use tasklist to get memory usage
            $output = [];
            exec("tasklist /FI \"PID eq {$pid}\" /FO CSV /NH 2>&1", $output);
            if (!empty($output[0])) {
                // Format: "processname","PID","Session Name","Session#","Mem Usage"
                // Memory format may include commas (e.g., "1,234 K"), so we strip all non-digits
                $parts = str_getcsv($output[0]);
                if (isset($parts[4])) {
                    // Remove "K" suffix, commas, and spaces - extract only digits, then convert KB to MB
                    $memKb = (int)preg_replace('/[^\d]/', '', $parts[4]);
                    return (int)($memKb / 1024);
                }
            }
            return 0;
        }
        
        // Linux/Unix: read from /proc/{pid}/status
        $statusFile = "/proc/{$pid}/status";
        if (is_readable($statusFile)) {
            $content = @file_get_contents($statusFile);
            if ($content && preg_match('/VmRSS:\s+(\d+)\s+kB/', $content, $matches)) {
                return (int)($matches[1] / 1024);
            }
        }
        
        // Fallback: try ps command
        $output = [];
        exec("ps -o rss= -p {$pid} 2>&1", $output);
        if (!empty($output[0])) {
            $rssKb = (int)trim($output[0]);
            return (int)($rssKb / 1024);
        }
        
        return 0;
    }
    
    /**
     * Kill a process by PID
     * 
     * @param int $pid Process ID
     * @return bool Success
     */
    public function killProcess(int $pid): bool
    {
        $this->log("Killing process: PID={$pid}", 'warning');
        
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows
            exec("taskkill /F /PID {$pid} 2>&1", $output, $code);
        } else {
            // Unix/Linux/Mac
            // First try SIGTERM
            posix_kill($pid, SIGTERM);
            usleep(500000); // 500ms grace period
            
            // Check if still running
            if (posix_kill($pid, 0)) {
                // Force kill with SIGKILL
                posix_kill($pid, SIGKILL);
            }
            $code = 0;
        }
        
        $success = $code === 0;
        $this->log("Kill result: PID={$pid}, success=" . ($success ? 'true' : 'false'), 'info');
        
        return $success;
    }
    
    /**
     * Kill all running processes
     * 
     * @return array Results of kill operations
     */
    public function killAll(): array
    {
        $results = [];
        
        foreach ($this->runningProcesses as $pid => $info) {
            $results[$pid] = $this->killProcess($pid);
        }
        
        $this->runningProcesses = [];
        
        return $results;
    }
    
    /**
     * Get list of running processes
     * 
     * @return array Running processes
     */
    public function getRunningProcesses(): array
    {
        $processes = [];
        
        foreach ($this->runningProcesses as $pid => $info) {
            $runningTime = time() - $info['started_at'];
            $isHung = $runningTime > $info['timeout'];
            
            $processes[$pid] = [
                'pid' => $pid,
                'command' => $info['command'],
                'started_at' => date('Y-m-d H:i:s', $info['started_at']),
                'running_time_sec' => $runningTime,
                'timeout' => $info['timeout'],
                'is_hung' => $isHung,
            ];
        }
        
        return $processes;
    }
    
    /**
     * Check if a specific process is hung
     * 
     * @param int $pid Process ID
     * @return bool True if hung
     */
    public function isProcessHung(int $pid): bool
    {
        if (!isset($this->runningProcesses[$pid])) {
            return false;
        }
        
        $info = $this->runningProcesses[$pid];
        $runningTime = time() - $info['started_at'];
        
        return $runningTime > $info['timeout'];
    }
    
    /**
     * Kill all hung processes
     * 
     * @return array Killed process PIDs
     */
    public function killHungProcesses(): array
    {
        $killed = [];
        
        foreach ($this->runningProcesses as $pid => $info) {
            if ($this->isProcessHung($pid)) {
                if ($this->killProcess($pid)) {
                    $killed[] = $pid;
                }
            }
        }
        
        return $killed;
    }
    
    /**
     * Find module script path
     * 
     * @param string $module Module name
     * @return string|null Script path or null if not found
     */
        private function findModuleScript(string $module): ?string
    {
        $paths = SystemPaths::instance();
        $root = $paths->get('root');

        // Resolve by PackMap keys first (preferred), then fallback to root-based well-known locations
        $candidates = [];

        if ($module === 'parser5') {
            $candidates = array_merge(
                $this->resolveScriptsByModuleKey($paths, 'parser.parser5_signal_monitor', ['runner.php', 'run.php', 'service.php']),
                [
                    $root . '/modules/parser/parser5_signal_monitor/runner.php',
                    $root . '/modules/parser/parser5_signal_monitor/run.php',
                ]
            );
        } elseif ($module === 'simulator') {
            $candidates = array_merge(
                $this->resolveScriptsByModuleKey($paths, 'simulator.parser6_simulator', ['runner.php', 'run.php', 'service.php']),
                [
                    $root . '/modules/simulator/parser6_simulator/runner.php',
                    $root . '/modules/simulator/parser6_simulator/run.php',
                ]
            );
        } elseif ($module === 'executor') {
            $candidates = array_merge(
                $this->resolveScriptsByModuleKey($paths, 'trading.executor_bot', ['executor_runner.php', 'runner.php', 'run.php', 'cron_handler.php', 'service.php']),
                [
                    $root . '/modules/trading/executor_bot/executor_runner.php',
                    $root . '/modules/trading/executor_bot/cron_handler.php',
                ]
            );
        }

        // Normalize, unique, and return first existing file
        $candidates = array_values(array_unique(array_filter($candidates, static fn($v) => is_string($v) && $v !== '')));

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param SystemPaths $paths
     * @param string $moduleKey PackMap base key, e.g. "simulator.parser6_simulator"
     * @param array<int,string> $filenames
     * @return array<int,string>
     */
    private function resolveScriptsByModuleKey(SystemPaths $paths, string $moduleKey, array $filenames): array
    {
        try {
            $base = $paths->get($moduleKey);
            $out = [];
            foreach ($filenames as $f) {
                $out[] = $base . '/' . $f;
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    /**
     * Build command string (Block A: includes PHP memory_limit directive)
     * 
     * @param string $scriptPath Script path
     * @param array $args Arguments
     * @param int $memoryLimitMb Memory limit in MB
     * @return string Command string
     */
    private function buildCommand(string $scriptPath, array $args, int $memoryLimitMb): string
    {
        $phpBinary = defined('PHP_BINARY') ? PHP_BINARY : 'php';
        
        // Block A: Set PHP memory limit via -d directive
        $cmd = escapeshellarg($phpBinary) . 
               " -d memory_limit={$memoryLimitMb}M " . 
               escapeshellarg($scriptPath);
        
        foreach ($args as $key => $value) {
            if (is_int($key)) {
                // Positional argument
                $cmd .= ' ' . escapeshellarg((string)$value);
            } else {
                // Named argument
                $cmd .= ' --' . $key . '=' . escapeshellarg((string)$value);
            }
        }
        
        $cmd .= ' 2>&1';
        
        return $cmd;
    }
    
    /**
     * Set default timeout
     * 
     * @param int $seconds Timeout in seconds
     */
    public function setDefaultTimeout(int $seconds): void
    {
        $this->defaultTimeout = max(1, $seconds);
    }
    
    /**
     * Get default timeout
     * 
     * @return int Timeout in seconds
     */
    public function getDefaultTimeout(): int
    {
        return $this->defaultTimeout;
    }
    
    /**
     * Get process log contents
     * 
     * @param int $lines Number of lines (0 = all)
     * @return string Log contents
     */
    public function getLog(int $lines = 100): string
    {
        if (!is_file($this->logFile)) {
            return '';
        }
        
        if ($lines === 0) {
            return file_get_contents($this->logFile) ?: '';
        }
        
        // Get last N lines
        $file = new \SplFileObject($this->logFile, 'r');
        $file->seek(PHP_INT_MAX);
        $lastLine = $file->key();
        
        $start = max(0, $lastLine - $lines);
        $output = [];
        
        $file->seek($start);
        while (!$file->eof()) {
            $line = $file->fgets();
            if ($line !== false) {
                $output[] = rtrim($line);
            }
        }
        
        return implode("\n", $output);
    }
    
    /**
     * Clear process log
     */
    public function clearLog(): void
    {
        if (is_file($this->logFile)) {
            file_put_contents($this->logFile, '');
        }
    }
    
    /**
     * Write to process log
     * 
     * @param string $message Message
     * @param string $level Log level
     */
    private function log(string $message, string $level = 'info'): void
    {
        $this->ensureLogDir();
        
        // Use microtime(true) and format properly for reliable millisecond extraction
        $microtime = microtime(true);
        $milliseconds = sprintf('%03d', ($microtime - floor($microtime)) * 1000);
        $timestamp = date('Y-m-d H:i:s', (int)$microtime) . '.' . $milliseconds;
        
        $line = "[{$timestamp}] [{$level}] {$message}\n";
        
        file_put_contents($this->logFile, $line, FILE_APPEND);
    }
}

/* RULES
 * CONFIG FIRST / ZERO-HARDCODE
 * SystemPaths / PackMap ONLY (no local path computations)
 */
