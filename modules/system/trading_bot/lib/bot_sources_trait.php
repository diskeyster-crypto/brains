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
     * Runtime marker: proves which version of bot_sources_trait.php actually executed.
     * If last_run does not show this marker, the server is running a stale/different file.
     */
    private const BOT_SOURCES_RUNTIME_MARKER = 'brain_detect_v3_diag_2026_03_19';

    /** @var array Step-by-step diagnostic trace from last detectBrainControlledMode() call */
    private array $brainDetectionTrace = [];

    /** @var array Resolved Brain/SmartBrain paths from last detection */
    private array $brainResolvedPaths = [];
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

            // V3: Use resolveLiveIntentsPath() to check Smart Brain storage first,
            // then Brain module storage. This fixes the path mismatch where Smart Brain
            // writes live_intents.json to smart_brain/storage/ but bot was only
            // checking brain/storage/.
            $liveIntentsPath = $this->resolveLiveIntentsPath() ?? ($brainBase . '/live_intents.json');

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
            $lifecycleSkipped = [
                'expired' => 0,
                'claimed' => 0,
                'already_executed' => 0,
                'rejected' => 0,
                'invalid_status' => 0,
            ];

            foreach ($intents as $intent) {
                // V2 FIX: Use intent_id as the authoritative identity key for Brain intents
                $intentId = $intent['intent_id'] ?? null;
                $signalId = $intent['signal_id'] ?? null;
                $executionKey = $intentId ?? $signalId ?? null;
                if (empty($executionKey)) {
                    continue;
                }

                // ── Lifecycle status gate ────────────────────────────────
                // Only process intents with status = pending (or missing status for backward compat)
                $intentStatus = $intent['status'] ?? 'pending';
                if ($intentStatus === 'claimed') {
                    $lifecycleSkipped['claimed']++;
                    continue;
                }
                if ($intentStatus === 'executed') {
                    $lifecycleSkipped['already_executed']++;
                    continue;
                }
                if ($intentStatus === 'rejected') {
                    $lifecycleSkipped['rejected']++;
                    continue;
                }
                if ($intentStatus === 'expired') {
                    $lifecycleSkipped['expired']++;
                    continue;
                }
                if ($intentStatus !== 'pending') {
                    $lifecycleSkipped['invalid_status']++;
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
                        'execution_stage' => 'duplicate_skipped',
                        'exchange_submit_attempted' => false,
                        'exchange_response_code' => null,
                        'exchange_response_message' => null,
                        'validation_error_summary' => null,
                        'missing_fields_preview' => [],
                    ];
                    continue;
                }

                // Skip if expired by TTL
                $expiresAt = $intent['expires_at'] ?? 0;
                if ($expiresAt > 0 && $expiresAt < time()) {
                    $lifecycleSkipped['expired']++;
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
                    'created_at' => $intent['created_at'] ?? (($intent['created_ts'] ?? 0) ? date('c', (int)$intent['created_ts']) : date('c')),
                    'expires_at' => $intent['expires_at'] ?? 0,
                    'risk' => $risk,
                    'trailing' => $brainTrailing,
                    'brain' => [],
                    'source' => 'brain_live_intent',
                    'intent_created_at' => $intent['created_at'] ?? (($intent['created_ts'] ?? 0) ? date('c', (int)$intent['created_ts']) : date('c')),
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
                    // Pass-through for late-entry diagnostics
                    'pattern_algorithm' => (string)($intent['pattern_algorithm'] ?? ''),
                    'source_schema_version' => (string)($intent['source_schema_version'] ?? ''),
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
            $result['lifecycle_skipped'] = $lifecycleSkipped;
            $result['live_intents_path'] = $liveIntentsPath;

            if (count($validIntents) === 0) {
                if (($lifecycleSkipped['expired'] ?? 0) > 0 && $duplicateSkipped === 0) {
                    $result['source_status'] = 'expired_only';
                } else {
                    $result['source_status'] = 'empty';
                }
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
     * Claim live intents by atomically updating their status to 'claimed'.
     *
     * @param array  $intentIds  List of intent_ids to claim
     * @param string $liveIntentsPath Absolute path to live_intents.json
     * @param string $claimedBy  Identifier of the claiming consumer (e.g. 'trading_bot')
     * @return array{claimed_count:int, already_claimed:int, not_found:int, errors:list<string>}
     */
    protected function claimLiveIntents(array $intentIds, string $liveIntentsPath, string $claimedBy = 'trading_bot'): array
    {
        $claimResult = [
            'claimed_count' => 0,
            'already_claimed' => 0,
            'not_found' => 0,
            'expired_skipped' => 0,
            'errors' => [],
        ];

        if (empty($intentIds) || empty($liveIntentsPath)) {
            return $claimResult;
        }

        $intentIdSet = array_flip($intentIds);
        $now = time();

        $ok = $this->atomicUpdateLiveIntentsFile($liveIntentsPath, function(array &$data) use ($intentIdSet, $now, $claimedBy, &$claimResult) {
            $intents = &$data['intents'];
            if (!is_array($intents)) {
                return;
            }

            $foundIds = [];
            foreach ($intents as &$intent) {
                $iid = $intent['intent_id'] ?? '';
                if ($iid === '' || !isset($intentIdSet[$iid])) {
                    continue;
                }
                $foundIds[$iid] = true;

                $status = $intent['status'] ?? 'pending';
                if ($status !== 'pending') {
                    $claimResult['already_claimed']++;
                    continue;
                }

                // Check expiry
                $expiresAt = (int)($intent['expires_at'] ?? 0);
                if ($expiresAt > 0 && $expiresAt <= $now) {
                    $claimResult['expired_skipped']++;
                    continue;
                }

                $intent['status'] = 'claimed';
                $intent['claimed_at'] = date('c');
                $intent['claimed_at_ts'] = $now;
                $intent['claimed_by'] = $claimedBy;
                $claimResult['claimed_count']++;
            }
            unset($intent);

            $claimResult['not_found'] = count($intentIdSet) - count($foundIds);
        });

        if (!$ok) {
            $claimResult['errors'][] = 'Failed to acquire lock on live_intents.json for claim';
        }

        return $claimResult;
    }

    /**
     * Update an intent's status in live_intents.json to a terminal state.
     *
     * @param string $intentId        Intent to update
     * @param string $liveIntentsPath Absolute path to live_intents.json
     * @param string $newStatus       New status (executed, rejected)
     * @param array  $metadata        Additional fields (reject_reason, execution_result, etc.)
     * @return bool True on success
     */
    protected function updateLiveIntentStatus(string $intentId, string $liveIntentsPath, string $newStatus, array $metadata = []): bool
    {
        if (empty($intentId) || empty($liveIntentsPath)) {
            return false;
        }

        $now = time();
        return $this->atomicUpdateLiveIntentsFile($liveIntentsPath, function(array &$data) use ($intentId, $newStatus, $metadata, $now) {
            $intents = &$data['intents'];
            if (!is_array($intents)) {
                return;
            }

            foreach ($intents as &$intent) {
                if (($intent['intent_id'] ?? '') !== $intentId) {
                    continue;
                }

                $intent['status'] = $newStatus;
                if ($newStatus === 'executed') {
                    $intent['executed_at'] = date('c');
                    $intent['executed_at_ts'] = $now;
                    $intent['execution_result'] = $metadata['execution_result'] ?? null;
                } elseif ($newStatus === 'rejected') {
                    $intent['rejected_at'] = date('c');
                    $intent['rejected_at_ts'] = $now;
                    $intent['reject_reason'] = $metadata['reject_reason'] ?? null;
                    $intent['reject_context'] = $metadata['reject_context'] ?? null;
                }
                break;
            }
            unset($intent);
        });
    }

    /**
     * Finalize stale claimed intents that have exceeded the claim timeout.
     *
     * Claimed intents that have not been resolved (executed/rejected) within
     * the timeout window are finalized as rejected to prevent zombie records.
     *
     * @param string $liveIntentsPath  Absolute path to live_intents.json
     * @param int    $claimTimeoutMin  Maximum minutes a claimed intent can stay unresolved
     * @return array Result with counts: finalized_count, stale_claimed_preview
     */
    protected function finalizeStaleClaimedIntents(string $liveIntentsPath, int $claimTimeoutMin = 10): array
    {
        $finalizeResult = [
            'finalized_count' => 0,
            'stale_claimed_found' => 0,
            'stale_claimed_preview' => [],
            'errors' => [],
        ];

        if (empty($liveIntentsPath) || !is_file($liveIntentsPath)) {
            return $finalizeResult;
        }

        $now = time();
        $cutoff = $now - ($claimTimeoutMin * 60);

        $ok = $this->atomicUpdateLiveIntentsFile($liveIntentsPath, function(array &$data) use ($cutoff, $now, &$finalizeResult) {
            $intents = &$data['intents'];
            if (!is_array($intents)) {
                return;
            }

            foreach ($intents as &$intent) {
                $status = $intent['status'] ?? 'pending';
                if ($status !== 'claimed') {
                    continue;
                }

                $claimedTs = (int)($intent['claimed_at_ts'] ?? 0);
                if ($claimedTs <= 0 || $claimedTs > $cutoff) {
                    continue; // Not yet stale
                }

                $finalizeResult['stale_claimed_found']++;

                // Finalize as rejected with explicit stale-claim reason
                $intent['status'] = 'rejected';
                $intent['rejected_at'] = date('c');
                $intent['rejected_at_ts'] = $now;
                $intent['reject_reason'] = 'rejected_claim_stale_timeout';
                $intent['reject_context'] = sprintf(
                    'Claimed at %s (%ds ago), timeout %dmin exceeded',
                    $intent['claimed_at'] ?? 'unknown',
                    $now - $claimedTs,
                    intdiv($now - $cutoff + ($now - $claimedTs), 60)
                );

                $finalizeResult['finalized_count']++;

                // Build preview (first 10)
                if (count($finalizeResult['stale_claimed_preview']) < 10) {
                    $finalizeResult['stale_claimed_preview'][] = [
                        'intent_id' => $intent['intent_id'] ?? '',
                        'symbol' => $intent['symbol'] ?? '',
                        'side' => $intent['side'] ?? '',
                        'claimed_at' => $intent['claimed_at'] ?? null,
                        'claimed_at_ts' => $claimedTs,
                        'stale_seconds' => $now - $claimedTs,
                        'finalized_as' => 'rejected_claim_stale_timeout',
                    ];
                }
            }
            unset($intent);
        });

        if (!$ok) {
            $finalizeResult['errors'][] = 'Failed to acquire lock on live_intents.json for stale claim finalization';
        }

        return $finalizeResult;
    }

    /**
     * Atomically read-modify-write live_intents.json with flock.
     *
     * @param string   $path     Absolute path to live_intents.json
     * @param callable $modifier fn(array &$data): void — modifies data in-place
     * @return bool True on success
     */
    private function atomicUpdateLiveIntentsFile(string $path, callable $modifier): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $fp = @fopen($path, 'c+');
        if ($fp === false) {
            return false;
        }

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return false;
        }

        try {
            $content = '';
            while (!feof($fp)) {
                $content .= fread($fp, 8192);
            }

            $data = @json_decode($content, true);
            if (!is_array($data)) {
                $data = ['schema_version' => 'live_intents_v1', 'intents' => []];
            }

            $modifier($data);

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            fflush($fp);

            return true;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
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
     * V3 DIAGNOSTIC: Also checks Smart Brain module paths (smart_brain/) in addition
     * to the Brain module paths (brain/). Smart Brain writes effective_config.json
     * to its own runtime/ directory and live_intents.json to its own storage/.
     * Previous versions only checked the brain/ module path, missing Smart Brain output.
     *
     * Stores step-by-step diagnostic trace in $this->brainDetectionTrace.
     *
     * @return bool true when Brain-controlled live mode is active
     */
    protected function detectBrainControlledMode(): bool
    {
        $trace = [];
        $resolvedPaths = [];
        $flagsFound = [];
        $result = false;

        try {
            $paths = SystemPaths::instance();
            $brainKey = $this->config['sources']['signals_key'] ?? 'system.brain.storage';

            if (!$paths->has($brainKey)) {
                $trace[] = ['step' => 'resolve_brain_key', 'key' => $brainKey, 'exists' => false, 'error' => 'Brain storage path key not registered'];
                $this->brainDetectionTrace = $trace;
                $this->brainResolvedPaths = $resolvedPaths;
                return false;
            }

            $brainBase = $paths->get($brainKey);
            $resolvedPaths['brain_storage_base'] = $brainBase;
            $trace[] = ['step' => 'resolve_brain_key', 'key' => $brainKey, 'exists' => true, 'resolved' => $brainBase];

            // Derive Smart Brain paths: smart_brain module is a sibling of brain module
            // brain/storage → ../../smart_brain/ for the smart_brain module root
            $smartBrainBase = realpath($brainBase . '/../../smart_brain') ?: ($brainBase . '/../../smart_brain');
            $resolvedPaths['smart_brain_base_derived'] = $smartBrainBase;
            $resolvedPaths['smart_brain_base_exists'] = is_dir($smartBrainBase);

            // Also try SystemPaths for smart_brain if registered
            $smartBrainStorageFromPaths = null;
            if ($paths->has('system.smart_brain.storage')) {
                $smartBrainStorageFromPaths = $paths->get('system.smart_brain.storage');
                $resolvedPaths['smart_brain_storage_from_systempaths'] = $smartBrainStorageFromPaths;
            }

            // Build list of candidate directories to check for config/intents files
            // Priority: Smart Brain paths first (where current Smart Brain actually writes),
            // then Brain module paths (legacy/fallback).
            $configSearchPaths = [];
            $intentsSearchPaths = [];

            // Smart Brain runtime (derived)
            $configSearchPaths[] = $smartBrainBase . '/runtime';
            // Smart Brain storage (derived) for live_intents.json
            $intentsSearchPaths[] = $smartBrainBase . '/storage';
            // Smart Brain storage from SystemPaths
            if ($smartBrainStorageFromPaths !== null) {
                $intentsSearchPaths[] = $smartBrainStorageFromPaths;
            }

            // Brain module paths (original/legacy)
            $configSearchPaths[] = $brainBase . '/../runtime';
            $intentsSearchPaths[] = $brainBase;

            $resolvedPaths['config_search_paths'] = $configSearchPaths;
            $resolvedPaths['intents_search_paths'] = $intentsSearchPaths;

            // ================================================================
            // Check effective_config.json across all candidate paths
            // ================================================================
            foreach ($configSearchPaths as $configDir) {
                $effectiveConfigPath = $configDir . '/effective_config.json';
                $resolvedPaths['effective_config_checked'][] = $effectiveConfigPath;
                $stepResult = $this->checkConfigFileForLiveFlag($effectiveConfigPath, 'effective_config', [
                    'live_trading.live_trading_enabled',
                    'user_limits.live_trading_enabled',
                    'live_trading_enabled',
                ]);
                $trace[] = $stepResult;
                if ($stepResult['flag_found'] && $stepResult['flag_value'] === true) {
                    $flagsFound[] = $stepResult;
                    $result = true;
                }
            }

            // ================================================================
            // Check user_config.json across all candidate paths
            // ================================================================
            foreach ($configSearchPaths as $configDir) {
                $userConfigPath = $configDir . '/user_config.json';
                $resolvedPaths['user_config_checked'][] = $userConfigPath;
                $stepResult = $this->checkConfigFileForLiveFlag($userConfigPath, 'user_config', [
                    'live_trading_enabled',
                ]);
                $trace[] = $stepResult;
                if ($stepResult['flag_found'] && $stepResult['flag_value'] === true) {
                    $flagsFound[] = $stepResult;
                    $result = true;
                }
            }

            // ================================================================
            // Check live_intents.json for authoritative Brain hints
            // ================================================================
            foreach ($intentsSearchPaths as $intentsDir) {
                $liveIntentsPath = $intentsDir . '/live_intents.json';
                $resolvedPaths['live_intents_checked'][] = $liveIntentsPath;
                $stepResult = $this->checkConfigFileForLiveFlag($liveIntentsPath, 'live_intents', [
                    'brain_controlled_live_mode',
                    'live_trading_enabled',
                ]);
                $trace[] = $stepResult;
                if ($stepResult['flag_found'] && $stepResult['flag_value'] === true) {
                    $flagsFound[] = $stepResult;
                    $result = true;
                }
            }

            $trace[] = [
                'step' => 'final_decision',
                'result' => $result,
                'flags_found_count' => count($flagsFound),
                'reason' => $result
                    ? 'At least one authoritative Brain live flag is true'
                    : 'No authoritative Brain live flag found in any checked location',
            ];

        } catch (\Throwable $e) {
            $trace[] = ['step' => 'exception', 'error' => $e->getMessage()];
            $result = false;
        }

        $this->brainDetectionTrace = $trace;
        $this->brainResolvedPaths = $resolvedPaths;
        return $result;
    }

    /**
     * Check a single config file for any of the specified live flag key paths.
     *
     * @param string $filePath Absolute path to JSON config file
     * @param string $sourceLabel Label for trace (e.g. 'effective_config')
     * @param list<string> $flagKeys Dot-separated or plain keys to check
     * @return array Diagnostic step result
     */
    private function checkConfigFileForLiveFlag(string $filePath, string $sourceLabel, array $flagKeys): array
    {
        $step = [
            'step' => 'check_' . $sourceLabel,
            'path' => $filePath,
            'exists' => false,
            'parse_ok' => false,
            'flag_found' => false,
            'flag_value' => null,
            'flag_key' => null,
        ];

        if (!is_file($filePath)) {
            return $step;
        }
        $step['exists'] = true;

        $content = @file_get_contents($filePath);
        if ($content === false) {
            $step['error'] = 'read_failed';
            return $step;
        }

        $data = @json_decode($content, true);
        if (!is_array($data)) {
            $step['error'] = 'json_invalid';
            return $step;
        }
        $step['parse_ok'] = true;

        // Check each flag key path
        foreach ($flagKeys as $keyPath) {
            $value = $this->resolveNestedKey($data, $keyPath);
            if ($value !== null) {
                $step['flag_found'] = true;
                $step['flag_value'] = (bool)$value;
                $step['flag_key'] = $keyPath;
                return $step;
            }
        }

        return $step;
    }

    /**
     * Resolve a potentially dot-separated key from a nested array.
     * E.g. 'live_trading.live_trading_enabled' checks $data['live_trading']['live_trading_enabled']
     * Falls back to flat key check: $data['live_trading.live_trading_enabled']
     *
     * @param array $data Source array
     * @param string $keyPath Dot-separated or plain key
     * @return mixed|null Value if found, null otherwise
     */
    private function resolveNestedKey(array $data, string $keyPath)
    {
        // Try dot-separated nested access
        $parts = explode('.', $keyPath);
        if (count($parts) > 1) {
            $current = $data;
            foreach ($parts as $part) {
                if (!is_array($current) || !array_key_exists($part, $current)) {
                    // Fall through to flat key check
                    $current = null;
                    break;
                }
                $current = $current[$part];
            }
            if ($current !== null) {
                return $current;
            }
        }

        // Try flat key
        return $data[$keyPath] ?? null;
    }

    /**
     * Get diagnostic information from the last detectBrainControlledMode() call.
     * Includes step-by-step trace, resolved paths, and runtime marker.
     *
     * @return array Diagnostic payload for runtime/last_run output
     */
    protected function getBrainDetectionDiagnostics(): array
    {
        return [
            'bot_sources_trait_runtime_marker' => self::BOT_SOURCES_RUNTIME_MARKER,
            'php_file_used_bot_sources_trait' => __FILE__,
            'brain_resolved_paths' => $this->brainResolvedPaths,
            'brain_detection_trace' => $this->brainDetectionTrace,
        ];
    }

    /**
     * Resolve the best path for Brain live_intents.json.
     *
     * Checks Smart Brain storage first (where current Smart Brain writes),
     * then falls back to Brain module storage (legacy).
     *
     * @return string|null Absolute path to live_intents.json or null if not found
     */
    private function resolveLiveIntentsPath(): ?string
    {
        try {
            $paths = SystemPaths::instance();
            $brainKey = $this->config['sources']['signals_key'] ?? 'system.brain.storage';
            if (!$paths->has($brainKey)) {
                return null;
            }
            $brainBase = $paths->get($brainKey);

            // Smart Brain storage (derived from brain module path)
            $smartBrainStorage = realpath($brainBase . '/../../smart_brain/storage');
            if ($smartBrainStorage !== false) {
                $candidate = $smartBrainStorage . '/live_intents.json';
                if (is_file($candidate)) {
                    return $candidate;
                }
            }

            // Smart Brain storage from SystemPaths
            if ($paths->has('system.smart_brain.storage')) {
                $candidate = $paths->get('system.smart_brain.storage') . '/live_intents.json';
                if (is_file($candidate)) {
                    return $candidate;
                }
            }

            // Brain module storage (legacy/original)
            $candidate = $brainBase . '/live_intents.json';
            if (is_file($candidate)) {
                return $candidate;
            }
        } catch (\Throwable $e) {
            // Silently fail — caller handles missing path
        }

        return null;
    }

    /**
     * Normalize Brain trailing contract into risk.trailing format
     * that bot execution engines (BotRiskEngine, BotTrailingEngine) expect.
     *
     * ── CANONICAL SOURCE GUARD ──────────────────────────────────────────
     * When Brain's buildBotReadyRiskBlock() has already built risk.trailing
     * (indicated by brain_trailing_applied=true), this function is a no-op.
     * This prevents contradictions between the intent's top-level trailing
     * (Brain naming, ratio units) and risk.trailing (bot naming, percent units).
     *
     * @legacy — this normalization path is only active for intents that were NOT
     * built by the canonical buildBotReadyRiskBlock(). Once all signal sources
     * produce canonical risk blocks, this function becomes a pass-through guard.
     *
     * Brain contract fields → Bot execution fields mapping:
     * - trailing.trailing_enabled → risk.trailing.enabled
     * - trailing.trailing_activation_roi → risk.trailing.activation_roi_pct (×100 ratio→percent)
     * - trailing.trailing_min_lock_roi → risk.trailing.min_lock_roi
     * - trailing.trailing_min_step → risk.trailing.min_step
     * - trailing.break_even_enabled → risk.trailing.break_even_enabled
     * - trailing.break_even_activation_roi → risk.trailing.break_even_activation_roi (×100)
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
        // UNIFIED EXIT CONTRACT GUARD: If risk.trailing was already built by Brain's
        // buildBotReadyRiskBlock() from the canonical config source, do NOT overwrite it.
        // This prevents contradictions between the top-level trailing (Brain naming) and
        // risk.trailing (bot naming) — they both derive from the same canonical source.
        if (!empty($risk['trailing']['brain_trailing_applied'])) {
            // Already a canonical contract — add source tracking and return as-is
            $risk['trailing']['effective_trailing_contract_source'] = 'brain_canonical_risk_trailing';
            return $risk;
        }

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
            // Brain uses ratio (e.g. 0.025 = 2.5%), bot expects percentage (e.g. 2.5 = 2.5%)
            'break_even_activation_roi' => (float)($brainTrailing['break_even_activation_roi'] ?? 0) * 100,
            'exit_mode' => (string)($brainTrailing['exit_mode'] ?? 'hybrid_tp'),
            'fixed_take_profit_roi' => (float)($brainTrailing['fixed_take_profit_roi'] ?? 0),
            'hybrid_tp_share' => (float)($brainTrailing['hybrid_tp_share'] ?? 0),
            'brain_trailing_applied' => true,
            'effective_trailing_contract_source' => 'brain_trailing_contract_normalized',
        ];

        $risk['trailing'] = $normalized;
        return $risk;
    }

    /**
     * Load intents from Brain signals (legacy fallback).
     *
     * @legacy — this path is only used when Brain's live_intents.json is unavailable
     * or when the bot is not in Brain-controlled mode. Once Brain is the sole signal
     * source, this function can be retired.
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
            
            // Extract signals array — support both container shapes:
            // A. Wrapped: {"signals": [signal1, signal2, ...]}
            // B. Plain list: [signal1, signal2, ...]
            // Previous logic used $data['signals'] ?? [] which returns []
            // for plain arrays (numeric keys), and [] is_array so fallback never ran.
            if (is_array($data) && isset($data['signals']) && is_array($data['signals'])) {
                $signals = $data['signals'];
            } elseif (is_array($data)) {
                $signals = $data;
            } else {
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
        // Priority order: skipped > rejected > failed > closed > trailing_active > protected > opened > pending

        // Explicit skipped state (duplicate suppression)
        if (($execResult['status'] ?? '') === 'skipped') {
            return 'skipped';
        }

        $status = $execResult['status'] ?? '';

        if (strpos($status, 'rejected_') === 0) {
            return 'rejected';
        }
        if ($status === 'critical_unprotected_position_close_failed') {
            return 'failed';
        }
        if ($status === 'error') {
            return 'failed';
        }

        // Closed states
        if (strpos($status, 'closed_') === 0 || $status === 'exchange_closed') {
            return 'closed';
        }

        if (!empty($execResult['opened'])) {
            // trailing_active outranks protected outranks opened
            // NOTE: trailing_active is typically set by post-processing in service.php
            // after updateActivePositions() runs, but can also be detected here if
            // trailing_active flag is present in exec result.
            if (!empty($execResult['trailing_active'])) {
                return 'trailing_active';
            }
            if ($status === 'opened_protected') {
                return 'protected';
            }
            return 'opened';
        }

        if (strpos($status, 'deferred_') === 0) {
            return 'deferred';
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
            // P0.1: Execution stage audit - exact stage where chain stopped
            'execution_stage' => $execResult['execution_stage'] ?? 'unknown',
            // P0.3: Exchange submit visibility per intent
            'exchange_submit_attempted' => (bool)($execResult['exchange_submit_attempted'] ?? false),
            'exchange_response_code' => $execResult['exchange_response_code'] ?? null,
            'exchange_response_message' => $execResult['exchange_response_message'] ?? null,
            // P0.6: Validation rejection detail
            'validation_error_summary' => $execResult['validation_error_summary'] ?? null,
            'missing_fields_preview' => $execResult['missing_fields_preview'] ?? [],
            // Execution truth fields: explicit order/position outcome
            'order_send_attempted' => (bool)($execResult['exchange_submit_attempted'] ?? false),
            'order_sent' => (bool)($execResult['opened'] ?? false),
            'position_opened' => (bool)($execResult['opened'] ?? false) && in_array($lifecycleState, ['opened', 'protected', 'trailing_active'], true),
            'terminal_status' => $this->resolveTerminalStatus($lifecycleState, $execStatus, (bool)($execResult['exchange_submit_attempted'] ?? false)),
        ];

        if ($lifecycleState === 'rejected') {
            $record['rejection_reason'] = $this->normalizeRejectionReason($execStatus);
            // Propagate late-entry sub-reason and diagnostics from execution context
            if (isset($execResult['context']['reject_subreason'])) {
                $record['reject_subreason'] = $execResult['context']['reject_subreason'];
            }
            if (isset($execResult['context']['late_entry_diagnostics'])) {
                $record['late_entry_diagnostics'] = $execResult['context']['late_entry_diagnostics'];
            }
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
     * Resolve a terminal status label that reflects real execution truth.
     *
     * Only intents that actually sent an order and opened a position are
     * considered "executed". Everything else is rejected/failed.
     *
     * @param string $lifecycleState  Resolved lifecycle state
     * @param string $execStatus      Raw execution status
     * @param bool   $exchangeSubmitAttempted  Whether order send was attempted
     * @return string Terminal status label
     */
    protected function resolveTerminalStatus(string $lifecycleState, string $execStatus, bool $exchangeSubmitAttempted): string
    {
        if (in_array($lifecycleState, ['opened', 'protected', 'trailing_active'], true)) {
            return 'executed_position_opened';
        }
        if ($lifecycleState === 'failed') {
            if ($exchangeSubmitAttempted) {
                return 'failed_exchange_reject';
            }
            return 'failed_pre_exchange';
        }
        if ($lifecycleState === 'rejected') {
            // Return the specific rejection status for explainability
            if (strpos($execStatus, 'rejected_') === 0) {
                return $execStatus;
            }
            return 'rejected_' . $execStatus;
        }
        if ($lifecycleState === 'closed') {
            return 'closed_fail_safe';
        }
        if ($lifecycleState === 'deferred') {
            return 'deferred';
        }
        return 'unknown';
    }

    /**
     * Rebuild lifecycle_summary from actual intents array inside live_intents.json.
     *
     * This ensures the top-level summary always matches the real intent statuses,
     * preventing stale summary drift between Brain runs.
     *
     * @param string $liveIntentsPath Absolute path to live_intents.json
     * @return bool True on success
     */
    protected function rebuildLifecycleSummary(string $liveIntentsPath): bool
    {
        if (empty($liveIntentsPath) || !is_file($liveIntentsPath)) {
            return false;
        }

        return $this->atomicUpdateLiveIntentsFile($liveIntentsPath, function(array &$data) {
            $intents = $data['intents'] ?? [];
            if (!is_array($intents)) {
                $intents = [];
            }

            $statusCounts = ['pending' => 0, 'claimed' => 0, 'executed' => 0, 'rejected' => 0, 'expired' => 0];
            foreach ($intents as $intent) {
                $s = $intent['status'] ?? 'pending';
                if (isset($statusCounts[$s])) {
                    $statusCounts[$s]++;
                }
            }

            $data['lifecycle_summary'] = [
                'total' => count($intents),
                'pending' => $statusCounts['pending'],
                'claimed' => $statusCounts['claimed'],
                'executed' => $statusCounts['executed'],
                'rejected' => $statusCounts['rejected'],
                'expired' => $statusCounts['expired'],
                'rebuilt_at' => date('c'),
            ];
        });
    }

    /**
     * Detect whether trailing is actually active on a trade.
     *
     * trailing_active means trailing has been applied to exchange,
     * NOT merely enabled in config.
     *
     * Primary: protection block has trailing_stop > 0 AND trailing_enabled
     * Fallback: runtime.dumb_trailing_applied (legacy field, still written by updateActivePositions)
     *
     * @param array $trade Active trade record
     * @return bool
     */
    protected function isTrailingActive(array $trade): bool
    {
        $prot = is_array($trade['protection'] ?? null) ? $trade['protection'] : [];
        $rt = is_array($trade['runtime'] ?? null) ? $trade['runtime'] : [];

        // Primary: exchange protection state
        $exchangeTrailingSet = (float)($prot['trailing_stop'] ?? 0) > 0
            && (bool)($prot['trailing_enabled'] ?? false);
        // Fallback: legacy runtime field
        $runtimeTrailingApplied = !empty($rt['dumb_trailing_applied']);

        return $exchangeTrailingSet || $runtimeTrailingApplied;
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
