<?php

declare(strict_types=1);

/**
 * Dynamic Strategies — Strategy
 *
 * Consumes useful rejected/failed/diagnostic contexts from ordinary strategies
 * (e.g. double_bottom_long) and generates short-watch shadow candidates and demo signals.
 *
 * Pipeline:
 *   1. load config
 *   2. load source adapters → collect contexts from source strategy artifacts
 *   3. merge collected contexts with any externally-written input_contexts.json
 *   4. write merged result back to input_contexts.json (internal cache)
 *   5. filter stale + dedupe contexts
 *   6. classify contexts (useful vs noise)
 *   7. group by symbol
 *   8. evaluate dynamic rules per symbol
 *   9. score candidates
 *  10. filter candidates by side_mode (short / long / all)
 *  11. build signals from high-confidence candidates
 *  12. build bot_handoff_queue (executable when handoff_enabled + emit_bot_handoff + threshold + entry_price pass)
 *  13. write all storage files + last_run.json
 *
 * ARCHITECTURE:
 *   - Strategies are environment-neutral: they emit trading signals only.
 *   - Bot environment owns execution mode (demo/live) based on its own config.
 *   - handoff_enabled = false → no handoff queue entries (observation-only mode)
 *   - handoff_enabled = true + emit_bot_handoff = true → executable handoff when thresholds pass
 *   - side_mode = short/long/all → gates which candidate sides proceed to signals
 *   - Config keys mode/live_enabled/live_handoff_enabled are deprecated as execution gates
 *     (kept for backward compat; ignored for handoff gating)
 *   - Does NOT scan market data directly
 *   - Does NOT modify source strategy storage
 *   - Does NOT open real orders
 *   - Source adapters are read-only consumers of existing artifacts
 */

namespace Modules\Strategy\DynamicStrategies;

// file names are lowercase by project convention; PHP class names remain CamelCase
require_once __DIR__ . '/sources/dynamic_source_adapter_interface.php';
require_once __DIR__ . '/sources/abstract_dynamic_source_adapter.php';
require_once __DIR__ . '/sources/double_bottom_long_source_adapter.php';

use Modules\Strategy\DynamicStrategies\Sources\DynamicSourceAdapterInterface;
use Modules\Strategy\DynamicStrategies\Sources\DoubleBottomLongSourceAdapter;

final class DynamicStrategiesStrategy
{
    private const STRATEGY_ID = 'dynamic_strategies';
    private const VERSION     = '0.1.0';

    // Context types that produce nothing useful by themselves
    private const NOISE_CONTEXT_TYPES = [
        'symbol_freeze',
        'symbol_blacklist',
        'temporary_data_error',
        'missing_market_data',
        'low_quality_noise',
        'registry_skip',
        'scan_suppressed',
        'too_young',
        'outside_watch_window',
        'stale_lifecycle',
    ];

    // Context types that directly map to dynamic rules
    private const RULE_CONTEXT_MAP = [
        'falling_knife_short_watch' => [
            'falling_knife_reject',
            'active_downtrend_no_stabilization',
        ],
        'failed_reclaim_short_watch' => [
            'failed_reclaim',
            'reclaim_lost',
            'neckline_lost',
            'pending_invalidated_reclaim_lost',
        ],
        'base_breakdown_short_watch' => [
            'base_support_broken',
            'pending_invalidated_base_support_broken',
        ],
        'failed_pending_breakdown_short_watch' => [
            'pending_invalidated_fresh_dump',
            'pending_invalidated_reclaim_lost',
            'pending_invalidated_base_support_broken',
            'pending_invalidated_falling_knife',
        ],
        'failed_long_after_entry_short_watch' => [
            'early_fail_closed',
            'emergency_stop_closed',
            'deep_loss_closed',
            'missed_early_fail',
        ],
        'late_exhaustion_short_watch' => [
            'late_exhaustion_detected',
            'late_exhaustion_warning',
        ],
    ];

    // Directional context types eligible for rejected-context short replay analysis.
    // These represent breakdown/downtrend signals that could indicate short entries.
    private const DIRECTIONAL_CONTEXT_TYPES = [
        'active_downtrend_no_stabilization',
        'falling_knife_reject',
        'fresh_dump_after_reclaim',
        'fresh_lower_low_after_reclaim',
        'fast_red_candle_after_setup',
        'failed_reclaim',
        'reclaim_lost',
        'neckline_lost',
        'base_support_broken',
        'pending_invalidated_fresh_dump',
        'pending_invalidated_reclaim_lost',
        'pending_invalidated_base_support_broken',
        'pending_invalidated_falling_knife',
    ];

    private string $moduleDir;
    private string $repoRoot;
    private array  $config;

