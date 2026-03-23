<?php
declare(strict_types=1);

require_once __DIR__ . '/smart_brain_config.php';
require_once __DIR__ . '/smart_brain_logger.php';
require_once __DIR__ . '/state_manager.php';
require_once __DIR__ . '/parser4_analyzer.php';
require_once __DIR__ . '/corridor_monitor.php';
require_once __DIR__ . '/coin_passport_engine.php';
require_once __DIR__ . '/risk_engine.php';
require_once __DIR__ . '/signal_builder.php';
require_once __DIR__ . '/simulator_engine.php';
require_once __DIR__ . '/smart_brain_runtime.php';
require_once __DIR__ . '/price_feed.php';
require_once __DIR__ . '/symbol_intelligence.php';

final class SmartBrainCore
{
    private const LOCK_FILE = 'runtime/brain.lock';
    private const LOCK_STALE_SECONDS = 300;

    private string $moduleBase;
    private SmartBrainConfig $config;
    private SmartBrainLogger $logger;
    private StateManager $state;

    public function __construct(string $moduleBase)
    {
        $this->moduleBase = rtrim($moduleBase, '/');
        $this->config = new SmartBrainConfig($this->moduleBase);
        $this->logger = new SmartBrainLogger($this->moduleBase);
        $this->state = new StateManager($this->moduleBase);
    }

    /**
     * Run full Smart Brain cycle with lock, timing, and status tracking.
     *
     * @param string $source  'cron' or 'manual'
     * @return array<string,mixed>
     */
    public function run(string $source = 'cron'): array
    {
        $startTime = microtime(true);

        // Acquire lock — prevent overlapping runs
        if (!$this->acquireLock($source)) {
            $this->logger->log('warning', 'Smart Brain cycle skipped: lock held by another process');
            return [
                'ok' => false,
                'updated_at' => date('c'),
                'status' => 'skipped',
                'source' => $source,
                'error_message' => 'Lock held by another process',
            ];
        }

        $this->logger->log('info', 'Smart Brain cycle started (source=' . $source . ')');

        try {
            $result = $this->executePipeline($source, $startTime);
        } catch (\Throwable $e) {
            $durationMs = (int)round((microtime(true) - $startTime) * 1000);
            $this->logger->log('error', 'Smart Brain cycle FAILED: ' . $e->getMessage());

            $result = [
                'ok' => false,
                'updated_at' => date('c'),
                'status' => 'error',
                'source' => $source,
                'duration_ms' => $durationMs,
                'candidates' => 0,
                'monitors' => 0,
                'signals' => 0,
                'error_message' => $e->getMessage(),
            ];
            $this->state->writeJson('storage/last_run.json', $result);
        } finally {
            $this->releaseLock();
        }

        return $result;
    }

