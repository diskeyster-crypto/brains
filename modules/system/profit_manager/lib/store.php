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

    /**
     * Load all safe trade keys that have a persisted active state file.
     *
     * Returns the safe-encoded key (the part after "active_" and before ".json").
     * Excludes active_owner_journal and any other known non-position files.
     * Used by PM-9 stale-state cleanup.
     *
     * @return string[]
     */
    public function loadAllActiveStateKeys(): array
    {
        $dir = $this->storageDir . '/runtime';
        if (!is_dir($dir)) {
            return [];
        }
        $skipBases = ['active_owner_journal'];
        $keys = [];
        foreach (glob($dir . '/active_*.json') ?: [] as $path) {
            $base = basename($path, '.json');
            if (in_array($base, $skipBases, true)) {
                continue;
            }
            $key = substr($base, strlen('active_'));
            if ($key !== '') {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /**
     * Delete active state for a trade key.
     *
     * Accepts either the original tradeKey or the already-safe key (regex is idempotent).
     * Called by PM-9 position lifecycle cleanup when a position is no longer managed.
     *
     * @param string $tradeKey
     */
    public function deleteActiveState(string $tradeKey): void
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $tradeKey);
        $path = $this->storageDir . '/runtime/active_' . $safe . '.json';
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    // =========================================================================
    // PM-9 stabilization counters (cumulative, persisted across ticks)
    // =========================================================================

    /**
     * Load cumulative PM-9 stabilization counters.
     *
     * Counters accumulate across all executeActive() cycles.
     * Used to prove multi-tick ownership stability in the archive.
     *
     * @return array
     */
    public function loadPm9Counters(): array
    {
        $path = $this->storageDir . '/runtime/pm9_counters.json';
        return $this->readJson($path);
    }

    /**
     * Save cumulative PM-9 stabilization counters.
     *
     * @param array $counters
     */
    public function savePm9Counters(array $counters): void
    {
        $path = $this->storageDir . '/runtime/pm9_counters.json';
        $this->writeJson($path, $counters);
    }

    // =========================================================================
    // PM-10 refinement counters (cumulative, persisted across ticks)
    // =========================================================================

    /**
     * Load cumulative PM-10 refinement counters.
     *
     * Counters accumulate across all executeActive() cycles.
     * Used to prove which refinement branches were exercised in the archive.
     *
     * @return array
     */
    public function loadPm10Counters(): array
    {
        $path = $this->storageDir . '/runtime/pm10_counters.json';
        return $this->readJson($path);
    }

    /**
     * Save cumulative PM-10 refinement counters.
     *
     * @param array $counters
     */
    public function savePm10Counters(array $counters): void
    {
        $path = $this->storageDir . '/runtime/pm10_counters.json';
        $this->writeJson($path, $counters);
    }

    // =========================================================================
    // PM-11 adaptive counters (cumulative, persisted across ticks)
    // =========================================================================

    /**
     * Load cumulative PM-11 adaptive refinement counters.
     *
     * Counters accumulate across all executeActive() cycles.
     * Used to prove which adaptive modes were exercised in the archive.
     *
     * @return array
     */
    public function loadPm11Counters(): array
    {
        $path = $this->storageDir . '/runtime/pm11_counters.json';
        return $this->readJson($path);
    }

    /**
     * Save cumulative PM-11 adaptive refinement counters.
     *
     * @param array $counters
     */
    public function savePm11Counters(array $counters): void
    {
        $path = $this->storageDir . '/runtime/pm11_counters.json';
        $this->writeJson($path, $counters);
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

    // =========================================================================
    // PM-12 post-entry evidence (NDJSON bounded history, write-only memory)
    // =========================================================================

    /**
     * Append one or more compact evidence records to the bounded NDJSON evidence file.
     *
     * Keeps at most 1000 records (oldest are dropped when the limit is exceeded).
     * Write is best-effort and non-fatal — errors are silently ignored.
     *
     * @param array $records
     */
    public function appendPostEntryEvidence(array $records): void
    {
        if (empty($records)) {
            return;
        }
        $path       = $this->storageDir . '/runtime/pm_post_entry_evidence.ndjson';
        $maxRecords = 1000;

        // Read existing records
        $existing = $this->loadPostEntryEvidence(0);

        // Append new records and trim to bound
        $all = array_merge($existing, $records);
        if (count($all) > $maxRecords) {
            $all = array_slice($all, -$maxRecords);
        }

        // Write as NDJSON (one JSON object per line)
        $lines = [];
        foreach ($all as $record) {
            $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($line !== false) {
                $lines[] = $line;
            }
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX);
    }

    /**
     * Load post-entry evidence records from the bounded NDJSON file.
     *
     * @param int $limit Maximum number of records to return (0 = all)
     * @return array
     */
    public function loadPostEntryEvidence(int $limit = 0): array
    {
        $path = $this->storageDir . '/runtime/pm_post_entry_evidence.ndjson';
        if (!file_exists($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }
        $records = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $records[] = $decoded;
            }
        }
        if ($limit > 0 && count($records) > $limit) {
            $records = array_slice($records, -$limit);
        }
        return $records;
    }

    // =========================================================================
    // PM-12 evidence counters (cumulative, persisted across ticks)
    // =========================================================================

    /**
     * Load cumulative PM-12 passive evidence counters.
     *
     * Counters accumulate across all executeActive() cycles.
     * Used to prove evidence capture is working in the archive.
     *
     * @return array
     */
    public function loadPm12Counters(): array
    {
        $path = $this->storageDir . '/runtime/pm12_counters.json';
        return $this->readJson($path);
    }

    /**
     * Save cumulative PM-12 passive evidence counters.
     *
     * @param array $counters
     */
    public function savePm12Counters(array $counters): void
    {
        $path = $this->storageDir . '/runtime/pm12_counters.json';
        $this->writeJson($path, $counters);
    }

    // =========================================================================
    // PM-13 post-entry evidence read model (derived, write-only from evidence)
    // =========================================================================

    /**
     * Build a compact read model from all post-entry evidence records.
     *
     * Produces per-symbol aggregates + a global summary block.
     * Uses count-based weighted averages for ROI fields.
     * This method is pure (no I/O). Call savePostEntryReadModel() to persist.
     *
     * @param array $records  All records from loadPostEntryEvidence()
     * @param string $updatedAt  ISO timestamp for updated_at fields
     * @return array
     */
    public function buildPostEntryReadModel(array $records, string $updatedAt = ''): array
    {
        if ($updatedAt === '') {
            $updatedAt = date('c');
        }

        $bySymbol = [];

        foreach ($records as $rec) {
            $sym = (string)($rec['symbol'] ?? '');
            if ($sym === '') {
                continue;
            }
            $evType = (string)($rec['event_type'] ?? '');

            if (!isset($bySymbol[$sym])) {
                $bySymbol[$sym] = [
                    'symbol'                              => $sym,
                    'side'                                => $rec['side'] ?? null,
                    'samples_total'                       => 0,
                    'apply_samples_total'                 => 0,
                    'skip_samples_total'                  => 0,
                    'noop_samples_total'                  => 0,
                    'first_lock_hold_samples_total'       => 0,
                    'continuation_extension_samples_total' => 0,
                    'shallow_pullback_samples_total'      => 0,
                    'adaptive_weak_samples_total'         => 0,
                    'adaptive_steady_samples_total'       => 0,
                    'adaptive_strong_samples_total'       => 0,
                    'adaptive_shallow_pullback_samples_total' => 0,
                    '_sum_peak_roi'                       => 0.0,
                    '_sum_current_roi'                    => 0.0,
                    '_sum_proposed_lock_roi'              => 0.0,
                    '_cnt_proposed_lock_roi'              => 0,
                    '_sum_applied_lock_roi'               => 0.0,
                    '_cnt_applied_lock_roi'               => 0,
                    'last_pm_management_stage'            => null,
                    'last_refinement_policy_stage'        => null,
                    'last_adaptive_refinement_mode'       => null,
                    'last_skip_reason'                    => null,
                    'last_block_reason'                   => null,
                    'last_apply_applied'                  => null,
                    'last_event_time'                     => null,
                ];
            }

            $s = &$bySymbol[$sym];
            $s['samples_total']++;

            if ($evType === 'apply') {
                $s['apply_samples_total']++;
            } elseif ($evType === 'noop') {
                $s['noop_samples_total']++;
            } else {
                $s['skip_samples_total']++;
            }

            $refStage = (string)($rec['refinement_policy_stage'] ?? '');
            if ($refStage === 'first_lock_protection') {
                $s['first_lock_hold_samples_total']++;
            } elseif ($refStage === 'continuation_extension') {
                $s['continuation_extension_samples_total']++;
            } elseif ($refStage === 'shallow_pullback_protection') {
                $s['shallow_pullback_samples_total']++;
            }

            $adaptMode = (string)($rec['adaptive_refinement_mode'] ?? '');
            if ($adaptMode === 'weak_continuation') {
                $s['adaptive_weak_samples_total']++;
            } elseif ($adaptMode === 'steady_continuation') {
                $s['adaptive_steady_samples_total']++;
            } elseif ($adaptMode === 'strong_continuation') {
                $s['adaptive_strong_samples_total']++;
            } elseif ($adaptMode === 'shallow_pullback') {
                $s['adaptive_shallow_pullback_samples_total']++;
            }

            $peakRoi = $rec['peak_roi'] ?? null;
            if (is_numeric($peakRoi)) {
                $s['_sum_peak_roi'] += (float)$peakRoi;
            }
            $curRoi = $rec['current_roi'] ?? null;
            if (is_numeric($curRoi)) {
                $s['_sum_current_roi'] += (float)$curRoi;
            }
            $propLockRoi = $rec['proposed_lock_roi'] ?? null;
            if (is_numeric($propLockRoi)) {
                $s['_sum_proposed_lock_roi'] += (float)$propLockRoi;
                $s['_cnt_proposed_lock_roi']++;
            }
            $appLockRoi = $rec['applied_lock_roi'] ?? null;
            if (is_numeric($appLockRoi)) {
                $s['_sum_applied_lock_roi'] += (float)$appLockRoi;
                $s['_cnt_applied_lock_roi']++;
            }

            // Last-seen fields (always overwrite with latest record for this symbol)
            if (($rec['pm_management_stage'] ?? null) !== null) {
                $s['last_pm_management_stage'] = $rec['pm_management_stage'];
            }
            if (($rec['refinement_policy_stage'] ?? null) !== null) {
                $s['last_refinement_policy_stage'] = $rec['refinement_policy_stage'];
            }
            if (($rec['adaptive_refinement_mode'] ?? null) !== null) {
                $s['last_adaptive_refinement_mode'] = $rec['adaptive_refinement_mode'];
            }
            if (($rec['skip_reason'] ?? null) !== null) {
                $s['last_skip_reason'] = $rec['skip_reason'];
            }
            if (($rec['block_reason'] ?? null) !== null) {
                $s['last_block_reason'] = $rec['block_reason'];
            }
            $s['last_apply_applied'] = $rec['apply_applied'] ?? null;
            $s['last_event_time']    = $rec['event_time'] ?? $rec['updated_at'] ?? null;
        }

        // Materialise per-symbol entries (compute averages, drop internal accumulator keys)
        $globalApply      = 0;
        $globalSkip       = 0;
        $globalNoop       = 0;
        $globalRefinement = 0;
        $globalAdaptive   = 0;
        $globalRecords    = 0;
        $symbolEntries    = [];

        foreach ($bySymbol as $sym => $s) {
            unset($s['symbol']); // will be added back at front
            $n = $s['samples_total'];

            $avgPeakRoi        = $n > 0 ? round($s['_sum_peak_roi']    / $n, 6) : null;
            $avgCurrentRoi     = $n > 0 ? round($s['_sum_current_roi'] / $n, 6) : null;
            $cntProp = $s['_cnt_proposed_lock_roi'];
            $avgProposedLockRoi = $cntProp > 0 ? round($s['_sum_proposed_lock_roi'] / $cntProp, 6) : null;
            $cntApp  = $s['_cnt_applied_lock_roi'];
            $avgAppliedLockRoi  = $cntApp  > 0 ? round($s['_sum_applied_lock_roi']  / $cntApp,  6) : null;

            $refinementSamples = $s['first_lock_hold_samples_total']
                + $s['continuation_extension_samples_total']
                + $s['shallow_pullback_samples_total'];
            $adaptiveSamples   = $s['adaptive_weak_samples_total']
                + $s['adaptive_steady_samples_total']
                + $s['adaptive_strong_samples_total']
                + $s['adaptive_shallow_pullback_samples_total'];

            $entry = [
                'symbol'                                  => $sym,
                'side'                                    => $s['side'],
                'samples_total'                           => $n,
                'apply_samples_total'                     => $s['apply_samples_total'],
                'skip_samples_total'                      => $s['skip_samples_total'],
                'noop_samples_total'                      => $s['noop_samples_total'],
                'first_lock_hold_samples_total'           => $s['first_lock_hold_samples_total'],
                'continuation_extension_samples_total'    => $s['continuation_extension_samples_total'],
                'shallow_pullback_samples_total'          => $s['shallow_pullback_samples_total'],
                'adaptive_weak_samples_total'             => $s['adaptive_weak_samples_total'],
                'adaptive_steady_samples_total'           => $s['adaptive_steady_samples_total'],
                'adaptive_strong_samples_total'           => $s['adaptive_strong_samples_total'],
                'adaptive_shallow_pullback_samples_total' => $s['adaptive_shallow_pullback_samples_total'],
                'avg_peak_roi'                            => $avgPeakRoi,
                'avg_current_roi'                         => $avgCurrentRoi,
                'avg_proposed_lock_roi'                   => $avgProposedLockRoi,
                'avg_applied_lock_roi'                    => $avgAppliedLockRoi,
                'last_pm_management_stage'                => $s['last_pm_management_stage'],
                'last_refinement_policy_stage'            => $s['last_refinement_policy_stage'],
                'last_adaptive_refinement_mode'           => $s['last_adaptive_refinement_mode'],
                'last_skip_reason'                        => $s['last_skip_reason'],
                'last_block_reason'                       => $s['last_block_reason'],
                'last_apply_applied'                      => $s['last_apply_applied'],
                'last_event_time'                         => $s['last_event_time'],
                'updated_at'                              => $updatedAt,
            ];
            $symbolEntries[$sym] = $entry;

            $globalApply      += $s['apply_samples_total'];
            $globalSkip       += $s['skip_samples_total'];
            $globalNoop       += $s['noop_samples_total'];
            $globalRefinement += $refinementSamples;
            $globalAdaptive   += $adaptiveSamples;
            $globalRecords    += $n;
        }

        $global = [
            'symbols_total'       => count($symbolEntries),
            'records_total'       => $globalRecords,
            'apply_records_total' => $globalApply,
            'skip_records_total'  => $globalSkip,
            'noop_records_total'  => $globalNoop,
            'refinement_records_total' => $globalRefinement,
            'adaptive_records_total'   => $globalAdaptive,
            'updated_at'          => $updatedAt,
        ];

        return [
            'global'  => $global,
            'symbols' => $symbolEntries,
        ];
    }

    /**
     * Save the PM-13 post-entry read model to disk.
     *
     * @param array $model
     */
    public function savePostEntryReadModel(array $model): void
    {
        $path = $this->storageDir . '/runtime/pm_post_entry_read_model.json';
        $this->writeJson($path, $model);
    }

    /**
     * Load the PM-13 post-entry read model from disk.
     *
     * @return array
     */
    public function loadPostEntryReadModel(): array
    {
        $path = $this->storageDir . '/runtime/pm_post_entry_read_model.json';
        return $this->readJson($path);
    }

    // =========================================================================
    // PM-13 read model counters (cumulative, persisted across ticks)
    // =========================================================================

    /**
     * Load cumulative PM-13 read model generation counters.
     *
     * @return array
     */
    public function loadPm13Counters(): array
    {
        $path = $this->storageDir . '/runtime/pm13_counters.json';
        return $this->readJson($path);
    }

    /**
     * Save cumulative PM-13 read model generation counters.
     *
     * @param array $counters
     */
    public function savePm13Counters(array $counters): void
    {
        $path = $this->storageDir . '/runtime/pm13_counters.json';
        $this->writeJson($path, $counters);
    }

    // =========================================================================
    // PM-14 passive outcome linking
    // =========================================================================

    /**
     * Build passive outcome-link records by joining PM post-entry evidence with real
     * closed-position outcomes.
     *
     * Accepts closed-trade records from any authoritative source (Trading Bot
     * trades/closed_trades.json, ai_shadow virtual_trades_closed, etc.).  The caller
     * normalises records to the expected shape before passing them here.
     *
     * Matching is symbol-first; side confirms the match. Confidence is:
     *   - 'high'   : exactly one closed trade for symbol+side
     *   - 'medium' : multiple closed trades for the same symbol+side, or side unavailable
     *   - 'low'    : side mismatch (symbol matches but sides differ)
     *
     * This method is pure (no I/O). Call savePostEntryOutcomeLinks() to persist.
     *
     * @param array  $evidence     All records from loadPostEntryEvidence() (may be empty)
     * @param array  $readModel    Result of buildPostEntryReadModel() (contains 'symbols' map)
     * @param array  $closedTrades Normalised closed-trade objects
     * @param string $updatedAt    ISO timestamp for linked_at / updated_at fields
     * @return array
     */
    public function buildPostEntryOutcomeLinks(
        array  $evidence,
        array  $readModel,
        array  $closedTrades,
        string $updatedAt = ''
    ): array {
        if ($updatedAt === '') {
            $updatedAt = date('c');
        }

        $symbolEntries = $readModel['symbols'] ?? [];

        // Build per-symbol index of the last event_id / event_type from ordered evidence records.
        // Records are appended chronologically, so later entries overwrite earlier ones here.
        $lastEventById   = [];
        $lastEventByType = [];
        foreach ($evidence as $rec) {
            $sym = strtoupper((string)($rec['symbol'] ?? ''));
            if ($sym === '') {
                continue;
            }
            $lastEventById[$sym]   = $rec['event_id']   ?? null;
            $lastEventByType[$sym] = $rec['event_type'] ?? null;
        }

        // Group closed trades by normalised symbol (upper-case)
        $closedBySymbol = [];
        foreach ($closedTrades as $ct) {
            $sym = strtoupper((string)($ct['symbol'] ?? ''));
            if ($sym === '' || ($ct['status'] ?? '') !== 'closed') {
                continue;
            }
            $closedBySymbol[$sym][] = $ct;
        }

        $links            = [];
        $globalLinked     = 0;
        $globalProfitable = 0;
        $globalLosing     = 0;
        $globalNeutral    = 0;
        $globalApply      = 0;
        $globalSkip       = 0;
        $globalNoop       = 0;
        $globalLowConf    = 0;
        $linkedSymbols    = [];

        foreach ($symbolEntries as $sym => $entry) {
            $symUpper = strtoupper($sym);
            if (!isset($closedBySymbol[$symUpper])) {
                continue; // no closed trades for this symbol → skip
            }

            $entrySymSide = strtolower((string)($entry['side'] ?? ''));
            $tradesForSym = $closedBySymbol[$symUpper];

            // Sort by closed_at descending so the most recent close appears first
            usort($tradesForSym, static function ($a, $b) {
                return ((int)($b['closed_at'] ?? 0)) <=> ((int)($a['closed_at'] ?? 0));
            });

            // Count same-side trades for confidence calculation
            $sameSideCount = 0;
            if ($entrySymSide !== '') {
                foreach ($tradesForSym as $ct) {
                    if (strtolower((string)($ct['side'] ?? '')) === $entrySymSide) {
                        $sameSideCount++;
                    }
                }
            }

            foreach ($tradesForSym as $ct) {
                $ctSide = strtolower((string)($ct['side'] ?? ''));

                // Confidence determination
                if ($entrySymSide !== '' && $ctSide !== '') {
                    if ($entrySymSide === $ctSide) {
                        $confidence = $sameSideCount === 1 ? 'high' : 'medium';
                    } else {
                        $confidence = 'low'; // side mismatch — link is tentative
                    }
                } else {
                    $confidence = 'medium'; // side data missing on one side
                }

                // Prefer live_roi (the realised exchange result) over simulated roi
                $liveRoi = isset($ct['live_roi']) && is_numeric($ct['live_roi']) ? (float)$ct['live_roi'] : null;
                $compRoi = isset($ct['roi'])      && is_numeric($ct['roi'])      ? (float)$ct['roi']      : null;
                $roi     = $liveRoi ?? $compRoi;

                if ($roi === null) {
                    $outcomeStatus = 'unknown';
                } elseif ($roi > 0.0) {
                    $outcomeStatus = 'profitable';
                } elseif ($roi < 0.0) {
                    $outcomeStatus = 'losing';
                } else {
                    $outcomeStatus = 'neutral';
                }

                // MFE (max favourable excursion) as peak_roi_seen; fall back to read-model avg
                $mfe         = isset($ct['mfe']) && is_numeric($ct['mfe']) && (float)$ct['mfe'] !== 0.0
                    ? (float)$ct['mfe']
                    : null;
                $peakRoiSeen = $mfe ?? ($entry['avg_peak_roi'] ?? null);

                $link = [
                    'symbol'                        => $entry['symbol'],
                    'side'                          => $entry['side'],
                    'owner_mode'                    => 'profit_manager',
                    'evidence_samples_total'        => (int)($entry['samples_total']       ?? 0),
                    'last_evidence_event_id'        => $lastEventById[$symUpper]            ?? null,
                    'last_evidence_event_type'      => $lastEventByType[$symUpper]          ?? null,
                    'last_pm_management_stage'      => $entry['last_pm_management_stage']   ?? null,
                    'last_refinement_policy_stage'  => $entry['last_refinement_policy_stage'] ?? null,
                    'last_adaptive_refinement_mode' => $entry['last_adaptive_refinement_mode'] ?? null,
                    'apply_samples_total'           => (int)($entry['apply_samples_total']  ?? 0),
                    'skip_samples_total'            => (int)($entry['skip_samples_total']   ?? 0),
                    'noop_samples_total'            => (int)($entry['noop_samples_total']   ?? 0),
                    'final_outcome_status'          => $outcomeStatus,
                    'final_close_reason'            => $ct['live_close_reason'] ?? $ct['close_reason'] ?? null,
                    'final_roi'                     => $roi,
                    'peak_roi_seen'                 => $peakRoiSeen,
                    'drawdown_after_apply'          => null, // requires tick-by-tick replay — not available
                    'continuation_after_apply'      => null, // requires tick-by-tick replay — not available
                    'outcome_link_confidence'       => $confidence,
                    'linked_at'                     => $updatedAt,
                    'source_refs'                   => [
                        'source_type'      => $ct['_pm14_source']   ?? 'ai_shadow',
                        'virtual_trade_id' => $ct['virtual_trade_id'] ?? null,
                        'live_trade_id'    => $ct['live_trade_id']    ?? null,
                        'closed_at'        => $ct['closed_at']        ?? null,
                    ],
                ];

                $links[]                   = $link;
                $globalLinked++;
                $linkedSymbols[$symUpper]  = true;

                if ($outcomeStatus === 'profitable') {
                    $globalProfitable++;
                } elseif ($outcomeStatus === 'losing') {
                    $globalLosing++;
                } elseif ($outcomeStatus === 'neutral') {
                    $globalNeutral++;
                }

                if ($confidence === 'low') {
                    $globalLowConf++;
                }

                // Tabulate whether this symbol's evidence had apply/skip/noop activity
                if ((int)($entry['apply_samples_total'] ?? 0) > 0) {
                    $globalApply++;
                }
                if ((int)($entry['skip_samples_total'] ?? 0) > 0) {
                    $globalSkip++;
                }
                if ((int)($entry['noop_samples_total'] ?? 0) > 0) {
                    $globalNoop++;
                }
            }
        }

        $global = [
            'linked_symbols_total'      => count($linkedSymbols),
            'linked_records_total'      => $globalLinked,
            'profitable_outcomes_total' => $globalProfitable,
            'losing_outcomes_total'     => $globalLosing,
            'neutral_outcomes_total'    => $globalNeutral,
            'apply_linked_total'        => $globalApply,
            'skip_linked_total'         => $globalSkip,
            'noop_linked_total'         => $globalNoop,
            'low_confidence_total'      => $globalLowConf,
            'updated_at'                => $updatedAt,
        ];

        return [
            'global' => $global,
            'links'  => $links,
        ];
    }

    /**
     * Save the PM-14 post-entry outcome links to disk.
     *
     * @param array $links
     */
    public function savePostEntryOutcomeLinks(array $links): void
    {
        $path = $this->storageDir . '/runtime/pm_post_entry_outcome_links.json';
        $this->writeJson($path, $links);
    }

    /**
     * Load the PM-14 post-entry outcome links from disk.
     *
     * @return array
     */
    public function loadPostEntryOutcomeLinks(): array
    {
        $path = $this->storageDir . '/runtime/pm_post_entry_outcome_links.json';
        return $this->readJson($path);
    }

    // =========================================================================
    // PM-14 outcome-link counters (cumulative, persisted across ticks)
    // =========================================================================

    /**
     * Load cumulative PM-14 outcome-link generation counters.
     *
     * @return array
     */
    public function loadPm14Counters(): array
    {
        $path = $this->storageDir . '/runtime/pm14_counters.json';
        return $this->readJson($path);
    }

    /**
     * Save cumulative PM-14 outcome-link generation counters.
     *
     * @param array $counters
     */
    public function savePm14Counters(array $counters): void
    {
        $path = $this->storageDir . '/runtime/pm14_counters.json';
        $this->writeJson($path, $counters);
    }
}

/* RULES
- SystemPaths ONLY (no absolute paths)
- Writes ONLY inside this module storage/
- Atomic writes for data integrity
- Ring buffer for applied_index to prevent unbounded growth
*/
