<?php

declare(strict_types=1);

/**
 * Stop Manager Module — Service
 *
 * Architecture:
 *   strategy modules → produce signals and position parameters
 *   bot module       → owns position lifecycle (active_positions.json)
 *   stop_manager     → sole owner of stop-loss computation and state
 *   profit manager   → separate, handles profit locks
 *
 * This module must NOT place or move stops on any exchange.
 * All computation is local only (same math for demo and paper modes).
 *
 * Stop modes:
 *   liq_distance_percent — stop placed between liquidation price and entry price.
 *
 *   liq_distance_percent is the % of the way from liquidation toward entry where
 *   the stop is placed.  Value is clamped 1..99.
 *
 *   For long:  stop = liq_price + (liq_distance_percent / 100) * (entry_price - liq_price)
 *   For short: stop = liq_price - (liq_distance_percent / 100) * (liq_price - entry_price)
 *
 *   Examples:
 *     liq_distance_percent = 90  → stop is 90% of the way from liq to entry (close to entry)
 *     liq_distance_percent = 10  → stop is 10% of the way from liq to entry (close to liq)
 *
 *   If liq_price is not available in the position record, an estimate is
 *   derived from entry_price and bot_leverage (isolated-margin approximation):
 *     long:  liq_estimate = entry_price × (1 − 1 / leverage)
 *     short: liq_estimate = entry_price × (1 + 1 / leverage)
 *   Positions with leverage ≤ 0 where no liq_price exists are skipped.
 *
 * Breakeven / profit-lock rule (optional):
 *   When ROI% ≥ breakeven_trigger_roi, the stop is shifted to lock
 *   breakeven_profit_lock_roi.  Requires current_price on the position.
 *   ROI = (current_price − entry_price) / entry_price × 100 × leverage (long)
 *       = (entry_price − current_price) / entry_price × 100 × leverage (short)
 *
 * Stop state values:
 *   active        — computed from a real (exchange-provided) liquidation price
 *   estimated_liq — computed from an estimated liquidation price (leverage fallback)
 *   no_liq        — cannot compute; liq_price unavailable and leverage unusable
 *   stale         — position closed; stop kept as closed reference
 *
 * Liquidation source values (liq_source field on every stop record):
 *   real      — exchange-provided liq_price > 0
 *   estimated — derived from entry_price and bot_leverage (isolated-margin approx)
 *   missing   — no valid liq data; stop_state will be no_liq
 *
 * Execution modes:
 *   disabled — initialize storage only; no stop computation
 *   demo     — stop computation for Bybit Demo positions + call setTradingStop on Bybit Demo
 *              (requires demo_execute_stops=true and bot demo credentials configured)
 *   paper    — local stop computation for paper/local positions (legacy)
 */

namespace Modules\StopManager;

final class StopManagerService
{
    private static ?self $instance = null;
    private string $moduleDir;
    private string $repoRoot;

    public function __construct(?string $moduleDir = null)
    {
        if ($moduleDir !== null) {
            $this->moduleDir = rtrim($moduleDir, '/');
        } else {
            $paths = \Core\System\SystemPaths::instance();
            $this->moduleDir = rtrim(
                $paths->has('stop_manager.stop_manager')
                    ? $paths->get('stop_manager.stop_manager')
                    : $paths->get('stop_manager'),
                '/'
            );
        }
        $this->repoRoot = rtrim(dirname($this->moduleDir, 2), '/');
    }

    public static function instance(string $moduleDir): self
    {
        if (self::$instance === null) {
            self::$instance = new self($moduleDir);
        }
        return self::$instance;
    }

    // =========================================================================
    // Public API
    // =========================================================================

    public function getConfig(): array
    {
        try {
            $this->requireBootstrap();
            return StopManagerBootstrap::instance($this->moduleDir)->load()['config'];
        } catch (\Throwable) {
            return [];
        }
    }

    public function getLastRun(): array
    {
        return $this->readJson('storage/last_run.json', []);
    }

    public function getStats(): array
    {
        return $this->readJson('storage/stats.json', []);
    }

    public function getStops(): array
    {
        return $this->readJson('storage/stops.json', []);
    }

    public function getRuntimeSnapshot(): array
    {
        try {
            $snap = require $this->moduleDir . '/config/runtime_snapshot.php';
            return is_array($snap) ? $snap : [];
        } catch (\Throwable) {
            return [];
        }
    }

    // =========================================================================
    // Cron entry-point
    // =========================================================================

