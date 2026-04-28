<?php

declare(strict_types=1);

/**
 * Controlled Daily Momentum Long — Strategy
 *
 * Detects narrow controlled daily momentum continuation longs.
 *
 * Target examples:
 *   APEUSDT ~+20%, ZEREBROUSDT ~+15%, ZKPUSDT ~+12%, VELODROMEUSDT/OPGUSDT ~+10%
 *
 * Pipeline per symbol:
 *   1. daily_momentum    — daily change in [hard_min, hard_max]; classify watch/valid/ideal
 *   2. anti_blowoff      — reject 1m/5m pumps, single-candle dominance, deep drawdown
 *   3. soft_turnover_ramp — 1h turnover ratio + 15m ramp + volume persistence + cliff check
 *   4. structure         — detect higher-low structure (min_higher_lows_count)
 *   5. pullback_reclaim  — impulse high → controlled pullback → hold → reclaim
 *   6. control_check     — entry distance from reclaim / structure; not at local high
 *   7. signal            — emit entry-focused signal payload
 *
 * IMPORTANT RULES:
 *   - Does NOT calculate leverage, stop-loss, ROI stop, budget, or PM/trailing decisions
 *   - Does NOT emit signals with signal_source_mode=governor_approved_demo
 *   - Does NOT send signals to bot queue when handoff_enabled = false
 *   - structure_break_reference_price is informational geometry only (not a stop)
 *   - Only writes to this strategy's own storage directory
 */

namespace Modules\Strategy\ControlledDailyMomentumLong;

final class ControlledDailyMomentumLongStrategy
{
    private const STRATEGY_ID = 'controlled_daily_momentum_long';

    private string $moduleDir;
    private array  $config;

