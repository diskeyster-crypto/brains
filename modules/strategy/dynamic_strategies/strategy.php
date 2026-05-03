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
 *  10. build signals from high-confidence candidates
 *  11. build bot_handoff_queue (empty unless handoff_enabled + !shadow_only + emit_bot_handoff)
 *  12. write all storage files + last_run.json
 *
 * SAFETY RULES:
 *   - shadow_only = true  → no executable signals, no handoff queue entries
 *   - handoff_enabled = false → no handoff queue entries
 *   - live_enabled = false → no live signals
 *   - Does NOT scan market data directly
 *   - Does NOT modify source strategy storage
 *   - Does NOT open real orders
 *   - Source adapters are read-only consumers of existing artifacts
 */

namespace Modules\Strategy\DynamicStrategies;

require_once __DIR__ . '/sources/DynamicSourceAdapterInterface.php';
require_once __DIR__ . '/sources/AbstractDynamicSourceAdapter.php';
require_once __DIR__ . '/sources/DoubleBottomLongSourceAdapter.php';

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

        $shadowOnly     = (bool)($config['shadow_only']      ?? true);
        $handoffEnabled = (bool)($config['handoff_enabled']  ?? false);
        $liveEnabled    = (bool)($config['live_enabled']     ?? false);
        $emitHandoff    = (bool)($config['emit_bot_handoff'] ?? false);
        $mode           = (string)($config['mode']           ?? 'demo');

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
        $candidates      = [];
        $rejectItems     = [];
        $minConf         = (int)($config['min_confirmations_for_shadow_candidate'] ?? 2);
        $minDemoConf     = (int)($config['min_confirmations_for_demo_signal']      ?? 3);
        $minDemoScore    = (float)($config['min_confidence_for_demo_signal']       ?? 0.65);
        $minLiveScore    = (float)($config['min_confidence_for_live_signal']       ?? 0.85);

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

                if ($candidate['confirmation_count'] < $minConf) {
                    $rejectItems[] = [
                        'symbol'              => $sym,
                        'dynamic_rule'        => $rule,
                        'reject_reason'       => 'insufficient_confirmations',
                        'confirmation_count'  => $candidate['confirmation_count'],
                        'min_required'        => $minConf,
                        'confidence_score'    => $candidate['confidence_score'],
                        'source_context_ids'  => $candidate['source_context_ids'],
                        'rejected_at'         => date('c'),
                    ];
                    continue;
                }

                $candidate['candidate_id'] = 'dyn_' . $rule . '_' . $sym . '_' . $now;
                $candidates[] = $candidate;
            }
        }

        // ── 8. Build signals ───────────────────────────────────────────────────
        $signals        = [];
        $shadowSignals  = [];
        $maxSigPerRun   = (int)($config['max_signals_per_run']    ?? 20);
        $maxSigPerSym   = (int)($config['max_signals_per_symbol'] ?? 1);
        $ttlMinutes     = (int)($config['signal_ttl_minutes']     ?? 120);
        $signalsBySymbol = [];

        foreach ($candidates as $cand) {
            $sym     = $cand['symbol'];
            $score   = $cand['confidence_score'];
            $confCnt = $cand['confirmation_count'];

            $qualifiesForDemo = $confCnt >= $minDemoConf && $score >= $minDemoScore;
            if (!$qualifiesForDemo) {
                continue;
            }
            if (count($signals) >= $maxSigPerRun) {
                break;
            }
            if (($signalsBySymbol[$sym] ?? 0) >= $maxSigPerSym) {
                continue;
            }

            $qualifiesForLive = $liveEnabled && !$shadowOnly && $score >= $minLiveScore;
            $warnings = (array)($cand['warnings'] ?? []);

            // Live disabled by config
            if ($mode === 'live' && !$liveEnabled) {
                $warnings[] = 'live_disabled_by_config';
            }

            $signal = [
                'signal_id'           => 'dsig_' . $cand['dynamic_rule'] . '_' . $sym . '_' . $now,
                'symbol'              => $sym,
                'side'                => 'short',
                'strategy'            => self::STRATEGY_ID,
                'strategy_id'         => self::STRATEGY_ID,
                'dynamic_rule'        => $cand['dynamic_rule'],
                'confidence_score'    => $score,
                'mode'                => $shadowOnly ? 'demo' : $mode,
                'shadow_only'         => $shadowOnly,
                'handoff_ready'       => false,
                'executable'          => false,
                'detected_at'         => date('c'),
                'expires_at'          => date('c', $now + ($ttlMinutes * 60)),
                'source_context_ids'  => $cand['source_context_ids'],
                'source_strategies'   => $cand['source_strategies'],
                'confirmations'       => $cand['confirmations'],
                'confirmation_count'  => $confCnt,
                'reason_codes'        => $cand['reason_codes'],
                'warnings'            => $warnings,
            ];

            // Shadow signals — always non-executable
            $shadow = $signal;
            $shadow['shadow_only']    = true;
            $shadow['handoff_ready']  = false;
            $shadow['executable']     = false;
            $shadowSignals[] = $shadow;

            // Demo/live executable signal (only if all gates passed)
            if (!$shadowOnly && $handoffEnabled && $emitHandoff) {
                if ($mode === 'live' && $qualifiesForLive) {
                    $signal['handoff_ready'] = true;
                    $signal['executable']    = true;
                } elseif ($mode === 'demo') {
                    $signal['handoff_ready'] = true;
                    $signal['executable']    = true;
                }
            }

            $signals[] = $signal;
            $signalsBySymbol[$sym] = ($signalsBySymbol[$sym] ?? 0) + 1;
        }

        // ── 9. Build handoff queue ─────────────────────────────────────────────
        // Queue stays empty or contains only non-executable diagnostic rows unless:
        //   shadow_only = false AND handoff_enabled = true AND emit_bot_handoff = true
        $handoffQueue        = [];
        $handoffExecutable   = 0;
        $handoffReadyTotal   = 0;
        $handoffWrittenTotal = 0;

        if (!$shadowOnly && $handoffEnabled && $emitHandoff) {
            foreach ($signals as $sig) {
                if ($sig['executable'] ?? false) {
                    $handoffQueue[] = [
                        'signal_id'        => $sig['signal_id'],
                        'symbol'           => $sig['symbol'],
                        'side'             => $sig['side'],
                        'strategy_id'      => self::STRATEGY_ID,
                        'dynamic_rule'     => $sig['dynamic_rule'],
                        'confidence_score' => $sig['confidence_score'],
                        'mode'             => $sig['mode'],
                        'shadow_only'      => false,
                        'executable'       => true,
                        'queued_at'        => date('c'),
                        'expires_at'       => $sig['expires_at'],
                        'source_context_ids' => $sig['source_context_ids'],
                    ];
                    $handoffExecutable++;
                    $handoffReadyTotal++;
                }
            }
            $handoffWrittenTotal = count($handoffQueue);
        } else {
            // Diagnostic non-executable rows (so the queue file is never completely empty
            // when there are shadow signals — aids debugging without enabling handoff)
            foreach ($shadowSignals as $sig) {
                $handoffQueue[] = [
                    'signal_id'        => $sig['signal_id'],
                    'symbol'           => $sig['symbol'],
                    'side'             => $sig['side'],
                    'strategy_id'      => self::STRATEGY_ID,
                    'dynamic_rule'     => $sig['dynamic_rule'],
                    'confidence_score' => $sig['confidence_score'],
                    'mode'             => 'demo',
                    'shadow_only'      => true,
                    'executable'       => false,
                    'handoff_ready'    => false,
                    'diagnostic_only'  => true,
                    'queued_at'        => date('c'),
                    'expires_at'       => $sig['expires_at'],
                    'source_context_ids' => $sig['source_context_ids'],
                ];
            }
            $handoffWrittenTotal = count($handoffQueue);
        }

        // ── 10. Write storage files ────────────────────────────────────────────
        $finishedAt  = date('c');
        $durationMs  = (int)round((microtime(true) - $startMicro) * 1000);

        $stats = [
            'status'                          => 'done',
            'started_at'                      => $startedAt,
            'finished_at'                     => $finishedAt,
            'duration_ms'                     => $durationMs,
            'enabled'                         => true,
            'mode'                            => $mode,
            'shadow_only'                     => $shadowOnly,
            'handoff_enabled'                 => $handoffEnabled,
            'live_enabled'                    => $liveEnabled,
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
            'candidates_total'                => count($candidates),
            'short_candidates_total'          => count($candidates),
            'rejected_contexts_total'         => count($rejectItems),
            'signals_total'                   => count($signals),
            'shadow_signals_total'            => count($shadowSignals),
            'bot_handoff_ready_total'         => $handoffReadyTotal,
            'bot_handoff_queue_written_total' => $handoffWrittenTotal,
            'bot_handoff_queue_executable_total' => $handoffExecutable,
            'errors_total'                    => 0,
            // Examples (up to 5 each)
            'candidate_examples'              => array_slice($candidates, 0, 5),
            'rejected_context_examples'       => array_slice($rejectItems, 0, 5),
            'signal_examples'                 => array_slice($signals, 0, 5),
            // Alias fields for generic dashboard display
            'found'                           => count($candidates),
            'generated_signals_count'         => count($signals),
            'active_pool_signals_total'       => count($shadowSignals),
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

        return [
            'symbol'              => $symbol,
            'side'                => 'short',
            'dynamic_rule'        => $rule,
            'source_context_ids'  => array_values(array_unique($sourceContextIds)),
            'source_strategies'   => array_values($sourceStrategies),
            'source_signal_ids'   => array_values($sourceSignalIds),
            'confirmations'       => $confirmations,
            'confirmation_count'  => $confCount,
            'confidence_score'    => round($score, 4),
            'suggested_mode'      => 'demo',
            'shadow_only'         => true,
            'executable'          => false,
            'handoff_ready'       => false,
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
