<?php

declare(strict_types=1);

/**
 * Corridor Bottom Long — Strategy
 *
 * 24h corridor bottom reversal strategy (validation-based).
 * Demo draft. NOT connected to cron, bot queue, or trading.
 *
 * Pipeline per symbol:
 *   1. LOAD SYMBOL DATA     — fetch 1m candles from Bybit (timestamps in seconds)
 *   2. CALCULATE 24h HIGH / LOW
 *   3. ZONE CHECK           — skip if price is not near the corridor low
 *   4. CANDIDATE DETECTION  — create / update candidate (state = waiting_validation)
 *   5. PATTERN DETECTION    — run enabled reversal patterns (only inside zone)
 *   6. VALIDATION ENGINE    — state machine: waiting → enter | reject
 *                             includes accumulation score + risk-to-low check
 *   7. SIGNAL OUTPUT        — write enriched signal to storage/signals.json
 *   8. LAST RUN DEBUG       — write full stats to storage/last_run.json
 *
 * Public entry points:
 *   run(?array $symbols): array           — full scan (requires enabled = true)
 *   runSimulation(?array $symbols): array — safe simulation (bypasses enabled guard)
 *
 * IMPORTANT RULES:
 *   - Does NOT send signals to the bot queue
 *   - Does NOT connect to any execution module
 *   - Does NOT modify other modules
 *   - Auto-enable is permanently disabled
 *   - No API keys stored here
 */

namespace Modules\Strategy\CorridorBottomLong;

require_once __DIR__ . '/lib/validation_engine.php';
require_once __DIR__ . '/lib/pattern_detector.php';

use Modules\Strategy\CorridorBottomLong\Lib\ValidationEngine;
use Modules\Strategy\CorridorBottomLong\Lib\PatternDetector;

final class CorridorBottomLongStrategy
{
    // ── Constants ─────────────────────────────────────────────────────────────

    private const STRATEGY_ID = 'corridor_bottom_long';
    private const KLINE_LIMIT = 1440; // 1440 × 1 min = 24 h

    // ── Properties ───────────────────────────────────────────────────────────

    private string $moduleDir;
    private array  $config;

    // ── Constructor ───────────────────────────────────────────────────────────

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
     *
     * @param  array|null $symbols  Optional symbol list (overrides universe build).
     * @return array
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
     *
     * @param  array $symbols  Optional symbol list.
     * @return array           Summary of the simulation run.
     */
    public function runSimulation(array $symbols = []): array
    {
        return $this->execute(empty($symbols) ? null : $symbols, true);
    }

    // ── Core pipeline ─────────────────────────────────────────────────────────

