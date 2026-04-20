<?php

declare(strict_types=1);

/**
 * Fish Strategy — Service
 *
 * Orchestrates Рыбалка scanner cycles, both synchronous (for manual_list) and
 * batched/async (for large all-universe runs).
 *
 * ── Synchronous path (manual_list / smoke_demo) ──────────────────────────────
 *   run()         Full scan in one call.  Safe for small manual_list universes.
 *
 * ── Batched path (all-universe smoke test) ────────────────────────────────────
 *   queueRun()    Write run_state.json with status=queued and return immediately.
 *   tickBatch()   Process one batch (batch_size symbols) from the queued run.
 *                 Called by the cron runner; can also be triggered manually from
 *                 the admin UI.  Safe to call repeatedly — resumes from cursor.
 *
 * ── Bot execution ────────────────────────────────────────────────────────────
 *   tickBot()     Run one Fish bot execution cycle.
 *                 Enqueues any new signals, then calls FishExecutor::tick().
 *                 Called by the cron runner (120 s interval).
 *                 Requires bot_enabled = true in Fish config.
 */

namespace Modules\Strategy\Fish;

final class FishService
{
    private static ?self $instance = null;

    private string $moduleDir;

    /** Bybit kline interval string for H4 */
    private const H4_INTERVAL = '240';

    /**
     * Public no-arg constructor — required by CronManager which calls new FishService().
     * When called without arguments the module directory is resolved via SystemPaths.
     * When called with an explicit $moduleDir (legacy singleton path) that value is used.
     */
    public function __construct(?string $moduleDir = null)
    {
        if ($moduleDir !== null) {
            $this->moduleDir = rtrim($moduleDir, '/');
        } else {
            // CronManager path: resolve via SystemPaths (registered from manifest.json)
            $this->moduleDir = rtrim(
                \Core\System\SystemPaths::instance()->get('strategy.fish'),
                '/'
            );
        }
    }

    public static function instance(string $moduleDir): self
    {
        if (self::$instance === null) {
            self::$instance = new self($moduleDir);
        }
        return self::$instance;
    }

    // =========================================================================
    // Public: synchronous run (manual_list / small universes)
    // =========================================================================

    /**
     * Execute one full scanner cycle synchronously.
     * Use for manual_list universes or direct CLI invocation.
     * Do NOT call from HTTP for large all-universe scans (use queueRun/tickBatch).
     *
     * @return array  Run result with diagnostics
     */
    public function run(): array
    {
        $startMs = (int)round(microtime(true) * 1000);
        $runAt   = date('c');

        // Bootstrap: load + validate config
        require_once $this->moduleDir . '/bootstrap.php';
        $bootstrap = FishBootstrap::instance($this->moduleDir);

        try {
            $boot = $bootstrap->load();
        } catch (\Throwable $e) {
            $result = $this->failResult('Bootstrap failed: ' . $e->getMessage(), [], $startMs, $runAt);
            $this->persist($result, null, []);
            return $result;
        }

        if (!$boot['valid']) {
            $msg    = 'Config validation failed: ' . implode('; ', $boot['errors']);
            $result = $this->failResult($msg, $boot['errors'], $startMs, $runAt);
            $this->persist($result, $boot['config'], []);
            return $result;
        }

        $config = $boot['config'];

        // Module disabled — skip, report clean
        if (!($config['enabled'] ?? false) || ($config['mode'] ?? 'disabled') === 'disabled') {
            $result = $this->okResult(
                'Strategy loaded. Config valid. Module is disabled — scanner not run.',
                $config, $startMs, $runAt, []
            );
            $this->persist($result, $config, []);
            return $result;
        }

        // Load logic modules
        $this->requireLogic();

        // Run scanner
        [$signals, $diagnostics] = $this->scan($config, $runAt);

        $result = $this->okResult(
            sprintf(
                'Scanner completed. Scanned: %d symbols. Valid signals: %d.',
                $diagnostics['symbols_scanned'],
                $diagnostics['signals_valid']
            ),
            $config, $startMs, $runAt, $diagnostics
        );
        $result['signals_found'] = $diagnostics['signals_valid'];

        $this->persist($result, $config, $signals);

        return $result;
    }

    // =========================================================================
    // Public: batched/async run lifecycle
    // =========================================================================

    /**
     * Queue a batched run.
     * Writes run_state.json with status=queued and returns the run_id.
     * The actual processing happens via repeated tickBatch() calls (cron or manual).
     *
     * @return string  run_id
     */
    public function queueRun(): string
    {
        require_once $this->moduleDir . '/bootstrap.php';
        $boot   = FishBootstrap::instance($this->moduleDir)->load();
        $config = $boot['config'] ?? [];

        $runId = 'fish_run_' . date('Ymd_His');

        $state = [
            'run_id'                      => $runId,
            'run_status'                  => 'queued',
            'universe_mode'               => $config['universe_mode'] ?? 'all',
            'total_symbols'               => 0,
            'processed_symbols'           => 0,
            'remaining_symbols'           => 0,
            'batch_cursor'                => 0,
            'current_symbol'              => null,
            'started_at'                  => date('c'),
            'updated_at'                  => date('c'),
            'finished_at'                 => null,
            'last_error'                  => null,
            'signals_found'               => 0,
            'api_errors'                  => 0,
            'symbols_skipped'             => 0,
            'signals_geometry_valid'      => 0,
            'signals_geometry_rejected'   => 0,
            'signals_rr_below_min'        => 0,
            'signals_stop_side_invalid'   => 0,
            'signals_tp_side_invalid'     => 0,
            'batch_size'                  => (int)($config['batch_size'] ?? 20),
        ];

        $this->saveRunState($state);

        return $runId;
    }

