<?php

declare(strict_types=1);

/**
 * Confirmed Continuation Strategy — Service
 *
 * Narrow bidirectional continuation strategy.
 * Catches ONLY confirmed trend continuation after a visible structure and a
 * controlled pullback/retest.  Does NOT catch bottoms, tops, first bounces,
 * falling knives, or late blowoff moves.
 *
 * Setup classes:
 *   LONG:  higher_low_retest_continuation_long
 *   SHORT: lower_high_retest_continuation_short
 *
 * Pipeline per symbol:
 *   fetchCandles → detectStructure → gateHardRejects → scoreCandidate
 *   → obcGate → buildSignal → writeStorage
 *
 * ARCHITECTURE: Environment-neutral signal producer.
 * Do NOT write execution_mode, live_enabled, or demo/live gates.
 * Bot owns execution mode.
 *
 * Batched path:
 *   queueRun()   Write run_state.json with status=queued.
 *   tickBatch()  Process one batch_size symbols per cron tick.
 */

namespace Modules\Strategy\ConfirmedContinuation;

final class ConfirmedContinuationService
{
    private const TIMESTAMP_MS_TO_SECONDS_THRESHOLD = 20000000000;
    private const IDEA_KEY_BUCKET_SECONDS = 600;
    private static ?self $instance = null;
    private string $moduleDir;
    private string $repoRoot;

    /** @var \OrderBookContextService|null */
    private ?\OrderBookContextService $obcService = null;

    // ── Per-tick OBC counters (reset at start of tickBatch) ───────────────────
    private int $obcCheckedTotal             = 0;
    private int $obcFetchSuccessTotal        = 0;
    private int $obcFetchFailedTotal         = 0;
    private int $obcSoftDemoteTotal          = 0;
    private int $obcSoftDemoteBlockedHandoff = 0;
    private int $obcSoftDemoteAllowedHandoff = 0;
    /** @var list<array<string,mixed>> */
    private array $obcBlockExamples = [];

    // ── Per-tick candidate counters ───────────────────────────────────────────
    private int $candidatesTotal      = 0;
    private int $longCandidatesTotal  = 0;
    private int $shortCandidatesTotal = 0;
    private int $signalsTotal         = 0;
    private int $longSignalsTotal     = 0;
    private int $shortSignalsTotal    = 0;
    private int $handoffReadyTotal    = 0;
    private int $rejectedTotal        = 0;
    /** @var array<string,int> */
    private array $rejectReasonCounts = [];
    /** @var list<array<string,mixed>> */
    private array $acceptedExamples            = [];
    /** @var list<array<string,mixed>> */
    private array $rejectedExamples            = [];
    /** @var list<array<string,mixed>> */
    private array $lateEntryRejectExamples     = [];
    /** @var list<array<string,mixed>> */
    private array $firstBounceRejectExamples   = [];
    /** @var list<array<string,mixed>> */
    private array $noRetestRejectExamples      = [];
    /** @var list<array<string,mixed>> */
    private array $antiCombExamples            = [];
    /** @var list<array<string,mixed>> */
    private array $wallTestExamples            = [];

    // ── Pattern / quality diagnostics ──────────────────────────────────────────
    private int $higherLowCandidatesTotal   = 0;
    private int $lowerHighCandidatesTotal   = 0;
    private int $retestHeldTotal            = 0;
    private int $continuationConfirmedTotal = 0;
    private int $firstBounceRejectedTotal   = 0;
    private int $lateEntryRejectedTotal     = 0;
    private int $pattern123CheckedTotal     = 0;
    private int $pattern123DetectedTotal    = 0;
    private int $pattern123RejectedTotal    = 0;
    private int $pattern123MissingTotal     = 0;
    private int $point3BreaksPoint1Total    = 0;
    private int $point3NotConfirmedTotal    = 0;
    private int $entryTooFarFromPoint3Total = 0;
    /** @var list<array<string,mixed>> */
    private array $acceptedPattern123Examples = [];
    /** @var list<array<string,mixed>> */
    private array $rejectedPattern123Examples = [];

    // ── Anti-comb diagnostics ──────────────────────────────────────────────────
    private int $antiCombCheckedTotal                  = 0;
    private int $antiCombRejectedTotal                 = 0;
    private int $antiCombRecentRangeRejectTotal        = 0;
    private int $antiCombRecentSwingRejectTotal        = 0;
    private int $antiCombOppositeSwingRejectTotal      = 0;
    private int $antiCombWickChaosRejectTotal          = 0;
    private int $antiCombLowConsistencyRejectTotal     = 0;
    private int $antiCombStructureBreaksRejectTotal    = 0;
    private int $antiCombAlternatingCandlesRejectTotal = 0;

    // ── Day regime diagnostics ─────────────────────────────────────────────────
    private int $dayRegimeCheckedTotal   = 0;
    private int $dayRegimeBlockedTotal   = 0;
    private int $dayRegimeLongBiasTotal  = 0;
    private int $dayRegimeShortBiasTotal = 0;

    // ── Universe diagnostics (set by fetchUniverse) ────────────────────────────
    private string $universeSource      = 'unknown';
    private int    $universeTotal       = 0;
    private int    $universeBatchCount  = 0;
    /** @var list<string> */
    private array  $universeExamples    = [];

    // ── Wall test diagnostics ──────────────────────────────────────────────────
    private int $wallTestPendingTotal        = 0;
    private int $wallTestConfirmedTotal      = 0;
    private int $wallTestRejectedTotal       = 0;
    private int $wallTestOppositeContextTotal= 0;
    private int $wallPendingBlockedTotal     = 0;
    /** @var list<array<string,mixed>> */
    private array $wallPendingExamples       = [];

    // ── Post-structure / entry timing / smooth trend diagnostics ───────────────
    private int $postStructureFilterCheckedTotal   = 0;
    private int $postStructureFilterRejectedTotal  = 0;
    private int $entryTimingCheckedTotal           = 0;
    private int $nearExitZoneRejectedTotal         = 0;
    private int $controlledTrendGateCheckedTotal   = 0;
    private int $controlledTrendGateRejectedTotal  = 0;
    private int $controlledTrendScoreTooLowTotal   = 0;
    private int $directionalConsistencyTooLowTotal = 0;
    private int $wickChaosTooHighTotal             = 0;
    private int $recentSwingTooHighTotal           = 0;
    private int $oppositeSwingTooHighTotal         = 0;
    private int $structureBreaksNotAllowedTotal    = 0;
    private int $smoothTrendCheckedTotal           = 0;
    private int $smoothTrendRejectedTotal          = 0;
    private int $smoothTrendScoreTooLowTotal       = 0;
    private int $verticalSpikeRejectedTotal        = 0;

    // ── Funnel / split-reason diagnostics (new) ────────────────────────────────
    private int $structuresDetectedTotal                   = 0;
    private int $pattern123StructuresSeenTotal             = 0;
    private int $pattern123ValidBeforeFiltersTotal         = 0;
    private int $pattern123InvalidBeforeFiltersTotal       = 0;
    private int $pattern123ValidButAntiCombRejectedTotal   = 0;
    private int $pattern123ValidButEntryTimingRejectedTotal= 0;
    private int $pattern123ValidButWallBlockedTotal        = 0;
    private int $pattern123ValidSignalReadyTotal           = 0;
    private int $postStructureCombDetectedTotal            = 0;
    private int $antiCombPassedTotal                       = 0;
    private int $entryTimingPassedTotal                    = 0;
    private int $qualityGatePassedTotal                    = 0;
    private int $wallTestPassedTotal                       = 0;
    private int $signalReadyTotal                          = 0;
    /** @var list<array<string,mixed>> */
    private array $acceptedEarlyStructureExamples  = [];
    /** @var list<array<string,mixed>> */
    private array $acceptedMidTrendExamples        = [];
    /** @var list<array<string,mixed>> */
    private array $rejectedLateEntryExamples       = [];
    /** @var list<array<string,mixed>> */
    private array $rejectedCombExamples            = [];
    /** @var list<array<string,mixed>> */
    private array $pattern123ValidButAntiCombRejectedExamples = [];
    /** @var array<string,int> */
    private array $candleSourceUsedCounts = [
        'parser2_history' => 0,
        'bybit_fallback' => 0,
        'none' => 0,
    ];
    private int $parser2HistoryUsedTotal = 0;
    private int $bybitFallbackUsedTotal = 0;
    private int $backward123CheckedTotal = 0;
    private int $backward123ValidTotal = 0;
    private int $backward123InvalidTotal = 0;
    private int $recentPoint3CandidatesTotal = 0;
    private int $point3NearEntryTotal = 0;
    private int $point3TurnConfirmedTotal = 0;
    private int $point2FoundTotal = 0;
    private int $point1FoundTotal = 0;
    private int $structureBreaksAfterPoint3Total = 0;
    private int $structureBreaksBeforePoint3IgnoredTotal = 0;
    /** @var list<array<string,mixed>> */
    private array $backward123ValidExamples = [];
    /** @var list<array<string,mixed>> */
    private array $backward123InvalidExamples = [];
    /** @var list<array<string,mixed>> */
    private array $point3CandidateExamples = [];
    /** @var list<array<string,mixed>> */
    private array $parser2HistoryErrorExamples = [];
    /** @var array<string,mixed> */
    private array $lastCandleFetchDiag = [
        'candle_source_used' => 'none',
        'parser2_history_files_read' => 0,
        'parser2_history_candles_loaded' => 0,
        'parser2_history_candles_after_dedupe' => 0,
        'parser2_history_oldest_ts' => null,
        'parser2_history_newest_ts' => null,
        'bybit_fallback_used' => false,
        'candle_source_error' => null,
    ];

    // ── Handoff throttling diagnostics ──────────────────────────────────────────
    private int $handoffCandidatesBeforeThrottleTotal = 0;
    private int $handoffAfterThrottleTotal            = 0;
    private int $handoffThrottledTotal                = 0;
    /** @var array<string,int> */
    private array $handoffThrottleReasonCounts        = [];
    /** @var list<array<string,mixed>> */
    private array $handoffThrottleExamples            = [];

    // ── Handoff queue diagnostics ──────────────────────────────────────────────
    private int $handoffQueueWrittenTotal       = 0;
    private int $handoffQueueNewTotal           = 0;
    private int $handoffQueueRefreshedTotal     = 0;
    private int $handoffQueueMissingStatusTotal = 0;
    /** @var list<array<string,mixed>> */
    private array $handoffQueueExamples         = [];

    // ── Deprecated config diagnostics ──────────────────────────────────────────
    private bool $deprecatedExecutionModeKeySeen    = false;
    private bool $deprecatedExecutionModeKeyIgnored = false;
    private string $effectiveStrategyMode           = 'passive';

    public function __construct(?string $moduleDir = null)
    {
        if ($moduleDir !== null) {
            $this->moduleDir = rtrim($moduleDir, '/');
        } else {
            $this->moduleDir = rtrim(
                \Core\System\SystemPaths::instance()->get('strategy.confirmed_continuation'),
                '/'
            );
        }
        // Repo root is 4 levels above modules/strategy/pattern/confirmed_continuation/
        $this->repoRoot = rtrim(dirname($this->moduleDir, 4), '/');

        // ── OrderBook Context service (optional; fails gracefully) ─────────────
        $obcDir = $this->repoRoot . '/modules/system/orderbook_context';
        if (is_file($obcDir . '/service.php')) {
            try {
                require_once $obcDir . '/service.php';
                $this->obcService = new \OrderBookContextService($obcDir);
            } catch (\Throwable) {
                $this->obcService = null;
            }
        }
    }