    /**
     * Shared implementation for run() and runSimulation().
     *
     * @param  array|null $symbols
     * @param  bool       $simulation  When true, enabled guard is skipped.
     * @return array
     */
    private function execute(?array $symbols, bool $simulation): array
    {
        $config = $this->config;
        $now    = time();

        $symbols = $symbols ?? $this->buildUniverse($config);

        // ── Universe batching (Task 4) ────────────────────────────────────────
        $runtime          = $this->readJson('storage/runtime.json', []);
        $cursor           = (int)($runtime['universe_cursor']   ?? 0);
        $universeCycleId  = (int)($runtime['universe_cycle_id'] ?? 0);
        $maxCount         = (int)($config['max_symbols_per_run'] ?? 50);
        $universeTotal    = count($symbols);

        // Clamp cursor if universe shrank
        if ($cursor > $universeTotal) {
            $cursor = 0;
        }

        $universeWrapped  = false;
        $batchStartIndex  = $cursor;

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

        // Initialise stats
        $stats = [
            'started_at'             => date('c', $now),
            'simulation'             => $simulation,
            'symbols_checked'        => 0,
            'no_candle_data'         => 0,
            'skipped_not_near_low'   => 0,
            'candidates_found'       => 0,
            'candidates_waiting'     => 0,
            'candidates_validated'   => 0,
            'generated_signals_count'=> 0,
            'rejected'               => 0,
            'reject_reasons'         => [],
            // Breakdown rejection counters (legacy names kept for UI compatibility)
            'rejected_fast_dump'          => 0,
            'rejected_new_low'            => 0,
            'rejected_no_micro_reversal'  => 0,
            'rejected_low_accumulation'   => 0,
            'rejected_risk_to_low'        => 0,
            // Universe batching
            'universe_total'         => $universeTotal,
            'batch_size'             => count($symbols),
            'batch_offset'           => $batchStartIndex,
            'batch_start_index'      => $batchStartIndex,
            'batch_end_index'        => $batchEndIndex,
            'next_cursor'            => $nextCursor,
            'universe_cycle_id'      => $universeCycleId,
            'universe_wrapped'       => $universeWrapped,
            // ── PART 1: Explicit validation pipeline counters ─────────────────
            'candidates_total_loaded'               => 0,
            'candidates_new_created'                => 0,
            'candidates_existing_rechecked'         => 0,
            'candidates_waiting_too_fresh'          => 0,
            'candidates_rejected_too_stale'         => 0,
            'candidates_rejected_new_low'           => 0,
            'candidates_rejected_fast_dump'         => 0,
            'candidates_rejected_no_micro_reversal' => 0,
            'candidates_rejected_no_accumulation'   => 0,
            'candidates_rejected_risk_to_low'       => 0,
            'candidates_validated_ok'               => 0,
            'signals_blocked_by_max_signals_per_run'=> 0,
            'signals_generated_current_run'         => 0,
            // ── PART 2: Candidate age diagnostics (computed after loop) ───────
            'candidate_min_age_seconds'  => null,
            'candidate_max_age_seconds'  => null,
            'candidate_avg_age_seconds'  => null,
            'validation_min_age_seconds' => null,
            'validation_max_age_seconds' => null,
            // ── PART 3: Normalized reject counters (computed after loop) ──────
            'reject_reasons_normalized'  => (object)[],
            'reject_examples'            => [],
            // ── PART 4: Signal readiness diagnostics ─────────────────────────
            'signal_rejected_cap_reached'        => 0,
            'signal_rejected_unknown'            => 0,
            'signal_rejected_missing_entry_price'=> 0,
            'signal_rejected_low_score'          => 0,
            'signal_rejected_duplicate'          => 0,
            // ── Config validation window (explicit, always populated) ─────────
            'validation_window_min_seconds'      => null,
            'validation_window_max_seconds'      => null,
        ];

        // Age-tracking arrays (used for PART 2 — computed after the main loop)
        // Populate config validation window from loaded config (always present even when no candidates validated)
        $stats['validation_window_min_seconds'] = isset($config['validation_min_age_seconds'])
            ? (int)$config['validation_min_age_seconds']
            : (isset($config['validation_window_seconds']) ? (int)$config['validation_window_seconds'] : null);
        $stats['validation_window_max_seconds'] = isset($config['validation_max_age_seconds'])
            ? (int)$config['validation_max_age_seconds']
            : null;
        $_waitingAges   = [];   // age-in-seconds for every 'waiting' candidate
        $_validatedAges = [];   // age-in-seconds for every validated candidate
        // Reject tracking for PART 3
        $_rejectCounters = [];  // [ reason => count ]
        $_rejectExamples = [];  // [ ['symbol'=>…, 'reason'=>…], … ] (max 10)

        // Track signals generated in THIS run only (not accumulated historical pool)
        $currentRunSignals = [];

        // Load persisted state
        $candidates = $this->readJson('storage/candidates.json', []);
        $signals    = $this->readJson('storage/signals.json',    []);

        $stats['candidates_total_loaded'] = count($candidates);

        $detector = new PatternDetector();
        $engine   = new ValidationEngine();

        foreach ($symbols as $symbol) {
            $stats['symbols_checked']++;

            // ── 1 + 2. Fetch candles, compute 24h corridor ───────────────────
            $candles = $this->fetchCandles((string)$symbol, $config);
            if (count($candles) < 2) {
                $stats['no_candle_data']++;
                continue;
            }

            $corridorLow  = min(array_column($candles, 'low'));
            $corridorHigh = max(array_column($candles, 'high'));
            $lastClose    = (float)(end($candles)['close'] ?? 0.0);

            if ($corridorLow <= 0.0 || $lastClose <= 0.0) {
                $stats['no_candle_data']++;
                continue;
            }

            // ── 3. Zone check ────────────────────────────────────────────────
            $distancePct    = (($lastClose - $corridorLow) / $corridorLow) * 100;
            $maxDistancePct = (float)($config['max_distance_from_low_pct'] ?? 8);

            if ($distancePct > $maxDistancePct) {
                $stats['skipped_not_near_low']++;
                continue;
            }

            $stats['candidates_found']++;

            // ── 4. Candidate detection / update ─────────────────────────────
            $candidateKey = strtolower($symbol) . '_' . self::STRATEGY_ID;
            $existing     = $this->findCandidate($candidates, $candidateKey);

            if ($existing === null) {
                // New candidate: enter the waiting_validation state
                $expiresAt = $now + (int)($config['validation_window_seconds'] ?? 240) + 60;
                $candidate = [
                    'key'           => $candidateKey,
                    'symbol'        => $symbol,
                    'state'         => 'waiting_validation',
                    'detected_at'   => $now,
                    'expires_at'    => $expiresAt,
                    'low_price'     => $corridorLow,
                    'high_price'    => $corridorHigh,
                    'current_price' => $lastClose,
                    'distance_pct'  => round($distancePct, 4),
                    'pattern_type'  => null,
                    'validation_score'   => 0,
                    'accumulation_score' => 0,
                    'risk_to_low_roi'    => 0.0,
                    'reject_reason'      => null,
                ];
                $stats['candidates_new_created']++;
            } else {
                // Continuation: update live price snapshot, keep detected_at
                $candidate                  = $existing;
                $candidate['current_price'] = $lastClose;
                $candidate['distance_pct']  = round($distancePct, 4);
                $candidate['low_price']     = $corridorLow;
                $candidate['high_price']    = $corridorHigh;
                $stats['candidates_existing_rechecked']++;
            }

            // ── 5. Pattern detection ─────────────────────────────────────────
            $patternResult = $detector->detect($candles, $corridorLow, $config);
            $candidate['pattern_type'] = $patternResult['best']['pattern_type'] ?? null;

            // ── 5b. Risk to corridor low ─────────────────────────────────────
            $leverage      = (float)($config['leverage'] ?? $config['default_leverage'] ?? 5);
            $riskToLowRoi  = $lastClose > 0
                ? (($lastClose - $corridorLow) / $lastClose) * $leverage * 100
                : 0.0;
            $riskToLowRoi  = round($riskToLowRoi, 2);
            $maxRisk        = (float)($config['max_risk_to_low_roi'] ?? 60);

            $candidate['risk_to_low_roi'] = $riskToLowRoi;

            if ($riskToLowRoi > $maxRisk) {
                // Candidate rejected before validation — too far from low
                $stats['rejected']++;
                $stats['reject_reasons'][]    = $symbol . ':risk_to_low_too_high';
                $stats['rejected_risk_to_low']++;
                $stats['candidates_rejected_risk_to_low']++;
                // PART 3: normalized
                $_rejectCounters['risk_to_low_too_high'] = ($_rejectCounters['risk_to_low_too_high'] ?? 0) + 1;
                if (count($_rejectExamples) < 10) {
                    $_rejectExamples[] = ['symbol' => $symbol, 'reason' => 'risk_to_low_too_high'];
                }
                $candidate['state']        = 'rejected';
                $candidate['reject_reason']= 'risk_to_low_too_high';
                $candidates = $this->upsertCandidate($candidates, $candidateKey, $candidate);
                // Clean immediately
                $candidates = $this->removeCandidate($candidates, $candidateKey);
                continue;
            }

            // ── 6. Validation engine ─────────────────────────────────────────
            $priceHistory = $this->loadPriceHistory($symbol, $candles);
            $result = $engine->evaluate(
                $candidate,
                $priceHistory,
                $candles,
                $patternResult,
                $config,
                $now
            );

            $decision = $result['decision'];
            $candidate['validation_score']   = $result['validation_score'];
            $candidate['accumulation_score'] = $result['accumulation_score'];

            if ($decision === 'waiting') {
                $stats['candidates_waiting']++;
                $stats['candidates_waiting_too_fresh']++;
                // PART 2: track age of waiting candidates
                $_waitingAges[] = $now - (int)($candidate['detected_at'] ?? $now);
                $candidate['state'] = 'waiting_validation';
                $candidates         = $this->upsertCandidate($candidates, $candidateKey, $candidate);
                continue;
            }

            if ($decision === 'reject') {
                $stats['rejected']++;
                $rejectReason = $result['reject_reason'] ?? 'rejected';
                $stats['reject_reasons'][] = $symbol . ':' . $rejectReason;
                // Legacy breakdown counters
                match ($rejectReason) {
                    'fast_dump'                  => $stats['rejected_fast_dump']++,
                    'new_low_broken'             => $stats['rejected_new_low']++,
                    'no_micro_reversal'          => $stats['rejected_no_micro_reversal']++,
                    'no_accumulation_after_dump' => $stats['rejected_low_accumulation']++,
                    'risk_to_low_too_high'       => $stats['rejected_risk_to_low']++,
                    default                      => null,
                };
                // PART 1: named pipeline counters
                match ($rejectReason) {
                    'candidate_too_stale'        => $stats['candidates_rejected_too_stale']++,
                    'new_low_broken'             => $stats['candidates_rejected_new_low']++,
                    'fast_dump'                  => $stats['candidates_rejected_fast_dump']++,
                    'no_micro_reversal'          => $stats['candidates_rejected_no_micro_reversal']++,
                    'no_accumulation_after_dump' => $stats['candidates_rejected_no_accumulation']++,
                    'risk_to_low_too_high'       => $stats['candidates_rejected_risk_to_low']++,
                    default                      => null,
                };
                // PART 3: normalized reject counters + examples
                $_rejectCounters[$rejectReason] = ($_rejectCounters[$rejectReason] ?? 0) + 1;
                if (count($_rejectExamples) < 10) {
                    $_rejectExamples[] = ['symbol' => $symbol, 'reason' => $rejectReason];
                }
                $candidate['state']        = 'rejected';
                $candidate['reject_reason']= $rejectReason;
                // Store briefly for diagnostics then clean
                $candidates = $this->upsertCandidate($candidates, $candidateKey, $candidate);
                $candidates = $this->removeCandidate($candidates, $candidateKey);
                continue;
            }

            // ── 7. Signal output (enter) ─────────────────────────────────────
            // Per-run signal cap
            $maxSignalsPerRun = max(1, (int)($config['max_signals_per_run'] ?? 3));
            if ($stats['generated_signals_count'] >= $maxSignalsPerRun) {
                // Cap reached — keep candidate alive for next run
                $stats['signals_blocked_by_max_signals_per_run']++;
                $stats['signal_rejected_cap_reached']++;
                $candidate['state'] = 'waiting_validation';
                $candidates         = $this->upsertCandidate($candidates, $candidateKey, $candidate);
                continue;
            }

            // PART 2: track age of validated candidates
            $_validatedAges[] = $now - (int)($candidate['detected_at'] ?? $now);

            $stats['candidates_validated']++;
            $stats['candidates_validated_ok']++;
            $stats['generated_signals_count']++;
            $stats['signals_generated_current_run']++;

            $signal = [
                // Identity — required by bot buildQueueItem
                'signal_id'          => $this->makeSignalId($symbol, $now),
                'strategy_id'        => self::STRATEGY_ID,
                'symbol'             => $symbol,
                'side'               => 'long',
                'mode'               => (string)($config['mode'] ?? 'demo'),

                // Entry geometry — required by bot execution path
                'entry_type'         => 'limit_near_low',
                'entry_mode'         => 'limit',
                'entry_price'        => $lastClose,

                // Lifecycle timestamps — detected_at used by bot age gate
                'detected_at'        => date('c', $now),
                'created_at'         => date('c', $now),

                // Strategy-specific diagnostics
                'pattern_type'       => $patternResult['best']['pattern_type'] ?? null,
                'reason'             => 'validated_corridor_bottom_long',
                'validation_score'   => $result['validation_score'],
                'accumulation_score' => $result['accumulation_score'],
                'risk_to_low_roi'    => $riskToLowRoi,
                'corridor_low'       => round($corridorLow,  6),
                'corridor_high'      => round($corridorHigh, 6),
                'current_price'      => $lastClose,
                'distance_pct'       => round($distancePct,  4),
                'score_reasons'      => $result['reasons'],
            ];

            $signals    = $this->upsertSignal($signals, $symbol, $signal);
            $currentRunSignals[] = $signal;
            $candidate['state'] = 'emitted';
            $candidates = $this->removeCandidate($candidates, $candidateKey);
        }

        // ── PART 2: Compute candidate age diagnostics ─────────────────────────
        if (!empty($_waitingAges)) {
            $stats['candidate_min_age_seconds'] = min($_waitingAges);
            $stats['candidate_max_age_seconds'] = max($_waitingAges);
            $stats['candidate_avg_age_seconds'] = (int)round(array_sum($_waitingAges) / count($_waitingAges));
        }
        if (!empty($_validatedAges)) {
            $stats['validation_min_age_seconds'] = min($_validatedAges);
            $stats['validation_max_age_seconds'] = max($_validatedAges);
        }

        // ── PART 3: Normalized reject counters ────────────────────────────────
        arsort($_rejectCounters);
        $stats['reject_reasons_normalized'] = (object)$_rejectCounters;
        $stats['reject_examples']           = $_rejectExamples;

        // Persist updated state (storage dirs created lazily by writeJson)
        $this->initStorage();
        $this->writeJson('storage/candidates.json', $candidates);
        $this->writeJson('storage/signals.json',    $signals);

        // ── Persist universe cursor for next run (Task 4) ─────────────────────
        $runtime['universe_cursor']   = $nextCursor;
        $runtime['universe_cycle_id'] = $universeCycleId;
        $runtime['last_run_at']       = date('c', $now);
        $this->writeJson('storage/runtime.json', $runtime);

        // ── Handoff queue (Task 1+2+3) ────────────────────────────────────────
        $handoffEnabled = (bool)($config['handoff_enabled'] ?? false);
        $stats['handoff_enabled'] = $handoffEnabled;

        // max_handoff_per_run is deprecated and NOT used here.
        // Quantity limits are the responsibility of global bot settings.
        $handoffCandidatesTotal         = 0;
        $handoffWrittenTotal            = 0;
        $handoffBlockedNotEligibleTotal = 0;
        $handoffBlockedStaleTotal       = 0;
        $handoffBlockedHistoricalTotal  = 0;
        $handoffRejectedInvalid         = 0;
        $handoffRejectReasons           = [];

        if ($handoffEnabled) {
            $handoffQueue = [];

            foreach ($currentRunSignals as $sig) {
                $handoffCandidatesTotal++;

                // Block stale or invalidated signals
                if ((bool)($sig['stale'] ?? false) || (bool)($sig['stale_invalidated'] ?? false)) {
                    $handoffBlockedStaleTotal++;
                    $handoffRejectReasons[] = ($sig['symbol'] ?? '?') . ':stale_or_invalidated';
                    continue;
                }

                // Block historical signals — only current-run signals may enter the queue
                if ((bool)($sig['historical_valid'] ?? false)
                    || ($sig['signal_status'] ?? '') === 'historical_valid') {
                    $handoffBlockedHistoricalTotal++;
                    $handoffRejectReasons[] = ($sig['symbol'] ?? '?') . ':historical_signal';
                    continue;
                }

                // Validate required bot-ready fields
                $validationError = $this->validateSignalForHandoff($sig);
                if ($validationError !== null) {
                    $handoffRejectedInvalid++;
                    $handoffRejectReasons[] = ($sig['symbol'] ?? '?') . ':' . $validationError;
                    continue;
                }

                $handoffQueue[] = array_merge($sig, [
                    'handoff_status'    => 'new',
                    'first_seen_at'     => date('c', $now),
                    'last_refreshed_at' => date('c', $now),
                    'seen_count'        => 1,
                ]);
                $handoffWrittenTotal++;
            }

            $this->writeJson('storage/bot_handoff_queue.json', $handoffQueue);
        } else {
            // handoff_enabled=false: always keep queue empty
            $this->writeJson('storage/bot_handoff_queue.json', []);
        }

        $stats['handoff_candidates_total']          = $handoffCandidatesTotal;
        $stats['handoff_written_total']             = $handoffWrittenTotal;
        $stats['handoff_blocked_not_eligible_total']= $handoffBlockedNotEligibleTotal;
        $stats['handoff_blocked_stale_total']       = $handoffBlockedStaleTotal;
        $stats['handoff_blocked_historical_total']  = $handoffBlockedHistoricalTotal;
        $stats['handoff_rejected_invalid']          = $handoffRejectedInvalid;
        $stats['handoff_reject_reasons']            = $handoffRejectReasons;
        $stats['local_handoff_cap_removed']         = true;
        $stats['handoff_limit_authority']           = 'global_bot_settings';

        // ── 8. Last-run diagnostics ───────────────────────────────────────────
        $stats['finished_at'] = date('c');
        $this->writeJson('storage/last_run.json', $stats);

        return ['ok' => true, 'stats' => $stats];
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

    /**
     * Build the symbol list from the market registry.
     * Falls back to an empty list on any error.
     */
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

        $symbols = array_values(
            array_filter($symbols, fn($s) => !in_array($s, $excluded, true))
        );

        return $symbols;
    }