    /**
     * Process one batch from the current queued/running run.
     *
     * - On first call after queueRun(): builds universe, transitions to running.
     * - Each call processes batch_size symbols from the cursor.
     * - Stops when max_runtime_seconds is exceeded (saves progress for next tick).
     * - When all symbols are processed, finalizes and writes signals.json / stats.
     *
     * @return array{ok: bool, status: string, message: string, ...}
     */
    public function tickBatch(): array
    {
        $tickAt = date('c');
        $state  = $this->loadRunState();
        $status = $state['run_status'] ?? 'idle';

        if (!in_array($status, ['queued', 'running'], true)) {
            return [
                'ok'      => false,
                'status'  => $status,
                'message' => 'No active run (current status: ' . $status . ')',
            ];
        }

        // Bootstrap config
        require_once $this->moduleDir . '/bootstrap.php';
        $boot = FishBootstrap::instance($this->moduleDir)->load();

        if (!$boot['valid']) {
            $msg = 'Config invalid: ' . implode('; ', $boot['errors'] ?? []);
            $state['run_status'] = 'failed';
            $state['last_error'] = $msg;
            $state['updated_at'] = date('c');
            $this->saveRunState($state);
            return ['ok' => false, 'status' => 'failed', 'message' => $msg];
        }

        $config      = $boot['config'];
        $batchSize   = (int)($config['batch_size']          ?? 20);
        $maxRunSec   = (int)($config['max_runtime_seconds'] ?? 55);
        $maxSymbols  = (int)($config['max_symbols_per_run'] ?? 0);
        $tickStart   = microtime(true);

        // Transition: queued → running (load and persist symbol list)
        if ($status === 'queued') {
            require_once $this->moduleDir . '/logic/universe.php';
            $universe   = new \Modules\Strategy\Fish\Logic\FishUniverse($this->moduleDir);
            $univResult = $universe->build($config);
            $allSymbols = $univResult['symbols'];

            if ($maxSymbols > 0) {
                $allSymbols = array_slice($allSymbols, 0, $maxSymbols);
            }

            $state['run_status']        = 'running';
            $state['total_symbols']     = count($allSymbols);
            $state['remaining_symbols'] = count($allSymbols);
            $state['batch_cursor']      = 0;
            $state['processed_symbols'] = 0;
            $state['started_at']        = date('c');
            $state['updated_at']        = date('c');

            $this->writeRunSymbols($allSymbols);
        }

        // Load symbol list (persisted in run_symbols.json)
        $allSymbols = $this->loadRunSymbols();
        $cursor     = (int)($state['batch_cursor'] ?? 0);

        if ($cursor >= count($allSymbols)) {
            return $this->finalizeRun($state, $config, $state['started_at'] ?? date('c'));
        }

        // Check trading window before processing
        $windowEnabled = (bool)($config['window_enabled'] ?? false);
        $windowStart   = (string)($config['window_start'] ?? '00:00');
        $windowEnd     = (string)($config['window_end']   ?? '23:59');

        if ($windowEnabled && !$this->isInsideWindow($windowStart, $windowEnd)) {
            $state['last_error'] = 'Outside trading window — skipped this tick.';
            $state['updated_at'] = date('c');
            $this->saveRunState($state);
            return [
                'ok'      => true,
                'status'  => 'running',
                'message' => 'Outside trading window — tick skipped. Run is still queued.',
            ];
        }

        // Load logic modules
        $this->requireLogic();

        // Process one batch
        $batch        = array_slice($allSymbols, $cursor, $batchSize);
        $runAt        = $state['started_at'] ?? date('c');
        [$batchSignals, $batchDiag] = $this->scanSymbols($batch, $config, $runAt);

        // Accumulate signals
        $pending   = $this->loadPendingSignals();
        $pending   = array_merge($pending, $batchSignals);
        $this->writePendingSignals($pending);

        // Update run state
        $newCursor                         = $cursor + count($batch);
        $state['batch_cursor']             = $newCursor;
        $state['processed_symbols']        = $newCursor;
        $state['remaining_symbols']        = max(0, count($allSymbols) - $newCursor);
        $state['current_symbol']           = end($batch) ?: null;
        $state['signals_found']            = ($state['signals_found'] ?? 0) + count($batchSignals);
        $state['api_errors']               = ($state['api_errors']    ?? 0) + ($batchDiag['symbols_skipped_api_err'] ?? 0);
        $state['symbols_skipped']          = ($state['symbols_skipped'] ?? 0) + ($batchDiag['symbols_skipped_no_data'] ?? 0);
        $state['symbols_scanned']          = ($state['symbols_scanned'] ?? 0) + ($batchDiag['symbols_scanned'] ?? 0);
        $state['structures_valid']         = ($state['structures_valid'] ?? 0) + ($batchDiag['structures_valid'] ?? 0);
        $state['structures_invalid']       = ($state['structures_invalid'] ?? 0) + ($batchDiag['structures_invalid'] ?? 0);
        $state['levels_found']             = ($state['levels_found'] ?? 0) + ($batchDiag['levels_found'] ?? 0);
        $state['levels_expired']           = ($state['levels_expired'] ?? 0) + ($batchDiag['levels_expired'] ?? 0);
        $state['candidates_valid']         = ($state['candidates_valid'] ?? 0) + ($batchDiag['candidates_valid'] ?? 0);
        $state['candidates_rejected']      = ($state['candidates_rejected'] ?? 0) + ($batchDiag['candidates_rejected'] ?? 0);
        $state['signals_geometry_valid']   = ($state['signals_geometry_valid']   ?? 0) + ($batchDiag['signals_geometry_valid']   ?? 0);
        $state['signals_geometry_rejected']= ($state['signals_geometry_rejected'] ?? 0) + ($batchDiag['signals_geometry_rejected'] ?? 0);
        $state['signals_rr_below_min']     = ($state['signals_rr_below_min']     ?? 0) + ($batchDiag['signals_rr_below_min']     ?? 0);
        $state['signals_stop_side_invalid']= ($state['signals_stop_side_invalid'] ?? 0) + ($batchDiag['signals_stop_side_invalid'] ?? 0);
        $state['signals_tp_side_invalid']  = ($state['signals_tp_side_invalid']  ?? 0) + ($batchDiag['signals_tp_side_invalid']  ?? 0);

        // Accumulate reject reason distribution
        foreach ($batchDiag['reject_reasons'] ?? [] as $reason => $count) {
            $state['reject_reasons'][$reason] = ($state['reject_reasons'][$reason] ?? 0) + $count;
        }

        $state['batches_completed']        = ($state['batches_completed'] ?? 0) + 1;
        $state['last_tick_at']             = $tickAt;
        $state['updated_at']               = date('c');

        $elapsed = microtime(true) - $tickStart;
        $isDone  = ($newCursor >= count($allSymbols));

        if ($isDone) {
            $state['last_tick_result'] = 'finalized';
            return $this->finalizeRun($state, $config, $runAt);
        }

        if ($elapsed >= $maxRunSec) {
            // Time limit reached — save progress, resume on next tick
            $state['last_tick_result'] = sprintf(
                'time_limit_reached (%d processed, %d remaining)',
                $newCursor,
                $state['remaining_symbols']
            );
            $this->saveRunState($state);
            return [
                'ok'        => true,
                'status'    => 'running',
                'message'   => sprintf(
                    'Batch done (%d processed, %d remaining). Time limit reached — resume next tick.',
                    $newCursor,
                    $state['remaining_symbols']
                ),
                'processed' => $newCursor,
                'total'     => count($allSymbols),
            ];
        }

        $state['last_tick_result'] = sprintf(
            'batch_ok (%d processed, %d remaining)',
            $newCursor,
            $state['remaining_symbols']
        );
        $this->saveRunState($state);
        return [
            'ok'        => true,
            'status'    => 'running',
            'message'   => sprintf(
                'Batch: %d processed, %d remaining.',
                $newCursor,
                $state['remaining_symbols']
            ),
            'processed' => $newCursor,
            'total'     => count($allSymbols),
        ];
    }

