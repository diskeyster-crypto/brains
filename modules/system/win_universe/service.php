<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/win_universe_engine.php';

/**
 * WinUniverseService
 *
 * Service layer for the Win Universe module.
 * Delegates computation to WinUniverseEngine and persists runtime outputs.
 *
 * SHADOW MODE ONLY — does not affect Smart Brain, Trading Bot, or Profit Manager.
 *
 * Runtime outputs (under storage/runtime/):
 *   - win_universe_stats.json      — per-symbol raw stats
 *   - win_universe.json            — qualified + near-qualified + rejected lists with details
 *   - win_universe_status.json     — last run status / counters
 *   - win_universe_pool.json       — current win pool state (promoted symbols)
 *   - win_universe_promotions.json — history of promotion events
 *   - win_universe_demotions.json  — history of demotion events
 */
final class WinUniverseService
{
    private WinUniverseEngine $engine;

    /** Absolute path to this module's storage/runtime directory */
    private string $runtimeDir;

    /** Merged module config (defaults + user config overlay) */
    private array $config = [];

    /** Max promotion/demotion history entries to keep */
    private const MAX_HISTORY = 200;

    public function __construct()
    {
        $moduleBase = __DIR__;

        $this->runtimeDir = $moduleBase . '/storage/runtime';
        $this->config     = $this->loadConfig($moduleBase);
        $this->engine     = new WinUniverseEngine($moduleBase);
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Run the win universe computation and persist outputs.
     *
     * @return array<string,mixed> Result summary
     */
    public function run(): array
    {
        $startTime = microtime(true);
        $ts        = date('c');

        if (!($this->config['module']['enabled'] ?? true)) {
            return [
                'ok'       => true,
                'skipped'  => true,
                'reason'   => 'module_disabled',
                'run_at'   => $ts,
            ];
        }

        $wuConfig = $this->config['win_universe'] ?? [];

        if (!($wuConfig['win_universe_enabled'] ?? true)) {
            return [
                'ok'       => true,
                'skipped'  => true,
                'reason'   => 'win_universe_disabled',
                'run_at'   => $ts,
            ];
        }

        try {
            // Load previous win pool state for lifecycle continuity
            $prevPoolData = $this->loadJson('win_universe_pool.json');
            $prevPool     = $prevPoolData['win_pool'] ?? [];

            $result = $this->engine->compute($wuConfig, $prevPool);

            $this->ensureRuntimeDir();
            $this->persistOutputs($result, $ts);

            // Evaluation / attribution layer (best-effort, non-fatal)
            $evalData = null;
            try {
                $evalData = $this->computeEval($result, $ts);
                $this->saveJson('win_universe_eval.json', $evalData);
                $this->saveJson('win_universe_eval_by_status.json', [
                    'computed_at'    => $ts,
                    'attribution_note' => $evalData['attribution_note'],
                    'groups'         => $evalData['groups'],
                ]);
            } catch (\Throwable $ignored) {
                // non-fatal — evaluation failure must not break the main run
            }

            // Entry-status attribution evaluation (best-effort, non-fatal)
            $evalAtEntry = null;
            try {
                $evalAtEntry = $this->computeEvalAtEntry($ts);
                $this->saveJson('win_universe_eval_at_entry.json', $evalAtEntry);
                $this->saveJson('win_universe_eval_by_entry_status.json', [
                    'computed_at'      => $ts,
                    'attribution_note' => $evalAtEntry['attribution_note'],
                    'groups'           => $evalAtEntry['groups'],
                ]);
                $this->saveJson('win_universe_bonus_eval_at_entry.json', [
                    'computed_at'      => $ts,
                    'attribution_note' => $evalAtEntry['attribution_note'],
                    'bonus_groups'     => $evalAtEntry['bonus_groups'],
                    'intent_log_size'  => $evalAtEntry['intent_log_size'],
                ]);
            } catch (\Throwable $ignored) {
                // non-fatal
            }

            $elapsed = round(microtime(true) - $startTime, 3);

            // Build eval summary for status.json
            $evalSummary        = $this->buildEvalSummary($evalData);
            $evalAtEntrySummary = $this->buildEvalAtEntrySummary($evalAtEntry);

            $status = [
                'ok'                            => true,
                'skipped'                       => false,
                'run_at'                        => $ts,
                'elapsed_sec'                   => $elapsed,
                'symbols_seen'                  => count($result['symbols']),
                'qualified_count'               => count($result['qualified']),
                'near_qualified_count'          => count($result['near_qualified']),
                'rejected_count'                => count($result['rejected']),
                'win_pool_count'                => count($result['win_pool']),
                'promotions_this_run'           => count($result['promotions']),
                'demotions_this_run'            => count($result['demotions']),
                'trade_count_total'             => $result['trade_count_total'],
                'sources_used'                  => $result['sources_used'],
                'config_used'                   => $result['config_used'],
                'threshold_sensitivity'         => $result['threshold_sensitivity'],
                'threshold_diagnostics'         => [
                    'failed_by_min_trades_count'      => $result['threshold_sensitivity']['failed_by_min_trades_count']    ?? 0,
                    'failed_by_roi_threshold_count'   => $result['threshold_sensitivity']['failed_by_roi_threshold_count'] ?? 0,
                    'failed_by_avg_roi_count'         => $result['threshold_sensitivity']['failed_by_avg_roi_count']       ?? 0,
                    'failed_by_winrate_count'         => $result['threshold_sensitivity']['failed_by_winrate_count']       ?? 0,
                    'failed_by_target_wins_count'     => $result['threshold_sensitivity']['failed_by_target_wins_count']   ?? 0,
                    'failed_by_speed_count'           => $result['threshold_sensitivity']['failed_by_speed_count']         ?? 0,
                    'failed_by_missing_time_count'    => $result['threshold_sensitivity']['failed_by_missing_time_count']  ?? 0,
                ],
                'candidate_sensitivity_preview'         => $result['candidate_sensitivity_preview'] ?? [],
                'diagnostics_consistent'                => $result['diagnostics_consistent'] ?? true,
                'preview_current_matches_active'        => $result['preview_current_matches_active'] ?? true,
                'excessive_qualification_warning'       => $result['excessive_qualification_warning'] ?? false,
                'excessive_qualification_note'          => $result['excessive_qualification_note'] ?? null,
                'mode'                                  => $result['config_used']['win_universe_mode'] ?? 'shadow',
                'mode_source'                           => $this->config['_meta']['mode_source'] ?? 'config_defaults',
            ] + $evalSummary + $evalAtEntrySummary;

            $this->saveJson('win_universe_status.json', $status);

            return $status;

        } catch (\Throwable $e) {
            $errorStatus = [
                'ok'       => false,
                'skipped'  => false,
                'run_at'   => $ts,
                'error'    => $e->getMessage(),
                'mode'     => 'shadow',
            ];
            try {
                $this->ensureRuntimeDir();
                $this->saveJson('win_universe_status.json', $errorStatus);
            } catch (\Throwable $ignored) {
                // non-fatal
            }
            return $errorStatus;
        }
    }

    /**
     * Return the last saved win universe result (win_universe.json) or null.
     *
     * @return array<string,mixed>|null
     */
    public function getUniverse(): ?array
    {
        return $this->loadJson('win_universe.json');
    }

    /**
     * Return the last saved status (win_universe_status.json) or null.
     *
     * @return array<string,mixed>|null
     */
    public function getStatus(): ?array
    {
        return $this->loadJson('win_universe_status.json');
    }

    /**
     * Return per-symbol stats (win_universe_stats.json) or null.
     *
     * @return array<string,mixed>|null
     */
    public function getStats(): ?array
    {
        return $this->loadJson('win_universe_stats.json');
    }

    /**
     * Return current win pool state (win_universe_pool.json) or null.
     *
     * @return array<string,mixed>|null
     */
    public function getPool(): ?array
    {
        return $this->loadJson('win_universe_pool.json');
    }

    /**
     * Return recent promotion history (win_universe_promotions.json) or null.
     *
     * @return array<string,mixed>|null
     */
    public function getPromotions(): ?array
    {
        return $this->loadJson('win_universe_promotions.json');
    }

    /**
     * Return recent demotion history (win_universe_demotions.json) or null.
     *
     * @return array<string,mixed>|null
     */
    public function getDemotions(): ?array
    {
        return $this->loadJson('win_universe_demotions.json');
    }

    /**
     * Return evaluation data (win_universe_eval.json) or null.
     *
     * @return array<string,mixed>|null
     */
    public function getEval(): ?array
    {
        return $this->loadJson('win_universe_eval.json');
    }

    /**
     * Return evaluation by status (win_universe_eval_by_status.json) or null.
     *
     * @return array<string,mixed>|null
     */
    public function getEvalByStatus(): ?array
    {
        return $this->loadJson('win_universe_eval_by_status.json');
    }

    /**
     * Return entry-status-based evaluation (win_universe_eval_at_entry.json) or null.
     *
     * @return array<string,mixed>|null
     */
    public function getEvalAtEntry(): ?array
    {
        return $this->loadJson('win_universe_eval_at_entry.json');
    }

    /**
     * Return evaluation by entry status (win_universe_eval_by_entry_status.json) or null.
     *
     * @return array<string,mixed>|null
     */
    public function getEvalByEntryStatus(): ?array
    {
        return $this->loadJson('win_universe_eval_by_entry_status.json');
    }

    /**
     * Return bonus evaluation at entry (win_universe_bonus_eval_at_entry.json) or null.
     *
     * @return array<string,mixed>|null
     */
    public function getBonusEvalAtEntry(): ?array
    {
        return $this->loadJson('win_universe_bonus_eval_at_entry.json');
    }

    /**
     * Return the current merged config (for display/API).
     *
     * @return array<string,mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Save a user config overlay to win_universe_user_config.json.
     * Only whitelisted keys are accepted. Returns success/error.
     *
     * @param array<string,mixed> $values
     * @return array{ok:bool,saved:array<string,mixed>,errors:list<string>}
     */
    public function saveUserConfig(array $values): array
    {
        $allowed = [
            'win_universe_enabled'      => 'bool',
            'win_universe_mode'         => 'string',
            'min_roi_threshold'         => 'float',
            'min_target_roi'            => 'float',
            'min_wins_above_target'     => 'int',
            'max_time_to_target_minutes' => 'int',
            'lookback_days'             => 'int',
            'min_closed_trades'         => 'int',
            'min_winrate'               => 'float',
            'min_avg_roi'               => 'float',
            'qualification_expiry_days' => 'int',
            'demotion_loss_streak'      => 'int',
            'priority_bonus_enabled'    => 'bool',
            'priority_bonus_strength'   => 'float',
        ];

        $modeAllowed = ['shadow', 'priority', 'win_only'];
        $saved  = [];
        $errors = [];

        foreach ($allowed as $key => $type) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $raw = $values[$key];
            switch ($type) {
                case 'bool':
                    $saved[$key] = (bool)$raw;
                    break;
                case 'float':
                    if (!is_numeric($raw)) {
                        $errors[] = "Invalid float value for {$key}";
                        continue 2;
                    }
                    $saved[$key] = (float)$raw;
                    break;
                case 'int':
                    if (!is_numeric($raw)) {
                        $errors[] = "Invalid int value for {$key}";
                        continue 2;
                    }
                    $saved[$key] = (int)$raw;
                    break;
                case 'string':
                    if ($key === 'win_universe_mode' && !in_array((string)$raw, $modeAllowed, true)) {
                        $errors[] = "Invalid mode value: {$raw}";
                        continue 2;
                    }
                    $saved[$key] = (string)$raw;
                    break;
            }
        }

        if (!empty($errors)) {
            return ['ok' => false, 'saved' => [], 'errors' => $errors];
        }

        try {
            $this->ensureRuntimeDir();
            $existing = $this->loadJson('win_universe_user_config.json') ?? ['win_universe' => []];
            $existing['win_universe'] = array_merge($existing['win_universe'] ?? [], $saved);
            $existing['updated_at'] = date('c');
            $this->saveJson('win_universe_user_config.json', $existing);
        } catch (\Throwable $e) {
            return ['ok' => false, 'saved' => [], 'errors' => [$e->getMessage()]];
        }

        return ['ok' => true, 'saved' => $saved, 'errors' => []];
    }