    // ── Candle fetch ──────────────────────────────────────────────────────────

    /**
     * Fetch 1-minute klines from Bybit for the last 24 h.
     *
     * Bybit returns timestamps in milliseconds; they are converted to seconds here
     * so that all downstream comparisons use Unix seconds consistently.
     *
     * @return array  [ ['ts'=>int(s), 'open'=>float, 'high'=>float, 'low'=>float,
     *                   'close'=>float, 'volume'=>float], … ]  oldest → newest
     */
    private function fetchCandles(string $symbol, array $config): array
    {
        $interval   = (string)($config['kline_interval']    ?? '1');
        $limit      = (int)($config['lookback_candles']     ?? self::KLINE_LIMIT);
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
        $list    = array_reverse($list); // oldest → newest

        $candles = [];
        foreach ($list as $bar) {
            // Bybit timestamps are in milliseconds → convert to seconds
            $tsMs = (int)($bar[0] ?? 0);
            $tsSec = (int)floor($tsMs / 1000);

            $candles[] = [
                'ts'     => $tsSec,
                'open'   => (float)($bar[1] ?? 0),
                'high'   => (float)($bar[2] ?? 0),
                'low'    => (float)($bar[3] ?? 0),
                'close'  => (float)($bar[4] ?? 0),
                'volume' => (float)($bar[5] ?? 0),
            ];
        }

        return $candles;
    }