    public function __construct(?string $moduleDir = null)
    {
        $resolved = $moduleDir !== null
            ? rtrim($moduleDir, '/')
            : rtrim(__DIR__, '/');

        // Ensure absolute path so file_exists/file_get_contents work regardless of CWD.
        if (!str_starts_with($resolved, '/')) {
            $resolved = rtrim(getcwd() . '/' . $resolved, '/');
        }
        $this->moduleDir = $resolved;

        // Repo root is 3 levels above modules/strategy/dynamic_strategies/
        $this->repoRoot = rtrim(dirname($this->moduleDir, 3), '/');

        $this->config = $this->loadConfig();
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Full evaluation cycle.
     * Strategy must be explicitly enabled in config; otherwise returns an error.
     */
    public function run(): array
    {
        if (!(bool)($this->config['enabled'] ?? false)) {
            return ['ok' => false, 'error' => 'strategy_disabled'];
        }

        return $this->execute();
    }

    // ── Core pipeline ─────────────────────────────────────────────────────────

    private function execute(): array
    {
        $startedAt  = date('c');
        $startMicro = microtime(true);
        $config     = $this->config;
        $now        = time();

        // ── OrderBook Wall Context service (optional; fails gracefully) ────────
        $obcService = null;
        $obcDir     = $this->repoRoot . '/modules/system/orderbook_context';
        if (is_file($obcDir . '/service.php')) {
            try {
                require_once $obcDir . '/service.php';
                $obcService = new \OrderBookContextService($obcDir);
            } catch (\Throwable) {
                $obcService = null;
            }
        }

        $shadowOnly          = (bool)($config['shadow_only']          ?? false);  // deprecated; kept for compat
        $handoffEnabled      = (bool)($config['handoff_enabled']       ?? true);
        // Deprecated execution-gate keys — read for backward compat but not used for handoff gating.
        // Execution mode is owned by Bot environment, not strategy config.
        $liveEnabled         = (bool)($config['live_enabled']          ?? false);   // deprecated gate
        $liveHandoffEnabled  = (bool)($config['live_handoff_enabled']  ?? false);   // deprecated gate
        $emitHandoff         = (bool)($config['emit_bot_handoff']      ?? true);
        $mode                = (string)($config['mode']                ?? 'demo');  // deprecated gate; kept for compat
        $sideMode            = (string)($config['side_mode']           ?? 'short');  // short | long | all
        // Track whether deprecated mode keys are present (for diagnostics)
        $deprecatedModeKeysSeen = isset($config['mode']) || isset($config['live_enabled']) || isset($config['live_handoff_enabled']);

        $maxAgeMinutes = (int)($config['context_max_age_minutes'] ?? 180);
        $maxContexts   = (int)($config['max_contexts_per_run']    ?? 100);

        // ── 1. Collect contexts from source adapters ───────────────────────────
        // Source adapters read existing artifacts from source strategies (read-only).
        // They never modify source strategy storage.
        $sourceStats = $this->collectSourceContexts($config);

        // ── 2. Load existing input_contexts.json (external or prior-run cache) ─
        $ctxFile = $this->repoRoot . '/' . ltrim((string)($config['input_contexts_file'] ?? ''), '/');
        if ($ctxFile === $this->repoRoot . '/') {
            $ctxFile = $this->moduleDir . '/storage/input_contexts.json';
        }

        $allContexts = $this->readJson($ctxFile, []);
        if (!is_array($allContexts)) {
            $allContexts = [];
        }

        // ── 3. Merge source adapter contexts with loaded contexts ──────────────
        // Adapter contexts take precedence for their own context_ids; existing
        // external contexts are preserved for backward compatibility.
        $adapterContexts = $sourceStats['all_contexts'] ?? [];
        if (!empty($adapterContexts)) {
            // Build index of existing context_ids for fast dedupe
            $existingIds = [];
            foreach ($allContexts as $ctx) {
                $cid = (string)($ctx['context_id'] ?? '');
                if ($cid !== '') {
                    $existingIds[$cid] = true;
                }
            }
            foreach ($adapterContexts as $ctx) {
                $cid = (string)($ctx['context_id'] ?? '');
                if ($cid === '' || !isset($existingIds[$cid])) {
                    $allContexts[] = $ctx;
                    if ($cid !== '') {
                        $existingIds[$cid] = true;
                    }
                }
            }
        }

        // ── 4. Write merged contexts back to input_contexts.json ───────────────
        // input_contexts.json is now Dynamic's internal context cache.
        // Cap before writing to avoid unbounded file growth.
        $writeContexts = $allContexts;
        $cacheMaxAge   = max($maxAgeMinutes, (int)($config['replay_context_max_age_minutes'] ?? 480));
        $writeCutoff   = $now - ($cacheMaxAge * 60);
        $writeContexts = array_values(array_filter($writeContexts, static function (array $ctx) use ($writeCutoff): bool {
            $oa = $ctx['observed_at'] ?? null;
            if ($oa === null) {
                return true; // keep if no timestamp
            }
            $ts = is_int($oa) ? $oa : (int)strtotime((string)$oa);
            return $ts <= 0 || $ts >= $writeCutoff;
        }));
        $cacheWriteMax = max(
            (int)($config['max_contexts_per_run'] ?? 100),
            (int)($config['replay_max_contexts']  ?? 500)
        ) * 2;
        if (count($writeContexts) > $cacheWriteMax) {
            // Keep most recent
            usort($writeContexts, static function (array $a, array $b): int {
                $ta = isset($a['observed_at']) ? (int)strtotime((string)$a['observed_at']) : 0;
                $tb = isset($b['observed_at']) ? (int)strtotime((string)$b['observed_at']) : 0;
                return $tb - $ta;
            });
            $writeContexts = array_slice($writeContexts, 0, $cacheWriteMax);
        }
        $this->writeJson('storage/input_contexts.json', array_values($writeContexts));

        $loadedTotal = count($allContexts);

        // ── 5. Filter stale contexts ───────────────────────────────────────────
        $cutoff      = $now - ($maxAgeMinutes * 60);
        $staleSkipped = 0;
        $recentContexts = [];
        foreach ($allContexts as $ctx) {
            if (!is_array($ctx)) {
                continue;
            }
            $observedAt = $ctx['observed_at'] ?? null;
            if ($observedAt !== null) {
                $ts = is_int($observedAt) ? $observedAt : (int)strtotime((string)$observedAt);
                if ($ts > 0 && $ts < $cutoff) {
                    $staleSkipped++;
                    continue;
                }
            }
            $recentContexts[] = $ctx;
        }

        // ── 6. Dedupe by context_id ────────────────────────────────────────────
        $seen      = [];
        $deduped   = [];
        foreach ($recentContexts as $ctx) {
            $cid = (string)($ctx['context_id'] ?? '');
            if ($cid !== '' && isset($seen[$cid])) {
                continue;
            }
            if ($cid !== '') {
                $seen[$cid] = true;
            }
            $deduped[] = $ctx;
        }

        // Cap to max_contexts_per_run (take most recent)
        if (count($deduped) > $maxContexts) {
            $deduped = array_slice($deduped, -$maxContexts);
        }
        $recentTotal = count($deduped);

        // ── 7. Classify contexts ───────────────────────────────────────────────
        $usefulContexts  = [];
        $noiseSkipped    = 0;
        foreach ($deduped as $ctx) {
            $classification = $this->classifyContext($ctx);
            if ($classification === 'noise') {
                $noiseSkipped++;
                continue;
            }
            $usefulContexts[] = $ctx;
        }
        $usefulTotal = count($usefulContexts);

        // ── 5. Group by symbol ─────────────────────────────────────────────────
        $bySymbol = [];
        foreach ($usefulContexts as $ctx) {
            $sym = (string)($ctx['symbol'] ?? '');
            if ($sym === '') {
                continue;
            }
            $bySymbol[$sym][] = $ctx;
        }

        // ── 8. Evaluate dynamic rules + 9. Score ──────────────────────────────
        $candidates    = [];
        $rejectItems   = [];
        // Minimum confirmations to be tracked as a candidate at all (very low bar).
        // Per-rule and per-mode thresholds are applied later in the signals loop.
        $minCandConf   = (int)($config['min_confirmations_for_shadow_candidate'] ?? 2);

        $enabledRules = [];
        foreach (array_keys(self::RULE_CONTEXT_MAP) as $rule) {
            if ((bool)($config[$rule] ?? true)) {
                $enabledRules[] = $rule;
            }
        }

        foreach ($bySymbol as $sym => $ctxList) {
            foreach ($enabledRules as $rule) {
                $candidate = $this->evaluateRule($rule, $sym, $ctxList, $config);
                if ($candidate === null) {
                    continue;
                }

                if ($candidate['confirmation_count'] < $minCandConf) {
                    $rejectItems[] = [
                        'symbol'             => $sym,
                        'dynamic_rule'       => $rule,
                        'reject_reason'      => 'insufficient_confirmations',
                        'confirmation_count' => $candidate['confirmation_count'],
                        'min_required'       => $minCandConf,
                        'confidence_score'   => $candidate['confidence_score'],
                        'source_context_ids' => $candidate['source_context_ids'],
                        'rejected_at'        => date('c'),
                    ];
                    continue;
                }

                $candidate['candidate_id'] = 'dyn_' . $rule . '_' . $sym . '_' . $now;
                $candidates[] = $candidate;
            }
        }

        // ── 9b. side_mode filtering ────────────────────────────────────────────
        // Filter candidates to only those matching the configured side_mode.
        // Rejected candidates go to rejects.json with reason=side_mode_filtered.
        $sideFilteredExamples = [];
        $candidatesShortTotal = 0;
        $candidatesLongTotal  = 0;
        $allowedCandidates    = [];
        $filteredBySide       = 0;

        foreach ($candidates as $cand) {
            $candSide = (string)($cand['side'] ?? 'short');
            if ($candSide === 'short') {
                $candidatesShortTotal++;
            } elseif ($candSide === 'long') {
                $candidatesLongTotal++;
            }

            $allowed = ($sideMode === 'all')
                || ($sideMode === 'short' && $candSide === 'short')
                || ($sideMode === 'long'  && $candSide === 'long');

            if ($allowed) {
                $allowedCandidates[] = $cand;
            } else {
                $filteredBySide++;
                $rejectEntry = [
                    'symbol'        => $cand['symbol'],
                    'dynamic_rule'  => $cand['dynamic_rule'],
                    'reject_reason' => 'side_mode_filtered',
                    'side_mode'     => $sideMode,
                    'candidate_side' => $candSide,
                    'confidence_score' => $cand['confidence_score'],
                    'source_context_ids' => $cand['source_context_ids'],
                    'rejected_at'   => date('c'),
                ];
                $rejectItems[] = $rejectEntry;
                if (count($sideFilteredExamples) < 5) {
                    $sideFilteredExamples[] = $rejectEntry;
                }
            }
        }
        $candidates = $allowedCandidates;

        // ── Replay/trend confirmation gate — load prior run data ──────────────────
        // The replay analyzer runs at the END of execute() and updates
        // rejected_context_replay.json.  During THIS run we gate short executable
        // handoffs using data from the PREVIOUS run (file already on disk).
        $requireReplayGate = (bool)($config['dynamic_handoff_require_replay_confirmation'] ?? true);
        $priorReplayFile   = $this->moduleDir . '/storage/rejected_context_replay.json';
        $replayBySymbol    = [];
        if ($requireReplayGate && file_exists($priorReplayFile) && is_readable($priorReplayFile)) {
            $rawReplay = @file_get_contents($priorReplayFile);
            if ($rawReplay !== false && trim($rawReplay) !== '') {
                $decodedReplay = json_decode($rawReplay, true);
                if (is_array($decodedReplay)) {
                    foreach ($decodedReplay as $rec) {
                        $rsym = (string)($rec['symbol'] ?? '');
                        if ($rsym !== '') {
                            $replayBySymbol[$rsym][] = $rec;
                        }
                    }
                }
            }
        }

        // ── Pre-enrich candidates with matched replay records ─────────────────────
        // Adds replay_status, trend_5m/15m/30m, replay_reject_reason to each
        // candidate so the signal loop and candidates.json carry replay context.
        $replayMatchAgeMinutes = (int)($config['context_max_age_minutes'] ?? 180);
        if ($requireReplayGate) {
            foreach ($candidates as $idx => $cand) {
                $matchedRec = $this->findReplayRecordForCandidate(
                    $cand, $replayBySymbol, $replayMatchAgeMinutes
                );
                $replayMeta = $matchedRec !== null ? ($matchedRec['_replay_match_meta'] ?? []) : [];
                $candidates[$idx]['replay_status']             = $matchedRec !== null ? ($matchedRec['status']             ?? null) : null;
                $candidates[$idx]['replay_reject_reason']      = $matchedRec !== null ? ($matchedRec['reject_reason']      ?? null) : null;
                $candidates[$idx]['trend_5m']                  = $matchedRec !== null ? ($matchedRec['trend_5m']           ?? null) : null;
                $candidates[$idx]['trend_15m']                 = $matchedRec !== null ? ($matchedRec['trend_15m']          ?? null) : null;
                $candidates[$idx]['trend_30m']                 = $matchedRec !== null ? ($matchedRec['trend_30m']          ?? null) : null;
                $candidates[$idx]['replay_match_mode']         = $replayMeta['replay_match_mode']          ?? ($matchedRec !== null ? 'unknown' : null);
                $candidates[$idx]['replay_matched_context_ids']= $replayMeta['replay_matched_context_ids'] ?? [];
                $candidates[$idx]['replay_context_type_matched']= $replayMeta['replay_context_type_matched'] ?? false;
                $candidates[$idx]['replay_record_observed_at'] = $replayMeta['replay_record_observed_at']  ?? null;
                $candidates[$idx]['replay_record_age_seconds'] = $replayMeta['replay_record_age_seconds']  ?? null;
                // Extract replay confirmation fields from confirmations sub-array (new format),
                // with fallback to top-level confirmation_count for legacy records.
                $replayCnt            = 0;
                $replayConfPassed     = null;
                $replayConfConfidence = null;
                $replayPriceChangePct = null;
                if ($matchedRec !== null) {
                    $confBlock = $matchedRec['confirmations'] ?? null;
                    if (is_array($confBlock)) {
                        $replayCnt            = (int)($confBlock['count'] ?? 0);
                        $replayConfPassed     = array_key_exists('passed', $confBlock) ? (bool)$confBlock['passed'] : null;
                        $replayConfConfidence = is_numeric($confBlock['confidence'] ?? null)    ? (float)$confBlock['confidence']    : null;
                        $replayPriceChangePct = is_numeric($confBlock['price_change_pct'] ?? null) ? (float)$confBlock['price_change_pct'] : null;
                    } else {
                        // Legacy format: confirmation_count at top level
                        $replayCnt = (int)($matchedRec['confirmation_count'] ?? 0);
                    }
                }
                $candidates[$idx]['replay_confirmation_count']          = $replayCnt;
                $candidates[$idx]['replay_confirmation_passed']         = $replayConfPassed;
                $candidates[$idx]['replay_confirmation_confidence']     = $replayConfConfidence;
                $candidates[$idx]['replay_price_change_pct']            = $replayPriceChangePct;
                // Required confirmation count (from config, for context in diagnostics)
                $candidates[$idx]['replay_required_confirmation_count'] = (int)($config['dynamic_handoff_min_confirmations_with_replay'] ?? 3);
                // context_type_matched flag: true when the replay record was found via
                // context_type match rather than exact source_context_ids match.
                $candidates[$idx]['replay_context_type_matched']        = isset($replayMeta['replay_match_mode'])
                    && $replayMeta['replay_match_mode'] === 'context_type';
                // Stable idea key for diagnostics/dedupe — does not change signal_id consumers
                $ideaObsAt    = $cand['observed_at'] ?? null;
                $ideaTsRaw    = $ideaObsAt !== null ? (is_int($ideaObsAt) ? $ideaObsAt : (int)strtotime((string)$ideaObsAt)) : time();
                $ideaHrBucket = (int)floor($ideaTsRaw / 3600);
                $candidates[$idx]['dynamic_idea_key'] = implode(':', [
                    strtoupper((string)($cand['symbol']    ?? '')),
                    strtolower((string)($cand['side']      ?? 'none')),
                    (string)($cand['dynamic_rule']         ?? 'none'),
                    (string)($cand['context_type']         ?? 'none'),
                    (string)$ideaHrBucket,
                ]);
            }
        }

        // ── 10. Build signals ──────────────────────────────────────────────────
        $signals             = [];
        $nonExecutableSignals = [];
        $executableTotal     = 0;
        $nonExecutableTotal  = 0;
        $maxSigPerRun   = (int)($config['max_signals_per_run']    ?? 20);
        $maxSigPerSym   = (int)($config['max_signals_per_symbol'] ?? 1);
        $ttlMinutes     = (int)($config['signal_ttl_minutes']     ?? 120);
        $signalsBySymbol = [];

        // Threshold diagnostics
        $thresholdPassedTotal         = 0;
        $thresholdFailedTotal         = 0;
        $thresholdRuleConfigTotal     = 0;
        $thresholdGlobalFallbackTotal = 0;
        $thresholdPassedExamples      = [];
        $thresholdFailedExamples      = [];

        // Entry price + handoff payload diagnostics
        $entryPriceResolvedTotal              = 0;
        $entryPriceMissingTotal               = 0;
        $handoffBlockedMissingPriceTotal      = 0;
        $handoffBlockedMissingPriceExamples   = [];
        $botHandoffPayloadValidTotal          = 0;
        $botHandoffPayloadInvalidTotal        = 0;
        $botHandoffPayloadExamples            = [];
        $botHandoffPayloadInvalidExamples     = [];

        // Replay gate counters (short-only gate, applied when requireReplayGate=true)
        $replayGateCheckedTotal         = 0;
        $replayGatePassedTotal          = 0;
        $replayGateBlockedTotal         = 0;
        $replayGateMissingTotal         = 0;
        $replayGateBlocked5mTotal       = 0;
        $replayGateBlocked15mTotal      = 0;
        $replayGateBlocked30mTotal      = 0;
        $replayGateBlockedRecoveryTotal = 0;
        $replayGatePassedExamples       = [];
        $replayGateBlockedExamples      = [];
        $replayGateMissingExamples      = [];

        // Entry wall gate counters
        $entryWallGateEnabled         = (bool)($config['entry_wall_gate_enabled']             ?? true);
        $entryWallGateCheckedTotal    = 0;
        $entryWallGateBlockedTotal    = 0;
        $entryWallGateDemotedTotal    = 0;
        $entryWallGatePassedTotal     = 0;
        $entryWallGateExamples        = [];

        // Global thresholds (fallback when rule-specific config is missing)
        $globalMinDemoConf  = (int)($config['min_confirmations_demo'] ?? $config['min_confirmations_for_demo_signal'] ?? 3);
        $globalMinDemoScore = (float)($config['min_confidence_demo']  ?? $config['min_confidence_for_demo_signal']   ?? 0.65);
        $globalMinLiveConf  = (int)($config['min_confirmations_live'] ?? $globalMinDemoConf + 1);
        $globalMinLiveScore = (float)($config['min_confidence_live']  ?? $config['min_confidence_for_live_signal']   ?? 0.85);

        foreach ($candidates as $cand) {
            $sym     = $cand['symbol'];
            $score   = $cand['confidence_score'];
            $confCnt = $cand['confirmation_count'];
            $candSide = (string)($cand['side'] ?? 'short');
            $ruleId  = (string)($cand['dynamic_rule'] ?? '');

            // Per-rule threshold lookup (preferred); fall back to globals
            $ruleCfg = $config['rules'][$ruleId] ?? [];
            $thresholdSource = ($ruleCfg !== []) ? 'rule_config' : 'global_fallback';
            if ($thresholdSource === 'rule_config') {
                $thresholdRuleConfigTotal++;
            } else {
                $thresholdGlobalFallbackTotal++;
            }

            $minDemoConf  = isset($ruleCfg['min_confirmations_demo'])
                ? (int)$ruleCfg['min_confirmations_demo']
                : $globalMinDemoConf;
            $minDemoScore = isset($ruleCfg['min_confidence_demo'])
                ? (float)$ruleCfg['min_confidence_demo']
                : $globalMinDemoScore;
            $minLiveConf  = isset($ruleCfg['min_confirmations_live'])
                ? (int)$ruleCfg['min_confirmations_live']
                : $globalMinLiveConf;
            $minLiveScore = isset($ruleCfg['min_confidence_live'])
                ? (float)$ruleCfg['min_confidence_live']
                : $globalMinLiveScore;

            // Use per-mode thresholds (demo thresholds are the primary gate)
            $qualifiesForDemo = $confCnt >= $minDemoConf && $score >= $minDemoScore;

            if (!$qualifiesForDemo) {
                $thresholdFailedTotal++;
                $failedReject = [
                    'symbol'                  => $sym,
                    'dynamic_rule'            => $ruleId,
                    'reject_reason'           => 'rule_threshold_not_met',
                    'confirmation_count'      => $confCnt,
                    'confidence_score'        => $score,
                    'min_confirmations_required' => $minDemoConf,
                    'min_confidence_required' => $minDemoScore,
                    'min_conf_required'       => $minDemoConf,   // backward-compat alias
                    'min_score_required'      => $minDemoScore,  // backward-compat alias
                    'threshold_source'        => $thresholdSource,
                    'source_context_ids'      => $cand['source_context_ids'],
                    'rejected_at'             => date('c'),
                ];
                $rejectItems[] = $failedReject;
                if (count($thresholdFailedExamples) < 5) {
                    $thresholdFailedExamples[] = $failedReject;
                }
                continue;
            }
            $thresholdPassedTotal++;
            if (count($thresholdPassedExamples) < 5) {
                $thresholdPassedExamples[] = [
                    'symbol'                  => $sym,
                    'dynamic_rule'            => $ruleId,
                    'confirmation_count'      => $confCnt,
                    'confidence_score'        => $score,
                    'min_confirmations_required' => $minDemoConf,
                    'min_confidence_required' => $minDemoScore,
                    'threshold_source'        => $thresholdSource,
                    'threshold_passed'        => true,
                ];
            }
            if (count($signals) >= $maxSigPerRun) {
                break;
            }
            if (($signalsBySymbol[$sym] ?? 0) >= $maxSigPerSym) {
                continue;
            }

            $warnings = (array)($cand['warnings'] ?? []);

            // ── Entry price resolution ─────────────────────────────────────────
            $entryPrice       = is_numeric($cand['entry_price'] ?? null) ? (float)$cand['entry_price'] : 0.0;
            $entryPriceSrc    = (string)($cand['entry_price_source'] ?? '');
            $candTrigger      = (string)($cand['candidate_trigger'] ?? '');

            if ($entryPrice > 0.0) {
                $entryPriceResolvedTotal++;
            } else {
                $entryPriceMissingTotal++;
            }

            // Determine executability — strategy is environment-neutral.
            // Handoff is executable when handoff_enabled + emit_bot_handoff + thresholds pass.
            // Bot environment decides demo/live execution from its own config.
            $isExecutable = false;
            $executionMode = null;  // not set by strategy; Bot environment owns this
            $handoffReady  = false;
            $blockReason   = null;

            if ($handoffEnabled && $emitHandoff) {
                $isExecutable  = true;
                $handoffReady  = true;
            } else {
                // handoff disabled — observation only
                $blockReason = 'handoff_disabled';
                $warnings[]  = 'handoff_disabled';
            }

            // ── Block executable handoff if entry_price is missing ─────────────
            if ($isExecutable && $entryPrice <= 0.0) {
                $isExecutable = false;
                $handoffReady = false;
                $blockReason  = 'missing_dynamic_entry_price';
                $warnings[]   = 'missing_dynamic_entry_price';
                $handoffBlockedMissingPriceTotal++;
                if (count($handoffBlockedMissingPriceExamples) < 5) {
                    $availablePriceFields = [];
                    foreach ((array)($cand['source_prices'] ?? []) as $pField => $vals) {
                        if (!empty($vals)) {
                            $availablePriceFields[$pField] = $vals[0];
                        }
                    }
                    $handoffBlockedMissingPriceExamples[] = [
                        'symbol'                 => $sym,
                        'dynamic_rule'           => $ruleId,
                        'source_context_ids'     => $cand['source_context_ids'],
                        'source_context_types'   => $cand['source_context_types'] ?? [],
                        'available_price_fields' => $availablePriceFields,
                        'reason'                 => 'missing_dynamic_entry_price',
                    ];
                }
            }

            // ── Replay/trend confirmation gate (short only) ────────────────────
            // Uses pre-enriched replay fields on the candidate (loaded from prior run).
            // Blocks executable=true for short candidates that lack replay confirmation
            // or show bullish 15m/30m trends.  Long candidates bypass this gate.
            if ($requireReplayGate && $candSide === 'short' && $isExecutable) {
                $replayGateCheckedTotal++;
                $executableBeforeGate = $isExecutable;

                $gateResult = $this->applyReplayGate($cand, $config);

                if (!($gateResult['passed'] ?? false)) {
                    $isExecutable = false;
                    $handoffReady = false;
                    $blockReason  = (string)($gateResult['block_reason'] ?? 'replay_trend_confirmation_failed');
                    $warnings[]   = $blockReason;
                    $replayGateBlockedTotal++;

                    if (($gateResult['block_reason'] ?? '') === 'missing_replay_confirmation') {
                        $replayGateMissingTotal++;
                    }

                    foreach ((array)($gateResult['failed_checks'] ?? []) as $fc) {
                        if ($fc === '5m_not_bearish')              { $replayGateBlocked5mTotal++; }
                        elseif ($fc === '15m_too_bullish')         { $replayGateBlocked15mTotal++; }
                        elseif ($fc === '30m_too_bullish')         { $replayGateBlocked30mTotal++; }
                        elseif ($fc === 'strong_recovery_detected'){ $replayGateBlockedRecoveryTotal++; }
                    }

                    if (count($replayGateBlockedExamples) < 5) {
                        $replayGateBlockedExamples[] = [
                            'symbol'                                    => $sym,
                            'dynamic_rule'                              => $ruleId,
                            'source_context_ids'                        => $cand['source_context_ids'],
                            'replay_status'                             => $gateResult['replay_status'],
                            'replay_reject_reason'                      => $gateResult['replay_reject_reason'],
                            'candidate_confirmation_count'              => $gateResult['candidate_confirmation_count'],
                            'replay_confirmation_count'                 => $gateResult['replay_confirmation_count'],
                            'replay_confirmation_passed'                => $gateResult['replay_confirmation_passed'],
                            'replay_confirmation_confidence'            => $gateResult['replay_confirmation_confidence'],
                            'dynamic_handoff_min_confirmations_with_replay' => $gateResult['dynamic_handoff_min_confirmations_with_replay'],
                            'trend_5m_price_change_pct'                 => $gateResult['trend_5m_price_change_pct'],
                            'trend_15m_price_change_pct'                => $gateResult['trend_15m_price_change_pct'],
                            'trend_30m_price_change_pct'                => $gateResult['trend_30m_price_change_pct'],
                            'failed_checks'                             => $gateResult['failed_checks'],
                            'executable_before_gate'                    => $executableBeforeGate,
                            'executable_after_gate'                     => false,
                        ];
                    }

                    if (($gateResult['block_reason'] ?? '') === 'missing_replay_confirmation'
                        && count($replayGateMissingExamples) < 5
                    ) {
                        $replayGateMissingExamples[] = [
                            'symbol'                 => $sym,
                            'dynamic_rule'           => $ruleId,
                            'source_context_ids'     => $cand['source_context_ids'],
                            'source_context_types'   => $cand['source_context_types'] ?? [],
                            'executable_before_gate' => $executableBeforeGate,
                            'executable_after_gate'  => false,
                            'reason'                 => 'no_replay_record_found',
                        ];
                    }
                } else {
                    $replayGatePassedTotal++;
                    if (count($replayGatePassedExamples) < 5) {
                        $replayGatePassedExamples[] = [
                            'symbol'                                    => $sym,
                            'dynamic_rule'                              => $ruleId,
                            'source_context_ids'                        => $cand['source_context_ids'],
                            'replay_status'                             => $gateResult['replay_status'],
                            'candidate_confirmation_count'              => $gateResult['candidate_confirmation_count'],
                            'replay_confirmation_count'                 => $gateResult['replay_confirmation_count'],
                            'replay_confirmation_passed'                => $gateResult['replay_confirmation_passed'],
                            'replay_confirmation_confidence'            => $gateResult['replay_confirmation_confidence'],
                            'dynamic_handoff_min_confirmations_with_replay' => $gateResult['dynamic_handoff_min_confirmations_with_replay'],
                            'trend_5m_price_change_pct'                 => $gateResult['trend_5m_price_change_pct'],
                            'trend_15m_price_change_pct'                => $gateResult['trend_15m_price_change_pct'],
                            'trend_30m_price_change_pct'                => $gateResult['trend_30m_price_change_pct'],
                            'executable_before_gate'                    => $executableBeforeGate,
                            'executable_after_gate'                     => true,
                        ];
                    }
                }
            }

            if ($isExecutable) {
                $executableTotal++;
            } else {
                $nonExecutableTotal++;
            }

            // ── Entry wall gate ────────────────────────────────────────────────
            // For long entries: persistent ask wall nearby = resistance → block/demote.
            // For short entries: persistent bid wall nearby = support → block/demote.
            // Applied only when OBC service is available and entry_wall_gate_enabled=true.
            // Does NOT block if wall is eaten or broken.
            // Uses demote (lower confidence) instead of hard reject when
            // entry_wall_demote_instead_of_reject = true.
            $wallGateReason     = null;
            $wallGateBlocked    = false;
            $entryWallRisk      = null;
            $nearestAskWallCtx  = null;
            $nearestBidWallCtx  = null;

            if ($obcService !== null && $entryWallGateEnabled && $isExecutable && $entryPrice > 0.0) {
                $blockDist       = (float)($config['entry_wall_block_distance_pct']      ?? 0.8);
                $requirePersist  = (bool)($config['entry_wall_require_persistent']       ?? true);
                $demoteNotReject = (bool)($config['entry_wall_demote_instead_of_reject'] ?? true);

                try {
                    $wallCtx         = $obcService->getWallContext($sym, $entryPrice);
                    $nearestAskWallCtx = $wallCtx['nearest_ask_wall'] ?? null;
                    $nearestBidWallCtx = $wallCtx['nearest_bid_wall'] ?? null;

                    $entryWallGateCheckedTotal++;

                    if ($candSide === 'long' && $nearestAskWallCtx !== null) {
                        $askDist   = (float)($nearestAskWallCtx['distance_pct'] ?? 999.0);
                        $askStatus = (string)($wallCtx['ask_wall_status'] ?? 'none');
                        $isBlockable = !$requirePersist || $askStatus === 'persistent';

                        if ($askDist <= $blockDist && $isBlockable
                            && $askStatus !== 'eaten' && $askStatus !== 'broken'
                        ) {
                            $wallGateReason = 'entry_blocked_near_ask_wall';
                            $entryWallRisk  = 'ask_wall';

                            if ($demoteNotReject) {
                                // Demote: reduce confidence score, keep signal but mark non-executable
                                $score         = round($score * 0.5, 4);
                                $isExecutable  = false;
                                $handoffReady  = false;
                                $wallGateBlocked = false; // demote, not hard block
                                $warnings[]    = $wallGateReason;
                                $entryWallGateDemotedTotal++;
                            } else {
                                // Hard block
                                $isExecutable  = false;
                                $handoffReady  = false;
                                $wallGateBlocked = true;
                                $warnings[]    = $wallGateReason;
                                $entryWallGateBlockedTotal++;
                            }
                        } else {
                            $entryWallGatePassedTotal++;
                        }
                    } elseif ($candSide === 'short' && $nearestBidWallCtx !== null) {
                        $bidDist   = (float)($nearestBidWallCtx['distance_pct'] ?? 999.0);
                        $bidStatus = (string)($wallCtx['bid_wall_status'] ?? 'none');
                        $isBlockable = !$requirePersist || $bidStatus === 'persistent';

                        if ($bidDist <= $blockDist && $isBlockable
                            && $bidStatus !== 'eaten' && $bidStatus !== 'broken'
                        ) {
                            $wallGateReason = 'entry_blocked_near_bid_wall';
                            $entryWallRisk  = 'bid_wall';

                            if ($demoteNotReject) {
                                $score         = round($score * 0.5, 4);
                                $isExecutable  = false;
                                $handoffReady  = false;
                                $wallGateBlocked = false;
                                $warnings[]    = $wallGateReason;
                                $entryWallGateDemotedTotal++;
                            } else {
                                $isExecutable  = false;
                                $handoffReady  = false;
                                $wallGateBlocked = true;
                                $warnings[]    = $wallGateReason;
                                $entryWallGateBlockedTotal++;
                            }
                        } else {
                            $entryWallGatePassedTotal++;
                        }
                    } else {
                        $entryWallGatePassedTotal++;
                    }

                    if (($wallGateBlocked || $wallGateReason !== null) && count($entryWallGateExamples) < 5) {
                        $entryWallGateExamples[] = [
                            'symbol'            => $sym,
                            'side'              => $candSide,
                            'current_price'     => $entryPrice,
                            'wall_side'         => $entryWallRisk,
                            'wall_price'        => ($entryWallRisk === 'ask_wall')
                                ? ($nearestAskWallCtx['price']        ?? null)
                                : ($nearestBidWallCtx['price']        ?? null),
                            'wall_distance_pct' => ($entryWallRisk === 'ask_wall')
                                ? ($nearestAskWallCtx['distance_pct'] ?? null)
                                : ($nearestBidWallCtx['distance_pct'] ?? null),
                            'wall_notional'     => ($entryWallRisk === 'ask_wall')
                                ? ($nearestAskWallCtx['notional']     ?? null)
                                : ($nearestBidWallCtx['notional']     ?? null),
                            'wall_score'        => ($entryWallRisk === 'ask_wall')
                                ? ($nearestAskWallCtx['wall_score']   ?? null)
                                : ($nearestBidWallCtx['wall_score']   ?? null),
                            'wall_status'       => ($entryWallRisk === 'ask_wall')
                                ? ($wallCtx['ask_wall_status'] ?? 'none')
                                : ($wallCtx['bid_wall_status'] ?? 'none'),
                            'action'            => $demoteNotReject ? 'demoted' : 'blocked',
                            'reason'            => $wallGateReason,
                        ];
                    }
                } catch (\Throwable) {
                    // OBC service failure — never block the signal
                }
            }

            // ── Build strategy_signal_context ──────────────────────────────────
            $stratSignalCtx = [
                'strategy_id'          => self::STRATEGY_ID,
                'dynamic_rule'         => $ruleId,
                'dynamic_idea_key'     => $cand['dynamic_idea_key'] ?? null,
                'source_context_ids'   => $cand['source_context_ids'],
                'source_strategies'    => $cand['source_strategies'],
                'source_signal_ids'    => $cand['source_signal_ids'] ?? [],
                'source_context_types' => $cand['source_context_types'] ?? [],
                'confidence_score'     => $score,
                'confirmations'        => $cand['confirmations'],
                'confirmation_count'   => $confCnt,
                'entry_price'          => $entryPrice > 0.0 ? $entryPrice : null,
                'entry_price_source'   => $entryPriceSrc !== '' ? $entryPriceSrc : null,
                'candidate_trigger'    => $candTrigger !== '' ? $candTrigger : null,
                'source_prices'        => $cand['source_prices'] ?? [],
                'source_levels'        => $cand['source_levels'] ?? [],
                'source_warnings'      => $cand['source_warnings'] ?? [],
                'source_reason_codes'  => $cand['source_reason_codes'] ?? [],
                'warnings'             => $warnings,
                'reason_codes'         => $cand['reason_codes'],
                // Replay gate fields (null when gate not applied or no record found)
                'replay_status'              => $cand['replay_status'] ?? null,
                'replay_reject_reason'       => $cand['replay_reject_reason'] ?? null,
                'trend_5m_price_change_pct'  => is_array($cand['trend_5m']  ?? null) ? ($cand['trend_5m']['price_change_pct']  ?? null) : null,
                'trend_15m_price_change_pct' => is_array($cand['trend_15m'] ?? null) ? ($cand['trend_15m']['price_change_pct'] ?? null) : null,
                'trend_30m_price_change_pct' => is_array($cand['trend_30m'] ?? null) ? ($cand['trend_30m']['price_change_pct'] ?? null) : null,
                'replay_gate_applied'        => $requireReplayGate && $candSide === 'short',
                // Full replay diagnostics — propagated from candidate pre-enrichment
                'replay_match_mode'                  => $cand['replay_match_mode']                  ?? null,
                'replay_matched_context_ids'         => $cand['replay_matched_context_ids']         ?? [],
                'replay_context_type_matched'        => $cand['replay_context_type_matched']        ?? null,
                'replay_record_observed_at'          => $cand['replay_record_observed_at']          ?? null,
                'replay_record_age_seconds'          => $cand['replay_record_age_seconds']          ?? null,
                'replay_confirmation_count'          => $cand['replay_confirmation_count']          ?? null,
                'replay_required_confirmation_count' => $cand['replay_required_confirmation_count'] ?? null,
                'replay_confirmation_passed'         => $cand['replay_confirmation_passed']         ?? null,
                'replay_confirmation_confidence'     => $cand['replay_confirmation_confidence']     ?? null,
                // Wall entry gate fields
                'entry_wall_risk'            => $entryWallRisk,
                'nearest_ask_wall'           => $nearestAskWallCtx,
                'nearest_bid_wall'           => $nearestBidWallCtx,
                'wall_gate_reason'           => $wallGateReason,
                'wall_gate_blocked'          => $wallGateBlocked,
            ];

            $detectedAt = date('c');
            $signal = [
                'signal_id'                   => (function () use ($cand, $sym, $candSide): string {
                                                    $ctxIds = $cand['source_context_ids'] ?? [];
                                                    sort($ctxIds);
                                                    $ctxHash = substr(md5(implode(',', $ctxIds)), 0, 8);
                                                    return 'dsig_' . $cand['dynamic_rule'] . '_' . $sym . '_' . $candSide . '_' . $ctxHash;
                                                })(),
                'symbol'                      => $sym,
                'side'                        => $candSide,
                'strategy'                    => self::STRATEGY_ID,
                'strategy_id'                 => self::STRATEGY_ID,
                'owner_strategy'              => self::STRATEGY_ID,
                'dynamic_rule'                => $cand['dynamic_rule'],
                'confidence_score'            => $score,
                // execution_mode is intentionally omitted — Bot environment owns execution mode.
                // Strategy signals are environment-neutral.
                'side_mode'                   => $sideMode,
                'handoff_ready'               => $handoffReady,
                'executable'                  => $isExecutable,
                'threshold_passed'            => true,
                'threshold_source'            => $thresholdSource,
                'min_confirmations_required'  => $minDemoConf,
                'min_confidence_required'     => $minDemoScore,
                // Entry geometry (bot-compatible)
                'entry_price'                 => $entryPrice > 0.0 ? $entryPrice : null,
                'entry_price_source'          => $entryPriceSrc !== '' ? $entryPriceSrc : null,
                'candidate_trigger'           => $candTrigger !== '' ? $candTrigger : null,
                'entry_mode'                  => 'limit',
                'entry_type'                  => 'dynamic_context',
                'timeframe'                   => 'dynamic',
                // Freshness
                'detected_at'                 => $detectedAt,
                'expires_at'                  => date('c', $now + ($ttlMinutes * 60)),
                // Source context data
                'source_context_ids'          => $cand['source_context_ids'],
                'source_strategies'           => $cand['source_strategies'],
                'source_signal_ids'           => $cand['source_signal_ids'] ?? [],
                'confirmations'               => $cand['confirmations'],
                'confirmation_count'          => $confCnt,
                'reason_codes'                => $cand['reason_codes'],
                'warnings'                    => $warnings,
                'dynamic_idea_key'            => $cand['dynamic_idea_key'] ?? null,
                // Replay diagnostics at signal top-level for consumers that do not
                // read strategy_signal_context (e.g. bot, stop_manager).
                'replay_match_mode'                  => $cand['replay_match_mode']                  ?? null,
                'replay_matched_context_ids'         => $cand['replay_matched_context_ids']         ?? [],
                'replay_context_type_matched'        => $cand['replay_context_type_matched']        ?? null,
                'replay_record_observed_at'          => $cand['replay_record_observed_at']          ?? null,
                'replay_record_age_seconds'          => $cand['replay_record_age_seconds']          ?? null,
                'replay_confirmation_count'          => $cand['replay_confirmation_count']          ?? null,
                'replay_required_confirmation_count' => $cand['replay_required_confirmation_count'] ?? null,
                'replay_confirmation_passed'         => $cand['replay_confirmation_passed']         ?? null,
                'replay_confirmation_confidence'     => $cand['replay_confirmation_confidence']     ?? null,
                // Nested context for bot
                'strategy_signal_context'     => $stratSignalCtx,
            ];
            if ($blockReason !== null) {
                $signal['block_reason'] = $blockReason;
            }

            $signals[] = $signal;
            $signalsBySymbol[$sym] = ($signalsBySymbol[$sym] ?? 0) + 1;
        }

        // ── 11. Build handoff queue ────────────────────────────────────────────
        // Executable entries written when handoff_enabled + emit_bot_handoff + threshold + entry_price pass.
        // Strategy signals are environment-neutral; Bot environment owns execution mode.
        // Non-executable diagnostic rows written when handoff disabled (aids debugging).
        $handoffQueue        = [];
        $handoffExecutable   = 0;
        $handoffReadyTotal   = 0;
        $handoffWrittenTotal = 0;

        if ($handoffEnabled && $emitHandoff) {
            foreach ($signals as $sig) {
                if ($sig['executable'] ?? false) {
                    $sigEntryPrice = (float)($sig['entry_price'] ?? 0.0);
                    $handoffEntry = [
                        // ── Identity ──────────────────────────────────────────
                        'signal_id'              => $sig['signal_id'],
                        'owner_strategy'         => self::STRATEGY_ID,
                        'strategy'               => self::STRATEGY_ID,
                        'strategy_id'            => self::STRATEGY_ID,
                        'symbol'                 => $sig['symbol'],
                        'side'                   => $sig['side'],
                        'timeframe'              => 'dynamic',
                        // ── Execution ─────────────────────────────────────────
                        // execution_mode intentionally omitted — Bot environment owns this.
                        'entry_mode'             => 'limit',
                        'entry_type'             => 'dynamic_context',
                        'entry_price'            => $sigEntryPrice > 0.0 ? $sigEntryPrice : null,
                        'entry_price_source'     => $sig['entry_price_source'] ?? null,
                        'candidate_trigger'      => $sig['candidate_trigger'] ?? null,
                        // ── Lifecycle ─────────────────────────────────────────
                        'handoff_ready'          => true,
                        'executable'             => true,
                        'handoff_status'         => 'new',
                        'detected_at'            => $sig['detected_at'],
                        'queued_at'              => date('c'),
                        'expires_at'             => $sig['expires_at'],
                        // ── Dynamic context ───────────────────────────────────
                        'dynamic_rule'           => $sig['dynamic_rule'],
                        'dynamic_idea_key'       => $sig['dynamic_idea_key'] ?? null,
                        'confidence_score'       => $sig['confidence_score'],
                        'confirmations'          => $sig['confirmations'],
                        'confirmation_count'     => $sig['confirmation_count'],
                        'source_context_ids'     => $sig['source_context_ids'],
                        'source_strategies'      => $sig['source_strategies'],
                        'source_signal_ids'      => $sig['source_signal_ids'] ?? [],
                        'reason_codes'           => $sig['reason_codes'],
                        'warnings'               => $sig['warnings'],
                        // ── Replay diagnostics ────────────────────────────────
                        'replay_match_mode'                  => $sig['replay_match_mode']                  ?? null,
                        'replay_matched_context_ids'         => $sig['replay_matched_context_ids']         ?? [],
                        'replay_context_type_matched'        => $sig['replay_context_type_matched']        ?? null,
                        'replay_record_observed_at'          => $sig['replay_record_observed_at']          ?? null,
                        'replay_record_age_seconds'          => $sig['replay_record_age_seconds']          ?? null,
                        'replay_confirmation_count'          => $sig['replay_confirmation_count']          ?? null,
                        'replay_required_confirmation_count' => $sig['replay_required_confirmation_count'] ?? null,
                        'replay_confirmation_passed'         => $sig['replay_confirmation_passed']         ?? null,
                        'replay_confirmation_confidence'     => $sig['replay_confirmation_confidence']     ?? null,
                        // ── Bot context ───────────────────────────────────────
                        'strategy_signal_context' => $sig['strategy_signal_context'] ?? null,
                    ];
                    $handoffQueue[]    = $handoffEntry;
                    $handoffExecutable++;
                    $handoffReadyTotal++;
                    $botHandoffPayloadValidTotal++;
                    if (count($botHandoffPayloadExamples) < 5) {
                        $botHandoffPayloadExamples[] = [
                            'symbol'            => $sig['symbol'],
                            'side'              => $sig['side'],
                            'dynamic_rule'      => $sig['dynamic_rule'],
                            'entry_price'       => $sigEntryPrice > 0.0 ? $sigEntryPrice : null,
                            'entry_price_source' => $sig['entry_price_source'] ?? null,
                            'handoff_ready'     => true,
                            'executable'        => true,
                        ];
                    }
                }
            }
        } else {
            // Diagnostic non-executable rows — aids debugging without enabling handoff
            foreach ($signals as $sig) {
                $handoffQueue[] = [
                    'signal_id'               => $sig['signal_id'],
                    'symbol'                  => $sig['symbol'],
                    'side'                    => $sig['side'],
                    'strategy_id'             => self::STRATEGY_ID,
                    'owner_strategy'          => self::STRATEGY_ID,
                    'dynamic_rule'            => $sig['dynamic_rule'],
                    'confidence_score'        => $sig['confidence_score'],
                    'handoff_ready'           => false,
                    'executable'              => false,
                    'diagnostic_only'         => true,
                    'block_reason'            => $sig['block_reason'] ?? 'handoff_disabled',
                    'detected_at'             => $sig['detected_at'],
                    'queued_at'               => date('c'),
                    'expires_at'              => $sig['expires_at'],
                    'source_context_ids'      => $sig['source_context_ids'],
                    'strategy_signal_context' => $sig['strategy_signal_context'] ?? null,
                ];
                $botHandoffPayloadInvalidTotal++;
                if (count($botHandoffPayloadInvalidExamples) < 5) {
                    $botHandoffPayloadInvalidExamples[] = [
                        'symbol'       => $sig['symbol'],
                        'dynamic_rule' => $sig['dynamic_rule'],
                        'reason'       => $sig['block_reason'] ?? 'handoff_disabled',
                    ];
                }
            }
        }
        $handoffWrittenTotal = count($handoffQueue);

        // ── 10. Write storage files ────────────────────────────────────────────
        $finishedAt  = date('c');
        $durationMs  = (int)round((microtime(true) - $startMicro) * 1000);

        $stats = [
            'status'                          => 'done',
            'started_at'                      => $startedAt,
            'finished_at'                     => $finishedAt,
            'duration_ms'                     => $durationMs,
            'enabled'                         => true,
            'mode'                            => $mode,       // kept for backward compat; not used as execution gate
            'side_mode'                       => $sideMode,
            'handoff_enabled'                 => $handoffEnabled,
            'emit_bot_handoff'                => $emitHandoff,
            // Deprecated execution-gate fields — kept for backward compat; ignored for handoff gating
            'deprecated_mode_fields'          => true,
            'deprecated_mode_keys_seen'       => $deprecatedModeKeysSeen,
            // Backward-compat shadow fields (deprecated; always false)
            'shadow_only'                     => false,
            'shadow_signals_total'            => 0,
            'deprecated_shadow_fields'        => true,
            // ── Source adapter diagnostics ────────────────────────────────────
            'source_adapters_total'           => $sourceStats['adapters_total']           ?? 0,
            'source_adapters_enabled_total'   => $sourceStats['adapters_enabled_total']   ?? 0,
            'source_contexts_loaded_total'    => $sourceStats['contexts_loaded_total']    ?? 0,
            'source_contexts_useful_total'    => $sourceStats['contexts_useful_total']    ?? 0,
            'source_contexts_skipped_noise_total' => $sourceStats['contexts_skipped_noise_total'] ?? 0,
            'source_contexts_deduped_total'   => $sourceStats['contexts_deduped_total']   ?? 0,
            'source_contexts_written_total'   => count($writeContexts),
            'source_stats'                    => $sourceStats['per_source']               ?? [],
            'source_context_examples'         => array_slice($sourceStats['all_contexts'] ?? [], 0, 3),
            'source_adapter_error_examples'   => $sourceStats['errors'] ?? [],
            // ── Input context pipeline ────────────────────────────────────────
            'input_contexts_loaded_total'     => $loadedTotal,
            'input_contexts_recent_total'     => $recentTotal,
            'input_contexts_skipped_stale_total' => $staleSkipped,
            'input_contexts_skipped_noise_total' => $noiseSkipped,
            'useful_contexts_total'           => $usefulTotal,
            // ── Candidates ───────────────────────────────────────────────────
            'candidates_total'                         => count($candidates),
            'candidates_short_total'                   => $candidatesShortTotal,
            'candidates_long_total'                    => $candidatesLongTotal,
            'candidates_allowed_by_side_total'         => count($candidates),
            'candidates_filtered_by_side_total'        => $filteredBySide,
            'rejected_contexts_total'                  => count($rejectItems),
            // ── Threshold diagnostics ─────────────────────────────────────────
            'candidates_threshold_passed_total'        => $thresholdPassedTotal,
            'candidates_threshold_failed_total'        => $thresholdFailedTotal,
            'candidates_threshold_rule_config_total'   => $thresholdRuleConfigTotal,
            'candidates_threshold_global_fallback_total' => $thresholdGlobalFallbackTotal,
            // ── Signals ──────────────────────────────────────────────────────
            'signals_total'                   => count($signals),
            'executable_signals_total'        => $executableTotal,
            'non_executable_signals_total'    => $nonExecutableTotal,
            // ── Handoff queue ─────────────────────────────────────────────────
            'bot_handoff_ready_total'         => $handoffReadyTotal,
            'bot_handoff_queue_written_total' => $handoffWrittenTotal,
            'bot_handoff_queue_executable_total' => $handoffExecutable,
            'errors_total'                    => 0,
            // ── Entry price diagnostics ───────────────────────────────────────
            'dynamic_entry_price_resolved_total'             => $entryPriceResolvedTotal,
            'dynamic_entry_price_missing_total'              => $entryPriceMissingTotal,
            'dynamic_handoff_blocked_missing_entry_price_total' => $handoffBlockedMissingPriceTotal,
            'bot_handoff_payload_valid_total'                => $botHandoffPayloadValidTotal,
            'bot_handoff_payload_invalid_total'              => $botHandoffPayloadInvalidTotal,
            // Examples (up to 5 each)
            'candidate_examples'              => array_slice($candidates, 0, 5),
            'rejected_context_examples'       => array_slice($rejectItems, 0, 5),
            'signal_examples'                 => array_slice($signals, 0, 5),
            'handoff_examples'                => array_slice($handoffQueue, 0, 5),
            'side_filtered_examples'          => $sideFilteredExamples,
            'threshold_passed_examples'       => $thresholdPassedExamples,
            'threshold_failed_examples'       => $thresholdFailedExamples,
            'dynamic_handoff_blocked_missing_entry_price_examples' => $handoffBlockedMissingPriceExamples,
            'bot_handoff_payload_examples'        => $botHandoffPayloadExamples,
            'bot_handoff_payload_invalid_examples' => $botHandoffPayloadInvalidExamples,
            // ── Replay gate diagnostics ───────────────────────────────────────
            'dynamic_replay_gate_enabled'                       => $requireReplayGate,
            'dynamic_replay_gate_checked_total'                 => $replayGateCheckedTotal,
            'dynamic_replay_gate_passed_total'                  => $replayGatePassedTotal,
            'dynamic_replay_gate_blocked_total'                 => $replayGateBlockedTotal,
            'dynamic_replay_gate_missing_total'                 => $replayGateMissingTotal,
            'dynamic_replay_gate_blocked_5m_total'              => $replayGateBlocked5mTotal,
            'dynamic_replay_gate_blocked_15m_total'             => $replayGateBlocked15mTotal,
            'dynamic_replay_gate_blocked_30m_total'             => $replayGateBlocked30mTotal,
            'dynamic_replay_gate_blocked_strong_recovery_total' => $replayGateBlockedRecoveryTotal,
            'dynamic_replay_gate_passed_examples'               => $replayGatePassedExamples,
            'dynamic_replay_gate_blocked_examples'              => $replayGateBlockedExamples,
            'dynamic_replay_gate_missing_examples'              => $replayGateMissingExamples,
            // ── Entry wall gate diagnostics ────────────────────────────────────
            'entry_wall_gate_enabled'                           => $entryWallGateEnabled,
            'entry_wall_gate_obc_available'                     => ($obcService !== null),
            'entry_wall_gate_checked_total'                     => $entryWallGateCheckedTotal,
            'entry_wall_gate_blocked_total'                     => $entryWallGateBlockedTotal,
            'entry_wall_gate_demoted_total'                     => $entryWallGateDemotedTotal,
            'entry_wall_gate_passed_total'                      => $entryWallGatePassedTotal,
            'entry_wall_gate_examples'                          => $entryWallGateExamples,
            'obc_stats'                                         => ($obcService !== null) ? $obcService->getStats() : null,
            // Alias fields for generic dashboard display
            'found'                           => count($candidates),
            'generated_signals_count'         => count($signals),
            'active_pool_signals_total'       => count($signals),
            'handoff_ready'                   => $handoffReadyTotal,
        ];

        if ((bool)($config['write_candidates'] ?? true)) {
            $this->writeJson('storage/candidates.json', array_values($candidates));
        }
        if ((bool)($config['write_rejects'] ?? true)) {
            $this->writeJson('storage/rejects.json', array_values($rejectItems));
        }
        if ((bool)($config['write_signals'] ?? true)) {
            $this->writeJson('storage/signals.json', array_values($signals));
        }
        if ((bool)($config['write_bot_handoff_queue'] ?? true)) {
            $this->writeJson('storage/bot_handoff_queue.json', array_values($handoffQueue));
        }

        // ── Rejected-context short replay analyzer ─────────────────────────────
        // Diagnostics-only — writes replay.json and replay_summary.json.
        // No orders, no handoff, no live signals.
        $replayStats = $this->computeRejectedContextReplay($config, $allContexts);
        $stats = array_merge($stats, $replayStats);

        $this->writeJson('storage/last_run.json', $stats);

        return [
            'ok'    => true,
            'stats' => $stats,
        ];
    }

    // ── Source adapter collection ──────────────────────────────────────────────

    /**
     * Instantiate all enabled source adapters and collect contexts.
     *
     * Returns a combined stats array:
     *   adapters_total           int
     *   adapters_enabled_total   int
     *   contexts_loaded_total    int
     *   contexts_useful_total    int
     *   contexts_skipped_noise_total int
     *   contexts_deduped_total   int
     *   per_source               array  keyed by adapter id
     *   all_contexts             array  flat list of collected contexts
     *   errors                   array  non-fatal error messages
     */
    private function collectSourceContexts(array $config): array
    {
        /** @var DynamicSourceAdapterInterface[] $adapters */
        $adapters = [
            new DoubleBottomLongSourceAdapter($this->repoRoot),
            // Future adapters registered here
        ];

        $totalAdapters   = count($adapters);
        $enabledAdapters = 0;
        $allContexts     = [];
        $perSource       = [];
        $allErrors       = [];

        foreach ($adapters as $adapter) {
            if (!$adapter->enabled($config)) {
                $perSource[$adapter->id()] = ['enabled' => false];
                continue;
            }
            $enabledAdapters++;

            try {
                $result = $adapter->collect($config);
            } catch (\Throwable $e) {
                $perSource[$adapter->id()] = [
                    'enabled'               => true,
                    'contexts_loaded_total' => 0,
                    'contexts_useful_total' => 0,
                    'errors_total'          => 1,
                    'error'                 => $e->getMessage(),
                ];
                $allErrors[] = '[' . $adapter->id() . '] ' . $e->getMessage();
                continue;
            }

            $adapterContexts = (array)($result['contexts'] ?? []);
            $adapterStats    = (array)($result['stats']    ?? []);
            $adapterErrors   = (array)($result['errors']   ?? []);

            $allContexts = array_merge($allContexts, $adapterContexts);
            $perSource[$adapter->id()] = $adapterStats;
            foreach ($adapterErrors as $err) {
                $allErrors[] = '[' . $adapter->id() . '] ' . $err;
            }
        }

        // Global dedupe by context_id across all adapters
        $seen    = [];
        $deduped = [];
        $dupCount = 0;
        foreach ($allContexts as $ctx) {
            $cid = (string)($ctx['context_id'] ?? '');
            if ($cid !== '' && isset($seen[$cid])) {
                $dupCount++;
                continue;
            }
            if ($cid !== '') {
                $seen[$cid] = true;
            }
            $deduped[] = $ctx;
        }

        $loadedTotal       = count($allContexts);
        $usefulTotal       = 0;
        $noiseTotal        = 0;
        foreach ($deduped as $ctx) {
            $ct = (string)($ctx['context_type'] ?? '');
            if ($ct !== '') {
                $usefulTotal++;
            } else {
                $noiseTotal++;
            }
        }

        return [
            'adapters_total'               => $totalAdapters,
            'adapters_enabled_total'       => $enabledAdapters,
            'contexts_loaded_total'        => $loadedTotal,
            'contexts_useful_total'        => $usefulTotal,
            'contexts_skipped_noise_total' => $noiseTotal,
            'contexts_deduped_total'       => $dupCount,
            'per_source'                   => $perSource,
            'all_contexts'                 => $deduped,
            'errors'                       => $allErrors,
        ];
    }

    // ── Rule evaluation ───────────────────────────────────────────────────────

    /**
     * Evaluate a single dynamic rule against the contexts for one symbol.
     * Returns a candidate array or null if the rule is not triggered.
     */
    private function evaluateRule(string $rule, string $symbol, array $ctxList, array $config): ?array
    {
        $triggeringContexts = [];
        $confirmations      = [];
        $warnings           = [];
        $reasonCodes        = [];
        $sourceContextIds   = [];
        $sourceStrategies   = [];
        $sourceSignalIds    = [];

        $now = time();

        // Collect triggering contexts and field-level signals
        foreach ($ctxList as $ctx) {
            if (!$this->contextTriggersRule($rule, $ctx)) {
                continue;
            }
            $triggeringContexts[] = $ctx;
            $cid = (string)($ctx['context_id'] ?? '');
            if ($cid !== '') {
                $sourceContextIds[] = $cid;
            }
            $ss = (string)($ctx['source_strategy'] ?? '');
            if ($ss !== '' && !in_array($ss, $sourceStrategies, true)) {
                $sourceStrategies[] = $ss;
            }
            $sid = (string)($ctx['source_signal_id'] ?? '');
            if ($sid !== '' && !in_array($sid, $sourceSignalIds, true)) {
                $sourceSignalIds[] = $sid;
            }
        }

        if (empty($triggeringContexts)) {
            return null;
        }

        // Compute rule-specific confirmations
        $confirmations = $this->computeConfirmations($rule, $triggeringContexts);
        $confCount     = count($confirmations);

        // Base confidence
        $score = 0.40;

        // +0.10 per confirmation, capped at +0.30
        $score += min(0.30, $confCount * 0.10);

        // +0.10 if multiple source contexts agree
        if (count($triggeringContexts) >= 2) {
            $score += 0.10;
        }

        // +0.05 if source strategy score was high but long failed (quality context)
        foreach ($triggeringContexts as $ctx) {
            $sqScore = (float)($ctx['synthetic_quality_score'] ?? 0.0);
            if ($sqScore >= 0.70) {
                $score += 0.05;
                break;
            }
        }

        // +0.05 if close_roi / deep loss confirms failed long
        foreach ($triggeringContexts as $ctx) {
            $roi = $ctx['close_roi'] ?? $ctx['roi'] ?? null;
            if ($roi !== null && (float)$roi < -5.0) {
                $score += 0.05;
                break;
            }
        }

        // -0.10 if context is stale (within age window but close to cutoff)
        $cutoff = $now - ((int)($config['context_max_age_minutes'] ?? 180) * 60);
        foreach ($triggeringContexts as $ctx) {
            $observedAt = $ctx['observed_at'] ?? null;
            if ($observedAt !== null) {
                $ts = is_int($observedAt) ? $observedAt : (int)strtotime((string)$observedAt);
                $age = $now - $ts;
                if ($age > (int)(($config['context_max_age_minutes'] ?? 180) * 60 * 0.75)) {
                    // Context is older than 75% of max age — treat as somewhat stale
                    $score -= 0.10;
                    $warnings[] = 'context_near_stale';
                    break;
                }
            }
        }

        // -0.10 if only one weak context (single confirming context with low quality)
        if (count($triggeringContexts) === 1) {
            $singleCtx = $triggeringContexts[0];
            $sqScore   = (float)($singleCtx['synthetic_quality_score'] ?? 0.0);
            $cqScore   = (float)($singleCtx['candidate_quality_score'] ?? 0.0);
            if ($sqScore < 0.50 && $cqScore < 0.50) {
                $score -= 0.10;
                $warnings[] = 'single_weak_context';
            }
        }

        // Clamp to [0.0, 1.0]
        $score = max(0.0, min(1.0, $score));

        // Collect reason codes from contexts
        foreach ($triggeringContexts as $ctx) {
            $rcs = (array)($ctx['reason_codes'] ?? []);
            foreach ($rcs as $rc) {
                if (is_string($rc) && $rc !== '' && !in_array($rc, $reasonCodes, true)) {
                    $reasonCodes[] = $rc;
                }
            }
        }

        // ── Resolve entry price from triggering contexts ──────────────────────
        $priceResolution    = $this->resolveCandidateEntryPrice($rule, $triggeringContexts);
        $sourceContextTypes = array_values(array_unique(array_map(
            static fn(array $ctx): string => (string)($ctx['context_type'] ?? ''),
            $triggeringContexts
        )));

        return [
            'symbol'              => $symbol,
            'side'                => 'short',
            'dynamic_rule'        => $rule,
            'source_context_ids'  => array_values(array_unique($sourceContextIds)),
            'source_strategies'   => array_values($sourceStrategies),
            'source_signal_ids'   => array_values($sourceSignalIds),
            'source_context_types' => $sourceContextTypes,
            'confirmations'       => $confirmations,
            'confirmation_count'  => $confCount,
            'confidence_score'    => round($score, 4),
            'suggested_mode'      => 'demo',
            // Entry price resolved from source contexts
            'entry_price'         => $priceResolution['entry_price'],
            'entry_price_source'  => $priceResolution['entry_price_source'],
            'candidate_trigger'   => $priceResolution['candidate_trigger'],
            'source_prices'       => $priceResolution['source_prices'],
            'source_levels'       => $priceResolution['source_levels'],
            // Pre-signal diagnostic state — executable/handoff_ready are set later
            // after threshold evaluation in the signal-building loop.
            'executable'          => false,
            'handoff_ready'       => false,
            'diagnostic_only'     => true,
            // Deprecated compat field — always false; use mode/handoff_enabled instead
            'deprecated_shadow_only' => false,
            'warnings'            => $warnings,
            'reason_codes'        => $reasonCodes,
            'evaluated_at'        => date('c'),
            'context_count'       => count($triggeringContexts),
        ];
    }

    /**
     * Check if a context triggers a given rule.
     */
    private function contextTriggersRule(string $rule, array $ctx): bool
    {
        $ctxType = (string)($ctx['context_type'] ?? '');

        // Direct type match
        $typesForRule = self::RULE_CONTEXT_MAP[$rule] ?? [];
        if (in_array($ctxType, $typesForRule, true)) {
            return true;
        }

        // Field-level signal matches (rule-specific secondary triggers)
        switch ($rule) {
            case 'falling_knife_short_watch':
                return (bool)($ctx['falling_knife'] ?? false)
                    || (bool)($ctx['fresh_lower_low_after_reclaim'] ?? false)
                    || (bool)($ctx['fast_red_candle_after_setup'] ?? false);

            case 'failed_reclaim_short_watch':
                return (bool)($ctx['reclaim_lost'] ?? false)
                    || (bool)($ctx['neckline_lost'] ?? false);

            case 'base_breakdown_short_watch':
                return (bool)($ctx['base_support_broken'] ?? false);

            case 'failed_pending_breakdown_short_watch':
                // Pending-invalidated contexts with directional breakdown fields
                if (str_starts_with($ctxType, 'pending_invalidated_')) {
                    return (bool)($ctx['reclaim_lost'] ?? false)
                        || (bool)($ctx['base_support_broken'] ?? false)
                        || (bool)($ctx['falling_knife'] ?? false)
                        || str_contains((string)($ctx['reject_reason'] ?? ''), 'fresh_dump');
                }
                return false;

            case 'failed_long_after_entry_short_watch':
                $roi = $ctx['close_roi'] ?? $ctx['roi'] ?? null;
                if ($roi !== null && (float)$roi < -3.0) {
                    return true;
                }
                $closeGuard  = (string)($ctx['close_guard']  ?? '');
                $closeReason = (string)($ctx['close_reason'] ?? '');
                return $closeGuard !== '' || str_contains($closeReason, 'fail')
                    || str_contains($closeReason, 'stop')
                    || str_contains($closeReason, 'loss');

            case 'late_exhaustion_short_watch':
                return (bool)($ctx['fast_red_candle_after_setup'] ?? false)
                    && ((bool)($ctx['reclaim_lost'] ?? false) || (bool)($ctx['neckline_lost'] ?? false));
        }

        return false;
    }

    /**
     * Compute confirmation signals for a given rule from the triggering contexts.
     * Returns a flat list of confirmation label strings.
     */
    private function computeConfirmations(string $rule, array $ctxList): array
    {
        $confirmations = [];

        // Helper: add unique confirmation
        $add = static function (string $label) use (&$confirmations): void {
            if (!in_array($label, $confirmations, true)) {
                $confirmations[] = $label;
            }
        };

        // Common field checks across all rules
        $hasField = static function (string $field, bool $value = true) use ($ctxList): bool {
            foreach ($ctxList as $ctx) {
                if ((bool)($ctx[$field] ?? false) === $value) {
                    return true;
                }
            }
            return false;
        };

        $hasPriceBelow = static function (string $priceField, string $levelField) use ($ctxList): bool {
            foreach ($ctxList as $ctx) {
                $price = $ctx[$priceField] ?? null;
                $level = $ctx[$levelField] ?? null;
                if ($price !== null && $level !== null && (float)$price > 0 && (float)$level > 0) {
                    if ((float)$price < (float)$level) {
                        return true;
                    }
                }
            }
            return false;
        };

        switch ($rule) {
            case 'falling_knife_short_watch':
                if ($hasField('falling_knife')) {
                    $add('falling_knife_confirmed');
                }
                if ($hasField('fresh_lower_low_after_reclaim')) {
                    $add('fresh_lower_low_after_reclaim');
                }
                if ($hasField('fast_red_candle_after_setup')) {
                    $add('fast_red_candle_after_setup');
                }
                if ($hasPriceBelow('current_price', 'reclaim_level')) {
                    $add('price_below_reclaim');
                }
                if ($hasPriceBelow('current_price', 'neckline_level')) {
                    $add('price_below_neckline');
                }
                break;

            case 'failed_reclaim_short_watch':
                if ($hasField('reclaim_lost')) {
                    $add('reclaim_lost');
                }
                if ($hasField('neckline_lost')) {
                    $add('neckline_lost');
                }
                if ($hasPriceBelow('current_price', 'reclaim_level')) {
                    $add('price_below_reclaim');
                }
                if ($hasField('fresh_lower_low_after_reclaim')) {
                    $add('fresh_lower_low');
                }
                break;

            case 'base_breakdown_short_watch':
                if ($hasField('base_support_broken')) {
                    $add('base_support_broken');
                }
                if ($hasPriceBelow('current_price', 'base_low')) {
                    $add('price_below_base_low');
                }
                if ($hasField('fast_red_candle_after_setup')) {
                    $add('fast_red_candle');
                }
                break;

            case 'failed_pending_breakdown_short_watch':
                // Pending invalidated type is itself a confirmation
                foreach ($ctxList as $ctx) {
                    if (str_starts_with((string)($ctx['context_type'] ?? ''), 'pending_invalidated_')) {
                        $add('pending_setup_invalidated');
                        break;
                    }
                }
                if ($hasField('reclaim_lost')) {
                    $add('reclaim_lost');
                }
                if ($hasField('base_support_broken')) {
                    $add('base_support_broken');
                }
                if ($hasField('falling_knife')) {
                    $add('falling_knife');
                }
                // Check for fresh_dump in reject reason
                foreach ($ctxList as $ctx) {
                    if (str_contains((string)($ctx['reject_reason'] ?? ''), 'fresh_dump')) {
                        $add('fresh_dump_detected');
                        break;
                    }
                }
                break;

            case 'failed_long_after_entry_short_watch':
                // Negative ROI confirms failed long
                foreach ($ctxList as $ctx) {
                    $roi = $ctx['close_roi'] ?? $ctx['roi'] ?? null;
                    if ($roi !== null && (float)$roi < -3.0) {
                        $add('negative_roi_confirmed');
                        break;
                    }
                }
                // Close guard / reason
                foreach ($ctxList as $ctx) {
                    $cg = (string)($ctx['close_guard'] ?? '');
                    if ($cg !== '') {
                        $add('close_guard_triggered');
                        break;
                    }
                }
                foreach ($ctxList as $ctx) {
                    $cr = (string)($ctx['close_reason'] ?? '');
                    if (str_contains($cr, 'early_fail') || str_contains($cr, 'emergency_stop')) {
                        $add('protective_close_reason');
                        break;
                    }
                }
                // Price below entry / reclaim
                if ($hasPriceBelow('current_price', 'entry_price')) {
                    $add('price_below_entry');
                }
                if ($hasPriceBelow('current_price', 'reclaim_level')) {
                    $add('price_below_reclaim');
                }
                break;

            case 'late_exhaustion_short_watch':
                if ($hasField('fast_red_candle_after_setup')) {
                    $add('fast_red_candle');
                }
                if ($hasField('reclaim_lost')) {
                    $add('reclaim_lost');
                }
                if ($hasField('neckline_lost')) {
                    $add('neckline_lost');
                }
                // Check direct late_exhaustion type
                foreach ($ctxList as $ctx) {
                    $t = (string)($ctx['context_type'] ?? '');
                    if (str_contains($t, 'late_exhaustion')) {
                        $add('late_exhaustion_detected');
                        break;
                    }
                }
                break;
        }

        return $confirmations;
    }

    // ── Entry price resolution ────────────────────────────────────────────────

    /**
     * Resolve the best entry_price for a dynamic candidate from its triggering
     * source contexts, using a conservative fallback chain.
     *
     * Fallback order for short candidates:
     *   1. current_price          — live tradable context price
     *   2. price_at_invalidation  — price when the setup was invalidated
     *   3. price_at_loss          — price when loss was recorded
     *   4. hypothetical_entry_price — from replay analysis
     *   5. entry_price            — source context entry price (original long entry)
     *
     * Returns an array with:
     *   entry_price         float|null
     *   entry_price_source  string|null
     *   candidate_trigger   string|null   — the level/event that triggered the rule
     *   source_prices       array         — keyed lists of all found prices per field
     *   source_levels       array         — structural levels (neckline, reclaim, base)
     *   source_warnings     array
     *   source_reason_codes array
     */
    private function resolveCandidateEntryPrice(string $rule, array $triggeringContexts): array
    {
        $sourcePrices     = [];
        $sourceLevels     = [];
        $sourceWarnings   = [];
        $sourceReasonCodes = [];

        foreach ($triggeringContexts as $ctx) {
            // ── price fields ──────────────────────────────────────────────────
            $fields = [
                'current_price',
                'price_at_invalidation',
                'price_at_loss',
                'hypothetical_entry_price',
                'entry_price',
            ];
            foreach ($fields as $f) {
                $v = $ctx[$f] ?? null;
                if ($v !== null && is_numeric($v) && (float)$v > 0.0) {
                    $sourcePrices[$f][] = (float)$v;
                }
            }

            // ── structural levels ─────────────────────────────────────────────
            foreach (['neckline_level', 'reclaim_level', 'base_low', 'base_high'] as $lf) {
                $lv = $ctx[$lf] ?? null;
                if ($lv !== null && is_numeric($lv) && (float)$lv > 0.0) {
                    // Keep the first non-null value per level field
                    if (!isset($sourceLevels[$lf])) {
                        $sourceLevels[$lf] = (float)$lv;
                    }
                }
            }

            // ── warnings / reason_codes from contexts ─────────────────────────
            foreach ((array)($ctx['warnings']    ?? []) as $w) {
                if (is_string($w) && $w !== '' && !in_array($w, $sourceWarnings, true)) {
                    $sourceWarnings[] = $w;
                }
            }
            foreach ((array)($ctx['reason_codes'] ?? []) as $rc) {
                if (is_string($rc) && $rc !== '' && !in_array($rc, $sourceReasonCodes, true)) {
                    $sourceReasonCodes[] = $rc;
                }
            }
        }

        // ── Candidate trigger ─────────────────────────────────────────────────
        $candidateTrigger = match ($rule) {
            'failed_reclaim_short_watch'          =>
                isset($sourceLevels['reclaim_level'])  ? 'reclaim_level'
                : (isset($sourceLevels['neckline_level']) ? 'neckline_level' : 'failed_reclaim'),
            'base_breakdown_short_watch'          =>
                isset($sourceLevels['base_low']) ? 'base_low' : 'base_support_broken',
            'falling_knife_short_watch'           => 'active_downtrend',
            'failed_pending_breakdown_short_watch' => 'pending_setup_invalidated',
            'failed_long_after_entry_short_watch' => 'failed_long_entry',
            'late_exhaustion_short_watch'         => 'late_exhaustion',
            default                               => null,
        };

        // ── Resolve entry_price via fallback chain ────────────────────────────
        $entryPrice       = null;
        $entryPriceSource = null;

        $fallbackChain = [
            'current_price',
            'price_at_invalidation',
            'price_at_loss',
            'hypothetical_entry_price',
            'entry_price',
        ];
        foreach ($fallbackChain as $f) {
            $v = $sourcePrices[$f][0] ?? null;
            if ($v !== null && $v > 0.0) {
                $entryPrice       = $v;
                $entryPriceSource = $f;
                break;
            }
        }

        return [
            'entry_price'        => $entryPrice,
            'entry_price_source' => $entryPriceSource,
            'candidate_trigger'  => $candidateTrigger,
            'source_prices'      => $sourcePrices,
            'source_levels'      => $sourceLevels,
            'source_warnings'    => $sourceWarnings,
            'source_reason_codes' => $sourceReasonCodes,
        ];
    }

    // ── Context classification ─────────────────────────────────────────────────

    /**
     * Classify a context as 'useful' or 'noise'.
     * Noise contexts do not contribute to any dynamic rule.
     */
    private function classifyContext(array $ctx): string
    {
        $ctxType = (string)($ctx['context_type'] ?? '');

        // Explicit noise types
        foreach (self::NOISE_CONTEXT_TYPES as $noiseType) {
            if ($ctxType === $noiseType || str_starts_with($ctxType, $noiseType)) {
                return 'noise';
            }
        }

        // no_post_dump_detected without any directional fields
        if ($ctxType === 'no_post_dump_detected') {
            $hasDirectional = (bool)($ctx['falling_knife'] ?? false)
                || (bool)($ctx['reclaim_lost'] ?? false)
                || (bool)($ctx['base_support_broken'] ?? false)
                || (bool)($ctx['fresh_lower_low_after_reclaim'] ?? false)
                || (bool)($ctx['fast_red_candle_after_setup'] ?? false);
            if (!$hasDirectional) {
                return 'noise';
            }
        }

        // Missing symbol
        if ((string)($ctx['symbol'] ?? '') === '') {
            return 'noise';
        }

        // Check if context triggers at least one rule
        foreach (array_keys(self::RULE_CONTEXT_MAP) as $rule) {
            if ($this->contextTriggersRule($rule, $ctx)) {
                return 'useful';
            }
        }

        // Context type matches any rule type map
        foreach (self::RULE_CONTEXT_MAP as $types) {
            if (in_array($ctxType, $types, true)) {
                return 'useful';
            }
        }

        return 'noise';
    }

    // ── Rejected-context short replay analyzer ───────────────────────────────

    /**
     * Analyze directional rejected contexts from input_contexts.json to measure
     * whether hypothetical short entries (with a 3-minute confirmation pause)
     * would have been profitable.
     *
     * SAFETY: diagnostics/replay only.
     * - No orders opened.
     * - No handoff queue entries written.
     * - No live or demo signals emitted.
     * - Source strategy behavior unchanged.
     *
     * Writes:
     *   storage/rejected_context_replay.json
     *   storage/rejected_context_replay_summary.json
     *
     * Returns: stats subset merged into last_run.json by execute().
     */
    private function computeRejectedContextReplay(array $config, array $allContexts): array
    {
        if (!(bool)($config['replay_enabled'] ?? true)) {
            return ['replay_enabled' => false];
        }

        $replayMaxAgeMin = (int)($config['replay_context_max_age_minutes'] ?? 480);
        $replayPauseMin  = (int)($config['replay_pause_minutes']           ?? 3);
        $replayMaxCtx    = (int)($config['replay_max_contexts']            ?? 500);
        $now             = time();
        $replayCutoff    = $now - ($replayMaxAgeMin * 60);

        // ── 1. Filter to directional contexts within replay age window ─────────
        $replayContexts = [];
        $noiseSkipped   = 0;

        foreach ($allContexts as $ctx) {
            if (!is_array($ctx)) {
                continue;
            }

            $symbol = (string)($ctx['symbol'] ?? '');
            if ($symbol === '') {
                $noiseSkipped++;
                continue;
            }

            $observedAt = $ctx['observed_at'] ?? null;
            if ($observedAt !== null) {
                $ts = is_int($observedAt) ? $observedAt : (int)strtotime((string)$observedAt);
                if ($ts > 0 && $ts < $replayCutoff) {
                    continue; // older than replay window — skip silently
                }
            }

            $ctxType       = (string)($ctx['context_type'] ?? '');
            $isDirectional = in_array($ctxType, self::DIRECTIONAL_CONTEXT_TYPES, true)
                || (bool)($ctx['falling_knife']                 ?? false)
                || (bool)($ctx['reclaim_lost']                  ?? false)
                || (bool)($ctx['neckline_lost']                 ?? false)
                || (bool)($ctx['base_support_broken']           ?? false)
                || (bool)($ctx['fresh_lower_low_after_reclaim'] ?? false)
                || (bool)($ctx['fast_red_candle_after_setup']   ?? false);

            if (!$isDirectional) {
                $noiseSkipped++;
                continue;
            }

            $replayContexts[] = $ctx;
        }

        // Cap to replay_max_contexts (take most recent)
        if (count($replayContexts) > $replayMaxCtx) {
            $replayContexts = array_slice($replayContexts, -$replayMaxCtx);
        }

        // ── 2. Frequency stats by context type ────────────────────────────────
        $typeFreq = [];
        foreach ($replayContexts as $ctx) {
            $ctxType = (string)($ctx['context_type'] ?? 'unknown');
            $symbol  = (string)($ctx['symbol']       ?? '');
            if (!isset($typeFreq[$ctxType])) {
                $typeFreq[$ctxType] = ['count' => 0, 'symbols' => [], 'examples' => []];
            }
            $typeFreq[$ctxType]['count']++;
            if ($symbol !== '' && !in_array($symbol, $typeFreq[$ctxType]['symbols'], true)) {
                $typeFreq[$ctxType]['symbols'][] = $symbol;
            }
            if (count($typeFreq[$ctxType]['examples']) < 3) {
                $typeFreq[$ctxType]['examples'][] = [
                    'symbol'     => $symbol,
                    'context_id' => $ctx['context_id'] ?? null,
                    'observed_at' => $ctx['observed_at'] ?? null,
                ];
            }
        }

        // Useful types = those in DIRECTIONAL_CONTEXT_TYPES
        $usefulTypeFreq = array_filter(
            $typeFreq,
            fn(string $k) => in_array($k, self::DIRECTIONAL_CONTEXT_TYPES, true),
            ARRAY_FILTER_USE_KEY
        );

        // Sort both by count descending
        uasort($typeFreq,       fn(array $a, array $b) => $b['count'] - $a['count']);
        uasort($usefulTypeFreq, fn(array $a, array $b) => $b['count'] - $a['count']);
        $topUsefulTypes = array_slice(array_keys($usefulTypeFreq), 0, 5);

        // ── 3–6. Replay each context with 3-min confirmation + trend windows ──
        $candleStorageDir        = $this->repoRoot . '/' . ltrim(
            (string)($config['replay_candle_storage_dir'] ?? 'modules/parser/parser2_history_accumulator/storage'),
            '/'
        );

        $replayEntries           = [];
        $candlesUnavailableTotal = 0;
        $shortCandidatesTotal    = 0;
        $shortRejectedTotal      = 0;

        foreach ($replayContexts as $ctx) {
            $symbol     = (string)($ctx['symbol']       ?? '');
            $ctxType    = (string)($ctx['context_type'] ?? 'unknown');
            $observedAt = $ctx['observed_at'] ?? null;
            $observedTs = $observedAt !== null
                ? (is_int($observedAt) ? $observedAt : (int)strtotime((string)$observedAt))
                : 0;

            if ($observedTs <= 0) {
                continue;
            }

            $confirmationTs = $observedTs + ($replayPauseMin * 60);

            // Dedupe by (symbol + ctxType + observedTs)
            $replayId = 'rpl_' . substr(md5($symbol . '_' . $ctxType . '_' . $observedTs), 0, 12);

            // Extract key levels from context
            $reclaimLevel = (float)($ctx['reclaim_level'] ?? $ctx['neckline_level'] ?? 0.0);
            $baseLevel    = (float)($ctx['base_low']      ?? $ctx['support_level']  ?? 0.0);
            $entryPrice   = (float)($ctx['current_price'] ?? $ctx['price_at_rejection'] ?? 0.0);

            // Load candle price points covering observed_at .. confirmation_time + 61 min
            $candleFrom = $observedTs - 60;
            $candleTo   = $confirmationTs + (61 * 60);
            $candles    = $this->loadSymbolCandlePoints($symbol, $candleStorageDir, $candleFrom, $candleTo);

            if (empty($candles)) {
                $candlesUnavailableTotal++;
                $replayEntries[] = [
                    'replay_id'                => $replayId,
                    'symbol'                   => $symbol,
                    'context_type'             => $ctxType,
                    'source_strategy'          => (string)($ctx['source_strategy']  ?? 'double_bottom_long'),
                    'source_signal_id'         => $ctx['source_signal_id'] ?? null,
                    'observed_at'              => date('c', $observedTs),
                    'confirmation_time'        => date('c', $confirmationTs),
                    'hypothetical_side'        => 'short',
                    'hypothetical_entry_price' => $entryPrice > 0.0 ? $entryPrice : null,
                    'status'                   => 'skipped_candles_unavailable',
                ];
                continue;
            }

            // Determine entry price from candles if not in context
            if ($entryPrice <= 0.0) {
                $entryPrice = $this->closestCandlePrice($candles, $observedTs);
            }

            // ── 3. 3-minute confirmation pause ─────────────────────────────────
            $pauseCheck = $this->computeShortPauseConfirmation(
                $candles, $observedTs, $confirmationTs, $reclaimLevel, $baseLevel
            );

            if (!($pauseCheck['passed'] ?? false)) {
                $shortRejectedTotal++;
                $replayEntries[] = [
                    'replay_id'                => $replayId,
                    'symbol'                   => $symbol,
                    'context_type'             => $ctxType,
                    'source_strategy'          => (string)($ctx['source_strategy'] ?? 'double_bottom_long'),
                    'source_signal_id'         => $ctx['source_signal_id'] ?? null,
                    'observed_at'              => date('c', $observedTs),
                    'confirmation_time'        => date('c', $confirmationTs),
                    'hypothetical_side'        => 'short',
                    'hypothetical_entry_price' => $entryPrice > 0.0 ? round($entryPrice, 8) : null,
                    'confirmations'            => $pauseCheck,
                    'status'                   => 'replay_rejected',
                    'reject_reason'            => $pauseCheck['reject_reason'] ?? 'pause_confirmation_failed',
                ];
                continue;
            }

            // ── 4. Trend windows ───────────────────────────────────────────────
            $trend5m  = $this->computeShortTrendWindow($candles, $confirmationTs,  5 * 60);
            $trend15m = $this->computeShortTrendWindow($candles, $confirmationTs, 15 * 60);
            $trend30m = $this->computeShortTrendWindow($candles, $confirmationTs, 30 * 60);

            // Short confirmation passes when trends are bearish or neutral-bearish
            $trendConfirmed = ($trend5m['lower_close']  ?? false)
                || ($trend15m['lower_close'] ?? false)
                || ($trend30m['lower_close'] ?? false);

            if (!$trendConfirmed) {
                $shortRejectedTotal++;
                $replayEntries[] = [
                    'replay_id'                => $replayId,
                    'symbol'                   => $symbol,
                    'context_type'             => $ctxType,
                    'source_strategy'          => (string)($ctx['source_strategy'] ?? 'double_bottom_long'),
                    'source_signal_id'         => $ctx['source_signal_id'] ?? null,
                    'observed_at'              => date('c', $observedTs),
                    'confirmation_time'        => date('c', $confirmationTs),
                    'hypothetical_side'        => 'short',
                    'hypothetical_entry_price' => $entryPrice > 0.0 ? round($entryPrice, 8) : null,
                    'confirmations'            => $pauseCheck,
                    'trend_5m'                 => $trend5m,
                    'trend_15m'               => $trend15m,
                    'trend_30m'               => $trend30m,
                    'status'                   => 'replay_rejected',
                    'reject_reason'            => 'trend_not_bearish',
                ];
                continue;
            }

            // ── 5–6. Short candidate with forward outcomes ─────────────────────
            $shortCandidatesTotal++;

            $forwardOutcomes = [];
            foreach ([5, 10, 15, 30, 60] as $minutes) {
                $forwardOutcomes[$minutes . 'm'] = $this->computeShortForwardOutcome(
                    $candles, $confirmationTs, $minutes * 60, $entryPrice
                );
            }

            $keyLevels = array_filter([
                'reclaim' => $reclaimLevel > 0.0 ? $reclaimLevel : null,
                'base'    => $baseLevel    > 0.0 ? $baseLevel    : null,
            ]);

            $replayEntries[] = [
                'replay_id'                => $replayId,
                'symbol'                   => $symbol,
                'context_type'             => $ctxType,
                'source_strategy'          => (string)($ctx['source_strategy'] ?? 'double_bottom_long'),
                'source_signal_id'         => $ctx['source_signal_id'] ?? null,
                'observed_at'              => date('c', $observedTs),
                'confirmation_time'        => date('c', $confirmationTs),
                'hypothetical_side'        => 'short',
                'hypothetical_entry_price' => $entryPrice > 0.0 ? round($entryPrice, 8) : null,
                'confirmations'            => $pauseCheck,
                'confirmation_count'       => $pauseCheck['count'] ?? 0,
                'trend_5m'                 => $trend5m,
                'trend_15m'               => $trend15m,
                'trend_30m'               => $trend30m,
                'key_levels'               => !empty($keyLevels) ? $keyLevels : null,
                'confidence_score'         => $pauseCheck['confidence_score'] ?? 0.0,
                'forward_outcomes'         => $forwardOutcomes,
                'status'                   => 'replay_short_candidate',
            ];
        }

        // ── 7. Write replay files ──────────────────────────────────────────────
        $this->writeJson('storage/rejected_context_replay.json', $replayEntries);

        // ── 8. Summary by context type ─────────────────────────────────────────
        $summary = $this->buildReplaySummary($replayEntries);
        $this->writeJson('storage/rejected_context_replay_summary.json', array_values($summary));

        // Best = highest avg_forward_15m_roi_10x; worst = lowest (or negative)
        $ranked = $summary;
        usort($ranked, fn(array $a, array $b) => ($b['avg_forward_15m_roi_10x'] ?? -INF) <=> ($a['avg_forward_15m_roi_10x'] ?? -INF));
        $bestTypes  = array_slice(array_map(fn(array $r) => $r['context_type'], array_filter($ranked, fn(array $r) => $r['avg_forward_15m_roi_10x'] !== null)), 0, 3);
        $worstTypes = array_slice(array_map(fn(array $r) => $r['context_type'], array_reverse($ranked)), 0, 3);

        return [
            'replay_enabled'                          => true,
            'replay_contexts_loaded_total'            => count($allContexts),
            'replay_useful_contexts_total'            => count($replayContexts),
            'replay_skipped_noise_total'              => $noiseSkipped,
            'replay_skipped_candles_unavailable_total' => $candlesUnavailableTotal,
            'replay_short_candidates_total'           => $shortCandidatesTotal,
            'replay_short_rejected_total'             => $shortRejectedTotal,
            'top_useful_rejected_context_types'       => $topUsefulTypes,
            'best_replay_context_types'               => $bestTypes,
            'worst_replay_context_types'              => $worstTypes,
            'rejected_context_type_frequency'         => array_values(array_map(
                fn(string $k, array $v) => array_merge(['context_type' => $k], $v),
                array_keys($typeFreq), $typeFreq
            )),
            'useful_rejected_context_type_frequency'  => array_values(array_map(
                fn(string $k, array $v) => array_merge(['context_type' => $k], $v),
                array_keys($usefulTypeFreq), $usefulTypeFreq
            )),
        ];
    }

    /**
     * Load price points for a symbol from parser2 NDJSON storage.
     * Returns ascending time-sorted deduplicated [{ts_unix, price}] array.
     * Returns empty array when storage is unavailable (graceful degradation).
     */
    private function loadSymbolCandlePoints(string $symbol, string $storageDir, int $fromTs, int $toTs): array
    {
        $symbolDir = rtrim($storageDir, '/') . '/' . strtoupper($symbol);
        if (!is_dir($symbolDir)) {
            return [];
        }

        $points  = [];
        $cursor  = $fromTs;
        $covered = [];

        while ($cursor <= $toTs) {
            $dateStr = date('Y-m-d', $cursor);
            if (!in_array($dateStr, $covered, true)) {
                $covered[] = $dateStr;
                $file = $symbolDir . '/' . $dateStr . '.ndjson';
                if (is_file($file) && is_readable($file)) {
                    $handle = @fopen($file, 'r');
                    if ($handle !== false) {
                        while (($line = fgets($handle)) !== false) {
                            $line = trim($line);
                            if ($line === '') {
                                continue;
                            }
                            $rec = json_decode($line, true);
                            if (!is_array($rec)) {
                                continue;
                            }
                            $ts = isset($rec['ts_unix']) ? (int)$rec['ts_unix'] : 0;
                            if ($ts < $fromTs || $ts > $toTs + 60) {
                                continue;
                            }
                            $price = (float)($rec['last_price'] ?? $rec['price'] ?? $rec['close'] ?? 0.0);
                            if ($price <= 0.0) {
                                continue;
                            }
                            $points[] = ['ts_unix' => $ts, 'price' => $price];
                        }
                        fclose($handle);
                    }
                }
            }
            $cursor += 86400;
        }

        if (empty($points)) {
            return [];
        }

        usort($points, fn(array $a, array $b) => $a['ts_unix'] <=> $b['ts_unix']);

        // Deduplicate by ts_unix, keeping last value at each timestamp
        $deduped = [];
        foreach ($points as $p) {
            $deduped[$p['ts_unix']] = $p;
        }
        return array_values($deduped);
    }

    /**
     * Return the price from the candle closest in time to the given timestamp.
     * Returns 0.0 if candles is empty.
     */
    private function closestCandlePrice(array $candles, int $ts): float
    {
        $closest = 0.0;
        $minDiff = PHP_INT_MAX;
        foreach ($candles as $p) {
            $diff = abs($p['ts_unix'] - $ts);
            if ($diff < $minDiff) {
                $minDiff = $diff;
                $closest = (float)$p['price'];
            }
        }
        return $closest;
    }

    /**
     * Evaluate the 3-minute confirmation pause for a hypothetical short.
     * Checks that price did not recover strongly during the pause window.
     */
    private function computeShortPauseConfirmation(
        array $candles,
        int   $observedTs,
        int   $confirmationTs,
        float $reclaimLevel,
        float $baseLevel
    ): array {
        $priceAtObserved     = $this->closestCandlePrice($candles, $observedTs);
        $priceAtConfirmation = $this->closestCandlePrice($candles, $confirmationTs);

        if ($priceAtObserved <= 0.0 || $priceAtConfirmation <= 0.0) {
            return [
                'passed'           => false,
                'reject_reason'    => 'no_price_data_at_confirmation',
                'count'            => 0,
                'confidence_score' => 0.0,
            ];
        }

        $priceDelta      = ($priceAtConfirmation - $priceAtObserved) / $priceAtObserved;
        $strongRecovery  = $priceDelta > 0.005; // +0.5% recovery during pause = not a short setup

        $checks    = [];
        $passCount = 0;

        // Check 1: no strong recovery during the pause
        $noStrongRecovery = !$strongRecovery;
        $checks['no_strong_recovery_in_pause'] = $noStrongRecovery;
        if ($noStrongRecovery) {
            $passCount++;
        }

        // Check 2: price still bearish (not going up noticeably)
        $stillBearish = $priceDelta <= 0.002; // allow at most +0.2% drift
        $checks['still_bearish_direction'] = $stillBearish;
        if ($stillBearish) {
            $passCount++;
        }

        // Check 3 (optional): price still below reclaim level
        if ($reclaimLevel > 0.0) {
            $belowReclaim = $priceAtConfirmation < $reclaimLevel;
            $checks['price_below_reclaim_at_confirmation'] = $belowReclaim;
            if ($belowReclaim) {
                $passCount++;
            }
        }

        // Check 4 (optional): price still below base support level
        if ($baseLevel > 0.0) {
            $belowBase = $priceAtConfirmation < $baseLevel;
            $checks['price_below_base_at_confirmation'] = $belowBase;
            if ($belowBase) {
                $passCount++;
            }
        }

        $passed = $noStrongRecovery && $stillBearish;
        $confidenceScore = round(min(1.0, 0.35 + $passCount * 0.15), 4);

        return [
            'passed'                => $passed,
            'reject_reason'         => $passed ? null : ($strongRecovery ? 'strong_recovery_during_pause' : 'price_not_bearish'),
            'count'                 => $passCount,
            'confidence_score'      => $confidenceScore,
            'price_at_observed'     => round($priceAtObserved, 8),
            'price_at_confirmation' => round($priceAtConfirmation, 8),
            'price_change_pct'      => round($priceDelta * 100, 4),
            'checks'                => $checks,
        ];
    }

    /**
     * Compute short-side trend statistics for a price window starting at fromTs
     * with the given duration in seconds.
     */
    private function computeShortTrendWindow(array $candles, int $fromTs, int $durationSeconds): array
    {
        $toTs   = $fromTs + $durationSeconds;
        $window = array_values(array_filter($candles, fn(array $p) => $p['ts_unix'] >= $fromTs && $p['ts_unix'] <= $toTs));

        if (count($window) < 2) {
            return ['data_available' => false, 'candle_count' => count($window)];
        }

        $firstPrice = (float)$window[0]['price'];
        $lastPrice  = (float)$window[count($window) - 1]['price'];
        $prices     = array_column($window, 'price');
        $highPrice  = (float)max($prices);
        $lowPrice   = (float)min($prices);

        $priceChangePct = ($lastPrice - $firstPrice) / $firstPrice * 100.0;
        $lowerClose     = $lastPrice < $firstPrice;

        // Red candle bias: fraction of steps where price declined
        $redCount = 0;
        $cnt      = count($window);
        for ($i = 1; $i < $cnt; $i++) {
            if ((float)$window[$i]['price'] < (float)$window[$i - 1]['price']) {
                $redCount++;
            }
        }
        $redCandleBias = $cnt > 1 ? ($redCount / ($cnt - 1)) > 0.5 : false;

        // No strong recovery: high never exceeded first price by more than 0.3%
        $noStrongRecovery = $highPrice <= $firstPrice * 1.003;

        return [
            'data_available'     => true,
            'candle_count'       => $cnt,
            'price_change_pct'   => round($priceChangePct, 4),
            'lower_close'        => $lowerClose,
            'no_strong_recovery' => $noStrongRecovery,
            'red_candle_bias'    => $redCandleBias,
            'first_price'        => round($firstPrice, 8),
            'last_price'         => round($lastPrice, 8),
            'high'               => round($highPrice, 8),
            'low'                => round($lowPrice, 8),
            'bearish'            => $lowerClose && $redCandleBias,
        ];
    }

    /**
     * Compute hypothetical short forward outcome metrics for a window starting
     * at confirmationTs with the given duration in seconds.
     *
     * For short positions: favorable = price goes down, adverse = price goes up.
     * ROI calculated at 5×, 10×, 15× leverage based on close-price move.
     */
    private function computeShortForwardOutcome(
        array $candles,
        int   $fromTs,
        int   $durationSeconds,
        float $entryPrice
    ): array {
        $toTs   = $fromTs + $durationSeconds;
        $window = array_values(array_filter($candles, fn(array $p) => $p['ts_unix'] >= $fromTs && $p['ts_unix'] <= $toTs));

        if (empty($window) || $entryPrice <= 0.0) {
            return ['data_available' => false];
        }

        $prices    = array_column($window, 'price');
        $minPrice  = (float)min($prices);
        $maxPrice  = (float)max($prices);
        $closePrice = (float)end($prices);

        // Short: favorable move = price fell below entry
        $maxFavorablePct = ($entryPrice - $minPrice) / $entryPrice * 100.0;
        // Short: adverse move = price rose above entry
        $maxAdversePct   = ($maxPrice - $entryPrice)  / $entryPrice * 100.0;
        // Close P&L for short
        $closePct        = ($entryPrice - $closePrice) / $entryPrice * 100.0;

        // ROI at various leverages
        $roiAt5x  = $closePct * 5.0;
        $roiAt10x = $closePct * 10.0;
        $roiAt15x = $closePct * 15.0;

        // Stop-loss hit checks (adverse ROI thresholds at 10× leverage)
        $wouldHitStop20roi = $maxAdversePct >= 2.0;  // 20 ROI at 10× = 2% adverse move
        $wouldHitStop30roi = $maxAdversePct >= 3.0;  // 30 ROI at 10× = 3% adverse move

        // Take-profit hit checks (favorable ROI thresholds at 10× leverage)
        $wouldReachProfit10roi = $maxFavorablePct >= 1.0;  // 10 ROI at 10× = 1% favorable move
        $wouldReachProfit20roi = $maxFavorablePct >= 2.0;  // 20 ROI at 10× = 2% favorable move

        return [
            'data_available'           => true,
            'candle_count'             => count($window),
            'max_favorable_move_pct'   => round($maxFavorablePct, 4),
            'max_adverse_move_pct'     => round($maxAdversePct,   4),
            'close_move_pct'           => round($closePct,        4),
            'roi_at_5x'                => round($roiAt5x,  3),
            'roi_at_10x'               => round($roiAt10x, 3),
            'roi_at_15x'               => round($roiAt15x, 3),
            'would_hit_stop_20roi'     => $wouldHitStop20roi,
            'would_hit_stop_30roi'     => $wouldHitStop30roi,
            'would_reach_profit_10roi' => $wouldReachProfit10roi,
            'would_reach_profit_20roi' => $wouldReachProfit20roi,
        ];
    }

    /**
     * Build a summary indexed by context type from the replay entry list.
     * Returns a keyed array of per-type stats with recommendation.
     */
    private function buildReplaySummary(array $entries): array
    {
        $byType = [];

        foreach ($entries as $entry) {
            $t = (string)($entry['context_type'] ?? 'unknown');
            if (!isset($byType[$t])) {
                $byType[$t] = [
                    'context_type'        => $t,
                    'count'               => 0,
                    'candidates'          => 0,
                    'passed_confirmation' => 0,
                    'failed_confirmation' => 0,
                    'skipped_candles'     => 0,
                    '_roi_5m'             => [],
                    '_roi_15m'            => [],
                    '_roi_30m'            => [],
                    '_max_fav'            => [],
                    '_max_adv'            => [],
                    '_stop20_hits'        => 0,
                    '_stop30_hits'        => 0,
                    '_profit10_hits'      => 0,
                    '_profit20_hits'      => 0,
                    '_outcome_windows'    => 0,
                ];
            }
            $byType[$t]['count']++;

            $status = (string)($entry['status'] ?? '');

            if ($status === 'skipped_candles_unavailable') {
                $byType[$t]['skipped_candles']++;
                continue;
            }

            $byType[$t]['candidates']++;

            if ($status === 'replay_short_candidate') {
                $byType[$t]['passed_confirmation']++;
                $fwd = $entry['forward_outcomes'] ?? [];

                if (isset($fwd['5m']['roi_at_10x'])) {
                    $byType[$t]['_roi_5m'][] = (float)$fwd['5m']['roi_at_10x'];
                }
                if (isset($fwd['15m']['roi_at_10x'])) {
                    $byType[$t]['_roi_15m'][] = (float)$fwd['15m']['roi_at_10x'];
                }
                if (isset($fwd['30m']['roi_at_10x'])) {
                    $byType[$t]['_roi_30m'][] = (float)$fwd['30m']['roi_at_10x'];
                }

                foreach ([5, 10, 15, 30, 60] as $min) {
                    $o = $fwd[$min . 'm'] ?? [];
                    if (!empty($o['data_available'])) {
                        $byType[$t]['_max_fav'][]  = (float)($o['max_favorable_move_pct'] ?? 0.0);
                        $byType[$t]['_max_adv'][]  = (float)($o['max_adverse_move_pct']   ?? 0.0);
                        $byType[$t]['_outcome_windows']++;
                        if ($o['would_hit_stop_20roi']     ?? false) { $byType[$t]['_stop20_hits']++; }
                        if ($o['would_hit_stop_30roi']     ?? false) { $byType[$t]['_stop30_hits']++; }
                        if ($o['would_reach_profit_10roi'] ?? false) { $byType[$t]['_profit10_hits']++; }
                        if ($o['would_reach_profit_20roi'] ?? false) { $byType[$t]['_profit20_hits']++; }
                    }
                }
            } else {
                $byType[$t]['failed_confirmation']++;
            }
        }

        // Compute derived stats + recommendation for each type
        $result = [];
        foreach ($byType as $t => $d) {
            $avgRoi5m  = !empty($d['_roi_5m'])  ? round(array_sum($d['_roi_5m'])  / count($d['_roi_5m']),  3) : null;
            $avgRoi15m = !empty($d['_roi_15m']) ? round(array_sum($d['_roi_15m']) / count($d['_roi_15m']), 3) : null;
            $avgRoi30m = !empty($d['_roi_30m']) ? round(array_sum($d['_roi_30m']) / count($d['_roi_30m']), 3) : null;
            $avgFav    = !empty($d['_max_fav']) ? round(array_sum($d['_max_fav']) / count($d['_max_fav']), 3) : null;
            $avgAdv    = !empty($d['_max_adv']) ? round(array_sum($d['_max_adv']) / count($d['_max_adv']), 3) : null;

            $windows = $d['_outcome_windows'];
            $stop20Rate  = $windows > 0 ? round($d['_stop20_hits']  / $windows, 3) : null;
            $stop30Rate  = $windows > 0 ? round($d['_stop30_hits']  / $windows, 3) : null;
            $profit10Rate = $windows > 0 ? round($d['_profit10_hits'] / $windows, 3) : null;
            $profit20Rate = $windows > 0 ? round($d['_profit20_hits'] / $windows, 3) : null;

            // Recommendation
            $rec = 'ignore';
            if ($d['count'] < 2 || $d['skipped_candles'] >= $d['count']) {
                $rec = 'observe_more'; // not enough data to conclude
            } elseif ($d['passed_confirmation'] >= 2 && $avgRoi15m !== null) {
                if ($avgRoi15m > 5.0) {
                    $rec = 'possible_dynamic_short_rule';
                } elseif ($avgRoi15m > 0.0) {
                    $rec = 'observe_more';
                }
            } elseif ($d['passed_confirmation'] >= 1) {
                $rec = 'observe_more';
            }

            $result[$t] = [
                'context_type'            => $t,
                'count'                   => $d['count'],
                'candidates'              => $d['candidates'],
                'passed_confirmation'     => $d['passed_confirmation'],
                'failed_confirmation'     => $d['failed_confirmation'],
                'skipped_candles'         => $d['skipped_candles'],
                'avg_forward_5m_roi_10x'  => $avgRoi5m,
                'avg_forward_15m_roi_10x' => $avgRoi15m,
                'avg_forward_30m_roi_10x' => $avgRoi30m,
                'avg_max_favorable_roi_10x' => $avgFav,
                'avg_max_adverse_roi_10x'   => $avgAdv,
                'stop_20roi_hit_rate'     => $stop20Rate,
                'stop_30roi_hit_rate'     => $stop30Rate,
                'profit_10roi_hit_rate'   => $profit10Rate,
                'profit_20roi_hit_rate'   => $profit20Rate,
                'recommendation'          => $rec,
            ];
        }

        // Sort by count descending
        uasort($result, fn(array $a, array $b) => $b['count'] - $a['count']);
        return $result;
    }

    // ── Replay gate helpers ───────────────────────────────────────────────────

    /**
     * Find the most recent matching replay record for a given candidate.
     *
     * Matching criteria:
     *   1. Same symbol.
     *   2. context_type of the replay record is in the candidate's source_context_types
     *      (when source_context_types is non-empty; otherwise any type for the symbol).
     *   3. Record is within $maxAgeMinutes.
     *   4. status != 'skipped_candles_unavailable' (no trend data to evaluate).
     *
     * Returns the most recent matching record, or null if none found.
     */
    /**
     * Find the most recent matching replay record for a given candidate.
     *
     * Matching priority:
     *   1. source_context_ids intersection (exact source context match)
     *   2. context_type match within symbol
     *   3. observed_at time bucket (age filter)
     *   4. dynamic_rule when available
     *
     * Returns the best matching record along with the match mode used, or null if none found.
     * The returned array has an extra '_replay_match_meta' key with diagnostics.
     */
    private function findReplayRecordForCandidate(
        array $candidate,
        array $replayBySymbol,
        int   $maxAgeMinutes
    ): ?array {
        $sym                = (string)($candidate['symbol'] ?? '');
        $srcContextTypes    = (array)($candidate['source_context_types'] ?? []);
        $srcContextIds      = (array)($candidate['source_context_ids']   ?? []);
        $candDynamicRule    = (string)($candidate['dynamic_rule']        ?? '');
        $now                = time();
        $cutoff             = $now - ($maxAgeMinutes * 60);

        $records = $replayBySymbol[$sym] ?? [];
        if (empty($records)) {
            return null;
        }

        // Age filter + skip no-data records
        $ageFiltered = [];
        foreach ($records as $rec) {
            if (!is_array($rec)) {
                continue;
            }
            if (($rec['status'] ?? '') === 'skipped_candles_unavailable') {
                continue;
            }
            $obsAt = $rec['observed_at'] ?? null;
            if ($obsAt !== null) {
                $recTs = is_int($obsAt) ? $obsAt : (int)strtotime((string)$obsAt);
                if ($recTs > 0 && $recTs < $cutoff) {
                    continue;
                }
            }
            $ageFiltered[] = $rec;
        }

        if (empty($ageFiltered)) {
            return null;
        }

        // Helper: sort by most recent observed_at
        $sortByRecent = static function (array $a, array $b): int {
            $oaA = $a['observed_at'] ?? null;
            $oaB = $b['observed_at'] ?? null;
            $ta  = $oaA !== null ? (is_int($oaA) ? $oaA : (int)strtotime((string)$oaA)) : 0;
            $tb  = $oaB !== null ? (is_int($oaB) ? $oaB : (int)strtotime((string)$oaB)) : 0;
            return $tb - $ta;
        };

        // ── Priority 1: exact source_context_ids intersection ────────────────
        if (!empty($srcContextIds)) {
            $exactMatches = [];
            foreach ($ageFiltered as $rec) {
                $recCtxIds = (array)($rec['source_context_ids'] ?? []);
                if (!empty($recCtxIds)) {
                    $intersection = array_intersect($srcContextIds, $recCtxIds);
                    if (!empty($intersection)) {
                        $recCopy = $rec;
                        $recCopy['_intersection_count'] = count($intersection);
                        $exactMatches[] = $recCopy;
                    }
                }
            }
            if (!empty($exactMatches)) {
                // Sort by intersection size desc, then by most recent
                usort($exactMatches, static function (array $a, array $b) use ($sortByRecent): int {
                    $intDiff = ($b['_intersection_count'] ?? 0) - ($a['_intersection_count'] ?? 0);
                    if ($intDiff !== 0) {
                        return $intDiff;
                    }
                    return $sortByRecent($a, $b);
                });
                $best = $exactMatches[0];
                $matchedCtxIds = array_values(array_intersect(
                    $srcContextIds,
                    (array)($best['source_context_ids'] ?? [])
                ));
                unset($best['_intersection_count']);
                $best['_replay_match_meta'] = [
                    'replay_match_mode'             => 'exact_source_context',
                    'replay_context_type_matched'   => false,
                    'replay_matched_context_ids'    => $matchedCtxIds,
                    'replay_record_observed_at'     => $best['observed_at'] ?? null,
                    'replay_record_age_seconds'     => ($best['observed_at'] ?? null) !== null
                        ? max(0, $now - (is_int($best['observed_at']) ? $best['observed_at'] : (int)strtotime((string)$best['observed_at'])))
                        : null,
                ];
                return $best;
            }
        }

        // ── Priority 2: context_type match ───────────────────────────────────
        if (!empty($srcContextTypes)) {
            $typeMatches = [];
            foreach ($ageFiltered as $rec) {
                $recType = (string)($rec['context_type'] ?? '');
                if (in_array($recType, $srcContextTypes, true)) {
                    $typeMatches[] = $rec;
                }
            }
            if (!empty($typeMatches)) {
                // Sub-prioritise by dynamic_rule match if available
                if ($candDynamicRule !== '') {
                    $ruleMatches = array_filter($typeMatches, static function (array $rec) use ($candDynamicRule): bool {
                        $recRule = (string)($rec['dynamic_rule'] ?? '');
                        return $recRule !== '' && $recRule === $candDynamicRule;
                    });
                    if (!empty($ruleMatches)) {
                        $typeMatches = array_values($ruleMatches);
                    }
                }
                usort($typeMatches, $sortByRecent);
                $best = $typeMatches[0];
                $best['_replay_match_meta'] = [
                    // Normalised to 'time_bucket': context_type matching shares the same
                    // semantics as time_bucket (no exact source_context_ids intersection).
                    // replay_context_type_matched=true distinguishes it from a pure
                    // time_bucket fallback (which carries no context_type match).
                    'replay_match_mode'          => 'time_bucket',
                    'replay_context_type_matched'=> true,
                    'replay_matched_context_ids' => [],
                    'replay_record_observed_at'  => $best['observed_at'] ?? null,
                    'replay_record_age_seconds'  => ($best['observed_at'] ?? null) !== null
                        ? max(0, $now - (is_int($best['observed_at']) ? $best['observed_at'] : (int)strtotime((string)$best['observed_at'])))
                        : null,
                ];
                return $best;
            }
        }

        // ── Priority 3: fallback — any record for this symbol (time_bucket) ──
        // Conservative fallback: use the most recent record for the symbol.
        // This is explicit (not silent) and labelled 'symbol_context_fallback'.
        usort($ageFiltered, $sortByRecent);
        $best = $ageFiltered[0];
        $best['_replay_match_meta'] = [
            'replay_match_mode'          => 'symbol_context_fallback',
            'replay_context_type_matched'=> false,
            'replay_matched_context_ids' => [],
            'replay_record_observed_at'  => $best['observed_at'] ?? null,
            'replay_record_age_seconds'  => ($best['observed_at'] ?? null) !== null
                ? max(0, $now - (is_int($best['observed_at']) ? $best['observed_at'] : (int)strtotime((string)$best['observed_at'])))
                : null,
        ];
        return $best;
    }

    /**
     * Apply the replay/trend confirmation gate to a pre-enriched short candidate.
     *
     * The candidate must already have the following fields set by the pre-enrichment pass:
     *   replay_status, replay_reject_reason, trend_5m, trend_15m, trend_30m,
     *   confirmation_count.
     *
     * Returns an array with:
     *   passed       bool
     *   gate_enabled bool
     *   block_reason string|null
     *   failed_checks array
     *   replay_status string|null
     *   replay_reject_reason string|null
     *   trend_5m/15m/30m_price_change_pct float|null
     */
    private function applyReplayGate(array $candidate, array $config): array
    {
        $require5m     = (bool)($config['dynamic_handoff_require_5m_bearish']          ?? true);
        $require15m    = (bool)($config['dynamic_handoff_require_15m_not_bullish']     ?? true);
        $require30m    = (bool)($config['dynamic_handoff_require_30m_not_bullish']     ?? true);
        $blockRecovery = (bool)($config['dynamic_handoff_block_on_strong_recovery']    ?? true);
        $max15mPct     = (float)($config['dynamic_handoff_max_15m_price_change_pct']   ?? 0.20);
        $max30mPct     = (float)($config['dynamic_handoff_max_30m_price_change_pct']   ?? 0.30);
        $minConf       = (int)  ($config['dynamic_handoff_min_confirmations_with_replay'] ?? 3);

        $replayStatus = $candidate['replay_status'] ?? null;

        // No matching replay record → block
        if ($replayStatus === null) {
            return [
                'passed'                                        => false,
                'gate_enabled'                                  => true,
                'block_reason'                                  => 'missing_replay_confirmation',
                'failed_checks'                                 => ['no_replay_record'],
                'replay_status'                                 => null,
                'replay_reject_reason'                         => $candidate['replay_reject_reason'] ?? null,
                'candidate_confirmation_count'                  => (int)($candidate['confirmation_count'] ?? 0),
                'replay_confirmation_count'                     => (int)($candidate['replay_confirmation_count'] ?? 0),
                'replay_confirmation_passed'                    => $candidate['replay_confirmation_passed']     ?? null,
                'replay_confirmation_confidence'                => $candidate['replay_confirmation_confidence'] ?? null,
                'dynamic_handoff_min_confirmations_with_replay' => $minConf,
                'trend_5m_price_change_pct'                     => null,
                'trend_15m_price_change_pct'                    => null,
                'trend_30m_price_change_pct'                    => null,
            ];
        }

        // Replay record found but not a confirmed short candidate → block
        if ($replayStatus !== 'replay_short_candidate') {
            return [
                'passed'                                        => false,
                'gate_enabled'                                  => true,
                'block_reason'                                  => 'replay_not_confirmed',
                'failed_checks'                                 => ['replay_status_not_candidate'],
                'replay_status'                                 => $replayStatus,
                'replay_reject_reason'                         => $candidate['replay_reject_reason'] ?? null,
                'candidate_confirmation_count'                  => (int)($candidate['confirmation_count'] ?? 0),
                'replay_confirmation_count'                     => (int)($candidate['replay_confirmation_count'] ?? 0),
                'replay_confirmation_passed'                    => $candidate['replay_confirmation_passed']     ?? null,
                'replay_confirmation_confidence'                => $candidate['replay_confirmation_confidence'] ?? null,
                'dynamic_handoff_min_confirmations_with_replay' => $minConf,
                'trend_5m_price_change_pct'                     => null,
                'trend_15m_price_change_pct'                    => null,
                'trend_30m_price_change_pct'                    => null,
            ];
        }

        $trend5m  = (array)($candidate['trend_5m']  ?? []);
        $trend15m = (array)($candidate['trend_15m'] ?? []);
        $trend30m = (array)($candidate['trend_30m'] ?? []);

        $failedChecks  = [];
        $candConfCnt   = (int)($candidate['confirmation_count']       ?? 0);
        $replayConfCnt = (int)($candidate['replay_confirmation_count'] ?? 0);

        // Minimum replay confirmations check.
        // Uses replay_record.confirmations.count (not the per-rule candidate confirmation_count).
        // Per-rule min_confirmations_demo is already checked separately before the gate.
        if ($replayConfCnt < $minConf) {
            $failedChecks[] = 'insufficient_replay_confirmations';
        }

        // 5m bearish: lower_close OR bearish flag (only checked when data available)
        if ($require5m && ($trend5m['data_available'] ?? false)) {
            $is5mBearish = ($trend5m['lower_close'] ?? false) || ($trend5m['bearish'] ?? false);
            if (!$is5mBearish) {
                $failedChecks[] = '5m_not_bearish';
            }
        }

        // 15m not bullish: price_change_pct must be <= threshold
        $pct15m = (float)($trend15m['price_change_pct'] ?? 0.0);
        if ($require15m && ($trend15m['data_available'] ?? false) && $pct15m > $max15mPct) {
            $failedChecks[] = '15m_too_bullish';
        }

        // 30m not bullish
        $pct30m = (float)($trend30m['price_change_pct'] ?? 0.0);
        if ($require30m && ($trend30m['data_available'] ?? false) && $pct30m > $max30mPct) {
            $failedChecks[] = '30m_too_bullish';
        }

        // No strong recovery in 15m or 30m window
        if ($blockRecovery) {
            $noRecov15m = $trend15m['no_strong_recovery'] ?? true;
            $noRecov30m = $trend30m['no_strong_recovery'] ?? true;
            if (!$noRecov15m || !$noRecov30m) {
                $failedChecks[] = 'strong_recovery_detected';
            }
        }

        $passed = empty($failedChecks);

        return [
            'passed'                          => $passed,
            'gate_enabled'                    => true,
            'block_reason'                    => $passed ? null : 'replay_trend_confirmation_failed',
            'failed_checks'                   => $failedChecks,
            'replay_status'                   => $replayStatus,
            'replay_reject_reason'            => $candidate['replay_reject_reason'] ?? null,
            'candidate_confirmation_count'    => $candConfCnt,
            'replay_confirmation_count'       => $replayConfCnt,
            'replay_confirmation_passed'      => $candidate['replay_confirmation_passed']     ?? null,
            'replay_confirmation_confidence'  => $candidate['replay_confirmation_confidence'] ?? null,
            'dynamic_handoff_min_confirmations_with_replay' => $minConf,
            'trend_5m_price_change_pct'       => $trend5m['price_change_pct']  ?? null,
            'trend_15m_price_change_pct'      => $trend15m['price_change_pct'] ?? null,
            'trend_30m_price_change_pct'      => $trend30m['price_change_pct'] ?? null,
        ];
    }

    // ── Config helpers ─────────────────────────────────────────────────────────

    private function loadConfig(): array
    {
        $base   = $this->loadConfigFile('config/base.php');
        $active = $this->loadConfigFile('config/active.php');
        return array_merge($base, $active);
    }

    private function loadConfigFile(string $relPath): array
    {
        $path = $this->moduleDir . '/' . $relPath;
        if (!file_exists($path)) {
            return [];
        }
        try {
            $data = require $path;
            return is_array($data) ? $data : [];
        } catch (\Throwable) {
            return [];
        }
    }

    // ── JSON helpers ──────────────────────────────────────────────────────────

    private function readJson(string $path, mixed $default = []): mixed
    {
        // Accept both absolute and relative paths
        $abs = str_starts_with($path, '/') ? $path : $this->moduleDir . '/' . $path;
        if (!file_exists($abs)) {
            return $default;
        }
        $raw = file_get_contents($abs);
        if ($raw === false || trim($raw) === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        return ($decoded !== null) ? $decoded : $default;
    }

    private function writeJson(string $relPath, mixed $data): void
    {
        $path = $this->moduleDir . '/' . $relPath;
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            file_put_contents($path, $json, LOCK_EX);
        }
    }
}