    // =========================================================================
    // Output persistence
    // =========================================================================

    // =========================================================================
    // Evaluation / attribution layer
    // =========================================================================

    /**
     * Compute evaluation stats grouped by qualification status and win-pool membership.
     *
     * Attribution note: trade counts are aggregated from current qualification
     * results (symbols' most recent window stats). Attribution is by current
     * qualification status, not historical status at trade entry time.
     *
     * Groups computed:
     *   qualified        — symbols currently qualified
     *   near_qualified   — symbols near-qualified
     *   rejected         — symbols currently rejected
     *   in_win_pool      — symbols currently in the win pool
     *   not_in_pool      — symbols not in the win pool
     *
     * @param array<string,mixed> $result Engine compute() result
     * @param string $ts ISO timestamp
     * @return array<string,mixed>
     */
    private function computeEval(array $result, string $ts): array
    {
        $symbols = $result['symbols'] ?? [];
        $winPool = $result['win_pool'] ?? [];

        /** @var array<string,array<string,mixed>> $groups */
        $groups = [];
        foreach (['qualified', 'near_qualified', 'rejected', 'in_win_pool', 'not_in_pool'] as $key) {
            $groups[$key] = [
                'symbol_count'         => 0,
                'trade_count'          => 0,
                'win_count'            => 0,
                '_roi_values'          => [],
                '_weighted_roi_sum'    => 0.0,
                '_weighted_roi_count'  => 0,
            ];
        }

        foreach ($symbols as $sym => $rec) {
            $qStatus    = (string)($rec['qualification_status'] ?? 'rejected');
            $inPool     = isset($winPool[(string)$sym]);
            $tradeCount = (int)($rec['closed_trades_window'] ?? $rec['recent_trade_count'] ?? 0);
            $winCount   = (int)($rec['wins_above_threshold'] ?? 0);
            $avgRoi     = isset($rec['recent_avg_roi']) && $rec['recent_avg_roi'] !== null
                ? (float)$rec['recent_avg_roi']
                : null;

            // Status group (qualified / near_qualified / rejected)
            if (isset($groups[$qStatus])) {
                $groups[$qStatus]['symbol_count']++;
                $groups[$qStatus]['trade_count']  += $tradeCount;
                $groups[$qStatus]['win_count']    += $winCount;
                if ($avgRoi !== null) {
                    $groups[$qStatus]['_roi_values'][]         = $avgRoi;
                    $groups[$qStatus]['_weighted_roi_sum']    += $avgRoi * max(1, $tradeCount);
                    $groups[$qStatus]['_weighted_roi_count']  += max(1, $tradeCount);
                }
            }

            // Pool group
            $poolKey = $inPool ? 'in_win_pool' : 'not_in_pool';
            $groups[$poolKey]['symbol_count']++;
            $groups[$poolKey]['trade_count']  += $tradeCount;
            $groups[$poolKey]['win_count']    += $winCount;
            if ($avgRoi !== null) {
                $groups[$poolKey]['_roi_values'][]         = $avgRoi;
                $groups[$poolKey]['_weighted_roi_sum']    += $avgRoi * max(1, $tradeCount);
                $groups[$poolKey]['_weighted_roi_count']  += max(1, $tradeCount);
            }
        }

        // Finalize each group
        $minEvalTrades = 10;
        $finalGroups   = [];
        foreach ($groups as $key => $g) {
            $tc = (int)$g['trade_count'];
            $wc = (int)$g['win_count'];
            $lc = max(0, $tc - $wc);

            $winrate = $tc > 0 ? round($wc / $tc, 4) : null;

            $avgRoi = null;
            if ($g['_weighted_roi_count'] > 0) {
                $avgRoi = round($g['_weighted_roi_sum'] / $g['_weighted_roi_count'], 4);
            }

            $medianRoi = null;
            $rois = $g['_roi_values'];
            if (!empty($rois)) {
                sort($rois);
                $mid       = (int)(count($rois) / 2);
                $medianRoi = count($rois) % 2 === 0
                    ? round(($rois[$mid - 1] + $rois[$mid]) / 2.0, 4)
                    : round($rois[$mid], 4);
            }

            $finalGroups[$key] = [
                'symbol_count'          => (int)$g['symbol_count'],
                'trade_count'           => $tc,
                'win_count'             => $wc,
                'loss_count'            => $lc,
                'winrate'               => $winrate,
                'avg_roi'               => $avgRoi,
                'median_roi'            => $medianRoi,
                'small_sample_warning'  => $tc < $minEvalTrades,
                'sample_note'           => $tc < $minEvalTrades
                    ? 'Мало данных (' . $tc . ' сделок) — статистика ненадёжна'
                    : null,
            ];
        }

        return [
            'computed_at'                    => $ts,
            'attribution_note'               => 'ВТОРИЧНАЯ ОЦЕНКА (приближение). Атрибуция по ТЕКУЩЕМУ статусу квалификации символа. '
                . 'Статус на момент открытия конкретной сделки НЕ фиксируется. '
                . 'Первичная оценка (по статусу НА МОМЕНТ ВХОДА) — в win_universe_eval_at_entry.json.',
            'attribution_basis'              => 'current_qualification_state_approximation',
            'evaluation_method'              => 'secondary_current_state',
            'min_eval_sample_warning_threshold' => $minEvalTrades,
            'total_symbols_evaluated'        => count($symbols),
            'groups'                         => $finalGroups,
        ];
    }