    /**
     * CronManager / manual entry-point: process one tick.
     *
     * 1. Ensure storage files exist.
     * 2. Load config.
     * 3. If disabled: write truthful last_run and return.
     * 4. Load active positions from bot storage.
     * 5. Load existing stops.
     * 6. Process each position: initialize or recalculate stop, apply breakeven.
     * 7. Mark stops for positions that are no longer active.
     * 8. Persist stops, stats, last_run, runtime snapshot.
     */
    public function tick(): void
    {
        $this->initializeStorage();

        $config = $this->getConfig();
        if (empty($config)) {
            return;
        }

        if (!(bool)($config['enabled'] ?? false)) {
            $this->writeJson('storage/last_run.json', array_merge(
                $this->readJson('storage/last_run.json', []),
                [
                    'status'         => 'disabled',
                    'tick_at'        => date('c'),
                    'module_enabled' => false,
                    'module_mode'    => $config['mode'] ?? 'disabled',
                ]
            ));
            return;
        }

        $tickAt = date('c');
        $tStart = microtime(true);
        $mode   = (string)($config['mode'] ?? 'disabled');

        // ── 1. Load state ──────────────────────────────────────────────────────
        $positions = $this->loadBotPositions($config);
        $stops     = $this->readJson('storage/stops.json', []);
        $stats     = array_merge($this->zeroStats(), $this->getStats());

        // ── 2. Process ─────────────────────────────────────────────────────────
        $result = $this->processStops($positions, $stops, $config, $mode, $tickAt);
        $stops  = $result['stops'];

        // ── 3. Update stats ────────────────────────────────────────────────────
        $stats['ticks_total']                        += 1;
        $stats['positions_seen_total']               += $result['positions_seen'];
        $stats['positions_with_real_liq_total']      += $result['positions_with_real_liq'];
        $stats['positions_with_estimated_liq_total'] += $result['positions_with_estimated_liq'];
        $stats['positions_without_liq_total']        += $result['positions_without_liq'];
        $stats['stops_initialized_total']            += $result['stops_initialized'];
        $stats['stops_recalculated_total']           += $result['stops_recalculated'];
        $stats['breakeven_applied_total']            += $result['breakeven_applied'];
        $stats['stops_closed_reference_total']       += $result['stops_closed_reference'];
        $stats['stops_active_total']                  = count(array_filter(
            $stops,
            static fn(array $s) => in_array($s['stop_state'] ?? '', ['active', 'estimated_liq'], true)
        ));
        // Demo stop execution counters
        $stats['demo_stops_set_total']           += $result['demo_stops_set']           ?? 0;
        $stats['demo_stops_already_set_total']   += $result['demo_stops_already_set']   ?? 0;
        $stats['demo_stops_failed_total']        += $result['demo_stops_failed']        ?? 0;
        $stats['demo_stops_skipped_no_gw_total'] += $result['demo_stops_skipped_no_gw'] ?? 0;

        // ── 4. Persist ─────────────────────────────────────────────────────────
        $this->writeJson('storage/stops.json', array_values($stops));
        $this->writeJson('storage/stats.json', $stats);

        $elapsed = round(microtime(true) - $tStart, 4);

        $lastRun = [
            'status'                       => 'ok',
            'tick_at'                      => $tickAt,
            'elapsed_sec'                  => $elapsed,
            'module_enabled'               => true,
            'module_mode'                  => $mode,
            'positions_seen'               => $result['positions_seen'],
            'positions_with_real_liq'      => $result['positions_with_real_liq'],
            'positions_with_estimated_liq' => $result['positions_with_estimated_liq'],
            'positions_without_liq'        => $result['positions_without_liq'],
            'stops_initialized'            => $result['stops_initialized'],
            'stops_recalculated'           => $result['stops_recalculated'],
            'breakeven_applied'            => $result['breakeven_applied'],
            'stops_closed_reference'       => $result['stops_closed_reference'],
            'stops_active_count'           => (int)($stats['stops_active_total'] ?? 0),
            'ticks_total'                  => (int)($stats['ticks_total'] ?? 0),
            // Demo stop execution diagnostics (this tick)
            'demo_stops_set'               => $result['demo_stops_set']           ?? 0,
            'demo_stops_already_set'       => $result['demo_stops_already_set']   ?? 0,
            'demo_stops_failed'            => $result['demo_stops_failed']        ?? 0,
            'demo_stops_skipped_no_gw'     => $result['demo_stops_skipped_no_gw'] ?? 0,
            'demo_last_stop_error_code'    => $result['demo_last_stop_error_code'] ?? null,
            'demo_last_stop_error_msg'     => $result['demo_last_stop_error_msg']  ?? null,
            'demo_last_stop_symbol'        => $result['demo_last_stop_symbol']     ?? null,
        ];

        $this->writeJson('storage/last_run.json', $lastRun);
        $this->writeRuntimeSnapshot($config, $lastRun, (int)($stats['stops_active_total'] ?? 0));
    }

    // =========================================================================
    // Core stop processing
    // =========================================================================

