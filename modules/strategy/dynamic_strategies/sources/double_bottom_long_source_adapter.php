<?php

declare(strict_types=1);

/**
 * Dynamic Strategies — DoubleBottomLong Source Adapter
 *
 * Reads existing storage artifacts from the double_bottom_long strategy module
 * and emits normalized Dynamic context records for directional / bearish signals.
 *
 * Reads (read-only — never modifies):
 *   1. {module_path}/storage/last_run.json
 *      Extracts: rejected_signal_examples, pending_confirmation_invalidated_examples,
 *                calibration_deep_loss_examples, late_good_setup_examples,
 *                setup_allowed_quality_failed_examples
 *
 *   2. {module_path}/storage/pending_confirmations.json
 *      Extracts: active pending entries (as diagnostic context)
 *
 *   3. {bot_module_dir}/storage/trades/closed_trades.json
 *      Filter: strategy_id / owner_strategy = 'double_bottom_long'
 *      Extracts: deep_loss_closed (roi <= -30), early_fail_closed, emergency_stop_closed
 *
 *   4. {bot_module_dir}/storage/last_run.json
 *      Extracts: diagnostic example arrays if present
 *
 *   5. {stop_manager_module_dir}/storage/last_run.json
 *      Extracts: double_bottom early_fail / emergency_stop triggered examples
 *
 * Missing files are handled gracefully — returns empty contexts and diagnostic stats.
 *
 * SAFETY:
 *   - Read-only. Does not write to any source strategy storage.
 *   - All returned contexts have diagnostic_only = true.
 *   - Does not open orders, does not call exchange APIs.
 */

namespace Modules\Strategy\DynamicStrategies\Sources;

final class DoubleBottomLongSourceAdapter extends AbstractDynamicSourceAdapter
{
    private const SOURCE_STRATEGY = 'double_bottom_long';
    private const SOURCE_MODULE   = 'modules/strategy/pattern/double_bottom_long';

    // Max examples to import per artifact per run to avoid memory overload
    private const MAX_PER_ARTIFACT = 50;

    // Deep-loss threshold (ROI % at which a closed trade is classified as deep_loss)
    private const DEEP_LOSS_ROI_THRESHOLD = -30.0;

    // Max age in minutes for a last_run.json tick to be considered fresh
    private const MAX_LAST_RUN_AGE_MINUTES = 480;

    public function id(): string
    {
        return self::SOURCE_STRATEGY;
    }

    public function enabled(array $config): bool
    {
        $srcConfig = $config['sources'][self::SOURCE_STRATEGY] ?? [];
        return (bool)($srcConfig['enabled'] ?? true);
    }