    // =========================================================================
    // Scanner pipeline
    // =========================================================================

    /**
     * Full scan (synchronous) — builds universe, checks window, scans all symbols.
     *
     * @return array{0: list<array>, 1: array}  [signals, diagnostics]
     */
    private function scan(array $config, string $runAt): array
    {
        $diag = $this->emptyDiag();

        require_once $this->moduleDir . '/logic/universe.php';
        $universe   = new \Modules\Strategy\Fish\Logic\FishUniverse($this->moduleDir);
        $univResult = $universe->build($config);
        $symbols    = $univResult['symbols'];
        $diag['symbols_total'] = count($symbols);

        if (empty($symbols)) {
            return [[], $diag];
        }

        // Trading window check
        $windowEnabled = (bool)($config['window_enabled'] ?? false);
        $windowStart   = (string)($config['window_start'] ?? '00:00');
        $windowEnd     = (string)($config['window_end']   ?? '23:59');

        if ($windowEnabled && !$this->isInsideWindow($windowStart, $windowEnd)) {
            $diag['symbols_skipped_window'] = count($symbols);
            foreach ($symbols as $_) {
                $this->bumpRejectReason($diag['reject_reasons'], 'outside_trading_window');
            }
            return [[], $diag];
        }

        [$signals, $batchDiag] = $this->scanSymbols($symbols, $config, $runAt);

        // Merge batch diag (everything except symbols_total / symbols_skipped_window)
        foreach ($batchDiag as $k => $v) {
            if ($k === 'reject_reasons') {
                foreach ($v as $reason => $count) {
                    $diag['reject_reasons'][$reason] = ($diag['reject_reasons'][$reason] ?? 0) + $count;
                }
            } elseif (isset($diag[$k]) || array_key_exists($k, $diag)) {
                // Accumulate numeric fields that exist in the parent diag
                if (is_int($v) || is_float($v)) {
                    $diag[$k] = ($diag[$k] ?? 0) + $v;
                }
            }
        }

        return [$signals, $diag];
    }