    /**
     * Process all active positions against existing stops.
     *
     * In demo mode (when demo_execute_stops=true), after computing a valid stop price,
     * calls Bybit Demo /v5/position/trading-stop to set the stop on the exchange.
     * Credentials are sourced from the bot module config. If credentials are absent,
     * execution falls back to local-only computation (same as paper mode).
     *
     * @return array{
     *   stops: array,
     *   positions_seen: int,
     *   positions_with_real_liq: int,
     *   positions_with_estimated_liq: int,
     *   positions_without_liq: int,
     *   stops_initialized: int,
     *   stops_recalculated: int,
     *   breakeven_applied: int,
     *   stops_closed_reference: int,
     *   demo_stops_set: int,
     *   demo_stops_already_set: int,
     *   demo_stops_failed: int,
     *   demo_stops_skipped_no_gw: int,
     *   demo_last_stop_error_code: int|null,
     *   demo_last_stop_error_msg: string|null,
     *   demo_last_stop_symbol: string|null,
     * }
     */
    private function processStops(
        array $positions,
        array $stops,
        array $config,
        string $mode,
        string $tickAt
    ): array {
        $positionsSeen              = 0;
        $positionsWithRealLiq       = 0;
        $positionsWithEstimatedLiq  = 0;
        $positionsWithoutLiq        = 0;
        $stopsInitialized           = 0;
        $stopsRecalculated          = 0;
        $breakevenApplied           = 0;
        $stopsClosedReference       = 0;

        // Demo stop execution counters
        $demoStopsSet           = 0;
        $demoStopsAlreadySet    = 0;
        $demoStopsFailed        = 0;
        $demoStopsSkippedNoGw   = 0;
        $demoLastStopErrCode    = null;
        $demoLastStopErrMsg     = null;
        $demoLastStopSymbol     = null;

        $isPaperMode = in_array($mode, ['paper', 'demo'], true);
        $isDemoMode  = ($mode === 'demo');

        // Prepare demo gateway once if in demo mode and demo_execute_stops is enabled
        $demoExecuteStops = $isDemoMode && (bool)($config['demo_execute_stops'] ?? true);
        $demoGw           = $demoExecuteStops ? $this->getDemoGateway($config) : null;

        // Index positions by execution key
        $posMap = [];
        foreach ($positions as $pos) {
            $k = $this->positionKey($pos);
            if ($k !== '' && ($pos['position_status'] ?? '') === 'open') {
                $posMap[$k] = $pos;
            }
        }

        // Index stops by execution key
        $stopMap = [];
        foreach ($stops as $stop) {
            $k = $this->positionKey($stop);
            if ($k !== '') {
                $stopMap[$k] = $stop;
            }
        }

        if ($isPaperMode) {
            // ── Process active positions ─────────────────────────────────────
            foreach ($posMap as $key => $pos) {
                $positionsSeen++;
                $liqDistPct = max(1.0, min(99.0, (float)($config['liq_distance_percent'] ?? 90.0)));
                $bufferPct  = $liqDistPct / 100.0;

                // Classify liquidation data quality
                $liq       = $this->classifyLiqSource($pos);
                $liqSource = $liq['source'];  // 'real' | 'estimated' | 'missing'
                $liqPrice  = $liq['price'];   // float | null

                if ($liqSource === 'real') {
                    $positionsWithRealLiq++;
                } elseif ($liqSource === 'estimated') {
                    $positionsWithEstimatedLiq++;
                } else {
                    // missing — record no_liq stop so the position is tracked
                    $positionsWithoutLiq++;
                    $isNew = !isset($stopMap[$key]);
                    $stopMap[$key] = $this->buildNoLiqStop($pos, $tickAt);
                    if ($isNew) {
                        $stopsInitialized++;
                        $this->appendActionLog([
                            'timestamp'      => $tickAt,
                            'event_type'     => 'stop_skipped_missing_liq',
                            'strategy_id'    => $pos['strategy_id']    ?? '',
                            'owner_strategy' => $pos['owner_strategy'] ?? '',
                            'signal_id'      => $pos['signal_id']      ?? '',
                            'symbol'         => $pos['symbol']         ?? '',
                            'side'           => $pos['side']           ?? '',
                            'entry_price'    => (float)($pos['entry_price'] ?? 0.0),
                            'liq_source'     => 'missing',
                            'execution_mode' => $this->normalizeExecMode((string)($pos['execution_mode'] ?? 'paper')),
                            'reason'         => 'stop_skipped_missing_liq',
                        ]);
                    }
                    continue;
                }

                $entryPrice      = (float)($pos['entry_price'] ?? 0.0);
                $side            = (string)($pos['side'] ?? 'long');
                $stopPrice       = $this->calcStopPrice($side, $entryPrice, $liqPrice, $bufferPct);
                $initEventType   = $liqSource === 'real'
                    ? 'stop_initialized_real_liq'
                    : 'stop_initialized_estimated_liq';
                $recalcEventType = $liqSource === 'real'
                    ? 'stop_recalculated_real_liq'
                    : 'stop_recalculated_estimated_liq';

                if (!isset($stopMap[$key])) {
                    // New stop
                    $stopMap[$key] = $this->buildStop($pos, $stopPrice, $liqPrice, $liqSource, $tickAt, $initEventType);
                    $stopsInitialized++;
                    $this->appendActionLog([
                        'timestamp'      => $tickAt,
                        'event_type'     => $initEventType,
                        'strategy_id'    => $pos['strategy_id']    ?? '',
                        'owner_strategy' => $pos['owner_strategy'] ?? '',
                        'signal_id'      => $pos['signal_id']      ?? '',
                        'symbol'         => $pos['symbol']         ?? '',
                        'side'           => $side,
                        'entry_price'    => $entryPrice,
                        'liq_price'      => $liqPrice,
                        'liq_source'     => $liqSource,
                        'stop_price'     => $stopPrice,
                        'execution_mode' => $this->normalizeExecMode((string)($pos['execution_mode'] ?? 'paper')),
                        'reason'         => $initEventType,
                    ]);

                    // ── Demo: set stop on Bybit Demo exchange ─────────────────
                    if ($demoExecuteStops && $stopPrice > 0.0) {
                        if ($demoGw === null) {
                            $demoStopsSkippedNoGw++;
                            $stopMap[$key]['demo_stop_set']  = false;
                            $stopMap[$key]['demo_stop_note'] = 'no_gateway';
                        } else {
                            $setResult = $this->setDemoTradingStop($demoGw, (string)($pos['symbol'] ?? ''), $stopPrice);
                            $stopMap[$key]['demo_stop_set']      = $setResult['ok'];
                            $stopMap[$key]['demo_stop_note']     = $setResult['note'];
                            $stopMap[$key]['demo_stop_ret_code'] = $setResult['ret_code'];
                            if ($setResult['ok']) {
                                if ($setResult['note'] === 'already_set') {
                                    $demoStopsAlreadySet++;
                                } else {
                                    $demoStopsSet++;
                                }
                            } else {
                                $demoStopsFailed++;
                                $demoLastStopErrCode   = $setResult['ret_code'];
                                $demoLastStopErrMsg    = $setResult['ret_msg'];
                                $demoLastStopSymbol    = $setResult['symbol'];
                            }
                            $this->appendActionLog([
                                'timestamp'      => $tickAt,
                                'event_type'     => $setResult['ok'] ? 'demo_stop_set' : 'demo_stop_set_failed',
                                'symbol'         => $setResult['symbol'],
                                'stop_price'     => $setResult['stop_price'],
                                'ret_code'       => $setResult['ret_code'],
                                'ret_msg'        => $setResult['ret_msg'],
                                'note'           => $setResult['note'],
                                'reason'         => 'set_trading_stop_on_bybit_demo',
                            ]);
                        }
                    }
                } else {
                    // Recalculate existing stop
                    $prevStop      = $stopMap[$key];
                    $prevStopPrice = (float)($prevStop['stop_price'] ?? 0.0);
                    $prevState     = (string)($prevStop['stop_state'] ?? '');
                    $recalcReason  = null;

                    // Recalc if price changed meaningfully OR if transitioning out of no_liq
                    if (
                        abs($stopPrice - $prevStopPrice) > ($entryPrice * 0.00001)
                        || $prevState === 'no_liq'
                    ) {
                        $recalcReason = $recalcEventType;
                    }

                    // Apply breakeven if not yet applied
                    $breakevenWasApplied = (bool)($prevStop['breakeven_applied'] ?? false);
                    $breakEnabled        = (bool)($config['breakeven_enabled'] ?? false);
                    $currentPrice        = isset($pos['current_price'])
                        ? (float)$pos['current_price']
                        : null;

                    if ($breakEnabled && !$breakevenWasApplied && $currentPrice !== null && $entryPrice > 0.0) {
                        $leverage = max(1, (int)($pos['bot_leverage'] ?? 1));
                        $roi      = $this->calcRoi($side, $entryPrice, $currentPrice, $leverage);
                        $trigger  = (float)($config['breakeven_trigger_roi'] ?? 10.0);
                        $lockRoi  = (float)($config['breakeven_profit_lock_roi'] ?? 3.0);

                        if ($roi >= $trigger) {
                            $beStopPrice  = $this->calcBreakevenStop($side, $entryPrice, $leverage, $lockRoi);
                            $stopPrice    = $beStopPrice;
                            $recalcReason = 'breakeven_applied';
                            $breakevenApplied++;
                            $stopMap[$key]['breakeven_applied'] = true;

                            $this->appendActionLog([
                                'timestamp'      => $tickAt,
                                'event_type'     => 'breakeven_applied',
                                'strategy_id'    => $pos['strategy_id']    ?? '',
                                'owner_strategy' => $pos['owner_strategy'] ?? '',
                                'signal_id'      => $pos['signal_id']      ?? '',
                                'symbol'         => $pos['symbol']         ?? '',
                                'side'           => $side,
                                'entry_price'    => $entryPrice,
                                'liq_source'     => $liqSource,
                                'current_price'  => $currentPrice,
                                'roi'            => $roi,
                                'stop_price'     => $stopPrice,
                                'execution_mode' => $this->normalizeExecMode((string)($pos['execution_mode'] ?? 'paper')),
                                'reason'         => 'breakeven_applied',
                            ]);
                        }
                    }

                    if ($recalcReason !== null && $recalcReason !== 'breakeven_applied') {
                        $stopsRecalculated++;
                        $this->appendActionLog([
                            'timestamp'      => $tickAt,
                            'event_type'     => $recalcEventType,
                            'strategy_id'    => $pos['strategy_id']    ?? '',
                            'owner_strategy' => $pos['owner_strategy'] ?? '',
                            'signal_id'      => $pos['signal_id']      ?? '',
                            'symbol'         => $pos['symbol']         ?? '',
                            'side'           => $side,
                            'entry_price'    => $entryPrice,
                            'liq_price'      => $liqPrice,
                            'liq_source'     => $liqSource,
                            'stop_price'     => $stopPrice,
                            'execution_mode' => $this->normalizeExecMode((string)($pos['execution_mode'] ?? 'paper')),
                            'reason'         => $recalcEventType,
                        ]);
                    }

                    $newStopState = $liqSource === 'real' ? 'active' : 'estimated_liq';
                    $stopMap[$key]['stop_price']        = $stopPrice;
                    $stopMap[$key]['liq_price']         = $liqPrice;
                    $stopMap[$key]['liq_source']        = $liqSource;
                    $stopMap[$key]['stop_state']        = $newStopState;
                    $stopMap[$key]['last_updated_at']   = $tickAt;
                    $stopMap[$key]['stop_mode']         = 'liq_distance_percent';
                    $stopMap[$key]['execution_mode']    = $this->normalizeExecMode(
                        (string)($stopMap[$key]['execution_mode'] ?? 'paper')
                    );
                    // Update diagnostic distances on recalc
                    if ($liqPrice > 0.0 && $entryPrice > 0.0) {
                        $totalDist = $side === 'long'
                            ? $entryPrice - $liqPrice
                            : $liqPrice - $entryPrice;
                        if ($totalDist > 0.0) {
                            $stopFromLiq = $side === 'long'
                                ? $stopPrice - $liqPrice
                                : $liqPrice - $stopPrice;
                            $stopMap[$key]['distance_from_liq_pct']   = round($stopFromLiq / $totalDist * 100.0, 2);
                            $stopMap[$key]['distance_from_entry_pct'] = round(100.0 - $stopMap[$key]['distance_from_liq_pct'], 2);
                        }
                    }
                    if ($recalcReason !== null) {
                        $stopMap[$key]['transition_reason'] = $recalcReason;
                    }

                    // ── Demo: update stop on Bybit Demo exchange when stop changed ─
                    if ($demoExecuteStops && $recalcReason !== null && $stopPrice > 0.0) {
                        if ($demoGw === null) {
                            $demoStopsSkippedNoGw++;
                            $stopMap[$key]['demo_stop_set']  = false;
                            $stopMap[$key]['demo_stop_note'] = 'no_gateway';
                        } else {
                            $setResult = $this->setDemoTradingStop($demoGw, (string)($pos['symbol'] ?? ''), $stopPrice);
                            $stopMap[$key]['demo_stop_set']      = $setResult['ok'];
                            $stopMap[$key]['demo_stop_note']     = $setResult['note'];
                            $stopMap[$key]['demo_stop_ret_code'] = $setResult['ret_code'];
                            if ($setResult['ok']) {
                                if ($setResult['note'] === 'already_set') {
                                    $demoStopsAlreadySet++;
                                } else {
                                    $demoStopsSet++;
                                }
                            } else {
                                $demoStopsFailed++;
                                $demoLastStopErrCode   = $setResult['ret_code'];
                                $demoLastStopErrMsg    = $setResult['ret_msg'];
                                $demoLastStopSymbol    = $setResult['symbol'];
                            }
                            $this->appendActionLog([
                                'timestamp'      => $tickAt,
                                'event_type'     => $setResult['ok'] ? 'demo_stop_updated' : 'demo_stop_update_failed',
                                'symbol'         => $setResult['symbol'],
                                'stop_price'     => $setResult['stop_price'],
                                'ret_code'       => $setResult['ret_code'],
                                'ret_msg'        => $setResult['ret_msg'],
                                'note'           => $setResult['note'],
                                'recalc_reason'  => $recalcReason,
                                'reason'         => 'update_trading_stop_on_bybit_demo',
                            ]);
                        }
                    }
                }
            }

            // ── Mark stops for closed/missing positions ───────────────────────
            foreach ($stopMap as $key => $stop) {
                if (isset($posMap[$key])) {
                    continue;
                }
                if (($stop['stop_state'] ?? '') === 'stale') {
                    continue;
                }
                $stopMap[$key]['stop_state']        = 'stale';
                $stopMap[$key]['last_updated_at']   = $tickAt;
                $stopMap[$key]['transition_reason'] = 'stop_position_closed_reference';
                $stopsClosedReference++;

                $this->appendActionLog([
                    'timestamp'      => $tickAt,
                    'event_type'     => 'stop_position_closed_reference',
                    'strategy_id'    => $stop['strategy_id']    ?? '',
                    'owner_strategy' => $stop['owner_strategy'] ?? '',
                    'signal_id'      => $stop['signal_id']      ?? '',
                    'symbol'         => $stop['symbol']         ?? '',
                    'side'           => $stop['side']           ?? '',
                    'entry_price'    => $stop['entry_price']    ?? 0.0,
                    'liq_source'     => $stop['liq_source']     ?? 'missing',
                    'stop_price'     => $stop['stop_price']     ?? 0.0,
                    'execution_mode' => $this->normalizeExecMode((string)($stop['execution_mode'] ?? 'paper')),
                    'reason'         => 'position_no_longer_active',
                ]);
            }
        }

        return [
            'stops'                       => $stopMap,
            'positions_seen'              => $positionsSeen,
            'positions_with_real_liq'     => $positionsWithRealLiq,
            'positions_with_estimated_liq'=> $positionsWithEstimatedLiq,
            'positions_without_liq'       => $positionsWithoutLiq,
            'stops_initialized'           => $stopsInitialized,
            'stops_recalculated'          => $stopsRecalculated,
            'breakeven_applied'           => $breakevenApplied,
            'stops_closed_reference'      => $stopsClosedReference,
            'demo_stops_set'              => $demoStopsSet,
            'demo_stops_already_set'      => $demoStopsAlreadySet,
            'demo_stops_failed'           => $demoStopsFailed,
            'demo_stops_skipped_no_gw'    => $demoStopsSkippedNoGw,
            'demo_last_stop_error_code'   => $demoLastStopErrCode,
            'demo_last_stop_error_msg'    => $demoLastStopErrMsg,
            'demo_last_stop_symbol'       => $demoLastStopSymbol,
        ];
    }