    /**
     * Collect contexts from all configured double_bottom_long artifacts.
     */
    public function collect(array $config): array
    {
        $srcConfig = $config['sources'][self::SOURCE_STRATEGY] ?? [];

        $modulePath       = $this->repoRoot . '/' . ltrim(
            (string)($srcConfig['module_path'] ?? self::SOURCE_MODULE), '/'
        );
        $botModuleDir     = $this->repoRoot . '/' . ltrim(
            (string)($config['bot_module_dir'] ?? 'modules/bot'), '/'
        );
        $stopManagerDir   = $this->repoRoot . '/' . ltrim(
            (string)($config['stop_manager_module_dir'] ?? 'modules/stop_manager'), '/'
        );

        $maxAgeMin  = (int)($srcConfig['context_max_age_minutes'] ?? self::MAX_LAST_RUN_AGE_MINUTES);
        $maxItems   = (int)($srcConfig['max_items_per_run']       ?? 200);

        $contexts = [];
        $errors   = [];
        $stats    = [
            'enabled'                     => true,
            'files_read_total'            => 0,
            'files_missing_total'         => 0,
            'contexts_loaded_total'       => 0,
            'contexts_useful_total'       => 0,
            'contexts_skipped_noise_total' => 0,
            'errors_total'               => 0,
        ];

        $now = time();

        // ── 1. double_bottom_long/storage/last_run.json ────────────────────────
        if ((bool)($srcConfig['read_last_run'] ?? true)) {
            $path = $modulePath . '/storage/last_run.json';
            $lr   = $this->readJsonFile($path, null);
            if ($lr === null) {
                $stats['files_missing_total']++;
            } else {
                $stats['files_read_total']++;

                // Use tick_at from last_run.json as observed_at for examples
                $tickAt = (string)($lr['tick_at'] ?? $lr['finished_at'] ?? $lr['started_at'] ?? '');
                if ($tickAt === '') {
                    $tickAt = date('c');
                }
                $tickTs = (int)strtotime($tickAt);

                // Only use if recent enough
                $tooOld = $maxAgeMin > 0 && $tickTs > 0 && ($now - $tickTs) > ($maxAgeMin * 60);

                if (!$tooOld) {
                    // rejected_signal_examples
                    $rejected = (array)($lr['rejected_signal_examples'] ?? []);
                    $new = $this->extractLastRunRejectExamples($rejected, $tickAt, 'last_run.rejected_signal_examples');
                    $contexts = array_merge($contexts, $new);
                    $stats['contexts_loaded_total'] += count($new);

                    // setup_allowed_quality_failed_examples
                    $qaFailed = (array)($lr['setup_allowed_quality_failed_examples'] ?? []);
                    $new = $this->extractLastRunRejectExamples($qaFailed, $tickAt, 'last_run.setup_allowed_quality_failed_examples');
                    $contexts = array_merge($contexts, $new);
                    $stats['contexts_loaded_total'] += count($new);

                    // pending_confirmation_invalidated_examples
                    $pendInv = (array)($lr['pending_confirmation_invalidated_examples'] ?? []);
                    $new = $this->extractPendingInvalidatedExamples($pendInv, $tickAt, 'last_run.pending_confirmation_invalidated_examples');
                    $contexts = array_merge($contexts, $new);
                    $stats['contexts_loaded_total'] += count($new);

                    // calibration_deep_loss_examples
                    $deepLoss = (array)($lr['calibration_deep_loss_examples'] ?? []);
                    $new = $this->extractDeepLossExamples($deepLoss, $tickAt, 'last_run.calibration_deep_loss_examples');
                    $contexts = array_merge($contexts, $new);
                    $stats['contexts_loaded_total'] += count($new);
                }
            }
        }

        // ── 2. double_bottom_long/storage/pending_confirmations.json ──────────
        if ((bool)($srcConfig['read_pending'] ?? true)) {
            $path    = $modulePath . '/storage/pending_confirmations.json';
            $pending = $this->readJsonFile($path, null);
            if ($pending === null) {
                $stats['files_missing_total']++;
            } else {
                $stats['files_read_total']++;
                $new = $this->extractActivePendingContexts((array)$pending);
                $contexts = array_merge($contexts, $new);
                $stats['contexts_loaded_total'] += count($new);
            }
        }

        // ── 3. bot/storage/trades/closed_trades.json ──────────────────────────
        if ((bool)($srcConfig['read_closed_trades'] ?? true)) {
            $path   = $botModuleDir . '/storage/trades/closed_trades.json';
            $trades = $this->readJsonFile($path, null);
            if ($trades === null) {
                $stats['files_missing_total']++;
            } else {
                $stats['files_read_total']++;
                $new = $this->extractClosedTradeContexts(
                    (array)$trades,
                    $maxAgeMin,
                    $now,
                    self::MAX_PER_ARTIFACT
                );
                $contexts = array_merge($contexts, $new);
                $stats['contexts_loaded_total'] += count($new);
            }
        }

        // ── 4. bot/storage/last_run.json ──────────────────────────────────────
        if ((bool)($srcConfig['read_bot_last_run'] ?? true)) {
            $path = $botModuleDir . '/storage/last_run.json';
            $blr  = $this->readJsonFile($path, null);
            if ($blr === null) {
                $stats['files_missing_total']++;
            } else {
                $stats['files_read_total']++;
                $tickAt = (string)($blr['tick_at'] ?? date('c'));
                $tickTs = (int)strtotime($tickAt);
                $tooOld = $maxAgeMin > 0 && $tickTs > 0 && ($now - $tickTs) > ($maxAgeMin * 60);
                if (!$tooOld) {
                    // Extract any double_bottom_long deep_loss / early_fail_missed examples
                    foreach ([
                        'double_bottom_deep_loss_examples'         => 'deep_loss_closed',
                        'double_bottom_early_fail_missed_examples' => 'missed_early_fail',
                    ] as $key => $ctxType) {
                        $examples = (array)($blr[$key] ?? []);
                        $new = $this->extractBotLastRunExamples($examples, $ctxType, $tickAt, "bot_last_run.{$key}");
                        $contexts = array_merge($contexts, $new);
                        $stats['contexts_loaded_total'] += count($new);
                    }
                }
            }
        }

        // ── 5. stop_manager/storage/last_run.json ─────────────────────────────
        if ((bool)($srcConfig['read_stop_manager_last_run'] ?? true)) {
            $path = $stopManagerDir . '/storage/last_run.json';
            $slr  = $this->readJsonFile($path, null);
            if ($slr === null) {
                $stats['files_missing_total']++;
            } else {
                $stats['files_read_total']++;
                $tickAt = (string)($slr['tick_at'] ?? date('c'));
                $tickTs = (int)strtotime($tickAt);
                $tooOld = $maxAgeMin > 0 && $tickTs > 0 && ($now - $tickTs) > ($maxAgeMin * 60);
                if (!$tooOld) {
                    foreach ([
                        'double_bottom_early_fail_triggered_examples'    => 'early_fail_closed',
                        'double_bottom_emergency_stop_triggered_examples' => 'emergency_stop_closed',
                    ] as $key => $ctxType) {
                        $examples = (array)($slr[$key] ?? []);
                        $new = $this->extractBotLastRunExamples($examples, $ctxType, $tickAt, "stop_manager_last_run.{$key}");
                        $contexts = array_merge($contexts, $new);
                        $stats['contexts_loaded_total'] += count($new);
                    }
                }
            }
        }

        // ── Filter to useful only + cap ────────────────────────────────────────
        $usefulContexts  = [];
        $noiseSkipped    = 0;
        foreach ($contexts as $ctx) {
            if ($this->isUsefulContextType((string)($ctx['context_type'] ?? ''))) {
                $usefulContexts[] = $ctx;
            } else {
                $noiseSkipped++;
            }
        }
        $stats['contexts_skipped_noise_total'] = $noiseSkipped;

        // Cap to max_items_per_run (keep newest by observed_at)
        if (count($usefulContexts) > $maxItems) {
            usort($usefulContexts, static function (array $a, array $b): int {
                $ta = isset($a['observed_at']) ? (int)strtotime((string)$a['observed_at']) : 0;
                $tb = isset($b['observed_at']) ? (int)strtotime((string)$b['observed_at']) : 0;
                return $tb - $ta; // newest first
            });
            $usefulContexts = array_slice($usefulContexts, 0, $maxItems);
        }

        $stats['contexts_useful_total'] = count($usefulContexts);
        $stats['errors_total']          = count($errors);

        return [
            'contexts' => $usefulContexts,
            'stats'    => $stats,
            'errors'   => $errors,
        ];
    }