    public function __construct(?string $moduleDir = null)
    {
        $this->moduleDir = $moduleDir !== null
            ? rtrim($moduleDir, '/')
            : rtrim(__DIR__, '/');

        $this->config = $this->loadConfig();
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Full symbol scan.
     * Strategy must be explicitly enabled in config; otherwise returns an error.
     */
    public function run(?array $symbols = null): array
    {
        if (!(bool)($this->config['enabled'] ?? false)) {
            return ['ok' => false, 'error' => 'strategy_disabled'];
        }

        return $this->execute($symbols, false);
    }

    /**
     * Safe simulation entry point.
     * Does NOT check 'enabled'. Does NOT send to bot queue.
     * Writes ONLY to this strategy's storage directory.
     */
    public function runSimulation(array $symbols = []): array
    {
        return $this->execute(empty($symbols) ? null : $symbols, true);
    }

    // ── Core pipeline ─────────────────────────────────────────────────────────

    private function execute(?array $symbols, bool $simulation): array
    {
        $config = $this->config;
        $now    = time();

        $symbols = $symbols ?? $this->buildUniverse($config);

        // ── Universe batching ─────────────────────────────────────────────────
        $runtime         = $this->readJson('storage/runtime.json', []);
        $cursor          = (int)($runtime['universe_cursor']   ?? 0);
        $universeCycleId = (int)($runtime['universe_cycle_id'] ?? 0);
        $maxCount        = (int)($config['max_symbols_per_run'] ?? 50);
        $universeTotal   = count($symbols);

        if ($cursor > $universeTotal) {
            $cursor = 0;
        }

        $universeWrapped = false;
        $batchStartIndex = $cursor;

        if ($maxCount > 0 && $universeTotal > $maxCount) {
            $batchSymbols = array_slice($symbols, $cursor, $maxCount);
            $nextCursor   = $cursor + $maxCount;
            if ($nextCursor >= $universeTotal) {
                $nextCursor      = 0;
                $universeCycleId++;
                $universeWrapped = true;
            }
        } else {
            $batchSymbols    = $symbols;
            $nextCursor      = 0;
            $universeWrapped = $universeTotal > 0;
            if ($universeWrapped) {
                $universeCycleId++;
            }
        }

        $batchEndIndex = $batchStartIndex + count($batchSymbols) - 1;
        $symbols       = $batchSymbols;

        // ── Initialise stats ──────────────────────────────────────────────────
        $stats = [
            'started_at'              => date('c', $now),
            'simulation'              => $simulation,
            // Explicit counters (new)
            'scanned_total'                  => 0,
            'universe_total'                 => $universeTotal,
            'batch_start_index'              => $batchStartIndex,
            'batch_end_index'                => $batchEndIndex,
            'next_cursor'                    => $nextCursor,
            'ignored_low_momentum_total'     => 0,
            'weak_watch_only_total'          => 0,
            'candidates_total'               => 0,
            'signals_total'                  => 0,
            'rejects_total'                  => 0,
            // Stage pass counters
            'daily_momentum_pass_total'      => 0,
            'anti_blowoff_pass_total'        => 0,
            'soft_turnover_pass_total'       => 0,
            'soft_turnover_warning_total'    => 0,
            'structure_pass_total'           => 0,
            'pullback_reclaim_pass_total'    => 0,
            'control_check_pass_total'       => 0,
            // Backwards-compatible existing counters
            'symbols_checked'         => 0,
            'no_candle_data'          => 0,
            'candidates_found'        => 0,
            'generated_signals_count' => 0,
            'rejected'                => 0,
            'handoff_enabled'         => false,
            'handoff_ready'           => 0,
            // Universe batching
            'batch_size'              => count($symbols),
            'universe_cycle_id'       => $universeCycleId,
            'universe_wrapped'        => $universeWrapped,
            // Reject breakdown
            'reject_reasons_normalized' => (object)[],
            'reject_examples'           => [],
            // Signal counters
            'signals_generated_current_run'          => 0,
            'signals_blocked_by_max_signals_per_run' => 0,
            // Handoff counters
            'handoff_candidates_total'        => 0,
            'handoff_rejected_invalid'        => 0,
            'handoff_reject_reasons'          => [],
            'handoff_limited_by_max_per_run'  => 0,
        ];

        $_rejectCounters = [];
        $_rejectExamples = [];

        $maxSignalsPerRun = max(1, (int)($config['max_signals_per_run'] ?? 10));
        $signalsThisRun   = 0;
        $hardMinPct       = (float)($config['hard_min_daily_change_pct'] ?? 5.0);

        // Load persisted state
        $candidates = $this->readJson('storage/candidates.json', []);
        $signals    = $this->readJson('storage/signals.json',    []);
        $rejects    = $this->readJson('storage/rejects.json',    []);

        $currentRunSignals       = [];
        $currentRunCandidateKeys = [];

        foreach ($symbols as $symbol) {
            $stats['scanned_total']++;
            $stats['symbols_checked']++;

            // ── 1. Fetch candles ──────────────────────────────────────────────
            $candles = $this->fetchCandles((string)$symbol, $config);
            if (count($candles) < 60) {
                $stats['no_candle_data']++;
                continue;
            }

            $lastClose    = (float)(end($candles)['close'] ?? 0.0);
            if ($lastClose <= 0.0) {
                $stats['no_candle_data']++;
                continue;
            }

            // ── 2. daily_momentum ─────────────────────────────────────────────
            $momentumResult = $this->pipelineDailyMomentum($candles, $config);
            $dailyChangePct = (float)($momentumResult['daily_change_pct'] ?? 0.0);
            $momentumClass  = (string)($momentumResult['class'] ?? 'ignore');

            // Symbols below hard_min: silently ignore (no candidate record)
            if ($momentumResult['reason'] === 'ignore_low_momentum_below_5pct' || $dailyChangePct < $hardMinPct) {
                $stats['ignored_low_momentum_total']++;
                $stats['rejected']++;
                $this->recordReject($symbol, $momentumResult['reason'], $_rejectCounters, $_rejectExamples, $rejects, $now, [
                    'daily_change_pct' => $dailyChangePct,
                ]);
                continue;
            }

            // All symbols >= hard_min are diagnostic candidates — write to candidates.json
            $candidateKey = strtolower($symbol) . '_' . self::STRATEGY_ID;
            $stats['candidates_total']++;
            $currentRunCandidateKeys[$candidateKey] = true; // track for top_current_candidates

            // Accumulate diagnostic fields as the symbol progresses through stages
            $diagFields = [
                'daily_change_pct' => $dailyChangePct,
                'momentum_class'   => $momentumClass,
            ];

            // Weak watch_only (daily >= hard_min but < min_daily): record and skip
            if (!$momentumResult['pass']) {
                $stats['weak_watch_only_total']++;
                $stats['rejected']++;
                $stats['rejects_total']++;
                $diagCandidate = array_merge([
                    'key'          => $candidateKey,
                    'symbol'       => $symbol,
                    'state'        => 'watch_only',
                    'failed_stage' => 'daily_momentum',
                    'decision'     => 'watch_only',
                    'reason'       => $momentumResult['reason'],
                    'updated_at'   => date('c', $now),
                ], $diagFields);
                $candidates = $this->upsertCandidate($candidates, $candidateKey, $diagCandidate);
                $this->recordReject($symbol, $momentumResult['reason'], $_rejectCounters, $_rejectExamples, $rejects, $now, $diagFields);
                continue;
            }

            // Momentum passed (daily >= min_daily)
            $open24h       = (float)($momentumResult['open_24h'] ?? 0.0);
            $high24h       = (float)($momentumResult['high_24h'] ?? 0.0);
            $low24h        = (float)($momentumResult['low_24h']  ?? 0.0);

            $stats['candidates_found']++;
            $stats['daily_momentum_pass_total']++;

            // ── 3. anti_blowoff ───────────────────────────────────────────────
            $blowoffResult = $this->pipelineAntiBlowoff($candles, $dailyChangePct, $high24h, $config);
            $diagFields = array_merge($diagFields, array_filter([
                'max_1m_pump_pct'        => $blowoffResult['max_1m_pump_pct']        ?? null,
                'max_5m_pump_pct'        => $blowoffResult['max_5m_pump_pct']        ?? null,
                'max_candle_share_pct'   => $blowoffResult['max_candle_share_pct']   ?? null,
                'drawdown_from_high_pct' => $blowoffResult['drawdown_from_high_pct'] ?? null,
            ], fn($v) => $v !== null));
            if (!$blowoffResult['pass']) {
                $stats['rejected']++;
                $stats['rejects_total']++;
                $diagCandidate = array_merge([
                    'key'          => $candidateKey,
                    'symbol'       => $symbol,
                    'state'        => 'rejected',
                    'failed_stage' => 'anti_blowoff',
                    'decision'     => 'rejected',
                    'reject_reasons' => [$blowoffResult['reason']],
                    'updated_at'   => date('c', $now),
                ], $diagFields);
                $candidates = $this->upsertCandidate($candidates, $candidateKey, $diagCandidate);
                $this->recordReject($symbol, $blowoffResult['reason'], $_rejectCounters, $_rejectExamples, $rejects, $now, $diagFields);
                continue;
            }
            $stats['anti_blowoff_pass_total']++;

            // ── 4. soft_turnover_ramp ─────────────────────────────────────────
            $turnoverResult = $this->pipelineSoftTurnoverRamp($candles, $config);
            $diagFields = array_merge($diagFields, array_filter([
                'turnover_1h_ratio'    => $turnoverResult['turnover_1h_ratio']    ?? null,
                'turnover_15m_ramp'    => $turnoverResult['turnover_15m_ramp']    ?? null,
                'persistence_bars'     => $turnoverResult['persistence_bars']     ?? null,
                'cliff_ratio'          => $turnoverResult['cliff_ratio']          ?? null,
                'soft_turnover_status' => $turnoverResult['soft_turnover_status'] ?? null,
                'turnover_warnings'    => !empty($turnoverResult['warnings']) ? $turnoverResult['warnings'] : null,
            ], fn($v) => $v !== null));
            if (!$turnoverResult['pass']) {
                $stats['rejected']++;
                $stats['rejects_total']++;
                $diagCandidate = array_merge([
                    'key'          => $candidateKey,
                    'symbol'       => $symbol,
                    'state'        => 'rejected',
                    'failed_stage' => 'soft_turnover_ramp',
                    'decision'     => 'rejected',
                    'reject_reasons' => [$turnoverResult['reason']],
                    'updated_at'   => date('c', $now),
                ], $diagFields);
                $candidates = $this->upsertCandidate($candidates, $candidateKey, $diagCandidate);
                $this->recordReject($symbol, $turnoverResult['reason'], $_rejectCounters, $_rejectExamples, $rejects, $now, $diagFields);
                continue;
            }
            $stats['soft_turnover_pass_total']++;
            if (!empty($turnoverResult['warnings'])) {
                $stats['soft_turnover_warning_total']++;
            }

            // ── 5. structure ──────────────────────────────────────────────────
            $structureResult = $this->pipelineStructure($candles, $config);
            $diagFields = array_merge($diagFields, array_filter([
                'higher_lows_count' => $structureResult['higher_lows_count'] ?? null,
            ], fn($v) => $v !== null));
            if (!$structureResult['pass']) {
                $stats['rejected']++;
                $stats['rejects_total']++;
                $diagCandidate = array_merge([
                    'key'          => $candidateKey,
                    'symbol'       => $symbol,
                    'state'        => 'rejected',
                    'failed_stage' => 'structure',
                    'decision'     => 'rejected',
                    'reject_reasons' => [$structureResult['reason']],
                    'updated_at'   => date('c', $now),
                ], $diagFields);
                $candidates = $this->upsertCandidate($candidates, $candidateKey, $diagCandidate);
                $this->recordReject($symbol, $structureResult['reason'], $_rejectCounters, $_rejectExamples, $rejects, $now, $diagFields);
                continue;
            }
            $stats['structure_pass_total']++;
            $structureBreakRef = $structureResult['structure_break_reference_price'];

            // ── 6. pullback_reclaim ───────────────────────────────────────────
            $pullbackResult = $this->pipelinePullbackReclaim($candles, $high24h, $config);
            $diagFields = array_merge($diagFields, array_filter([
                'pullback_depth_pct' => $pullbackResult['pullback_depth_pct'] ?? null,
                'reclaim_level'      => $pullbackResult['reclaim_level']      ?? null,
                'pullback_low'       => $pullbackResult['pullback_low']       ?? null,
            ], fn($v) => $v !== null));
            if (!$pullbackResult['pass']) {
                $stats['rejected']++;
                $stats['rejects_total']++;
                $diagCandidate = array_merge([
                    'key'          => $candidateKey,
                    'symbol'       => $symbol,
                    'state'        => 'rejected',
                    'failed_stage' => 'pullback_reclaim',
                    'decision'     => 'rejected',
                    'reject_reasons' => [$pullbackResult['reason']],
                    'updated_at'   => date('c', $now),
                ], $diagFields);
                $candidates = $this->upsertCandidate($candidates, $candidateKey, $diagCandidate);
                $this->recordReject($symbol, $pullbackResult['reason'], $_rejectCounters, $_rejectExamples, $rejects, $now, $diagFields);
                continue;
            }
            $stats['pullback_reclaim_pass_total']++;
            $impulseHigh   = $pullbackResult['impulse_high'];
            $pullbackLow   = $pullbackResult['pullback_low'];
            $reclaimLevel  = $pullbackResult['reclaim_level'];

            // ── 7. control_check ──────────────────────────────────────────────
            $controlResult = $this->pipelineControlCheck(
                $candles,
                $lastClose,
                $reclaimLevel,
                $structureBreakRef,
                $high24h,
                $config
            );
            $diagFields = array_merge($diagFields, array_filter([
                'entry_distance_from_reclaim_pct'   => $controlResult['dist_from_reclaim_pct']   ?? null,
                'entry_distance_from_structure_pct' => $controlResult['dist_from_structure_pct'] ?? null,
            ], fn($v) => $v !== null));
            if (!$controlResult['pass']) {
                $stats['rejected']++;
                $stats['rejects_total']++;
                $diagCandidate = array_merge([
                    'key'          => $candidateKey,
                    'symbol'       => $symbol,
                    'state'        => 'rejected',
                    'failed_stage' => 'control_check',
                    'decision'     => 'rejected',
                    'reject_reasons' => [$controlResult['reason']],
                    'updated_at'   => date('c', $now),
                ], $diagFields);
                $candidates = $this->upsertCandidate($candidates, $candidateKey, $diagCandidate);
                $this->recordReject($symbol, $controlResult['reason'], $_rejectCounters, $_rejectExamples, $rejects, $now, $diagFields);
                continue;
            }
            $stats['control_check_pass_total']++;

            // ── Per-run signal cap ────────────────────────────────────────────
            if ($signalsThisRun >= $maxSignalsPerRun) {
                $stats['signals_blocked_by_max_signals_per_run']++;
                $diagCandidate = array_merge([
                    'key'        => $candidateKey,
                    'symbol'     => $symbol,
                    'state'      => 'cap_reached',
                    'decision'   => 'cap_reached',
                    'updated_at' => date('c', $now),
                ], $diagFields);
                $candidates   = $this->upsertCandidate($candidates, $candidateKey, $diagCandidate);
                continue;
            }

            // ── 8. signal ─────────────────────────────────────────────────────
            $entryPrice = $lastClose;
            $signalId   = $this->makeSignalId($symbol, $now);

            $entryDistFromReclaimPct   = $reclaimLevel > 0
                ? round((($entryPrice - $reclaimLevel) / $reclaimLevel) * 100, 4)
                : 0.0;
            $entryDistFromStructurePct = $structureBreakRef > 0
                ? round((($entryPrice - $structureBreakRef) / $structureBreakRef) * 100, 4)
                : 0.0;

            // Score components (0–10 each)
            $dailyMomentumScore    = $this->scoreDailyMomentum($dailyChangePct, $config);
            $controlledMoveScore   = $this->scoreControlledMove($blowoffResult);
            $softTurnoverRampScore = $this->scoreSoftTurnoverRamp($turnoverResult);
            $volumePersistenceScore= $this->scoreVolumePersistence($turnoverResult);
            $structureScore        = $this->scoreStructure($structureResult);
            $pullbackScore         = $this->scorePullback($pullbackResult, $config);
            $reclaimScore          = $this->scoreReclaim($controlResult);
            $entryPrecisionScore   = $this->scoreEntryPrecision($entryDistFromReclaimPct, $entryDistFromStructurePct);
            $lateEntryScore        = max(0, 10 - (int)round(abs($entryDistFromReclaimPct) * 2));

            $candidateQualityScore = round((
                $dailyMomentumScore + $controlledMoveScore + $softTurnoverRampScore
                + $volumePersistenceScore + $structureScore + $pullbackScore
                + $reclaimScore + $entryPrecisionScore
            ) / 8, 2);

            $signal = [
                // Identity
                'strategy_id'     => self::STRATEGY_ID,
                'signal_id'       => $signalId,
                'symbol'          => $symbol,
                'side'            => 'long',
                'mode'            => 'demo', // controlled_daily_momentum_long is demo-only

                // Entry
                'entry_type'      => 'controlled_reclaim',
                'entry_price'     => $entryPrice,

                // Pattern
                'primary_pattern' => 'controlled_daily_momentum_long',

                // Timestamps
                'detected_at'     => date('c', $now),
                'created_at'      => date('c', $now),

                // Geometry (informational only — not stop-loss)
                'daily_change_pct'                  => round($dailyChangePct, 4),
                'reclaim_level'                     => round($reclaimLevel, 6),
                'pullback_low'                      => round($pullbackLow, 6),
                'structure_break_reference_price'   => round($structureBreakRef, 6),
                'entry_distance_from_reclaim_pct'   => $entryDistFromReclaimPct,
                'entry_distance_from_structure_pct' => $entryDistFromStructurePct,

                // Scores
                'entry_precision_score'    => $entryPrecisionScore,
                'late_entry_score'         => $lateEntryScore,
                'daily_momentum_score'     => $dailyMomentumScore,
                'controlled_move_score'    => $controlledMoveScore,
                'soft_turnover_ramp_score' => $softTurnoverRampScore,
                'volume_persistence_score' => $volumePersistenceScore,
                'structure_score'          => $structureScore,
                'pullback_score'           => $pullbackScore,
                'reclaim_score'            => $reclaimScore,
                'candidate_quality_score'  => $candidateQualityScore,

                // Reason codes
                'reason_codes'         => $this->collectReasonCodes($momentumResult, $blowoffResult, $turnoverResult, $structureResult, $pullbackResult, $controlResult),
                'reject_reasons'       => [],
                'turnover_warnings'    => $turnoverResult['warnings']    ?? [],
                'soft_turnover_status' => $turnoverResult['soft_turnover_status'] ?? 'ok',

                // Momentum class
                'momentum_class' => $momentumClass,
            ];

            $signals           = $this->upsertSignal($signals, $symbol, $signal);
            $currentRunSignals[] = $signal;
            $signalsThisRun++;
            $stats['generated_signals_count']++;
            $stats['signals_generated_current_run']++;
            $stats['signals_total']++;

            // Write diagnostic candidate with state='signal' (keep in candidates.json)
            $diagFields['candidate_quality_score']              = $candidateQualityScore;
            $diagFields['entry_distance_from_reclaim_pct']      = $entryDistFromReclaimPct;
            $diagFields['entry_distance_from_structure_pct']    = $entryDistFromStructurePct;
            $diagCandidate = array_merge([
                'key'        => $candidateKey,
                'symbol'     => $symbol,
                'state'      => 'signal',
                'decision'   => 'emit',
                'reason'     => 'all_stages_passed',
                'updated_at' => date('c', $now),
            ], $diagFields);
            $candidates = $this->upsertCandidate($candidates, $candidateKey, $diagCandidate);
        }

        // ── PART 3: Normalized reject counters ────────────────────────────────
        arsort($_rejectCounters);
        $stats['reject_reasons_normalized'] = (object)$_rejectCounters;
        $stats['reject_examples']           = array_slice($_rejectExamples, 0, 10);

        // ── Build top_candidates for last_run.json ────────────────────────────
        // Sort: signal first, then by candidate_quality_score desc, daily_change_pct desc, updated_at desc
        $sortComparator = function (array $a, array $b): int {
            $stateOrder = ['signal' => 0, 'cap_reached' => 1, 'watch_only' => 2, 'rejected' => 3];
            $sa = $stateOrder[$a['state'] ?? ''] ?? 9;
            $sb = $stateOrder[$b['state'] ?? ''] ?? 9;
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }
            $qa = (float)($a['candidate_quality_score'] ?? 0.0);
            $qb = (float)($b['candidate_quality_score'] ?? 0.0);
            if (abs($qa - $qb) > 0.001) {
                return $qb <=> $qa;
            }
            $da = (float)($a['daily_change_pct'] ?? 0.0);
            $db = (float)($b['daily_change_pct'] ?? 0.0);
            if (abs($da - $db) > 0.001) {
                return $db <=> $da;
            }
            return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
        };

        $candidatesForSort = array_filter($candidates, fn($c) => ($c['key'] ?? '') !== '');
        usort($candidatesForSort, $sortComparator);
        $stats['top_candidates'] = array_values(array_slice($candidatesForSort, 0, 10));

        // ── Build top_current_candidates (only candidates touched this run) ───
        $currentRunCandidates = array_filter(
            $candidates,
            fn($c) => isset($currentRunCandidateKeys[$c['key'] ?? ''])
        );
        usort($currentRunCandidates, $sortComparator);
        $stats['current_run_candidates_total'] = count($currentRunCandidates);
        $stats['top_current_candidates']       = array_values(array_slice($currentRunCandidates, 0, 10));

        // ── Persist state ─────────────────────────────────────────────────────
        $this->initStorage();
        $this->writeJson('storage/candidates.json',      $candidates);
        $this->writeJson('storage/signals.json',         $signals);
        $this->writeJson('storage/rejects.json',         $rejects);

        // Universe cursor
        $runtime['universe_cursor']   = $nextCursor;
        $runtime['universe_cycle_id'] = $universeCycleId;
        $runtime['last_run_at']       = date('c', $now);
        $this->writeJson('storage/runtime.json', $runtime);

        // ── Handoff queue ─────────────────────────────────────────────────────
        $handoffEnabled = (bool)($config['handoff_enabled'] ?? false);
        $stats['handoff_enabled'] = $handoffEnabled;

        $maxHandoffPerRun            = (int)($config['max_handoff_per_run'] ?? 0);
        $handoffCandidatesTotal      = 0;
        $handoffReady                = 0;
        $handoffRejectedInvalid      = 0;
        $handoffRejectReasons        = [];
        $handoffLimitedByMaxPerRun   = 0;

        if ($handoffEnabled && $maxHandoffPerRun > 0) {
            $handoffQueue = [];

            foreach ($currentRunSignals as $sig) {
                $handoffCandidatesTotal++;

                $validationError = $this->validateSignalForHandoff($sig);
                if ($validationError !== null) {
                    $handoffRejectedInvalid++;
                    $handoffRejectReasons[] = ($sig['symbol'] ?? '?') . ':' . $validationError;
                    continue;
                }

                if ($handoffReady >= $maxHandoffPerRun) {
                    $handoffLimitedByMaxPerRun++;
                    continue;
                }

                $handoffQueue[] = array_merge($sig, [
                    'handoff_status'    => 'new',
                    'first_seen_at'     => date('c', $now),
                    'last_refreshed_at' => date('c', $now),
                    'seen_count'        => 1,
                ]);
                $handoffReady++;
            }

            $this->writeJson('storage/bot_handoff_queue.json', $handoffQueue);
        } else {
            // handoff disabled or max_handoff_per_run = 0: always clear queue
            $this->writeJson('storage/bot_handoff_queue.json', []);
        }

        $stats['handoff_candidates_total']       = $handoffCandidatesTotal;
        $stats['handoff_ready']                  = $handoffReady;
        $stats['handoff_rejected_invalid']       = $handoffRejectedInvalid;
        $stats['handoff_reject_reasons']         = $handoffRejectReasons;
        $stats['handoff_limited_by_max_per_run'] = $handoffLimitedByMaxPerRun;

        // ── Stats file ────────────────────────────────────────────────────────
        $statsFile = $this->readJson('storage/stats.json', [
            'total_runs'             => 0,
            'total_signals_emitted'  => 0,
            'total_symbols_checked'  => 0,
        ]);
        $statsFile['total_runs']++;
        $statsFile['total_signals_emitted'] += $stats['generated_signals_count'];
        $statsFile['total_symbols_checked'] += $stats['symbols_checked'];
        $statsFile['last_run_at']            = date('c', $now);
        $this->writeJson('storage/stats.json', $statsFile);

        // ── last_run diagnostics ──────────────────────────────────────────────
        $stats['finished_at'] = date('c');
        $this->writeJson('storage/last_run.json', $stats);

        return ['ok' => true, 'stats' => $stats];
    }

