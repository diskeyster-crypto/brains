<?php
declare(strict_types=1);

require_once __DIR__ . '/smart_brain_config.php';
require_once __DIR__ . '/smart_brain_logger.php';
require_once __DIR__ . '/state_manager.php';
require_once __DIR__ . '/parser4_analyzer.php';
require_once __DIR__ . '/corridor_monitor.php';
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

        // Enrich V2/V3 candidates with policy fields for storage consistency
        // (confirmation_tier, entry_action, zone_widen_profile, v2_priority_score)
        foreach ($candidates as &$c) {
            $patternAlgo = (string)($c['pattern_algorithm'] ?? '');
            if ($patternAlgo === 'double_bottom_contextual_v2' || $patternAlgo === 'double_bottom_contextual_v3'
                || $patternAlgo === 'double_top_contextual_v2' || $patternAlgo === 'double_top_contextual_v3') {
                $policyFields = CorridorMonitor::computeV2PolicyFields($c, $userLimits);
                $c['confirmation_tier'] = $policyFields['confirmation_tier'];
                $c['entry_action'] = $policyFields['entry_action'];
                $c['zone_widen_profile'] = $policyFields['zone_widen_profile'];
                $c['v2_priority_score'] = $policyFields['v2_priority_score'];
            }
        }
        unset($c);

        $this->state->writeJson('storage/candidates.json', $candidates);

        // Shadow mirror pass — produces double_bottom_contextual_v2/v3 observations for
        // trend_reversal_soft_ladder_short exit-side harvest logic, even when long trading
        // is disabled and those patterns are absent from the main enabled-pattern list.
        // Output is written to storage/reversal_shadow_mirror.json ONLY — it is NOT fed
        // into the monitors → risk-engine → signals → live-intents pipeline.
        if (($userLimits['trailing_step_mode'] ?? '') === 'trend_reversal_soft_ladder_short') {
            $shadowMirrorPatterns = ['double_bottom_contextual_v2', 'double_bottom_contextual_v3'];
            $enabledNow = (array)($parser4Cfg['pattern_algorithms']['enabled'] ?? []);
            $missingMirrorPatterns = array_diff($shadowMirrorPatterns, $enabledNow);
            if (!empty($missingMirrorPatterns)) {
                // Run a dedicated shadow parser with only the mirror patterns enabled.
                // shadow_mode=true prevents writes to candidates.json / v2_stage_counters.json
                // / downstream_counters.json so the main Brain pipeline is not polluted.
                $shadowParser4Cfg = $parser4Cfg;
                $shadowParser4Cfg['pattern_algorithms']['enabled'] = array_values($shadowMirrorPatterns);
                $shadowParser4Cfg['pattern_algorithms']['mode']    = 'any';
                $shadowParser4Cfg['shadow_mode']                   = true;
                try {
                    $shadowParser = new Parser4Analyzer($shadowParser4Cfg, $this->state);
                    $shadowRaw    = $shadowParser->run();
                    $shadowMirrorCandidates = [];
                    foreach ($shadowRaw as $sc) {
                        $algo = (string)($sc['pattern_algorithm'] ?? '');
                        if (in_array($algo, $shadowMirrorPatterns, true)) {
                            $sc['shadow_mirror_only'] = true;
                            $shadowMirrorCandidates[] = $sc;
                        }
                    }
                } catch (\Throwable $shadowEx) {
                    $shadowMirrorCandidates = [];
                }
            } else {
                // Mirror patterns already detected in the main pass — extract them.
                $shadowMirrorCandidates = [];
                foreach ($candidates as $c) {
                    $algo = (string)($c['pattern_algorithm'] ?? '');
                    if (in_array($algo, $shadowMirrorPatterns, true)) {
                        $shadowMirrorCandidates[] = $c;
                    }
                }
            }
            $this->state->writeJson('storage/reversal_shadow_mirror.json', $shadowMirrorCandidates);
        }

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
        // Merge V2 tier widening config into corridor config so CorridorMonitor can use it
        $v2TierKeys = ['v2_confirmation_weak_max', 'v2_confirmation_strong_min',
            'v2_zone_widen_weak_pct', 'v2_zone_widen_medium_pct', 'v2_zone_widen_strong_pct', 'v2_zone_widen_max_cap_pct'];
        foreach ($v2TierKeys as $key) {
            if (isset($userLimits[$key])) {
                $corridorCfg[$key] = $userLimits[$key];
            }
        }
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

        // Passport computation is handled by the standalone coin_passport module.
        // Smart Brain no longer maintains its own passport storage.
        $botStatsLoaded = false;
        $botStatsParseOk = false;
        $botStatsSymbolsCount = 0;
        $botStatsSourcePath = null;
        $passportEnrichResult = [
            'passports_updated_count' => 0,
            'passports_with_mae_profile_count' => 0,
            'passport_mae_symbols_preview' => [],
        ];

        $risk = new RiskEngine($riskCfg, $profilesCfg, $this->state);
        $signals = $risk->apply($monitors, $prices, $userLimits);
        $this->state->writeJson('storage/signals.json', $signals);

        // Collect rejection counters and debug lines (Phase B)
        $rejectionCounters = $risk->getRejectionCounters();
        $debugLines = $risk->getDebugLines();
        $signalModeCounters = $risk->getSignalModeCounters();
        $perPatternRejections = $risk->getPerPatternRejections();
        $failedMonitorPreview = $risk->getFailedMonitorPreview();

        // Write debug signal log (Phase B, Part 3)
        $this->logger->writeDebugLog($debugLines);

        // ================================================================
        // Live Intent Generation — Brain-controlled live bot refactor
        // Brain applies live_signal_selection_mode to filter signals
        // and produces live_intents.json for the Trading Bot executor.
        // ================================================================
        $liveConfig = $this->config->buildLiveConfig();
        $liveIntentResult = $this->generateLiveIntents($signals, $liveConfig, $userLimits, $prices);

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

        // V2 Downstream Funnel: per-pattern monitor status distribution
        $v2DownstreamFunnel = [];
        $contextualPatterns = ['double_bottom_contextual_v2', 'double_bottom_contextual_v3', 'double_top_contextual_v2', 'double_top_contextual_v3'];
        foreach ($monitors as $m) {
            $algo = (string)($m['pattern_algorithm'] ?? '');
            if (!in_array($algo, $contextualPatterns, true)) {
                continue;
            }
            if (!isset($v2DownstreamFunnel[$algo])) {
                $v2DownstreamFunnel[$algo] = [
                    'candidates_count' => 0,
                    'monitors_count' => 0,
                    'entry_zone_count' => 0,
                    'monitoring_count' => 0,
                    'invalidated_count' => 0,
                    'signals_count' => 0,
                    'enter_now_count' => 0,
                    'wait_retrace_count' => 0,
                    'enter_now_promoted_count' => 0,
                    'short_enter_now_candidates_count' => 0,
                    'short_enter_now_signal_emitted_count' => 0,
                    'short_enter_now_monitor_bypassed_count' => 0,
                    'long_enter_now_candidates_count' => 0,
                    'long_enter_now_signal_emitted_count' => 0,
                    'long_enter_now_monitor_bypassed_count' => 0,
                    'avg_zone_width_pct' => 0.0,
                    'avg_zone_distance' => 0.0,
                    'avg_price_position' => 0.0,
                    'avg_confirmation_score' => 0.0,
                    'whatif_enter_now_would_signal' => 0,
                    'whatif_wider_zone_would_signal' => 0,
                    'reject_detail_distribution' => [],
                    'zone_widths' => [],
                    'zone_distances' => [],
                    'price_positions' => [],
                    'confirmation_scores' => [],
                    'component_scores' => [
                        'reclaim_strength' => [],
                        'hold_quality' => [],
                        'post_reclaim_stability' => [],
                        'zone_defense' => [],
                    ],
                ];
            }
            $v2DownstreamFunnel[$algo]['monitors_count']++;
            $st = (string)($m['status'] ?? '');
            match ($st) {
                'entry_zone' => $v2DownstreamFunnel[$algo]['entry_zone_count']++,
                'monitoring' => $v2DownstreamFunnel[$algo]['monitoring_count']++,
                'invalidated' => $v2DownstreamFunnel[$algo]['invalidated_count']++,
                default => null,
            };

            // Track entry_action policy and enter_now promotions
            $mEntryAction = (string)($m['entry_action'] ?? 'wait_retrace');
            if ($mEntryAction === 'enter_now') {
                $v2DownstreamFunnel[$algo]['enter_now_count']++;
            } else {
                $v2DownstreamFunnel[$algo]['wait_retrace_count']++;
            }
            if (!empty($m['enter_now_promoted'])) {
                $v2DownstreamFunnel[$algo]['enter_now_promoted_count']++;
            }

            // Track short enter_now candidates and monitor bypass
            $mSide = strtolower(trim((string)($m['side'] ?? '')));
            if ($mSide === 'short' && $mEntryAction === 'enter_now') {
                $v2DownstreamFunnel[$algo]['short_enter_now_candidates_count']++;
                if (!empty($m['enter_now_promoted'])) {
                    $v2DownstreamFunnel[$algo]['short_enter_now_monitor_bypassed_count']++;
                }
            }
            // Track long enter_now candidates and monitor bypass (symmetric)
            if ($mSide === 'long' && $mEntryAction === 'enter_now') {
                $v2DownstreamFunnel[$algo]['long_enter_now_candidates_count']++;
                if (!empty($m['enter_now_promoted'])) {
                    $v2DownstreamFunnel[$algo]['long_enter_now_monitor_bypassed_count']++;
                }
            }

            $v2DownstreamFunnel[$algo]['zone_widths'][] = (float)($m['zone_width_pct'] ?? 0);
            $v2DownstreamFunnel[$algo]['zone_distances'][] = (float)($m['zone_distance_from_price'] ?? 0);
            $v2DownstreamFunnel[$algo]['price_positions'][] = (float)($m['price_position'] ?? 0);
            $v2DownstreamFunnel[$algo]['confirmation_scores'][] = (float)($m['confirmation_score'] ?? 0);

            // Collect component scores for analytics
            $v2DownstreamFunnel[$algo]['component_scores']['reclaim_strength'][] = (float)($m['reclaim_strength_score'] ?? 0);
            $v2DownstreamFunnel[$algo]['component_scores']['hold_quality'][] = (float)($m['hold_quality_score'] ?? 0);
            $v2DownstreamFunnel[$algo]['component_scores']['post_reclaim_stability'][] = (float)($m['post_reclaim_stability_score'] ?? 0);
            $v2DownstreamFunnel[$algo]['component_scores']['zone_defense'][] = (float)($m['zone_defense_score'] ?? 0);

            // What-if counters
            if ($st !== 'entry_zone') {
                $whatifEnterNow = (string)($m['whatif_enter_now_status'] ?? '');
                $whatifWiderZone = (string)($m['whatif_wider_zone_status'] ?? '');
                if ($whatifEnterNow === 'entry_zone') {
                    $v2DownstreamFunnel[$algo]['whatif_enter_now_would_signal']++;
                }
                if ($whatifWiderZone === 'entry_zone') {
                    $v2DownstreamFunnel[$algo]['whatif_wider_zone_would_signal']++;
                }
            }

            // Reject detail distribution
            $rejectDetail = (string)($m['reject_detail'] ?? 'none');
            if ($rejectDetail !== 'none') {
                $v2DownstreamFunnel[$algo]['reject_detail_distribution'][$rejectDetail] =
                    ($v2DownstreamFunnel[$algo]['reject_detail_distribution'][$rejectDetail] ?? 0) + 1;
            }
        }

        // Count candidates and signals per contextual pattern
        // Also track policy field propagation sanity
        $candidatePolicyFieldsMissingCount = 0;
        $monitorPolicyFieldsMissingCount = 0;
        foreach ($candidates as $c) {
            $algo = (string)($c['pattern_algorithm'] ?? '');
            if (isset($v2DownstreamFunnel[$algo])) {
                $v2DownstreamFunnel[$algo]['candidates_count']++;
            }
            // Policy field propagation sanity for V2/V3 candidates
            if ($algo === 'double_bottom_contextual_v2' || $algo === 'double_bottom_contextual_v3'
                || $algo === 'double_top_contextual_v2' || $algo === 'double_top_contextual_v3') {
                foreach (['confirmation_tier', 'entry_action', 'zone_widen_profile', 'v2_priority_score'] as $pf) {
                    if (!array_key_exists($pf, $c) || $c[$pf] === null) {
                        $candidatePolicyFieldsMissingCount++;
                        break;
                    }
                }
            }
        }
        foreach ($monitors as $m2) {
            $algo2 = (string)($m2['pattern_algorithm'] ?? '');
            if ($algo2 === 'double_bottom_contextual_v2' || $algo2 === 'double_bottom_contextual_v3'
                || $algo2 === 'double_top_contextual_v2' || $algo2 === 'double_top_contextual_v3') {
                foreach (['confirmation_tier', 'entry_action', 'zone_widen_profile', 'v2_priority_score'] as $pf) {
                    if (!array_key_exists($pf, $m2) || $m2[$pf] === null) {
                        $monitorPolicyFieldsMissingCount++;
                        break;
                    }
                }
            }
        }
        $finalSignalConfScoreMissing = 0;
        $finalSignalConfScoreNull = 0;
        $signalTierDistribution = ['weak' => 0, 'medium' => 0, 'strong' => 0, 'none' => 0];
        foreach ($signals as $s) {
            $algo = (string)($s['pattern_algorithm'] ?? '');
            if (isset($v2DownstreamFunnel[$algo])) {
                $v2DownstreamFunnel[$algo]['signals_count']++;
                // Per-tier signal counts
                $tier = (string)($s['confirmation_tier'] ?? 'none');
                if (!isset($v2DownstreamFunnel[$algo]['signals_by_tier'])) {
                    $v2DownstreamFunnel[$algo]['signals_by_tier'] = ['weak' => 0, 'medium' => 0, 'strong' => 0, 'none' => 0];
                }
                $v2DownstreamFunnel[$algo]['signals_by_tier'][$tier] =
                    ($v2DownstreamFunnel[$algo]['signals_by_tier'][$tier] ?? 0) + 1;

                // Track short enter_now signal emission
                $sSide = strtolower(trim((string)($s['side'] ?? '')));
                $sEntryAction = (string)($s['entry_action'] ?? 'wait_retrace');
                if ($sSide === 'short' && $sEntryAction === 'enter_now') {
                    $v2DownstreamFunnel[$algo]['short_enter_now_signal_emitted_count']++;
                }
                // Track long enter_now signal emission (symmetric)
                if ($sSide === 'long' && $sEntryAction === 'enter_now') {
                    $v2DownstreamFunnel[$algo]['long_enter_now_signal_emitted_count']++;
                }
            }
            // Track global tier distribution for V2 signals
            if (in_array($algo, $contextualPatterns, true)) {
                $tier = (string)($s['confirmation_tier'] ?? 'none');
                $signalTierDistribution[$tier] = ($signalTierDistribution[$tier] ?? 0) + 1;
            }
            // Sanity: track V2 signals missing confirmation_score in final payload
            if (in_array($algo, $contextualPatterns, true)) {
                if (!array_key_exists('confirmation_score', $s)) {
                    $finalSignalConfScoreMissing++;
                } elseif ($s['confirmation_score'] === null) {
                    $finalSignalConfScoreNull++;
                }
            }
        }

        // Compute averages and clean up temp arrays
        foreach ($v2DownstreamFunnel as $algo => &$funnel) {
            $widths = $funnel['zone_widths'];
            $distances = $funnel['zone_distances'];
            $positions = $funnel['price_positions'];
            $confScores = $funnel['confirmation_scores'];
            $funnel['avg_zone_width_pct'] = count($widths) > 0 ? round(array_sum($widths) / count($widths), 6) : 0.0;
            $funnel['avg_zone_distance'] = count($distances) > 0 ? round(array_sum($distances) / count($distances), 6) : 0.0;
            $funnel['avg_price_position'] = count($positions) > 0 ? round(array_sum($positions) / count($positions), 4) : 0.0;
            $funnel['avg_confirmation_score'] = count($confScores) > 0 ? round(array_sum($confScores) / count($confScores), 4) : 0.0;

            // Confirmation score distribution analytics
            $nonZeroScores = array_filter($confScores, static fn($s) => $s > 0.0);
            $funnel['confirmation_score_min'] = count($nonZeroScores) > 0 ? round(min($nonZeroScores), 4) : 0.0;
            $funnel['confirmation_score_max'] = count($nonZeroScores) > 0 ? round(max($nonZeroScores), 4) : 0.0;
            // Bucket counts: weak (0.0-0.39), medium (0.40-0.59), strong (0.60-0.79), very_strong (0.80-1.0)
            $buckets = ['weak' => 0, 'medium' => 0, 'strong' => 0, 'very_strong' => 0];
            foreach ($nonZeroScores as $cs) {
                if ($cs >= 0.80) {
                    $buckets['very_strong']++;
                } elseif ($cs >= 0.60) {
                    $buckets['strong']++;
                } elseif ($cs >= 0.40) {
                    $buckets['medium']++;
                } else {
                    $buckets['weak']++;
                }
            }
            $funnel['confirmation_score_buckets'] = $buckets;

            // Sanity counters: detect zero-score regression for confirmed V2 patterns
            $confScoreZeroOnConfirmed = 0;
            foreach ($confScores as $cs) {
                if ($cs <= 0.0) {
                    $confScoreZeroOnConfirmed++;
                }
            }
            $funnel['confirmation_score_zero_on_confirmed_count'] = $confScoreZeroOnConfirmed;
            $funnel['confirmation_score_total_monitors'] = count($confScores);

            // Flat-score warning: all non-zero scores identical
            $uniqueNonZero = array_unique(array_map(static fn($s) => round($s, 4), $nonZeroScores));
            $funnel['confirmation_score_flat_warning'] = (count($uniqueNonZero) === 1 && count($nonZeroScores) > 1);

            // Component score averages
            $componentAverages = [];
            foreach ($funnel['component_scores'] as $compName => $compValues) {
                $nonZeroComp = array_filter($compValues, static fn($v) => $v > 0.0);
                $componentAverages['avg_' . $compName . '_score'] = count($nonZeroComp) > 0
                    ? round(array_sum($nonZeroComp) / count($nonZeroComp), 4)
                    : 0.0;
            }
            $funnel['component_score_averages'] = $componentAverages;

            unset($funnel['zone_widths'], $funnel['zone_distances'], $funnel['price_positions'], $funnel['confirmation_scores'], $funnel['component_scores']);

            // Add per-pattern rejection reasons
            $funnel['rejection_reasons'] = $perPatternRejections[$algo] ?? [];
        }
        unset($funnel);

        // Build what-if analysis summary for V2 (includes tier-based scenarios)
        $v2WhatIfAnalysis = $this->computeV2WhatIfAnalysis($v2DownstreamFunnel);

        // Build V3 debug preview: compact summary of V3 signals for sniper observability
        $v3DebugPreview = [];
        foreach ($signals as $s) {
            $algo = (string)($s['pattern_algorithm'] ?? '');
            if ($algo === 'double_bottom_contextual_v3' && count($v3DebugPreview) < 10) {
                $v3DebugPreview[] = [
                    'symbol' => (string)($s['symbol'] ?? ''),
                    'confirmation_score' => round((float)($s['confirmation_score'] ?? 0.0), 4),
                    'confirmation_tier' => (string)($s['confirmation_tier'] ?? 'none'),
                    'reclaim_strength_score' => round((float)($s['reclaim_strength_score'] ?? 0.0), 4),
                    'hold_quality_score' => round((float)($s['hold_quality_score'] ?? 0.0), 4),
                    'post_reclaim_stability_score' => round((float)($s['post_reclaim_stability_score'] ?? 0.0), 4),
                    'zone_defense_score' => round((float)($s['zone_defense_score'] ?? 0.0), 4),
                    'entry_action' => (string)($s['entry_action'] ?? 'wait_retrace'),
                    'price_position' => round((float)($s['price_position'] ?? 0.0), 4),
                    'entry_zone_low' => $s['entry_zone_low'] ?? null,
                    'entry_zone_high' => $s['entry_zone_high'] ?? null,
                    'pattern_confidence' => round((float)($s['pattern_confidence'] ?? 0.0), 4),
                    'v2_priority_score' => round((float)($s['v2_priority_score'] ?? 0.0), 4),
                ];
            }
        }

        // Also build V3 debug preview from candidates (for cases where no signals pass)
        $v3CandidatePreview = [];
        foreach ($candidates as $c) {
            $algo = (string)($c['pattern_algorithm'] ?? '');
            if ($algo === 'double_bottom_contextual_v3' && count($v3CandidatePreview) < 10) {
                $v3CandidatePreview[] = [
                    'symbol' => (string)($c['symbol'] ?? ''),
                    'confirmation_score' => round((float)($c['confirmation_score'] ?? 0.0), 4),
                    'confirmation_tier' => (string)($c['confirmation_tier'] ?? 'none'),
                    'reclaim_strength_score' => round((float)($c['reclaim_strength_score'] ?? 0.0), 4),
                    'hold_quality_score' => round((float)($c['hold_quality_score'] ?? 0.0), 4),
                    'post_reclaim_stability_score' => round((float)($c['post_reclaim_stability_score'] ?? 0.0), 4),
                    'zone_defense_score' => round((float)($c['zone_defense_score'] ?? 0.0), 4),
                    'entry_action' => (string)($c['entry_action'] ?? 'wait_retrace'),
                    'pattern_confidence' => round((float)($c['pattern_confidence'] ?? 0.0), 4),
                ];
            }
        }

        // Build V3 downstream entry zone diagnostics from monitors (both long V3 and short V3)
        $v3ContextualPatterns = ['double_bottom_contextual_v3', 'double_top_contextual_v3'];
        $v3DownstreamDiag = [
            'v3_candidates_count' => 0,
            'v3_monitors_count' => 0,
            'v3_entry_zone_count' => 0,
            'v3_signals_count' => 0,
            'v3_rejected_not_entry_zone_count' => 0,
            'v3_reject_zone_too_far_count' => 0,
            'v3_wait_retrace_count' => 0,
            'v3_enter_now_count' => 0,
            'v3_reject_detail_distribution' => [],
            'v3_monitor_preview' => [],
        ];
        foreach ($candidates as $c) {
            if (in_array((string)($c['pattern_algorithm'] ?? ''), $v3ContextualPatterns, true)) {
                $v3DownstreamDiag['v3_candidates_count']++;
            }
        }
        foreach ($monitors as $m) {
            if (!in_array((string)($m['pattern_algorithm'] ?? ''), $v3ContextualPatterns, true)) {
                continue;
            }
            $v3DownstreamDiag['v3_monitors_count']++;
            $mst = (string)($m['status'] ?? '');
            if ($mst === 'entry_zone') {
                $v3DownstreamDiag['v3_entry_zone_count']++;
            } else {
                $v3DownstreamDiag['v3_rejected_not_entry_zone_count']++;
            }
            $mReject = (string)($m['reject_detail'] ?? 'none');
            if ($mReject === 'reject_zone_too_far') {
                $v3DownstreamDiag['v3_reject_zone_too_far_count']++;
            }
            if ($mReject !== 'none') {
                $v3DownstreamDiag['v3_reject_detail_distribution'][$mReject] =
                    ($v3DownstreamDiag['v3_reject_detail_distribution'][$mReject] ?? 0) + 1;
            }
            $mEntryAction = (string)($m['entry_action'] ?? 'wait_retrace');
            if ($mEntryAction === 'enter_now') {
                $v3DownstreamDiag['v3_enter_now_count']++;
            } else {
                $v3DownstreamDiag['v3_wait_retrace_count']++;
            }
            // Monitor preview (first 10)
            if (count($v3DownstreamDiag['v3_monitor_preview']) < 10) {
                $v3DownstreamDiag['v3_monitor_preview'][] = [
                    'symbol' => (string)($m['symbol'] ?? ''),
                    'status' => $mst,
                    'entry_action' => $mEntryAction,
                    'confirmation_score' => round((float)($m['confirmation_score'] ?? 0.0), 4),
                    'confirmation_tier' => (string)($m['confirmation_tier'] ?? 'none'),
                    'price_position' => round((float)($m['price_position'] ?? 0.0), 4),
                    'entry_zone_percent' => round((float)($m['entry_zone_percent'] ?? 0.0), 4),
                    'zone_width_pct' => round((float)($m['zone_width_pct'] ?? 0.0), 6),
                    'zone_distance_from_price' => round((float)($m['zone_distance_from_price'] ?? 0.0), 6),
                    'reject_detail' => $mReject,
                    'whatif_enter_now_status' => (string)($m['whatif_enter_now_status'] ?? ''),
                    'whatif_wider_zone_status' => (string)($m['whatif_wider_zone_status'] ?? ''),
                    'pattern_confidence' => round((float)($m['pattern_confidence'] ?? 0.0), 4),
                ];
            }
        }
        foreach ($signals as $s) {
            if (in_array((string)($s['pattern_algorithm'] ?? ''), $v3ContextualPatterns, true)) {
                $v3DownstreamDiag['v3_signals_count']++;
            }
        }

        // Compute side-specific candidate/signal totals for long-path observability
        $longCandidatesCount = 0;
        $shortCandidatesCount = 0;
        foreach ($candidates as $c) {
            $cSide = strtolower(trim((string)($c['side'] ?? '')));
            if ($cSide === 'long') {
                $longCandidatesCount++;
            } elseif ($cSide === 'short') {
                $shortCandidatesCount++;
            }
        }
        $longSignalsCount = 0;
        $shortSignalsCount = 0;
        foreach ($signals as $s) {
            $sSide2 = strtolower(trim((string)($s['side'] ?? '')));
            if ($sSide2 === 'long') {
                $longSignalsCount++;
            } elseif ($sSide2 === 'short') {
                $shortSignalsCount++;
            }
        }

        // Persist V2 downstream funnel
        $this->state->writeJson('storage/v2_downstream_funnel.json', [
            'by_pattern' => $v2DownstreamFunnel,
            'failed_monitor_preview' => $failedMonitorPreview,
            'whatif_analysis' => $v2WhatIfAnalysis,
            'signal_tier_distribution' => $signalTierDistribution,
            'v3_debug_preview' => $v3DebugPreview,
            'v3_candidate_preview' => $v3CandidatePreview,
            'v3_downstream_diagnostics' => $v3DownstreamDiag,
            'final_signal_confirmation_score_missing_count' => $finalSignalConfScoreMissing,
            'final_signal_confirmation_score_null_count' => $finalSignalConfScoreNull,
            'candidate_policy_fields_missing_count' => $candidatePolicyFieldsMissingCount,
            'monitor_policy_fields_missing_count' => $monitorPolicyFieldsMissingCount,
            // Sniper V3 live filter diagnostics
            'sniper_v3_live_eligible_count' => (int)($liveIntentResult['sniper_v3_live_eligible_count'] ?? 0),
            'sniper_v3_live_rejected_count' => (int)($liveIntentResult['sniper_v3_live_rejected_count'] ?? 0),
            'sniper_v3_shadow_only_count' => (int)($liveIntentResult['sniper_v3_shadow_only_count'] ?? 0),
            'sniper_v3_reject_reason_distribution' => $liveIntentResult['sniper_v3_reject_reason_distribution'] ?? [],
            'sniper_v3_rejected_preview' => $liveIntentResult['sniper_v3_rejected_preview'] ?? [],
            // V2 live quality floor diagnostics
            'v2_live_quality_floor_applied_count' => (int)($liveIntentResult['v2_live_quality_floor_applied_count'] ?? 0),
            'v2_live_quality_floor_rejected_count' => (int)($liveIntentResult['v2_live_quality_floor_rejected_count'] ?? 0),
            'v2_live_quality_floor_passed_count' => (int)($liveIntentResult['v2_live_quality_floor_passed_count'] ?? 0),
            'v2_live_quality_floor_reject_reason_distribution' => $liveIntentResult['v2_live_quality_floor_reject_reason_distribution'] ?? [],
            'v2_live_quality_floor_rejected_preview' => $liveIntentResult['v2_live_quality_floor_rejected_preview'] ?? [],
            // Short enter_now live diagnostics
            'short_enter_now_live_applied_count' => (int)($liveIntentResult['short_enter_now_live_applied_count'] ?? 0),
            'short_enter_now_live_approved_count' => (int)($liveIntentResult['short_enter_now_live_approved_count'] ?? 0),
            'short_enter_now_live_rejected_count' => (int)($liveIntentResult['short_enter_now_live_rejected_count'] ?? 0),
            'short_enter_now_live_reject_reasons' => $liveIntentResult['short_enter_now_live_reject_reasons'] ?? [],
            'short_enter_now_live_borderline_pass_count' => (int)($liveIntentResult['short_enter_now_live_borderline_pass_count'] ?? 0),
            // Long enter_now live diagnostics (symmetric)
            'long_enter_now_live_applied_count' => (int)($liveIntentResult['long_enter_now_live_applied_count'] ?? 0),
            'long_enter_now_live_approved_count' => (int)($liveIntentResult['long_enter_now_live_approved_count'] ?? 0),
            'long_enter_now_live_rejected_count' => (int)($liveIntentResult['long_enter_now_live_rejected_count'] ?? 0),
            // Long-path funnel observability counters
            'long_quality_floor_reject_total' => (int)($liveIntentResult['long_quality_floor_reject_total'] ?? 0),
            'long_sniper_v3_live_rejected_count' => (int)($liveIntentResult['long_sniper_v3_live_rejected_count'] ?? 0),
            'long_passport_gate_reject_total' => (int)($liveIntentResult['long_passport_gate_reject_total'] ?? 0),
            'long_cycle_veto_total' => (int)($liveIntentResult['long_cycle_veto_total'] ?? 0),
            // Manual blacklist diagnostics
            'manual_blacklist_active' => (bool)($liveIntentResult['manual_blacklist_active'] ?? false),
            'manual_blacklist_count' => (int)($liveIntentResult['manual_blacklist_count'] ?? 0),
            'manual_blacklist_rejected_count' => (int)($liveIntentResult['manual_blacklist_rejected_count'] ?? 0),
            'manual_blacklist_rejected_preview' => $liveIntentResult['manual_blacklist_rejected_preview'] ?? [],
            'updated_at' => date('c'),
        ]);

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
            'rejected_price_below_zone' => $rejectionCounters['rejected_price_below_zone'] ?? 0,
            // Stable Config Refactor — bootstrap / normal signal counts
            'bootstrap_signals_count' => $signalModeCounters['bootstrap_signals_count'] ?? 0,
            'warmup_symbols_count' => $signalModeCounters['warmup_symbols_count'] ?? 0,
            'normal_signals_count' => $signalModeCounters['normal_signals_count'] ?? 0,
            // V2 downstream funnel
            'v2_downstream_funnel' => $v2DownstreamFunnel,
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
            // Config Module migration proof — first-wave soft-switch runtime evidence
            'config_source_proof' => (static function (array $ms): array {
                $migratedCount = $ms['migrated_count'] ?? count($ms['switched_params'] ?? []);
                $fallbackCount = $ms['fallback_count'] ?? count($ms['fallback_params'] ?? []);
                return [
                    'migration_wave'           => $ms['switch_wave']                ?? 'v1_operational_params',
                    'partially_migrated'       => (bool)($ms['partially_migrated']  ?? ($migratedCount > 0 && $fallbackCount > 0)),
                    'unified_config_available' => (bool)($ms['unified_config_available'] ?? false),
                    'unified_config_used'      => $migratedCount > 0,
                    'legacy_fallback_used'     => $fallbackCount > 0,
                    'migrated_count'           => $migratedCount,
                    'fallback_count'           => $fallbackCount,
                    'first_wave_total'         => $ms['first_wave_total'] ?? ($migratedCount + $fallbackCount),
                    'switched_params'          => $ms['switched_params']  ?? [],
                    'fallback_params'          => $ms['fallback_params']  ?? [],
                    'source'                   => $ms['source']           ?? 'legacy_user_config',
                    'recorded_at'              => $ms['recorded_at']      ?? null,
                ];
            })($this->config->getMigrationStatus()),
            // Manual Symbol Universe
            'manual_symbol_universe_enabled' => $manualUniverseEnabled,
            'manual_symbol_mode' => $manualSymbolMode,
            'manual_symbol_count' => $manualSymbolCount,
            // Config Conflict Guard
            'config_conflict_detected' => $configConflictDetected,
            'config_conflict_message' => $configConflictMessage,
            // Execution Profile
            'execution_profile' => (string)($userLimits['execution_profile'] ?? 'custom'),
            'execution_profile_label' => self::getExecutionProfileLabel($userLimits),
            'pattern_profile_mode' => (string)($userLimits['pattern_profile_mode'] ?? 'manual_override'),
            'pattern_policy' => self::resolvePatternPolicy($userLimits, $this->config),
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
            // Long-path observability counters
            'long_candidates_count' => $longCandidatesCount,
            'short_candidates_count' => $shortCandidatesCount,
            'long_signals_count' => $longSignalsCount,
            'short_signals_count' => $shortSignalsCount,
            'long_live_candidates_approved_count' => (int)($liveIntentResult['long_approved_count'] ?? 0),
            'long_live_intents_created_count' => (int)($liveIntentResult['long_intents_created_count'] ?? 0),
            // Long-path funnel observability counters
            'long_quality_floor_reject_total' => (int)($liveIntentResult['long_quality_floor_reject_total'] ?? 0),
            'long_sniper_v3_live_rejected_count' => (int)($liveIntentResult['long_sniper_v3_live_rejected_count'] ?? 0),
            'long_passport_gate_reject_total' => (int)($liveIntentResult['long_passport_gate_reject_total'] ?? 0),
            'long_cycle_veto_total' => (int)($liveIntentResult['long_cycle_veto_total'] ?? 0),
            'live_intents_total_after_merge' => $liveIntentResult['intents_total_after_merge'] ?? 0,
            'live_terminal_retained_count' => $liveIntentResult['terminal_retained_count'] ?? 0,
            'lifecycle_counters' => $liveIntentResult['lifecycle_counters'] ?? [],
            'lifecycle_summary' => $liveIntentResult['lifecycle_summary'] ?? [],
            'intent_ttl_minutes' => $liveIntentResult['intent_ttl_minutes'] ?? 5,
            'live_invalid_payload_count' => $liveIntentResult['live_invalid_payload_count'],
            'live_missing_risk_count' => $liveIntentResult['live_missing_risk_count'],
            'live_missing_entry_count' => $liveIntentResult['live_missing_entry_count'],
            'live_mode_filter_rejected_count' => $liveIntentResult['live_mode_filter_rejected_count'],
            // Live mode filter observability (req. WU-5 §7)
            'live_mode_filter_used' => (bool)($liveIntentResult['live_mode_filter_used'] ?? true),
            'live_mode_filter_mode' => (string)($liveIntentResult['live_mode_filter_mode'] ?? 'unknown'),
            'live_invalid_risk_contract_count' => $liveIntentResult['live_invalid_risk_contract_count'],
            'late_entry_rejected_count' => $liveIntentResult['late_entry_rejected_count'] ?? 0,
            'late_entry_rejected_distribution' => $liveIntentResult['late_entry_rejected_distribution'] ?? [],
            'late_entry_rejected_preview' => $liveIntentResult['late_entry_rejected_preview'] ?? [],
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
            // Sniper V3 Live Filter diagnostics
            'sniper_v3_live_eligible_count' => (int)($liveIntentResult['sniper_v3_live_eligible_count'] ?? 0),
            'sniper_v3_live_rejected_count' => (int)($liveIntentResult['sniper_v3_live_rejected_count'] ?? 0),
            'sniper_v3_shadow_only_count' => (int)($liveIntentResult['sniper_v3_shadow_only_count'] ?? 0),
            'sniper_v3_reject_reason_distribution' => $liveIntentResult['sniper_v3_reject_reason_distribution'] ?? [],
            'sniper_v3_rejected_preview' => $liveIntentResult['sniper_v3_rejected_preview'] ?? [],
            // V2 Live Quality Floor diagnostics
            'v2_live_quality_floor_applied_count' => (int)($liveIntentResult['v2_live_quality_floor_applied_count'] ?? 0),
            'v2_live_quality_floor_rejected_count' => (int)($liveIntentResult['v2_live_quality_floor_rejected_count'] ?? 0),
            'v2_live_quality_floor_passed_count' => (int)($liveIntentResult['v2_live_quality_floor_passed_count'] ?? 0),
            'v2_live_quality_floor_reject_reason_distribution' => $liveIntentResult['v2_live_quality_floor_reject_reason_distribution'] ?? [],
            // Manual Blacklist diagnostics
            'manual_blacklist_active' => (bool)($liveIntentResult['manual_blacklist_active'] ?? false),
            'manual_blacklist_count' => (int)($liveIntentResult['manual_blacklist_count'] ?? 0),
            'manual_blacklist_rejected_count' => (int)($liveIntentResult['manual_blacklist_rejected_count'] ?? 0),
            'manual_blacklist_rejected_preview' => $liveIntentResult['manual_blacklist_rejected_preview'] ?? [],
            // Coin Passport live gate diagnostics
            'passport_gate_applied_count' => (int)($liveIntentResult['passport_gate_applied_count'] ?? 0),
            'passport_gate_passed_count' => (int)($liveIntentResult['passport_gate_passed_count'] ?? 0),
            'passport_gate_rejected_count' => (int)($liveIntentResult['passport_gate_rejected_count'] ?? 0),
            'passport_gate_allow_live_count' => (int)($liveIntentResult['passport_gate_allow_live_count'] ?? 0),
            'passport_gate_bootstrap_live_count' => (int)($liveIntentResult['passport_gate_bootstrap_live_count'] ?? 0),
            'passport_gate_sim_only_count' => (int)($liveIntentResult['passport_gate_sim_only_count'] ?? 0),
            'passport_gate_shadow_only_count' => (int)($liveIntentResult['passport_gate_shadow_only_count'] ?? 0),
            'passport_gate_reject_count' => (int)($liveIntentResult['passport_gate_reject_count'] ?? 0),
            'passport_gate_demoted_to_sim_count' => (int)($liveIntentResult['passport_gate_demoted_to_sim_count'] ?? 0),
            'passport_gate_no_passport_count' => (int)($liveIntentResult['passport_gate_no_passport_count'] ?? 0),
            'passport_gate_low_confidence_count' => (int)($liveIntentResult['passport_gate_low_confidence_count'] ?? 0),
            'passport_gate_strict_block_count' => (int)($liveIntentResult['passport_gate_strict_block_count'] ?? 0),
            'passport_gate_signal_blocked_by_passport_count' => (int)($liveIntentResult['passport_gate_signal_blocked_by_passport_count'] ?? 0),
            'passport_gate_reject_reason_distribution' => $liveIntentResult['passport_gate_reject_reason_distribution'] ?? [],
            'passport_gate_rejected_preview' => $liveIntentResult['passport_gate_rejected_preview'] ?? [],
            // Coin cycle decision debug observability counters (read-only, does not affect routing)
            'cycle_debug_available_total' => (int)($liveIntentResult['cycle_debug_available_total'] ?? 0),
            'cycle_debug_missing_total' => (int)($liveIntentResult['cycle_debug_missing_total'] ?? 0),
            'cycle_debug_low_confidence_total' => (int)($liveIntentResult['cycle_debug_low_confidence_total'] ?? 0),
            // Coin cycle model veto/demotion layer counters (Coin Core Step 11)
            'cycle_model_veto_total' => (int)($liveIntentResult['cycle_model_veto_total'] ?? 0),
            'cycle_model_demote_demo_total' => (int)($liveIntentResult['cycle_model_demote_demo_total'] ?? 0),
            'cycle_model_demote_skip_total' => (int)($liveIntentResult['cycle_model_demote_skip_total'] ?? 0),
            'cycle_model_no_effect_total' => (int)($liveIntentResult['cycle_model_no_effect_total'] ?? 0),
            'cycle_model_unavailable_total' => (int)($liveIntentResult['cycle_model_unavailable_total'] ?? 0),
            // Coin cycle positive support layer counters (Coin Core Step 12)
            'cycle_model_support_total'           => (int)($liveIntentResult['cycle_model_support_total'] ?? 0),
            'cycle_model_support_live_total'      => (int)($liveIntentResult['cycle_model_support_live_total'] ?? 0),
            'cycle_model_support_borderline_total' => (int)($liveIntentResult['cycle_model_support_borderline_total'] ?? 0),
            'cycle_model_support_no_effect_total'  => (int)($liveIntentResult['cycle_model_support_no_effect_total'] ?? 0),
            // Coin cycle positive support layer per-symbol proof preview (Coin Core Step 12)
            'cycle_model_support_preview'         => $liveIntentResult['cycle_model_support_preview'] ?? [],
            // Coin cycle eligibility refinement counters (Coin Core Step 13)
            'cycle_eligibility_refine_total'      => (int)($liveIntentResult['cycle_eligibility_refine_total']      ?? 0),
            'cycle_eligibility_upgrade_total'     => (int)($liveIntentResult['cycle_eligibility_upgrade_total']     ?? 0),
            'cycle_eligibility_downgrade_total'   => (int)($liveIntentResult['cycle_eligibility_downgrade_total']   ?? 0),
            'cycle_eligibility_no_effect_total'   => (int)($liveIntentResult['cycle_eligibility_no_effect_total']   ?? 0),
            'cycle_eligibility_unavailable_total' => (int)($liveIntentResult['cycle_eligibility_unavailable_total'] ?? 0),
            // Slot Priority Layer diagnostics (time-aware candidate ranking)
            'slot_priority_used'                  => (bool)($liveIntentResult['slot_priority_used']                  ?? false),
            'slot_priority_candidates_total'      => (int)($liveIntentResult['slot_priority_candidates_total']       ?? 0),
            'slot_priority_won_total'             => (int)($liveIntentResult['slot_priority_won_total']              ?? 0),
            'slot_priority_lost_total'            => (int)($liveIntentResult['slot_priority_lost_total']             ?? 0),
            'slot_priority_not_needed_total'      => (int)($liveIntentResult['slot_priority_not_needed_total']       ?? 0),
            'slot_priority_open_positions_count'  => (int)($liveIntentResult['slot_priority_open_positions_count']   ?? 0),
            'slot_priority_existing_active_slots' => (int)($liveIntentResult['slot_priority_existing_active_slots']  ?? 0),
            'slot_priority_slots_available'       => (int)($liveIntentResult['slot_priority_slots_available']        ?? 0),
            'slot_priority_rejected_preview'      => $liveIntentResult['slot_priority_rejected_preview']             ?? [],
            // Entry Quality Filter diagnostics (bounded entry-quality improvement layer)
            'entry_quality_filter_total'           => (int)($liveIntentResult['entry_quality_filter_total']           ?? 0),
            'entry_quality_filter_reject_total'    => (int)($liveIntentResult['entry_quality_filter_reject_total']    ?? 0),
            'entry_quality_filter_demo_total'      => (int)($liveIntentResult['entry_quality_filter_demo_total']      ?? 0),
            'entry_quality_filter_no_effect_total' => (int)($liveIntentResult['entry_quality_filter_no_effect_total'] ?? 0),
            'entry_quality_filter_rejected_preview' => $liveIntentResult['entry_quality_filter_rejected_preview']     ?? [],
            // Per-rule entry quality filter counters
            'entry_filter_weak_stale_reject_total'     => (int)($liveIntentResult['entry_filter_weak_stale_reject_total']     ?? 0),
            'entry_filter_weak_structure_reject_total' => (int)($liveIntentResult['entry_filter_weak_structure_reject_total'] ?? 0),
            'entry_filter_weak_structure_demo_total'   => (int)($liveIntentResult['entry_filter_weak_structure_demo_total']   ?? 0),
            'entry_filter_late_pressure_reject_total'  => (int)($liveIntentResult['entry_filter_late_pressure_reject_total']  ?? 0),
            'entry_filter_weak_tail_reject_total'      => (int)($liveIntentResult['entry_filter_weak_tail_reject_total']      ?? 0),
            // Wave Filter diagnostics (bounded wave amplitude/speed improvement layer)
            'wave_filter_total'                => (int)($liveIntentResult['wave_filter_total']                ?? 0),
            'wave_filter_reject_total'         => (int)($liveIntentResult['wave_filter_reject_total']         ?? 0),
            'wave_filter_demo_total'           => (int)($liveIntentResult['wave_filter_demo_total']           ?? 0),
            'wave_filter_no_effect_total'      => (int)($liveIntentResult['wave_filter_no_effect_total']      ?? 0),
            'wave_filter_rejected_preview'     => $liveIntentResult['wave_filter_rejected_preview']           ?? [],
            'wave_filter_release_valve_used'   => (bool)($liveIntentResult['wave_filter_release_valve_used']  ?? false),
            // V2 Cleanup filter diagnostics (V2-specific weak+slow quality tightening)
            'v2_cleanup_total'                 => (int)($liveIntentResult['v2_cleanup_total']                 ?? 0),
            'v2_cleanup_reject_total'          => (int)($liveIntentResult['v2_cleanup_reject_total']          ?? 0),
            'v2_cleanup_demo_total'            => (int)($liveIntentResult['v2_cleanup_demo_total']            ?? 0),
            'v2_cleanup_no_effect_total'       => (int)($liveIntentResult['v2_cleanup_no_effect_total']       ?? 0),
            // Confirmation Layer diagnostics (post-pattern wait window for targeted patterns)
            'confirmation_total'               => (int)($liveIntentResult['confirmation_total']               ?? 0),
            'confirmation_confirmed_total'     => (int)($liveIntentResult['confirmation_confirmed_total']     ?? 0),
            'confirmation_demo_total'          => (int)($liveIntentResult['confirmation_demo_total']          ?? 0),
            'confirmation_reject_total'        => (int)($liveIntentResult['confirmation_reject_total']        ?? 0),
            'confirmation_fakeout_total'       => (int)($liveIntentResult['confirmation_fakeout_total']       ?? 0),
            'confirmation_expired_total'       => (int)($liveIntentResult['confirmation_expired_total']       ?? 0),
            'confirmation_state_preview'       => $liveIntentResult['confirmation_state_preview']             ?? [],
            // Side-separated V2 confirmation counters
            'short_v2_confirmation_total'           => (int)($liveIntentResult['short_v2_confirmation_total']           ?? 0),
            'short_v2_confirmation_confirmed_total'  => (int)($liveIntentResult['short_v2_confirmation_confirmed_total']  ?? 0),
            'short_v2_confirmation_demo_total'       => (int)($liveIntentResult['short_v2_confirmation_demo_total']       ?? 0),
            'short_v2_confirmation_reject_total'     => (int)($liveIntentResult['short_v2_confirmation_reject_total']     ?? 0),
            'short_v2_confirmation_fakeout_total'    => (int)($liveIntentResult['short_v2_confirmation_fakeout_total']    ?? 0),
            'short_v2_confirmation_expired_total'    => (int)($liveIntentResult['short_v2_confirmation_expired_total']    ?? 0),
            'long_v2_confirmation_total'            => (int)($liveIntentResult['long_v2_confirmation_total']            ?? 0),
            'long_v2_confirmation_confirmed_total'   => (int)($liveIntentResult['long_v2_confirmation_confirmed_total']   ?? 0),
            'long_v2_confirmation_demo_total'        => (int)($liveIntentResult['long_v2_confirmation_demo_total']        ?? 0),
            'long_v2_confirmation_reject_total'      => (int)($liveIntentResult['long_v2_confirmation_reject_total']      ?? 0),
            'long_v2_confirmation_fakeout_total'     => (int)($liveIntentResult['long_v2_confirmation_fakeout_total']     ?? 0),
            'long_v2_confirmation_expired_total'     => (int)($liveIntentResult['long_v2_confirmation_expired_total']     ?? 0),
            // Post-confirm quality gate diagnostics (weak+normal long V2 only)
            'post_confirm_quality_gate_total'           => (int)($liveIntentResult['post_confirm_quality_gate_total']           ?? 0),
            'post_confirm_quality_gate_applied'         => (int)($liveIntentResult['post_confirm_quality_gate_applied']         ?? 0),
            'post_confirm_quality_gate_used'            => (bool)($liveIntentResult['post_confirm_quality_gate_used']           ?? false),
            'post_confirm_quality_gate_live_pass_total' => (int)($liveIntentResult['post_confirm_quality_gate_live_pass_total'] ?? 0),
            'post_confirm_quality_gate_demo_total'      => (int)($liveIntentResult['post_confirm_quality_gate_demo_total']      ?? 0),
            'post_confirm_quality_gate_reject_total'    => (int)($liveIntentResult['post_confirm_quality_gate_reject_total']    ?? 0),
            'post_confirm_quality_gate_no_effect_total' => (int)($liveIntentResult['post_confirm_quality_gate_no_effect_total'] ?? 0),
            // Wave Penalty layer diagnostics (ranking penalty for weak/slow wave candidates)
            'wave_penalty_total'               => (int)($liveIntentResult['wave_penalty_total']               ?? 0),
            'wave_penalty_applied_total'       => (int)($liveIntentResult['wave_penalty_applied_total']       ?? 0),
            'wave_penalty_no_effect_total'     => (int)($liveIntentResult['wave_penalty_no_effect_total']     ?? 0),
            // Ranking Boost layer diagnostics (bounded positive boost for strong/fresh/clean candidates)
            'ranking_boost_total'              => (int)($liveIntentResult['ranking_boost_total']              ?? 0),
            'ranking_boost_applied_total'      => (int)($liveIntentResult['ranking_boost_applied_total']      ?? 0),
            'ranking_boost_no_effect_total'    => (int)($liveIntentResult['ranking_boost_no_effect_total']    ?? 0),
            // Win Universe bonus layer diagnostics (soft priority bonus for qualified win-pool symbols)
            'win_universe_bonus_total'                 => (int)($liveIntentResult['win_universe_bonus_total']                  ?? 0),
            'win_universe_bonus_applied_total'         => (int)($liveIntentResult['win_universe_bonus_applied_total']          ?? 0),
            'win_universe_bonus_no_effect_total'       => (int)($liveIntentResult['win_universe_bonus_no_effect_total']        ?? 0),
            'win_universe_bonus_ranking_changed_total' => (int)($liveIntentResult['win_universe_bonus_ranking_changed_total']  ?? 0),
            'win_universe_mode'                        => (string)($liveIntentResult['win_universe_mode']                     ?? 'shadow'),
            'win_universe_mode_source'                 => (string)($liveIntentResult['win_universe_mode_source']              ?? 'config_defaults'),
            'win_universe_mode_sync_ok'                => (bool)($liveIntentResult['win_universe_mode_sync_ok']               ?? true),
            'win_universe_last_run_mode'               => $liveIntentResult['win_universe_last_run_mode']                     ?? null,
            'win_universe_pool_size'                   => (int)($liveIntentResult['win_universe_pool_size']                   ?? 0),
            'win_universe_bonus_preview'               => $liveIntentResult['win_universe_bonus_preview']                     ?? [],
            // Leverage chain config proof — shows every cap layer so operators can diagnose silent crushing
            'leverage_chain_config' => (static function (
                array $userLimits,
                array $profilesCfg
            ): array {
                $profileKey = (string)($profilesCfg['default_profile'] ?? '111');
                $profile = (array)($profilesCfg['profiles'][$profileKey] ?? []);
                $leverageMode   = (string)($userLimits['leverage_mode'] ?? 'auto');
                $requestedManual = (int)($userLimits['manual_leverage'] ?? 3);
                $requestedMax    = (int)($userLimits['max_leverage'] ?? 15);
                $bootstrapMax    = (int)($userLimits['bootstrap_max_leverage'] ?? 3);
                $profileMax      = (int)($profile['max_leverage'] ?? 5);
                // Effective cap = whichever layer is most restrictive for the active mode
                if ($leverageMode === 'manual') {
                    $effectiveCap = $requestedMax;
                    $effectiveCapLabel = 'max_leverage';
                } else {
                    $effectiveCap = min($profileMax, $requestedMax);
                    $effectiveCapLabel = ($profileMax <= $requestedMax) ? 'profile_max' : 'max_leverage';
                }
                // risk_engine_cap: highest leverage the dynamic algorithm can return in normal mode
                // (profile_max × 1, before volatility/corridor/reliability adjustments)
                $riskEngineCap = ($leverageMode === 'manual') ? $requestedMax : $effectiveCap;
                return [
                    'leverage_mode'          => $leverageMode,
                    'requested_manual'       => $requestedManual,
                    'requested_max'          => $requestedMax,
                    'bootstrap_max'          => $bootstrapMax,
                    'profile_max'            => $profileMax,
                    'risk_engine_cap'        => $riskEngineCap,
                    'effective_cap'          => $effectiveCap,
                    'effective_cap_label'    => $effectiveCapLabel,
                    'manual_would_be_crushed' => ($leverageMode === 'manual' && $requestedManual > $requestedMax),
                    'auto_crushed_by_profile' => ($leverageMode !== 'manual' && $profileMax < $requestedMax),
                ];
            })($userLimits, $profilesCfg),
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
    private function generateLiveIntents(array $signals, array $liveConfig, array $userLimits, array $prices = []): array
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
            // Live mode filter observability (req. WU-5 §7)
            'live_mode_filter_used' => true,  // filter is always evaluated when live trading is enabled
            'live_mode_filter_mode' => 'unknown', // updated below once selectionMode is resolved
            'live_invalid_risk_contract_count' => 0,
            'live_debug_preview' => [],
            // MAE adaptive stop runtime proof counters
            'mae_stop_hints_available_count' => 0,
            'mae_stop_hints_applied_count' => 0,
            'mae_stop_hints_fallback_count' => 0,
            'mae_stop_debug_preview' => [],
            // Sniper V3 live filter diagnostics
            'sniper_v3_live_eligible_count' => 0,
            'sniper_v3_live_rejected_count' => 0,
            'sniper_v3_shadow_only_count' => 0,
            'sniper_v3_reject_reason_distribution' => [],
            'sniper_v3_rejected_preview' => [],
            'structural_v3_signal_count' => 0,
            // V2 live quality floor diagnostics
            'v2_live_quality_floor_applied_count' => 0,
            'v2_live_quality_floor_rejected_count' => 0,
            'v2_live_quality_floor_passed_count' => 0,
            'v2_live_quality_floor_reject_reason_distribution' => [],
            'v2_live_quality_floor_rejected_preview' => [],
            // Short enter_now live diagnostics
            'short_enter_now_live_applied_count' => 0,
            'short_enter_now_live_approved_count' => 0,
            'short_enter_now_live_rejected_count' => 0,
            'short_enter_now_live_reject_reasons' => [],
            'short_enter_now_live_borderline_pass_count' => 0,
            // Long enter_now live diagnostics (symmetric)
            'long_enter_now_live_applied_count' => 0,
            'long_enter_now_live_approved_count' => 0,
            'long_enter_now_live_rejected_count' => 0,
            // Long-path intent counters
            'long_approved_count' => 0,
            'long_intents_created_count' => 0,
            // Long-path funnel rejection counters (per problem-statement requirement)
            'long_quality_floor_reject_total' => 0,
            'long_sniper_v3_live_rejected_count' => 0,
            'long_passport_gate_reject_total' => 0,
            'long_cycle_veto_total' => 0,
            // Manual blacklist diagnostics
            'manual_blacklist_active' => false,
            'manual_blacklist_count' => 0,
            'manual_blacklist_rejected_count' => 0,
            'manual_blacklist_rejected_preview' => [],
            // Coin Passport live gate diagnostics
            'passport_gate_applied_count' => 0,
            'passport_gate_passed_count' => 0,
            'passport_gate_rejected_count' => 0,
            'passport_gate_allow_live_count' => 0,
            'passport_gate_bootstrap_live_count' => 0,
            'passport_gate_sim_only_count' => 0,
            'passport_gate_shadow_only_count' => 0,
            'passport_gate_reject_count' => 0,
            'passport_gate_demoted_to_sim_count' => 0,
            'passport_gate_no_passport_count' => 0,
            'passport_gate_low_confidence_count' => 0,
            'passport_gate_strict_block_count' => 0,
            'passport_gate_signal_blocked_by_passport_count' => 0,
            'passport_gate_reject_reason_distribution' => [],
            'passport_gate_rejected_preview' => [],
            // Coin cycle decision debug observability (read-only, does not affect routing)
            'cycle_debug_available_total' => 0,
            'cycle_debug_missing_total' => 0,
            'cycle_debug_low_confidence_total' => 0,
            // Coin cycle model veto/demotion layer (Coin Core Step 11)
            'cycle_model_veto_total' => 0,
            'cycle_model_demote_demo_total' => 0,
            'cycle_model_demote_skip_total' => 0,
            'cycle_model_no_effect_total' => 0,
            'cycle_model_unavailable_total' => 0,
            // Coin cycle positive support layer counters (Coin Core Step 12)
            'cycle_model_support_total'           => 0,
            'cycle_model_support_live_total'      => 0,
            'cycle_model_support_borderline_total' => 0,
            'cycle_model_support_no_effect_total'  => 0,
            // Coin cycle positive support layer per-symbol proof preview (Coin Core Step 12)
            'cycle_model_support_preview'         => [],
            // Coin cycle eligibility refinement counters (Coin Core Step 13)
            'cycle_eligibility_refine_total'      => 0,
            'cycle_eligibility_upgrade_total'     => 0,
            'cycle_eligibility_downgrade_total'   => 0,
            'cycle_eligibility_no_effect_total'   => 0,
            'cycle_eligibility_unavailable_total' => 0,
            // Intent lifecycle diagnostics
            'lifecycle_counters' => [],
            'lifecycle_summary' => [],
            'intent_ttl_minutes' => SmartBrainConfig::LIVE_INTENT_TTL_MINUTES,
            // Slot priority layer diagnostics (time-aware candidate ranking)
            'slot_priority_used'                  => false,
            'slot_priority_candidates_total'      => 0,
            'slot_priority_won_total'             => 0,
            'slot_priority_lost_total'            => 0,
            'slot_priority_not_needed_total'      => 0,
            'slot_priority_open_positions_count'  => 0,
            'slot_priority_existing_active_slots' => 0,
            'slot_priority_slots_available'       => 0,
            'slot_priority_rejected_preview'      => [],
            // Entry quality filter diagnostics (bounded entry-quality improvement layer)
            'entry_quality_filter_total'          => 0,
            'entry_quality_filter_reject_total'   => 0,
            'entry_quality_filter_demo_total'     => 0,
            'entry_quality_filter_no_effect_total' => 0,
            'entry_quality_filter_rejected_preview' => [],
            // Per-rule entry quality filter counters
            'entry_filter_weak_stale_reject_total'     => 0,
            'entry_filter_weak_structure_reject_total' => 0,
            'entry_filter_weak_structure_demo_total'   => 0,
            'entry_filter_late_pressure_reject_total'  => 0,
            'entry_filter_weak_tail_reject_total'      => 0,
            // Wave filter diagnostics (bounded wave amplitude/speed improvement layer)
            'wave_filter_total'                => 0,
            'wave_filter_reject_total'         => 0,
            'wave_filter_demo_total'           => 0,
            'wave_filter_no_effect_total'      => 0,
            'wave_filter_rejected_preview'     => [],
            'wave_filter_release_valve_used'   => false,
            // V2 Cleanup filter diagnostics (V2-specific weak+slow quality tightening)
            'v2_cleanup_total'                 => 0,
            'v2_cleanup_reject_total'          => 0,
            'v2_cleanup_demo_total'            => 0,
            'v2_cleanup_no_effect_total'       => 0,
            // Confirmation Layer diagnostics (post-pattern wait window for targeted patterns)
            'confirmation_total'               => 0,
            'confirmation_confirmed_total'     => 0,
            'confirmation_demo_total'          => 0,
            'confirmation_reject_total'        => 0,
            'confirmation_fakeout_total'       => 0,
            'confirmation_expired_total'       => 0,
            // Confirmation Layer observability: preview of all pending entries and their current state.
            // Includes setup_detected, waiting, confirmed, fakeout, expired events this cycle.
            'confirmation_state_preview'       => [],
            // Side-separated V2 confirmation counters (short V2 = double_top_contextual_v2, long V2 = double_bottom_contextual_v2)
            'short_v2_confirmation_total'          => 0,
            'short_v2_confirmation_confirmed_total' => 0,
            'short_v2_confirmation_demo_total'      => 0,
            'short_v2_confirmation_reject_total'    => 0,
            'short_v2_confirmation_fakeout_total'   => 0,
            'short_v2_confirmation_expired_total'   => 0,
            'long_v2_confirmation_total'           => 0,
            'long_v2_confirmation_confirmed_total'  => 0,
            'long_v2_confirmation_demo_total'       => 0,
            'long_v2_confirmation_reject_total'     => 0,
            'long_v2_confirmation_fakeout_total'    => 0,
            'long_v2_confirmation_expired_total'    => 0,
            // Post-confirm quality gate diagnostics (weak+normal long V2 only)
            // Applied after confirmation_result=confirmed for double_bottom_contextual_v2 long
            // signals with wave_amplitude_state=weak and wave_speed_state=normal.
            'post_confirm_quality_gate_total'           => 0,
            'post_confirm_quality_gate_applied'         => 0,
            'post_confirm_quality_gate_used'            => false,
            'post_confirm_quality_gate_live_pass_total' => 0,
            'post_confirm_quality_gate_demo_total'      => 0,
            'post_confirm_quality_gate_reject_total'    => 0,
            'post_confirm_quality_gate_no_effect_total' => 0,
            // Wave penalty layer diagnostics (ranking penalty for weak/slow wave candidates)
            'wave_penalty_total'               => 0,
            'wave_penalty_applied_total'       => 0,
            'wave_penalty_no_effect_total'     => 0,
            // Ranking boost layer diagnostics (bounded positive boost for strong/fresh/clean candidates)
            'ranking_boost_total'              => 0,
            'ranking_boost_applied_total'      => 0,
            'ranking_boost_no_effect_total'    => 0,
            // Win Universe bonus layer diagnostics (soft priority bonus for qualified win-pool symbols)
            'win_universe_bonus_total'              => 0,
            'win_universe_bonus_applied_total'      => 0,
            'win_universe_bonus_no_effect_total'    => 0,
            'win_universe_bonus_ranking_changed_total' => 0,
            'win_universe_mode'                     => 'shadow',
            'win_universe_mode_source'              => 'config_defaults',
            'win_universe_mode_sync_ok'             => true,
            'win_universe_last_run_mode'            => null,
            'win_universe_pool_size'                => 0,
            'win_universe_bonus_preview'            => [],
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

        // Update live_mode_filter_mode now that selectionMode is resolved
        $result['live_mode_filter_mode'] = $selectionMode;

        // Load symbol intelligence lists for live selection filtering
        $whitelist = $this->loadSymbolList('whitelist.json');
        $softWhitelist = $this->loadSymbolList('soft_whitelist.json');
        $watchlist = $this->loadSymbolList('watchlist.json');
        $manualSymbols = [];
        if (!empty($userLimits['manual_symbol_universe_enabled'])) {
            $rawList = (string)($userLimits['manual_symbol_list'] ?? '');
            $manualSymbols = SymbolIntelligence::parseManualSymbolList($rawList);
        }

        // Load manual live blacklist — authoritative live-only symbol block
        $blacklistData = $this->config->loadManualBlacklist();
        $manualBlacklist = $blacklistData['symbols'];
        $result['manual_blacklist_active'] = !empty($manualBlacklist);
        $result['manual_blacklist_count'] = $blacklistData['count'];
        if (!$blacklistData['valid']) {
            $this->logger->log('warning', 'Manual blacklist: ' . $blacklistData['warning']);
        }

        // Load Coin Passport data for live eligibility gate
        // Passport gate is enabled by default; can be overridden via user config.
        $passportGateEnabled = (bool)($userLimits['passport_gate_enabled'] ?? true);
        $passportGateStrict  = (bool)($userLimits['passport_gate_strict'] ?? false);
        // Configurable strict-mode thresholds (only used when passport_gate_strict=true)
        $passportStrictMinConfidence     = (string)($userLimits['passport_gate_strict_min_confidence'] ?? 'medium');
        $passportStrictMinCorridorP75Roi = (float)($userLimits['passport_gate_strict_min_corridor_p75_roi'] ?? 3.0);
        $passportStrictMinRunnerProb     = (float)($userLimits['passport_gate_strict_min_runner_probability'] ?? 0.05);
        $passportStrictMaxNoiseScore     = (float)($userLimits['passport_gate_strict_max_noise_score'] ?? 0.65);
        $passportStrictMinPatternSuccess = (float)($userLimits['passport_gate_strict_min_pattern_success_rate'] ?? 0.35);
        $passports = [];
        $passportsDir = dirname($this->moduleBase) . '/coin_passport/storage/passports';

        // Refresh cycle decision model into passport files before loading them (best-effort,
        // non-fatal). This ensures coin_cycle_decision_model is present in passport files even
        // if the coin_passport cron has not yet run since the passports were last rebuilt.
        // Read-only observability only — does NOT affect routing decisions.
        if ($passportGateEnabled && is_dir($passportsDir)) {
            try {
                $cpLibFile = dirname($this->moduleBase) . '/coin_passport/lib/passport_engine.php';
                if (is_file($cpLibFile)) {
                    if (!class_exists('CoinPassportEngine', false)) {
                        require_once $cpLibFile;
                    }
                    $cpEng = new CoinPassportEngine(
                        $passportsDir,
                        dirname($this->moduleBase) . '/trading_bot/storage',
                        dirname($this->moduleBase) . '/ai_shadow/storage'
                    );
                    $cpRuntimeDir = dirname($this->moduleBase) . '/coin_passport/storage/runtime';
                    @mkdir($cpRuntimeDir, 0755, true);
                    $cpEng->projectCycleDecisionModelToPassports(
                        $cpRuntimeDir . '/coin_cycle_decision_model_projection.json'
                    );
                    // Step 13: Apply cycle-aware eligibility refinement immediately after the
                    // decision model is projected so Smart Brain reads already-refined passports.
                    // Best-effort, non-fatal — failure leaves recommended_live_eligibility unchanged.
                    try {
                        $cpEng->applyCycleEligibilityRefinement(
                            $cpRuntimeDir . '/coin_cycle_eligibility_refinement.json'
                        );
                    } catch (\Throwable $refineEx) {
                        // non-fatal
                    }
                }
            } catch (\Throwable $e) {
                // non-fatal — passports load without cycle decision model
            }
        }

        // Passports are loaded unconditionally when the directory exists.
        // The debug block below reads them regardless of whether the passport gate
        // is enforcing routing decisions. The gate enforcement block (further below)
        // is still controlled by $passportGateEnabled.
        if (is_dir($passportsDir)) {
            foreach (glob($passportsDir . '/*.json') ?: [] as $pFile) {
                $raw = @file_get_contents($pFile);
                $pData = $raw !== false ? @json_decode($raw, true) : null;
                if (is_array($pData) && !empty($pData['symbol'])) {
                    $passports[strtoupper((string)$pData['symbol'])] = $pData;
                } elseif ($raw !== false) {
                    $this->logger->log('warning', 'Coin Passport: failed to decode passport file: ' . basename($pFile));
                }
            }
        }

        // ── Win Universe pool pre-load (best-effort, non-fatal) ───────────────
        // Reads win_universe_pool.json to support the Win Universe Bonus Layer below.
        // Mode + bonus strength come from win_universe/storage/runtime/win_universe_user_config.json
        // with fallback to win_universe/config/config.php defaults.
        $wuWinPool           = [];
        $wuMode              = 'shadow';
        $wuBonusEnabled      = false;
        $wuBonusStrength     = 0.5;
        $wuPoolSize          = 0;
        $wuMaxQualifiedRatio = 0.30; // safety cap: ignore bonus when >30% of seen qualifies
        $wuModeSource        = 'config_defaults';
        $wuLastRunMode       = null;
        try {
            $wuModuleBase = dirname($this->moduleBase) . '/win_universe';
            // Load pool
            $wuPoolPath = $wuModuleBase . '/storage/runtime/win_universe_pool.json';
            if (is_file($wuPoolPath)) {
                $wuPoolRaw = @file_get_contents($wuPoolPath);
                if ($wuPoolRaw !== false && $wuPoolRaw !== '') {
                    $wuPoolData = @json_decode($wuPoolRaw, true);
                    if (is_array($wuPoolData) && isset($wuPoolData['win_pool']) && is_array($wuPoolData['win_pool'])) {
                        foreach (array_keys($wuPoolData['win_pool']) as $wuSym) {
                            $wuWinPool[strtoupper((string)$wuSym)] = true;
                        }
                        $wuPoolSize = count($wuWinPool);
                    }
                }
            }
            // Load config: same resolution order as WinUniverseService::loadConfig()
            // Priority: win_universe_user_config.json → config/config.php defaults
            $wuCfg        = [];
            $wuModeSource = 'config_defaults';
            $wuUserCfgPath = $wuModuleBase . '/storage/runtime/win_universe_user_config.json';
            if (is_file($wuUserCfgPath)) {
                $wuUserCfgRaw = @file_get_contents($wuUserCfgPath);
                if ($wuUserCfgRaw !== false && $wuUserCfgRaw !== '') {
                    $wuUserCfgData = @json_decode($wuUserCfgRaw, true);
                    if (is_array($wuUserCfgData) && isset($wuUserCfgData['win_universe'])) {
                        $wuCfg        = $wuUserCfgData['win_universe'];
                        $wuModeSource = 'user_config';
                    }
                }
            }
            if (empty($wuCfg)) {
                $wuDefaultCfgPath = $wuModuleBase . '/config/config.php';
                if (is_file($wuDefaultCfgPath)) {
                    $wuDefaultCfgLoaded = @include $wuDefaultCfgPath;
                    if (is_array($wuDefaultCfgLoaded) && isset($wuDefaultCfgLoaded['win_universe'])) {
                        $wuCfg = $wuDefaultCfgLoaded['win_universe'];
                    }
                }
            }
            $wuMode         = (string)($wuCfg['win_universe_mode']      ?? 'shadow');
            $wuBonusEnabled = (bool)($wuCfg['priority_bonus_enabled']   ?? false);
            $wuBonusStrength = min(1.0, max(0.0, (float)($wuCfg['priority_bonus_strength'] ?? 0.5)));
            // Safety cap: if excessive_qualification_warning is set in win_universe_status.json, deactivate bonus
            $wuStatusPath = $wuModuleBase . '/storage/runtime/win_universe_status.json';
            $wuLastRunMode = null; // mode from Win Universe's last run (for sync check)
            if (is_file($wuStatusPath)) {
                $wuStatusRaw = @file_get_contents($wuStatusPath);
                if ($wuStatusRaw !== false && $wuStatusRaw !== '') {
                    $wuStatusData = @json_decode($wuStatusRaw, true);
                    if (is_array($wuStatusData)) {
                        if (!empty($wuStatusData['excessive_qualification_warning'])) {
                            // Pool qualifies >30% of universe — treat bonus as inactive for safety
                            $wuBonusEnabled = false;
                        }
                        $wuLastRunMode = $wuStatusData['mode'] ?? null;
                    }
                }
            }
        } catch (\Throwable $wuLoadEx) {
            // non-fatal — bonus stays inactive
        }
        $result['win_universe_mode']            = $wuMode;
        $result['win_universe_mode_source']     = $wuModeSource;
        $result['win_universe_mode_sync_ok']    = ($wuLastRunMode === null || $wuLastRunMode === $wuMode);
        $result['win_universe_last_run_mode']   = $wuLastRunMode;
        $result['win_universe_pool_size']       = $wuPoolSize;

        // ── Confirmation Layer pre-load ──────────────────────────────────────────
        // Load persistent confirmation pending store (survives across cron cycles).
        $confLayerEnabled        = (bool)($userLimits['confirmation_layer_enabled']         ?? false);
        $confWaitCycles          = max(1, (int)($userLimits['confirmation_wait_cycles']      ?? 3));
        $confReclaimTolPct       = max(0.0, (float)($userLimits['confirmation_reclaim_tolerance_pct'] ?? 0.005));
        $confTargetPatterns      = (array)($userLimits['confirmation_target_patterns']       ?? ['double_top_contextual_v2']);
        $confMaxAgeSeconds       = max(60, (int)($userLimits['confirmation_max_age_seconds'] ?? 480));
        $confPending             = $this->state->readJson('storage/confirmation_pending.json', []);
        $confNow                 = time();

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

            // === BRAIN BLACKLIST GATE: manual live-only symbol block ===
            // Applied before any other filtering. Structural signal may still exist in analytics/debug.
            if (SmartBrainConfig::isSymbolManuallyBlacklisted($symbol, $manualBlacklist)) {
                $result['manual_blacklist_rejected_count']++;
                // Record preview for diagnostics (first 10)
                if (count($result['manual_blacklist_rejected_preview']) < 10) {
                    $result['manual_blacklist_rejected_preview'][] = [
                        'symbol' => $symbol,
                        'reason' => 'reject_symbol_blacklisted_manual',
                        'stage' => 'brain_blacklist_gate',
                        'source' => 'manual_live_blacklist',
                    ];
                }
                $this->rejectLiveSignal($result, $symbol, $signalId ?? '', 'reject_symbol_blacklisted_manual', $selectionMode);
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

            // Record live_mode_filter outcome on the signal for downstream observability.
            // live_mode_filter_passed = true means the candidate survived this gate.
            $signal['live_mode_filter_passed'] = true;
            $signal['live_mode_filter_mode']   = $selectionMode;

            // === VALIDATION GATE 5: Weak Entry Quality Filter (P3) ===
            // Reject late entries (signal age > threshold) with nuanced sub-reasons
            $signalCreatedTs = (int)($signal['created_ts'] ?? 0);
            $lateEntryThresholdMinutes = (int)($userLimits['late_entry_max_minutes'] ?? 15);
            if ($signalCreatedTs > 0 && $lateEntryThresholdMinutes > 0) {
                $signalAgeMinutes = (time() - $signalCreatedTs) / 60;
                $signalAgeSeconds = (int)(time() - $signalCreatedTs);

                // Tolerance buffer: high-quality signals get +50% extra time allowance
                $confirmationScore = (float)($signal['confirmation_score'] ?? 0);
                $patternConfidence = (float)($signal['pattern_confidence'] ?? 0);
                $toleranceMultiplier = 1.0;
                if ($confirmationScore >= 0.70 && $patternConfidence >= 0.60) {
                    $toleranceMultiplier = 1.5;
                } elseif ($confirmationScore >= 0.55 && $patternConfidence >= 0.45) {
                    $toleranceMultiplier = 1.25;
                }
                $effectiveThresholdMinutes = $lateEntryThresholdMinutes * $toleranceMultiplier;

                if ($signalAgeMinutes > $effectiveThresholdMinutes) {
                    // Determine specific sub-reason
                    if ($signalAgeMinutes > $lateEntryThresholdMinutes * 3) {
                        $lateSubReason = 'late_entry_signal_too_old';
                    } elseif ($signalAgeMinutes > $lateEntryThresholdMinutes * 2) {
                        $lateSubReason = 'late_entry_timeout_exceeded';
                    } else {
                        $lateSubReason = 'late_entry_post_confirm_delay';
                    }

                    $this->rejectLiveSignal($result, $symbol, $signalId, $lateSubReason, $selectionMode);
                    $result['late_entry_rejected_count'] = ($result['late_entry_rejected_count'] ?? 0) + 1;
                    $result['late_entry_rejected_distribution'][$lateSubReason] = ($result['late_entry_rejected_distribution'][$lateSubReason] ?? 0) + 1;
                    if (count($result['late_entry_rejected_preview'] ?? []) < 5) {
                        $result['late_entry_rejected_preview'][] = [
                            'symbol' => $symbol,
                            'side' => strtolower(trim((string)($signal['side'] ?? ''))),
                            'pattern_algorithm' => (string)($signal['pattern_algorithm'] ?? ''),
                            'signal_age_seconds' => $signalAgeSeconds,
                            'threshold_minutes' => $lateEntryThresholdMinutes,
                            'effective_threshold_minutes' => round($effectiveThresholdMinutes, 1),
                            'tolerance_multiplier' => $toleranceMultiplier,
                            'confirmation_score' => $confirmationScore,
                            'pattern_confidence' => $patternConfidence,
                            'reject_subreason' => $lateSubReason,
                        ];
                    }
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

            // === V2 LIVE QUALITY FLOOR GATE ===
            // Applied to V2 contextual signals to prevent weak/medium-quality leakage into live
            $patternAlgo = (string)($signal['pattern_algorithm'] ?? '');
            $execProfile = (string)($userLimits['execution_profile'] ?? 'custom');
            $v2QualityFloorEnabled = (bool)($userLimits['v2_live_quality_floor_enabled'] ?? true);

            if (($patternAlgo === 'double_bottom_contextual_v2' || $patternAlgo === 'double_top_contextual_v2') && $v2QualityFloorEnabled) {
                $result['v2_live_quality_floor_applied_count'] = ($result['v2_live_quality_floor_applied_count'] ?? 0) + 1;

                // Track short enter_now signals at quality floor gate
                $signalSide = strtolower(trim((string)($signal['side'] ?? '')));
                $signalEntryAction = (string)($signal['entry_action'] ?? 'wait_retrace');
                $isShortEnterNow = ($signalSide === 'short' && $signalEntryAction === 'enter_now');
                $isLongEnterNow  = ($signalSide === 'long'  && $signalEntryAction === 'enter_now');
                if ($isShortEnterNow) {
                    $result['short_enter_now_live_applied_count'] = ($result['short_enter_now_live_applied_count'] ?? 0) + 1;
                }
                if ($isLongEnterNow) {
                    $result['long_enter_now_live_applied_count'] = ($result['long_enter_now_live_applied_count'] ?? 0) + 1;
                }

                $v2FloorResult = SmartBrainConfig::evaluateV2LiveQualityFloor($signal, $userLimits);
                if (!$v2FloorResult['eligible']) {
                    $result['v2_live_quality_floor_rejected_count'] = ($result['v2_live_quality_floor_rejected_count'] ?? 0) + 1;
                    // Track reject reasons in result
                    foreach ($v2FloorResult['reject_reasons'] as $vr) {
                        $result['v2_live_quality_floor_reject_reason_distribution'][$vr] =
                            ($result['v2_live_quality_floor_reject_reason_distribution'][$vr] ?? 0) + 1;
                    }
                    // Record preview for diagnostics (first 10)
                    if (count($result['v2_live_quality_floor_rejected_preview'] ?? []) < 10) {
                        $result['v2_live_quality_floor_rejected_preview'][] = [
                            'symbol' => $symbol,
                            'reject_reasons' => $v2FloorResult['reject_reasons'],
                            'checked_values' => $v2FloorResult['checked_values'],
                        ];
                    }
                    // Track short/long enter_now rejection with explicit reason
                    if ($isShortEnterNow) {
                        $result['short_enter_now_live_rejected_count'] = ($result['short_enter_now_live_rejected_count'] ?? 0) + 1;
                        foreach ($v2FloorResult['reject_reasons'] as $vr) {
                            $result['short_enter_now_live_reject_reasons'][$vr] =
                                ($result['short_enter_now_live_reject_reasons'][$vr] ?? 0) + 1;
                        }
                    }
                    if ($isLongEnterNow) {
                        $result['long_enter_now_live_rejected_count'] = ($result['long_enter_now_live_rejected_count'] ?? 0) + 1;
                    }
                    // Long-path funnel observability
                    if ($signalSide === 'long') {
                        $result['long_quality_floor_reject_total']++;
                    }
                    $this->rejectLiveSignal($result, $symbol, $signalId, 'v2_live_quality_floor', $selectionMode);
                    continue;
                }
                $result['v2_live_quality_floor_passed_count'] = ($result['v2_live_quality_floor_passed_count'] ?? 0) + 1;
                // Track short/long enter_now quality floor pass
                if ($isShortEnterNow) {
                    $result['short_enter_now_live_approved_count'] = ($result['short_enter_now_live_approved_count'] ?? 0) + 1;
                    if (!empty($v2FloorResult['checked_values']['trend_match_floor_borderline_pass'])) {
                        $result['short_enter_now_live_borderline_pass_count'] = ($result['short_enter_now_live_borderline_pass_count'] ?? 0) + 1;
                    }
                }
                if ($isLongEnterNow) {
                    $result['long_enter_now_live_approved_count'] = ($result['long_enter_now_live_approved_count'] ?? 0) + 1;
                }
            }

            // === SNIPER V3 LIVE QUALITY FILTER ===
            // Applied when execution_profile is a sniper profile (sniper_75_attempt or sniper_lite) AND pattern = V3
            $sniperV3FilterEnabled = (bool)($userLimits['sniper_v3_live_filter_enabled'] ?? false);
            $isSniperProfile = in_array($execProfile, ['sniper_75_attempt', 'sniper_lite'], true);

            if (($patternAlgo === 'double_bottom_contextual_v3' || $patternAlgo === 'double_top_contextual_v3')
                && $isSniperProfile
                && $sniperV3FilterEnabled
            ) {
                // Track structural V3 signal count (every V3 reaching this point is structurally valid)
                $result['structural_v3_signal_count'] = ($result['structural_v3_signal_count'] ?? 0) + 1;

                $sniperFilterResult = SmartBrainConfig::evaluateSniperV3LiveFilter($signal, $userLimits, $execProfile);
                // Tag signal for diagnostics
                $signal['sniper_profile_name'] = $execProfile;
                $signal['structural_signal'] = true;
                $signal['sniper_live_eligible'] = $sniperFilterResult['eligible'];
                $signal['sniper_filter_passed'] = $sniperFilterResult['eligible'];
                if (!$sniperFilterResult['eligible']) {
                    $signal['sniper_shadow_only'] = true;
                    $signal['sniper_reject_reasons'] = $sniperFilterResult['reject_reasons'];
                    // Track reject reasons in result
                    foreach ($sniperFilterResult['reject_reasons'] as $sr) {
                        $result['sniper_v3_reject_reason_distribution'][$sr] =
                            ($result['sniper_v3_reject_reason_distribution'][$sr] ?? 0) + 1;
                    }
                    $result['sniper_v3_live_rejected_count'] = ($result['sniper_v3_live_rejected_count'] ?? 0) + 1;
                    $result['sniper_v3_shadow_only_count'] = ($result['sniper_v3_shadow_only_count'] ?? 0) + 1;
                    // Long-path funnel observability
                    if ($side === 'long') {
                        $result['long_sniper_v3_live_rejected_count']++;
                    }
                    // Record preview for diagnostics (first 10)
                    if (count($result['sniper_v3_rejected_preview'] ?? []) < 10) {
                        $result['sniper_v3_rejected_preview'][] = [
                            'symbol' => $symbol,
                            'side' => $side,
                            'pattern_algorithm' => $patternAlgo,
                            'reject_reasons' => $sniperFilterResult['reject_reasons'],
                            'checked_values' => $sniperFilterResult['checked_values'],
                            'threshold_source' => $sniperFilterResult['checked_values']['threshold_source'] ?? 'default_v3',
                            'short_v3_threshold_applied' => $sniperFilterResult['short_v3_threshold_applied'] ?? false,
                            'long_v3_threshold_applied' => $sniperFilterResult['long_v3_threshold_applied'] ?? false,
                        ];
                    }
                    $this->rejectLiveSignal($result, $symbol, $signalId, 'sniper_v3_quality_filter', $selectionMode);
                    continue;
                }
                $result['sniper_v3_live_eligible_count'] = ($result['sniper_v3_live_eligible_count'] ?? 0) + 1;
                // Track short/long V3 eligible separately
                if (!empty($sniperFilterResult['short_v3_threshold_applied'])) {
                    $result['sniper_v3_short_live_eligible_count'] = ($result['sniper_v3_short_live_eligible_count'] ?? 0) + 1;
                }
                if (!empty($sniperFilterResult['long_v3_threshold_applied'])) {
                    $result['sniper_v3_long_live_eligible_count'] = ($result['sniper_v3_long_live_eligible_count'] ?? 0) + 1;
                }
            }

            // === COIN CYCLE DECISION DEBUG (read-only observability, does not affect routing) ===
            // Evaluated here — before the passport gate — so ALL evaluated signals are counted,
            // including those that are later rejected/demoted by the gate. Counters reflect
            // reality for every signal that reaches this point in the evaluation loop.
            // Runs unconditionally: passport gate enforcement is separate (see below).
            // cycle_decision_debug is only attached to the intent for approved signals below.
            $cycleDecisionDebug = null;
            $passportForDebug = $passports[strtoupper($symbol)] ?? null;
            if ($passportForDebug !== null && is_array($passportForDebug['coin_cycle_decision_model'] ?? null)) {
                $dm = $passportForDebug['coin_cycle_decision_model'];
                $cycleDecisionDebug = [
                    'available'                  => true,
                    'model_state'                => $dm['decision_model_state'] ?? null,
                    'model_confidence'           => $dm['decision_model_confidence'] ?? null,
                    'model_readiness'            => $dm['decision_model_readiness'] ?? null,
                    'model_actionability'        => $dm['decision_model_actionability'] ?? null,
                    'model_risk_posture'         => $dm['decision_model_risk_posture'] ?? null,
                    'model_hold_posture'         => $dm['decision_model_hold_posture'] ?? null,
                    'model_stop_posture'         => $dm['decision_model_stop_posture'] ?? null,
                    'model_live_bias'            => $dm['decision_model_live_bias'] ?? null,
                    'model_demo_bias'            => $dm['decision_model_demo_bias'] ?? null,
                    'model_shadow_bias'          => $dm['decision_model_shadow_bias'] ?? null,
                    'model_skip_bias'            => $dm['decision_model_skip_bias'] ?? null,
                    'model_warning_flag'         => $dm['decision_model_warning_flag'] ?? null,
                    'model_warning_reason'       => $dm['decision_model_warning_reason'] ?? null,
                    'model_low_confidence_flag'  => $dm['decision_model_low_confidence_flag'] ?? null,
                    'model_low_confidence_reason' => $dm['decision_model_low_confidence_reason'] ?? null,
                    'model_preferred_mode'       => $dm['decision_model_preferred_mode'] ?? null,
                    'model_preferred_risk'       => $dm['decision_model_preferred_risk'] ?? null,
                    'model_preferred_hold'       => $dm['decision_model_preferred_hold'] ?? null,
                    'model_preferred_stop'       => $dm['decision_model_preferred_stop'] ?? null,
                    'source_updated_at'          => $dm['updated_at'] ?? null,
                ];
                $result['cycle_debug_available_total']++;
                if (!empty($dm['decision_model_low_confidence_flag'])) {
                    $result['cycle_debug_low_confidence_total']++;
                }
            } else {
                $cycleDecisionDebug = ['available' => false];
                $result['cycle_debug_missing_total']++;
            }

            // === COIN CYCLE MODEL VETO LAYER (Coin Core Step 11) ===
            // Repositioned before the passport gate so the veto layer runs for ALL
            // quality-floor-passing candidates, not only the rare subset that also
            // clears the passport gate.  Hard gates (passport, quality floors, late-entry,
            // duplicate) remain intact and execute after this block.
            // Bounded: may only demote/skip; cannot promote or bypass existing hard gates.
            //
            // === COIN CYCLE POSITIVE SUPPORT LAYER (Coin Core Step 12) ===
            // Integrated after the veto conditions below.
            // For condition 3 (non_live_bias soft veto): if the overall cycle model is
            // explicitly favorable + actionable + no warnings, Step 12 support preserves
            // live routing instead of demoting to demo ("borderline live" case).
            // For no-veto signals: Step 12 records whether the cycle model actively
            // supports the live candidate (cycle_model_support_live) or is neutral
            // (cycle_model_support_no_effect).
            // Bounded: support can only preserve/tag; cannot promote hard-rejected candidates.
            $cycleModelUsed         = false;
            $cycleModelVetoApplied  = false;
            $cycleModelVetoReason   = null;
            $cycleModelRouteBefore  = 'live';
            // Step 12 support tracking variables
            $cycleModelSupportUsed          = false;
            $cycleModelSupportApplied       = false;
            $cycleModelSupportReason        = null;
            $cycleModelRouteBeforeSupport   = null;
            $cycleModelRouteAfterSupport    = null;

            if ($cycleDecisionDebug !== null && ($cycleDecisionDebug['available'] ?? false) === true) {
                $cycleModelUsed  = true;
                $cmState         = (string)($cycleDecisionDebug['model_state']         ?? 'unavailable');
                $cmActionability = (string)($cycleDecisionDebug['model_actionability'] ?? 'non_actionable');
                $cmRisk          = (string)($cycleDecisionDebug['model_risk_posture']  ?? 'unavailable');
                $cmLiveBias      = (string)($cycleDecisionDebug['model_live_bias']     ?? 'non_live_bias');
                $cmWarnFlag      = (bool)($cycleDecisionDebug['model_warning_flag']        ?? false);
                $cmLowConf       = (bool)($cycleDecisionDebug['model_low_confidence_flag'] ?? false);

                // Step 12: pre-compute support eligibility — explicit favorable conditions required.
                // Used by condition 3 and the no-veto support evaluation below.
                $isCycleSupportFavorable = (
                    $cmState         === 'favorable'
                    && $cmActionability === 'actionable'
                    && $cmRisk        !== 'high_risk'
                    && !$cmWarnFlag
                    && !$cmLowConf
                );

                // Hard veto → skip: non_actionable AND high_risk together signal a clearly
                // unfavorable entry window; skip is the appropriate outcome.
                if ($cmActionability === 'non_actionable' && $cmRisk === 'high_risk') {
                    $cycleModelVetoApplied = true;
                    $cycleModelVetoReason  = 'cycle_model_veto_high_risk';
                    $result['cycle_model_veto_total']++;
                    $result['cycle_model_demote_skip_total']++;
                    if ($side === 'long') {
                        $result['long_cycle_veto_total']++;
                    }
                    $this->rejectLiveSignal($result, $symbol, $signalId, 'cycle_model_veto_high_risk', $selectionMode);
                    continue;
                }

                // Hard veto → skip: model explicitly non_actionable with state weak or unavailable
                if ($cmActionability === 'non_actionable' && in_array($cmState, ['weak', 'unavailable'], true)) {
                    $cycleModelVetoApplied = true;
                    $cycleModelVetoReason  = 'cycle_model_veto_non_actionable';
                    $result['cycle_model_veto_total']++;
                    $result['cycle_model_demote_skip_total']++;
                    if ($side === 'long') {
                        $result['long_cycle_veto_total']++;
                    }
                    $this->rejectLiveSignal($result, $symbol, $signalId, 'cycle_model_veto_non_actionable', $selectionMode);
                    continue;
                }

                // Soft veto → demo: model has no live bias (would prefer demo/shadow).
                // Step 12 override: when overall cycle conditions are explicitly favorable
                // (state=favorable, actionable, no high_risk, no warnings, no low confidence),
                // support preserves live routing rather than demoting — "borderline live" case.
                if ($cmLiveBias === 'non_live_bias') {
                    if ($isCycleSupportFavorable) {
                        // Step 12 positive support: favorable overall state overrides non_live_bias
                        $cycleModelSupportUsed         = true;
                        $cycleModelSupportApplied      = true;
                        $cycleModelSupportReason       = 'cycle_model_support_borderline_live';
                        $cycleModelRouteBeforeSupport  = 'pending_demo_demotion';
                        $cycleModelRouteAfterSupport   = 'live';
                        $result['cycle_model_support_total']++;
                        $result['cycle_model_support_borderline_total']++;
                        // Do NOT continue — signal survives into passport gate
                    } else {
                        $cycleModelVetoApplied = true;
                        $cycleModelVetoReason  = 'cycle_model_demote_demo';
                        $result['cycle_model_veto_total']++;
                        $result['cycle_model_demote_demo_total']++;
                        if ($side === 'long') {
                            $result['long_cycle_veto_total']++;
                        }
                        $this->rejectLiveSignal($result, $symbol, $signalId, 'cycle_model_demote_demo', $selectionMode);
                        continue;
                    }
                }

                // Soft veto → demo: warning active AND low confidence together.
                // Note: $isCycleSupportFavorable requires !$cmWarnFlag && !$cmLowConf, so a
                // borderline-support bypass (above) can never also satisfy this condition.
                if ($cmWarnFlag && $cmLowConf) {
                    $cycleModelVetoApplied = true;
                    $cycleModelVetoReason  = 'cycle_model_demote_demo';
                    $result['cycle_model_veto_total']++;
                    $result['cycle_model_demote_demo_total']++;
                    if ($side === 'long') {
                        $result['long_cycle_veto_total']++;
                    }
                    $this->rejectLiveSignal($result, $symbol, $signalId, 'cycle_model_demote_demo', $selectionMode);
                    continue;
                }

                // No hard veto triggered — model conditions acceptable for live.
                // Step 12: evaluate positive support for this viable candidate.
                // (Borderline-bypass case already set $cycleModelSupportApplied above.)
                if (!$cycleModelSupportApplied) {
                    $cycleModelSupportUsed        = true;
                    $cycleModelRouteBeforeSupport = 'live';
                    $cycleModelRouteAfterSupport  = 'live';
                    $result['cycle_model_support_total']++;
                    if ($isCycleSupportFavorable) {
                        $cycleModelSupportApplied = true;
                        $cycleModelSupportReason  = 'cycle_model_support_live';
                        $result['cycle_model_support_live_total']++;
                    } else {
                        $cycleModelSupportReason  = 'cycle_model_support_no_effect';
                        $result['cycle_model_support_no_effect_total']++;
                    }
                }
                $result['cycle_model_no_effect_total']++;
            } else {
                // Cycle model unavailable for this symbol — no veto applied
                $result['cycle_model_unavailable_total']++;
            }

            // Step 12 support proof: record support evaluation before passport gate so the
            // data survives even if the candidate is later rejected by a downstream gate.
            if ($cycleModelSupportUsed && count($result['cycle_model_support_preview']) < 20) {
                $result['cycle_model_support_preview'][] = [
                    'symbol'                           => $symbol,
                    'signal_id'                        => $signalId,
                    'cycle_model_support_used'         => true,
                    'cycle_model_support_applied'      => $cycleModelSupportApplied,
                    'cycle_model_support_reason'       => $cycleModelSupportReason,
                    'cycle_model_route_before_support' => $cycleModelRouteBeforeSupport,
                    'cycle_model_route_after_support'  => $cycleModelRouteAfterSupport,
                ];
            }

            // Step 13: cycle eligibility refinement observability variables.
            // Populated inside the passport gate when passport is found; remain null/false
            // when passport is absent or refinement has not yet been applied.
            $cycleRefUsed            = false;
            $cycleRefApplied         = false;
            $cycleRefReason          = null;
            $passportEligBase        = null;
            $passportEligAfterCycle  = null;

            // === COIN PASSPORT LIVE GATE ===
            // Brain reads Coin Passport before allowing live signal issuance.
            // Gate result: allow_live | bootstrap_live | sim_only | shadow_only | reject
            if ($passportGateEnabled) {
                $result['passport_gate_applied_count']++;

                $passport = $passports[strtoupper($symbol)] ?? null;
                if ($passport === null) {
                    // No passport found — apply strict vs permissive policy
                    $result['passport_gate_no_passport_count']++;
                    if ($passportGateStrict) {
                        $result['passport_gate_signal_blocked_by_passport_count']++;
                        $this->rejectLiveSignal($result, $symbol, $signalId, 'passport_gate_no_passport', $selectionMode);
                        $result['passport_gate_rejected_count']++;
                        $result['passport_gate_reject_count']++;
                        $result['passport_gate_strict_block_count']++;
                        $result['passport_gate_reject_reason_distribution']['no_passport'] =
                            ($result['passport_gate_reject_reason_distribution']['no_passport'] ?? 0) + 1;
                        continue;
                    }
                    // Permissive default: no passport → allow but tag signal
                    $signal['passport_gate_result'] = 'no_passport_permissive';
                    $result['passport_gate_passed_count']++;
                    $result['passport_gate_allow_live_count']++;
                } else {
                    $passportEligibility  = (string)($passport['recommended_live_eligibility'] ?? 'sim_only');
                    $passportBlockReason  = (string)($passport['live_block_reason'] ?? '');
                    // Detailed insufficiency reason (e.g. "insufficient_total_samples:1<10").
                    // Present when the sim_only outcome came from the data-sufficiency check;
                    // null/empty when the block came from a metric gate instead.
                    $passportInsufReason  = (string)($passport['insufficient_data_reason'] ?? '');
                    $passportConfidence   = (string)($passport['data_confidence'] ?? 'none');
                    $passportCorridorP75  = (float)($passport['corridor_p75_roi'] ?? $passport['corridor_high_roi'] ?? 0.0);
                    $passportRunnerProb   = (float)($passport['runner_probability'] ?? 0.0);
                    $passportNoiseScore   = (float)($passport['noise_score'] ?? 1.0);
                    $pb                   = is_array($passport['pattern_behavior'] ?? null) ? $passport['pattern_behavior'] : [];
                    $passportV2Success    = is_float($pb['v2_success_rate'] ?? null) ? (float)$pb['v2_success_rate'] : null;

                    // Step 13: Read cycle eligibility refinement fields written by
                    // applyCycleEligibilityRefinement() (Coin Core Step 13).
                    // recommended_live_eligibility already reflects the refined value when present.
                    if (array_key_exists('cycle_refinement_applied', $passport)) {
                        $cycleRefUsed           = true;
                        $cycleRefApplied        = (bool)($passport['cycle_refinement_applied'] ?? false);
                        $cycleRefReason         = ($passport['cycle_refinement_reason'] ?? null) ?: null;
                        $passportEligBase       = ($passport['base_live_eligibility'] ?? null) ?: null;
                        $passportEligAfterCycle = ($passport['cycle_refined_live_eligibility'] ?? null) ?: null;
                        // Increment Step 13 counters
                        $result['cycle_eligibility_refine_total']++;
                        if ($cycleRefApplied && $passportEligBase !== null && $passportEligAfterCycle !== null) {
                            $eligOrder = ['shadow_only' => 0, 'sim_only' => 1, 'bootstrap_live' => 2, 'allow_live' => 3];
                            if (($eligOrder[$passportEligAfterCycle] ?? 1) > ($eligOrder[$passportEligBase] ?? 1)) {
                                $result['cycle_eligibility_upgrade_total']++;
                            } else {
                                $result['cycle_eligibility_downgrade_total']++;
                            }
                        } else {
                            $result['cycle_eligibility_no_effect_total']++;
                        }
                    } else {
                        $result['cycle_eligibility_unavailable_total']++;
                    }

                    // Track low-confidence passports regardless of eligibility decision
                    if ($passportConfidence === 'none' || $passportConfidence === 'low') {
                        $result['passport_gate_low_confidence_count']++;
                    }

                    // Strict mode: when passport confidence is sufficient, apply extra checks
                    $strictBlockReason = null;
                    if ($passportGateStrict && $passportEligibility === 'allow_live') {
                        $confRankMap = ['none' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];
                        $strictMinRank = $confRankMap[$passportStrictMinConfidence] ?? 2;
                        $curConfRank   = $confRankMap[$passportConfidence] ?? 0;

                        if ($curConfRank < $strictMinRank) {
                            $strictBlockReason = "strict:confidence_below_{$passportStrictMinConfidence}:{$passportConfidence}";
                        } elseif ($passportCorridorP75 < $passportStrictMinCorridorP75Roi) {
                            $strictBlockReason = "strict:corridor_p75_too_low:{$passportCorridorP75}<{$passportStrictMinCorridorP75Roi}";
                        } elseif ($passportRunnerProb < $passportStrictMinRunnerProb) {
                            $strictBlockReason = "strict:runner_prob_too_low:{$passportRunnerProb}<{$passportStrictMinRunnerProb}";
                        } elseif ($passportNoiseScore > $passportStrictMaxNoiseScore) {
                            $strictBlockReason = "strict:noise_too_high:{$passportNoiseScore}>{$passportStrictMaxNoiseScore}";
                        } elseif ($passportV2Success !== null && $passportV2Success < $passportStrictMinPatternSuccess) {
                            $strictBlockReason = "strict:v2_success_rate_too_low:{$passportV2Success}<{$passportStrictMinPatternSuccess}";
                        }

                        if ($strictBlockReason !== null) {
                            $passportEligibility = 'sim_only';
                            $passportBlockReason = $strictBlockReason;
                            $result['passport_gate_strict_block_count']++;
                        }
                    }

                    if ($passportEligibility === 'allow_live') {
                        $signal['passport_gate_result'] = 'allow_live';
                        $signal['passport_corridor_p75']  = $passportCorridorP75;
                        $signal['passport_runner_prob']   = $passportRunnerProb;
                        $signal['passport_noise_score']   = $passportNoiseScore;
                        $signal['passport_regime_health'] = $passport['market_regime_health_score'] ?? null;
                        $result['passport_gate_passed_count']++;
                        $result['passport_gate_allow_live_count']++;
                    } elseif ($passportEligibility === 'bootstrap_live') {
                        // bootstrap_live: 24h evidence is present but thin, or 7d context limited.
                        // All metric gates passed — live orders are permitted at reduced confidence.
                        // Smart Brain surfaces the bootstrap state explicitly so observers can
                        // distinguish it from a full allow_live.
                        $signal['passport_gate_result']           = 'bootstrap_live';
                        $signal['passport_gate_bootstrap']        = true;
                        $signal['passport_gate_bootstrap_reason'] = $passportBlockReason;
                        $signal['passport_corridor_p75']          = $passportCorridorP75;
                        $signal['passport_runner_prob']           = $passportRunnerProb;
                        $signal['passport_noise_score']           = $passportNoiseScore;
                        $signal['passport_regime_health']         = $passport['market_regime_health_score'] ?? null;
                        // Compact gate observability fields
                        $signal['passport_gate_state']            = 'bootstrap_live';
                        $signal['passport_gate_decision']         = 'pass_bootstrap';
                        $signal['passport_gate_demote_reason_detail'] = $passportBlockReason ?: null;
                        // Fresh-window counters for downstream inspection
                        $signal['passport_gate_samples_24h']     = (int)($passport['recent_samples_24h'] ?? 0);
                        $signal['passport_gate_samples_7d']      = (int)($passport['recent_samples_7d']  ?? 0);
                        $result['passport_gate_passed_count']++;
                        $result['passport_gate_bootstrap_live_count']++;
                    } elseif ($passportEligibility === 'reject') {
                        // Hard reject — coin explicitly blocked
                        $result['passport_gate_signal_blocked_by_passport_count']++;
                        if ($side === 'long') {
                            $result['long_passport_gate_reject_total']++;
                        }
                        $this->rejectLiveSignal($result, $symbol, $signalId, 'passport_gate_reject:' . $passportBlockReason, $selectionMode);
                        $result['passport_gate_rejected_count']++;
                        $result['passport_gate_reject_count']++;
                        $result['passport_gate_reject_reason_distribution'][$passportBlockReason ?: 'reject'] =
                            ($result['passport_gate_reject_reason_distribution'][$passportBlockReason ?: 'reject'] ?? 0) + 1;
                        if (count($result['passport_gate_rejected_preview']) < 10) {
                            $result['passport_gate_rejected_preview'][] = [
                                'symbol'                           => $symbol,
                                'eligibility'                      => $passportEligibility,
                                'block_reason'                     => $passportBlockReason,
                                'pattern_algorithm'                => (string)($signal['pattern_algorithm'] ?? ''),
                                // Step 12: cycle support fields for cross-reference
                                'cycle_model_support_used'         => $cycleModelSupportUsed,
                                'cycle_model_support_applied'      => $cycleModelSupportApplied,
                                'cycle_model_support_reason'       => $cycleModelSupportReason,
                                'cycle_model_route_before_support' => $cycleModelRouteBeforeSupport,
                                'cycle_model_route_after_support'  => $cycleModelRouteAfterSupport,
                                // Step 13: cycle eligibility refinement fields
                                'passport_cycle_refinement_used'     => $cycleRefUsed,
                                'passport_cycle_refinement_applied'  => $cycleRefApplied,
                                'passport_cycle_refinement_reason'   => $cycleRefReason,
                                'passport_eligibility_before_cycle'  => $passportEligBase,
                                'passport_eligibility_after_cycle'   => $passportEligAfterCycle,
                            ];
                        }
                        continue;
                    } else {
                        // sim_only / shadow_only — demote, do not issue live.
                        // passport_gate_demote:sim_only is produced only for these states.
                        // bootstrap_live is explicitly handled above and does NOT reach this branch.
                        $result['passport_gate_signal_blocked_by_passport_count']++;
                        if ($side === 'long') {
                            $result['long_passport_gate_reject_total']++;
                        }
                        $signal['passport_gate_result']    = $passportEligibility;
                        $signal['passport_gate_demoted']   = true;
                        $signal['passport_block_reason']   = $passportBlockReason;
                        $signal['passport_gate_state']     = $passportEligibility;
                        $signal['passport_gate_decision']  = 'demoted';
                        // Surface the actual insufficiency detail so observers can distinguish
                        // e.g. stale_24h from metric-gate failures without reading passport files.
                        if ($passportInsufReason !== '') {
                            $signal['passport_gate_demote_reason_detail'] = $passportInsufReason;
                        } elseif ($passportBlockReason !== '') {
                            $signal['passport_gate_demote_reason_detail'] = $passportBlockReason;
                        }
                        $result['passport_gate_demoted_to_sim_count']++;
                        if ($passportEligibility === 'sim_only') {
                            $result['passport_gate_sim_only_count']++;
                        } elseif ($passportEligibility === 'shadow_only') {
                            $result['passport_gate_shadow_only_count']++;
                        }
                        $result['passport_gate_reject_reason_distribution'][$passportBlockReason ?: $passportEligibility] =
                            ($result['passport_gate_reject_reason_distribution'][$passportBlockReason ?: $passportEligibility] ?? 0) + 1;
                        if (count($result['passport_gate_rejected_preview']) < 10) {
                            $result['passport_gate_rejected_preview'][] = [
                                'symbol'                           => $symbol,
                                'eligibility'                      => $passportEligibility,
                                'block_reason'                     => $passportBlockReason,
                                'insuf_reason'                     => $passportInsufReason ?: null,
                                'pattern_algorithm'                => (string)($signal['pattern_algorithm'] ?? ''),
                                // Step 12: cycle support fields for cross-reference
                                'cycle_model_support_used'         => $cycleModelSupportUsed,
                                'cycle_model_support_applied'      => $cycleModelSupportApplied,
                                'cycle_model_support_reason'       => $cycleModelSupportReason,
                                'cycle_model_route_before_support' => $cycleModelRouteBeforeSupport,
                                'cycle_model_route_after_support'  => $cycleModelRouteAfterSupport,
                                // Step 13: cycle eligibility refinement fields
                                'passport_cycle_refinement_used'     => $cycleRefUsed,
                                'passport_cycle_refinement_applied'  => $cycleRefApplied,
                                'passport_cycle_refinement_reason'   => $cycleRefReason,
                                'passport_eligibility_before_cycle'  => $passportEligBase,
                                'passport_eligibility_after_cycle'   => $passportEligAfterCycle,
                            ];
                        }
                        $this->rejectLiveSignal($result, $symbol, $signalId, 'passport_gate_demote:' . $passportEligibility, $selectionMode);
                        continue;
                    }
                }
            }

            // === ENTRY QUALITY FILTER (Bounded Entry-Quality Layer) ===
            // Runs AFTER all hard gates pass. Rejects structurally poor entry setups
            // before a live intent is created. Does NOT bypass passport/cycle/late-entry.
            // Rules are conservative — each rule requires two weak indicators together.
            {
                $eqFilterEnabled = (bool)($userLimits['entry_quality_filter_enabled'] ?? true);
                $eqFilterApplied  = false;
                $eqFilterReason   = null;

                // Freshness state (for observability and rule use)
                $eqSignalAge      = max(0, (int)(time() - (int)($signal['created_ts'] ?? time())));
                $eqLateLimitSec   = max(60, (int)($userLimits['late_entry_max_minutes'] ?? 15) * 60);
                $eqFreshnessRatio = $eqLateLimitSec > 0 ? ($eqSignalAge / $eqLateLimitSec) : 0.0;
                if ($eqFreshnessRatio <= 0.33) {
                    $eqFreshnessState = 'fresh';
                } elseif ($eqFreshnessRatio <= 0.66) {
                    $eqFreshnessState = 'aging';
                } else {
                    $eqFreshnessState = 'stale';
                }

                // Stretch state from corridor_width (for observability and rule use).
                // Thresholds align with Rule 2 (overstretched = >= 0.25, wide = >= 0.15).
                $eqCorridorWidth = (float)($signal['corridor_width'] ?? 0.0);
                if ($eqCorridorWidth >= 0.25) {
                    $eqStretchState = 'overstretched';
                } elseif ($eqCorridorWidth >= 0.15) {
                    $eqStretchState = 'wide';
                } else {
                    $eqStretchState = 'normal';
                }

                if ($eqFilterEnabled) {
                    $eqEntryQuality = (float)($signal['entry_quality_score'] ?? $signal['hold_quality_score'] ?? 0.0);
                    $eqHoldQuality  = (float)($signal['hold_quality_score']  ?? 0.0);
                    $eqPatternConf  = (float)($signal['pattern_confidence']  ?? $signal['confirmation_score'] ?? 0.0);
                    // confidence_score: confirmation_score with pattern_confidence as fallback
                    $eqConfScore    = (float)($signal['confirmation_score']  ?? $signal['pattern_confidence'] ?? 0.0);
                    $eqEntryAction  = (string)($signal['entry_action']       ?? 'wait_retrace');
                    $eqCmAvailable  = ($cycleDecisionDebug['available'] ?? false) === true;
                    $eqCmState      = $eqCmAvailable ? (string)($cycleDecisionDebug['model_state']        ?? '') : '';
                    $eqCmRisk       = $eqCmAvailable ? (string)($cycleDecisionDebug['model_risk_posture'] ?? '') : '';
                    // Soft-demote flag: when true the filter outcome is counted as demo_total
                    // (conceptually "would go to demo") rather than a hard reject.
                    $eqIsDemote     = false;

                    $result['entry_quality_filter_total']++;

                    // Rule 1: weak_structure_reject (HARD reject — quality floor)
                    // Raised to 0.45 so clearly weak signals are removed before learning
                    // overhead is paid. Low confidence doubles down on reject.
                    if (!$eqFilterApplied
                        && $eqEntryQuality < 0.45
                    ) {
                        $eqFilterApplied = true;
                        $eqFilterReason  = 'weak_structure_reject';
                        $result['entry_filter_weak_structure_reject_total']++;
                    }

                    // Rule 1b: weak_structure (demo or reject based on confidence)
                    // quality in [0.45, 0.50) AND confidence < 0.55 = borderline weak.
                    // If confidence is also very low (< 0.45) bias toward reject rather
                    // than demo — low-confidence borderline signals have poor learning value.
                    if (!$eqFilterApplied
                        && $eqEntryQuality < 0.50
                        && $eqConfScore    < 0.55
                    ) {
                        $eqFilterApplied = true;
                        // Very low confidence → hard reject; otherwise → demo, but guard against overflow
                        $demoOverflow = ($result['entry_quality_filter_demo_total'] ?? 0) > 4;
                        if ($eqConfScore < 0.45 || $demoOverflow) {
                            $eqIsDemote     = false;
                            $eqFilterReason = 'weak_structure_reject';
                            $result['entry_filter_weak_structure_reject_total']++;
                        } else {
                            $eqIsDemote     = true;
                            $eqFilterReason = 'weak_structure_demo';
                            $result['entry_filter_weak_structure_demo_total']++;
                        }
                    }

                    // Rule 2: weak_stale_reject (HARD reject)
                    // Signal is BOTH weak (low quality + low signal/confidence strength)
                    // AND stale/aging. Low learning value → hard reject, not demo.
                    if (!$eqFilterApplied
                        && $eqEntryQuality < 0.45
                        && ($eqPatternConf < 0.50 || $eqConfScore < 0.50)
                        && $eqFreshnessState !== 'fresh'
                    ) {
                        $eqFilterApplied = true;
                        $eqIsDemote      = false;
                        $eqFilterReason  = 'weak_stale_reject';
                        $result['entry_filter_weak_stale_reject_total']++;
                    }

                    // Rule 3: late_pressure_reject — metric-based, fires even when signal is fresh.
                    // Targets signals where signal_strength and confidence are both sub-threshold
                    // while quality is not high enough to compensate. Must trigger BEFORE live
                    // intent creation. No staleness requirement — catches late/degraded candidates
                    // regardless of age.
                    if (!$eqFilterApplied
                        && $eqPatternConf  < 0.55   // signal_strength weak
                        && $eqConfScore    < 0.60   // confidence weak
                        && $eqEntryQuality < 0.60   // quality not high enough to compensate
                    ) {
                        $eqFilterApplied = true;
                        $eqIsDemote      = false;
                        $eqFilterReason  = 'late_pressure_reject';
                        $result['entry_filter_late_pressure_reject_total']++;
                    }

                    // Rule 3b: staleness-based late pressure (HARD reject / conditional demo)
                    // Hard late: enter_now past the confirmation window OR stale freshness.
                    // Only clearly strong signals are exempt (quality >= 0.78 AND conf >= 0.70).
                    // Soft late: aging signals default to REJECT. Demo only if unusually strong
                    // (quality >= 0.70 AND pattern_conf >= 0.65) — high learning value edge case.
                    $eqLateEnterNowMaxSec = max(120, (int)($userLimits['entry_quality_late_enter_now_max_minutes'] ?? 8) * 60);
                    $eqIsStrongSignal     = ($eqEntryQuality >= 0.78 && $eqPatternConf >= 0.70);
                    $eqIsHighValueLate    = ($eqEntryQuality >= 0.70 && $eqPatternConf >= 0.65);
                    $eqIsHardLate         = ($eqEntryAction === 'enter_now' && $eqSignalAge > $eqLateEnterNowMaxSec)
                                        || ($eqFreshnessState === 'stale');
                    $eqIsSoftLate         = ($eqFreshnessState === 'aging' && !$eqIsStrongSignal);
                    if (!$eqFilterApplied && $eqIsHardLate && !$eqIsStrongSignal) {
                        $eqFilterApplied = true;
                        $eqFilterReason  = 'late_pressure_reject';
                        $result['entry_filter_late_pressure_reject_total']++;
                    } elseif (!$eqFilterApplied && $eqIsSoftLate) {
                        $eqFilterApplied = true;
                        // Default soft-late to reject; only demo if unusually high-value
                        if ($eqIsHighValueLate) {
                            $eqIsDemote     = true;
                            $eqFilterReason = 'late_pressure_reject';
                        } else {
                            $eqIsDemote     = false;
                            $eqFilterReason = 'late_pressure_reject';
                            $result['entry_filter_late_pressure_reject_total']++;
                        }
                    }

                    // Rule 4: entry_quality_overstretched (HARD reject)
                    // Price too far from zone (corridor >= 0.25). Overstretch is a dominant reject reason.
                    // To pass, signal must be ALL three: strong quality (>= 0.75), strong confidence
                    // (>= 0.68), AND fresh. Any other overstretched signal is rejected.
                    if (!$eqFilterApplied
                        && $eqCorridorWidth >= 0.25
                        && !($eqEntryQuality >= 0.75 && $eqPatternConf >= 0.68 && $eqFreshnessState === 'fresh')
                    ) {
                        $eqFilterApplied = true;
                        $eqFilterReason  = 'entry_quality_overstretched';
                    }

                    // Rule 5: entry_quality_poor_actionability (soft demote → demo)
                    // Cycle model: weak/unavailable state + below-average entry quality.
                    if (!$eqFilterApplied
                        && $eqCmAvailable
                        && in_array($eqCmState, ['weak', 'unavailable'], true)
                        && $eqEntryQuality < 0.55
                    ) {
                        $eqFilterApplied = true;
                        $eqIsDemote      = true;
                        $eqFilterReason  = 'entry_quality_poor_actionability';
                    }

                    // Rule 6: weak_tail_reject — fallback to guarantee minimum filter effect.
                    // If no other rule fired, reject the absolute lowest quality tail signals.
                    // Threshold (< 0.38) targets the bottom 10–20% of candidates without
                    // collapsing live flow. Prevents "no-op filter" runs.
                    if (!$eqFilterApplied
                        && $eqEntryQuality < 0.38
                    ) {
                        $eqFilterApplied = true;
                        $eqIsDemote      = false;
                        $eqFilterReason  = 'weak_tail_reject';
                        $result['entry_filter_weak_tail_reject_total']++;
                    }

                    if ($eqFilterApplied) {
                        if ($eqIsDemote) {
                            $result['entry_quality_filter_demo_total']++;
                        } else {
                            $result['entry_quality_filter_reject_total']++;
                        }
                        if (count($result['entry_quality_filter_rejected_preview']) < 10) {
                            $result['entry_quality_filter_rejected_preview'][] = [
                                'symbol'              => $symbol,
                                'side'                => $side,
                                'pattern_algorithm'   => (string)($signal['pattern_algorithm'] ?? ''),
                                'filter_reason'       => $eqFilterReason,
                                'filter_outcome'      => $eqIsDemote ? 'demote' : 'reject',
                                'entry_quality_score' => $eqEntryQuality,
                                'hold_quality_score'  => $eqHoldQuality,
                                'pattern_confidence'  => $eqPatternConf,
                                'confidence_score'    => $eqConfScore,
                                'signal_age_seconds'  => $eqSignalAge,
                                'freshness_state'     => $eqFreshnessState,
                                'stretch_state'       => $eqStretchState,
                                'corridor_width'      => $eqCorridorWidth,
                                'entry_action'        => $eqEntryAction,
                            ];
                        }
                        if (count($result['live_debug_preview']) < 10) {
                            $result['live_debug_preview'][] = [
                                'symbol'              => $symbol,
                                'side'                => $side,
                                'outcome'             => $eqIsDemote ? 'entry_filter_demote' : 'entry_filter_reject',
                                'reason'              => $eqFilterReason,
                                'entry_quality_score' => $eqEntryQuality,
                                'hold_quality_score'  => $eqHoldQuality,
                                'pattern_confidence'  => $eqPatternConf,
                                'confidence_score'    => $eqConfScore,
                                'freshness_state'     => $eqFreshnessState,
                                'signal_age_seconds'  => $eqSignalAge,
                            ];
                        }
                        $this->rejectLiveSignal($result, $symbol, $signalId, $eqFilterReason, $selectionMode);
                        continue;
                    }

                    $result['entry_quality_filter_no_effect_total']++;
                }

                // Capture filter state for intent-level observability (attached to intent below)
                $eqFilterResult = [
                    'entry_quality_filter_used'     => $eqFilterEnabled,
                    'entry_quality_filter_applied'  => $eqFilterApplied,
                    'entry_quality_filter_reason'   => $eqFilterReason,
                    'entry_quality_freshness_state' => $eqFreshnessState,
                    'entry_quality_stretch_state'   => $eqStretchState,
                ];
            }
            // === END ENTRY QUALITY FILTER ===

            // === WAVE FILTER (Bounded Wave Amplitude/Speed Layer — soft mode) ===
            // Runs after entry quality filter. Targets narrow low-amplitude / slow-wave candidates.
            // Uses existing signal data only. Does NOT bypass passport/cycle/slot gates.
            // soft mode: thresholds relaxed so filter is a secondary quality layer, not a primary
            //   blocker. Hard reject reserved ONLY for weak+slow without quality bypass. All other
            //   cases demote to demo or pass (no_effect). Strong/fresh signals always survive.
            {
                $wfEnabled  = (bool)($userLimits['wave_filter_enabled'] ?? true);
                $wfApplied  = false;
                $wfReason   = null;
                $wfIsDemote = false;

                // Wave amplitude: soft-mode thresholds — fewer signals classified as 'weak'.
                // strong requires corridor >= 0.14 or roi >= 0.015 (was 0.18/0.018).
                $wfInitialRoi = (float)($signal['initial_roi'] ?? $signal['entry_roi'] ?? 0.0);
                if ($wfInitialRoi >= 0.015 || $eqCorridorWidth >= 0.14) {
                    $wfAmplitudeState = 'strong';
                } elseif ($wfInitialRoi >= 0.007 || $eqCorridorWidth >= 0.08) {
                    $wfAmplitudeState = 'acceptable';
                } else {
                    $wfAmplitudeState = 'weak';
                }

                // Wave speed: soft-mode lower bound for 'normal' raised to 0.38 (was 0.50)
                // so fewer signals fall into 'slow'. fast threshold unchanged at 0.68.
                $wfTrendScore = (float)($signal['trend_match_score'] ?? 0.0);
                $wfVolatility = (float)($signal['volatility']        ?? 0.0);
                $wfVolNorm    = min(1.0, $wfVolatility / 0.005);
                $wfSpeedProxy = ($wfTrendScore * 0.7 + $wfVolNorm * 0.3);
                if ($wfSpeedProxy >= 0.68) {
                    $wfSpeedState = 'fast';
                } elseif ($wfSpeedProxy >= 0.38) {
                    $wfSpeedState = 'normal';
                } else {
                    $wfSpeedState = 'slow';
                }

                // Signal quality helpers for exception gates.
                // $eqFreshnessState is always computed above (outside the eq-filter enabled block).
                $wfEntryQuality = (float)($signal['entry_quality_score'] ?? $signal['hold_quality_score'] ?? 0.0);
                $wfPatternConf  = (float)($signal['pattern_confidence']  ?? $signal['confirmation_score'] ?? 0.0);
                // Strong-fresh exception: weak amplitude CAN pass when signal is high quality + fresh.
                // Speed restriction removed (was also requiring fast) — high quality fresh signals
                // must always survive wave filter regardless of speed state.
                $wfStrongFreshExcept     = ($wfEntryQuality >= 0.68 && $wfPatternConf >= 0.60
                                            && $eqFreshnessState === 'fresh');
                // High-quality-fresh exception: slow speed + strong amplitude can pass when
                // high quality AND fresh (strong breakout in a slow market).
                $wfHighQualityFreshExcept = ($wfEntryQuality >= 0.68 && $wfPatternConf >= 0.62
                                            && $eqFreshnessState === 'fresh');
                // Rule 1 excellent escape: weak+slow signal with good quality + fresh → pass entirely.
                // Prevents over-rejection when the entire pool is classified as weak+slow.
                $wfRule1ExcellentEscape = ($wfEntryQuality >= 0.72 && $wfPatternConf >= 0.64
                                           && $eqFreshnessState === 'fresh');
                // Rule 1 soft escape: weak+slow signal → demote, not reject, when signal has
                // reasonable quality OR is fresh with acceptable confidence.
                // OR logic ensures most weak+slow signals demote instead of hard-reject.
                $wfRule1SoftEscape = ($wfEntryQuality >= 0.60
                                      || ($wfPatternConf >= 0.55 && $eqFreshnessState === 'fresh'));

                if ($wfEnabled) {
                    $result['wave_filter_total']++;

                    // Rule 1: weak amplitude + slow speed → HARD reject with quality exceptions.
                    // Excellent quality + fresh: pass entirely (release valve for over-filtered pools).
                    // Good quality + fresh: demote to demo (not hard reject).
                    // All others: hard reject (primary reject source for narrow+slow candidates).
                    if (!$wfApplied
                        && $wfAmplitudeState === 'weak'
                        && $wfSpeedState === 'slow'
                        && !$wfRule1ExcellentEscape
                    ) {
                        $wfApplied  = true;
                        $wfIsDemote = $wfRule1SoftEscape; // demote if good quality, reject if poor
                        $wfReason   = 'wave_filter_low_amplitude_slow_wave';
                    }
                    // else: $wfRule1ExcellentEscape → pass through (wfApplied stays false)

                    // Rule 2: weak amplitude (normal or fast speed) → default to demote.
                    // Exception: strong signal + high quality + fresh → allow pass (no_effect).
                    // Speed requirement removed from exception: high quality fresh signals survive.
                    if (!$wfApplied && $wfAmplitudeState === 'weak') {
                        if (!$wfStrongFreshExcept) {
                            $wfApplied  = true;
                            $wfIsDemote = true;
                            $wfReason   = 'wave_filter_low_amplitude';
                        }
                        // else: strong+high quality+fresh exception — pass (no filter)
                    }

                    // Rule 3: slow speed + acceptable amplitude → demote.
                    // No exception — acceptable amplitude + slow speed is weak market structure.
                    if (!$wfApplied
                        && $wfSpeedState === 'slow'
                        && $wfAmplitudeState === 'acceptable'
                    ) {
                        $wfApplied  = true;
                        $wfIsDemote = true;
                        $wfReason   = 'wave_filter_slow_wave';
                    }

                    // Rule 4: slow speed + strong amplitude → demote unless truly high quality + fresh.
                    // Prevents silent pass on slow-moving markets even when amplitude looks strong.
                    if (!$wfApplied
                        && $wfSpeedState === 'slow'
                        && $wfAmplitudeState === 'strong'
                        && !$wfHighQualityFreshExcept
                    ) {
                        $wfApplied  = true;
                        $wfIsDemote = true;
                        $wfReason   = 'wave_filter_slow_wave_strong_amp';
                    }

                    if ($wfApplied && !$wfIsDemote) {
                        // Hard reject: only triggered when BOTH weak amplitude AND slow speed
                        // (Rule 1 without a quality escape). Signal is removed from the live pool.
                        $result['wave_filter_reject_total']++;
                        if (count($result['wave_filter_rejected_preview']) < 10) {
                            $result['wave_filter_rejected_preview'][] = [
                                'symbol'               => $symbol,
                                'side'                 => $side,
                                'pattern_algorithm'    => (string)($signal['pattern_algorithm'] ?? ''),
                                'filter_reason'        => $wfReason,
                                'filter_outcome'       => 'reject',
                                'wave_amplitude_state' => $wfAmplitudeState,
                                'wave_speed_state'     => $wfSpeedState,
                                'corridor_width'       => $eqCorridorWidth,
                                'trend_match_score'    => $wfTrendScore,
                                'volatility'           => $wfVolatility,
                            ];
                        }
                        $this->rejectLiveSignal($result, $symbol, $signalId, $wfReason, $selectionMode);
                        continue;
                    }

                    if ($wfApplied && $wfIsDemote) {
                        // Soft demote: single-condition cases (only-weak OR only-slow) and
                        // Rule 1 quality escapes. Signal is tagged for observability but is
                        // NOT removed from the live pool — it proceeds as a live candidate.
                        $result['wave_filter_demo_total']++;
                        if (count($result['wave_filter_rejected_preview']) < 10) {
                            $result['wave_filter_rejected_preview'][] = [
                                'symbol'               => $symbol,
                                'side'                 => $side,
                                'pattern_algorithm'    => (string)($signal['pattern_algorithm'] ?? ''),
                                'filter_reason'        => $wfReason,
                                'filter_outcome'       => 'demote',
                                'wave_amplitude_state' => $wfAmplitudeState,
                                'wave_speed_state'     => $wfSpeedState,
                                'corridor_width'       => $eqCorridorWidth,
                                'trend_match_score'    => $wfTrendScore,
                                'volatility'           => $wfVolatility,
                            ];
                        }
                        // fall through — signal continues to approved list
                    } else {
                        $result['wave_filter_no_effect_total']++;
                    }
                }

                // Capture filter state for intent-level observability
                $wfFilterResult = [
                    'wave_filter_used'        => $wfEnabled,
                    'wave_filter_applied'     => $wfApplied,
                    'wave_filter_reason'      => $wfReason,
                    'wave_amplitude_state'    => $wfAmplitudeState,
                    'wave_speed_state'        => $wfSpeedState,
                    'wave_filter_soft_mode'   => true,
                ];
            }
            // === END WAVE FILTER ===

            // === V2 CLEANUP FILTER ===
            // Stricter quality tightening for V2 contextual patterns only.
            // Runs after the general wave filter. Targets weak+slow V2 candidates that
            // the wave filter's soft-mode demote still lets through, as well as
            // medium-quality V2 signals that fall below V2-specific tighter floors.
            // Does NOT touch V3, non-V2 patterns, or global wave filter logic.
            {
                $v2cPatternAlgo = (string)($signal['pattern_algorithm'] ?? '');
                $isV2Pattern    = ($v2cPatternAlgo === 'double_bottom_contextual_v2'
                                   || $v2cPatternAlgo === 'double_top_contextual_v2');

                $v2cApplied     = false;
                $v2cReason      = '';
                $v2cQualityBand = 'none';
                $v2cWaveState   = '';

                if ($isV2Pattern) {
                    $result['v2_cleanup_total']++;

                    // Wave states already computed by wave filter block above (always available).
                    $v2cAmpState   = $wfAmplitudeState;
                    $v2cSpeedState = $wfSpeedState;
                    $v2cWaveState  = $v2cAmpState . '+' . $v2cSpeedState;

                    // Quality metrics for V2 cleanup exception gate.
                    $v2cEntryQuality = (float)($signal['entry_quality_score'] ?? $signal['hold_quality_score'] ?? 0.0);
                    $v2cPatternConf  = (float)($signal['pattern_confidence'] ?? 0.0);
                    $v2cConfScore    = (float)($signal['confirmation_score'] ?? 0.0);
                    $v2cSignalStr    = (float)($signal['reclaim_strength_score'] ?? 0.0);

                    // Classify quality band for V2 (tighter than wave filter exception thresholds).
                    if ($v2cEntryQuality >= 0.72 && $v2cPatternConf >= 0.64 && $v2cConfScore >= 0.68) {
                        $v2cQualityBand = 'high';
                    } elseif ($v2cEntryQuality >= 0.58 && $v2cPatternConf >= 0.52) {
                        $v2cQualityBand = 'medium';
                    } else {
                        $v2cQualityBand = 'low';
                    }

                    // Strong exception: a V2 weak+slow may survive only when signal is clearly
                    // strong across entry quality, pattern confidence, confirmation, AND signal strength.
                    $v2cStrongException = ($v2cEntryQuality >= 0.72
                                          && $v2cPatternConf >= 0.64
                                          && $v2cConfScore   >= 0.68
                                          && $v2cSignalStr   >= 0.60);

                    // Rule A: V2 weak+slow → reject unless strong exception passes.
                    if ($v2cAmpState === 'weak' && $v2cSpeedState === 'slow') {
                        $v2cApplied = true;
                        if ($v2cStrongException) {
                            // Passes with strong exception — tag as demo-tier, allow through.
                            $v2cReason = 'v2_cleanup_weak_slow_strong_exception';
                            $result['v2_cleanup_demo_total']++;
                        } else {
                            // No exception — reject from live pool.
                            $v2cReason = 'v2_cleanup_weak_slow_rejected';
                            $result['v2_cleanup_reject_total']++;
                            $this->rejectLiveSignal($result, $symbol, $signalId, $v2cReason, $selectionMode);
                            continue;
                        }
                    }

                    // Rule B: V2 medium quality band → apply V2-specific tighter live floor.
                    // Reject only when BOTH entry quality AND pattern confidence are below V2 floor.
                    // AND logic keeps this bounded — a strong confidence OR strong EQ rescues the signal.
                    // Only applies when Rule A did not already fire.
                    if (!$v2cApplied && $v2cQualityBand === 'medium') {
                        $v2cMediumLiveFloorConf = (float)($userLimits['v2_cleanup_medium_min_conf'] ?? 0.57);
                        $v2cMediumLiveFloorEq   = (float)($userLimits['v2_cleanup_medium_min_eq']   ?? 0.60);
                        if ($v2cPatternConf < $v2cMediumLiveFloorConf && $v2cEntryQuality < $v2cMediumLiveFloorEq) {
                            $v2cApplied = true;
                            $v2cReason  = 'v2_cleanup_medium_quality_tightened';
                            $result['v2_cleanup_reject_total']++;
                            $this->rejectLiveSignal($result, $symbol, $signalId, $v2cReason, $selectionMode);
                            continue;
                        }
                    }

                    // Rule C: V2 low quality band → reject unconditionally.
                    if (!$v2cApplied && $v2cQualityBand === 'low') {
                        $v2cApplied = true;
                        $v2cReason  = 'v2_cleanup_low_quality_rejected';
                        $result['v2_cleanup_reject_total']++;
                        $this->rejectLiveSignal($result, $symbol, $signalId, $v2cReason, $selectionMode);
                        continue;
                    }

                    if (!$v2cApplied) {
                        $result['v2_cleanup_no_effect_total']++;
                    }
                }

                // Capture V2 cleanup state for intent-level observability.
                $v2cFilterResult = [
                    'v2_cleanup_used'         => $isV2Pattern,
                    'v2_cleanup_applied'      => $v2cApplied,
                    'v2_cleanup_reason'       => $v2cReason,
                    'v2_cleanup_wave_state'   => $v2cWaveState,
                    'v2_cleanup_quality_band' => $v2cQualityBand,
                ];
            }
            // === END V2 CLEANUP FILTER ===

            // === CONFIRMATION LAYER ===
            // Post-pattern wait window for targeted patterns before allowing live entry.
            // V2 rollout scope: double_top_contextual_v2 short, double_bottom_contextual_v2 long.
            // Does NOT touch V3 or any other pattern.
            // Direction is derived from pattern name: double_top → short, double_bottom → long.
            $confLayerResult = [
                'confirmation_layer_used'       => false,
                'confirmation_setup_detected'   => false,
                'confirmation_wait_cycles_used' => 0,
                'confirmation_result'           => 'not_applicable',
                'confirmation_reason'           => '',
                'confirmation_side'             => $side,
            ];

            // Derive the expected side from pattern name so double_top always maps to short
            // and double_bottom always maps to long, without hardcoding per-call.
            $confPatternAlgoKey = (string)($signal['pattern_algorithm'] ?? '');
            $confExpectedSide   = str_contains($confPatternAlgoKey, 'double_top')    ? 'short'
                : (str_contains($confPatternAlgoKey, 'double_bottom') ? 'long' : '');

            if ($confLayerEnabled
                && $confExpectedSide !== ''
                && in_array($confPatternAlgoKey, $confTargetPatterns, true)
                && $side === $confExpectedSide
            ) {
                $confLayerResult['confirmation_layer_used'] = true;
                $result['confirmation_total']++;

                // Side-separated counter key: short_v2 for double_top short, long_v2 for double_bottom long.
                $confSideCounterKey = ($confPatternAlgoKey === 'double_top_contextual_v2'    && $side === 'short') ? 'short_v2'
                    : (($confPatternAlgoKey === 'double_bottom_contextual_v2' && $side === 'long')  ? 'long_v2' : '');
                if ($confSideCounterKey !== '') {
                    $result[$confSideCounterKey . '_confirmation_total']++;
                }

                // Build a precise lineage key: prefer signal_id (stable identity) when it is
                // a real upstream ID (not a fallback we generated ourselves this run).
                // Fallback: stable zone-hash anchored to setup geometry + side so a new setup
                // at a different price level or opposite side gets its own slot.
                $confZoneHigh = (float)($signal['entry_zone_high'] ?? 0.0);
                $confZoneLow  = (float)($signal['entry_zone_low']  ?? 0.0);
                $patternAlgoConf = $confPatternAlgoKey;
                if ($signalIdSource === 'original' && $signalId !== '') {
                    $confKey = 'sid_' . $signalId;
                } else {
                    $zoneHash = substr(md5($symbol . '|' . $patternAlgoConf . '|' . $side . '|' . $confZoneHigh . '|' . $confZoneLow), 0, 12);
                    $confKey = $symbol . '_' . $patternAlgoConf . '_' . $side . '_' . $zoneHash;
                }

                // Current live price for continuation proof.
                $currentLivePrice = (float)($prices[$symbol] ?? 0.0);

                if (!isset($confPending[$confKey])) {
                    // First time seeing this lineage → record setup, hold this cycle.
                    $confPending[$confKey] = [
                        'symbol'            => $symbol,
                        'pattern_algorithm' => $patternAlgoConf,
                        'side'              => $side,
                        'conf_key'          => $confKey,
                        'setup_detected_at' => date('c'),
                        'setup_ts'          => $confNow,
                        'cycles_seen'       => 1,
                        'setup_zone_high'   => $confZoneHigh,
                        'setup_zone_low'    => $confZoneLow,
                        'setup_live_price'  => $currentLivePrice,
                    ];
                    $confLayerResult['confirmation_setup_detected'] = true;
                    $confLayerResult['confirmation_result']         = 'setup_detected';
                    $confLayerResult['confirmation_reason']         = 'first_detection_hold';
                    $confLayerResult['confirmation_wait_cycles_used'] = 1;
                    $result['confirmation_state_preview'][] = [
                        'symbol'            => $symbol,
                        'pattern_algorithm' => $patternAlgoConf,
                        'side'              => $side,
                        'state'             => 'setup_detected',
                        'result'            => 'setup_detected',
                        'reason'            => 'first_detection_hold',
                        'wait_cycles_used'  => 1,
                        'setup_zone_high'   => $confZoneHigh,
                        'setup_zone_low'    => $confZoneLow,
                        'live_price'        => $currentLivePrice,
                    ];
                    // Skip to next signal — do not create a live intent this cycle.
                    continue;
                }

                // Seen before: increment cycle count and check fakeout / continuation / confirmation.
                $confPending[$confKey]['cycles_seen']++;
                $cyclesSeen = (int)$confPending[$confKey]['cycles_seen'];
                $confLayerResult['confirmation_wait_cycles_used'] = $cyclesSeen;

                $setupZoneHigh = (float)($confPending[$confKey]['setup_zone_high'] ?? 0.0);
                $setupZoneLow  = (float)($confPending[$confKey]['setup_zone_low']  ?? 0.0);

                if ($side === 'short') {
                    // Fakeout check for short: if current entry_zone_high drifted significantly
                    // above the setup zone high, the top was reclaimed → fakeout.
                    $fakeoutThreshold = $setupZoneHigh > 0.0
                        ? $setupZoneHigh * (1.0 + $confReclaimTolPct)
                        : 0.0;
                    $isFakeout = ($fakeoutThreshold > 0.0 && $confZoneHigh > $fakeoutThreshold);
                    $fakeoutReason = 'zone_high_reclaimed_above_setup';

                    // Continuation proof for short: price must be strictly below setup zone high.
                    $hasContinuation = ($currentLivePrice > 0.0 && $setupZoneHigh > 0.0)
                        ? ($currentLivePrice < $setupZoneHigh)
                        : false;
                    $continuationReason    = 'wait_cycles_and_downside_continuation';
                    $noContinuationReason  = 'no_downside_continuation_at_expiry';
                } else {
                    // Fakeout check for long: if current entry_zone_low drifted significantly
                    // below the setup zone low, the bottom was reclaimed → fakeout.
                    $fakeoutThreshold = $setupZoneLow > 0.0
                        ? $setupZoneLow * (1.0 - $confReclaimTolPct)
                        : 0.0;
                    $isFakeout = ($fakeoutThreshold > 0.0 && $confZoneLow < $fakeoutThreshold);
                    $fakeoutReason = 'zone_low_reclaimed_below_setup';

                    // Continuation proof for long: price must be strictly above setup zone low.
                    $hasContinuation = ($currentLivePrice > 0.0 && $setupZoneLow > 0.0)
                        ? ($currentLivePrice > $setupZoneLow)
                        : false;
                    $continuationReason    = 'wait_cycles_and_upside_continuation';
                    $noContinuationReason  = 'no_upside_continuation_at_expiry';
                }

                if ($isFakeout) {
                    // Fakeout detected — remove from pending, route to demo.
                    unset($confPending[$confKey]);
                    $confLayerResult['confirmation_result'] = 'fakeout';
                    $confLayerResult['confirmation_reason'] = $fakeoutReason;
                    $result['confirmation_fakeout_total']++;
                    $result['confirmation_demo_total']++;
                    if ($confSideCounterKey !== '') {
                        $result[$confSideCounterKey . '_confirmation_fakeout_total']++;
                        $result[$confSideCounterKey . '_confirmation_demo_total']++;
                    }
                    $result['confirmation_state_preview'][] = [
                        'symbol'            => $symbol,
                        'pattern_algorithm' => $patternAlgoConf,
                        'side'              => $side,
                        'state'             => 'fakeout',
                        'result'            => 'fakeout',
                        'reason'            => $fakeoutReason,
                        'wait_cycles_used'  => $cyclesSeen,
                        'setup_zone_high'   => $setupZoneHigh,
                        'setup_zone_low'    => $setupZoneLow,
                        'current_zone_high' => $confZoneHigh,
                        'current_zone_low'  => $confZoneLow,
                        'live_price'        => $currentLivePrice,
                    ];
                    $this->rejectLiveSignal($result, $symbol, $signalId, 'confirmation_fakeout', $selectionMode);
                    continue;
                }

                if ($cyclesSeen >= $confWaitCycles) {
                    // Enough cycles elapsed without fakeout — require explicit continuation proof
                    // in the intended direction. Do NOT auto-confirm on wait-cycle expiry alone.
                    if ($hasContinuation) {
                        // Confirmed: wait elapsed AND price moved in the intended direction.
                        unset($confPending[$confKey]);
                        $confLayerResult['confirmation_result'] = 'confirmed';
                        $confLayerResult['confirmation_reason'] = $continuationReason;
                        $result['confirmation_confirmed_total']++;
                        if ($confSideCounterKey !== '') {
                            $result[$confSideCounterKey . '_confirmation_confirmed_total']++;
                        }
                        $result['confirmation_state_preview'][] = [
                            'symbol'            => $symbol,
                            'pattern_algorithm' => $patternAlgoConf,
                            'side'              => $side,
                            'state'             => 'confirmed',
                            'result'            => 'confirmed',
                            'reason'            => $continuationReason,
                            'wait_cycles_used'  => $cyclesSeen,
                            'setup_zone_high'   => $setupZoneHigh,
                            'setup_zone_low'    => $setupZoneLow,
                            'live_price'        => $currentLivePrice,
                        ];
                        // Fall through — signal proceeds to live intent.
                    } else {
                        // Wait elapsed but no continuation proof — reject (not fakeout).
                        // Prefer demo on soft rollout; remove from pending to unblock next fresh setup.
                        unset($confPending[$confKey]);
                        $confLayerResult['confirmation_result'] = 'reject';
                        $confLayerResult['confirmation_reason'] = $noContinuationReason;
                        $result['confirmation_reject_total']++;
                        $result['confirmation_demo_total']++;
                        if ($confSideCounterKey !== '') {
                            $result[$confSideCounterKey . '_confirmation_reject_total']++;
                            $result[$confSideCounterKey . '_confirmation_demo_total']++;
                        }
                        $result['confirmation_state_preview'][] = [
                            'symbol'            => $symbol,
                            'pattern_algorithm' => $patternAlgoConf,
                            'side'              => $side,
                            'state'             => 'reject',
                            'result'            => 'reject',
                            'reason'            => $noContinuationReason,
                            'wait_cycles_used'  => $cyclesSeen,
                            'setup_zone_high'   => $setupZoneHigh,
                            'setup_zone_low'    => $setupZoneLow,
                            'live_price'        => $currentLivePrice,
                        ];
                        $this->rejectLiveSignal($result, $symbol, $signalId, 'confirmation_no_continuation', $selectionMode);
                        continue;
                    }
                } else {
                    // Still waiting: hold this cycle.
                    $confLayerResult['confirmation_result'] = 'waiting';
                    $confLayerResult['confirmation_reason'] = 'cycles_remaining_' . ($confWaitCycles - $cyclesSeen);
                    $result['confirmation_state_preview'][] = [
                        'symbol'            => $symbol,
                        'pattern_algorithm' => $patternAlgoConf,
                        'side'              => $side,
                        'state'             => 'waiting',
                        'result'            => 'waiting',
                        'reason'            => 'cycles_remaining_' . ($confWaitCycles - $cyclesSeen),
                        'wait_cycles_used'  => $cyclesSeen,
                        'cycles_needed'     => $confWaitCycles,
                        'setup_zone_high'   => $setupZoneHigh,
                        'setup_zone_low'    => $setupZoneLow,
                        'live_price'        => $currentLivePrice,
                    ];
                    continue;
                }
            }
            // === END CONFIRMATION LAYER ===

            // === POST-CONFIRM QUALITY GATE (weak+normal long V2) ===
            // Runs ONLY when all five conditions are true:
            //   1. pattern_algorithm = double_bottom_contextual_v2
            //   2. side = long
            //   3. confirmation_result = confirmed
            //   4. wave_amplitude_state = weak
            //   5. wave_speed_state = normal
            // Uses a bounded multi-signal gate: at least min_signals_pass of three primary
            // score checks must pass. Signals that fail are demoted to demo (safe fallback —
            // not hard reject) so the funnel stays alive for strong confirmed long V2.
            {
                $pcGateEnabled = (bool)($userLimits['post_confirm_wn_long_v2_gate_enabled'] ?? true);
                $pcGateResult  = [
                    'post_confirm_quality_gate_used'    => false,
                    'post_confirm_quality_gate_applied' => false,
                    'post_confirm_quality_gate_reason'  => '',
                    'post_confirm_quality_gate_outcome' => 'not_applicable',
                    'post_confirm_quality_gate_result'  => 'not_applicable',
                    'post_confirm_quality_gate_inputs'  => null,
                ];

                $pcIsTarget = ($pcGateEnabled
                    && $confPatternAlgoKey === 'double_bottom_contextual_v2'
                    && $side === 'long'
                    && $confLayerResult['confirmation_result'] === 'confirmed'
                    && isset($wfAmplitudeState)
                    && $wfAmplitudeState === 'weak'
                    && isset($wfSpeedState)
                    && $wfSpeedState === 'normal'
                );

                // total / no_effect only count when the gate actually evaluates a signal
                // (pcIsTarget = true). Non-targeted signals do not update any gate counter,
                // which prevents no_effect > 0 while total stays 0.
                if ($pcIsTarget) {
                    $result['post_confirm_quality_gate_total']++;
                    $result['post_confirm_quality_gate_used'] = true;
                    $pcGateResult['post_confirm_quality_gate_used'] = true;

                    $pcMinEq   = (float)($userLimits['post_confirm_wn_long_v2_min_entry_quality']      ?? 0.58);
                    $pcMinCf   = (float)($userLimits['post_confirm_wn_long_v2_min_corridor_fit']       ?? 0.52);
                    $pcMinTm   = (float)($userLimits['post_confirm_wn_long_v2_min_trend_match']        ?? 0.48);
                    $pcMinPc   = (float)($userLimits['post_confirm_wn_long_v2_min_pattern_confidence'] ?? 0.52);
                    $pcMinPass = (int)($userLimits['post_confirm_wn_long_v2_min_signals_pass']         ?? 2);

                    $pcEq = (float)($signal['entry_quality_score']  ?? 0.0);
                    $pcCf = (float)($signal['corridor_fit_score']   ?? 0.0);
                    $pcTm = (float)($signal['trend_match_score']    ?? 0.0);
                    $pcPc = (float)($signal['pattern_confidence']   ?? 0.0);

                    $pcPass = 0;
                    if ($pcEq >= $pcMinEq) { $pcPass++; }
                    if ($pcCf >= $pcMinCf) { $pcPass++; }
                    if ($pcTm >= $pcMinTm) { $pcPass++; }

                    $pcPatternConfPass = ($pcPc >= $pcMinPc);
                    $pcGateFail = ($pcPass < $pcMinPass || !$pcPatternConfPass);

                    // Compact inputs snapshot for per-intent diagnostics.
                    $pcGateResult['post_confirm_quality_gate_inputs'] = [
                        'entry_quality_score'    => $pcEq,
                        'corridor_fit_score'     => $pcCf,
                        'trend_match_score'      => $pcTm,
                        'pattern_confidence'     => $pcPc,
                        'min_entry_quality'      => $pcMinEq,
                        'min_corridor_fit'       => $pcMinCf,
                        'min_trend_match'        => $pcMinTm,
                        'min_pattern_confidence' => $pcMinPc,
                        'signals_passed'         => $pcPass,
                        'min_signals_pass'       => $pcMinPass,
                        'pattern_conf_pass'      => $pcPatternConfPass,
                    ];

                    if ($pcGateFail) {
                        $pcFailReason = !$pcPatternConfPass
                            ? 'post_confirm_wn_long_v2_pattern_confidence_below_floor'
                            : 'post_confirm_wn_long_v2_multi_signal_below_floor';
                        $result['post_confirm_quality_gate_applied']++;
                        $result['post_confirm_quality_gate_demo_total']++;
                        $pcGateResult['post_confirm_quality_gate_applied'] = true;
                        $pcGateResult['post_confirm_quality_gate_reason']  = $pcFailReason;
                        $pcGateResult['post_confirm_quality_gate_outcome'] = 'demo';
                        $pcGateResult['post_confirm_quality_gate_result']  = 'demoted_to_demo';
                        // Prefer demo (not hard reject) as safe fallback.
                        // Signal is tagged for observability and removed from live flow.
                        $this->rejectLiveSignal($result, $symbol, $signalId, $pcFailReason, $selectionMode);
                        continue;
                    }

                    // Gate passed — strong enough to proceed as live.
                    // no_effect increments here (gate evaluated, routing unchanged).
                    $result['post_confirm_quality_gate_live_pass_total']++;
                    $result['post_confirm_quality_gate_no_effect_total']++;
                    $pcGateResult['post_confirm_quality_gate_outcome'] = 'live_pass';
                    $pcGateResult['post_confirm_quality_gate_result']  = 'live_pass';
                } else {
                    // Gate not applicable for this signal — no counter updates.
                    $pcGateResult['post_confirm_quality_gate_outcome'] = 'not_applicable';
                    $pcGateResult['post_confirm_quality_gate_result']  = 'not_applicable';
                }
            }
            // === END POST-CONFIRM QUALITY GATE ===

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
            if ($side === 'long') {
                $result['long_approved_count']++;
            }

            $intent = [
                'schema_version' => 'live_intent_v1',
                'intent_id' => 'li_' . $signalId . '_' . substr(md5($signalId . $symbol . $side . $entryPolicy), 0, 8),
                'signal_id' => $signalId,
                'signal_id_source' => $signalIdSource,
                'symbol' => $symbol,
                'side' => $side,
                'pattern_algorithm' => (string)($signal['pattern_algorithm'] ?? ''),
                'entry_action' => $entryPolicy,
                'entry_timeout_minutes' => (int)($signal['entry_timeout_minutes'] ?? 8),
                'entry_price_reference' => $entryPriceRef,
                // Signal quality fields — used by decision engine to compute confidence band and route_state.
                // pattern_confidence → signal_strength, entry_quality_score → quality_score, v2_priority_score → scenario_score.
                'signal_strength'   => (float)($signal['pattern_confidence']    ?? $signal['confirmation_score'] ?? 0.0),
                'quality_score'     => (float)($signal['entry_quality_score']   ?? $signal['hold_quality_score'] ?? 0.0),
                'scenario_id'       => '',
                'scenario_score'    => (float)($signal['v2_priority_score']     ?? $signal['analyzer_score']    ?? 0.0),
                'pattern_version'   => (string)($signal['schema_version']       ?? ''),
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
                'created_at' => date('c'),
                'created_ts' => time(),
                'expires_at' => time() + (SmartBrainConfig::LIVE_INTENT_TTL_MINUTES * 60),
                'status' => SmartBrainConfig::INTENT_STATUS_PENDING,
                'claimed_at' => null,
                'claimed_by' => null,
                'executed_at' => null,
                'rejected_at' => null,
                'reject_reason' => null,
                'execution_limits_snapshot' => [
                    'live_max_positions' => (int)($liveConfig['live_max_positions'] ?? 3),
                    'live_one_trade_per_symbol' => (bool)($liveConfig['live_one_trade_per_symbol'] ?? true),
                ],
            ];

            if ($reverseEnabled && $sideOriginal !== $side) {
                $intent['side_original'] = $sideOriginal;
            }

            // Attach passport gate result for audit trail
            if ($passportGateEnabled) {
                $intent['passport_gate_result']     = $signal['passport_gate_result'] ?? 'not_applied';
                $intent['passport_corridor_p75']    = $signal['passport_corridor_p75'] ?? null;
                $intent['passport_runner_prob']     = $signal['passport_runner_prob'] ?? null;
                $intent['passport_noise_score']     = $signal['passport_noise_score'] ?? null;
                $intent['passport_regime_health']   = $signal['passport_regime_health'] ?? null;
            }

            // Attach read-only cycle decision debug snapshot (observability only, no routing effect)
            if ($cycleDecisionDebug !== null) {
                $intent['cycle_decision_debug'] = $cycleDecisionDebug;
            }

            // Attach cycle model veto layer observability fields (Coin Core Step 11)
            $intent['cycle_model_used']        = $cycleModelUsed;
            $intent['cycle_model_veto_applied'] = $cycleModelVetoApplied;
            $intent['cycle_model_veto_reason']  = $cycleModelVetoReason;
            $intent['cycle_model_route_before'] = $cycleModelRouteBefore;
            $intent['cycle_model_route_after']  = 'live';

            // Attach cycle positive support layer observability fields (Coin Core Step 12)
            $intent['cycle_model_support_used']          = $cycleModelSupportUsed;
            $intent['cycle_model_support_applied']       = $cycleModelSupportApplied;
            $intent['cycle_model_support_reason']        = $cycleModelSupportReason;
            $intent['cycle_model_route_before_support']  = $cycleModelRouteBeforeSupport;
            $intent['cycle_model_route_after_support']   = $cycleModelRouteAfterSupport;

            // Attach cycle eligibility refinement observability fields (Coin Core Step 13)
            $intent['passport_cycle_refinement_used']    = $cycleRefUsed;
            $intent['passport_cycle_refinement_applied'] = $cycleRefApplied;
            $intent['passport_cycle_refinement_reason']  = $cycleRefReason;
            $intent['passport_eligibility_before_cycle'] = $passportEligBase;
            $intent['passport_eligibility_after_cycle']  = $passportEligAfterCycle;

            // Attach entry quality filter observability fields
            $intent['entry_quality_filter_used']     = $eqFilterResult['entry_quality_filter_used'];
            $intent['entry_quality_filter_applied']  = $eqFilterResult['entry_quality_filter_applied'];
            $intent['entry_quality_filter_reason']   = $eqFilterResult['entry_quality_filter_reason'];
            $intent['entry_quality_freshness_state'] = $eqFilterResult['entry_quality_freshness_state'];
            $intent['entry_quality_stretch_state']   = $eqFilterResult['entry_quality_stretch_state'];

            // Attach wave filter observability fields
            $intent['wave_filter_used']        = $wfFilterResult['wave_filter_used'];
            $intent['wave_filter_applied']     = $wfFilterResult['wave_filter_applied'];
            $intent['wave_filter_reason']      = $wfFilterResult['wave_filter_reason'];
            $intent['wave_amplitude_state']    = $wfFilterResult['wave_amplitude_state'];
            $intent['wave_speed_state']        = $wfFilterResult['wave_speed_state'];
            $intent['wave_filter_soft_mode']   = $wfFilterResult['wave_filter_soft_mode'];

            // Attach V2 cleanup filter observability fields
            $intent['v2_cleanup_used']         = $v2cFilterResult['v2_cleanup_used'];
            $intent['v2_cleanup_applied']      = $v2cFilterResult['v2_cleanup_applied'];
            $intent['v2_cleanup_reason']       = $v2cFilterResult['v2_cleanup_reason'];
            $intent['v2_cleanup_wave_state']   = $v2cFilterResult['v2_cleanup_wave_state'];
            $intent['v2_cleanup_quality_band'] = $v2cFilterResult['v2_cleanup_quality_band'];

            // Attach confirmation layer observability fields
            $intent['confirmation_layer_used']       = $confLayerResult['confirmation_layer_used'];
            $intent['confirmation_setup_detected']   = $confLayerResult['confirmation_setup_detected'];
            $intent['confirmation_wait_cycles_used'] = $confLayerResult['confirmation_wait_cycles_used'];
            $intent['confirmation_result']           = $confLayerResult['confirmation_result'];
            $intent['confirmation_reason']           = $confLayerResult['confirmation_reason'];
            $intent['confirmation_side']             = $confLayerResult['confirmation_side'];

            // Attach post-confirm quality gate observability fields
            $intent['post_confirm_quality_gate_used']    = $pcGateResult['post_confirm_quality_gate_used'];
            $intent['post_confirm_quality_gate_applied'] = $pcGateResult['post_confirm_quality_gate_applied'];
            $intent['post_confirm_quality_gate_reason']  = $pcGateResult['post_confirm_quality_gate_reason'];
            $intent['post_confirm_quality_gate_outcome'] = $pcGateResult['post_confirm_quality_gate_outcome'];
            $intent['post_confirm_quality_gate_result']  = $pcGateResult['post_confirm_quality_gate_result']  ?? $pcGateResult['post_confirm_quality_gate_outcome'];
            if (isset($pcGateResult['post_confirm_quality_gate_inputs'])) {
                $intent['post_confirm_quality_gate_inputs'] = $pcGateResult['post_confirm_quality_gate_inputs'];
            }

            // P7: Attach per-symbol hint metadata for audit trail
            if ($symbolHints['applied']) {
                $intent['symbol_hints'] = $symbolHints;
            }

            if (isset($signal['schema_version'])) {
                $intent['source_schema_version'] = $signal['schema_version'];
            }

            // Leverage chain — expose every cap applied so operators can diagnose silent crushing
            $intent['leverage_chain_requested_manual'] = (int)($signal['leverage_chain_requested_manual'] ?? $userLimits['manual_leverage'] ?? 0);
            $intent['leverage_chain_requested_max']    = (int)($signal['leverage_chain_requested_max'] ?? $userLimits['max_leverage'] ?? 0);
            $intent['leverage_chain_mode_cap']         = (int)($signal['leverage_chain_mode_cap'] ?? 0);
            $intent['leverage_chain_mode_cap_label']   = (string)($signal['leverage_chain_mode_cap_label'] ?? '');
            $intent['leverage_chain_profile_cap']      = (int)($signal['leverage_chain_profile_cap'] ?? 0);
            $intent['leverage_chain_risk_cap']         = (int)($signal['leverage_chain_risk_cap'] ?? 0);
            $intent['leverage_chain_final']            = (int)($signal['leverage_chain_final'] ?? $botReadyRisk['leverage'] ?? 0);
            $intent['leverage_chain_reason']           = (string)($signal['leverage_chain_reason'] ?? '');

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
                    // Live mode filter observability (req. WU-5 §7)
                    'live_mode_filter_passed' => true,
                    'live_mode_filter_mode'   => $selectionMode,
                    'profile_id' => $botReadyRisk['profile_id'] ?? null,
                    'budget_usdt_per_trade' => $botReadyRisk['budget_usdt_per_trade'] ?? null,
                    'order_type' => $botReadyRisk['order_type'] ?? null,
                    'has_limits' => !empty($botReadyRisk['limits']),
                    'cycle_model_used' => $cycleModelUsed,
                    'cycle_model_veto_applied' => $cycleModelVetoApplied,
                    'cycle_model_veto_reason' => $cycleModelVetoReason,
                    'cycle_model_route_before' => $cycleModelRouteBefore,
                    'cycle_model_route_after' => 'live',
                    'cycle_model_support_used' => $cycleModelSupportUsed,
                    'cycle_model_support_applied' => $cycleModelSupportApplied,
                    'cycle_model_support_reason' => $cycleModelSupportReason,
                    'cycle_model_route_before_support' => $cycleModelRouteBeforeSupport,
                    'cycle_model_route_after_support' => $cycleModelRouteAfterSupport,
                    // Step 13: cycle eligibility refinement observability
                    'passport_cycle_refinement_used'    => $cycleRefUsed,
                    'passport_cycle_refinement_applied' => $cycleRefApplied,
                    'passport_cycle_refinement_reason'  => $cycleRefReason,
                    'passport_eligibility_before_cycle' => $passportEligBase,
                    'passport_eligibility_after_cycle'  => $passportEligAfterCycle,
                    // Entry quality filter observability
                    'entry_quality_filter_used'     => $eqFilterResult['entry_quality_filter_used'],
                    'entry_quality_freshness_state' => $eqFilterResult['entry_quality_freshness_state'],
                    'entry_quality_stretch_state'   => $eqFilterResult['entry_quality_stretch_state'],
                    // Wave filter observability
                    'wave_filter_used'        => $wfFilterResult['wave_filter_used'],
                    'wave_amplitude_state'    => $wfFilterResult['wave_amplitude_state'],
                    'wave_speed_state'        => $wfFilterResult['wave_speed_state'],
                    'wave_filter_soft_mode'   => $wfFilterResult['wave_filter_soft_mode'],
                ];
            }
        }

        $result['intents_created'] = count($intents);
        $result['long_intents_created_count'] = count(array_filter($intents, static fn($i) => ($i['side'] ?? '') === 'long'));

        // Wave filter release valve: if live pool ended up empty despite signals being wave-filtered,
        // flag for observability. The quality exceptions in Rule 1 serve as the primary release
        // mechanism (excellent-quality weak+slow signals pass entirely instead of being rejected).
        if ($result['approved_count'] === 0 && $result['wave_filter_total'] > 0) {
            $result['wave_filter_release_valve_used'] = true;
        }

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

        // ── Wave Penalty Layer ──────────────────────────────────────────────
        // Additive ranking penalty for candidates with weak/slow wave conditions.
        // Runs AFTER all hard gates (entry filter, passport, cycle, wave filter).
        // Does NOT add filtering — reduces effective slot priority score so that
        // weak/slow candidates more often lose slot competition to stronger ones,
        // while still surviving when the pool is weak and slots are available.
        // Side-neutral: penalty applies identically to long and short.
        {
            $wpEnabled = (bool)($userLimits['wave_penalty_enabled'] ?? true);

            foreach ($intents as &$wpIntent) {
                $wpAmpState   = (string)($wpIntent['wave_amplitude_state'] ?? 'acceptable');
                $wpSpeedState = (string)($wpIntent['wave_speed_state']     ?? 'normal');

                $wpPenalty = 0.0;
                $wpReason  = null;

                if ($wpEnabled) {
                    $result['wave_penalty_total']++;
                    $wpIsWeakAmp  = ($wpAmpState  === 'weak');
                    $wpIsSlowWave = ($wpSpeedState === 'slow');

                    if ($wpIsWeakAmp && $wpIsSlowWave) {
                        // Both weak amplitude AND slow wave → larger combined penalty.
                        // These candidates survived only via a quality escape in Rule 1.
                        $wpPenalty = 10.0;
                        $wpReason  = 'wave_penalty_weak_amplitude_slow_wave';
                    } elseif ($wpIsWeakAmp) {
                        // Weak amplitude only → small penalty.
                        $wpPenalty = 5.0;
                        $wpReason  = 'wave_penalty_weak_amplitude';
                    } elseif ($wpIsSlowWave) {
                        // Slow wave only → small penalty.
                        $wpPenalty = 5.0;
                        $wpReason  = 'wave_penalty_slow_wave';
                    }

                    if ($wpPenalty > 0.0) {
                        $result['wave_penalty_applied_total']++;
                    } else {
                        $result['wave_penalty_no_effect_total']++;
                    }
                }

                $wpIntent['wave_penalty_used']            = $wpEnabled;
                $wpIntent['wave_penalty_value']           = $wpPenalty;
                $wpIntent['wave_penalty_reason']          = $wpReason;
                $wpIntent['wave_penalty_amplitude_state'] = $wpAmpState;
                $wpIntent['wave_penalty_speed_state']     = $wpSpeedState;
            }
            unset($wpIntent);
        }
        // ── End Wave Penalty Layer ──────────────────────────────────────────

        // ── Ranking Boost Layer ─────────────────────────────────────────────
        // Runs AFTER all filters and wave penalty. Does NOT filter or reject.
        // Computes a bounded positive boost (0–10 pts) per intent so that
        // clearly strong/fresh/clean candidates earn a higher slot priority score
        // and beat merely acceptable ones more often under slot competition.
        // Components: quality (0–4), freshness (0–3), cycle support (0–2), wave clean (0–1).
        // Side-neutral: boost applies identically to long and short.
        {
            $rbEnabled = (bool)($userLimits['ranking_boost_enabled'] ?? true);

            foreach ($intents as &$rbIntent) {
                $rbBoost     = 0.0;
                $rbReasons   = [];
                $rbQComp     = 0.0;
                $rbFComp     = 0.0;
                $rbCComp     = 0.0;
                $rbWComp     = 0.0;

                if ($rbEnabled) {
                    $result['ranking_boost_total']++;

                    // Quality component: reward clearly strong entry quality.
                    $rbQuality = (float)($rbIntent['quality_score'] ?? 0.0);
                    if ($rbQuality >= 0.75) {
                        $rbQComp   = 4.0;
                        $rbReasons[] = 'quality_strong+4';
                    } elseif ($rbQuality >= 0.65) {
                        $rbQComp   = 2.0;
                        $rbReasons[] = 'quality_good+2';
                    }

                    // Freshness component: reward fresh signals over aging/stale ones.
                    $rbFreshnessState = (string)($rbIntent['entry_quality_freshness_state'] ?? 'stale');
                    if ($rbFreshnessState === 'fresh') {
                        $rbFComp   = 3.0;
                        $rbReasons[] = 'freshness_fresh+3';
                    } elseif ($rbFreshnessState === 'aging') {
                        $rbFComp   = 1.0;
                        $rbReasons[] = 'freshness_aging+1';
                    }

                    // Cycle support component: reward cycle-confirmed candidates.
                    if ((bool)($rbIntent['cycle_model_support_applied'] ?? false)) {
                        $rbCComp   = 2.0;
                        $rbReasons[] = 'cycle_support+2';
                    } elseif (!(bool)($rbIntent['cycle_model_veto_applied'] ?? false)) {
                        $rbCComp   = 1.0;
                        $rbReasons[] = 'no_cycle_veto+1';
                    }

                    // Wave cleanliness component: reward candidates with zero wave penalty
                    // that also passed the wave filter without a soft-mode override.
                    $rbWavePenalty      = (float)($rbIntent['wave_penalty_value']   ?? 0.0);
                    $rbWaveFilterApplied = (bool)($rbIntent['wave_filter_applied']  ?? false);
                    if ($rbWavePenalty === 0.0 && !$rbWaveFilterApplied) {
                        $rbWComp   = 1.0;
                        $rbReasons[] = 'wave_clean+1';
                    }

                    $rbBoost = $rbQComp + $rbFComp + $rbCComp + $rbWComp;
                    $rbBoost = min(10.0, max(0.0, $rbBoost));

                    if ($rbBoost > 0.0) {
                        $result['ranking_boost_applied_total']++;
                    } else {
                        $result['ranking_boost_no_effect_total']++;
                    }
                }

                $rbIntent['ranking_boost_used']               = $rbEnabled;
                $rbIntent['ranking_boost_value']              = $rbBoost;
                $rbIntent['ranking_boost_reason']             = $rbEnabled && !empty($rbReasons) ? implode(', ', $rbReasons) : null;
                $rbIntent['ranking_boost_quality_component']  = $rbQComp;
                $rbIntent['ranking_boost_freshness_component'] = $rbFComp;
                $rbIntent['ranking_boost_cycle_component']    = $rbCComp;
                $rbIntent['ranking_boost_wave_component']     = $rbWComp;
            }
            unset($rbIntent);
        }
        // ── End Ranking Boost Layer ─────────────────────────────────────────

        // ── Win Universe Bonus Layer ────────────────────────────────────────
        // Soft ranking bonus for qualified win-pool symbols.
        // Only active when win_universe_mode = 'priority' AND priority_bonus_enabled = true.
        // Runs AFTER all hard gates and ranking layers, BEFORE Slot Priority scoring.
        // Does NOT filter or reject — adds a bounded positive bonus to slot_priority_score
        // so win-pool symbols more often beat similar non-pool candidates in slot competition,
        // while clearly stronger non-pool signals still win.
        // Side-neutral: bonus applies identically to long and short.
        // Safety: inactive when excessive_qualification_warning is set (>30% of universe qualified).
        //
        // Per-intent observability fields set here:
        //   in_win_pool                  = true|false — symbol is currently in win pool
        //   win_universe_status_at_eval  = 'qualified'|'not_in_pool'|'shadow_mode'|'bonus_disabled'|'pool_empty'
        //   win_universe_bonus_applied   = true|false — bonus was applied to THIS candidate
        //   win_universe_bonus_used      = same as win_universe_bonus_applied (alias for backward compat)
        //   win_universe_bonus_value     = bonus points pre-loaded into intent for slot priority scoring
        //   win_universe_bonus_reason    = human-readable reason string
        //   ranking_changed_by_bonus     = false here; may be set true in Slot Priority competition branch
        //   effective_priority_before_bonus = null here; set in Slot Priority layer
        //   effective_priority_after_bonus  = null here; set in Slot Priority layer
        {
            $wuBonusActive = ($wuMode === 'priority' && $wuBonusEnabled && $wuPoolSize > 0);

            foreach ($intents as &$wuIntent) {
                $wuSym      = strtoupper((string)($wuIntent['symbol'] ?? ''));
                $wuInPool   = isset($wuWinPool[$wuSym]);
                $wuBonusVal = 0.0;
                $wuReason   = null;
                $wuStatus   = 'not_in_pool';

                $result['win_universe_bonus_total']++;

                if ($wuBonusActive && $wuInPool) {
                    // Bonus = strength × 10 pts, bounded [0, 10]
                    $wuBonusVal = round(min(10.0, max(0.0, $wuBonusStrength * 10.0)), 2);
                    $wuReason   = 'win_pool_qualified+' . $wuBonusVal;
                    $wuStatus   = 'qualified';
                    $result['win_universe_bonus_applied_total']++;
                } else {
                    $result['win_universe_bonus_no_effect_total']++;
                    if (!$wuBonusActive) {
                        if ($wuMode !== 'priority') {
                            $wuStatus = 'shadow_mode';
                        } elseif (!$wuBonusEnabled) {
                            $wuStatus = 'bonus_disabled';
                        } else {
                            // mode=priority, bonus_enabled=true, but pool is empty
                            $wuStatus = 'pool_empty';
                        }
                    }
                }

                // win_universe_bonus_applied = true when THIS candidate received the bonus (per-intent flag).
                // win_universe_bonus_used is kept as an alias for backward compatibility.
                $wuBonusApplied = ($wuBonusActive && $wuInPool);

                $wuIntent['in_win_pool']                       = $wuInPool;
                $wuIntent['win_universe_status_at_eval']       = $wuStatus;
                $wuIntent['win_universe_bonus_applied']        = $wuBonusApplied;
                $wuIntent['win_universe_bonus_used']           = $wuBonusApplied;
                $wuIntent['win_universe_bonus_value']          = $wuBonusVal;
                $wuIntent['win_universe_bonus_reason']         = $wuReason;
                // Explicit at-entry fields for causal evaluation attribution.
                // These are stamped at evaluation time and must NOT be recomputed later.
                $wuIntent['win_universe_status_at_entry']       = $wuStatus;
                $wuIntent['in_win_pool_at_entry']               = $wuInPool;
                $wuIntent['win_universe_bonus_applied_at_entry'] = $wuBonusApplied;
                $wuIntent['win_universe_bonus_used_at_entry']   = $wuBonusApplied;
                $wuIntent['win_universe_bonus_value_at_entry']  = $wuBonusVal;
                // These fields are populated by the Slot Priority layer below.
                $wuIntent['effective_priority_before_bonus']   = null;
                $wuIntent['effective_priority_after_bonus']    = null;
                $wuIntent['ranking_changed_by_bonus']          = false;
            }
            unset($wuIntent);
        }
        // ── End Win Universe Bonus Layer ────────────────────────────────────

        // ── Confirmation Layer: expire stale pending entries and persist ──────
        // Remove entries that have exceeded the max age window (no update in too long).
        if ($confLayerEnabled) {
            foreach ($confPending as $ck => $ce) {
                $setupTs = (int)($ce['setup_ts'] ?? 0);
                if ($setupTs > 0 && ($confNow - $setupTs) > $confMaxAgeSeconds) {
                    $cePattern = (string)($ce['pattern_algorithm'] ?? '');
                    $ceSide    = (string)($ce['side'] ?? '');
                    $ceSideKey = ($cePattern === 'double_top_contextual_v2'    && $ceSide === 'short') ? 'short_v2'
                        : (($cePattern === 'double_bottom_contextual_v2' && $ceSide === 'long')  ? 'long_v2' : '');
                    $result['confirmation_state_preview'][] = [
                        'symbol'            => (string)($ce['symbol'] ?? ''),
                        'pattern_algorithm' => $cePattern,
                        'side'              => $ceSide,
                        'state'             => 'expired',
                        'result'            => 'expired',
                        'reason'            => 'max_age_exceeded',
                        'wait_cycles_used'  => (int)($ce['cycles_seen'] ?? 0),
                        'setup_zone_high'   => (float)($ce['setup_zone_high'] ?? 0.0),
                        'setup_zone_low'    => (float)($ce['setup_zone_low']  ?? 0.0),
                        'age_seconds'       => $confNow - $setupTs,
                    ];
                    unset($confPending[$ck]);
                    $result['confirmation_expired_total']++;
                    if ($ceSideKey !== '') {
                        $result[$ceSideKey . '_confirmation_expired_total']++;
                    }
                }
            }
            $this->state->writeJson('storage/confirmation_pending.json', $confPending);
        }
        // ── End Confirmation Layer persist ───────────────────────────────────

        // ── Slot Priority Layer ─────────────────────────────────────────────
        // Time-aware candidate ranking for limited live slots.
        // Applied AFTER all existing gates, BEFORE lifecycle merge.
        // Does NOT change hard slot limits — only ranks competing candidates.
        // Pipeline: signal → decision → slot_priority → slot_competition → routing
        {
            $slotPriorityEnabled     = (bool)($userLimits['slot_priority_enabled']     ?? true);
            $freshnessDecayEnabled   = (bool)($userLimits['freshness_decay_enabled']   ?? true);
            $freshnessWindowMinutes  = max(1, (int)($userLimits['slot_priority_freshness_window_minutes'] ?? 30));
            $maxPositions            = (int)($liveConfig['live_max_positions'] ?? 3);

            // Read current open position count from bot's last_run.json (best-effort, non-fatal).
            $currentOpenPositions = 0;
            try {
                $botStoragePath = $this->resolveBotStoragePath();
                if ($botStoragePath !== null) {
                    $rawBotLastRun = @file_get_contents($botStoragePath . '/last_run.json');
                    if ($rawBotLastRun !== false) {
                        $botLastRunData = @json_decode($rawBotLastRun, true);
                        if (is_array($botLastRunData)) {
                            $activeProtSummary = $botLastRunData['active_protection_summary'] ?? [];
                            $currentOpenPositions = (int)($activeProtSummary['active_positions_count'] ?? 0);
                        }
                    }
                }
            } catch (\Throwable $slotEx) {
                // non-fatal — slotsAvailable falls back to maxPositions
            }

            // Count pending+claimed intents already in live_intents.json that are NOT being
            // superseded by the current run. These occupy future slots and must be subtracted
            // from available slots to correctly detect slot pressure.
            $existingActiveSlots = 0;
            $liveIntentsPathForSlot = $this->state->resolvePath('storage/live_intents.json');
            try {
                if (is_file($liveIntentsPathForSlot)) {
                    $rawLiveIntents = @file_get_contents($liveIntentsPathForSlot);
                    if ($rawLiveIntents !== false) {
                        $existingLiveData = @json_decode($rawLiveIntents, true);
                        if (is_array($existingLiveData)) {
                            // Build set of intent_ids being emitted by this run
                            $thisRunIntentIds = [];
                            foreach ($intents as $spCheckIntent) {
                                $thisRunId = $spCheckIntent['intent_id'] ?? '';
                                if ($thisRunId !== '') {
                                    $thisRunIntentIds[$thisRunId] = true;
                                }
                            }
                            // Count non-terminal existing intents not replaced by this run
                            foreach ($existingLiveData['intents'] ?? [] as $ei) {
                                $eiId     = $ei['intent_id'] ?? '';
                                $eiStatus = $ei['status']    ?? 'pending';
                                if (($eiStatus === SmartBrainConfig::INTENT_STATUS_PENDING ||
                                     $eiStatus === SmartBrainConfig::INTENT_STATUS_CLAIMED) &&
                                    ($eiId === '' || !isset($thisRunIntentIds[$eiId]))) {
                                    $existingActiveSlots++;
                                }
                            }
                        }
                    }
                }
            } catch (\Throwable $slotLookEx) {
                // non-fatal — existingActiveSlots stays 0
            }

            $slotsAvailable  = max(0, $maxPositions - $currentOpenPositions - $existingActiveSlots);
            $candidatesCount = count($intents);

            $result['slot_priority_open_positions_count']   = $currentOpenPositions;
            $result['slot_priority_existing_active_slots']  = $existingActiveSlots;
            $result['slot_priority_slots_available']        = $slotsAvailable;
            $result['slot_priority_candidates_total']       = $candidatesCount;

            if (!$slotPriorityEnabled || $candidatesCount <= $slotsAvailable) {
                // No slot competition — all candidates fit or feature disabled.
                $result['slot_priority_used']             = false;
                $result['slot_priority_not_needed_total'] = $candidatesCount;
                $spNoCompIdx = 0;
                foreach ($intents as &$spIntentRef) {
                    $priorityData = $this->computeSlotPriorityScore($spIntentRef, $freshnessDecayEnabled, $freshnessWindowMinutes);
                    $spIntentRef['slot_priority_score']           = $priorityData['score'];
                    $spIntentRef['slot_priority_bucket']          = $priorityData['bucket'];
                    $spIntentRef['slot_priority_reason']          = $priorityData['reason'];
                    $spIntentRef['slot_priority_ranking_index']   = $spNoCompIdx;
                    $spIntentRef['slot_priority_total_competitors'] = $candidatesCount;
                    $spIntentRef['slot_competition_result']       = 'not_needed';
                    $spIntentRef['slot_competition_reason']       = $slotPriorityEnabled
                        ? 'slots_available'
                        : 'slot_priority_disabled';
                    // Win Universe bonus observability: before/after scores.
                    // No competition — bonus increased score but did not change ranking outcome.
                    $spWuBonusOnThis = (float)($spIntentRef['win_universe_bonus_value'] ?? 0.0);
                    $spIntentRef['effective_priority_before_bonus'] = round($priorityData['score'] - $spWuBonusOnThis, 2);
                    $spIntentRef['effective_priority_after_bonus']  = $priorityData['score'];
                    $spIntentRef['ranking_changed_by_bonus']        = false;
                    $spNoCompIdx++;
                }
                unset($spIntentRef);
            } else {
                // Slot competition: more candidates than available slots.
                $result['slot_priority_used'] = true;

                // Score every candidate.
                // Also compute score-without-wu-bonus for ranking_changed_by_bonus detection.
                $scoredCandidates = [];
                foreach ($intents as $spIdx => $spIntent) {
                    $priorityData = $this->computeSlotPriorityScore($spIntent, $freshnessDecayEnabled, $freshnessWindowMinutes);
                    $spWuBonusThis = (float)($spIntent['win_universe_bonus_value'] ?? 0.0);
                    $scoreWithoutWuBonus = round($priorityData['score'] - $spWuBonusThis, 2);
                    $scoredCandidates[] = [
                        'idx'                    => $spIdx,
                        'intent'                 => $spIntent,
                        'score'                  => $priorityData['score'],
                        'score_without_wu_bonus' => $scoreWithoutWuBonus,
                        'wu_bonus_value'         => $spWuBonusThis,
                        'bucket'                 => $priorityData['bucket'],
                        'reason'                 => $priorityData['reason'],
                        'created_ts'             => (int)($spIntent['created_ts'] ?? 0),
                        'quality_score'          => (float)($spIntent['quality_score'] ?? 0.0),
                    ];
                }

                // Sort: primary = score desc, secondary = freshness (created_ts desc),
                // tertiary = quality_score desc.
                usort($scoredCandidates, static function (array $a, array $b): int {
                    if ($b['score'] !== $a['score']) {
                        return $b['score'] <=> $a['score'];
                    }
                    if ($b['created_ts'] !== $a['created_ts']) {
                        return $b['created_ts'] <=> $a['created_ts'];
                    }
                    return $b['quality_score'] <=> $a['quality_score'];
                });

                $totalCompetitors = count($scoredCandidates);
                $winners = array_slice($scoredCandidates, 0, $slotsAvailable);
                $losers  = array_slice($scoredCandidates, $slotsAvailable);

                // Cutoff score = lowest winning score (used for ranking_changed_by_bonus detection).
                // A pool-bonus winner whose score-without-bonus would fall below this cutoff
                // would have lost without the bonus, so ranking_changed_by_bonus = true.
                $cutoffScore = !empty($winners) ? (float)end($winners)['score'] : 0.0;

                $winnerIdxSet = [];
                foreach ($winners as $wRank => $w) {
                    $winnerIdxSet[$w['idx']] = ['data' => $w, 'rank' => $wRank];
                }

                // Rebuild intents list with only winners; annotate priority fields.
                $priorityFilteredIntents = [];
                foreach ($intents as $spIdx => $spIntent) {
                    if (isset($winnerIdxSet[$spIdx])) {
                        $wEntry = $winnerIdxSet[$spIdx];
                        $w      = $wEntry['data'];
                        $spIntent['slot_priority_score']             = $w['score'];
                        $spIntent['slot_priority_bucket']            = $w['bucket'];
                        $spIntent['slot_priority_reason']            = $w['reason'];
                        $spIntent['slot_priority_ranking_index']     = $wEntry['rank'];
                        $spIntent['slot_priority_total_competitors'] = $totalCompetitors;
                        $spIntent['slot_competition_result']         = 'won';
                        $spIntent['slot_competition_reason']         = 'higher_priority_won';
                        // Win Universe bonus observability for competition winners.
                        $spIntent['effective_priority_after_bonus']  = $w['score'];
                        $spIntent['effective_priority_before_bonus'] = $w['score_without_wu_bonus'];
                        // ranking_changed_by_bonus: true when the bonus caused this win
                        // (without bonus the candidate would have fallen to the loser set).
                        $wuBonusHere = $w['wu_bonus_value'];
                        $rankChangedHere = ($wuBonusHere > 0.0 && $w['score_without_wu_bonus'] < $cutoffScore);
                        $spIntent['ranking_changed_by_bonus'] = $rankChangedHere;
                        if ($rankChangedHere) {
                            $result['win_universe_bonus_ranking_changed_total']++;
                        }
                        $priorityFilteredIntents[] = $spIntent;
                        $result['slot_priority_won_total']++;
                    }
                }

                // Record losers in rejection_reasons and preview (observability only).
                foreach ($losers as $lRank => $l) {
                    $lIntent = $l['intent'];
                    $lIntent['slot_priority_score']              = $l['score'];
                    $lIntent['slot_priority_bucket']             = $l['bucket'];
                    $lIntent['slot_priority_reason']             = $l['reason'];
                    $lIntent['slot_priority_ranking_index']      = $slotsAvailable + $lRank;
                    $lIntent['slot_priority_total_competitors']  = $totalCompetitors;
                    $lIntent['slot_competition_result']          = 'lost';
                    $lIntent['slot_competition_reason']          = 'low_priority_lost';
                    $lIntent['effective_priority_after_bonus']   = $l['score'];
                    $lIntent['effective_priority_before_bonus']  = $l['score_without_wu_bonus'];
                    $lIntent['ranking_changed_by_bonus']         = false;
                    $this->rejectLiveSignal(
                        $result,
                        (string)($lIntent['symbol'] ?? ''),
                        (string)($lIntent['signal_id'] ?? ''),
                        'slot_priority_lost',
                        $selectionMode
                    );
                    $result['slot_priority_lost_total']++;
                    if (count($result['slot_priority_rejected_preview']) < 5) {
                        $result['slot_priority_rejected_preview'][] = [
                            'symbol'                          => (string)($lIntent['symbol'] ?? ''),
                            'side'                            => (string)($lIntent['side'] ?? ''),
                            'slot_priority_score'             => $l['score'],
                            'slot_priority_bucket'            => $l['bucket'],
                            'slot_priority_reason'            => $l['reason'],
                            'slot_priority_ranking_index'     => $slotsAvailable + $lRank,
                            'slot_priority_total_competitors' => $totalCompetitors,
                            'slot_competition_result'         => 'lost',
                            'slot_competition_reason'         => 'low_priority_lost',
                        ];
                    }
                }

                $intents = $priorityFilteredIntents;

                // Update derived intent counters to reflect post-priority filtering.
                $result['intents_created']            = count($intents);
                $result['long_intents_created_count'] = count(array_filter($intents, static fn($i) => ($i['side'] ?? '') === 'long'));
            }
        }
        // ── End Slot Priority Layer ─────────────────────────────────────────

        // Build win_universe_bonus_preview AFTER slot priority so it includes
        // effective_priority_before_bonus, effective_priority_after_bonus, and ranking_changed_by_bonus.
        {
            $wuPreview = [];
            foreach ($intents as $wuPrevIntent) {
                if (count($wuPreview) >= 10) {
                    break;
                }
                $wuPreview[] = [
                    'symbol'                          => $wuPrevIntent['symbol']                         ?? '',
                    'side'                            => $wuPrevIntent['side']                           ?? '',
                    'in_win_pool'                     => $wuPrevIntent['in_win_pool']                    ?? false,
                    'win_universe_status_at_eval'     => $wuPrevIntent['win_universe_status_at_eval']    ?? 'not_in_pool',
                    'win_universe_status_at_entry'    => $wuPrevIntent['win_universe_status_at_entry']   ?? 'not_in_pool',
                    'win_universe_bonus_applied'      => $wuPrevIntent['win_universe_bonus_applied']     ?? false,
                    'win_universe_bonus_used'         => $wuPrevIntent['win_universe_bonus_used']        ?? false,
                    'win_universe_bonus_value'        => $wuPrevIntent['win_universe_bonus_value']       ?? 0.0,
                    'win_universe_bonus_reason'       => $wuPrevIntent['win_universe_bonus_reason']      ?? null,
                    'effective_priority_before_bonus' => $wuPrevIntent['effective_priority_before_bonus'] ?? null,
                    'effective_priority_after_bonus'  => $wuPrevIntent['effective_priority_after_bonus']  ?? null,
                    'ranking_changed_by_bonus'        => $wuPrevIntent['ranking_changed_by_bonus']        ?? false,
                    'slot_competition_result'         => $wuPrevIntent['slot_competition_result']         ?? null,
                    'slot_priority_score'             => $wuPrevIntent['slot_priority_score']             ?? null,
                ];
            }
            $result['win_universe_bonus_preview'] = $wuPreview;
        }

        // ── Win Universe entry-attribution log (best-effort, non-fatal) ─────
        // Append one record per new intent to win_universe/storage/runtime/win_universe_intent_attribution.ndjson.
        // This log is consumed by WinUniverseService::computeEvalAtEntry() to build causal
        // entry-status evaluation. Bounded to 2000 records to prevent unbounded growth.
        if (!empty($intents)) {
            try {
                $wuModBaseForLog = dirname($this->moduleBase) . '/win_universe';
                $wuAttrLogPath   = $wuModBaseForLog . '/storage/runtime/win_universe_intent_attribution.ndjson';
                $wuAttrDir       = dirname($wuAttrLogPath);
                if (!is_dir($wuAttrDir)) {
                    @mkdir($wuAttrDir, 0755, true);
                }

                // Load per-symbol WU engine qualification_status (qualified/near_qualified/rejected)
                // from win_universe.json. This allows evaluation to distinguish near_qualified from
                // rejected at the time of entry — a finer distinction than win-pool membership alone.
                $wuSymQualStatus = [];
                try {
                    $wuUnivPath = $wuModBaseForLog . '/storage/runtime/win_universe.json';
                    if (is_file($wuUnivPath)) {
                        $wuUnivRaw = @file_get_contents($wuUnivPath);
                        if ($wuUnivRaw !== false && $wuUnivRaw !== '') {
                            $wuUnivData = @json_decode($wuUnivRaw, true);
                            if (is_array($wuUnivData) && isset($wuUnivData['symbols']) && is_array($wuUnivData['symbols'])) {
                                foreach ($wuUnivData['symbols'] as $wuQSym => $wuQRec) {
                                    $wuQStatus = (string)($wuQRec['qualification_status'] ?? '');
                                    if ($wuQStatus !== '') {
                                        $wuSymQualStatus[strtoupper((string)$wuQSym)] = $wuQStatus;
                                    }
                                }
                            }
                        }
                    }
                } catch (\Throwable $_wuQEx) {
                    // non-fatal — qualification_status_at_entry will be null for this run
                }

                $wuNowTs  = time();
                $wuLines  = [];
                foreach ($intents as $wuAttrIntent) {
                    $wuAttrSym = strtoupper((string)($wuAttrIntent['symbol'] ?? ''));
                    if ($wuAttrSym === '') {
                        continue;
                    }
                    // wu_qualification_status_at_entry: WU engine's assessment (qualified/near_qualified/rejected).
                    // Distinct from win_universe_status_at_entry which reflects pool membership + bonus state.
                    $wuQualStatusAtEntry = $wuSymQualStatus[$wuAttrSym] ?? null;
                    $wuLines[] = json_encode([
                        'intent_id'                        => $wuAttrIntent['intent_id']                      ?? null,
                        'symbol'                           => $wuAttrSym,
                        'side'                             => (string)($wuAttrIntent['side'] ?? ''),
                        'created_at_ts'                    => $wuNowTs,
                        'win_universe_status_at_entry'     => $wuAttrIntent['win_universe_status_at_entry']   ?? 'not_in_pool',
                        'in_win_pool_at_entry'             => (bool)($wuAttrIntent['in_win_pool_at_entry']   ?? false),
                        'bonus_applied_at_entry'           => (bool)($wuAttrIntent['win_universe_bonus_applied_at_entry'] ?? false),
                        'bonus_value_at_entry'             => (float)($wuAttrIntent['win_universe_bonus_value_at_entry']  ?? 0.0),
                        'wu_qualification_status_at_entry' => $wuQualStatusAtEntry,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                if (!empty($wuLines)) {
                    // Read existing lines, prepend new, bound to 2000
                    $wuExistingLines = [];
                    if (is_file($wuAttrLogPath)) {
                        $wuRawLog = @file_get_contents($wuAttrLogPath);
                        if ($wuRawLog !== false && $wuRawLog !== '') {
                            $wuExistingLines = array_filter(explode("\n", trim($wuRawLog)));
                        }
                    }
                    $wuAllLines = array_merge($wuLines, array_values($wuExistingLines));
                    if (count($wuAllLines) > 2000) {
                        $wuAllLines = array_slice($wuAllLines, 0, 2000);
                    }
                    @file_put_contents($wuAttrLogPath, implode("\n", $wuAllLines) . "\n", LOCK_EX);
                }
            } catch (\Throwable $wuAttrEx) {
                // non-fatal
            }
        }
        // ── End Win Universe entry-attribution log ──────────────────────────

        // Rebuild rejection_reason_stats to include any slot_priority_lost rejections added above.
        $reasonStats = [];
        foreach ($result['rejection_reasons'] as $r) {
            $reason = $r['reason'] ?? 'unknown';
            $reasonStats[$reason] = ($reasonStats[$reason] ?? 0) + 1;
        }
        $result['rejection_reason_stats'] = $reasonStats;

        // ── Lifecycle-aware merge + cleanup ────────────────────────────────
        // Instead of overwriting live_intents.json, we:
        // 1. Load existing intents (preserve claimed, fresh pending from other runs)
        // 2. Add new pending intents
        // 3. Expire stale pending intents
        // 4. Clean old terminal intents beyond retention window
        $liveIntentsPath = $this->state->resolvePath('storage/live_intents.json');
        $existingData = [];
        if (is_file($liveIntentsPath)) {
            $raw = @file_get_contents($liveIntentsPath);
            if ($raw !== false) {
                $existingData = @json_decode($raw, true);
                if (!is_array($existingData)) {
                    $existingData = [];
                }
            }
        }

        $existingIntents = $existingData['intents'] ?? [];
        if (!is_array($existingIntents)) {
            $existingIntents = [];
        }

        // Index new intents by intent_id for fast lookup
        $newIntentIds = [];
        foreach ($intents as $ni) {
            $nid = $ni['intent_id'] ?? '';
            if ($nid !== '') {
                $newIntentIds[$nid] = true;
            }
        }

        $now = time();
        $retentionCutoff = $now - (SmartBrainConfig::LIVE_INTENT_CLEANUP_RETENTION_MINUTES * 60);
        $lifecycleCounters = [
            'preserved_claimed' => 0,
            'preserved_pending' => 0,
            'expired_by_brain' => 0,
            'cleaned_terminal' => 0,
            'superseded_terminal_by_new_pending' => 0,
            'new_pending' => count($intents),
        ];

        // Merge: keep non-superseded, non-stale existing intents
        $mergedIntents = [];
        foreach ($existingIntents as $ei) {
            $eid = $ei['intent_id'] ?? '';
            $status = $ei['status'] ?? 'pending';
            $expiresAt = (int)($ei['expires_at'] ?? 0);
            $createdTs = (int)($ei['created_ts'] ?? 0);

            // Skip if new Brain run produced a replacement for this intent
            if ($eid !== '' && isset($newIntentIds[$eid])) {
                if ($status === SmartBrainConfig::INTENT_STATUS_PENDING) {
                    continue; // will be replaced by the new version
                }
                if (SmartBrainConfig::isTerminalIntentStatus($status)) {
                    // Terminal intent (executed/rejected/expired) with same deterministic ID:
                    // the new Brain run produced a fresh pending for the same signal lineage.
                    // Drop the old terminal so the new pending can be inserted without ID collision.
                    $lifecycleCounters['superseded_terminal_by_new_pending']++;
                    continue;
                }
                // Claimed: bot is actively working on it — keep existing, block new via mergedIds check.
                unset($newIntentIds[$eid]);
            }

            // Expire stale pending intents
            if ($status === SmartBrainConfig::INTENT_STATUS_PENDING && $expiresAt > 0 && $expiresAt <= $now) {
                $ei['status'] = SmartBrainConfig::INTENT_STATUS_EXPIRED;
                $ei['expired_at'] = date('c');
                $ei['expired_by'] = 'brain_cleanup';
                $lifecycleCounters['expired_by_brain']++;
            }

            // Clean old terminal intents beyond retention window
            $eStatus = $ei['status'] ?? 'pending';
            if (SmartBrainConfig::isTerminalIntentStatus($eStatus)) {
                $terminalTs = max(
                    (int)($ei['executed_at_ts'] ?? 0),
                    (int)($ei['rejected_at_ts'] ?? 0),
                    strtotime($ei['expired_at'] ?? '1970-01-01') ?: 0,
                    $createdTs
                );
                if ($terminalTs > 0 && $terminalTs < $retentionCutoff) {
                    $lifecycleCounters['cleaned_terminal']++;
                    continue; // drop from merged list
                }
            }

            // Preserve
            if ($eStatus === SmartBrainConfig::INTENT_STATUS_CLAIMED) {
                $lifecycleCounters['preserved_claimed']++;
            } elseif ($eStatus === SmartBrainConfig::INTENT_STATUS_PENDING) {
                $lifecycleCounters['preserved_pending']++;
            }

            $mergedIntents[] = $ei;
        }

        // Add new pending intents (skip any whose ID already exists in merged set as non-pending)
        $mergedIds = [];
        foreach ($mergedIntents as $mi) {
            $mid = $mi['intent_id'] ?? '';
            if ($mid !== '') {
                $mergedIds[$mid] = true;
            }
        }
        foreach ($intents as $ni) {
            $nid = $ni['intent_id'] ?? '';
            if ($nid !== '' && isset($mergedIds[$nid])) {
                continue; // already preserved from existing (non-pending state)
            }
            $mergedIntents[] = $ni;
        }

        // Build lifecycle summary counts
        $statusCounts = ['pending' => 0, 'claimed' => 0, 'executed' => 0, 'rejected' => 0, 'expired' => 0];
        foreach ($mergedIntents as $mi) {
            $s = $mi['status'] ?? 'pending';
            if (isset($statusCounts[$s])) {
                $statusCounts[$s]++;
            }
        }

        // Write merged live_intents.json atomically
        $payload = [
            'schema_version' => 'live_intents_v1',
            'generated_at' => date('c'),
            'live_trading_enabled' => true,
            'brain_controlled_live_mode' => true,
            'live_stage_runtime_signature' => self::LIVE_STAGE_VERSION,
            'effective_live_config' => $liveConfig,
            'intent_ttl_minutes' => SmartBrainConfig::LIVE_INTENT_TTL_MINUTES,
            'lifecycle_summary' => [
                'total' => count($mergedIntents),
                'pending' => $statusCounts['pending'],
                'claimed' => $statusCounts['claimed'],
                'executed' => $statusCounts['executed'],
                'rejected' => $statusCounts['rejected'],
                'expired' => $statusCounts['expired'],
            ],
            'intents' => $mergedIntents,
        ];

        $writeOk = SmartBrainConfig::atomicUpdateLiveIntents($liveIntentsPath, function() use ($payload) {
            return $payload;
        });
        if (!$writeOk) {
            // Fallback: non-atomic write
            $this->state->writeJson('storage/live_intents.json', $payload);
        }

        $result['intents_written'] = $result['intents_created'];
        $result['intents_total_after_merge'] = count($mergedIntents);
        $result['terminal_retained_count'] = count($mergedIntents) - ($statusCounts['pending'] ?? 0) - ($statusCounts['claimed'] ?? 0);
        $result['lifecycle_counters'] = $lifecycleCounters;
        $result['lifecycle_summary'] = $payload['lifecycle_summary'];

        if (count($intents) > 0) {
            $this->logger->log('info', 'Live Intents: generated ' . count($intents) . ' new pending, merged total ' . count($mergedIntents) . ' (mode=' . $selectionMode . ', claimed_preserved=' . $lifecycleCounters['preserved_claimed'] . ', superseded_terminal=' . $lifecycleCounters['superseded_terminal_by_new_pending'] . ', expired=' . $lifecycleCounters['expired_by_brain'] . ', cleaned=' . $lifecycleCounters['cleaned_terminal'] . ', terminal_retained=' . $result['terminal_retained_count'] . ')');
        } elseif ($result['signals_seen'] > 0) {
            $lateCount = $result['late_entry_rejected_count'] ?? 0;
            $this->logger->log('info', 'Live Intents: 0 new intents from ' . $result['signals_seen'] . ' signals, merged total ' . count($mergedIntents) . ' (approved=' . $result['approved_count'] . ', rejected=' . $result['rejected_count'] . ', late_entry_rejected=' . $lateCount . ', terminal_retained=' . $result['terminal_retained_count'] . ', reasons=' . json_encode($reasonStats) . ')');
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
                // Live mode filter observability (req. WU-5 §7)
                'live_mode_filter_passed' => false,
                'live_mode_filter_mode'   => ($reason === 'live_mode_filter_rejected') ? $selectionMode : null,
            ];
        }
    }

    /**
     * Compute a bounded, explicit slot priority score for a live intent candidate.
     *
     * Score components (total cap: 100):
     *   signal_strength   → 0–25 pts
     *   quality_score     → 0–25 pts
     *   scenario_score    → 0–15 pts
     *   passport state    → 0–15 pts  (allow_live=15, bootstrap_live=5)
     *   cycle support     → 0–10 pts  (support+10, no-veto+5, veto=0)
     *   freshness decay   → 0–10 pts  (linear decay within freshness window)
     *
     * Buckets: high (>=70), medium (40–69), low (<40).
     *
     * @param array $intent                  The approved live intent record.
     * @param bool  $freshnessDecayEnabled   Whether time-decay component is active.
     * @param int   $freshnessWindowMinutes  Window in minutes within which signals are "fresh".
     * @return array{score:float,bucket:string,reason:string}
     */
    private function computeSlotPriorityScore(array $intent, bool $freshnessDecayEnabled, int $freshnessWindowMinutes): array
    {
        $score   = 0.0;
        $factors = [];

        // ── Signal strength (0–25 pts) ────────────────────────────────────
        $signalStrength = min(1.0, max(0.0, (float)($intent['signal_strength'] ?? 0.0)));
        $ssPoints       = round($signalStrength * 25.0, 2);
        $score         += $ssPoints;
        if ($ssPoints > 0) {
            $factors[] = 'signal_strength+' . $ssPoints;
        }

        // ── Entry quality score (0–25 pts) ───────────────────────────────
        $qualityScore = min(1.0, max(0.0, (float)($intent['quality_score'] ?? 0.0)));
        $qsPoints     = round($qualityScore * 25.0, 2);
        $score       += $qsPoints;
        if ($qsPoints > 0) {
            $factors[] = 'quality_score+' . $qsPoints;
        }

        // ── Scenario / V2 priority score (0–15 pts) ──────────────────────
        $scenarioScore = min(1.0, max(0.0, (float)($intent['scenario_score'] ?? 0.0)));
        $scPoints      = round($scenarioScore * 15.0, 2);
        $score        += $scPoints;
        if ($scPoints > 0) {
            $factors[] = 'scenario_score+' . $scPoints;
        }

        // ── Passport live eligibility state (0–15 pts) ───────────────────
        $passportResult = (string)($intent['passport_gate_result'] ?? 'not_applied');
        if ($passportResult === 'allow_live') {
            $score    += 15.0;
            $factors[] = 'passport:allow_live+15';
        } elseif ($passportResult === 'bootstrap_live') {
            $score    += 5.0;
            $factors[] = 'passport:bootstrap_live+5';
        }

        // ── Cycle model layer (0–10 pts) ─────────────────────────────────
        if ((bool)($intent['cycle_model_support_applied'] ?? false)) {
            $score    += 10.0;
            $factors[] = 'cycle_support+10';
        } elseif (!(bool)($intent['cycle_model_veto_applied'] ?? false)) {
            $score    += 5.0;
            $factors[] = 'no_cycle_veto+5';
        } else {
            $factors[] = 'cycle_veto_detected';
        }

        // ── Freshness decay (0–10 pts) ────────────────────────────────────
        // Signals generated recently score higher than older ones.
        // Uses the signal's created_ts if available; otherwise treat as fully fresh.
        if ($freshnessDecayEnabled) {
            $createdTs            = (int)($intent['created_ts'] ?? time());
            $ageSeconds           = max(0, time() - $createdTs);
            $windowSeconds        = $freshnessWindowMinutes * 60;
            $freshnessRatio       = max(0.0, 1.0 - ($ageSeconds / max(1, $windowSeconds)));
            $freshnessPoints      = round($freshnessRatio * 10.0, 2);
            $score               += $freshnessPoints;
            if ($freshnessPoints > 0) {
                $factors[] = 'freshness+' . $freshnessPoints . '(age=' . $ageSeconds . 's)';
            } else {
                $factors[] = 'freshness:stale(age=' . $ageSeconds . 's)';
            }
        }

        // ── Wave penalty (bounded deduction for weak/slow wave candidates) ──
        // Pre-computed by the Wave Penalty Layer before this scoring call.
        // weak amplitude only: -5 pts, slow wave only: -5 pts,
        // weak amplitude + slow wave: -10 pts. Bounded to [0, 10].
        $wavePenalty = min(10.0, max(0.0, (float)($intent['wave_penalty_value'] ?? 0.0)));
        if ($wavePenalty > 0.0) {
            $score    -= $wavePenalty;
            $factors[] = 'wave_penalty-' . $wavePenalty . '(' . ($intent['wave_penalty_reason'] ?? 'wave_penalty') . ')';
        }

        // ── Ranking boost (bounded positive boost for strong/fresh/clean candidates) ──
        // Pre-computed by the Ranking Boost Layer. Rewards quality, freshness, cycle
        // support, and wave cleanliness. Bounded to [0, 10].
        $rankingBoost = min(10.0, max(0.0, (float)($intent['ranking_boost_value'] ?? 0.0)));
        if ($rankingBoost > 0.0) {
            $score    += $rankingBoost;
            $factors[] = 'ranking_boost+' . $rankingBoost . '(' . ($intent['ranking_boost_reason'] ?? 'ranking_boost') . ')';
        }

        // ── Win Universe bonus (soft priority for qualified win-pool symbols) ──
        // Pre-computed by the Win Universe Bonus Layer. Only non-zero when
        // win_universe_mode=priority, priority_bonus_enabled=true, and symbol is in pool.
        // Bounded to [0, 10]. Does NOT bypass any hard gates.
        $wuBonus = min(10.0, max(0.0, (float)($intent['win_universe_bonus_value'] ?? 0.0)));
        if ($wuBonus > 0.0) {
            $score    += $wuBonus;
            $factors[] = 'win_universe_bonus+' . $wuBonus . '(' . ($intent['win_universe_bonus_reason'] ?? 'win_pool') . ')';
        }

        // ── Cap and bucket ────────────────────────────────────────────────
        $score  = min(100.0, max(0.0, round($score, 2)));
        $bucket = $score >= 70.0 ? 'high' : ($score >= 40.0 ? 'medium' : 'low');
        $reason = implode(', ', $factors) ?: 'no_factors';

        return ['score' => $score, 'bucket' => $bucket, 'reason' => $reason];
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

        // Trailing mode: roi_giveback (default) or price_distance or price_distance_floor
        $trailingMode = (string)($userLimits['trailing_mode'] ?? 'roi_giveback');
        if (!in_array($trailingMode, ['roi_giveback', 'price_distance', 'price_distance_floor'], true)) {
            $trailingMode = 'roi_giveback';
        }

        // Price-distance trailing: fixed pct from current price (ratio, 0.02 = 2%)
        $trailingPriceDistancePct = (float)($userLimits['trailing_price_distance_pct'] ?? 0.02);
        // Validate bounds: min 0.005 (0.5%), max 0.20 (20%)
        if ($trailingPriceDistancePct < 0.005) { $trailingPriceDistancePct = 0.005; }
        if ($trailingPriceDistancePct > 0.20) { $trailingPriceDistancePct = 0.20; }

        // Price-distance-floor specific fields
        $rawFloorActivation = (float)($userLimits['trailing_activation_floor_roi'] ?? 0.04);
        $rawFloorLockRoi    = (float)($userLimits['trailing_floor_lock_roi'] ?? 0.03);
        // Convert ratio→percent if needed
        $floorActivationPct = ($rawFloorActivation > 0 && $rawFloorActivation < 1.0) ? $rawFloorActivation * 100 : $rawFloorActivation;
        $floorLockRoiPct    = ($rawFloorLockRoi > 0 && $rawFloorLockRoi < 1.0) ? $rawFloorLockRoi * 100 : $rawFloorLockRoi;
        // Validate: floor lock must be less than or equal to floor activation
        if ($floorLockRoiPct > $floorActivationPct && $floorActivationPct > 0) {
            $floorLockRoiPct = $floorActivationPct * 0.75;
        }

        // ROI-based trailing preset resolution (canonical Brain contract)
        $trailingPresetMode = (string)($userLimits['trailing_preset_mode'] ?? 'custom');
        if (!in_array($trailingPresetMode, ['soft', 'medium', 'hard', 'custom'], true)) {
            $trailingPresetMode = 'custom';
        }
        $trailingPresets = [
            'soft'   => ['activation_roi' => 2.0, 'floor_lock_roi' => 2.0, 'distance_roi' => 0.5],
            'medium' => ['activation_roi' => 3.0, 'floor_lock_roi' => 3.0, 'distance_roi' => 0.8],
            'hard'   => ['activation_roi' => 4.0, 'floor_lock_roi' => 4.0, 'distance_roi' => 1.0],
        ];
        $trailingDistanceRoi = null;
        $presetContractSource = 'brain_custom';
        if ($trailingPresetMode !== 'custom' && isset($trailingPresets[$trailingPresetMode])) {
            $preset = $trailingPresets[$trailingPresetMode];
            $floorActivationPct = (float)$preset['activation_roi'];
            $floorLockRoiPct    = (float)$preset['floor_lock_roi'];
            $trailingDistanceRoi = (float)$preset['distance_roi'];
            $presetContractSource = 'brain_preset';
        } else {
            // Custom mode: use explicit distance_roi if set
            $rawDistanceRoi = (float)($userLimits['trailing_distance_roi'] ?? 0);
            if ($rawDistanceRoi > 0) {
                $trailingDistanceRoi = $rawDistanceRoi;
            }
        }
        $trailingStepMode = (string)($userLimits['trailing_step_mode'] ?? 'fixed');
        if (!in_array($trailingStepMode, ['fixed', 'auto_strength', 'fixed_roi_ladder', 'trend_reversal_soft_ladder_short'], true)) {
            $trailingStepMode = 'fixed';
        }
        $trailingStepPctMin = (float)($userLimits['trailing_step_pct_min'] ?? 0.005);
        $trailingStepPctMax = (float)($userLimits['trailing_step_pct_max'] ?? 0.02);
        if ($trailingStepPctMin < 0.001) { $trailingStepPctMin = 0.001; }
        if ($trailingStepPctMax < $trailingStepPctMin) { $trailingStepPctMax = $trailingStepPctMin; }
        if ($trailingStepPctMax > 0.10) { $trailingStepPctMax = 0.10; }
        $trailingStepRoi = max(0.1, min(20.0, (float)($userLimits['trailing_step_roi'] ?? 1.5)));

        $botReady['trailing'] = [
            'enabled' => $trailingEnabled,
            'trailing_mode' => $trailingMode,
            'trailing_preset_mode' => $trailingPresetMode,
            'trailing_distance_roi' => $trailingDistanceRoi,
            'trailing_contract_source' => $presetContractSource,
            'activation_roi_pct' => $activationPct,
            'drawdown_factor' => 0.5,
            'trailing_price_distance_pct' => $trailingPriceDistancePct,
            'trailing_activation_floor_roi' => $floorActivationPct,
            'trailing_floor_lock_roi' => $floorLockRoiPct,
            'trailing_step_mode' => $trailingStepMode,
            'trailing_step_pct_min' => $trailingStepPctMin,
            'trailing_step_pct_max' => $trailingStepPctMax,
            'trailing_step_roi' => $trailingStepRoi,
            'min_step' => (float)($userLimits['trailing_min_step'] ?? 0.01),
            'min_lock_roi' => (float)($userLimits['trailing_min_lock_roi'] ?? 0.012),
            'break_even_enabled' => (bool)($userLimits['break_even_enabled'] ?? false),
            'break_even_activation_roi' => $breakEvenActivationPct,
            'exit_mode' => (string)($userLimits['exit_mode'] ?? 'hybrid_tp'),
            'fixed_take_profit_roi' => (float)($userLimits['fixed_take_profit_roi'] ?? 0.03),
            'hybrid_tp_share' => (float)($userLimits['hybrid_tp_share'] ?? 0.40),
            'brain_trailing_applied' => true,
            'profit_addon_enabled' => !empty($userLimits['profit_addon_enabled']),
            'profit_addon_budget_pct' => max(0.0, min(500.0, (float)($userLimits['profit_addon_budget_pct'] ?? 0.0))),
            'unit_system' => 'activation_pct=percent,drawdown_factor=ratio,trailing_price_distance_pct=ratio,floor_activation=percent,floor_lock=percent,step_pct=ratio,min_step=ratio,min_lock_roi=ratio,fixed_tp_roi=ratio,hybrid_share=ratio',
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
     * Cleanup Pass 2: structurally separated into active_trailing_contract + legacy block.
     *
     * @param array $userLimits User limits from config
     * @return array Effective trailing contract summary (mode-aware, active fields only in main block)
     */
    private function buildEffectiveTrailingContractSummary(array $userLimits): array
    {
        $rawBreakEvenActivation = (float)($userLimits['break_even_activation_roi'] ?? 0.025);
        $trailingMode = (string)($userLimits['trailing_mode'] ?? 'roi_giveback');

        // Shared fields across all modes
        $contract = [
            'trailing_enabled' => (bool)($userLimits['trailing_enabled'] ?? false),
            'trailing_mode' => $trailingMode,
            'break_even_enabled' => (bool)($userLimits['break_even_enabled'] ?? false),
            'break_even_activation_roi' => $rawBreakEvenActivation,
            'break_even_activation_roi_pct' => ($rawBreakEvenActivation > 0 && $rawBreakEvenActivation < 1.0) ? $rawBreakEvenActivation * 100 : $rawBreakEvenActivation,
            'exit_mode' => (string)($userLimits['exit_mode'] ?? 'hybrid_tp'),
            'fixed_take_profit_roi' => (float)($userLimits['fixed_take_profit_roi'] ?? 0.03),
            'hybrid_tp_share' => (float)($userLimits['hybrid_tp_share'] ?? 0.40),
            'logical_stop_roi' => (float)($userLimits['logical_stop_roi'] ?? 0.03),
            'canonical_source' => 'brain_user_limits',
        ];

        // Active trailing fields: only those relevant to the selected mode
        switch ($trailingMode) {
            case 'price_distance_floor':
                $contract['trailing_activation_floor_roi'] = (float)($userLimits['trailing_activation_floor_roi'] ?? 0.04);
                $contract['trailing_floor_lock_roi'] = (float)($userLimits['trailing_floor_lock_roi'] ?? 0.03);
                $contract['trailing_price_distance_pct'] = (float)($userLimits['trailing_price_distance_pct'] ?? 0.02);
                $contract['trailing_step_mode'] = (string)($userLimits['trailing_step_mode'] ?? 'fixed');
                $contract['trailing_step_pct_min'] = (float)($userLimits['trailing_step_pct_min'] ?? 0.005);
                $contract['trailing_step_pct_max'] = (float)($userLimits['trailing_step_pct_max'] ?? 0.02);
                $contract['trailing_step_roi'] = max(0.1, (float)($userLimits['trailing_step_roi'] ?? 1.5));
                break;
            case 'price_distance':
                $rawActivation = (float)($userLimits['trailing_activation_roi'] ?? 0.05);
                $contract['trailing_activation_roi'] = $rawActivation;
                $contract['trailing_activation_roi_pct'] = ($rawActivation > 0 && $rawActivation < 1.0) ? $rawActivation * 100 : $rawActivation;
                $contract['trailing_price_distance_pct'] = (float)($userLimits['trailing_price_distance_pct'] ?? 0.02);
                break;
            case 'roi_giveback':
            default:
                $rawActivation = (float)($userLimits['trailing_activation_roi'] ?? 0.05);
                $contract['trailing_activation_roi'] = $rawActivation;
                $contract['trailing_activation_roi_pct'] = ($rawActivation > 0 && $rawActivation < 1.0) ? $rawActivation * 100 : $rawActivation;
                $contract['trailing_min_lock_roi'] = (float)($userLimits['trailing_min_lock_roi'] ?? 0.012);
                $contract['trailing_min_step'] = (float)($userLimits['trailing_min_step'] ?? 0.01);
                $contract['drawdown_factor'] = 0.5;
                break;
        }

        // Legacy indicator: whether old-mode fields are still present in user config
        $hasLegacy = ($trailingMode !== 'roi_giveback') && (
            isset($userLimits['trailing_activation_roi']) ||
            isset($userLimits['trailing_min_lock_roi']) ||
            isset($userLimits['trailing_min_step'])
        );
        $contract['legacy_trailing_fields_present'] = $hasLegacy;

        return $contract;
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
     * Get the human-readable label for the active execution profile.
     *
     * @param array<string,mixed> $userLimits
     * @return string
     */
    private static function getExecutionProfileLabel(array $userLimits): string
    {
        $profileId = (string)($userLimits['execution_profile'] ?? 'custom');
        $bundles = SmartBrainConfig::getExecutionProfileBundles();
        return $bundles[$profileId]['label'] ?? 'Custom';
    }

    /**
     * Resolve effective pattern policy for last_run, ensuring non-null arrays always.
     *
     * @param array<string,mixed> $userLimits
     * @param SmartBrainConfig $config
     * @return array{live_patterns:list<string>,shadow_patterns:list<string>,disabled_patterns:list<string>,canonical_source:string,fallback_used:bool,fallback_reason:string}
     */
    private static function resolvePatternPolicy(array $userLimits, SmartBrainConfig $config): array
    {
        $profileId = (string)($userLimits['execution_profile'] ?? 'custom');
        $patternMode = (string)($userLimits['pattern_profile_mode'] ?? 'manual_override');
        $routingBundles = SmartBrainConfig::getProfilePatternRoutingBundles();

        // Profile-controlled mode with a known preset
        if ($profileId !== 'custom' && $patternMode === 'profile_controlled' && isset($routingBundles[$profileId])) {
            $bundle = $routingBundles[$profileId];
            return [
                'live_patterns' => array_values((array)($bundle['live_patterns'] ?? [])),
                'shadow_patterns' => array_values((array)($bundle['shadow_patterns'] ?? [])),
                'disabled_patterns' => array_values((array)($bundle['disabled_patterns'] ?? [])),
                'canonical_source' => 'execution_profile',
                'fallback_used' => false,
                'fallback_reason' => '',
            ];
        }

        // Manual override or custom profile: derive from current enabled patterns
        $enabledPatterns = $config->getEnabledPatterns();
        $allPatterns = SmartBrainConfig::getAllowedPatternAlgorithms();

        if (!empty($enabledPatterns)) {
            return [
                'live_patterns' => array_values($enabledPatterns),
                'shadow_patterns' => [],
                'disabled_patterns' => array_values(array_diff($allPatterns, $enabledPatterns)),
                'canonical_source' => 'manual_override',
                'fallback_used' => false,
                'fallback_reason' => '',
            ];
        }

        // Manual override with missing/empty lists: fall back to profile preset if available
        if ($profileId !== 'custom' && isset($routingBundles[$profileId])) {
            $bundle = $routingBundles[$profileId];
            return [
                'live_patterns' => array_values((array)($bundle['live_patterns'] ?? [])),
                'shadow_patterns' => array_values((array)($bundle['shadow_patterns'] ?? [])),
                'disabled_patterns' => array_values((array)($bundle['disabled_patterns'] ?? [])),
                'canonical_source' => 'profile_fallback',
                'fallback_used' => true,
                'fallback_reason' => 'manual_override_missing_lists',
            ];
        }

        // Ultimate fallback: empty but non-null
        return [
            'live_patterns' => [],
            'shadow_patterns' => [],
            'disabled_patterns' => $allPatterns,
            'canonical_source' => 'default_fallback',
            'fallback_used' => true,
            'fallback_reason' => 'no_patterns_configured',
        ];
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
            'config_source_status' => $this->state->readJson('runtime/config_source_status.json', []),
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
            'manual_blacklist' => $this->config->loadManualBlacklist(),
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
     * Save manual live blacklist.
     *
     * @param list<string> $symbols
     * @return array{ok:bool,count:int,symbols:list<string>}
     */
    public function saveManualBlacklist(array $symbols): array
    {
        return $this->config->saveManualBlacklist($symbols);
    }

    /**
     * Load manual live blacklist.
     *
     * @return array{symbols:list<string>,count:int,valid:bool,warning:string}
     */
    public function loadManualBlacklist(): array
    {
        return $this->config->loadManualBlacklist();
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
        // Read from the standalone coin_passport module — single source of truth.
        $passportsDir = dirname($this->moduleBase) . '/coin_passport/storage/passports';
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

        // Read passports from the standalone coin_passport module — single source of truth.
        $passportsDir = dirname($this->moduleBase) . '/coin_passport/storage/passports';
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

        // Read passport for this symbol from the standalone coin_passport module (single source of truth).
        $passportPath = '../coin_passport/storage/passports/' . $symbol . '.json';
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

    /**
     * Compute V2 what-if analysis for entry zone tuning.
     *
     * Compares current behavior with hypothetical scenarios:
     * 1. Current mode (actual results)
     * 2. Enter-now: full corridor as entry zone
     * 3. Wider zone: 0.85 entry zone percent
     * 4. Moderate widen: 0.70 entry zone percent
     *
     * @param array<string,array<string,mixed>> $v2Funnel
     * @return array<string,mixed>
     */
    private function computeV2WhatIfAnalysis(array $v2Funnel): array
    {
        $analysis = [];
        foreach ($v2Funnel as $algo => $funnel) {
            $monitors = (int)($funnel['monitors_count'] ?? 0);
            $currentEntryZone = (int)($funnel['entry_zone_count'] ?? 0);
            $currentSignals = (int)($funnel['signals_count'] ?? 0);
            $whatifEnterNow = (int)($funnel['whatif_enter_now_would_signal'] ?? 0);
            $whatifWiderZone = (int)($funnel['whatif_wider_zone_would_signal'] ?? 0);
            $monitoring = (int)($funnel['monitoring_count'] ?? 0);
            $invalidated = (int)($funnel['invalidated_count'] ?? 0);
            $signalsByTier = (array)($funnel['signals_by_tier'] ?? []);

            $analysis[$algo] = [
                'monitors_total' => $monitors,
                'scenarios' => [
                    'current' => [
                        'label' => 'Current tier-based entry policy',
                        'entry_zone_count' => $currentEntryZone,
                        'signals_count' => $currentSignals,
                        'conversion_rate' => $monitors > 0 ? round($currentSignals / $monitors, 4) : 0.0,
                        'stalled_monitoring' => $monitoring,
                        'invalidated' => $invalidated,
                        'signals_by_tier' => $signalsByTier,
                    ],
                    'enter_now_hypothetical' => [
                        'label' => 'If enter_now (full corridor)',
                        'additional_entry_zone' => $whatifEnterNow,
                        'potential_signals_gain' => $whatifEnterNow,
                        'estimated_total_signals' => $currentSignals + $whatifEnterNow,
                        'estimated_conversion_rate' => $monitors > 0 ? round(($currentSignals + $whatifEnterNow) / $monitors, 4) : 0.0,
                    ],
                    'wider_zone_085' => [
                        'label' => 'If entry zone 85%',
                        'additional_entry_zone' => $whatifWiderZone,
                        'potential_signals_gain' => $whatifWiderZone,
                        'estimated_total_signals' => $currentSignals + $whatifWiderZone,
                        'estimated_conversion_rate' => $monitors > 0 ? round(($currentSignals + $whatifWiderZone) / $monitors, 4) : 0.0,
                    ],
                    'tier_policy_strong_enter_now' => [
                        'label' => 'Strong→enter_now, Medium→wait_retrace, Weak→conservative',
                        'strong_signals' => (int)($signalsByTier['strong'] ?? 0),
                        'medium_signals' => (int)($signalsByTier['medium'] ?? 0),
                        'weak_signals' => (int)($signalsByTier['weak'] ?? 0),
                        'strong_would_enter_now' => (int)($signalsByTier['strong'] ?? 0),
                        'description' => 'Strong tier uses widest zone + enter_now; medium uses moderate zone; weak uses narrow zone.',
                    ],
                    'widening_only' => [
                        'label' => 'Score-driven zone widening only (no action change)',
                        'description' => 'All tiers use wait_retrace but zone width is tier-dependent.',
                        'signals_count' => $currentSignals,
                    ],
                ],
                'diagnostics' => [
                    'avg_price_position' => (float)($funnel['avg_price_position'] ?? 0),
                    'avg_zone_width_pct' => (float)($funnel['avg_zone_width_pct'] ?? 0),
                    'avg_zone_distance' => (float)($funnel['avg_zone_distance'] ?? 0),
                    'avg_confirmation_score' => (float)($funnel['avg_confirmation_score'] ?? 0),
                    'confirmation_score_min' => (float)($funnel['confirmation_score_min'] ?? 0),
                    'confirmation_score_max' => (float)($funnel['confirmation_score_max'] ?? 0),
                    'confirmation_score_buckets' => $funnel['confirmation_score_buckets'] ?? [],
                    'confirmation_score_zero_on_confirmed_count' => (int)($funnel['confirmation_score_zero_on_confirmed_count'] ?? 0),
                    'confirmation_score_total_monitors' => (int)($funnel['confirmation_score_total_monitors'] ?? 0),
                    'confirmation_score_flat_warning' => (bool)($funnel['confirmation_score_flat_warning'] ?? false),
                    'reject_detail_distribution' => $funnel['reject_detail_distribution'] ?? [],
                    'component_score_averages' => $funnel['component_score_averages'] ?? [],
                ],
            ];
        }

        return $analysis;
    }
}