    /**
     * Scan a symbol slice through the full pipeline (structure → levels → risk → signal).
     * Callers must load logic requires (requireLogic()) before calling this.
     *
     * @param  array   $symbols  Symbols to process
     * @param  array   $config   Effective config
     * @param  string  $runAt    ISO-8601 run timestamp
     * @return array{0: list<array>, 1: array}  [signals, diagnostics_increment]
     */
    private function scanSymbols(array $symbols, array $config, string $runAt): array
    {
        $diag = [
            'symbols_scanned'            => 0,
            'symbols_skipped_no_data'    => 0,
            'symbols_skipped_api_err'    => 0,
            'structures_valid'           => 0,
            'structures_invalid'         => 0,
            'levels_found'               => 0,
            'levels_expired'             => 0,
            'candidates_valid'           => 0,
            'candidates_rejected'        => 0,
            'signals_valid'              => 0,
            'signals_geometry_valid'     => 0,
            'signals_geometry_rejected'  => 0,
            'signals_rr_below_min'       => 0,
            'signals_stop_side_invalid'  => 0,
            'signals_tp_side_invalid'    => 0,
            'reject_reasons'             => [],
        ];

        $structure     = new \Modules\Strategy\Fish\Logic\FishStructure();
        $levelDetector = new \Modules\Strategy\Fish\Logic\FishLiquidityLevel();
        $entryCalc     = new \Modules\Strategy\Fish\Logic\FishEntry();
        $riskCalc      = new \Modules\Strategy\Fish\Logic\FishRisk();
        $signalBuilder = new \Modules\Strategy\Fish\Logic\FishSignal();

        $pivotWindow = (int)($config['structure_pivot_window']         ?? 3);
        $minBars     = (int)($config['liquidity_pattern_min_bars']     ?? 3);
        $maxBars     = (int)($config['liquidity_pattern_max_bars']     ?? 4);
        $tolerance   = (float)($config['liquidity_level_tolerance']    ?? 0.003);
        $confirmReq  = (bool)($config['confirm_bar_required']          ?? true);
        $maxAgeBars  = (int)($config['level_max_age_bars']             ?? 20);
        $tpMult      = (float)($config['tp_multiplier']                ?? 4.0);
        $beMult      = (float)($config['breakeven_trigger_multiplier'] ?? 1.0);
        $lookback    = (int)($config['lookback_candles']               ?? 100);
        $bybitBase   = (string)($config['bybit_base_url']              ?? 'https://api.bybit.com');
        $timeoutSec  = (int)($config['bybit_timeout_sec']              ?? 10);
        $minRr       = (float)($config['min_rr_ratio']                 ?? 2.0);

        $allSignals    = [];
        $seenSignalIds = [];

        foreach ($symbols as $symbol) {
            // Fetch H4 candles
            $candles = $this->fetchKlines($symbol, self::H4_INTERVAL, $lookback, $bybitBase, $timeoutSec);

            if ($candles === null) {
                $diag['symbols_skipped_api_err']++;
                $this->bumpRejectReason($diag['reject_reasons'], 'api_error');
                continue;
            }

            if (count($candles) < ($pivotWindow * 2 + $minBars + 2)) {
                $diag['symbols_skipped_no_data']++;
                $this->bumpRejectReason($diag['reject_reasons'], 'insufficient_candles');
                continue;
            }

            $diag['symbols_scanned']++;

            // Trend structure
            $structResult = $structure->analyse($candles, $pivotWindow);

            if (!$structResult['valid']) {
                $diag['structures_invalid']++;
                $this->bumpRejectReason($diag['reject_reasons'], $structResult['reject_reason'] ?? 'invalid_structure');
                continue;
            }

            $trend = $structResult['trend_direction'];
            if ($trend === 'ranging' || $trend === 'unknown') {
                $diag['structures_invalid']++;
                $this->bumpRejectReason($diag['reject_reasons'], 'ranging_or_unknown_trend');
                continue;
            }

            $diag['structures_valid']++;
            $side = ($trend === 'bullish') ? 'long' : 'short';

            // Detect liquidity levels (direction-aware)
            $levels = $levelDetector->detect(
                $candles, $minBars, $maxBars, $tolerance, $confirmReq, $maxAgeBars, $side
            );

            if (empty($levels)) {
                $this->bumpRejectReason($diag['reject_reasons'], 'no_levels_found');
                continue;
            }

            foreach ($levels as $level) {
                if ($level['status'] === 'expired') {
                    $diag['levels_expired']++;
                    $this->bumpRejectReason($diag['reject_reasons'], 'level_expired');
                    continue;
                }

                $diag['levels_found']++;

                // Entry
                $entry = $entryCalc->calculate($side, $level);

                // Risk / geometry
                $risk = $riskCalc->calculate(
                    $side,
                    $entry['entry_price'],
                    $level,
                    $structResult,
                    $tpMult,
                    $beMult,
                    $minRr
                );

                if (!$risk['valid']) {
                    $diag['candidates_rejected']++;
                    $reason = $risk['reject_reason'] ?? 'risk_invalid';
                    $this->bumpRejectReason($diag['reject_reasons'], $reason);

                    // Geometry-specific counters
                    $diag['signals_geometry_rejected']++;
                    if ($reason === 'rr_below_min') {
                        $diag['signals_rr_below_min']++;
                    } elseif (in_array($reason, ['stop_above_entry', 'stop_below_entry'], true)) {
                        $diag['signals_stop_side_invalid']++;
                    } elseif (in_array($reason, ['tp_below_entry', 'tp_above_entry'], true)) {
                        $diag['signals_tp_side_invalid']++;
                    }
                    continue;
                }

                $diag['candidates_valid']++;
                $diag['signals_geometry_valid']++;

                // Build signal
                $signal = $signalBuilder->build(
                    $symbol, $side, $entry, $risk, $level, $structResult, $config, $runAt
                );

                $sid = $signal['signal_id'];
                if (isset($seenSignalIds[$sid])) {
                    continue;  // deduplicate
                }
                $seenSignalIds[$sid] = true;

                $allSignals[] = $signal;
                $diag['signals_valid']++;
            }
        }

        return [$allSignals, $diag];
    }

