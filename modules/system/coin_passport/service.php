<?php
declare(strict_types=1);

use Core\System\SystemPaths;

require_once __DIR__ . '/lib/passport_engine.php';

/**
 * CoinPassportService
 *
 * Service layer for the Coin Passport module.
 * Delegates computation to CoinPassportEngine.
 *
 * Persistent storage:  modules/system/coin_passport/storage/passports/
 * Trade data source:   modules/system/trading_bot/storage/
 */
final class CoinPassportService
{
    private CoinPassportEngine $engine;
    private string $storageDir;

    /** Path to rebuild status file */
    private const STATUS_FILE = 'status.json';

    public function __construct()
    {
        $this->storageDir   = __DIR__ . '/storage';
        $passportsDir       = $this->storageDir . '/passports';
        $tradingBotStorage  = __DIR__ . '/../trading_bot/storage';
        $aiShadowStorage    = __DIR__ . '/../ai_shadow/storage';

        $this->engine = new CoinPassportEngine($passportsDir, $tradingBotStorage, $aiShadowStorage);
    }

    // =========================================================================
    // Read
    // =========================================================================

    /**
     * Return all passports as sorted array.
     *
     * @return array{passports:array<string,array<string,mixed>>,count:int}
     */
    public function getAllPassports(): array
    {
        $passports = $this->engine->loadAll();
        return [
            'passports' => $passports,
            'count'     => count($passports),
        ];
    }

    /**
     * Return a single passport by symbol (null if not found).
     *
     * @return array<string,mixed>|null
     */
    public function getPassport(string $symbol): ?array
    {
        return $this->engine->load(strtoupper($symbol));
    }

    /**
     * Return the evidence timeline for a symbol.
     *
     * @return list<array<string,mixed>>
     */
    public function getEvidenceTimeline(string $symbol): array
    {
        return $this->engine->loadEvidence(strtoupper($symbol));
    }

    /**
     * Append a single evidence event for a symbol.
     * Called from trade-close hooks, demotion events etc.
     *
     * @param array<string,mixed> $event  Must include 'type' at minimum.
     */
    public function appendEvidence(string $symbol, array $event): void
    {
        $this->engine->appendEvidence(strtoupper($symbol), $event);
    }

