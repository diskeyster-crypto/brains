<?php

declare(strict_types=1);

namespace Modules\Strategy\EarlyImpulseGrowthLong;

final class EarlyImpulseGrowthLongService
{
    private const STRATEGY_ID = 'early_impulse_growth_long';
    private const SIDE = 'long';

    private static ?self $instance = null;
    private string $moduleDir;
    private string $repoRoot;

    private string $universeSource = 'unknown';
    private int $universeTotal = 0;
    /** @var list<string> */
    private array $universeExamples = [];
    /** @var array<string,array<string,mixed>>|null */
    private ?array $cachedFilterCatalog = null;
    /** @var array<string,array<string,mixed>>|null */
    private ?array $cachedFilterProfiles = null;
    private mixed $coinContextService = null;
    private mixed $orderbookContextService = null;

    public function __construct(?string $moduleDir = null)
    {
        if ($moduleDir !== null) {
            $this->moduleDir = rtrim($moduleDir, '/');
        } else {
            $this->moduleDir = rtrim(
                \Core\System\SystemPaths::instance()->get('strategy.early_impulse_growth_long'),
                '/'
            );
        }
        // modules/strategy/flow/early_impulse_growth_long -> repo root is 4 levels up
        $this->repoRoot = rtrim(dirname($this->moduleDir, 4), '/');
    }