    // =========================================================================
    // Batched run helpers
    // =========================================================================

    /** Finalize a batched run: write signals, stats, last_run, clean up temp files. */
    private function finalizeRun(array $state, array $config, string $runAt): array
    {
        $startMs = isset($state['started_at'])
            ? (int)(strtotime($state['started_at']) * 1000)
            : (int)(microtime(true) * 1000);

        $state['run_status']  = 'done';
        $state['finished_at'] = date('c');
        $state['updated_at']  = date('c');
        $this->saveRunState($state);

        // Move accumulated signals to signals.json
        $signals = $this->loadPendingSignals();
        $this->writeSignals($signals);
        $this->clearPendingSignals();

        // Build summary result for stats / last_run
        $diag = [
            'symbols_total'              => $state['total_symbols']              ?? 0,
            'symbols_scanned'            => $state['symbols_scanned']            ?? $state['processed_symbols'] ?? 0,
            'symbols_skipped_api_err'    => $state['api_errors']                 ?? 0,
            'symbols_skipped_no_data'    => $state['symbols_skipped']            ?? 0,
            'symbols_skipped_window'     => $state['symbols_skipped_window']     ?? 0,
            'structures_valid'           => $state['structures_valid']           ?? 0,
            'structures_invalid'         => $state['structures_invalid']         ?? 0,
            'levels_found'               => $state['levels_found']               ?? 0,
            'levels_expired'             => $state['levels_expired']             ?? 0,
            'candidates_valid'           => $state['candidates_valid']           ?? 0,
            'candidates_rejected'        => $state['candidates_rejected']        ?? 0,
            'signals_valid'              => $state['signals_found']              ?? 0,
            'signals_geometry_valid'     => $state['signals_geometry_valid']     ?? 0,
            'signals_geometry_rejected'  => $state['signals_geometry_rejected']  ?? 0,
            'signals_rr_below_min'       => $state['signals_rr_below_min']       ?? 0,
            'signals_stop_side_invalid'  => $state['signals_stop_side_invalid']  ?? 0,
            'signals_tp_side_invalid'    => $state['signals_tp_side_invalid']    ?? 0,
            'reject_reasons'             => $state['reject_reasons']             ?? [],
        ];

        $result = $this->okResult(
            sprintf(
                'Batched run complete. Processed: %d symbols. Valid signals: %d.',
                $state['processed_symbols'] ?? 0,
                $state['signals_found']     ?? 0
            ),
            $config, $startMs, $runAt, $diag
        );
        $result['signals_found'] = $state['signals_found'] ?? 0;

        $this->writeRuntimeSnapshot($result, $config);
        $this->writeLastRun($result);
        $this->updateStats($result);

        return [
            'ok'        => true,
            'status'    => 'done',
            'message'   => $result['message'],
            'signals'   => count($signals),
        ];
    }

    // =========================================================================
    // Bybit H4 candle fetcher
    // =========================================================================