    // =========================================================================
    // Stop math
    // =========================================================================

    /**
     * Calculate stop price using liq_distance_percent mode.
     *
     * Long:  stop = liq_price + buffer_pct × (entry_price − liq_price)
     * Short: stop = liq_price − buffer_pct × (liq_price − entry_price)
     *
     * bufferPct = liq_distance_percent / 100  (clamped 0.01..0.99)
     */
    private function calcStopPrice(string $side, float $entryPrice, float $liqPrice, float $bufferPct): float
    {
        if ($side === 'long') {
            $distance = $entryPrice - $liqPrice;
            return $liqPrice + $bufferPct * $distance;
        }
        // short
        $distance = $liqPrice - $entryPrice;
        return $liqPrice - $bufferPct * $distance;
    }

    /**
     * Calculate ROI for breakeven check.
     *
     * Long:  roi = (current − entry) / entry × 100 × leverage
     * Short: roi = (entry − current) / entry × 100 × leverage
     */
    private function calcRoi(string $side, float $entryPrice, float $currentPrice, int $leverage): float
    {
        if ($entryPrice <= 0.0) {
            return 0.0;
        }
        if ($side === 'long') {
            return ($currentPrice - $entryPrice) / $entryPrice * 100.0 * $leverage;
        }
        return ($entryPrice - $currentPrice) / $entryPrice * 100.0 * $leverage;
    }

