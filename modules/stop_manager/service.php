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
 * All computation is local only (same math for demo and live modes).
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
 *   demo — stop computation for Bybit Demo positions + call setTradingStop on Bybit Demo
 *          (requires demo_execute_stops=true and bot demo credentials configured)
 *   live — stop computation for live positions + call setTradingStop on live account
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
                    'module_mode'    => $config['mode'] ?? 'demo',
                ]
            ));
            return;
        }

        $tickAt = date('c');
        $tStart = microtime(true);
        $mode   = (string)($config['mode'] ?? 'demo');

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
        // Early fail guard cumulative counters
        $stats['ef_checked_total']            += $result['ef_checked_total']         ?? 0;
        $stats['ef_triggered_total']          += $result['ef_triggered_total']       ?? 0;
        $stats['ef_closed_total']             += $result['ef_closed_total']          ?? 0;
        $stats['ef_registry_written_total']   += $result['ef_registry_written_total'] ?? 0;
        $stats['ef_skipped_missing_trace_total']   += $result['ef_skipped_missing_trace']  ?? 0;
        $stats['ef_skipped_no_setup_break_total']  += $result['ef_skipped_no_setup_break'] ?? 0;
        $stats['ef_skipped_too_young_total']       += $result['ef_skipped_too_young']      ?? 0;
        $stats['ef_skipped_not_db_total']          += $result['ef_skipped_not_db']         ?? 0;
        // Position price/ROI normalization cumulative counters
        $stats['db_price_normalized_total']  += $result['db_price_normalized_total'] ?? 0;
        $stats['db_roi_normalized_total']    += $result['db_roi_normalized_total']   ?? 0;
        $stats['db_roi_missing_total']       += $result['db_roi_missing_total']      ?? 0;
        $stats['db_price_missing_total']     += $result['db_price_missing_total']    ?? 0;
        // Short emergency stop cumulative counters
        $stats['short_stop_checked_total']                      += $result['short_stop_checked_total']                      ?? 0;
        $stats['short_stop_triggered_total']                    += $result['short_stop_triggered_total']                    ?? 0;
        $stats['short_stop_closed_total']                       += $result['short_stop_closed_total']                       ?? 0;
        $stats['short_stop_skipped_not_demo_total']             += $result['short_stop_skipped_not_demo_total']             ?? 0;
        $stats['short_stop_skipped_strategy_not_allowed_total'] += $result['short_stop_skipped_strategy_not_allowed_total'] ?? 0;
        $stats['short_stop_skipped_roi_above_cap_total']        += $result['short_stop_skipped_roi_above_cap_total']        ?? 0;
        $stats['short_stop_skipped_missing_roi_total']          += $result['short_stop_skipped_missing_roi_total']          ?? 0;
        $stats['short_stop_skipped_too_young_total']            += $result['short_stop_skipped_too_young_total']            ?? 0;
        // Long emergency stop cumulative counters
        $stats['long_stop_checked_total']                       += $result['long_stop_checked_total']                       ?? 0;
        $stats['long_stop_triggered_total']                     += $result['long_stop_triggered_total']                     ?? 0;
        $stats['long_stop_closed_total']                        += $result['long_stop_closed_total']                        ?? 0;
        $stats['long_stop_skipped_not_demo_total']              += $result['long_stop_skipped_not_demo_total']              ?? 0;
        $stats['long_stop_skipped_strategy_not_allowed_total']  += $result['long_stop_skipped_strategy_not_allowed_total']  ?? 0;
        $stats['long_stop_skipped_roi_above_cap_total']         += $result['long_stop_skipped_roi_above_cap_total']         ?? 0;
        $stats['long_stop_skipped_missing_roi_total']           += $result['long_stop_skipped_missing_roi_total']           ?? 0;
        $stats['long_stop_skipped_too_young_total']             += $result['long_stop_skipped_too_young_total']             ?? 0;
        // Legacy liq_distance path cumulative counters
        $stats['legacy_stop_skipped_total']                     += $result['legacy_stop_skipped_total']                     ?? 0;
        // Protective stop cumulative counters
        $stats['protective_stops_checked_total']  += $result['protective_stops_checked_total']  ?? 0;
        $stats['protective_stops_set_total']      += $result['protective_stops_set_total']      ?? 0;
        $stats['protective_stops_updated_total']  += $result['protective_stops_updated_total']  ?? 0;
        $stats['protective_stops_already_ok_total'] += $result['protective_stops_already_ok_total'] ?? 0;
        $stats['protective_stops_failed_total']   += $result['protective_stops_failed_total']   ?? 0;
        $stats['protective_stops_skipped_total']  += $result['protective_stops_skipped_total']  ?? 0;
        $stats['long_protective_stop_set_total']  += $result['long_protective_stop_set_total']  ?? 0;
        $stats['long_protective_stop_failed_total']  += $result['long_protective_stop_failed_total']  ?? 0;
        $stats['short_protective_stop_set_total'] += $result['short_protective_stop_set_total'] ?? 0;
        $stats['short_protective_stop_failed_total'] += $result['short_protective_stop_failed_total'] ?? 0;
        // StopLoss set/verify cumulative counters
        $stats['stoploss_set_attempted_total']      += $result['stoploss_set_attempted_total']      ?? 0;
        $stats['stoploss_set_success_total']        += $result['stoploss_set_success_total']        ?? 0;
        $stats['stoploss_set_verified_total']       += $result['stoploss_set_verified_total']       ?? 0;
        $stats['stoploss_set_unverified_total']     += $result['stoploss_set_unverified_total']     ?? 0;
        $stats['stoploss_set_failed_total']         += $result['stoploss_set_failed_total']         ?? 0;
        $stats['live_stoploss_set_attempted_total'] += $result['live_stoploss_set_attempted_total'] ?? 0;
        $stats['live_stoploss_set_verified_total']  += $result['live_stoploss_set_verified_total']  ?? 0;
        $stats['demo_stoploss_set_verified_total']  += $result['demo_stoploss_set_verified_total']  ?? 0;
        // Close deduplication cumulative counters
        $stats['stop_close_deduped_total']        += $result['stop_close_deduped_total']        ?? 0;

        // ── 4. Persist ─────────────────────────────────────────────────────────
        $this->writeJson('storage/stops.json', array_values($stops));
        $this->writeJson('storage/stats.json', $stats);

        $elapsed = round(microtime(true) - $tStart, 4);

        // Config snapshot for diagnostics
        $longProfile  = $config['profiles']['long']  ?? [];
        $shortProfile = $config['profiles']['short'] ?? [];
        $legacyCfgEnabled = (bool)($config['legacy_liq_distance_stop_enabled'] ?? false);

        $lastRun = [
            'status'                       => 'ok',
            'tick_at'                      => $tickAt,
            'elapsed_sec'                  => $elapsed,
            'module_enabled'               => true,
            'module_mode'                  => $mode,
            // Legacy liq_distance_percent path status
            'legacy_liq_distance_stop_enabled' => $legacyCfgEnabled,
            'legacy_stop_counters_deprecated'  => !$legacyCfgEnabled,
            'legacy_stop_skipped_total'        => $result['legacy_stop_skipped_total'] ?? 0,
            // Counters superseded by side-specific ROI protective stops
            'deprecated_stop_counter_names'    => ['demo_stops_set', 'stops_active_count', 'stops_initialized', 'stops_recalculated'],
            // Kept for backward compat (deprecated when legacy path disabled)
            'liq_distance_percent'         => max(1.0, min(99.0, (float)($config['liq_distance_percent'] ?? 90.0))),
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
            // Early fail guard diagnostics (this tick)
            'double_bottom_early_fail_checked_total'             => $result['ef_checked_total']            ?? 0,
            'double_bottom_early_fail_triggered_total'           => $result['ef_triggered_total']          ?? 0,
            'double_bottom_early_fail_closed_total'              => $result['ef_closed_total']             ?? 0,
            'registry_written_total'                             => $result['ef_registry_written_total']   ?? 0,
            'double_bottom_early_fail_skipped_missing_trace_total'  => $result['ef_skipped_missing_trace'] ?? 0,
            'double_bottom_early_fail_skipped_no_setup_break_total' => $result['ef_skipped_no_setup_break'] ?? 0,
            'double_bottom_early_fail_skipped_too_young_total'   => $result['ef_skipped_too_young']        ?? 0,
            'double_bottom_early_fail_skipped_not_double_bottom_total' => $result['ef_skipped_not_db']     ?? 0,
            'double_bottom_early_fail_triggered_examples'        => $result['ef_triggered_examples']       ?? [],
            'double_bottom_early_fail_skipped_examples'          => $result['ef_skipped_examples']         ?? [],
            // Cumulative early fail totals from stats
            'double_bottom_early_fail_checked_cumulative'        => (int)($stats['ef_checked_total']            ?? 0),
            'double_bottom_early_fail_triggered_cumulative'      => (int)($stats['ef_triggered_total']          ?? 0),
            'double_bottom_early_fail_closed_cumulative'         => (int)($stats['ef_closed_total']             ?? 0),
            'registry_written_cumulative'                        => (int)($stats['ef_registry_written_total']   ?? 0),
            // Position price/ROI normalization diagnostics (this tick)
            'double_bottom_position_price_normalized_total'      => $result['db_price_normalized_total'] ?? 0,
            'double_bottom_position_roi_normalized_total'        => $result['db_roi_normalized_total']   ?? 0,
            'double_bottom_position_roi_missing_total'           => $result['db_roi_missing_total']      ?? 0,
            'double_bottom_position_price_missing_total'         => $result['db_price_missing_total']    ?? 0,
            'double_bottom_position_normalization_examples'      => $result['db_normalization_examples'] ?? [],
            'double_bottom_position_missing_price_examples'      => $result['db_missing_price_examples'] ?? [],
            'double_bottom_position_missing_roi_examples'        => $result['db_missing_roi_examples']   ?? [],
            // Position price/ROI normalization cumulative
            'double_bottom_position_price_normalized_cumulative' => (int)($stats['db_price_normalized_total'] ?? 0),
            'double_bottom_position_roi_normalized_cumulative'   => (int)($stats['db_roi_normalized_total']   ?? 0),
            'double_bottom_position_roi_missing_cumulative'      => (int)($stats['db_roi_missing_total']      ?? 0),
            'double_bottom_position_price_missing_cumulative'    => (int)($stats['db_price_missing_total']    ?? 0),
            // ── Config snapshot for diagnostics ──────────────────────────────
            'long_stop_enabled'           => (bool)($longProfile['enabled']                ?? true),
            'long_emergency_stop_roi'     => (float)($longProfile['emergency_stop_roi']    ?? -30.0),
            'short_stop_enabled'          => (bool)($shortProfile['enabled']               ?? true),
            'short_emergency_stop_roi'    => (float)($shortProfile['emergency_stop_roi']   ?? -20.0),
            'short_stop_applies_to_strategies' => (array)($shortProfile['applies_to_strategies'] ?? ['dynamic_strategies']),
            // ── Long emergency stop diagnostics (this tick) ──────────────────
            'long_stop_positions_checked_total'             => $result['long_stop_checked_total']                       ?? 0,
            'long_stop_triggered_total'                     => $result['long_stop_triggered_total']                     ?? 0,
            'long_stop_closed_total'                        => $result['long_stop_closed_total']                        ?? 0,
            'long_stop_skipped_not_demo_total'              => $result['long_stop_skipped_not_demo_total']              ?? 0,
            'long_stop_skipped_strategy_not_allowed_total'  => $result['long_stop_skipped_strategy_not_allowed_total']  ?? 0,
            'long_stop_skipped_roi_above_cap_total'         => $result['long_stop_skipped_roi_above_cap_total']         ?? 0,
            'long_stop_skipped_missing_roi_total'           => $result['long_stop_skipped_missing_roi_total']           ?? 0,
            'long_stop_skipped_too_young_total'             => $result['long_stop_skipped_too_young_total']             ?? 0,
            'long_stop_triggered_examples'                  => $result['long_stop_triggered_examples']                  ?? [],
            'long_stop_skipped_examples'                    => $result['long_stop_skipped_examples']                    ?? [],
            // ── Long emergency stop cumulative ───────────────────────────────
            'long_stop_checked_cumulative'                       => (int)($stats['long_stop_checked_total']                       ?? 0),
            'long_stop_triggered_cumulative'                     => (int)($stats['long_stop_triggered_total']                     ?? 0),
            'long_stop_closed_cumulative'                        => (int)($stats['long_stop_closed_total']                        ?? 0),
            // ── Short emergency stop diagnostics (this tick) ─────────────────
            'short_stop_positions_checked_total'            => $result['short_stop_checked_total']                      ?? 0,
            'short_stop_triggered_total'                    => $result['short_stop_triggered_total']                    ?? 0,
            'short_stop_closed_total'                       => $result['short_stop_closed_total']                       ?? 0,
            'short_stop_skipped_not_demo_total'             => $result['short_stop_skipped_not_demo_total']             ?? 0,
            'short_stop_skipped_strategy_not_allowed_total' => $result['short_stop_skipped_strategy_not_allowed_total'] ?? 0,
            'short_stop_skipped_roi_above_cap_total'        => $result['short_stop_skipped_roi_above_cap_total']        ?? 0,
            'short_stop_skipped_missing_roi_total'          => $result['short_stop_skipped_missing_roi_total']          ?? 0,
            'short_stop_skipped_too_young_total'            => $result['short_stop_skipped_too_young_total']            ?? 0,
            'short_stop_triggered_examples'                 => $result['short_stop_triggered_examples']                 ?? [],
            'short_stop_skipped_examples'                   => $result['short_stop_skipped_examples']                   ?? [],
            // ── Short emergency stop cumulative ───────────────────────────────
            'short_stop_checked_cumulative'                      => (int)($stats['short_stop_checked_total']                      ?? 0),
            'short_stop_triggered_cumulative'                    => (int)($stats['short_stop_triggered_total']                    ?? 0),
            'short_stop_closed_cumulative'                       => (int)($stats['short_stop_closed_total']                       ?? 0),
            // ── Protective stop diagnostics (this tick) ──────────────────────
            'side_roi_protective_stop_enabled'               => $result['side_roi_protective_stop_enabled'] ?? false,
            'protective_stops_checked_total'                 => $result['protective_stops_checked_total']  ?? 0,
            'protective_stops_set_total'                     => $result['protective_stops_set_total']      ?? 0,
            'protective_stops_updated_total'                 => $result['protective_stops_updated_total']  ?? 0,
            'protective_stops_already_ok_total'              => $result['protective_stops_already_ok_total'] ?? 0,
            'protective_stops_failed_total'                  => $result['protective_stops_failed_total']   ?? 0,
            'protective_stops_skipped_total'                 => $result['protective_stops_skipped_total']  ?? 0,
            'long_protective_stop_set_total'                 => $result['long_protective_stop_set_total']  ?? 0,
            'long_protective_stop_failed_total'              => $result['long_protective_stop_failed_total'] ?? 0,
            'short_protective_stop_set_total'                => $result['short_protective_stop_set_total'] ?? 0,
            'short_protective_stop_failed_total'             => $result['short_protective_stop_failed_total'] ?? 0,
            'protective_stop_examples'                       => $result['protective_stop_examples']        ?? [],
            'protective_stop_failed_examples'                => $result['protective_stop_failed_examples'] ?? [],
            'protective_stops_active_count'                  => $result['protective_stops_active_count']   ?? 0,
            // ── Protective stop cumulative ───────────────────────────────────
            'protective_stops_set_cumulative'                => (int)($stats['protective_stops_set_total']     ?? 0),
            'protective_stops_failed_cumulative'             => (int)($stats['protective_stops_failed_total']  ?? 0),
            // ── StopLoss set/verify diagnostics (this tick) ──────────────────
            'stoploss_set_attempted_total'                   => $result['stoploss_set_attempted_total']      ?? 0,
            'stoploss_set_success_total'                     => $result['stoploss_set_success_total']        ?? 0,
            'stoploss_set_verified_total'                    => $result['stoploss_set_verified_total']       ?? 0,
            'stoploss_set_unverified_total'                  => $result['stoploss_set_unverified_total']     ?? 0,
            'stoploss_set_failed_total'                      => $result['stoploss_set_failed_total']         ?? 0,
            'live_stoploss_set_attempted_total'              => $result['live_stoploss_set_attempted_total'] ?? 0,
            'live_stoploss_set_verified_total'               => $result['live_stoploss_set_verified_total']  ?? 0,
            'demo_stoploss_set_verified_total'               => $result['demo_stoploss_set_verified_total']  ?? 0,
            'stoploss_set_examples'                          => $result['stoploss_set_examples']             ?? [],
            'stoploss_unverified_examples'                   => $result['stoploss_unverified_examples']      ?? [],
            'stoploss_failed_examples'                       => $result['stoploss_failed_examples']          ?? [],
            // ── StopLoss set/verify cumulative ───────────────────────────────
            'stoploss_set_attempted_cumulative'              => (int)($stats['stoploss_set_attempted_total']      ?? 0),
            'stoploss_set_verified_cumulative'               => (int)($stats['stoploss_set_verified_total']       ?? 0),
            'stoploss_set_unverified_cumulative'             => (int)($stats['stoploss_set_unverified_total']     ?? 0),
            'stoploss_set_failed_cumulative'                 => (int)($stats['stoploss_set_failed_total']         ?? 0),
            'live_stoploss_set_attempted_cumulative'         => (int)($stats['live_stoploss_set_attempted_total'] ?? 0),
            'live_stoploss_set_verified_cumulative'          => (int)($stats['live_stoploss_set_verified_total']  ?? 0),
            'demo_stoploss_set_verified_cumulative'          => (int)($stats['demo_stoploss_set_verified_total']  ?? 0),
            // ── SM config snapshot for diagnostics ───────────────────────────
            'live_protective_stops_enabled'                  => (bool)($config['live_protective_stops_enabled']    ?? false),
            'protective_stop_position_mode'                  => (string)($config['protective_stop_position_mode']  ?? 'one-way'),
            'protective_stop_tpsl_mode'                      => (string)($config['protective_stop_tpsl_mode']      ?? 'Full'),
            'protective_stop_trigger_by'                     => (string)($config['protective_stop_trigger_by']     ?? 'MarkPrice'),
            'protective_stop_verify_after_set'               => (bool)($config['protective_stop_verify_after_set'] ?? true),
            // ── Close deduplication diagnostics (this tick) ──────────────────
            'stop_close_deduped_total'                       => $result['stop_close_deduped_total']       ?? 0,
            'stop_close_deduped_examples'                    => $result['stop_close_deduped_examples']    ?? [],
            // ── Close deduplication cumulative ───────────────────────────────
            'stop_close_deduped_cumulative'                  => (int)($stats['stop_close_deduped_total']  ?? 0),
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
     * Also runs the double_bottom_long early-fail guard when enabled and mode=demo.
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
     *   ef_checked_total: int,
     *   ef_triggered_total: int,
     *   ef_closed_total: int,
     *   ef_skipped_missing_trace: int,
     *   ef_skipped_no_setup_break: int,
     *   ef_skipped_too_young: int,
     *   ef_skipped_not_db: int,
     *   ef_triggered_examples: array,
     *   ef_skipped_examples: array,
     *   short_stop_checked_total: int,
     *   short_stop_triggered_total: int,
     *   short_stop_closed_total: int,
     *   short_stop_skipped_not_demo_total: int,
     *   short_stop_skipped_strategy_not_allowed_total: int,
     *   short_stop_skipped_roi_above_cap_total: int,
     *   short_stop_skipped_missing_roi_total: int,
     *   short_stop_skipped_too_young_total: int,
     *   short_stop_triggered_examples: array,
     *   short_stop_skipped_examples: array,
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

        // Early fail guard counters
        $efCheckedTotal           = 0;
        $efTriggeredTotal         = 0;
        $efClosedTotal            = 0;
        $efSkippedMissingTrace    = 0;
        $efSkippedNoSetupBreak    = 0;
        $efSkippedTooYoung        = 0;
        $efSkippedNotDB           = 0;
        $efTriggeredExamples      = [];
        $efSkippedExamples        = [];

        // Position price/ROI normalization counters (double_bottom_long positions only)
        $dbPriceNormalizedTotal  = 0;
        $dbRoiNormalizedTotal    = 0;
        $dbRoiMissingTotal       = 0;
        $dbPriceMissingTotal     = 0;
        $dbNormalizationExamples = [];
        $dbMissingPriceExamples  = [];
        $dbMissingRoiExamples    = [];

        // Short emergency stop counters
        $shortStopCheckedTotal                    = 0;
        $shortStopTriggeredTotal                  = 0;
        $shortStopClosedTotal                     = 0;
        $shortStopSkippedNotDemoTotal             = 0;
        $shortStopSkippedStratNotAllowedTotal     = 0;
        $shortStopSkippedRoiAboveCapTotal         = 0;
        $shortStopSkippedMissingRoiTotal          = 0;
        $shortStopSkippedTooYoungTotal            = 0;
        $shortStopTriggeredExamples               = [];
        $shortStopSkippedExamples                 = [];

        // Long emergency stop counters
        $longStopCheckedTotal                     = 0;
        $longStopTriggeredTotal                   = 0;
        $longStopClosedTotal                      = 0;
        $longStopSkippedNotDemoTotal              = 0;
        $longStopSkippedStratNotAllowedTotal      = 0;
        $longStopSkippedRoiAboveCapTotal          = 0;
        $longStopSkippedMissingRoiTotal           = 0;
        $longStopSkippedTooYoungTotal             = 0;
        $longStopTriggeredExamples                = [];
        $longStopSkippedExamples                  = [];

        // Protective stop (ROI-based, set proactively on exchange) counters
        $protStopsCheckedTotal     = 0;
        $protStopsSetTotal         = 0;
        $protStopsUpdatedTotal     = 0;
        $protStopsAlreadyOkTotal   = 0;
        $protStopsFailedTotal      = 0;
        $protStopsSkippedTotal     = 0;
        $longProtStopsSetTotal     = 0;
        $longProtStopsFailedTotal  = 0;
        $shortProtStopsSetTotal    = 0;
        $shortProtStopsFailedTotal = 0;
        $protStopExamples          = [];
        $protStopFailedExamples    = [];

        // Close deduplication: prevent duplicate close attempts per position per tick
        $closedThisTick           = [];   // key => true
        $stopCloseDedupedTotal    = 0;
        $stopCloseDedupedExamples = [];

        // Legacy liq_distance_percent path control
        $legacyEnabled          = (bool)($config['legacy_liq_distance_stop_enabled'] ?? false);
        $legacyStopSkippedTotal = 0;

        $isActiveMode = in_array($mode, ['demo', 'live'], true);

        // Prepare gateway: when in demo/live mode, check bot mode to decide demo vs live
        $demoExecuteStops = $isActiveMode && (bool)($config['demo_execute_stops'] ?? true);

        // Bot mode determines which exchange API to use (no mixed mode)
        $botConfig = $demoExecuteStops ? $this->loadBotConfig($config) : [];
        $botMode   = (string)($botConfig['mode'] ?? 'demo');
        $useLiveGw = ($demoExecuteStops && $botMode === 'live');
        $useDemoGw = ($demoExecuteStops && $botMode !== 'live');

        $demoGw   = $useDemoGw ? $this->getDemoGateway($config) : null;
        $liveGw   = $useLiveGw ? $this->getLiveGateway($config) : null;
        $activeGw = $liveGw ?? $demoGw;

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

        if ($isActiveMode) {
            // ── Process active positions ─────────────────────────────────────
            foreach ($posMap as $key => $pos) {
                $positionsSeen++;

                if (!$legacyEnabled) {
                    // Legacy liq_distance_percent path is disabled — skip stop
                    // computation and exchange calls for this position.
                    $legacyStopSkippedTotal++;
                    continue;
                }

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
                            'execution_mode' => $this->normalizeExecMode((string)($pos['execution_mode'] ?? 'demo')),
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
                    $stopMap[$key] = $this->buildStop($pos, $stopPrice, $liqPrice, $liqSource, $tickAt, $initEventType, $liqDistPct);
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
                        'execution_mode' => $this->normalizeExecMode((string)($pos['execution_mode'] ?? 'demo')),
                        'reason'         => $initEventType,
                    ]);

                    // ── Exchange: set stop on exchange (demo or live) ─────────────
                    if ($demoExecuteStops && $stopPrice > 0.0) {
                        if ($activeGw === null) {
                            $demoStopsSkippedNoGw++;
                            $stopMap[$key]['demo_stop_set']  = false;
                            $stopMap[$key]['demo_stop_note'] = 'no_gateway';
                        } else {
                            $setResult = $this->setDemoTradingStop($activeGw, (string)($pos['symbol'] ?? ''), $stopPrice);
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
                    // OR if the stored liq_distance_percent differs from current config
                    $storedLiqDist = (float)($prevStop['liq_distance_percent'] ?? -1.0);
                    if (
                        abs($stopPrice - $prevStopPrice) > ($entryPrice * 0.00001)
                        || $prevState === 'no_liq'
                        || ($storedLiqDist >= 0.0 && abs($storedLiqDist - $liqDistPct) > 0.01)
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
                                'execution_mode' => $this->normalizeExecMode((string)($pos['execution_mode'] ?? 'demo')),
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
                            'execution_mode' => $this->normalizeExecMode((string)($pos['execution_mode'] ?? 'demo')),
                            'reason'         => $recalcEventType,
                        ]);
                    }

                    $newStopState = $liqSource === 'real' ? 'active' : 'estimated_liq';
                    $stopMap[$key]['stop_price']            = $stopPrice;
                    $stopMap[$key]['liq_price']             = $liqPrice;
                    $stopMap[$key]['liq_source']            = $liqSource;
                    $stopMap[$key]['stop_state']            = $newStopState;
                    $stopMap[$key]['last_updated_at']       = $tickAt;
                    $stopMap[$key]['stop_mode']             = 'liq_distance_percent';
                    $stopMap[$key]['liq_distance_percent']  = $liqDistPct;
                    // Always take execution_mode from the live position record, not the cached stop
                    $stopMap[$key]['execution_mode'] = $this->normalizeExecMode(
                        (string)($pos['execution_mode'] ?? 'demo')
                    );
                    $stopMap[$key]['mode']    = (string)($pos['mode']    ?? $mode);
                    $stopMap[$key]['account'] = (string)($pos['account'] ?? '');
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

                    // ── Exchange: update stop on exchange (demo or live) when stop changed ─
                    if ($demoExecuteStops && $recalcReason !== null && $stopPrice > 0.0) {
                        if ($activeGw === null) {
                            $demoStopsSkippedNoGw++;
                            $stopMap[$key]['demo_stop_set']  = false;
                            $stopMap[$key]['demo_stop_note'] = 'no_gateway';
                        } else {
                            $setResult = $this->setDemoTradingStop($activeGw, (string)($pos['symbol'] ?? ''), $stopPrice);
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
                    'execution_mode' => $this->normalizeExecMode((string)($stop['execution_mode'] ?? 'demo')),
                    'reason'         => 'position_no_longer_active',
                ]);
            }
        }

        // ── Side-specific ROI protective stops (demo only) ───────────────────
        // For each active demo position where the side profile is enabled,
        // compute the protective stop price from emergency_stop_roi and set it on
        // the exchange proactively.  This ensures a real stop order exists before
        // ROI reaches the emergency threshold, not just a market-close-on-tick.
        //
        // Long:  stop_price = entry_price × (1 + emergency_stop_roi / leverage / 100)
        // Short: stop_price = entry_price × (1 − emergency_stop_roi / leverage / 100)
        // (emergency_stop_roi is negative, so long stop is below entry)
        $longProtCfg   = $config['profiles']['long']  ?? [];
        $shortProtCfg  = $config['profiles']['short'] ?? [];
        $longProtOn    = (bool)($longProtCfg['enabled'] ?? true)
                         && (bool)($longProtCfg['emergency_stop_enabled'] ?? true);
        $shortProtOn   = (bool)($shortProtCfg['enabled'] ?? true)
                         && (bool)($shortProtCfg['emergency_stop_enabled'] ?? true);
        $sideRoiProtEnabled = $longProtOn || $shortProtOn;

        // Protective stop exchange API parameters from config
        $liveProtStopsEnabled = (bool)($config['live_protective_stops_enabled']    ?? false);
        $protPositionMode     = (string)($config['protective_stop_position_mode']  ?? 'one-way');
        $protTpslMode         = (string)($config['protective_stop_tpsl_mode']      ?? 'Full');
        $protTriggerBy        = (string)($config['protective_stop_trigger_by']     ?? 'MarkPrice');
        $protVerifyAfterSet   = (bool)($config['protective_stop_verify_after_set'] ?? true);

        // New stoploss set/verify counters (per-tick)
        $slSetAttemptedTotal       = 0;
        $slSetSuccessTotal         = 0;
        $slSetVerifiedTotal        = 0;
        $slSetUnverifiedTotal      = 0;
        $slSetFailedTotal          = 0;
        $liveSlSetAttemptedTotal   = 0;
        $liveSlSetVerifiedTotal    = 0;
        $demoSlSetVerifiedTotal    = 0;
        $slSetExamples             = [];
        $slUnverifiedExamples      = [];
        $slFailedExamples          = [];

        $protStopState        = $this->readJson('storage/protective_stops_state.json', []);
        $protStopStateChanged = false;

        // Run for demo always; run for live only when live_protective_stops_enabled=true
        $runProtForDemo = $sideRoiProtEnabled && $mode === 'demo' && $isActiveMode && $activeGw !== null;
        $runProtForLive = $sideRoiProtEnabled && $mode === 'live' && $liveProtStopsEnabled && $isActiveMode && $activeGw !== null;

        if ($runProtForDemo || $runProtForLive) {
            foreach ($posMap as $_protKey => $_protPos) {
                $pSide    = (string)($_protPos['side'] ?? '');
                $pMode    = $this->normalizeExecMode((string)($_protPos['execution_mode'] ?? 'demo'));
                $pSymbol  = (string)($_protPos['symbol'] ?? '');
                $pStratId = (string)($_protPos['strategy_id'] ?? $_protPos['owner_strategy'] ?? '');
                $pSigId   = (string)($_protPos['signal_id'] ?? '');

                // For demo SM mode: only handle demo positions
                // For live SM mode: only handle live positions (safety gate)
                if ($runProtForDemo && $pMode !== 'demo') {
                    $protStopsSkippedTotal++;
                    continue;
                }
                if ($runProtForLive && $pMode !== 'live') {
                    $protStopsSkippedTotal++;
                    continue;
                }

                if ($pSide === 'long' && $longProtOn) {
                    $pSideProfile = $longProtCfg;
                    $pStopGuard   = 'long_emergency_stop';
                    $pStopReason  = 'long_emergency_roi_cap';
                } elseif ($pSide === 'short' && $shortProtOn) {
                    $pSideProfile = $shortProtCfg;
                    $pStopGuard   = 'short_emergency_stop';
                    $pStopReason  = 'short_emergency_roi_cap';
                } else {
                    $protStopsSkippedTotal++;
                    continue;
                }

                // Strategy filter
                $pAppliesTo = (array)($pSideProfile['applies_to_strategies']
                    ?? ($pSide === 'long' ? ['double_bottom_long', '*'] : ['dynamic_strategies']));
                if (!in_array('*', $pAppliesTo, true) && !in_array($pStratId, $pAppliesTo, true)) {
                    $protStopsSkippedTotal++;
                    continue;
                }

                $protStopsCheckedTotal++;

                // Normalize entry price and leverage
                $pPriceNorm  = $this->normalizePositionPrices($_protPos);
                $pEntry      = $pPriceNorm['normalized_entry_price'];
                $pLeverage   = (float)$pPriceNorm['normalized_leverage'];
                $pCurrentPx  = $pPriceNorm['normalized_current_price'];

                if ($pEntry === null || $pEntry <= 0.0 || $pLeverage <= 0.0 || $pCurrentPx === null || $pCurrentPx <= 0.0) {
                    $protStopsSkippedTotal++;
                    continue;
                }

                $pEmgRoi   = (float)($pSideProfile['emergency_stop_roi'] ?? ($pSide === 'long' ? -30.0 : -20.0));
                $pStopPrice = $this->computeProtectiveStopPrice($pSide, $pEntry, $pLeverage, $pEmgRoi);

                if ($pStopPrice === null || $pStopPrice <= 0.0) {
                    $protStopsSkippedTotal++;
                    continue;
                }

                // Check existing protective stop state
                $pStateKey      = "{$pMode}_{$pSymbol}_{$pSide}";
                $pExistingStop  = $protStopState[$pStateKey] ?? null;
                // Use requested_stop_price for change detection (backward-compat fallback: stop_price_set)
                $pExistingPrice = isset($pExistingStop['requested_stop_price'])
                    ? (float)$pExistingStop['requested_stop_price']
                    : (isset($pExistingStop['stop_price_set']) ? (float)$pExistingStop['stop_price_set'] : 0.0);

                // Skip if already set at same price (tiny epsilon = 0.001% of stop price)
                $pPriceChanged = ($pExistingPrice <= 0.0)
                    || (abs($pStopPrice - $pExistingPrice) > 0.00001 * max($pStopPrice, $pExistingPrice));

                if (!$pPriceChanged) {
                    $protStopsAlreadyOkTotal++;
                    continue;
                }

                // Track attempt
                $slSetAttemptedTotal++;
                if ($pMode === 'live') { $liveSlSetAttemptedTotal++; }

                $pSetResult = $this->setTradingStop(
                    $activeGw,
                    $pSymbol,
                    $pSide,
                    $pStopPrice,
                    $protPositionMode,
                    $protTpslMode,
                    $protTriggerBy
                );
                $pAction = $pExistingPrice <= 0.0 ? 'set' : 'update';

                // Verification (if enabled and set was reported ok)
                $pVerifyResult = null;
                $pConfirmed    = false;
                if ($protVerifyAfterSet && ($pSetResult['ok'] || $pSetResult['note'] === 'already_set')) {
                    $pVerifyResult = $this->verifyTradingStop($activeGw, $pSymbol, $pSide, $pStopPrice, $protPositionMode);
                    $pConfirmed    = (bool)($pVerifyResult['stop_loss_confirmed'] ?? false);
                }

                $pExample = [
                    'symbol'              => $pSymbol,
                    'side'                => $pSide,
                    'mode'                => $pMode,
                    'account_id'          => isset($_protPos['account_id']) ? (string)$_protPos['account_id'] : null,
                    'strategy_id'         => $pStratId,
                    'signal_id'           => $pSigId !== '' ? $pSigId : null,
                    'entry_price'         => $pEntry,
                    'current_price'       => $pCurrentPx,
                    'leverage'            => (int)$pLeverage,
                    'emergency_stop_roi'  => $pEmgRoi,
                    'requested_stop_price'=> $pStopPrice,
                    'confirmed_stop_price'=> $pVerifyResult['confirmed_stop_loss'] ?? null,
                    'stop_guard'          => $pStopGuard,
                    'action'              => $pAction,
                    'positionIdx'         => $pSetResult['position_idx'] ?? 0,
                    'category'            => 'linear',
                    'tpslMode'            => $protTpslMode,
                    'slTriggerBy'         => $protTriggerBy,
                    'ret_code'            => $pSetResult['ret_code'],
                    'ret_msg'             => $pSetResult['ret_msg'],
                    'verification_status' => $pVerifyResult['verification_status'] ?? ($protVerifyAfterSet ? 'not_attempted' : 'skipped'),
                ];

                if ($pSetResult['ok'] || $pSetResult['note'] === 'already_set') {
                    $slSetSuccessTotal++;
                    if ($pAction === 'set') {
                        $protStopsSetTotal++;
                        if ($pSide === 'long')  { $longProtStopsSetTotal++;  }
                        if ($pSide === 'short') { $shortProtStopsSetTotal++; }
                    } else {
                        $protStopsUpdatedTotal++;
                    }

                    $pNowTs = time();

                    // Build state record with requested + confirmed separation
                    $pStateRecord = [
                        // Legacy compat field (still set for backward compat with UI reading stop_price_set)
                        'stop_price_set'          => $pStopPrice,
                        'set_at'                  => $tickAt,
                        'set_at_ts'               => $pNowTs,
                        'stop_guard'              => $pStopGuard,
                        'strategy_id'             => $pStratId,
                        'emergency_roi'           => $pEmgRoi,
                        // Requested fields
                        'requested_stop_price'    => $pStopPrice,
                        'requested_emergency_roi' => $pEmgRoi,
                        'requested_at'            => $tickAt,
                        // Confirmed fields
                        'confirmed_stop_price'    => $pVerifyResult['confirmed_stop_loss'] ?? null,
                        'confirmed_emergency_roi' => $pConfirmed ? $pEmgRoi : null,
                        'confirmed_at'            => $pConfirmed ? $tickAt : null,
                        'confirmed_by_exchange'   => $pConfirmed,
                        'verification_status'     => $pVerifyResult['verification_status'] ?? ($protVerifyAfterSet ? 'not_attempted' : 'skipped'),
                    ];

                    if ($pConfirmed) {
                        $slSetVerifiedTotal++;
                        if ($pMode === 'live') { $liveSlSetVerifiedTotal++; }
                        if ($pMode === 'demo') { $demoSlSetVerifiedTotal++; }
                        if (count($slSetExamples) < 5) { $slSetExamples[] = $pExample; }
                    } elseif ($protVerifyAfterSet && $pVerifyResult !== null) {
                        // Set was reported ok, but verification failed
                        $slSetUnverifiedTotal++;
                        if (count($slUnverifiedExamples) < 5) { $slUnverifiedExamples[] = $pExample; }
                    } else {
                        // Verification skipped (protVerifyAfterSet=false)
                        if (count($slSetExamples) < 5) { $slSetExamples[] = $pExample; }
                    }

                    $protStopState[$pStateKey] = $pStateRecord;
                    $protStopStateChanged = true;
                    if (count($protStopExamples) < 5) { $protStopExamples[] = $pExample; }
                } else {
                    $slSetFailedTotal++;
                    $protStopsFailedTotal++;
                    if ($pSide === 'long')  { $longProtStopsFailedTotal++;  }
                    if ($pSide === 'short') { $shortProtStopsFailedTotal++; }
                    if (count($protStopFailedExamples) < 5) { $protStopFailedExamples[] = $pExample; }
                    if (count($slFailedExamples) < 5) { $slFailedExamples[] = $pExample; }
                }

                $this->appendActionLog([
                    'timestamp'           => $tickAt,
                    'event_type'          => $pSetResult['ok'] ? 'protective_stop_set' : 'protective_stop_failed',
                    'stop_source'         => 'stop_manager',
                    'stop_guard'          => $pStopGuard,
                    'stop_reason'         => $pStopReason,
                    'stop_profile'        => 'side_specific_roi_emergency',
                    'strategy_id'         => $pStratId,
                    'signal_id'           => $pSigId,
                    'symbol'              => $pSymbol,
                    'side'                => $pSide,
                    'mode'                => $pMode,
                    'entry_price'         => $pEntry,
                    'current_price'       => $pCurrentPx,
                    'leverage'            => (int)$pLeverage,
                    'emergency_stop_roi'  => $pEmgRoi,
                    'requested_stop_price'=> $pStopPrice,
                    'confirmed_stop_price'=> $pVerifyResult['confirmed_stop_loss'] ?? null,
                    'action'              => $pAction,
                    'positionIdx'         => $pSetResult['position_idx'] ?? 0,
                    'tpslMode'            => $protTpslMode,
                    'slTriggerBy'         => $protTriggerBy,
                    'ret_code'            => $pSetResult['ret_code'],
                    'ret_msg'             => $pSetResult['ret_msg'],
                    'verification_status' => $pExample['verification_status'],
                ]);
            }
        }

        // Prune protective stop state entries for positions no longer active.
        // Runs regardless of gateway availability so the state stays clean.
        $runProtPrune = $sideRoiProtEnabled && $isActiveMode && ($mode === 'demo' || ($mode === 'live' && $liveProtStopsEnabled));
        if ($runProtPrune) {
            foreach (array_keys($protStopState) as $_pStateKey) {
                $_found = false;
                foreach ($posMap as $_chkPos) {
                    $_pMode   = $this->normalizeExecMode((string)($_chkPos['execution_mode'] ?? 'demo'));
                    $_pSym    = (string)($_chkPos['symbol'] ?? '');
                    $_pSide   = (string)($_chkPos['side'] ?? '');
                    if ($_pStateKey === "{$_pMode}_{$_pSym}_{$_pSide}") {
                        $_found = true;
                        break;
                    }
                }
                if (!$_found) {
                    unset($protStopState[$_pStateKey]);
                    $protStopStateChanged = true;
                }
            }
        }

        if ($protStopStateChanged) {
            $this->writeJson('storage/protective_stops_state.json', $protStopState);
        }

        // ── Early fail guard for double_bottom_long (demo only) ──────────────
        $efEnabled  = (bool)($config['double_bottom_early_fail_enabled']  ?? true);
        $efMode     = (string)($config['double_bottom_early_fail_mode']   ?? 'demo');
        $efStrategy = (string)($config['double_bottom_early_fail_strategy'] ?? 'double_bottom_long');
        $efRegistryWrittenTotal = 0;

        // Guard: only execute in demo mode, never live
        if ($efEnabled && $mode === 'demo' && $efMode === 'demo' && $isActiveMode) {
            foreach ($posMap as $key => $pos) {
                $posStratId = (string)($pos['strategy_id'] ?? $pos['owner_strategy'] ?? '');

                // Not a double_bottom_long position — count and skip
                if ($posStratId !== $efStrategy) {
                    $efSkippedNotDB++;
                    continue;
                }

                // Is a double_bottom_long position — count as checked
                $efCheckedTotal++;

                $efResult = $this->checkDoubleBottomEarlyFail($pos, $config, $tickAt);

                // ── Track price/ROI normalization for this double_bottom position ─────
                $cpSource  = $efResult['current_price_source'] ?? null;
                $roiSource = $efResult['roi_source']           ?? null;
                // Count as normalized when a usable normalized value was derived,
                // regardless of the source field name. Count as missing only when
                // no normalized value could be produced at all.
                $normalizedCp  = $efResult['normalized_current_price'] ?? null;
                $normalizedRoi = $efResult['normalized_roi']            ?? null;

                if ($normalizedCp !== null) {
                    $dbPriceNormalizedTotal++;
                    // Collect examples when the price was derived from a fallback source
                    if ($cpSource !== null && $cpSource !== 'current_price' && count($dbNormalizationExamples) < 5) {
                        $dbNormalizationExamples[] = [
                            'symbol'                   => $pos['symbol']    ?? null,
                            'signal_id'                => $pos['signal_id'] ?? null,
                            'current_price_source'     => $cpSource,
                            'entry_price_source'       => $efResult['entry_price_source']       ?? null,
                            'leverage_source'          => $efResult['leverage_source']           ?? null,
                            'roi_source'               => $roiSource,
                            'normalized_current_price' => $normalizedCp,
                            'normalized_entry_price'   => $efResult['normalized_entry_price']   ?? null,
                            'normalized_leverage'      => $efResult['normalized_leverage']       ?? null,
                            'normalized_roi'           => $normalizedRoi,
                        ];
                    }
                } else {
                    $dbPriceMissingTotal++;
                    if (count($dbMissingPriceExamples) < 5) {
                        $dbMissingPriceExamples[] = [
                            'symbol'               => $pos['symbol']    ?? null,
                            'signal_id'            => $pos['signal_id'] ?? null,
                            'current_price_source' => $cpSource,
                            'entry_price_source'   => $efResult['entry_price_source'] ?? null,
                            'leverage_source'      => $efResult['leverage_source']    ?? null,
                            'roi_source'           => $roiSource,
                            'skip_reason'          => $efResult['skip_reason'] ?? null,
                        ];
                    }
                }

                if ($normalizedRoi !== null) {
                    $dbRoiNormalizedTotal++;
                } else {
                    $dbRoiMissingTotal++;
                    if (count($dbMissingRoiExamples) < 5) {
                        $dbMissingRoiExamples[] = [
                            'symbol'               => $pos['symbol']    ?? null,
                            'signal_id'            => $pos['signal_id'] ?? null,
                            'current_price_source' => $cpSource,
                            'entry_price_source'   => $efResult['entry_price_source'] ?? null,
                            'leverage_source'      => $efResult['leverage_source']    ?? null,
                            'roi_source'           => $roiSource,
                            'skip_reason'          => $efResult['skip_reason'] ?? null,
                        ];
                    }
                }

                if (!$efResult['triggered']) {
                    $skipReason = $efResult['skip_reason'] ?? '';
                    if ($skipReason === 'early_fail_skipped_missing_trace') {
                        $efSkippedMissingTrace++;
                    } elseif ($skipReason === 'too_young') {
                        $efSkippedTooYoung++;
                    } elseif (in_array($skipReason, [
                        'no_setup_break_detected',
                        'adverse_roi_only_no_setup_break',
                    ], true)) {
                        $efSkippedNoSetupBreak++;
                    }
                    if ($skipReason !== '' && count($efSkippedExamples) < 5) {
                        // Use normalized values as fallbacks so examples are never
                        // null when usable data was derived from the position record.
                        $exRoi          = $efResult['roi']           ?? $efResult['normalized_roi']            ?? null;
                        $exCurPrice     = $efResult['current_price'] ?? $efResult['normalized_current_price']  ?? null;
                        $exEntryPrice   = (isset($pos['entry_price']) && (float)$pos['entry_price'] > 0)
                            ? (float)$pos['entry_price']
                            : ($efResult['normalized_entry_price'] ?? null);
                        $exLeverage     = $efResult['normalized_leverage'] ?? ($pos['bot_leverage'] ?? $pos['leverage'] ?? null);
                        $efSkippedExamples[] = [
                            'symbol'                   => $pos['symbol']    ?? null,
                            'side'                     => $pos['side']      ?? null,
                            'signal_id'                => $pos['signal_id'] ?? null,
                            'skip_reason'              => $skipReason,
                            'roi'                      => $exRoi,
                            'age_minutes'              => $efResult['age_minutes']   ?? null,
                            'current_price'            => $exCurPrice,
                            'entry_price'              => $exEntryPrice,
                            'leverage'                 => $exLeverage,
                            'neckline_level'           => $efResult['neckline_level'] ?? null,
                            'reclaim_level'            => $efResult['reclaim_level']  ?? null,
                            'adverse_roi_soft'         => (float)($config['double_bottom_early_fail_adverse_roi_soft'] ?? -20.0),
                            'adverse_roi_hard'         => (float)($config['double_bottom_early_fail_adverse_roi_hard'] ?? -35.0),
                            'diagnostic'               => $efResult['diagnostic']   ?? null,
                            // normalization diagnostics (kept alongside plain fields)
                            'current_price_source'     => $efResult['current_price_source']     ?? null,
                            'entry_price_source'       => $efResult['entry_price_source']       ?? null,
                            'leverage_source'          => $efResult['leverage_source']           ?? null,
                            'roi_source'               => $efResult['roi_source']               ?? null,
                            'normalized_current_price' => $efResult['normalized_current_price'] ?? null,
                            'normalized_entry_price'   => $efResult['normalized_entry_price']   ?? null,
                            'normalized_leverage'      => $efResult['normalized_leverage']       ?? null,
                            'normalized_roi'           => $efResult['normalized_roi']            ?? null,
                        ];
                    }
                    continue;
                }

                // Setup failure confirmed
                $efTriggeredTotal++;

                if (count($efTriggeredExamples) < 5) {
                    $ctx = is_array($pos['strategy_signal_context'] ?? null)
                        ? $pos['strategy_signal_context']
                        : [];
                    $efTriggeredExamples[] = [
                        'symbol'                           => $pos['symbol']      ?? null,
                        'side'                             => $pos['side']        ?? null,
                        'signal_id'                        => $pos['signal_id']   ?? null,
                        'setup_class'                      => $pos['setup_class'] ?? $ctx['setup_class'] ?? null,
                        'entry_price'                      => $pos['entry_price'] ?? null,
                        'current_price'                    => $efResult['current_price'] ?? null,
                        'roi'                              => $efResult['roi'],
                        'leverage'                         => $pos['bot_leverage'] ?? $pos['leverage'] ?? null,
                        'opened_at'                        => $pos['opened_at']   ?? null,
                        'age_minutes'                      => $efResult['age_minutes'],
                        'neckline_level'                   => $efResult['neckline_level'],
                        'reclaim_level'                    => $efResult['reclaim_level'],
                        'setup_break_reason'               => $efResult['setup_break_reason'],
                        'close_reason'                     => $config['double_bottom_early_fail_close_reason'] ?? 'double_bottom_setup_failed_after_entry',
                        'synthetic_quality_score'          => $ctx['synthetic_quality_score']          ?? null,
                        'setup_class_score'                => $ctx['setup_class_score']                ?? null,
                        'intraday_double_bottom_score'     => $ctx['intraday_double_bottom_score']     ?? null,
                        'entry_distance_from_neckline_pct' => $ctx['entry_distance_from_neckline_pct'] ?? null,
                        'warnings'                         => $ctx['warnings']    ?? null,
                        'reason_codes'                     => $ctx['reason_codes'] ?? null,
                        // normalization diagnostics
                        'current_price_source'             => $efResult['current_price_source']     ?? null,
                        'entry_price_source'               => $efResult['entry_price_source']       ?? null,
                        'leverage_source'                  => $efResult['leverage_source']           ?? null,
                        'roi_source'                       => $efResult['roi_source']               ?? null,
                        'normalized_current_price'         => $efResult['normalized_current_price'] ?? null,
                        'normalized_entry_price'           => $efResult['normalized_entry_price']   ?? null,
                        'normalized_leverage'              => $efResult['normalized_leverage']       ?? null,
                        'normalized_roi'                   => $efResult['normalized_roi']            ?? null,
                    ];
                }

                // Attempt demo close
                $closeReason = (string)($config['double_bottom_early_fail_close_reason']
                    ?? 'double_bottom_setup_failed_after_entry');

                $closeAttempted = false;
                $closeOk        = null;

                // ── Deduplicate: skip if another guard already closed this position ──
                $efDedupeKey = $this->normalizeExecMode((string)($pos['execution_mode'] ?? 'demo'))
                    . '_' . ($pos['symbol'] ?? '')
                    . '_' . ($pos['side'] ?? '')
                    . (($pos['signal_id'] ?? '') !== '' ? '_' . $pos['signal_id'] : '');

                if (isset($closedThisTick[$efDedupeKey])) {
                    $stopCloseDedupedTotal++;
                    if (count($stopCloseDedupedExamples) < 5) {
                        $stopCloseDedupedExamples[] = [
                            'symbol'           => $pos['symbol']    ?? null,
                            'side'             => $pos['side']      ?? null,
                            'signal_id'        => $pos['signal_id'] ?? null,
                            'skipped_guard'    => 'double_bottom_early_fail',
                            'first_close_guard'=> $closedThisTick[$efDedupeKey],
                            'reason'           => 'skipped_already_closing',
                        ];
                    }
                    $this->appendActionLog([
                        'timestamp'   => $tickAt,
                        'event_type'  => 'close_deduped_skipped',
                        'guard'       => 'double_bottom_early_fail',
                        'symbol'      => $pos['symbol'] ?? '',
                        'side'        => $pos['side']   ?? '',
                        'signal_id'   => $pos['signal_id'] ?? '',
                        'reason'      => 'skipped_already_closing',
                    ]);
                    continue;
                }

                if ($activeGw !== null) {
                    $closeAttempted = true;
                    $closeResult    = $this->submitDemoCloseOrder(
                        $activeGw,
                        $pos,
                        $closeReason,
                        'double_bottom_early_fail',
                        $tickAt
                    );
                    $closeOk = $closeResult['ok'];

                    if ($closeOk) {
                        $efClosedTotal++;
                        $closedThisTick[$efDedupeKey] = 'double_bottom_early_fail';
                        // Write early-fail close registry so the bot can preserve attribution
                        // in closed_trades.json when it detects the position has disappeared.
                        $this->writeEfCloseRegistry($pos, $closeResult, $efResult, $closeReason, $config, $tickAt);
                        $efRegistryWrittenTotal++;
                    }

                    $this->appendActionLog([
                        'timestamp'          => $tickAt,
                        'event_type'         => $closeOk
                            ? 'double_bottom_early_fail_close_submitted'
                            : 'double_bottom_early_fail_close_failed',
                        'close_source'       => 'stop_manager',
                        'close_guard'        => 'double_bottom_early_fail',
                        'close_reason'       => $closeReason,
                        'strategy_id'        => $posStratId,
                        'signal_id'          => $pos['signal_id']   ?? '',
                        'symbol'             => $pos['symbol']       ?? '',
                        'side'               => $pos['side']         ?? '',
                        'entry_price'        => (float)($pos['entry_price'] ?? 0.0),
                        'current_price'      => $efResult['current_price'],
                        'roi'                => $efResult['roi'],
                        'setup_break_reason' => $efResult['setup_break_reason'],
                        'close_attempted'    => $closeAttempted,
                        'close_ok'           => $closeOk,
                        'close_ret_code'     => $closeResult['ret_code'] ?? null,
                        'close_ret_msg'      => $closeResult['ret_msg']  ?? null,
                        'strategy_signal_context' => $pos['strategy_signal_context'] ?? null,
                    ]);
                } else {
                    $this->appendActionLog([
                        'timestamp'          => $tickAt,
                        'event_type'         => 'double_bottom_early_fail_close_skipped_no_gw',
                        'close_source'       => 'stop_manager',
                        'close_guard'        => 'double_bottom_early_fail',
                        'close_reason'       => $closeReason,
                        'strategy_id'        => $posStratId,
                        'signal_id'          => $pos['signal_id']   ?? '',
                        'symbol'             => $pos['symbol']       ?? '',
                        'side'               => $pos['side']         ?? '',
                        'roi'                => $efResult['roi'],
                        'setup_break_reason' => $efResult['setup_break_reason'],
                        'reason'             => 'no_gateway_available',
                    ]);
                }
            }
        }

        // ── Short emergency stop (demo only) ─────────────────────────────────
        $shortProfile      = $config['profiles']['short'] ?? [];
        $shortStopEnabled  = (bool)($shortProfile['enabled']                ?? true);
        $shortEmergEnabled = (bool)($shortProfile['emergency_stop_enabled'] ?? true);

        if ($shortStopEnabled && $shortEmergEnabled && $mode === 'demo' && $isActiveMode) {
            foreach ($posMap as $key => $pos) {
                $posSide = (string)($pos['side'] ?? '');
                if ($posSide !== 'short') {
                    continue;
                }

                $shortStopCheckedTotal++;

                $ssResult   = $this->checkShortEmergencyStop($pos, $shortProfile, $tickAt);
                $skipReason = (string)($ssResult['skip_reason'] ?? '');

                if (!$ssResult['triggered']) {
                    if ($skipReason === 'not_demo_position') {
                        $shortStopSkippedNotDemoTotal++;
                    } elseif ($skipReason === 'strategy_not_allowed') {
                        $shortStopSkippedStratNotAllowedTotal++;
                    } elseif ($skipReason === 'roi_above_cap') {
                        $shortStopSkippedRoiAboveCapTotal++;
                    } elseif ($skipReason === 'short_stop_missing_roi') {
                        $shortStopSkippedMissingRoiTotal++;
                    } elseif ($skipReason === 'too_young') {
                        $shortStopSkippedTooYoungTotal++;
                    }

                    if ($skipReason !== '' && count($shortStopSkippedExamples) < 5) {
                        $shortStopSkippedExamples[] = [
                            'symbol'             => $pos['symbol']    ?? null,
                            'side'               => 'short',
                            'strategy_id'        => $pos['strategy_id'] ?? $pos['owner_strategy'] ?? null,
                            'signal_id'          => $pos['signal_id']  ?? null,
                            'entry_price'        => $ssResult['entry_price']   ?? null,
                            'current_price'      => $ssResult['current_price'] ?? null,
                            'leverage'           => $ssResult['leverage']      ?? null,
                            'normalized_roi'     => $ssResult['normalized_roi'] ?? null,
                            'emergency_stop_roi' => (float)($shortProfile['emergency_stop_roi'] ?? -20.0),
                            'age_minutes'        => $ssResult['age_minutes'] ?? null,
                            'skip_reason'        => $skipReason,
                        ];
                    }
                    continue;
                }

                $shortStopTriggeredTotal++;

                if (count($shortStopTriggeredExamples) < 5) {
                    $ctx = is_array($pos['strategy_signal_context'] ?? null)
                        ? $pos['strategy_signal_context']
                        : [];
                    $shortStopTriggeredExamples[] = [
                        'symbol'             => $pos['symbol']       ?? null,
                        'side'               => 'short',
                        'strategy_id'        => $pos['strategy_id']  ?? $pos['owner_strategy'] ?? null,
                        'owner_strategy'     => $pos['owner_strategy'] ?? null,
                        'signal_id'          => $pos['signal_id']    ?? null,
                        'entry_price'        => $ssResult['entry_price']   ?? null,
                        'current_price'      => $ssResult['current_price'] ?? null,
                        'leverage'           => $ssResult['leverage']      ?? null,
                        'normalized_roi'     => $ssResult['normalized_roi'] ?? null,
                        'emergency_stop_roi' => (float)($shortProfile['emergency_stop_roi'] ?? -20.0),
                        'age_minutes'        => $ssResult['age_minutes'] ?? null,
                        'close_guard'        => 'short_emergency_stop',
                        'close_reason'       => (string)($shortProfile['close_reason'] ?? 'short_emergency_roi_cap'),
                        'dynamic_rule'       => $ctx['dynamic_rule'] ?? null,
                        'strategy_signal_context' => $ctx ?: null,
                    ];
                }

                $ssCloseReason  = (string)($shortProfile['close_reason'] ?? 'short_emergency_roi_cap');
                $ssCloseGuard   = (string)($shortProfile['close_guard']   ?? 'short_emergency_stop');
                $ssCloseAttempted = false;
                $ssCloseOk      = null;

                // ── Deduplicate: skip if another guard already closed this position ──
                $ssDedupeKey = $this->normalizeExecMode((string)($pos['execution_mode'] ?? 'demo'))
                    . '_' . ($pos['symbol'] ?? '')
                    . '_short'
                    . (($pos['signal_id'] ?? '') !== '' ? '_' . $pos['signal_id'] : '');

                if (isset($closedThisTick[$ssDedupeKey])) {
                    $stopCloseDedupedTotal++;
                    if (count($stopCloseDedupedExamples) < 5) {
                        $stopCloseDedupedExamples[] = [
                            'symbol'           => $pos['symbol']    ?? null,
                            'side'             => 'short',
                            'signal_id'        => $pos['signal_id'] ?? null,
                            'skipped_guard'    => $ssCloseGuard,
                            'first_close_guard'=> $closedThisTick[$ssDedupeKey],
                            'reason'           => 'skipped_already_closing',
                        ];
                    }
                    $this->appendActionLog([
                        'timestamp'  => $tickAt,
                        'event_type' => 'close_deduped_skipped',
                        'guard'      => $ssCloseGuard,
                        'symbol'     => $pos['symbol'] ?? '',
                        'side'       => 'short',
                        'signal_id'  => $pos['signal_id'] ?? '',
                        'reason'     => 'skipped_already_closing',
                    ]);
                    continue;
                }

                if ($activeGw !== null) {
                    $ssCloseAttempted = true;
                    $ssCloseResult    = $this->submitDemoCloseOrder(
                        $activeGw,
                        $pos,
                        $ssCloseReason,
                        $ssCloseGuard,
                        $tickAt
                    );
                    $ssCloseOk = $ssCloseResult['ok'];

                    if ($ssCloseOk) {
                        $shortStopClosedTotal++;
                        $closedThisTick[$ssDedupeKey] = $ssCloseGuard;
                        $this->writeShortEmergencyStopRegistry(
                            $pos,
                            $ssCloseResult,
                            $ssResult,
                            $ssCloseReason,
                            $ssCloseGuard,
                            $config,
                            $tickAt
                        );
                    }

                    $this->appendActionLog([
                        'timestamp'          => $tickAt,
                        'event_type'         => $ssCloseOk
                            ? 'short_emergency_stop_close_submitted'
                            : 'short_emergency_stop_close_failed',
                        'close_source'       => 'stop_manager',
                        'close_guard'        => $ssCloseGuard,
                        'close_reason'       => $ssCloseReason,
                        'strategy_id'        => $pos['strategy_id']  ?? $pos['owner_strategy'] ?? '',
                        'signal_id'          => $pos['signal_id']    ?? '',
                        'symbol'             => $pos['symbol']       ?? '',
                        'side'               => 'short',
                        'entry_price'        => $ssResult['entry_price']   ?? null,
                        'current_price'      => $ssResult['current_price'] ?? null,
                        'normalized_roi'     => $ssResult['normalized_roi'] ?? null,
                        'emergency_stop_roi' => (float)($shortProfile['emergency_stop_roi'] ?? -20.0),
                        'close_attempted'    => $ssCloseAttempted,
                        'close_ok'           => $ssCloseOk,
                        'close_ret_code'     => $ssCloseResult['ret_code'] ?? null,
                        'close_ret_msg'      => $ssCloseResult['ret_msg']  ?? null,
                        'strategy_signal_context' => $pos['strategy_signal_context'] ?? null,
                    ]);
                } else {
                    $this->appendActionLog([
                        'timestamp'      => $tickAt,
                        'event_type'     => 'short_emergency_stop_close_skipped_no_gw',
                        'close_source'   => 'stop_manager',
                        'close_guard'    => $ssCloseGuard,
                        'close_reason'   => $ssCloseReason,
                        'strategy_id'    => $pos['strategy_id']  ?? $pos['owner_strategy'] ?? '',
                        'signal_id'      => $pos['signal_id']    ?? '',
                        'symbol'         => $pos['symbol']       ?? '',
                        'side'           => 'short',
                        'normalized_roi' => $ssResult['normalized_roi'] ?? null,
                        'reason'         => 'no_gateway_available',
                    ]);
                }
            }
        }

        // ── Long emergency stop (demo only) ──────────────────────────────────
        $longProfile      = $config['profiles']['long'] ?? [];
        $longStopEnabled  = (bool)($longProfile['enabled']                ?? true);
        $longEmergEnabled = (bool)($longProfile['emergency_stop_enabled'] ?? true);

        if ($longStopEnabled && $longEmergEnabled && $mode === 'demo' && $isActiveMode) {
            foreach ($posMap as $key => $pos) {
                $posSide = (string)($pos['side'] ?? '');
                if ($posSide !== 'long') {
                    continue;
                }

                $longStopCheckedTotal++;

                $lsResult   = $this->checkLongEmergencyStop($pos, $longProfile, $tickAt);
                $skipReason = (string)($lsResult['skip_reason'] ?? '');

                if (!$lsResult['triggered']) {
                    if ($skipReason === 'not_demo_position') {
                        $longStopSkippedNotDemoTotal++;
                    } elseif ($skipReason === 'strategy_not_allowed') {
                        $longStopSkippedStratNotAllowedTotal++;
                    } elseif ($skipReason === 'roi_above_cap') {
                        $longStopSkippedRoiAboveCapTotal++;
                    } elseif ($skipReason === 'long_stop_missing_roi') {
                        $longStopSkippedMissingRoiTotal++;
                    } elseif ($skipReason === 'too_young') {
                        $longStopSkippedTooYoungTotal++;
                    }

                    if ($skipReason !== '' && count($longStopSkippedExamples) < 5) {
                        $longStopSkippedExamples[] = [
                            'symbol'             => $pos['symbol']    ?? null,
                            'side'               => 'long',
                            'strategy_id'        => $pos['strategy_id'] ?? $pos['owner_strategy'] ?? null,
                            'signal_id'          => $pos['signal_id']  ?? null,
                            'entry_price'        => $lsResult['entry_price']   ?? null,
                            'current_price'      => $lsResult['current_price'] ?? null,
                            'leverage'           => $lsResult['leverage']      ?? null,
                            'normalized_roi'     => $lsResult['normalized_roi'] ?? null,
                            'emergency_stop_roi' => (float)($longProfile['emergency_stop_roi'] ?? -30.0),
                            'age_minutes'        => $lsResult['age_minutes'] ?? null,
                            'skip_reason'        => $skipReason,
                        ];
                    }
                    continue;
                }

                $longStopTriggeredTotal++;

                if (count($longStopTriggeredExamples) < 5) {
                    $ctx = is_array($pos['strategy_signal_context'] ?? null)
                        ? $pos['strategy_signal_context']
                        : [];
                    $longStopTriggeredExamples[] = [
                        'symbol'             => $pos['symbol']       ?? null,
                        'side'               => 'long',
                        'strategy_id'        => $pos['strategy_id']  ?? $pos['owner_strategy'] ?? null,
                        'owner_strategy'     => $pos['owner_strategy'] ?? null,
                        'signal_id'          => $pos['signal_id']    ?? null,
                        'entry_price'        => $lsResult['entry_price']   ?? null,
                        'current_price'      => $lsResult['current_price'] ?? null,
                        'leverage'           => $lsResult['leverage']      ?? null,
                        'normalized_roi'     => $lsResult['normalized_roi'] ?? null,
                        'emergency_stop_roi' => (float)($longProfile['emergency_stop_roi'] ?? -30.0),
                        'age_minutes'        => $lsResult['age_minutes'] ?? null,
                        'close_guard'        => 'long_emergency_stop',
                        'close_reason'       => (string)($longProfile['close_reason'] ?? 'long_emergency_roi_cap'),
                        'dynamic_rule'       => $ctx['dynamic_rule'] ?? null,
                        'strategy_signal_context' => $ctx ?: null,
                    ];
                }

                $lsCloseReason  = (string)($longProfile['close_reason'] ?? 'long_emergency_roi_cap');
                $lsCloseGuard   = (string)($longProfile['close_guard']   ?? 'long_emergency_stop');
                $lsCloseAttempted = false;
                $lsCloseOk      = null;

                // ── Deduplicate: skip if another guard already closed this position ──
                $lsDedupeKey = $this->normalizeExecMode((string)($pos['execution_mode'] ?? 'demo'))
                    . '_' . ($pos['symbol'] ?? '')
                    . '_long'
                    . (($pos['signal_id'] ?? '') !== '' ? '_' . $pos['signal_id'] : '');

                if (isset($closedThisTick[$lsDedupeKey])) {
                    $stopCloseDedupedTotal++;
                    if (count($stopCloseDedupedExamples) < 5) {
                        $stopCloseDedupedExamples[] = [
                            'symbol'           => $pos['symbol']    ?? null,
                            'side'             => 'long',
                            'signal_id'        => $pos['signal_id'] ?? null,
                            'skipped_guard'    => $lsCloseGuard,
                            'first_close_guard'=> $closedThisTick[$lsDedupeKey],
                            'reason'           => 'skipped_already_closing',
                        ];
                    }
                    $this->appendActionLog([
                        'timestamp'  => $tickAt,
                        'event_type' => 'close_deduped_skipped',
                        'guard'      => $lsCloseGuard,
                        'symbol'     => $pos['symbol'] ?? '',
                        'side'       => 'long',
                        'signal_id'  => $pos['signal_id'] ?? '',
                        'reason'     => 'skipped_already_closing',
                    ]);
                    continue;
                }

                if ($activeGw !== null) {
                    $lsCloseAttempted = true;
                    $lsCloseResult    = $this->submitDemoCloseOrder(
                        $activeGw,
                        $pos,
                        $lsCloseReason,
                        $lsCloseGuard,
                        $tickAt
                    );
                    $lsCloseOk = $lsCloseResult['ok'];

                    if ($lsCloseOk) {
                        $longStopClosedTotal++;
                        $closedThisTick[$lsDedupeKey] = $lsCloseGuard;
                        $this->writeLongEmergencyStopRegistry(
                            $pos,
                            $lsCloseResult,
                            $lsResult,
                            $lsCloseReason,
                            $lsCloseGuard,
                            $config,
                            $tickAt
                        );
                    }

                    $this->appendActionLog([
                        'timestamp'          => $tickAt,
                        'event_type'         => $lsCloseOk
                            ? 'long_emergency_stop_close_submitted'
                            : 'long_emergency_stop_close_failed',
                        'close_source'       => 'stop_manager',
                        'close_guard'        => $lsCloseGuard,
                        'close_reason'       => $lsCloseReason,
                        'strategy_id'        => $pos['strategy_id']  ?? $pos['owner_strategy'] ?? '',
                        'signal_id'          => $pos['signal_id']    ?? '',
                        'symbol'             => $pos['symbol']       ?? '',
                        'side'               => 'long',
                        'entry_price'        => $lsResult['entry_price']   ?? null,
                        'current_price'      => $lsResult['current_price'] ?? null,
                        'normalized_roi'     => $lsResult['normalized_roi'] ?? null,
                        'emergency_stop_roi' => (float)($longProfile['emergency_stop_roi'] ?? -30.0),
                        'close_attempted'    => $lsCloseAttempted,
                        'close_ok'           => $lsCloseOk,
                        'close_ret_code'     => $lsCloseResult['ret_code'] ?? null,
                        'close_ret_msg'      => $lsCloseResult['ret_msg']  ?? null,
                        'strategy_signal_context' => $pos['strategy_signal_context'] ?? null,
                    ]);
                } else {
                    $this->appendActionLog([
                        'timestamp'      => $tickAt,
                        'event_type'     => 'long_emergency_stop_close_skipped_no_gw',
                        'close_source'   => 'stop_manager',
                        'close_guard'    => $lsCloseGuard,
                        'close_reason'   => $lsCloseReason,
                        'strategy_id'    => $pos['strategy_id']  ?? $pos['owner_strategy'] ?? '',
                        'signal_id'      => $pos['signal_id']    ?? '',
                        'symbol'         => $pos['symbol']       ?? '',
                        'side'           => 'long',
                        'normalized_roi' => $lsResult['normalized_roi'] ?? null,
                        'reason'         => 'no_gateway_available',
                    ]);
                }
            }
        }

        return [
            'stops'                                        => $stopMap,
            'positions_seen'                               => $positionsSeen,
            'positions_with_real_liq'                      => $positionsWithRealLiq,
            'positions_with_estimated_liq'                 => $positionsWithEstimatedLiq,
            'positions_without_liq'                        => $positionsWithoutLiq,
            'stops_initialized'                            => $stopsInitialized,
            'stops_recalculated'                           => $stopsRecalculated,
            'breakeven_applied'                            => $breakevenApplied,
            'stops_closed_reference'                       => $stopsClosedReference,
            'demo_stops_set'                               => $demoStopsSet,
            'demo_stops_already_set'                       => $demoStopsAlreadySet,
            'demo_stops_failed'                            => $demoStopsFailed,
            'demo_stops_skipped_no_gw'                     => $demoStopsSkippedNoGw,
            'demo_last_stop_error_code'                    => $demoLastStopErrCode,
            'demo_last_stop_error_msg'                     => $demoLastStopErrMsg,
            'demo_last_stop_symbol'                        => $demoLastStopSymbol,
            // Early fail guard counters
            'ef_checked_total'                             => $efCheckedTotal,
            'ef_triggered_total'                           => $efTriggeredTotal,
            'ef_closed_total'                              => $efClosedTotal,
            'ef_registry_written_total'                    => $efRegistryWrittenTotal,
            'ef_skipped_missing_trace'                     => $efSkippedMissingTrace,
            'ef_skipped_no_setup_break'                    => $efSkippedNoSetupBreak,
            'ef_skipped_too_young'                         => $efSkippedTooYoung,
            'ef_skipped_not_db'                            => $efSkippedNotDB,
            'ef_triggered_examples'                        => $efTriggeredExamples,
            'ef_skipped_examples'                          => $efSkippedExamples,
            // Position price/ROI normalization counters
            'db_price_normalized_total'                    => $dbPriceNormalizedTotal,
            'db_roi_normalized_total'                      => $dbRoiNormalizedTotal,
            'db_roi_missing_total'                         => $dbRoiMissingTotal,
            'db_price_missing_total'                       => $dbPriceMissingTotal,
            'db_normalization_examples'                    => $dbNormalizationExamples,
            'db_missing_price_examples'                    => $dbMissingPriceExamples,
            'db_missing_roi_examples'                      => $dbMissingRoiExamples,
            // Short emergency stop counters
            'short_stop_checked_total'                      => $shortStopCheckedTotal,
            'short_stop_triggered_total'                    => $shortStopTriggeredTotal,
            'short_stop_closed_total'                       => $shortStopClosedTotal,
            'short_stop_skipped_not_demo_total'             => $shortStopSkippedNotDemoTotal,
            'short_stop_skipped_strategy_not_allowed_total' => $shortStopSkippedStratNotAllowedTotal,
            'short_stop_skipped_roi_above_cap_total'        => $shortStopSkippedRoiAboveCapTotal,
            'short_stop_skipped_missing_roi_total'          => $shortStopSkippedMissingRoiTotal,
            'short_stop_skipped_too_young_total'            => $shortStopSkippedTooYoungTotal,
            'short_stop_triggered_examples'                 => $shortStopTriggeredExamples,
            'short_stop_skipped_examples'                   => $shortStopSkippedExamples,
            // Long emergency stop counters
            'long_stop_checked_total'                       => $longStopCheckedTotal,
            'long_stop_triggered_total'                     => $longStopTriggeredTotal,
            'long_stop_closed_total'                        => $longStopClosedTotal,
            'long_stop_skipped_not_demo_total'              => $longStopSkippedNotDemoTotal,
            'long_stop_skipped_strategy_not_allowed_total'  => $longStopSkippedStratNotAllowedTotal,
            'long_stop_skipped_roi_above_cap_total'         => $longStopSkippedRoiAboveCapTotal,
            'long_stop_skipped_missing_roi_total'           => $longStopSkippedMissingRoiTotal,
            'long_stop_skipped_too_young_total'             => $longStopSkippedTooYoungTotal,
            'long_stop_triggered_examples'                  => $longStopTriggeredExamples,
            'long_stop_skipped_examples'                    => $longStopSkippedExamples,
            // Legacy liq_distance path counters
            'legacy_stop_skipped_total'                     => $legacyStopSkippedTotal,
            // Protective stop (ROI-based proactive exchange stop) counters
            'side_roi_protective_stop_enabled'              => $sideRoiProtEnabled,
            'protective_stops_checked_total'                => $protStopsCheckedTotal,
            'protective_stops_set_total'                    => $protStopsSetTotal,
            'protective_stops_updated_total'                => $protStopsUpdatedTotal,
            'protective_stops_already_ok_total'             => $protStopsAlreadyOkTotal,
            'protective_stops_failed_total'                 => $protStopsFailedTotal,
            'protective_stops_skipped_total'                => $protStopsSkippedTotal,
            'long_protective_stop_set_total'                => $longProtStopsSetTotal,
            'long_protective_stop_failed_total'             => $longProtStopsFailedTotal,
            'short_protective_stop_set_total'               => $shortProtStopsSetTotal,
            'short_protective_stop_failed_total'            => $shortProtStopsFailedTotal,
            'protective_stop_examples'                      => $protStopExamples,
            'protective_stop_failed_examples'               => $protStopFailedExamples,
            'protective_stops_active_count'                 => count($protStopState),
            // StopLoss set/verify diagnostics
            'stoploss_set_attempted_total'                  => $slSetAttemptedTotal,
            'stoploss_set_success_total'                    => $slSetSuccessTotal,
            'stoploss_set_verified_total'                   => $slSetVerifiedTotal,
            'stoploss_set_unverified_total'                 => $slSetUnverifiedTotal,
            'stoploss_set_failed_total'                     => $slSetFailedTotal,
            'live_stoploss_set_attempted_total'             => $liveSlSetAttemptedTotal,
            'live_stoploss_set_verified_total'              => $liveSlSetVerifiedTotal,
            'demo_stoploss_set_verified_total'              => $demoSlSetVerifiedTotal,
            'stoploss_set_examples'                         => $slSetExamples,
            'stoploss_unverified_examples'                  => $slUnverifiedExamples,
            'stoploss_failed_examples'                      => $slFailedExamples,
            // Close deduplication counters
            'stop_close_deduped_total'                      => $stopCloseDedupedTotal,
            'stop_close_deduped_examples'                   => $stopCloseDedupedExamples,
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

    /**
     * Normalize current price, entry price, and leverage for a position
     * using safe fallback chains.
     *
     * current_price fallback: current_price → mark_price → last_price → price → null
     * entry_price fallback:   entry_price   → avg_entry_price → open_price → null
     * leverage fallback:      leverage      → bot_leverage    → config_leverage → 1 (default)
     *
     * @return array{
     *   normalized_current_price: float|null,
     *   current_price_source: string,
     *   normalized_entry_price: float|null,
     *   entry_price_source: string,
     *   normalized_leverage: int,
     *   leverage_source: string,
     * }
     */
    private function normalizePositionPrices(array $pos): array
    {
        // Current price fallback chain
        $currentPrice       = null;
        $currentPriceSource = 'missing';
        foreach (['current_price', 'mark_price', 'last_price', 'price'] as $field) {
            $val = $pos[$field] ?? null;
            if ($val !== null && is_numeric($val) && (float)$val > 0.0) {
                $currentPrice       = (float)$val;
                $currentPriceSource = $field;
                break;
            }
        }

        // Entry price fallback chain
        $entryPrice       = null;
        $entryPriceSource = 'missing';
        foreach (['entry_price', 'avg_entry_price', 'open_price'] as $field) {
            $val = $pos[$field] ?? null;
            if ($val !== null && is_numeric($val) && (float)$val > 0.0) {
                $entryPrice       = (float)$val;
                $entryPriceSource = $field;
                break;
            }
        }

        // Leverage fallback chain
        $leverage       = 1;
        $leverageSource = 'default';
        foreach (['leverage', 'bot_leverage', 'config_leverage'] as $field) {
            $val = $pos[$field] ?? null;
            if ($val !== null && is_numeric($val) && (int)$val > 0) {
                $leverage       = (int)$val;
                $leverageSource = $field;
                break;
            }
        }

        return [
            'normalized_current_price' => $currentPrice,
            'current_price_source'     => $currentPriceSource,
            'normalized_entry_price'   => $entryPrice,
            'entry_price_source'       => $entryPriceSource,
            'normalized_leverage'      => $leverage,
            'leverage_source'          => $leverageSource,
        ];
    }

    /**
     * Normalize ROI for a position.
     *
     * Tries explicit ROI fields first, then calculates from normalized prices.
     * Calculation: long:  ((current − entry) / entry) × leverage × 100
     *              short: ((entry − current) / entry) × leverage × 100
     *
     * @return array{normalized_roi: float|null, roi_source: string}
     */
    private function normalizePositionRoi(array $pos, array $priceNorm): array
    {
        $side = (string)($pos['side'] ?? 'long');

        // Try explicit ROI fields first
        foreach (['roi', 'roi_pct', 'unrealized_roi', 'unrealised_roi'] as $field) {
            $val = $pos[$field] ?? null;
            if ($val !== null && is_numeric($val)) {
                return [
                    'normalized_roi' => (float)$val,
                    'roi_source'     => $field,
                ];
            }
        }

        // Calculate from normalized prices
        $currentPrice = $priceNorm['normalized_current_price'];
        $entryPrice   = $priceNorm['normalized_entry_price'];
        $leverage     = $priceNorm['normalized_leverage'];
        if ($currentPrice !== null && $entryPrice !== null && $entryPrice > 0.0) {
            return [
                'normalized_roi' => $this->calcRoi($side, $entryPrice, $currentPrice, $leverage),
                'roi_source'     => 'calculated',
            ];
        }

        return [
            'normalized_roi' => null,
            'roi_source'     => 'missing',
        ];
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
        string $reason,
        float $liqDistPct = 90.0
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
            'execution_mode'        => $this->normalizeExecMode((string)($pos['execution_mode'] ?? 'demo')),
            'mode'                  => (string)($pos['mode']    ?? $pos['execution_mode'] ?? 'demo'),
            'account'               => (string)($pos['account'] ?? ''),
            'stop_mode'             => 'liq_distance_percent',
            'liq_distance_percent'  => $liqDistPct,
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
            'execution_mode'    => $this->normalizeExecMode((string)($pos['execution_mode'] ?? 'demo')),
            'mode'              => (string)($pos['mode']    ?? $pos['execution_mode'] ?? 'demo'),
            'account'           => (string)($pos['account'] ?? ''),
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
                'module_mode'                  => 'demo',
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
     * Get a Bybit gateway client for live trading via KeyCenter.
     *
     * Uses account_id from the bot config (single source of truth).
     * Never uses demo credentials, never falls back.
     */
    private function getLiveGateway(array $smConfig): ?\Core\Gateway\Bybit
    {
        $botConfig = $this->loadBotConfig($smConfig);
        $accountId = trim((string)($botConfig['account_id'] ?? ''));
        if ($accountId === '') {
            return null;
        }

        try {
            return \Core\Gateway\Bybit::client($accountId);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Call Bybit /v5/position/trading-stop to set a stop-loss (demo or live).
     *
     * Uses the correct Bybit V5 semantics:
     *   - category = linear
     *   - tpslMode = Full (set full-position stop)
     *   - slTriggerBy = MarkPrice (or from config)
     *   - positionIdx derived from $side and $positionMode config:
     *       one-way mode: 0 for all positions
     *       hedge mode:   1 for long, 2 for short
     *
     * Treats the following as success:
     *   - retCode = 0
     *   - retCode = 110043 (already set / not modified)
     *   - retMsg contains "not modified" or "not been modified"
     *
     * Never logs API keys.
     *
     * @param \Core\Gateway\Bybit $gw         Gateway client (demo or live)
     * @param string              $symbol      Trading symbol (uppercase)
     * @param string              $side        'long' or 'short'
     * @param float               $stopPrice   Stop-loss price
     * @param string              $positionMode 'one-way' (default) or 'hedge'
     * @param string              $tpslMode    'Full' or 'Partial' (default 'Full')
     * @param string              $triggerBy   'MarkPrice', 'LastPrice', 'IndexPrice'
     *
     * @return array{
     *   ok: bool,
     *   symbol: string,
     *   side: string,
     *   stop_price: float,
     *   position_idx: int,
     *   tpsl_mode: string,
     *   trigger_by: string,
     *   ret_code: int,
     *   ret_msg: string,
     *   note: string
     * }
     */
    private function setTradingStop(
        \Core\Gateway\Bybit $gw,
        string $symbol,
        string $side,
        float  $stopPrice,
        string $positionMode = 'one-way',
        string $tpslMode     = 'Full',
        string $triggerBy    = 'MarkPrice'
    ): array {
        $stopStr = rtrim(rtrim(number_format($stopPrice, 8, '.', ''), '0'), '.');

        // Derive positionIdx from position mode and side
        if ($positionMode === 'hedge') {
            $positionIdx = ($side === 'short') ? 2 : 1;
        } else {
            $positionIdx = 0; // one-way mode
        }

        try {
            $resp = $gw->request('/v5/position/trading-stop', [
                'category'    => 'linear',
                'symbol'      => $symbol,
                'stopLoss'    => $stopStr,
                'slTriggerBy' => $triggerBy,
                'tpslMode'    => $tpslMode,
                'positionIdx' => $positionIdx,
            ], true);
        } catch (\Throwable $ex) {
            return [
                'ok'           => false,
                'symbol'       => $symbol,
                'side'         => $side,
                'stop_price'   => $stopPrice,
                'position_idx' => $positionIdx,
                'tpsl_mode'    => $tpslMode,
                'trigger_by'   => $triggerBy,
                'ret_code'     => -1,
                'ret_msg'      => $ex->getMessage(),
                'note'         => 'exception',
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
            'ok'           => $ok,
            'symbol'       => $symbol,
            'side'         => $side,
            'stop_price'   => $stopPrice,
            'position_idx' => $positionIdx,
            'tpsl_mode'    => $tpslMode,
            'trigger_by'   => $triggerBy,
            'ret_code'     => $retCode,
            'ret_msg'      => $retMsg,
            'note'         => $note,
        ];
    }

    /**
     * Verify that a stopLoss was accepted by the exchange by querying position info.
     *
     * Queries /v5/position/info and checks the stopLoss field on the returned position.
     * Considers the stop verified if the exchange-reported stopLoss is non-zero and
     * approximately equal to the requested price (within 0.1% relative tolerance).
     *
     * @param \Core\Gateway\Bybit $gw                Gateway client
     * @param string              $symbol             Trading symbol
     * @param string              $side               'long' or 'short'
     * @param float               $requestedStopPrice Price that was requested
     * @param string              $positionMode        'one-way' or 'hedge'
     *
     * @return array{
     *   stop_loss_confirmed: bool,
     *   requested_stop_loss: float,
     *   confirmed_stop_loss: float|null,
     *   ret_code: int|null,
     *   ret_msg: string|null,
     *   verification_status: string
     * }
     */
    private function verifyTradingStop(
        \Core\Gateway\Bybit $gw,
        string $symbol,
        string $side,
        float  $requestedStopPrice,
        string $positionMode = 'one-way'
    ): array {
        $base = [
            'stop_loss_confirmed'  => false,
            'requested_stop_loss'  => $requestedStopPrice,
            'confirmed_stop_loss'  => null,
            'ret_code'             => null,
            'ret_msg'              => null,
            'verification_status'  => 'unknown',
        ];

        try {
            $resp = $gw->request('/v5/position/info', [
                'category' => 'linear',
                'symbol'   => $symbol,
            ]);
        } catch (\Throwable $ex) {
            return array_merge($base, [
                'verification_status' => 'query_exception',
                'ret_msg'             => $ex->getMessage(),
            ]);
        }

        $retCode = (int)($resp['ret_code'] ?? -1);
        $retMsg  = (string)($resp['ret_msg'] ?? '');

        if ($retCode !== 0) {
            return array_merge($base, [
                'ret_code'            => $retCode,
                'ret_msg'             => $retMsg,
                'verification_status' => 'query_failed',
            ]);
        }

        // Find the matching position in the list
        $positionList = (array)($resp['result']['list'] ?? []);
        $matchedPos   = null;

        foreach ($positionList as $p) {
            $pSide = strtolower((string)($p['side'] ?? ''));
            // Normalize Bybit side: 'Buy' → 'long', 'Sell' → 'short'
            if ($pSide === 'buy')  { $pSide = 'long'; }
            if ($pSide === 'sell') { $pSide = 'short'; }

            if ($pSide === $side) {
                $matchedPos = $p;
                break;
            }
        }

        if ($matchedPos === null) {
            return array_merge($base, [
                'ret_code'            => $retCode,
                'ret_msg'             => $retMsg,
                'verification_status' => 'position_not_found',
            ]);
        }

        $exchangeStop = (float)($matchedPos['stopLoss'] ?? 0.0);

        if ($exchangeStop <= 0.0) {
            return array_merge($base, [
                'ret_code'            => $retCode,
                'ret_msg'             => $retMsg,
                'confirmed_stop_loss' => $exchangeStop,
                'verification_status' => 'stop_loss_zero_on_exchange',
            ]);
        }

        // Approximate match: within 0.1% of requested price
        $tolerance = 0.001 * max($requestedStopPrice, $exchangeStop);
        $confirmed = abs($exchangeStop - $requestedStopPrice) <= $tolerance;

        return [
            'stop_loss_confirmed'  => $confirmed,
            'requested_stop_loss'  => $requestedStopPrice,
            'confirmed_stop_loss'  => $exchangeStop,
            'ret_code'             => $retCode,
            'ret_msg'              => $retMsg,
            'verification_status'  => $confirmed ? 'verified' : 'price_mismatch',
        ];
    }

    /**
     * @deprecated Use setTradingStop() instead.
     *
     * Backward-compat wrapper kept so callers in the legacy liq_distance_percent
     * path continue to work without change. Delegates to setTradingStop().
     * side defaults to 'long' for backward compat (legacy path is long-only).
     */
    private function setDemoTradingStop(
        \Core\Gateway\Bybit $gw,
        string $symbol,
        float $stopPrice,
        string $side = 'long'
    ): array {
        return $this->setTradingStop($gw, $symbol, $side, $stopPrice);
    }

    /**
     * Normalize legacy execution_mode values into the canonical set (demo | live).
     * Legacy aliases: paper, smoke, active, passive, disabled → demo.
     */
    private function normalizeExecMode(string $raw): string
    {
        return match ($raw) {
            'live'  => 'live',
            'demo'  => 'demo',
            // Legacy modes: map all to demo
            default => 'demo',
        };
    }

    /**
     * Normalize a mode string to the canonical set (demo | live).
     * Legacy modes (paper, passive, active, disabled) → demo.
     */
    public static function normalizeMode(string $mode): string
    {
        return match ($mode) {
            'live'  => 'live',
            'demo'  => 'demo',
            default => 'demo',
        };
    }

    /**
     * Normalize enabled flag taking legacy mode into account.
     * If mode was 'disabled' or 'passive', force enabled=false.
     * Otherwise preserve the explicit enabled value.
     */
    public static function normalizeEnabled(bool $enabled, string $rawMode): bool
    {
        if ($rawMode === 'disabled' || $rawMode === 'passive') {
            return false;
        }
        return $enabled;
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
            // Early fail guard cumulative counters
            'ef_checked_total'                    => 0,
            'ef_triggered_total'                  => 0,
            'ef_closed_total'                     => 0,
            'ef_registry_written_total'           => 0,
            'ef_skipped_missing_trace_total'      => 0,
            'ef_skipped_no_setup_break_total'     => 0,
            'ef_skipped_too_young_total'           => 0,
            'ef_skipped_not_db_total'             => 0,
            // Position price/ROI normalization cumulative counters
            'db_price_normalized_total'           => 0,
            'db_roi_normalized_total'             => 0,
            'db_roi_missing_total'                => 0,
            'db_price_missing_total'              => 0,
            // Short emergency stop cumulative counters
            'short_stop_checked_total'                      => 0,
            'short_stop_triggered_total'                    => 0,
            'short_stop_closed_total'                       => 0,
            'short_stop_skipped_not_demo_total'             => 0,
            'short_stop_skipped_strategy_not_allowed_total' => 0,
            'short_stop_skipped_roi_above_cap_total'        => 0,
            'short_stop_skipped_missing_roi_total'          => 0,
            'short_stop_skipped_too_young_total'            => 0,
            // Protective stop cumulative counters
            'protective_stops_checked_total'                => 0,
            'protective_stops_set_total'                    => 0,
            'protective_stops_updated_total'                => 0,
            'protective_stops_already_ok_total'             => 0,
            'protective_stops_failed_total'                 => 0,
            'protective_stops_skipped_total'                => 0,
            'long_protective_stop_set_total'                => 0,
            'long_protective_stop_failed_total'             => 0,
            'short_protective_stop_set_total'               => 0,
            'short_protective_stop_failed_total'            => 0,
            // Close deduplication cumulative counters
            'stop_close_deduped_total'                      => 0,
            // StopLoss set/verify cumulative counters
            'stoploss_set_attempted_total'                  => 0,
            'stoploss_set_success_total'                    => 0,
            'stoploss_set_verified_total'                   => 0,
            'stoploss_set_unverified_total'                 => 0,
            'stoploss_set_failed_total'                     => 0,
            'live_stoploss_set_attempted_total'             => 0,
            'live_stoploss_set_verified_total'              => 0,
            'demo_stoploss_set_verified_total'              => 0,
        ];
    }

    /**
     * Compute the protective stop price for a side-specific ROI emergency stop.
     *
     * Long:  stop_price = entry_price × (1 + emergency_stop_roi / leverage / 100)
     *        (emergency_stop_roi is negative → price is below entry)
     *
     * Short: stop_price = entry_price × (1 − emergency_stop_roi / leverage / 100)
     *        (emergency_stop_roi is negative → price is above entry)
     *
     * @param string $side            'long' or 'short'
     * @param float  $entryPrice      Position entry price (> 0)
     * @param float  $leverage        Position leverage (> 0)
     * @param float  $emergencyStopRoi Emergency stop ROI threshold (< 0, e.g. -12.0)
     * @return float|null             Protective stop price, or null if inputs invalid
     */
    private function computeProtectiveStopPrice(
        string $side,
        float  $entryPrice,
        float  $leverage,
        float  $emergencyStopRoi
    ): ?float {
        if ($entryPrice <= 0.0 || $leverage <= 0.0) {
            return null;
        }

        $priceMovePercent = $emergencyStopRoi / $leverage / 100.0;

        if ($side === 'long') {
            // Loss when price decreases; stop below entry
            return $entryPrice * (1.0 + $priceMovePercent);
        }

        if ($side === 'short') {
            // Loss when price increases; stop above entry
            return $entryPrice * (1.0 - $priceMovePercent);
        }

        return null;
    }

    private function appendActionLog(array $event): void
    {
        $path = $this->moduleDir . '/storage/actions_log.ndjson';
        $line = json_encode($event, JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    // =========================================================================
    // double_bottom_long early fail guard
    // =========================================================================

    /**
     * Detect whether a double_bottom_long position's setup has broken after entry.
     *
     * Returns a diagnostic array.  Key fields:
     *   triggered       bool   — true when a setup-failure condition was met
     *   skip_reason     string — reason when triggered=false (empty string when triggered)
     *   setup_break_reason string — which condition fired (when triggered=true)
     *   roi             float  — current ROI
     *   age_minutes     float  — position age in minutes
     *   current_price   float  — price used for evaluation
     *   neckline_level  float|null
     *   reclaim_level   float|null
     *   diagnostic      string|null — extra notes separated by ';'
     *
     * Setup-failure conditions evaluated (any one triggers):
     *   A) Neckline or reclaim level lost by configured break %
     *   B) Price below entry_price by entry_break_pct AND ROI <= soft threshold
     *   C) Fast dump >= fast_drop_pct within fast_drop_window_minutes AND ROI <= soft
     *      NOTE: Condition C is NOT evaluated by this implementation.  active_positions.json
     *      carries only the latest current_price snapshot, not per-tick price history.
     *      The diagnostic field will always contain 'condition_c_fast_dump_skipped_no_price_history'.
     *   D) ROI <= hard threshold AND signal trace contains a known warning code
     *
     * This method has no side effects.  Closing is the caller's responsibility.
     */
    private function checkDoubleBottomEarlyFail(array $pos, array $config, string $tickAt): array
    {
        $base = [
            'triggered'                => false,
            'skip_reason'              => '',
            'setup_break_reason'       => null,
            'roi'                      => null,
            'age_minutes'              => null,
            'current_price'            => null,
            'neckline_level'           => null,
            'reclaim_level'            => null,
            'diagnostic'               => null,
            // position price/ROI normalization diagnostics
            'current_price_source'     => null,
            'entry_price_source'       => null,
            'leverage_source'          => null,
            'roi_source'               => null,
            'normalized_current_price' => null,
            'normalized_entry_price'   => null,
            'normalized_leverage'      => null,
            'normalized_roi'           => null,
        ];

        // Only long side
        $side = (string)($pos['side'] ?? 'long');
        if ($side !== 'long') {
            return array_merge($base, ['skip_reason' => 'not_long_side']);
        }

        // Only demo positions
        $posMode = $this->normalizeExecMode((string)($pos['execution_mode'] ?? 'demo'));
        if ($posMode !== 'demo') {
            return array_merge($base, ['skip_reason' => 'not_demo_position']);
        }

        // Minimum age gate
        $minAgeSec  = max(0, (int)($config['double_bottom_early_fail_min_age_seconds'] ?? 60));
        $openedAt   = (string)($pos['opened_at'] ?? '');
        $openedTs   = $openedAt !== '' ? (int)strtotime($openedAt) : 0;
        $nowTs      = time();
        $ageSec     = $openedTs > 0 ? max(0, $nowTs - $openedTs) : 0;
        $ageMinutes = round($ageSec / 60.0, 1);

        // Price/ROI normalization — run before any skip branch (too_young,
        // outside_watch_window, roi_not_adverse) so every return path carries
        // normalized fields when source data is available in the position record.
        $priceNorm    = $this->normalizePositionPrices($pos);
        $currentPrice = $priceNorm['normalized_current_price'];
        $entryPrice   = $priceNorm['normalized_entry_price'] ?? 0.0;
        $leverage     = $priceNorm['normalized_leverage'];

        // Propagate normalization metadata into $base so all subsequent return
        // paths include it automatically via array_merge($base, [...])
        $base['current_price_source']     = $priceNorm['current_price_source'];
        $base['entry_price_source']       = $priceNorm['entry_price_source'];
        $base['leverage_source']          = $priceNorm['leverage_source'];
        $base['normalized_current_price'] = $currentPrice;
        $base['normalized_entry_price']   = $entryPrice > 0.0 ? $entryPrice : null;
        $base['normalized_leverage']      = $leverage;

        $roiNorm = $this->normalizePositionRoi($pos, $priceNorm);
        $roi     = $roiNorm['normalized_roi'];
        $base['roi_source']    = $roiNorm['roi_source'];
        $base['normalized_roi'] = $roi;

        if ($ageSec < $minAgeSec) {
            return array_merge($base, [
                'skip_reason' => 'too_young',
                'age_minutes' => $ageMinutes,
            ]);
        }

        // Watch window gate — only evaluate within the configured window after entry
        $watchMinutes = max(0, (int)($config['double_bottom_early_fail_watch_minutes'] ?? 30));
        if ($watchMinutes > 0 && $ageMinutes > $watchMinutes) {
            return array_merge($base, [
                'skip_reason' => 'outside_watch_window',
                'age_minutes' => $ageMinutes,
            ]);
        }

        // Require signal trace: signal_id OR non-empty strategy_signal_context
        $signalId = (string)($pos['signal_id'] ?? '');
        $ctx      = is_array($pos['strategy_signal_context'] ?? null)
            ? $pos['strategy_signal_context']
            : [];
        $hasTrace = $signalId !== '' || !empty($ctx);
        if (!$hasTrace) {
            return array_merge($base, [
                'skip_reason' => 'early_fail_skipped_missing_trace',
                'age_minutes' => $ageMinutes,
            ]);
        }

        if ($currentPrice === null || $entryPrice <= 0.0) {
            return array_merge($base, [
                'skip_reason'   => 'no_price_data',
                'age_minutes'   => $ageMinutes,
                'current_price' => $currentPrice,
            ]);
        }

        // Guard defensively for null roi (roi will be non-null when price/entry
        // are valid, but static analysis requires the check)
        if ($roi === null) {
            return array_merge($base, [
                'skip_reason'   => 'no_roi_data',
                'age_minutes'   => $ageMinutes,
                'current_price' => $currentPrice,
            ]);
        }

        // No adverse ROI at all — nothing to guard against
        if ($roi >= 0.0) {
            return array_merge($base, [
                'skip_reason'   => 'roi_not_adverse',
                'roi'           => $roi,
                'age_minutes'   => $ageMinutes,
                'current_price' => $currentPrice,
            ]);
        }

        $softThreshold = (float)($config['double_bottom_early_fail_adverse_roi_soft'] ?? -20.0);
        $hardThreshold = (float)($config['double_bottom_early_fail_adverse_roi_hard'] ?? -35.0);

        // Extract signal-context structure levels (prefer ctx over flat pos fields)
        $necklineLevel = null;
        $reclaimLevel  = null;
        $rawNeck = $ctx['neckline_level']  ?? $pos['neckline_level']  ?? null;
        $rawRecl = $ctx['reclaim_level']   ?? $pos['reclaim_level']   ?? null;
        if ($rawNeck !== null && is_numeric($rawNeck) && (float)$rawNeck > 0.0) {
            $necklineLevel = (float)$rawNeck;
        }
        if ($rawRecl !== null && is_numeric($rawRecl) && (float)$rawRecl > 0.0) {
            $reclaimLevel = (float)$rawRecl;
        }

        $warnings    = [];
        $reasonCodes = [];
        $rawW = $ctx['warnings']    ?? $pos['warnings']    ?? null;
        $rawR = $ctx['reason_codes'] ?? $pos['reason_codes'] ?? null;
        if (is_array($rawW)) {
            $warnings = $rawW;
        } elseif (is_string($rawW) && $rawW !== '') {
            $warnings = [$rawW];
        }
        if (is_array($rawR)) {
            $reasonCodes = $rawR;
        } elseif (is_string($rawR) && $rawR !== '') {
            $reasonCodes = [$rawR];
        }

        $requireSetupBreak = (bool)($config['double_bottom_early_fail_require_setup_break'] ?? true);
        $setupBreakReason  = null;
        $diagnosticNotes   = [];

        // ── Condition A: Neckline/reclaim level lost ──────────────────────────
        $neckBreakPct  = (float)($config['double_bottom_early_fail_neckline_break_pct'] ?? 0.35) / 100.0;
        $reclBreakPct  = (float)($config['double_bottom_early_fail_reclaim_break_pct']  ?? 0.35) / 100.0;

        if ($setupBreakReason === null && $necklineLevel !== null) {
            $threshold = $necklineLevel * (1.0 - $neckBreakPct);
            if ($currentPrice < $threshold) {
                $setupBreakReason = 'neckline_lost';
            }
        }
        if ($setupBreakReason === null && $reclaimLevel !== null) {
            $threshold = $reclaimLevel * (1.0 - $reclBreakPct);
            if ($currentPrice < $threshold) {
                $setupBreakReason = 'reclaim_level_lost';
            }
        }

        // ── Condition B: Entry structure broken ───────────────────────────────
        if ($setupBreakReason === null && $roi <= $softThreshold) {
            $entryBreakPct = (float)($config['double_bottom_early_fail_entry_break_pct'] ?? 1.8) / 100.0;
            $threshold     = $entryPrice * (1.0 - $entryBreakPct);
            if ($currentPrice < $threshold) {
                $setupBreakReason = 'entry_structure_broken';
            }
        }

        // ── Condition C: Fast dump (requires per-tick price history — skipped) ─
        // The stop_manager reads active_positions.json which carries the latest
        // current_price snapshot but not historical per-tick prices.  Condition C
        // cannot be evaluated reliably without a price history store.
        $diagnosticNotes[] = 'condition_c_fast_dump_skipped_no_price_history';

        // ── Condition D: Hard adverse ROI with trace warning ──────────────────
        if ($setupBreakReason === null && $roi <= $hardThreshold) {
            $adverseWarnings = [
                'generic_entry_context_score_low',
                'final_context_inconsistent_warning',
                'final_trend_mismatch_warning',
                'late_good_setup',
                'missed_ideal_entry',
            ];
            $allCodes = array_merge($warnings, $reasonCodes);
            foreach ($adverseWarnings as $aw) {
                if (in_array($aw, $allCodes, true)) {
                    $setupBreakReason = 'hard_roi_with_trace_warning:' . $aw;
                    break;
                }
            }
        }

        // ── Evaluate result ───────────────────────────────────────────────────
        if ($setupBreakReason === null) {
            // No setup break found
            $skipReason = $requireSetupBreak
                ? 'no_setup_break_detected'
                : 'adverse_roi_only_no_setup_break';
            return array_merge($base, [
                'skip_reason'   => $skipReason,
                'roi'           => $roi,
                'age_minutes'   => $ageMinutes,
                'current_price' => $currentPrice,
                'neckline_level'=> $necklineLevel,
                'reclaim_level' => $reclaimLevel,
                'diagnostic'    => implode(';', $diagnosticNotes),
            ]);
        }

        // Setup failure confirmed
        return array_merge($base, [
            'triggered'          => true,
            'skip_reason'        => '',
            'setup_break_reason' => $setupBreakReason,
            'roi'                => $roi,
            'age_minutes'        => $ageMinutes,
            'current_price'      => $currentPrice,
            'neckline_level'     => $necklineLevel,
            'reclaim_level'      => $reclaimLevel,
            'diagnostic'         => implode(';', $diagnosticNotes),
        ]);
    }

    /**
     * Submit a market close order for a demo position via Bybit Demo API.
     *
     * For a long position this places a Sell reduceOnly market order.
     * For a short position this places a Buy reduceOnly market order.
     *
     * Returns a result array with:
     *   ok         bool    — true when the order was accepted by the exchange
     *   symbol     string
     *   qty        float
     *   ret_code   int
     *   ret_msg    string
     *   note       string  — 'close_submitted' | 'close_failed' | 'missing_symbol_or_size' | 'exception'
     *   order_id   string|null
     */
    private function submitDemoCloseOrder(
        \Core\Gateway\Bybit $gw,
        array $pos,
        string $closeReason,
        string $closeGuard,
        string $tickAt
    ): array {
        $symbol = (string)($pos['symbol'] ?? '');
        $side   = (string)($pos['side']   ?? 'long');
        $size   = (float)($pos['size']    ?? 0.0);

        if ($symbol === '' || $size <= 0.0) {
            return [
                'ok'       => false,
                'symbol'   => $symbol,
                'qty'      => $size,
                'ret_code' => -1,
                'ret_msg'  => 'missing_symbol_or_size',
                'note'     => 'missing_symbol_or_size',
                'order_id' => null,
            ];
        }

        // Opposite side to close the long/short position
        $closeSide = ($side === 'long') ? 'Sell' : 'Buy';
        $qtyStr    = rtrim(rtrim(number_format($size, 8, '.', ''), '0'), '.');

        try {
            $resp = $gw->request('/v5/order/create', [
                'category'    => 'linear',
                'symbol'      => $symbol,
                'side'        => $closeSide,
                'orderType'   => 'Market',
                'qty'         => $qtyStr,
                'reduceOnly'  => true,
                'positionIdx' => 0,
            ], true);
        } catch (\Throwable $ex) {
            return [
                'ok'       => false,
                'symbol'   => $symbol,
                'qty'      => $size,
                'ret_code' => -1,
                'ret_msg'  => $ex->getMessage(),
                'note'     => 'exception',
                'order_id' => null,
            ];
        }

        $retCode = (int)($resp['ret_code'] ?? -1);
        $retMsg  = (string)($resp['ret_msg'] ?? '');
        $ok      = ($resp['success'] ?? false) && $retCode === 0;

        return [
            'ok'       => $ok,
            'symbol'   => $symbol,
            'qty'      => $size,
            'ret_code' => $retCode,
            'ret_msg'  => $retMsg,
            'note'     => $ok ? 'close_submitted' : 'close_failed',
            'order_id' => $ok ? ($resp['result']['orderId'] ?? null) : null,
        ];
    }

    /**
     * Write an early-fail close registry entry so the bot's recordClosedTrade()
     * can preserve Stop Manager attribution instead of falling back to
     * exchange_disappeared when the position disappears from Bybit Demo.
     *
     * Registry is written to the bot module's runtime directory so the bot can
     * read it without any cross-module import.  Path mirrors pm_close_registry.json.
     *
     * TTL: 2 hours (7200 s).  Entry is marked consumed after first attribution read.
     */
    private function writeEfCloseRegistry(
        array  $pos,
        array  $closeResult,
        array  $efResult,
        string $closeReason,
        array  $config,
        string $tickAt
    ): void {
        try {
            $botRelDir = (string)($config['bot_module_dir'] ?? 'modules/bot');
            $botDir    = str_starts_with($botRelDir, '/')
                ? rtrim($botRelDir, '/')
                : $this->repoRoot . '/' . rtrim($botRelDir, '/');

            $registryPath = $botDir . '/storage/runtime/ef_close_registry.json';

            $registryDir = dirname($registryPath);
            if (!is_dir($registryDir)) {
                mkdir($registryDir, 0755, true);
            }

            $registry = [];
            if (is_file($registryPath)) {
                $raw = @file_get_contents($registryPath);
                if ($raw !== false && $raw !== '') {
                    $dec = @json_decode($raw, true);
                    if (is_array($dec)) {
                        $registry = $dec;
                    }
                }
            }

            $symbol   = (string)($pos['symbol']    ?? '');
            $side     = (string)($pos['side']      ?? 'long');
            $signalId = (string)($pos['signal_id'] ?? '');
            // Include normalized mode in the registry key so demo and live entries
            // never collide, and so the bot can look up by the same mode+symbol+side key.
            $posMode  = $this->normalizeExecMode((string)($pos['execution_mode'] ?? 'demo'));
            $key      = $posMode . '_' . $symbol . '_' . $side;
            $nowTs    = time();
            $ttl      = 7200; // 2 hours

            // Prune expired entries before writing
            foreach ($registry as $k => $entry) {
                $entryTs = (int)($entry['ts'] ?? 0);
                if ($entryTs > 0 && ($nowTs - $entryTs) > $ttl) {
                    unset($registry[$k]);
                }
            }

            $registry[$key] = [
                'mode'                       => $posMode,
                'symbol'                     => $symbol,
                'side'                       => $side,
                'signal_id'                  => $signalId,
                'strategy_id'                => 'double_bottom_long',
                'owner_strategy'             => (string)($pos['owner_strategy'] ?? 'double_bottom_long'),
                'close_source'               => 'stop_manager',
                'close_guard'                => 'double_bottom_early_fail',
                'close_reason'               => $closeReason,
                'setup_break_reason'         => $efResult['setup_break_reason'] ?? null,
                'close_order_id'             => $closeResult['order_id'] ?? null,
                'close_submitted_at'         => $tickAt,
                'close_ok'                   => $closeResult['ok'] ?? false,
                'close_ret_code'             => $closeResult['ret_code'] ?? null,
                'close_ret_msg'              => $closeResult['ret_msg']  ?? null,
                'roi_at_close'               => $efResult['roi']         ?? null,
                'entry_price'                => isset($pos['entry_price'])   ? (float)$pos['entry_price']   : null,
                'current_price'              => $efResult['current_price']   ?? null,
                'strategy_signal_context'    => $pos['strategy_signal_context'] ?? null,
                'ts'                         => $nowTs,
                'expires_at'                 => date('c', $nowTs + $ttl),
                'close_attribution_consumed' => false,
            ];

            @file_put_contents(
                $registryPath,
                json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
                LOCK_EX
            );
        } catch (\Throwable) {
            // Never crash the tick over registry write failures
        }
    }

    // =========================================================================
    // Short emergency stop guard
    // =========================================================================

    /**
     * Check whether a short position's ROI has breached the emergency-stop cap.
     *
     * Demo-only.  Never affects live positions.
     * Uses the shared normalizePositionPrices / normalizePositionRoi helpers.
     *
     * ROI formula for short: ((entry_price - current_price) / entry_price) * leverage * 100
     * A short loses when price goes up, so adverse ROI is negative.
     *
     * @return array{
     *   triggered: bool,
     *   skip_reason: string,
     *   normalized_roi: float|null,
     *   current_price: float|null,
     *   entry_price: float|null,
     *   leverage: int,
     *   age_minutes: float|null,
     * }
     */
    private function checkShortEmergencyStop(array $pos, array $shortProfile, string $tickAt): array
    {
        $base = [
            'triggered'      => false,
            'skip_reason'    => '',
            'normalized_roi' => null,
            'current_price'  => null,
            'entry_price'    => null,
            'leverage'       => 1,
            'age_minutes'    => null,
        ];

        // Only demo positions
        $posMode = $this->normalizeExecMode((string)($pos['execution_mode'] ?? 'demo'));
        if ($posMode !== 'demo') {
            return array_merge($base, ['skip_reason' => 'not_demo_position']);
        }

        // Strategy filter
        $appliesTo  = (array)($shortProfile['applies_to_strategies'] ?? ['dynamic_strategies']);
        $posStratId = (string)($pos['strategy_id'] ?? $pos['owner_strategy'] ?? '');
        if (!in_array('*', $appliesTo, true) && !in_array($posStratId, $appliesTo, true)) {
            return array_merge($base, ['skip_reason' => 'strategy_not_allowed']);
        }

        // Minimum age gate
        $minAgeSec  = max(0, (int)($shortProfile['min_age_seconds'] ?? 60));
        $openedAt   = (string)($pos['opened_at'] ?? '');
        $openedTs   = $openedAt !== '' ? (int)strtotime($openedAt) : 0;
        $ageSec     = $openedTs > 0 ? max(0, time() - $openedTs) : 0;
        $ageMinutes = round($ageSec / 60.0, 1);

        if ($ageSec < $minAgeSec) {
            return array_merge($base, [
                'skip_reason' => 'too_young',
                'age_minutes' => $ageMinutes,
            ]);
        }

        // Normalize prices and ROI (short-aware via calcRoi)
        $priceNorm = $this->normalizePositionPrices($pos);
        $roiNorm   = $this->normalizePositionRoi($pos, $priceNorm);
        $roi       = $roiNorm['normalized_roi'];

        if ($roi === null) {
            return array_merge($base, [
                'skip_reason' => 'short_stop_missing_roi',
                'age_minutes' => $ageMinutes,
                'entry_price' => $priceNorm['normalized_entry_price'],
                'leverage'    => $priceNorm['normalized_leverage'],
            ]);
        }

        $emergencyRoi = (float)($shortProfile['emergency_stop_roi'] ?? -20.0);

        if ($roi > $emergencyRoi) {
            return array_merge($base, [
                'skip_reason'    => 'roi_above_cap',
                'normalized_roi' => $roi,
                'current_price'  => $priceNorm['normalized_current_price'],
                'entry_price'    => $priceNorm['normalized_entry_price'],
                'leverage'       => $priceNorm['normalized_leverage'],
                'age_minutes'    => $ageMinutes,
            ]);
        }

        return array_merge($base, [
            'triggered'      => true,
            'skip_reason'    => '',
            'normalized_roi' => $roi,
            'current_price'  => $priceNorm['normalized_current_price'],
            'entry_price'    => $priceNorm['normalized_entry_price'],
            'leverage'       => $priceNorm['normalized_leverage'],
            'age_minutes'    => $ageMinutes,
        ]);
    }

    /**
     * Write a short-emergency-stop close registry entry so the bot's recordClosedTrade()
     * can preserve Stop Manager attribution when the short position disappears from Bybit Demo.
     *
     * Reuses the same ef_close_registry.json file as the early-fail guard.
     * Key format: {mode}_{symbol}_short  (e.g. demo_BTCUSDT_short).
     * TTL: 2 hours (7200 s).
     */
    private function writeShortEmergencyStopRegistry(
        array  $pos,
        array  $closeResult,
        array  $ssResult,
        string $closeReason,
        string $closeGuard,
        array  $config,
        string $tickAt
    ): void {
        try {
            $botRelDir = (string)($config['bot_module_dir'] ?? 'modules/bot');
            $botDir    = str_starts_with($botRelDir, '/')
                ? rtrim($botRelDir, '/')
                : $this->repoRoot . '/' . rtrim($botRelDir, '/');

            $registryPath = $botDir . '/storage/runtime/ef_close_registry.json';

            $registryDir = dirname($registryPath);
            if (!is_dir($registryDir)) {
                mkdir($registryDir, 0755, true);
            }

            $registry = [];
            if (is_file($registryPath)) {
                $raw = @file_get_contents($registryPath);
                if ($raw !== false && $raw !== '') {
                    $dec = @json_decode($raw, true);
                    if (is_array($dec)) {
                        $registry = $dec;
                    }
                }
            }

            $symbol   = (string)($pos['symbol']    ?? '');
            $signalId = (string)($pos['signal_id'] ?? '');
            $posMode  = $this->normalizeExecMode((string)($pos['execution_mode'] ?? 'demo'));
            $key      = $posMode . '_' . $symbol . '_short';
            $nowTs    = time();
            $ttl      = 7200; // 2 hours

            // Prune expired entries before writing
            foreach ($registry as $k => $entry) {
                $entryTs = (int)($entry['ts'] ?? 0);
                if ($entryTs > 0 && ($nowTs - $entryTs) > $ttl) {
                    unset($registry[$k]);
                }
            }

            $ctx = is_array($pos['strategy_signal_context'] ?? null)
                ? $pos['strategy_signal_context']
                : null;

            $registry[$key] = [
                'mode'                       => $posMode,
                'symbol'                     => $symbol,
                'side'                       => 'short',
                'signal_id'                  => $signalId,
                'strategy_id'                => (string)($pos['strategy_id']  ?? $pos['owner_strategy'] ?? ''),
                'owner_strategy'             => (string)($pos['owner_strategy'] ?? ''),
                'close_source'               => 'stop_manager',
                'close_guard'                => $closeGuard,
                'close_reason'               => $closeReason,
                'close_order_id'             => $closeResult['order_id'] ?? null,
                'close_submitted_at'         => $tickAt,
                'close_ok'                   => $closeResult['ok'] ?? false,
                'close_ret_code'             => $closeResult['ret_code'] ?? null,
                'close_ret_msg'              => $closeResult['ret_msg']  ?? null,
                'roi_at_close'               => $ssResult['normalized_roi'] ?? null,
                'entry_price'                => $ssResult['entry_price']   ?? null,
                'current_price'              => $ssResult['current_price'] ?? null,
                'strategy_signal_context'    => $ctx,
                'ts'                         => $nowTs,
                'expires_at'                 => date('c', $nowTs + $ttl),
                'close_attribution_consumed' => false,
            ];

            @file_put_contents(
                $registryPath,
                json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
                LOCK_EX
            );
        } catch (\Throwable) {
            // Never crash the tick over registry write failures
        }
    }

    // =========================================================================
    // Long emergency stop guard
    // =========================================================================

    /**
     * Check whether a long position's ROI has breached the emergency-stop cap.
     *
     * Demo-only.  Never affects live positions.
     * Uses the shared normalizePositionPrices / normalizePositionRoi helpers.
     *
     * ROI formula for long: ((current_price - entry_price) / entry_price) * leverage * 100
     * A long loses when price goes down, so adverse ROI is negative.
     *
     * @return array{
     *   triggered: bool,
     *   skip_reason: string,
     *   normalized_roi: float|null,
     *   current_price: float|null,
     *   entry_price: float|null,
     *   leverage: int,
     *   age_minutes: float|null,
     * }
     */
    private function checkLongEmergencyStop(array $pos, array $longProfile, string $tickAt): array
    {
        $base = [
            'triggered'      => false,
            'skip_reason'    => '',
            'normalized_roi' => null,
            'current_price'  => null,
            'entry_price'    => null,
            'leverage'       => 1,
            'age_minutes'    => null,
        ];

        // Only demo positions
        $posMode = $this->normalizeExecMode((string)($pos['execution_mode'] ?? 'demo'));
        if ($posMode !== 'demo') {
            return array_merge($base, ['skip_reason' => 'not_demo_position']);
        }

        // Strategy filter
        $appliesTo  = (array)($longProfile['applies_to_strategies'] ?? ['double_bottom_long', '*']);
        $posStratId = (string)($pos['strategy_id'] ?? $pos['owner_strategy'] ?? '');
        if (!in_array('*', $appliesTo, true) && !in_array($posStratId, $appliesTo, true)) {
            return array_merge($base, ['skip_reason' => 'strategy_not_allowed']);
        }

        // Minimum age gate
        $minAgeSec  = max(0, (int)($longProfile['min_age_seconds'] ?? 60));
        $openedAt   = (string)($pos['opened_at'] ?? '');
        $openedTs   = $openedAt !== '' ? (int)strtotime($openedAt) : 0;
        $ageSec     = $openedTs > 0 ? max(0, time() - $openedTs) : 0;
        $ageMinutes = round($ageSec / 60.0, 1);

        if ($ageSec < $minAgeSec) {
            return array_merge($base, [
                'skip_reason' => 'too_young',
                'age_minutes' => $ageMinutes,
            ]);
        }

        // Normalize prices and ROI (long-aware via calcRoi)
        $priceNorm = $this->normalizePositionPrices($pos);
        $roiNorm   = $this->normalizePositionRoi($pos, $priceNorm);
        $roi       = $roiNorm['normalized_roi'];

        if ($roi === null) {
            return array_merge($base, [
                'skip_reason' => 'long_stop_missing_roi',
                'age_minutes' => $ageMinutes,
                'entry_price' => $priceNorm['normalized_entry_price'],
                'leverage'    => $priceNorm['normalized_leverage'],
            ]);
        }

        $emergencyRoi = (float)($longProfile['emergency_stop_roi'] ?? -30.0);

        if ($roi > $emergencyRoi) {
            return array_merge($base, [
                'skip_reason'    => 'roi_above_cap',
                'normalized_roi' => $roi,
                'current_price'  => $priceNorm['normalized_current_price'],
                'entry_price'    => $priceNorm['normalized_entry_price'],
                'leverage'       => $priceNorm['normalized_leverage'],
                'age_minutes'    => $ageMinutes,
            ]);
        }

        return array_merge($base, [
            'triggered'      => true,
            'skip_reason'    => '',
            'normalized_roi' => $roi,
            'current_price'  => $priceNorm['normalized_current_price'],
            'entry_price'    => $priceNorm['normalized_entry_price'],
            'leverage'       => $priceNorm['normalized_leverage'],
            'age_minutes'    => $ageMinutes,
        ]);
    }

    /**
     * Write a long-emergency-stop close registry entry so the bot's recordClosedTrade()
     * can preserve Stop Manager attribution when the long position disappears from Bybit Demo.
     *
     * Reuses the same ef_close_registry.json file as the early-fail guard.
     * Key format: {mode}_{symbol}_long  (e.g. demo_BTCUSDT_long).
     * TTL: 2 hours (7200 s).
     */
    private function writeLongEmergencyStopRegistry(
        array  $pos,
        array  $closeResult,
        array  $lsResult,
        string $closeReason,
        string $closeGuard,
        array  $config,
        string $tickAt
    ): void {
        try {
            $botRelDir = (string)($config['bot_module_dir'] ?? 'modules/bot');
            $botDir    = str_starts_with($botRelDir, '/')
                ? rtrim($botRelDir, '/')
                : $this->repoRoot . '/' . rtrim($botRelDir, '/');

            $registryPath = $botDir . '/storage/runtime/ef_close_registry.json';

            $registryDir = dirname($registryPath);
            if (!is_dir($registryDir)) {
                mkdir($registryDir, 0755, true);
            }

            $registry = [];
            if (is_file($registryPath)) {
                $raw = @file_get_contents($registryPath);
                if ($raw !== false && $raw !== '') {
                    $dec = @json_decode($raw, true);
                    if (is_array($dec)) {
                        $registry = $dec;
                    }
                }
            }

            $symbol   = (string)($pos['symbol']    ?? '');
            $signalId = (string)($pos['signal_id'] ?? '');
            $posMode  = $this->normalizeExecMode((string)($pos['execution_mode'] ?? 'demo'));
            $key      = $posMode . '_' . $symbol . '_long';
            $nowTs    = time();
            $ttl      = 7200; // 2 hours

            // Prune expired entries before writing
            foreach ($registry as $k => $entry) {
                $entryTs = (int)($entry['ts'] ?? 0);
                if ($entryTs > 0 && ($nowTs - $entryTs) > $ttl) {
                    unset($registry[$k]);
                }
            }

            $ctx = is_array($pos['strategy_signal_context'] ?? null)
                ? $pos['strategy_signal_context']
                : null;

            $registry[$key] = [
                'mode'                       => $posMode,
                'symbol'                     => $symbol,
                'side'                       => 'long',
                'signal_id'                  => $signalId,
                'strategy_id'                => (string)($pos['strategy_id']  ?? $pos['owner_strategy'] ?? ''),
                'owner_strategy'             => (string)($pos['owner_strategy'] ?? ''),
                'close_source'               => 'stop_manager',
                'close_guard'                => $closeGuard,
                'close_reason'               => $closeReason,
                'close_order_id'             => $closeResult['order_id'] ?? null,
                'close_submitted_at'         => $tickAt,
                'close_ok'                   => $closeResult['ok'] ?? false,
                'close_ret_code'             => $closeResult['ret_code'] ?? null,
                'close_ret_msg'              => $closeResult['ret_msg']  ?? null,
                'roi_at_close'               => $lsResult['normalized_roi'] ?? null,
                'entry_price'                => $lsResult['entry_price']   ?? null,
                'current_price'              => $lsResult['current_price'] ?? null,
                'strategy_signal_context'    => $ctx,
                'ts'                         => $nowTs,
                'expires_at'                 => date('c', $nowTs + $ttl),
                'close_attribution_consumed' => false,
            ];

            @file_put_contents(
                $registryPath,
                json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
                LOCK_EX
            );
        } catch (\Throwable) {
            // Never crash the tick over registry write failures
        }
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