    public static function instance(?string $moduleDir = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($moduleDir);
        }
        return self::$instance;
    }

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Queue a full universe scan.  Called by admin UI or cron.
     */
    public function queueRun(): array
    {
        $this->requireBootstrap();
        $boot = ConfirmedContinuationBootstrap::instance($this->moduleDir)->load();
        if (!$boot['valid']) {
            return ['queued' => false, 'errors' => $boot['errors']];
        }
        $config = $boot['config'];

        $allSymbols = $this->fetchUniverse($config);
        $total      = count($allSymbols);
        $batchSize  = max(1, (int)($config['batch_size'] ?? 50));
        $maxTotal   = max(1, (int)($config['max_symbols_per_run'] ?? 50));
        $continuousScanEnabled = (bool)($config['continuous_scan_enabled'] ?? true);
        $autoRequeueWhenDone   = (bool)($config['auto_requeue_when_done'] ?? true);
        $windowSize = $total > 0 ? min($maxTotal, $total) : 0;

        $prevState   = $this->readJson($this->moduleDir . '/storage/run_state.json', []);
        $rawPrevCursor  = (int)($prevState['next_registry_cursor'] ?? 0);
        $prevCursor  = $rawPrevCursor;
        $cursorResetReason = null;
        if ($total > 0 && ($prevCursor < 0 || $prevCursor >= $total)) {
            $prevCursor = 0;
            $cursorResetReason = 'cursor_out_of_range';
        }
        if ($total === 0) {
            $prevCursor = 0;
        }

        $selectedSymbols = [];
        if ($total > 0) {
            for ($i = 0; $i < $windowSize; $i++) {
                $idx = ($prevCursor + $i) % $total;
                $selectedSymbols[] = $allSymbols[$idx];
            }
        }
        $selectedTotal = count($selectedSymbols);
        $windowStart   = $selectedTotal > 0 ? $prevCursor : 0;
        $windowEnd     = $selectedTotal > 0 ? (($windowStart + $selectedTotal - 1) % $total) : 0;
        $wrapped       = $selectedTotal > 0 && ($windowStart + $maxTotal > $total);
        $nextCursor    = ($total > 0 && $selectedTotal > 0) ? (($windowStart + $selectedTotal) % $total) : 0;

        $storageDir = $this->moduleDir . '/storage';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }
        $state = [
            'status'                    => 'queued',
            'queued_at'                 => date('c'),
            'batch_offset'              => 0,
            'symbols'                   => $selectedSymbols,
            'selected_window_total'     => $selectedTotal,
            'universe_total'            => $total,
            'max_symbols_per_run'       => $maxTotal,
            'batch_size'                => $batchSize,
            'continuous_scan_enabled'   => $continuousScanEnabled,
            'auto_requeue_when_done'    => $autoRequeueWhenDone,
            'registry_cursor'           => $windowStart,
            'previous_registry_cursor'  => $rawPrevCursor,
            'next_registry_cursor'      => $nextCursor,
            'registry_window_start'     => $windowStart,
            'registry_window_end'       => $windowEnd,
            'registry_window_wrapped'   => $wrapped,
            'registry_cursor_reset_reason' => $cursorResetReason,
            'auto_requeued_from_status' => null,
            'auto_requeue_at'           => null,
            'auto_requeue_result'       => null,
            'previous_next_registry_cursor_before_requeue' => null,
            'registry_cursor_after_requeue' => null,
            'next_registry_cursor_after_requeue' => null,
            'auto_requeue_skipped_reason' => null,
        ];
        $this->writeJson($this->moduleDir . '/storage/run_state.json', $state);
        return [
            'queued'                => true,
            'queued_at'             => $state['queued_at'],
            'selected_window_total' => $selectedTotal,
            'registry_cursor'       => $windowStart,
            'next_registry_cursor'  => $nextCursor,
        ];
    }

    /**
     * Process one batch of symbols.  Called by cron.
     */
    public function tickBatch(): array
    {
        $this->resetCounters();
        $this->effectiveStrategyMode = 'passive';

        $deprecatedModeDiag = $this->normalizeDeprecatedExecutionModeKeyInActiveConfig();
        $this->deprecatedExecutionModeKeySeen    = (bool)($deprecatedModeDiag['seen'] ?? false);
        $this->deprecatedExecutionModeKeyIgnored = (bool)($deprecatedModeDiag['ignored'] ?? false);

        $this->requireBootstrap();
        $boot = ConfirmedContinuationBootstrap::instance($this->moduleDir)->load();
        if (!$boot['valid'] || empty($boot['config']['enabled'])) {
            return ['status' => 'disabled', 'errors' => $boot['errors']];
        }
        $config = $boot['config'];
        $continuousScanEnabled = (bool)($config['continuous_scan_enabled'] ?? true);
        $autoRequeueWhenDone   = (bool)($config['auto_requeue_when_done'] ?? true);

        $state  = $this->readJson($this->moduleDir . '/storage/run_state.json', []);
        $status = (string)($state['status'] ?? '');
        $statusForAuto = $status === '' ? 'idle' : $status;
        $autoRequeueDiag = [
            'continuous_scan_enabled'   => $continuousScanEnabled,
            'auto_requeue_when_done'    => $autoRequeueWhenDone,
            'auto_requeued_from_status' => null,
            'auto_requeue_at'           => null,
            'auto_requeue_result'       => null,
            'previous_next_registry_cursor_before_requeue' => null,
            'registry_cursor_after_requeue' => null,
            'next_registry_cursor_after_requeue' => null,
            'auto_requeue_skipped_reason' => null,
        ];
        $shouldAutoQueue = false;
        if ($statusForAuto === 'idle') {
            if ($continuousScanEnabled) {
                $shouldAutoQueue = true;
            } else {
                $autoRequeueDiag['auto_requeue_result'] = 'skipped';
                $autoRequeueDiag['auto_requeue_skipped_reason'] = 'continuous_scan_disabled';
            }
        } elseif ($statusForAuto === 'done') {
            if (!$continuousScanEnabled) {
                $autoRequeueDiag['auto_requeue_result'] = 'skipped';
                $autoRequeueDiag['auto_requeue_skipped_reason'] = 'continuous_scan_disabled';
            } elseif (!$autoRequeueWhenDone) {
                $autoRequeueDiag['auto_requeue_result'] = 'skipped';
                $autoRequeueDiag['auto_requeue_skipped_reason'] = 'disabled';
            } else {
                $shouldAutoQueue = true;
            }
        } else {
            $autoRequeueDiag['auto_requeue_result'] = 'skipped';
            $autoRequeueDiag['auto_requeue_skipped_reason'] = 'status_not_done_or_idle';
        }

        if ($shouldAutoQueue) {
            $autoRequeueDiag['auto_requeued_from_status'] = $statusForAuto;
            $autoRequeueDiag['auto_requeue_at'] = date('c');
            $autoRequeueDiag['previous_next_registry_cursor_before_requeue'] = (int)($state['next_registry_cursor'] ?? 0);
            $queueResult = $this->queueRun();
            if (!($queueResult['queued'] ?? false)) {
                $autoRequeueDiag['auto_requeue_result'] = 'queue_failed';
                $autoRequeueDiag['auto_requeue_skipped_reason'] = 'queue_failed';
                $state = array_merge($state, $autoRequeueDiag);
                $state['continuous_scan_enabled'] = $continuousScanEnabled;
                $state['auto_requeue_when_done'] = $autoRequeueWhenDone;
                $this->writeJson($this->moduleDir . '/storage/run_state.json', $state);
                return ['status' => 'queue_failed', 'errors' => $queueResult['errors'] ?? []];
            }
            $state  = $this->readJson($this->moduleDir . '/storage/run_state.json', []);
            $autoRequeueDiag['auto_requeue_result'] = 'queued';
            $autoRequeueDiag['registry_cursor_after_requeue'] = (int)($state['registry_cursor'] ?? 0);
            $autoRequeueDiag['next_registry_cursor_after_requeue'] = (int)($state['next_registry_cursor'] ?? 0);
            $state = array_merge($state, $autoRequeueDiag);
            $this->writeJson($this->moduleDir . '/storage/run_state.json', $state);
            $status = (string)($state['status'] ?? '');
        } else {
            $state = array_merge($state, $autoRequeueDiag);
            $state['continuous_scan_enabled'] = $continuousScanEnabled;
            $state['auto_requeue_when_done'] = $autoRequeueWhenDone;
            $this->writeJson($this->moduleDir . '/storage/run_state.json', $state);
        }
        if (!$shouldAutoQueue && in_array($statusForAuto, ['idle', 'done'], true)) {
            return ['status' => 'idle'];
        }
        if ($status === 'done') {
            return ['status' => 'idle'];
        }

        $batchSize = max(1, (int)($config['batch_size'] ?? 50));

        $allSymbols = $this->fetchUniverse($config);

        // Empty universe: write diagnostic last_run and return early
        if (empty($allSymbols)) {
            $startedAt  = date('c');
            $finishedAt = date('c');
            $lastRun = [
                'strategy_id'                     => 'confirmed_continuation',
                'strategy_is_environment_neutral' => true,
                'execution_mode_used_for_selection' => false,
                'status'                          => 'no_universe',
                'skip_reason'                     => 'universe_empty',
                'started_at'                      => $startedAt,
                'finished_at'                     => $finishedAt,
                'universe_source'                 => $this->universeSource,
                'universe_total'                  => 0,
                'universe_batch_count'            => 0,
                'universe_symbols_examples'       => [],
                'candidates_total'                => 0,
                'signals_total'                   => 0,
                'handoff_ready_total'             => 0,
                'rejected_total'                  => 0,
                'reject_reason_counts'            => [],
                'handoff_queue_written_total'     => 0,
                'handoff_queue_new_total'         => 0,
                'handoff_queue_refreshed_total'   => 0,
                'handoff_queue_missing_status_total' => 0,
                'handoff_queue_examples'          => [],
                'deprecated_execution_mode_key_seen'    => $this->deprecatedExecutionModeKeySeen,
                'deprecated_execution_mode_key_ignored' => $this->deprecatedExecutionModeKeyIgnored,
                'effective_strategy_mode'         => $this->effectiveStrategyMode,
                'selected_window_total'           => 0,
                'batch_size'                      => $batchSize,
                'max_symbols_per_run'             => max(1, (int)($config['max_symbols_per_run'] ?? 50)),
                'batch_offset_before'             => 0,
                'batch_offset_after'              => 0,
                'registry_cursor'                 => (int)($state['registry_cursor'] ?? 0),
                'previous_registry_cursor'        => (int)($state['previous_registry_cursor'] ?? 0),
                'next_registry_cursor'            => (int)($state['next_registry_cursor'] ?? 0),
                'registry_window_start'           => (int)($state['registry_window_start'] ?? 0),
                'registry_window_end'             => (int)($state['registry_window_end'] ?? 0),
                'registry_window_wrapped'         => (bool)($state['registry_window_wrapped'] ?? false),
                'registry_cursor_reset_reason'    => $state['registry_cursor_reset_reason'] ?? null,
                'batch_symbols_examples'          => [],
                'post_structure_filter_checked_total' => 0,
                'post_structure_filter_rejected_total' => 0,
                'pattern_123_checked_total'       => 0,
                'pattern_123_detected_total'      => 0,
                'pattern_123_rejected_total'      => 0,
                'backward_123_checked_total'      => 0,
                'backward_123_valid_total'        => 0,
                'backward_123_invalid_total'      => 0,
                'recent_point3_candidates_total'  => 0,
                'point3_near_entry_total'         => 0,
                'point3_turn_confirmed_total'     => 0,
                'point2_found_total'              => 0,
                'point1_found_total'              => 0,
                'structure_breaks_after_point3_total' => 0,
                'structure_breaks_before_point3_ignored_total' => 0,
                'pattern_123_missing_total'       => 0,
                'point_3_breaks_point_1_total'    => 0,
                'point_3_not_confirmed_total'     => 0,
                'entry_too_far_from_point_3_total'=> 0,
                'entry_timing_checked_total'      => 0,
                'near_exit_zone_rejected_total'   => 0,
                'controlled_trend_gate_checked_total' => 0,
                'controlled_trend_gate_rejected_total' => 0,
                'smooth_trend_checked_total'      => 0,
                'smooth_trend_rejected_total'     => 0,
                'vertical_spike_rejected_total'   => 0,
                'wall_pending_blocked_total'      => 0,
                'handoff_candidates_before_throttle_total' => 0,
                'handoff_after_throttle_total'    => 0,
                'handoff_throttled_total'         => 0,
                'handoff_throttle_reason_counts'  => [],
                'handoff_throttle_examples'       => [],
                'candle_source_used_counts'       => ['parser2_history' => 0, 'bybit_fallback' => 0, 'none' => 0],
                'parser2_history_used_total'      => 0,
                'bybit_fallback_used_total'       => 0,
                'backward_123_valid_examples'     => [],
                'backward_123_invalid_examples'   => [],
                'point3_candidate_examples'       => [],
                'parser2_history_error_examples'  => [],
                'continuous_scan_enabled'         => (bool)($state['continuous_scan_enabled'] ?? $continuousScanEnabled),
                'auto_requeue_when_done'          => (bool)($state['auto_requeue_when_done'] ?? $autoRequeueWhenDone),
                'auto_requeued_from_status'       => $state['auto_requeued_from_status'] ?? null,
                'auto_requeue_at'                 => $state['auto_requeue_at'] ?? null,
                'auto_requeue_result'             => $state['auto_requeue_result'] ?? null,
                'previous_next_registry_cursor_before_requeue' => $state['previous_next_registry_cursor_before_requeue'] ?? null,
                'registry_cursor_after_requeue'   => $state['registry_cursor_after_requeue'] ?? null,
                'next_registry_cursor_after_requeue' => $state['next_registry_cursor_after_requeue'] ?? null,
                'auto_requeue_skipped_reason'     => $state['auto_requeue_skipped_reason'] ?? null,
            ];
            $this->writeJson($this->moduleDir . '/storage/last_run.json', $lastRun);
            $state['status']       = 'done';
            $state['finished_at']  = $finishedAt;
            $state['batch_offset'] = 0;
            $this->writeJson($this->moduleDir . '/storage/run_state.json', $state);
            return $lastRun;
        }

        $windowSymbols = (isset($state['symbols']) && is_array($state['symbols'])) ? $state['symbols'] : [];
        $selectedTotal = (int)($state['selected_window_total'] ?? count($windowSymbols));
        if ((empty($windowSymbols) || $selectedTotal <= 0) && !empty($allSymbols)) {
            $cursor   = (int)($state['registry_cursor'] ?? 0);
            $maxTotal = max(1, (int)($state['max_symbols_per_run'] ?? $config['max_symbols_per_run'] ?? 50));
            $fullTotal = count($allSymbols);
            if ($fullTotal > 0 && ($cursor < 0 || $cursor >= $fullTotal)) {
                $cursor = 0;
            }
            $windowSize = $fullTotal > 0 ? min($maxTotal, $fullTotal) : 0;
            $windowSymbols = [];
            if ($fullTotal > 0) {
                for ($i = 0; $i < $windowSize; $i++) {
                    $idx = ($cursor + $i) % $fullTotal;
                    $windowSymbols[] = $allSymbols[$idx];
                }
            }
            $selectedTotal = count($windowSymbols);
            $state['symbols'] = $windowSymbols;
            $state['selected_window_total'] = $selectedTotal;
            $state['registry_window_start'] = $selectedTotal > 0 ? $cursor : 0;
            $state['registry_window_end'] = $selectedTotal > 0 ? (($cursor + $selectedTotal - 1) % $fullTotal) : 0;
            $state['registry_window_wrapped'] = $selectedTotal > 0 && ($cursor + $maxTotal > $fullTotal);
            $state['next_registry_cursor'] = ($fullTotal > 0 && $selectedTotal > 0) ? (($cursor + $selectedTotal) % $fullTotal) : 0;
            $state['universe_total'] = $fullTotal;
            $state['batch_size'] = $batchSize;
            $this->writeJson($this->moduleDir . '/storage/run_state.json', $state);
        }

        $offsetBefore = max(0, min((int)($state['batch_offset'] ?? 0), max(0, $selectedTotal)));
        $symbols = array_slice($windowSymbols, $offsetBefore, $batchSize);
        $offsetAfter = min($selectedTotal, $offsetBefore + count($symbols));
        $this->universeBatchCount = count($symbols);

        $startedAt = date('c');
        $t0        = microtime(true);

        $allCandidates = $this->readJson($this->moduleDir . '/storage/candidates.json', []);
        $allSignals    = $this->readJson($this->moduleDir . '/storage/signals.json',    []);
        $allHandoff    = $this->readJson($this->moduleDir . '/storage/bot_handoff_queue.json', []);
        $allRejects    = $this->readJson($this->moduleDir . '/storage/rejects.json',    []);

        $newCandidates = [];
        $newSignals    = [];
        $newRejects    = [];

        foreach ($symbols as $symbol) {
            $this->processSymbol($symbol, $config, $allSignals, $newCandidates, $newSignals, $newRejects);
        }

        // Merge new signals into existing (keyed by signal_id, dedup)
        $signalIndex = [];
        foreach ($allSignals as $sig) {
            $sid = (string)($sig['signal_id'] ?? '');
            if ($sid !== '') {
                $signalIndex[$sid] = $sig;
            }
        }
        foreach ($newSignals as $sig) {
            $sid = (string)($sig['signal_id'] ?? '');
            if ($sid !== '') {
                $signalIndex[$sid] = $sig;
            }
        }

        // Mark stale signals
        $ttlMin     = max(1, (int)($config['signal_ttl_minutes'] ?? 90));
        $staleAfter = time() - ($ttlMin * 60);
        foreach ($signalIndex as &$sig) {
            if (!($sig['stale'] ?? false)) {
                $detectedAt = (int)strtotime((string)($sig['detected_at'] ?? ''));
                if ($detectedAt > 0 && $detectedAt < $staleAfter) {
                    $sig['stale']        = true;
                    $sig['stale_reason'] = 'ttl_expired';
                    $sig['active_final'] = false;
                    $sig['handoff_ready'] = false;
                    $sig['executable']   = false;
                }
            }
        }
        unset($sig);

        $freshSignals = array_values($signalIndex);
        $handoffQueue = $this->buildHandoffQueue($freshSignals, $allHandoff, $config);

        // Persist rejects (append-style, capped)
        $allRejects = array_merge($allRejects, $newRejects);
        if (count($allRejects) > 500) {
            $allRejects = array_slice($allRejects, -500);
        }

        // Persist candidates (append-style, capped; includes rejected for diagnostics visibility)
        $allCandidates = array_merge($allCandidates, $newCandidates);
        if (count($allCandidates) > 500) {
            $allCandidates = array_slice($allCandidates, -500);
        }

        $this->writeJson($this->moduleDir . '/storage/candidates.json',        array_values($allCandidates));
        $this->writeJson($this->moduleDir . '/storage/signals.json',           $freshSignals);
        $this->writeJson($this->moduleDir . '/storage/bot_handoff_queue.json', $handoffQueue);
        $this->writeJson($this->moduleDir . '/storage/rejects.json',           $allRejects);

        if ($offsetAfter >= $selectedTotal) {
            $state['status']       = 'done';
            $state['finished_at']  = date('c');
            $state['batch_offset'] = $offsetAfter;
            $this->writeJson($this->moduleDir . '/storage/run_state.json', $state);
        } else {
            $state['status']       = 'running';
            $state['batch_offset'] = $offsetAfter;
            $this->writeJson($this->moduleDir . '/storage/run_state.json', $state);
        }

        $finishedAt  = date('c');
        $durationMs  = (int)round((microtime(true) - $t0) * 1000);

        $lastRun = $this->buildLastRun($config, $startedAt, $finishedAt, $durationMs, $freshSignals, [
            'selected_window_total'      => $selectedTotal,
            'batch_size'                 => $batchSize,
            'max_symbols_per_run'        => (int)($state['max_symbols_per_run'] ?? $config['max_symbols_per_run'] ?? 50),
            'batch_offset_before'        => $offsetBefore,
            'batch_offset_after'         => $offsetAfter,
            'registry_cursor'            => (int)($state['registry_cursor'] ?? 0),
            'previous_registry_cursor'   => (int)($state['previous_registry_cursor'] ?? 0),
            'next_registry_cursor'       => (int)($state['next_registry_cursor'] ?? 0),
            'registry_window_start'      => (int)($state['registry_window_start'] ?? 0),
            'registry_window_end'        => (int)($state['registry_window_end'] ?? 0),
            'registry_window_wrapped'    => (bool)($state['registry_window_wrapped'] ?? false),
            'registry_cursor_reset_reason' => $state['registry_cursor_reset_reason'] ?? null,
            'batch_symbols_examples'     => array_slice($symbols, 0, 5),
            'continuous_scan_enabled'    => (bool)($state['continuous_scan_enabled'] ?? $continuousScanEnabled),
            'auto_requeue_when_done'     => (bool)($state['auto_requeue_when_done'] ?? $autoRequeueWhenDone),
            'auto_requeued_from_status'  => $state['auto_requeued_from_status'] ?? null,
            'auto_requeue_at'            => $state['auto_requeue_at'] ?? null,
            'auto_requeue_result'        => $state['auto_requeue_result'] ?? null,
            'previous_next_registry_cursor_before_requeue' => $state['previous_next_registry_cursor_before_requeue'] ?? null,
            'registry_cursor_after_requeue' => $state['registry_cursor_after_requeue'] ?? null,
            'next_registry_cursor_after_requeue' => $state['next_registry_cursor_after_requeue'] ?? null,
            'auto_requeue_skipped_reason' => $state['auto_requeue_skipped_reason'] ?? null,
        ]);
        $this->writeJson($this->moduleDir . '/storage/last_run.json', $lastRun);

        return $lastRun;
    }

    // ── Core symbol processing ─────────────────────────────────────────────────

    private function processSymbol(
        string $symbol,
        array  $config,
        array  $existingSignals,
        array  &$newCandidates,
        array  &$newSignals,
        array  &$newRejects
    ): void {
        $sideMode = (string)($config['side_mode'] ?? 'all');

        foreach (['long', 'short'] as $side) {
            if ($sideMode !== 'all' && $sideMode !== $side) {
                continue;
            }

            // Guard: max 1 active non-stale signal per symbol+side
            $maxActive = (int)($config['max_active_signals_per_symbol_side'] ?? 1);
            if ($maxActive > 0) {
                $activeCount = 0;
                foreach ($existingSignals as $sig) {
                    if (
                        (string)($sig['symbol'] ?? '') === $symbol
                        && (string)($sig['side']   ?? '') === $side
                        && !($sig['stale']        ?? false)
                        && ($sig['active_final']  ?? false)
                    ) {
                        $activeCount++;
                    }
                }
                if ($activeCount >= $maxActive) {
                    continue;
                }
            }

            // Fetch candles
            $candles = $this->fetchCandles($symbol, (int)($config['lookback_candles'] ?? 60), $config);
            $candleFetchDiag = $this->lastCandleFetchDiag;
            $sourceUsed = (string)($candleFetchDiag['candle_source_used'] ?? 'none');
            if (!isset($this->candleSourceUsedCounts[$sourceUsed])) {
                $this->candleSourceUsedCounts[$sourceUsed] = 0;
            }
            $this->candleSourceUsedCounts[$sourceUsed]++;
            if ($sourceUsed === 'parser2_history') {
                $this->parser2HistoryUsedTotal++;
            } elseif ($sourceUsed === 'bybit_fallback') {
                $this->bybitFallbackUsedTotal++;
            }
            if ((string)($candleFetchDiag['candle_source_error'] ?? '') !== '' && count($this->parser2HistoryErrorExamples) < 12) {
                $this->parser2HistoryErrorExamples[] = [
                    'symbol' => $symbol,
                    'side' => $side,
                    'candle_source_error' => (string)$candleFetchDiag['candle_source_error'],
                    'candle_source_used' => $sourceUsed,
                ];
            }
            if (count($candles) < 20) {
                continue;
            }

            // 24h side-bias diagnostics / gating context
            $dayCtx = $this->computeDayRegimeContext($symbol, $side, $candles, $config);

            // Detect structure
            $structure = $this->detectStructure($candles, $side, $config);
            if ($structure === null) {
                continue;
            }
            $structure = array_merge($structure, $dayCtx, $candleFetchDiag);
            if ($side === 'long') {
                $this->higherLowCandidatesTotal++;
            } else {
                $this->lowerHighCandidatesTotal++;
            }
            if (($structure['retest_held'] ?? false) === true) {
                $this->retestHeldTotal++;
            }
            if (($structure['continuation_confirmed'] ?? false) === true) {
                $this->continuationConfirmedTotal++;
            }

            // Anti-comb / anti-chaos diagnostics
            $antiComb = $this->computeAntiCombDiagnostics($candles, $side, $structure, $config, $symbol);
            $structure = array_merge($structure, $antiComb);
            $entryTiming = $this->computeEntryTimingDiagnostics($structure, $candles, $side, $config);
            $structure = array_merge($structure, $entryTiming);

            $this->candidatesTotal++;
            if ($side === 'long') {
                $this->longCandidatesTotal++;
            } else {
                $this->shortCandidatesTotal++;
            }

            // ── Pre-filter 1-2-3 / funnel diagnostics (counted before gates) ────
            $this->structuresDetectedTotal++;
            $this->pattern123StructuresSeenTotal++;
            $p123PreValid = (bool)($structure['pattern_123_detected'] ?? false)
                && ($structure['pattern_123_invalid_reason'] ?? null) === null;
            if ($p123PreValid) {
                $this->pattern123ValidBeforeFiltersTotal++;
            } else {
                $this->pattern123InvalidBeforeFiltersTotal++;
            }
            // Track anti-comb pass for funnel (before hard reject gates)
            if (!($structure['anti_comb_rejected'] ?? false)) {
                $this->antiCombPassedTotal++;
            }

            // Hard reject filters
            $hardReject = $this->checkHardRejects($structure, $candles, $side, $config);
            if ($hardReject !== null) {
                $this->rejectedTotal++;
                $this->rejectReasonCounts[$hardReject['reason']] = ($this->rejectReasonCounts[$hardReject['reason']] ?? 0) + 1;
                $rejectRec = [
                    'symbol'       => $symbol,
                    'side'         => $side,
                    'setup_class'  => $structure['setup_class'],
                    'reject_reason'=> $hardReject['reason'],
                    'primary_reject_reason' => $hardReject['reason'],
                    'secondary_reject_reasons' => is_array($hardReject['secondary_reject_reasons'] ?? null)
                        ? array_values($hardReject['secondary_reject_reasons'])
                        : (is_array($structure['secondary_reject_reasons'] ?? null) ? array_values($structure['secondary_reject_reasons']) : []),
                    'failed_stage' => $hardReject['stage'],
                    'rejected_at'  => date('c'),
                    'structure'    => $structure,
                ];
                $newRejects[] = $rejectRec;
                if (count($this->rejectedExamples) < 10) {
                    $this->rejectedExamples[] = $rejectRec;
                }
                // Track specific reject types for diagnostics
                if (str_contains($hardReject['stage'], 'late_entry')) {
                    $this->lateEntryRejectedTotal++;
                    if (count($this->lateEntryRejectExamples) < 5) {
                        $this->lateEntryRejectExamples[] = $rejectRec;
                    }
                    if (count($this->rejectedLateEntryExamples) < 8) {
                        $this->rejectedLateEntryExamples[] = $rejectRec;
                    }
                } elseif (str_contains($hardReject['stage'], 'first_bounce')) {
                    $this->firstBounceRejectedTotal++;
                    if (count($this->firstBounceRejectExamples) < 5) {
                        $this->firstBounceRejectExamples[] = $rejectRec;
                    }
                } elseif (str_contains($hardReject['stage'], 'no_retest')) {
                    if (count($this->noRetestRejectExamples) < 5) {
                        $this->noRetestRejectExamples[] = $rejectRec;
                    }
                } elseif (($hardReject['stage'] ?? '') === 'pattern_123') {
                    if (count($this->rejectedPattern123Examples) < 8) {
                        $this->rejectedPattern123Examples[] = $rejectRec;
                    }
                }
                if (($hardReject['reason'] ?? '') === 'entry_near_take_profit_zone') {
                    $this->nearExitZoneRejectedTotal++;
                }
                // ── Funnel tracking: which stage did valid-123 fail at? ────────
                if ($p123PreValid) {
                    if (($hardReject['stage'] ?? '') === 'anti_comb') {
                        $this->pattern123ValidButAntiCombRejectedTotal++;
                        if (count($this->pattern123ValidButAntiCombRejectedExamples) < 12) {
                            $this->pattern123ValidButAntiCombRejectedExamples[] = $this->buildFullDiagExample(
                                $symbol, $side, $structure, $hardReject['reason'], $hardReject['stage']
                            );
                        }
                    } elseif (in_array($hardReject['stage'] ?? '', [
                        'entry_timing', 'late_entry', 'blowoff_reject',
                        'no_retest', 'first_bounce_or_no_structure', 'first_dump_or_no_structure',
                    ], true)) {
                        $this->pattern123ValidButEntryTimingRejectedTotal++;
                    }
                }
                // ── Write rejected candidate to candidates.json for visibility ─
                $newCandidates[] = [
                    'strategy_id'      => 'confirmed_continuation',
                    'symbol'           => $symbol,
                    'side'             => $side,
                    'setup_class'      => $structure['setup_class'] ?? '',
                    'candidate_state'  => 'rejected',
                    'failed_stage'     => $hardReject['stage'],
                    'reject_reason'    => $hardReject['reason'],
                    'primary_reject_reason' => $hardReject['reason'],
                    'secondary_reject_reasons' => is_array($hardReject['secondary_reject_reasons'] ?? null)
                        ? array_values($hardReject['secondary_reject_reasons'])
                        : (is_array($structure['secondary_reject_reasons'] ?? null) ? array_values($structure['secondary_reject_reasons']) : []),
                    'block_reason'     => null,
                    'handoff_ready'    => false,
                    'executable'       => false,
                    'active_final'     => false,
                    'stale'            => false,
                    'detected_at'      => date('c'),
                    'entry_price'      => $structure['entry_price'] ?? null,
                    'pattern_123_detected'           => $structure['pattern_123_detected'] ?? false,
                    'pattern_123_detection_direction'=> $structure['pattern_123_detection_direction'] ?? null,
                    'pattern_123_invalid_reason'     => $structure['pattern_123_invalid_reason'] ?? null,
                    'pattern_123_entry_mode'         => $structure['pattern_123_entry_mode'] ?? 'none',
                    'point_3_age_minutes'            => $structure['point_3_age_minutes'] ?? null,
                    'point_3_distance_from_current_pct' => $structure['point_3_distance_from_current_pct'] ?? null,
                    'candle_source_used'             => $structure['candle_source_used'] ?? null,
                    'parser2_history_files_read'     => $structure['parser2_history_files_read'] ?? null,
                    'parser2_history_candles_loaded' => $structure['parser2_history_candles_loaded'] ?? null,
                    'parser2_history_candles_after_dedupe' => $structure['parser2_history_candles_after_dedupe'] ?? null,
                    'parser2_history_oldest_ts'      => $structure['parser2_history_oldest_ts'] ?? null,
                    'parser2_history_newest_ts'      => $structure['parser2_history_newest_ts'] ?? null,
                    'bybit_fallback_used'            => $structure['bybit_fallback_used'] ?? null,
                    'candle_source_error'            => $structure['candle_source_error'] ?? null,
                    'anti_comb_rejected'             => $structure['anti_comb_rejected'] ?? false,
                    'anti_comb_reject_reason'        => $structure['anti_comb_reject_reason'] ?? null,
                    'anti_comb_reject_reason_detail' => $structure['anti_comb_reject_reason_detail'] ?? null,
                    'secondary_reject_reasons'       => is_array($structure['secondary_reject_reasons'] ?? null) ? array_values($structure['secondary_reject_reasons']) : [],
                    'primary_reject_reason'          => $structure['primary_reject_reason'] ?? ($structure['anti_comb_reject_reason'] ?? null),
                    'structure_breaks_exceeded'      => (bool)($structure['structure_breaks_exceeded'] ?? false),
                    'anti_comb_max_structure_breaks' => $structure['anti_comb_max_structure_breaks'] ?? null,
                    'recent_swing_exceeded'          => (bool)($structure['recent_swing_exceeded'] ?? false),
                    'opposite_swing_exceeded'        => (bool)($structure['opposite_swing_exceeded'] ?? false),
                    'range_1m_exceeded'              => (bool)($structure['range_1m_exceeded'] ?? false),
                    'range_3m_exceeded'              => (bool)($structure['range_3m_exceeded'] ?? false),
                    'wick_chaos_exceeded'            => (bool)($structure['wick_chaos_exceeded'] ?? false),
                    'directional_consistency_failed' => (bool)($structure['directional_consistency_failed'] ?? false),
                    'post_structure_comb_detected'   => $structure['post_structure_comb_detected'] ?? false,
                    'controlled_trend_score'         => $structure['controlled_trend_score'] ?? null,
                    'directional_consistency_score'  => $structure['directional_consistency_score'] ?? null,
                    'wick_chaos_score'               => $structure['wick_chaos_score'] ?? null,
                    'smooth_trend_score'             => $structure['smooth_trend_score'] ?? null,
                    'entry_timing_class'             => $structure['entry_timing_class'] ?? null,
                    'strategy_signal_context'        => [
                        'candidate_state' => 'rejected',
                        'reject_reason'   => $hardReject['reason'],
                        'primary_reject_reason' => $hardReject['reason'],
                        'secondary_reject_reasons' => is_array($hardReject['secondary_reject_reasons'] ?? null)
                            ? array_values($hardReject['secondary_reject_reasons'])
                            : (is_array($structure['secondary_reject_reasons'] ?? null) ? array_values($structure['secondary_reject_reasons']) : []),
                        'structure_breaks_exceeded' => (bool)($structure['structure_breaks_exceeded'] ?? false),
                        'anti_comb_max_structure_breaks' => $structure['anti_comb_max_structure_breaks'] ?? null,
                        'failed_stage'    => $hardReject['stage'],
                        'anti_comb_reject_reason'    => $structure['anti_comb_reject_reason'] ?? null,
                        'pattern_123_invalid_reason' => $structure['pattern_123_invalid_reason'] ?? null,
                        'pattern_123_detection_direction' => $structure['pattern_123_detection_direction'] ?? null,
                        'point_3_age_minutes' => $structure['point_3_age_minutes'] ?? null,
                        'point_3_distance_from_current_pct' => $structure['point_3_distance_from_current_pct'] ?? null,
                        'candle_source_used' => $structure['candle_source_used'] ?? null,
                    ],
                ];
                continue;
            }

            // Hard reject filters all passed: count entry-timing pass for funnel
            $this->entryTimingPassedTotal++;

            // Score candidate
            $quality = $this->scoreCandidate($structure, $candles, $side, $config);

            // Gate on minimum quality
            $minQuality   = (float)($config['min_candidate_quality_score'] ?? 0.75);
            $minStructure = (float)($config['min_structure_score']          ?? 0.75);
            if ($quality['final_candidate_score'] < $minQuality
                || $quality['structure_score']     < $minStructure) {
                $reason = 'quality_below_threshold';
                $this->rejectedTotal++;
                $this->rejectReasonCounts[$reason] = ($this->rejectReasonCounts[$reason] ?? 0) + 1;
                $newRejects[] = [
                    'symbol'       => $symbol,
                    'side'         => $side,
                    'setup_class'  => $structure['setup_class'],
                    'reject_reason'=> $reason,
                    'failed_stage' => 'quality_gate',
                    'rejected_at'  => date('c'),
                    'scores'       => $quality,
                    'candidate_quality_score' => $quality['candidate_quality_score'] ?? null,
                    'structure_score'         => $quality['structure_score'] ?? null,
                    'controlled_trend_score'  => $quality['controlled_trend_score'] ?? ($structure['controlled_trend_score'] ?? null),
                ];
                // Write rejected candidate to candidates.json for visibility
                $newCandidates[] = [
                    'strategy_id'      => 'confirmed_continuation',
                    'symbol'           => $symbol,
                    'side'             => $side,
                    'setup_class'      => $structure['setup_class'] ?? '',
                    'candidate_state'  => 'rejected',
                    'failed_stage'     => 'quality_gate',
                    'reject_reason'    => $reason,
                    'block_reason'     => null,
                    'handoff_ready'    => false,
                    'executable'       => false,
                    'active_final'     => false,
                    'stale'            => false,
                    'detected_at'      => date('c'),
                    'entry_price'      => $structure['entry_price'] ?? null,
                    'candidate_quality_score' => $quality['candidate_quality_score'] ?? null,
                    'structure_score'         => $quality['structure_score'] ?? null,
                    'controlled_trend_score'  => $quality['controlled_trend_score'] ?? ($structure['controlled_trend_score'] ?? null),
                    'pattern_123_detected'    => $structure['pattern_123_detected'] ?? false,
                    'pattern_123_entry_mode'  => $structure['pattern_123_entry_mode'] ?? 'none',
                    'entry_timing_class'      => $structure['entry_timing_class'] ?? null,
                    'strategy_signal_context' => [
                        'candidate_state' => 'rejected',
                        'reject_reason'   => $reason,
                        'failed_stage'    => 'quality_gate',
                    ],
                ];
                continue;
            }

            $this->qualityGatePassedTotal++;

            // OBC gate (only after cheap filters pass)
            $obcResult = $this->applyObcGate($symbol, $side, $structure['entry_price'] ?? 0.0, $quality, $config);
            // Wall decision test (after OBC context is available)
            $wallTest  = $this->computeWallDecisionTest($side, $structure, $obcResult, $config);
            $structure = array_merge($structure, $wallTest);

            // Track wall-test pass for funnel
            if (!($structure['wall_test_blocked'] ?? false)) {
                $this->wallTestPassedTotal++;
            } elseif ($p123PreValid) {
                $this->pattern123ValidButWallBlockedTotal++;
            }

            $candidate = $this->buildCandidate($symbol, $side, $structure, $quality, $obcResult, $config);
            $newCandidates[] = $candidate;
            if (!(bool)($candidate['handoff_ready'] ?? true) || !(bool)($candidate['executable'] ?? true)) {
                $blockReason = (string)($candidate['block_reason'] ?? 'blocked');
                $this->rejectedTotal++;
                $this->rejectReasonCounts[$blockReason] = ($this->rejectReasonCounts[$blockReason] ?? 0) + 1;
                $newRejects[] = [
                    'symbol' => $symbol,
                    'side' => $side,
                    'setup_class' => $structure['setup_class'],
                    'reject_reason' => $blockReason,
                    'failed_stage' => 'handoff_gate',
                    'rejected_at' => date('c'),
                    'structure' => $candidate,
                ];
            }

            // Determine handoff_ready and executable
            $isHandoffReady = $candidate['handoff_ready'];
            $isExecutable   = $candidate['executable'];

            // Build signal
            $signal = $this->buildSignal($candidate, $config);
            $newSignals[] = $signal;

            if ($isHandoffReady && $isExecutable) {
                $this->signalReadyTotal++;
                if ($p123PreValid) {
                    $this->pattern123ValidSignalReadyTotal++;
                }
            }

            $this->signalsTotal++;
            if ($side === 'long') {
                $this->longSignalsTotal++;
            } else {
                $this->shortSignalsTotal++;
            }
            if ($isHandoffReady) {
                $this->handoffReadyTotal++;
            }
            if (count($this->acceptedExamples) < 5) {
                $this->acceptedExamples[] = [
                    'symbol'                => $symbol,
                    'side'                  => $side,
                    'setup_class'           => $structure['setup_class'],
                    'final_candidate_score' => $quality['final_candidate_score'],
                    'handoff_ready'         => $isHandoffReady,
                    'executable'            => $isExecutable,
                    'ob_soft_demoted'       => $obcResult['ob_soft_demoted'] ?? false,
                ];
            }
            $entryClass = (string)($structure['entry_timing_class'] ?? '');
            if (in_array($entryClass, ['early_structure_entry', 'early_breakdown_entry'], true) && count($this->acceptedEarlyStructureExamples) < 8) {
                $this->acceptedEarlyStructureExamples[] = [
                    'symbol' => $symbol,
                    'side' => $side,
                    'entry_timing_class' => $entryClass,
                    'candidate_quality_score' => $quality['candidate_quality_score'] ?? null,
                ];
            }
            if (in_array($entryClass, ['mid_trend_structure_entry', 'mid_trend_breakdown_entry'], true) && count($this->acceptedMidTrendExamples) < 8) {
                $this->acceptedMidTrendExamples[] = [
                    'symbol' => $symbol,
                    'side' => $side,
                    'entry_timing_class' => $entryClass,
                    'candidate_quality_score' => $quality['candidate_quality_score'] ?? null,
                ];
            }
            if (($structure['pattern_123_detected'] ?? false) && count($this->acceptedPattern123Examples) < 8) {
                $this->acceptedPattern123Examples[] = [
                    'symbol' => $symbol,
                    'side' => $side,
                    'pattern_123_entry_mode' => $structure['pattern_123_entry_mode'] ?? 'none',
                    'point_1_price' => $structure['point_1_price'] ?? null,
                    'point_2_price' => $structure['point_2_price'] ?? null,
                    'point_3_price' => $structure['point_3_price'] ?? null,
                ];
            }
        }
    }

    // ── Structure detection ────────────────────────────────────────────────────

    /**
     * Detect trend structure from candle data.
     *
     * Returns a structure array with all scored fields, or null if no valid
     * structure is detectable.
     */
    private function detectStructure(array $candles, string $side, array $config): ?array
    {
        if (count($candles) < 10) {
            return null;
        }
        return $side === 'long'
            ? $this->detectLong123FromRecentPoint3($candles, $config)
            : $this->detectShort123FromRecentPoint3($candles, $config);
    }

    private function normalizeCandleTimestampToSeconds(int $ts): int
    {
        return $ts > self::TIMESTAMP_MS_TO_SECONDS_THRESHOLD ? (int)floor($ts / 1000) : $ts;
    }

    private function formatCandleTimestamp(?int $ts): ?string
    {
        if ($ts === null || $ts <= 0) {
            return null;
        }
        return gmdate('c', $this->normalizeCandleTimestampToSeconds($ts));
    }

    private function getIdeaKeyTimeBucket(): int
    {
        return (int)(floor(time() / self::IDEA_KEY_BUCKET_SECONDS) * self::IDEA_KEY_BUCKET_SECONDS);
    }

    private function detectLong123FromRecentPoint3(array $candles, array $config): ?array
    {
        $this->backward123CheckedTotal++;
        $closes = array_column($candles, 'close');
        $highs  = array_column($candles, 'high');
        $lows   = array_column($candles, 'low');
        $n      = count($candles);
        $currentPrice = (float)($closes[$n - 1] ?? 0.0);
        if ($currentPrice <= 0.0 || $n < 10) {
            $this->backward123InvalidTotal++;
            return null;
        }

        $recentSearchMinutes = max(5, (int)($config['recent_point3_search_minutes'] ?? 30));
        $maxPoint3AgeMinutes = max(5, (int)($config['pattern_123_max_point3_age_minutes'] ?? 30));
        $maxEntryDistPct = (float)($config['pattern_123_max_entry_distance_from_point3_pct'] ?? 0.7);
        $currentTs = $this->normalizeCandleTimestampToSeconds((int)($candles[$n - 1]['ts'] ?? time()));
        $recentStartTs = $currentTs - ($recentSearchMinutes * 60);

        $candidatePoint3 = [];
        for ($i = $n - 3; $i >= 2; $i--) {
            $tsi = $this->normalizeCandleTimestampToSeconds((int)($candles[$i]['ts'] ?? 0));
            if ($tsi > 0 && $tsi < $recentStartTs) {
                break;
            }
            if ($this->isLocalSwingLow($lows, $i)) {
                $candidatePoint3[] = $i;
            }
        }
        $this->recentPoint3CandidatesTotal += count($candidatePoint3);
        foreach (array_slice($candidatePoint3, 0, 3) as $idx) {
            if (count($this->point3CandidateExamples) >= 20) {
                break;
            }
            $point3Price = (float)($lows[$idx] ?? 0.0);
            $dist = $point3Price > 0.0 ? abs(($currentPrice - $point3Price) / $point3Price * 100.0) : null;
            $this->point3CandidateExamples[] = [
                'side' => 'long',
                'point_3_idx' => $idx,
                'point_3_price' => $point3Price > 0 ? round($point3Price, 8) : null,
                'point_3_time' => $this->formatCandleTimestamp((int)($candles[$idx]['ts'] ?? 0)),
                'point_3_distance_from_current_pct' => $dist !== null ? round($dist, 4) : null,
            ];
        }
        if (empty($candidatePoint3)) {
            $this->backward123InvalidTotal++;
            return null;
        }

        $selected = null;
        foreach ($candidatePoint3 as $point3Idx) {
            $point3Price = (float)$lows[$point3Idx];
            if ($point3Price <= 0.0 || $currentPrice <= $point3Price) {
                continue;
            }
            $point3Ts = $this->normalizeCandleTimestampToSeconds((int)($candles[$point3Idx]['ts'] ?? 0));
            $point3Age = $point3Ts > 0 ? max(0.0, ($currentTs - $point3Ts) / 60.0) : 9999.0;
            if ($point3Age > $maxPoint3AgeMinutes) {
                continue;
            }
            $entryDistPoint3Pct = (($currentPrice - $point3Price) / $point3Price) * 100.0;
            $entryNearPoint3 = $entryDistPoint3Pct <= $maxEntryDistPct;
            if ($entryNearPoint3) {
                $this->point3NearEntryTotal++;
            }
            $point3Turn = ($point3Idx < $n - 1)
                && ((float)$closes[$n - 1] > max((float)$closes[$point3Idx], (float)$closes[max(0, $point3Idx + 1)]));
            if ($point3Turn) {
                $this->point3TurnConfirmedTotal++;
            }
            if (!$entryNearPoint3) {
                continue;
            }

            $point2Idx = $this->findMaxIndex($highs, 1, $point3Idx - 1);
            if ($point2Idx <= 1) {
                continue;
            }
            $this->point2FoundTotal++;
            $point1Idx = $this->findMinIndex($lows, 1, $point2Idx - 1);
            if ($point1Idx < 0) {
                continue;
            }
            $this->point1FoundTotal++;
            $selected = [
                'point1Idx' => $point1Idx,
                'point2Idx' => $point2Idx,
                'point3Idx' => $point3Idx,
                'point3AgeMinutes' => $point3Age,
                'entryDistPoint3Pct' => $entryDistPoint3Pct,
                'point3Turn' => $point3Turn,
            ];
            break;
        }

        if ($selected === null) {
            $this->backward123InvalidTotal++;
            if (count($this->backward123InvalidExamples) < 16) {
                $this->backward123InvalidExamples[] = [
                    'side' => 'long',
                    'reason' => 'recent_point3_not_found_or_not_near_entry',
                    'symbol_context' => 'backward_from_recent_point3',
                ];
            }
            return null;
        }

        $point1Idx = (int)$selected['point1Idx'];
        $point2Idx = (int)$selected['point2Idx'];
        $point3Idx = (int)$selected['point3Idx'];
        $point1Price = (float)$lows[$point1Idx];
        $point2Price = (float)$highs[$point2Idx];
        $point3Price = (float)$lows[$point3Idx];
        $point3Holds = $point3Price > $point1Price;
        $point3Distance = $point1Price > 0 ? (($point3Price - $point1Price) / $point1Price * 100.0) : 0.0;
        $entryPrice = $currentPrice;

        $recentLow = min(array_slice($lows, -5));
        $maxPullbackDepthPct = (float)($config['max_pullback_depth_pct'] ?? 2.0);
        $pullbackPct = ($currentPrice - $recentLow) / $currentPrice * 100.0;
        $pullbackDetected = $pullbackPct >= 0.1 && $pullbackPct <= $maxPullbackDepthPct;
        if (($config['require_retest'] ?? true) && !$pullbackDetected) {
            $this->backward123InvalidTotal++;
            return null;
        }

        $continuationPrice = (float)end($closes);
        $continuationConfirmed = $continuationPrice > $point3Price;
        if (($config['require_continuation_after_retest'] ?? true) && !$continuationConfirmed) {
            $this->backward123InvalidTotal++;
            return null;
        }

        $tolPct = (float)($config['structure_break_after_point3_tolerance_pct'] ?? 0.15);
        $downBreakLevel = $point3Price * (1.0 - ($tolPct / 100.0));
        $breaksAfter = 0;
        $breaksBeforeIgnored = 0;
        for ($i = 0; $i < $n; $i++) {
            $low = (float)$lows[$i];
            if ($low < $downBreakLevel) {
                if ($i > $point3Idx) {
                    $breaksAfter++;
                } else {
                    $breaksBeforeIgnored++;
                }
            }
        }
        $this->structureBreaksAfterPoint3Total += $breaksAfter;
        $this->structureBreaksBeforePoint3IgnoredTotal += $breaksBeforeIgnored;

        $entryDistancePct = (($entryPrice - $point3Price) / $point3Price) * 100.0;
        $entryNearPoint3 = ((float)$selected['entryDistPoint3Pct']) <= $maxEntryDistPct;
        $point3Turn = (bool)$selected['point3Turn'];
        $point2Breakout = $entryPrice > $point2Price;
        $point2Retest = $point2Price > 0 ? ($recentLow <= $point2Price * 1.006 && $recentLow >= $point2Price * 0.994) : false;
        $patternMode = $point2Breakout && $point2Retest ? 'conservative_point2_retest' : (($entryNearPoint3 && $point3Turn) ? 'aggressive_point3' : 'none');
        $patternDetected = $point1Idx < $point2Idx && $point2Idx < $point3Idx && $point2Price > $point1Price && $point3Price > $point1Price;
        $patternInvalid = null;
        if (!$patternDetected) {
            $patternInvalid = 'pattern_123_missing';
        } elseif (!$point3Holds) {
            $patternInvalid = 'point_3_breaks_point_1';
        } elseif ($point3Distance < (float)($config['pattern_123_min_point3_distance_from_point1_pct'] ?? 0.15)) {
            $patternInvalid = 'point_3_not_confirmed';
        } elseif (!$point3Turn && (bool)($config['pattern_123_require_point3_turn_confirmation'] ?? true)) {
            $patternInvalid = 'point_3_not_confirmed';
        } elseif (!$entryNearPoint3) {
            $patternInvalid = 'entry_too_far_from_point_3';
        }
        if ($patternInvalid === null) {
            $this->backward123ValidTotal++;
            if (count($this->backward123ValidExamples) < 16) {
                $this->backward123ValidExamples[] = [
                    'side' => 'long',
                    'point_1_price' => round($point1Price, 8),
                    'point_2_price' => round($point2Price, 8),
                    'point_3_price' => round($point3Price, 8),
                    'point_3_age_minutes' => round((float)$selected['point3AgeMinutes'], 2),
                ];
            }
        } else {
            $this->backward123InvalidTotal++;
            if (count($this->backward123InvalidExamples) < 16) {
                $this->backward123InvalidExamples[] = [
                    'side' => 'long',
                    'reason' => $patternInvalid,
                    'point_1_price' => round($point1Price, 8),
                    'point_2_price' => round($point2Price, 8),
                    'point_3_price' => round($point3Price, 8),
                ];
            }
        }

        return [
            'setup_class'                          => 'higher_low_retest_continuation_long',
            'side'                                 => 'long',
            'entry_price'                          => $entryPrice,
            'current_price'                        => $currentPrice,
            'higher_lows_count'                    => 2,
            'last_higher_low_price'                => $point3Price,
            'rising_support_price'                 => $point3Price,
            'retest_price'                         => $recentLow,
            'retest_held'                          => $pullbackDetected,
            'continuation_reclaim_price'           => $continuationPrice,
            'entry_distance_from_last_higher_low_pct' => round($entryDistancePct, 4),
            'distance_from_rising_support_pct'         => round($entryDistancePct, 4),
            'distance_from_last_higher_low_pct'        => round($entryDistancePct, 4),
            'distance_from_falling_resistance_pct'     => null,
            'distance_from_last_lower_high_pct'        => null,
            'trend_phase'                          => 'confirmed_mid_trend_continuation',
            'structure_holds'                      => $breaksAfter === 0,
            'pullback_detected'                    => $pullbackDetected,
            'pullback_pct'                         => round($pullbackPct, 4),
            'continuation_confirmed'               => $continuationConfirmed,
            'structure_anchor_type'                => 'point_1',
            'structure_anchor_idx'                 => $point1Idx,
            'structure_anchor_time'                => $this->formatCandleTimestamp((int)($candles[$point1Idx]['ts'] ?? 0)),
            'structure_anchor_price'               => round($point1Price, 8),
            'post_structure_window_start'          => max(0, $point1Idx),
            'post_structure_window_minutes'        => max(1, $n - $point1Idx),
            'pre_structure_impulse_pct'            => round($this->computeLongPreStructureImpulsePct($highs, $point1Idx, $point1Price), 4),
            'pre_structure_impulse_roi'            => round($this->computeLongPreStructureImpulsePct($highs, $point1Idx, $point1Price) * max(1.0, (float)($config['anti_comb_roi_equiv_leverage'] ?? 15.0)), 4),
            'pre_structure_impulse_allowed'        => true,
            'post_structure_controlled_trend_score'=> 0.0,
            'pattern_123_detected'                 => $patternDetected,
            'pattern_123_side'                     => 'long',
            'pattern_123_detection_direction'      => 'backward_from_recent_point3',
            'point_1_price'                        => round($point1Price, 8),
            'point_1_time'                         => $this->formatCandleTimestamp((int)($candles[$point1Idx]['ts'] ?? 0)),
            'point_2_price'                        => round($point2Price, 8),
            'point_2_time'                         => $this->formatCandleTimestamp((int)($candles[$point2Idx]['ts'] ?? 0)),
            'point_3_price'                        => round($point3Price, 8),
            'point_3_time'                         => $this->formatCandleTimestamp((int)($candles[$point3Idx]['ts'] ?? 0)),
            'point_3_holds_structure'              => $point3Holds,
            'point_3_distance_from_point_1_pct'    => round($point3Distance, 4),
            'point_3_age_minutes'                  => round((float)$selected['point3AgeMinutes'], 4),
            'point_3_distance_from_current_pct'    => round((float)$selected['entryDistPoint3Pct'], 4),
            'entry_near_point_3'                   => $entryNearPoint3,
            'entry_after_point_3_turn'             => $point3Turn,
            'point_2_breakout_confirmed'           => $point2Breakout,
            'point_2_retest_confirmed'             => $point2Retest,
            'pattern_123_entry_mode'               => $patternMode,
            'pattern_123_invalid_reason'           => $patternInvalid,
            'structure_breaks_count_after_point3'  => $breaksAfter,
            'structure_breaks_count_before_point3_ignored' => $breaksBeforeIgnored,
            'structure_break_check_start_idx'      => $point3Idx + 1,
            'structure_break_reference'            => 'point_3',
            'structure_break_tolerance_pct'        => $tolPct,
            'structure_breaks_count'               => $breaksAfter,
            'point_3_idx'                          => $point3Idx,
        ];
    }

    private function detectShort123FromRecentPoint3(array $candles, array $config): ?array
    {
        $this->backward123CheckedTotal++;
        $closes = array_column($candles, 'close');
        $highs  = array_column($candles, 'high');
        $lows   = array_column($candles, 'low');
        $n      = count($candles);
        $currentPrice = (float)($closes[$n - 1] ?? 0.0);
        if ($currentPrice <= 0.0 || $n < 10) {
            $this->backward123InvalidTotal++;
            return null;
        }

        $recentSearchMinutes = max(5, (int)($config['recent_point3_search_minutes'] ?? 30));
        $maxPoint3AgeMinutes = max(5, (int)($config['pattern_123_max_point3_age_minutes'] ?? 30));
        $maxEntryDistPct = (float)($config['pattern_123_max_entry_distance_from_point3_pct'] ?? 0.7);
        $currentTs = $this->normalizeCandleTimestampToSeconds((int)($candles[$n - 1]['ts'] ?? time()));
        $recentStartTs = $currentTs - ($recentSearchMinutes * 60);

        $candidatePoint3 = [];
        for ($i = $n - 3; $i >= 2; $i--) {
            $tsi = $this->normalizeCandleTimestampToSeconds((int)($candles[$i]['ts'] ?? 0));
            if ($tsi > 0 && $tsi < $recentStartTs) {
                break;
            }
            if ($this->isLocalSwingHigh($highs, $i)) {
                $candidatePoint3[] = $i;
            }
        }
        $this->recentPoint3CandidatesTotal += count($candidatePoint3);
        foreach (array_slice($candidatePoint3, 0, 3) as $idx) {
            if (count($this->point3CandidateExamples) >= 20) {
                break;
            }
            $point3Price = (float)($highs[$idx] ?? 0.0);
            $dist = $point3Price > 0.0 ? abs(($point3Price - $currentPrice) / $point3Price * 100.0) : null;
            $this->point3CandidateExamples[] = [
                'side' => 'short',
                'point_3_idx' => $idx,
                'point_3_price' => $point3Price > 0 ? round($point3Price, 8) : null,
                'point_3_time' => $this->formatCandleTimestamp((int)($candles[$idx]['ts'] ?? 0)),
                'point_3_distance_from_current_pct' => $dist !== null ? round($dist, 4) : null,
            ];
        }
        if (empty($candidatePoint3)) {
            $this->backward123InvalidTotal++;
            return null;
        }

        $selected = null;
        foreach ($candidatePoint3 as $point3Idx) {
            $point3Price = (float)$highs[$point3Idx];
            if ($point3Price <= 0.0 || $currentPrice >= $point3Price) {
                continue;
            }
            $point3Ts = $this->normalizeCandleTimestampToSeconds((int)($candles[$point3Idx]['ts'] ?? 0));
            $point3Age = $point3Ts > 0 ? max(0.0, ($currentTs - $point3Ts) / 60.0) : 9999.0;
            if ($point3Age > $maxPoint3AgeMinutes) {
                continue;
            }
            $entryDistPoint3Pct = (($point3Price - $currentPrice) / $point3Price) * 100.0;
            $entryNearPoint3 = $entryDistPoint3Pct <= $maxEntryDistPct;
            if ($entryNearPoint3) {
                $this->point3NearEntryTotal++;
            }
            $point3Turn = ($point3Idx < $n - 1)
                && ((float)$closes[$n - 1] < min((float)$closes[$point3Idx], (float)$closes[max(0, $point3Idx + 1)]));
            if ($point3Turn) {
                $this->point3TurnConfirmedTotal++;
            }
            if (!$entryNearPoint3) {
                continue;
            }
            $point2Idx = $this->findMinIndex($lows, 1, $point3Idx - 1);
            if ($point2Idx <= 1) {
                continue;
            }
            $this->point2FoundTotal++;
            $point1Idx = $this->findMaxIndex($highs, 1, $point2Idx - 1);
            if ($point1Idx < 0) {
                continue;
            }
            $this->point1FoundTotal++;
            $selected = [
                'point1Idx' => $point1Idx,
                'point2Idx' => $point2Idx,
                'point3Idx' => $point3Idx,
                'point3AgeMinutes' => $point3Age,
                'entryDistPoint3Pct' => $entryDistPoint3Pct,
                'point3Turn' => $point3Turn,
            ];
            break;
        }

        if ($selected === null) {
            $this->backward123InvalidTotal++;
            if (count($this->backward123InvalidExamples) < 16) {
                $this->backward123InvalidExamples[] = [
                    'side' => 'short',
                    'reason' => 'recent_point3_not_found_or_not_near_entry',
                    'symbol_context' => 'backward_from_recent_point3',
                ];
            }
            return null;
        }

        $point1Idx = (int)$selected['point1Idx'];
        $point2Idx = (int)$selected['point2Idx'];
        $point3Idx = (int)$selected['point3Idx'];
        $point1Price = (float)$highs[$point1Idx];
        $point2Price = (float)$lows[$point2Idx];
        $point3Price = (float)$highs[$point3Idx];
        $point3Holds = $point3Price < $point1Price;
        $point3Distance = $point1Price > 0 ? (($point1Price - $point3Price) / $point1Price * 100.0) : 0.0;
        $entryPrice = $currentPrice;

        $recentHigh = max(array_slice($highs, -5));
        $maxPullbackDepthPct = (float)($config['max_pullback_depth_pct'] ?? 2.0);
        $bouncePct = ($recentHigh - $currentPrice) / $currentPrice * 100.0;
        $bounceDetected = $bouncePct >= 0.1 && $bouncePct <= $maxPullbackDepthPct;
        if (($config['require_retest'] ?? true) && !$bounceDetected) {
            $this->backward123InvalidTotal++;
            return null;
        }

        $continuationPrice = (float)end($closes);
        $continuationConfirmed = $continuationPrice < $point3Price;
        if (($config['require_continuation_after_retest'] ?? true) && !$continuationConfirmed) {
            $this->backward123InvalidTotal++;
            return null;
        }

        $tolPct = (float)($config['structure_break_after_point3_tolerance_pct'] ?? 0.15);
        $upBreakLevel = $point3Price * (1.0 + ($tolPct / 100.0));
        $breaksAfter = 0;
        $breaksBeforeIgnored = 0;
        for ($i = 0; $i < $n; $i++) {
            $high = (float)$highs[$i];
            if ($high > $upBreakLevel) {
                if ($i > $point3Idx) {
                    $breaksAfter++;
                } else {
                    $breaksBeforeIgnored++;
                }
            }
        }
        $this->structureBreaksAfterPoint3Total += $breaksAfter;
        $this->structureBreaksBeforePoint3IgnoredTotal += $breaksBeforeIgnored;

        $entryDistancePct = (($point3Price - $entryPrice) / $point3Price) * 100.0;
        $entryNearPoint3 = ((float)$selected['entryDistPoint3Pct']) <= $maxEntryDistPct;
        $point3Turn = (bool)$selected['point3Turn'];
        $point2Breakout = $entryPrice < $point2Price;
        $point2Retest = $point2Price > 0 ? ($recentHigh >= $point2Price * 0.994 && $recentHigh <= $point2Price * 1.006) : false;
        $patternMode = $point2Breakout && $point2Retest ? 'conservative_point2_retest' : (($entryNearPoint3 && $point3Turn) ? 'aggressive_point3' : 'none');
        $patternDetected = $point1Idx < $point2Idx && $point2Idx < $point3Idx && $point2Price < $point1Price && $point3Price < $point1Price;
        $patternInvalid = null;
        if (!$patternDetected) {
            $patternInvalid = 'pattern_123_missing';
        } elseif (!$point3Holds) {
            $patternInvalid = 'point_3_breaks_point_1';
        } elseif ($point3Distance < (float)($config['pattern_123_min_point3_distance_from_point1_pct'] ?? 0.15)) {
            $patternInvalid = 'point_3_not_confirmed';
        } elseif (!$point3Turn && (bool)($config['pattern_123_require_point3_turn_confirmation'] ?? true)) {
            $patternInvalid = 'point_3_not_confirmed';
        } elseif (!$entryNearPoint3) {
            $patternInvalid = 'entry_too_far_from_point_3';
        }
        if ($patternInvalid === null) {
            $this->backward123ValidTotal++;
            if (count($this->backward123ValidExamples) < 16) {
                $this->backward123ValidExamples[] = [
                    'side' => 'short',
                    'point_1_price' => round($point1Price, 8),
                    'point_2_price' => round($point2Price, 8),
                    'point_3_price' => round($point3Price, 8),
                    'point_3_age_minutes' => round((float)$selected['point3AgeMinutes'], 2),
                ];
            }
        } else {
            $this->backward123InvalidTotal++;
            if (count($this->backward123InvalidExamples) < 16) {
                $this->backward123InvalidExamples[] = [
                    'side' => 'short',
                    'reason' => $patternInvalid,
                    'point_1_price' => round($point1Price, 8),
                    'point_2_price' => round($point2Price, 8),
                    'point_3_price' => round($point3Price, 8),
                ];
            }
        }

        return [
            'setup_class'                          => 'lower_high_retest_continuation_short',
            'side'                                 => 'short',
            'entry_price'                          => $entryPrice,
            'current_price'                        => $currentPrice,
            'lower_highs_count'                    => 2,
            'last_lower_high_price'                => $point3Price,
            'falling_resistance_price'             => $point3Price,
            'retest_price'                         => $recentHigh,
            'retest_held'                          => $bounceDetected,
            'continuation_breakdown_price'         => $continuationPrice,
            'entry_distance_from_last_lower_high_pct' => round($entryDistancePct, 4),
            'distance_from_falling_resistance_pct'    => round($entryDistancePct, 4),
            'distance_from_last_lower_high_pct'       => round($entryDistancePct, 4),
            'distance_from_rising_support_pct'        => null,
            'distance_from_last_higher_low_pct'       => null,
            'trend_phase'                          => 'confirmed_downtrend_continuation',
            'structure_holds'                      => $breaksAfter === 0,
            'bounce_detected'                      => $bounceDetected,
            'bounce_pct'                           => round($bouncePct, 4),
            'continuation_confirmed'               => $continuationConfirmed,
            'structure_anchor_type'                => 'point_1',
            'structure_anchor_idx'                 => $point1Idx,
            'structure_anchor_time'                => $this->formatCandleTimestamp((int)($candles[$point1Idx]['ts'] ?? 0)),
            'structure_anchor_price'               => round($point1Price, 8),
            'post_structure_window_start'          => max(0, $point1Idx),
            'post_structure_window_minutes'        => max(1, $n - $point1Idx),
            'pre_structure_impulse_pct'            => round($this->computeShortPreStructureImpulsePct($lows, $point1Idx, $point1Price), 4),
            'pre_structure_impulse_roi'            => round($this->computeShortPreStructureImpulsePct($lows, $point1Idx, $point1Price) * max(1.0, (float)($config['anti_comb_roi_equiv_leverage'] ?? 15.0)), 4),
            'pre_structure_impulse_allowed'        => true,
            'post_structure_controlled_trend_score'=> 0.0,
            'pattern_123_detected'                 => $patternDetected,
            'pattern_123_side'                     => 'short',
            'pattern_123_detection_direction'      => 'backward_from_recent_point3',
            'point_1_price'                        => round($point1Price, 8),
            'point_1_time'                         => $this->formatCandleTimestamp((int)($candles[$point1Idx]['ts'] ?? 0)),
            'point_2_price'                        => round($point2Price, 8),
            'point_2_time'                         => $this->formatCandleTimestamp((int)($candles[$point2Idx]['ts'] ?? 0)),
            'point_3_price'                        => round($point3Price, 8),
            'point_3_time'                         => $this->formatCandleTimestamp((int)($candles[$point3Idx]['ts'] ?? 0)),
            'point_3_holds_structure'              => $point3Holds,
            'point_3_distance_from_point_1_pct'    => round($point3Distance, 4),
            'point_3_age_minutes'                  => round((float)$selected['point3AgeMinutes'], 4),
            'point_3_distance_from_current_pct'    => round((float)$selected['entryDistPoint3Pct'], 4),
            'entry_near_point_3'                   => $entryNearPoint3,
            'entry_after_point_3_turn'             => $point3Turn,
            'point_2_breakout_confirmed'           => $point2Breakout,
            'point_2_retest_confirmed'             => $point2Retest,
            'pattern_123_entry_mode'               => $patternMode,
            'pattern_123_invalid_reason'           => $patternInvalid,
            'structure_breaks_count_after_point3'  => $breaksAfter,
            'structure_breaks_count_before_point3_ignored' => $breaksBeforeIgnored,
            'structure_break_check_start_idx'      => $point3Idx + 1,
            'structure_break_reference'            => 'point_3',
            'structure_break_tolerance_pct'        => $tolPct,
            'structure_breaks_count'               => $breaksAfter,
            'point_3_idx'                          => $point3Idx,
        ];
    }

    private function isLocalSwingLow(array $lows, int $idx): bool
    {
        if ($idx < 2 || $idx > count($lows) - 3) {
            return false;
        }
        return $lows[$idx] < $lows[$idx - 1]
            && $lows[$idx] < $lows[$idx + 1]
            && $lows[$idx] < $lows[$idx - 2]
            && $lows[$idx] < $lows[$idx + 2];
    }

    private function isLocalSwingHigh(array $highs, int $idx): bool
    {
        if ($idx < 2 || $idx > count($highs) - 3) {
            return false;
        }
        return $highs[$idx] > $highs[$idx - 1]
            && $highs[$idx] > $highs[$idx + 1]
            && $highs[$idx] > $highs[$idx - 2]
            && $highs[$idx] > $highs[$idx + 2];
    }

    private function findMaxIndex(array $values, int $start, int $end): int
    {
        if ($start > $end || $start < 0 || $end >= count($values)) {
            return -1;
        }
        $maxIdx = $start;
        $maxVal = (float)$values[$start];
        for ($i = $start + 1; $i <= $end; $i++) {
            $val = (float)$values[$i];
            if ($val > $maxVal) {
                $maxVal = $val;
                $maxIdx = $i;
            }
        }
        return $maxIdx;
    }

    private function findMinIndex(array $values, int $start, int $end): int
    {
        if ($start > $end || $start < 0 || $end >= count($values)) {
            return -1;
        }
        $minIdx = $start;
        $minVal = (float)$values[$start];
        for ($i = $start + 1; $i <= $end; $i++) {
            $val = (float)$values[$i];
            if ($val < $minVal) {
                $minVal = $val;
                $minIdx = $i;
            }
        }
        return $minIdx;
    }

    private function computeLongPreStructureImpulsePct(array $highs, int $point1Idx, float $point1Price): float
    {
        if ($point1Idx <= 0 || $point1Price <= 0.0) {
            return 0.0;
        }
        $preHigh = max(array_slice($highs, 0, $point1Idx + 1));
        if ($preHigh <= 0.0) {
            return 0.0;
        }
        return max(0.0, (($preHigh - $point1Price) / $preHigh) * 100.0);
    }

    private function computeShortPreStructureImpulsePct(array $lows, int $point1Idx, float $point1Price): float
    {
        if ($point1Idx <= 0 || $point1Price <= 0.0) {
            return 0.0;
        }
        $preLow = min(array_slice($lows, 0, $point1Idx + 1));
        if ($preLow <= 0.0) {
            return 0.0;
        }
        return max(0.0, (($point1Price - $preLow) / $preLow) * 100.0);
    }

    // ── Hard reject filters ────────────────────────────────────────────────────

    private function computeEntryTimingDiagnostics(array $structure, array $candles, string $side, array $config): array
    {
        $highs = array_column($candles, 'high');
        $lows  = array_column($candles, 'low');
        $n     = count($candles);
        $entry = (float)($structure['entry_price'] ?? 0.0);
        $maxDist = (float)($config['max_entry_distance_from_structure_pct'] ?? 0.6);
        $dist = $side === 'long'
            ? (float)($structure['entry_distance_from_last_higher_low_pct'] ?? 0.0)
            : (float)($structure['entry_distance_from_last_lower_high_pct'] ?? 0.0);

        $entryClass = 'late_extended_entry';
        if ($side === 'long') {
            if ($dist <= $maxDist * 0.5) {
                $entryClass = 'early_structure_entry';
            } elseif ($dist <= $maxDist) {
                $entryClass = 'mid_trend_structure_entry';
            }
        } else {
            if ($dist <= $maxDist * 0.5) {
                $entryClass = 'early_breakdown_entry';
            } elseif ($dist <= $maxDist) {
                $entryClass = 'mid_trend_breakdown_entry';
            }
        }

        $expectedExit = null;
        $upsideRoom = null;
        $downsideRoom = null;
        $nearTp = false;
        if ($entry > 0.0) {
            if ($side === 'long') {
                $expectedExit = !empty($highs) ? max(array_slice($highs, -min(20, max(3, $n)))) : null;
                if ($expectedExit !== null) {
                    $upsideRoom = max(0.0, ($expectedExit - $entry) / $entry * 100.0);
                    $nearTp = $upsideRoom <= (float)($config['min_upside_room_to_resistance_pct_long'] ?? 0.8);
                }
            } else {
                $expectedExit = !empty($lows) ? min(array_slice($lows, -min(20, max(3, $n)))) : null;
                if ($expectedExit !== null) {
                    $downsideRoom = max(0.0, ($entry - $expectedExit) / $entry * 100.0);
                    $nearTp = $downsideRoom <= (float)($config['min_downside_room_to_support_pct_short'] ?? 0.8);
                }
            }
        }

        return [
            'entry_timing_class' => $entryClass,
            'upside_room_to_resistance_pct' => $upsideRoom !== null ? round($upsideRoom, 4) : null,
            'downside_room_to_support_pct' => $downsideRoom !== null ? round($downsideRoom, 4) : null,
            'near_take_profit_zone' => $nearTp,
            'expected_exit_zone_price' => $expectedExit !== null ? round((float)$expectedExit, 8) : null,
            'late_entry_reject_reason' => $entryClass === 'late_extended_entry' ? 'late_extended_entry' : ($nearTp ? 'entry_near_take_profit_zone' : null),
        ];
    }

    /**
     * Returns ['reason' => string, 'stage' => string] or null if no hard reject.
     */
    private function checkHardRejects(array $structure, array $candles, string $side, array $config): ?array
    {
        $closes = array_column($candles, 'close');
        $highs  = array_column($candles, 'high');
        $lows   = array_column($candles, 'low');
        $n      = count($closes);

        $entryPrice = (float)($structure['entry_price'] ?? 0.0);
        if ($entryPrice <= 0) {
            return ['reason' => 'missing_entry_price', 'stage' => 'pre_filter'];
        }

        // Day-regime side-bias hard blocks
        if (($structure['day_regime_blocked'] ?? false) === true) {
            return [
                'reason' => (string)($structure['day_regime_reject_reason'] ?? 'day_regime_blocked'),
                'stage'  => 'day_regime',
            ];
        }

        // Anti-comb / anti-chaos hard blocks
        if (($structure['anti_comb_rejected'] ?? false) === true) {
            return [
                'reason' => (string)($structure['anti_comb_reject_reason'] ?? 'anti_comb_rejected'),
                'stage'  => 'anti_comb',
                'secondary_reject_reasons' => is_array($structure['secondary_reject_reasons'] ?? null)
                    ? array_values($structure['secondary_reject_reasons'])
                    : [],
            ];
        }

        // 1-2-3 structure gate (entry timing core)
        $this->pattern123CheckedTotal++;
        if ((bool)($config['pattern_123_enabled'] ?? true)) {
            $patternDetected = (bool)($structure['pattern_123_detected'] ?? false);
            if ((bool)($config['pattern_123_required_for_signal'] ?? true) && !$patternDetected) {
                $this->pattern123RejectedTotal++;
                $this->pattern123MissingTotal++;
                return ['reason' => 'pattern_123_missing', 'stage' => 'pattern_123'];
            }
            $point3Holds = (bool)($structure['point_3_holds_structure'] ?? false);
            if ($side === 'long' && !((bool)($config['pattern_123_require_point3_above_point1_long'] ?? true))) {
                $point3Holds = true;
            }
            if ($side === 'short' && !((bool)($config['pattern_123_require_point3_below_point1_short'] ?? true))) {
                $point3Holds = true;
            }
            if (!$point3Holds) {
                $this->pattern123RejectedTotal++;
                $this->point3BreaksPoint1Total++;
                return ['reason' => 'point_3_breaks_point_1', 'stage' => 'pattern_123'];
            }
            $point3Distance = (float)($structure['point_3_distance_from_point_1_pct'] ?? 0.0);
            if ($point3Distance < (float)($config['pattern_123_min_point3_distance_from_point1_pct'] ?? 0.15)) {
                $this->pattern123RejectedTotal++;
                $this->point3NotConfirmedTotal++;
                return ['reason' => 'point_3_not_confirmed', 'stage' => 'pattern_123'];
            }
            $entryNearPoint3 = (bool)($structure['entry_near_point_3'] ?? false);
            if (!$entryNearPoint3) {
                $this->pattern123RejectedTotal++;
                $this->entryTooFarFromPoint3Total++;
                return ['reason' => 'entry_too_far_from_point_3', 'stage' => 'pattern_123'];
            }
            $point3Turn = (bool)($structure['entry_after_point_3_turn'] ?? false);
            $point2Breakout = (bool)($structure['point_2_breakout_confirmed'] ?? false);
            $point2Retest = (bool)($structure['point_2_retest_confirmed'] ?? false);
            $allowAggressive = (bool)($config['pattern_123_allow_aggressive_point3_entry'] ?? true);
            $allowConservative = (bool)($config['pattern_123_allow_conservative_point2_retest_entry'] ?? true);
            $maxLateFromP2 = (float)($config['pattern_123_max_late_distance_from_point2_pct'] ?? 1.2);
            $point2 = (float)($structure['point_2_price'] ?? 0.0);
            $entry = (float)($structure['entry_price'] ?? 0.0);
            if ($point2 > 0.0 && $entry > 0.0) {
                $lateDist = $side === 'long'
                    ? (($entry - $point2) / $point2 * 100.0)
                    : (($point2 - $entry) / $point2 * 100.0);
                if ($lateDist > $maxLateFromP2) {
                    $this->pattern123RejectedTotal++;
                    return ['reason' => 'entry_too_late_after_point_2', 'stage' => 'pattern_123'];
                }
            }
            if ((bool)($config['pattern_123_require_point3_turn_confirmation'] ?? true) && !$point3Turn) {
                $this->pattern123RejectedTotal++;
                $this->point3NotConfirmedTotal++;
                return ['reason' => 'point_3_not_confirmed', 'stage' => 'pattern_123'];
            }
            $validAggressive = $allowAggressive && $point3Turn;
            $validConservative = $allowConservative && $point2Breakout && $point2Retest;
            if (!$validAggressive && !$validConservative) {
                $this->pattern123RejectedTotal++;
                return ['reason' => 'no_point_2_breakout_or_point_3_turn', 'stage' => 'pattern_123'];
            }
            $this->pattern123DetectedTotal++;
        }

        $this->entryTimingCheckedTotal++;
        $maxDist = (float)($config['max_entry_distance_from_structure_pct'] ?? 0.6);
        $maxLate = (float)($config['max_late_entry_extension_from_structure_pct'] ?? 1.2);
        $allowMid= (bool)($config['allow_mid_trend_entry'] ?? true);
        $requireRetestNear = (bool)($config['require_retest_near_structure'] ?? true);
        $rejectLate = (bool)($config['reject_late_extended_entry'] ?? true);

        // Reject: no structure higher lows / lower highs
        if ($side === 'long') {
            if (($structure['higher_lows_count'] ?? 0) < 2) {
                return ['reason' => 'no_higher_lows', 'stage' => 'first_bounce_or_no_structure'];
            }
            if (($structure['pullback_detected'] ?? false) === false) {
                return ['reason' => 'first_bounce_after_dump', 'stage' => 'first_bounce_or_no_structure'];
            }
            $distPct      = (float)($structure['entry_distance_from_last_higher_low_pct'] ?? 0);
            $entryClass = $distPct <= ($maxDist * 0.5) ? 'early_structure_entry'
                : ($distPct <= $maxDist ? 'mid_trend_structure_entry' : 'late_extended_entry');
            if (!$allowMid && $entryClass === 'mid_trend_structure_entry') {
                return ['reason' => 'entry_far_from_structure', 'stage' => 'entry_timing'];
            }
            if ($rejectLate && ($entryClass === 'late_extended_entry' || $distPct > $maxLate)) {
                return ['reason' => 'late_extended_entry', 'stage' => 'late_entry'];
            }
            if ($distPct > $maxDist) {
                return ['reason' => 'entry_too_far_above_structure', 'stage' => 'late_entry'];
            }
            // Reject: price already pumped too far from local base
            $maxExt = (float)($config['max_extension_from_local_base_pct'] ?? 12.0);
            $lastHLPrice = (float)($structure['last_higher_low_price'] ?? 0);
            if ($lastHLPrice > 0) {
                $extPct = ($entryPrice - $lastHLPrice) / $lastHLPrice * 100;
                if ($extPct > $maxExt) {
                    return ['reason' => 'too_extended_from_local_base', 'stage' => 'late_entry'];
                }
            }
            $expectedExit = max(array_slice($highs, -min(20, max(3, $n))));
            $upsideRoomPct = $entryPrice > 0 ? max(0.0, ($expectedExit - $entryPrice) / $entryPrice * 100.0) : 0.0;
            $minUpside = (float)($config['min_upside_room_to_resistance_pct_long'] ?? 0.8);
            if ($upsideRoomPct <= $minUpside) {
                if ($upsideRoomPct <= max(0.0, $minUpside * 0.5)) {
                    return ['reason' => 'entry_near_take_profit_zone', 'stage' => 'entry_timing'];
                }
                return ['reason' => 'insufficient_room_to_next_wall_or_resistance', 'stage' => 'entry_timing'];
            }
            // Reject: blowoff 1m candle at entry
            if ($n >= 2) {
                $lastClose = $closes[$n - 1];
                $prevClose = $closes[$n - 2];
                $candlePct = $prevClose > 0 ? abs($lastClose - $prevClose) / $prevClose * 100 : 0;
                if ($candlePct > (float)($config['max_1m_blowoff_pct'] ?? 2.0)) {
                    return ['reason' => 'blowoff_candle_at_entry', 'stage' => 'blowoff_reject'];
                }
            }
            // Reject: retest not held (structure broken)
            if (!($structure['retest_held'] ?? false) && ($config['require_retest'] ?? true || $requireRetestNear)) {
                return ['reason' => 'no_retest_near_structure', 'stage' => 'no_retest'];
            }
        } else {
            if (($structure['lower_highs_count'] ?? 0) < 2) {
                return ['reason' => 'no_lower_highs', 'stage' => 'first_dump_or_no_structure'];
            }
            if (($structure['bounce_detected'] ?? false) === false) {
                return ['reason' => 'first_dump_after_pump', 'stage' => 'first_dump_or_no_structure'];
            }
            $distPct = (float)($structure['entry_distance_from_last_lower_high_pct'] ?? 0);
            $entryClass = $distPct <= ($maxDist * 0.5) ? 'early_breakdown_entry'
                : ($distPct <= $maxDist ? 'mid_trend_breakdown_entry' : 'late_extended_entry');
            if (!$allowMid && $entryClass === 'mid_trend_breakdown_entry') {
                return ['reason' => 'entry_far_from_structure', 'stage' => 'entry_timing'];
            }
            if ($rejectLate && ($entryClass === 'late_extended_entry' || $distPct > $maxLate)) {
                return ['reason' => 'late_extended_entry', 'stage' => 'late_entry'];
            }
            if ($distPct > $maxDist) {
                return ['reason' => 'entry_too_far_below_structure', 'stage' => 'late_entry'];
            }
            $maxExt       = (float)($config['max_extension_from_local_base_pct'] ?? 12.0);
            $lastLHPrice  = (float)($structure['last_lower_high_price'] ?? 0);
            if ($lastLHPrice > 0) {
                $extPct = ($lastLHPrice - $entryPrice) / $lastLHPrice * 100;
                if ($extPct > $maxExt) {
                    return ['reason' => 'too_extended_from_breakdown_base', 'stage' => 'late_entry'];
                }
            }
            $expectedExit = min(array_slice($lows, -min(20, max(3, $n))));
            $downsideRoomPct = $entryPrice > 0 ? max(0.0, ($entryPrice - $expectedExit) / $entryPrice * 100.0) : 0.0;
            $minDownside = (float)($config['min_downside_room_to_support_pct_short'] ?? 0.8);
            if ($downsideRoomPct <= $minDownside) {
                if ($downsideRoomPct <= max(0.0, $minDownside * 0.5)) {
                    return ['reason' => 'entry_near_take_profit_zone', 'stage' => 'entry_timing'];
                }
                return ['reason' => 'insufficient_room_to_next_wall_or_resistance', 'stage' => 'entry_timing'];
            }
            if ($n >= 2) {
                $lastClose = $closes[$n - 1];
                $prevClose = $closes[$n - 2];
                $candlePct = $prevClose > 0 ? abs($lastClose - $prevClose) / $prevClose * 100 : 0;
                if ($candlePct > (float)($config['max_1m_blowoff_pct'] ?? 2.0)) {
                    return ['reason' => 'panic_dump_candle_at_entry', 'stage' => 'blowoff_reject'];
                }
            }
            if (!($structure['retest_held'] ?? false) && ($config['require_retest'] ?? true || $requireRetestNear)) {
                return ['reason' => 'no_retest_near_structure', 'stage' => 'no_retest'];
            }
        }

        return null;
    }

    // ── Scoring ────────────────────────────────────────────────────────────────

    private function scoreCandidate(array $structure, array $candles, string $side, array $config): array
    {
        $closes  = array_column($candles, 'close');
        $volumes = array_column($candles, 'volume');
        $n       = count($closes);

        // Structure score: based on number of higher lows / lower highs
        $structureCount  = ($side === 'long')
            ? (int)($structure['higher_lows_count'] ?? 2)
            : (int)($structure['lower_highs_count'] ?? 2);
        $structureScore  = min(1.0, ($structureCount - 1) / 4 + 0.5);

        // Retest score
        $retestScore = ($structure['retest_held'] ?? false) ? 0.85 : 0.40;

        // Continuation score
        $continuationScore = 0.5;
        if ($side === 'long') {
            $contPrice   = (float)($structure['continuation_reclaim_price'] ?? 0);
            $retestPrice = (float)($structure['retest_price'] ?? 0);
            if ($contPrice > 0 && $retestPrice > 0 && $contPrice > $retestPrice) {
                $contMoveAbs = ($contPrice - $retestPrice) / $retestPrice * 100;
                $continuationScore = min(1.0, 0.5 + $contMoveAbs * 0.1);
            }
        } else {
            $contPrice   = (float)($structure['continuation_breakdown_price'] ?? 0);
            $retestPrice = (float)($structure['retest_price'] ?? 0);
            if ($contPrice > 0 && $retestPrice > 0 && $contPrice < $retestPrice) {
                $contMoveAbs = ($retestPrice - $contPrice) / $retestPrice * 100;
                $continuationScore = min(1.0, 0.5 + $contMoveAbs * 0.1);
            }
        }

        // Entry distance score
        $distPct = ($side === 'long')
            ? (float)($structure['entry_distance_from_last_higher_low_pct'] ?? 99)
            : (float)($structure['entry_distance_from_last_lower_high_pct'] ?? 99);
        $maxDist  = (float)($config['max_entry_distance_from_structure_pct'] ?? 0.6);
        $entryDistanceScore = $maxDist > 0 ? max(0.0, min(1.0, 1.0 - $distPct / $maxDist)) : 0.5;

        // Pullback quality score
        $pullbackPct = ($side === 'long')
            ? (float)($structure['pullback_pct'] ?? 0)
            : (float)($structure['bounce_pct'] ?? 0);
        $maxPullback = (float)($config['max_pullback_depth_pct'] ?? 2.0);
        $pullbackQualityScore = $maxPullback > 0 ? max(0.0, min(1.0, 1.0 - ($pullbackPct / $maxPullback))) : 0.5;

        // Volume persistence score (simple: above-median volume in last 5 candles)
        $volumePersistenceScore = 0.5;
        if ($n >= 10) {
            $allVols    = array_slice($volumes, 0, $n - 5);
            $recentVols = array_slice($volumes, -5);
            if (!empty($allVols)) {
                sort($allVols);
                $medianVol = $allVols[(int)(count($allVols) / 2)] ?? 0;
                if ($medianVol > 0) {
                    $avgRecent  = array_sum($recentVols) / max(1, count($recentVols));
                    $volumePersistenceScore = min(1.0, $avgRecent / $medianVol * 0.5);
                }
            }
        }

        // OBC score placeholder (updated after OBC fetch)
        $obcScore = 0.5;
        $dayRegimeScore = (float)($structure['day_regime_score'] ?? 0.5);
        $controlledTrendScore = (float)($structure['controlled_trend_score'] ?? 0.5);

        $finalScore = round(
            $structureScore          * 0.23
            + $retestScore           * 0.20
            + $continuationScore     * 0.17
            + $entryDistanceScore    * 0.10
            + $pullbackQualityScore  * 0.05
            + $volumePersistenceScore* 0.05
            + $dayRegimeScore        * 0.10
            + $controlledTrendScore  * 0.05
            + $obcScore              * 0.05,
            4
        );

        return [
            'structure_score'         => round($structureScore,        4),
            'retest_score'            => round($retestScore,           4),
            'continuation_score'      => round($continuationScore,     4),
            'entry_distance_score'    => round($entryDistanceScore,    4),
            'pullback_quality_score'  => round($pullbackQualityScore,  4),
            'volume_persistence_score'=> round($volumePersistenceScore,4),
            'day_regime_score'        => round($dayRegimeScore,        4),
            'controlled_trend_score'  => round($controlledTrendScore,  4),
            'obc_score'               => round($obcScore,              4),
            'final_candidate_score'   => $finalScore,
            'candidate_quality_score' => $finalScore,
        ];
    }

    // ── OBC gate ───────────────────────────────────────────────────────────────

    private function applyObcGate(
        string $symbol,
        string $side,
        float  $entryPrice,
        array  $quality,
        array  $config
    ): array {
        $gateEnabled = (bool)($config['orderbook_wall_gate_enabled'] ?? true);
        $defaultResult = [
            'ob_wall_checked'       => false,
            'ob_wall_context'       => null,
            'ob_ask_wall_risk'      => false,
            'ob_bid_wall_risk'      => false,
            'ob_bid_wall_support'   => false,
            'ob_ask_wall_resistance'=> false,
            'ob_soft_demoted'       => false,
            'ob_gate_mode'          => $config['orderbook_wall_gate_mode'] ?? 'soft_demote',
            'ob_fetch_ok'           => false,
            'ob_ask_wall_distance_pct' => null,
            'ob_bid_wall_distance_pct' => null,
            'ob_ask_wall_status'    => null,
            'ob_bid_wall_status'    => null,
        ];

        if (!$gateEnabled || $this->obcService === null) {
            return $defaultResult;
        }
        if ($entryPrice <= 0.0) {
            return array_merge($defaultResult, ['ob_skip_reason' => 'missing_entry_price']);
        }

        $minQualityForFetch = (float)($config['orderbook_wall_fetch_after_score'] ?? 0.75);
        if ($quality['final_candidate_score'] < $minQualityForFetch) {
            return array_merge($defaultResult, ['ob_skip_reason' => 'quality_below_obc_threshold']);
        }

        $this->obcCheckedTotal++;

        try {
            $wallCtx = $this->obcService->getWallContext($symbol, $entryPrice);
            if ($wallCtx === null) {
                $this->obcFetchFailedTotal++;
                return array_merge($defaultResult, ['ob_fetch_ok' => false, 'ob_error' => true, 'ob_error_message' => 'null_context']);
            }
            $this->obcFetchSuccessTotal++;

            $nearPct       = (float)($config['orderbook_wall_near_pct'] ?? 1.2);
            $requirePersist= (bool)($config['orderbook_wall_persistent_required'] ?? true);
            $gateMode      = (string)($config['orderbook_wall_gate_mode'] ?? 'soft_demote');

            $askWall = $wallCtx['nearest_ask_wall'] ?? null;
            $bidWall = $wallCtx['nearest_bid_wall'] ?? null;

            $askDist    = null;
            $askStatus  = 'none';
            $askRisk    = false;
            $bidDist    = null;
            $bidStatus  = 'none';
            $bidSupport = false;

            if ($askWall && isset($askWall['price']) && $entryPrice > 0) {
                $askDist   = abs($askWall['price'] - $entryPrice) / $entryPrice * 100;
                $askStatus = $wallCtx['ask_wall_status'] ?? 'present';
                if ($askDist <= $nearPct) {
                    $isPersistent = (bool)($askWall['persistent'] ?? false);
                    if (!$requirePersist || $isPersistent) {
                        $askRisk = true;
                    }
                }
            }

            if ($bidWall && isset($bidWall['price']) && $entryPrice > 0) {
                $bidDist   = abs($entryPrice - $bidWall['price']) / $entryPrice * 100;
                $bidStatus = $wallCtx['bid_wall_status'] ?? 'present';
                if ($bidDist <= $nearPct) {
                    $isPersistent = (bool)($bidWall['persistent'] ?? false);
                    if (!$requirePersist || $isPersistent) {
                        $bidSupport = true;
                    }
                }
            }

            // LONG: ask wall above = risk; bid wall below = support
            // SHORT: bid wall below = risk; ask wall above = resistance
            $isSoftDemoted = false;
            $bidWallRisk   = false;
            $askWallRes    = false;

            if ($side === 'long') {
                $isSoftDemoted = $askRisk;
            } else {
                $bidWallRisk   = $bidSupport; // for short, nearby bid wall = risk
                $isSoftDemoted = $bidWallRisk;
                $askWallRes    = $askRisk;    // ask wall above = resistance (bonus for short)
            }

            if ($isSoftDemoted) {
                $this->obcSoftDemoteTotal++;
            }

            return [
                'ob_wall_checked'        => true,
                'ob_wall_context'        => $wallCtx,
                'ob_ask_wall_risk'       => $askRisk,
                'ob_bid_wall_risk'       => $bidWallRisk,
                'ob_bid_wall_support'    => $bidSupport && $side === 'long',
                'ob_ask_wall_resistance' => $askWallRes,
                'ob_soft_demoted'        => $isSoftDemoted,
                'ob_gate_mode'           => $gateMode,
                'ob_fetch_ok'            => true,
                'ob_ask_wall_distance_pct' => $askDist !== null ? round($askDist, 4) : null,
                'ob_bid_wall_distance_pct' => $bidDist !== null ? round($bidDist, 4) : null,
                'ob_ask_wall_status'     => $askStatus,
                'ob_bid_wall_status'     => $bidStatus,
            ];
        } catch (\Throwable $e) {
            $this->obcFetchFailedTotal++;
            return array_merge($defaultResult, [
                'ob_fetch_ok'      => false,
                'ob_error'         => true,
                'ob_error_message' => $e->getMessage(),
            ]);
        }
    }

    // ── Day-regime / anti-comb / wall-test helpers ────────────────────────────

    private function computeDayRegimeContext(string $symbol, string $side, array $candles, array $config): array
    {
        $this->dayRegimeCheckedTotal++;
        $ctx = [
            'day_change_pct'             => null,
            'day_high'                   => null,
            'day_low'                    => null,
            'distance_to_24h_high_pct'   => null,
            'distance_to_24h_low_pct'    => null,
            'day_regime_bias'            => 'neutral',
            'day_regime_reject_reason'   => null,
            'day_regime_blocked'         => false,
            'day_regime_score'           => 0.5,
        ];
        if (!(bool)($config['day_regime_filter_enabled'] ?? true)) {
            return $ctx;
        }

        $ticker = $this->fetchTicker24h($symbol, $config);
        if ($ticker === null) {
            return $ctx;
        }

        $dayChangePct = (float)($ticker['price24hPcnt'] ?? 0.0) * 100.0;
        $dayHigh      = (float)($ticker['highPrice24h'] ?? 0.0);
        $dayLow       = (float)($ticker['lowPrice24h'] ?? 0.0);
        $lastPrice    = (float)($ticker['lastPrice'] ?? 0.0);
        if ($lastPrice <= 0.0 && !empty($candles)) {
            $lastPrice = (float)($candles[count($candles) - 1]['close'] ?? 0.0);
        }

        $distHigh = ($dayHigh > 0 && $lastPrice > 0) ? (($dayHigh - $lastPrice) / $lastPrice * 100.0) : null;
        $distLow  = ($dayLow > 0 && $lastPrice > 0) ? (($lastPrice - $dayLow) / $lastPrice * 100.0) : null;

        $ctx['day_change_pct'] = round($dayChangePct, 4);
        $ctx['day_high'] = $dayHigh > 0 ? $dayHigh : null;
        $ctx['day_low']  = $dayLow > 0 ? $dayLow : null;
        $ctx['distance_to_24h_high_pct'] = $distHigh !== null ? round($distHigh, 4) : null;
        $ctx['distance_to_24h_low_pct']  = $distLow !== null ? round($distLow, 4) : null;

        $nearHigh = $distHigh !== null && $distHigh <= (float)($config['long_reject_near_24h_high_pct'] ?? 2.0);
        $nearLow  = $distLow !== null && $distLow <= (float)($config['short_reject_near_24h_low_pct'] ?? 2.0);

        if ($side === 'long') {
            $ctx['day_regime_bias'] = 'long';
            $this->dayRegimeLongBiasTotal++;
            $ctx['day_regime_score'] = ($dayChangePct <= (float)($config['long_prefer_24h_change_max_pct'] ?? 8.0)) ? 0.75 : 0.45;
            if (
                $dayChangePct >= (float)($config['long_reject_24h_change_above_pct'] ?? 18.0)
                && $nearHigh
            ) {
                $ctx['day_regime_blocked'] = true;
                $ctx['day_regime_bias'] = 'blocked';
                $ctx['day_regime_reject_reason'] = 'day_regime_long_near_24h_high_extension';
                $ctx['day_regime_score'] = 0.20;
            }
        } else {
            $ctx['day_regime_bias'] = 'short';
            $this->dayRegimeShortBiasTotal++;
            $ctx['day_regime_score'] = ($dayChangePct >= (float)($config['short_prefer_24h_change_min_pct'] ?? -8.0)) ? 0.75 : 0.45;
            if (
                $dayChangePct <= (float)($config['short_reject_24h_change_below_pct'] ?? -18.0)
                && $nearLow
            ) {
                $ctx['day_regime_blocked'] = true;
                $ctx['day_regime_bias'] = 'blocked';
                $ctx['day_regime_reject_reason'] = 'day_regime_short_near_24h_low_extension';
                $ctx['day_regime_score'] = 0.20;
            }
        }

        if ($ctx['day_regime_blocked']) {
            $this->dayRegimeBlockedTotal++;
        }
        return $ctx;
    }

    private function computeAntiCombDiagnostics(array $candles, string $side, array $structure, array $config, string $symbol = ''): array
    {
        $diag = [
            'recent_max_1m_range_pct'            => 0.0,
            'recent_max_1m_range_roi'            => 0.0,
            'recent_max_3m_range_pct'            => 0.0,
            'recent_max_3m_range_roi'            => 0.0,
            'recent_max_swing_pct'               => 0.0,
            'recent_max_swing_roi'               => 0.0,
            'recent_opposite_swing_pct'          => 0.0,
            'recent_opposite_swing_roi'          => 0.0,
            'directional_consistency_score'      => 1.0,
            'wick_chaos_score'                   => 0.0,
            'structure_breaks_count'             => 0,
            'anti_comb_max_structure_breaks'     => 0,
            'alternating_large_candles_detected' => false,
            'controlled_trend_score'             => 1.0,
            'post_structure_controlled_trend_score' => 1.0,
            'anti_comb_rejected'                 => false,
            'anti_comb_reject_reason'            => null,
            'anti_comb_reject_reason_detail'     => null,
            'primary_reject_reason'              => null,
            'secondary_reject_reasons'           => [],
            'post_structure_comb_detected'       => false,
            'anti_comb_window_mode'              => 'post_point1_and_post_point3',
            'structure_breaks_exceeded'          => false,
            'recent_swing_exceeded'              => false,
            'opposite_swing_exceeded'            => false,
            'range_1m_exceeded'                  => false,
            'range_3m_exceeded'                  => false,
            'wick_chaos_exceeded'                => false,
            'directional_consistency_failed'     => false,
            'structure_breaks_count_after_point3' => 0,
            'structure_breaks_count_before_point3_ignored' => 0,
            'structure_break_check_start_idx'    => null,
            'structure_break_reference'          => 'point_3',
            'structure_break_tolerance_pct'      => (float)($config['structure_break_after_point3_tolerance_pct'] ?? 0.15),
            'structure_window_range_roi'         => 0.0,
            'post_point3_range_roi'              => 0.0,
            'post_point3_opposite_swing_roi'     => 0.0,
            'post_point3_directional_consistency'=> 1.0,
            'post_point3_wick_chaos'             => 0.0,
            'smooth_trend_score'                 => 1.0,
            'step_count'                         => 0,
            'impulse_share'                      => 0.0,
            'single_candle_contribution'         => 0.0,
            'vertical_spike_detected'            => false,
            'pullback_before_entry_detected'     => false,
            'smooth_retest_confirmed'            => false,
        ];
        if (!(bool)($config['anti_comb_enabled'] ?? true)) {
            return $diag;
        }
        $this->antiCombCheckedTotal++;
        $this->postStructureFilterCheckedTotal++;
        $this->smoothTrendCheckedTotal++;

        $lookback = max(5, (int)($config['anti_comb_lookback_minutes'] ?? 20));
        $slice = array_slice($candles, -$lookback);
        $usePostStructureOnly = (bool)($config['post_structure_filter_enabled'] ?? true)
            && (bool)($config['ignore_pre_structure_impulse_for_anti_comb'] ?? true);
        $postStart = (int)($structure['post_structure_window_start'] ?? 0);
        $point1Idx = max(0, (int)($structure['structure_anchor_idx'] ?? $postStart));
        $point3Idx = (int)($structure['point_3_idx'] ?? -1);
        $structureWindow = ($point1Idx >= 0 && $point1Idx < count($candles))
            ? array_slice($candles, $point1Idx, max(0, ($point3Idx >= $point1Idx ? ($point3Idx - $point1Idx + 1) : count($candles) - $point1Idx)))
            : [];
        $postPoint3Slice = ($point3Idx >= 0 && $point3Idx < count($candles))
            ? array_slice($candles, $point3Idx)
            : [];
        if ($usePostStructureOnly && !empty($postPoint3Slice)) {
            $slice = $postPoint3Slice;
        } elseif ($usePostStructureOnly && $postStart > 0 && $postStart < count($candles)) {
            $slice = array_slice($candles, $postStart);
        }
        if (count($slice) < 5) {
            return $diag;
        }
        $opens = array_column($slice, 'open');
        $highs = array_column($slice, 'high');
        $lows  = array_column($slice, 'low');
        $closes= array_column($slice, 'close');

        $max1m = 0.0;
        $wickSamples = [];
        $dirGood = 0;
        $dirTotal = 0;
        $alternating = 0;
        $largeThr = 1.8;
        for ($i = 0; $i < count($slice); $i++) {
            $o = (float)$opens[$i];
            $h = (float)$highs[$i];
            $l = (float)$lows[$i];
            $c = (float)$closes[$i];
            if ($o > 0) {
                $r = abs($h - $l) / $o * 100.0;
                $max1m = max($max1m, $r);
                $body = abs($c - $o);
                $wick = max(0.0, ($h - $l) - $body);
                $wickSamples[] = ($h - $l) > 0 ? ($wick / ($h - $l)) : 0.0;
                $dirTotal++;
                if (($side === 'long' && $c >= $o) || ($side === 'short' && $c <= $o)) {
                    $dirGood++;
                }
                if ($i > 0) {
                    $po = (float)$opens[$i - 1];
                    $pc = (float)$closes[$i - 1];
                    if ($po > 0) {
                        $prevRange = abs((float)$highs[$i - 1] - (float)$lows[$i - 1]) / $po * 100.0;
                        if ($prevRange >= $largeThr && $r >= $largeThr) {
                            $prevUp = $pc >= $po;
                            $curUp  = $c >= $o;
                            if ($prevUp !== $curUp) {
                                $alternating++;
                            }
                        }
                    }
                }
            }
        }

        $max3m = 0.0;
        for ($i = 2; $i < count($slice); $i++) {
            $o = (float)$opens[$i - 2];
            if ($o <= 0) {
                continue;
            }
            $h = max((float)$highs[$i - 2], (float)$highs[$i - 1], (float)$highs[$i]);
            $l = min((float)$lows[$i - 2], (float)$lows[$i - 1], (float)$lows[$i]);
            $max3m = max($max3m, abs($h - $l) / $o * 100.0);
        }

        $swingHigh = max($highs);
        $swingLow  = min($lows);
        $base = (float)$closes[0];
        $recentSwing = ($base > 0) ? abs($swingHigh - $swingLow) / $base * 100.0 : 0.0;

        $oppSwing = 0.0;
        if ($side === 'long') {
            $oppSwing = ($base > 0) ? max(0.0, ($base - $swingLow) / $base * 100.0) : 0.0;
        } else {
            $oppSwing = ($base > 0) ? max(0.0, ($swingHigh - $base) / $base * 100.0) : 0.0;
        }

        $consistency = $dirTotal > 0 ? ($dirGood / $dirTotal) : 0.0;
        $wickChaos   = !empty($wickSamples) ? (array_sum($wickSamples) / count($wickSamples)) : 1.0;

        $structureBreaks = (int)($structure['structure_breaks_count_after_point3'] ?? $structure['structure_breaks_count'] ?? 0);
        $structureBreaksBeforeIgnored = (int)($structure['structure_breaks_count_before_point3_ignored'] ?? 0);

        $diag['recent_max_1m_range_pct'] = round($max1m, 4);
        $diag['recent_max_3m_range_pct'] = round($max3m, 4);
        $diag['recent_max_swing_pct'] = round($recentSwing, 4);
        $diag['recent_opposite_swing_pct'] = round($oppSwing, 4);

        $leverage = max(1.0, (float)($config['anti_comb_roi_equiv_leverage'] ?? 15.0));
        $diag['recent_max_1m_range_roi'] = round($max1m * $leverage, 4);
        $diag['recent_max_3m_range_roi'] = round($max3m * $leverage, 4);
        $diag['recent_max_swing_roi'] = round($recentSwing * $leverage, 4);
        $diag['recent_opposite_swing_roi'] = round($oppSwing * $leverage, 4);
        $diag['pre_structure_impulse_allowed'] = true;
        $diag['anti_comb_window_mode'] = 'post_point1_and_post_point3';

        $structureWindowRangePct = 0.0;
        if (!empty($structureWindow)) {
            $swOpens = array_column($structureWindow, 'open');
            $swHighs = array_column($structureWindow, 'high');
            $swLows  = array_column($structureWindow, 'low');
            $swBase  = (float)($swOpens[0] ?? 0.0);
            if ($swBase > 0.0 && !empty($swHighs) && !empty($swLows)) {
                $structureWindowRangePct = abs(max($swHighs) - min($swLows)) / $swBase * 100.0;
            }
        }
        $diag['structure_window_range_roi'] = round($structureWindowRangePct * $leverage, 4);

        $postPoint3RangePct = 0.0;
        $postPoint3OppSwingPct = 0.0;
        $postPoint3DirConsistency = 1.0;
        $postPoint3WickChaos = 0.0;
        if (count($postPoint3Slice) >= 2) {
            $ppOpens = array_column($postPoint3Slice, 'open');
            $ppHighs = array_column($postPoint3Slice, 'high');
            $ppLows  = array_column($postPoint3Slice, 'low');
            $ppCloses = array_column($postPoint3Slice, 'close');
            $ppBase = (float)($ppOpens[0] ?? 0.0);
            if ($ppBase > 0.0) {
                $postPoint3RangePct = abs(max($ppHighs) - min($ppLows)) / $ppBase * 100.0;
                if ($side === 'long') {
                    $postPoint3OppSwingPct = max(0.0, ($ppBase - min($ppLows)) / $ppBase * 100.0);
                } else {
                    $postPoint3OppSwingPct = max(0.0, (max($ppHighs) - $ppBase) / $ppBase * 100.0);
                }
            }
            $ppDirGood = 0;
            $ppDirTotal = 0;
            $ppWickSamples = [];
            for ($i = 0; $i < count($ppOpens); $i++) {
                $o = (float)$ppOpens[$i];
                $c = (float)$ppCloses[$i];
                $h = (float)$ppHighs[$i];
                $l = (float)$ppLows[$i];
                $ppDirTotal++;
                if (($side === 'long' && $c >= $o) || ($side === 'short' && $c <= $o)) {
                    $ppDirGood++;
                }
                $body = abs($c - $o);
                $range = max(0.0, $h - $l);
                $wick = max(0.0, $range - $body);
                $ppWickSamples[] = $range > 0.0 ? ($wick / $range) : 0.0;
            }
            $postPoint3DirConsistency = $ppDirTotal > 0 ? ($ppDirGood / $ppDirTotal) : 1.0;
            $postPoint3WickChaos = !empty($ppWickSamples) ? (array_sum($ppWickSamples) / count($ppWickSamples)) : 0.0;
        }
        $diag['post_point3_range_roi'] = round($postPoint3RangePct * $leverage, 4);
        $diag['post_point3_opposite_swing_roi'] = round($postPoint3OppSwingPct * $leverage, 4);
        $diag['post_point3_directional_consistency'] = round($postPoint3DirConsistency, 4);
        $diag['post_point3_wick_chaos'] = round($postPoint3WickChaos, 4);

        $diag['directional_consistency_score'] = round($consistency, 4);
        $diag['wick_chaos_score'] = round($wickChaos, 4);
        $diag['structure_breaks_count'] = $structureBreaks;
        $diag['structure_breaks_count_after_point3'] = $structureBreaks;
        $diag['structure_breaks_count_before_point3_ignored'] = $structureBreaksBeforeIgnored;
        $diag['structure_break_check_start_idx'] = $structure['structure_break_check_start_idx'] ?? null;
        $diag['structure_break_reference'] = 'point_3';
        $diag['structure_break_tolerance_pct'] = (float)($structure['structure_break_tolerance_pct'] ?? ($config['structure_break_after_point3_tolerance_pct'] ?? 0.15));
        $diag['anti_comb_max_structure_breaks'] = (int)($config['anti_comb_max_structure_breaks'] ?? 1);
        $diag['alternating_large_candles_detected'] = $alternating > 0;

        $controlledTrendScore = 1.0;
        $controlledTrendScore -= min(0.35, $diag['recent_max_1m_range_roi'] / max(1.0, (float)($config['anti_comb_max_1m_range_roi'] ?? 18.0)) * 0.35);
        $controlledTrendScore -= min(0.25, max(0.0, $wickChaos - 0.2));
        $controlledTrendScore -= min(0.20, max(0.0, 0.8 - $consistency));
        $controlledTrendScore -= min(0.20, $structureBreaks * 0.10);
        $diag['controlled_trend_score'] = round(max(0.0, min(1.0, $controlledTrendScore)), 4);
        $diag['post_structure_controlled_trend_score'] = $diag['controlled_trend_score'];

        $max1mRoi = (float)($config['anti_comb_max_1m_range_roi'] ?? 18.0);
        $max3mRoi = (float)($config['anti_comb_max_3m_range_roi'] ?? 30.0);
        $maxRecentSwingRoi = (float)($config['anti_comb_max_recent_swing_roi'] ?? 35.0);
        $maxOppSwingRoi = (float)($config['anti_comb_max_opposite_swing_roi'] ?? 25.0);
        $maxWickChaos = (float)($config['anti_comb_max_wick_chaos_score'] ?? 0.55);
        $minDirectionalConsistency = (float)($config['anti_comb_min_directional_consistency'] ?? 0.62);
        $maxStructureBreaks = (int)($config['anti_comb_max_structure_breaks'] ?? 1);

        $diag['range_1m_exceeded'] = $diag['recent_max_1m_range_roi'] > $max1mRoi;
        $diag['range_3m_exceeded'] = $diag['recent_max_3m_range_roi'] > $max3mRoi;
        $diag['recent_swing_exceeded'] = $diag['recent_max_swing_roi'] > $maxRecentSwingRoi;
        $diag['opposite_swing_exceeded'] = $diag['recent_opposite_swing_roi'] > $maxOppSwingRoi;
        $diag['wick_chaos_exceeded'] = $wickChaos > $maxWickChaos;
        $diag['directional_consistency_failed'] = $consistency < $minDirectionalConsistency;
        $diag['structure_breaks_exceeded'] = $structureBreaks > $maxStructureBreaks;

        // Smooth stair-step trend diagnostics (post-structure segment)
        $entryPrice = (float)($structure['entry_price'] ?? 0.0);
        $segmentStart = (float)($closes[0] ?? 0.0);
        $segmentEnd   = (float)($closes[count($closes) - 1] ?? 0.0);
        $totalMoveAbs = abs($segmentEnd - $segmentStart);
        $maxBodyAbs = 0.0;
        $maxRangeAbs = 0.0;
        $stepCount = 0;
        for ($i = 1; $i < count($closes); $i++) {
            $move = (float)$closes[$i] - (float)$closes[$i - 1];
            if (($side === 'long' && $move > 0) || ($side === 'short' && $move < 0)) {
                $stepCount++;
            }
            $bodyAbs = abs((float)$closes[$i] - (float)$opens[$i]);
            $rangeAbs = abs((float)$highs[$i] - (float)$lows[$i]);
            $maxBodyAbs = max($maxBodyAbs, $bodyAbs);
            $maxRangeAbs = max($maxRangeAbs, $rangeAbs);
        }
        $impulseShare = $totalMoveAbs > 0 ? min(1.0, $maxBodyAbs / $totalMoveAbs) : 1.0;
        $singleContribution = $totalMoveAbs > 0 ? min(1.0, $maxRangeAbs / max($totalMoveAbs, 1e-9)) : 1.0;
        $verticalSpike = $diag['recent_max_1m_range_roi'] > (float)($config['max_1m_range_roi_for_signal'] ?? 10.0);
        $pullbackDetected = $side === 'long'
            ? (bool)($structure['pullback_detected'] ?? false)
            : (bool)($structure['bounce_detected'] ?? false);
        $smoothRetest = (bool)($structure['retest_held'] ?? false);
        $smoothScore = max(
            0.0,
            min(
                1.0,
                0.35 * $diag['directional_consistency_score']
                + 0.25 * (1.0 - min(1.0, $impulseShare))
                + 0.20 * (1.0 - min(1.0, $singleContribution))
                + 0.20 * min(1.0, $stepCount / max(1.0, (float)($config['smooth_trend_min_step_count'] ?? 2)))
            )
        );
        $diag['smooth_trend_score'] = round($smoothScore, 4);
        $diag['step_count'] = (int)$stepCount;
        $diag['impulse_share'] = round($impulseShare, 4);
        $diag['single_candle_contribution'] = round($singleContribution, 4);
        $diag['vertical_spike_detected'] = $verticalSpike;
        $diag['pullback_before_entry_detected'] = $pullbackDetected;
        $diag['smooth_retest_confirmed'] = $smoothRetest;

        $rejectReason = null;
        $secondaryReasons = [];

        if ($diag['range_1m_exceeded'] || $diag['range_3m_exceeded']) {
            $rejectReason = 'anti_comb_recent_range_too_high';
            $this->antiCombRecentRangeRejectTotal++;
        }
        if ($diag['recent_swing_exceeded']) {
            if ($rejectReason === null) {
                $rejectReason = 'anti_comb_recent_swing_too_high';
            } else {
                $secondaryReasons[] = 'anti_comb_recent_swing_too_high';
            }
            $this->antiCombRecentSwingRejectTotal++;
        }
        if ($diag['opposite_swing_exceeded']) {
            if ($rejectReason === null) {
                $rejectReason = 'anti_comb_opposite_swing_too_high';
            } else {
                $secondaryReasons[] = 'anti_comb_opposite_swing_too_high';
            }
            $this->antiCombOppositeSwingRejectTotal++;
        }
        if ($diag['wick_chaos_exceeded']) {
            if ($rejectReason === null) {
                $rejectReason = 'anti_comb_wick_chaos';
            } else {
                $secondaryReasons[] = 'anti_comb_wick_chaos';
            }
            $this->antiCombWickChaosRejectTotal++;
        }
        if ($diag['directional_consistency_failed']) {
            if ($rejectReason === null) {
                $rejectReason = 'anti_comb_low_directional_consistency';
            } else {
                $secondaryReasons[] = 'anti_comb_low_directional_consistency';
            }
            $this->antiCombLowConsistencyRejectTotal++;
        }
        if ($diag['structure_breaks_exceeded']) {
            if ($rejectReason === null) {
                $rejectReason = 'anti_comb_too_many_structure_breaks';
            } else {
                $secondaryReasons[] = 'anti_comb_too_many_structure_breaks';
            }
            $this->antiCombStructureBreaksRejectTotal++;
        }
        if (($config['anti_comb_reject_if_alternating_large_candles'] ?? true) && $alternating > 0) {
            if ($rejectReason === null) {
                $rejectReason = 'anti_comb_alternating_large_candles';
            } else {
                $secondaryReasons[] = 'anti_comb_alternating_large_candles';
            }
            $this->antiCombAlternatingCandlesRejectTotal++;
        }

        if ((bool)($config['smooth_trend_filter_enabled'] ?? true)) {
            if ($diag['smooth_trend_score'] < (float)($config['smooth_trend_min_score'] ?? 0.75)) {
                if ($rejectReason === null) {
                    $rejectReason = 'smooth_trend_score_too_low';
                    $this->smoothTrendScoreTooLowTotal++;
                } else {
                    $secondaryReasons[] = 'smooth_trend_score_too_low';
                }
                $this->smoothTrendRejectedTotal++;
            } elseif ((bool)($config['smooth_trend_reject_vertical_spike'] ?? true) && $diag['vertical_spike_detected']) {
                if ($rejectReason === null) {
                    $rejectReason = 'vertical_spike_reject';
                } else {
                    $secondaryReasons[] = 'vertical_spike_reject';
                }
                $this->smoothTrendRejectedTotal++;
                $this->verticalSpikeRejectedTotal++;
            } elseif ($diag['single_candle_contribution'] > (float)($config['smooth_trend_max_single_candle_contribution'] ?? 0.45)) {
                if ($rejectReason === null) {
                    $rejectReason = 'single_candle_dominates_move';
                } else {
                    $secondaryReasons[] = 'single_candle_dominates_move';
                }
                $this->smoothTrendRejectedTotal++;
            } elseif ($diag['impulse_share'] > (float)($config['smooth_trend_max_impulse_share'] ?? 0.55)) {
                if ($rejectReason === null) {
                    $rejectReason = 'impulse_share_too_high';
                } else {
                    $secondaryReasons[] = 'impulse_share_too_high';
                }
                $this->smoothTrendRejectedTotal++;
            } elseif ((bool)($config['smooth_trend_require_pullback_before_entry'] ?? true) && !$diag['pullback_before_entry_detected']) {
                if ($rejectReason === null) {
                    $rejectReason = 'no_controlled_pullback_before_entry';
                } else {
                    $secondaryReasons[] = 'no_controlled_pullback_before_entry';
                }
                $this->smoothTrendRejectedTotal++;
            } elseif ($diag['step_count'] < (int)($config['smooth_trend_min_step_count'] ?? 2)) {
                if ($rejectReason === null) {
                    $rejectReason = 'post_structure_single_candle_dominates';
                } else {
                    $secondaryReasons[] = 'post_structure_single_candle_dominates';
                }
                $this->smoothTrendRejectedTotal++;
            } elseif ($diag['directional_consistency_score'] < (float)($config['anti_comb_min_directional_consistency'] ?? 0.72)) {
                if ($rejectReason === null) {
                    $rejectReason = 'post_structure_directional_consistency_too_low';
                } else {
                    $secondaryReasons[] = 'post_structure_directional_consistency_too_low';
                }
                $this->smoothTrendRejectedTotal++;
            }
        }

        if ($rejectReason !== null) {
            $secondaryReasons = array_filter(
                $secondaryReasons,
                static fn ($v): bool => is_string($v) && $v !== ''
            );
            $secondaryReasons = array_values(array_unique(array_values($secondaryReasons)));
            $diag['anti_comb_rejected'] = true;
            $diag['anti_comb_reject_reason_detail'] = $rejectReason;
            // Always use the specific reason as the primary reject_reason.
            // post_structure_comb_detected is a boolean context field, NOT the reject reason.
            $diag['anti_comb_reject_reason'] = $rejectReason;
            $diag['primary_reject_reason'] = $rejectReason;
            $diag['secondary_reject_reasons'] = $secondaryReasons;
            if ($usePostStructureOnly) {
                $diag['post_structure_comb_detected'] = true;
                $this->postStructureFilterRejectedTotal++;
                $this->postStructureCombDetectedTotal++;
            } else {
                $diag['post_structure_comb_detected'] = false;
            }
            $this->antiCombRejectedTotal++;
            if (count($this->antiCombExamples) < 8) {
                $this->antiCombExamples[] = $this->buildFullDiagExample($symbol, $side, array_merge($structure, $diag), $rejectReason, 'anti_comb');
            }
            if (count($this->rejectedCombExamples) < 8) {
                $this->rejectedCombExamples[] = $this->buildFullDiagExample($symbol, $side, array_merge($structure, $diag), $rejectReason, 'anti_comb');
            }
        } else {
            $diag['post_structure_comb_detected'] = false;
        }

        return $diag;
    }

    private function computeWallDecisionTest(string $side, array $structure, array $obcResult, array $config): array
    {
        $res = [
            'wall_test_state' => 'none',
            'wall_test_level' => null,
            'wall_test_started_at' => null,
            'wall_test_result' => null,
            'wall_test_block_reason' => null,
            'wall_test_opposite_context_created' => false,
            'wall_test_blocked' => false,
        ];
        if (!(bool)($config['wall_decision_test_enabled'] ?? true)) {
            return $res;
        }

        $now = date('c');
        $nearWallPct = (float)($config['wall_test_near_wall_pct'] ?? 0.35);
        $blockNewWalls = (bool)($config['wall_test_block_new_walls'] ?? true);
        if ($side === 'long') {
            $risk = (bool)($obcResult['ob_ask_wall_risk'] ?? false);
            $status = (string)($obcResult['ob_ask_wall_status'] ?? 'none');
            $askDist = (float)($obcResult['ob_ask_wall_distance_pct'] ?? 999.0);
            $res['wall_test_level'] = $structure['rising_support_price'] ?? $structure['entry_price'] ?? null;
            if (($risk || $askDist <= $nearWallPct) && !in_array($status, ['eaten', 'broken'], true) && $blockNewWalls) {
                $res['wall_test_state'] = 'pending_breakout';
                $res['wall_test_started_at'] = $now;
                $res['wall_test_result'] = 'pending';
                $res['wall_test_block_reason'] = 'pending_ask_wall_breakout_test';
                $res['wall_test_blocked'] = true;
                $this->wallTestPendingTotal++;
            } elseif (in_array($status, ['eaten', 'broken'], true)) {
                $res['wall_test_state'] = 'breakout_confirmed';
                $res['wall_test_result'] = 'breakout_confirmed';
                $this->wallTestConfirmedTotal++;
            } elseif (in_array($status, ['rejected', 'holding'], true) && $risk) {
                $res['wall_test_state'] = 'rejected';
                $res['wall_test_result'] = 'rejected';
                $res['wall_test_block_reason'] = 'long_wall_rejection';
                $res['wall_test_opposite_context_created'] = (bool)($config['wall_rejection_can_create_opposite_context'] ?? true);
                $res['wall_test_blocked'] = true;
                $this->wallTestRejectedTotal++;
                if ($res['wall_test_opposite_context_created']) {
                    $this->wallTestOppositeContextTotal++;
                }
            }
        } else {
            $risk = (bool)($obcResult['ob_bid_wall_risk'] ?? false);
            $status = (string)($obcResult['ob_bid_wall_status'] ?? 'none');
            $bidDist = (float)($obcResult['ob_bid_wall_distance_pct'] ?? 999.0);
            $res['wall_test_level'] = $structure['falling_resistance_price'] ?? $structure['entry_price'] ?? null;
            if (($risk || $bidDist <= $nearWallPct) && !in_array($status, ['eaten', 'broken'], true) && $blockNewWalls) {
                $res['wall_test_state'] = 'pending_breakdown';
                $res['wall_test_started_at'] = $now;
                $res['wall_test_result'] = 'pending';
                $res['wall_test_block_reason'] = 'pending_bid_wall_breakdown_test';
                $res['wall_test_blocked'] = true;
                $this->wallTestPendingTotal++;
            } elseif (in_array($status, ['eaten', 'broken'], true)) {
                $res['wall_test_state'] = 'breakdown_confirmed';
                $res['wall_test_result'] = 'breakdown_confirmed';
                $this->wallTestConfirmedTotal++;
            } elseif (in_array($status, ['rejected', 'holding'], true) && $risk) {
                $res['wall_test_state'] = 'rejected';
                $res['wall_test_result'] = 'rejected';
                $res['wall_test_block_reason'] = 'short_wall_rejection';
                $res['wall_test_opposite_context_created'] = (bool)($config['wall_rejection_can_create_opposite_context'] ?? true);
                $res['wall_test_blocked'] = true;
                $this->wallTestRejectedTotal++;
                if ($res['wall_test_opposite_context_created']) {
                    $this->wallTestOppositeContextTotal++;
                }
            }
        }

        if ($res['wall_test_state'] !== 'none' && count($this->wallTestExamples) < 8) {
            $this->wallTestExamples[] = $res;
        }
        return $res;
    }

    private function fetchTicker24h(string $symbol, array $config): ?array
    {
        $baseUrl = rtrim((string)($config['bybit_base_url'] ?? 'https://api.bybit.com'), '/');
        $timeout = max(3, (int)($config['bybit_timeout_sec'] ?? 6));
        $url = $baseUrl . '/v5/market/tickers?' . http_build_query([
            'category' => 'linear',
            'symbol'   => $symbol,
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        $list = $decoded['result']['list'] ?? null;
        if (!is_array($list) || empty($list[0]) || !is_array($list[0])) {
            return null;
        }
        return $list[0];
    }

    // ── Candidate and signal builders ──────────────────────────────────────────

    /**
     * Build a full diagnostic example record for last_run example buckets.
     * Used by anti-comb, 1-2-3 valid-but-rejected, and comb example buckets.
     */
    private function buildFullDiagExample(
        string $symbol,
        string $side,
        array  $structure,
        string $rejectReason,
        string $failedStage
    ): array {
        return [
            'symbol'                          => $symbol,
            'side'                            => $side,
            'failed_stage'                    => $failedStage,
            'reject_reason'                   => $rejectReason,
            'primary_reject_reason'           => $structure['primary_reject_reason'] ?? $rejectReason,
            'secondary_reject_reasons'        => is_array($structure['secondary_reject_reasons'] ?? null) ? array_values($structure['secondary_reject_reasons']) : [],
            'candidate_state'                 => 'rejected',
            'pattern_123_detected'            => (bool)($structure['pattern_123_detected'] ?? false),
            'pattern_123_detection_direction' => $structure['pattern_123_detection_direction'] ?? null,
            'pattern_123_entry_mode'          => $structure['pattern_123_entry_mode'] ?? 'none',
            'pattern_123_invalid_reason'      => $structure['pattern_123_invalid_reason'] ?? null,
            'point_1_price'                   => $structure['point_1_price'] ?? null,
            'point_2_price'                   => $structure['point_2_price'] ?? null,
            'point_3_price'                   => $structure['point_3_price'] ?? null,
            'point_3_age_minutes'             => $structure['point_3_age_minutes'] ?? null,
            'point_3_distance_from_current_pct' => $structure['point_3_distance_from_current_pct'] ?? null,
            'entry_near_point_3'              => $structure['entry_near_point_3'] ?? null,
            'entry_after_point_3_turn'        => $structure['entry_after_point_3_turn'] ?? null,
            'entry_timing_class'              => $structure['entry_timing_class'] ?? null,
            'controlled_trend_score'          => $structure['controlled_trend_score'] ?? null,
            'smooth_trend_score'              => $structure['smooth_trend_score'] ?? null,
            'directional_consistency_score'   => $structure['directional_consistency_score'] ?? null,
            'wick_chaos_score'                => $structure['wick_chaos_score'] ?? null,
            'recent_max_1m_range_roi'         => $structure['recent_max_1m_range_roi'] ?? null,
            'recent_max_3m_range_roi'         => $structure['recent_max_3m_range_roi'] ?? null,
            'recent_max_swing_roi'            => $structure['recent_max_swing_roi'] ?? null,
            'recent_opposite_swing_roi'       => $structure['recent_opposite_swing_roi'] ?? null,
            'structure_breaks_count'          => $structure['structure_breaks_count'] ?? null,
            'anti_comb_max_structure_breaks'  => $structure['anti_comb_max_structure_breaks'] ?? null,
            'structure_breaks_exceeded'       => (bool)($structure['structure_breaks_exceeded'] ?? false),
            'recent_swing_exceeded'           => (bool)($structure['recent_swing_exceeded'] ?? false),
            'opposite_swing_exceeded'         => (bool)($structure['opposite_swing_exceeded'] ?? false),
            'range_1m_exceeded'               => (bool)($structure['range_1m_exceeded'] ?? false),
            'range_3m_exceeded'               => (bool)($structure['range_3m_exceeded'] ?? false),
            'wick_chaos_exceeded'             => (bool)($structure['wick_chaos_exceeded'] ?? false),
            'directional_consistency_failed'  => (bool)($structure['directional_consistency_failed'] ?? false),
            'anti_comb_reject_reason'         => $structure['anti_comb_reject_reason'] ?? null,
            'post_structure_comb_detected'    => (bool)($structure['post_structure_comb_detected'] ?? false),
            'candle_source_used'              => $structure['candle_source_used'] ?? null,
        ];
    }

    private function buildCandidate(
        string $symbol,
        string $side,
        array  $structure,
        array  $quality,
        array  $obcResult,
        array  $config
    ): array {
        $softDemoteBlocksHandoff = (bool)($config['orderbook_wall_soft_demote_blocks_handoff'] ?? true);
        $isSoftDemoted           = (bool)($obcResult['ob_soft_demoted'] ?? false);
        $handoffEnabled          = (bool)($config['handoff_enabled'] ?? true);

        $blockReason    = null;
        $handoffReady   = true;
        $executable     = true;

        // Upstream hard blocks (day regime / anti-comb / wall test pending/rejected)
        if (($structure['day_regime_blocked'] ?? false) === true) {
            $blockReason  = (string)($structure['day_regime_reject_reason'] ?? 'day_regime_blocked');
            $handoffReady = false;
            $executable   = false;
        }
        if (($structure['anti_comb_rejected'] ?? false) === true) {
            $blockReason  = (string)($structure['anti_comb_reject_reason'] ?? 'anti_comb_rejected');
            $handoffReady = false;
            $executable   = false;
        }
        if (($structure['wall_test_blocked'] ?? false) === true) {
            $blockReason  = (string)($structure['wall_test_block_reason'] ?? 'wall_test_pending');
            $handoffReady = false;
            $executable   = false;
            if (str_starts_with((string)$blockReason, 'pending_')) {
                $this->wallPendingBlockedTotal++;
                if (count($this->wallPendingExamples) < 8) {
                    $this->wallPendingExamples[] = [
                        'symbol' => $symbol,
                        'side' => $side,
                        'wall_test_state' => $structure['wall_test_state'] ?? 'none',
                        'block_reason' => $blockReason,
                    ];
                }
            }
        }

        if ($isSoftDemoted && $softDemoteBlocksHandoff) {
            $blockReason  = 'ob_soft_demote_wall_risk';
            $handoffReady = false;
            $executable   = false;
            $this->obcSoftDemoteBlockedHandoff++;
            if (count($this->obcBlockExamples) < 5) {
                $this->obcBlockExamples[] = [
                    'symbol' => $symbol,
                    'side'   => $side,
                    'block_reason' => $blockReason,
                ];
            }
        } elseif ($isSoftDemoted) {
            // soft_demote annotation only — does not block
            $this->obcSoftDemoteAllowedHandoff++;
        }

        if (!$handoffEnabled) {
            $executable   = false;
            $handoffReady = false;
        }

        // Hard controlled-trend gate before handoff/executable
        $this->controlledTrendGateCheckedTotal++;
        if ($handoffReady && $executable) {
            $controlledTrend = (float)($structure['controlled_trend_score'] ?? 0.0);
            $dirConsistency  = (float)($structure['directional_consistency_score'] ?? 0.0);
            $wickChaos       = (float)($structure['wick_chaos_score'] ?? 1.0);
            $structureBreaks = (int)($structure['structure_breaks_count'] ?? 0);
            $recentSwingRoi  = (float)($structure['recent_max_swing_roi'] ?? 0.0);
            $oppSwingRoi     = (float)($structure['recent_opposite_swing_roi'] ?? 0.0);
            $r1m             = (float)($structure['recent_max_1m_range_roi'] ?? 0.0);
            $r3m             = (float)($structure['recent_max_3m_range_roi'] ?? 0.0);

            if ($controlledTrend < (float)($config['min_controlled_trend_score_for_signal'] ?? 0.78)) {
                $blockReason = 'controlled_trend_score_too_low';
                $this->controlledTrendScoreTooLowTotal++;
            } elseif ($dirConsistency < (float)($config['min_directional_consistency_for_signal'] ?? 0.72)) {
                $blockReason = 'directional_consistency_too_low';
                $this->directionalConsistencyTooLowTotal++;
            } elseif ($wickChaos > (float)($config['max_wick_chaos_for_signal'] ?? 0.35)) {
                $blockReason = 'wick_chaos_too_high';
                $this->wickChaosTooHighTotal++;
            } elseif ($structureBreaks > (int)($config['max_structure_breaks_for_signal'] ?? 0)) {
                $blockReason = 'structure_breaks_not_allowed';
                $this->structureBreaksNotAllowedTotal++;
            } elseif ($recentSwingRoi > (float)($config['max_recent_swing_roi_for_signal'] ?? 18.0)) {
                $blockReason = 'recent_swing_too_high';
                $this->recentSwingTooHighTotal++;
            } elseif ($oppSwingRoi > (float)($config['max_opposite_swing_roi_for_signal'] ?? 8.0)) {
                $blockReason = 'opposite_swing_too_high';
                $this->oppositeSwingTooHighTotal++;
            } elseif (
                $r1m > (float)($config['max_1m_range_roi_for_signal'] ?? 10.0)
                || $r3m > (float)($config['max_3m_range_roi_for_signal'] ?? 18.0)
            ) {
                $blockReason = 'short_range_too_volatile';
            }
            if ($blockReason !== null) {
                $handoffReady = false;
                $executable = false;
                $this->controlledTrendGateRejectedTotal++;
            }
        }

        $detectedAt = date('c');

        // Compute stable idea key for candidate (same formula as in buildSignal)
        $structureLevel = ($side === 'long')
            ? (float)($structure['last_higher_low_price'] ?? 0)
            : (float)($structure['last_lower_high_price'] ?? 0);
        $timeBucket = $this->getIdeaKeyTimeBucket();
        $ideaKey = $symbol
            . ':' . $side
            . ':' . ($structure['setup_class'] ?? '')
            . ':' . round($structureLevel, 6)
            . ':' . $timeBucket;

        $candidate  = array_merge($structure, $quality, $obcResult, [
            'strategy_id'                     => 'confirmed_continuation',
            'confirmed_continuation_idea_key' => $ideaKey,
            'symbol'         => $symbol,
            'side'           => $side,
            'detected_at'    => $detectedAt,
            'refreshed_at'   => $detectedAt,
            'active_final'   => $handoffReady && (!$isSoftDemoted || !$softDemoteBlocksHandoff),
            'stale'          => false,
            'stale_reason'   => null,
            'handoff_ready'  => $handoffReady,
            'executable'     => $executable,
            'block_reason'   => $blockReason,
            'candidate_state'=> ($handoffReady && $executable) ? 'signal_ready' : 'blocked',
        ]);

        return $candidate;
    }

    private function buildSignal(array $candidate, array $config): array
    {
        $side = (string)($candidate['side'] ?? 'long');
        $now  = date('c');

        // Stable idea key: symbol+side+setup_class+nearest structure level+10-minute bucket
        $structureLevel = ($side === 'long')
            ? (float)($candidate['last_higher_low_price'] ?? $candidate['entry_price'] ?? 0)
            : (float)($candidate['last_lower_high_price'] ?? $candidate['entry_price'] ?? 0);
        $timeBucket = $this->getIdeaKeyTimeBucket();
        $ideaKey = ($candidate['symbol'] ?? '')
            . ':' . $side
            . ':' . ($candidate['setup_class'] ?? '')
            . ':' . round($structureLevel, 6)
            . ':' . $timeBucket;
        $signalId = 'cc_' . substr(md5($ideaKey), 0, 16);

        $signal = [
            'strategy_id'                        => 'confirmed_continuation',
            'signal_id'                          => $signalId,
            'confirmed_continuation_idea_key'    => $ideaKey,
            'symbol'                 => $candidate['symbol'] ?? '',
            'side'                   => $side,
            'entry_price'            => $candidate['entry_price'] ?? null,
            'confidence_score'       => $candidate['final_candidate_score'] ?? 0.0,
            'candidate_quality_score'=> $candidate['candidate_quality_score'] ?? 0.0,
            'setup_class'            => $candidate['setup_class'] ?? '',
            'detected_at'            => $candidate['detected_at'] ?? $now,
            'refreshed_at'           => $now,
            'active_final'           => $candidate['active_final']  ?? true,
            'stale'                  => $candidate['stale']         ?? false,
            'stale_reason'           => $candidate['stale_reason']  ?? null,
            'handoff_ready'          => $candidate['handoff_ready'] ?? true,
            'executable'             => $candidate['executable']    ?? true,
            'block_reason'           => $candidate['block_reason']  ?? null,
            // OBC fields
            'ob_wall_checked'        => $candidate['ob_wall_checked']        ?? false,
            'ob_wall_context'        => $candidate['ob_wall_context']        ?? null,
            'ob_ask_wall_risk'       => $candidate['ob_ask_wall_risk']       ?? false,
            'ob_bid_wall_risk'       => $candidate['ob_bid_wall_risk']       ?? false,
            'ob_bid_wall_support'    => $candidate['ob_bid_wall_support']    ?? false,
            'ob_ask_wall_resistance' => $candidate['ob_ask_wall_resistance'] ?? false,
            'ob_soft_demoted'        => $candidate['ob_soft_demoted']        ?? false,
            'ob_gate_mode'           => $candidate['ob_gate_mode']           ?? 'soft_demote',
            'ob_fetch_ok'            => $candidate['ob_fetch_ok']            ?? false,
            'ob_ask_wall_distance_pct' => $candidate['ob_ask_wall_distance_pct'] ?? null,
            'ob_bid_wall_distance_pct' => $candidate['ob_bid_wall_distance_pct'] ?? null,
            'ob_ask_wall_status'     => $candidate['ob_ask_wall_status']     ?? null,
            'ob_bid_wall_status'     => $candidate['ob_bid_wall_status']     ?? null,
            // 24h / anti-comb / wall-test diagnostics
            'day_change_pct'         => $candidate['day_change_pct']         ?? null,
            'day_regime_bias'        => $candidate['day_regime_bias']        ?? null,
            'day_regime_reject_reason'=> $candidate['day_regime_reject_reason'] ?? null,
            'controlled_trend_score' => $candidate['controlled_trend_score'] ?? null,
            'directional_consistency_score' => $candidate['directional_consistency_score'] ?? null,
            'wick_chaos_score'       => $candidate['wick_chaos_score']       ?? null,
            'recent_max_swing_roi'   => $candidate['recent_max_swing_roi']   ?? null,
            'wall_test_state'        => $candidate['wall_test_state']        ?? 'none',
            'wall_test_level'        => $candidate['wall_test_level']        ?? null,
            'wall_test_result'       => $candidate['wall_test_result']       ?? null,
            'wall_test_block_reason' => $candidate['wall_test_block_reason'] ?? null,
            'wall_test_opposite_context_created' => $candidate['wall_test_opposite_context_created'] ?? false,
            // 1-2-3 diagnostics
            'pattern_123_detected' => $candidate['pattern_123_detected'] ?? false,
            'pattern_123_side' => $candidate['pattern_123_side'] ?? $side,
            'pattern_123_detection_direction' => $candidate['pattern_123_detection_direction'] ?? null,
            'point_1_price' => $candidate['point_1_price'] ?? null,
            'point_1_time' => $candidate['point_1_time'] ?? null,
            'point_2_price' => $candidate['point_2_price'] ?? null,
            'point_2_time' => $candidate['point_2_time'] ?? null,
            'point_3_price' => $candidate['point_3_price'] ?? null,
            'point_3_time' => $candidate['point_3_time'] ?? null,
            'point_3_age_minutes' => $candidate['point_3_age_minutes'] ?? null,
            'point_3_distance_from_current_pct' => $candidate['point_3_distance_from_current_pct'] ?? null,
            'point_3_holds_structure' => $candidate['point_3_holds_structure'] ?? null,
            'point_3_distance_from_point_1_pct' => $candidate['point_3_distance_from_point_1_pct'] ?? null,
            'entry_near_point_3' => $candidate['entry_near_point_3'] ?? null,
            'entry_after_point_3_turn' => $candidate['entry_after_point_3_turn'] ?? null,
            'point_2_breakout_confirmed' => $candidate['point_2_breakout_confirmed'] ?? null,
            'point_2_retest_confirmed' => $candidate['point_2_retest_confirmed'] ?? null,
            'pattern_123_entry_mode' => $candidate['pattern_123_entry_mode'] ?? 'none',
            'pattern_123_invalid_reason' => $candidate['pattern_123_invalid_reason'] ?? null,
            // Structure/post-structure diagnostics
            'structure_anchor_type'  => $candidate['structure_anchor_type'] ?? null,
            'structure_anchor_time'  => $candidate['structure_anchor_time'] ?? null,
            'structure_anchor_price' => $candidate['structure_anchor_price'] ?? null,
            'post_structure_window_start' => $candidate['post_structure_window_start'] ?? null,
            'post_structure_window_minutes' => $candidate['post_structure_window_minutes'] ?? null,
            'pre_structure_impulse_pct' => $candidate['pre_structure_impulse_pct'] ?? null,
            'pre_structure_impulse_roi' => $candidate['pre_structure_impulse_roi'] ?? null,
            'pre_structure_impulse_allowed' => $candidate['pre_structure_impulse_allowed'] ?? true,
            'post_structure_controlled_trend_score' => $candidate['post_structure_controlled_trend_score'] ?? null,
            'candle_source_used' => $candidate['candle_source_used'] ?? null,
            'parser2_history_files_read' => $candidate['parser2_history_files_read'] ?? null,
            'parser2_history_candles_loaded' => $candidate['parser2_history_candles_loaded'] ?? null,
            'parser2_history_candles_after_dedupe' => $candidate['parser2_history_candles_after_dedupe'] ?? null,
            'parser2_history_oldest_ts' => $candidate['parser2_history_oldest_ts'] ?? null,
            'parser2_history_newest_ts' => $candidate['parser2_history_newest_ts'] ?? null,
            'bybit_fallback_used' => $candidate['bybit_fallback_used'] ?? null,
            'candle_source_error' => $candidate['candle_source_error'] ?? null,
            // Entry timing / room diagnostics
            'entry_timing_class' => $candidate['entry_timing_class'] ?? null,
            'distance_from_rising_support_pct' => $candidate['distance_from_rising_support_pct'] ?? null,
            'distance_from_falling_resistance_pct' => $candidate['distance_from_falling_resistance_pct'] ?? null,
            'distance_from_last_higher_low_pct' => $candidate['distance_from_last_higher_low_pct'] ?? null,
            'distance_from_last_lower_high_pct' => $candidate['distance_from_last_lower_high_pct'] ?? null,
            'upside_room_to_resistance_pct' => $candidate['upside_room_to_resistance_pct'] ?? null,
            'downside_room_to_support_pct' => $candidate['downside_room_to_support_pct'] ?? null,
            'near_take_profit_zone' => $candidate['near_take_profit_zone'] ?? false,
            'expected_exit_zone_price' => $candidate['expected_exit_zone_price'] ?? null,
            'late_entry_reject_reason' => $candidate['late_entry_reject_reason'] ?? null,
            // Anti-comb / smooth diagnostics full
            'recent_max_1m_range_pct' => $candidate['recent_max_1m_range_pct'] ?? null,
            'recent_max_1m_range_roi' => $candidate['recent_max_1m_range_roi'] ?? null,
            'recent_max_3m_range_pct' => $candidate['recent_max_3m_range_pct'] ?? null,
            'recent_max_3m_range_roi' => $candidate['recent_max_3m_range_roi'] ?? null,
            'recent_max_swing_pct' => $candidate['recent_max_swing_pct'] ?? null,
            'recent_opposite_swing_pct' => $candidate['recent_opposite_swing_pct'] ?? null,
            'recent_opposite_swing_roi' => $candidate['recent_opposite_swing_roi'] ?? null,
            'structure_breaks_count' => $candidate['structure_breaks_count'] ?? null,
            'anti_comb_max_structure_breaks' => $candidate['anti_comb_max_structure_breaks'] ?? null,
            'structure_breaks_count_after_point3' => $candidate['structure_breaks_count_after_point3'] ?? null,
            'structure_breaks_count_before_point3_ignored' => $candidate['structure_breaks_count_before_point3_ignored'] ?? null,
            'structure_break_check_start_idx' => $candidate['structure_break_check_start_idx'] ?? null,
            'structure_break_reference' => $candidate['structure_break_reference'] ?? null,
            'structure_break_tolerance_pct' => $candidate['structure_break_tolerance_pct'] ?? null,
            'anti_comb_window_mode' => $candidate['anti_comb_window_mode'] ?? null,
            'structure_window_range_roi' => $candidate['structure_window_range_roi'] ?? null,
            'post_point3_range_roi' => $candidate['post_point3_range_roi'] ?? null,
            'post_point3_opposite_swing_roi' => $candidate['post_point3_opposite_swing_roi'] ?? null,
            'post_point3_directional_consistency' => $candidate['post_point3_directional_consistency'] ?? null,
            'post_point3_wick_chaos' => $candidate['post_point3_wick_chaos'] ?? null,
            'primary_reject_reason' => $candidate['primary_reject_reason'] ?? null,
            'secondary_reject_reasons' => is_array($candidate['secondary_reject_reasons'] ?? null) ? array_values($candidate['secondary_reject_reasons']) : [],
            'structure_breaks_exceeded' => (bool)($candidate['structure_breaks_exceeded'] ?? false),
            'recent_swing_exceeded' => (bool)($candidate['recent_swing_exceeded'] ?? false),
            'opposite_swing_exceeded' => (bool)($candidate['opposite_swing_exceeded'] ?? false),
            'range_1m_exceeded' => (bool)($candidate['range_1m_exceeded'] ?? false),
            'range_3m_exceeded' => (bool)($candidate['range_3m_exceeded'] ?? false),
            'wick_chaos_exceeded' => (bool)($candidate['wick_chaos_exceeded'] ?? false),
            'directional_consistency_failed' => (bool)($candidate['directional_consistency_failed'] ?? false),
            'alternating_large_candles_detected' => $candidate['alternating_large_candles_detected'] ?? false,
            'smooth_trend_score' => $candidate['smooth_trend_score'] ?? null,
            'step_count' => $candidate['step_count'] ?? null,
            'impulse_share' => $candidate['impulse_share'] ?? null,
            'single_candle_contribution' => $candidate['single_candle_contribution'] ?? null,
            'vertical_spike_detected' => $candidate['vertical_spike_detected'] ?? false,
            'pullback_before_entry_detected' => $candidate['pullback_before_entry_detected'] ?? false,
        ];

        // Side-specific fields
        if ($side === 'long') {
            $signal['higher_lows_count']               = $candidate['higher_lows_count']    ?? 0;
            $signal['last_higher_low_price']           = $candidate['last_higher_low_price'] ?? null;
            $signal['rising_support_price']            = $candidate['rising_support_price']  ?? null;
            $signal['retest_price']                    = $candidate['retest_price']           ?? null;
            $signal['retest_held']                     = $candidate['retest_held']            ?? false;
            $signal['continuation_reclaim_price']      = $candidate['continuation_reclaim_price'] ?? null;
            $signal['trend_phase']                     = 'confirmed_mid_trend_continuation';
            $signal['entry_distance_from_last_higher_low_pct'] = $candidate['entry_distance_from_last_higher_low_pct'] ?? null;
        } else {
            $signal['lower_highs_count']               = $candidate['lower_highs_count']    ?? 0;
            $signal['last_lower_high_price']           = $candidate['last_lower_high_price'] ?? null;
            $signal['falling_resistance_price']        = $candidate['falling_resistance_price'] ?? null;
            $signal['retest_price']                    = $candidate['retest_price']           ?? null;
            $signal['retest_held']                     = $candidate['retest_held']            ?? false;
            $signal['continuation_breakdown_price']    = $candidate['continuation_breakdown_price'] ?? null;
            $signal['trend_phase']                     = 'confirmed_downtrend_continuation';
            $signal['entry_distance_from_last_lower_high_pct'] = $candidate['entry_distance_from_last_lower_high_pct'] ?? null;
        }

        // Strategy signal context for PM handoff
        $strategySignalContext = [
            'strategy_id'                => 'confirmed_continuation',
            'setup_class'                => $signal['setup_class'],
            'candidate_quality_score'    => $signal['candidate_quality_score'],
            'structure_score'            => $candidate['structure_score']          ?? null,
            'retest_score'               => $candidate['retest_score']             ?? null,
            'continuation_score'         => $candidate['continuation_score']       ?? null,
            'entry_distance_score'       => $candidate['entry_distance_score']     ?? null,
            'pullback_quality_score'     => $candidate['pullback_quality_score']   ?? null,
            'volume_persistence_score'   => $candidate['volume_persistence_score'] ?? null,
            // OBC fields in context
            'ob_wall_checked'            => $signal['ob_wall_checked'],
            'ob_ask_wall_risk'           => $signal['ob_ask_wall_risk'],
            'ob_bid_wall_risk'           => $signal['ob_bid_wall_risk'],
            'ob_bid_wall_support'        => $signal['ob_bid_wall_support'],
            'ob_ask_wall_resistance'     => $signal['ob_ask_wall_resistance'],
            'ob_soft_demoted'            => $signal['ob_soft_demoted'],
            'ob_gate_mode'               => $signal['ob_gate_mode'],
            'ob_fetch_ok'                => $signal['ob_fetch_ok'],
            'ob_ask_wall_distance_pct'   => $signal['ob_ask_wall_distance_pct'],
            'ob_bid_wall_distance_pct'   => $signal['ob_bid_wall_distance_pct'],
            'ob_ask_wall_status'         => $signal['ob_ask_wall_status'],
            'ob_bid_wall_status'         => $signal['ob_bid_wall_status'],
            // day regime / anti-comb / wall test
            'day_change_pct'             => $signal['day_change_pct'],
            'day_regime_bias'            => $signal['day_regime_bias'],
            'day_regime_reject_reason'   => $signal['day_regime_reject_reason'],
            'controlled_trend_score'     => $signal['controlled_trend_score'],
            'directional_consistency_score' => $signal['directional_consistency_score'],
            'wick_chaos_score'           => $signal['wick_chaos_score'],
            'recent_max_swing_roi'       => $signal['recent_max_swing_roi'],
            'wall_test_state'            => $signal['wall_test_state'],
            'wall_test_level'            => $signal['wall_test_level'],
            'wall_test_result'           => $signal['wall_test_result'],
            'wall_test_block_reason'     => $signal['wall_test_block_reason'],
            'wall_test_opposite_context_created' => $signal['wall_test_opposite_context_created'],
            'pattern_123_detected'       => $signal['pattern_123_detected'],
            'pattern_123_side'           => $signal['pattern_123_side'],
            'pattern_123_detection_direction' => $signal['pattern_123_detection_direction'],
            'point_1_price'              => $signal['point_1_price'],
            'point_1_time'               => $signal['point_1_time'],
            'point_2_price'              => $signal['point_2_price'],
            'point_2_time'               => $signal['point_2_time'],
            'point_3_price'              => $signal['point_3_price'],
            'point_3_time'               => $signal['point_3_time'],
            'point_3_age_minutes'        => $signal['point_3_age_minutes'],
            'point_3_distance_from_current_pct' => $signal['point_3_distance_from_current_pct'],
            'point_3_holds_structure'    => $signal['point_3_holds_structure'],
            'point_3_distance_from_point_1_pct' => $signal['point_3_distance_from_point_1_pct'],
            'entry_near_point_3'         => $signal['entry_near_point_3'],
            'entry_after_point_3_turn'   => $signal['entry_after_point_3_turn'],
            'point_2_breakout_confirmed' => $signal['point_2_breakout_confirmed'],
            'point_2_retest_confirmed'   => $signal['point_2_retest_confirmed'],
            'pattern_123_entry_mode'     => $signal['pattern_123_entry_mode'],
            'pattern_123_invalid_reason' => $signal['pattern_123_invalid_reason'],
            'structure_anchor_type'      => $signal['structure_anchor_type'],
            'structure_anchor_time'      => $signal['structure_anchor_time'],
            'structure_anchor_price'     => $signal['structure_anchor_price'],
            'post_structure_window_start' => $signal['post_structure_window_start'],
            'post_structure_window_minutes' => $signal['post_structure_window_minutes'],
            'pre_structure_impulse_pct'  => $signal['pre_structure_impulse_pct'],
            'pre_structure_impulse_roi'  => $signal['pre_structure_impulse_roi'],
            'pre_structure_impulse_allowed' => $signal['pre_structure_impulse_allowed'],
            'post_structure_controlled_trend_score' => $signal['post_structure_controlled_trend_score'],
            'candle_source_used'         => $signal['candle_source_used'],
            'parser2_history_files_read' => $signal['parser2_history_files_read'],
            'parser2_history_candles_loaded' => $signal['parser2_history_candles_loaded'],
            'parser2_history_candles_after_dedupe' => $signal['parser2_history_candles_after_dedupe'],
            'parser2_history_oldest_ts' => $signal['parser2_history_oldest_ts'],
            'parser2_history_newest_ts' => $signal['parser2_history_newest_ts'],
            'bybit_fallback_used'       => $signal['bybit_fallback_used'],
            'candle_source_error'       => $signal['candle_source_error'],
            'entry_timing_class'         => $signal['entry_timing_class'],
            'distance_from_rising_support_pct' => $signal['distance_from_rising_support_pct'],
            'distance_from_falling_resistance_pct' => $signal['distance_from_falling_resistance_pct'],
            'distance_from_last_higher_low_pct' => $signal['distance_from_last_higher_low_pct'],
            'distance_from_last_lower_high_pct' => $signal['distance_from_last_lower_high_pct'],
            'upside_room_to_resistance_pct' => $signal['upside_room_to_resistance_pct'],
            'downside_room_to_support_pct' => $signal['downside_room_to_support_pct'],
            'near_take_profit_zone'      => $signal['near_take_profit_zone'],
            'expected_exit_zone_price'   => $signal['expected_exit_zone_price'],
            'recent_max_1m_range_pct'    => $signal['recent_max_1m_range_pct'],
            'recent_max_1m_range_roi'    => $signal['recent_max_1m_range_roi'],
            'recent_max_3m_range_pct'    => $signal['recent_max_3m_range_pct'],
            'recent_max_3m_range_roi'    => $signal['recent_max_3m_range_roi'],
            'recent_max_swing_pct'       => $signal['recent_max_swing_pct'],
            'recent_opposite_swing_pct'  => $signal['recent_opposite_swing_pct'],
            'recent_opposite_swing_roi'  => $signal['recent_opposite_swing_roi'],
            'structure_breaks_count'     => $signal['structure_breaks_count'],
            'structure_breaks_count_after_point3' => $signal['structure_breaks_count_after_point3'],
            'structure_breaks_count_before_point3_ignored' => $signal['structure_breaks_count_before_point3_ignored'],
            'structure_break_check_start_idx' => $signal['structure_break_check_start_idx'],
            'structure_break_reference'  => $signal['structure_break_reference'],
            'structure_break_tolerance_pct' => $signal['structure_break_tolerance_pct'],
            'anti_comb_window_mode'      => $signal['anti_comb_window_mode'],
            'structure_window_range_roi' => $signal['structure_window_range_roi'],
            'post_point3_range_roi'      => $signal['post_point3_range_roi'],
            'post_point3_opposite_swing_roi' => $signal['post_point3_opposite_swing_roi'],
            'post_point3_directional_consistency' => $signal['post_point3_directional_consistency'],
            'post_point3_wick_chaos'     => $signal['post_point3_wick_chaos'],
            'anti_comb_max_structure_breaks' => $signal['anti_comb_max_structure_breaks'],
            'primary_reject_reason'      => $signal['primary_reject_reason'],
            'secondary_reject_reasons'   => $signal['secondary_reject_reasons'],
            'structure_breaks_exceeded'  => $signal['structure_breaks_exceeded'],
            'recent_swing_exceeded'      => $signal['recent_swing_exceeded'],
            'opposite_swing_exceeded'    => $signal['opposite_swing_exceeded'],
            'range_1m_exceeded'          => $signal['range_1m_exceeded'],
            'range_3m_exceeded'          => $signal['range_3m_exceeded'],
            'wick_chaos_exceeded'        => $signal['wick_chaos_exceeded'],
            'directional_consistency_failed' => $signal['directional_consistency_failed'],
            'alternating_large_candles_detected' => $signal['alternating_large_candles_detected'],
            'smooth_trend_score'         => $signal['smooth_trend_score'],
            'step_count'                 => $signal['step_count'],
            'impulse_share'              => $signal['impulse_share'],
            'single_candle_contribution' => $signal['single_candle_contribution'],
            'vertical_spike_detected'    => $signal['vertical_spike_detected'],
            'pullback_before_entry_detected' => $signal['pullback_before_entry_detected'],
            'block_reason'               => $signal['block_reason'],
            'handoff_ready'              => $signal['handoff_ready'],
            'executable'                 => $signal['executable'],
            'active_final'               => $signal['active_final'],
        ];

        // PM context hints
        if ($side === 'long') {
            $strategySignalContext['preferred_pm_phase']         = 'confirmed_mid_trend_continuation';
            $strategySignalContext['allow_mid_trend_hold']        = true;
            $strategySignalContext['structure_stop_reference']    = $candidate['last_higher_low_price'] ?? null;
            $strategySignalContext['trend_structure_intact']      = $candidate['structure_holds'] ?? true;
            $strategySignalContext['higher_lows_count']           = $signal['higher_lows_count']          ?? 0;
            $strategySignalContext['last_higher_low_price']       = $signal['last_higher_low_price']       ?? null;
            $strategySignalContext['rising_support_price']        = $signal['rising_support_price']        ?? null;
            $strategySignalContext['retest_price']                = $signal['retest_price']                ?? null;
            $strategySignalContext['retest_held']                 = $signal['retest_held']                 ?? false;
            $strategySignalContext['continuation_reclaim_price']  = $signal['continuation_reclaim_price']  ?? null;
            $strategySignalContext['trend_phase']                 = 'confirmed_mid_trend_continuation';
        } else {
            $strategySignalContext['preferred_pm_phase']         = 'confirmed_downtrend_continuation';
            $strategySignalContext['structure_stop_reference']    = $candidate['last_lower_high_price'] ?? null;
            $strategySignalContext['trend_structure_intact']      = $candidate['structure_holds'] ?? true;
            $strategySignalContext['lower_highs_count']           = $signal['lower_highs_count']          ?? 0;
            $strategySignalContext['last_lower_high_price']       = $signal['last_lower_high_price']       ?? null;
            $strategySignalContext['falling_resistance_price']    = $signal['falling_resistance_price']    ?? null;
            $strategySignalContext['retest_price']                = $signal['retest_price']                ?? null;
            $strategySignalContext['retest_held']                 = $signal['retest_held']                 ?? false;
            $strategySignalContext['continuation_breakdown_price']= $signal['continuation_breakdown_price'] ?? null;
            $strategySignalContext['trend_phase']                 = 'confirmed_downtrend_continuation';
        }

        $signal['strategy_signal_context'] = $strategySignalContext;

        return $signal;
    }

    private function buildHandoffQueue(array $signals, array $previousQueue = [], array $config = []): array
    {
        $prevBySignalId = [];
        foreach ($previousQueue as $prev) {
            if (!is_array($prev)) {
                continue;
            }
            $prevSignalId = (string)($prev['signal_id'] ?? '');
            if ($prevSignalId !== '') {
                $prevBySignalId[$prevSignalId] = true;
            }
        }

        $candidates = [];
        foreach ($signals as $sig) {
            $isExecutable  = (bool)($sig['executable'] ?? false);
            $isStale       = (bool)($sig['stale'] ?? false);
            $isHandoffReady= (bool)($sig['handoff_ready'] ?? false);
            $isActiveFinal = (bool)($sig['active_final'] ?? false);
            $blockReason   = $sig['block_reason'] ?? null;
            $isBlocked     = is_string($blockReason) ? trim($blockReason) !== '' : ($blockReason !== null);
            if (!$isExecutable || $isStale || !$isHandoffReady || !$isActiveFinal || $isBlocked) {
                continue;
            }
            $signalId = (string)($sig['signal_id'] ?? '');
            if ($signalId === '') {
                continue;
            }
            $isRefreshed = isset($prevBySignalId[$signalId]);
            $handoffStatus = $isRefreshed ? 'refreshed' : 'new';
            $entry = [
                'strategy_id'             => 'confirmed_continuation',
                'signal_id'               => $signalId,
                'confirmed_continuation_idea_key' => $sig['confirmed_continuation_idea_key'] ?? '',
                'symbol'                  => $sig['symbol'] ?? '',
                'side'                    => $sig['side'] ?? '',
                'entry_price'             => $sig['entry_price'] ?? null,
                'confidence_score'        => $sig['confidence_score'] ?? 0.0,
                'candidate_quality_score' => $sig['candidate_quality_score'] ?? 0.0,
                'setup_class'             => $sig['setup_class'] ?? '',
                'detected_at'             => $sig['detected_at'] ?? '',
                'refreshed_at'            => $sig['refreshed_at'] ?? date('c'),
                'handoff_status'          => $handoffStatus,
                'queue_status'            => $isRefreshed ? 'ready' : 'new',
                'handoff_ready'           => true,
                'executable'              => true,
                'active_final'            => true,
                'stale'                   => false,
                'block_reason'            => null,
                'strategy_signal_context' => $sig['strategy_signal_context'] ?? [],
            ];
            $candidates[] = $entry;
        }

        $this->handoffCandidatesBeforeThrottleTotal = count($candidates);
        $preferHighest = (bool)($config['prefer_highest_quality_handoff'] ?? true);
        if ($preferHighest) {
            usort($candidates, static function (array $a, array $b): int {
                $qa = (float)($a['candidate_quality_score'] ?? $a['confidence_score'] ?? 0.0);
                $qb = (float)($b['candidate_quality_score'] ?? $b['confidence_score'] ?? 0.0);
                return $qb <=> $qa;
            });
        }

        $maxPerTick = max(1, (int)($config['max_handoff_signals_per_tick'] ?? 2));
        $maxPerSide = max(1, (int)($config['max_handoff_signals_per_side_per_tick'] ?? 1));
        $maxActiveTotal = max(1, (int)($config['max_active_confirmed_continuation_signals_total'] ?? 5));
        $queue = [];
        $sideCounts = ['long' => 0, 'short' => 0];
        foreach ($candidates as $entry) {
            $side = (string)($entry['side'] ?? '');
            if (count($queue) >= $maxPerTick) {
                $this->handoffThrottleReasonCounts['max_handoff_signals_per_tick'] = ($this->handoffThrottleReasonCounts['max_handoff_signals_per_tick'] ?? 0) + 1;
                if (count($this->handoffThrottleExamples) < 8) {
                    $this->handoffThrottleExamples[] = ['signal_id' => $entry['signal_id'] ?? '', 'reason' => 'max_handoff_signals_per_tick'];
                }
                continue;
            }
            if (($sideCounts[$side] ?? 0) >= $maxPerSide) {
                $this->handoffThrottleReasonCounts['max_handoff_signals_per_side_per_tick'] = ($this->handoffThrottleReasonCounts['max_handoff_signals_per_side_per_tick'] ?? 0) + 1;
                if (count($this->handoffThrottleExamples) < 8) {
                    $this->handoffThrottleExamples[] = ['signal_id' => $entry['signal_id'] ?? '', 'reason' => 'max_handoff_signals_per_side_per_tick'];
                }
                continue;
            }
            if (count($queue) >= $maxActiveTotal) {
                $this->handoffThrottleReasonCounts['max_active_confirmed_continuation_signals_total'] = ($this->handoffThrottleReasonCounts['max_active_confirmed_continuation_signals_total'] ?? 0) + 1;
                if (count($this->handoffThrottleExamples) < 8) {
                    $this->handoffThrottleExamples[] = ['signal_id' => $entry['signal_id'] ?? '', 'reason' => 'max_active_confirmed_continuation_signals_total'];
                }
                continue;
            }
            $queue[] = $entry;
            $sideCounts[$side] = ($sideCounts[$side] ?? 0) + 1;

            $this->handoffQueueWrittenTotal++;
            $handoffStatus = (string)($entry['handoff_status'] ?? 'new');
            if ($handoffStatus === 'refreshed') {
                $this->handoffQueueRefreshedTotal++;
            } else {
                $this->handoffQueueNewTotal++;
            }
            if (count($this->handoffQueueExamples) < 5) {
                $this->handoffQueueExamples[] = [
                    'signal_id'      => $entry['signal_id'],
                    'symbol'         => $entry['symbol'],
                    'side'           => $entry['side'],
                    'handoff_status' => $entry['handoff_status'],
                    'queue_status'   => $entry['queue_status'],
                ];
            }
        }
        $this->handoffAfterThrottleTotal = count($queue);
        $this->handoffThrottledTotal = max(0, $this->handoffCandidatesBeforeThrottleTotal - $this->handoffAfterThrottleTotal);
        $this->handoffQueueMissingStatusTotal = count(array_filter(
            $queue,
            static fn(array $entry): bool => !isset($entry['handoff_status'])
                || !in_array((string)$entry['handoff_status'], ['new', 'refreshed'], true)
        ));
        return $queue;
    }

    // ── last_run builder ───────────────────────────────────────────────────────

    private function buildLastRun(
        array  $config,
        string $startedAt,
        string $finishedAt,
        int    $durationMs,
        array  $signals,
        array  $runWindow = []
    ): array {
        return [
            'strategy_id'                     => 'confirmed_continuation',
            'strategy_is_environment_neutral' => true,
            'execution_mode_used_for_selection' => false,
            'effective_strategy_mode'         => $this->effectiveStrategyMode,
            'status'                          => 'done',
            'started_at'                      => $startedAt,
            'finished_at'                     => $finishedAt,
            'duration_ms'                     => $durationMs,
            'candidates_total'                => $this->candidatesTotal,
            'long_candidates_total'           => $this->longCandidatesTotal,
            'short_candidates_total'          => $this->shortCandidatesTotal,
            'signals_total'                   => $this->signalsTotal,
            'long_signals_total'              => $this->longSignalsTotal,
            'short_signals_total'             => $this->shortSignalsTotal,
            'handoff_ready_total'             => $this->handoffReadyTotal,
            'rejected_total'                  => $this->rejectedTotal,
            'reject_reason_counts'            => $this->rejectReasonCounts,
            // Pattern diagnostics (old names mapped to new counters for backward compat)
            'higher_low_candidates_total'     => $this->higherLowCandidatesTotal,
            'lower_high_candidates_total'     => $this->lowerHighCandidatesTotal,
            'retest_held_total'               => $this->retestHeldTotal,
            'continuation_confirmed_total'    => $this->continuationConfirmedTotal,
            'first_bounce_rejected_total'     => $this->firstBounceRejectedTotal,
            'late_entry_rejected_total'       => $this->lateEntryRejectedTotal,
            // Old pattern_123 counters mapped to pre-filter counts for consistency
            'pattern_123_checked_total'       => $this->pattern123StructuresSeenTotal,
            'pattern_123_detected_total'      => $this->pattern123ValidBeforeFiltersTotal,
            'pattern_123_rejected_total'      => $this->pattern123InvalidBeforeFiltersTotal,
            'pattern_123_missing_total'       => $this->pattern123MissingTotal,
            'point_3_breaks_point_1_total'    => $this->point3BreaksPoint1Total,
            'point_3_not_confirmed_total'     => $this->point3NotConfirmedTotal,
            'entry_too_far_from_point_3_total'=> $this->entryTooFarFromPoint3Total,
            // Anti-comb diagnostics
            'anti_comb_checked_total'                       => $this->antiCombCheckedTotal,
            'anti_comb_rejected_total'                      => $this->antiCombRejectedTotal,
            'anti_comb_recent_range_reject_total'           => $this->antiCombRecentRangeRejectTotal,
            'anti_comb_recent_range_too_high_total'         => $this->antiCombRecentRangeRejectTotal,
            'anti_comb_recent_swing_too_high_total'         => $this->antiCombRecentSwingRejectTotal,
            'anti_comb_opposite_swing_reject_total'         => $this->antiCombOppositeSwingRejectTotal,
            'anti_comb_opposite_swing_too_high_total'       => $this->antiCombOppositeSwingRejectTotal,
            'anti_comb_wick_chaos_reject_total'             => $this->antiCombWickChaosRejectTotal,
            'anti_comb_wick_chaos_total'                    => $this->antiCombWickChaosRejectTotal,
            'anti_comb_low_consistency_reject_total'        => $this->antiCombLowConsistencyRejectTotal,
            'anti_comb_low_directional_consistency_total'   => $this->antiCombLowConsistencyRejectTotal,
            'anti_comb_too_many_structure_breaks_total'     => $this->antiCombStructureBreaksRejectTotal,
            'post_structure_comb_detected_total'            => $this->postStructureCombDetectedTotal,
            'smooth_trend_score_too_low_total'              => $this->smoothTrendScoreTooLowTotal,
            'post_structure_filter_checked_total'           => $this->postStructureFilterCheckedTotal,
            'post_structure_filter_rejected_total'          => $this->postStructureFilterRejectedTotal,
            'entry_timing_checked_total'              => $this->entryTimingCheckedTotal,
            'near_exit_zone_rejected_total'           => $this->nearExitZoneRejectedTotal,
            'controlled_trend_gate_checked_total'     => $this->controlledTrendGateCheckedTotal,
            'controlled_trend_gate_rejected_total'    => $this->controlledTrendGateRejectedTotal,
            'controlled_trend_score_too_low_total'    => $this->controlledTrendScoreTooLowTotal,
            'directional_consistency_too_low_total'   => $this->directionalConsistencyTooLowTotal,
            'wick_chaos_too_high_total'               => $this->wickChaosTooHighTotal,
            'recent_swing_too_high_total'             => $this->recentSwingTooHighTotal,
            'opposite_swing_too_high_total'           => $this->oppositeSwingTooHighTotal,
            'structure_breaks_not_allowed_total'      => $this->structureBreaksNotAllowedTotal,
            'smooth_trend_checked_total'              => $this->smoothTrendCheckedTotal,
            'smooth_trend_rejected_total'             => $this->smoothTrendRejectedTotal,
            'vertical_spike_rejected_total'           => $this->verticalSpikeRejectedTotal,
            // Filter funnel diagnostics
            'structures_detected_total'                        => $this->structuresDetectedTotal,
            'pattern_123_structures_seen_total'                => $this->pattern123StructuresSeenTotal,
            'pattern_123_valid_before_filters_total'           => $this->pattern123ValidBeforeFiltersTotal,
            'pattern_123_invalid_before_filters_total'         => $this->pattern123InvalidBeforeFiltersTotal,
            'pattern_123_valid_but_anti_comb_rejected_total'   => $this->pattern123ValidButAntiCombRejectedTotal,
            'pattern_123_valid_but_entry_timing_rejected_total'=> $this->pattern123ValidButEntryTimingRejectedTotal,
            'pattern_123_valid_but_wall_blocked_total'         => $this->pattern123ValidButWallBlockedTotal,
            'pattern_123_valid_signal_ready_total'             => $this->pattern123ValidSignalReadyTotal,
            'backward_123_checked_total'                       => $this->backward123CheckedTotal,
            'backward_123_valid_total'                         => $this->backward123ValidTotal,
            'backward_123_invalid_total'                       => $this->backward123InvalidTotal,
            'recent_point3_candidates_total'                   => $this->recentPoint3CandidatesTotal,
            'point3_near_entry_total'                          => $this->point3NearEntryTotal,
            'point3_turn_confirmed_total'                      => $this->point3TurnConfirmedTotal,
            'point2_found_total'                               => $this->point2FoundTotal,
            'point1_found_total'                               => $this->point1FoundTotal,
            'structure_breaks_after_point3_total'              => $this->structureBreaksAfterPoint3Total,
            'structure_breaks_before_point3_ignored_total'     => $this->structureBreaksBeforePoint3IgnoredTotal,
            'anti_comb_passed_total'                           => $this->antiCombPassedTotal,
            'entry_timing_passed_total'                        => $this->entryTimingPassedTotal,
            'quality_gate_passed_total'                        => $this->qualityGatePassedTotal,
            'wall_test_passed_total'                           => $this->wallTestPassedTotal,
            'signal_ready_total'                               => $this->signalReadyTotal,
            // 24h regime diagnostics
            'day_regime_checked_total'        => $this->dayRegimeCheckedTotal,
            'day_regime_blocked_total'        => $this->dayRegimeBlockedTotal,
            'day_regime_long_bias_total'      => $this->dayRegimeLongBiasTotal,
            'day_regime_short_bias_total'     => $this->dayRegimeShortBiasTotal,
            // OBC/wall counters
            'obc_checked_total'               => $this->obcCheckedTotal,
            'obc_fetch_success_total'         => $this->obcFetchSuccessTotal,
            'obc_fetch_failed_total'          => $this->obcFetchFailedTotal,
            'obc_soft_demote_blocked_total'   => $this->obcSoftDemoteBlockedHandoff,
            'obc_soft_demote_allowed_total'   => $this->obcSoftDemoteAllowedHandoff,
            'wall_test_pending_total'         => $this->wallTestPendingTotal,
            'wall_test_confirmed_total'       => $this->wallTestConfirmedTotal,
            'wall_test_rejected_total'        => $this->wallTestRejectedTotal,
            'wall_test_opposite_context_total'=> $this->wallTestOppositeContextTotal,
            'wall_pending_blocked_total'      => $this->wallPendingBlockedTotal,
            'accepted_examples'               => $this->acceptedExamples,
            'rejected_examples'               => $this->rejectedExamples,
            'accepted_early_structure_examples' => $this->acceptedEarlyStructureExamples,
            'accepted_mid_trend_examples'     => $this->acceptedMidTrendExamples,
            'accepted_pattern_123_examples'   => $this->acceptedPattern123Examples,
            'rejected_pattern_123_examples'   => $this->rejectedPattern123Examples,
            'rejected_late_entry_examples'    => $this->rejectedLateEntryExamples,
            'rejected_comb_examples'          => $this->rejectedCombExamples,
            'pattern_123_valid_but_anti_comb_rejected_examples' => $this->pattern123ValidButAntiCombRejectedExamples,
            'wall_pending_examples'           => $this->wallPendingExamples,
            'obc_block_examples'              => $this->obcBlockExamples,
            'wall_test_examples'              => $this->wallTestExamples,
            'late_entry_reject_examples'      => $this->lateEntryRejectExamples,
            'first_bounce_reject_examples'    => $this->firstBounceRejectExamples,
            'no_retest_reject_examples'       => $this->noRetestRejectExamples,
            'anti_comb_examples'              => $this->antiCombExamples,
            'active_signals_total'            => count(array_filter($signals, fn($s) => !($s['stale'] ?? false) && ($s['active_final'] ?? false))),
            'candle_source_used_counts'       => $this->candleSourceUsedCounts,
            'parser2_history_used_total'      => $this->parser2HistoryUsedTotal,
            'bybit_fallback_used_total'       => $this->bybitFallbackUsedTotal,
            'backward_123_valid_examples'     => $this->backward123ValidExamples,
            'backward_123_invalid_examples'   => $this->backward123InvalidExamples,
            'point3_candidate_examples'       => $this->point3CandidateExamples,
            'parser2_history_error_examples'  => $this->parser2HistoryErrorExamples,
            // Handoff queue diagnostics
            'handoff_queue_written_total'     => $this->handoffQueueWrittenTotal,
            'handoff_queue_new_total'         => $this->handoffQueueNewTotal,
            'handoff_queue_refreshed_total'   => $this->handoffQueueRefreshedTotal,
            'handoff_queue_missing_status_total' => $this->handoffQueueMissingStatusTotal,
            'handoff_queue_examples'          => $this->handoffQueueExamples,
            'handoff_candidates_before_throttle_total' => $this->handoffCandidatesBeforeThrottleTotal,
            'handoff_after_throttle_total'    => $this->handoffAfterThrottleTotal,
            'handoff_throttled_total'         => $this->handoffThrottledTotal,
            'handoff_throttle_reason_counts'  => $this->handoffThrottleReasonCounts,
            'handoff_throttle_examples'       => $this->handoffThrottleExamples,
            // Deprecated config diagnostics
            'deprecated_execution_mode_key_seen'    => $this->deprecatedExecutionModeKeySeen,
            'deprecated_execution_mode_key_ignored' => $this->deprecatedExecutionModeKeyIgnored,
            // Universe diagnostics
            'universe_source'                 => $this->universeSource,
            'universe_total'                  => $this->universeTotal,
            'universe_batch_count'            => $this->universeBatchCount,
            'universe_symbols_examples'       => $this->universeExamples,
            'selected_window_total'           => (int)($runWindow['selected_window_total'] ?? 0),
            'batch_size'                      => (int)($runWindow['batch_size'] ?? (int)($config['batch_size'] ?? 50)),
            'max_symbols_per_run'             => (int)($runWindow['max_symbols_per_run'] ?? (int)($config['max_symbols_per_run'] ?? 50)),
            'batch_offset_before'             => (int)($runWindow['batch_offset_before'] ?? 0),
            'batch_offset_after'              => (int)($runWindow['batch_offset_after'] ?? 0),
            'registry_cursor'                 => (int)($runWindow['registry_cursor'] ?? 0),
            'previous_registry_cursor'        => (int)($runWindow['previous_registry_cursor'] ?? 0),
            'next_registry_cursor'            => (int)($runWindow['next_registry_cursor'] ?? 0),
            'registry_window_start'           => (int)($runWindow['registry_window_start'] ?? 0),
            'registry_window_end'             => (int)($runWindow['registry_window_end'] ?? 0),
            'registry_window_wrapped'         => (bool)($runWindow['registry_window_wrapped'] ?? false),
            'registry_cursor_reset_reason'    => $runWindow['registry_cursor_reset_reason'] ?? null,
            'batch_symbols_examples'          => is_array($runWindow['batch_symbols_examples'] ?? null) ? array_slice($runWindow['batch_symbols_examples'], 0, 5) : [],
            'continuous_scan_enabled'         => (bool)($runWindow['continuous_scan_enabled'] ?? (bool)($config['continuous_scan_enabled'] ?? true)),
            'auto_requeue_when_done'          => (bool)($runWindow['auto_requeue_when_done'] ?? (bool)($config['auto_requeue_when_done'] ?? true)),
            'auto_requeued_from_status'       => $runWindow['auto_requeued_from_status'] ?? null,
            'auto_requeue_at'                 => $runWindow['auto_requeue_at'] ?? null,
            'auto_requeue_result'             => $runWindow['auto_requeue_result'] ?? null,
            'previous_next_registry_cursor_before_requeue' => $runWindow['previous_next_registry_cursor_before_requeue'] ?? null,
            'registry_cursor_after_requeue'   => $runWindow['registry_cursor_after_requeue'] ?? null,
            'next_registry_cursor_after_requeue' => $runWindow['next_registry_cursor_after_requeue'] ?? null,
            'auto_requeue_skipped_reason'     => $runWindow['auto_requeue_skipped_reason'] ?? null,
        ];
    }

    // ── Candle fetch ───────────────────────────────────────────────────────────

    /**
     * Fetch candles with parser2-history primary source and bybit fallback.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchCandles(string $symbol, int $limit, array $config): array
    {
        $this->lastCandleFetchDiag = [
            'candle_source_used' => 'none',
            'parser2_history_files_read' => 0,
            'parser2_history_candles_loaded' => 0,
            'parser2_history_candles_after_dedupe' => 0,
            'parser2_history_oldest_ts' => null,
            'parser2_history_newest_ts' => null,
            'bybit_fallback_used' => false,
            'candle_source_error' => null,
        ];

        $source = (string)($config['candle_source'] ?? 'parser2_history');
        $fallback = (string)($config['candle_source_fallback'] ?? 'bybit');
        $parser2Enabled = (bool)($config['parser2_history_enabled'] ?? true);
        $parser2LookbackMinutes = max(30, (int)($config['parser2_history_lookback_minutes'] ?? 240));
        $parser2MinCandles = max(20, (int)($config['parser2_history_min_candles'] ?? 80));
        $bybitFallbackLimit = max($limit, (int)($config['bybit_fallback_limit'] ?? 120));

        if ($source === 'parser2_history' && $parser2Enabled) {
            $fromParser2 = $this->loadCandlesFromParser2History($symbol, $parser2LookbackMinutes, $parser2MinCandles, $this->lastCandleFetchDiag);
            if (count($fromParser2) >= $parser2MinCandles) {
                $this->lastCandleFetchDiag['candle_source_used'] = 'parser2_history';
                return array_slice($fromParser2, -max($limit, $parser2MinCandles));
            }
            $this->lastCandleFetchDiag['candle_source_error'] = $this->lastCandleFetchDiag['candle_source_error'] ?? 'parser2_history_insufficient_candles';
        }

        if ($fallback === 'bybit') {
            $candles = $this->fetchBybitCandles($symbol, $bybitFallbackLimit, $config);
            if (!empty($candles)) {
                $this->lastCandleFetchDiag['candle_source_used'] = 'bybit_fallback';
                $this->lastCandleFetchDiag['bybit_fallback_used'] = true;
                return array_slice($candles, -max($limit, 20));
            }
            $this->lastCandleFetchDiag['candle_source_error'] = $this->lastCandleFetchDiag['candle_source_error'] ?? 'bybit_fallback_empty';
        }

        return [];
    }

    /**
     * @param array<string,mixed> $diag
     * @return list<array<string,mixed>>
     */
    private function loadCandlesFromParser2History(string $symbol, int $lookbackMinutes, int $minCandles, array &$diag): array
    {
        $normalizedSymbol = strtoupper(trim($symbol));
        if (!preg_match('/^[A-Z0-9]{2,30}$/', $normalizedSymbol)) {
            $diag['candle_source_error'] = 'parser2_history_invalid_symbol';
            return [];
        }
        $storageRoot = $this->repoRoot . '/modules/parser/parser2_history_accumulator/storage/' . $normalizedSymbol;
        $files = [
            $storageRoot . '/' . gmdate('Y-m-d') . '.ndjson',
            $storageRoot . '/' . gmdate('Y-m-d', time() - 86400) . '.ndjson',
        ];

        $rows = [];
        $filesRead = 0;
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $filesRead++;
            $fh = @fopen($file, 'r');
            if (!is_resource($fh)) {
                $diag['candle_source_error'] = 'parser2_file_open_failed';
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
                $price = (float)($row['last_price'] ?? ($row['data']['lastPrice'] ?? 0.0));
                $volume = (float)($row['volume24h'] ?? 0.0);
                if ($ts <= 0 || $price <= 0.0) {
                    continue;
                }
                $rows[] = ['ts' => $ts, 'price' => $price, 'volume' => $volume];
            }
            fclose($fh);
        }

        $diag['parser2_history_files_read'] = $filesRead;
        $diag['parser2_history_candles_loaded'] = count($rows);
        if (empty($rows)) {
            $diag['candle_source_error'] = $diag['candle_source_error'] ?? 'parser2_history_empty';
            return [];
        }

        usort($rows, static fn(array $a, array $b): int => ((int)$a['ts']) <=> ((int)$b['ts']));
        $dedupedByTs = [];
        foreach ($rows as $row) {
            $dedupedByTs[(string)$row['ts']] = $row;
        }
        $rows = array_values($dedupedByTs);
        usort($rows, static fn(array $a, array $b): int => ((int)$a['ts']) <=> ((int)$b['ts']));
        $diag['parser2_history_candles_after_dedupe'] = count($rows);

        $minuteBars = [];
        foreach ($rows as $row) {
            $bucket = (int)(floor(((int)$row['ts']) / 60) * 60);
            $price = (float)$row['price'];
            $volume = (float)$row['volume'];
            if (!isset($minuteBars[$bucket])) {
                $minuteBars[$bucket] = [
                    'ts' => $bucket,
                    'open' => $price,
                    'high' => $price,
                    'low' => $price,
                    'close' => $price,
                    'volume' => $volume,
                ];
            } else {
                $minuteBars[$bucket]['high'] = max((float)$minuteBars[$bucket]['high'], $price);
                $minuteBars[$bucket]['low'] = min((float)$minuteBars[$bucket]['low'], $price);
                $minuteBars[$bucket]['close'] = $price;
                $minuteBars[$bucket]['volume'] = (float)$minuteBars[$bucket]['volume'] + $volume;
            }
        }
        ksort($minuteBars);
        $candles = array_values($minuteBars);
        if (empty($candles)) {
            $diag['candle_source_error'] = $diag['candle_source_error'] ?? 'parser2_history_no_minute_bars';
            return [];
        }

        $newestTs = (int)($candles[count($candles) - 1]['ts'] ?? 0);
        $minTs = $newestTs - ($lookbackMinutes * 60);
        $candles = array_values(array_filter($candles, static fn(array $c): bool => (int)$c['ts'] >= $minTs));

        if (count($candles) < $minCandles) {
            $candles = array_slice($candles, -$minCandles);
        }
        if (!empty($candles)) {
            $diag['parser2_history_oldest_ts'] = $this->formatCandleTimestamp((int)$candles[0]['ts']);
            $diag['parser2_history_newest_ts'] = $this->formatCandleTimestamp((int)$candles[count($candles) - 1]['ts']);
        }
        return $candles;
    }

    /**
     * Fetch OHLCV candles from Bybit. Returns [] on any failure.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchBybitCandles(string $symbol, int $limit, array $config): array
    {
        $baseUrl = rtrim((string)($config['bybit_base_url'] ?? 'https://api.bybit.com'), '/');
        $timeout = max(3, (int)($config['bybit_timeout_sec'] ?? 6));
        $url = $baseUrl . '/v5/market/kline?' . http_build_query([
            'category' => 'linear',
            'symbol'   => $symbol,
            'interval' => '1',
            'limit'    => min($limit, 200),
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!isset($decoded['result']['list']) || !is_array($decoded['result']['list'])) {
            return [];
        }

        $candles = [];
        foreach (array_reverse($decoded['result']['list']) as $row) {
            if (!is_array($row) || count($row) < 6) {
                continue;
            }
            $candles[] = [
                'ts'     => (int)$row[0],
                'open'   => (float)$row[1],
                'high'   => (float)$row[2],
                'low'    => (float)$row[3],
                'close'  => (float)$row[4],
                'volume' => (float)$row[5],
            ];
        }
        return $candles;
    }

    // ── Universe ───────────────────────────────────────────────────────────────

    private function fetchUniverse(array $config): array
    {
        $universeSource  = 'unknown';
        $symbols         = [];
        $diagTotal       = 0;

        // Resolve parser1_market_registry path (same as double_bottom_long)
        try {
            $registryDir = \Core\System\SystemPaths::instance()
                ->get('parser.parser1_market_registry');
        } catch (\Throwable) {
            $registryDir = $this->repoRoot . '/modules/parser/parser1_market_registry';
        }

        // Prefer active.json (symbol list); fall back to registry.json
        $activePath   = rtrim($registryDir, '/') . '/storage/active.json';
        $registryPath = rtrim($registryDir, '/') . '/storage/registry.json';

        foreach ([$activePath, $registryPath] as $tryPath) {
            if (!is_file($tryPath)) {
                continue;
            }
            $raw = @file_get_contents($tryPath);
            if ($raw === false || $raw === '') {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }

            if (array_is_list($data)) {
                // List of objects [{symbol: ...}] or flat strings
                foreach ($data as $item) {
                    if (is_array($item) && isset($item['symbol'])) {
                        $sym = (string)$item['symbol'];
                        if ($sym !== '') {
                            $symbols[] = $sym;
                        }
                    } elseif (is_string($item) && $item !== '') {
                        $symbols[] = $item;
                    }
                }
            } else {
                // Keyed dict: {BTCUSDT: {...}}
                foreach ($data as $key => $val) {
                    if (is_array($val) && isset($val['symbol'])) {
                        $sym = (string)$val['symbol'];
                    } else {
                        $sym = (string)$key;
                    }
                    // Only include active linear USDT perpetuals
                    $status = is_array($val) ? (string)($val['status'] ?? 'active') : 'active';
                    $type   = is_array($val) ? (string)($val['contract_type'] ?? $val['type'] ?? 'LinearPerpetual') : 'LinearPerpetual';
                    if (str_ends_with($sym, 'USDT') && $status !== 'inactive' && str_contains(strtolower($type), 'linear')) {
                        $symbols[] = $sym;
                    }
                }
            }

            if (!empty($symbols)) {
                $universeSource = $tryPath;
                $diagTotal      = count($symbols);
                break;
            }
        }

        // Write universe diagnostics into last_run context (via instance vars)
        $this->universeSource       = $universeSource;
        $this->universeTotal        = $diagTotal;
        $this->universeBatchCount   = 0; // updated after slice
        $this->universeExamples     = array_slice($symbols, 0, 5);

        return $symbols;
    }

    // ── Utilities ──────────────────────────────────────────────────────────────

    private function resetCounters(): void
    {
        $this->obcCheckedTotal             = 0;
        $this->obcFetchSuccessTotal        = 0;
        $this->obcFetchFailedTotal         = 0;
        $this->obcSoftDemoteTotal          = 0;
        $this->obcSoftDemoteBlockedHandoff = 0;
        $this->obcSoftDemoteAllowedHandoff = 0;
        $this->obcBlockExamples            = [];
        $this->candidatesTotal             = 0;
        $this->longCandidatesTotal         = 0;
        $this->shortCandidatesTotal        = 0;
        $this->signalsTotal                = 0;
        $this->longSignalsTotal            = 0;
        $this->shortSignalsTotal           = 0;
        $this->handoffReadyTotal           = 0;
        $this->rejectedTotal               = 0;
        $this->rejectReasonCounts          = [];
        $this->acceptedExamples            = [];
        $this->rejectedExamples            = [];
        $this->lateEntryRejectExamples     = [];
        $this->firstBounceRejectExamples   = [];
        $this->noRetestRejectExamples      = [];
        $this->antiCombExamples            = [];
        $this->wallTestExamples            = [];
        $this->higherLowCandidatesTotal    = 0;
        $this->lowerHighCandidatesTotal    = 0;
        $this->retestHeldTotal             = 0;
        $this->continuationConfirmedTotal  = 0;
        $this->firstBounceRejectedTotal    = 0;
        $this->lateEntryRejectedTotal      = 0;
        $this->pattern123CheckedTotal      = 0;
        $this->pattern123DetectedTotal     = 0;
        $this->pattern123RejectedTotal     = 0;
        $this->pattern123MissingTotal      = 0;
        $this->point3BreaksPoint1Total     = 0;
        $this->point3NotConfirmedTotal     = 0;
        $this->entryTooFarFromPoint3Total  = 0;
        $this->acceptedPattern123Examples  = [];
        $this->rejectedPattern123Examples  = [];
        $this->antiCombCheckedTotal                  = 0;
        $this->antiCombRejectedTotal                 = 0;
        $this->antiCombRecentRangeRejectTotal        = 0;
        $this->antiCombRecentSwingRejectTotal        = 0;
        $this->antiCombOppositeSwingRejectTotal      = 0;
        $this->antiCombWickChaosRejectTotal          = 0;
        $this->antiCombLowConsistencyRejectTotal     = 0;
        $this->antiCombStructureBreaksRejectTotal    = 0;
        $this->antiCombAlternatingCandlesRejectTotal = 0;
        $this->dayRegimeCheckedTotal       = 0;
        $this->dayRegimeBlockedTotal       = 0;
        $this->dayRegimeLongBiasTotal      = 0;
        $this->dayRegimeShortBiasTotal     = 0;
        $this->wallTestPendingTotal        = 0;
        $this->wallTestConfirmedTotal      = 0;
        $this->wallTestRejectedTotal       = 0;
        $this->wallTestOppositeContextTotal= 0;
        $this->wallPendingBlockedTotal     = 0;
        $this->wallPendingExamples         = [];
        $this->handoffQueueWrittenTotal       = 0;
        $this->handoffQueueNewTotal           = 0;
        $this->handoffQueueRefreshedTotal     = 0;
        $this->handoffQueueMissingStatusTotal = 0;
        $this->handoffQueueExamples           = [];
        $this->postStructureFilterCheckedTotal   = 0;
        $this->postStructureFilterRejectedTotal  = 0;
        $this->entryTimingCheckedTotal           = 0;
        $this->nearExitZoneRejectedTotal         = 0;
        $this->controlledTrendGateCheckedTotal   = 0;
        $this->controlledTrendGateRejectedTotal  = 0;
        $this->controlledTrendScoreTooLowTotal   = 0;
        $this->directionalConsistencyTooLowTotal = 0;
        $this->wickChaosTooHighTotal             = 0;
        $this->recentSwingTooHighTotal           = 0;
        $this->oppositeSwingTooHighTotal         = 0;
        $this->structureBreaksNotAllowedTotal    = 0;
        $this->smoothTrendCheckedTotal           = 0;
        $this->smoothTrendRejectedTotal          = 0;
        $this->smoothTrendScoreTooLowTotal       = 0;
        $this->verticalSpikeRejectedTotal        = 0;
        $this->acceptedEarlyStructureExamples    = [];
        $this->acceptedMidTrendExamples          = [];
        $this->rejectedLateEntryExamples         = [];
        $this->rejectedCombExamples              = [];
        $this->pattern123ValidButAntiCombRejectedExamples = [];
        $this->handoffCandidatesBeforeThrottleTotal = 0;
        $this->handoffAfterThrottleTotal            = 0;
        $this->handoffThrottledTotal                = 0;
        $this->handoffThrottleReasonCounts          = [];
        $this->handoffThrottleExamples              = [];
        // Funnel / split-reason counters
        $this->structuresDetectedTotal                    = 0;
        $this->pattern123StructuresSeenTotal              = 0;
        $this->pattern123ValidBeforeFiltersTotal          = 0;
        $this->pattern123InvalidBeforeFiltersTotal        = 0;
        $this->pattern123ValidButAntiCombRejectedTotal    = 0;
        $this->pattern123ValidButEntryTimingRejectedTotal = 0;
        $this->pattern123ValidButWallBlockedTotal         = 0;
        $this->pattern123ValidSignalReadyTotal            = 0;
        $this->candleSourceUsedCounts                     = ['parser2_history' => 0, 'bybit_fallback' => 0, 'none' => 0];
        $this->parser2HistoryUsedTotal                    = 0;
        $this->bybitFallbackUsedTotal                     = 0;
        $this->backward123CheckedTotal                    = 0;
        $this->backward123ValidTotal                      = 0;
        $this->backward123InvalidTotal                    = 0;
        $this->recentPoint3CandidatesTotal                = 0;
        $this->point3NearEntryTotal                       = 0;
        $this->point3TurnConfirmedTotal                   = 0;
        $this->point2FoundTotal                           = 0;
        $this->point1FoundTotal                           = 0;
        $this->structureBreaksAfterPoint3Total            = 0;
        $this->structureBreaksBeforePoint3IgnoredTotal    = 0;
        $this->backward123ValidExamples                   = [];
        $this->backward123InvalidExamples                 = [];
        $this->point3CandidateExamples                    = [];
        $this->parser2HistoryErrorExamples                = [];
        $this->lastCandleFetchDiag = [
            'candle_source_used' => 'none',
            'parser2_history_files_read' => 0,
            'parser2_history_candles_loaded' => 0,
            'parser2_history_candles_after_dedupe' => 0,
            'parser2_history_oldest_ts' => null,
            'parser2_history_newest_ts' => null,
            'bybit_fallback_used' => false,
            'candle_source_error' => null,
        ];
        $this->postStructureCombDetectedTotal             = 0;
        $this->antiCombPassedTotal                        = 0;
        $this->entryTimingPassedTotal                     = 0;
        $this->qualityGatePassedTotal                     = 0;
        $this->wallTestPassedTotal                        = 0;
        $this->signalReadyTotal                           = 0;
        $this->deprecatedExecutionModeKeySeen    = false;
        $this->deprecatedExecutionModeKeyIgnored = false;
        $this->effectiveStrategyMode             = 'passive';
        // Universe diagnostics
        $this->universeSource     = 'unknown';
        $this->universeTotal      = 0;
        $this->universeBatchCount = 0;
        $this->universeExamples   = [];
    }

    /**
     * Ensure bootstrap class file is loaded (safe to call multiple times).
     */
    private function requireBootstrap(): void
    {
        if (!class_exists(ConfirmedContinuationBootstrap::class, false)) {
            require_once $this->moduleDir . '/bootstrap.php';
        }
    }

    /**
     * Strategy is environment-neutral: legacy mode=demo/live in active config
     * is ignored and removed so bootstrap falls back to base passive mode.
     *
     * @return array{seen:bool,ignored:bool}
     */
    private function normalizeDeprecatedExecutionModeKeyInActiveConfig(): array
    {
        $activePath = $this->moduleDir . '/config/active.php';
        if (!is_file($activePath)) {
            return ['seen' => false, 'ignored' => false];
        }
        try {
            $active = require $activePath;
            if (!is_array($active)) {
                return ['seen' => false, 'ignored' => false];
            }
        } catch (\Throwable) {
            return ['seen' => false, 'ignored' => false];
        }

        $mode = (string)($active['mode'] ?? '');
        if (!in_array($mode, ['demo', 'live'], true)) {
            return ['seen' => false, 'ignored' => false];
        }

        unset($active['mode']);
        $safe = [];
        foreach ($active as $k => $v) {
            if (is_bool($v) || is_int($v) || is_float($v) || is_string($v)) {
                $safe[(string)$k] = $v;
            }
        }
        $php = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($safe, true) . ";\n";
        @file_put_contents($activePath, $php, LOCK_EX);

        return ['seen' => true, 'ignored' => true];
    }

    private function readJson(string $path, mixed $default = []): mixed
    {
        if (!is_file($path) || !is_readable($path)) {
            return $default;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return $default;
        }
        $decoded = @json_decode($raw, true);
        return ($decoded !== null) ? $decoded : $default;
    }

    private function writeJson(string $path, mixed $data): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json !== false && @file_put_contents($path, $json, LOCK_EX) !== false;
    }
}