    // ── Private extraction helpers ────────────────────────────────────────────

    /**
     * Extract directional contexts from rejected_signal_examples / setup_allowed_quality_failed_examples
     * keys in double_bottom_long/last_run.json.
     */
    private function extractLastRunRejectExamples(array $examples, string $tickAt, string $artifact): array
    {
        $out = [];
        foreach (array_slice($examples, 0, self::MAX_PER_ARTIFACT) as $ex) {
            if (!is_array($ex) || empty($ex['symbol'])) {
                continue;
            }
            $symbol      = (string)$ex['symbol'];
            $rejectReason = (string)($ex['reject_reason'] ?? $ex['kill_reason'] ?? $ex['reason'] ?? '');
            $ctxType     = $this->reasonToContextType($rejectReason);
            if ($ctxType === null) {
                continue; // not a directional reject
            }
            $ctx                          = $this->makeContext(
                $ctxType, $symbol, self::SOURCE_STRATEGY, self::SOURCE_MODULE, $artifact, $tickAt
            );
            $ctx['setup_class']           = $ex['setup_class']           ?? null;
            $ctx['synthetic_quality_score'] = isset($ex['synthetic_quality_score']) ? (float)$ex['synthetic_quality_score'] : null;
            $ctx['candidate_quality_score'] = isset($ex['candidate_quality_score']) ? (float)$ex['candidate_quality_score'] : null;
            $ctx['failed_stage']          = $ex['failed_stage']          ?? null;
            $ctx['reject_reason']         = $rejectReason;
            $ctx['warnings']              = (array)($ex['warnings']      ?? []);
            $ctx['reason_codes']          = (array)($ex['reason_codes']  ?? []);
            $out[] = $ctx;
        }
        return $out;
    }