    // ── Pipeline steps ────────────────────────────────────────────────────────

    /**
     * Step 1: daily_momentum
     * Computes 24h price change and classifies: ignore / watch_only / valid / ideal.
     */
    private function pipelineDailyMomentum(array $candles, array $config): array
    {
        $hardMin  = (float)($config['hard_min_daily_change_pct']  ?? 5.0);
        $minValid = (float)($config['min_daily_change_pct']       ?? 8.0);
        $idealMin = (float)($config['ideal_min_daily_change_pct'] ?? 10.0);
        $idealMax = (float)($config['ideal_max_daily_change_pct'] ?? 25.0);
        $hardMax  = (float)($config['hard_max_daily_change_pct']  ?? 35.0);

        $count = count($candles);
        $lookback = min(1440, $count);

        $slice   = array_slice($candles, $count - $lookback);
        $open24h = (float)($slice[0]['open'] ?? $slice[0]['close'] ?? 0.0);
        $close   = (float)(end($candles)['close'] ?? 0.0);
        $high24h = (float)(max(array_column($slice, 'high')) ?: $close);
        $low24h  = (float)(min(array_column($slice, 'low'))  ?: $close);

        if ($open24h <= 0.0 || $close <= 0.0) {
            return ['pass' => false, 'reason' => 'no_candle_data',
                'daily_change_pct' => 0.0, 'open_24h' => 0.0,
                'high_24h' => 0.0, 'low_24h' => 0.0, 'class' => 'ignore'];
        }

        $dailyChangePct = (($close - $open24h) / $open24h) * 100;

        if ($dailyChangePct < $hardMin) {
            return ['pass' => false, 'reason' => 'ignore_low_momentum_below_5pct',
                'daily_change_pct' => round($dailyChangePct, 4),
                'open_24h' => $open24h, 'high_24h' => $high24h, 'low_24h' => $low24h,
                'class' => 'ignore'];
        }

        if ($dailyChangePct >= $hardMin && $dailyChangePct < $minValid) {
            return ['pass' => false, 'reason' => 'watch_only_weak_momentum',
                'daily_change_pct' => round($dailyChangePct, 4),
                'open_24h' => $open24h, 'high_24h' => $high24h, 'low_24h' => $low24h,
                'class' => 'watch_only'];
        }

        if ($dailyChangePct > $hardMax) {
            return ['pass' => false, 'reason' => 'daily_change_too_high',
                'daily_change_pct' => round($dailyChangePct, 4),
                'open_24h' => $open24h, 'high_24h' => $high24h, 'low_24h' => $low24h,
                'class' => 'observation'];
        }

        $class = ($dailyChangePct >= $idealMin && $dailyChangePct <= $idealMax) ? 'ideal' : 'valid';

        return [
            'pass'            => true,
            'reason'          => 'momentum_ok',
            'daily_change_pct'=> round($dailyChangePct, 4),
            'open_24h'        => $open24h,
            'high_24h'        => $high24h,
            'low_24h'         => $low24h,
            'class'           => $class,
        ];
    }