    /**
     * Build a compact eval summary suitable for inclusion in win_universe_status.json.
     *
     * @param array<string,mixed>|null $evalData Return value of computeEval(), or null on error
     * @return array<string,mixed>
     */
    private function buildEvalSummary(?array $evalData): array
    {
        if ($evalData === null) {
            return [];
        }
        $groups = $evalData['groups'] ?? [];

        $pick = static function (string $group, string $field) use ($groups) {
            return $groups[$group][$field] ?? null;
        };

        $nonQualTradeCount = (int)($groups['near_qualified']['trade_count'] ?? 0)
            + (int)($groups['rejected']['trade_count'] ?? 0);
        $nonQualWinCount   = (int)($groups['near_qualified']['win_count']   ?? 0)
            + (int)($groups['rejected']['win_count']   ?? 0);

        $nonQualWinrate = $nonQualTradeCount > 0
            ? round($nonQualWinCount / $nonQualTradeCount, 4)
            : null;

        // Weighted avg ROI across near_qualified + rejected
        $nqAvgRoi = null;
        $nqRoiSum = 0.0;
        $nqCount  = 0;
        foreach (['near_qualified', 'rejected'] as $gk) {
            $tc  = (int)($groups[$gk]['trade_count'] ?? 0);
            $roi = $groups[$gk]['avg_roi'] ?? null;
            if ($roi !== null && $tc > 0) {
                $nqRoiSum += $roi * $tc;
                $nqCount  += $tc;
            }
        }
        if ($nqCount > 0) {
            $nqAvgRoi = round($nqRoiSum / $nqCount, 4);
        }

        return [
            'eval_computed_at'              => $evalData['computed_at'] ?? null,
            'qualified_trade_count'         => $pick('qualified', 'trade_count'),
            'qualified_winrate'             => $pick('qualified', 'winrate'),
            'qualified_avg_roi'             => $pick('qualified', 'avg_roi'),
            'qualified_small_sample'        => $pick('qualified', 'small_sample_warning'),
            'nonqualified_trade_count'      => $nonQualTradeCount ?: null,
            'nonqualified_winrate'          => $nonQualWinrate,
            'nonqualified_avg_roi'          => $nqAvgRoi,
            'in_win_pool_trade_count'       => $pick('in_win_pool', 'trade_count'),
            'in_win_pool_winrate'           => $pick('in_win_pool', 'winrate'),
            'in_win_pool_avg_roi'           => $pick('in_win_pool', 'avg_roi'),
            'in_win_pool_small_sample'      => $pick('in_win_pool', 'small_sample_warning'),
            'eval_sample_warning'           => (
                (bool)($groups['qualified']['small_sample_warning'] ?? true)
                && (bool)($groups['in_win_pool']['small_sample_warning'] ?? true)
            ),
        ];
    }