    /**
     * Calculate breakeven stop price that locks the given ROI%.
     *
     * Long:  stop = entry × (1 + lock_roi / (100 × leverage))
     * Short: stop = entry × (1 − lock_roi / (100 × leverage))
     */
    private function calcBreakevenStop(string $side, float $entryPrice, int $leverage, float $lockRoi): float
    {
        $leverageSafe = max(1, $leverage);
        $fraction     = $lockRoi / (100.0 * $leverageSafe);
        if ($side === 'long') {
            return $entryPrice * (1.0 + $fraction);
        }
        return $entryPrice * (1.0 - $fraction);
    }

    /**
     * Classify the liquidation data quality for a position.
     *
     * Priority:
     *   1. liq_price > 0            → real      (exchange-provided)
     *   2. estimated_liq_price > 0  → estimated (bot-computed local paper estimate)
     *   3. formula from leverage    → estimated (fallback for legacy records)
     *   4. none of the above        → missing
     *
     * Zero, null, or absent liq_price is never treated as real.
     * estimated_liq_price set by the bot position contract is taken as-is.
     */
    private function classifyLiqSource(array $pos): array
    {
        $raw = $pos['liq_price'] ?? null;
        if ($raw !== null && is_numeric($raw) && (float)$raw > 0.0) {
            return ['source' => 'real', 'price' => (float)$raw];
        }

        // Explicit estimated liq provided by the bot position contract
        $explicit = $pos['estimated_liq_price'] ?? null;
        if ($explicit !== null && is_numeric($explicit) && (float)$explicit > 0.0) {
            return ['source' => 'estimated', 'price' => (float)$explicit];
        }

        // Fallback: derive from entry_price + bot_leverage (handles legacy records)
        $estimated = $this->estimateLiqPrice($pos);
        if ($estimated !== null && $estimated > 0.0) {
            return ['source' => 'estimated', 'price' => $estimated];
        }

        return ['source' => 'missing', 'price' => null];
    }

