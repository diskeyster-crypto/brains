<?php
declare(strict_types=1);

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
            'recommended_live_eligibility'          => $passport['recommended_live_eligibility'] ?? 'sim_only',
            'live_block_reason'                     => $passport['live_block_reason'] ?? null,
            // Data confidence
            'data_confidence'                       => $passport['data_confidence'],
            'insufficient_data_flag'                => $passport['insufficient_data_flag'] ?? false,
            'insufficient_data_reason'              => $passport['insufficient_data_reason'] ?? null,
            'fallback_mode'                         => $passport['fallback_mode'] ?? 'sim_only',
            'current_usable_samples'                => $passport['current_usable_samples'] ?? $passport['sample_size'] ?? 0,
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