    /**
     * Fetch klines from the Bybit public API.
     * Returns null on any HTTP/parse error.
     * Returns candle array ordered oldest → newest.
     *
     * @return list<array>|null
     */
    private function fetchKlines(
        string $symbol,
        string $interval,
        int    $limit,
        string $baseUrl,
        int    $timeoutSec
    ): ?array {
        $url = rtrim($baseUrl, '/') . '/v5/market/kline'
            . '?category=linear'
            . '&symbol=' . urlencode($symbol)
            . '&interval=' . urlencode($interval)
            . '&limit=' . $limit;

        $ctx = stream_context_create([
            'http' => [
                'timeout'       => $timeoutSec,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || ($decoded['retCode'] ?? -1) !== 0) {
            return null;
        }

        $list = $decoded['result']['list'] ?? [];
        if (!is_array($list) || empty($list)) {
            return null;
        }

        // Bybit returns newest first — reverse to oldest first
        return array_reverse($list);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function requireLogic(): void
    {
        require_once $this->moduleDir . '/logic/structure.php';
        require_once $this->moduleDir . '/logic/liquidity_level.php';
        require_once $this->moduleDir . '/logic/entry.php';
        require_once $this->moduleDir . '/logic/risk.php';
        require_once $this->moduleDir . '/logic/signal.php';
    }

    private function emptyDiag(): array
    {
        return [
            'symbols_total'              => 0,
            'symbols_scanned'            => 0,
            'symbols_skipped_no_data'    => 0,
            'symbols_skipped_api_err'    => 0,
            'symbols_skipped_window'     => 0,
            'structures_valid'           => 0,
            'structures_invalid'         => 0,
            'levels_found'               => 0,
            'levels_expired'             => 0,
            'candidates_valid'           => 0,
            'candidates_rejected'        => 0,
            'signals_valid'              => 0,
            'signals_geometry_valid'     => 0,
            'signals_geometry_rejected'  => 0,
            'signals_rr_below_min'       => 0,
            'signals_stop_side_invalid'  => 0,
            'signals_tp_side_invalid'    => 0,
            'reject_reasons'             => [],
        ];
    }

    private function bumpRejectReason(array &$reasons, string $key): void
    {
        $reasons[$key] = ($reasons[$key] ?? 0) + 1;
    }

    private function isInsideWindow(string $windowStart, string $windowEnd): bool
    {
        $nowMinutes   = (int)gmdate('H') * 60 + (int)gmdate('i');
        $startMinutes = $this->parseHHMM($windowStart);
        $endMinutes   = $this->parseHHMM($windowEnd);

        if ($startMinutes <= $endMinutes) {
            return $nowMinutes >= $startMinutes && $nowMinutes < $endMinutes;
        }

        // Overnight window (e.g. 22:00–06:00)
        return $nowMinutes >= $startMinutes || $nowMinutes < $endMinutes;
    }

    private function parseHHMM(string $hhmm): int
    {
        $parts = explode(':', $hhmm);
        return (int)($parts[0] ?? 0) * 60 + (int)($parts[1] ?? 0);
    }

    // =========================================================================
    // Result builders
    // =========================================================================

    private function failResult(string $message, array $errors, int $startMs, string $runAt): array
    {
        return $this->buildResult('error', false, $errors, $message, $startMs, $runAt, []);
    }

    private function okResult(
        string $message,
        array  $config,
        int    $startMs,
        string $runAt,
        array  $diagnostics
    ): array {
        return $this->buildResult('ok', true, [], $message, $startMs, $runAt, $diagnostics);
    }

    private function buildResult(
        string $status,
        bool   $configValid,
        array  $configErrors,
        string $message,
        int    $startMs,
        string $runAt,
        array  $diagnostics
    ): array {
        $durationMs = (int)round(microtime(true) * 1000) - $startMs;

        return [
            'strategy_id'   => 'fish',
            'status'        => $status,
            'config_valid'  => $configValid,
            'config_errors' => $configErrors,
            'message'       => $message,
            'run_at'        => $runAt,
            'duration_ms'   => $durationMs,
            'signals_found' => $diagnostics['signals_valid'] ?? 0,
            'orders_placed' => 0,
            'errors_count'  => $configValid ? 0 : count($configErrors),
            'diagnostics'   => $diagnostics,
        ];
    }

    // =========================================================================
    // Persistence
    // =========================================================================

    private function persist(array $result, ?array $config, array $signals): void
    {
        $this->writeRuntimeSnapshot($result, $config);
        $this->writeLastRun($result);
        $this->writeSignals($signals);
        $this->updateStats($result);
    }

    private function writeRuntimeSnapshot(array $result, ?array $config): void
    {
        $snapshot = [
            'strategy_id'      => 'fish',
            'snapshot_version' => '0.1.0',
            'generated_at'     => $result['run_at'],
            'effective_config' => $config ?? [],
            'config_valid'     => $result['config_valid'],
            'config_errors'    => $result['config_errors'],
            'status'           => $result['status'],
        ];

        $export = '<?php' . "\n\n"
            . "declare(strict_types=1);\n\n"
            . "/**\n"
            . " * Fish Strategy — Runtime Snapshot\n"
            . " *\n"
            . " * Auto-generated by service.php. Do NOT edit manually.\n"
            . " * Generated: " . $result['run_at'] . "\n"
            . " */\n\n"
            . 'return ' . var_export($snapshot, true) . ";\n";

        @file_put_contents($this->moduleDir . '/config/runtime_snapshot.php', $export);
    }

    private function writeLastRun(array $result): void
    {
        @file_put_contents(
            $this->moduleDir . '/storage/last_run.json',
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
        );
    }

    private function writeSignals(array $signals): void
    {
        @file_put_contents(
            $this->moduleDir . '/storage/signals.json',
            json_encode($signals, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
        );
    }

    private function updateStats(array $result): void
    {
        $path  = $this->moduleDir . '/storage/stats.json';
        $stats = $this->loadStorage('stats.json');

        if (empty($stats)) {
            $stats = [
                'strategy_id'                     => 'fish',
                'total_runs'                      => 0,
                'successful_runs'                 => 0,
                'failed_runs'                     => 0,
                'signals_found_total'             => 0,
                'orders_placed_total'             => 0,
                'errors_count'                    => 0,
                'signals_geometry_valid_total'    => 0,
                'signals_geometry_rejected_total' => 0,
                'signals_rr_below_min_total'      => 0,
                'signals_stop_side_invalid_total' => 0,
                'signals_tp_side_invalid_total'   => 0,
                'last_updated'                    => null,
            ];
        }

        $diag = $result['diagnostics'] ?? [];

        $stats['total_runs']++;
        if ($result['status'] === 'ok') {
            $stats['successful_runs']++;
        } else {
            $stats['failed_runs']++;
        }
        $stats['signals_found_total']             += ($result['signals_found'] ?? 0);
        $stats['errors_count']                    += ($result['errors_count']  ?? 0);
        $stats['signals_geometry_valid_total']    += ($diag['signals_geometry_valid']    ?? 0);
        $stats['signals_geometry_rejected_total'] += ($diag['signals_geometry_rejected'] ?? 0);
        $stats['signals_rr_below_min_total']      += ($diag['signals_rr_below_min']      ?? 0);
        $stats['signals_stop_side_invalid_total'] += ($diag['signals_stop_side_invalid'] ?? 0);
        $stats['signals_tp_side_invalid_total']   += ($diag['signals_tp_side_invalid']   ?? 0);
        $stats['last_updated']                     = $result['run_at'];

        @file_put_contents(
            $path,
            json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
        );
    }

    // ─── Run state persistence ───────────────────────────────────────────────

    private function saveRunState(array $state): void
    {
        @file_put_contents(
            $this->moduleDir . '/storage/run_state.json',
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
        );
    }

    private function loadRunState(): array
    {
        $path = $this->moduleDir . '/storage/run_state.json';
        if (!file_exists($path)) {
            return ['run_status' => 'idle'];
        }
        $raw  = file_get_contents($path);
        $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : ['run_status' => 'idle'];
    }

    private function writeRunSymbols(array $symbols): void
    {
        @file_put_contents(
            $this->moduleDir . '/storage/run_symbols.json',
            json_encode($symbols, JSON_UNESCAPED_UNICODE) . "\n"
        );
    }

    private function loadRunSymbols(): array
    {
        $path = $this->moduleDir . '/storage/run_symbols.json';
        if (!file_exists($path)) {
            return [];
        }
        $raw  = file_get_contents($path);
        $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : [];
    }

    private function loadPendingSignals(): array
    {
        $path = $this->moduleDir . '/storage/signals_pending.json';
        if (!file_exists($path)) {
            return [];
        }
        $raw  = file_get_contents($path);
        $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : [];
    }

    private function writePendingSignals(array $signals): void
    {
        @file_put_contents(
            $this->moduleDir . '/storage/signals_pending.json',
            json_encode($signals, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
        );
    }

    private function clearPendingSignals(): void
    {
        $path = $this->moduleDir . '/storage/signals_pending.json';
        if (file_exists($path)) {
            @file_put_contents($path, json_encode([]) . "\n");
        }
    }

    // =========================================================================
    // Read-only accessors for admin pages
    // =========================================================================

    public function getConfig(): array
    {
        try {
            require_once $this->moduleDir . '/bootstrap.php';
            $boot = FishBootstrap::instance($this->moduleDir)->load();
            return $boot['config'];
        } catch (\Throwable) {
            return [];
        }
    }

    public function getRuntimeSnapshot(): array
    {
        $path = $this->moduleDir . '/config/runtime_snapshot.php';
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

    public function getLastRun(): array
    {
        $path = $this->moduleDir . '/storage/last_run.json';
        if (!file_exists($path)) {
            return [];
        }
        $raw  = file_get_contents($path);
        $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : [];
    }

    public function getRunState(): array
    {
        return $this->loadRunState();
    }

    public function loadStorage(string $filename): array
    {
        $path = $this->moduleDir . '/storage/' . $filename;
        if (!file_exists($path)) {
            return [];
        }
        $raw  = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public function getStats(): array
    {
        return $this->loadStorage('stats.json');
    }

    // =========================================================================
    // Public: Fish Bot tick
    // =========================================================================

    /**
     * Run one Fish bot execution cycle.
     *
     * Steps:
     *   1. Load config — if bot_enabled = false, return no-op result.
     *   2. Load signals.json; enqueue any signal not already in the execution queue.
     *   3. Instantiate bot components and call FishExecutor::tick().
     *   4. Return the tick summary.
     *
     * This method is called by the cron runner every 120 s.
     * It may also be triggered manually from the admin AJAX handler.
     *
     * @return array  Tick result
     */
    public function tickBot(): array
    {
        require_once $this->moduleDir . '/bootstrap.php';
        $boot = FishBootstrap::instance($this->moduleDir)->load();
        $config = $boot['config'] ?? [];

        if (!($config['bot_enabled'] ?? false)) {
            // Even when disabled, write a visible last_run so the UI shows a clear state.
            $this->requireBotClasses();
            $store = new \Modules\Strategy\Fish\Bot\FishBotStore($this->moduleDir);
            $store->initStorage();
            $summary = [
                'bot_enabled'       => false,
                'execution_mode'    => (string)($config['execution_mode'] ?? 'smoke'),
                'queue_depth'       => count($store->readExecutionQueue()),
                'active_orders'     => count($store->readActiveOrders()),
                'active_positions'  => count($store->readActivePositions()),
                'signals_enqueued'  => 0,
                'intents_processed' => 0,
                'orders_accepted'   => 0,
                'orders_rejected'   => 0,
                'last_tick'         => date('Y-m-d H:i:s'),
                'last_error'        => null,
                'status'            => 'disabled',
            ];
            $store->writeLastRun($summary);
            return [
                'ok'      => false,
                'status'  => 'disabled',
                'message' => 'Fish bot is disabled (bot_enabled = false in config).',
            ];
        }

        $this->requireBotClasses();

        $mode = (string)($config['execution_mode'] ?? 'smoke');

        $store   = new \Modules\Strategy\Fish\Bot\FishBotStore($this->moduleDir);
        $store->initStorage();

        $journal = new \Modules\Strategy\Fish\Bot\FishBotJournal($this->moduleDir);

        $exchange = new \Modules\Strategy\Fish\Bot\FishExchangeAdapter(
            $mode,
            (string)($config['account_id'] ?? '')
        );

        $slManager = new \Modules\Strategy\Fish\Bot\FishSlManager(
            $exchange, $journal, (string)($config['bot_sl_profile'] ?? 'default')
        );

        $positionManager = new \Modules\Strategy\Fish\Bot\FishPositionManager(
            $exchange, $store, $journal, $slManager, (string)($config['bot_pm_profile'] ?? 'default')
        );

        $pmManager    = new \Modules\Strategy\Fish\Bot\FishPmManager($positionManager, $store, $journal);
        $orderBuilder = new \Modules\Strategy\Fish\Bot\FishOrderBuilder();

        $executor = new \Modules\Strategy\Fish\Bot\FishExecutor(
            $exchange, $store, $journal, $orderBuilder, $pmManager, $config
        );

        // Enqueue any new signals from signals.json
        $signals   = $this->loadStorage('signals.json');
        $enqueued  = 0;
        foreach ($signals as $signal) {
            $signalId = (string)($signal['signal_id'] ?? '');
            if ($signalId === '') {
                continue;
            }
            // Merge config execution fields into the signal intent
            $intent = array_merge($signal, [
                'owner_strategy' => 'fish',
                'bot_budget'     => $config['bot_budget']  ?? 0.0,
                'bot_leverage'   => $config['bot_leverage'] ?? 1,
            ]);
            $store->enqueue($intent);
            $enqueued++;
        }

        $tickResult = $executor->tick();
        $tickResult['signals_enqueued'] = $enqueued;
        $tickResult['ok']               = true;

        // Always write bot_last_run.json regardless of queue depth
        $summary = [
            'bot_enabled'       => true,
            'execution_mode'    => $mode,
            'queue_depth'       => count($store->readExecutionQueue()),
            'active_orders'     => count($store->readActiveOrders()),
            'active_positions'  => count($store->readActivePositions()),
            'signals_enqueued'  => $enqueued,
            'intents_processed' => (int)($tickResult['intents_processed'] ?? 0),
            'orders_accepted'   => (int)($tickResult['orders_accepted']   ?? 0),
            'orders_rejected'   => (int)($tickResult['orders_rejected']   ?? 0),
            'last_tick'         => date('Y-m-d H:i:s'),
            'last_error'        => $tickResult['last_error'] ?? null,
            'status'            => 'ok',
            'gateway'           => $exchange->getDiagnostics(),
        ];
        $store->writeLastRun($summary);

        return $tickResult;
    }

    // =========================================================================
    // Public: Bot read-only accessors
    // =========================================================================

    public function getBotLastRun(): array
    {
        $path = $this->moduleDir . '/storage/bot_last_run.json';
        if (!file_exists($path)) {
            return [];
        }
        $raw  = file_get_contents($path);
        $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : [];
    }

    public function getBotStats(): array
    {
        $this->requireBotClasses();
        $store = new \Modules\Strategy\Fish\Bot\FishBotStore($this->moduleDir);
        return $store->readStats();
    }

    public function getBotActiveOrders(): array
    {
        return $this->loadStorage('bot_active_orders.json');
    }

    public function getBotActivePositions(): array
    {
        return $this->loadStorage('bot_active_positions.json');
    }

    public function getBotExecutionQueue(): array
    {
        return $this->loadStorage('bot_execution_queue.json');
    }

    /**
     * Return an initialized FishBotStore for direct access to mode-isolation helpers
     * (purgeSmokeOrders, purgeSmokePositions, countOpenOrdersByMode).
     */
    public function getBotStore(): \Modules\Strategy\Fish\Bot\FishBotStore
    {
        $this->requireBotClasses();
        return new \Modules\Strategy\Fish\Bot\FishBotStore($this->moduleDir);
    }

    // =========================================================================
    // Private: bot class autoloader
    // =========================================================================

    private function requireBotClasses(): void
    {
        $botDir = $this->moduleDir . '/bot/';
        foreach ([
            'store.php',
            'journal.php',
            'exchange_adapter.php',
            'order_builder.php',
            'sl_manager.php',
            'position_manager.php',
            'pm_manager.php',
            'executor.php',
        ] as $file) {
            require_once $botDir . $file;
        }
    }
}