    /**
     * Estimate liquidation price when it is not provided in the position record.
     * Uses simplified isolated-margin approximation.
     *
     * Long:  liq_estimate = entry × (1 − 1 / leverage)
     * Short: liq_estimate = entry × (1 + 1 / leverage)
     *
     * Returns null if leverage is 0 or entry_price is 0.
     */
    private function estimateLiqPrice(array $pos): ?float
    {
        $entryPrice = (float)($pos['entry_price'] ?? 0.0);
        $leverage   = (int)($pos['bot_leverage']  ?? 0);
        $side       = (string)($pos['side']        ?? 'long');

        if ($entryPrice <= 0.0 || $leverage <= 0) {
            return null;
        }

        if ($side === 'long') {
            $estimate = $entryPrice * (1.0 - 1.0 / $leverage);
        } else {
            $estimate = $entryPrice * (1.0 + 1.0 / $leverage);
        }

        // Leverage = 1 long yields estimate = 0; any non-positive result is unusable.
        if ($estimate <= 0.0) {
            return null;
        }

        return $estimate;
    }

    // =========================================================================
    // Stop record builders
    // =========================================================================

    private function buildStop(
        array $pos,
        float $stopPrice,
        float $liqPrice,
        string $liqSource,
        string $tickAt,
        string $reason
    ): array {
        $stopState   = $liqSource === 'real' ? 'active' : 'estimated_liq';
        $entryPrice  = (float)($pos['entry_price'] ?? 0.0);
        $side        = (string)($pos['side'] ?? 'long');

        // Diagnostic distances
        $distFromLiqPct   = null;
        $distFromEntryPct = null;
        if ($liqPrice > 0.0 && $entryPrice > 0.0) {
            $totalDist = $side === 'long'
                ? $entryPrice - $liqPrice
                : $liqPrice - $entryPrice;
            if ($totalDist > 0.0) {
                $stopFromLiq = $side === 'long'
                    ? $stopPrice - $liqPrice
                    : $liqPrice - $stopPrice;
                $distFromLiqPct   = round($stopFromLiq / $totalDist * 100.0, 2);
                $distFromEntryPct = round(100.0 - $distFromLiqPct, 2);
            }
        }

        return [
            'owner_strategy'        => (string)($pos['owner_strategy'] ?? ''),
            'strategy_id'           => (string)($pos['strategy_id']    ?? ''),
            'signal_id'             => (string)($pos['signal_id']      ?? ''),
            'symbol'                => (string)($pos['symbol']         ?? ''),
            'side'                  => $side,
            'entry_price'           => $entryPrice,
            'liq_price'             => $liqPrice,
            'liq_source'            => $liqSource,
            'execution_mode'        => $this->normalizeExecMode((string)($pos['execution_mode'] ?? 'paper')),
            'stop_mode'             => 'liq_distance_percent',
            'stop_price'            => $stopPrice,
            'stop_state'            => $stopState,
            'distance_from_liq_pct'   => $distFromLiqPct,
            'distance_from_entry_pct' => $distFromEntryPct,
            'breakeven_applied'     => false,
            'last_updated_at'       => $tickAt,
            'created_at'            => $tickAt,
            'transition_reason'     => $reason,
        ];
    }