    /**
     * Step 2: anti_blowoff
     * Reject symbols with vertical pump characteristics.
     */
    private function pipelineAntiBlowoff(array $candles, float $dailyChangePct, float $high24h, array $config): array
    {
        $max1mPump       = (float)($config['max_1m_pump_pct']                    ?? 3.0);
        $max5mPump       = (float)($config['max_5m_pump_pct']                    ?? 8.0);
        $maxCandleShare  = (float)($config['max_single_candle_share_of_move_pct']?? 35.0);
        $maxDrawdown     = (float)($config['max_drawdown_from_24h_high_pct']     ?? 18.0);

        $count   = count($candles);
        $lastBar = end($candles);
        $close   = (float)($lastBar['close'] ?? 0.0);

        // 1m pump: largest single-candle move in last 10 bars
        $last10 = array_slice($candles, max(0, $count - 10));
        $max1m  = 0.0;
        foreach ($last10 as $bar) {
            $o = (float)($bar['open']  ?? 0);
            $c = (float)($bar['close'] ?? 0);
            if ($o > 0) {
                $pct = (($c - $o) / $o) * 100;
                if ($pct > $max1m) {
                    $max1m = $pct;
                }
            }
        }
        if ($max1m > $max1mPump) {
            return ['pass' => false, 'reason' => 'blowoff_1m_pump',
                'max_1m_pump_pct' => round($max1m, 4), 'max_5m_pump_pct' => 0.0,
                'max_candle_share_pct' => 0.0, 'drawdown_from_high_pct' => 0.0];
        }

        // 5m pump: aggregate open-to-close of last 5 bars
        $last5   = array_slice($candles, max(0, $count - 5));
        $open5m  = (float)(($last5[0]['open'] ?? $last5[0]['close']) ?: 0);
        $close5m = (float)(end($last5)['close'] ?? 0);
        $pump5m  = ($open5m > 0) ? (($close5m - $open5m) / $open5m) * 100 : 0.0;
        if ($pump5m > $max5mPump) {
            return ['pass' => false, 'reason' => 'blowoff_5m_pump',
                'max_1m_pump_pct' => round($max1m, 4), 'max_5m_pump_pct' => round($pump5m, 4),
                'max_candle_share_pct' => 0.0, 'drawdown_from_high_pct' => 0.0];
        }

        // Single candle share of total daily move
        $totalMove = abs($dailyChangePct);
        $largestCandleSharePct = 0.0;
        if ($totalMove > 0) {
            foreach ($candles as $bar) {
                $o = (float)($bar['open']  ?? 0);
                $c = (float)($bar['close'] ?? 0);
                if ($o > 0) {
                    $share = abs(($c - $o) / $o * 100 / $totalMove) * 100;
                    if ($share > $largestCandleSharePct) {
                        $largestCandleSharePct = $share;
                    }
                }
            }
        }
        if ($largestCandleSharePct > $maxCandleShare) {
            return ['pass' => false, 'reason' => 'single_candle_move_too_large',
                'max_1m_pump_pct' => round($max1m, 4), 'max_5m_pump_pct' => round($pump5m, 4),
                'max_candle_share_pct' => round($largestCandleSharePct, 2), 'drawdown_from_high_pct' => 0.0];
        }

        // Deep drawdown from 24h high
        $drawdownPct = ($high24h > 0 && $close > 0)
            ? (($high24h - $close) / $high24h) * 100
            : 0.0;
        if ($drawdownPct > $maxDrawdown) {
            return ['pass' => false, 'reason' => 'deep_drawdown_from_high',
                'max_1m_pump_pct' => round($max1m, 4), 'max_5m_pump_pct' => round($pump5m, 4),
                'max_candle_share_pct' => round($largestCandleSharePct, 2),
                'drawdown_from_high_pct' => round($drawdownPct, 4)];
        }

        return [
            'pass'                  => true,
            'reason'                => 'anti_blowoff_ok',
            'max_1m_pump_pct'       => round($max1m, 4),
            'max_5m_pump_pct'       => round($pump5m, 4),
            'max_candle_share_pct'  => round($largestCandleSharePct, 2),
            'drawdown_from_high_pct'=> round($drawdownPct, 4),
        ];
    }