    // ── Price history ─────────────────────────────────────────────────────────

    /**
     * Build a tick-level price history for the validation engine.
     *
     * Primary source: PM price_history.json (keyed by symbol), timestamps in seconds.
     * Fallback: derive ticks from candle close prices (already in seconds).
     *
     * @return array  [ ['ts' => int, 'price' => float], … ]  oldest → newest
     */
    private function loadPriceHistory(string $symbol, array $candles): array
    {
        $pmPath = dirname(__DIR__, 3)
            . '/prof_manager/profiles/long/storage/price_history.json';

        if (file_exists($pmPath)) {
            $raw  = @file_get_contents($pmPath);
            $data = $raw ? json_decode($raw, true) : null;
            if (is_array($data) && isset($data[$symbol]) && is_array($data[$symbol])) {
                $ticks = [];
                foreach ($data[$symbol] as $entry) {
                    if (isset($entry['ts'], $entry['price'])) {
                        // Normalise: if ts looks like milliseconds (> year 3000 in seconds), convert
                        $ts = (int)$entry['ts'];
                        if ($ts > 32503680000) { // rough ms guard
                            $ts = (int)floor($ts / 1000);
                        }
                        $ticks[] = ['ts' => $ts, 'price' => (float)$entry['price']];
                    }
                }
                if (!empty($ticks)) {
                    usort($ticks, fn($a, $b) => $a['ts'] <=> $b['ts']);
                    return $ticks;
                }
            }
        }

        // Fallback: candle closes (already in seconds from fetchCandles)
        $ticks = [];
        foreach ($candles as $c) {
            if (isset($c['ts'], $c['close'])) {
                $ticks[] = ['ts' => (int)$c['ts'], 'price' => (float)$c['close']];
            }
        }
        return $ticks;
    }

