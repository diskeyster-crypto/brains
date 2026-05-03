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
 *   2. load input_contexts.json
 *   3. filter stale + dedupe contexts
 *   4. classify contexts (useful vs noise)
 *   5. group by symbol
 *   6. evaluate dynamic rules per symbol
 *   7. score candidates
 *   8. build signals from high-confidence candidates
 *   9. build bot_handoff_queue (empty unless handoff_enabled + !shadow_only + emit_bot_handoff)
 *  10. write all storage files + last_run.json
 *
 * SAFETY RULES:
 *   - shadow_only = true  → no executable signals, no handoff queue entries
 *   - handoff_enabled = false → no handoff queue entries
 *   - live_enabled = false → no live signals
 *   - Does NOT scan market data directly
 *   - Does NOT modify source strategy storage
 *   - Does NOT open real orders
 */

namespace Modules\Strategy\DynamicStrategies;

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

        // ── 1. Load input contexts ─────────────────────────────────────────────
        $ctxFile = $this->repoRoot . '/' . ltrim((string)($config['input_contexts_file'] ?? ''), '/');
        if ($ctxFile === $this->repoRoot . '/') {
            $ctxFile = $this->moduleDir . '/storage/input_contexts.json';
        }

        $allContexts = $this->readJson($ctxFile, []);
        if (!is_array($allContexts)) {
            $allContexts = [];
        }
        $loadedTotal = count($allContexts);

        // ── 2. Filter stale contexts ───────────────────────────────────────────
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

        // ── 3. Dedupe by context_id ────────────────────────────────────────────
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

        // ── 4. Classify contexts ───────────────────────────────────────────────
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

        // ── 6. Evaluate dynamic rules + 7. Score ──────────────────────────────
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
        $this->writeJson('storage/last_run.json', $stats);

        return [
            'ok'    => true,
            'stats' => $stats,
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