    /**
     * Step 3: soft_turnover_ramp
     * 1h turnover vs 24h average + 15m ramp + volume persistence + cliff check.
     */
    private function pipelineSoftTurnoverRamp(array $candles, array $config): array
    {
        // Hard/soft thresholds — new keys; fall back to legacy keys for backward compat
        $hardMinRatio1h    = (float)($config['min_turnover_1h_vs_avg_24h_hard']  ?? 0.8);
        $softMinRatio1h    = (float)($config['min_turnover_1h_vs_avg_24h_soft']
                              ?? $config['min_turnover_1h_vs_avg_24h']            ?? 1.3);
        $maxRatio1h        = (float)($config['max_turnover_1h_vs_avg_24h']        ?? 4.0);
        $minRamp15m        = (float)($config['min_turnover_ramp_15m_ratio']       ?? 1.15);
        $persWindow        = (int)($config['volume_persistence_window']           ?? 4);
        $persHard          = (int)($config['min_persistence_candles_hard']        ?? 1);
        $persSoft          = (int)($config['min_persistence_candles_soft']
                              ?? $config['min_volume_persistence_candles']        ?? 3);
        $hardCliff         = (float)($config['hard_volume_cliff_ratio']           ?? 0.15);
        $warnCliff         = (float)($config['warning_volume_cliff_ratio']
                              ?? $config['max_volume_cliff_ratio']                ?? 0.45);
        $strongRamp        = (float)($config['strong_ramp_override_ratio']        ?? 1.5);
        $strongTurnover    = (float)($config['strong_turnover_override_ratio']    ?? 2.0);

        $count     = count($candles);
        $volumes   = array_column($candles, 'volume');
        $avgVol24h = count($volumes) > 0 ? array_sum($volumes) / count($volumes) : 0.0;

        if ($avgVol24h <= 0.0) {
            return [
                'pass'               => false,
                'reason'             => 'turnover_too_low',
                'warnings'           => [],
                'soft_turnover_status' => 'reject',
                'turnover_1h_ratio'  => 0.0,
                'turnover_15m_ramp'  => 0.0,
                'persistence_bars'   => 0,
                'cliff_ratio'        => 0.0,
            ];
        }

        // 1h turnover (last 60 bars)
        $last60    = array_slice($candles, max(0, $count - 60));
        $vol1h     = array_sum(array_column($last60, 'volume'));
        $avg1hBase = $avgVol24h * 60;
        $ratio1h   = ($avg1hBase > 0) ? $vol1h / $avg1hBase : 0.0;

        // Hard reject: turnover below hard minimum
        if ($ratio1h < $hardMinRatio1h) {
            return [
                'pass'               => false,
                'reason'             => 'turnover_too_low',
                'warnings'           => [],
                'soft_turnover_status' => 'reject',
                'turnover_1h_ratio'  => round($ratio1h, 4),
                'turnover_15m_ramp'  => 0.0,
                'persistence_bars'   => 0,
                'cliff_ratio'        => 0.0,
            ];
        }

        // Hard reject: turnover spike too large
        if ($ratio1h > $maxRatio1h) {
            return [
                'pass'               => false,
                'reason'             => 'turnover_spike_too_large',
                'warnings'           => [],
                'soft_turnover_status' => 'reject',
                'turnover_1h_ratio'  => round($ratio1h, 4),
                'turnover_15m_ramp'  => 0.0,
                'persistence_bars'   => 0,
                'cliff_ratio'        => 0.0,
            ];
        }

        $warnings = [];
        $softTurnoverStatus = 'ok';

        // Soft warning: turnover between hard and soft thresholds
        if ($ratio1h < $softMinRatio1h) {
            $warnings[]         = 'turnover_soft_below_target';
            $softTurnoverStatus = 'warning';
        }

        // 15m turnover ramp (last 15 vs prior 15)
        $last15 = array_slice($candles, max(0, $count - 15));
        $prev15 = array_slice($candles, max(0, $count - 30), 15);
        $volL15 = array_sum(array_column($last15, 'volume'));
        $volP15 = array_sum(array_column($prev15, 'volume'));
        $ramp15 = ($volP15 > 0) ? $volL15 / $volP15 : 1.0;

        // Volume persistence
        $persSlice    = array_slice($candles, max(0, $count - $persWindow));
        $persAboveAvg = 0;
        $peakVol      = 0.0;
        foreach ($persSlice as $bar) {
            $v = (float)($bar['volume'] ?? 0);
            if ($v > $peakVol) {
                $peakVol = $v;
            }
            if ($v >= $avgVol24h) {
                $persAboveAvg++;
            }
        }

        // Volume cliff check: last bar vs peak
        $lastVol          = (float)(end($candles)['volume'] ?? 0.0);
        $cliffRatioActual = ($peakVol > 0) ? $lastVol / $peakVol : 1.0;

        // ── Adaptive override: strong turnover + strong ramp ──────────────────
        // If both turnover and ramp are strong, persistence requirement relaxes to >= 2
        $isStrongSignal = ($ratio1h >= $strongTurnover && $ramp15 >= $strongRamp);

        // ── Hard reject: ramp below minimum (no override for ramp) ────────────
        if ($ramp15 < $minRamp15m) {
            // Not a hard reject — soft warning only; strong turnover can carry this
            $warnings[] = 'turnover_ramp_soft_low';
            if ($softTurnoverStatus === 'ok') {
                $softTurnoverStatus = 'warning';
            }
            // Only hard-reject the ramp if turnover is also NOT strong
            if (!$isStrongSignal) {
                return [
                    'pass'               => false,
                    'reason'             => 'turnover_not_persistent',
                    'warnings'           => $warnings,
                    'soft_turnover_status' => 'reject',
                    'turnover_1h_ratio'  => round($ratio1h, 4),
                    'turnover_15m_ramp'  => round($ramp15, 4),
                    'persistence_bars'   => $persAboveAvg,
                    'cliff_ratio'        => round($cliffRatioActual, 4),
                ];
            }
        }

        // ── Persistence checks ────────────────────────────────────────────────
        $minPersistence = $persHard; // default hard minimum
        if ($isStrongSignal) {
            // Strong signal: allow persistence >= 2 even if below soft target
            $minPersistence = 2;
        }

        if ($persAboveAvg < $persHard) {
            // Always a hard reject: below absolute minimum
            return [
                'pass'               => false,
                'reason'             => 'turnover_not_persistent',
                'warnings'           => $warnings,
                'soft_turnover_status' => 'reject',
                'turnover_1h_ratio'  => round($ratio1h, 4),
                'turnover_15m_ramp'  => round($ramp15, 4),
                'persistence_bars'   => $persAboveAvg,
                'cliff_ratio'        => round($cliffRatioActual, 4),
            ];
        }

        if ($persAboveAvg < $minPersistence) {
            // Below adaptive minimum (only possible when isStrongSignal and minPersistence=2)
            return [
                'pass'               => false,
                'reason'             => 'turnover_not_persistent',
                'warnings'           => $warnings,
                'soft_turnover_status' => 'reject',
                'turnover_1h_ratio'  => round($ratio1h, 4),
                'turnover_15m_ramp'  => round($ramp15, 4),
                'persistence_bars'   => $persAboveAvg,
                'cliff_ratio'        => round($cliffRatioActual, 4),
            ];
        }

        if ($persAboveAvg < $persSoft) {
            // Below soft target but >= hard minimum: warning only
            $warnings[] = 'turnover_persistence_marginal';
            if ($softTurnoverStatus === 'ok') {
                $softTurnoverStatus = 'warning';
            }
        }

        // ── Cliff checks ──────────────────────────────────────────────────────
        if ($cliffRatioActual < $hardCliff) {
            // Hard reject: cliff ratio below hard minimum
            return [
                'pass'               => false,
                'reason'             => 'volume_cliff_after_pump',
                'warnings'           => $warnings,
                'soft_turnover_status' => 'reject',
                'turnover_1h_ratio'  => round($ratio1h, 4),
                'turnover_15m_ramp'  => round($ramp15, 4),
                'persistence_bars'   => $persAboveAvg,
                'cliff_ratio'        => round($cliffRatioActual, 4),
            ];
        }

        if ($cliffRatioActual < $warnCliff) {
            // Warning-level cliff
            $cliffWarning = true;
            $warnings[]   = 'volume_cliff_warning';
            if ($softTurnoverStatus === 'ok') {
                $softTurnoverStatus = 'warning';
            }

            // Adaptive pass: if persistence >= soft target AND ramp >= min, allow through with warning
            $canOverrideCliff = ($persAboveAvg >= $persSoft && $ramp15 >= $minRamp15m);
            if (!$canOverrideCliff) {
                return [
                    'pass'               => false,
                    'reason'             => 'volume_cliff_after_pump',
                    'warnings'           => $warnings,
                    'soft_turnover_status' => 'reject',
                    'turnover_1h_ratio'  => round($ratio1h, 4),
                    'turnover_15m_ramp'  => round($ramp15, 4),
                    'persistence_bars'   => $persAboveAvg,
                    'cliff_ratio'        => round($cliffRatioActual, 4),
                ];
            }
        }

        return [
            'pass'               => true,
            'reason'             => 'turnover_ramp_ok',
            'warnings'           => $warnings,
            'soft_turnover_status' => $softTurnoverStatus,
            'turnover_1h_ratio'  => round($ratio1h, 4),
            'turnover_15m_ramp'  => round($ramp15, 4),
            'persistence_bars'   => $persAboveAvg,
            'cliff_ratio'        => round($cliffRatioActual, 4),
        ];
    }