    /**
     * Extract contexts from pending_confirmation_invalidated_examples.
     */
    private function extractPendingInvalidatedExamples(array $examples, string $fallbackTickAt, string $artifact): array
    {
        $out = [];
        foreach (array_slice($examples, 0, self::MAX_PER_ARTIFACT) as $ex) {
            if (!is_array($ex) || empty($ex['symbol'])) {
                continue;
            }
            $symbol = (string)$ex['symbol'];
            $reason = (string)($ex['pending_recheck_reason'] ?? $ex['reason'] ?? $ex['pending_invalidated_reason'] ?? '');
            $ctxType = $this->reasonToContextType($reason);
            if ($ctxType === null) {
                continue;
            }
            // Prefer the recheck timestamp; fall back to tick time
            $observedAt = (string)($ex['last_rechecked_at'] ?? $ex['pending_created_at'] ?? $fallbackTickAt);
            if ($observedAt === '') {
                $observedAt = $fallbackTickAt;
            }
            $ctx                     = $this->makeContext(
                $ctxType, $symbol, self::SOURCE_STRATEGY, self::SOURCE_MODULE, $artifact, $observedAt
            );
            $ctx['setup_class']      = $ex['setup_class']   ?? null;
            $ctx['neckline_level']   = isset($ex['neckline_price'])   ? (float)$ex['neckline_price']   : null;
            $ctx['reclaim_level']    = isset($ex['neckline_price'])   ? (float)$ex['neckline_price']   : null;
            $ctx['current_price']    = isset($ex['current_price'])    ? (float)$ex['current_price']    : null;
            $ctx['reject_reason']    = $reason;
            // Boolean flags carried as bool fields for rule evaluation
            if (!empty($ex['reclaim_lost']))         { $ctx['reclaim_lost']         = true; }
            if (!empty($ex['base_support_broken']))  { $ctx['base_support_broken']  = true; }
            if (!empty($ex['fresh_dump_detected']))  { $ctx['falling_knife']        = true; }
            $out[] = $ctx;
        }
        return $out;
    }

