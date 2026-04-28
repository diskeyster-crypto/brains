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
            'early_watch_total'              => 0,
            'active_watch_total'             => 0,
            'weak_watch_only_total'          => 0,
            'watchlist_total'                => 0,
            'watchlist_new_total'            => 0,
            'watchlist_promoted_total'       => 0,
            'late_momentum_warning_total'    => 0,
            'candidate_first_seen_too_late_total' => 0,
            'recovery_drift_detected_total'  => 0,
            'recovery_drift_watch_only_total'=> 0,
            'dump_risk_warning_total'        => 0,
            'candidates_total'               => 0,
            'signals_total'                  => 0,
            'rejects_total'                  => 0,
            // Stage pass counters
            'daily_momentum_pass_total'               => 0,
            'anti_blowoff_pass_total'                 => 0,
            'soft_turnover_pass_total'                => 0,
            'soft_turnover_warning_total'             => 0,
            'soft_turnover_hard_reject_total'         => 0,
            'structure_pass_total'                    => 0,
            'pullback_reclaim_pass_total'             => 0,
            'control_check_pass_total'                => 0,
            'recovery_drift_passed_to_structure_total'=> 0,
            // Structure type counters
            'structure_reject_total'                         => 0,
            'structure_warning_total'                        => 0,
            'structure_type_higher_low_total'                => 0,
            'structure_type_range_hold_total'                => 0,
            'structure_type_base_reclaim_total'              => 0,
            'structure_type_recovery_drift_hold_total'       => 0,
            'recovery_drift_structure_pass_total'            => 0,
            // Signal status counts
            'signals_current_valid_total'                    => 0,
            'signals_historical_valid_total'                 => 0,
            'signals_stale_invalidated_total'                => 0,
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

        // Load persisted state
        $candidates = $this->readJson('storage/candidates.json', []);
        $signals    = $this->readJson('storage/signals.json',    []);
        $rejects    = $this->readJson('storage/rejects.json',    []);
        $watchlist  = $this->readJson('storage/watchlist.json',  []);

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

            // Symbols below early-watch min (<3%): silently ignore (no candidate record)
            if ($momentumClass === 'ignore') {
                $stats['ignored_low_momentum_total']++;
                $stats['rejected']++;
                $this->recordReject($symbol, $momentumResult['reason'], $_rejectCounters, $_rejectExamples, $rejects, $now, [
                    'daily_change_pct' => $dailyChangePct,
                ]);
                continue;
            }

            // ── All symbols >= 3%: update watchlist ───────────────────────────
            $prevWatchState   = $watchlist[$symbol]['watch_state'] ?? null;
            $watchlistEntry   = $this->updateWatchlistEntry($watchlist, $symbol, $dailyChangePct, $momentumClass, $now);
            $isNewEntry       = ($prevWatchState === null);
            if ($isNewEntry) {
                $stats['watchlist_new_total']++;
            }
            $stats['watchlist_total']++;
            $accelDiag = $this->computeAccelerationDiagnostics($watchlistEntry, $dailyChangePct, $now);

            // Candidate key + tracking
            $candidateKey = strtolower($symbol) . '_' . self::STRATEGY_ID;
            $stats['candidates_total']++;
            $currentRunCandidateKeys[$candidateKey] = true;

            // Base diagnostic fields (shared across all branches)
            $diagFields = array_merge([
                'daily_change_pct'            => $dailyChangePct,
                'momentum_class'              => $momentumClass,
                'watch_state'                 => $watchlistEntry['watch_state'],
                'first_seen_daily_change_pct' => (float)($watchlistEntry['first_seen_daily_change_pct'] ?? $dailyChangePct),
                'last_seen_daily_change_pct'  => $dailyChangePct,
            ], $accelDiag);

            // ── Early watch (3–5 %): record to watchlist, no signal ───────────
            if ($momentumClass === 'early_watch') {
                $stats['early_watch_total']++;
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

            // ── Active watch (5–8 %): record to watchlist, no signal ──────────
            if ($momentumClass === 'active_watch') {
                $stats['active_watch_total']++;
                $stats['weak_watch_only_total']++;
                $stats['rejected']++;
                $stats['rejects_total']++;
                if ($prevWatchState === 'early_watch') {
                    $stats['watchlist_promoted_total']++;
                }
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

            // ── Observation (>35 %): hard reject ─────────────────────────────
            if (!$momentumResult['pass']) {
                $stats['rejected']++;
                $stats['rejects_total']++;
                $diagCandidate = array_merge([
                    'key'          => $candidateKey,
                    'symbol'       => $symbol,
                    'state'        => 'rejected',
                    'failed_stage' => 'daily_momentum',
                    'decision'     => 'rejected',
                    'reason'       => $momentumResult['reason'],
                    'updated_at'   => date('c', $now),
                ], $diagFields);
                $candidates = $this->upsertCandidate($candidates, $candidateKey, $diagCandidate);
                $this->recordReject($symbol, $momentumResult['reason'], $_rejectCounters, $_rejectExamples, $rejects, $now, $diagFields);
                continue;
            }

            // ── Momentum passed (daily >= min_daily and <= hard_max) ──────────
            $open24h = (float)($momentumResult['open_24h'] ?? 0.0);
            $high24h = (float)($momentumResult['high_24h'] ?? 0.0);
            $low24h  = (float)($momentumResult['low_24h']  ?? 0.0);

            $stats['candidates_found']++;
            $stats['daily_momentum_pass_total']++;

            // Track watchlist promotions (entry was previously at a lower watch tier)
            if (in_array($prevWatchState, ['early_watch', 'active_watch'], true)) {
                $stats['watchlist_promoted_total']++;
            }

            // Late-entry diagnostics
            $cautionPct               = (float)($config['caution_daily_change_pct'] ?? 18.0);
            $firstSeenPct             = (float)($watchlistEntry['first_seen_daily_change_pct'] ?? $dailyChangePct);
            $candidateFirstSeenTooLate = $firstSeenPct >= $cautionPct;
            $lateMomentumWarning       = ($momentumClass === 'late_momentum_warning');
            if ($candidateFirstSeenTooLate) {
                $stats['candidate_first_seen_too_late_total']++;
            }
            if ($lateMomentumWarning) {
                $stats['late_momentum_warning_total']++;
            }
            $diagFields = array_merge($diagFields, [
                'candidate_first_seen_too_late' => $candidateFirstSeenTooLate,
                'late_momentum_warning'         => $lateMomentumWarning,
            ]);

            // Potential context diagnostics (informational only, not a hard gate)
            $diagFields = array_merge($diagFields, [
                'upside_room_to_18pct' => max(0.0, round(18.0 - $dailyChangePct, 2)),
                'upside_room_to_25pct' => max(0.0, round(25.0 - $dailyChangePct, 2)),
                'upside_room_to_35pct' => max(0.0, round(35.0 - $dailyChangePct, 2)),
                'entry_risk_context'   => match (true) {
                    $dailyChangePct < 8.0  => 'early_watch',
                    $dailyChangePct <= 18.0 => 'ok',
                    $dailyChangePct <= 25.0 => 'caution',
                    $dailyChangePct <= 35.0 => 'late',
                    default                => 'overextended',
                },
            ]);

            // ── Recovery drift detection (diagnostic, does not gate pipeline) ─
            $recoveryDriftResult = $this->pipelineRecoveryDrift($candles, $dailyChangePct, $config);
            $diagFields = array_merge($diagFields, [
                'recovery_drift_detected'        => $recoveryDriftResult['recovery_drift_detected'],
                'recovery_drift_duration_minutes'=> $recoveryDriftResult['recovery_drift_duration_minutes'],
                'recovery_drift_score'           => $recoveryDriftResult['recovery_drift_score'],
                'recovery_after_dump_score'      => $recoveryDriftResult['recovery_after_dump_score'],
                'current_extension_score'        => $recoveryDriftResult['current_extension_score'],
                'dump_risk_warning'              => $recoveryDriftResult['dump_risk_warning'],
                'recovery_drift_reason'          => $recoveryDriftResult['recovery_drift_reason'],
            ]);
            if ($recoveryDriftResult['recovery_drift_detected']) {
                $stats['recovery_drift_detected_total']++;
            }
            if ($recoveryDriftResult['dump_risk_warning']) {
                $stats['dump_risk_warning_total']++;
            }

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
                $this->markSignalStale($signals, $symbol, 'anti_blowoff', $blowoffResult['reason'], 'rejected', $now);
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
            $turnoverResult = $this->pipelineSoftTurnoverRamp($candles, $config, (bool)($recoveryDriftResult['recovery_drift_detected'] ?? false));
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
                if ($turnoverResult['hard_reject'] ?? true) {
                    $stats['soft_turnover_hard_reject_total']++;
                }
                $this->markSignalStale($signals, $symbol, 'soft_turnover_ramp', $turnoverResult['reason'], 'rejected', $now);
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
            if ($recoveryDriftResult['recovery_drift_detected'] ?? false) {
                $stats['recovery_drift_passed_to_structure_total']++;
            }

            // ── 5. structure ──────────────────────────────────────────────────
            $structureResult = $this->pipelineStructure($candles, $config, (bool)($recoveryDriftResult['recovery_drift_detected'] ?? false));
            $diagFields = array_merge($diagFields, array_filter([
                'higher_lows_count'        => $structureResult['higher_lows_count']        ?? null,
                'structure_status'         => $structureResult['structure_status']         ?? null,
                'structure_type'           => $structureResult['structure_type']           ?? null,
                'structure_score'          => $structureResult['structure_score']          ?? null,
                'range_hold_score'         => $structureResult['range_hold_score']         ?? null,
                'base_hold_score'          => $structureResult['base_hold_score']          ?? null,
                'recovery_structure_score' => $structureResult['recovery_structure_score'] ?? null,
                'structure_warnings'       => !empty($structureResult['structure_warnings']) ? $structureResult['structure_warnings'] : null,
                'structure_reason'         => $structureResult['reason']                   ?? null,
            ], fn($v) => $v !== null));
            if (!$structureResult['pass']) {
                $stats['rejected']++;
                $stats['rejects_total']++;
                $stats['structure_reject_total']++;
                $this->markSignalStale($signals, $symbol, 'structure', $structureResult['reason'], 'rejected', $now);
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
            // Increment structure-type specific counters
            $structureType = (string)($structureResult['structure_type'] ?? 'none');
            match ($structureType) {
                'higher_low'          => $stats['structure_type_higher_low_total']++,
                'range_hold'          => $stats['structure_type_range_hold_total']++,
                'base_reclaim'        => $stats['structure_type_base_reclaim_total']++,
                'recovery_drift_hold' => $stats['structure_type_recovery_drift_hold_total']++,
                default               => null,
            };
            if (!empty($structureResult['structure_warnings'])) {
                $stats['structure_warning_total']++;
            }
            if ($structureType === 'recovery_drift_hold' && ($recoveryDriftResult['recovery_drift_detected'] ?? false)) {
                $stats['recovery_drift_structure_pass_total']++;
            }
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
                // If recovery drift detected, annotate with recovery-drift-specific reason
                $pullbackRejectReason = $pullbackResult['reason'];
                if ($recoveryDriftResult['recovery_drift_detected']) {
                    $pullbackRejectReason = 'no_fresh_pullback_after_recovery_drift';
                    $stats['recovery_drift_watch_only_total']++;
                    $diagFields['recovery_drift_reason'] = 'recovery_drift_watch_only';
                }
                $this->markSignalStale($signals, $symbol, 'pullback_reclaim', $pullbackRejectReason, 'rejected', $now);
                $diagCandidate = array_merge([
                    'key'          => $candidateKey,
                    'symbol'       => $symbol,
                    'state'        => 'rejected',
                    'failed_stage' => 'pullback_reclaim',
                    'decision'     => 'rejected',
                    'reject_reasons' => [$pullbackRejectReason],
                    'updated_at'   => date('c', $now),
                ], $diagFields);
                $candidates = $this->upsertCandidate($candidates, $candidateKey, $diagCandidate);
                $this->recordReject($symbol, $pullbackRejectReason, $_rejectCounters, $_rejectExamples, $rejects, $now, $diagFields);
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
                $this->markSignalStale($signals, $symbol, 'control_check', $controlResult['reason'], 'rejected', $now);
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

                // Structure diagnostics
                'structure_status'         => $structureResult['structure_status']         ?? 'pass',
                'structure_type'           => $structureResult['structure_type']           ?? 'higher_low',
                'higher_lows_count'        => $structureResult['higher_lows_count']        ?? 0,
                'range_hold_score'         => $structureResult['range_hold_score']         ?? 0.0,
                'base_hold_score'          => $structureResult['base_hold_score']          ?? 0.0,
                'recovery_structure_score' => $structureResult['recovery_structure_score'] ?? 0.0,
                'structure_warnings'       => $structureResult['structure_warnings']       ?? [],

                // Signal status
                'stale'                   => false,

                // Momentum class
                'momentum_class' => $momentumClass,

                // Watchlist & acceleration diagnostics
                'watch_state'                 => $watchlistEntry['watch_state'],
                'first_seen_daily_change_pct' => (float)($watchlistEntry['first_seen_daily_change_pct'] ?? $dailyChangePct),
                'daily_change_delta_from_first' => $accelDiag['daily_change_delta_from_first'],
                'daily_change_delta_since_last' => $accelDiag['daily_change_delta_since_last'],
                'time_from_first_seen_sec'      => $accelDiag['time_from_first_seen_sec'],
                'momentum_acceleration_score'   => $accelDiag['momentum_acceleration_score'],

                // Late-entry diagnostics
                'candidate_first_seen_too_late' => $candidateFirstSeenTooLate,
                'late_momentum_warning'         => $lateMomentumWarning,

                // Recovery drift diagnostics
                'recovery_drift_detected'        => $recoveryDriftResult['recovery_drift_detected'],
                'recovery_drift_score'           => $recoveryDriftResult['recovery_drift_score'],
                'recovery_after_dump_score'      => $recoveryDriftResult['recovery_after_dump_score'],
                'current_extension_score'        => $recoveryDriftResult['current_extension_score'],
                'dump_risk_warning'              => $recoveryDriftResult['dump_risk_warning'],
                'recovery_drift_reason'          => $recoveryDriftResult['recovery_drift_reason'],
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

        // ── Signal status counts ──────────────────────────────────────────────
        $currentRunSignalSymbols = array_column($currentRunSignals, 'symbol');
        $sigCurrentValid         = 0;
        $sigHistoricalValid      = 0;
        $sigStaleInvalidated     = 0;
        foreach ($signals as $s) {
            if ($s['stale'] ?? false) {
                $sigStaleInvalidated++;
            } elseif (in_array($s['symbol'] ?? '', $currentRunSignalSymbols, true)) {
                $sigCurrentValid++;
            } else {
                $sigHistoricalValid++;
            }
        }
        $stats['signals_current_valid_total']    = $sigCurrentValid;
        $stats['signals_historical_valid_total'] = $sigHistoricalValid;
        $stats['signals_stale_invalidated_total']= $sigStaleInvalidated;

        // ── Build top_candidates for last_run.json ────────────────────────────
        // Sort priority: signal > candidate with daily >= min > recovery_drift >
        //   soft_turnover_warning passed > momentum_acceleration > quality > daily > active_watch > early_watch
        $minDailyForSort = (float)($config['min_daily_change_pct'] ?? 8.0);
        $sortComparator = function (array $a, array $b) use ($minDailyForSort): int {
            // 1. Signals first
            $isSignalA = ($a['state'] ?? '') === 'signal';
            $isSignalB = ($b['state'] ?? '') === 'signal';
            if ($isSignalA !== $isSignalB) {
                return $isSignalA ? -1 : 1;
            }

            // 2. Candidates with daily_change_pct >= min_daily (not pure watch_only)
            $aboveMinA = (float)($a['daily_change_pct'] ?? 0.0) >= $minDailyForSort
                         && ($a['state'] ?? '') !== 'watch_only';
            $aboveMinB = (float)($b['daily_change_pct'] ?? 0.0) >= $minDailyForSort
                         && ($b['state'] ?? '') !== 'watch_only';
            if ($aboveMinA !== $aboveMinB) {
                return $aboveMinA ? -1 : 1;
            }

            // 3. recovery_drift_detected=true
            $rdA = (bool)($a['recovery_drift_detected'] ?? false);
            $rdB = (bool)($b['recovery_drift_detected'] ?? false);
            if ($rdA !== $rdB) {
                return $rdA ? -1 : 1;
            }

            // 4. soft_turnover_status=warning but not rejected at that stage
            $stwA = ($a['soft_turnover_status'] ?? '') === 'warning'
                    && ($a['failed_stage'] ?? '') !== 'soft_turnover_ramp';
            $stwB = ($b['soft_turnover_status'] ?? '') === 'warning'
                    && ($b['failed_stage'] ?? '') !== 'soft_turnover_ramp';
            if ($stwA !== $stwB) {
                return $stwA ? -1 : 1;
            }

            // 5. Higher momentum_acceleration_score
            $maA = (float)($a['momentum_acceleration_score'] ?? 0.0);
            $maB = (float)($b['momentum_acceleration_score'] ?? 0.0);
            if (abs($maA - $maB) > 0.001) {
                return $maB <=> $maA;
            }

            // 6. Higher candidate_quality_score
            $qaA = (float)($a['candidate_quality_score'] ?? 0.0);
            $qaB = (float)($b['candidate_quality_score'] ?? 0.0);
            if (abs($qaA - $qaB) > 0.001) {
                return $qaB <=> $qaA;
            }

            // 7. Higher daily_change_pct
            $daA = (float)($a['daily_change_pct'] ?? 0.0);
            $daB = (float)($b['daily_change_pct'] ?? 0.0);
            if (abs($daA - $daB) > 0.001) {
                return $daB <=> $daA;
            }

            // 8–9. Watch state: candidate > active_watch > early_watch > others
            $wsOrder = ['candidate' => 0, 'active_watch' => 1, 'early_watch' => 2, 'rejected_late' => 3, 'stale' => 4];
            $wsA = $wsOrder[$a['watch_state'] ?? ''] ?? 5;
            $wsB = $wsOrder[$b['watch_state'] ?? ''] ?? 5;
            if ($wsA !== $wsB) {
                return $wsA <=> $wsB;
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
        $this->writeJson('storage/watchlist.json',       $watchlist);

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
        $earlyWatchMin  = (float)($config['early_watch_min_daily_change_pct']  ?? 3.0);
        $activeWatchMin = (float)($config['active_watch_min_daily_change_pct'] ?? 5.0);
        $minValid       = (float)($config['min_daily_change_pct']              ?? 8.0);
        $idealMin       = (float)($config['ideal_min_daily_change_pct']        ?? 10.0);
        $cautionPct     = (float)($config['caution_daily_change_pct']          ?? 18.0);
        $lateWarnPct    = (float)($config['late_momentum_warning_pct']         ?? 25.0);
        $hardMax        = (float)($config['hard_max_daily_change_pct']         ?? 35.0);

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

        if ($dailyChangePct < $earlyWatchMin) {
            return ['pass' => false, 'reason' => 'ignore_low_momentum_below_3pct',
                'daily_change_pct' => round($dailyChangePct, 4),
                'open_24h' => $open24h, 'high_24h' => $high24h, 'low_24h' => $low24h,
                'class' => 'ignore'];
        }

        if ($dailyChangePct < $activeWatchMin) {
            return ['pass' => false, 'reason' => 'early_watch_momentum',
                'daily_change_pct' => round($dailyChangePct, 4),
                'open_24h' => $open24h, 'high_24h' => $high24h, 'low_24h' => $low24h,
                'class' => 'early_watch'];
        }

        if ($dailyChangePct < $minValid) {
            return ['pass' => false, 'reason' => 'active_watch_momentum',
                'daily_change_pct' => round($dailyChangePct, 4),
                'open_24h' => $open24h, 'high_24h' => $high24h, 'low_24h' => $low24h,
                'class' => 'active_watch'];
        }

        if ($dailyChangePct > $hardMax) {
            return ['pass' => false, 'reason' => 'daily_change_too_high',
                'daily_change_pct' => round($dailyChangePct, 4),
                'open_24h' => $open24h, 'high_24h' => $high24h, 'low_24h' => $low24h,
                'class' => 'observation'];
        }

        // Determine class for passing symbols (>= min_valid and <= hard_max)
        if ($dailyChangePct > $lateWarnPct) {
            $class = 'late_momentum_warning';
        } elseif ($dailyChangePct >= $cautionPct) {
            $class = 'caution_late_momentum';
        } elseif ($dailyChangePct >= $idealMin) {
            $class = 'ideal';
        } else {
            $class = 'valid';
        }

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
     *
     * Hard rejects only when:
     *   - turnover_1h_ratio < min_turnover_1h_vs_avg_24h_hard
     *   - turnover_1h_ratio > max_turnover_1h_vs_avg_24h_hard
     *   - persistence_bars < min_persistence_candles_hard
     *   - volume_cliff_ratio < hard_volume_cliff_ratio
     *
     * Everything else is a soft warning: pass=true, soft_turnover_status=warning.
     * Recovery drift override: if $recoveryDriftDetected=true, low ramp / low persistence-soft
     *   issues convert from hard reject to warning (as long as ratio is not below hard min / above hard max).
     */
    private function pipelineSoftTurnoverRamp(array $candles, array $config, bool $recoveryDriftDetected = false): array
    {
        // Hard/soft thresholds — new keys; fall back to legacy keys for backward compat
        $hardMinRatio1h  = (float)($config['min_turnover_1h_vs_avg_24h_hard']  ?? 0.8);
        $softMinRatio1h  = (float)($config['min_turnover_1h_vs_avg_24h_soft']
                            ?? $config['min_turnover_1h_vs_avg_24h']            ?? 1.3);
        $softMaxRatio1h  = (float)($config['max_turnover_1h_vs_avg_24h_soft']
                            ?? $config['max_turnover_1h_vs_avg_24h']            ?? 4.0);
        $hardMaxRatio1h  = (float)($config['max_turnover_1h_vs_avg_24h_hard']  ?? 7.0);
        $minRamp15m      = (float)($config['min_turnover_ramp_15m_ratio']       ?? 1.15);
        $persWindow      = (int)($config['volume_persistence_window']           ?? 4);
        $persHard        = (int)($config['min_persistence_candles_hard']        ?? 1);
        $persSoft        = (int)($config['min_persistence_candles_soft']
                            ?? $config['min_volume_persistence_candles']        ?? 3);
        $hardCliff       = (float)($config['hard_volume_cliff_ratio']           ?? 0.12);
        $warnCliff       = (float)($config['warning_volume_cliff_ratio']
                            ?? $config['max_volume_cliff_ratio']                ?? 0.45);
        $strongRamp      = (float)($config['strong_ramp_override_ratio']        ?? 1.5);
        $strongTurnover  = (float)($config['strong_turnover_override_ratio']    ?? 2.0);
        $recoveryAllowed = (bool)($config['recovery_drift_turnover_warning_allowed'] ?? true);

        $count     = count($candles);
        $volumes   = array_column($candles, 'volume');
        $avgVol24h = count($volumes) > 0 ? array_sum($volumes) / count($volumes) : 0.0;

        if ($avgVol24h <= 0.0) {
            return [
                'pass'               => false,
                'hard_reject'        => true,
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

        // Hard reject: turnover below hard minimum (truly dead — no override)
        if ($ratio1h < $hardMinRatio1h) {
            return [
                'pass'               => false,
                'hard_reject'        => true,
                'reason'             => 'turnover_too_low',
                'warnings'           => [],
                'soft_turnover_status' => 'reject',
                'turnover_1h_ratio'  => round($ratio1h, 4),
                'turnover_15m_ramp'  => 0.0,
                'persistence_bars'   => 0,
                'cliff_ratio'        => 0.0,
            ];
        }

        // Hard reject: turnover spike above hard maximum (blow-off level — no override)
        if ($ratio1h > $hardMaxRatio1h) {
            return [
                'pass'               => false,
                'hard_reject'        => true,
                'reason'             => 'turnover_spike_too_large',
                'warnings'           => [],
                'soft_turnover_status' => 'reject',
                'turnover_1h_ratio'  => round($ratio1h, 4),
                'turnover_15m_ramp'  => 0.0,
                'persistence_bars'   => 0,
                'cliff_ratio'        => 0.0,
            ];
        }

        $warnings           = [];
        $softTurnoverStatus = 'ok';

        // Soft warning: turnover below soft minimum (alive but weak)
        if ($ratio1h < $softMinRatio1h) {
            $warnings[]         = 'turnover_soft_below_target';
            $softTurnoverStatus = 'warning';
        }

        // Soft warning: turnover between soft max and hard max (elevated but not blow-off)
        if ($ratio1h > $softMaxRatio1h) {
            $warnings[]         = 'turnover_spike_warning';
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
        // Strong signal relaxes soft persistence requirement to 2 (instead of persSoft)
        $isStrongSignal = ($ratio1h >= $strongTurnover && $ramp15 >= $strongRamp);

        // ── Ramp check: soft warning only (not a hard reject by itself) ───────
        if ($ramp15 < $minRamp15m) {
            $warnings[] = 'turnover_ramp_soft_low';
            if ($softTurnoverStatus === 'ok') {
                $softTurnoverStatus = 'warning';
            }
        }

        // ── Hard reject: persistence below absolute minimum ───────────────────
        if ($persAboveAvg < $persHard) {
            return [
                'pass'               => false,
                'hard_reject'        => true,
                'reason'             => 'turnover_not_persistent',
                'warnings'           => $warnings,
                'soft_turnover_status' => 'reject',
                'turnover_1h_ratio'  => round($ratio1h, 4),
                'turnover_15m_ramp'  => round($ramp15, 4),
                'persistence_bars'   => $persAboveAvg,
                'cliff_ratio'        => round($cliffRatioActual, 4),
            ];
        }

        // ── Soft persistence checks ───────────────────────────────────────────
        // Strong signal relaxes soft threshold: if turnover+ramp are strong, persistence >= 2 is ok
        $softPersistenceMin = $isStrongSignal ? 2 : $persSoft;
        if ($persAboveAvg < $softPersistenceMin) {
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
                'hard_reject'        => true,
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
            // Warning-level cliff: soft warning only, pass through
            $warnings[]         = 'volume_cliff_warning';
            if ($softTurnoverStatus === 'ok') {
                $softTurnoverStatus = 'warning';
            }
        }

        return [
            'pass'               => true,
            'hard_reject'        => false,
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
     * Step 4: structure (adaptive)
     *
     * Detection order:
     *   1. Classic higher-low structure (always tried first)
     *   2. Range/base hold structure (adaptive mode only)
     *   3. Base reclaim structure (adaptive mode only)
     *   4. Recovery drift hold structure (adaptive mode + recovery_drift_detected only)
     *
     * Returns a structured payload:
     *   pass, structure_status, structure_type, structure_score,
     *   higher_lows_count, range_hold_score, base_hold_score,
     *   recovery_structure_score, structure_warnings, reason,
     *   structure_break_reference_price
     */
    private function pipelineStructure(array $candles, array $config, bool $recoveryDriftDetected = false): array
    {
        $mode     = (string)($config['structure_mode']             ?? 'adaptive');
        $lookback = (int)($config['structure_lookback_candles']    ?? 90);
        $minHL    = (int)($config['min_higher_lows_count']         ?? 2);

        // Range hold
        $rangeHoldLookback = (int)($config['range_hold_lookback_candles']   ?? 60);
        $maxRangeBreak     = (float)($config['max_range_hold_break_pct']    ?? 1.2);
        $minRangeRecovery  = (float)($config['min_range_hold_recovery_pct'] ?? 0.4);

        // Base reclaim
        $baseHoldLookback = (int)($config['base_hold_lookback_candles']  ?? 120);
        $maxBaseBreak     = (float)($config['max_base_break_pct']        ?? 1.5);
        $minBaseReclaim   = (float)($config['min_base_reclaim_pct']      ?? 0.5);

        // Recovery drift structure
        $recoveryMinDuration  = (int)($config['recovery_structure_min_duration_minutes']              ?? 360);
        $recoveryMaxDump      = (float)($config['recovery_structure_max_recent_dump_pct']             ?? 5.0);
        $recoveryMinScore     = (float)($config['recovery_structure_min_hold_score']                  ?? 0.55);
        $recoveryAllowWithout = (bool)($config['recovery_structure_allow_without_classic_higher_lows']?? true);

        $count     = count($candles);
        $lastClose = (float)(end($candles)['close'] ?? 0.0);

        // ── 1. Classic higher-low detection ──────────────────────────────────
        $higherLows   = 0;
        $structureRef = 0.0;
        $prevLow      = PHP_FLOAT_MAX;

        $slice = array_slice($candles, max(0, $count - $lookback));
        foreach ($slice as $bar) {
            $low = (float)($bar['low'] ?? 0);
            if ($low <= 0) {
                continue;
            }
            if ($prevLow < PHP_FLOAT_MAX) {
                if ($low > $prevLow) {
                    $higherLows++;
                    if ($higherLows === 1) {
                        $structureRef = $prevLow;
                    }
                } else {
                    $higherLows   = 0;
                    $structureRef = 0.0;
                }
            }
            $prevLow = $low;
        }

        if ($higherLows >= $minHL) {
            if ($structureRef <= 0.0) {
                $structureRef = $lastClose;
            }
            return [
                'pass'                            => true,
                'structure_status'                => 'pass',
                'structure_type'                  => 'higher_low',
                'structure_score'                 => min(10, $higherLows * 3 + 4),
                'higher_lows_count'               => $higherLows,
                'range_hold_score'                => 0.0,
                'base_hold_score'                 => 0.0,
                'recovery_structure_score'        => 0.0,
                'structure_warnings'              => [],
                'reason'                          => 'structure_ok',
                'structure_break_reference_price' => round($structureRef, 6),
            ];
        }

        $structureWarnings = ['structure_warning_no_classic_higher_lows'];

        // Non-adaptive mode: hard reject after classic check
        if ($mode !== 'adaptive') {
            return [
                'pass'                            => false,
                'structure_status'                => 'reject',
                'structure_type'                  => 'none',
                'structure_score'                 => 0.0,
                'higher_lows_count'               => $higherLows,
                'range_hold_score'                => 0.0,
                'base_hold_score'                 => 0.0,
                'recovery_structure_score'        => 0.0,
                'structure_warnings'              => $structureWarnings,
                'reason'                          => 'no_higher_low_structure',
                'structure_break_reference_price' => 0.0,
            ];
        }

        // ── 2. Range/base hold structure ──────────────────────────────────────
        $rangeSlice = array_slice($candles, max(0, $count - $rangeHoldLookback));
        $rangeLow   = PHP_FLOAT_MAX;
        $rangeHigh  = 0.0;
        foreach ($rangeSlice as $bar) {
            $l = (float)($bar['low']  ?? PHP_FLOAT_MAX);
            $h = (float)($bar['high'] ?? 0.0);
            if ($l > 0 && $l < $rangeLow) {
                $rangeLow = $l;
            }
            if ($h > $rangeHigh) {
                $rangeHigh = $h;
            }
        }

        $rangeHoldScore = 0.0;
        if ($rangeLow < PHP_FLOAT_MAX && $rangeLow > 0 && $rangeHigh > 0) {
            $rangeBroken   = ($lastClose < $rangeLow * (1 - $maxRangeBreak / 100));
            $recovPct      = (($lastClose - $rangeLow) / $rangeLow) * 100;
            $rangeRecovered = ($recovPct >= $minRangeRecovery);

            if (!$rangeBroken && $rangeRecovered) {
                $rangeHoldScore = min(10.0, round(($recovPct / max($minRangeRecovery * 2, 0.001)) * 6 + 3, 2));
            }
        }

        if ($rangeHoldScore >= 3.0) {
            // Check there is no recent sharp dump
            $last60Slice   = array_slice($candles, max(0, $count - 60));
            $recentMaxDump = 0.0;
            $prevC         = 0.0;
            foreach ($last60Slice as $bar) {
                $c = (float)($bar['close'] ?? 0.0);
                if ($prevC > 0 && $c > 0 && $c < $prevC) {
                    $dumpPct = (($prevC - $c) / $prevC) * 100;
                    if ($dumpPct > $recentMaxDump) {
                        $recentMaxDump = $dumpPct;
                    }
                }
                if ($c > 0) {
                    $prevC = $c;
                }
            }

            if ($recentMaxDump <= $recoveryMaxDump) {
                $finalRef = ($rangeLow < PHP_FLOAT_MAX && $rangeLow > 0) ? $rangeLow : $lastClose;
                return [
                    'pass'                            => true,
                    'structure_status'                => 'pass',
                    'structure_type'                  => 'range_hold',
                    'structure_score'                 => (int)round($rangeHoldScore),
                    'higher_lows_count'               => $higherLows,
                    'range_hold_score'                => round($rangeHoldScore, 2),
                    'base_hold_score'                 => 0.0,
                    'recovery_structure_score'        => 0.0,
                    'structure_warnings'              => $structureWarnings,
                    'reason'                          => 'structure_ok',
                    'structure_break_reference_price' => round($finalRef, 6),
                ];
            }
        }

        // ── 3. Base reclaim structure ─────────────────────────────────────────
        $baseSlice     = array_slice($candles, max(0, $count - $baseHoldLookback));
        $baseHoldScore = 0.0;
        $baseRef       = 0.0;

        if (count($baseSlice) >= 20) {
            $halfLen    = (int)floor(count($baseSlice) / 2);
            $firstHalf  = array_slice($baseSlice, 0, $halfLen);
            $secondHalf = array_slice($baseSlice, $halfLen);

            $baseLow = PHP_FLOAT_MAX;
            foreach ($firstHalf as $bar) {
                $l = (float)($bar['low'] ?? PHP_FLOAT_MAX);
                if ($l > 0 && $l < $baseLow) {
                    $baseLow = $l;
                }
            }

            if ($baseLow < PHP_FLOAT_MAX && $baseLow > 0) {
                $secondLow = PHP_FLOAT_MAX;
                foreach ($secondHalf as $bar) {
                    $l = (float)($bar['low'] ?? PHP_FLOAT_MAX);
                    if ($l > 0 && $l < $secondLow) {
                        $secondLow = $l;
                    }
                }

                $dippedToBase  = ($secondLow < PHP_FLOAT_MAX && $secondLow <= $baseLow * (1 + $maxBaseBreak / 100));
                $reclaimedBase = ($lastClose > $baseLow * (1 + $minBaseReclaim / 100));

                if ($dippedToBase && $reclaimedBase) {
                    $reclaimPct    = (($lastClose - $baseLow) / $baseLow) * 100;
                    $baseHoldScore = min(10.0, round($reclaimPct / max($minBaseReclaim * 2, 0.001) * 6 + 2, 2));
                    $baseRef       = $baseLow;
                }
            }
        }

        if ($baseHoldScore >= 3.0) {
            return [
                'pass'                            => true,
                'structure_status'                => 'pass',
                'structure_type'                  => 'base_reclaim',
                'structure_score'                 => (int)round($baseHoldScore),
                'higher_lows_count'               => $higherLows,
                'range_hold_score'                => 0.0,
                'base_hold_score'                 => round($baseHoldScore, 2),
                'recovery_structure_score'        => 0.0,
                'structure_warnings'              => $structureWarnings,
                'reason'                          => 'structure_ok',
                'structure_break_reference_price' => round($baseRef > 0 ? $baseRef : $lastClose, 6),
            ];
        }

        // ── 4. Recovery drift hold structure ──────────────────────────────────
        if ($recoveryDriftDetected && $recoveryAllowWithout) {
            $driftSlice   = array_slice($candles, max(0, $count - $recoveryMinDuration));
            $recoveryScore = 0.0;

            if (count($driftSlice) >= 60) {
                $startPrice = (float)(($driftSlice[0]['open'] ?? $driftSlice[0]['close']) ?: 0.0);

                $last30Slice = array_slice($candles, max(0, $count - 30));
                $recentHigh  = 0.0;
                $recentLow30 = PHP_FLOAT_MAX;
                foreach ($last30Slice as $bar) {
                    $h = (float)($bar['high'] ?? 0.0);
                    $l = (float)($bar['low']  ?? PHP_FLOAT_MAX);
                    if ($h > $recentHigh) {
                        $recentHigh = $h;
                    }
                    if ($l > 0 && $l < $recentLow30) {
                        $recentLow30 = $l;
                    }
                }
                $recentDumpPct = ($recentHigh > 0 && $recentLow30 < PHP_FLOAT_MAX)
                    ? (($recentHigh - $recentLow30) / $recentHigh) * 100
                    : 0.0;

                $aboveDriftStart = ($startPrice > 0 && $lastClose > $startPrice * 0.95);

                if ($aboveDriftStart && $recentDumpPct <= $recoveryMaxDump) {
                    $recoveryScore = $recentDumpPct < $recoveryMaxDump * 0.5 ? 0.85 : 0.70;
                }
            }

            if ($recoveryScore >= $recoveryMinScore) {
                $structureWarnings[] = 'recovery_drift_structure_without_classic_higher_lows';
                return [
                    'pass'                            => true,
                    'structure_status'                => 'warning',
                    'structure_type'                  => 'recovery_drift_hold',
                    'structure_score'                 => (int)round($recoveryScore * 10),
                    'higher_lows_count'               => $higherLows,
                    'range_hold_score'                => 0.0,
                    'base_hold_score'                 => 0.0,
                    'recovery_structure_score'        => round($recoveryScore, 2),
                    'structure_warnings'              => $structureWarnings,
                    'reason'                          => 'structure_ok',
                    'structure_break_reference_price' => round($lastClose, 6),
                ];
            }
        }

        // ── All structure types failed — hard reject ───────────────────────────
        // Keep no_higher_low_structure for backward compat when count is 0; otherwise no_valid_structure
        $rejectReason = ($higherLows === 0) ? 'no_higher_low_structure' : 'no_valid_structure';

        return [
            'pass'                            => false,
            'structure_status'                => 'reject',
            'structure_type'                  => 'none',
            'structure_score'                 => 0.0,
            'higher_lows_count'               => $higherLows,
            'range_hold_score'                => 0.0,
            'base_hold_score'                 => 0.0,
            'recovery_structure_score'        => 0.0,
            'structure_warnings'              => $structureWarnings,
            'reason'                          => $rejectReason,
            'structure_break_reference_price' => 0.0,
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
        // Use pre-computed structure_score when available (adaptive structure types)
        if (isset($r['structure_score']) && (float)$r['structure_score'] > 0) {
            return min(10, (int)round((float)$r['structure_score']));
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
        // Include structure warnings
        foreach ($structure['structure_warnings'] ?? [] as $w) {
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
            'higher_lows_count', 'structure_status', 'structure_type', 'structure_score',
            'range_hold_score', 'base_hold_score', 'recovery_structure_score', 'structure_warnings', 'structure_reason',
            'pullback_depth_pct', 'reclaim_level', 'pullback_low',
            'entry_distance_from_reclaim_pct', 'entry_distance_from_structure_pct',
            'candidate_quality_score',
            // Watchlist & acceleration
            'watch_state', 'first_seen_daily_change_pct', 'last_seen_daily_change_pct',
            'daily_change_delta_from_first', 'daily_change_delta_since_last',
            'time_from_first_seen_sec', 'momentum_acceleration_score',
            // Late-entry
            'candidate_first_seen_too_late', 'late_momentum_warning',
            // Recovery drift
            'recovery_drift_detected', 'recovery_drift_duration_minutes', 'recovery_drift_score',
            'recovery_after_dump_score', 'current_extension_score',
            'dump_risk_warning', 'recovery_drift_reason',
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

    // ── Watchlist helpers ─────────────────────────────────────────────────────

    /**
     * Update (or create) a watchlist entry for a symbol and return it.
     * $watchlist is passed by reference so the caller's copy is updated in place.
     */
    private function updateWatchlistEntry(
        array &$watchlist,
        string $symbol,
        float $dailyChangePct,
        string $momentumClass,
        int $now
    ): array {
        $watchState = match ($momentumClass) {
            'early_watch'  => 'early_watch',
            'active_watch' => 'active_watch',
            'observation'  => 'rejected_late',
            default        => 'candidate',
        };

        $existing = $watchlist[$symbol] ?? null;

        if ($existing === null) {
            $entry = [
                'symbol'                    => $symbol,
                'first_seen_at'             => date('c', $now),
                'first_seen_daily_change_pct' => $dailyChangePct,
                'last_seen_at'              => date('c', $now),
                'last_seen_daily_change_pct'=> $dailyChangePct,
                'prev_daily_change_pct'     => null,
                'max_seen_daily_change_pct' => $dailyChangePct,
                'daily_change_delta_from_first' => 0.0,
                'watch_state'               => $watchState,
                'watch_reason'              => $momentumClass,
                'observations_count'        => 1,
            ];
        } else {
            $prevDailyChange = (float)($existing['last_seen_daily_change_pct'] ?? $dailyChangePct);
            $entry = array_merge($existing, [
                'prev_daily_change_pct'     => $prevDailyChange,
                'last_seen_at'              => date('c', $now),
                'last_seen_daily_change_pct'=> $dailyChangePct,
                'max_seen_daily_change_pct' => max((float)($existing['max_seen_daily_change_pct'] ?? 0.0), $dailyChangePct),
                'daily_change_delta_from_first' => $dailyChangePct - (float)($existing['first_seen_daily_change_pct'] ?? $dailyChangePct),
                'watch_state'               => $watchState,
                'watch_reason'              => $momentumClass,
                'observations_count'        => (int)($existing['observations_count'] ?? 0) + 1,
            ]);
        }

        $watchlist[$symbol] = $entry;
        return $entry;
    }

    /**
     * Compute momentum acceleration diagnostics from a watchlist entry.
     */
    private function computeAccelerationDiagnostics(array $watchlistEntry, float $currentDailyChangePct, int $now): array
    {
        $firstSeenPct   = (float)($watchlistEntry['first_seen_daily_change_pct'] ?? $currentDailyChangePct);
        $prevPct        = (float)($watchlistEntry['prev_daily_change_pct']        ?? $currentDailyChangePct);
        $firstSeenAt    = $watchlistEntry['first_seen_at'] ?? null;

        $deltaFromFirst = round($currentDailyChangePct - $firstSeenPct, 4);
        $deltaSinceLast = round($currentDailyChangePct - $prevPct,       4);

        $timeFromFirstSec = 0;
        if ($firstSeenAt !== null) {
            $firstTs = strtotime((string)$firstSeenAt);
            if ($firstTs > 0) {
                $timeFromFirstSec = max(0, $now - $firstTs);
            }
        }

        // Acceleration score: how fast did the move develop after first seen?
        $accelerationScore = 0.0;
        if ($deltaFromFirst > 0 && $timeFromFirstSec > 0) {
            $ratePerHour       = ($deltaFromFirst / $timeFromFirstSec) * 3600.0;
            $accelerationScore = min(10.0, round($ratePerHour * 2.0, 2));
        }

        return [
            'daily_change_delta_from_first' => $deltaFromFirst,
            'daily_change_delta_since_last' => $deltaSinceLast,
            'time_from_first_seen_sec'      => $timeFromFirstSec,
            'momentum_acceleration_score'   => $accelerationScore,
        ];
    }

    /**
     * Recovery drift detection.
     * Determines whether the symbol has been rising in a controlled, sustained way
     * for at least recovery_drift_min_duration_minutes without a single dominant spike.
     * Does NOT gate the pipeline — returns a diagnostic payload only.
     */
    private function pipelineRecoveryDrift(array $candles, float $dailyChangePct, array $config): array
    {
        $minDriftMinutes  = (int)($config['recovery_drift_min_duration_minutes']          ?? 720);
        $maxSlopeSpike    = (float)($config['recovery_drift_max_slope_spike_pct']         ?? 4.0);
        $minDriftDailyPct = (float)($config['recovery_drift_min_daily_change_pct']        ?? 8.0);
        $maxDriftDailyPct = (float)($config['recovery_drift_max_daily_change_pct']        ?? 25.0);
        $maxExtension     = (float)($config['max_entry_extension_from_recent_pullback_pct'] ?? 3.0);

        $base = [
            'recovery_drift_detected'        => false,
            'recovery_drift_duration_minutes'=> 0,
            'recovery_drift_score'           => 0.0,
            'recovery_after_dump_score'      => 0.0,
            'current_extension_score'        => 0.0,
            'dump_risk_warning'              => false,
            'recovery_drift_reason'          => 'not_evaluated',
        ];

        if ($dailyChangePct < $minDriftDailyPct || $dailyChangePct > $maxDriftDailyPct) {
            return $base;
        }

        $count       = count($candles);
        $driftWindow = min($minDriftMinutes, $count);
        $driftSlice  = array_slice($candles, max(0, $count - $driftWindow));

        if (count($driftSlice) < 60) {
            return array_merge($base, ['recovery_drift_reason' => 'insufficient_candles']);
        }

        $startPrice = (float)(($driftSlice[0]['open'] ?? $driftSlice[0]['close']) ?: 0.0);
        $endPrice   = (float)(end($driftSlice)['close'] ?? 0.0);

        if ($startPrice <= 0.0 || $endPrice <= 0.0) {
            return $base;
        }

        $periodChangePct = (($endPrice - $startPrice) / $startPrice) * 100.0;
        if ($periodChangePct < 3.0) {
            return array_merge($base, ['recovery_drift_reason' => 'insufficient_drift_movement']);
        }

        // Check for single-candle spike within drift window
        $hasSpikeCandle = false;
        foreach ($driftSlice as $bar) {
            $o = (float)($bar['open']  ?? 0.0);
            $c = (float)($bar['close'] ?? 0.0);
            if ($o > 0.0 && (($c - $o) / $o * 100.0) > $maxSlopeSpike) {
                $hasSpikeCandle = true;
                break;
            }
        }

        // Compute max drawdown from period high
        $periodHigh = 0.0;
        $periodLow  = PHP_FLOAT_MAX;
        foreach ($driftSlice as $bar) {
            $h = (float)($bar['high']  ?? 0.0);
            $l = (float)($bar['low']   ?? PHP_FLOAT_MAX);
            if ($h > $periodHigh) { $periodHigh = $h; }
            if ($l > 0.0 && $l < $periodLow) { $periodLow = $l; }
        }
        $maxDrawdownFromHigh = ($periodHigh > 0.0 && $periodLow < PHP_FLOAT_MAX)
            ? (($periodHigh - $periodLow) / $periodHigh) * 100.0
            : 0.0;

        // Recovery-after-dump score: check if start of drift window was recovering from a prior drop
        $preDriftOffset = max(0, $count - $driftWindow - 60);
        $preDriftSlice  = array_slice($candles, $preDriftOffset, 60);
        $preDriftHigh   = 0.0;
        foreach ($preDriftSlice as $bar) {
            $h = (float)($bar['high'] ?? 0.0);
            if ($h > $preDriftHigh) { $preDriftHigh = $h; }
        }
        $dumpScore = 0.0;
        if ($preDriftHigh > 0.0 && $startPrice > 0.0 && $startPrice < $preDriftHigh) {
            $dumpFromHigh = (($preDriftHigh - $startPrice) / $preDriftHigh) * 100.0;
            $dumpScore    = min(10.0, round($dumpFromHigh / 3.0, 2));
        }

        // Drift score
        $driftScore = 0.0;
        if ($periodChangePct >= 3.0) {
            $driftScore += $hasSpikeCandle ? 2.0 : 5.0;
        }
        if ($maxDrawdownFromHigh < 15.0) {
            $driftScore += 3.0;
        } elseif ($maxDrawdownFromHigh < 25.0) {
            $driftScore += 1.5;
        }
        if (count($driftSlice) >= $minDriftMinutes) {
            $driftScore += 2.0;
        }

        $isRecoveryDrift = ($driftScore >= 5.0 && !$hasSpikeCandle && $maxDrawdownFromHigh < 20.0);

        // Current extension score: how far above the recent 30-bar low is the close?
        $last30    = array_slice($candles, max(0, $count - 30));
        $recentLow = PHP_FLOAT_MAX;
        foreach ($last30 as $bar) {
            $l = (float)($bar['low'] ?? PHP_FLOAT_MAX);
            if ($l > 0.0 && $l < $recentLow) { $recentLow = $l; }
        }
        $extensionScore = 0.0;
        if ($recentLow < PHP_FLOAT_MAX && $recentLow > 0.0) {
            $extensionPct   = (($endPrice - $recentLow) / $recentLow) * 100.0;
            $extensionScore = min(10.0, round($extensionPct / ($maxExtension * 2.0) * 10.0, 2));
        }

        $dumpRiskWarning = ($isRecoveryDrift && $extensionScore >= 7.0);

        return [
            'recovery_drift_detected'        => $isRecoveryDrift,
            'recovery_drift_duration_minutes'=> count($driftSlice),
            'recovery_drift_score'           => round($driftScore, 2),
            'recovery_after_dump_score'      => round($dumpScore, 2),
            'current_extension_score'        => round($extensionScore, 2),
            'dump_risk_warning'              => $dumpRiskWarning,
            'recovery_drift_reason'          => $isRecoveryDrift ? 'recovery_drift_ok' : 'no_recovery_drift',
        ];
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

    /**
     * Mark a non-stale signal for the given symbol as stale/invalidated.
     * Returns true if a signal was found and marked, false otherwise.
     */
    private function markSignalStale(
        array &$signals,
        string $symbol,
        string $stage,
        string $reason,
        string $decision,
        int $now
    ): bool {
        foreach ($signals as &$s) {
            if (($s['symbol'] ?? '') === $symbol
                && ($s['strategy_id'] ?? '') === self::STRATEGY_ID
                && !($s['stale'] ?? false)
            ) {
                $s['stale']                     = true;
                $s['invalidated_at']            = date('c', $now);
                $s['invalidated_reason']        = $reason;
                $s['invalidated_stage']         = $stage;
                $s['last_current_run_decision'] = $decision;
                return true;
            }
        }
        unset($s);
        return false;
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
            'storage/watchlist.json'         => (object)[],
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