    /**
     * Execute the actual pipeline logic.
     *
     * @return array<string,mixed>
     */
    private function executePipeline(string $source, float $startTime): array
    {
        $parser4Cfg = $this->config->get('parser4', []);
        $corridorCfg = $this->config->getEffective('corridor');
        $riskCfg = $this->config->getEffective('risk_engine');
        $profilesCfg = $this->config->getEffective('profiles');
        $simulatorCfg = $this->config->getEffective('simulator');
        $userLimits = $this->config->getUserLimits();

        // Write effective config snapshot (Stable Config Refactor, Part 4)
        $effectiveSnapshot = $this->config->buildEffectiveSnapshot();
        $this->state->writeJson('runtime/effective_config.json', $effectiveSnapshot);

        // Parser4: structure analysis from Parser2 history
        $parser = new Parser4Analyzer($parser4Cfg, $this->state);
        $candidates = $parser->run();
        $rawCandidatesCount = count($candidates);

        // Manual Symbol Universe: restrict candidates to user-specified symbols
        $manualUniverseEnabled = (bool)($userLimits['manual_symbol_universe_enabled'] ?? false);
        $manualSymbolMode = (string)($userLimits['manual_symbol_mode'] ?? 'manual_only');
        $manualSymbolCount = 0;
        $manualUniverseFiltered = 0;
        if ($manualUniverseEnabled) {
            $rawList = (string)($userLimits['manual_symbol_list'] ?? '');
            $manualSymbols = SymbolIntelligence::parseManualSymbolList($rawList);
            $manualSymbolCount = count($manualSymbols);
            if ($manualSymbolCount > 0) {
                $symbolIntelInstance = new SymbolIntelligence($this->state, $userLimits);
                $beforeManual = count($candidates);
                $candidates = $symbolIntelInstance->filterByManualUniverse($candidates, $manualSymbols, $manualSymbolMode);
                $manualUniverseFiltered = $beforeManual - count($candidates);
                if ($manualUniverseFiltered > 0) {
                    $this->logger->log('info', 'Manual Symbol Universe: filtered ' . $manualUniverseFiltered . ' candidates (mode=' . $manualSymbolMode . ', symbols=' . $manualSymbolCount . ', remaining=' . count($candidates) . ')');
                }
            }
        }

        // ---- Config Conflict Guard: filter precedence ----
        // When manual_only is active, it acts as a terminal universe restriction.
        // Symbol Intelligence filter must NOT further narrow candidates in this case,
        // unless the manual_symbol_mode explicitly combines with symbol intelligence
        // (manual_plus_whitelist, manual_plus_soft, manual_exclude_blacklist).
        $symbolIntelEnabled = (bool)($userLimits['symbol_intelligence_enabled'] ?? false);
        $symbolFilterMode = (string)($userLimits['symbol_filter_mode'] ?? 'all');
        $symbolIntelFiltered = 0;
        $configConflictDetected = false;
        $configConflictMessage = '';

        $skipSymbolIntelFilter = false;
        if ($manualUniverseEnabled && $manualSymbolMode === 'manual_only'
            && $symbolIntelEnabled && $symbolFilterMode !== 'all'
        ) {
            // manual_only is a terminal restriction — do not further narrow by symbol intelligence.
            // Note: 'exclude_blacklist' mode is NOT in restrictiveModes because it only removes
            // known bad symbols and does not narrow the universe to a subset — it is safe to combine
            // with manual_only. The restrictive modes below require intersection with specific lists,
            // which conflicts with manual_only intent.
            $skipSymbolIntelFilter = true;
            $restrictiveModes = ['whitelist_only', 'soft_whitelist_only', 'whitelist_plus_soft', 'watchlist_only'];
            if (in_array($symbolFilterMode, $restrictiveModes, true)) {
                $configConflictDetected = true;
                $configConflictMessage = 'Конфликт фильтров: включён manual_only и одновременно активен режим '
                    . $symbolFilterMode . '. manual_only работает как терминальное ограничение — '
                    . 'фильтр Symbol Intelligence пропущен для этого запуска.';
                $this->logger->log('warning', 'Config Conflict Guard: ' . $configConflictMessage);
            }
        }

        // Symbol Intelligence: filter candidates by user-selected mode
        if (!$skipSymbolIntelFilter && $symbolIntelEnabled && $symbolFilterMode !== 'all') {
            $symbolIntel = new SymbolIntelligence($this->state, $userLimits);
            $beforeCount = count($candidates);
            $candidates = $symbolIntel->filterCandidates($candidates, $symbolFilterMode);
            $symbolIntelFiltered = $beforeCount - count($candidates);
            if ($symbolIntelFiltered > 0) {
                $this->logger->log('info', 'Symbol Intelligence: filtered ' . $symbolIntelFiltered . ' candidates (mode=' . $symbolFilterMode . ', remaining=' . count($candidates) . ')');
            }
        }

        // Re-write candidates.json with filtered data (early filter application)
        $filteredCandidatesCount = count($candidates);
        $afterManualFilterCount = $rawCandidatesCount - $manualUniverseFiltered;
        $afterSymbolFilterCount = $filteredCandidatesCount;

        // ---- Config Conflict Guard V2: stage-aware zero-output diagnostics ----
        $filterStageThatRemovedAll = 'none';
        $zeroOutputReason = '';
        $restrictiveSiSkipped = $skipSymbolIntelFilter;
        $restrictiveSiSkipReason = '';

        if ($skipSymbolIntelFilter) {
            $restrictiveSiSkipReason = 'manual_only является terminal restriction — restrictive SI filter пропущен.';
        }

        if ($rawCandidatesCount === 0) {
            $filterStageThatRemovedAll = 'analyzer';
            $zeroOutputReason = 'Analyzer не нашёл raw candidates — это не конфликт фильтров.';
        } elseif ($rawCandidatesCount > 0 && $afterManualFilterCount === 0 && $manualUniverseEnabled) {
            $filterStageThatRemovedAll = 'manual_filter';
            $zeroOutputReason = 'Raw candidates найдены (' . $rawCandidatesCount . '), но после manual filter осталось 0.';
            if (!$configConflictDetected) {
                $configConflictDetected = true;
                $configConflictMessage = $zeroOutputReason;
                $this->logger->log('warning', 'Config Conflict Guard: ' . $configConflictMessage);
            }
        } elseif ($rawCandidatesCount > 0 && $filteredCandidatesCount === 0 && $symbolIntelFiltered > 0) {
            $filterStageThatRemovedAll = 'symbol_intelligence_filter';
            $zeroOutputReason = 'Raw candidates найдены (' . $rawCandidatesCount . '), но после symbol intelligence filter осталось 0.';
            if (!$configConflictDetected) {
                $configConflictDetected = true;
                $configConflictMessage = $zeroOutputReason;
                $this->logger->log('warning', 'Config Conflict Guard: ' . $configConflictMessage);
            }
        } elseif ($rawCandidatesCount > 0 && $filteredCandidatesCount === 0 && !$configConflictDetected) {
            $filterStageThatRemovedAll = 'unknown';
            $reasons = [];
            if ($manualUniverseEnabled) {
                $reasons[] = 'manual_symbol_universe (mode=' . $manualSymbolMode . ', count=' . $manualSymbolCount . ')';
            }
            if ($symbolIntelEnabled && $symbolFilterMode !== 'all') {
                $reasons[] = 'symbol_intelligence (mode=' . $symbolFilterMode . ')';
            }
            $configConflictDetected = true;
            $configConflictMessage = 'Все ' . $rawCandidatesCount . ' кандидатов были отфильтрованы. '
                . 'Активные фильтры: ' . ($reasons !== [] ? implode(' + ', $reasons) : 'нет')
                . '. Проверьте настройки фильтрации или содержимое whitelist/blacklist.';
            $this->logger->log('warning', 'Config Conflict Guard: zero candidates after filtering. ' . $configConflictMessage);
        }

        $this->state->writeJson('storage/candidates.json', $candidates);

        // Write analyzer debug log (Pattern-First Decision Flow)
        $analyzerDebugLines = $parser->getAnalyzerDebugLines();
        if ($analyzerDebugLines !== []) {
            $this->logger->writeAnalyzerDebugLog($analyzerDebugLines);
        }

        // Collect all symbols from candidates
        $symbols = [];
        foreach ($candidates as $c) {
            $sym = (string)($c['symbol'] ?? '');
            if ($sym !== '') {
                $symbols[] = $sym;
            }
        }

        // Fetch real current prices from Bybit (Phase 8.2)
        $priceFeed = new PriceFeed($this->logger);
        $livePrices = $priceFeed->getPrices(array_unique($symbols));

        // Build final price map: Bybit first, internal last_price as fallback
        $prices = $this->buildPriceMap($livePrices, $candidates);

        if ($livePrices !== []) {
            $this->logger->log('info', 'PriceFeed: fetched ' . count($livePrices) . ' live prices from Bybit');
        }

        // Corridor Monitor uses real prices for price_position / status
        $corridor = new CorridorMonitor($corridorCfg);
        $monitors = $corridor->buildMonitors($candidates, $prices);

        // Enrich monitors with trend_bias and side from candidates
        $candidateBySymbol = [];
        foreach ($candidates as $c) {
            $sym = (string)($c['symbol'] ?? '');
            if ($sym !== '') {
                $candidateBySymbol[$sym] = $c;
            }
        }
        foreach ($monitors as &$m) {
            $sym = (string)($m['symbol'] ?? '');
            if (isset($candidateBySymbol[$sym])) {
                $m['trend_bias'] = (string)($candidateBySymbol[$sym]['trend_bias'] ?? '');
                // Propagate explicit side from pattern detection
                if (isset($candidateBySymbol[$sym]['side']) && $candidateBySymbol[$sym]['side'] !== '') {
                    $m['side'] = (string)$candidateBySymbol[$sym]['side'];
                }
            }
        }
        unset($m);

        $this->state->writeJson('storage/monitors.json', $monitors);

        $passports = new CoinPassportEngine($this->state);
        $passports->update($monitors);

        // P7: Enrich passports with live bot execution profile
        $botStatsLoaded = false;
        $botStatsParseOk = false;
        $botStatsSymbolsCount = 0;
        $botStatsSourcePath = null;
        $passportEnrichResult = [
            'passports_updated_count' => 0,
            'passports_with_mae_profile_count' => 0,
            'passport_mae_symbols_preview' => [],
        ];
        try {
            $botStoragePath = $this->resolveBotStoragePath();
            if ($botStoragePath !== null) {
                $botLastRunPath = $botStoragePath . '/last_run.json';
                $botStatsSourcePath = $botLastRunPath;
                if (file_exists($botLastRunPath)) {
                    $botRunRaw = (string)file_get_contents($botLastRunPath);
                    $botRunData = json_decode($botRunRaw, true);
                    $botStatsParseOk = is_array($botRunData);
                    if ($botStatsParseOk && !empty($botRunData['symbol_exit_stats'])) {
                        $botStatsLoaded = true;
                        $botStatsSymbolsCount = count($botRunData['symbol_exit_stats']);
                        $passportEnrichResult = $passports->enrichWithExecutionProfile($botRunData['symbol_exit_stats']);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Non-fatal: passport enrichment failure does not break pipeline
        }

        $risk = new RiskEngine($riskCfg, $profilesCfg, $this->state);
        $signals = $risk->apply($monitors, $prices, $userLimits);
        $this->state->writeJson('storage/signals.json', $signals);

        // Collect rejection counters and debug lines (Phase B)
        $rejectionCounters = $risk->getRejectionCounters();
        $debugLines = $risk->getDebugLines();
        $signalModeCounters = $risk->getSignalModeCounters();

        // Write debug signal log (Phase B, Part 3)
        $this->logger->writeDebugLog($debugLines);

        // ================================================================
        // Live Intent Generation — Brain-controlled live bot refactor
        // Brain applies live_signal_selection_mode to filter signals
        // and produces live_intents.json for the Trading Bot executor.
        // ================================================================
        $liveConfig = $this->config->buildLiveConfig();
        $liveIntentResult = $this->generateLiveIntents($signals, $liveConfig, $userLimits);

        // Simulator uses real prices for entry trigger / ROI / MAE / MFE / SL / TP
        $simulator = new SimulatorEngine($simulatorCfg, $this->state);
        $simulator->tick($signals, $prices);
        $stats = $simulator->computeStats();

        // Rebuild symbol intelligence from updated closed trades
        $symbolIntelRebuild = new SymbolIntelligence($this->state, $userLimits);
        $symbolIntelRebuild->rebuild();

        $runtime = new SmartBrainRuntime($this->state);
        $runtime->snapshot($this->config->all());

        $durationMs = (int)round((microtime(true) - $startTime) * 1000);

        // Monitor status distribution (Phase B, Part 1)
        $monitoringCount = 0;
        $entryZoneCount = 0;
        $triggeredCount = 0;
        $invalidatedCount = 0;
        foreach ($monitors as $m) {
            $st = (string)($m['status'] ?? '');
            match ($st) {
                'monitoring' => $monitoringCount++,
                'entry_zone' => $entryZoneCount++,
                'triggered' => $triggeredCount++,
                'invalidated' => $invalidatedCount++,
                default => null,
            };
        }

        $result = [
            'ok' => true,
            'updated_at' => date('c'),
            'status' => 'ok',
            'source' => $source,
            'duration_ms' => $durationMs,
            'candidates' => count($candidates),
            'monitors' => count($monitors),
            'signals' => count($signals),
            'error_message' => '',
            // Phase B — monitor status distribution
            'monitoring_count' => $monitoringCount,
            'entry_zone_count' => $entryZoneCount,
            'triggered_count' => $triggeredCount,
            'invalidated_count' => $invalidatedCount,
            // Phase B — rejection counters
            'rejected_not_entry_zone' => $rejectionCounters['rejected_not_entry_zone'] ?? 0,
            'rejected_low_reliability' => $rejectionCounters['rejected_low_reliability'] ?? 0,
            'rejected_missing_passport' => $rejectionCounters['rejected_missing_passport'] ?? 0,
            'rejected_missing_price' => $rejectionCounters['rejected_missing_price'] ?? 0,
            'rejected_side_unresolved' => $rejectionCounters['rejected_side_unresolved'] ?? 0,
            // Stable Config Refactor — bootstrap / normal signal counts
            'bootstrap_signals_count' => $signalModeCounters['bootstrap_signals_count'] ?? 0,
            'warmup_symbols_count' => $signalModeCounters['warmup_symbols_count'] ?? 0,
            'normal_signals_count' => $signalModeCounters['normal_signals_count'] ?? 0,
            // Symbol Intelligence
            'symbol_intel_filtered' => $symbolIntelFiltered,
            'symbol_filter_mode' => $symbolFilterMode,
            // Stage-aware filter diagnostics (Config Conflict Guard V2)
            'raw_candidates_count' => $rawCandidatesCount,
            'after_manual_filter_count' => $afterManualFilterCount,
            'after_symbol_filter_count' => $afterSymbolFilterCount,
            'filtered_candidates_count' => $filteredCandidatesCount,
            'filter_stage_that_removed_all' => $filterStageThatRemovedAll,
            'zero_output_reason' => $zeroOutputReason,
            'restrictive_si_skipped' => $restrictiveSiSkipped,
            'restrictive_si_skip_reason' => $restrictiveSiSkipReason,
            // Config Conflict Guard warnings
            'config_warnings' => $this->config->detectConfigConflicts(),
            // Manual Symbol Universe
            'manual_symbol_universe_enabled' => $manualUniverseEnabled,
            'manual_symbol_mode' => $manualSymbolMode,
            'manual_symbol_count' => $manualSymbolCount,
            // Config Conflict Guard
            'config_conflict_detected' => $configConflictDetected,
            'config_conflict_message' => $configConflictMessage,
            // Live Intent Generation — audit fields
            'live_stage_runtime_signature' => $liveIntentResult['live_stage_runtime_signature'] ?? '',
            'live_trading_enabled' => $liveConfig['live_trading_enabled'],
            'brain_controlled_live_mode' => (bool)($liveConfig['live_trading_enabled'] ?? false),
            'live_signal_selection_mode' => $liveConfig['live_signal_selection_mode'],
            'live_signals_seen' => $liveIntentResult['signals_seen'],
            'live_signals_processed' => $liveIntentResult['signals_processed'],
            'live_candidates_approved_count' => $liveIntentResult['approved_count'],
            'live_candidates_rejected_count' => $liveIntentResult['rejected_count'],
            'live_missing_id_fallback_used' => $liveIntentResult['fallback_id_used'],
            'live_rejection_reasons' => $liveIntentResult['rejection_reasons'],
            'live_rejection_reason_stats' => $liveIntentResult['rejection_reason_stats'],
            'live_signal_id_source_stats' => $liveIntentResult['signal_id_source_stats'],
            'live_intents_created_count' => $liveIntentResult['intents_created'],
            'live_intents_sent_to_bot_count' => $liveIntentResult['intents_written'],
            'live_invalid_payload_count' => $liveIntentResult['live_invalid_payload_count'],
            'live_missing_risk_count' => $liveIntentResult['live_missing_risk_count'],
            'live_missing_entry_count' => $liveIntentResult['live_missing_entry_count'],
            'live_mode_filter_rejected_count' => $liveIntentResult['live_mode_filter_rejected_count'],
            'live_invalid_risk_contract_count' => $liveIntentResult['live_invalid_risk_contract_count'],
            'live_debug_preview' => $liveIntentResult['live_debug_preview'],
            'effective_execution_limits' => [
                'live_max_positions' => (int)($liveConfig['live_max_positions'] ?? 3),
                'live_one_trade_per_symbol' => (bool)($liveConfig['live_one_trade_per_symbol'] ?? true),
            ],
            'effective_trailing_contract' => $this->buildEffectiveTrailingContractSummary($userLimits),
            // ── MAE ADAPTIVE STOP: RUNTIME PROOF ─────────────────────────
            // Part 1: Bot stats load status
            'bot_symbol_exit_stats_loaded' => $botStatsLoaded,
            'bot_symbol_exit_stats_symbols_count' => $botStatsSymbolsCount,
            'bot_symbol_exit_stats_source_path' => $botStatsSourcePath,
            'bot_symbol_exit_stats_parse_ok' => $botStatsParseOk,
            // Part 4: Passport write counts
            'passports_updated_count' => $passportEnrichResult['passports_updated_count'],
            'passports_with_mae_profile_count' => $passportEnrichResult['passports_with_mae_profile_count'],
            'passport_mae_symbols_preview' => $passportEnrichResult['passport_mae_symbols_preview'],
            // Part 5: MAE hint counters (from live intent generation)
            'mae_stop_hints_available_count' => $liveIntentResult['mae_stop_hints_available_count'] ?? 0,
            'mae_stop_hints_applied_count' => $liveIntentResult['mae_stop_hints_applied_count'] ?? 0,
            'mae_stop_hints_fallback_count' => $liveIntentResult['mae_stop_hints_fallback_count'] ?? 0,
            // Part 2: MAE debug preview (first few symbol/side cases)
            'mae_stop_debug_preview' => $liveIntentResult['mae_stop_debug_preview'] ?? [],
        ];

        $this->state->writeJson('storage/last_run.json', $result);
        $this->logger->log('info', 'Smart Brain cycle finished: source=' . $source . ' signals=' . count($signals) . ' duration=' . $durationMs . 'ms');

        return $result;
    }

    // =========================================================================
    // Live Intent Generation — Brain-controlled live bot refactor
    // =========================================================================

    /** Runtime signature for live-stage code version verification */
    private const LIVE_STAGE_VERSION = 'live_stage_v3_cleanup_2026-03-21';

    /**
     * Generate Brain-approved live intents from signals.
     * Applies live_signal_selection_mode filter and writes storage/live_intents.json.
     *
     * Every signal must end as approved or rejected — no silent skip.
     *
     * P0 FIX: Builds full bot-ready risk contract with all mandatory execution fields
     * (profile_id, budget_usdt_per_trade, stop_from_liq_range_pct, slippage_bps,
     * fees_bps, order_type, limits) so Trading Bot validator accepts the intent.
     *
     * @param array  $signals    Approved signals from RiskEngine
     * @param array  $liveConfig Effective live trading config from buildLiveConfig()
     * @param array  $userLimits User limits from config
     * @return array Live stage audit result
     */
    private function generateLiveIntents(array $signals, array $liveConfig, array $userLimits): array
    {
        $result = [
            'live_stage_runtime_signature' => self::LIVE_STAGE_VERSION,
            'approved_count' => 0,
            'rejected_count' => 0,
            'rejection_reasons' => [],
            'intents_created' => 0,
            'intents_written' => 0,
            'approvals' => [],
            'signals_processed' => 0,
            'signals_seen' => 0,
            'fallback_id_used' => 0,
            'rejection_reason_stats' => [],
            'signal_id_source_stats' => ['original' => 0, 'generated_fallback' => 0],
            'live_invalid_payload_count' => 0,
            'live_missing_risk_count' => 0,
            'live_missing_entry_count' => 0,
            'live_mode_filter_rejected_count' => 0,
            'live_invalid_risk_contract_count' => 0,
            'live_debug_preview' => [],
            // MAE adaptive stop runtime proof counters
            'mae_stop_hints_available_count' => 0,
            'mae_stop_hints_applied_count' => 0,
            'mae_stop_hints_fallback_count' => 0,
            'mae_stop_debug_preview' => [],
        ];

        // If live trading is disabled, write empty intents and return
        if (!($liveConfig['live_trading_enabled'] ?? false)) {
            $this->state->writeJson('storage/live_intents.json', [
                'schema_version' => 'live_intents_v1',
                'generated_at' => date('c'),
                'live_trading_enabled' => false,
                'live_stage_runtime_signature' => self::LIVE_STAGE_VERSION,
                'effective_live_config' => $liveConfig,
                'intents' => [],
            ]);
            return $result;
        }

        $selectionMode = (string)($liveConfig['live_signal_selection_mode'] ?? 'whitelist_only');
        $entryPolicy = (string)($liveConfig['live_entry_policy'] ?? 'enter_now');
        $reverseEnabled = (bool)($liveConfig['live_reverse_side_enabled'] ?? false);

        // Load symbol intelligence lists for live selection filtering
        $whitelist = $this->loadSymbolList('whitelist.json');
        $softWhitelist = $this->loadSymbolList('soft_whitelist.json');
        $watchlist = $this->loadSymbolList('watchlist.json');
        $manualSymbols = [];
        if (!empty($userLimits['manual_symbol_universe_enabled'])) {
            $rawList = (string)($userLimits['manual_symbol_list'] ?? '');
            $manualSymbols = SymbolIntelligence::parseManualSymbolList($rawList);
        }

        $intents = [];

        // Extract signal list from both supported container shapes:
        // A. Wrapped: ['signals' => [signal1, signal2, ...]]
        // B. Plain list: [signal1, signal2, ...]
        // Previous logic used $signals['signals'] ?? [] which returns []
        // for plain arrays (numeric keys), and [] is_array so fallback never ran.
        if (is_array($signals) && isset($signals['signals']) && is_array($signals['signals'])) {
            $signalsList = $signals['signals'];
        } elseif (is_array($signals)) {
            $signalsList = $signals;
        } else {
            $signalsList = [];
        }

        $result['signals_seen'] = count($signalsList);

        foreach ($signalsList as $signal) {
            // Defensive: skip non-array entries
            if (!is_array($signal)) {
                $result['signals_processed']++;
                $this->rejectLiveSignal($result, '', '', 'invalid_signal_payload', $selectionMode);
                $result['live_invalid_payload_count']++;
                continue;
            }

            $result['signals_processed']++;
            $symbol = (string)($signal['symbol'] ?? '');
            $signalIdSource = 'original';

            // === VALIDATION GATE 1: missing symbol ===
            if ($symbol === '') {
                $this->rejectLiveSignal($result, '', '', 'missing_symbol', $selectionMode);
                $result['live_invalid_payload_count']++;
                continue;
            }

            // === SIGNAL ID: original or deterministic fallback ===
            $signalId = (string)($signal['id'] ?? '');
            if ($signalId === '') {
                $signalId = $this->buildDeterministicSignalId($signal);
                $signalIdSource = 'generated_fallback';
                $result['fallback_id_used']++;
            }
            $result['signal_id_source_stats'][$signalIdSource]++;

            // === VALIDATION GATE 2: missing side ===
            $side = (string)($signal['side'] ?? '');
            if ($side !== 'long' && $side !== 'short') {
                $this->rejectLiveSignal($result, $symbol, $signalId, 'missing_side', $selectionMode);
                $result['live_invalid_payload_count']++;
                continue;
            }

            // === VALIDATION GATE 3: risk block must be usable ===
            $risk = $this->normalizeRiskBlock($signal);
            if (!is_array($risk) || empty($risk)) {
                $this->rejectLiveSignal($result, $symbol, $signalId, 'missing_risk_block', $selectionMode);
                $result['live_missing_risk_count']++;
                continue;
            }
            if (empty($risk['leverage']) || empty($risk['budget'])) {
                $this->rejectLiveSignal($result, $symbol, $signalId, 'invalid_risk_block', $selectionMode);
                $result['live_missing_risk_count']++;
                continue;
            }

            // === VALIDATION GATE 4: entry reference must exist ===
            $entryPriceRef = $this->resolveEntryPriceReference($signal);
            if ($entryPriceRef <= 0.0) {
                $this->rejectLiveSignal($result, $symbol, $signalId, 'missing_entry_payload', $selectionMode);
                $result['live_missing_entry_count']++;
                continue;
            }

            // === SELECTION MODE FILTER ===
            $approved = false;
            $approvalReason = '';
            $selectionSource = '';

            switch ($selectionMode) {
                case 'all':
                    $approved = true;
                    $approvalReason = 'mode=all';
                    $selectionSource = 'all';
                    break;
                case 'whitelist_only':
                    $approved = in_array($symbol, $whitelist, true);
                    $approvalReason = $approved ? 'symbol in whitelist' : '';
                    $selectionSource = 'whitelist';
                    break;
                case 'soft_whitelist_only':
                    $approved = in_array($symbol, $softWhitelist, true);
                    $approvalReason = $approved ? 'symbol in soft_whitelist' : '';
                    $selectionSource = 'soft_whitelist';
                    break;
                case 'whitelist_plus_soft':
                    $approved = in_array($symbol, $whitelist, true) || in_array($symbol, $softWhitelist, true);
                    $approvalReason = $approved ? 'symbol in whitelist or soft_whitelist' : '';
                    $selectionSource = 'whitelist+soft';
                    break;
                case 'manual_only':
                    $approved = in_array($symbol, $manualSymbols, true);
                    $approvalReason = $approved ? 'symbol in manual_list' : '';
                    $selectionSource = 'manual';
                    break;
                case 'manual_plus_soft':
                    $approved = in_array($symbol, $manualSymbols, true) || in_array($symbol, $softWhitelist, true);
                    $approvalReason = $approved ? 'symbol in manual_list or soft_whitelist' : '';
                    $selectionSource = 'manual+soft';
                    break;
                case 'manual_plus_whitelist':
                    $approved = in_array($symbol, $manualSymbols, true) || in_array($symbol, $whitelist, true);
                    $approvalReason = $approved ? 'symbol in manual_list or whitelist' : '';
                    $selectionSource = 'manual+whitelist';
                    break;
                case 'watchlist_only':
                    $approved = in_array($symbol, $watchlist, true);
                    $approvalReason = $approved ? 'symbol in watchlist' : '';
                    $selectionSource = 'watchlist';
                    break;
                default:
                    $approved = false;
                    $approvalReason = 'unknown selection mode: ' . $selectionMode;
                    $selectionSource = 'unknown';
                    break;
            }

            if (!$approved) {
                $this->rejectLiveSignal($result, $symbol, $signalId, 'live_mode_filter_rejected', $selectionMode);
                $result['live_mode_filter_rejected_count']++;
                continue;
            }

            // === VALIDATION GATE 5: Weak Entry Quality Filter (P3) ===
            // Reject late entries (signal age > threshold)
            $signalCreatedTs = (int)($signal['created_ts'] ?? 0);
            $lateEntryThresholdMinutes = (int)($userLimits['late_entry_max_minutes'] ?? 15);
            if ($signalCreatedTs > 0 && $lateEntryThresholdMinutes > 0) {
                $signalAgeMinutes = (time() - $signalCreatedTs) / 60;
                if ($signalAgeMinutes > $lateEntryThresholdMinutes) {
                    $this->rejectLiveSignal($result, $symbol, $signalId, 'late_entry_rejected', $selectionMode);
                    $result['late_entry_rejected_count'] = ($result['late_entry_rejected_count'] ?? 0) + 1;
                    continue;
                }
            }

            // Early failure guard: reject if signal has adverse initial momentum
            $earlyFailureEnabled = (bool)($userLimits['early_failure_enabled'] ?? true);
            if ($earlyFailureEnabled) {
                $maxAdverseRoi = (float)($userLimits['early_failure_max_adverse_roi'] ?? -0.008);
                $signalInitialRoi = (float)($signal['initial_roi'] ?? $signal['entry_roi'] ?? 0);
                if ($signalInitialRoi < $maxAdverseRoi) {
                    $this->rejectLiveSignal($result, $symbol, $signalId, 'early_failure_adverse_roi', $selectionMode);
                    $result['early_failure_rejected_count'] = ($result['early_failure_rejected_count'] ?? 0) + 1;
                    continue;
                }
            }

            // === APPROVED: build bot-ready live intent ===

            $sideOriginal = $side;
            if ($reverseEnabled && ($side === 'long' || $side === 'short')) {
                $side = ($side === 'long') ? 'short' : 'long';
            }

            // P7: Per-symbol exit hints — apply bounded adjustments from execution profile
            // Pass $side so hints can use per-side MAE data (symbol+side aware)
            $symbolHints = $this->computePerSymbolHints($symbol, $userLimits, 10, $side);
            $effectiveLimits = $userLimits;
            if ($symbolHints['applied']) {
                $h = $symbolHints['hints'];
                if (isset($h['suggested_logical_stop_roi'])) {
                    $effectiveLimits['logical_stop_roi'] = $h['suggested_logical_stop_roi'];
                }
                // Pass through real source for logical_stop block traceability
                if (isset($h['logical_stop_source'])) {
                    $effectiveLimits['_logical_stop_source'] = $h['logical_stop_source'];
                }
                if (isset($h['suggested_trailing_activation_roi'])) {
                    $effectiveLimits['trailing_activation_roi'] = $h['suggested_trailing_activation_roi'];
                }
                if (isset($h['suggested_break_even_activation_roi'])) {
                    $effectiveLimits['break_even_activation_roi'] = $h['suggested_break_even_activation_roi'];
                }
                if (isset($h['suggested_exit_mode'])) {
                    $effectiveLimits['exit_mode'] = $h['suggested_exit_mode'];
                }
                if (isset($h['suggested_hybrid_tp_share'])) {
                    $effectiveLimits['hybrid_tp_share'] = $h['suggested_hybrid_tp_share'];
                }

                // ── MAE RUNTIME PROOF: count hint application ──
                $hintSource = $h['logical_stop_source'] ?? '';
                if ($hintSource === 'mae_adaptive_side' || $hintSource === 'mae_adaptive_symbol') {
                    $result['mae_stop_hints_applied_count']++;
                } elseif ($hintSource === 'fallback_default' || !empty($h['mae_fallback_used'])) {
                    $result['mae_stop_hints_fallback_count']++;
                }
                $result['mae_stop_hints_available_count']++;

                // MAE debug preview (first 5 cases)
                if (count($result['mae_stop_debug_preview']) < 5) {
                    $result['mae_stop_debug_preview'][] = [
                        'symbol' => $symbol,
                        'side' => $side,
                        'source' => $hintSource,
                        'side_sample_size' => (int)($h['side_sample_size'] ?? 0),
                        'side_winners_count' => (int)($h['side_winners_count'] ?? 0),
                        'symbol_sample_size' => (int)($h['symbol_sample_size'] ?? 0),
                        'suggested_logical_stop_roi' => $h['suggested_logical_stop_roi'] ?? null,
                        'fallback_used' => (bool)($h['mae_fallback_used'] ?? false),
                    ];
                }
            }

            // P0 FIX: Build full bot-ready risk contract from Brain signal risk + config
            $botReadyRisk = $this->buildBotReadyRiskBlock($risk, $signal, $liveConfig, $effectiveLimits);

            // UNIFIED EXIT CONTRACT: Derive the top-level trailing block from the canonical
            // risk.trailing that was just built, reverse-mapped to Brain field names.
            // This eliminates contradictions: both blocks come from the same canonical source.
            $canonicalTrailing = $botReadyRisk['trailing'] ?? [];
            $trailing = [
                'trailing_enabled' => (bool)($canonicalTrailing['enabled'] ?? false),
                // Reverse: percent→ratio for Brain traceability (5.0% → 0.05)
                'trailing_activation_roi' => ($canonicalTrailing['activation_roi_pct'] ?? 0) / 100,
                'trailing_min_lock_roi' => (float)($canonicalTrailing['min_lock_roi'] ?? 0),
                'trailing_min_step' => (float)($canonicalTrailing['min_step'] ?? 0),
                'break_even_enabled' => (bool)($canonicalTrailing['break_even_enabled'] ?? false),
                // Reverse: percent→ratio for Brain traceability (2.5% → 0.025)
                'break_even_activation_roi' => ($canonicalTrailing['break_even_activation_roi'] ?? 0) / 100,
                'exit_mode' => (string)($canonicalTrailing['exit_mode'] ?? 'hybrid_tp'),
                'fixed_take_profit_roi' => (float)($canonicalTrailing['fixed_take_profit_roi'] ?? 0),
                'hybrid_tp_share' => (float)($canonicalTrailing['hybrid_tp_share'] ?? 0),
                'drawdown_factor' => (float)($canonicalTrailing['drawdown_factor'] ?? 0.5),
                'stop_control_mode' => (string)($userLimits['stop_control_mode'] ?? 'auto'),
                'manual_stop_loss_roi' => (float)($userLimits['manual_stop_loss_roi'] ?? 0.03),
                'stop_loss_from_entry_roi' => (float)($userLimits['stop_loss_from_entry_roi'] ?? 0.10),
                'logical_stop_roi' => (float)($effectiveLimits['logical_stop_roi'] ?? 0.03),
                'canonical_source' => 'risk_trailing_derived',
            ];

            // P0.5: Validate bot-ready risk contract before writing
            $contractValidation = $this->validateBotReadyRiskContract($botReadyRisk);
            if (!$contractValidation['valid']) {
                $this->rejectLiveSignal($result, $symbol, $signalId, $contractValidation['reason'], $selectionMode);
                $result['live_invalid_risk_contract_count']++;
                // Debug preview
                if (count($result['live_debug_preview']) < 10) {
                    $result['live_debug_preview'][] = [
                        'symbol' => $symbol,
                        'signal_id_source' => $signalIdSource,
                        'outcome' => 'rejected',
                        'reason' => $contractValidation['reason'],
                        'profile_id_present' => !empty($botReadyRisk['profile_id']),
                        'limits_present' => !empty($botReadyRisk['limits']),
                    ];
                }
                continue;
            }

            // Increment approved count only after bot-ready validation passes
            $result['approved_count']++;

            $intent = [
                'schema_version' => 'live_intent_v1',
                'intent_id' => 'li_' . $signalId . '_' . substr(md5($signalId . $symbol . $side . $entryPolicy), 0, 8),
                'signal_id' => $signalId,
                'signal_id_source' => $signalIdSource,
                'symbol' => $symbol,
                'side' => $side,
                'entry_action' => $entryPolicy,
                'entry_timeout_minutes' => (int)($signal['entry_timeout_minutes'] ?? 8),
                'entry_price_reference' => $entryPriceRef,
                // ── CANONICAL SOURCES OF TRUTH ──────────────────────────────
                // risk       → bot-ready execution contract (budget, stop, trailing, logical/emergency stop)
                //               Built by buildBotReadyRiskBlock(). Bot consumes risk.trailing, risk.stop_control,
                //               risk.logical_stop, risk.emergency_stop directly.
                // trailing   → Brain-naming mirror of risk.trailing for traceability/UI.
                //               Derived from the SAME canonical risk.trailing (reverse percent→ratio).
                //               NOT a competing source of truth — just a read-friendly representation.
                // Stop policy fields live inside risk.stop_control — no separate stop_policy block needed.
                'risk' => $botReadyRisk,
                'trailing' => $trailing,
                'selection_mode_used' => $selectionMode,
                'selection_source' => $selectionSource,
                'approval_reason' => $approvalReason,
                'created_ts' => (int)($signal['created_ts'] ?? time()),
                'expires_at' => (int)($signal['expires_at'] ?? 0),
                'execution_limits_snapshot' => [
                    'live_max_positions' => (int)($liveConfig['live_max_positions'] ?? 3),
                    'live_one_trade_per_symbol' => (bool)($liveConfig['live_one_trade_per_symbol'] ?? true),
                ],
            ];

            if ($reverseEnabled && $sideOriginal !== $side) {
                $intent['side_original'] = $sideOriginal;
            }

            // P7: Attach per-symbol hint metadata for audit trail
            if ($symbolHints['applied']) {
                $intent['symbol_hints'] = $symbolHints;
            }

            if (isset($signal['schema_version'])) {
                $intent['source_schema_version'] = $signal['schema_version'];
            }

            $intents[] = $intent;
            $result['approvals'][] = [
                'symbol' => $symbol,
                'signal_id' => $signalId,
                'signal_id_source' => $signalIdSource,
                'reason' => $approvalReason,
            ];

            // Debug preview (first 10)
            if (count($result['live_debug_preview']) < 10) {
                $result['live_debug_preview'][] = [
                    'symbol' => $symbol,
                    'signal_id_source' => $signalIdSource,
                    'outcome' => 'approved',
                    'reason' => $approvalReason,
                    'profile_id' => $botReadyRisk['profile_id'] ?? null,
                    'budget_usdt_per_trade' => $botReadyRisk['budget_usdt_per_trade'] ?? null,
                    'order_type' => $botReadyRisk['order_type'] ?? null,
                    'has_limits' => !empty($botReadyRisk['limits']),
                ];
            }
        }

        $result['intents_created'] = count($intents);

        // Build rejection reason stats (grouped counts)
        $reasonStats = [];
        foreach ($result['rejection_reasons'] as $r) {
            $reason = $r['reason'] ?? 'unknown';
            $reasonStats[$reason] = ($reasonStats[$reason] ?? 0) + 1;
        }
        $result['rejection_reason_stats'] = $reasonStats;

        // P0.4: FINAL DEFENSIVE GUARD — sanitize all intents before writing.
        // Ensure no scalar take_profit can ever reach live_intents.json.
        // Also enforce trailing mode compatibility: when trailing contract covers exit,
        // remove take_profit entirely to avoid broken hybrid exit contracts.
        foreach ($intents as &$intentRef) {
            if (isset($intentRef['risk']['take_profit'])) {
                // Remove any non-array take_profit (scalar, int, string, null)
                if (!is_array($intentRef['risk']['take_profit'])) {
                    unset($intentRef['risk']['take_profit']);
                }
                // When trailing_tp mode or trailing enabled, remove take_profit entirely
                $exitMode = $intentRef['risk']['trailing']['exit_mode'] ?? '';
                $trailingOn = !empty($intentRef['risk']['trailing']['enabled']);
                if ($exitMode === 'trailing_tp' || $trailingOn) {
                    unset($intentRef['risk']['take_profit']);
                }
            }
        }
        unset($intentRef);

        // Write live_intents.json
        $payload = [
            'schema_version' => 'live_intents_v1',
            'generated_at' => date('c'),
            'live_trading_enabled' => true,
            'brain_controlled_live_mode' => true,
            'live_stage_runtime_signature' => self::LIVE_STAGE_VERSION,
            'effective_live_config' => $liveConfig,
            'intents' => $intents,
        ];
        $this->state->writeJson('storage/live_intents.json', $payload);
        $result['intents_written'] = count($intents);

        if (count($intents) > 0) {
            $this->logger->log('info', 'Live Intents: generated ' . count($intents) . ' intents (mode=' . $selectionMode . ', approved=' . $result['approved_count'] . ', rejected=' . $result['rejected_count'] . ')');
        } elseif ($result['signals_seen'] > 0) {
            $this->logger->log('info', 'Live Intents: 0 intents from ' . $result['signals_seen'] . ' signals (approved=' . $result['approved_count'] . ', rejected=' . $result['rejected_count'] . ', reasons=' . json_encode($reasonStats) . ')');
        }

        return $result;
    }

    /**
     * Record explicit rejection for a live-stage signal.
     * Increments rejected count, records reason, adds debug preview.
     */
    private function rejectLiveSignal(array &$result, string $symbol, string $signalId, string $reason, string $selectionMode): void
    {
        $result['rejected_count']++;
        $result['rejection_reasons'][] = [
            'symbol' => $symbol,
            'signal_id' => $signalId,
            'reason' => $reason,
            'selection_mode' => $selectionMode,
        ];

        // Debug preview (first 10)
        if (count($result['live_debug_preview']) < 10) {
            $result['live_debug_preview'][] = [
                'symbol' => $symbol !== '' ? $symbol : '(empty)',
                'signal_id_source' => $signalId !== '' ? 'present' : 'missing',
                'outcome' => 'rejected',
                'reason' => $reason,
            ];
        }
    }

    /**
     * Normalize risk block from signal.
     *
     * Canonical source priority:
     *   1. Structured risk block from signal (risk.leverage, risk.budget, risk.trailing, …)
     *   2. Flat signal fields as legacy fallback (signal.leverage, signal.budget, …)
     *
     * If signal has structured risk block, return it.
     * Otherwise, build risk block from flat signal fields.
     *
     * @return array Normalized risk block (may be empty if signal has no risk data)
     */
    private function normalizeRiskBlock(array $signal): array
    {
        // Prefer structured risk block if present and non-empty
        $risk = $signal['risk'] ?? [];
        if (is_array($risk) && !empty($risk) && !empty($risk['leverage'])) {
            // P0: Defensive guard — take_profit must be a valid structured array or absent.
            // Scalar take_profit is NEVER allowed in the outgoing risk block.
            if (array_key_exists('take_profit', $risk)) {
                $tp = $risk['take_profit'];
                if (is_array($tp) && !empty($tp)) {
                    // Valid structured take_profit — keep it
                } else {
                    // Scalar, null, zero, empty array, or any invalid value — remove entirely.
                    // Do NOT convert scalar to structured: prefer omission.
                    unset($risk['take_profit']);
                }
            }
            // P0 Part 3: Trailing mode compatibility — when trailing contract covers exit,
            // remove take_profit to avoid broken hybrid exit contract.
            $trailingBlock = $risk['trailing'] ?? [];
            if (is_array($trailingBlock) && !empty($trailingBlock['enabled'])) {
                unset($risk['take_profit']);
            }
            return $risk;
        }

        // Legacy fallback: build risk block from flat signal fields
        // @legacy — flat signal fields (signal.leverage, signal.budget) supported for backward
        // compatibility with older signal generators. New signals should use structured risk block.
        $leverage = $signal['leverage'] ?? null;
        $budget = $signal['budget'] ?? null;
        if ($leverage === null && $budget === null) {
            return [];
        }

        $normalized = [
            'leverage' => (int)($leverage ?? 1),
            'budget' => (float)($budget ?? 0),
            'stop_loss' => (float)($signal['stop_loss'] ?? 0),
        ];

        // P0: take_profit — only include if already a valid structured array.
        // Scalar take_profit is NEVER allowed. Prefer omission.
        $rawTp = $signal['take_profit'] ?? $signal['take_profit_ratio'] ?? null;
        if (is_array($rawTp) && !empty($rawTp)) {
            $normalized['take_profit'] = $rawTp;
        }
        // else: omit take_profit entirely — scalar values are never converted

        // Build trailing sub-block from flat exit policy fields
        $trailingEnabled = (bool)($signal['trailing_enabled'] ?? false);
        $normalized['trailing'] = [
            'enabled' => $trailingEnabled,
            'activation_roi_pct' => (float)($signal['trailing_activation_roi'] ?? 0.05),
            'min_lock_roi' => (float)($signal['trailing_min_lock_roi'] ?? 0.012),
            'min_step' => (float)($signal['trailing_min_step'] ?? 0.01),
        ];

        // P0 Part 3: When trailing covers exit, remove take_profit to avoid hybrid.
        if ($trailingEnabled) {
            unset($normalized['take_profit']);
        }

        return $normalized;
    }

    /**
     * Build a full bot-ready risk block from Brain signal risk + config.
     *
     * ── CANONICAL SOURCE OF TRUTH FOR BOT EXECUTION CONTRACT ─────────────
     * This function is the SINGLE canonical builder for the risk execution
     * contract that flows: Brain → live_intents.json → Trading Bot.
     *
     * Canonical fields produced:
     *   risk.trailing           → exit/trailing/BE contract (percent units for bot)
     *   risk.logical_stop       → strategy invalidation stop (separate from emergency)
     *   risk.emergency_stop     → liquidation safety net (independent of logical stop)
     *   risk.stop_control       → stop calculation mode (auto/manual/entry_roi)
     *
     * Unit convention (AUDIT):
     *   Brain config stores RATIOS (0.05 = 5%).
     *   Bot engines expect PERCENT (5.0 = 5%) for activation/BE fields.
     *   drawdown_factor is 0–1 multiplier, NOT percent — stays as-is.
     *   min_step, min_lock_roi, fixed_take_profit_roi, hybrid_tp_share stay as ratios.
     *
     * Maps Brain signal/risk fields into the complete execution contract
     * that Trading Bot validator (bot_risk_engine.php) requires:
     *   profile_id, budget_usdt_per_trade, leverage, stop_from_liq_range_pct,
     *   slippage_bps, fees_bps, order_type, limits, trailing.
     *
     * @param array $risk       Normalized risk block from normalizeRiskBlock()
     * @param array $signal     Original signal data
     * @param array $liveConfig Effective live config from buildLiveConfig()
     * @param array $userLimits User limits from config
     * @return array Full bot-ready risk block
     */
    private function buildBotReadyRiskBlock(array $risk, array $signal, array $liveConfig, array $userLimits): array
    {
        $profilesCfg = $this->config->getEffective('profiles');
        $profileKey = (string)($profilesCfg['default_profile'] ?? '111');
        $profile = (array)($profilesCfg['profiles'][$profileKey] ?? []);

        // A. budget mapping: Brain risk.budget → bot risk.budget_usdt_per_trade
        $budget = (float)($risk['budget'] ?? $signal['budget'] ?? $profile['budget'] ?? 10.0);

        // B. stop mapping: Brain risk.stop_loss (corridor-based) → bot stop_from_liq_range_pct
        //    Brain stop_loss is a corridor_width ratio. Bot expects a % distance from liquidation price.
        //    Conversion: use profile stop_loss_range or a documented default.
        //    If Brain provides stop_loss as a ratio (e.g. 0.05 = 5% of corridor),
        //    convert to stop_from_liq_range_pct which means "SL placed X% away from liquidation".
        $stopLossRatio = (float)($risk['stop_loss'] ?? $signal['stop_loss'] ?? 0);
        $profileStopLossRange = (float)($profile['stop_loss_range'] ?? 0.35);
        // Use the Brain-computed stop_loss if available, else profile default
        $stopFromLiqRangePct = $stopLossRatio > 0
            ? round($stopLossRatio * 100, 2)
            : round($profileStopLossRange * 100, 2);
        // Ensure minimum viable value (> 0, <= 100)
        $stopFromLiqRangePct = max(1.0, min(100.0, $stopFromLiqRangePct));

        // C. slippage / fees defaults from profile or documented defaults
        $slippageBps = (int)($risk['slippage_bps'] ?? $profile['slippage_bps'] ?? 20);
        $feesBps = (int)($risk['fees_bps'] ?? $profile['fees_bps'] ?? 12);

        // D. order_type: live bot uses market execution
        $orderType = (string)($risk['order_type'] ?? 'market');

        // E. limits block from live config
        $limits = [
            'max_open_trades' => (int)($liveConfig['live_max_positions'] ?? 3),
            'one_trade_per_symbol' => (bool)($liveConfig['live_one_trade_per_symbol'] ?? true),
        ];

        // F. profile_id: active profile key
        $profileId = (string)($risk['profile_id'] ?? $profileKey);

        // G. take_profit: use ONLY from normalized risk block (never from flat signal field).
        //    normalizeRiskBlock() already ensures take_profit is structured or absent.
        //    Do NOT read $signal['take_profit'] as it may be a scalar ratio.
        //    If scalar somehow survived normalizeRiskBlock — remove it entirely (never convert).
        $rawTakeProfit = $risk['take_profit'] ?? null;
        $takeProfitBlock = null;
        if (is_array($rawTakeProfit) && !empty($rawTakeProfit)) {
            // Already structured — pass through
            $takeProfitBlock = $rawTakeProfit;
        }
        // Scalar or any other non-array value → omit take_profit entirely

        // Build the complete bot-ready risk block
        $botReady = [
            'profile_id' => $profileId,
            'budget_usdt_per_trade' => round($budget, 2),
            'leverage' => (int)($risk['leverage'] ?? 1),
            'stop_from_liq_range_pct' => $stopFromLiqRangePct,
            'slippage_bps' => $slippageBps,
            'fees_bps' => $feesBps,
            'order_type' => $orderType,
            'limits' => $limits,
            // @legacy — original Brain risk fields preserved for audit/traceability.
            // Canonical values are budget_usdt_per_trade and stop_from_liq_range_pct above.
            'stop_loss' => (float)($risk['stop_loss'] ?? 0),
            'budget' => round($budget, 2),
        ];

        // ============================================================
        // CANONICAL TRAILING BLOCK — ONE SOURCE OF TRUTH
        // ============================================================
        // Build trailing from $userLimits (the canonical config source) so that
        // risk.trailing and the top-level trailing on the intent always agree.
        //
        // UNIT AUDIT: Brain config stores ratios (0.05 = 5%). Bot engines expect percent (5.0 = 5%).
        // activation_roi_pct and break_even_activation_roi: converted ratio→percent here.
        // drawdown_factor: 0–1 multiplier, NOT a percent — stays as-is.
        // trailing_price_distance_pct: ratio (0.02 = 2%) — stays as-is.
        // min_step, min_lock_roi: stay as ratios.
        // fixed_take_profit_roi: stays as ratio.
        // hybrid_tp_share: stays as ratio (0.40 = 40%).
        $trailingEnabled = (bool)($userLimits['trailing_enabled'] ?? false);
        $rawActivation = (float)($userLimits['trailing_activation_roi'] ?? 0.05);
        $rawBreakEvenActivation = (float)($userLimits['break_even_activation_roi'] ?? 0.025);

        // Convert ratio→percent: if value < 1.0, it's a ratio (0.05 = 5%)
        $activationPct = ($rawActivation > 0 && $rawActivation < 1.0) ? $rawActivation * 100 : $rawActivation;
        $breakEvenActivationPct = ($rawBreakEvenActivation > 0 && $rawBreakEvenActivation < 1.0) ? $rawBreakEvenActivation * 100 : $rawBreakEvenActivation;

        // Trailing mode: roi_giveback (default) or price_distance
        $trailingMode = (string)($userLimits['trailing_mode'] ?? 'roi_giveback');
        if (!in_array($trailingMode, ['roi_giveback', 'price_distance'], true)) {
            $trailingMode = 'roi_giveback';
        }

        // Price-distance trailing: fixed pct from current price (ratio, 0.02 = 2%)
        $trailingPriceDistancePct = (float)($userLimits['trailing_price_distance_pct'] ?? 0.02);
        // Validate bounds: min 0.005 (0.5%), max 0.20 (20%)
        if ($trailingPriceDistancePct < 0.005) { $trailingPriceDistancePct = 0.005; }
        if ($trailingPriceDistancePct > 0.20) { $trailingPriceDistancePct = 0.20; }

        $botReady['trailing'] = [
            'enabled' => $trailingEnabled,
            'trailing_mode' => $trailingMode,
            'activation_roi_pct' => $activationPct,
            'drawdown_factor' => 0.5,
            'trailing_price_distance_pct' => $trailingPriceDistancePct,
            'min_step' => (float)($userLimits['trailing_min_step'] ?? 0.01),
            'min_lock_roi' => (float)($userLimits['trailing_min_lock_roi'] ?? 0.012),
            'break_even_enabled' => (bool)($userLimits['break_even_enabled'] ?? false),
            'break_even_activation_roi' => $breakEvenActivationPct,
            'exit_mode' => (string)($userLimits['exit_mode'] ?? 'hybrid_tp'),
            'fixed_take_profit_roi' => (float)($userLimits['fixed_take_profit_roi'] ?? 0.03),
            'hybrid_tp_share' => (float)($userLimits['hybrid_tp_share'] ?? 0.40),
            'brain_trailing_applied' => true,
            'unit_system' => 'activation_pct=percent,drawdown_factor=ratio,trailing_price_distance_pct=ratio,min_step=ratio,min_lock_roi=ratio,fixed_tp_roi=ratio,hybrid_share=ratio',
        ];

        // Logical stop vs emergency stop separation
        // Emergency stop = existing stop_from_liq_range_pct (liquidation-based safety net)
        // Logical stop = strategy invalidation stop (closer, based on corridor/structure)
        // When MAE-adaptive stop is active, logical_stop_roi comes from symbol-side p75 MAE winners
        $logicalStopRoi = (float)($userLimits['logical_stop_roi'] ?? 0.03);
        // Use real source from per-symbol hints if available, otherwise determine from value
        $logicalStopSource = (string)($userLimits['_logical_stop_source'] ?? '');
        if ($logicalStopSource === '') {
            $logicalStopSource = ($logicalStopRoi !== 0.03) ? 'mae_adaptive' : 'brain_config';
        }
        $botReady['logical_stop'] = [
            'enabled' => true,
            'logical_stop_roi' => $logicalStopRoi,
            'mode' => 'strategy_invalidation',
            'source' => $logicalStopSource,
        ];
        $botReady['emergency_stop'] = [
            'enabled' => true,
            'stop_from_liq_range_pct' => $stopFromLiqRangePct,
            'mode' => 'liquidation_safety_net',
            'source' => 'brain_config',
        ];

        // Stop control mode: determines how exchange SL is calculated
        // auto = liquidation-based (stop_from_liq_range_pct)
        // manual = fixed ROI from entry (manual_stop_loss_roi)
        // entry_roi = percentage from entry price (stop_loss_from_entry_roi)
        $stopControlMode = (string)($userLimits['stop_control_mode'] ?? 'auto');
        $stopLossFromEntryRoi = (float)($userLimits['stop_loss_from_entry_roi'] ?? 0.10);
        $manualStopLossRoi = (float)($userLimits['manual_stop_loss_roi'] ?? 0.03);
        $botReady['stop_control'] = [
            'stop_control_mode' => $stopControlMode,
            'stop_loss_from_entry_roi' => $stopLossFromEntryRoi,
            'manual_stop_loss_roi' => $manualStopLossRoi,
            'source' => 'brain_config',
        ];

        // P0 Part 3: Trailing mode compatibility — when exit_mode is trailing_tp
        // or trailing is enabled, do NOT include direct take_profit.
        // One valid exit contract, not a broken hybrid.
        $exitMode = $botReady['trailing']['exit_mode'] ?? '';
        $trailingEnabled = !empty($botReady['trailing']['enabled']);
        if ($exitMode === 'trailing_tp' || $trailingEnabled) {
            $takeProfitBlock = null;
        }

        // Only include take_profit if structured, valid, and not suppressed by trailing mode
        if (is_array($takeProfitBlock) && !empty($takeProfitBlock)) {
            $botReady['take_profit'] = $takeProfitBlock;
        }

        return $botReady;
    }

    /**
     * Build a consistent effective trailing contract summary from userLimits.
     * Used for runtime display and Brain→Bot contract traceability.
     *
     * @param array $userLimits User limits from config
     * @return array Effective trailing contract summary
     */
    private function buildEffectiveTrailingContractSummary(array $userLimits): array
    {
        $rawActivation = (float)($userLimits['trailing_activation_roi'] ?? 0.05);
        $rawBreakEvenActivation = (float)($userLimits['break_even_activation_roi'] ?? 0.025);

        return [
            'trailing_enabled' => (bool)($userLimits['trailing_enabled'] ?? false),
            'trailing_activation_roi' => $rawActivation,
            'trailing_activation_roi_pct' => ($rawActivation > 0 && $rawActivation < 1.0) ? $rawActivation * 100 : $rawActivation,
            'trailing_min_lock_roi' => (float)($userLimits['trailing_min_lock_roi'] ?? 0.012),
            'trailing_min_step' => (float)($userLimits['trailing_min_step'] ?? 0.01),
            'break_even_enabled' => (bool)($userLimits['break_even_enabled'] ?? false),
            'break_even_activation_roi' => $rawBreakEvenActivation,
            'break_even_activation_roi_pct' => ($rawBreakEvenActivation > 0 && $rawBreakEvenActivation < 1.0) ? $rawBreakEvenActivation * 100 : $rawBreakEvenActivation,
            'exit_mode' => (string)($userLimits['exit_mode'] ?? 'hybrid_tp'),
            'fixed_take_profit_roi' => (float)($userLimits['fixed_take_profit_roi'] ?? 0.03),
            'hybrid_tp_share' => (float)($userLimits['hybrid_tp_share'] ?? 0.40),
            'logical_stop_roi' => (float)($userLimits['logical_stop_roi'] ?? 0.03),
            'drawdown_factor' => 0.5,
            'canonical_source' => 'brain_user_limits',
            'unit_system' => 'activation_roi=ratio,activation_roi_pct=percent,drawdown_factor=ratio,min_step=ratio,min_lock_roi=ratio,fixed_tp_roi=ratio,hybrid_share=ratio',
        ];
    }

    /**
     * P0.5: Validate that a risk block has all mandatory bot execution fields.
     * Should be called before writing a live intent to live_intents.json.
     *
     * @param array $risk Bot-ready risk block
     * @return array{valid:bool,reason:string}
     */
    private function validateBotReadyRiskContract(array $risk): array
    {
        $requiredFields = [
            'profile_id',
            'budget_usdt_per_trade',
            'leverage',
            'stop_from_liq_range_pct',
            'slippage_bps',
            'fees_bps',
            'order_type',
            'limits',
            'trailing',
        ];

        $missing = [];
        foreach ($requiredFields as $field) {
            if (!isset($risk[$field]) || $risk[$field] === '' || $risk[$field] === null) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            return [
                'valid' => false,
                'reason' => 'invalid_risk_contract:missing_' . implode(',', $missing),
            ];
        }

        // Validate limits is an array with required sub-fields
        if (!is_array($risk['limits'])) {
            return ['valid' => false, 'reason' => 'invalid_risk_contract:missing_limits'];
        }
        if (!isset($risk['limits']['max_open_trades'])) {
            return ['valid' => false, 'reason' => 'invalid_risk_contract:missing_limits.max_open_trades'];
        }
        if (!isset($risk['limits']['one_trade_per_symbol'])) {
            return ['valid' => false, 'reason' => 'invalid_risk_contract:missing_limits.one_trade_per_symbol'];
        }

        // Validate trailing is an array
        if (!is_array($risk['trailing'])) {
            return ['valid' => false, 'reason' => 'invalid_risk_contract:missing_trailing'];
        }

        // Validate take_profit format (optional, but if present must be an array)
        if (isset($risk['take_profit']) && !is_array($risk['take_profit'])) {
            return ['valid' => false, 'reason' => 'invalid_risk_contract:invalid_take_profit_format'];
        }

        // Validate numeric ranges
        if ((float)($risk['budget_usdt_per_trade'] ?? 0) <= 0) {
            return ['valid' => false, 'reason' => 'invalid_risk_contract:invalid_budget_usdt_per_trade'];
        }
        if ((int)($risk['leverage'] ?? 0) < 1) {
            return ['valid' => false, 'reason' => 'invalid_risk_contract:invalid_leverage'];
        }
        if ((float)($risk['stop_from_liq_range_pct'] ?? 0) <= 0 || (float)($risk['stop_from_liq_range_pct'] ?? 0) > 100) {
            return ['valid' => false, 'reason' => 'invalid_risk_contract:invalid_stop_from_liq_range_pct'];
        }
        if ((int)($risk['slippage_bps'] ?? -1) < 0) {
            return ['valid' => false, 'reason' => 'invalid_risk_contract:invalid_slippage_bps'];
        }
        if ((int)($risk['fees_bps'] ?? -1) < 0) {
            return ['valid' => false, 'reason' => 'invalid_risk_contract:invalid_fees_bps'];
        }

        return ['valid' => true, 'reason' => ''];
    }

    /**
     * Resolve entry price reference from signal.
     * Checks structured entry block first, then flat fields.
     *
     * @return float Entry price reference (0.0 if not available)
     */
    private function resolveEntryPriceReference(array $signal): float
    {
        // Structured entry block
        $entry = $signal['entry'] ?? [];
        if (is_array($entry) && !empty($entry['price']) && (float)$entry['price'] > 0) {
            return (float)$entry['price'];
        }

        // Flat entry_price field
        if (!empty($signal['entry_price']) && (float)$signal['entry_price'] > 0) {
            return (float)$signal['entry_price'];
        }

        // Compute from entry zone midpoint
        $entryZoneLow = (float)($signal['entry_zone_low'] ?? 0);
        $entryZoneHigh = (float)($signal['entry_zone_high'] ?? 0);
        if ($entryZoneLow > 0 && $entryZoneHigh > 0) {
            return round(($entryZoneLow + $entryZoneHigh) / 2, 8);
        }

        return 0.0;
    }

    /**
     * Build a deterministic signal ID from stable signal fields.
     *
     * Used when signal['id'] is missing. The generated ID is stable:
     * the same signal payload produces the same fallback ID across runs.
     *
     * @param array<string,mixed> $signal Signal data
     * @return string Deterministic fallback signal ID (prefixed with 'fb_')
     */
    private function buildDeterministicSignalId(array $signal): string
    {
        $parts = [
            'symbol' => (string)($signal['symbol'] ?? ''),
            'side' => (string)($signal['side'] ?? ''),
            'pattern_algorithm' => (string)($signal['pattern_algorithm'] ?? ''),
            'signal_mode' => (string)($signal['signal_mode'] ?? ''),
            'corridor_low' => (string)($signal['corridor_low'] ?? ''),
            'corridor_high' => (string)($signal['corridor_high'] ?? ''),
            'entry_zone_low' => (string)($signal['entry_zone_low'] ?? ''),
            'entry_zone_high' => (string)($signal['entry_zone_high'] ?? ''),
        ];

        $hashInput = implode('|', $parts);
        return 'fb_' . substr(md5($hashInput), 0, 16);
    }

    /**
     * Load a symbol list from storage JSON file.
     * Returns flat array of symbol strings.
     *
     * @param string $filename Filename relative to storage/
     * @return list<string>
     */
    private function loadSymbolList(string $filename): array
    {
        $result = $this->config->loadSymbolListJson($filename);
        if (!$result['valid'] && $result['warning'] !== '') {
            $this->logger->log('warning', $result['warning']);
        }
        $list = $result['list'];
        // Extract symbol names from list items
        $symbols = [];
        foreach ($list as $item) {
            if (is_string($item)) {
                $symbols[] = $item;
            } elseif (is_array($item) && isset($item['symbol'])) {
                $symbols[] = (string)$item['symbol'];
            }
        }
        return $symbols;
    }

    // =========================================================================
    // Lock mechanism
    // =========================================================================

    /**
     * Acquire run lock. Returns true if lock acquired, false if another run is active.
     * Checks for stale locks (older than LOCK_STALE_SECONDS) and recovers automatically.
     *
     * @return bool true if lock acquired or stale lock recovered; false if fresh lock held
     */
    private function acquireLock(string $source): bool
    {
        $lockPath = $this->moduleBase . '/' . self::LOCK_FILE;
        $lockDir = dirname($lockPath);
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0755, true);
        }

        // Check for existing lock
        if (is_file($lockPath)) {
            $raw = @file_get_contents($lockPath);
            if ($raw !== false && trim($raw) !== '') {
                $lockData = json_decode($raw, true);
                if (is_array($lockData)) {
                    $lockedAt = (int)($lockData['timestamp'] ?? 0);
                    // If lock is fresh (not stale), reject
                    if ($lockedAt > 0 && (time() - $lockedAt) < self::LOCK_STALE_SECONDS) {
                        return false;
                    }
                    // Stale lock — log and recover
                    $this->logger->log('warning', 'Stale lock detected (age=' . (time() - $lockedAt) . 's), recovering');
                }
            }
        }

        // Write lock
        $lockData = [
            'pid' => getmypid(),
            'source' => $source,
            'timestamp' => time(),
            'acquired_at' => date('c'),
        ];

        $written = @file_put_contents($lockPath, json_encode($lockData, JSON_UNESCAPED_SLASHES), LOCK_EX);
        if ($written === false) {
            $this->logger->log('warning', 'Failed to write lock file');
            return false;
        }

        return true;
    }

    /**
     * Release run lock.
     */
    private function releaseLock(): void
    {
        $lockPath = $this->moduleBase . '/' . self::LOCK_FILE;
        if (is_file($lockPath)) {
            @unlink($lockPath);
        }
    }

    /**
     * Build symbol→price map.
     * Primary: Bybit live prices.
     * Fallback: internal last_price from candidates.
     *
     * @param array<string,float>            $livePrices   Bybit prices
     * @param array<int,array<string,mixed>> $candidates   Parser4 candidates
     * @return array<string,float>
     */
    private function buildPriceMap(array $livePrices, array $candidates): array
    {
        $prices = $livePrices;

        // Fallback: use internal last_price for symbols not in livePrices
        foreach ($candidates as $c) {
            $sym = (string)($c['symbol'] ?? '');
            $p   = (float)($c['last_price'] ?? 0.0);
            if ($sym !== '' && $p > 0.0 && !isset($prices[$sym])) {
                $prices[$sym] = $p;
            }
        }

        return $prices;
    }

    // =========================================================================
    // Trading Bot Execution Mirror
    // =========================================================================

    /**
     * Read the latest Trading Bot runtime summary for mirror display.
     *
     * ── MIRROR LAYER CANONICAL MAP ──────────────────────────────────────
     * Source of truth: Trading Bot → storage/last_run.json
     * Mirror consumer: Brain dashboard + runtime views
     *
     * Mirrored fields (all read-only, never written back to bot):
     *   - Execution counters: intents_processed/opened/skipped/rejected/failed
     *   - Exchange submit stats: exchange_submit_attempted/failed/success_count
     *   - Active protection: active_positions_count, trailing_active_count, etc.
     *   - Contract generation mix: active_trade_contract_generation_stats
     *   - Flat effective contract: effective_exit_mode, effective_trailing_*, etc.
     *   - Symbol exit stats (P7): per-symbol closed trade statistics + MAE data
     *   - Expectancy metrics (P6): winrate, average_win/loss, expectancy
     *
     * Flat effective_* fields use bot's flat last_run fields with fallback to
     * nested effective_trailing_contract for older bot versions.
     *
     * Reads bot storage/last_run.json safely and extracts a compact summary.
     * If bot runtime is unavailable, returns a graceful fallback.
     *
     * @return array<string,mixed> Compact bot execution mirror
     */
    private function readBotExecutionMirror(): array
    {
        $mirror = [
            'available' => false,
            'error' => null,
            'brain_controlled_live_mode' => null,
            'bot_controlled_by_brain' => null,
            'bot_input_source' => null,
            'approved_intents_loaded' => null,
            'duplicate_skipped' => null,
            'intents_processed' => null,
            'intents_opened' => null,
            'intents_skipped' => null,
            'intents_rejected' => null,
            'intents_failed' => null,
            'intents_deferred' => null,
            'rejection_reason_stats' => [],
            'close_reason_stats' => [],
            'source_status' => null,
            'source_error_message' => null,
            'bot_last_updated_at' => null,
            // P0.3: Exchange submit visibility
            'executable_after_dedupe' => null,
            'busy_skipped' => null,
            'executable_after_busy' => null,
            'exchange_submit_attempted_count' => null,
            'exchange_submit_failed_count' => null,
            'exchange_submit_success_count' => null,
            'position_open_confirmed_count' => null,
            'protection_apply_failed_count' => null,
            'execution_guard_blocked_count' => null,
            // P0.4: Latest exchange error
            'latest_exchange_error_code' => null,
            'latest_exchange_error_message' => null,
            'last_failed_symbol' => null,
            'last_failed_stage' => null,
            // P0.8: Top blocker reason
            'top_rejection_reason' => null,
            'top_execution_block_reason' => null,
            // P0.9: No-order-path debug preview
            'no_order_path_preview' => [],
            // Active protection state mirror
            'active_positions_count' => 0,
            'protected_positions_count' => 0,
            'trailing_active_count' => 0,
            'break_even_armed_count' => 0,
            'break_even_applied_count' => 0,
            'protection_errors_count' => 0,
            'active_protection_summary' => [],
            'active_position_protection_details' => [],
            // Contract generation mix stats (Part 7: Brain mirror must not flatten mixed generations)
            'active_trade_contract_generation_stats' => [],
            // Flat effective post-entry contract fields (from bot runtime)
            'effective_exit_mode' => null,
            'effective_break_even_enabled' => null,
            'effective_break_even_activation' => null,
            'effective_trailing_activation' => null,
            'effective_trailing_enabled' => null,
            'effective_drawdown_factor' => null,
            'effective_hybrid_tp_share' => null,
            'effective_trailing_contract_source' => null,
            // P7: Per-symbol exit statistics
            'symbol_exit_stats' => [],
        ];

        try {
            // Resolve Trading Bot storage path relative to Smart Brain module
            $botStoragePath = $this->resolveBotStoragePath();
            if ($botStoragePath === null) {
                $mirror['error'] = 'bot_storage_path_not_found';
                return $mirror;
            }

            $botLastRunPath = $botStoragePath . '/last_run.json';
            if (!is_file($botLastRunPath)) {
                $mirror['error'] = 'bot_last_run_not_found';
                return $mirror;
            }

            $content = @file_get_contents($botLastRunPath);
            if ($content === false) {
                $mirror['error'] = 'bot_last_run_read_failed';
                return $mirror;
            }

            $botData = @json_decode($content, true);
            if (!is_array($botData)) {
                $mirror['error'] = 'bot_last_run_json_invalid';
                return $mirror;
            }

            $mirror['available'] = true;
            $mirror['brain_controlled_live_mode'] = (bool)($botData['brain_controlled_live_mode'] ?? false);
            $mirror['bot_controlled_by_brain'] = (bool)($botData['controlled_by_brain'] ?? false);
            $mirror['bot_input_source'] = (string)($botData['input_source'] ?? 'unknown');
            $mirror['approved_intents_loaded'] = (int)($botData['approved_intents_loaded'] ?? 0);
            $mirror['duplicate_skipped'] = (int)($botData['duplicate_skipped'] ?? 0);
            $mirror['intents_processed'] = (int)($botData['intents_processed'] ?? 0);
            $mirror['intents_opened'] = (int)($botData['intents_opened'] ?? 0);
            $mirror['intents_skipped'] = (int)($botData['intents_skipped'] ?? 0);
            $mirror['intents_rejected'] = (int)($botData['intents_rejected_exec'] ?? 0);
            $mirror['intents_failed'] = (int)($botData['intents_failed_exec'] ?? 0);
            $mirror['intents_deferred'] = (int)($botData['intents_deferred'] ?? 0);
            $mirror['rejection_reason_stats'] = (array)($botData['rejection_reason_stats'] ?? []);
            $mirror['close_reason_stats'] = (array)($botData['close_reason_stats'] ?? []);
            $mirror['source_status'] = (string)($botData['source_status'] ?? 'unknown');
            $mirror['source_error_message'] = (string)($botData['source_error_message'] ?? '');
            $mirror['bot_last_updated_at'] = (string)($botData['timestamp'] ?? $botData['updated_at'] ?? '');

            // P0.3: Exchange submit visibility
            $mirror['executable_after_dedupe'] = (int)($botData['executable_after_dedupe'] ?? 0);
            $mirror['busy_skipped'] = (int)($botData['busy_skipped'] ?? 0);
            $mirror['executable_after_busy'] = (int)($botData['executable_after_busy'] ?? 0);
            $mirror['exchange_submit_attempted_count'] = (int)($botData['exchange_submit_attempted_count'] ?? 0);
            $mirror['exchange_submit_failed_count'] = (int)($botData['exchange_submit_failed_count'] ?? 0);
            $mirror['exchange_submit_success_count'] = (int)($botData['exchange_submit_success_count'] ?? 0);
            $mirror['position_open_confirmed_count'] = (int)($botData['position_open_confirmed_count'] ?? 0);
            $mirror['protection_apply_failed_count'] = (int)($botData['protection_apply_failed_count'] ?? 0);
            $mirror['execution_guard_blocked_count'] = (int)($botData['execution_guard_blocked_count'] ?? 0);

            // P0.4: Latest exchange error
            $mirror['latest_exchange_error_code'] = $botData['latest_exchange_error_code'] ?? null;
            $mirror['latest_exchange_error_message'] = (string)($botData['latest_exchange_error_message'] ?? '');
            $mirror['last_failed_symbol'] = (string)($botData['last_failed_symbol'] ?? '');
            $mirror['last_failed_stage'] = (string)($botData['last_failed_stage'] ?? '');

            // P0.8: Top blocker reasons
            $rejStats = (array)($botData['rejection_reason_stats'] ?? []);
            $topRejection = '';
            $topRejCount = 0;
            foreach ($rejStats as $reason => $cnt) {
                if ((int)$cnt > $topRejCount) {
                    $topRejection = (string)$reason;
                    $topRejCount = (int)$cnt;
                }
            }
            $mirror['top_rejection_reason'] = $topRejection !== '' ? $topRejection : null;

            // Determine top execution block reason from execution_stage_stats
            $stageStats = (array)($botData['execution_stage_stats'] ?? []);
            $blockReasons = ['validation_rejected', 'execution_guard_blocked', 'exchange_prepare_failed', 'exchange_submit_failed', 'protection_apply_failed'];
            $topBlockReason = '';
            $topBlockCount = 0;
            foreach ($blockReasons as $blockStage) {
                $cnt = (int)($stageStats[$blockStage] ?? 0);
                if ($cnt > $topBlockCount) {
                    $topBlockReason = $blockStage;
                    $topBlockCount = $cnt;
                }
            }
            $mirror['top_execution_block_reason'] = $topBlockReason !== '' ? $topBlockReason : null;

            // Active protection state mirror (Part 6/8 — live audit)
            $activeProtSummary = is_array($botData['active_protection_summary'] ?? null) ? $botData['active_protection_summary'] : [];
            $mirror['active_positions_count'] = (int)($activeProtSummary['active_positions_count'] ?? 0);
            $mirror['protected_positions_count'] = (int)($activeProtSummary['protected_positions_count'] ?? 0);
            $mirror['trailing_active_count'] = (int)($activeProtSummary['trailing_active_count'] ?? 0);
            $mirror['break_even_armed_count'] = (int)($activeProtSummary['break_even_armed_count'] ?? 0);
            $mirror['break_even_applied_count'] = (int)($activeProtSummary['break_even_applied_count'] ?? 0);
            $mirror['protection_errors_count'] = (int)($activeProtSummary['protection_errors_count'] ?? 0);

            // Per-trade protection details (protection_state, trailing source, logical stop)
            $mirror['active_protection_summary'] = $activeProtSummary;
            $mirror['active_position_protection_details'] = is_array($botData['active_position_protection_details'] ?? null)
                ? $botData['active_position_protection_details']
                : [];

            // Contract generation mix stats (Part 7: Brain must not flatten mixed generations)
            $mirror['active_trade_contract_generation_stats'] = is_array($botData['active_trade_contract_generation_stats'] ?? null)
                ? $botData['active_trade_contract_generation_stats']
                : [];

            // P0.9: No-order-path debug preview
            $mirror['no_order_path_preview'] = is_array($botData['no_order_path_preview'] ?? null)
                ? array_slice($botData['no_order_path_preview'], 0, 5)
                : [];

            // P6: Expectancy metrics mirror
            $expectancy = is_array($botData['expectancy_metrics'] ?? null) ? $botData['expectancy_metrics'] : [];
            $mirror['expectancy_metrics'] = [
                'total_closed' => (int)($expectancy['total_closed'] ?? 0),
                'wins' => (int)($expectancy['wins'] ?? 0),
                'losses' => (int)($expectancy['losses'] ?? 0),
                'average_win' => (float)($expectancy['average_win'] ?? 0),
                'average_loss' => (float)($expectancy['average_loss'] ?? 0),
                'winrate' => (float)($expectancy['winrate'] ?? 0),
                'expectancy' => (float)($expectancy['expectancy'] ?? 0),
            ];

            // P7: Per-symbol exit statistics mirror
            $mirror['symbol_exit_stats'] = is_array($botData['symbol_exit_stats'] ?? null)
                ? $botData['symbol_exit_stats'] : [];

            // MAE stop diagnostics: track whether bot stats were loaded and how many hints are possible
            $symExitStats = $mirror['symbol_exit_stats'];
            $mirror['bot_symbol_exit_stats_loaded'] = !empty($symExitStats);
            $mirror['bot_symbol_exit_stats_symbols_count'] = is_array($symExitStats) ? count($symExitStats) : 0;
            $maeHintsAvailable = 0;
            $maeHintsFallback = 0;
            $maeMinWinners = 5;
            foreach ($symExitStats as $sym => $ss) {
                if (!is_array($ss)) {
                    continue;
                }
                foreach (['long', 'short'] as $sideKey) {
                    $sideData = $ss['by_side'][$sideKey] ?? null;
                    if (is_array($sideData) && !empty($sideData['mae_winners_stats'])) {
                        $wc = (int)($sideData['mae_winners_stats']['count'] ?? 0);
                        if ($wc >= $maeMinWinners) {
                            $maeHintsAvailable++;
                        } else {
                            $maeHintsFallback++;
                        }
                    } else {
                        $maeHintsFallback++;
                    }
                }
            }
            $mirror['mae_stop_hints_available_count'] = $maeHintsAvailable;
            $mirror['mae_stop_hints_fallback_count'] = $maeHintsFallback;

            // Effective trailing contract mirror from bot runtime
            // @legacy — nested effective_trailing_contract kept as fallback for older bot versions
            // that don't expose flat effective_* fields at the top level of last_run.json.
            // Remove after all active bot instances emit flat fields directly.
            $botEffectiveContract = is_array($botData['effective_trailing_contract'] ?? null) ? $botData['effective_trailing_contract'] : [];
            $mirror['effective_trailing_contract'] = $botEffectiveContract;

            // Flat effective post-entry contract fields (canonical: directly from bot last_run)
            // Fallback to nested effective_trailing_contract for older bot versions.
            $mirror['effective_exit_mode'] = $botData['effective_exit_mode'] ?? ($botEffectiveContract['exit_mode'] ?? null);
            $mirror['effective_break_even_enabled'] = $botData['effective_break_even_enabled'] ?? ($botEffectiveContract['break_even_enabled'] ?? null);
            $mirror['effective_break_even_activation'] = $botData['effective_break_even_activation'] ?? ($botEffectiveContract['break_even_activation_roi'] ?? null);
            $mirror['effective_trailing_activation'] = $botData['effective_trailing_activation'] ?? ($botEffectiveContract['activation_roi_pct'] ?? null);
            $mirror['effective_trailing_enabled'] = $botData['effective_trailing_enabled'] ?? ($botEffectiveContract['enabled'] ?? null);
            $mirror['effective_drawdown_factor'] = $botData['effective_drawdown_factor'] ?? ($botEffectiveContract['drawdown_factor'] ?? null);
            $mirror['effective_hybrid_tp_share'] = $botData['effective_hybrid_tp_share'] ?? ($botEffectiveContract['hybrid_tp_share'] ?? null);
            $mirror['effective_trailing_contract_source'] = (string)($botData['effective_trailing_contract_source'] ?? 'unknown');

            // Stop control mode mirror
            $mirror['effective_stop_control_mode'] = $botData['effective_stop_control_mode'] ?? 'auto';
            $mirror['effective_stop_loss_from_entry_roi'] = $botData['effective_stop_loss_from_entry_roi'] ?? null;
            $mirror['effective_stop_price'] = $botData['effective_stop_price'] ?? null;
            // Initial vs current stop separation mirror
            $mirror['initial_computed_stop_price'] = $botData['initial_computed_stop_price'] ?? null;
            $mirror['stop_moved_from_initial'] = (bool)($botData['stop_moved_from_initial'] ?? false);

        } catch (\Throwable $e) {
            $mirror['error'] = 'exception: ' . $e->getMessage();
        }

        return $mirror;
    }

    /**
     * Resolve Trading Bot storage path.
     * Smart Brain lives at modules/system/smart_brain/, Bot at modules/system/trading_bot/.
     *
     * @return string|null Absolute path to bot storage, or null if not found
     */
    private function resolveBotStoragePath(): ?string
    {
        // Direct sibling: smart_brain → trading_bot
        $candidate = realpath($this->moduleBase . '/../trading_bot/storage');
        if ($candidate !== false && is_dir($candidate)) {
            return $candidate;
        }

        // Try via SystemPaths if registered
        try {
            $paths = \Core\System\SystemPaths::instance();
            if ($paths->has('system.trading_bot.storage')) {
                $resolved = $paths->get('system.trading_bot.storage');
                if (is_dir($resolved)) {
                    return $resolved;
                }
            }
            // Fallback: system.trading_bot module root + /storage
            if ($paths->has('system.trading_bot')) {
                $resolved = $paths->get('system.trading_bot') . '/storage';
                if (is_dir($resolved)) {
                    return $resolved;
                }
            }
        } catch (\Throwable $e) {
            // SystemPaths not available — rely on filesystem only
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    public function getDashboardData(): array
    {
        $uiSettings = $this->config->getEffective('ui');
        return [
            'title' => (string)($uiSettings['title'] ?? 'Smart Brain'),
            'last_run' => $this->state->readJson('storage/last_run.json', []),
            'signals' => $this->state->readJson('storage/signals.json', []),
            'monitors' => $this->state->readJson('storage/monitors.json', []),
            'waiting' => $this->state->readJson('storage/simulator/waiting.json', []),
            'active' => $this->state->readJson('storage/simulator/active.json', []),
            'closed' => $this->state->readJson('storage/simulator/closed.json', []),
            'stats' => $this->state->readJson('storage/simulator/stats.json', []),
            'live_intents' => $this->state->readJson('storage/live_intents.json', []),
            'config_warnings' => $this->config->detectConfigConflicts(),
            'bot_execution_mirror' => $this->readBotExecutionMirror(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getRuntimeData(): array
    {
        return [
            'config' => $this->config->all(),
            'snapshot' => $this->state->readJson('runtime/config.snapshot.json', []),
            'last_run' => $this->state->readJson('storage/last_run.json', []),
            'stats' => $this->state->readJson('storage/simulator/stats.json', []),
            'live_intents' => $this->state->readJson('storage/live_intents.json', []),
            'config_warnings' => $this->config->detectConfigConflicts(),
            'bot_execution_mirror' => $this->readBotExecutionMirror(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getConfigData(): array
    {
        return [
            'config' => $this->config->all(),
            'user_limits' => $this->config->getUserLimits(),
            'brain_auto' => $this->config->getBrainAutoValues(),
            'effective_config' => $this->state->readJson('runtime/effective_config.json', []),
        ];
    }

    /**
     * Get data for User Config form.
     *
     * @return array<string,mixed>
     */
    public function getUserConfigData(): array
    {
        $userLimits = $this->config->getUserLimits();

        // Get effective pattern selection from parser4 config (base merged with user override)
        $parser4Cfg = $this->config->get('parser4', []);
        $patternAlgorithms = (array)($parser4Cfg['pattern_algorithms'] ?? []);
        $patternsEnabled = (array)($patternAlgorithms['enabled'] ?? []);
        $patternMode = (string)($patternAlgorithms['mode'] ?? 'one');

        // If user has patterns saved, use those for form display
        $savedUserConfig = $this->config->loadUserConfig();
        if (isset($savedUserConfig['patterns']) && is_array($savedUserConfig['patterns'])) {
            $patternsEnabled = (array)($savedUserConfig['patterns']['enabled'] ?? $patternsEnabled);
            $patternMode = (string)($savedUserConfig['patterns']['mode'] ?? $patternMode);
        }

        return [
            'user_limits' => $userLimits,
            'patterns_enabled' => $patternsEnabled,
            'pattern_mode' => $patternMode,
            'symbol_intelligence_enabled' => (bool)($userLimits['symbol_intelligence_enabled'] ?? false),
            'symbol_filter_mode' => (string)($userLimits['symbol_filter_mode'] ?? 'all'),
            'manual_symbol_universe_enabled' => (bool)($userLimits['manual_symbol_universe_enabled'] ?? false),
            'manual_symbol_mode' => (string)($userLimits['manual_symbol_mode'] ?? 'manual_only'),
            'config_warnings' => $this->config->detectConfigConflicts(),
        ];
    }

    /**
     * Save user config and return result.
     *
     * @param array<string,mixed> $values
     * @return array{ok:bool,errors:list<string>}
     */
    public function saveUserConfig(array $values): array
    {
        return $this->config->saveUserConfig($values);
    }

    /**
     * @return array<string,mixed>
     */
    public function getAnalizatorData(): array
    {
        $parser4Cfg = $this->config->get('parser4', []);
        $patternAlgorithms = (array)($parser4Cfg['pattern_algorithms'] ?? []);

        return [
            'candidates' => $this->state->readJson('storage/candidates.json', []),
            'signals' => $this->state->readJson('storage/signals.json', []),
            'monitors' => $this->state->readJson('storage/monitors.json', []),
            'last_run' => $this->state->readJson('storage/last_run.json', []),
            'pattern_selection' => [
                'enabled' => (array)($patternAlgorithms['enabled'] ?? []),
                'mode' => (string)($patternAlgorithms['mode'] ?? 'one'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getSimulatorData(): array
    {
        return [
            'waiting' => $this->state->readJson('storage/simulator/waiting.json', []),
            'active' => $this->state->readJson('storage/simulator/active.json', []),
            'closed' => $this->state->readJson('storage/simulator/closed.json', []),
            'stats' => $this->state->readJson('storage/simulator/stats.json', []),
            'last_run' => $this->state->readJson('storage/last_run.json', []),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getSimulatorAnalyticsData(): array
    {
        $waiting = $this->state->readJson('storage/simulator/waiting.json', []);
        $active  = $this->state->readJson('storage/simulator/active.json', []);
        $closed  = $this->state->readJson('storage/simulator/closed.json', []);
        $stats   = $this->state->readJson('storage/simulator/stats.json', []);

        // Exit reason counts
        $exitReasons = [];
        foreach ($closed as $trade) {
            $reason = (string)($trade['reason'] ?? 'unknown');
            $exitReasons[$reason] = ($exitReasons[$reason] ?? 0) + 1;
        }

        // ROI aggregates for closed trades
        $closedRois = array_map(fn($t) => (float)($t['roi'] ?? 0), $closed);
        $avgRoi = count($closedRois) > 0 ? array_sum($closedRois) / count($closedRois) : 0;
        sort($closedRois);
        $medianRoi = 0;
        if (count($closedRois) > 0) {
            $mid = (int)floor(count($closedRois) / 2);
            $medianRoi = count($closedRois) % 2 === 0
                ? ($closedRois[$mid - 1] + $closedRois[$mid]) / 2
                : $closedRois[$mid];
        }

        // MAE/MFE/duration averages for closed trades
        $closedMae = array_map(fn($t) => (float)($t['mae'] ?? 0), $closed);
        $closedMfe = array_map(fn($t) => (float)($t['mfe'] ?? 0), $closed);
        $closedDur = array_map(fn($t) => (float)($t['duration'] ?? 0), $closed);
        $avgMae = count($closedMae) > 0 ? array_sum($closedMae) / count($closedMae) : 0;
        $avgMfe = count($closedMfe) > 0 ? array_sum($closedMfe) / count($closedMfe) : 0;
        $avgDuration = count($closedDur) > 0 ? array_sum($closedDur) / count($closedDur) : 0;

        // Win/loss counts
        $wins = count(array_filter($closedRois, fn($r) => $r > 0));
        $losses = count($closedRois) - $wins;

        // ROI distribution buckets for chart
        $roiBuckets = ['< -5%' => 0, '-5% to -2%' => 0, '-2% to 0%' => 0, '0% to 2%' => 0, '2% to 5%' => 0, '> 5%' => 0];
        foreach ($closedRois as $roi) {
            $pct = $roi * 100;
            if ($pct < -5) $roiBuckets['< -5%']++;
            elseif ($pct < -2) $roiBuckets['-5% to -2%']++;
            elseif ($pct < 0) $roiBuckets['-2% to 0%']++;
            elseif ($pct < 2) $roiBuckets['0% to 2%']++;
            elseif ($pct < 5) $roiBuckets['2% to 5%']++;
            else $roiBuckets['> 5%']++;
        }

        // Side-based analytics
        $sideSummary = ['long' => ['count' => 0, 'wins' => 0, 'roi_sum' => 0.0], 'short' => ['count' => 0, 'wins' => 0, 'roi_sum' => 0.0]];
        foreach ($closed as $trade) {
            $side = (string)($trade['side'] ?? '');
            $roi = (float)($trade['roi'] ?? 0);
            $key = ($side === 'short') ? 'short' : 'long';  // backward compat: unknown side grouped with long for analytics
            $sideSummary[$key]['count']++;
            $sideSummary[$key]['roi_sum'] += $roi;
            if ($roi > 0) {
                $sideSummary[$key]['wins']++;
            }
        }

        return [
            'waiting' => $waiting,
            'active'  => $active,
            'closed'  => $closed,
            'stats'   => $stats,
            'exit_reasons' => $exitReasons,
            'avg_roi' => $avgRoi,
            'median_roi' => $medianRoi,
            'avg_mae' => $avgMae,
            'avg_mfe' => $avgMfe,
            'avg_duration' => $avgDuration,
            'wins' => $wins,
            'losses' => $losses,
            'roi_buckets' => $roiBuckets,
            'side_summary' => $sideSummary,
            'pattern_stats' => (array)($stats['pattern_stats'] ?? []),
            'reversal_comparison' => (array)($stats['reversal_comparison'] ?? []),
            'leverage_mode_stats' => (array)($stats['leverage_mode_stats'] ?? ['manual' => ['count' => 0, 'wins' => 0, 'roi_sum' => 0.0], 'auto' => ['count' => 0, 'wins' => 0, 'roi_sum' => 0.0]]),
            'stop_control_stats' => (array)($stats['stop_control_stats'] ?? ['manual' => ['count' => 0, 'wins' => 0, 'mae_sum' => 0.0], 'auto' => ['count' => 0, 'wins' => 0, 'mae_sum' => 0.0]]),
            // Symbol Intelligence data
            'symbol_intelligence' => $this->getSymbolIntelligenceData(),
        ];
    }

    /**
     * Get symbol intelligence data from storage.
     *
     * @return array<string,mixed>
     */
    public function getSymbolIntelligenceData(): array
    {
        $symbolIntel = new SymbolIntelligence($this->state);
        return $symbolIntel->getSymbolStatsData();
    }

    /**
     * @return array<string,mixed>
     */
    public function getPassportsData(): array
    {
        $passportsDir = $this->moduleBase . '/storage/passports';
        $passports = [];

        if (is_dir($passportsDir)) {
            $files = glob($passportsDir . '/*.json');
            if ($files) {
                foreach ($files as $file) {
                    $data = json_decode((string)file_get_contents($file), true);
                    if (is_array($data)) {
                        $passports[] = $data;
                    }
                }
            }
        }

        return [
            'passports' => $passports,
            'count' => count($passports),
        ];
    }

    /**
     * Get data for Live Performance Analyzer page.
     *
     * Reads bot execution mirror, closed trades from trading bot storage,
     * and coin passports, then runs LivePerformanceEngine for full analytics.
     *
     * @return array<string,mixed>
     */
    public function getLivePerformanceData(): array
    {
        $botMirror = $this->readBotExecutionMirror();

        // Read closed trades from trading bot storage
        $closedTrades = $this->readBotClosedTrades(200);

        // Read passports
        $passportsDir = $this->moduleBase . '/storage/passports';
        $passports = [];
        if (is_dir($passportsDir)) {
            $files = glob($passportsDir . '/*.json') ?: [];
            foreach ($files as $file) {
                $data = @json_decode((string)@file_get_contents($file), true);
                if (is_array($data) && !empty($data['symbol'])) {
                    $passports[(string)$data['symbol']] = $data;
                }
            }
        }

        require_once __DIR__ . '/live_performance_engine.php';
        $engine = new LivePerformanceEngine();
        $analytics = $engine->compute($botMirror, $closedTrades, $passports);

        return [
            'analytics' => $analytics,
            'bot_mirror_available' => (bool)($botMirror['available'] ?? false),
            'bot_mirror_error' => $botMirror['error'] ?? null,
            'closed_trades_count' => count($closedTrades),
        ];
    }

    /**
     * Read closed trade records from Trading Bot storage.
     *
     * @param int $limit Maximum number of most-recent trades to load
     * @return array<int,array<string,mixed>>
     */
    private function readBotClosedTrades(int $limit = 200): array
    {
        $botStoragePath = $this->resolveBotStoragePath();
        if ($botStoragePath === null) {
            return [];
        }

        $closedDir = $botStoragePath . '/trades/closed';
        if (!is_dir($closedDir)) {
            return [];
        }

        $files = glob($closedDir . '/*.json') ?: [];
        if (empty($files)) {
            return [];
        }

        // Sort by modification time, newest first
        usort($files, static function (string $a, string $b): int {
            return (int)filemtime($b) - (int)filemtime($a);
        });

        $trades = [];
        foreach (array_slice($files, 0, $limit) as $file) {
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }
            $trade = @json_decode($content, true);
            if (is_array($trade)) {
                $trades[] = $trade;
            }
        }

        return $trades;
    }

    /**
     * Compute sample-gated per-symbol exit hints from passport execution profiles.
     *
     * Returns bounded adjustments to trailing/stop parameters based on the symbol's
     * historical execution behavior. Only returns hints when sample size is sufficient.
     *
     * ── MAE ADAPTIVE STOP — STRICT SIDE-AWARE GATING ───────────────────
     * Fallback chain for logical stop source:
     *   1. mae_adaptive_side   — per-symbol + side (requires BOTH side_sample_size >= min_trades
     *                            AND side_winners_count >= min_winners)
     *   2. mae_adaptive_symbol — per-symbol aggregate (requires symbol sample thresholds)
     *   3. fallback_default    — insufficient data, default logical_stop_roi used
     *   4. fallback_stop_sensitivity — high stop sensitivity score triggers wider stop
     *
     * Long and short qualify independently — symbol-level sample does NOT satisfy
     * side-specific thresholds.
     *
     * Diagnostic fields (side_sample_size, side_winners_count, side_threshold_passed,
     * symbol_sample_size, symbol_threshold_passed) are always included in hint metadata
     * for threshold explainability.
     *
     * All hints are:
     * - explainable (reason provided)
     * - bounded (clamped to safe min/max)
     * - sample-size gated (minimum threshold)
     * - overrideable (applied as soft suggestions, not hard overrides)
     *
     * @param string $symbol Symbol name
     * @param array $userLimits Current user limits for reference/clamping
     * @param int $minSampleSize Minimum closed trades to produce hints
     * @param string $side Signal side ('long' or 'short') for side-aware MAE stop
     * @return array{hints: array, applied: bool, reason: string}
     */
    private function computePerSymbolHints(string $symbol, array $userLimits, int $minSampleSize = 10, string $side = ''): array
    {
        $result = ['hints' => [], 'applied' => false, 'reason' => 'no_profile'];

        // Read passport for this symbol
        $passportPath = 'storage/passports/' . $symbol . '.json';
        $passport = $this->state->readJson($passportPath, []);
        $ep = $passport['execution_profile'] ?? null;

        if (!is_array($ep) || empty($ep)) {
            return $result;
        }

        $sampleSize = (int)($ep['sample_size'] ?? 0);
        if ($sampleSize < $minSampleSize) {
            $result['reason'] = 'low_sample_size (' . $sampleSize . '/' . $minSampleSize . ')';
            return $result;
        }

        if (empty($ep['actionable'])) {
            $result['reason'] = 'profile_not_actionable';
            return $result;
        }

        $hints = [];

        // 1. MAE-based adaptive logical stop (primary recommendation)
        // Use p75 of winning trade MAE as baseline, clamped to floor/cap
        // SIDE-AWARE: prefer per-side MAE data when signal side is known
        $maeStopEnabled = (bool)($userLimits['mae_stop_enabled'] ?? true);
        $maeStopFloor = (float)($userLimits['mae_stop_floor'] ?? 0.03);
        $maeStopCap = (float)($userLimits['mae_stop_cap'] ?? 0.08);
        $maeMinTrades = (int)($userLimits['mae_stop_min_trades'] ?? 10);
        $maeMinWinners = (int)($userLimits['mae_stop_min_winners'] ?? 5);
        $maePercentile = (int)($userLimits['mae_stop_percentile'] ?? 75);
        $maeStopApplied = false;

        if ($maeStopEnabled) {
            // Side-aware MAE resolution with strict side-specific gating:
            // 1. symbol+side adaptive (if side thresholds pass)
            // 2. symbol-level adaptive (if symbol thresholds pass)
            // 3. fallback/default
            $sideNorm = ($side === 'long' || $side === 'short') ? $side : '';
            $maeProfile = null;
            $maeSource = 'none';
            // Diagnostic fields for threshold explainability
            $sideSampleSize = 0;
            $sideWinnersCount = 0;
            $sideThresholdPassed = false;
            $symbolThresholdPassed = false;

            // Try per-side MAE profile first (symbol + side) with STRICT side-specific gating
            if ($sideNorm !== '') {
                $sideProfile = $ep['by_side'][$sideNorm]['mae_stop_profile'] ?? null;
                if (is_array($sideProfile)) {
                    $sideWinnersCount = (int)($sideProfile['mae_winners_count'] ?? 0);
                    // Use side-specific sample_size for total trades threshold
                    $sideSampleSize = (int)($sideProfile['sample_size'] ?? 0);
                    // @legacy — backward compat: if sample_size not yet stored in passport,
                    // fall back to by_side trades count. Remove after all passports refreshed.
                    if ($sideSampleSize === 0) {
                        $sideSampleSize = (int)($ep['by_side'][$sideNorm]['trades'] ?? 0);
                    }
                    // STRICT: both thresholds must be side-specific
                    if ($sideSampleSize >= $maeMinTrades && $sideWinnersCount >= $maeMinWinners) {
                        $maeProfile = $sideProfile;
                        $maeSource = 'mae_adaptive_side';
                        $sideThresholdPassed = true;
                    }
                }
            }

            // Fall back to symbol-level MAE profile if side data insufficient
            if ($maeProfile === null) {
                $symbolProfile = $ep['mae_stop_profile'] ?? null;
                if (is_array($symbolProfile)) {
                    $symWinners = (int)($symbolProfile['mae_winners_count'] ?? 0);
                    if ($sampleSize >= $maeMinTrades && $symWinners >= $maeMinWinners) {
                        $maeProfile = $symbolProfile;
                        $maeSource = 'mae_adaptive_symbol';
                        $symbolThresholdPassed = true;
                    }
                }
            }

            $winnersCount = (int)($maeProfile['mae_winners_count'] ?? 0);
            // Effective sample size depends on source: side-level or symbol-level
            $effectiveSampleSize = ($maeSource === 'mae_adaptive_side') ? $sideSampleSize : $sampleSize;

            if ($maeProfile !== null) {
                // Use the configured percentile (p75 by default, p80 also available)
                $maeBaseline = ($maePercentile >= 80)
                    ? (float)($maeProfile['mae_winners_p80'] ?? $maeProfile['mae_winners_p75'] ?? 0)
                    : (float)($maeProfile['mae_winners_p75'] ?? 0);

                if ($maeBaseline > 0) {
                    // Clamp to floor/cap
                    $suggestedStop = max($maeStopFloor, min($maeStopCap, $maeBaseline));
                    $currentStop = (float)($userLimits['logical_stop_roi'] ?? 0.03);

                    $hints['suggested_logical_stop_roi'] = round($suggestedStop, 4);
                    $hints['logical_stop_source'] = $maeSource;
                    $hints['logical_stop_side'] = $sideNorm ?: 'any';
                    $hints['logical_stop_reason'] = 'p' . $maePercentile . '_mae_winners='
                        . number_format($maeBaseline, 4)
                        . ' clamped=[' . number_format($maeStopFloor, 2) . ',' . number_format($maeStopCap, 2) . ']'
                        . ' winners=' . $winnersCount
                        . ' sample=' . $effectiveSampleSize
                        . ' source=' . $maeSource;
                    $hints['mae_baseline_raw'] = round($maeBaseline, 4);
                    $hints['mae_winners_count'] = $winnersCount;
                    $hints['mae_winners_median'] = (float)($maeProfile['mae_winners_median'] ?? 0);
                    $hints['mae_winners_p75'] = (float)($maeProfile['mae_winners_p75'] ?? 0);
                    $hints['mae_winners_p80'] = (float)($maeProfile['mae_winners_p80'] ?? 0);
                    $hints['mae_selected_percentile'] = $maePercentile;
                    $hints['mae_hard_cap_used'] = ($maeBaseline > $maeStopCap);
                    $hints['mae_floor_used'] = ($maeBaseline < $maeStopFloor);
                    $hints['mae_fallback_used'] = false;
                    // Diagnostic: threshold details
                    $hints['side_sample_size'] = $sideSampleSize;
                    $hints['side_winners_count'] = $sideWinnersCount;
                    $hints['side_threshold_passed'] = $sideThresholdPassed;
                    $hints['symbol_sample_size'] = $sampleSize;
                    $hints['symbol_threshold_passed'] = $symbolThresholdPassed;
                    $maeStopApplied = true;
                }
            }

            if (!$maeStopApplied) {
                // Insufficient sample: fall back to default
                $hints['mae_fallback_used'] = true;
                $hints['logical_stop_source'] = 'fallback_default';
                $hints['mae_fallback_reason'] = 'insufficient_sample'
                    . ($sideNorm !== '' ? ' side=' . $sideNorm . ' (side_trades=' . $sideSampleSize . ', side_winners=' . $sideWinnersCount . ')' : '')
                    . ' symbol_trades=' . $sampleSize
                    . ' need=' . $maeMinTrades . '/' . $maeMinWinners;
                // Diagnostic: threshold details even on fallback
                $hints['side_sample_size'] = $sideSampleSize;
                $hints['side_winners_count'] = $sideWinnersCount;
                $hints['side_threshold_passed'] = $sideThresholdPassed;
                $hints['symbol_sample_size'] = $sampleSize;
                $hints['symbol_threshold_passed'] = $symbolThresholdPassed;
            }
        }

        // 2. Stop sensitivity adjustment (fallback when MAE stop not applied)
        $stopSens = (float)($ep['stop_sensitivity_score'] ?? 0);
        if (!$maeStopApplied && $stopSens > 0.6) {
            // High stop sensitivity: symbol is noisy, suggest slightly wider logical stop
            $currentStop = (float)($userLimits['logical_stop_roi'] ?? 0.03);
            $suggestedStop = min(0.06, $currentStop * 1.2); // Max 20% wider, capped at 6%
            if ($suggestedStop > $currentStop) {
                $hints['suggested_logical_stop_roi'] = round($suggestedStop, 4);
                $hints['logical_stop_reason'] = 'high_stop_sensitivity (' . number_format($stopSens, 2) . ')';
            }
        }

        // 3. Trailing friendliness adjustment
        $trailScore = (float)($ep['trailing_friendliness_score'] ?? 0);
        $trailActivationRate = (float)($ep['trailing_behavior']['trailing_activation_rate'] ?? 0);

        if ($trailScore < 0.3 && $trailActivationRate < 0.2) {
            // Symbol rarely benefits from trailing: suggest fixed TP instead
            $hints['suggested_exit_mode'] = 'fixed_tp';
            $hints['exit_mode_reason'] = 'low_trailing_friendliness (' . number_format($trailScore, 2) . '), trail_act_rate=' . number_format($trailActivationRate, 2);
        } elseif ($trailScore > 0.7 && $trailActivationRate > 0.5) {
            // Symbol trails well: suggest slightly lower trailing activation for earlier engagement
            $currentActivation = (float)($userLimits['trailing_activation_roi'] ?? 0.05);
            $suggestedActivation = max(0.02, $currentActivation * 0.85); // Max 15% lower, floor at 2%
            if ($suggestedActivation < $currentActivation) {
                $hints['suggested_trailing_activation_roi'] = round($suggestedActivation, 4);
                $hints['trailing_activation_reason'] = 'high_trailing_friendliness (' . number_format($trailScore, 2) . ')';
            }
        }

        // 4. Break-even adjustment
        $beApplyRate = (float)($ep['break_even_behavior']['break_even_apply_rate'] ?? 0);
        if ($beApplyRate > 0.6) {
            // BE frequently applies: suggest slightly lower BE activation
            $currentBE = (float)($userLimits['break_even_activation_roi'] ?? 0.025);
            $suggestedBE = max(0.01, $currentBE * 0.85); // Max 15% lower, floor at 1%
            if ($suggestedBE < $currentBE) {
                $hints['suggested_break_even_activation_roi'] = round($suggestedBE, 4);
                $hints['be_activation_reason'] = 'high_be_apply_rate (' . number_format($beApplyRate, 2) . ')';
            }
        }

        // 5. Hybrid TP share adjustment based on exit distribution
        $exitDist = $ep['exit_reason_distribution'] ?? [];
        $trailCloseCount = (int)($ep['trailing_behavior']['trailing_close_count'] ?? 0);
        $stopHitCount = (int)($ep['stop_behavior']['stop_hit_count'] ?? 0);
        if ($sampleSize >= 20 && $trailCloseCount > 0 && $stopHitCount > 0) {
            $trailToStopRatio = $trailCloseCount / max(1, $stopHitCount);
            if ($trailToStopRatio > 2.0) {
                // Trailing closes way more than stops: suggest more trailing share
                $currentHybrid = (float)($userLimits['hybrid_tp_share'] ?? 0.40);
                $suggestedHybrid = min(0.60, max(0.20, $currentHybrid - 0.10)); // Shift 10% more to trailing
                $hints['suggested_hybrid_tp_share'] = round($suggestedHybrid, 2);
                $hints['hybrid_reason'] = 'trail_to_stop_ratio=' . number_format($trailToStopRatio, 2);
            }
        }

        if (!empty($hints)) {
            $result['hints'] = $hints;
            $result['applied'] = true;
            $result['reason'] = 'profile_based_hints (sample=' . $sampleSize . ')';
        } else {
            $result['reason'] = 'no_adjustments_needed';
        }

        return $result;
    }
}