    public static function instance(?string $moduleDir = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($moduleDir);
        }
        return self::$instance;
    }

    /**
     * @return array<string,mixed>
     */
    public function getConfig(): array
    {
        return $this->loadConfig();
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function getFilterCatalog(): array
    {
        if ($this->cachedFilterCatalog === null) {
            require_once $this->repoRoot . '/modules/filter_engine/filter_engine.php';
            $this->cachedFilterCatalog = \Modules\FilterEngine\FilterEngine::discoverFilterMetadata();
        }
        return $this->cachedFilterCatalog;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function getFilterProfiles(): array
    {
        if ($this->cachedFilterProfiles === null) {
            $profiles = $this->readPhpArray($this->moduleDir . '/config/filter_profiles.php');
            $this->cachedFilterProfiles = is_array($profiles) ? $profiles : [];
        }
        return $this->cachedFilterProfiles;
    }

    /**
     * Queue one rotating universe window for batched processing.
     *
     * @return array<string,mixed>
     */
    public function queueRun(): array
    {
        $config = $this->loadConfig();
        if (!(bool)$config['enabled']) {
            return ['queued' => false, 'status' => 'disabled'];
        }

        $symbols = $this->fetchUniverse();
        $total = count($symbols);
        $batchSize = max(1, (int)$config['batch_size']);
        $maxSymbols = max(1, (int)$config['max_symbols_per_run']);
        $windowSize = $total > 0 ? min($maxSymbols, $total) : 0;

        $prev = $this->readJson($this->storagePath('run_state.json'), []);
        $rawPrevCursor = (int)($prev['next_registry_cursor'] ?? 0);
        $cursor = $rawPrevCursor;
        $cursorResetReason = null;
        if ($total > 0 && ($cursor < 0 || $cursor >= $total)) {
            $cursor = 0;
            $cursorResetReason = 'cursor_out_of_range';
        }
        if ($total === 0) {
            $cursor = 0;
        }

        $selectedSymbols = [];
        if ($total > 0) {
            for ($i = 0; $i < $windowSize; $i++) {
                $idx = ($cursor + $i) % $total;
                $selectedSymbols[] = $symbols[$idx];
            }
        }

        $selectedTotal = count($selectedSymbols);
        $windowStart = $selectedTotal > 0 ? $cursor : 0;
        $windowEnd = $selectedTotal > 0 ? (($windowStart + $selectedTotal - 1) % max(1, $total)) : 0;
        $wrapped = $selectedTotal > 0 && ($windowStart + $windowSize > $total);
        $nextCursor = ($total > 0 && $selectedTotal > 0) ? (($windowStart + $selectedTotal) % $total) : 0;

        $state = [
            'status' => 'queued',
            'queued_at' => date('c'),
            'batch_offset' => 0,
            'symbols' => $selectedSymbols,
            'selected_window_total' => $selectedTotal,
            'universe_total' => $total,
            'batch_size' => $batchSize,
            'max_symbols_per_run' => $maxSymbols,
            'continuous_scan_enabled' => (bool)$config['continuous_scan_enabled'],
            'auto_requeue_when_done' => (bool)$config['auto_requeue_when_done'],
            'registry_cursor' => $windowStart,
            'previous_registry_cursor' => $rawPrevCursor,
            'next_registry_cursor' => $nextCursor,
            'registry_window_start' => $windowStart,
            'registry_window_end' => $windowEnd,
            'registry_window_wrapped' => $wrapped,
            'registry_cursor_reset_reason' => $cursorResetReason,
            'universe_source' => $this->universeSource,
            'universe_examples' => $this->universeExamples,
        ];

        $this->writeJson($this->storagePath('run_state.json'), $state);

        return [
            'queued' => true,
            'queued_at' => $state['queued_at'],
            'selected_window_total' => $selectedTotal,
            'registry_cursor' => $windowStart,
            'next_registry_cursor' => $nextCursor,
            'universe_total' => $total,
            'batch_size' => $batchSize,
        ];
    }

    /**
     * Process one queue batch. Cron entrypoint.
     *
     * @return array<string,mixed>
     */
    public function tickBatch(): array
    {
        $config = $this->loadConfig();
        if (!(bool)$config['enabled']) {
            return ['status' => 'disabled'];
        }

        $state = $this->readJson($this->storagePath('run_state.json'), []);
        $status = (string)($state['status'] ?? 'idle');

        $continuousScanEnabled = (bool)$config['continuous_scan_enabled'];
        $autoRequeueWhenDone = (bool)$config['auto_requeue_when_done'];

        if (in_array($status, ['idle', 'done'], true) && $continuousScanEnabled && ($status === 'idle' || $autoRequeueWhenDone)) {
            $queue = $this->queueRun();
            if (!($queue['queued'] ?? false)) {
                return ['status' => 'idle'];
            }
            $state = $this->readJson($this->storagePath('run_state.json'), []);
            $status = (string)($state['status'] ?? 'idle');
        }

        if ($status !== 'queued' && $status !== 'running') {
            return ['status' => 'idle'];
        }

        $symbols = isset($state['symbols']) && is_array($state['symbols']) ? array_values($state['symbols']) : [];
        $selectedTotal = (int)($state['selected_window_total'] ?? count($symbols));
        $batchSize = max(1, (int)($state['batch_size'] ?? $config['batch_size']));
        $offsetBefore = max(0, min((int)($state['batch_offset'] ?? 0), max(0, $selectedTotal)));

        $batchSymbols = array_slice($symbols, $offsetBefore, $batchSize);
        $offsetAfter = min($selectedTotal, $offsetBefore + count($batchSymbols));

        $allEvaluated = $this->readJson($this->storagePath('evaluated_contexts.json'), []);
        $allCandidates = $this->readJson($this->storagePath('candidates.json'), []);
        $allWatchCandidates = $this->readJson($this->storagePath('watch_candidates.json'), []);
        if (!is_array($allWatchCandidates)) {
            $allWatchCandidates = $this->readJson($this->storagePath('near_pass_candidates.json'), []);
        }
        $allRejects = $this->readJson($this->storagePath('rejects.json'), []);
        $allSignals = $this->readJson($this->storagePath('signals.json'), []);

        $startedAt = date('c');
        $t0 = microtime(true);

        $newEvaluated = [];
        $newCandidates = [];
        $newWatchCandidates = [];
        $newRejects = [];
        $newSignals = [];

        // Build watch candidate map (symbol_lower => latest watch record)
        $watchMap = [];
        foreach (is_array($allWatchCandidates) ? $allWatchCandidates : [] as $wc) {
            if (!is_array($wc)) {
                continue;
            }
            $symLow = strtolower(trim((string)($wc['symbol'] ?? '')));
            if ($symLow !== '') {
                $watchMap[$symLow] = $wc;
            }
        }

        // --- Watch recheck phase: re-evaluate active stabilizing candidates ---
        $watchRecheckDiag = [
            'watch_recheck_enabled' => false,
            'watch_recheck_candidates_loaded_total' => count($watchMap),
            'watch_recheck_selected_total' => 0,
            'watch_recheck_processed_total' => 0,
            'watch_recheck_triggered_total' => 0,
            'watch_recheck_still_stabilizing_total' => 0,
            'watch_recheck_failed_total' => 0,
            'watch_recheck_expired_total' => 0,
            'watch_recheck_skipped_total' => 0,
            'watch_recheck_skip_reasons' => [],
            'watch_recheck_examples' => [],
        ];
        $recheckProcessedSymbols = [];

        if ((bool)($config['watch_recheck_enabled'] ?? true)) {
            $watchRecheckDiag['watch_recheck_enabled'] = true;
            $recheckNow = time();
            $recheckMaxPerTick = max(1, (int)($config['watch_recheck_max_symbols_per_tick'] ?? 30));
            $recheckMinAgeSec = max(0, (int)($config['watch_recheck_min_age_seconds'] ?? 60));
            $recheckMaxAgeMin = max(1, (int)($config['watch_recheck_max_age_minutes'] ?? 60));
            $recheckOnlyIfNotFailed = (bool)($config['watch_recheck_only_if_not_failed'] ?? true);
            $recheckPriorityPhases = array_values(array_filter(
                array_map('trim', explode(',', (string)($config['watch_recheck_priority_phases'] ?? 'stabilizing,dump_only')))
            ));

            $selectedForRecheck = [];
            foreach ($watchMap as $symLow => $wc) {
                $watchStatus = (string)($wc['watch_status'] ?? 'active');
                if (in_array($watchStatus, ['triggered', 'expired'], true)) {
                    continue;
                }
                if ($recheckOnlyIfNotFailed && $watchStatus === 'failed') {
                    continue;
                }
                $phase = (string)($wc['recovery_phase'] ?? $wc['entry_timing'] ?? '');
                if ($recheckPriorityPhases !== [] && !in_array($phase, $recheckPriorityPhases, true)) {
                    $watchRecheckDiag['watch_recheck_skipped_total']++;
                    $watchRecheckDiag['watch_recheck_skip_reasons']['not_priority_phase'] = (int)($watchRecheckDiag['watch_recheck_skip_reasons']['not_priority_phase'] ?? 0) + 1;
                    continue;
                }
                // Check expiry by age
                $watchStartedAt = (string)($wc['watch_started_at'] ?? $wc['detected_at'] ?? '');
                if ($watchStartedAt !== '') {
                    $startTs = $this->parseIsoToTs($watchStartedAt);
                    if ($startTs !== null && ($recheckNow - $startTs) > ($recheckMaxAgeMin * 60)) {
                        $watchMap[$symLow]['watch_status'] = 'expired';
                        $watchRecheckDiag['watch_recheck_expired_total']++;
                        continue;
                    }
                }
                // Check if too recently checked
                $lastRecheckedAt = (string)($wc['last_rechecked_at'] ?? '');
                if ($lastRecheckedAt !== '') {
                    $lastTs = $this->parseIsoToTs($lastRecheckedAt);
                    if ($lastTs !== null && ($recheckNow - $lastTs) < $recheckMinAgeSec) {
                        $watchRecheckDiag['watch_recheck_skipped_total']++;
                        $watchRecheckDiag['watch_recheck_skip_reasons']['too_recently_checked'] = (int)($watchRecheckDiag['watch_recheck_skip_reasons']['too_recently_checked'] ?? 0) + 1;
                        continue;
                    }
                }
                $selectedForRecheck[] = $symLow;
                if (count($selectedForRecheck) >= $recheckMaxPerTick) {
                    break;
                }
            }
            $watchRecheckDiag['watch_recheck_selected_total'] = count($selectedForRecheck);

            foreach ($selectedForRecheck as $symLow) {
                $prevWc = $watchMap[$symLow] ?? [];
                $prevPhase = (string)($prevWc['recovery_phase'] ?? $prevWc['entry_timing'] ?? '');
                $prevSmoothGrowthPct = $prevWc['smooth_growth_pct'] ?? null;
                $prevOiPct = $prevWc['open_interest_growth_pct'] ?? null;
                $prevStabMin = $prevWc['stabilization_duration_minutes'] ?? null;
                $recheckCount = (int)($prevWc['recheck_count'] ?? 0) + 1;

                $res = $this->processSymbol(strtoupper($symLow), $config);
                $recheckProcessedSymbols[$symLow] = true;
                $watchRecheckDiag['watch_recheck_processed_total']++;

                $rc = $res['candidate'];
                $newPhase = (string)($rc['recovery_phase'] ?? '');

                $updatedWc = array_merge((array)$prevWc, [
                    'last_rechecked_at' => date('c', $recheckNow),
                    'last_seen_at' => date('c', $recheckNow),
                    'recheck_count' => $recheckCount,
                    'recovery_phase' => $newPhase,
                    'entry_timing' => $rc['entry_timing'] ?? ($prevWc['entry_timing'] ?? null),
                    'smooth_growth_pct' => $rc['smooth_growth_pct'] ?? ($prevWc['smooth_growth_pct'] ?? null),
                    'open_interest_growth_pct' => $rc['open_interest_growth_pct'] ?? ($prevWc['open_interest_growth_pct'] ?? null),
                    'stabilization_duration_minutes' => $rc['stabilization_duration_minutes'] ?? ($prevWc['stabilization_duration_minutes'] ?? null),
                    'dump_pct' => $rc['dump_pct'] ?? ($prevWc['dump_pct'] ?? null),
                    'phase_block_reason' => $rc['phase_block_reason'] ?? ($prevWc['phase_block_reason'] ?? null),
                    'handoff_ready' => (bool)($rc['handoff_ready'] ?? false),
                    'handoff_block_reason' => $rc['handoff_block_reason'] ?? null,
                ]);

                if ($newPhase === 'early_entry') {
                    $updatedWc['watch_status'] = 'triggered';
                    $watchRecheckDiag['watch_recheck_triggered_total']++;
                    $newEvaluated[] = $rc;
                    $newCandidates[] = $rc;
                    if (!empty($res['signal'])) {
                        $newSignals[] = $res['signal'];
                    }
                } elseif (in_array($newPhase, ['stabilizing', 'dump_only'], true)) {
                    $updatedWc['watch_status'] = 'active';
                    $watchRecheckDiag['watch_recheck_still_stabilizing_total']++;
                    $newEvaluated[] = $rc;
                } elseif (in_array($newPhase, ['late_spike', 'extended'], true)) {
                    $updatedWc['watch_status'] = 'failed';
                    $updatedWc['fail_reason'] = $newPhase;
                    $watchRecheckDiag['watch_recheck_failed_total']++;
                    $newEvaluated[] = $rc;
                } elseif ($newPhase === 'failed' || $newPhase === '') {
                    $updatedWc['watch_status'] = 'failed';
                    $updatedWc['fail_reason'] = (string)($rc['raw_reject_reason'] ?? 'failed');
                    $watchRecheckDiag['watch_recheck_failed_total']++;
                    $newEvaluated[] = $rc;
                } else {
                    // confirmed_later or other visual phases: keep active (still watching)
                    $updatedWc['watch_status'] = 'active';
                    $watchRecheckDiag['watch_recheck_still_stabilizing_total']++;
                    $newEvaluated[] = $rc;
                }

                $watchMap[$symLow] = $updatedWc;

                if (count($watchRecheckDiag['watch_recheck_examples']) < 20) {
                    $watchRecheckDiag['watch_recheck_examples'][] = [
                        'symbol' => strtoupper($symLow),
                        'previous_phase' => $prevPhase,
                        'new_phase' => $newPhase,
                        'previous_smooth_growth_pct' => $prevSmoothGrowthPct,
                        'new_smooth_growth_pct' => $rc['smooth_growth_pct'] ?? null,
                        'previous_open_interest_growth_pct' => $prevOiPct,
                        'new_open_interest_growth_pct' => $rc['open_interest_growth_pct'] ?? null,
                        'previous_stabilization_duration_minutes' => $prevStabMin,
                        'stabilization_duration_minutes' => $rc['stabilization_duration_minutes'] ?? null,
                        'recheck_count' => $recheckCount,
                        'handoff_ready' => (bool)($rc['handoff_ready'] ?? false),
                        'handoff_block_reason' => $rc['handoff_block_reason'] ?? null,
                        'watch_status' => $updatedWc['watch_status'],
                    ];
                }
            }
        }

        $diag = [
            'insufficient_data_total' => 0,
            'stale_data_total' => 0,
            'data_source_error_total' => 0,
            'dump_detected_total' => 0,
            'stabilization_detected_total' => 0,
            'smooth_growth_detected_total' => 0,
            'early_entry_candidates_total' => 0,
            'stabilizing_candidates_total' => 0,
            'confirmed_later_candidates_total' => 0,
            'late_spike_candidates_total' => 0,
            'extended_candidates_total' => 0,
            'early_entry_handoff_ready_total' => 0,
            'non_early_handoff_blocked_total' => 0,
            'late_spike_handoff_blocked_total' => 0,
            'extended_handoff_blocked_total' => 0,
            'prior_decline_passed_total' => 0,
            'recovery_growth_passed_total' => 0,
            'open_interest_growth_passed_total' => 0,
            'oi_missing_allowed_total' => 0,
            'oi_missing_blocked_total' => 0,
            'current_acceleration_diagnostic_total' => 0,
            'too_early_no_structure_total' => 0,
            'late_spike_detected_total' => 0,
            'recovery_structure_too_weak_total' => 0,
            'recovery_phase_valid_recovery_total' => 0,
            'fast_spike_detected_total' => 0,
            'raw_strategy_passed_total' => 0,
            'raw_strategy_rejected_total' => 0,
            'filter_engine_checked_total' => 0,
            'filter_engine_blocked_total' => 0,
            'filter_engine_diagnostic_only_total' => 0,
            'coin_context_checked_total' => 0,
            'coin_context_available_total' => 0,
            'coin_context_missing_total' => 0,
            'coin_context_error_counts' => [],
            'coin_context_phase_counts' => [],
            'coin_context_trend_1h_counts' => [],
            'coin_context_quality_counts' => [],
            'coin_context_examples' => [],
            'wave_quality_filter_checked_total' => 0,
            'wave_quality_filter_blocked_total' => 0,
            'wave_quality_filter_passed_total' => 0,
            'wave_quality_filter_examples' => [],
            'orderbook_context_checked_total' => 0,
            'orderbook_context_available_total' => 0,
            'orderbook_context_missing_total' => 0,
            'orderbook_context_error_counts' => [],
            'orderbook_ask_wall_detected_total' => 0,
            'orderbook_ask_wall_high_risk_total' => 0,
            'orderbook_bid_support_strong_total' => 0,
            'orderbook_filter_checked_total' => 0,
            'orderbook_filter_blocked_total' => 0,
            'orderbook_filter_passed_total' => 0,
            'orderbook_filter_missing_allowed_total' => 0,
            'orderbook_filter_examples' => [],
            'chaotic_context_quality_downgraded_total' => 0,
            'downtrend_context_quality_downgraded_total' => 0,
            'spike_context_quality_downgraded_total' => 0,
            'open_interest_missing_examples' => [],
            'reject_reason_counts' => [],
            'filter_engine_results_by_filter' => [],
            'phase_evaluated_total' => 0,
            'phase_failed_total' => 0,
            'phase_dump_only_total' => 0,
            'phase_stabilizing_total' => 0,
            'phase_early_entry_total' => 0,
            'phase_confirmed_later_total' => 0,
            'phase_late_spike_total' => 0,
            'phase_extended_total' => 0,
            'handoff_candidates_before_filters_total' => 0,
            'handoff_blocked_by_phase_total' => 0,
            'handoff_blocked_by_filter_total' => 0,
            'handoff_blocked_by_filter_reason_counts' => [],
            'handoff_blocked_by_phase_reason_counts' => [],
            'handoff_blocked_by_quality_guard_total' => 0,
            'handoff_blocked_by_quality_guard_examples' => [],
            'quality_guard_existing_ready_checked_total' => 0,
            'quality_guard_existing_ready_withdrawn_total' => 0,
            'quality_guard_existing_ready_withdrawn_examples' => [],
        ];

        foreach ($batchSymbols as $symbol) {
            if (isset($recheckProcessedSymbols[strtolower((string)$symbol)])) {
                continue;
            }
            $res = $this->processSymbol((string)$symbol, $config);

            $candidate = $res['candidate'];
            $newEvaluated[] = $candidate;

            $entryTiming = (string)($candidate['entry_timing'] ?? 'failed');
            $isVisualCandidate = in_array($entryTiming, ['stabilizing', 'confirmed_later', 'late_spike', 'extended'], true);
            $isWatchCandidate = in_array($entryTiming, ['dump_only', 'stabilizing'], true);

            if (($candidate['raw_strategy_passed'] ?? false) || $isVisualCandidate) {
                $newCandidates[] = $candidate;
            }
            if ($isWatchCandidate) {
                $symLow = strtolower((string)$symbol);
                $existingWc = $watchMap[$symLow] ?? null;
                $candidateWithMeta = array_merge($candidate, [
                    'watch_status' => 'active',
                    'watch_started_at' => is_array($existingWc) ? (string)($existingWc['watch_started_at'] ?? $candidate['detected_at'] ?? date('c')) : (string)($candidate['detected_at'] ?? date('c')),
                    'last_seen_at' => (string)($candidate['detected_at'] ?? date('c')),
                    'last_rechecked_at' => is_array($existingWc) ? ($existingWc['last_rechecked_at'] ?? null) : null,
                    'recheck_count' => is_array($existingWc) ? (int)($existingWc['recheck_count'] ?? 0) : 0,
                ]);
                $newWatchCandidates[] = $candidateWithMeta;
                $watchMap[$symLow] = $candidateWithMeta;
            }
            if (!(($candidate['raw_strategy_passed'] ?? false) || $isVisualCandidate || $isWatchCandidate)) {
                $newRejects[] = [
                    'strategy_id' => self::STRATEGY_ID,
                    'symbol' => $candidate['symbol'],
                    'side' => self::SIDE,
                    'raw_reject_reason' => (string)($candidate['raw_reject_reason'] ?? 'unknown'),
                    'reject_reason' => (string)($candidate['raw_reject_reason'] ?? 'unknown'),
                    'detected_at' => $candidate['detected_at'],
                    'prior_decline_detected' => (bool)($candidate['prior_decline_detected'] ?? false),
                    'prior_decline_pct' => $candidate['prior_decline_pct'] ?? null,
                    'recovery_growth_pct' => $candidate['recovery_growth_pct'] ?? null,
                    'recovery_duration_minutes' => $candidate['recovery_duration_minutes'] ?? null,
                    'recovery_min_duration_minutes' => $candidate['recovery_min_duration_minutes'] ?? null,
                    'recovery_phase' => $candidate['recovery_phase'] ?? null,
                    'recovery_structure_score' => $candidate['recovery_structure_score'] ?? null,
                    'higher_low_count' => $candidate['higher_low_count'] ?? null,
                    'higher_close_count' => $candidate['higher_close_count'] ?? null,
                    'single_candle_dominance_pct' => $candidate['single_candle_dominance_pct'] ?? null,
                    'recovery_score' => $candidate['recovery_score'] ?? null,
                    'open_interest_growth_pct' => $candidate['open_interest_growth_pct'] ?? null,
                    'open_interest_growth_score' => $candidate['open_interest_growth_score'] ?? null,
                    'combined_recovery_score' => $candidate['combined_recovery_score'] ?? null,
                    'current_price_change_pct_10m' => $candidate['current_price_change_pct_10m'] ?? null,
                    'impulse_speed_pct_per_min' => $candidate['impulse_speed_pct_per_min'] ?? null,
                    'roi_equivalent_10m' => $candidate['roi_equivalent_10m'] ?? null,
                    'fast_spike_detected' => (bool)($candidate['fast_spike_detected'] ?? false),
                    'late_spike_detected' => (bool)($candidate['late_spike_detected'] ?? false),
                    'controlled_speed' => $candidate['controlled_speed'] ?? null,
                    'data_source_used' => $candidate['data_source_used'] ?? null,
                ];
            }

            if (!empty($res['signal'])) {
                $newSignals[] = $res['signal'];
            }

            $reason = (string)($candidate['raw_reject_reason'] ?? '');
            if ($reason !== '') {
                $diag['reject_reason_counts'][$reason] = (int)($diag['reject_reason_counts'][$reason] ?? 0) + 1;
            }

            foreach ((array)($candidate['filter_results'] ?? []) as $frow) {
                if (!is_array($frow)) {
                    continue;
                }
                $fid = trim((string)($frow['filter_id'] ?? ''));
                if ($fid === '') {
                    continue;
                }
                $bucket = &$diag['filter_engine_results_by_filter'][$fid];
                if (!is_array($bucket)) {
                    $bucket = [
                        'filter_id' => $fid,
                        'seen_total' => 0,
                        'enabled_total' => 0,
                        'passed_total' => 0,
                        'blocked_total' => 0,
                        'warning_total' => 0,
                    ];
                }
                $bucket['filter_id'] = $fid;
                $bucket['seen_total']++;
                if (!empty($frow['enabled'])) {
                    $bucket['enabled_total']++;
                }
                if ((bool)($frow['passed'] ?? false)) {
                    $bucket['passed_total']++;
                } else {
                    $bucket['blocked_total']++;
                }
                if ((string)($frow['severity'] ?? '') === 'warning') {
                    $bucket['warning_total']++;
                }
                unset($bucket);
            }

            $m = $res['metrics'];
            if ($m['coin_context_checked'] ?? false) {
                $diag['coin_context_checked_total']++;
                $coinContext = is_array($candidate['coin_context'] ?? null) ? (array)$candidate['coin_context'] : [];
                $contextAvailable = (bool)($coinContext['context_available'] ?? false);
                if ($contextAvailable) {
                    $diag['coin_context_available_total']++;
                } else {
                    $diag['coin_context_missing_total']++;
                    $ctxErr = (string)($coinContext['context_error'] ?? 'unknown');
                    $diag['coin_context_error_counts'][$ctxErr] = (int)($diag['coin_context_error_counts'][$ctxErr] ?? 0) + 1;
                }

                $ctxPhase = (string)($coinContext['context_phase'] ?? 'unknown');
                $diag['coin_context_phase_counts'][$ctxPhase] = (int)($diag['coin_context_phase_counts'][$ctxPhase] ?? 0) + 1;
                $ctxTrend1h = (string)($coinContext['trend_1h_direction'] ?? 'unknown');
                $diag['coin_context_trend_1h_counts'][$ctxTrend1h] = (int)($diag['coin_context_trend_1h_counts'][$ctxTrend1h] ?? 0) + 1;
                $ctxQuality = (string)($coinContext['context_quality'] ?? 'unknown');
                $diag['coin_context_quality_counts'][$ctxQuality] = (int)($diag['coin_context_quality_counts'][$ctxQuality] ?? 0) + 1;
                if (in_array('chaotic_context_quality_downgraded', (array)($coinContext['context_reasons'] ?? []), true)) {
                    $diag['chaotic_context_quality_downgraded_total']++;
                }
                if (in_array('downtrend_context_quality_downgraded', (array)($coinContext['context_reasons'] ?? []), true)) {
                    $diag['downtrend_context_quality_downgraded_total']++;
                }
                if (in_array('spike_context_quality_downgraded', (array)($coinContext['context_reasons'] ?? []), true)) {
                    $diag['spike_context_quality_downgraded_total']++;
                }

                if (count($diag['coin_context_examples']) < 20) {
                    $diag['coin_context_examples'][] = [
                        'symbol' => $candidate['symbol'] ?? null,
                        'context_available' => $contextAvailable,
                        'trend_1h_direction' => $coinContext['trend_1h_direction'] ?? null,
                        'trend_2h_direction' => $coinContext['trend_2h_direction'] ?? null,
                        'trend_4h_direction' => $coinContext['trend_4h_direction'] ?? null,
                        'price_change_1h_pct' => $coinContext['price_change_1h_pct'] ?? null,
                        'corridor_position_pct' => $coinContext['corridor_position_pct'] ?? null,
                        'room_to_recent_high_pct' => $coinContext['room_to_recent_high_pct'] ?? null,
                        'distance_from_recent_low_pct' => $coinContext['distance_from_recent_low_pct'] ?? null,
                        'context_phase' => $coinContext['context_phase'] ?? null,
                        'context_quality' => $coinContext['context_quality'] ?? null,
                        'context_reasons' => $coinContext['context_reasons'] ?? [],
                    ];
                }

                $waveQualityFilterRow = null;
                foreach ((array)($candidate['filter_results'] ?? []) as $filterRow) {
                    if (!is_array($filterRow)) {
                        continue;
                    }
                    if ((string)($filterRow['filter_id'] ?? '') === 'wave_quality_filter') {
                        $waveQualityFilterRow = $filterRow;
                        break;
                    }
                }
                if (is_array($waveQualityFilterRow)) {
                    $diag['wave_quality_filter_checked_total']++;
                    if ((bool)($waveQualityFilterRow['passed'] ?? false)) {
                        $diag['wave_quality_filter_passed_total']++;
                    } else {
                        $diag['wave_quality_filter_blocked_total']++;
                    }
                    if (count($diag['wave_quality_filter_examples']) < 20) {
                        $diag['wave_quality_filter_examples'][] = [
                            'symbol' => $candidate['symbol'] ?? null,
                            'recovery_phase' => $candidate['recovery_phase'] ?? null,
                            'entry_timing' => $candidate['entry_timing'] ?? null,
                            'smooth_growth_pct' => $candidate['smooth_growth_pct'] ?? null,
                            'open_interest_growth_pct' => $candidate['open_interest_growth_pct'] ?? null,
                            'context_phase' => $coinContext['context_phase'] ?? null,
                            'context_quality' => $coinContext['context_quality'] ?? null,
                            'context_reasons' => $coinContext['context_reasons'] ?? [],
                            'trend_1h_direction' => $coinContext['trend_1h_direction'] ?? null,
                            'trend_2h_direction' => $coinContext['trend_2h_direction'] ?? null,
                            'filter_result' => [
                                'passed' => (bool)($waveQualityFilterRow['passed'] ?? false),
                                'reason' => (string)($waveQualityFilterRow['reason'] ?? ''),
                                'severity' => (string)($waveQualityFilterRow['severity'] ?? ''),
                            ],
                            'handoff_ready' => (bool)($candidate['handoff_ready'] ?? false),
                            'handoff_block_reason' => $candidate['handoff_block_reason'] ?? null,
                        ];
                    }
                }
            }
            if ($m['orderbook_context_checked'] ?? false) {
                $diag['orderbook_context_checked_total']++;
                $orderbookContext = is_array($candidate['orderbook_context'] ?? null) ? (array)$candidate['orderbook_context'] : [];
                $orderbookAvailable = (bool)($orderbookContext['orderbook_context_available'] ?? false);
                if ($orderbookAvailable) {
                    $diag['orderbook_context_available_total']++;
                } else {
                    $diag['orderbook_context_missing_total']++;
                    $obErr = (string)($orderbookContext['orderbook_context_error'] ?? 'unknown');
                    $diag['orderbook_context_error_counts'][$obErr] = (int)($diag['orderbook_context_error_counts'][$obErr] ?? 0) + 1;
                }
                if ((bool)($orderbookContext['ask_wall_detected'] ?? false)) {
                    $diag['orderbook_ask_wall_detected_total']++;
                }
                if ((string)($orderbookContext['ask_wall_risk'] ?? 'none') === 'high') {
                    $diag['orderbook_ask_wall_high_risk_total']++;
                }
                if ((string)($orderbookContext['bid_support_quality'] ?? 'none') === 'strong') {
                    $diag['orderbook_bid_support_strong_total']++;
                }

                $orderbookFilterRow = null;
                foreach ((array)($candidate['filter_results'] ?? []) as $filterRow) {
                    if (!is_array($filterRow)) {
                        continue;
                    }
                    if ((string)($filterRow['filter_id'] ?? '') === 'orderbook_wall_filter') {
                        $orderbookFilterRow = $filterRow;
                        break;
                    }
                }
                if (is_array($orderbookFilterRow)) {
                    $diag['orderbook_filter_checked_total']++;
                    if ((bool)($orderbookFilterRow['passed'] ?? false)) {
                        $diag['orderbook_filter_passed_total']++;
                    } else {
                        $diag['orderbook_filter_blocked_total']++;
                    }
                    if ((string)($orderbookFilterRow['reason'] ?? '') === 'orderbook_missing_allowed') {
                        $diag['orderbook_filter_missing_allowed_total']++;
                    }
                }

                if (count($diag['orderbook_filter_examples']) < 20) {
                    $diag['orderbook_filter_examples'][] = [
                        'symbol' => $candidate['symbol'] ?? null,
                        'entry_price' => $candidate['entry_price'] ?? null,
                        'recovery_phase' => $candidate['recovery_phase'] ?? null,
                        'entry_timing' => $candidate['entry_timing'] ?? null,
                        'smooth_growth_pct' => $candidate['smooth_growth_pct'] ?? null,
                        'open_interest_growth_pct' => $candidate['open_interest_growth_pct'] ?? null,
                        'nearest_ask_wall_price' => $orderbookContext['nearest_ask_wall_price'] ?? null,
                        'nearest_ask_wall_distance_pct' => $orderbookContext['nearest_ask_wall_distance_pct'] ?? null,
                        'nearest_ask_wall_notional' => $orderbookContext['nearest_ask_wall_notional'] ?? null,
                        'ask_wall_risk' => $orderbookContext['ask_wall_risk'] ?? null,
                        'nearest_bid_wall_price' => $orderbookContext['nearest_bid_wall_price'] ?? null,
                        'nearest_bid_wall_distance_pct' => $orderbookContext['nearest_bid_wall_distance_pct'] ?? null,
                        'bid_support_score' => $orderbookContext['bid_support_score'] ?? null,
                        'bid_ask_notional_ratio' => $orderbookContext['bid_ask_notional_ratio'] ?? null,
                        'filter_result' => is_array($orderbookFilterRow)
                            ? [
                                'passed' => (bool)($orderbookFilterRow['passed'] ?? false),
                                'reason' => (string)($orderbookFilterRow['reason'] ?? ''),
                                'severity' => (string)($orderbookFilterRow['severity'] ?? ''),
                            ]
                            : null,
                        'handoff_ready' => (bool)($candidate['handoff_ready'] ?? false),
                        'handoff_block_reason' => $candidate['handoff_block_reason'] ?? null,
                    ];
                }
            }
            if ((bool)($candidate['dump_detected'] ?? false)) {
                $diag['dump_detected_total']++;
            }
            if ((bool)($candidate['stabilization_detected'] ?? false)) {
                $diag['stabilization_detected_total']++;
            }
            if ((bool)($candidate['smooth_growth_detected'] ?? false)) {
                $diag['smooth_growth_detected_total']++;
            }
            $diag['phase_evaluated_total']++;
            $entryTiming = (string)($candidate['entry_timing'] ?? '');
            $phaseKey = (string)($candidate['recovery_phase'] ?? 'failed');
            if ($phaseKey === 'early_entry') {
                $diag['phase_early_entry_total']++;
            } elseif ($phaseKey === 'stabilizing') {
                $diag['phase_stabilizing_total']++;
            } elseif ($phaseKey === 'dump_only') {
                $diag['phase_dump_only_total']++;
            } elseif ($phaseKey === 'confirmed_later') {
                $diag['phase_confirmed_later_total']++;
            } elseif ($phaseKey === 'late_spike') {
                $diag['phase_late_spike_total']++;
            } elseif ($phaseKey === 'extended') {
                $diag['phase_extended_total']++;
            } else {
                $diag['phase_failed_total']++;
            }
            if ($entryTiming === 'early') {
                $diag['early_entry_candidates_total']++;
            } elseif ($entryTiming === 'stabilizing') {
                $diag['stabilizing_candidates_total']++;
            } elseif ($entryTiming === 'confirmed_later') {
                $diag['confirmed_later_candidates_total']++;
            } elseif ($entryTiming === 'late_spike') {
                $diag['late_spike_candidates_total']++;
            } elseif ($entryTiming === 'extended') {
                $diag['extended_candidates_total']++;
            }
            if ((bool)($candidate['handoff_ready'] ?? false) && $entryTiming === 'early') {
                $diag['early_entry_handoff_ready_total']++;
            }
            if ($phaseKey === 'early_entry') {
                $diag['handoff_candidates_before_filters_total']++;
            }
            $phaseBlockReason = trim((string)($candidate['phase_block_reason'] ?? ''));
            if ($phaseKey !== 'early_entry') {
                $diag['handoff_blocked_by_phase_total']++;
                if ($phaseBlockReason !== '') {
                    $diag['handoff_blocked_by_phase_reason_counts'][$phaseBlockReason] = (int)($diag['handoff_blocked_by_phase_reason_counts'][$phaseBlockReason] ?? 0) + 1;
                }
            }
            $filterBlockReasons = [];
            foreach ((array)($candidate['filter_results'] ?? []) as $filterRow) {
                if (!is_array($filterRow) || (bool)($filterRow['passed'] ?? false)) {
                    continue;
                }
                $severity = (string)($filterRow['severity'] ?? '');
                if (!in_array($severity, ['fatal', 'hard_block'], true)) {
                    continue;
                }
                $filterId = trim((string)($filterRow['filter_id'] ?? ''));
                if ($filterId !== '') {
                    $filterBlockReasons[] = $filterId;
                }
            }
            $filterBlockReasons = array_values(array_unique($filterBlockReasons));
            if ($phaseKey === 'early_entry' && $filterBlockReasons !== []) {
                $diag['handoff_blocked_by_filter_total']++;
                foreach ($filterBlockReasons as $filterBlockReason) {
                    $diag['handoff_blocked_by_filter_reason_counts'][$filterBlockReason] = (int)($diag['handoff_blocked_by_filter_reason_counts'][$filterBlockReason] ?? 0) + 1;
                }
            }
            if ($entryTiming !== 'early' && !empty($candidate['handoff_block_reason'])) {
                $diag['non_early_handoff_blocked_total']++;
            }
            if ((string)($candidate['handoff_block_reason'] ?? '') === 'late_spike_detected') {
                $diag['late_spike_handoff_blocked_total']++;
            }
            if ((string)($candidate['handoff_block_reason'] ?? '') === 'extended_recovery_late') {
                $diag['extended_handoff_blocked_total']++;
            }

            if ($m['dump_pass'] ?? false) {
                $diag['prior_decline_passed_total']++;
            }
            if ($m['smooth_growth_pass'] ?? false) {
                $diag['recovery_growth_passed_total']++;
            }
            if ($m['oi_pass'] ?? false) {
                $diag['open_interest_growth_passed_total']++;
            }
            if ($m['oi_missing_allowed'] ?? false) {
                $diag['oi_missing_allowed_total']++;
                if (count($diag['open_interest_missing_examples']) < 8) {
                    $diag['open_interest_missing_examples'][] = [
                        'symbol' => $candidate['symbol'],
                        'detected_at' => $candidate['detected_at'],
                    ];
                }
            }
            if ($m['oi_missing_blocked'] ?? false) {
                $diag['oi_missing_blocked_total']++;
            }
            if ($m['insufficient_data'] ?? false) {
                $diag['insufficient_data_total']++;
            }
            if ($m['stale_data'] ?? false) {
                $diag['stale_data_total']++;
            }
            if ($m['data_source_error'] ?? false) {
                $diag['data_source_error_total']++;
            }
            if ($m['accel_computed'] ?? false) {
                $diag['current_acceleration_diagnostic_total']++;
            }
            $rawRejectReason = (string)($candidate['raw_reject_reason'] ?? '');
            if ($rawRejectReason === 'too_early_no_structure') {
                $diag['too_early_no_structure_total']++;
            } elseif ($rawRejectReason === 'recovery_structure_too_weak') {
                $diag['recovery_structure_too_weak_total']++;
            }
            if ((bool)($candidate['late_spike_detected'] ?? false)) {
                $diag['late_spike_detected_total']++;
            }
            if ((string)($candidate['recovery_phase'] ?? '') === 'early_entry') {
                $diag['recovery_phase_valid_recovery_total']++;
            }
            if ($m['late_spike_detected'] ?? false) {
                $diag['fast_spike_detected_total']++;
            }
            if ($candidate['raw_strategy_passed'] ?? false) {
                $diag['raw_strategy_passed_total']++;
            } else {
                $diag['raw_strategy_rejected_total']++;
            }
            if ($m['filter_checked'] ?? false) {
                $diag['filter_engine_checked_total']++;
            }
            if ($m['filter_blocked'] ?? false) {
                $diag['filter_engine_blocked_total']++;
            }
            if ($m['filter_diagnostic_only'] ?? false) {
                $diag['filter_engine_diagnostic_only_total']++;
            }

            // Track quality guard blocks for current-batch candidates
            if (($candidate['raw_strategy_passed'] ?? false)) {
                $qualityGuardFilterIds = ['wave_quality_filter', 'orderbook_wall_filter'];
                $wouldHaveBlocked = is_array($candidate['would_have_blocked_by_filters'] ?? null)
                    ? (array)$candidate['would_have_blocked_by_filters']
                    : [];
                $qualityGuardBlockers = array_values(array_intersect($qualityGuardFilterIds, $wouldHaveBlocked));
                if ($qualityGuardBlockers !== []) {
                    $diag['handoff_blocked_by_quality_guard_total']++;
                    if (count($diag['handoff_blocked_by_quality_guard_examples']) < 20) {
                        $coinCtx = is_array($candidate['coin_context'] ?? null) ? (array)$candidate['coin_context'] : [];
                        $obCtx = is_array($candidate['orderbook_context'] ?? null) ? (array)$candidate['orderbook_context'] : [];
                        $diag['handoff_blocked_by_quality_guard_examples'][] = [
                            'symbol' => $candidate['symbol'] ?? null,
                            'recovery_phase' => $candidate['recovery_phase'] ?? null,
                            'entry_timing' => $candidate['entry_timing'] ?? null,
                            'context_phase' => $coinCtx['context_phase'] ?? null,
                            'context_quality' => $coinCtx['context_quality'] ?? null,
                            'context_reasons' => $coinCtx['context_reasons'] ?? [],
                            'trend_1h_direction' => $coinCtx['trend_1h_direction'] ?? null,
                            'trend_2h_direction' => $coinCtx['trend_2h_direction'] ?? null,
                            'ask_wall_risk' => $obCtx['ask_wall_risk'] ?? null,
                            'nearest_ask_wall_distance_pct' => $obCtx['nearest_ask_wall_distance_pct'] ?? null,
                            'nearest_ask_wall_notional' => $obCtx['nearest_ask_wall_notional'] ?? null,
                            'bid_support_quality' => $obCtx['bid_support_quality'] ?? null,
                            'handoff_ready' => (bool)($candidate['handoff_ready'] ?? false),
                            'handoff_block_reason' => $candidate['handoff_block_reason'] ?? null,
                            'would_have_blocked_by_filters' => $qualityGuardBlockers,
                        ];
                    }
                }
            }
        }

        $allEvaluated = array_merge(is_array($allEvaluated) ? $allEvaluated : [], $newEvaluated);
        $allCandidates = array_merge(is_array($allCandidates) ? $allCandidates : [], $newCandidates);
        $allWatchCandidates = array_values($watchMap);
        $allRejects = array_merge(is_array($allRejects) ? $allRejects : [], $newRejects);

        $signalIndex = [];
        foreach (is_array($allSignals) ? $allSignals : [] as $signal) {
            if (!is_array($signal)) {
                continue;
            }
            $id = (string)($signal['signal_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $signalIndex[$id] = $signal;
        }
        foreach ($newSignals as $signal) {
            $id = (string)($signal['signal_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $signalIndex[$id] = $signal;
        }
        $allSignals = array_values($signalIndex);

        $allEvaluated = array_slice($allEvaluated, -max(100, (int)$config['max_evaluated_store']));
        $allCandidates = array_slice($allCandidates, -max(100, (int)$config['max_candidates_store']));
        $allWatchCandidates = array_slice($allWatchCandidates, -max(100, (int)$config['max_near_pass_store']));
        $allRejects = array_slice($allRejects, -max(100, (int)$config['max_rejects_store']));
        $allSignals = array_slice($allSignals, -max(100, (int)$config['max_signals_store']));

        $canEmitBotHandoff = (bool)$config['handoff_enabled'] && (bool)$config['emit_bot_handoff'];
        $queueMode = $this->resolveHandoffMode($config);
        $botReadyTtlMinutes = max(1, (int)$config['bot_ready_ttl_minutes']);
        $queueNowIso = date('c');
        $queueNowTs = time();

        $currentRunSignalIds = [];
        foreach ($newSignals as $signal) {
            if (!is_array($signal)) {
                continue;
            }
            $sid = trim((string)($signal['signal_id'] ?? ''));
            if ($sid !== '') {
                $currentRunSignalIds[$sid] = true;
            }
        }

        // Re-evaluate existing handoff-ready signals (not from the current batch) against
        // quality guard hard-block filters and withdraw those that now fail.
        $qualityGuardFilterIds = ['wave_quality_filter', 'orderbook_wall_filter'];
        $qualityGuardsActive = (bool)$config['filter_engine_enabled']
            && in_array((string)$config['filter_enforcement_mode'], ['soft', 'strict'], true);
        if ($qualityGuardsActive) {
            foreach ($allSignals as $idx => $sig) {
                if (!is_array($sig)) {
                    continue;
                }
                // Skip signals processed in the current batch (already evaluated)
                $sid = trim((string)($sig['signal_id'] ?? ''));
                if ($sid !== '' && isset($currentRunSignalIds[$sid])) {
                    continue;
                }
                if (!($sig['handoff_ready'] ?? false) || !($sig['executable'] ?? false)) {
                    continue;
                }
                $diag['quality_guard_existing_ready_checked_total']++;
                $existingFilterEval = $this->evaluateFilterEngine($sig, $config);
                $blockingReasons = array_merge(
                    array_values(array_filter(array_map('strval', (array)($existingFilterEval['fatal_filter_reasons'] ?? [])), static fn(string $v): bool => $v !== '')),
                    array_values(array_filter(array_map('strval', (array)($existingFilterEval['hard_block_filter_reasons'] ?? [])), static fn(string $v): bool => $v !== ''))
                );
                $qualityGuardBlockers = array_values(array_intersect($qualityGuardFilterIds, $blockingReasons));
                if ($qualityGuardBlockers !== []) {
                    $diag['quality_guard_existing_ready_withdrawn_total']++;
                    $blockReason = implode(',', $qualityGuardBlockers);
                    $allSignals[$idx] = $this->markSignalWithdrawn($sig, false, $blockReason, $queueNowIso);
                    if (count($diag['quality_guard_existing_ready_withdrawn_examples']) < 8) {
                        $coinCtx = is_array($sig['coin_context'] ?? null) ? (array)$sig['coin_context'] : [];
                        $obCtx = is_array($sig['orderbook_context'] ?? null) ? (array)$sig['orderbook_context'] : [];
                        $diag['quality_guard_existing_ready_withdrawn_examples'][] = [
                            'symbol' => $sig['symbol'] ?? null,
                            'recovery_phase' => $sig['recovery_phase'] ?? null,
                            'entry_timing' => $sig['entry_timing'] ?? null,
                            'context_phase' => $coinCtx['context_phase'] ?? ($sig['coin_context_phase'] ?? null),
                            'context_quality' => $coinCtx['context_quality'] ?? ($sig['coin_context_quality'] ?? null),
                            'ask_wall_risk' => $obCtx['ask_wall_risk'] ?? ($sig['ask_wall_risk'] ?? null),
                            'nearest_ask_wall_distance_pct' => $obCtx['nearest_ask_wall_distance_pct'] ?? ($sig['nearest_ask_wall_distance_pct'] ?? null),
                            'block_reasons' => $qualityGuardBlockers,
                        ];
                    }
                }
            }
        }

        $botQueueCandidatesConsideredTotal = 0;
        $botQueueStaleSkippedTotal = 0;
        $botQueueDuplicateSkippedTotal = 0;
        $botQueueMissingRequiredFieldsTotal = 0;
        $botQueueMissingRequiredFieldsExamples = [];

        $handoffQueue = [];
        if ($canEmitBotHandoff) {
            $queueCandidates = [];
            foreach ($allSignals as $idx => $sig) {
                if (!is_array($sig)) {
                    continue;
                }
                if (($sig['handoff_ready'] ?? false) !== true
                    || ($sig['executable'] ?? false) !== true
                    || ($sig['active_final'] ?? false) !== true
                ) {
                    continue;
                }
                $botQueueCandidatesConsideredTotal++;

                $record = $this->buildBotQueueRecordFromSignal($sig, $config, $queueMode, $queueNowIso);
                $detectedTs = $this->parseIsoToTs((string)($record['detected_at'] ?? ''));
                $isFresh = $detectedTs !== null && (($queueNowTs - $detectedTs) <= ($botReadyTtlMinutes * 60));
                if (!$isFresh) {
                    $botQueueStaleSkippedTotal++;
                    $allSignals[$idx] = $this->markSignalWithdrawn($sig, true, 'bot_ready_ttl_expired', $queueNowIso);
                    continue;
                }

                $validation = $this->validateBotQueueRecord($record, $queueMode);
                if (!($validation['valid'] ?? false)) {
                    $botQueueMissingRequiredFieldsTotal++;
                    if (count($botQueueMissingRequiredFieldsExamples) < 8) {
                        $botQueueMissingRequiredFieldsExamples[] = [
                            'signal_id' => (string)($record['signal_id'] ?? ''),
                            'symbol' => (string)($record['symbol'] ?? ''),
                            'missing_fields' => array_values(array_map('strval', (array)($validation['missing_fields'] ?? []))),
                        ];
                    }
                    $allSignals[$idx] = $this->markSignalWithdrawn($sig, false, 'missing_required_fields', $queueNowIso);
                    continue;
                }

                $allSignals[$idx] = array_merge(
                    $sig,
                    $record,
                    [
                        'handoff_ready' => true,
                        'executable' => true,
                        'active_final' => true,
                        'stale' => false,
                        'stale_reason' => null,
                    ]
                );
                $queueCandidates[] = [
                    'record' => $record,
                    'signal_index' => $idx,
                    'is_current_run' => isset($currentRunSignalIds[(string)($record['signal_id'] ?? '')]),
                    'detected_ts' => $detectedTs ?? 0,
                    'refreshed_ts' => $this->parseIsoToTs((string)($record['refreshed_at'] ?? '')) ?? 0,
                ];
            }

            usort($queueCandidates, static function (array $a, array $b): int {
                $aCurrent = !empty($a['is_current_run']);
                $bCurrent = !empty($b['is_current_run']);
                if ($aCurrent !== $bCurrent) {
                    return $aCurrent ? -1 : 1;
                }
                $detCmp = ((int)($b['detected_ts'] ?? 0)) <=> ((int)($a['detected_ts'] ?? 0));
                if ($detCmp !== 0) {
                    return $detCmp;
                }
                return ((int)($b['refreshed_ts'] ?? 0)) <=> ((int)($a['refreshed_ts'] ?? 0));
            });

            $dedupeSeen = [];
            foreach ($queueCandidates as $candidate) {
                $record = (array)($candidate['record'] ?? []);
                $signalIndex = (int)($candidate['signal_index'] ?? -1);
                $dedupeKey = (string)($record['strategy_id'] ?? self::STRATEGY_ID)
                    . '|' . strtolower((string)($record['symbol'] ?? ''))
                    . '|' . strtolower((string)($record['side'] ?? ''));
                if ($dedupeKey === self::STRATEGY_ID . '||') {
                    continue;
                }
                if (isset($dedupeSeen[$dedupeKey])) {
                    $botQueueDuplicateSkippedTotal++;
                    if (isset($allSignals[$signalIndex]) && is_array($allSignals[$signalIndex])) {
                        $allSignals[$signalIndex] = $this->markSignalWithdrawn(
                            (array)$allSignals[$signalIndex],
                            false,
                            'duplicate_ready_same_symbol_side',
                            $queueNowIso
                        );
                    }
                    continue;
                }
                $dedupeSeen[$dedupeKey] = true;
                $handoffQueue[] = $record;
            }
        }

        $this->writeJson($this->storagePath('evaluated_contexts.json'), $allEvaluated);
        $this->writeJson($this->storagePath('candidates.json'), $allCandidates);
        $this->writeJson($this->storagePath('watch_candidates.json'), $allWatchCandidates);
        $this->writeJson($this->storagePath('near_pass_candidates.json'), $allWatchCandidates);
        $this->writeJson($this->storagePath('rejects.json'), $allRejects);
        $this->writeJson($this->storagePath('signals.json'), $allSignals);
        $this->writeJson($this->storagePath('bot_handoff_queue.json'), $handoffQueue);

        $statusDone = $offsetAfter >= $selectedTotal;
        $state['status'] = $statusDone ? 'done' : 'running';
        $state['started_at'] = $state['started_at'] ?? $startedAt;
        $state['updated_at'] = date('c');
        $state['finished_at'] = $statusDone ? date('c') : null;
        $state['batch_offset'] = $statusDone ? 0 : $offsetAfter;
        $state['batch_offset_before'] = $offsetBefore;
        $state['batch_offset_after'] = $offsetAfter;
        $this->writeJson($this->storagePath('run_state.json'), $state);

        $currentRunHandoffReadyTotal = count(array_filter($newSignals, static fn(array $s): bool => is_array($s) && (bool)($s['handoff_ready'] ?? false)));
        $handoffReadyTotal = count(array_filter($allSignals, static fn(array $s): bool => is_array($s) && (bool)($s['handoff_ready'] ?? false)));
        $currentRunBotQueueWrittenTotal = 0;
        foreach ($handoffQueue as $queued) {
            $sid = is_array($queued) ? (string)($queued['signal_id'] ?? '') : '';
            if ($sid !== '' && isset($currentRunSignalIds[$sid])) {
                $currentRunBotQueueWrittenTotal++;
            }
        }
        $storedBotQueueWrittenTotal = count($handoffQueue);
        $actualBotHandoffQueueRecordsTotal = count($handoffQueue);
        $handoffEnabled = (bool)$config['handoff_enabled'];
        $emitBotHandoff = (bool)$config['emit_bot_handoff'];
        $effectiveBotHandoffEnabled = $handoffEnabled && $emitBotHandoff;
        if (!$handoffEnabled) {
            $botHandoffBlockReason = 'handoff_disabled';
        } elseif (!$emitBotHandoff) {
            $botHandoffBlockReason = 'emit_bot_handoff_disabled';
        } else {
            $botHandoffBlockReason = null;
        }

        $enabledFilters = $this->computeEnabledFilters($config);
        $filterCatalog = $this->getFilterCatalog();
        $acceptedExamples = $newCandidates;
        $rejectedExamples = array_values(array_filter($newEvaluated, static fn(array $c): bool => !(bool)($c['raw_strategy_passed'] ?? false)));
        $watchExamples = $newWatchCandidates;

        usort($acceptedExamples, static fn(array $a, array $b): int => ((float)($b['combined_recovery_score'] ?? 0.0) <=> (float)($a['combined_recovery_score'] ?? 0.0)));
        $bestRecoveryExamples = $acceptedExamples;
        if ($bestRecoveryExamples === []) {
            $bestRecoveryExamples = $watchExamples;
            usort($bestRecoveryExamples, static fn(array $a, array $b): int => ((float)($b['combined_recovery_score'] ?? 0.0) <=> (float)($a['combined_recovery_score'] ?? 0.0)));
        }
        if ($bestRecoveryExamples === []) {
            $bestRecoveryExamples = $newEvaluated;
            usort($bestRecoveryExamples, static fn(array $a, array $b): int => ((float)($b['combined_recovery_score'] ?? 0.0) <=> (float)($a['combined_recovery_score'] ?? 0.0)));
        }
        usort($bestRecoveryExamples, static fn(array $a, array $b): int => ((float)($b['recovery_growth_pct'] ?? 0.0) <=> (float)($a['recovery_growth_pct'] ?? 0.0)));
        $openInterestExamples = array_values(array_filter($acceptedExamples, static fn(array $c): bool => isset($c['open_interest_growth_pct']) && $c['open_interest_growth_pct'] !== null));
        if ($openInterestExamples === []) {
            $openInterestExamples = array_values(array_filter($watchExamples, static fn(array $c): bool => isset($c['open_interest_growth_pct']) && $c['open_interest_growth_pct'] !== null));
        }
        if ($openInterestExamples === []) {
            $openInterestExamples = array_values(array_filter($newEvaluated, static fn(array $c): bool => isset($c['open_interest_growth_pct']) && $c['open_interest_growth_pct'] !== null));
        }
        usort($openInterestExamples, static fn(array $a, array $b): int => ((float)($b['open_interest_growth_pct'] ?? -INF) <=> (float)($a['open_interest_growth_pct'] ?? -INF)));
        $priorDeclineExamples = $newEvaluated;
        usort($priorDeclineExamples, static fn(array $a, array $b): int => ((float)($b['prior_decline_pct'] ?? 0.0) <=> (float)($a['prior_decline_pct'] ?? 0.0)));
        $accelExamples = $newEvaluated;
        usort($accelExamples, static fn(array $a, array $b): int => ((float)($b['current_price_change_pct_10m'] ?? -INF) <=> (float)($a['current_price_change_pct_10m'] ?? -INF)));
        $fastSpikeExamples = array_values(array_filter($newEvaluated, static fn(array $c): bool => (bool)($c['fast_spike_detected'] ?? false)));
        usort($fastSpikeExamples, static fn(array $a, array $b): int => ((float)($b['roi_equivalent_10m'] ?? -INF) <=> (float)($a['roi_equivalent_10m'] ?? -INF)));
        $lateSpikeExamples = array_values(array_filter($newEvaluated, static fn(array $c): bool => (bool)($c['late_spike_detected'] ?? false)));
        usort($lateSpikeExamples, static fn(array $a, array $b): int => ((float)($b['impulse_speed_pct_per_min'] ?? -INF) <=> (float)($a['impulse_speed_pct_per_min'] ?? -INF)));
        $tooEarlyExamples = array_values(array_filter($newEvaluated, static fn(array $c): bool => (string)($c['recovery_phase'] ?? '') === 'too_early'));
        usort($tooEarlyExamples, static fn(array $a, array $b): int => ((int)($b['higher_low_count'] ?? 0) <=> (int)($a['higher_low_count'] ?? 0)));
        $recoveryStructureTooWeakExamples = array_values(array_filter($newEvaluated, static fn(array $c): bool => (string)($c['raw_reject_reason'] ?? '') === 'recovery_structure_too_weak'));
        usort($recoveryStructureTooWeakExamples, static fn(array $a, array $b): int => ((float)($b['recovery_structure_score'] ?? -INF) <=> (float)($a['recovery_structure_score'] ?? -INF)));

        $acceptedExamples = array_slice(array_map(static function (array $c): array {
            $coinContext = is_array($c['coin_context'] ?? null) ? (array)$c['coin_context'] : [];
            return [
                'symbol' => $c['symbol'] ?? null,
                'entry_price' => $c['entry_price'] ?? null,
                'prior_decline_pct' => $c['prior_decline_pct'] ?? null,
                'recovery_growth_pct' => $c['recovery_growth_pct'] ?? null,
                'recovery_duration_minutes' => $c['recovery_duration_minutes'] ?? null,
                'recovery_phase' => $c['recovery_phase'] ?? null,
                'recovery_structure_score' => $c['recovery_structure_score'] ?? null,
                'higher_low_count' => $c['higher_low_count'] ?? null,
                'higher_close_count' => $c['higher_close_count'] ?? null,
                'recovery_score' => $c['recovery_score'] ?? null,
                'open_interest_growth_pct' => $c['open_interest_growth_pct'] ?? null,
                'current_price_change_pct_10m' => $c['current_price_change_pct_10m'] ?? null,
                'combined_recovery_score' => $c['combined_recovery_score'] ?? null,
                'raw_strategy_passed' => (bool)($c['raw_strategy_passed'] ?? false),
                'handoff_ready' => (bool)($c['handoff_ready'] ?? false),
                'context_available' => (bool)($coinContext['context_available'] ?? false),
                'trend_1h_direction' => $coinContext['trend_1h_direction'] ?? null,
                'trend_2h_direction' => $coinContext['trend_2h_direction'] ?? null,
                'price_change_1h_pct' => $coinContext['price_change_1h_pct'] ?? null,
                'corridor_position_pct' => $coinContext['corridor_position_pct'] ?? null,
                'room_to_recent_high_pct' => $coinContext['room_to_recent_high_pct'] ?? null,
                'distance_from_recent_low_pct' => $coinContext['distance_from_recent_low_pct'] ?? null,
                'context_phase' => $coinContext['context_phase'] ?? null,
                'context_quality' => $coinContext['context_quality'] ?? null,
            ];
        }, $acceptedExamples), 0, 20);
        $rejectedExamples = array_slice(array_map(static function (array $c): array {
            return [
                'symbol' => $c['symbol'] ?? null,
                'raw_reject_reason' => $c['raw_reject_reason'] ?? null,
                'reject_reason' => $c['raw_reject_reason'] ?? null,
                'prior_decline_pct' => $c['prior_decline_pct'] ?? null,
                'recovery_growth_pct' => $c['recovery_growth_pct'] ?? null,
                'recovery_structure_score' => $c['recovery_structure_score'] ?? null,
                'open_interest_growth_pct' => $c['open_interest_growth_pct'] ?? null,
            ];
        }, $rejectedExamples), 0, 20);
        $watchExamples = array_slice(array_map(static function (array $c): array {
            return [
                'symbol' => $c['symbol'] ?? null,
                'entry_price' => $c['entry_price'] ?? null,
                'prior_decline_pct' => $c['prior_decline_pct'] ?? null,
                'recovery_growth_pct' => $c['recovery_growth_pct'] ?? null,
                'recovery_duration_minutes' => $c['recovery_duration_minutes'] ?? null,
                'recovery_structure_score' => $c['recovery_structure_score'] ?? null,
                'open_interest_growth_pct' => $c['open_interest_growth_pct'] ?? null,
                'combined_recovery_score' => $c['combined_recovery_score'] ?? null,
                'raw_reject_reason' => $c['raw_reject_reason'] ?? null,
                'reject_reason' => $c['raw_reject_reason'] ?? null,
            ];
        }, $watchExamples), 0, 20);
        $bestRecoveryExamples = array_slice(array_map(static function (array $c): array {
            return [
                'symbol' => $c['symbol'] ?? null,
                'recovery_growth_pct' => $c['recovery_growth_pct'] ?? null,
                'recovery_duration_minutes' => $c['recovery_duration_minutes'] ?? null,
                'recovery_structure_score' => $c['recovery_structure_score'] ?? null,
                'higher_low_count' => $c['higher_low_count'] ?? null,
                'open_interest_growth_pct' => $c['open_interest_growth_pct'] ?? null,
                'combined_recovery_score' => $c['combined_recovery_score'] ?? null,
            ];
        }, $bestRecoveryExamples), 0, 20);
        $priorDeclineExamples = array_slice(array_map(static function (array $c): array {
            return [
                'symbol' => $c['symbol'] ?? null,
                'prior_decline_pct' => $c['prior_decline_pct'] ?? null,
                'prior_decline_high_price' => $c['prior_decline_high_price'] ?? null,
                'recovery_low_price' => $c['recovery_low_price'] ?? null,
                'combined_recovery_score' => $c['combined_recovery_score'] ?? null,
            ];
        }, $priorDeclineExamples), 0, 20);
        $openInterestExamples = array_slice(array_map(static function (array $c): array {
            return [
                'symbol' => $c['symbol'] ?? null,
                'open_interest_growth_pct' => $c['open_interest_growth_pct'] ?? null,
                'open_interest_growth_score' => $c['open_interest_growth_score'] ?? null,
                'combined_recovery_score' => $c['combined_recovery_score'] ?? null,
            ];
        }, $openInterestExamples), 0, 20);
        $accelExamples = array_slice(array_map(static function (array $c): array {
            return [
                'symbol' => $c['symbol'] ?? null,
                'current_price_change_pct_10m' => $c['current_price_change_pct_10m'] ?? null,
                'current_oi_growth_pct_10m' => $c['current_oi_growth_pct_10m'] ?? null,
                'current_acceleration_score' => $c['current_acceleration_score'] ?? null,
                'combined_recovery_score' => $c['combined_recovery_score'] ?? null,
            ];
        }, $accelExamples), 0, 20);
        $fastSpikeExamples = array_slice(array_map(static function (array $c): array {
            return [
                'symbol' => $c['symbol'] ?? null,
                'recovery_growth_pct' => $c['recovery_growth_pct'] ?? null,
                'recovery_duration_minutes' => $c['recovery_duration_minutes'] ?? null,
                'current_price_change_pct_10m' => $c['current_price_change_pct_10m'] ?? null,
                'roi_equivalent_10m' => $c['roi_equivalent_10m'] ?? null,
                'open_interest_growth_pct' => $c['open_interest_growth_pct'] ?? null,
                'raw_reject_reason' => $c['raw_reject_reason'] ?? null,
            ];
        }, $fastSpikeExamples), 0, 20);
        $lateSpikeExamples = array_slice(array_map(static function (array $c): array {
            return [
                'symbol' => $c['symbol'] ?? null,
                'recovery_growth_pct' => $c['recovery_growth_pct'] ?? null,
                'recovery_duration_minutes' => $c['recovery_duration_minutes'] ?? null,
                'recovery_structure_score' => $c['recovery_structure_score'] ?? null,
                'higher_low_count' => $c['higher_low_count'] ?? null,
                'higher_close_count' => $c['higher_close_count'] ?? null,
                'single_candle_dominance_pct' => $c['single_candle_dominance_pct'] ?? null,
                'impulse_speed_pct_per_min' => $c['impulse_speed_pct_per_min'] ?? null,
                'current_price_change_pct_10m' => $c['current_price_change_pct_10m'] ?? null,
                'roi_equivalent_10m' => $c['roi_equivalent_10m'] ?? null,
                'open_interest_growth_pct' => $c['open_interest_growth_pct'] ?? null,
                'raw_reject_reason' => $c['raw_reject_reason'] ?? null,
            ];
        }, $lateSpikeExamples), 0, 20);
        $tooEarlyExamples = array_slice(array_map(static function (array $c): array {
            return [
                'symbol' => $c['symbol'] ?? null,
                'recovery_growth_pct' => $c['recovery_growth_pct'] ?? null,
                'recovery_duration_minutes' => $c['recovery_duration_minutes'] ?? null,
                'higher_low_count' => $c['higher_low_count'] ?? null,
                'higher_close_count' => $c['higher_close_count'] ?? null,
                'impulse_speed_pct_per_min' => $c['impulse_speed_pct_per_min'] ?? null,
                'current_price_change_pct_10m' => $c['current_price_change_pct_10m'] ?? null,
                'roi_equivalent_10m' => $c['roi_equivalent_10m'] ?? null,
                'open_interest_growth_pct' => $c['open_interest_growth_pct'] ?? null,
                'raw_reject_reason' => $c['raw_reject_reason'] ?? null,
            ];
        }, $tooEarlyExamples), 0, 20);
        $recoveryStructureTooWeakExamples = array_slice(array_map(static function (array $c): array {
            return [
                'symbol' => $c['symbol'] ?? null,
                'recovery_growth_pct' => $c['recovery_growth_pct'] ?? null,
                'recovery_duration_minutes' => $c['recovery_duration_minutes'] ?? null,
                'recovery_structure_score' => $c['recovery_structure_score'] ?? null,
                'higher_low_count' => $c['higher_low_count'] ?? null,
                'higher_close_count' => $c['higher_close_count'] ?? null,
                'single_candle_dominance_pct' => $c['single_candle_dominance_pct'] ?? null,
                'impulse_speed_pct_per_min' => $c['impulse_speed_pct_per_min'] ?? null,
                'current_price_change_pct_10m' => $c['current_price_change_pct_10m'] ?? null,
                'roi_equivalent_10m' => $c['roi_equivalent_10m'] ?? null,
                'open_interest_growth_pct' => $c['open_interest_growth_pct'] ?? null,
                'raw_reject_reason' => $c['raw_reject_reason'] ?? null,
            ];
        }, $recoveryStructureTooWeakExamples), 0, 20);

        $phaseExampleRow = static function (array $c): array {
            $coinContext = is_array($c['coin_context'] ?? null) ? (array)$c['coin_context'] : [];
            $orderbookContext = is_array($c['orderbook_context'] ?? null) ? (array)$c['orderbook_context'] : [];
            $orderbookFilterResult = null;
            $waveQualityFilterResult = null;
            foreach ((array)($c['filter_results'] ?? []) as $filterRow) {
                if (!is_array($filterRow)) {
                    continue;
                }
                if ((string)($filterRow['filter_id'] ?? '') === 'orderbook_wall_filter') {
                    $orderbookFilterResult = [
                        'passed' => (bool)($filterRow['passed'] ?? false),
                        'reason' => (string)($filterRow['reason'] ?? ''),
                        'severity' => (string)($filterRow['severity'] ?? ''),
                    ];
                    continue;
                }
                if ((string)($filterRow['filter_id'] ?? '') === 'wave_quality_filter') {
                    $waveQualityFilterResult = [
                        'passed' => (bool)($filterRow['passed'] ?? false),
                        'reason' => (string)($filterRow['reason'] ?? ''),
                        'severity' => (string)($filterRow['severity'] ?? ''),
                    ];
                }
            }
            return [
                'symbol' => $c['symbol'] ?? null,
                'dump_pct' => $c['dump_pct'] ?? null,
                'stabilization_duration_minutes' => $c['stabilization_duration_minutes'] ?? null,
                'stabilization_range_pct' => $c['stabilization_range_pct'] ?? null,
                'smooth_growth_pct' => $c['smooth_growth_pct'] ?? null,
                'smooth_growth_duration_minutes' => $c['smooth_growth_duration_minutes'] ?? null,
                'open_interest_growth_pct' => $c['open_interest_growth_pct'] ?? null,
                'recovery_phase' => $c['recovery_phase'] ?? null,
                'entry_timing' => $c['entry_timing'] ?? null,
                'handoff_ready' => (bool)($c['handoff_ready'] ?? false),
                'executable' => (bool)($c['executable'] ?? false),
                'early_entry_triggered' => (bool)($c['early_entry_triggered'] ?? false),
                'handoff_block_reason' => $c['handoff_block_reason'] ?? null,
                'phase_block_reason' => $c['phase_block_reason'] ?? null,
                'context_available' => (bool)($coinContext['context_available'] ?? false),
                'trend_1h_direction' => $coinContext['trend_1h_direction'] ?? null,
                'trend_2h_direction' => $coinContext['trend_2h_direction'] ?? null,
                'price_change_1h_pct' => $coinContext['price_change_1h_pct'] ?? null,
                'corridor_position_pct' => $coinContext['corridor_position_pct'] ?? null,
                'room_to_recent_high_pct' => $coinContext['room_to_recent_high_pct'] ?? null,
                'distance_from_recent_low_pct' => $coinContext['distance_from_recent_low_pct'] ?? null,
                'context_phase' => $coinContext['context_phase'] ?? null,
                'context_quality' => $coinContext['context_quality'] ?? null,
                'context_reasons' => $coinContext['context_reasons'] ?? [],
                'wave_quality_filter_result' => $waveQualityFilterResult,
                'nearest_ask_wall_distance_pct' => $orderbookContext['nearest_ask_wall_distance_pct'] ?? null,
                'nearest_ask_wall_notional' => $orderbookContext['nearest_ask_wall_notional'] ?? null,
                'ask_wall_risk' => $orderbookContext['ask_wall_risk'] ?? null,
                'bid_support_score' => $orderbookContext['bid_support_score'] ?? null,
                'bid_ask_notional_ratio' => $orderbookContext['bid_ask_notional_ratio'] ?? null,
                'orderbook_wall_filter_result' => $orderbookFilterResult,
            ];
        };
        $dumpExamples = array_slice(array_map($phaseExampleRow, array_values(array_filter($newEvaluated, static fn(array $c): bool => (bool)($c['dump_detected'] ?? false)))), 0, 20);
        $stabilizationExamples = array_slice(array_map($phaseExampleRow, array_values(array_filter($newEvaluated, static fn(array $c): bool => (bool)($c['stabilization_detected'] ?? false)))), 0, 20);
        $smoothGrowthExamples = array_slice(array_map($phaseExampleRow, array_values(array_filter($newEvaluated, static fn(array $c): bool => (bool)($c['smooth_growth_detected'] ?? false)))), 0, 20);
        $earlyEntryExamples = array_slice(array_map($phaseExampleRow, array_values(array_filter($newEvaluated, static fn(array $c): bool => (string)($c['entry_timing'] ?? '') === 'early'))), 0, 20);
        $stabilizingExamples = array_slice(array_map($phaseExampleRow, array_values(array_filter($newEvaluated, static fn(array $c): bool => (string)($c['entry_timing'] ?? '') === 'stabilizing'))), 0, 20);
        $confirmedLaterExamples = array_slice(array_map($phaseExampleRow, array_values(array_filter($newEvaluated, static fn(array $c): bool => (string)($c['entry_timing'] ?? '') === 'confirmed_later'))), 0, 20);
        $lateSpikePhaseExamples = array_slice(array_map($phaseExampleRow, array_values(array_filter($newEvaluated, static fn(array $c): bool => (string)($c['entry_timing'] ?? '') === 'late_spike'))), 0, 20);
        $extendedExamples = array_slice(array_map($phaseExampleRow, array_values(array_filter($newEvaluated, static fn(array $c): bool => (string)($c['entry_timing'] ?? '') === 'extended'))), 0, 20);

        $lastRun = [
            'strategy_id' => self::STRATEGY_ID,
            'status' => $statusDone ? 'done' : 'running',
            'started_at' => $startedAt,
            'finished_at' => date('c'),
            'duration_ms' => (int)round((microtime(true) - $t0) * 1000),

            // Current run counters
            'current_run_processed_total' => count($batchSymbols),
            'current_run_evaluated_total' => count($newEvaluated),
            'current_run_candidates_total' => count($newCandidates),
            'current_run_near_pass_total' => count($newWatchCandidates),
            'current_run_watch_candidates_total' => count($newWatchCandidates),
            'current_run_signals_total' => count($newSignals),
            'current_run_rejects_total' => count($newRejects),
            'current_run_handoff_ready_total' => $currentRunHandoffReadyTotal,
            'current_run_bot_queue_written_total' => $currentRunBotQueueWrittenTotal,
            'batch_symbols_examples' => array_slice($batchSymbols, 0, 8),

            // Stored totals
            'stored_evaluated_total' => count($allEvaluated),
            'stored_candidates_total' => count($allCandidates),
            'stored_near_pass_total' => count($allWatchCandidates),
            'stored_watch_candidates_total' => count($allWatchCandidates),
            'stored_signals_total' => count($allSignals),
            'stored_rejects_total' => count($allRejects),
            'stored_handoff_ready_total' => $handoffReadyTotal,
            'stored_bot_queue_written_total' => $storedBotQueueWrittenTotal,
            'actual_bot_handoff_queue_records_total' => $actualBotHandoffQueueRecordsTotal,
            'handoff_ready_total' => $handoffReadyTotal,

            // Universe info
            'universe_source' => $state['universe_source'] ?? $this->universeSource,
            'universe_total' => (int)($state['universe_total'] ?? $this->universeTotal),
            'universe_examples' => $state['universe_examples'] ?? $this->universeExamples,

            // Batch/window info
            'selected_window_total' => (int)($state['selected_window_total'] ?? 0),
            'batch_size' => (int)($state['batch_size'] ?? $batchSize),
            'max_symbols_per_run' => (int)($state['max_symbols_per_run'] ?? $config['max_symbols_per_run']),
            'recovery_window_minutes' => (int)$config['recovery_window_minutes'],
            'registry_cursor' => (int)($state['registry_cursor'] ?? 0),
            'previous_registry_cursor' => (int)($state['previous_registry_cursor'] ?? 0),
            'next_registry_cursor' => (int)($state['next_registry_cursor'] ?? 0),
            'batch_offset_before' => $offsetBefore,
            'batch_offset_after' => $offsetAfter,
            'registry_window_start' => (int)($state['registry_window_start'] ?? 0),
            'registry_window_end' => (int)($state['registry_window_end'] ?? 0),
            'registry_window_wrapped' => (bool)($state['registry_window_wrapped'] ?? false),
            'registry_cursor_reset_reason' => $state['registry_cursor_reset_reason'] ?? null,

            // Handoff diagnostics
            'handoff_enabled' => $handoffEnabled,
            'emit_bot_handoff' => $emitBotHandoff,
            'effective_bot_handoff_enabled' => $effectiveBotHandoffEnabled,
            'bot_handoff_block_reason' => $botHandoffBlockReason,
            'bot_queue_candidates_considered_total' => $botQueueCandidatesConsideredTotal,
            'bot_queue_written_total' => $storedBotQueueWrittenTotal,
            'bot_queue_stale_skipped_total' => $botQueueStaleSkippedTotal,
            'bot_queue_duplicate_skipped_total' => $botQueueDuplicateSkippedTotal,
            'bot_queue_missing_required_fields_total' => $botQueueMissingRequiredFieldsTotal,
            'bot_queue_missing_required_fields_examples' => $botQueueMissingRequiredFieldsExamples,

            // Filter engine
            'filter_engine_enabled' => (bool)$config['filter_engine_enabled'],
            'enabled_filters_count' => count($enabledFilters),
            'enabled_filters' => $enabledFilters,
            'filter_enforcement_mode' => (string)$config['filter_enforcement_mode'],
            'filter_engine_available_filters_total' => count($filterCatalog),
            'filter_engine_enabled_filters_total' => count($enabledFilters),
            'filter_engine_enabled_filter_ids' => $enabledFilters,
            'filter_engine_results_by_filter' => $diag['filter_engine_results_by_filter'],
            'phase_evaluated_total' => $diag['phase_evaluated_total'],
            'phase_failed_total' => $diag['phase_failed_total'],
            'phase_dump_only_total' => $diag['phase_dump_only_total'],
            'phase_stabilizing_total' => $diag['phase_stabilizing_total'],
            'phase_early_entry_total' => $diag['phase_early_entry_total'],
            'phase_confirmed_later_total' => $diag['phase_confirmed_later_total'],
            'phase_late_spike_total' => $diag['phase_late_spike_total'],
            'phase_extended_total' => $diag['phase_extended_total'],
            'handoff_candidates_before_filters_total' => $diag['handoff_candidates_before_filters_total'],
            'handoff_blocked_by_phase_total' => $diag['handoff_blocked_by_phase_total'],
            'handoff_blocked_by_filter_total' => $diag['handoff_blocked_by_filter_total'],
            'handoff_blocked_by_filter_reason_counts' => $diag['handoff_blocked_by_filter_reason_counts'],
            'handoff_blocked_by_phase_reason_counts' => $diag['handoff_blocked_by_phase_reason_counts'],

            // Recovery / decline diagnostics
            'prior_decline_passed_total' => $diag['prior_decline_passed_total'],
            'recovery_growth_passed_total' => $diag['recovery_growth_passed_total'],
            'open_interest_growth_passed_total' => $diag['open_interest_growth_passed_total'],
            'oi_missing_allowed_total' => $diag['oi_missing_allowed_total'],
            'oi_missing_blocked_total' => $diag['oi_missing_blocked_total'],
            'current_acceleration_diagnostic_total' => $diag['current_acceleration_diagnostic_total'],
            'too_early_no_structure_total' => $diag['too_early_no_structure_total'],
            'late_spike_detected_total' => $diag['late_spike_detected_total'],
            'recovery_structure_too_weak_total' => $diag['recovery_structure_too_weak_total'],
            'recovery_phase_valid_recovery_total' => $diag['recovery_phase_valid_recovery_total'],
            'fast_spike_detected_total' => $diag['fast_spike_detected_total'],
            'raw_strategy_passed_total' => $diag['raw_strategy_passed_total'],
            'raw_strategy_rejected_total' => $diag['raw_strategy_rejected_total'],
            'dump_detected_total' => $diag['dump_detected_total'],
            'stabilization_detected_total' => $diag['stabilization_detected_total'],
            'smooth_growth_detected_total' => $diag['smooth_growth_detected_total'],
            'early_entry_candidates_total' => $diag['early_entry_candidates_total'],
            'stabilizing_candidates_total' => $diag['stabilizing_candidates_total'],
            'confirmed_later_candidates_total' => $diag['confirmed_later_candidates_total'],
            'late_spike_candidates_total' => $diag['late_spike_candidates_total'],
            'extended_candidates_total' => $diag['extended_candidates_total'],
            'early_entry_handoff_ready_total' => $diag['early_entry_handoff_ready_total'],
            'non_early_handoff_blocked_total' => $diag['non_early_handoff_blocked_total'],
            'late_spike_handoff_blocked_total' => $diag['late_spike_handoff_blocked_total'],
            'extended_handoff_blocked_total' => $diag['extended_handoff_blocked_total'],

            // Data quality
            'insufficient_data_total' => $diag['insufficient_data_total'],
            'stale_data_total' => $diag['stale_data_total'],
            'data_source_error_total' => $diag['data_source_error_total'],

            // Filter engine diagnostics
            'filter_engine_checked_total' => $diag['filter_engine_checked_total'],
            'filter_engine_blocked_total' => $diag['filter_engine_blocked_total'],
            'filter_engine_diagnostic_only_total' => $diag['filter_engine_diagnostic_only_total'],
            'coin_context_enabled' => (bool)$config['coin_context_enabled'],
            'coin_context_checked_total' => $diag['coin_context_checked_total'],
            'coin_context_available_total' => $diag['coin_context_available_total'],
            'coin_context_missing_total' => $diag['coin_context_missing_total'],
            'coin_context_error_counts' => $diag['coin_context_error_counts'],
            'coin_context_phase_counts' => $diag['coin_context_phase_counts'],
            'coin_context_trend_1h_counts' => $diag['coin_context_trend_1h_counts'],
            'coin_context_quality_counts' => $diag['coin_context_quality_counts'],
            'coin_context_examples' => $diag['coin_context_examples'],
            'wave_quality_filter_checked_total' => $diag['wave_quality_filter_checked_total'],
            'wave_quality_filter_blocked_total' => $diag['wave_quality_filter_blocked_total'],
            'wave_quality_filter_passed_total' => $diag['wave_quality_filter_passed_total'],
            'wave_quality_filter_examples' => $diag['wave_quality_filter_examples'],
            'orderbook_context_enabled' => $diag['orderbook_context_checked_total'] > 0,
            'orderbook_context_checked_total' => $diag['orderbook_context_checked_total'],
            'orderbook_context_available_total' => $diag['orderbook_context_available_total'],
            'orderbook_context_missing_total' => $diag['orderbook_context_missing_total'],
            'orderbook_context_error_counts' => $diag['orderbook_context_error_counts'],
            'orderbook_ask_wall_detected_total' => $diag['orderbook_ask_wall_detected_total'],
            'orderbook_ask_wall_high_risk_total' => $diag['orderbook_ask_wall_high_risk_total'],
            'orderbook_bid_support_strong_total' => $diag['orderbook_bid_support_strong_total'],
            'orderbook_filter_checked_total' => $diag['orderbook_filter_checked_total'],
            'orderbook_filter_blocked_total' => $diag['orderbook_filter_blocked_total'],
            'orderbook_filter_passed_total' => $diag['orderbook_filter_passed_total'],
            'orderbook_filter_missing_allowed_total' => $diag['orderbook_filter_missing_allowed_total'],
            'orderbook_filter_examples' => $diag['orderbook_filter_examples'],
            'chaotic_context_quality_downgraded_total' => $diag['chaotic_context_quality_downgraded_total'],
            'downtrend_context_quality_downgraded_total' => $diag['downtrend_context_quality_downgraded_total'],
            'spike_context_quality_downgraded_total' => $diag['spike_context_quality_downgraded_total'],

            'open_interest_missing_examples' => $diag['open_interest_missing_examples'],
            'reject_reason_counts' => $diag['reject_reason_counts'],

            // Quality guard summary
            'quality_guards_enabled' => $qualityGuardsActive,
            'quality_guard_filter_ids' => $qualityGuardsActive ? array_values(array_intersect($qualityGuardFilterIds, $enabledFilters)) : [],
            'handoff_blocked_by_quality_guard_total' => $diag['handoff_blocked_by_quality_guard_total'],
            'handoff_blocked_by_quality_guard_examples' => $diag['handoff_blocked_by_quality_guard_examples'],
            'quality_guard_existing_ready_checked_total' => $diag['quality_guard_existing_ready_checked_total'],
            'quality_guard_existing_ready_withdrawn_total' => $diag['quality_guard_existing_ready_withdrawn_total'],
            'quality_guard_existing_ready_withdrawn_examples' => $diag['quality_guard_existing_ready_withdrawn_examples'],

            // Watch recheck diagnostics
            'watch_recheck_enabled' => $watchRecheckDiag['watch_recheck_enabled'],
            'watch_recheck_candidates_loaded_total' => $watchRecheckDiag['watch_recheck_candidates_loaded_total'],
            'watch_recheck_selected_total' => $watchRecheckDiag['watch_recheck_selected_total'],
            'watch_recheck_processed_total' => $watchRecheckDiag['watch_recheck_processed_total'],
            'watch_recheck_triggered_total' => $watchRecheckDiag['watch_recheck_triggered_total'],
            'watch_recheck_still_stabilizing_total' => $watchRecheckDiag['watch_recheck_still_stabilizing_total'],
            'watch_recheck_failed_total' => $watchRecheckDiag['watch_recheck_failed_total'],
            'watch_recheck_expired_total' => $watchRecheckDiag['watch_recheck_expired_total'],
            'watch_recheck_skipped_total' => $watchRecheckDiag['watch_recheck_skipped_total'],
            'watch_recheck_skip_reasons' => $watchRecheckDiag['watch_recheck_skip_reasons'],
            'watch_recheck_examples' => $watchRecheckDiag['watch_recheck_examples'],

            'accepted_examples' => $acceptedExamples,
            'near_pass_examples' => $watchExamples,
            'watch_candidates_examples' => $watchExamples,
            'dump_examples' => $dumpExamples,
            'stabilization_examples' => $stabilizationExamples,
            'smooth_growth_examples' => $smoothGrowthExamples,
            'early_entry_examples' => $earlyEntryExamples,
            'stabilizing_examples' => $stabilizingExamples,
            'confirmed_later_examples' => $confirmedLaterExamples,
            'late_spike_examples' => $lateSpikePhaseExamples,
            'extended_examples' => $extendedExamples,
            'rejected_examples' => $rejectedExamples,
            'best_recovery_examples' => $bestRecoveryExamples,
            'prior_decline_examples' => $priorDeclineExamples,
            'open_interest_growth_examples' => $openInterestExamples,
            'current_acceleration_examples' => $accelExamples,
            'fast_spike_examples' => $fastSpikeExamples,
            'late_spike_recovery_examples' => $lateSpikeExamples,
            'too_early_examples' => $tooEarlyExamples,
            'recovery_structure_too_weak_examples' => $recoveryStructureTooWeakExamples,
        ];

        $this->writeJson($this->storagePath('last_run.json'), $lastRun);
        $this->appendNdjson($this->storagePath('cycle_history.ndjson'), $lastRun);

        return $lastRun;
    }

    /**
     * Cron compatibility wrapper.
     */
    public function tickRun(): array
    {
        return $this->tickBatch();
    }

    /**
     * @return array{candidate: array<string,mixed>, signal: array<string,mixed>|null, metrics: array<string,bool>, near_pass: bool}
     */
    private function processSymbol(string $symbol, array $config): array
    {
        $now = time();
        $detectedAt = gmdate('c', $now);
        $dumpLookbackMin = (int)$config['dump_lookback_minutes'];
        $maxDumpAgeMin = (int)$config['max_dump_age_minutes'];
        $stabilizationMaxMin = (int)$config['stabilization_max_minutes'];
        $smoothGrowthWindowMin = (int)$config['smooth_growth_window_minutes'];
        $currentAccelWindowMin = (int)$config['current_acceleration_window_minutes'];
        $lateSpikePrice10mPct = (float)$config['late_spike_price_change_10m_pct'];
        $lateSpikeRoiLev = (float)$config['late_spike_roi_equivalent_leverage'];
        $lateSpikeRoiThreshold = (float)$config['late_spike_roi_equivalent_threshold'];
        $extendedRecoveryGrowthPct = (float)$config['extended_recovery_growth_pct'];

        $totalLookbackMin = max(
            $dumpLookbackMin + $maxDumpAgeMin + $stabilizationMaxMin + $smoothGrowthWindowMin + 30,
            $currentAccelWindowMin + 30
        );
        $lookbackMinutes = max($totalLookbackMin, (int)$config['parser2_history_lookback_minutes']);

        $metrics = [
            'dump_pass' => false,
            'stabilization_pass' => false,
            'smooth_growth_pass' => false,
            'oi_pass' => false,
            'oi_missing_allowed' => false,
            'oi_missing_blocked' => false,
            'insufficient_data' => false,
            'stale_data' => false,
            'data_source_error' => false,
            'accel_computed' => false,
            'filter_checked' => false,
            'filter_blocked' => false,
            'filter_diagnostic_only' => false,
            'late_spike_detected' => false,
            'coin_context_checked' => false,
            'orderbook_context_checked' => false,
        ];

        // --- 1. Load candle and row data ---
        $allRows = $this->loadParser2Rows($symbol, $lookbackMinutes);
        $source = 'parser2_history';
        $allCandles = $this->buildMinuteCandlesFromRows($allRows);
        $error = '';

        if (count($allCandles) < 10) {
            $allCandles = $this->fetchBybitCandles($symbol, min(1000, max(200, (int)$config['bybit_kline_limit'])), $config);
            $source = 'bybit_fallback';
            if (count($allCandles) < 5) {
                $error = 'no_candles';
            }
        }

        $latestCandle = count($allCandles) > 0 ? end($allCandles) : null;
        $latestPrice = $latestCandle ? (float)($latestCandle['close'] ?? 0.0) : 0.0;
        $latestTs = $latestCandle ? (int)($latestCandle['ts'] ?? 0) : 0;

        $rejectReason = null;

        if ($error !== '') {
            $rejectReason = 'data_source_error';
            $metrics['data_source_error'] = true;
        } elseif ($latestPrice <= 0.0 || count($allCandles) < 5) {
            $rejectReason = 'insufficient_data';
            $metrics['insufficient_data'] = true;
        } elseif ($latestTs <= 0 || ($now - $latestTs) > max(60, (int)$config['max_data_staleness_seconds'])) {
            $rejectReason = 'stale_data';
            $metrics['stale_data'] = true;
        }

        // --- 2. Detect dump -> stabilization -> smooth growth phases ---
        $dumpDetected = false;
        $dumpPct = 0.0;
        $dumpStartPrice = null;
        $dumpStartTs = null;
        $dumpLowPrice = null;
        $dumpLowTs = null;
        $dumpAgeMinutes = null;

        $stabilizationDetected = false;
        $stabilizationPassed = false;
        $stabilizationStartTs = null;
        $stabilizationEndTs = null;
        $stabilizationDurationMinutes = 0;
        $stabilizationRangePct = null;
        $stabilizationPriceChangePct = null;
        $stabilizationNewLowBreakPct = null;
        $stabilizationScore = 0.0;

        $smoothGrowthDetected = false;
        $smoothGrowthPassed = false;
        $smoothGrowthStartTs = null;
        $smoothGrowthEndTs = null;
        $smoothGrowthDurationMinutes = 0;
        $smoothGrowthPct = 0.0;
        $smoothGrowthHigherCloseCount = 0;
        $smoothGrowthHigherLowCount = 0;
        $smoothGrowthSingleCandleDominancePct = null;
        $smoothGrowthScore = 0.0;

        $recoveryPhase = 'failed';
        $entryTiming = 'failed';
        $earlyEntryTriggered = false;
        $earlyEntryReason = null;
        $lateSpikeDet = false;
        $extendedDetected = false;
        $impulseSpeedPctPerMin = null;
        $recoveryStructureScore = 0.0;
        $multiStepRecovery = false;
        $higherLowCount = 0;
        $higherCloseCount = 0;
        $singleCandleDominancePct = null;
        $controlledSpeed = false;

        if ($rejectReason === null) {
            $phase = $this->computePhaseFlow($allCandles, $now, $config, $latestPrice);

            $dumpDetected = (bool)($phase['dump_detected'] ?? false);
            $dumpPct = (float)($phase['dump_pct'] ?? 0.0);
            $dumpStartPrice = $phase['dump_start_price'] ?? null;
            $dumpStartTs = $phase['dump_start_ts'] ?? null;
            $dumpLowPrice = $phase['dump_low_price'] ?? null;
            $dumpLowTs = $phase['dump_low_ts'] ?? null;
            $dumpAgeMinutes = $phase['dump_age_minutes'] ?? null;

            $stabilizationDetected = (bool)($phase['stabilization_detected'] ?? false);
            $stabilizationPassed = (bool)($phase['stabilization_passed'] ?? false);
            $stabilizationStartTs = $phase['stabilization_start_ts'] ?? null;
            $stabilizationEndTs = $phase['stabilization_end_ts'] ?? null;
            $stabilizationDurationMinutes = (int)($phase['stabilization_duration_minutes'] ?? 0);
            $stabilizationRangePct = $phase['stabilization_range_pct'] ?? null;
            $stabilizationPriceChangePct = $phase['stabilization_price_change_pct'] ?? null;
            $stabilizationNewLowBreakPct = $phase['stabilization_new_low_break_pct'] ?? null;
            $stabilizationScore = (float)($phase['stabilization_score'] ?? 0.0);

            $smoothGrowthDetected = (bool)($phase['smooth_growth_detected'] ?? false);
            $smoothGrowthPassed = (bool)($phase['smooth_growth_passed'] ?? false);
            $smoothGrowthStartTs = $phase['smooth_growth_start_ts'] ?? null;
            $smoothGrowthEndTs = $phase['smooth_growth_end_ts'] ?? null;
            $smoothGrowthDurationMinutes = (int)($phase['smooth_growth_duration_minutes'] ?? 0);
            $smoothGrowthPct = (float)($phase['smooth_growth_pct'] ?? 0.0);
            $smoothGrowthHigherCloseCount = (int)($phase['smooth_growth_higher_close_count'] ?? 0);
            $smoothGrowthHigherLowCount = (int)($phase['smooth_growth_higher_low_count'] ?? 0);
            $smoothGrowthSingleCandleDominancePct = $phase['smooth_growth_single_candle_dominance_pct'] ?? null;
            $smoothGrowthScore = (float)($phase['smooth_growth_score'] ?? 0.0);

            $recoveryStructureScore = $smoothGrowthScore;
            $higherLowCount = $smoothGrowthHigherLowCount;
            $higherCloseCount = $smoothGrowthHigherCloseCount;
            $singleCandleDominancePct = $smoothGrowthSingleCandleDominancePct;
            $multiStepRecovery = $smoothGrowthHigherLowCount > 0 && $smoothGrowthHigherCloseCount > 0;
            $impulseSpeedPctPerMin = $smoothGrowthDurationMinutes > 0
                ? round($smoothGrowthPct / max(1, $smoothGrowthDurationMinutes), 6)
                : null;
            $controlledSpeed = $impulseSpeedPctPerMin !== null
                ? $impulseSpeedPctPerMin <= max(0.01, ((float)$config['smooth_growth_max_pct'] / max(1, (int)$config['smooth_growth_min_minutes'])))
                : false;

            if ($dumpDetected) {
                $metrics['dump_pass'] = true;
            }
            if ($stabilizationPassed) {
                $metrics['stabilization_pass'] = true;
            }
            if ($smoothGrowthPassed) {
                $metrics['smooth_growth_pass'] = true;
            }

            $rejectReason = (string)($phase['reject_reason'] ?? '') ?: null;
            $entryTiming = (string)($phase['entry_timing'] ?? 'failed');
        }

        // --- 4. Open interest growth over recovery window ---
        $oiStart = null;
        $oiEnd = null;
        $oiGrowthPct = null;
        $oiScore = null;
        $oiPass = false;

        if ((bool)$config['open_interest_enabled']) {
            $oiFromTs = (int)($stabilizationStartTs ?? $dumpLowTs ?? ($now - ($dumpLookbackMin * 60)));
            $oiToTs = (int)($smoothGrowthEndTs ?? $latestTs ?? $now);
            if ($oiToTs <= $oiFromTs) {
                $oiToTs = $now;
            }
            $oiSeries = $this->buildOpenInterestSeriesFromTs($allRows, $oiFromTs, $oiToTs);

            if (count($oiSeries) >= 2) {
                $oiStart = (float)$oiSeries[0]['oi'];
                $oiEnd = (float)$oiSeries[count($oiSeries) - 1]['oi'];
                if ($oiStart > 0.0) {
                    $oiGrowthPct = (($oiEnd - $oiStart) / $oiStart) * 100.0;
                    $oiScore = $this->scoreGradientTarget(
                        $oiGrowthPct,
                        (float)$config['min_open_interest_growth_pct'],
                        (float)$config['min_open_interest_growth_score'],
                        (float)($config['open_interest_score_target_pct'] ?? 5.0)
                    );
                    $oiPass = $oiGrowthPct >= (float)$config['min_open_interest_growth_pct'];
                }
            }

            if ($oiPass) {
                $metrics['oi_pass'] = true;
            }

            if (!$oiPass) {
                if ($oiGrowthPct === null) {
                    if ((bool)$config['allow_missing_open_interest']) {
                        $metrics['oi_missing_allowed'] = true;
                    } else {
                        $rejectReason = 'open_interest_growth_too_low';
                        $metrics['oi_missing_blocked'] = true;
                    }
                } else {
                    $rejectReason = 'open_interest_growth_too_low';
                }
            }
        } else {
            $oiPass = true;
            $metrics['oi_pass'] = true;
        }

        // OI growth confirmed flag (true=passed, false=failed, null=missing data)
        $oiGrowthConfirmed = $oiPass ? true : ($oiGrowthPct === null ? null : false);
        $combinedRecoveryScore = round((($smoothGrowthScore ?: 0.0) + (($oiScore ?? $smoothGrowthScore) ?: 0.0)) / 2, 4);

        // --- 5. Current acceleration diagnostic (not a reject condition) ---
        $accelPriceChangePct = null;
        $accelOiGrowthPct = null;
        $accelScore = null;

        $accelWindowStart = $now - ($currentAccelWindowMin * 60);
        $accelCandles = array_values(array_filter(
            $allCandles,
            static fn(array $c): bool => (int)($c['ts'] ?? 0) >= $accelWindowStart
        ));

        if (count($accelCandles) >= 2) {
            $accelPriceStart = (float)($accelCandles[0]['close'] ?? 0.0);
            if ($accelPriceStart > 0.0 && $latestPrice > 0.0) {
                $accelPriceChangePct = round((($latestPrice - $accelPriceStart) / $accelPriceStart) * 100.0, 6);
                $metrics['accel_computed'] = true;
            }
        }

        $accelOiSeries = $this->buildOpenInterestSeriesFromTs($allRows, $accelWindowStart, $now);
        if (count($accelOiSeries) >= 2) {
            $aoiStart = (float)$accelOiSeries[0]['oi'];
            $aoiEnd = (float)$accelOiSeries[count($accelOiSeries) - 1]['oi'];
            if ($aoiStart > 0.0) {
                $accelOiGrowthPct = round((($aoiEnd - $aoiStart) / $aoiStart) * 100.0, 6);
            }
        }

        if ($accelPriceChangePct !== null || $accelOiGrowthPct !== null) {
            $pScore = $accelPriceChangePct !== null && $accelPriceChangePct > 0
                ? min(1.0, $accelPriceChangePct / 2.0) : 0.0;
            $oScore = $accelOiGrowthPct !== null && $accelOiGrowthPct > 0
                ? min(1.0, $accelOiGrowthPct / 2.0) : 0.0;
            $accelScore = round(($pScore + $oScore) / 2, 4);
        }

        // --- 5.1 Fast spike / late-entry diagnostics ---
        $priceChangePct10m = null;
        $roiEquivalent10m = null;
        $fastSpikeDetected = false;
        $fastSpikeReason = null;

        $fastSpikeWindowStart = $now - (10 * 60);
        $fastSpikeCandles = array_values(array_filter(
            $allCandles,
            static fn(array $c): bool => (int)($c['ts'] ?? 0) >= $fastSpikeWindowStart
        ));
        if (count($fastSpikeCandles) >= 2) {
            $fastSpikePriceStart = (float)($fastSpikeCandles[0]['close'] ?? 0.0);
            if ($fastSpikePriceStart > 0.0 && $latestPrice > 0.0) {
                $priceChangePct10m = round((($latestPrice - $fastSpikePriceStart) / $fastSpikePriceStart) * 100.0, 6);
                $roiEquivalent10m = round($priceChangePct10m * $lateSpikeRoiLev, 6);
                if ($priceChangePct10m >= $lateSpikePrice10mPct || $roiEquivalent10m >= $lateSpikeRoiThreshold) {
                    $fastSpikeDetected = true;
                    $fastSpikeReason = 'late_spike_detected';
                    $metrics['late_spike_detected'] = true;
                }
            }
        }
        if ($dumpLowPrice !== null && $dumpLowPrice > 0.0 && $latestPrice > 0.0) {
            $fullRecoveryGrowthPct = (($latestPrice - $dumpLowPrice) / $dumpLowPrice) * 100.0;
            if ($fullRecoveryGrowthPct >= $extendedRecoveryGrowthPct) {
                $extendedDetected = true;
            }
        }
        if ($fastSpikeDetected) {
            $lateSpikeDet = true;
        }

        // --- 6. Final phase and entry timing ---
        // Structural phases take priority; OI failure alone must not force 'failed' for
        // symbols that passed dump → stabilization → smooth_growth (they become confirmed_later).
        if ($lateSpikeDet && $dumpDetected) {
            $recoveryPhase = 'late_spike';
            $entryTiming = 'late_spike';
            if ($rejectReason === null) {
                $rejectReason = 'late_spike_detected';
            }
        } elseif ($extendedDetected && $dumpDetected) {
            $recoveryPhase = 'extended';
            $entryTiming = 'extended';
            if ($rejectReason === null) {
                $rejectReason = 'extended_recovery_late';
            }
        } elseif ($dumpDetected && $stabilizationPassed && $smoothGrowthPassed && ($oiPass || $metrics['oi_missing_allowed'])) {
            $recoveryPhase = 'early_entry';
            $entryTiming = 'early';
            $earlyEntryTriggered = true;
            $earlyEntryReason = 'dump_stabilization_smooth_growth';
            $rejectReason = null;
        } elseif ($dumpDetected && $stabilizationPassed && $smoothGrowthPassed) {
            // Structural phases all passed but OI failed: visual confirmed_later candidate
            $recoveryPhase = 'confirmed_later';
            $entryTiming = 'confirmed_later';
            if ($rejectReason === null) {
                $rejectReason = 'open_interest_growth_too_low';
            }
        } elseif ($dumpDetected && $stabilizationDetected) {
            $recoveryPhase = 'stabilizing';
            $entryTiming = 'stabilizing';
            if ($rejectReason === null) {
                $rejectReason = 'smooth_growth_missing';
            }
        } elseif ($dumpDetected) {
            $recoveryPhase = 'dump_only';
            $entryTiming = 'dump_only';
            if ($rejectReason === null) {
                $rejectReason = 'stabilization_missing';
            }
        } else {
            $recoveryPhase = 'failed';
            $entryTiming = 'failed';
            if ($rejectReason === null) {
                $rejectReason = 'no_prior_dump';
            }
        }

        $rawPassed = $recoveryPhase === 'early_entry';
        $nearPass = in_array($entryTiming, ['dump_only', 'stabilizing', 'confirmed_later', 'late_spike', 'extended'], true);
        $phaseBlockReason = $this->resolvePhaseBlockReason($entryTiming, $rejectReason);

        $signalId = strtolower($symbol)
            . '_' . self::SIDE
            . '_' . gmdate('Ymd_Hi', (int)floor($now / 60) * 60)
            . '_' . substr(sha1($symbol . '|' . $now), 0, 8);

        // --- 7. Build candidate record ---
        $candidate = [
            'strategy_id' => self::STRATEGY_ID,
            'signal_id' => $signalId,
            'symbol' => $symbol,
            'side' => self::SIDE,
            'detected_at' => $detectedAt,
            'entry_price' => $latestPrice,

            'recovery_window_minutes' => (int)$config['recovery_window_minutes'],
            'prior_decline_lookback_minutes' => (int)$config['prior_decline_lookback_minutes'],

            'dump_detected' => $dumpDetected,
            'dump_pct' => $dumpDetected ? round($dumpPct, 6) : null,
            'dump_start_price' => $dumpStartPrice,
            'dump_start_ts' => $dumpStartTs !== null ? gmdate('c', (int)$dumpStartTs) : null,
            'dump_low_price' => $dumpLowPrice,
            'dump_low_ts' => $dumpLowTs !== null ? gmdate('c', (int)$dumpLowTs) : null,
            'dump_age_minutes' => $dumpAgeMinutes,

            'stabilization_detected' => $stabilizationDetected,
            'stabilization_start_ts' => $stabilizationStartTs !== null ? gmdate('c', (int)$stabilizationStartTs) : null,
            'stabilization_end_ts' => $stabilizationEndTs !== null ? gmdate('c', (int)$stabilizationEndTs) : null,
            'stabilization_duration_minutes' => $stabilizationDurationMinutes,
            'stabilization_range_pct' => $stabilizationRangePct,
            'stabilization_price_change_pct' => $stabilizationPriceChangePct,
            'stabilization_new_low_break_pct' => $stabilizationNewLowBreakPct,
            'stabilization_score' => round($stabilizationScore, 4),

            'smooth_growth_detected' => $smoothGrowthDetected,
            'smooth_growth_start_ts' => $smoothGrowthStartTs !== null ? gmdate('c', (int)$smoothGrowthStartTs) : null,
            'smooth_growth_end_ts' => $smoothGrowthEndTs !== null ? gmdate('c', (int)$smoothGrowthEndTs) : null,
            'smooth_growth_duration_minutes' => $smoothGrowthDurationMinutes,
            'smooth_growth_pct' => round($smoothGrowthPct, 6),
            'smooth_growth_higher_close_count' => $smoothGrowthHigherCloseCount,
            'smooth_growth_higher_low_count' => $smoothGrowthHigherLowCount,
            'smooth_growth_single_candle_dominance_pct' => $smoothGrowthSingleCandleDominancePct,
            'smooth_growth_score' => round($smoothGrowthScore, 4),

            'prior_decline_detected' => $dumpDetected,
            'prior_decline_pct' => $dumpDetected ? round($dumpPct, 6) : null,
            'prior_decline_high_price' => $dumpStartPrice,
            'prior_decline_high_ts' => $dumpStartTs !== null ? gmdate('c', (int)$dumpStartTs) : null,
            'recovery_low_price' => $dumpLowPrice,
            'recovery_low_ts' => $dumpLowTs !== null ? gmdate('c', (int)$dumpLowTs) : null,
            'recovery_started_after_decline' => $dumpDetected,

            'recovery_growth_pct' => round($smoothGrowthPct, 6),
            'recovery_duration_minutes' => $smoothGrowthDurationMinutes,
            'recovery_min_duration_minutes' => (int)$config['recovery_min_duration_minutes'],
            'recovery_score' => round($smoothGrowthScore, 6),
            'recovery_phase' => $recoveryPhase,
            'recovery_structure_score' => round($recoveryStructureScore, 4),
            'multi_step_recovery' => $multiStepRecovery,
            'higher_low_count' => $higherLowCount,
            'higher_close_count' => $higherCloseCount,
            'single_candle_dominance_pct' => $singleCandleDominancePct,
            'controlled_speed' => $controlledSpeed,
            'late_spike_detected' => $lateSpikeDet,
            'extended_detected' => $extendedDetected,
            'entry_timing' => $entryTiming,
            'early_entry_triggered' => $earlyEntryTriggered,
            'early_entry_reason' => $earlyEntryReason,
            'recovery_passed' => $smoothGrowthPassed,

            'open_interest_start' => $oiStart,
            'open_interest_end' => $oiEnd,
            'open_interest_growth_pct' => $oiGrowthPct !== null ? round($oiGrowthPct, 6) : null,
            'open_interest_growth_score' => $oiScore !== null ? round($oiScore, 6) : null,
            'oi_growth_confirmed' => $oiGrowthConfirmed,
            'open_interest_missing_diagnostic' => $metrics['oi_missing_allowed'],

            'current_acceleration_window_minutes' => $currentAccelWindowMin,
            'current_price_change_pct_10m' => $priceChangePct10m,
            'current_oi_growth_pct_10m' => $accelOiGrowthPct,
            'current_acceleration_score' => $accelScore,
            'impulse_speed_pct_per_min' => $impulseSpeedPctPerMin,
            'roi_equivalent_10m' => $roiEquivalent10m,
            'fast_spike_detected' => $lateSpikeDet,
            'fast_spike_reason' => $fastSpikeReason,
            'fast_spike_window_minutes' => 10,
            'fast_spike_diagnostic_enabled' => true,

            'combined_recovery_score' => $combinedRecoveryScore,
            'raw_strategy_passed' => $rawPassed,
            'raw_reject_reason' => $rawPassed ? null : ($rejectReason ?? 'insufficient_data'),
            'reject_reason' => $rawPassed ? null : ($rejectReason ?? 'insufficient_data'),
            'near_pass' => $nearPass,

            'data_source_used' => $source,
            'data_latest_ts_unix' => $latestTs,
            'data_latest_ts' => $latestTs > 0 ? gmdate('c', $latestTs) : null,

            'filter_engine_enabled' => (bool)$config['filter_engine_enabled'],
            'filter_results' => [],
            'fatal_filter_hit' => false,
            'would_have_blocked_by_filters' => [],
            'phase_block_reason' => $phaseBlockReason,
            'handoff_ready' => false,
            'executable' => false,
            'diagnostic_handoff_ready' => false,
            'active_final' => false,
            'handoff_block_reason' => null,
            'coin_context' => null,
            'coin_context_available' => null,
            'coin_context_phase' => null,
            'coin_context_quality' => null,
            'coin_context_reasons' => [],
            'coin_context_trend_1h_direction' => null,
            'coin_context_trend_2h_direction' => null,
            'coin_context_price_change_1h_pct' => null,
            'coin_context_corridor_position_pct' => null,
            'coin_context_room_to_recent_high_pct' => null,
            'coin_context_distance_from_recent_low_pct' => null,
            'orderbook_context' => null,
            'orderbook_context_available' => null,
            'orderbook_context_error' => null,
            'orderbook_context_generated_at' => null,
            'orderbook_source' => null,
            'orderbook_depth_limit' => null,
            'nearest_ask_wall_price' => null,
            'nearest_ask_wall_distance_pct' => null,
            'nearest_ask_wall_notional' => null,
            'nearest_ask_wall_qty' => null,
            'ask_wall_detected' => false,
            'ask_wall_strength_score' => null,
            'ask_wall_risk' => 'none',
            'nearest_bid_wall_price' => null,
            'nearest_bid_wall_distance_pct' => null,
            'nearest_bid_wall_notional' => null,
            'nearest_bid_wall_qty' => null,
            'bid_wall_detected' => false,
            'bid_support_score' => null,
            'bid_support_quality' => 'none',
            'orderbook_imbalance_score' => null,
            'bid_ask_notional_ratio' => null,
            'top_ask_notional' => null,
            'top_bid_notional' => null,
            'price_below_nearest_ask_wall' => null,
            'price_above_nearest_ask_wall' => null,
            'breakout_wall_confirmed' => false,
            'wall_context_summary' => null,
        ];

        $shouldAttachOrderbookContext = $rawPassed
            && $entryTiming === 'early'
            && $recoveryPhase === 'early_entry';
        if ($shouldAttachOrderbookContext) {
            $metrics['orderbook_context_checked'] = true;
            $orderbookContext = $this->buildOrderbookContextForSymbol($symbol, (float)$candidate['entry_price'], $now, $config);
            $candidate = $this->attachOrderbookContextToRecord($candidate, $orderbookContext);
        }

        $shouldBuildCoinContext = (bool)$config['coin_context_enabled']
            && (
                ((bool)$config['coin_context_attach_to_candidates'] && ($rawPassed || $nearPass))
                || (bool)$config['coin_context_attach_to_signals']
            );
        if ($shouldBuildCoinContext) {
            $metrics['coin_context_checked'] = true;
            $coinContext = $this->buildCoinContextForSymbol($symbol, $config, $allRows, $now, $latestPrice);
            $candidate['coin_context'] = $coinContext;
            $candidate['coin_context_available'] = (bool)($coinContext['context_available'] ?? false);
            $candidate['coin_context_phase'] = $coinContext['context_phase'] ?? null;
            $candidate['coin_context_quality'] = $coinContext['context_quality'] ?? null;
            $candidate['coin_context_reasons'] = is_array($coinContext['context_reasons'] ?? null) ? (array)$coinContext['context_reasons'] : [];
            $candidate['coin_context_trend_1h_direction'] = $coinContext['trend_1h_direction'] ?? null;
            $candidate['coin_context_trend_2h_direction'] = $coinContext['trend_2h_direction'] ?? null;
            $candidate['coin_context_price_change_1h_pct'] = $coinContext['price_change_1h_pct'] ?? null;
            $candidate['coin_context_corridor_position_pct'] = $coinContext['corridor_position_pct'] ?? null;
            $candidate['coin_context_room_to_recent_high_pct'] = $coinContext['room_to_recent_high_pct'] ?? null;
            $candidate['coin_context_distance_from_recent_low_pct'] = $coinContext['distance_from_recent_low_pct'] ?? null;
        }

        $filterEval = [
            'filter_results' => [],
            'fatal_filter_reasons' => [],
            'hard_block_filter_reasons' => [],
            'soft_block_filter_reasons' => [],
            'warning_filter_reasons' => [],
            'would_have_blocked_by_filters' => [],
        ];
        $isEarlyEntryPhase = $rawPassed
            && $entryTiming === 'early'
            && $recoveryPhase === 'early_entry';

        if ((bool)$config['filter_engine_enabled'] && $isEarlyEntryPhase) {
            $metrics['filter_checked'] = true;
            $filterEval = $this->evaluateFilterEngine($candidate, $config);
        }

        $candidate['filter_results'] = $filterEval['filter_results'] ?? [];
        $candidate['fatal_filter_hit'] = !empty($filterEval['fatal_filter_reasons']);
        $candidate['would_have_blocked_by_filters'] = array_values(array_unique((array)($filterEval['would_have_blocked_by_filters'] ?? [])));

        $hasFatal = !empty($filterEval['fatal_filter_reasons']);
        $hasHard = !empty($filterEval['hard_block_filter_reasons']);
        $hasSoft = !empty($filterEval['soft_block_filter_reasons']);
        $fatalFilterReasons = array_values(array_filter(array_map('strval', (array)($filterEval['fatal_filter_reasons'] ?? [])), static fn(string $v): bool => $v !== ''));
        $hardFilterReasons = array_values(array_filter(array_map('strval', (array)($filterEval['hard_block_filter_reasons'] ?? [])), static fn(string $v): bool => $v !== ''));
        $blockingFilterReasons = array_values(array_unique(array_merge($fatalFilterReasons, $hardFilterReasons)));
        $blockingFilterReason = $blockingFilterReasons !== [] ? implode(',', $blockingFilterReasons) : null;
        $mode = (string)$config['filter_enforcement_mode'];

        $enforcementBlocked = false;
        if ($mode === 'strict') {
            $enforcementBlocked = $hasFatal || $hasHard || $hasSoft;
        } elseif ($mode === 'soft') {
            $enforcementBlocked = $hasFatal || $hasHard;
        } else {
            $metrics['filter_diagnostic_only'] = true;
        }

        if ($enforcementBlocked) {
            $metrics['filter_blocked'] = true;
        }

        $canBeActive = $isEarlyEntryPhase && !$enforcementBlocked;
        $canHandoff = $canBeActive
            && (bool)$config['handoff_enabled']
            && (bool)$config['emit_bot_handoff'];

        $handoffBlockReason = null;
        if (!$isEarlyEntryPhase) {
            $handoffBlockReason = 'not_early_entry_phase';
        } elseif (!$canBeActive) {
            if ($blockingFilterReason !== null) {
                $handoffBlockReason = $blockingFilterReason;
            } else {
                $handoffBlockReason = 'not_early_entry_phase';
            }
        } elseif (!(bool)$config['handoff_enabled']) {
            $handoffBlockReason = 'handoff_disabled';
        } elseif (!(bool)$config['emit_bot_handoff']) {
            $handoffBlockReason = 'emit_bot_handoff_disabled';
        }

        $candidate['active_final'] = $canBeActive;
        $candidate['handoff_ready'] = $canHandoff;
        $candidate['executable'] = $canHandoff;
        $candidate['diagnostic_handoff_ready'] = $canBeActive;
        $candidate['handoff_block_reason'] = $handoffBlockReason;

        $signal = null;
        if ($canBeActive) {
            $handoffMode = $this->resolveHandoffMode($config);
            $enabledFilters = $this->computeEnabledFilters($config);
            $strategySignalContext = [
                'dump_pct' => $candidate['dump_pct'] ?? null,
                'stabilization_duration_minutes' => $candidate['stabilization_duration_minutes'] ?? null,
                'stabilization_range_pct' => $candidate['stabilization_range_pct'] ?? null,
                'smooth_growth_pct' => $candidate['smooth_growth_pct'] ?? null,
                'smooth_growth_duration_minutes' => $candidate['smooth_growth_duration_minutes'] ?? null,
                'smooth_growth_higher_close_count' => $candidate['smooth_growth_higher_close_count'] ?? null,
                'smooth_growth_higher_low_count' => $candidate['smooth_growth_higher_low_count'] ?? null,
                'smooth_growth_single_candle_dominance_pct' => $candidate['smooth_growth_single_candle_dominance_pct'] ?? null,
                'open_interest_growth_pct' => $candidate['open_interest_growth_pct'] ?? null,
                'recovery_phase' => $candidate['recovery_phase'] ?? null,
                'entry_timing' => $candidate['entry_timing'] ?? null,
                'early_entry_triggered' => (bool)($candidate['early_entry_triggered'] ?? false),
                'handoff_block_reason' => $candidate['handoff_block_reason'] ?? null,
                'phase_block_reason' => $candidate['phase_block_reason'] ?? null,
                'coin_context' => $candidate['coin_context'] ?? null,
                'coin_context_available' => $candidate['coin_context_available'] ?? null,
                'coin_context_phase' => $candidate['coin_context_phase'] ?? null,
                'coin_context_quality' => $candidate['coin_context_quality'] ?? null,
                'coin_context_reasons' => $candidate['coin_context_reasons'] ?? [],
                'coin_context_trend_1h_direction' => $candidate['coin_context_trend_1h_direction'] ?? null,
                'coin_context_trend_2h_direction' => $candidate['coin_context_trend_2h_direction'] ?? null,
                'orderbook_context' => $candidate['orderbook_context'] ?? null,
                'orderbook_context_available' => $candidate['orderbook_context_available'] ?? null,
                'nearest_ask_wall_distance_pct' => $candidate['nearest_ask_wall_distance_pct'] ?? null,
                'nearest_ask_wall_notional' => $candidate['nearest_ask_wall_notional'] ?? null,
                'ask_wall_strength_score' => $candidate['ask_wall_strength_score'] ?? null,
                'ask_wall_risk' => $candidate['ask_wall_risk'] ?? null,
                'bid_support_score' => $candidate['bid_support_score'] ?? null,
                'bid_ask_notional_ratio' => $candidate['bid_ask_notional_ratio'] ?? null,
                'breakout_wall_confirmed' => (bool)($candidate['breakout_wall_confirmed'] ?? false),
                'filter_engine_enabled' => (bool)$config['filter_engine_enabled'],
                'filter_engine_enabled_filters_total' => count($enabledFilters),
                'filter_results' => $candidate['filter_results'] ?? [],
                'raw_strategy_passed' => true,
            ];
            $signal = array_merge($candidate, [
                'raw_strategy_passed' => true,
                'raw_reject_reason' => null,
                'handoff_ready' => $canHandoff,
                'executable' => $canHandoff,
                'diagnostic_handoff_ready' => $canBeActive,
                'active_final' => true,
                'handoff_status' => $canHandoff ? 'new' : null,
                'created_at' => $detectedAt,
                'refreshed_at' => $detectedAt,
                'mode' => $handoffMode,
                'entry_mode' => 'limit',
                'entry_type' => 'early_impulse_growth',
                'timeframe' => 'phase_based',
                'signal_source_mode' => 'direct_strategy_handoff',
                'strategy_signal_key' => self::STRATEGY_ID . '|' . strtolower($symbol) . '|' . self::SIDE,
                'strategy_signal_context' => $strategySignalContext,
            ]);
        }

        if (is_array($signal) && (bool)$config['coin_context_attach_to_signals'] && is_array($candidate['coin_context'] ?? null)) {
            $signal['coin_context'] = $candidate['coin_context'];
            $signal['coin_context_available'] = $candidate['coin_context_available'];
            $signal['coin_context_phase'] = $candidate['coin_context_phase'];
            $signal['coin_context_quality'] = $candidate['coin_context_quality'];
            $signal['coin_context_reasons'] = $candidate['coin_context_reasons'];
            $signal['coin_context_trend_1h_direction'] = $candidate['coin_context_trend_1h_direction'];
            $signal['coin_context_trend_2h_direction'] = $candidate['coin_context_trend_2h_direction'];
            $signal['coin_context_price_change_1h_pct'] = $candidate['coin_context_price_change_1h_pct'];
            $signal['coin_context_corridor_position_pct'] = $candidate['coin_context_corridor_position_pct'];
            $signal['coin_context_room_to_recent_high_pct'] = $candidate['coin_context_room_to_recent_high_pct'];
            $signal['coin_context_distance_from_recent_low_pct'] = $candidate['coin_context_distance_from_recent_low_pct'];
            $signal['strategy_signal_context']['coin_context'] = $candidate['coin_context'];
        }

        return [
            'candidate' => $candidate,
            'signal' => $signal,
            'metrics' => $metrics,
            'near_pass' => $nearPass,
        ];
    }

    private function resolvePhaseBlockReason(string $entryTiming, ?string $rejectReason): ?string
    {
        if ($entryTiming === 'early') {
            return null;
        }

        $rejectReason = trim((string)$rejectReason);
        return match ($entryTiming) {
            'dump_only' => $rejectReason === 'open_interest_growth_too_low' ? 'oi_not_ready' : 'dump_only',
            'stabilizing' => $rejectReason === 'open_interest_growth_too_low' ? 'oi_not_ready' : 'smooth_growth_not_ready',
            'confirmed_later' => 'oi_not_ready',
            'late_spike' => 'late_spike',
            'extended' => 'extended',
            default => $rejectReason !== '' ? $rejectReason : 'not_early_entry_phase',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function buildMarketSnapshot(string $symbol, int $windowMinutes, array $config): array
    {
        // Legacy compatibility shim — delegates to candle loading
        $rows = $this->loadParser2Rows($symbol, max((int)$config['parser2_history_lookback_minutes'], $windowMinutes + 15));
        $source = 'parser2_history';
        $error = '';

        $candles = $this->buildMinuteCandlesFromRows($rows);
        if (count($candles) < 2) {
            $candles = $this->fetchBybitCandles($symbol, max(20, (int)$config['bybit_kline_limit']), $config);
            $source = 'bybit_fallback';
            if (count($candles) < 2) {
                $error = 'no_candles';
            }
        }

        $now = time();
        $minTs = $now - ($windowMinutes * 60);
        $windowCandles = array_values(array_filter($candles, static fn(array $c): bool => (int)($c['ts'] ?? 0) >= $minTs));
        if (count($windowCandles) < 2 && count($candles) >= 2) {
            $windowCandles = array_slice($candles, -max(2, $windowMinutes));
        }

        $priceStart = (float)($windowCandles[0]['close'] ?? 0.0);
        $priceEnd = (float)($windowCandles[count($windowCandles) - 1]['close'] ?? 0.0);
        $latestTs = (int)($windowCandles[count($windowCandles) - 1]['ts'] ?? 0);

        return [
            'rows' => $rows,
            'candles' => $windowCandles,
            'price_start' => $priceStart,
            'price_end' => $priceEnd,
            'latest_ts' => $latestTs,
            'source' => $source,
            'error' => $error,
        ];
    }

    /**
     * @param list<array<string,mixed>> $candles
     * @return array<string,mixed>
     */
    private function computePhaseFlow(array $candles, int $now, array $config, float $latestPrice): array
    {
        $dumpLookbackMinutes = max(30, (int)($config['dump_lookback_minutes'] ?? 120));
        $minDumpPct = max(0.1, (float)($config['min_dump_pct'] ?? 2.0));
        $maxDumpAgeMinutes = max(1, (int)($config['max_dump_age_minutes'] ?? 240));
        $stabilizationMinMinutes = max(1, (int)($config['stabilization_min_minutes'] ?? 10));
        $stabilizationMaxMinutes = max($stabilizationMinMinutes, (int)($config['stabilization_max_minutes'] ?? 45));
        $stabilizationMaxRangePct = max(0.1, (float)($config['stabilization_max_range_pct'] ?? 1.5));
        $stabilizationAllowSlightGrowthPct = max(0.0, (float)($config['stabilization_allow_slight_growth_pct'] ?? 1.0));
        $stabilizationMaxNewLowBreakPct = max(0.0, (float)($config['stabilization_max_new_low_break_pct'] ?? 0.3));
        $smoothGrowthWindowMinutes = max(3, (int)($config['smooth_growth_window_minutes'] ?? 10));
        $smoothGrowthMinMinutes = max(1, (int)($config['smooth_growth_min_minutes'] ?? 5));
        $smoothGrowthMinPct = (float)($config['smooth_growth_min_pct'] ?? 0.5);
        $smoothGrowthMaxPct = max($smoothGrowthMinPct, (float)($config['smooth_growth_max_pct'] ?? 2.5));
        $smoothGrowthMinHigherCloseCount = max(1, (int)($config['smooth_growth_min_higher_close_count'] ?? 2));
        $smoothGrowthMinHigherLowCount = max(1, (int)($config['smooth_growth_min_higher_low_count'] ?? 1));
        $smoothGrowthMaxSingleCandleDominancePct = max(10.0, min(100.0, (float)($config['smooth_growth_max_single_candle_dominance_pct'] ?? 65.0)));

        $out = [
            'dump_detected' => false,
            'dump_pct' => 0.0,
            'dump_start_price' => null,
            'dump_start_ts' => null,
            'dump_low_price' => null,
            'dump_low_ts' => null,
            'dump_age_minutes' => null,
            'stabilization_detected' => false,
            'stabilization_passed' => false,
            'stabilization_start_ts' => null,
            'stabilization_end_ts' => null,
            'stabilization_duration_minutes' => 0,
            'stabilization_range_pct' => null,
            'stabilization_price_change_pct' => null,
            'stabilization_new_low_break_pct' => null,
            'stabilization_score' => 0.0,
            'smooth_growth_detected' => false,
            'smooth_growth_passed' => false,
            'smooth_growth_start_ts' => null,
            'smooth_growth_end_ts' => null,
            'smooth_growth_duration_minutes' => 0,
            'smooth_growth_pct' => 0.0,
            'smooth_growth_higher_close_count' => 0,
            'smooth_growth_higher_low_count' => 0,
            'smooth_growth_single_candle_dominance_pct' => null,
            'smooth_growth_score' => 0.0,
            'entry_timing' => 'failed',
            'reject_reason' => 'insufficient_data',
        ];

        if (count($candles) < 8) {
            return $out;
        }

        $lookbackStartTs = $now - ($dumpLookbackMinutes * 60);
        $lookbackCandles = array_values(array_filter(
            $candles,
            static fn(array $c): bool => (int)($c['ts'] ?? 0) >= $lookbackStartTs
        ));
        if (count($lookbackCandles) < 6) {
            return $out;
        }

        $bestDumpPct = 0.0;
        $bestStart = null;
        $bestLow = null;
        $n = count($lookbackCandles);
        for ($i = 0; $i < $n - 1; $i++) {
            $startClose = (float)($lookbackCandles[$i]['close'] ?? 0.0);
            if ($startClose <= 0.0) {
                continue;
            }
            $minClose = $startClose;
            $minIndex = $i;
            for ($j = $i + 1; $j < $n; $j++) {
                $c = (float)($lookbackCandles[$j]['close'] ?? 0.0);
                if ($c > 0.0 && $c < $minClose) {
                    $minClose = $c;
                    $minIndex = $j;
                }
            }
            if ($minClose <= 0.0 || $minClose >= $startClose) {
                continue;
            }
            $dumpPct = (($startClose - $minClose) / $startClose) * 100.0;
            if ($dumpPct > $bestDumpPct) {
                $bestDumpPct = $dumpPct;
                $bestStart = $lookbackCandles[$i];
                $bestLow = $lookbackCandles[$minIndex];
            }
        }

        if ($bestStart === null || $bestLow === null || $bestDumpPct < $minDumpPct) {
            $out['reject_reason'] = 'no_prior_dump';
            return $out;
        }

        $dumpLowTs = (int)($bestLow['ts'] ?? 0);
        $dumpAgeMinutes = (int)round(($now - $dumpLowTs) / 60);
        if ($dumpLowTs <= 0 || $dumpAgeMinutes > $maxDumpAgeMinutes) {
            $out['reject_reason'] = 'no_prior_dump';
            return $out;
        }

        $dumpStartPrice = (float)($bestStart['close'] ?? 0.0);
        $dumpLowPrice = (float)($bestLow['close'] ?? 0.0);
        $out['dump_detected'] = true;
        $out['dump_pct'] = round($bestDumpPct, 6);
        $out['dump_start_price'] = $dumpStartPrice > 0 ? $dumpStartPrice : null;
        $out['dump_start_ts'] = (int)($bestStart['ts'] ?? 0);
        $out['dump_low_price'] = $dumpLowPrice > 0 ? $dumpLowPrice : null;
        $out['dump_low_ts'] = $dumpLowTs;
        $out['dump_age_minutes'] = $dumpAgeMinutes;
        $out['entry_timing'] = 'dump_only';

        $stabilizationEndLimitTs = $dumpLowTs + ($stabilizationMaxMinutes * 60);
        $stabilizationCandles = array_values(array_filter(
            $candles,
            static fn(array $c): bool => (int)($c['ts'] ?? 0) >= $dumpLowTs && (int)($c['ts'] ?? 0) <= $stabilizationEndLimitTs
        ));
        if (count($stabilizationCandles) < 2) {
            $out['reject_reason'] = 'stabilization_missing';
            return $out;
        }
        $out['stabilization_detected'] = true;
        $out['stabilization_start_ts'] = (int)($stabilizationCandles[0]['ts'] ?? 0);

        $stableRequiredTs = $dumpLowTs + ($stabilizationMinMinutes * 60);
        $stabilizationCore = array_values(array_filter(
            $stabilizationCandles,
            static fn(array $c): bool => (int)($c['ts'] ?? 0) <= $stableRequiredTs
        ));
        if (count($stabilizationCore) < 2) {
            $out['reject_reason'] = 'stabilization_too_short';
            return $out;
        }
        $stStartClose = (float)($stabilizationCore[0]['close'] ?? 0.0);
        $stEndClose = (float)($stabilizationCore[count($stabilizationCore) - 1]['close'] ?? 0.0);
        $stMin = PHP_FLOAT_MAX;
        $stMax = 0.0;
        foreach ($stabilizationCore as $c) {
            $low = (float)($c['low'] ?? $c['close'] ?? 0.0);
            $high = (float)($c['high'] ?? $c['close'] ?? 0.0);
            if ($low > 0.0) {
                $stMin = min($stMin, $low);
            }
            if ($high > 0.0) {
                $stMax = max($stMax, $high);
            }
        }
        if ($stStartClose <= 0.0 || $dumpLowPrice <= 0.0 || $stMin === PHP_FLOAT_MAX || $stMax <= 0.0) {
            $out['reject_reason'] = 'stabilization_missing';
            return $out;
        }
        $stRangePct = (($stMax - $stMin) / max($dumpLowPrice, 0.0000001)) * 100.0;
        $stPriceChangePct = (($stEndClose - $stStartClose) / $stStartClose) * 100.0;
        $newLowBreakPct = max(0.0, (($dumpLowPrice - $stMin) / $dumpLowPrice) * 100.0);
        $stDurationMinutes = max(0, (int)round((((int)($stabilizationCore[count($stabilizationCore) - 1]['ts'] ?? 0)) - $dumpLowTs) / 60));

        $out['stabilization_end_ts'] = (int)($stabilizationCore[count($stabilizationCore) - 1]['ts'] ?? 0);
        $out['stabilization_duration_minutes'] = $stDurationMinutes;
        $out['stabilization_range_pct'] = round($stRangePct, 6);
        $out['stabilization_price_change_pct'] = round($stPriceChangePct, 6);
        $out['stabilization_new_low_break_pct'] = round($newLowBreakPct, 6);

        if ($stDurationMinutes < $stabilizationMinMinutes) {
            $out['reject_reason'] = 'stabilization_too_short';
            return $out;
        }
        if ($stRangePct > $stabilizationMaxRangePct) {
            $out['reject_reason'] = 'stabilization_range_too_wide';
            return $out;
        }
        if ($newLowBreakPct > $stabilizationMaxNewLowBreakPct) {
            $out['reject_reason'] = 'stabilization_new_low_broken';
            return $out;
        }
        if ($stPriceChangePct > $stabilizationAllowSlightGrowthPct) {
            $out['reject_reason'] = 'stabilization_range_too_wide';
            return $out;
        }

        $out['stabilization_passed'] = true;
        $out['stabilization_score'] = round(
            (min(1.0, max(0.0, 1.0 - ($stRangePct / max(0.000001, $stabilizationMaxRangePct))))
            + min(1.0, max(0.0, 1.0 - ($newLowBreakPct / max(0.000001, $stabilizationMaxNewLowBreakPct))))
            + min(1.0, max(0.0, 1.0 - (abs($stPriceChangePct) / max(0.000001, $stabilizationAllowSlightGrowthPct + 0.000001)))))
            / 3.0,
            4
        );
        $out['entry_timing'] = 'stabilizing';

        $smoothStartTs = (int)$out['stabilization_end_ts'];
        $smoothEndLimitTs = $smoothStartTs + ($smoothGrowthWindowMinutes * 60);
        $smoothCandles = array_values(array_filter(
            $candles,
            static fn(array $c): bool => (int)($c['ts'] ?? 0) >= $smoothStartTs && (int)($c['ts'] ?? 0) <= $smoothEndLimitTs
        ));
        if (count($smoothCandles) < 2) {
            $out['reject_reason'] = 'smooth_growth_missing';
            return $out;
        }
        $out['smooth_growth_detected'] = true;
        $out['smooth_growth_start_ts'] = (int)($smoothCandles[0]['ts'] ?? 0);
        $out['smooth_growth_end_ts'] = (int)($smoothCandles[count($smoothCandles) - 1]['ts'] ?? 0);
        $smoothDuration = max(0, (int)round(($out['smooth_growth_end_ts'] - $out['smooth_growth_start_ts']) / 60));
        $out['smooth_growth_duration_minutes'] = $smoothDuration;
        if ($smoothDuration < $smoothGrowthMinMinutes) {
            $out['reject_reason'] = 'smooth_growth_missing';
            return $out;
        }

        $smoothStartPrice = (float)($smoothCandles[0]['close'] ?? 0.0);
        $smoothEndPrice = $latestPrice > 0.0 ? $latestPrice : (float)($smoothCandles[count($smoothCandles) - 1]['close'] ?? 0.0);
        if ($smoothStartPrice <= 0.0 || $smoothEndPrice <= 0.0) {
            $out['reject_reason'] = 'smooth_growth_missing';
            return $out;
        }
        $smoothGrowthPct = (($smoothEndPrice - $smoothStartPrice) / $smoothStartPrice) * 100.0;
        $out['smooth_growth_pct'] = round($smoothGrowthPct, 6);
        if ($smoothGrowthPct < $smoothGrowthMinPct) {
            $out['reject_reason'] = 'smooth_growth_too_weak';
            return $out;
        }
        if ($smoothGrowthPct > $smoothGrowthMaxPct) {
            $out['reject_reason'] = 'smooth_growth_too_fast';
            return $out;
        }

        $higherCloseCount = 0;
        $higherLowCount = 0;
        $maxGain = 0.0;
        for ($i = 1; $i < count($smoothCandles); $i++) {
            $prevClose = (float)($smoothCandles[$i - 1]['close'] ?? 0.0);
            $currClose = (float)($smoothCandles[$i]['close'] ?? 0.0);
            $prevLow = (float)($smoothCandles[$i - 1]['low'] ?? $prevClose);
            $currLow = (float)($smoothCandles[$i]['low'] ?? $currClose);
            if ($currClose > $prevClose && $prevClose > 0.0) {
                $higherCloseCount++;
            }
            if ($currLow > $prevLow && $prevLow > 0.0) {
                $higherLowCount++;
            }
            $maxGain = max($maxGain, $currClose - $prevClose);
        }
        $out['smooth_growth_higher_close_count'] = $higherCloseCount;
        $out['smooth_growth_higher_low_count'] = $higherLowCount;
        if ($higherCloseCount < $smoothGrowthMinHigherCloseCount || $higherLowCount < $smoothGrowthMinHigherLowCount) {
            $out['reject_reason'] = 'smooth_growth_structure_too_weak';
            return $out;
        }

        $totalGain = max(0.0, $smoothEndPrice - $smoothStartPrice);
        $dominancePct = $totalGain > 0.0 ? ($maxGain / $totalGain) * 100.0 : 100.0;
        $out['smooth_growth_single_candle_dominance_pct'] = round($dominancePct, 4);
        if ($dominancePct > $smoothGrowthMaxSingleCandleDominancePct) {
            $out['reject_reason'] = 'smooth_growth_single_candle_dominance';
            return $out;
        }

        $out['smooth_growth_passed'] = true;
        $out['smooth_growth_score'] = round(
            (
                min(1.0, $smoothGrowthPct / max(0.000001, $smoothGrowthMaxPct))
                + min(1.0, $higherCloseCount / max(1, $smoothGrowthMinHigherCloseCount))
                + min(1.0, $higherLowCount / max(1, $smoothGrowthMinHigherLowCount))
                + max(0.0, 1.0 - ($dominancePct / 100.0))
            ) / 4.0,
            4
        );
        $out['entry_timing'] = 'confirmed_later';
        $out['reject_reason'] = null;

        return $out;
    }

    /**
     * Compute recovery structure/phase from candles after the recovery low.
     *
     * @param list<array<string,mixed>> $candlesAfterLow Candles from recovery low onward (ts >= low ts), sorted asc
     * @param float $recoveryGrowthPct Overall recovery growth pct (from low to latest close)
     * @param int $recoveryDurationMin Minutes since recovery low
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function computeRecoveryStructure(
        array $candlesAfterLow,
        float $recoveryGrowthPct,
        int $recoveryDurationMin,
        array $config
    ): array {
        $structureEnabled = (bool)($config['recovery_structure_enabled'] ?? true);
        $minCandlesAfterLow = max(3, (int)($config['recovery_min_candles_after_low'] ?? 20));
        $minHigherLows = max(1, (int)($config['recovery_min_higher_lows'] ?? 5));
        $minHigherCloses = max(1, (int)($config['recovery_min_higher_closes'] ?? 5));
        $maxDominancePct = max(10.0, min(100.0, (float)($config['recovery_max_single_candle_dominance_pct'] ?? 60.0)));
        $maxSpeedPctPerMin = max(0.01, (float)($config['recovery_max_speed_pct_per_min'] ?? 0.3));
        $minStructureScore = max(0.0, min(1.0, (float)($config['recovery_min_structure_score'] ?? 0.55)));

        $n = count($candlesAfterLow);

        // Overall recovery speed (full recovery window)
        $impulseSpeedPctPerMin = $recoveryDurationMin > 0
            ? round($recoveryGrowthPct / max(1, $recoveryDurationMin), 6)
            : 0.0;

        // If structure detection is disabled, report valid_recovery unconditionally
        if (!$structureEnabled) {
            return [
                'recovery_phase' => 'valid_recovery',
                'recovery_structure_score' => 1.0,
                'multi_step_recovery' => true,
                'higher_low_count' => 0,
                'higher_close_count' => 0,
                'single_candle_dominance_pct' => null,
                'impulse_speed_pct_per_min' => $impulseSpeedPctPerMin,
                'controlled_speed' => true,
                'late_spike_detected' => false,
                'reject_reason' => null,
            ];
        }

        // Too few candles: cannot confirm structure yet
        if ($n < $minCandlesAfterLow) {
            return [
                'recovery_phase' => 'too_early',
                'recovery_structure_score' => 0.0,
                'multi_step_recovery' => false,
                'higher_low_count' => 0,
                'higher_close_count' => 0,
                'single_candle_dominance_pct' => null,
                'impulse_speed_pct_per_min' => $impulseSpeedPctPerMin,
                'controlled_speed' => $impulseSpeedPctPerMin <= $maxSpeedPctPerMin,
                'late_spike_detected' => false,
                'reject_reason' => 'too_early_no_structure',
            ];
        }

        // Count higher lows and higher closes (consecutive candle comparisons)
        $higherLowCount = 0;
        $higherCloseCount = 0;
        for ($i = 1; $i < $n; $i++) {
            $prevLow = (float)($candlesAfterLow[$i - 1]['low'] ?? $candlesAfterLow[$i - 1]['close'] ?? 0.0);
            $currLow = (float)($candlesAfterLow[$i]['low'] ?? $candlesAfterLow[$i]['close'] ?? 0.0);
            $prevClose = (float)($candlesAfterLow[$i - 1]['close'] ?? 0.0);
            $currClose = (float)($candlesAfterLow[$i]['close'] ?? 0.0);
            if ($currLow > $prevLow && $prevLow > 0.0) {
                $higherLowCount++;
            }
            if ($currClose > $prevClose && $prevClose > 0.0) {
                $higherCloseCount++;
            }
        }

        // Single-candle dominance: fraction of total recovery captured by the single best candle
        $firstClose = (float)($candlesAfterLow[0]['close'] ?? 0.0);
        $lastClose = (float)($candlesAfterLow[$n - 1]['close'] ?? 0.0);
        $totalRecoveryAbs = $lastClose - $firstClose;
        $singleCandleDominancePct = null;
        if ($totalRecoveryAbs > 0.0) {
            $maxCandleGain = 0.0;
            for ($i = 1; $i < $n; $i++) {
                $prevClose = (float)($candlesAfterLow[$i - 1]['close'] ?? 0.0);
                $currClose = (float)($candlesAfterLow[$i]['close'] ?? 0.0);
                $gain = $currClose - $prevClose;
                if ($gain > $maxCandleGain) {
                    $maxCandleGain = $gain;
                }
            }
            $singleCandleDominancePct = round(($maxCandleGain / $totalRecoveryAbs) * 100.0, 2);
        }

        // Speed check
        $controlledSpeed = $impulseSpeedPctPerMin <= $maxSpeedPctPerMin;

        // Late spike: excessive single-candle dominance OR uncontrolled speed
        $lateSpike = false;
        if ($singleCandleDominancePct !== null && $singleCandleDominancePct >= $maxDominancePct) {
            $lateSpike = true;
        }
        if (!$controlledSpeed) {
            $lateSpike = true;
        }

        // Multi-step recovery: enough higher lows AND higher closes
        $multiStepRecovery = $higherLowCount >= $minHigherLows && $higherCloseCount >= $minHigherCloses;

        // Structure score (weighted components)
        $hlScore = $minHigherLows > 0 ? min(1.0, $higherLowCount / $minHigherLows) : 1.0;
        $hcScore = $minHigherCloses > 0 ? min(1.0, $higherCloseCount / $minHigherCloses) : 1.0;
        $domScore = $singleCandleDominancePct !== null
            ? max(0.0, 1.0 - $singleCandleDominancePct / 100.0)
            : 0.5;
        $speedScore = $controlledSpeed
            ? 1.0
            : max(0.0, 1.0 - (($impulseSpeedPctPerMin - $maxSpeedPctPerMin) / max(0.01, $maxSpeedPctPerMin)));
        $recoveryStructureScore = round(
            $hlScore * 0.30 + $hcScore * 0.30 + $domScore * 0.20 + $speedScore * 0.20,
            4
        );

        // Phase determination
        if ($lateSpike) {
            $phase = 'late_spike';
            $structRejectReason = 'late_spike_detected';
        } elseif ($recoveryStructureScore >= $minStructureScore && $multiStepRecovery) {
            $phase = 'valid_recovery';
            $structRejectReason = null;
        } else {
            $phase = 'failed';
            $structRejectReason = 'recovery_structure_too_weak';
        }

        return [
            'recovery_phase' => $phase,
            'recovery_structure_score' => $recoveryStructureScore,
            'multi_step_recovery' => $multiStepRecovery,
            'higher_low_count' => $higherLowCount,
            'higher_close_count' => $higherCloseCount,
            'single_candle_dominance_pct' => $singleCandleDominancePct,
            'impulse_speed_pct_per_min' => $impulseSpeedPctPerMin,
            'controlled_speed' => $controlledSpeed,
            'late_spike_detected' => $lateSpike,
            'reject_reason' => $structRejectReason,
        ];
    }

    /**
     * Build OI series between two timestamps (inclusive).
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array{ts:int,oi:float}>
     */
    private function buildOpenInterestSeriesFromTs(array $rows, int $fromTs, int $toTs): array
    {
        $series = [];
        foreach ($rows as $row) {
            $ts = (int)($row['ts'] ?? 0);
            $oi = (float)($row['open_interest_value'] ?? $row['open_interest'] ?? 0.0);
            if ($ts <= 0 || $ts < $fromTs || $ts > $toTs || $oi <= 0.0) {
                continue;
            }
            $series[] = ['ts' => $ts, 'oi' => $oi];
        }
        if (count($series) >= 2) {
            usort($series, static fn(array $a, array $b): int => $a['ts'] <=> $b['ts']);
        }
        return $series;
    }

    /**
     * Legacy OI series builder (window from now back).
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array{ts:int,oi:float}>
     */
    private function buildOpenInterestSeries(array $rows, int $windowSeconds, int $now): array
    {
        return $this->buildOpenInterestSeriesFromTs($rows, $now - $windowSeconds, $now);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadParser2Rows(string $symbol, int $lookbackMinutes): array
    {
        $normalizedSymbol = strtoupper(trim($symbol));
        if (!preg_match('/^[A-Z0-9]{2,30}$/', $normalizedSymbol)) {
            return [];
        }

        $storageRoot = $this->repoRoot . '/modules/parser/parser2_history_accumulator/storage/' . $normalizedSymbol;
        $files = [
            $storageRoot . '/' . gmdate('Y-m-d') . '.ndjson',
            $storageRoot . '/' . gmdate('Y-m-d', time() - 86400) . '.ndjson',
        ];

        $rows = [];
        $minTs = time() - ($lookbackMinutes * 60);

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $fh = @fopen($file, 'r');
            if (!is_resource($fh)) {
                continue;
            }
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $row = json_decode($line, true);
                if (!is_array($row)) {
                    continue;
                }

                $ts = 0;
                if (isset($row['ts_unix'])) {
                    $ts = (int)$row['ts_unix'];
                } elseif (isset($row['ts'])) {
                    $parsedTs = strtotime((string)$row['ts']);
                    $ts = $parsedTs === false ? 0 : (int)$parsedTs;
                }
                if ($ts <= 0 || $ts < $minTs) {
                    continue;
                }

                $price = (float)($row['last_price'] ?? ($row['data']['lastPrice'] ?? 0.0));
                if ($price <= 0.0) {
                    continue;
                }

                $oi = null;
                if (isset($row['open_interest_value'])) {
                    $oi = (float)$row['open_interest_value'];
                } elseif (isset($row['open_interest'])) {
                    $oi = (float)$row['open_interest'];
                } elseif (isset($row['data']['openInterestValue'])) {
                    $oi = (float)$row['data']['openInterestValue'];
                } elseif (isset($row['data']['openInterest'])) {
                    $oi = (float)$row['data']['openInterest'];
                }

                $rows[] = [
                    'ts' => $ts,
                    'price' => $price,
                    'open_interest_value' => $oi,
                    'open_interest' => $oi,
                ];
            }
            fclose($fh);
        }

        usort($rows, static fn(array $a, array $b): int => ((int)$a['ts']) <=> ((int)$b['ts']));
        $dedup = [];
        foreach ($rows as $row) {
            $dedup[(string)$row['ts']] = $row;
        }
        return array_values($dedup);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function buildMinuteCandlesFromRows(array $rows): array
    {
        $bars = [];
        foreach ($rows as $row) {
            $ts = (int)($row['ts'] ?? 0);
            $price = (float)($row['price'] ?? 0.0);
            if ($ts <= 0 || $price <= 0.0) {
                continue;
            }
            $bucket = (int)(floor($ts / 60) * 60);
            if (!isset($bars[$bucket])) {
                $bars[$bucket] = [
                    'ts' => $bucket,
                    'open' => $price,
                    'high' => $price,
                    'low' => $price,
                    'close' => $price,
                    'volume' => 0.0,
                ];
            } else {
                $bars[$bucket]['high'] = max((float)$bars[$bucket]['high'], $price);
                $bars[$bucket]['low'] = min((float)$bars[$bucket]['low'], $price);
                $bars[$bucket]['close'] = $price;
            }
        }
        ksort($bars);
        return array_values($bars);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchBybitCandles(string $symbol, int $limit, array $config): array
    {
        $baseUrl = rtrim((string)$config['bybit_base_url'], '/');
        $timeout = max(3, (int)$config['bybit_timeout_sec']);
        $url = $baseUrl . '/v5/market/kline?' . http_build_query([
            'category' => 'linear',
            'symbol' => $symbol,
            'interval' => '1',
            'limit' => min(max(20, $limit), 1000),
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['result']['list']) || !is_array($decoded['result']['list'])) {
            return [];
        }

        $candles = [];
        foreach (array_reverse($decoded['result']['list']) as $row) {
            if (!is_array($row) || count($row) < 6) {
                continue;
            }
            $candles[] = [
                'ts' => (int)round(((int)$row[0]) / 1000),
                'open' => (float)$row[1],
                'high' => (float)$row[2],
                'low' => (float)$row[3],
                'close' => (float)$row[4],
                'volume' => (float)$row[5],
            ];
        }

        return $candles;
    }

    /**
     * @return array<string,mixed>
     */
    private function evaluateFilterEngine(array $signalContext, array $config): array
    {
        if (!is_file($this->repoRoot . '/modules/filter_engine/filter_engine.php')) {
            return [
                'filter_results' => [],
                'fatal_filter_reasons' => [],
                'hard_block_filter_reasons' => [],
                'soft_block_filter_reasons' => [],
                'warning_filter_reasons' => [],
                'would_have_blocked_by_filters' => [],
            ];
        }

        require_once $this->repoRoot . '/modules/filter_engine/filter_result.php';
        require_once $this->repoRoot . '/modules/filter_engine/filter_engine.php';

        // Auto-discover and load all filter files
        $filtersDir = $this->repoRoot . '/modules/filter_engine/filters';
        foreach (glob($filtersDir . '/*.php') ?: [] as $filterFile) {
            if (is_string($filterFile) && is_file($filterFile)) {
                require_once $filterFile;
            }
        }

        $engineConfig = $this->buildFilterEngineConfig($config);
        $engine = new \Modules\FilterEngine\FilterEngine();

        try {
            return $engine->evaluate($signalContext, $engineConfig);
        } catch (\Throwable) {
            return [
                'filter_results' => [],
                'fatal_filter_reasons' => [],
                'hard_block_filter_reasons' => [],
                'soft_block_filter_reasons' => [],
                'warning_filter_reasons' => [],
                'would_have_blocked_by_filters' => [],
            ];
        }
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function buildFilterEngineConfig(array $config): array
    {
        return $this->normalizeFilterConfig($config)['rows'];
    }

    /**
     * @return list<string>
     */
    private function computeEnabledFilters(array $config): array
    {
        return $this->normalizeFilterConfig($config)['enabled'];
    }

    /**
     * @param array<string,mixed> $config
     * @return array{rows: array<string,array<string,mixed>>, enabled: list<string>, metadata: array<string,array<string,mixed>>}
     */
    private function normalizeFilterConfig(array $config): array
    {
        require_once $this->repoRoot . '/modules/filter_engine/filter_engine.php';
        $metadata = $this->getFilterCatalog();
        $storedRows = is_array($config['filter_config'] ?? null) ? (array)$config['filter_config'] : [];

        $enabledLegacy = [];
        foreach ((array)($config['enabled_filters'] ?? []) as $id) {
            $id = trim((string)$id);
            if ($id !== '') {
                $enabledLegacy[$id] = true;
            }
        }

        $disabledLegacy = [];
        foreach ((array)($config['disabled_filters'] ?? []) as $id) {
            $id = trim((string)$id);
            if ($id !== '') {
                $disabledLegacy[$id] = true;
            }
        }

        $rows = [];
        foreach ($metadata as $id => $meta) {
            $row = is_array($storedRows[$id] ?? null) ? (array)$storedRows[$id] : [];
            if (!array_key_exists('enabled', $row)) {
                $legacyKey = 'eig_filter_' . $id . '_enabled';
                if (array_key_exists($legacyKey, $config)) {
                    $row['enabled'] = (bool)$config[$legacyKey];
                } elseif (isset($enabledLegacy[$id])) {
                    $row['enabled'] = true;
                }
            }
            if (isset($disabledLegacy[$id])) {
                $row['enabled'] = false;
            }
            foreach ((array)($meta['configurable_fields'] ?? []) as $fieldMeta) {
                if (!is_array($fieldMeta)) {
                    continue;
                }
                $fieldKey = trim((string)($fieldMeta['key'] ?? ''));
                if ($fieldKey === '' || array_key_exists($fieldKey, $row)) {
                    continue;
                }
                $legacyFieldKey = 'eig_filter_' . $id . '_' . $fieldKey;
                if (array_key_exists($legacyFieldKey, $config)) {
                    $row[$fieldKey] = $config[$legacyFieldKey];
                }
            }
            $rows[$id] = \Modules\FilterEngine\FilterEngine::normalizeConfigRow($meta, $row);
        }

        $enabled = [];
        foreach ($rows as $id => $row) {
            if (!empty($row['enabled'])) {
                $enabled[] = $id;
            }
        }

        return [
            'rows' => $rows,
            'enabled' => $enabled,
            'metadata' => $metadata,
        ];
    }

    /**
     * @return list<string>
     */
    private function fetchUniverse(): array
    {
        $symbols = [];
        $source = 'unknown';

        try {
            $registryDir = \Core\System\SystemPaths::instance()->get('parser.parser1_market_registry');
        } catch (\Throwable) {
            $registryDir = $this->repoRoot . '/modules/parser/parser1_market_registry';
        }

        $activePath = rtrim((string)$registryDir, '/') . '/storage/active.json';
        $registryPath = rtrim((string)$registryDir, '/') . '/storage/registry.json';

        foreach ([$activePath, $registryPath] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $raw = @file_get_contents($path);
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }

            if (array_is_list($data)) {
                foreach ($data as $item) {
                    $sym = '';
                    if (is_array($item) && isset($item['symbol'])) {
                        $sym = (string)$item['symbol'];
                    } elseif (is_string($item)) {
                        $sym = $item;
                    }
                    $sym = strtoupper(trim($sym));
                    if ($sym !== '' && preg_match('/^[A-Z0-9]{2,30}USDT$/', $sym)) {
                        $symbols[] = $sym;
                    }
                }
            } else {
                foreach ($data as $key => $val) {
                    $sym = is_array($val) && isset($val['symbol']) ? (string)$val['symbol'] : (string)$key;
                    $sym = strtoupper(trim($sym));
                    if ($sym === '' || !preg_match('/^[A-Z0-9]{2,30}USDT$/', $sym)) {
                        continue;
                    }
                    $status = is_array($val) ? (string)($val['status'] ?? 'active') : 'active';
                    if (strtolower($status) === 'inactive') {
                        continue;
                    }
                    $symbols[] = $sym;
                }
            }

            if (!empty($symbols)) {
                $source = $path;
                break;
            }
        }

        $symbols = array_values(array_unique($symbols));
        sort($symbols);

        $this->universeSource = $source;
        $this->universeTotal = count($symbols);
        $this->universeExamples = array_slice($symbols, 0, 8);

        return $symbols;
    }

    private function loadConfig(): array
    {
        $base = $this->readPhpArray($this->moduleDir . '/config/base.php');
        $active = $this->readPhpArray($this->moduleDir . '/config/active.php');
        $cfg = array_merge($base, $active);

        $cfg['strategy_id'] = (string)($cfg['strategy_id'] ?? self::STRATEGY_ID);
        $cfg['enabled'] = (bool)($cfg['enabled'] ?? false);
        $cfg['handoff_enabled'] = (bool)($cfg['handoff_enabled'] ?? false);
        $cfg['emit_bot_handoff'] = (bool)($cfg['emit_bot_handoff'] ?? false);
        $cfg['max_handoff_signals_per_tick'] = max(1, min(100, (int)($cfg['max_handoff_signals_per_tick'] ?? 5)));
        $cfg['bot_ready_ttl_minutes'] = max(1, min(240, (int)($cfg['bot_ready_ttl_minutes'] ?? 10)));
        $cfg['mode'] = (string)($cfg['mode'] ?? 'passive');
        $cfg['side'] = self::SIDE;

        $cfg['batch_size'] = max(1, (int)($cfg['batch_size'] ?? 100));
        $cfg['max_symbols_per_run'] = max(1, (int)($cfg['max_symbols_per_run'] ?? 100));
        $cfg['continuous_scan_enabled'] = (bool)($cfg['continuous_scan_enabled'] ?? true);
        $cfg['auto_requeue_when_done'] = (bool)($cfg['auto_requeue_when_done'] ?? true);

        // Recovery window config
        $cfg['recovery_window_minutes'] = max(60, min(360, (int)($cfg['recovery_window_minutes'] ?? 180)));
        $cfg['recovery_min_window_minutes'] = max(30, min(240, (int)($cfg['recovery_min_window_minutes'] ?? 120)));
        $cfg['recovery_max_window_minutes'] = max((int)$cfg['recovery_min_window_minutes'], min(360, (int)($cfg['recovery_max_window_minutes'] ?? 240)));
        $cfg['recovery_min_duration_minutes'] = max(
            30,
            min(
                (int)$cfg['recovery_max_window_minutes'],
                (int)($cfg['recovery_min_duration_minutes'] ?? $cfg['recovery_min_window_minutes'] ?? 120)
            )
        );
        $cfg['recovery_duration_rule_mode'] = 'phase_based';

        // Prior decline
        $cfg['prior_decline_lookback_minutes'] = max(60, min(720, (int)($cfg['prior_decline_lookback_minutes'] ?? 240)));
        $cfg['min_prior_decline_pct'] = max(0.0, min(50.0, (float)($cfg['min_prior_decline_pct'] ?? 2.0)));

        // Recovery growth
        $cfg['min_recovery_growth_pct'] = max(0.0, (float)($cfg['min_recovery_growth_pct'] ?? 3.0));
        $cfg['min_recovery_score'] = max(0.0, min(1.0, (float)($cfg['min_recovery_score'] ?? 0.55)));
        $cfg['min_combined_recovery_score'] = max(0.0, min(1.0, (float)($cfg['min_combined_recovery_score'] ?? 0.50)));
        $cfg['recovery_score_target_pct'] = max((float)$cfg['min_recovery_growth_pct'], (float)($cfg['recovery_score_target_pct'] ?? 8.0));
        $cfg['max_recovery_growth_pct'] = max((float)$cfg['min_recovery_growth_pct'], (float)($cfg['max_recovery_growth_pct'] ?? 30.0));
        $recoveryScoreMode = (string)($cfg['recovery_score_mode'] ?? 'threshold');
        $cfg['recovery_score_mode'] = in_array($recoveryScoreMode, ['threshold', 'range'], true) ? $recoveryScoreMode : 'threshold';

        // Phase-based dump / stabilization / smooth growth
        $cfg['dump_lookback_minutes'] = max(30, min(720, (int)($cfg['dump_lookback_minutes'] ?? 120)));
        $cfg['min_dump_pct'] = max(0.1, min(80.0, (float)($cfg['min_dump_pct'] ?? 2.0)));
        $cfg['max_dump_age_minutes'] = max(1, min(1440, (int)($cfg['max_dump_age_minutes'] ?? 240)));
        $cfg['stabilization_min_minutes'] = max(1, min(240, (int)($cfg['stabilization_min_minutes'] ?? 10)));
        $cfg['stabilization_max_minutes'] = max(
            (int)$cfg['stabilization_min_minutes'],
            min(480, (int)($cfg['stabilization_max_minutes'] ?? 45))
        );
        $cfg['stabilization_max_range_pct'] = max(0.1, min(20.0, (float)($cfg['stabilization_max_range_pct'] ?? 1.5)));
        $cfg['stabilization_allow_slight_growth_pct'] = max(0.0, min(20.0, (float)($cfg['stabilization_allow_slight_growth_pct'] ?? 1.0)));
        $cfg['stabilization_max_new_low_break_pct'] = max(0.0, min(20.0, (float)($cfg['stabilization_max_new_low_break_pct'] ?? 0.3)));
        $cfg['smooth_growth_window_minutes'] = max(3, min(120, (int)($cfg['smooth_growth_window_minutes'] ?? 10)));
        $cfg['smooth_growth_min_minutes'] = max(1, min((int)$cfg['smooth_growth_window_minutes'], (int)($cfg['smooth_growth_min_minutes'] ?? 5)));
        $cfg['smooth_growth_min_pct'] = max(0.0, min(20.0, (float)($cfg['smooth_growth_min_pct'] ?? 0.5)));
        $cfg['smooth_growth_max_pct'] = max((float)$cfg['smooth_growth_min_pct'], min(50.0, (float)($cfg['smooth_growth_max_pct'] ?? 2.5)));
        $cfg['smooth_growth_min_higher_close_count'] = max(1, min(50, (int)($cfg['smooth_growth_min_higher_close_count'] ?? 2)));
        $cfg['smooth_growth_min_higher_low_count'] = max(1, min(50, (int)($cfg['smooth_growth_min_higher_low_count'] ?? 1)));
        $cfg['smooth_growth_max_single_candle_dominance_pct'] = max(10.0, min(100.0, (float)($cfg['smooth_growth_max_single_candle_dominance_pct'] ?? 65.0)));

        // Open interest
        $cfg['open_interest_enabled'] = (bool)($cfg['open_interest_enabled'] ?? true);
        $cfg['min_open_interest_growth_pct'] = max(-100.0, min(100.0, (float)($cfg['min_open_interest_growth_pct'] ?? 1.0)));
        $cfg['min_open_interest_growth_score'] = max(0.0, min(1.0, (float)($cfg['min_open_interest_growth_score'] ?? 0.55)));
        $cfg['open_interest_score_target_pct'] = max((float)$cfg['min_open_interest_growth_pct'], (float)($cfg['open_interest_score_target_pct'] ?? 5.0));
        $cfg['allow_missing_open_interest'] = (bool)($cfg['allow_missing_open_interest'] ?? true);
        $cfg['missing_open_interest_mode'] = (string)($cfg['missing_open_interest_mode'] ?? 'diagnostic_only');

        // Recovery structure / phase detection
        $cfg['recovery_structure_enabled'] = (bool)($cfg['recovery_structure_enabled'] ?? true);
        $cfg['recovery_min_candles_after_low'] = max(3, min(200, (int)($cfg['recovery_min_candles_after_low'] ?? 20)));
        $cfg['recovery_min_higher_lows'] = max(1, min(100, (int)($cfg['recovery_min_higher_lows'] ?? 5)));
        $cfg['recovery_min_higher_closes'] = max(1, min(100, (int)($cfg['recovery_min_higher_closes'] ?? 5)));
        $cfg['recovery_max_single_candle_dominance_pct'] = max(10.0, min(100.0, (float)($cfg['recovery_max_single_candle_dominance_pct'] ?? 60.0)));
        $cfg['recovery_max_speed_pct_per_min'] = max(0.01, min(10.0, (float)($cfg['recovery_max_speed_pct_per_min'] ?? 0.3)));
        $cfg['recovery_min_structure_score'] = max(0.0, min(1.0, (float)($cfg['recovery_min_structure_score'] ?? 0.55)));

        // Current acceleration (diagnostic only)
        $cfg['current_acceleration_window_minutes'] = max(1, (int)($cfg['current_acceleration_window_minutes'] ?? 10));
        $cfg['fast_spike_diagnostic_enabled'] = (bool)($cfg['fast_spike_diagnostic_enabled'] ?? true);
        $cfg['fast_spike_window_minutes'] = max(1, min(60, (int)($cfg['fast_spike_window_minutes'] ?? 10)));
        $cfg['fast_spike_price_change_pct'] = max(0.0, min(100.0, (float)($cfg['fast_spike_price_change_pct'] ?? 2.0)));
        $cfg['fast_spike_roi_equivalent_leverage'] = max(1.0, min(200.0, (float)($cfg['fast_spike_roi_equivalent_leverage'] ?? 5.0)));
        $cfg['fast_spike_roi_equivalent_threshold'] = max(0.0, min(1000.0, (float)($cfg['fast_spike_roi_equivalent_threshold'] ?? 10.0)));
        $cfg['late_spike_price_change_10m_pct'] = max(0.0, min(100.0, (float)($cfg['late_spike_price_change_10m_pct'] ?? 2.0)));
        $cfg['late_spike_roi_equivalent_leverage'] = max(1.0, min(200.0, (float)($cfg['late_spike_roi_equivalent_leverage'] ?? 5.0)));
        $cfg['late_spike_roi_equivalent_threshold'] = max(0.0, min(1000.0, (float)($cfg['late_spike_roi_equivalent_threshold'] ?? 10.0)));
        $cfg['block_late_spike_handoff'] = (bool)($cfg['block_late_spike_handoff'] ?? true);
        $cfg['extended_recovery_growth_pct'] = max(0.0, min(200.0, (float)($cfg['extended_recovery_growth_pct'] ?? 8.0)));
        $cfg['block_extended_recovery_handoff'] = (bool)($cfg['block_extended_recovery_handoff'] ?? true);

        // FilterEngine
        $cfg['filter_engine_enabled'] = (bool)($cfg['filter_engine_enabled'] ?? true);
        $mode = (string)($cfg['filter_enforcement_mode'] ?? 'diagnostic_only');
        $cfg['filter_enforcement_mode'] = in_array($mode, ['diagnostic_only', 'soft', 'strict'], true)
            ? $mode
            : 'diagnostic_only';
        $cfg['filter_profile'] = (string)($cfg['filter_profile'] ?? 'raw_no_filters');
        $cfg['filter_profile_active'] = (string)($cfg['filter_profile_active'] ?? $cfg['filter_profile']);
        $cfg['enabled_filters'] = array_values(array_filter((array)($cfg['enabled_filters'] ?? []), static fn($v): bool => trim((string)$v) !== ''));
        $cfg['disabled_filters'] = array_values(array_filter((array)($cfg['disabled_filters'] ?? []), static fn($v): bool => trim((string)$v) !== ''));
        $cfg['filter_config'] = is_array($cfg['filter_config'] ?? null) ? (array)$cfg['filter_config'] : [];
        $cfg['eig_filter_orderbook_wall_filter_enabled'] = (bool)($cfg['eig_filter_orderbook_wall_filter_enabled'] ?? true);
        $cfg['eig_filter_orderbook_wall_filter_severity'] = (string)($cfg['eig_filter_orderbook_wall_filter_severity'] ?? 'hard_block');
        $cfg['eig_filter_orderbook_wall_filter_max_ask_wall_distance_pct'] = (float)($cfg['eig_filter_orderbook_wall_filter_max_ask_wall_distance_pct'] ?? 0.8);
        $cfg['eig_filter_orderbook_wall_filter_min_ask_wall_notional'] = (float)($cfg['eig_filter_orderbook_wall_filter_min_ask_wall_notional'] ?? 20000.0);
        $cfg['eig_filter_orderbook_wall_filter_min_ask_wall_strength_score'] = (float)($cfg['eig_filter_orderbook_wall_filter_min_ask_wall_strength_score'] ?? 0.60);
        $cfg['eig_filter_orderbook_wall_filter_require_bid_support'] = (bool)($cfg['eig_filter_orderbook_wall_filter_require_bid_support'] ?? false);
        $cfg['eig_filter_orderbook_wall_filter_min_bid_support_score'] = (float)($cfg['eig_filter_orderbook_wall_filter_min_bid_support_score'] ?? 0.35);
        $cfg['eig_filter_orderbook_wall_filter_min_bid_ask_ratio'] = (float)($cfg['eig_filter_orderbook_wall_filter_min_bid_ask_ratio'] ?? 0.65);
        $cfg['eig_filter_orderbook_wall_filter_allow_missing_orderbook'] = (bool)($cfg['eig_filter_orderbook_wall_filter_allow_missing_orderbook'] ?? true);
        $cfg['eig_filter_orderbook_wall_filter_block_if_orderbook_missing'] = (bool)($cfg['eig_filter_orderbook_wall_filter_block_if_orderbook_missing'] ?? false);
        $cfg['coin_context_enabled'] = (bool)($cfg['coin_context_enabled'] ?? true);
        $cfg['coin_context_attach_to_candidates'] = (bool)($cfg['coin_context_attach_to_candidates'] ?? true);
        $cfg['coin_context_attach_to_signals'] = (bool)($cfg['coin_context_attach_to_signals'] ?? true);
        $cfg['coin_context_fail_open'] = (bool)($cfg['coin_context_fail_open'] ?? true);

        $normalizedFilters = $this->normalizeFilterConfig($cfg);
        $cfg['filter_config'] = $normalizedFilters['rows'];
        $cfg['enabled_filters'] = $normalizedFilters['enabled'];
        $cfg['disabled_filters'] = array_values(array_diff(array_keys($normalizedFilters['rows']), $normalizedFilters['enabled']));
        foreach ($normalizedFilters['rows'] as $id => $row) {
            $cfg['eig_filter_' . $id . '_enabled'] = (bool)($row['enabled'] ?? false);
            foreach ((array)($normalizedFilters['metadata'][$id]['configurable_fields'] ?? []) as $fieldMeta) {
                if (!is_array($fieldMeta)) {
                    continue;
                }
                $fieldKey = trim((string)($fieldMeta['key'] ?? ''));
                if ($fieldKey === '') {
                    continue;
                }
                $cfg['eig_filter_' . $id . '_' . $fieldKey] = $row[$fieldKey] ?? null;
            }
        }

        // Data sources
        // parser2 lookback must cover prior_decline + recovery window
        $minLookback = (int)$cfg['prior_decline_lookback_minutes'] + (int)$cfg['recovery_max_window_minutes'] + 30;
        $cfg['parser2_history_lookback_minutes'] = max($minLookback, max(30, (int)($cfg['parser2_history_lookback_minutes'] ?? 500)));
        $cfg['max_data_staleness_seconds'] = max(60, (int)($cfg['max_data_staleness_seconds'] ?? 300));
        $cfg['bybit_base_url'] = (string)($cfg['bybit_base_url'] ?? 'https://api.bybit.com');
        $cfg['bybit_timeout_sec'] = max(3, (int)($cfg['bybit_timeout_sec'] ?? 6));
        $cfg['bybit_kline_limit'] = max(20, min(1000, (int)($cfg['bybit_kline_limit'] ?? 500)));
        $cfg['bybit_oi_interval'] = (string)($cfg['bybit_oi_interval'] ?? '5min');
        $cfg['bybit_oi_limit'] = max(2, min(50, (int)($cfg['bybit_oi_limit'] ?? 2)));

        $cfg['max_evaluated_store'] = max(100, (int)($cfg['max_evaluated_store'] ?? 4000));
        $cfg['max_candidates_store'] = max(100, (int)($cfg['max_candidates_store'] ?? 2000));
        $cfg['max_near_pass_store'] = max(100, (int)($cfg['max_near_pass_store'] ?? 2000));
        $cfg['max_rejects_store'] = max(100, (int)($cfg['max_rejects_store'] ?? 2000));
        $cfg['max_signals_store'] = max(100, (int)($cfg['max_signals_store'] ?? 1000));

        return $cfg;
    }

    /**
     * @return array<string,mixed>
     */
    private function readPhpArray(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        try {
            $loaded = require $path;
            return is_array($loaded) ? $loaded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function storagePath(string $file): string
    {
        return $this->moduleDir . '/storage/' . $file;
    }

    private function readJson(string $path, mixed $default): mixed
    {
        if (!is_file($path) || !is_readable($path)) {
            return $default;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        return $decoded === null ? $default : $decoded;
    }

    private function writeJson(string $path, mixed $data): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) && @file_put_contents($path, $json, LOCK_EX) !== false;
    }

    private function appendNdjson(string $path, array $row): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $json = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return false;
        }
        return @file_put_contents($path, $json . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
    }

    private function resolveHandoffMode(array $config): string
    {
        $mode = strtolower(trim((string)($config['mode'] ?? 'demo')));
        return $mode === 'paper' ? 'paper' : 'demo';
    }

    private function parseIsoToTs(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : (int)$ts;
    }

    /**
     * @param array<string,mixed> $signal
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function buildEiglStrategySignalContext(array $signal, array $config): array
    {
        $filterResults = is_array($signal['filter_results'] ?? null) ? (array)$signal['filter_results'] : [];
        $enabledFilters = $this->computeEnabledFilters($config);
        return [
            'dump_pct' => $signal['dump_pct'] ?? null,
            'stabilization_duration_minutes' => $signal['stabilization_duration_minutes'] ?? null,
            'stabilization_range_pct' => $signal['stabilization_range_pct'] ?? null,
            'smooth_growth_pct' => $signal['smooth_growth_pct'] ?? null,
            'smooth_growth_duration_minutes' => $signal['smooth_growth_duration_minutes'] ?? null,
            'smooth_growth_higher_close_count' => $signal['smooth_growth_higher_close_count'] ?? null,
            'smooth_growth_higher_low_count' => $signal['smooth_growth_higher_low_count'] ?? null,
            'smooth_growth_single_candle_dominance_pct' => $signal['smooth_growth_single_candle_dominance_pct'] ?? null,
            'open_interest_growth_pct' => $signal['open_interest_growth_pct'] ?? null,
            'recovery_phase' => $signal['recovery_phase'] ?? null,
            'entry_timing' => $signal['entry_timing'] ?? null,
            'early_entry_triggered' => (bool)($signal['early_entry_triggered'] ?? false),
            'handoff_block_reason' => $signal['handoff_block_reason'] ?? null,
            'coin_context' => is_array($signal['coin_context'] ?? null) ? (array)$signal['coin_context'] : null,
            'coin_context_available' => $signal['coin_context_available'] ?? null,
            'coin_context_phase' => $signal['coin_context_phase'] ?? null,
            'coin_context_quality' => $signal['coin_context_quality'] ?? null,
            'coin_context_reasons' => $signal['coin_context_reasons'] ?? [],
            'coin_context_trend_1h_direction' => $signal['coin_context_trend_1h_direction'] ?? null,
            'coin_context_trend_2h_direction' => $signal['coin_context_trend_2h_direction'] ?? null,
            'orderbook_context' => is_array($signal['orderbook_context'] ?? null) ? (array)$signal['orderbook_context'] : null,
            'orderbook_context_available' => $signal['orderbook_context_available'] ?? null,
            'nearest_ask_wall_distance_pct' => $signal['nearest_ask_wall_distance_pct'] ?? null,
            'nearest_ask_wall_notional' => $signal['nearest_ask_wall_notional'] ?? null,
            'ask_wall_strength_score' => $signal['ask_wall_strength_score'] ?? null,
            'ask_wall_risk' => $signal['ask_wall_risk'] ?? null,
            'bid_support_score' => $signal['bid_support_score'] ?? null,
            'bid_ask_notional_ratio' => $signal['bid_ask_notional_ratio'] ?? null,
            'breakout_wall_confirmed' => (bool)($signal['breakout_wall_confirmed'] ?? false),
            'filter_engine_enabled' => (bool)$config['filter_engine_enabled'],
            'filter_engine_enabled_filters_total' => count($enabledFilters),
            'filter_results' => $filterResults,
            'raw_strategy_passed' => true,
        ];
    }

    /**
     * @param array<string,mixed> $signal
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function buildBotQueueRecordFromSignal(array $signal, array $config, string $mode, string $nowIso): array
    {
        $signalId = trim((string)($signal['signal_id'] ?? ''));
        $symbol = strtoupper(trim((string)($signal['symbol'] ?? '')));
        $side = strtolower(trim((string)($signal['side'] ?? self::SIDE)));

        $detectedAt = trim((string)($signal['detected_at'] ?? ''));
        $createdAt = trim((string)($signal['created_at'] ?? ''));
        if ($createdAt === '') {
            $createdAt = $detectedAt !== '' ? $detectedAt : $nowIso;
        }
        $refreshedAt = trim((string)($signal['refreshed_at'] ?? ''));
        if ($refreshedAt === '') {
            $refreshedAt = $nowIso;
        }
        $existingStatus = strtolower(trim((string)($signal['handoff_status'] ?? '')));
        $handoffStatus = in_array($existingStatus, ['new', 'refreshed'], true)
            ? $existingStatus
            : (($createdAt !== '' && $createdAt !== $refreshedAt) ? 'refreshed' : 'new');

        $strategySignalContext = is_array($signal['strategy_signal_context'] ?? null)
            ? (array)$signal['strategy_signal_context']
            : [];
        $strategySignalContext = array_merge(
            $this->buildEiglStrategySignalContext($signal, $config),
            $strategySignalContext
        );
        $strategySignalContext['raw_strategy_passed'] = true;

        $entryMode = strtolower(trim((string)($signal['entry_mode'] ?? 'limit')));
        if (!in_array($entryMode, ['limit', 'market'], true)) {
            $entryMode = 'limit';
        }

        return [
            'strategy_id' => self::STRATEGY_ID,
            'signal_id' => $signalId,
            'strategy_signal_key' => trim((string)($signal['strategy_signal_key'] ?? (self::STRATEGY_ID . '|' . strtolower($symbol) . '|' . $side))),
            'symbol' => $symbol,
            'side' => $side,
            'mode' => $mode,
            'entry_price' => (float)($signal['entry_price'] ?? 0.0),
            'entry_mode' => $entryMode,
            'entry_type' => 'early_impulse_growth',
            'timeframe' => 'phase_based',
            'signal_source_mode' => 'direct_strategy_handoff',
            'strategy_signal_context' => $strategySignalContext,
            'handoff_status' => $handoffStatus,
            'handoff_ready' => true,
            'executable' => true,
            'active_final' => true,
            'detected_at' => $detectedAt,
            'created_at' => $createdAt,
            'refreshed_at' => $refreshedAt,
        ];
    }

    /**
     * @param array<string,mixed> $record
     * @return array{valid:bool,missing_fields:list<string>}
     */
    private function validateBotQueueRecord(array $record, string $expectedMode): array
    {
        $missing = [];

        if (trim((string)($record['symbol'] ?? '')) === '') {
            $missing[] = 'symbol';
        }
        if (strtolower(trim((string)($record['side'] ?? ''))) !== self::SIDE) {
            $missing[] = 'side';
        }
        if (!is_numeric($record['entry_price'] ?? null) || (float)$record['entry_price'] <= 0.0) {
            $missing[] = 'entry_price';
        }
        if (trim((string)($record['signal_id'] ?? '')) === '') {
            $missing[] = 'signal_id';
        }
        if (!in_array((string)($record['handoff_status'] ?? ''), ['new', 'refreshed'], true)) {
            $missing[] = 'handoff_status';
        }
        if (($record['handoff_ready'] ?? null) !== true) {
            $missing[] = 'handoff_ready';
        }
        if (($record['executable'] ?? null) !== true) {
            $missing[] = 'executable';
        }
        if (($record['active_final'] ?? null) !== true) {
            $missing[] = 'active_final';
        }
        if ((string)($record['mode'] ?? '') !== $expectedMode) {
            $missing[] = 'mode';
        }

        foreach (['created_at', 'refreshed_at', 'detected_at', 'strategy_signal_key', 'strategy_id', 'entry_type', 'entry_mode', 'timeframe', 'signal_source_mode'] as $requiredField) {
            if (trim((string)($record[$requiredField] ?? '')) === '') {
                $missing[] = $requiredField;
            }
        }

        $ctx = is_array($record['strategy_signal_context'] ?? null) ? (array)$record['strategy_signal_context'] : null;
        if ($ctx === null) {
            $missing[] = 'strategy_signal_context';
        } else {
            $requiredCtxKeys = [
                'dump_pct',
                'stabilization_duration_minutes',
                'stabilization_range_pct',
                'smooth_growth_pct',
                'smooth_growth_duration_minutes',
                'smooth_growth_higher_close_count',
                'smooth_growth_higher_low_count',
                'smooth_growth_single_candle_dominance_pct',
                'open_interest_growth_pct',
                'recovery_phase',
                'entry_timing',
                'early_entry_triggered',
                'filter_engine_enabled',
                'filter_engine_enabled_filters_total',
                'filter_results',
                'raw_strategy_passed',
            ];
            foreach ($requiredCtxKeys as $ctxKey) {
                if (!array_key_exists($ctxKey, $ctx)) {
                    $missing[] = 'strategy_signal_context.' . $ctxKey;
                }
            }
        }

        return ['valid' => $missing === [], 'missing_fields' => array_values(array_unique($missing))];
    }

    /**
     * @param array<string,mixed> $signal
     * @return array<string,mixed>
     */
    private function markSignalWithdrawn(array $signal, bool $stale, string $reason, string $nowIso): array
    {
        if (trim((string)($signal['created_at'] ?? '')) === '') {
            $signal['created_at'] = trim((string)($signal['detected_at'] ?? '')) !== ''
                ? (string)$signal['detected_at']
                : $nowIso;
        }
        $signal['refreshed_at'] = $nowIso;
        $signal['handoff_status'] = 'withdrawn';
        $signal['handoff_ready'] = false;
        $signal['executable'] = false;
        $signal['active_final'] = false;
        $signal['stale'] = $stale;
        $signal['stale_reason'] = $reason;
        return $signal;
    }

    /**
     * @param list<array<string,mixed>> $historyRows
     * @return array<string,mixed>
     */
    private function buildCoinContextForSymbol(string $symbol, array $config, array $historyRows, int $now, float $latestPrice): array
    {
        $fallback = [
            'context_available' => false,
            'context_generated_at' => date('c', $now),
            'context_source' => 'coin_context_module',
            'symbol' => strtoupper(trim($symbol)),
            'context_error' => 'context_service_unavailable',
            'trend_1h_direction' => 'unknown',
            'trend_2h_direction' => 'unknown',
            'trend_4h_direction' => 'unknown',
            'price_change_1h_pct' => null,
            'price_change_2h_pct' => null,
            'price_change_4h_pct' => null,
            'corridor_window_minutes' => 240,
            'corridor_low_price' => null,
            'corridor_high_price' => null,
            'corridor_low_ts' => null,
            'corridor_high_ts' => null,
            'corridor_range_pct' => null,
            'corridor_position_pct' => null,
            'room_to_corridor_high_pct' => null,
            'distance_from_corridor_low_pct' => null,
            'recent_high_price' => null,
            'recent_high_ts' => null,
            'recent_low_price' => null,
            'recent_low_ts' => null,
            'room_to_recent_high_pct' => null,
            'distance_from_recent_low_pct' => null,
            'context_phase' => 'unknown',
            'context_quality' => 'unknown',
            'context_reasons' => ['coin_context_unavailable'],
        ];

        if (!(bool)($config['coin_context_enabled'] ?? true)) {
            $fallback['context_error'] = 'coin_context_disabled';
            $fallback['context_reasons'] = ['coin_context_disabled'];
            return $fallback;
        }

        try {
            $service = $this->getCoinContextService();
            if ($service === null || !is_callable([$service, 'buildContext'])) {
                return $fallback;
            }
            $context = $service->buildContext($symbol, [
                'history_rows' => $historyRows,
                'latest_price' => $latestPrice,
                'now' => $now,
            ]);
            if (!is_array($context)) {
                return $fallback;
            }
            return array_merge($fallback, $context);
        } catch (\Throwable) {
            $fallback['context_error'] = (bool)($config['coin_context_fail_open'] ?? true)
                ? 'context_error_fail_open'
                : 'context_error';
            $fallback['context_reasons'] = [(bool)($config['coin_context_fail_open'] ?? true) ? 'context_fail_open' : 'context_error'];
            return $fallback;
        }
    }

    private function getCoinContextService(): mixed
    {
        if ($this->coinContextService === false) {
            return null;
        }
        if ($this->coinContextService !== null) {
            return $this->coinContextService;
        }

        $coinContextModuleDir = $this->repoRoot . '/modules/context/coin_context';
        $servicePath = $coinContextModuleDir . '/service.php';
        if (!is_file($servicePath)) {
            $this->coinContextService = false;
            return null;
        }

        try {
            require_once $servicePath;
            if (!class_exists(\Modules\Context\CoinContext\CoinContextService::class)) {
                $this->coinContextService = false;
                return null;
            }
            $this->coinContextService = \Modules\Context\CoinContext\CoinContextService::instance($coinContextModuleDir);
            return $this->coinContextService;
        } catch (\Throwable) {
            $this->coinContextService = false;
            return null;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function buildOrderbookContextForSymbol(string $symbol, float $entryPrice, int $now, array $config): array
    {
        $fallback = [
            'orderbook_context_available' => false,
            'orderbook_context_generated_at' => date('c', $now),
            'orderbook_context_error' => 'service_unavailable',
            'orderbook_source' => 'orderbook_context_service',
            'orderbook_depth_limit' => null,
            'nearest_ask_wall_price' => null,
            'nearest_ask_wall_distance_pct' => null,
            'nearest_ask_wall_notional' => null,
            'nearest_ask_wall_qty' => null,
            'ask_wall_detected' => false,
            'ask_wall_strength_score' => null,
            'ask_wall_risk' => 'none',
            'nearest_bid_wall_price' => null,
            'nearest_bid_wall_distance_pct' => null,
            'nearest_bid_wall_notional' => null,
            'nearest_bid_wall_qty' => null,
            'bid_wall_detected' => false,
            'bid_support_score' => null,
            'bid_support_quality' => 'none',
            'orderbook_imbalance_score' => null,
            'bid_ask_notional_ratio' => null,
            'top_ask_notional' => null,
            'top_bid_notional' => null,
            'price_below_nearest_ask_wall' => null,
            'price_above_nearest_ask_wall' => null,
            'breakout_wall_confirmed' => false,
            'wall_context_summary' => null,
        ];

        if ($entryPrice <= 0.0) {
            $fallback['orderbook_context_error'] = 'invalid_entry_price';
            return $fallback;
        }

        $service = $this->getOrderbookContextService();
        if ($service === null || !is_callable([$service, 'getWallContext'])) {
            return $fallback;
        }

        try {
            $context = $service->getWallContext($symbol, $entryPrice);
            if (!is_array($context)) {
                return $fallback;
            }

            $askWall = is_array($context['nearest_ask_wall'] ?? null) ? (array)$context['nearest_ask_wall'] : null;
            $bidWall = is_array($context['nearest_bid_wall'] ?? null) ? (array)$context['nearest_bid_wall'] : null;
            $fetchOk = (bool)($context['fetch_ok'] ?? false);
            $askPrice = is_numeric($askWall['price'] ?? null) ? (float)$askWall['price'] : null;
            $askNotional = is_numeric($askWall['notional'] ?? null) ? (float)$askWall['notional'] : null;
            $bidNotional = is_numeric($bidWall['notional'] ?? null) ? (float)$bidWall['notional'] : null;
            $askScore = is_numeric($context['ask_wall_score'] ?? null) ? (float)$context['ask_wall_score'] : null;
            $bidScore = is_numeric($context['bid_wall_score'] ?? null) ? (float)$context['bid_wall_score'] : null;
            $errors = is_array($context['errors'] ?? null) ? (array)$context['errors'] : [];

            $result = $fallback;
            $result['orderbook_context_available'] = $fetchOk;
            $result['orderbook_context_generated_at'] = (string)($context['fetched_at'] ?? date('c', $now));
            $result['orderbook_context_error'] = $fetchOk ? null : ((string)($errors[0] ?? 'fetch_failed'));
            $result['orderbook_depth_limit'] = (int)($config['orderbook_wall_limit'] ?? 200);
            $result['nearest_ask_wall_price'] = $askPrice;
            $result['nearest_ask_wall_distance_pct'] = is_numeric($askWall['distance_pct'] ?? null) ? (float)$askWall['distance_pct'] : null;
            $result['nearest_ask_wall_notional'] = $askNotional;
            $result['nearest_ask_wall_qty'] = is_numeric($askWall['size'] ?? null) ? (float)$askWall['size'] : null;
            $result['ask_wall_detected'] = $askWall !== null;
            $result['ask_wall_strength_score'] = $askScore;
            $result['ask_wall_risk'] = $this->classifyAskWallRisk($askScore, $result['nearest_ask_wall_distance_pct'], $askNotional);
            $result['nearest_bid_wall_price'] = is_numeric($bidWall['price'] ?? null) ? (float)$bidWall['price'] : null;
            $result['nearest_bid_wall_distance_pct'] = is_numeric($bidWall['distance_pct'] ?? null) ? (float)$bidWall['distance_pct'] : null;
            $result['nearest_bid_wall_notional'] = $bidNotional;
            $result['nearest_bid_wall_qty'] = is_numeric($bidWall['size'] ?? null) ? (float)$bidWall['size'] : null;
            $result['bid_wall_detected'] = $bidWall !== null;
            $result['bid_support_score'] = $bidScore;
            $result['bid_support_quality'] = $this->classifyBidSupportQuality($bidScore);
            $result['orderbook_imbalance_score'] = is_numeric($context['wall_imbalance'] ?? null) ? (float)$context['wall_imbalance'] : null;
            $result['top_ask_notional'] = $askNotional;
            $result['top_bid_notional'] = $bidNotional;
            $result['bid_ask_notional_ratio'] = ($askNotional !== null && $askNotional > 0.0 && $bidNotional !== null)
                ? round($bidNotional / $askNotional, 6)
                : null;
            $result['price_below_nearest_ask_wall'] = $askPrice !== null ? ($entryPrice < $askPrice) : null;
            $result['price_above_nearest_ask_wall'] = $askPrice !== null ? ($entryPrice > $askPrice) : null;
            $result['breakout_wall_confirmed'] = ((string)($context['ask_wall_status'] ?? '') === 'broken')
                || ($result['price_above_nearest_ask_wall'] === true);
            $result['wall_context_summary'] = $this->buildWallContextSummary($result);
            return $result;
        } catch (\Throwable) {
            $fallback['orderbook_context_error'] = 'fetch_exception';
            return $fallback;
        }
    }

    /**
     * @param array<string,mixed> $record
     * @param array<string,mixed> $orderbookContext
     * @return array<string,mixed>
     */
    private function attachOrderbookContextToRecord(array $record, array $orderbookContext): array
    {
        $record['orderbook_context'] = $orderbookContext;
        foreach ($orderbookContext as $key => $value) {
            if ($key === 'orderbook_context') {
                continue;
            }
            $record[$key] = $value;
        }
        return $record;
    }

    private function getOrderbookContextService(): mixed
    {
        if ($this->orderbookContextService === false) {
            return null;
        }
        if ($this->orderbookContextService !== null) {
            return $this->orderbookContextService;
        }

        $moduleDir = $this->repoRoot . '/modules/system/orderbook_context';
        $servicePath = $moduleDir . '/service.php';
        if (!is_file($servicePath)) {
            $this->orderbookContextService = false;
            return null;
        }

        try {
            require_once $servicePath;
            if (!class_exists(\OrderBookContextService::class)) {
                $this->orderbookContextService = false;
                return null;
            }
            $this->orderbookContextService = new \OrderBookContextService($moduleDir);
            return $this->orderbookContextService;
        } catch (\Throwable) {
            $this->orderbookContextService = false;
            return null;
        }
    }

    private function classifyAskWallRisk(?float $askScore, ?float $askDistancePct, ?float $askNotional): string
    {
        if ($askScore === null && $askDistancePct === null && $askNotional === null) {
            return 'none';
        }
        if (
            ($askDistancePct !== null && $askDistancePct <= 0.4 && $askNotional !== null && $askNotional >= 20000.0)
            || ($askScore !== null && $askScore >= 3.0)
        ) {
            return 'high';
        }
        if (
            ($askDistancePct !== null && $askDistancePct <= 0.8 && $askNotional !== null && $askNotional >= 10000.0)
            || ($askScore !== null && $askScore >= 1.5)
        ) {
            return 'medium';
        }
        return 'low';
    }

    private function classifyBidSupportQuality(?float $bidScore): string
    {
        if ($bidScore === null || $bidScore <= 0.0) {
            return 'none';
        }
        if ($bidScore >= 2.0) {
            return 'strong';
        }
        if ($bidScore >= 1.0) {
            return 'medium';
        }
        return 'weak';
    }

    /**
     * @param array<string,mixed> $orderbookContext
     */
    private function buildWallContextSummary(array $orderbookContext): string
    {
        if (!(bool)($orderbookContext['orderbook_context_available'] ?? false)) {
            return 'orderbook_missing';
        }
        if ((bool)($orderbookContext['breakout_wall_confirmed'] ?? false)) {
            return 'breakout_confirmed_above_wall';
        }
        $askRisk = (string)($orderbookContext['ask_wall_risk'] ?? 'none');
        if ($askRisk === 'high') {
            return 'ask_wall_high_risk';
        }
        if ($askRisk === 'medium') {
            return 'ask_wall_medium_risk';
        }
        $support = (string)($orderbookContext['bid_support_quality'] ?? 'none');
        if ($support === 'strong') {
            return 'bid_support_confirmed';
        }
        return 'no_wall_risk';
    }

    private function scoreRange(float $value, float $min, float $max): float
    {
        if ($max <= $min) {
            return $value >= $min ? 1.0 : 0.0;
        }
        if ($value <= $min) {
            return 0.0;
        }
        if ($value >= $max) {
            return 1.0;
        }
        return max(0.0, min(1.0, ($value - $min) / ($max - $min)));
    }

    private function scoreThreshold(float $value, float $threshold): float
    {
        if ($threshold <= 0.0) {
            return 1.0;
        }
        return max(0.0, min(1.0, $value / $threshold));
    }

    private function scoreGradientTarget(float $value, float $min, float $scoreAtMin, float $target): float
    {
        $scoreAtMin = max(0.0, min(1.0, $scoreAtMin));

        if ($target <= $min) {
            if ($value >= $min) {
                return 1.0;
            }
            if ($min <= 0.0) {
                return 0.0;
            }
            return max(0.0, min($scoreAtMin, ($value / $min) * $scoreAtMin));
        }

        if ($value < $min) {
            if ($min <= 0.0) {
                return 0.0;
            }
            return max(0.0, min($scoreAtMin, ($value / $min) * $scoreAtMin));
        }

        if ($value >= $target) {
            return 1.0;
        }

        $progress = ($value - $min) / ($target - $min);
        return max(0.0, min(1.0, $scoreAtMin + ((1.0 - $scoreAtMin) * $progress)));
    }
}