    /**
     * Passively write Profit Manager observation stats into a symbol's passport.
     *
     * Only the profit_manager_stats block is updated — all other passport fields
     * are left untouched. Best-effort: returns true on success, false on any failure.
     *
     * @param string              $symbol Upper-case symbol, e.g. 'BTCUSDT'
     * @param array<string,mixed> $stats  Compact PM observation summary (avg_peak_roi, samples_total, …)
     * @return bool
     */
    public function updateProfitManagerStats(string $symbol, array $stats): bool
    {
        try {
            return $this->engine->updateProfitManagerStats(strtoupper($symbol), $stats);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Return last rebuild status (for UI display).
     *
     * @return array<string,mixed>
     */
    public function getStatus(): array
    {
        $path = $this->storageDir . '/' . self::STATUS_FILE;
        if (!file_exists($path)) {
            return [
                'last_rebuild_at'     => null,
                'last_rebuild_mode'   => null,
                'last_rebuild_status' => 'never',
                'last_rebuild_error'  => null,
                'last_updated_count'  => 0,
                'storage_path'        => $this->storageDir . '/passports',
            ];
        }
        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    // =========================================================================
    // Write / rebuild
    // =========================================================================

    /**
     * Rebuild passports for all symbols from available trade data.
     * Called by CronManager (coin_passport:rebuildAll) and from UI.
     *
     * @return array{updated:int,symbols:list<string>,errors:list<string>}
     */
    public function rebuildAll(): array
    {
        $result = $this->engine->rebuildAll();
        $this->saveStatus('rebuild_all', $result);

        // After passports are rebuilt, project the latest cycle context into them (best-effort).
        try {
            $this->projectCycleContextToPassports();
        } catch (\Throwable $e) {
            // non-fatal
        }

        // Derive passive cycle hints from the projected context (best-effort, non-fatal).
        try {
            $this->projectCycleHintsToPassports();
        } catch (\Throwable $e) {
            // non-fatal
        }

        // Build passive cycle decision summary from context + hints (best-effort, non-fatal).
        try {
            $this->projectCycleSummaryToPassports();
        } catch (\Throwable $e) {
            // non-fatal
        }

        // Build passive cycle routing profile from cycle layers (best-effort, non-fatal).
        try {
            $this->projectCycleRoutingProfileToPassports();
        } catch (\Throwable $e) {
            // non-fatal
        }

        // Build passive cycle decision model from cycle layers (best-effort, non-fatal).
        try {
            $this->projectCycleDecisionModelToPassports();
        } catch (\Throwable $e) {
            // non-fatal
        }

        // Apply bounded cycle eligibility refinement from decision model (Coin Core Step 13).
        // Best-effort, non-fatal — failure leaves recommended_live_eligibility unchanged.
        try {
            $this->applyCycleEligibilityRefinement();
        } catch (\Throwable $e) {
            // non-fatal
        }

        return $result;
    }

    /**
     * Rebuild passports only for symbols that had recent trade activity
     * (closed in the last 7 days, from demo, live, or shadow sources).
     * Called by CronManager (coin_passport:rebuildRecentSymbols).
     *
     * @return array{updated:int,symbols:list<string>,errors:list<string>}
     */
    public function rebuildRecentSymbols(): array
    {
        $cutoff    = time() - 7 * 86400;
        $recent    = [];
        $botBase   = __DIR__ . '/../trading_bot';

        // Scan mode-separated bot storage directories for recent closed trades
        foreach (['storage_demo' => 'demo', 'storage_live' => 'live', 'storage_paper' => 'paper', 'storage' => 'live'] as $dir => $label) {
            $closedDir = $botBase . '/' . $dir . '/trades/closed';
            if (!is_dir($closedDir)) {
                continue;
            }
            foreach (glob($closedDir . '/*.json') ?: [] as $file) {
                $trade = json_decode((string)file_get_contents($file), true);
                if (!is_array($trade)) {
                    continue;
                }
                $symbol   = (string)($trade['symbol'] ?? '');
                $closedTs = (int)($trade['closed_ts'] ?? strtotime((string)($trade['closed_at'] ?? '')) ?: 0);
                if ($symbol !== '' && $closedTs >= $cutoff) {
                    $recent[strtoupper($symbol)] = true;
                }
            }
        }

        // Shadow virtual closed trades — include shadow-only symbols too
        $shadowClosedDir = __DIR__ . '/../ai_shadow/storage/virtual_trades_closed';
        if (is_dir($shadowClosedDir)) {
            foreach (glob($shadowClosedDir . '/*.json') ?: [] as $file) {
                $trade = json_decode((string)file_get_contents($file), true);
                if (!is_array($trade)) {
                    continue;
                }
                $symbol   = (string)($trade['symbol'] ?? '');
                $closedTs = isset($trade['closed_ts']) ? (int)$trade['closed_ts'] : (strtotime((string)($trade['closed_at'] ?? '')) ?: 0);
                if ($symbol !== '' && $closedTs >= $cutoff) {
                    $recent[strtoupper($symbol)] = true;
                }
            }
        }

        $result = ['updated' => 0, 'symbols' => [], 'errors' => []];

        foreach (array_keys($recent) as $symbol) {
            try {
                $this->engine->rebuildSymbol($symbol);
                $result['updated']++;
                $result['symbols'][] = $symbol;
            } catch (\Throwable $e) {
                $result['errors'][] = $symbol . ': ' . $e->getMessage();
            }
        }

        $this->saveStatus('rebuild_recent', $result);

        // After passports are rebuilt, project the latest cycle context into them (best-effort).
        try {
            $this->projectCycleContextToPassports();
        } catch (\Throwable $e) {
            // non-fatal
        }

        // Derive passive cycle hints from the projected context (best-effort, non-fatal).
        try {
            $this->projectCycleHintsToPassports();
        } catch (\Throwable $e) {
            // non-fatal
        }

        // Build passive cycle decision summary from context + hints (best-effort, non-fatal).
        try {
            $this->projectCycleSummaryToPassports();
        } catch (\Throwable $e) {
            // non-fatal
        }

        // Build passive cycle routing profile from cycle layers (best-effort, non-fatal).
        try {
            $this->projectCycleRoutingProfileToPassports();
        } catch (\Throwable $e) {
            // non-fatal
        }

        // Build passive cycle decision model from cycle layers (best-effort, non-fatal).
        try {
            $this->projectCycleDecisionModelToPassports();
        } catch (\Throwable $e) {
            // non-fatal
        }

        // Apply bounded cycle eligibility refinement from decision model (Coin Core Step 13).
        try {
            $this->applyCycleEligibilityRefinement();
        } catch (\Throwable $e) {
            // non-fatal
        }

        return $result;
    }

    /**
     * Rebuild passport for a single symbol.
     * Also called from trade-close hook for immediate update.
     *
     * @return array<string,mixed>
     */
    public function rebuildSymbol(string $symbol): array
    {
        $passport = $this->engine->rebuildSymbol(strtoupper($symbol));
        $this->saveStatus('rebuild_symbol:' . strtoupper($symbol), [
            'updated' => 1,
            'symbols' => [strtoupper($symbol)],
            'errors'  => [],
        ]);
        return $passport;
    }

    // =========================================================================
    // Status persistence
    // =========================================================================

    /**
     * Persist rebuild status to storage/status.json.
     *
     * @param array{updated:int,symbols:list<string>,errors:list<string>} $result
     */
    private function saveStatus(string $mode, array $result): void
    {
        $path = $this->storageDir . '/' . self::STATUS_FILE;
        $status = [
            'last_rebuild_at'     => date('Y-m-d H:i:s'),
            'last_rebuild_mode'   => $mode,
            'last_rebuild_status' => empty($result['errors']) ? 'ok' : 'partial_error',
            'last_rebuild_error'  => empty($result['errors']) ? null : implode('; ', $result['errors']),
            'last_updated_count'  => (int)($result['updated'] ?? 0),
            'storage_path'        => $this->storageDir . '/passports',
        ];
        @file_put_contents($path, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Return global market health summary across all passports.
     * Used by index UI (Phase 6) and Brain to gauge overall market conditions.
     *
     * @return array<string,mixed>
     */
    public function getMarketHealthSummary(): array
    {
        $passports = $this->engine->loadAll();
        $total = count($passports);

        $corridorHealthy  = 0;
        $runnerHealthy    = 0;
        $lowConfOnly      = 0;
        $shadowOnly       = 0;
        $simOnly          = 0;
        $allowLive        = 0;

        foreach ($passports as $p) {
            $p75    = (float)($p['corridor_p75_roi'] ?? $p['corridor_high_roi'] ?? 0);
            $runner = (float)($p['runner_probability'] ?? 0);
            $conf   = (string)($p['data_confidence'] ?? 'none');
            $elig   = (string)($p['recommended_live_eligibility'] ?? 'sim_only');

            if ($p75 >= 3.0) $corridorHealthy++;
            if ($runner >= 0.05) $runnerHealthy++;
            if ($conf === 'low' || $conf === 'none') $lowConfOnly++;
            if ($elig === 'shadow_only') $shadowOnly++;
            if ($elig === 'sim_only') $simOnly++;
            if ($elig === 'allow_live') $allowLive++;
        }

        return [
            'total_symbols'                => $total,
            'corridor_p75_healthy_count'   => $corridorHealthy,
            'runner_healthy_count'         => $runnerHealthy,
            'low_confidence_only_count'    => $lowConfOnly,
            'shadow_only_count'            => $shadowOnly,
            'sim_only_count'               => $simOnly,
            'allow_live_count'             => $allowLive,
        ];
    }

    // =========================================================================
    // API read path for Brain/Bot integration
    // =========================================================================

    /**
     * Build derived coin behavior cycle profiles from parser2_history_accumulator NDJSON data.
     * Data-layer only — does NOT feed into live admission or PM decisions yet.
     * Called by CronManager (coin_passport:buildCycleProfiles).
     *
     * @return array<string,mixed>
     */
    public function buildCycleProfiles(): array
    {
        // Resolve parser2 history storage via SystemPaths (project-standard resolver).
        // Key 'parser.parser2_history_accumulator.storage' is the canonical key used
        // by parser4, parser5, parser15, parser6_simulator, and simulator/controller.
        $parser2StorageDir = '';
        try {
            $paths = SystemPaths::instance();
            $parser2StorageDir = (string)$paths->get('parser.parser2_history_accumulator.storage');
            if ($parser2StorageDir === '') {
                // Fallback: base key + /storage
                $base = (string)$paths->get('parser.parser2_history_accumulator');
                if ($base !== '') {
                    $parser2StorageDir = rtrim($base, '/') . '/storage';
                }
            }
        } catch (\Throwable $e) {
            // SystemPaths not available — best-effort, non-fatal
        }
        $runtimeDir        = $this->storageDir . '/runtime';
        $outputPath        = $runtimeDir . '/coin_cycle_profile.json';

        try {
            $result = $this->engine->buildCoinCycleProfiles($parser2StorageDir, $outputPath);

            // Immediately derive the read model from the freshly written profile (best-effort, non-fatal).
            $readModelResult = [];
            try {
                $readModelPath   = $runtimeDir . '/coin_cycle_read_model.json';
                $readModelResult = $this->engine->buildCoinCycleReadModel($outputPath, $readModelPath);
            } catch (\Throwable $rmEx) {
                // non-fatal — counters will be zero
            }

            // Project the cycle context block into each passport (best-effort, non-fatal).
            $projectionResult = [];
            try {
                $projectionResult = $this->projectCycleContextToPassports();
            } catch (\Throwable $projEx) {
                // non-fatal
            }

            // Derive passive cycle hints from the projected context (best-effort, non-fatal).
            $hintsResult = [];
            try {
                $hintsResult = $this->projectCycleHintsToPassports();
            } catch (\Throwable $hintsEx) {
                // non-fatal
            }

            // Build passive cycle decision summary from context + hints (best-effort, non-fatal).
            $summaryResult = [];
            try {
                $summaryResult = $this->projectCycleSummaryToPassports();
            } catch (\Throwable $summaryEx) {
                // non-fatal
            }

            // Build passive cycle routing profile from cycle layers (best-effort, non-fatal).
            $routingResult = [];
            try {
                $routingResult = $this->projectCycleRoutingProfileToPassports();
            } catch (\Throwable $routingEx) {
                // non-fatal
            }

            // Build passive cycle decision model from cycle layers (best-effort, non-fatal).
            $decisionResult = [];
            try {
                $decisionResult = $this->projectCycleDecisionModelToPassports();
            } catch (\Throwable $decisionEx) {
                // non-fatal
            }

            // Apply bounded cycle eligibility refinement from decision model (Coin Core Step 13).
            $refinementResult = [];
            try {
                $refinementResult = $this->applyCycleEligibilityRefinement();
            } catch (\Throwable $refineEx) {
                // non-fatal
            }

            return [
                'ok'                              => true,
                'cycle_symbols_total'             => $result['cycle_symbols_total']            ?? 0,
                'cycle_profiles_generated_total'  => $result['cycle_profiles_generated_total'] ?? 0,
                'cycle_generation_error_total'    => $result['cycle_generation_error_total']   ?? 0,
                'generated_at'                    => $result['generated_at']                   ?? date('c'),
                'read_model_symbols_total'        => $readModelResult['read_model_symbols_total']   ?? 0,
                'read_model_generated_total'      => $readModelResult['read_model_generated_total'] ?? 0,
                'read_model_error_total'          => $readModelResult['read_model_error_total']     ?? 0,
                'projection_symbols_total'        => $projectionResult['symbols_total']    ?? 0,
                'projection_projected_total'      => $projectionResult['projected_total']  ?? 0,
                'projection_skipped_total'        => $projectionResult['skipped_total']    ?? 0,
                'projection_error_total'          => $projectionResult['error_total']      ?? 0,
                'hints_symbols_total'             => $hintsResult['symbols_total']         ?? 0,
                'hints_written_total'             => $hintsResult['hints_written_total']   ?? 0,
                'hints_error_total'               => $hintsResult['error_total']           ?? 0,
                'summary_symbols_total'           => $summaryResult['symbols_total']           ?? 0,
                'summary_written_total'           => $summaryResult['summaries_written_total'] ?? 0,
                'summary_error_total'             => $summaryResult['error_total']             ?? 0,
                'routing_symbols_total'           => $routingResult['symbols_total']           ?? 0,
                'routing_written_total'           => $routingResult['profiles_written_total']  ?? 0,
                'routing_error_total'             => $routingResult['error_total']             ?? 0,
                'decision_symbols_total'          => $decisionResult['symbols_total']          ?? 0,
                'decision_written_total'          => $decisionResult['models_written_total']   ?? 0,
                'decision_error_total'            => $decisionResult['error_total']            ?? 0,
                'refinement_symbols_total'        => $refinementResult['symbols_total']   ?? 0,
                'refinement_processed_total'      => $refinementResult['processed_total'] ?? 0,
                'refinement_upgrade_total'        => $refinementResult['upgrade_total']   ?? 0,
                'refinement_downgrade_total'      => $refinementResult['downgrade_total'] ?? 0,
                'refinement_no_effect_total'      => $refinementResult['no_effect_total'] ?? 0,
                'refinement_unavailable_total'    => $refinementResult['unavailable_total'] ?? 0,
                'refinement_error_total'          => $refinementResult['error_total']     ?? 0,
            ];
        } catch (\Throwable $e) {
            return [
                'ok'                             => false,
                'cycle_symbols_total'            => 0,
                'cycle_profiles_generated_total' => 0,
                'cycle_generation_error_total'   => 1,
                'error'                          => $e->getMessage(),
            ];
        }
    }

    /**
     * Build coin_cycle_read_model.json from the existing coin_cycle_profile.json.
     * Derives compact, decision-friendly per-symbol state summaries.
     * Data layer only — does NOT feed into live admission or Bot/PM decisions.
     * Called by CronManager (coin_passport:buildCycleReadModel).
     *
     * @return array<string,mixed>
     */
    public function buildCycleReadModel(): array
    {
        $runtimeDir    = $this->storageDir . '/runtime';
        $profilePath   = $runtimeDir . '/coin_cycle_profile.json';
        $readModelPath = $runtimeDir . '/coin_cycle_read_model.json';

        try {
            $result = $this->engine->buildCoinCycleReadModel($profilePath, $readModelPath);
            return [
                'ok'                         => true,
                'read_model_symbols_total'   => $result['read_model_symbols_total']   ?? 0,
                'read_model_generated_total' => $result['read_model_generated_total'] ?? 0,
                'read_model_error_total'     => $result['read_model_error_total']     ?? 0,
                'generated_at'               => $result['generated_at']               ?? date('c'),
            ];
        } catch (\Throwable $e) {
            return [
                'ok'                         => false,
                'read_model_symbols_total'   => 0,
                'read_model_generated_total' => 0,
                'read_model_error_total'     => 1,
                'error'                      => $e->getMessage(),
            ];
        }
    }

    /**
     * Project coin_cycle_read_model.json into each passport as a passive
     * 'coin_cycle_context' namespaced block.
     * Storage/read-side only — does NOT affect Bot, PM, or live admission.
     * Called after buildCycleProfiles() and after passport rebuilds.
     *
     * @return array<string,mixed>
     */
    public function projectCycleContextToPassports(): array
    {
        $runtimeDir   = $this->storageDir . '/runtime';
        $readModelPath = $runtimeDir . '/coin_cycle_read_model.json';
        $summaryPath   = $runtimeDir . '/coin_cycle_projection_summary.json';

        try {
            return $this->engine->projectCycleContextToPassports($readModelPath, $summaryPath);
        } catch (\Throwable $e) {
            return [
                'updated_at'           => date('c'),
                'source'               => 'coin_cycle_read_model',
                'symbols_total'        => 0,
                'projected_total'      => 0,
                'skipped_total'        => 0,
                'low_confidence_total' => 0,
                'error_total'          => 1,
                'error'                => $e->getMessage(),
            ];
        }
    }

    /**
     * Derive and write passive cycle eligibility hints into each passport.
     * Reads coin_cycle_context already projected into passports, derives coin_cycle_hints.
     * Storage/read-side only — does NOT affect Bot, PM, or live admission.
     * Called after projectCycleContextToPassports().
     *
     * @return array<string,mixed>
     */
    public function projectCycleHintsToPassports(): array
    {
        $runtimeDir  = $this->storageDir . '/runtime';
        $summaryPath = $runtimeDir . '/coin_cycle_hints_summary.json';

        try {
            return $this->engine->projectCycleHintsToPassports($summaryPath);
        } catch (\Throwable $e) {
            return [
                'updated_at'          => date('c'),
                'source'              => 'coin_cycle_context',
                'symbols_total'       => 0,
                'hints_written_total' => 0,
                'favorable_total'     => 0,
                'cautious_total'      => 0,
                'weak_total'          => 0,
                'unavailable_total'   => 0,
                'low_confidence_total' => 0,
                'error_total'         => 1,
                'error'               => $e->getMessage(),
            ];
        }
    }

    /**
     * Build and write a passive cycle decision summary into each passport.
     * Reads coin_cycle_context + coin_cycle_hints already in passports, derives coin_cycle_summary.
     * Storage/read-side only — does NOT affect Bot, PM, or live admission.
     * Called after projectCycleHintsToPassports().
     *
     * @return array<string,mixed>
     */
    public function projectCycleSummaryToPassports(): array
    {
        $runtimeDir  = $this->storageDir . '/runtime';
        $summaryPath = $runtimeDir . '/coin_cycle_summary_projection.json';

        try {
            return $this->engine->projectCycleSummaryToPassports($summaryPath);
        } catch (\Throwable $e) {
            return [
                'updated_at'              => date('c'),
                'source'                  => 'passport coin_cycle_context + coin_cycle_hints',
                'symbols_total'           => 0,
                'summaries_written_total' => 0,
                'favorable_total'         => 0,
                'cautious_total'          => 0,
                'weak_total'              => 0,
                'unavailable_total'       => 0,
                'actionable_total'        => 0,
                'non_actionable_total'    => 0,
                'low_confidence_total'    => 0,
                'error_total'             => 1,
                'error'                   => $e->getMessage(),
            ];
        }
    }

    /**
     * Build and write a passive cycle routing profile into each passport.
     * Reads coin_cycle_context + coin_cycle_hints + coin_cycle_summary from each passport,
     * derives coin_cycle_routing_profile, saves back.
     * Storage/read-side only — does NOT affect Bot, PM, or live admission.
     * Called after projectCycleSummaryToPassports().
     *
     * @return array<string,mixed>
     */
    public function projectCycleRoutingProfileToPassports(): array
    {
        $runtimeDir = $this->storageDir . '/runtime';
        $outputPath = $runtimeDir . '/coin_cycle_routing_profile_projection.json';

        try {
            return $this->engine->projectCycleRoutingProfileToPassports($outputPath);
        } catch (\Throwable $e) {
            return [
                'updated_at'             => date('c'),
                'source'                 => 'passport cycle layers',
                'symbols_total'          => 0,
                'profiles_written_total' => 0,
                'live_ready_total'       => 0,
                'demo_only_total'        => 0,
                'shadow_only_total'      => 0,
                'skip_total'             => 0,
                'low_confidence_total'   => 0,
                'error_total'            => 1,
                'error'                  => $e->getMessage(),
            ];
        }
    }

    /**
     * Build and write a passive cycle decision model into each passport.
     * Reads coin_cycle_context + coin_cycle_hints + coin_cycle_summary + coin_cycle_routing_profile
     * from each passport, derives coin_cycle_decision_model, saves back.
     * Storage/read-side only — does NOT affect Bot, PM, or live admission.
     * Called after projectCycleRoutingProfileToPassports().
     *
     * @return array<string,mixed>
     */
    public function projectCycleDecisionModelToPassports(): array
    {
        $runtimeDir = $this->storageDir . '/runtime';
        $outputPath = $runtimeDir . '/coin_cycle_decision_model_projection.json';

        try {
            return $this->engine->projectCycleDecisionModelToPassports($outputPath);
        } catch (\Throwable $e) {
            return [
                'updated_at'           => date('c'),
                'source'               => 'passport cycle layers',
                'symbols_total'        => 0,
                'models_written_total' => 0,
                'favorable_total'      => 0,
                'cautious_total'       => 0,
                'weak_total'           => 0,
                'unavailable_total'    => 0,
                'actionable_total'     => 0,
                'non_actionable_total' => 0,
                'live_bias_total'      => 0,
                'demo_bias_total'      => 0,
                'shadow_bias_total'    => 0,
                'skip_bias_total'      => 0,
                'low_confidence_total' => 0,
                'error_total'          => 1,
                'error'                => $e->getMessage(),
            ];
        }
    }

    /**
     * Apply bounded cycle-aware eligibility refinement to each passport.
     * Reads coin_cycle_decision_model already in each passport, applies conservative
     * bounded rules to refine recommended_live_eligibility (Coin Core Step 13).
     * Called after projectCycleDecisionModelToPassports().
     *
     * @return array<string,mixed>
     */
    public function applyCycleEligibilityRefinement(): array
    {
        $runtimeDir = $this->storageDir . '/runtime';
        $outputPath = $runtimeDir . '/coin_cycle_eligibility_refinement.json';

        try {
            return $this->engine->applyCycleEligibilityRefinement($outputPath);
        } catch (\Throwable $e) {
            return [
                'updated_at'      => date('c'),
                'source'          => 'passport coin_cycle_decision_model',
                'symbols_total'   => 0,
                'processed_total' => 0,
                'upgrade_total'   => 0,
                'downgrade_total' => 0,
                'no_effect_total' => 0,
                'unavailable_total' => 0,
                'error_total'     => 1,
                'error'           => $e->getMessage(),
            ];
        }
    }

    /**
     * Return a full guidance block for Brain to consume before signal approval.
     * Authoritative live eligibility gate output.
     *
     * @return array<string,mixed>
     */
    public function getGuidanceForSymbol(string $symbol): array
    {
        $passport = $this->engine->load(strtoupper($symbol));
        if ($passport === null) {
            return [
                'symbol'                       => strtoupper($symbol),
                'available'                    => false,
                'data_confidence'              => 'none',
                'recommended_live_eligibility' => 'shadow_only',
                'live_block_reason'            => 'no_passport',
                'insufficient_data_flag'       => true,
                'insufficient_data_reason'     => 'passport_not_found',
            ];
        }

        return [
            'symbol'                                => $passport['symbol'],
            'available'                             => true,
            // Eligibility gate output
            // States: shadow_only | sim_only | bootstrap_live | allow_live
            'recommended_live_eligibility'          => $passport['recommended_live_eligibility'] ?? 'sim_only',
            'live_block_reason'                     => $passport['live_block_reason'] ?? null,
            'passport_gate_state'                   => $passport['passport_gate_state'] ?? ($passport['recommended_live_eligibility'] ?? 'sim_only'),
            'passport_gate_reason_detail'           => $passport['passport_gate_reason_detail'] ?? ($passport['live_block_reason'] ?? null),
            // Data confidence
            'data_confidence'                       => $passport['data_confidence'],
            'insufficient_data_flag'                => $passport['insufficient_data_flag'] ?? false,
            'insufficient_data_reason'              => $passport['insufficient_data_reason'] ?? null,
            'fallback_mode'                         => $passport['fallback_mode'] ?? 'sim_only',
            'current_usable_samples'                => $passport['current_usable_samples'] ?? $passport['sample_size'] ?? 0,
            // Fresh-window sample counters (primary live gate inputs)
            'recent_samples_1h'                     => (int)($passport['recent_samples_1h']  ?? 0),
            'recent_samples_6h'                     => (int)($passport['recent_samples_6h']  ?? 0),
            'recent_samples_24h'                    => (int)($passport['recent_samples_24h'] ?? 0),
            'recent_samples_7d'                     => (int)($passport['recent_samples_7d']  ?? 0),
            // Derived sufficiency booleans
            'fresh_behavior_window_ok'              => (bool)($passport['fresh_behavior_window_ok'] ?? false),
            'behavior_context_7d_ok'                => (bool)($passport['behavior_context_7d_ok']   ?? false),
            // Corridor summary
            'corridor_p50_roi'                      => $passport['corridor_p50_roi'] ?? $passport['corridor_mid_roi'] ?? 0,
            'corridor_p75_roi'                      => $passport['corridor_p75_roi'] ?? $passport['corridor_high_roi'] ?? 0,
            'corridor_p90_roi'                      => $passport['corridor_p90_roi'] ?? $passport['p90_max_roi'] ?? 0,
            // Runner summary
            'runner_probability'                    => $passport['runner_probability'],
            'reach_5_roi_rate'                      => $passport['reach_5_roi_rate'] ?? null,
            'reach_10_roi_rate'                     => $passport['reach_10_roi_rate'] ?? null,
            // Impulse summary
            'impulse_strength_score'                => $passport['impulse_strength_score'] ?? null,
            'impulse_speed_score'                   => $passport['impulse_speed_score'] ?? null,
            'impulse_decay_score'                   => $passport['impulse_decay_score'] ?? null,
            'sustained_move_score'                  => $passport['sustained_move_score'] ?? null,
            // Pullback summary
            'pullback_severity_score'               => $passport['pullback_severity_score'] ?? null,
            'post_impulse_retrace_habit'            => $passport['post_impulse_retrace_habit'] ?? null,
            'deep_retrace_probability'              => $passport['deep_retrace_probability'] ?? null,
            // Pattern-specific summary
            'pattern_behavior'                      => $passport['pattern_behavior'] ?? null,
            // Regime summary
            'market_regime_health_score'            => $passport['market_regime_health_score'] ?? 0,
            'bear_regime_behavior_score'            => $passport['bear_regime_behavior_score'] ?? null,
            'high_vol_regime_behavior_score'        => $passport['high_vol_regime_behavior_score'] ?? null,
            // Scores
            'noise_score'                           => $passport['noise_score'] ?? 0,
            'short_suitability_score'               => $passport['short_suitability_score'] ?? 0,
            // Recommended stage thresholds
            'recommended_live_floor_roi'            => $passport['recommended_live_floor_roi'] ?? 0,
            'recommended_stage1_start_roi'          => $passport['recommended_stage1_start_roi'] ?? $passport['recommended_stage1_threshold_roi'] ?? 0,
            'recommended_stage2_start_roi'          => $passport['recommended_stage2_start_roi'] ?? $passport['recommended_stage2_threshold_roi'] ?? 0,
            'recommended_harvest_aggressiveness'    => $passport['recommended_harvest_aggressiveness'],
            'recommended_max_hold_minutes'          => $passport['recommended_max_hold_minutes'] ?? null,
            'recommended_runner_expectation'        => $passport['recommended_runner_expectation'] ?? null,
            // Legacy
            'sample_size'                           => $passport['sample_size'] ?? 0,
            'updated_at'                            => $passport['updated_at'],
        ];
    }
}