    /**
     * Step 4: structure
     * Detect higher-low structure in recent candles.
     */
    private function pipelineStructure(array $candles, array $config): array
    {
        $minHigherLows = (int)($config['min_higher_lows_count'] ?? 2);
        $count         = count($candles);

        // Check last 60 bars for consecutive higher lows
        $slice        = array_slice($candles, max(0, $count - 60));
        $higherLows   = 0;
        $prevLow      = PHP_FLOAT_MAX;
        $structureRef = 0.0;

        foreach ($slice as $bar) {
            $low = (float)($bar['low'] ?? 0);
            if ($low <= 0) {
                continue;
            }
            if ($prevLow < PHP_FLOAT_MAX) {
                if ($low > $prevLow) {
                    $higherLows++;
                    if ($higherLows === 1) {
                        // First higher low — use previous low as structure reference
                        $structureRef = $prevLow;
                    }
                } else {
                    $higherLows = 0;
                    $structureRef = 0.0;
                }
            }
            $prevLow = $low;
        }

        if ($higherLows < $minHigherLows) {
            return ['pass' => false, 'reason' => 'no_higher_low_structure',
                'higher_lows_count' => $higherLows,
                'structure_break_reference_price' => 0.0];
        }

        // Use the close of the last bar as a fallback structure reference
        if ($structureRef <= 0.0) {
            $structureRef = (float)(end($candles)['close'] ?? 0.0);
        }

        return [
            'pass'                           => true,
            'reason'                         => 'structure_ok',
            'higher_lows_count'              => $higherLows,
            'structure_break_reference_price'=> round($structureRef, 6),
        ];
    }

