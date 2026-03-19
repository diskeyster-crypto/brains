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
            'live_debug_preview' => $liveIntentResult['live_debug_preview'],
            'effective_execution_limits' => [
                'live_max_positions' => (int)($liveConfig['live_max_positions'] ?? 3),
                'live_one_trade_per_symbol' => (bool)($liveConfig['live_one_trade_per_symbol'] ?? true),
            ],
            'effective_trailing_contract' => $liveConfig['trailing_contract'] ?? [],
        ];

        $this->state->writeJson('storage/last_run.json', $result);
        $this->logger->log('info', 'Smart Brain cycle finished: source=' . $source . ' signals=' . count($signals) . ' duration=' . $durationMs . 'ms');

        return $result;
    }

    // =========================================================================
    // Live Intent Generation — Brain-controlled live bot refactor
    // =========================================================================

    /** Runtime signature for live-stage code version verification */
    private const LIVE_STAGE_VERSION = 'live_stage_v2_audit_2026-03-19';

    /**
     * Generate Brain-approved live intents from signals.
     * Applies live_signal_selection_mode filter and writes storage/live_intents.json.
     *
     * Every signal must end as approved or rejected — no silent skip.
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
            'live_debug_preview' => [],
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
        $signalsList = $signals['signals'] ?? [];
        if (!is_array($signalsList)) {
            $signalsList = $signals;
        }

        $result['signals_seen'] = count($signalsList);

        foreach ($signalsList as $signal) {
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

            // === APPROVED: build bot-ready live intent ===
            $result['approved_count']++;

            $sideOriginal = $side;
            if ($reverseEnabled && ($side === 'long' || $side === 'short')) {
                $side = ($side === 'long') ? 'short' : 'long';
            }

            $trailing = $liveConfig['trailing_contract'] ?? [];

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
                'risk' => $risk,
                'trailing' => $trailing,
                'stop_policy' => [
                    'stop_control_mode' => (string)($signal['stop_control_mode'] ?? ($trailing['stop_control_mode'] ?? 'auto')),
                    'manual_stop_loss_roi' => (float)($signal['manual_stop_loss_roi'] ?? ($trailing['manual_stop_loss_roi'] ?? 0.03)),
                ],
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
            return $risk;
        }

        // Fallback: build risk block from flat signal fields
        $leverage = $signal['leverage'] ?? null;
        $budget = $signal['budget'] ?? null;
        if ($leverage === null && $budget === null) {
            return [];
        }

        $normalized = [
            'leverage' => (int)($leverage ?? 1),
            'budget' => (float)($budget ?? 0),
            'stop_loss' => (float)($signal['stop_loss'] ?? 0),
            'take_profit' => (float)($signal['take_profit'] ?? 0),
        ];

        // Build trailing sub-block from flat exit policy fields
        $trailingEnabled = (bool)($signal['trailing_enabled'] ?? false);
        $normalized['trailing'] = [
            'enabled' => $trailingEnabled,
            'activation_roi_pct' => (float)($signal['trailing_activation_roi'] ?? 0.02),
            'min_lock_roi' => (float)($signal['trailing_min_lock_roi'] ?? 0.005),
            'min_step' => (float)($signal['trailing_min_step'] ?? 0.005),
        ];

        return $normalized;
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
}