    /**
     * Build a compact at-entry eval summary for win_universe_status.json.
     *
     * @param array<string,mixed>|null $evalAtEntry Return value of computeEvalAtEntry(), or null on error
     * @return array<string,mixed>
     */
    private function buildEvalAtEntrySummary(?array $evalAtEntry): array
    {
        if ($evalAtEntry === null) {
            return [];
        }
        $groups      = $evalAtEntry['groups']      ?? [];
        $bonusGroups = $evalAtEntry['bonus_groups'] ?? [];

        $pick = static function (string $group, string $field) use ($groups) {
            return $groups[$group][$field] ?? null;
        };
        $pickB = static function (string $group, string $field) use ($bonusGroups) {
            return $bonusGroups[$group][$field] ?? null;
        };

        return [
            'eval_at_entry_computed_at'                 => $evalAtEntry['computed_at'] ?? null,
            'eval_at_entry_attribution_method'          => $evalAtEntry['attribution_method'] ?? null,
            'eval_at_entry_evaluation_method'           => $evalAtEntry['evaluation_method'] ?? null,
            'eval_at_entry_intent_log_size'             => $evalAtEntry['intent_log_size'] ?? 0,
            'eval_at_entry_symbols_attributed'          => $evalAtEntry['symbols_attributed'] ?? 0,
            'eval_at_entry_trades_attributed'           => $evalAtEntry['trades_attributed'] ?? 0,
            'eval_at_entry_trades_no_attr'              => $evalAtEntry['trades_no_attr'] ?? 0,
            // Aggregate qualified vs nonqualified
            'qualified_at_entry_trade_count'            => $pick('qualified_at_entry', 'trade_count'),
            'qualified_at_entry_winrate'                => $pick('qualified_at_entry', 'winrate'),
            'qualified_at_entry_avg_roi'                => $pick('qualified_at_entry', 'avg_roi'),
            'qualified_at_entry_median_roi'             => $pick('qualified_at_entry', 'median_roi'),
            'qualified_at_entry_small_sample'           => $pick('qualified_at_entry', 'small_sample_warning'),
            // Granular WU-engine qualification buckets
            'near_qualified_at_entry_trade_count'       => $pick('near_qualified_at_entry', 'trade_count'),
            'near_qualified_at_entry_winrate'           => $pick('near_qualified_at_entry', 'winrate'),
            'near_qualified_at_entry_avg_roi'           => $pick('near_qualified_at_entry', 'avg_roi'),
            'near_qualified_at_entry_small_sample'      => $pick('near_qualified_at_entry', 'small_sample_warning'),
            'rejected_at_entry_trade_count'             => $pick('rejected_at_entry', 'trade_count'),
            'rejected_at_entry_winrate'                 => $pick('rejected_at_entry', 'winrate'),
            'rejected_at_entry_avg_roi'                 => $pick('rejected_at_entry', 'avg_roi'),
            'rejected_at_entry_small_sample'            => $pick('rejected_at_entry', 'small_sample_warning'),
            'nonqualified_at_entry_trade_count'         => $pick('nonqualified_at_entry', 'trade_count'),
            'nonqualified_at_entry_winrate'             => $pick('nonqualified_at_entry', 'winrate'),
            'nonqualified_at_entry_avg_roi'             => $pick('nonqualified_at_entry', 'avg_roi'),
            'nonqualified_at_entry_median_roi'          => $pick('nonqualified_at_entry', 'median_roi'),
            'nonqualified_at_entry_small_sample'        => $pick('nonqualified_at_entry', 'small_sample_warning'),
            // Granular pool-state sub-buckets
            'not_in_pool_at_entry_trade_count'          => $pick('not_in_pool_at_entry', 'trade_count'),
            'not_in_pool_at_entry_winrate'              => $pick('not_in_pool_at_entry', 'winrate'),
            'pool_empty_at_entry_trade_count'           => $pick('pool_empty_at_entry', 'trade_count'),
            'pool_empty_at_entry_winrate'               => $pick('pool_empty_at_entry', 'winrate'),
            // Bonus attribution
            'bonus_applied_at_entry_trade_count'        => $pickB('bonus_applied', 'trade_count'),
            'bonus_applied_at_entry_winrate'            => $pickB('bonus_applied', 'winrate'),
            'bonus_applied_at_entry_avg_roi'            => $pickB('bonus_applied', 'avg_roi'),
            'bonus_applied_at_entry_small_sample'       => $pickB('bonus_applied', 'small_sample_warning'),
            'bonus_used_not_applied_at_entry_trade_count' => $pickB('bonus_used_not_applied', 'trade_count'),
        ];
    }