    // ── Candidate helpers ─────────────────────────────────────────────────────

    private function findCandidate(array $candidates, string $key): ?array
    {
        foreach ($candidates as $c) {
            if (($c['key'] ?? '') === $key) {
                return $c;
            }
        }
        return null;
    }

    private function upsertCandidate(array $candidates, string $key, array $record): array
    {
        $replaced = false;
        foreach ($candidates as &$c) {
            if (($c['key'] ?? '') === $key) {
                $c        = $record;
                $replaced = true;
                break;
            }
        }
        unset($c);

        if (!$replaced) {
            $candidates[] = $record;
        }

        return $candidates;
    }

    private function removeCandidate(array $candidates, string $key): array
    {
        return array_values(
            array_filter($candidates, fn($c) => ($c['key'] ?? '') !== $key)
        );
    }

    // ── Signal helpers ────────────────────────────────────────────────────────

    /**
     * Upsert a signal — one active signal per symbol+strategy.
     */
    private function upsertSignal(array $signals, string $symbol, array $signal): array
    {
        $replaced = false;
        foreach ($signals as &$s) {
            if (($s['symbol'] ?? '') === $symbol
                && ($s['strategy_id'] ?? '') === self::STRATEGY_ID
            ) {
                $s        = $signal;
                $replaced = true;
                break;
            }
        }
        unset($s);

        if (!$replaced) {
            $signals[] = $signal;
        }

        return $signals;
    }