    /**
     * Step 5: pullback_reclaim
     * Detect impulse high → controlled pullback → hold → reclaim pattern.
     */
    private function pipelinePullbackReclaim(array $candles, float $high24h, array $config): array
    {
        $minPullback     = (float)($config['min_pullback_depth_pct'] ?? 1.5);
        $maxPullback     = (float)($config['max_pullback_depth_pct'] ?? 7.0);
        $confirmRequired = (bool)($config['confirm_required']        ?? true);
        $confirmMaxBars  = (int)($config['confirm_max_bars']         ?? 3);

        $count   = count($candles);
        $last30  = array_slice($candles, max(0, $count - 30));

        if (count($last30) < 5) {
            return ['pass' => false, 'reason' => 'no_candle_data',
                'impulse_high' => 0.0, 'pullback_low' => 0.0, 'reclaim_level' => 0.0];
        }

        // Find impulse high in last 30 bars
        $impulseHigh = 0.0;
        $impulseIdx  = 0;
        foreach ($last30 as $idx => $bar) {
            $h = (float)($bar['high'] ?? 0);
            if ($h > $impulseHigh) {
                $impulseHigh = $h;
                $impulseIdx  = $idx;
            }
        }

        if ($impulseHigh <= 0.0) {
            return ['pass' => false, 'reason' => 'no_candle_data',
                'impulse_high' => 0.0, 'pullback_low' => 0.0, 'reclaim_level' => 0.0];
        }

        // Find pullback low after impulse high
        $afterImpulse = array_slice($last30, $impulseIdx + 1);
        if (count($afterImpulse) < 2) {
            return ['pass' => false, 'reason' => 'pullback_too_shallow',
                'impulse_high' => round($impulseHigh, 6),
                'pullback_low' => 0.0, 'reclaim_level' => 0.0];
        }

        $pullbackLow = PHP_FLOAT_MAX;
        foreach ($afterImpulse as $bar) {
            $l = (float)($bar['low'] ?? 0);
            if ($l > 0 && $l < $pullbackLow) {
                $pullbackLow = $l;
            }
        }

        if ($pullbackLow === PHP_FLOAT_MAX) {
            return ['pass' => false, 'reason' => 'pullback_too_shallow',
                'impulse_high' => round($impulseHigh, 6),
                'pullback_low' => 0.0, 'reclaim_level' => 0.0];
        }

        $pullbackDepthPct = (($impulseHigh - $pullbackLow) / $impulseHigh) * 100;

        if ($pullbackDepthPct < $minPullback) {
            return ['pass' => false, 'reason' => 'pullback_too_shallow',
                'impulse_high' => round($impulseHigh, 6),
                'pullback_low' => round($pullbackLow, 6),
                'reclaim_level' => 0.0];
        }

        if ($pullbackDepthPct > $maxPullback) {
            return ['pass' => false, 'reason' => 'pullback_too_deep',
                'impulse_high' => round($impulseHigh, 6),
                'pullback_low' => round($pullbackLow, 6),
                'reclaim_level' => 0.0];
        }

        // Reclaim level = midpoint of pullback range
        $reclaimLevel = ($impulseHigh + $pullbackLow) / 2;

        // Confirmation: last N candles must close above reclaim level (2 of 3)
        $lastClose = (float)(end($candles)['close'] ?? 0.0);

        if ($confirmRequired) {
            $confirmSlice = array_slice($candles, max(0, $count - $confirmMaxBars));
            $aboveReclaim = 0;
            foreach ($confirmSlice as $bar) {
                if ((float)($bar['close'] ?? 0) >= $reclaimLevel) {
                    $aboveReclaim++;
                }
            }
            $required = (int)ceil($confirmMaxBars * 2 / 3);
            if ($aboveReclaim < $required) {
                return ['pass' => false, 'reason' => 'reclaim_not_confirmed',
                    'impulse_high'  => round($impulseHigh, 6),
                    'pullback_low'  => round($pullbackLow, 6),
                    'reclaim_level' => round($reclaimLevel, 6)];
            }
        }

        return [
            'pass'              => true,
            'reason'            => 'pullback_reclaim_ok',
            'impulse_high'      => round($impulseHigh, 6),
            'pullback_low'      => round($pullbackLow, 6),
            'reclaim_level'     => round($reclaimLevel, 6),
            'pullback_depth_pct'=> round($pullbackDepthPct, 4),
        ];
    }

    /**
     * Step 6: control_check
     * Verify entry is not at local high, and within allowed distance from reclaim/structure.
     */
    private function pipelineControlCheck(
        array $candles,
        float $close,
        float $reclaimLevel,
        float $structureRef,
        float $high24h,
        array $config
    ): array {
        $maxDistReclaim   = (float)($config['max_entry_distance_from_reclaim_pct']   ?? 2.5);
        $maxDistStructure = (float)($config['max_entry_distance_from_structure_pct'] ?? 5.0);

        // Entry at local high guard: close must be below the 24h high
        if ($high24h > 0 && $close >= $high24h * 0.995) {
            return ['pass' => false, 'reason' => 'entry_at_local_high',
                'reclaim_confirmed' => false, 'dist_from_reclaim_pct' => 0.0,
                'dist_from_structure_pct' => 0.0];
        }

        // Distance from reclaim
        $distReclaim = ($reclaimLevel > 0)
            ? (($close - $reclaimLevel) / $reclaimLevel) * 100
            : 0.0;

        if ($distReclaim > $maxDistReclaim) {
            return ['pass' => false, 'reason' => 'entry_too_late_after_reclaim',
                'reclaim_confirmed' => true, 'dist_from_reclaim_pct' => round($distReclaim, 4),
                'dist_from_structure_pct' => 0.0];
        }

        // Distance from structure reference
        $distStructure = ($structureRef > 0)
            ? (($close - $structureRef) / $structureRef) * 100
            : 0.0;

        if ($distStructure > $maxDistStructure) {
            return ['pass' => false, 'reason' => 'entry_too_far_from_structure',
                'reclaim_confirmed' => true, 'dist_from_reclaim_pct' => round($distReclaim, 4),
                'dist_from_structure_pct' => round($distStructure, 4)];
        }

        return [
            'pass'                    => true,
            'reason'                  => 'control_check_ok',
            'reclaim_confirmed'       => true,
            'dist_from_reclaim_pct'   => round($distReclaim, 4),
            'dist_from_structure_pct' => round($distStructure, 4),
        ];
    }

    // ── Score helpers ─────────────────────────────────────────────────────────

    private function scoreDailyMomentum(float $pct, array $config): int
    {
        $idealMin = (float)($config['ideal_min_daily_change_pct'] ?? 10.0);
        $idealMax = (float)($config['ideal_max_daily_change_pct'] ?? 25.0);
        if ($pct >= $idealMin && $pct <= $idealMax) {
            return 10;
        }
        if ($pct >= (float)($config['min_daily_change_pct'] ?? 8.0)) {
            return 7;
        }
        return 4;
    }

    private function scoreControlledMove(array $r): int
    {
        if (!$r['pass']) {
            return 0;
        }
        $score = 10;
        if (($r['max_1m_pump_pct'] ?? 0.0) > 2.0) {
            $score -= 2;
        }
        if (($r['drawdown_from_high_pct'] ?? 0.0) > 10.0) {
            $score -= 2;
        }
        return max(0, $score);
    }

    private function scoreSoftTurnoverRamp(array $r): int
    {
        if (!$r['pass']) {
            return 0;
        }
        $ratio = (float)($r['turnover_1h_ratio'] ?? 0.0);
        if ($ratio >= 1.5 && $ratio <= 3.0) {
            return 10;
        }
        if ($ratio >= 1.3) {
            return 7;
        }
        return 4;
    }

    private function scoreVolumePersistence(array $r): int
    {
        if (!$r['pass']) {
            return 0;
        }
        $bars = (int)($r['persistence_bars'] ?? 0);
        return min(10, $bars * 2 + 2);
    }

    private function scoreStructure(array $r): int
    {
        if (!$r['pass']) {
            return 0;
        }
        return min(10, ((int)($r['higher_lows_count'] ?? 0)) * 3 + 4);
    }

    private function scorePullback(array $r, array $config): int
    {
        if (!$r['pass']) {
            return 0;
        }
        $depth    = (float)($r['pullback_depth_pct']  ?? 0.0);
        $minDepth = (float)($config['min_pullback_depth_pct'] ?? 1.5);
        $maxDepth = (float)($config['max_pullback_depth_pct'] ?? 7.0);
        $midpoint = ($minDepth + $maxDepth) / 2;
        $dist     = abs($depth - $midpoint);
        return max(0, 10 - (int)round($dist));
    }

    private function scoreReclaim(array $r): int
    {
        return ($r['pass'] && ($r['reclaim_confirmed'] ?? false)) ? 10 : 5;
    }

    private function scoreEntryPrecision(float $distReclaim, float $distStructure): int
    {
        $penalty = abs($distReclaim) + abs($distStructure) * 0.5;
        return max(0, 10 - (int)round($penalty));
    }

    private function collectReasonCodes(
        array $momentum,
        array $blowoff,
        array $turnover,
        array $structure,
        array $pullback,
        array $control
    ): array {
        $codes = [];
        foreach ([$momentum, $blowoff, $turnover, $structure, $pullback, $control] as $r) {
            if (!empty($r['reason'])) {
                $codes[] = $r['reason'];
            }
        }
        // Include any soft turnover warnings as additional reason codes
        foreach ($turnover['warnings'] ?? [] as $w) {
            if (!in_array($w, $codes, true)) {
                $codes[] = $w;
            }
        }
        return $codes;
    }

    // ── Reject tracking ───────────────────────────────────────────────────────