    /**
     * Load and parse the intent attribution NDJSON log.
     *
     * Returns list of attribution records: each record has
     * symbol, side, created_at_ts, win_universe_status_at_entry, in_win_pool_at_entry,
     * bonus_applied_at_entry, bonus_value_at_entry.
     *
     * @return array<int,array<string,mixed>>
     */
    private function loadAttributionLog(): array
    {
        $path = $this->runtimeDir . '/win_universe_intent_attribution.ndjson';
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $records = [];
        foreach (explode("\n", trim($raw)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['symbol'])) {
                $records[] = $decoded;
            }
        }
        return $records;
    }

    /**
     * Load all closed trades for evaluation (mirrors WinUniverseEngine logic).
     * Reads bot closed trades and simulator closed trades.
     *
     * @return array<int,array<string,mixed>>
     */
    private function loadClosedTradesForEval(): array
    {
        $moduleBase    = __DIR__;
        $botBase       = dirname($moduleBase) . '/trading_bot';
        $brainBase     = dirname($moduleBase) . '/smart_brain';
        $trades        = [];

        // ── Bot storage resolution ────────────────────────────────────────────
        $botMode = 'demo';
        try {
            $botCfgPath = $botBase . '/config/bot.json';
            if (is_file($botCfgPath)) {
                $j = @file_get_contents($botCfgPath);
                if ($j !== false && $j !== '') {
                    $d = json_decode($j, true);
                    if (is_array($d)) {
                        $botMode = (string)($d['module']['mode'] ?? $d['mode'] ?? 'demo');
                    }
                }
            }
        } catch (\Throwable $e) {
            // keep 'demo'
        }
        $modeMap   = ['live' => 'storage_live', 'demo' => 'storage_demo', 'paper' => 'storage_paper'];
        $preferred = $modeMap[$botMode] ?? 'storage_demo';
        $botStorageDirs = [$botBase . '/' . $preferred];
        foreach ($modeMap as $sfx) {
            if ($sfx !== $preferred) {
                $botStorageDirs[] = $botBase . '/' . $sfx;
            }
        }
        $botStorageDirs[] = $botBase . '/storage';
        $botStorageDir = null;
        foreach ($botStorageDirs as $sd) {
            if (is_dir($sd)) {
                $botStorageDir = $sd;
                break;
            }
        }

        // ── Bot closed trades ─────────────────────────────────────────────────
        if ($botStorageDir !== null) {
            try {
                $raw = [];
                $aggPath = $botStorageDir . '/trades/closed_trades.json';
                if (is_file($aggPath)) {
                    $c = @file_get_contents($aggPath);
                    if ($c !== false && $c !== '') {
                        $dec = json_decode($c, true);
                        if (is_array($dec) && !empty($dec)) {
                            $raw = $dec;
                        }
                    }
                }
                if (empty($raw)) {
                    $closedDir = $botStorageDir . '/trades/closed';
                    if (is_dir($closedDir)) {
                        foreach (glob($closedDir . '/*.json') ?: [] as $p) {
                            $c = @file_get_contents($p);
                            if ($c === false || $c === '') {
                                continue;
                            }
                            $t = json_decode($c, true);
                            if (is_array($t) && !empty($t)) {
                                $raw[] = $t;
                            }
                        }
                    }
                }
                foreach ($raw as $trade) {
                    $n = $this->normaliseTrade($trade, 'bot');
                    if ($n !== null) {
                        $trades[] = $n;
                    }
                }
            } catch (\Throwable $e) {
                // non-fatal
            }
        }

        // ── Simulator closed trades ───────────────────────────────────────────
        try {
            $simPath = $brainBase . '/storage/simulator/closed.json';
            if (is_file($simPath)) {
                $c = @file_get_contents($simPath);
                if ($c !== false && $c !== '') {
                    $dec = json_decode($c, true);
                    if (is_array($dec)) {
                        foreach ($dec as $trade) {
                            if (!is_array($trade) || ($trade['status'] ?? '') !== 'closed') {
                                continue;
                            }
                            $n = $this->normaliseTrade($trade, 'simulator');
                            if ($n !== null) {
                                $trades[] = $n;
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // non-fatal
        }

        return $trades;
    }

    /**
     * Normalise a raw trade record for evaluation purposes.
     *
     * @param array<string,mixed> $trade
     * @param string              $source 'bot' or 'simulator'
     * @return array<string,mixed>|null
     */
    private function normaliseTrade(array $trade, string $source): ?array
    {
        $sym = strtoupper((string)($trade['symbol'] ?? ''));
        if ($sym === '') {
            return null;
        }

        // Closed timestamp
        $closedAt = 0;
        foreach (['closed_ts', 'closed_at'] as $fld) {
            if (isset($trade[$fld])) {
                $v = $trade[$fld];
                $closedAt = is_numeric($v) ? (int)$v : (int)strtotime((string)$v);
                if ($closedAt > 0) {
                    break;
                }
            }
        }
        if ($closedAt <= 0) {
            return null;
        }

        // Opened timestamp
        $openedAt = 0;
        foreach (['opened_ts', 'opened_at'] as $fld) {
            if (isset($trade[$fld])) {
                $v = $trade[$fld];
                $openedAt = is_numeric($v) ? (int)$v : (int)strtotime((string)$v);
                if ($openedAt > 0) {
                    break;
                }
            }
        }

        $roi = isset($trade['roi']) && is_numeric($trade['roi']) ? (float)$trade['roi'] : null;

        $durationMinutes = null;
        if (isset($trade['duration_minutes']) && is_numeric($trade['duration_minutes'])) {
            $durationMinutes = (float)$trade['duration_minutes'];
        } elseif (isset($trade['duration']) && is_numeric($trade['duration'])) {
            $durationMinutes = (float)$trade['duration'];
        } elseif ($openedAt > 0 && $closedAt > $openedAt) {
            $durationMinutes = round(($closedAt - $openedAt) / 60.0, 1);
        }

        // Carry embedded Win Universe status-at-entry fields if the trade record already has them.
        // Bot-executed trades may have these fields if the intent was persisted with WU attribution.
        $wuStatusAtEntry    = isset($trade['win_universe_status_at_entry'])
            ? (string)$trade['win_universe_status_at_entry'] : null;
        $wuInPoolAtEntry    = isset($trade['in_win_pool_at_entry'])
            ? (bool)$trade['in_win_pool_at_entry'] : null;
        $wuBonusApplied     = isset($trade['bonus_applied_at_entry'])
            ? (bool)$trade['bonus_applied_at_entry']
            : (isset($trade['win_universe_bonus_applied_at_entry']) ? (bool)$trade['win_universe_bonus_applied_at_entry'] : null);
        $wuBonusValue       = isset($trade['bonus_value_at_entry'])
            ? (float)$trade['bonus_value_at_entry']
            : (isset($trade['win_universe_bonus_value_at_entry']) ? (float)$trade['win_universe_bonus_value_at_entry'] : null);

        return [
            'symbol'                          => $sym,
            'side'                            => strtolower((string)($trade['side'] ?? '')),
            'roi'                             => $roi,
            'closed_at'                       => $closedAt,
            'opened_at'                       => $openedAt,
            'duration_minutes'                => $durationMinutes,
            'source'                          => $source,
            // Embedded WU fields (null if not present in trade record)
            'wu_status_at_entry'              => $wuStatusAtEntry,
            'wu_in_pool_at_entry'             => $wuInPoolAtEntry,
            'wu_bonus_applied_at_entry'       => $wuBonusApplied,
            'wu_bonus_value_at_entry'         => $wuBonusValue,
        ];
    }

    /**
     * Compute entry-status-based evaluation.
     *
     * Algorithm:
     *   1. Load the intent attribution log (NDJSON).
     *   2. Build per-symbol "last known entry status" from the log.
     *      For each symbol, find the most recent attribution record.
     *   3. Load closed trades (bot + simulator).
     *   4. For each trade, assign entry status from the attribution map.
     *      Trades whose symbols have no attribution record get status 'no_attribution'.
     *   5. Compute per-group performance metrics.
     *   6. Also compute bonus_applied vs bonus_not_applied performance.
     *
     * Important: this is best-effort attribution. Attribution log only covers
     * intents created since the attribution feature was deployed. Trades without
     * attribution are explicitly shown as 'no_attribution' — not hidden.
     *
     * @param string $ts ISO timestamp
     * @return array<string,mixed>
     */
    private function computeEvalAtEntry(string $ts): array
    {
        $minEvalTrades = 10;

        // Load attribution log (newest first due to prepend-on-write)
        $attrRecords = $this->loadAttributionLog();
        $logSize     = count($attrRecords);

        // ── Build per-symbol SORTED TIMELINE of attribution records ────────────
        // The log is prepended (newest first), so we reverse to sort chronologically.
        // For each trade we will find the attribution record whose created_at_ts is
        // closest to (and at most 1 hour after) the trade's opened_at timestamp.
        // This ensures we use the symbol's status AT THE TIME OF ENTRY, not its
        // most recent known status.
        $attrTimeline = []; // [symbol => [[ts, record], ...]] sorted asc by ts
        foreach ($attrRecords as $rec) {
            $sym = strtoupper((string)($rec['symbol'] ?? ''));
            $recTs = (int)($rec['created_at_ts'] ?? 0);
            if ($sym === '' || $recTs <= 0) {
                continue;
            }
            $attrTimeline[$sym][] = [$recTs, $rec];
        }
        // Sort each symbol's records chronologically (ascending)
        foreach ($attrTimeline as &$tl) {
            usort($tl, static fn($a, $b) => $a[0] <=> $b[0]);
        }
        unset($tl);

        // Count unique symbols in log for diagnostics
        $symbolsAttributed = count($attrTimeline);

        /**
         * Find attribution record for a given symbol and opened_at timestamp.
         * Returns the record whose created_at_ts is closest to opened_at (±1h tolerance).
         * If opened_at is 0, returns the most recent record for the symbol.
         *
         * @param string $sym
         * @param int    $openedAt Unix timestamp of trade open (0 = unknown)
         * @return array<string,mixed>|null
         */
        $findAttrRecord = static function (string $sym, int $openedAt) use ($attrTimeline): ?array {
            $timeline = $attrTimeline[$sym] ?? null;
            if ($timeline === null) {
                return null;
            }
            if ($openedAt <= 0) {
                // Unknown open time — use the most recent attribution record
                return end($timeline)[1];
            }
            // Walk backward from the end to find the most recent record that was
            // created at most 1 hour AFTER the trade open (grace window handles
            // the fact that intents are created slightly before orders fill).
            $best    = null;
            $bestDiff = PHP_INT_MAX;
            $graceSec = 3600; // 1 hour tolerance
            foreach ($timeline as [$recTs, $rec]) {
                $diff = abs($recTs - $openedAt);
                // Accept records within grace window; prefer smallest diff
                if ($diff <= $graceSec && $diff < $bestDiff) {
                    $bestDiff = $diff;
                    $best = $rec;
                }
            }
            // Fallback: if no record within grace window, use the closest one overall
            if ($best === null) {
                foreach ($timeline as [$recTs, $rec]) {
                    $diff = abs($recTs - $openedAt);
                    if ($diff < $bestDiff) {
                        $bestDiff = $diff;
                        $best = $rec;
                    }
                }
            }
            return $best;
        };

        // Load closed trades
        $closedTrades = $this->loadClosedTradesForEval();

        // ── Main entry-status groups ──────────────────────────────────────────
        // qualified_at_entry        — WU engine qualified at entry
        // near_qualified_at_entry   — WU engine near-qualified at entry
        // rejected_at_entry         — WU engine rejected at entry
        // nonqualified_at_entry     — all non-qualified attributed (aggregate: near + rejected + pool_empty + shadow)
        // not_in_pool_at_entry      — win_universe_status_at_entry === 'not_in_pool' (no qual_status info)
        // pool_empty_at_entry       — win_universe_status_at_entry === 'pool_empty'
        // shadow_mode_at_entry      — win_universe_status_at_entry === 'shadow_mode' or 'bonus_disabled'
        // no_attribution            — no matching record in log
        $groupKeys = [
            'qualified_at_entry',
            'near_qualified_at_entry',
            'rejected_at_entry',
            'nonqualified_at_entry',
            'not_in_pool_at_entry',
            'pool_empty_at_entry',
            'shadow_mode_at_entry',
            'no_attribution',
        ];
        $groups = [];
        foreach ($groupKeys as $gk) {
            $groups[$gk] = [
                'trade_count'      => 0,
                'win_count'        => 0,
                '_roi_values'      => [],
                '_duration_values' => [],
            ];
        }

        // ── Bonus groups ──────────────────────────────────────────────────────
        $bonusGroupKeys = ['bonus_applied', 'bonus_not_applied', 'bonus_used_not_applied', 'no_attribution'];
        $bonusGroups    = [];
        foreach ($bonusGroupKeys as $bk) {
            $bonusGroups[$bk] = [
                'trade_count' => 0,
                'win_count'   => 0,
                '_roi_values' => [],
            ];
        }

        $wuConfig = $this->config['win_universe'] ?? [];
        $minRoi   = (float)($wuConfig['min_roi_threshold'] ?? 0.01);

        foreach ($closedTrades as $trade) {
            $sym      = (string)($trade['symbol'] ?? '');
            $roi      = $trade['roi'];
            $openedAt = (int)($trade['opened_at'] ?? 0);
            if ($sym === '' || $roi === null) {
                continue;
            }

            $isWin = $roi >= $minRoi;

            // ── Resolve attribution ───────────────────────────────────────────
            // Priority 1: trade record itself has embedded WU fields (best-quality)
            // Priority 2: look up attribution log by time proximity
            $statusAtEntry       = null;
            $qualStatusAtEntry   = null; // qualified/near_qualified/rejected from WU engine
            $bonusApplied        = null;
            $bonusUsed           = null;

            if ($trade['wu_status_at_entry'] !== null) {
                // Trade has embedded WU fields (from bot-persisted intent data)
                $statusAtEntry = $trade['wu_status_at_entry'];
                $bonusApplied  = $trade['wu_bonus_applied_at_entry'];
                $bonusUsed     = $bonusApplied; // same for now
                // Derive qualification status from pool membership when embedded
                if ($statusAtEntry === 'qualified') {
                    $qualStatusAtEntry = 'qualified';
                }
            } else {
                // Look up attribution log for this symbol at this opened_at time
                $attrRec = $findAttrRecord($sym, $openedAt);
                if ($attrRec !== null) {
                    $statusAtEntry     = (string)($attrRec['win_universe_status_at_entry'] ?? 'not_in_pool');
                    $bonusApplied      = (bool)($attrRec['bonus_applied_at_entry'] ?? false);
                    $bonusUsed         = $bonusApplied; // currently same; future code may differ
                    // Prefer the richer wu_qualification_status_at_entry field if present
                    // (added by smart_brain_core.php WU-8 update)
                    $qualStatusAtEntry = isset($attrRec['wu_qualification_status_at_entry'])
                        ? (string)$attrRec['wu_qualification_status_at_entry']
                        : null;
                }
            }

            // ── Main group classification ─────────────────────────────────────
            // Use the WU engine's qualification status (qualified/near_qualified/rejected) when
            // available for precise bucketing. Fall back to pool-membership status otherwise.
            if ($statusAtEntry === null) {
                $mainGroup = 'no_attribution';
            } elseif ($qualStatusAtEntry === 'qualified' || $statusAtEntry === 'qualified') {
                $mainGroup = 'qualified_at_entry';
            } elseif ($qualStatusAtEntry === 'near_qualified') {
                $mainGroup = 'near_qualified_at_entry';
            } elseif ($qualStatusAtEntry === 'rejected') {
                $mainGroup = 'rejected_at_entry';
            } elseif ($statusAtEntry === 'pool_empty') {
                $mainGroup = 'pool_empty_at_entry';
            } elseif (in_array($statusAtEntry, ['shadow_mode', 'bonus_disabled'], true)) {
                $mainGroup = 'shadow_mode_at_entry';
            } else {
                // 'not_in_pool' and any unknown values → not_in_pool_at_entry
                $mainGroup = 'not_in_pool_at_entry';
            }

            // Also accumulate the aggregate nonqualified_at_entry bucket
            // (all attributed trades that are not qualified)
            $isAttributed = ($statusAtEntry !== null);
            $isQualified  = ($mainGroup === 'qualified_at_entry');

            if (isset($groups[$mainGroup])) {
                $groups[$mainGroup]['trade_count']++;
                if ($isWin) {
                    $groups[$mainGroup]['win_count']++;
                }
                $groups[$mainGroup]['_roi_values'][] = $roi;
                if ($trade['duration_minutes'] !== null) {
                    $groups[$mainGroup]['_duration_values'][] = (float)$trade['duration_minutes'];
                }
            }

            // Populate aggregate nonqualified bucket from granular groups
            if ($isAttributed && !$isQualified) {
                $groups['nonqualified_at_entry']['trade_count']++;
                if ($isWin) {
                    $groups['nonqualified_at_entry']['win_count']++;
                }
                $groups['nonqualified_at_entry']['_roi_values'][] = $roi;
                if ($trade['duration_minutes'] !== null) {
                    $groups['nonqualified_at_entry']['_duration_values'][] = (float)$trade['duration_minutes'];
                }
            }

            // ── Bonus group classification ────────────────────────────────────
            if ($statusAtEntry === null) {
                $bonusGroups['no_attribution']['trade_count']++;
                if ($isWin) {
                    $bonusGroups['no_attribution']['win_count']++;
                }
                $bonusGroups['no_attribution']['_roi_values'][] = $roi;
            } elseif ($bonusApplied) {
                $bonusGroups['bonus_applied']['trade_count']++;
                if ($isWin) {
                    $bonusGroups['bonus_applied']['win_count']++;
                }
                $bonusGroups['bonus_applied']['_roi_values'][] = $roi;
            } elseif ($bonusUsed && !$bonusApplied) {
                // Bonus was considered but not applied (e.g. candidate lost slot competition)
                $bonusGroups['bonus_used_not_applied']['trade_count']++;
                if ($isWin) {
                    $bonusGroups['bonus_used_not_applied']['win_count']++;
                }
                $bonusGroups['bonus_used_not_applied']['_roi_values'][] = $roi;
            } else {
                $bonusGroups['bonus_not_applied']['trade_count']++;
                if ($isWin) {
                    $bonusGroups['bonus_not_applied']['win_count']++;
                }
                $bonusGroups['bonus_not_applied']['_roi_values'][] = $roi;
            }
        }

        // ── Finalize groups ───────────────────────────────────────────────────
        $finalGroups = [];
        foreach ($groups as $key => $g) {
            $tc = $g['trade_count'];
            $wc = $g['win_count'];
            $lc = max(0, $tc - $wc);
            $finalGroups[$key] = $this->buildGroupStats($g, $tc, $wc, $lc, $minEvalTrades);
        }

        $finalBonusGroups = [];
        foreach ($bonusGroups as $key => $g) {
            $tc = $g['trade_count'];
            $wc = $g['win_count'];
            $lc = max(0, $tc - $wc);
            $finalBonusGroups[$key] = $this->buildGroupStats($g, $tc, $wc, $lc, $minEvalTrades);
        }

        $totalAttributed = ($finalGroups['qualified_at_entry']['trade_count']    ?? 0)
                         + ($finalGroups['nonqualified_at_entry']['trade_count']  ?? 0);
        $noAttrCount     = $finalGroups['no_attribution']['trade_count']          ?? 0;

        return [
            'computed_at'        => $ts,
            'attribution_note'   => 'ПЕРВИЧНАЯ ОЦЕНКА. Атрибуция по статусу Win Universe НА МОМЕНТ ВХОДА в сделку. '
                . 'Для каждой сделки находится запись в журнале атрибуции, ближайшая по времени '
                . 'к открытию позиции. Сделки без записи — «no_attribution». '
                . 'Вторичная оценка (по текущему статусу) — в win_universe_eval.json.',
            'attribution_basis'  => 'status_at_entry',
            'evaluation_method'  => 'primary_entry_based',
            'attribution_method' => 'time_proximity', // time-based matching, not latest-per-symbol
            'intent_log_size'    => $logSize,
            'symbols_attributed' => $symbolsAttributed,
            'trades_attributed'  => $totalAttributed,
            'trades_no_attr'     => $noAttrCount,
            'min_eval_sample_warning_threshold' => $minEvalTrades,
            'groups'             => $finalGroups,
            'bonus_groups'       => $finalBonusGroups,
        ];
    }

    /**
     * Compute standard performance statistics for an accumulated trade group.
     *
     * @param array<string,mixed> $g             Raw accumulated group data
     * @param int                 $tc            Trade count
     * @param int                 $wc            Win count
     * @param int                 $lc            Loss count
     * @param int                 $minEvalTrades Small-sample threshold
     * @return array<string,mixed>
     */
    private function buildGroupStats(array $g, int $tc, int $wc, int $lc, int $minEvalTrades): array
    {
        $winrate = $tc > 0 ? round($wc / $tc, 4) : null;

        $rois      = $g['_roi_values'] ?? [];
        $avgRoi    = null;
        $medianRoi = null;
        if (!empty($rois)) {
            $avgRoi = round(array_sum($rois) / count($rois), 4);
            sort($rois);
            $mid       = (int)(count($rois) / 2);
            $medianRoi = count($rois) % 2 === 0
                ? round(($rois[$mid - 1] + $rois[$mid]) / 2.0, 4)
                : round($rois[$mid], 4);
        }

        $durations = $g['_duration_values'] ?? [];
        $avgDuration = null;
        if (!empty($durations)) {
            $avgDuration = round(array_sum($durations) / count($durations), 1);
        }

        return [
            'trade_count'           => $tc,
            'win_count'             => $wc,
            'loss_count'            => $lc,
            'winrate'               => $winrate,
            'avg_roi'               => $avgRoi,
            'median_roi'            => $medianRoi,
            'avg_time_to_close_min' => $avgDuration,
            'small_sample_warning'  => $tc < $minEvalTrades,
            'sample_note'           => $tc < $minEvalTrades
                ? 'Мало данных (' . $tc . ' сд.) — статистика ненадёжна'
                : null,
        ];
    }

    /**
     * Persist all runtime artifacts from a compute result.
     *
     * @param array<string,mixed> $result  Return value from WinUniverseEngine::compute()
     * @param string              $ts      ISO timestamp
     */
    private function persistOutputs(array $result, string $ts): void
    {
        // win_universe.json — qualified/near-qualified/rejected lists + per-symbol details
        $universe = [
            'mode'                                  => $result['config_used']['win_universe_mode'] ?? 'shadow',
            'computed_at'                           => $ts,
            'qualified'                             => $result['qualified'],
            'near_qualified'                        => $result['near_qualified'],
            'rejected'                              => $result['rejected'],
            'symbols'                               => $result['symbols'],
            'win_pool'                              => $result['win_pool'],
            'config_used'                           => $result['config_used'],
            'sources_used'                          => $result['sources_used'],
            'trade_count_total'                     => $result['trade_count_total'],
            'threshold_sensitivity'                 => $result['threshold_sensitivity'],
            'candidate_sensitivity_preview'         => $result['candidate_sensitivity_preview'] ?? [],
            'excessive_qualification_warning'       => $result['excessive_qualification_warning'] ?? false,
            'excessive_qualification_note'          => $result['excessive_qualification_note'] ?? null,
        ];
        $this->saveJson('win_universe.json', $universe);

        // win_universe_pool.json — current win pool state
        $pool = [
            'win_pool'     => $result['win_pool'],
            'last_updated' => $ts,
            'pool_count'   => count($result['win_pool']),
        ];
        $this->saveJson('win_universe_pool.json', $pool);

        // win_universe_promotions.json — bounded promotion history
        $this->appendHistory('win_universe_promotions.json', $result['promotions'], $ts);

        // win_universe_demotions.json — bounded demotion history
        $this->appendHistory('win_universe_demotions.json', $result['demotions'], $ts);

        // win_universe_stats.json — raw per-symbol statistics with full diagnostics
        $stats = [
            'computed_at'                   => $ts,
            'symbols'                       => array_map(static function (array $rec): array {
                return [
                    'symbol'                        => $rec['symbol'],
                    'qualification_status'          => $rec['qualification_status'],
                    'qualified'                     => $rec['qualified'],
                    'near_qualified'                => $rec['near_qualified'],
                    'in_win_pool'                   => $rec['in_win_pool'] ?? false,
                    'promotion_eligible'            => $rec['promotion_eligible'] ?? false,
                    'demotion_eligible'             => $rec['demotion_eligible'] ?? false,
                    'qualification_reason'          => $rec['qualification_reason'],
                    'promotion_reason'              => $rec['promotion_reason'] ?? null,
                    'demotion_reason'               => $rec['demotion_reason'] ?? null,
                    'last_promotion_time'           => $rec['last_promotion_time'] ?? null,
                    'last_demotion_time'            => $rec['last_demotion_time'] ?? null,
                    'missing_requirements'          => $rec['missing_requirements'],
                    'recent_trade_count'            => $rec['recent_trade_count'],
                    'closed_trades_count'           => $rec['closed_trades_count'],
                    'closed_trades_window'          => $rec['closed_trades_window'],
                    'wins_above_threshold'          => $rec['wins_above_threshold'],
                    'wins_above_target'             => $rec['wins_above_target'] ?? 0,
                    'avg_time_to_target_minutes'    => $rec['avg_time_to_target_minutes'] ?? null,
                    'median_time_to_target_minutes' => $rec['median_time_to_target_minutes'] ?? null,
                    'fastest_time_to_target_minutes' => $rec['fastest_time_to_target_minutes'] ?? null,
                    'speed_to_target_status'        => $rec['speed_to_target_status'] ?? null,
                    'speed_to_target_reason'        => $rec['speed_to_target_reason'] ?? null,
                    'recent_avg_roi'                => $rec['recent_avg_roi'],
                    'best_roi'                      => $rec['best_roi'],
                    'recent_winrate'                => $rec['recent_winrate'],
                    'last_trade_time'               => $rec['last_trade_time'],
                    'lookback_window_used'          => $rec['lookback_window_used'],
                    'thresholds_used'               => $rec['thresholds_used'],
                    // Distance-to-qualify metrics
                    'distance_to_min_roi_threshold' => $rec['distance_to_min_roi_threshold'] ?? null,
                    'distance_to_min_avg_roi'       => $rec['distance_to_min_avg_roi'] ?? null,
                    'distance_to_min_winrate'       => $rec['distance_to_min_winrate'] ?? null,
                    'missing_trade_count'           => $rec['missing_trade_count'] ?? 0,
                    'wins_needed_above_threshold'   => $rec['wins_needed_above_threshold'] ?? 0,
                ];
            }, $result['symbols']),
            'sources_used'                  => $result['sources_used'],
            'trade_count_total'             => $result['trade_count_total'],
            'threshold_sensitivity'         => $result['threshold_sensitivity'],
            'candidate_sensitivity_preview' => $result['candidate_sensitivity_preview'] ?? [],
        ];
        $this->saveJson('win_universe_stats.json', $stats);
    }

    /**
     * Append new events to a bounded history file.
     *
     * @param list<array<string,mixed>> $newEvents
     */
    private function appendHistory(string $filename, array $newEvents, string $ts): void
    {
        if (empty($newEvents)) {
            // Still write/update the file to ensure it exists
            $existing = $this->loadJson($filename);
            if ($existing === null) {
                $this->saveJson($filename, ['events' => [], 'last_updated' => $ts]);
            }
            return;
        }

        $existing = $this->loadJson($filename) ?? ['events' => []];
        $events   = $existing['events'] ?? [];

        foreach ($newEvents as $ev) {
            array_unshift($events, $ev);
        }

        // Bound to MAX_HISTORY
        if (count($events) > self::MAX_HISTORY) {
            $events = array_slice($events, 0, self::MAX_HISTORY);
        }

        $this->saveJson($filename, [
            'events'       => $events,
            'total_events' => count($events),
            'last_updated' => $ts,
        ]);
    }

    // =========================================================================
    // Config loading
    // =========================================================================

    /**
     * Load and merge module config.
     * Priority (highest first):
     *   1. win_universe_user_config.json (runtime user overlay)
     *   2. config/config.php (module defaults)
     *
     * @return array<string,mixed>
     */
    private function loadConfig(string $moduleBase): array
    {
        $defaults = [];
        $configPath = $moduleBase . '/config/config.php';
        if (is_file($configPath)) {
            try {
                $loaded = require $configPath;
                if (is_array($loaded)) {
                    $defaults = $loaded;
                }
            } catch (\Throwable $e) {
                // keep empty defaults
            }
        }

        // Load user config overlay
        $userConfigPath = $moduleBase . '/storage/runtime/win_universe_user_config.json';
        $userCfgLoaded  = false;
        if (is_file($userConfigPath)) {
            try {
                $content = @file_get_contents($userConfigPath);
                if ($content !== false && $content !== '') {
                    $userCfg = json_decode($content, true);
                    if (is_array($userCfg) && !empty($userCfg['win_universe'])) {
                        // Merge user overlay into win_universe block
                        $defaults['win_universe'] = array_merge(
                            $defaults['win_universe'] ?? [],
                            $userCfg['win_universe']
                        );
                        $userCfgLoaded = true;
                    }
                }
            } catch (\Throwable $e) {
                // keep defaults
            }
        }

        // Track where the mode was resolved from for observability
        $defaults['_meta']['mode_source'] = $userCfgLoaded ? 'user_config' : 'config_defaults';

        return $defaults;
    }

    // =========================================================================
    // Storage helpers
    // =========================================================================

    private function ensureRuntimeDir(): void
    {
        if (!is_dir($this->runtimeDir)) {
            @mkdir($this->runtimeDir, 0755, true);
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function saveJson(string $filename, array $data): void
    {
        $path    = $this->runtimeDir . '/' . $filename;
        $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($content === false) {
            return;
        }
        @file_put_contents($path, $content, LOCK_EX);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadJson(string $filename): ?array
    {
        $path = $this->runtimeDir . '/' . $filename;
        if (!is_file($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        if ($content === false || $content === '') {
            return null;
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }
}