    private function makeSignalId(string $symbol, int $ts): string
    {
        return sprintf('%s_%s_%d', self::STRATEGY_ID, strtolower($symbol), $ts);
    }

    // ── Handoff validation (Task 2) ───────────────────────────────────────────

    /**
     * Validate that a signal has all required bot-ready fields.
     * Returns null if valid, or an error string if invalid.
     */
    private function validateSignalForHandoff(array $sig): ?string
    {
        if (($sig['signal_id']   ?? '') === '') { return 'missing_signal_id'; }
        if (($sig['strategy_id'] ?? '') !== self::STRATEGY_ID) { return 'wrong_strategy_id'; }
        if (($sig['symbol']      ?? '') === '') { return 'missing_symbol'; }
        if (($sig['side']        ?? '') !== 'long') { return 'wrong_side'; }
        if (($sig['mode']        ?? '') !== 'demo') { return 'wrong_mode'; }
        if ((float)($sig['entry_price'] ?? 0) <= 0) { return 'invalid_entry_price'; }
        if (($sig['detected_at'] ?? '') === '') { return 'missing_detected_at'; }
        $createdAt = $sig['created_at'] ?? '';
        if ($createdAt === '') { return 'missing_created_at'; }
        if (($sig['entry_mode']  ?? '') === '') { return 'missing_entry_mode'; }
        if (($sig['entry_type']  ?? '') === '') { return 'missing_entry_type'; }
        return null;
    }

    // ── Storage init ──────────────────────────────────────────────────────────

    /**
     * Lazily create all runtime storage files if they do not yet exist.
     * Called once per execute() cycle, before any reads or writes.
     */
    private function initStorage(): void
    {
        $defaults = [
            'storage/signals.json'           => [],
            'storage/candidates.json'        => [],
            'storage/runtime.json'           => (object)[],
            'storage/last_run.json'          => (object)[],
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
        $path = $this->moduleDir . '/' . $relPath;
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