    /**
     * Extract directional context records from calibration_deep_loss_examples
     * in double_bottom_long last_run.json.
     */
    private function extractDeepLossExamples(array $examples, string $fallbackTickAt, string $artifact): array
    {
        $out = [];
        foreach (array_slice($examples, 0, self::MAX_PER_ARTIFACT) as $ex) {
            if (!is_array($ex) || empty($ex['symbol'])) {
                continue;
            }
            $symbol     = (string)$ex['symbol'];
            $roi        = isset($ex['roi']) ? (float)$ex['roi'] : null;
            $closeGuard = (string)($ex['close_guard'] ?? '');
            $closedAt   = (string)($ex['closed_at']   ?? $fallbackTickAt);
            if ($closedAt === '') {
                $closedAt = $fallbackTickAt;
            }

            $ctxType = null;
            if ($closeGuard !== '' && str_contains($closeGuard, 'emergency_stop')) {
                $ctxType = 'emergency_stop_closed';
            } elseif ($closeGuard !== '' && str_contains($closeGuard, 'early_fail')) {
                $ctxType = 'early_fail_closed';
            } elseif ($roi !== null && $roi <= self::DEEP_LOSS_ROI_THRESHOLD) {
                $ctxType = 'deep_loss_closed';
            }
            if ($ctxType === null) {
                continue;
            }

            $signalId = (string)($ex['signal_id'] ?? '');

            $ctx                    = $this->makeContext(
                $ctxType, $symbol, self::SOURCE_STRATEGY, self::SOURCE_MODULE, $artifact, $closedAt,
                $signalId !== '' ? $signalId : null
            );
            $ctx['entry_price']     = isset($ex['entry_price']) ? (float)$ex['entry_price'] : null;
            $ctx['current_price']   = isset($ex['exit_price'])  ? (float)$ex['exit_price']  : null;
            $ctx['close_roi']       = $roi;
            $ctx['close_reason']    = $ex['close_reason'] ?? null;
            $ctx['close_guard']     = $closeGuard !== '' ? $closeGuard : null;
            $ctx['setup_class']     = $ex['setup_class'] ?? null;
            $out[] = $ctx;
        }
        return $out;
    }

    /**
     * Extract contexts from active pending_confirmations.json entries.
     * These are long setups that are still waiting for confirmation — useful as
     * diagnostic context but not directional short signals by themselves.
     * Skipped unless the pending entry has directional flags set.
     */
    private function extractActivePendingContexts(array $pending): array
    {
        $out = [];
        foreach (array_slice($pending, 0, self::MAX_PER_ARTIFACT) as $entry) {
            if (!is_array($entry) || empty($entry['symbol'])) {
                continue;
            }
            // Only emit a context if there is a directional breakdown flag already set
            $hasDirectional = (bool)($entry['support_broken'] ?? false)
                || (bool)($entry['reclaim_lost'] ?? false)
                || (bool)($entry['fresh_dump_detected'] ?? false)
                || (bool)($entry['falling_knife'] ?? false);
            if (!$hasDirectional) {
                continue;
            }
            $symbol     = (string)$entry['symbol'];
            $createdAt  = (string)($entry['created_at'] ?? date('c'));
            // Pick most specific context type
            if (!empty($entry['falling_knife'])) {
                $ctxType = 'pending_invalidated_falling_knife';
            } elseif (!empty($entry['fresh_dump_detected'])) {
                $ctxType = 'pending_invalidated_fresh_dump';
            } elseif (!empty($entry['reclaim_lost'])) {
                $ctxType = 'pending_invalidated_reclaim_lost';
            } else {
                $ctxType = 'pending_invalidated_base_support_broken';
            }

            $ctx                   = $this->makeContext(
                $ctxType, $symbol, self::SOURCE_STRATEGY, self::SOURCE_MODULE,
                'pending_confirmations.json', $createdAt
            );
            $ctx['setup_class']    = $entry['setup_class'] ?? null;
            $ctx['neckline_level'] = isset($entry['neckline_level']) ? (float)$entry['neckline_level'] : null;
            $ctx['reclaim_level']  = isset($entry['neckline_level']) ? (float)$entry['neckline_level'] : null;
            if (!empty($entry['reclaim_lost']))         { $ctx['reclaim_lost']         = true; }
            if (!empty($entry['support_broken']))       { $ctx['base_support_broken']  = true; }
            if (!empty($entry['falling_knife']))        { $ctx['falling_knife']        = true; }
            if (!empty($entry['fresh_dump_detected']))  { $ctx['falling_knife']        = true; }
            $out[] = $ctx;
        }
        return $out;
    }