    private function recordReject(
        string $symbol,
        string $reason,
        array &$rejectCounters,
        array &$rejectExamples,
        array &$rejects,
        int $now,
        array $diagMetrics = []
    ): void {
        $rejectCounters[$reason] = ($rejectCounters[$reason] ?? 0) + 1;
        if (count($rejectExamples) < 10) {
            $rejectExamples[] = ['symbol' => $symbol, 'reason' => $reason];
        }
        // Rolling rejects log (keep last 200) — enriched with available diagnostic metrics
        $record = [
            'symbol'      => $symbol,
            'reason'      => $reason,
            'rejected_at' => date('c', $now),
        ];
        foreach ([
            'daily_change_pct', 'momentum_class',
            'max_1m_pump_pct', 'max_5m_pump_pct', 'max_candle_share_pct', 'drawdown_from_high_pct',
            'turnover_1h_ratio', 'turnover_15m_ramp', 'persistence_bars', 'cliff_ratio',
            'soft_turnover_status', 'turnover_warnings',
            'higher_lows_count',
            'pullback_depth_pct', 'reclaim_level', 'pullback_low',
            'entry_distance_from_reclaim_pct', 'entry_distance_from_structure_pct',
            'candidate_quality_score',
        ] as $field) {
            if (array_key_exists($field, $diagMetrics)) {
                $record[$field] = $diagMetrics[$field];
            }
        }
        $rejects[] = $record;
        if (count($rejects) > 200) {
            $rejects = array_slice($rejects, -200);
        }
    }

    // ── Config ────────────────────────────────────────────────────────────────

    private function loadConfig(): array
    {
        $base   = $this->requireConfig('base.php');
        $active = $this->requireConfig('active.php');
        return array_merge($base, $active);
    }

    private function requireConfig(string $file): array
    {
        $path = $this->moduleDir . '/config/' . $file;
        if (!file_exists($path)) {
            return [];
        }
        $data = require $path;
        return is_array($data) ? $data : [];
    }

    // ── Universe build ────────────────────────────────────────────────────────

    private function buildUniverse(array $config): array
    {
        $excluded = (array)($config['excluded_symbols'] ?? []);

        if ((string)($config['universe_mode'] ?? 'all') === 'manual_list') {
            $symbols = (array)($config['allowed_symbols'] ?? []);
        } else {
            try {
                if (class_exists(\Core\System\SystemPaths::class, false)) {
                    $registryDir = \Core\System\SystemPaths::instance()
                        ->get('parser.parser1_market_registry');
                } else {
                    $registryDir = dirname(__DIR__, 3)
                        . '/parser/parser1_market_registry';
                }
                $activePath = rtrim($registryDir, '/') . '/storage/active.json';
                $raw        = file_exists($activePath) ? @file_get_contents($activePath) : false;
                $active     = $raw ? json_decode($raw, true) : null;
                if (!is_array($active)) {
                    throw new \RuntimeException('registry parse failed');
                }
                $symbols = array_is_list($active)
                    ? array_values(array_filter(array_column($active, 'symbol')))
                    : array_keys($active);
            } catch (\Throwable) {
                $symbols = [];
            }
        }

        return array_values(
            array_filter($symbols, fn($s) => !in_array($s, $excluded, true))
        );
    }

    // ── Candle fetch ──────────────────────────────────────────────────────────

    private function fetchCandles(string $symbol, array $config): array
    {
        $interval   = (string)($config['kline_interval']    ?? '1');
        $limit      = (int)($config['lookback_candles']     ?? 1440);
        $baseUrl    = (string)($config['bybit_base_url']    ?? 'https://api.bybit.com');
        $timeoutSec = (int)($config['bybit_timeout_sec']    ?? 10);

        $url = sprintf(
            '%s/v5/market/kline?category=linear&symbol=%s&interval=%s&limit=%d',
            rtrim($baseUrl, '/'),
            urlencode($symbol),
            $interval,
            $limit
        );

        $ctx  = stream_context_create(['http' => ['timeout' => $timeoutSec]]);
        $raw  = @file_get_contents($url, false, $ctx);
        $json = $raw ? json_decode($raw, true) : null;

        if (!$json || ($json['retCode'] ?? -1) !== 0) {
            return [];
        }

        $list    = $json['result']['list'] ?? [];
        $list    = array_reverse($list);

        $candles = [];
        foreach ($list as $bar) {
            $tsMs = (int)($bar[0] ?? 0);
            $candles[] = [
                'ts'     => (int)floor($tsMs / 1000),
                'open'   => (float)($bar[1] ?? 0),
                'high'   => (float)($bar[2] ?? 0),
                'low'    => (float)($bar[3] ?? 0),
                'close'  => (float)($bar[4] ?? 0),
                'volume' => (float)($bar[5] ?? 0),
            ];
        }

        return $candles;
    }

    // ── Candidate helpers ─────────────────────────────────────────────────────

    private function upsertCandidate(array $candidates, string $key, array $record): array
    {
        foreach ($candidates as &$c) {
            if (($c['key'] ?? '') === $key) {
                $c        = $record;
                return $candidates;
            }
        }
        unset($c);
        $candidates[] = $record;
        return $candidates;
    }

    private function removeCandidate(array $candidates, string $key): array
    {
        return array_values(
            array_filter($candidates, fn($c) => ($c['key'] ?? '') !== $key)
        );
    }

    // ── Signal helpers ────────────────────────────────────────────────────────

    private function upsertSignal(array $signals, string $symbol, array $signal): array
    {
        foreach ($signals as &$s) {
            if (($s['symbol'] ?? '') === $symbol
                && ($s['strategy_id'] ?? '') === self::STRATEGY_ID
            ) {
                $s = $signal;
                return $signals;
            }
        }
        unset($s);
        $signals[] = $signal;
        return $signals;
    }

    private function makeSignalId(string $symbol, int $ts): string
    {
        return sprintf('%s_%s_%d', self::STRATEGY_ID, strtolower($symbol), $ts);
    }

    // ── Handoff validation ────────────────────────────────────────────────────

    private function validateSignalForHandoff(array $sig): ?string
    {
        if (($sig['signal_id']   ?? '') === '') { return 'missing_signal_id'; }
        if (($sig['strategy_id'] ?? '') !== self::STRATEGY_ID) { return 'wrong_strategy_id'; }
        if (($sig['symbol']      ?? '') === '') { return 'missing_symbol'; }
        if (($sig['side']        ?? '') !== 'long') { return 'wrong_side'; }
        if (($sig['mode']        ?? '') !== 'demo') { return 'wrong_mode'; }
        if ((float)($sig['entry_price'] ?? 0) <= 0) { return 'invalid_entry_price'; }
        if (($sig['detected_at'] ?? '') === '') { return 'missing_detected_at'; }
        if (($sig['created_at']  ?? '') === '') { return 'missing_created_at'; }
        return null;
    }

    // ── Storage init ──────────────────────────────────────────────────────────

    private function initStorage(): void
    {
        $defaults = [
            'storage/signals.json'           => [],
            'storage/candidates.json'        => [],
            'storage/rejects.json'           => [],
            'storage/runtime.json'           => (object)[],
            'storage/last_run.json'          => (object)[],
            'storage/stats.json'             => (object)[],
            'storage/bot_handoff_queue.json' => [],
        ];
        foreach ($defaults as $relPath => $default) {
            $path = $this->moduleDir . '/' . $relPath;
            $dir  = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            if (!file_exists($path)) {
                file_put_contents(
                    $path,
                    json_encode($default, JSON_PRETTY_PRINT),
                    LOCK_EX
                );
            }
        }
    }

    // ── Storage helpers ───────────────────────────────────────────────────────

    private function readJson(string $relPath, mixed $default = null): mixed
    {
        $path    = $this->moduleDir . '/' . $relPath;
        if (!file_exists($path)) {
            return $default;
        }
        $raw     = @file_get_contents($path);
        $decoded = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
        return ($decoded !== null) ? $decoded : $default;
    }

    private function writeJson(string $relPath, mixed $data): void
    {
        $path = $this->moduleDir . '/' . $relPath;
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }
}
