<?php
declare(strict_types=1);

namespace Modules\System\ProfitManager\Lib;

/**
 * Profit Manager Store
 * 
 * Storage operations for Profit Manager.
 * Handles all file I/O for status, applied events, locks.
 */
class Store
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
        $dirs = [
            $this->storageDir,
            $this->storageDir . '/runtime',
            $this->storageDir . '/logs',
        ];
        
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
    }
    
    // =========================================================================
    // Last Run
    // =========================================================================
    
    /**
     * Save last run result
     */
    public function saveLastRun(array $result): void
    {
        $path = $this->storageDir . '/runtime/last_run.json';
        $this->writeJson($path, $result);
    }
    
    /**
     * Load last run result
     */
    public function loadLastRun(): array
    {
        $path = $this->storageDir . '/runtime/last_run.json';
        return $this->readJson($path);
    }
    
    // =========================================================================
    // Status (per-symbol state)
    // =========================================================================
    
    /**
     * Save status for all managed symbols
     */
    public function saveStatus(array $status): void
    {
        $path = $this->storageDir . '/runtime/status.json';
        $this->writeJson($path, [
            'ts' => date('c'),
            'symbols' => $status,
        ]);
    }
    
    /**
     * Load status
     */
    public function loadStatus(): array
    {
        $path = $this->storageDir . '/runtime/status.json';
        $data = $this->readJson($path);
        return $data['symbols'] ?? [];
    }
    
    /**
     * Get status for a single symbol
     */
    public function getSymbolStatus(string $symbol): ?array
    {
        $status = $this->loadStatus();
        return $status[$symbol] ?? null;
    }
    
    /**
     * Update status for a single symbol
     */
    public function updateSymbolStatus(string $symbol, array $data): void
    {
        $status = $this->loadStatus();
        $status[$symbol] = array_merge($status[$symbol] ?? [], $data, [
            'last_seen_ts' => time(),
        ]);
        $this->saveStatus($status);
    }
    
    // =========================================================================
    // Applied Index (ring buffer)
    // =========================================================================
    
    /**
     * Load applied index
     */
    public function loadAppliedIndex(): array
    {
        $path = $this->storageDir . '/runtime/applied_index.json';
        $data = $this->readJson($path);
        return $data['events'] ?? [];
    }
    
    /**
     * Add event to applied index
     */
    public function addAppliedEvent(array $event): void
    {
        $path = $this->storageDir . '/runtime/applied_index.json';
        $events = $this->loadAppliedIndex();
        
        // Add timestamp
        $event['ts'] = date('c');
        
        // Append event
        $events[] = $event;
        
        // Trim to max items (ring buffer)
        $maxItems = $this->config['runtime']['applied_index_max_items'] ?? 500;
        if (count($events) > $maxItems) {
            $events = array_slice($events, -$maxItems);
        }
        
        $this->writeJson($path, [
            'updated_at' => date('c'),
            'events' => array_values($events),
        ]);
    }
    
    /**
     * Get last applied event for symbol
     */
    public function getLastAppliedEvent(string $symbol): ?array
    {
        $events = $this->loadAppliedIndex();
        
        // Find last event for this symbol
        $lastEvent = null;
        foreach ($events as $event) {
            if (($event['symbol'] ?? '') === $symbol) {
                $lastEvent = $event;
            }
        }
        
        return $lastEvent;
    }
    
    // =========================================================================
    // Locks (anti-spam per symbol)
    // =========================================================================
    
    /**
     * Load locks
     */
    public function loadLocks(): array
    {
        $path = $this->storageDir . '/runtime/locks.json';
        return $this->readJson($path);
    }
    
    /**
     * Save locks
     */
    public function saveLocks(array $locks): void
    {
        $path = $this->storageDir . '/runtime/locks.json';
        $this->writeJson($path, [
            'updated_at' => date('c'),
            'symbols' => $locks,
        ]);
    }
    
    /**
     * Check if symbol is locked (cooldown)
     */
    public function isSymbolLocked(string $symbol): bool
    {
        $locks = $this->loadLocks();
        $symbolLock = $locks['symbols'][$symbol] ?? null;
        
        if ($symbolLock === null) {
            return false;
        }
        
        $lastUpdate = $symbolLock['last_update_ts'] ?? 0;
        $cooldown = $this->config['limits']['min_seconds_between_updates_per_symbol'] ?? 15;
        
        return (time() - $lastUpdate) < $cooldown;
    }
    
    /**
     * Lock symbol (set cooldown)
     */
    public function lockSymbol(string $symbol): void
    {
        $locks = $this->loadLocks();
        $locks['symbols'][$symbol] = [
            'last_update_ts' => time(),
        ];
        $this->saveLocks($locks);
    }
    
    /**
     * Get cooldown remaining for symbol (seconds)
     */
    public function getSymbolCooldownRemaining(string $symbol): int
    {
        $locks = $this->loadLocks();
        $symbolLock = $locks['symbols'][$symbol] ?? null;
        
        if ($symbolLock === null) {
            return 0;
        }
        
        $lastUpdate = $symbolLock['last_update_ts'] ?? 0;
        $cooldown = $this->config['limits']['min_seconds_between_updates_per_symbol'] ?? 15;
        $remaining = $cooldown - (time() - $lastUpdate);
        
        return max(0, $remaining);
    }
    
    // =========================================================================
    // Run Lock
    // =========================================================================
    
    /**
     * Acquire run lock
     * 
     * @return resource|false File handle on success, false if locked
     */
    public function acquireRunLock()
    {
        if (!($this->config['runtime']['run_lock_enabled'] ?? true)) {
            return true; // Lock disabled, return truthy value
        }
        
        $lockFile = $this->storageDir . '/' . ($this->config['runtime']['run_lock_file'] ?? 'runtime/exec.lock');
        $lockDir = dirname($lockFile);
        
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0755, true);
        }
        
        $fp = @fopen($lockFile, 'c');
        if ($fp === false) {
            return false;
        }
        
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return false;
        }
        
        // Write PID for debugging
        ftruncate($fp, 0);
        fwrite($fp, (string) getmypid());
        fflush($fp);
        
        return $fp;
    }
    
    /**
     * Release run lock
     */
    public function releaseRunLock($lockHandle): void
    {
        if ($lockHandle === true) {
            return; // Lock was disabled
        }
        
        if (is_resource($lockHandle)) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
    
    // =========================================================================
    // Error Log
    // =========================================================================
    
    /**
     * Log error
     */
    public function logError(string $message, array $context = []): void
    {
        $path = $this->storageDir . '/logs/error.log';
        
        $line = sprintf(
            "[%s] %s %s\n",
            date('Y-m-d H:i:s'),
            $message,
            !empty($context) ? json_encode($context, JSON_UNESCAPED_SLASHES) : ''
        );
        
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }
    
    // =========================================================================
    // Helpers
    // =========================================================================
    
    /**
     * Write JSON file atomically
     */
    private function writeJson(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        if ($this->config['write']['atomic'] ?? true) {
            $tmp = $path . '.tmp.' . getmypid();
            file_put_contents($tmp, $json, LOCK_EX);
            rename($tmp, $path);
        } else {
            file_put_contents($path, $json, LOCK_EX);
        }
    }
    
    /**
     * Read JSON file
     */
    private function readJson(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        
        $content = @file_get_contents($path);
        if ($content === false || $content === '') {
            return [];
        }
        
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    // =========================================================================
    // Shadow trailing runtime state (PM shadow mode)
    // =========================================================================

    /**
     * Load shadow state for a trade key (trade_id or position key).
     *
     * @param string $tradeKey
     * @return array
     */
    public function loadShadowState(string $tradeKey): array
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $tradeKey);
        $path = $this->storageDir . '/runtime/shadow_' . $safe . '.json';
        return $this->readJson($path);
    }

    /**
     * Save shadow state for a trade key.
     *
     * @param string $tradeKey
     * @param array  $state
     */
    public function saveShadowState(string $tradeKey, array $state): void
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $tradeKey);
        $path = $this->storageDir . '/runtime/shadow_' . $safe . '.json';
        $this->writeJson($path, $state);
    }

    /**
     * Save shadow journal (last run shadow diagnostics).
     *
     * @param array $journal
     */
    public function saveShadowJournal(array $journal): void
    {
        $path = $this->storageDir . '/runtime/shadow_journal.json';
        $this->writeJson($path, $journal);
    }

    /**
     * Load shadow journal.
     *
     * @return array
     */
    public function loadShadowJournal(): array
    {
        $path = $this->storageDir . '/runtime/shadow_journal.json';
        return $this->readJson($path);
    }

    // =========================================================================
    // Comparison metrics (PM shadow vs bot trailing)
    // =========================================================================

    /**
     * Load aggregate comparison metrics (accumulated across runs).
     *
     * @return array
     */
    public function loadComparisonMetrics(): array
    {
        $path = $this->storageDir . '/runtime/shadow_comparison_metrics.json';
        return $this->readJson($path);
    }

    /**
     * Save aggregate comparison metrics.
     *
     * @param array $metrics
     */
    public function saveComparisonMetrics(array $metrics): void
    {
        $path = $this->storageDir . '/runtime/shadow_comparison_metrics.json';
        $this->writeJson($path, $metrics);
    }

    /**
     * Save a per-trade closed comparison snapshot (lightweight).
     *
     * @param string $tradeKey
     * @param array  $snapshot
     */
    public function saveComparisonSnapshot(string $tradeKey, array $snapshot): void
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $tradeKey);
        $path = $this->storageDir . '/runtime/snapshot_' . $safe . '.json';
        $this->writeJson($path, $snapshot);
    }

    /**
     * Load a per-trade closed comparison snapshot.
     *
     * @param string $tradeKey
     * @return array
     */
    public function loadComparisonSnapshot(string $tradeKey): array
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $tradeKey);
        $path = $this->storageDir . '/runtime/snapshot_' . $safe . '.json';
        return $this->readJson($path);
    }

    /**
     * Load all shadow state keys (trade keys that have a shadow state file).
     *
     * @return string[]
     */
    public function loadShadowStateKeys(): array
    {
        $dir = $this->storageDir . '/runtime';
        if (!is_dir($dir)) {
            return [];
        }
        $keys = [];
        foreach (glob($dir . '/shadow_*.json') as $path) {
            $base = basename($path, '.json');
            // strip "shadow_" prefix
            $key = substr($base, strlen('shadow_'));
            if ($key !== '') {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /**
     * Load all persisted per-position shadow state files from the runtime directory.
     *
     * Returns only states that have a non-empty 'symbol' field.
     * Excludes the shadow journal file (shadow_journal.json) which has a different structure.
     *
     * Used by PM-7 passport write-back to supplement current-run items with states from
     * recently closed positions (which are no longer returned by the exchange gateway).
     *
     * @return array[]
     */
    public function loadAllShadowStates(): array
    {
        $dir = $this->storageDir . '/runtime';
        if (!is_dir($dir)) {
            return [];
        }
        $states = [];
        foreach (glob($dir . '/shadow_*.json') ?: [] as $path) {
            if (basename($path) === 'shadow_journal.json') {
                continue;
            }
            $state = $this->readJson($path);
            if (!empty($state) && isset($state['symbol']) && (string)$state['symbol'] !== '') {
                $states[] = $state;
            }
        }
        return $states;
    }

    // =========================================================================
    // Active trailing runtime state (PM active mode)
    // =========================================================================

    /**
     * Load active state for a trade key (persisted between ticks for monotonic peak_roi etc).
     *
     * @param string $tradeKey
     * @return array
     */
    public function loadActiveState(string $tradeKey): array
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $tradeKey);
        $path = $this->storageDir . '/runtime/active_' . $safe . '.json';
        return $this->readJson($path);
    }

    /**
     * Save active state for a trade key.
     *
     * @param string $tradeKey
     * @param array  $state
     */
    public function saveActiveState(string $tradeKey, array $state): void
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $tradeKey);
        $path = $this->storageDir . '/runtime/active_' . $safe . '.json';
        $this->writeJson($path, $state);
    }

    // =========================================================================
    // Active owner journal (PM-8 runtime proof artifact)
    // =========================================================================

    /**
     * Save active-owner journal (latest executeActive() run decisions).
     *
     * Written on every executeActive() call. Mirrors shadow_journal.json pattern.
     * Contains per-position stage, lock improvement, apply outcome, skip/block reasons.
     *
     * @param array $journal
     */
    public function saveActiveOwnerJournal(array $journal): void
    {
        $path = $this->storageDir . '/runtime/active_owner_journal.json';
        $this->writeJson($path, $journal);
    }

    /**
     * Load active-owner journal.
     *
     * @return array
     */
    public function loadActiveOwnerJournal(): array
    {
        $path = $this->storageDir . '/runtime/active_owner_journal.json';
        return $this->readJson($path);
    }

    // =========================================================================
    // Last active-owner proof (PM-8 durable proof artifact)
    // =========================================================================

    /**
     * Save the durable PM-8 proof artifact.
     *
     * Written only when a run has meaningful active-owner evidence
     * (eligible > 0, apply_attempted > 0, or apply_success > 0).
     * Never overwritten by a later empty/no-position run.
     *
     * @param array $proof
     */
    public function saveLastActiveOwnerProof(array $proof): void
    {
        $path = $this->storageDir . '/runtime/last_active_owner_proof.json';
        $this->writeJson($path, $proof);
    }

    /**
     * Load the durable PM-8 proof artifact.
     *
     * @return array
     */
    public function loadLastActiveOwnerProof(): array
    {
        $path = $this->storageDir . '/runtime/last_active_owner_proof.json';
        return $this->readJson($path);
    }
}

/* RULES
- SystemPaths ONLY (no absolute paths)
- Writes ONLY inside this module storage/
- Atomic writes for data integrity
- Ring buffer for applied_index to prevent unbounded growth
*/