    /**
     * Extract contexts from closed_trades.json, filtered to double_bottom_long.
     */
    private function extractClosedTradeContexts(
        array $trades,
        int   $maxAgeMin,
        int   $now,
        int   $maxItems
    ): array {
        $out    = [];
        $cutoff = $maxAgeMin > 0 ? $now - ($maxAgeMin * 60) : 0;

        foreach ($trades as $trade) {
            if (!is_array($trade)) {
                continue;
            }
            // Filter to double_bottom_long trades
            $stratId  = (string)($trade['strategy_id']    ?? $trade['owner_strategy'] ?? $trade['strategy'] ?? '');
            $ownerStr = (string)($trade['owner_strategy'] ?? '');
            if ($stratId !== self::SOURCE_STRATEGY && $ownerStr !== self::SOURCE_STRATEGY) {
                continue;
            }

            $symbol   = (string)($trade['symbol'] ?? '');
            if ($symbol === '') {
                continue;
            }

            // Age filter
            $closedAt   = (string)($trade['closed_at']   ?? $trade['exit_time'] ?? '');
            $closedTs   = $closedAt !== '' ? (int)strtotime($closedAt) : 0;
            if ($cutoff > 0 && $closedTs > 0 && $closedTs < $cutoff) {
                continue;
            }
            $observedAt = $closedAt !== '' ? $closedAt : date('c');

            $roi        = isset($trade['roi']) ? (float)$trade['roi'] : null;
            $closeGuard = (string)($trade['close_guard']  ?? '');
            $signalId   = (string)($trade['signal_id']    ?? '');

            $ctxType = null;
            if ($closeGuard !== '' && str_contains($closeGuard, 'emergency_stop')) {
                $ctxType = 'emergency_stop_closed';
            } elseif ($closeGuard !== '' && str_contains($closeGuard, 'early_fail')) {
                $ctxType = 'early_fail_closed';
            } elseif ($roi !== null && $roi <= self::DEEP_LOSS_ROI_THRESHOLD) {
                $ctxType = 'deep_loss_closed';
            }

            if ($ctxType === null) {
                continue;
            }

            $ctx                  = $this->makeContext(
                $ctxType, $symbol, self::SOURCE_STRATEGY, self::SOURCE_MODULE,
                'bot.closed_trades', $observedAt,
                $signalId !== '' ? $signalId : null
            );
            $ctx['entry_price']   = isset($trade['entry_price']) ? (float)$trade['entry_price'] : null;
            $ctx['current_price'] = isset($trade['exit_price'])  ? (float)$trade['exit_price']  : null;
            $ctx['close_roi']     = $roi;
            $ctx['close_reason']  = $trade['close_reason']  ?? null;
            $ctx['close_guard']   = $closeGuard !== '' ? $closeGuard : null;
            $ctx['setup_class']   = $trade['setup_class']   ?? null;
            $out[] = $ctx;

            if (count($out) >= $maxItems) {
                break;
            }
        }
        return $out;
    }

    /**
     * Extract contexts from bot or stop_manager last_run.json example arrays.
     */
    private function extractBotLastRunExamples(
        array  $examples,
        string $ctxType,
        string $tickAt,
        string $artifact
    ): array {
        $out = [];
        foreach (array_slice($examples, 0, self::MAX_PER_ARTIFACT) as $ex) {
            if (!is_array($ex) || empty($ex['symbol'])) {
                continue;
            }
            $symbol     = (string)$ex['symbol'];
            $observedAt = (string)($ex['closed_at'] ?? $ex['triggered_at'] ?? $ex['detected_at'] ?? $tickAt);
            if ($observedAt === '') {
                $observedAt = $tickAt;
            }
            $signalId   = (string)($ex['signal_id'] ?? '');
            $ctx                  = $this->makeContext(
                $ctxType, $symbol, self::SOURCE_STRATEGY, self::SOURCE_MODULE,
                $artifact, $observedAt, $signalId !== '' ? $signalId : null
            );
            $ctx['close_roi']     = isset($ex['roi'])          ? (float)$ex['roi']          : null;
            $ctx['close_reason']  = $ex['close_reason']        ?? null;
            $ctx['close_guard']   = $ex['close_guard']         ?? null;
            $ctx['entry_price']   = isset($ex['entry_price'])  ? (float)$ex['entry_price']  : null;
            $ctx['current_price'] = isset($ex['current_price']) ? (float)$ex['current_price'] : null;
            $out[] = $ctx;
        }
        return $out;
    }
}