    private function buildNoLiqStop(array $pos, string $tickAt): array
    {
        return [
            'owner_strategy'    => (string)($pos['owner_strategy'] ?? ''),
            'strategy_id'       => (string)($pos['strategy_id']    ?? ''),
            'signal_id'         => (string)($pos['signal_id']      ?? ''),
            'symbol'            => (string)($pos['symbol']         ?? ''),
            'side'              => (string)($pos['side']           ?? 'long'),
            'entry_price'       => (float)($pos['entry_price']     ?? 0.0),
            'liq_price'         => null,
            'liq_source'        => 'missing',
            'execution_mode'    => $this->normalizeExecMode((string)($pos['execution_mode'] ?? 'paper')),
            'stop_mode'         => 'liq_distance_percent',
            'stop_price'        => null,
            'stop_state'        => 'no_liq',
            'last_updated_at'   => $tickAt,
            'created_at'        => $tickAt,
            'transition_reason' => 'no_liq_price_available',
        ];
    }

    // =========================================================================
    // Bot positions reader
    // =========================================================================

    private function loadBotPositions(array $config): array
    {
        $relPath = (string)($config['bot_positions_path'] ?? 'modules/bot/storage/active_positions.json');
        $absPath = str_starts_with($relPath, '/')
            ? $relPath
            : $this->repoRoot . '/' . $relPath;

        if (!file_exists($absPath)) {
            return [];
        }
        $raw = file_get_contents($absPath);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    // =========================================================================
    // Storage initialization
    // =========================================================================

    private function initializeStorage(): void
    {
        $storageDir = $this->moduleDir . '/storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        $defaults = [
            'storage/stops.json'    => [],
            'storage/last_run.json' => [
                'status'                       => 'never_run',
                'tick_at'                      => null,
                'elapsed_sec'                  => 0,
                'module_enabled'               => false,
                'module_mode'                  => 'disabled',
                'positions_seen'               => 0,
                'positions_with_real_liq'      => 0,
                'positions_with_estimated_liq' => 0,
                'positions_without_liq'        => 0,
                'stops_initialized'            => 0,
                'stops_recalculated'           => 0,
                'breakeven_applied'            => 0,
                'stops_closed_reference'       => 0,
                'stops_active_count'           => 0,
                'ticks_total'                  => 0,
            ],
            'storage/stats.json' => $this->zeroStats(),
        ];

        foreach ($defaults as $relPath => $default) {
            $absPath = $this->moduleDir . '/' . $relPath;
            if (!file_exists($absPath)) {
                $this->writeJson($relPath, $default);
            }
        }

        // Ensure actions_log.ndjson exists (append-only; not overwritten)
        $logPath = $this->moduleDir . '/storage/actions_log.ndjson';
        if (!file_exists($logPath)) {
            @file_put_contents($logPath, '');
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Load the bot module config (base.php merged with active.php).
     *
     * Used to obtain demo API credentials for Bybit Demo gateway calls.
     * Returns an empty array if the bot config cannot be loaded.
     */
    private function loadBotConfig(array $smConfig): array
    {
        $botRelDir = (string)($smConfig['bot_module_dir'] ?? 'modules/bot');
        $botDir    = str_starts_with($botRelDir, '/')
            ? rtrim($botRelDir, '/')
            : $this->repoRoot . '/' . rtrim($botRelDir, '/');

        try {
            $base   = is_file($botDir . '/config/base.php')   ? (require $botDir . '/config/base.php')   : [];
            $active = is_file($botDir . '/config/active.php') ? (require $botDir . '/config/active.php') : [];
            return array_merge(is_array($base) ? $base : [], is_array($active) ? $active : []);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Get a Bybit Demo gateway client using credentials from the bot config.
     *
     * Returns null when credentials are not configured or on error.
     * Never logs or exposes API keys.
     */
    private function getDemoGateway(array $smConfig): ?\Core\Gateway\Bybit
    {
        $botConfig = $this->loadBotConfig($smConfig);
        $apiKey    = (string)($botConfig['demo_api_key']      ?? '');
        $apiSecret = (string)($botConfig['demo_api_secret']   ?? '');
        $baseUrl   = (string)($botConfig['demo_api_base_url'] ?? 'https://api-demo.bybit.com');

        if ($apiKey === '' || $apiSecret === '') {
            return null;
        }

        try {
            $gw = \Core\Gateway\Bybit::client('bybit_demo_sm');
            $gw->setCredentials($apiKey, $apiSecret);
            $gw->setBaseUrl($baseUrl);
            return $gw;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Call Bybit Demo /v5/position/trading-stop to set a stop-loss.
     *
     * Treats the following as success:
     *   - retCode = 0
     *   - retCode = 110043 (already set / not modified)
     *   - retMsg contains "not modified" or "not been modified"
     *
     * Never logs API keys.
     *
     * @return array{
     *   ok: bool,
     *   symbol: string,
     *   stop_price: float,
     *   ret_code: int,
     *   ret_msg: string,
     *   note: string
     * }
     */
    private function setDemoTradingStop(
        \Core\Gateway\Bybit $gw,
        string $symbol,
        float $stopPrice
    ): array {
        $stopStr = rtrim(rtrim(number_format($stopPrice, 8, '.', ''), '0'), '.');

        try {
            $resp = $gw->request('/v5/position/trading-stop', [
                'category'    => 'linear',
                'symbol'      => $symbol,
                'stopLoss'    => $stopStr,
                'positionIdx' => 0,
            ], true);
        } catch (\Throwable $ex) {
            return [
                'ok'         => false,
                'symbol'     => $symbol,
                'stop_price' => $stopPrice,
                'ret_code'   => -1,
                'ret_msg'    => $ex->getMessage(),
                'note'       => 'exception',
            ];
        }

        $retCode = (int)($resp['ret_code'] ?? -1);
        $retMsg  = (string)($resp['ret_msg'] ?? '');

        $ok = $retCode === 0
            || $retCode === 110043
            || stripos($retMsg, 'not modified') !== false
            || stripos($retMsg, 'not been modified') !== false;

        $note = 'rejected';
        if ($ok) {
            $note = ($retCode === 110043
                     || stripos($retMsg, 'not modified') !== false
                     || stripos($retMsg, 'not been modified') !== false)
                ? 'already_set'
                : 'set_ok';
        }

        return [
            'ok'         => $ok,
            'symbol'     => $symbol,
            'stop_price' => $stopPrice,
            'ret_code'   => $retCode,
            'ret_msg'    => $retMsg,
            'note'       => $note,
        ];
    }

    /**
     * Normalize legacy execution_mode values into the canonical set.
     *   smoke  → paper  (legacy alias)
     *   active → paper  (legacy alias)
     * Any other value is returned unchanged; unknown values fall back to 'paper'.
     */
    private function normalizeExecMode(string $raw): string
    {
        return match ($raw) {
            'smoke', 'active' => 'paper',
            'paper', 'demo', 'disabled', 'passive' => $raw,
            default => 'paper',
        };
    }

    private function positionKey(array $item): string
    {
        $signalId = (string)($item['signal_id'] ?? '');
        if ($signalId === '') {
            return '';
        }
        $stratId = (string)($item['strategy_id'] ?? $item['owner_strategy'] ?? 'unknown');
        return $stratId . ':' . $signalId;
    }

    private function zeroStats(): array
    {
        return [
            'ticks_total'                         => 0,
            'positions_seen_total'                => 0,
            'positions_with_real_liq_total'       => 0,
            'positions_with_estimated_liq_total'  => 0,
            'positions_without_liq_total'         => 0,
            'stops_initialized_total'             => 0,
            'stops_recalculated_total'            => 0,
            'breakeven_applied_total'             => 0,
            'stops_closed_reference_total'        => 0,
            'stops_active_total'                  => 0,
            // Demo stop-loss execution counters (demo mode only)
            'demo_stops_set_total'                => 0,
            'demo_stops_already_set_total'        => 0,
            'demo_stops_failed_total'             => 0,
            'demo_stops_skipped_no_gw_total'      => 0,
        ];
    }

    private function appendActionLog(array $event): void
    {
        $path = $this->moduleDir . '/storage/actions_log.ndjson';
        $line = json_encode($event, JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    private function writeRuntimeSnapshot(array $config, array $lastRun, int $stopsActiveTotal): void
    {
        $snap = [
            'snapshot_at'          => date('c'),
            'module_id'            => 'stop_manager',
            'mode'                 => $config['mode']    ?? 'disabled',
            'enabled'              => $config['enabled'] ?? false,
            'tick_at'              => $lastRun['tick_at']        ?? null,
            'last_tick_result'     => $lastRun['status']         ?? 'ok',
            'positions_seen_total' => $lastRun['positions_seen'] ?? 0,
            'stops_active_total'   => $stopsActiveTotal,
            'config_valid'         => true,
            'effective_config'     => [
                'mode'    => $config['mode']    ?? 'disabled',
                'enabled' => $config['enabled'] ?? false,
            ],
        ];

        $path  = $this->moduleDir . '/config/runtime_snapshot.php';
        $lines = [
            "<?php\n\ndeclare(strict_types=1);\n\n",
            "/**\n * Stop Manager Module — Runtime Snapshot\n",
            " * Auto-written after each tick. Do not edit manually.\n",
            " * snapshot_at: " . $snap['snapshot_at'] . "\n */\n\n",
            "return " . var_export($snap, true) . ";\n",
        ];
        @file_put_contents($path, implode('', $lines));
    }

    private function requireBootstrap(): void
    {
        require_once $this->moduleDir . '/bootstrap.php';
    }

    private function readJson(string $relPath, mixed $default = null): mixed
    {
        $path = $this->moduleDir . '/' . $relPath;
        if (!file_exists($path)) {
            return $default;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
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
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
