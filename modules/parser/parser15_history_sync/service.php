<?php
/**
 * Parser 1.5 History Sync Service
 * 
 * Gap repair and backfill for Parser2 NDJSON history files.
 * 
 * Features:
 * - Detects gaps in Parser2 history (> gap_threshold_sec)
 * - Fetches missing 1-minute klines from Bybit API
 * - Appends missing data to Parser2 NDJSON files
 * - Never overwrites or deletes existing data
 * 
 * @see ТЗ: Parser 1.5 — History Sync (Backfill & Gap Repair)
 */

class Parser15HistorySyncService
{
    private string $basePath;
    private array $config;
    private string $parser1ActivePath;
    private string $parser2StoragePath;
    
    // Statistics for current run
    private int $symbolsChecked = 0;
    private int $symbolsWithGaps = 0;
    private int $pointsAdded = 0;
    private int $apiCalls = 0;
    private int $maxGapSec = 0;
    private array $errors = [];

    public function __construct()
    {
        $this->basePath = __DIR__;
        $this->config = require $this->basePath . '/config/config.php';
        
        // Paths to other parser modules
        $this->parser1ActivePath = dirname($this->basePath) . '/parser1_market_registry/storage/active.json';
        $this->parser2StoragePath = dirname($this->basePath) . '/parser2_history_accumulator/storage';
        
        // Ensure storage directory exists
        $storageDir = $this->basePath . '/storage';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }
        $logsDir = $storageDir . '/logs';
        if (!is_dir($logsDir)) {
            @mkdir($logsDir, 0755, true);
        }
    }

    /**
     * Main entry point for CronManager - alias for run()
     */
    public function execute(): array
    {
        return $this->run();
    }

    /**
     * Main entry point - run gap detection and repair
     */
    public function run(): array
    {
        $startTime = microtime(true);
        
        if (!$this->config['enabled']) {
            return $this->buildResult(false, 'disabled', $startTime);
        }

        // Load active symbols from Parser1
        $symbols = $this->loadActiveSymbols();
        if (empty($symbols)) {
            $this->errors[] = 'no_active_symbols';
            return $this->buildResult(false, 'no_symbols', $startTime);
        }

        $nowTs = time();
        $gapThreshold = $this->config['sync']['gap_threshold_sec'];

        // Check each symbol for gaps
        foreach ($symbols as $symbol) {
            $this->symbolsChecked++;
            
            try {
                $lastLocalTs = $this->getLastLocalTimestamp($symbol);
                
                if ($lastLocalTs === null) {
                    // No local history - this is a new symbol, skip for now
                    continue;
                }

                $gapSec = $nowTs - $lastLocalTs;
                
                if ($gapSec > $gapThreshold) {
                    $this->symbolsWithGaps++;
                    $this->maxGapSec = max($this->maxGapSec, $gapSec);
                    
                    // Fetch and append missing data
                    $added = $this->repairGap($symbol, $lastLocalTs, $nowTs);
                    $this->pointsAdded += $added;
                }
            } catch (Exception $e) {
                $this->errors[] = "Error processing {$symbol}: " . $e->getMessage();
            }
        }

        // Save statistics
        $this->saveStats();
        $this->saveState();

        return $this->buildResult(true, 'ok', $startTime);
    }

    /**
     * Load active symbols from Parser1
     */
    private function loadActiveSymbols(): array
    {
        if (!file_exists($this->parser1ActivePath)) {
            return [];
        }

        $content = file_get_contents($this->parser1ActivePath);
        if (empty($content)) {
            return [];
        }

        $data = json_decode($content, true);
        if ($data === null) {
            return [];
        }

        // Support multiple formats (same as Parser2)
        $symbols = [];
        
        if (is_array($data) && isset($data[0])) {
            if (is_string($data[0])) {
                // Format A: ["BTCUSDT", "ETHUSDT"]
                $symbols = $data;
            } elseif (is_array($data[0]) && isset($data[0]['symbol'])) {
                // Format B: [{"symbol": "BTCUSDT"}]
                foreach ($data as $item) {
                    if (isset($item['symbol'])) {
                        $symbols[] = $item['symbol'];
                    }
                }
            }
        } elseif (is_array($data)) {
            if (isset($data['symbols'])) {
                // Format C: {"symbols": ["BTCUSDT"]}
                $symbols = $data['symbols'];
            } elseif (isset($data['items'])) {
                // Format D: {"items": [{"symbol": "BTCUSDT"}]}
                foreach ($data['items'] as $item) {
                    if (isset($item['symbol'])) {
                        $symbols[] = $item['symbol'];
                    }
                }
            } else {
                // Format E: {"BTCUSDT": {...}}
                $symbols = array_keys($data);
            }
        }

        // Normalize and validate symbols
        $normalized = [];
        foreach ($symbols as $symbol) {
            $symbol = strtoupper(trim((string)$symbol));
            if (preg_match('/^[A-Z0-9]{2,}USDT$/', $symbol)) {
                $normalized[] = $symbol;
            }
        }

        return array_unique($normalized);
    }

    /**
     * Get last timestamp from local Parser2 history
     */
    private function getLastLocalTimestamp(string $symbol): ?int
    {
        $symbolDir = $this->parser2StoragePath . '/' . $symbol;
        
        if (!is_dir($symbolDir)) {
            return null;
        }

        // Find most recent NDJSON file
        $files = glob($symbolDir . '/*.ndjson');
        if (empty($files)) {
            return null;
        }

        // Sort by filename (date) descending
        rsort($files);
        $latestFile = $files[0];

        // Read last line to get most recent timestamp
        $lastLine = $this->getLastLine($latestFile);
        if (empty($lastLine)) {
            return null;
        }

        $data = json_decode($lastLine, true);
        return $data['ts_unix'] ?? null;
    }

    /**
     * Get last non-empty line of a file efficiently
     */
    private function getLastLine(string $filepath): ?string
    {
        $file = new SplFileObject($filepath, 'r');
        $file->seek(PHP_INT_MAX);
        $lastLineNum = $file->key();
        
        // Work backwards to find last non-empty line
        for ($i = $lastLineNum; $i >= 0; $i--) {
            $file->seek($i);
            $line = trim($file->current());
            if (!empty($line)) {
                return $line;
            }
        }
        
        return null;
    }

    /**
     * Repair gap by fetching missing data from Bybit
     */
    private function repairGap(string $symbol, int $lastLocalTs, int $nowTs): int
    {
        $maxBackfill = $this->config['sync']['max_backfill_minutes'] * 60;
        
        // Limit backfill range
        $startTs = max($lastLocalTs + 60, $nowTs - $maxBackfill);
        $endTs = $nowTs;

        // Fetch klines from Bybit
        $klines = $this->fetchBybitKlines($symbol, $startTs, $endTs);
        if (empty($klines)) {
            return 0;
        }

        // Get existing timestamps to avoid duplicates (use hash map for O(1) lookups)
        $existingTs = array_flip($this->getExistingTimestamps($symbol, $startTs, $endTs));

        // Append missing points
        $added = 0;
        foreach ($klines as $kline) {
            $ts = (int)($kline[0] / 1000); // Bybit returns milliseconds
            
            if (!isset($existingTs[$ts])) {
                if ($this->appendToParser2($symbol, $ts, (float)$kline[4])) { // Close price
                    $added++;
                }
            }
        }

        return $added;
    }

    /**
     * Fetch klines from Bybit API
     */
    private function fetchBybitKlines(string $symbol, int $startTs, int $endTs): array
    {
        $this->apiCalls++;
        
        $url = $this->config['bybit']['base_url'] . $this->config['bybit']['endpoint'];
        $params = [
            'category' => 'linear',
            'symbol' => $symbol,
            'interval' => $this->config['bybit']['interval'],
            'start' => $startTs * 1000, // Convert to milliseconds
            'end' => $endTs * 1000,
            'limit' => $this->config['bybit']['max_points_per_request'],
        ];

        $url .= '?' . http_build_query($params);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->config['bybit']['timeout_sec'],
                'header' => 'Accept: application/json',
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $this->errors[] = "API call failed for {$symbol}";
            return [];
        }

        $data = json_decode($response, true);
        if (!isset($data['result']['list'])) {
            return [];
        }

        return $data['result']['list'];
    }

    /**
     * Get existing timestamps for a symbol in date range
     */
    private function getExistingTimestamps(string $symbol, int $startTs, int $endTs): array
    {
        $timestamps = [];
        $symbolDir = $this->parser2StoragePath . '/' . $symbol;
        
        if (!is_dir($symbolDir)) {
            return $timestamps;
        }

        // Get relevant date files
        $startDate = date('Y-m-d', $startTs);
        $endDate = date('Y-m-d', $endTs);
        
        $currentDate = $startDate;
        while ($currentDate <= $endDate) {
            $file = $symbolDir . '/' . $currentDate . '.ndjson';
            if (file_exists($file)) {
                $handle = fopen($file, 'r');
                while (($line = fgets($handle)) !== false) {
                    $data = json_decode(trim($line), true);
                    if (isset($data['ts_unix'])) {
                        $ts = (int)$data['ts_unix'];
                        if ($ts >= $startTs && $ts <= $endTs) {
                            $timestamps[] = $ts;
                        }
                    }
                }
                fclose($handle);
            }
            $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
        }

        return $timestamps;
    }

    /**
     * Append a data point to Parser2 NDJSON file
     * @return bool True if successful, false on failure
     */
    private function appendToParser2(string $symbol, int $tsUnix, float $price): bool
    {
        $date = date('Y-m-d', $tsUnix);
        $symbolDir = $this->parser2StoragePath . '/' . $symbol;
        
        // Create directory if needed
        if (!is_dir($symbolDir)) {
            if (!mkdir($symbolDir, 0755, true)) {
                $this->errors[] = "Failed to create directory: {$symbolDir}";
                return false;
            }
        }

        $file = $symbolDir . '/' . $date . '.ndjson';
        
        $record = [
            'ts' => date('c', $tsUnix),
            'ts_unix' => $tsUnix,
            'symbol' => $symbol,
            'last_price' => $price,
            'source' => 'parser1.5_backfill',
        ];

        $result = file_put_contents($file, json_encode($record) . "\n", FILE_APPEND | LOCK_EX);
        if ($result === false) {
            $this->errors[] = "Failed to write to file: {$file}";
            return false;
        }
        
        return true;
    }

    /**
     * Save run statistics
     */
    private function saveStats(): void
    {
        $statsFile = $this->basePath . '/storage/stats.json';
        
        $stats = [
            'symbols_checked' => $this->symbolsChecked,
            'symbols_with_gaps' => $this->symbolsWithGaps,
            'points_added' => $this->pointsAdded,
            'api_calls' => $this->apiCalls,
            'max_gap_sec' => $this->maxGapSec,
            'last_run_ts' => time(),
        ];

        $result = file_put_contents($statsFile, json_encode($stats, JSON_PRETTY_PRINT));
        if ($result === false) {
            $this->errors[] = "Failed to write stats file: {$statsFile}";
        }
    }

    /**
     * Save state for next run
     */
    private function saveState(): void
    {
        $stateFile = $this->basePath . '/storage/state.json';
        
        $state = [
            'last_global_sync_ts' => time(),
            'last_run_stats' => [
                'symbols_checked' => $this->symbolsChecked,
                'points_added' => $this->pointsAdded,
            ],
        ];

        $result = file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
        if ($result === false) {
            $this->errors[] = "Failed to write state file: {$stateFile}";
        }
    }

    /**
     * Build result array
     */
    private function buildResult(bool $ok, string $status, float $startTime): array
    {
        $result = [
            'ts' => date('c'),
            'ok' => $ok,
            'status' => $status,
            'duration_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'symbols_checked' => $this->symbolsChecked,
            'symbols_with_gaps' => $this->symbolsWithGaps,
            'points_added' => $this->pointsAdded,
            'api_calls' => $this->apiCalls,
            'max_gap_sec' => $this->maxGapSec,
            'errors_count' => count($this->errors),
            'errors' => array_slice($this->errors, 0, 10),
        ];

        // Save last_run.json
        $lastRunFile = $this->basePath . '/storage/last_run.json';
        $writeResult = file_put_contents($lastRunFile, json_encode($result, JSON_PRETTY_PRINT));
        if ($writeResult === false) {
            // Can't add to errors array as it would change result, just log
            error_log("Parser1.5: Failed to write last_run file: {$lastRunFile}");
        }

        return $result;
    }
}

/* RULES
- Purpose: Gap repair and backfill for Parser2 NDJSON history
- CronManager calls: execute()
- CLI runner calls: execute() via runner.php
- API: Bybit public kline API
- Storage: Appends to Parser2 NDJSON files
- Prohibitions:
  - Never delete or overwrite existing data
  - Never modify Parser2 storage structure
*/
