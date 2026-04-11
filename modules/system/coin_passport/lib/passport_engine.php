<?php
declare(strict_types=1);

/**
 * CoinPassportEngine
 *
 * Builds and updates persistent per-symbol coin passports.
 * Reads trade data from trading_bot storage and computes deep per-symbol
 * analytics including ROI corridor, reach rates, SL rates, hold times,
 * data sufficiency, and live eligibility decisions.
 *
 * Storage: modules/system/coin_passport/storage/passports/{SYMBOL}.json
 * This storage is NEVER wiped by bot/brain runtime clears.
 */
final class CoinPassportEngine
{
    private string $passportsDir;
    private string $tradingBotStorageDir;
    private string $evidenceDir;
    private string $aiShadowStorageDir;

    /** Minimum trades to compute meaningful confidence */
    private const MIN_SAMPLE_MEDIUM = 5;
    private const MIN_SAMPLE_HIGH   = 20;

    /** Data sufficiency thresholds */
    private const MIN_TOTAL_SAMPLES        = 10;
    private const MIN_PATTERN_V2_SAMPLES   = 5;
    private const MIN_PATTERN_V3_SAMPLES   = 5;
    private const MIN_RECENT_SAMPLES       = 3;

    /** Trust state promotion thresholds (healthy closed = non-orphan demo/live closes) */
    private const MIN_HEALTHY_CLOSED_FOR_YELLOW = 1;
    private const MIN_HEALTHY_CLOSED_FOR_GREEN  = 6;

    /** Live eligibility gate thresholds (defaults — override via config if needed) */
    private const LIVE_GATE_CORRIDOR_P75_MIN      = 3.0;   // corridor_p75_roi >= this
    private const LIVE_GATE_RUNNER_PROB_MIN        = 0.05;  // runner_probability >= this
    private const LIVE_GATE_SUITABILITY_MIN        = 0.3;   // short_suitability_score >= this
    private const LIVE_GATE_NOISE_MAX              = 0.65;  // noise_score <= this
    private const LIVE_GATE_CONFIDENCE_MIN         = 'low'; // data_confidence: none→low→medium→high
    private const LIVE_GATE_REGIME_HEALTH_MIN      = 0.3;   // market_regime_health_score >= this
    private const LIVE_GATE_IMPULSE_STRENGTH_MIN   = 0.2;   // impulse_strength_score >= this
    private const LIVE_GATE_PULLBACK_SEVERITY_MAX  = 0.75;  // pullback_severity_score <= this

    /**
     * Fresh-window observation buckets (seconds).
     * Only data within these windows is considered for live admission decisions.
     * Data older than WINDOW_7D must not be a primary admission driver.
     */
    private const WINDOW_1H  = 3600;
    private const WINDOW_6H  = 21600;
    private const WINDOW_24H = 86400;
    private const WINDOW_7D  = 604800;

    /**
     * Fresh-window sample thresholds for live eligibility states.
     *
     * States produced by the gate chain:
     *   shadow_only    – no recent data at all (24h=0, 7d=0) or no confidence
     *   sim_only       – 7d has data but 24h is dead, or hard metric failure
     *   bootstrap_live – 24h has >= MIN_SAMPLES_24H_FOR_BOOTSTRAP samples and
     *                    metric gates pass but not enough for full allow_live
     *   allow_live     – 24h >= MIN_SAMPLES_24H_FOR_ALLOW_LIVE AND
     *                    7d >= MIN_SAMPLES_7D_FOR_CONTEXT AND metric gates pass
     */
    private const MIN_SAMPLES_24H_FOR_BOOTSTRAP  = 1; // >=1 24h sample → bootstrap_live candidate
    private const MIN_SAMPLES_24H_FOR_ALLOW_LIVE = 2; // >=2 24h samples → allow_live candidate
    private const MIN_SAMPLES_7D_FOR_CONTEXT     = 3; // >=3 7d samples → 7d behavior context present

    /** Evidence timeline config */
    private const MAX_EVIDENCE_ITEMS = 100;

    /** @var list<array{path:string,label:string}> Mode-separated bot storage directories */
    private array $botStorageDirs;

    public function __construct(string $passportsDir, string $tradingBotStorageDir, string $aiShadowStorageDir = '')
    {
        $this->passportsDir         = $passportsDir;
        $this->tradingBotStorageDir = $tradingBotStorageDir;
        $this->aiShadowStorageDir   = $aiShadowStorageDir;
        $this->evidenceDir          = dirname($passportsDir) . '/evidence';

        // Build multi-mode bot storage list: include storage_demo, storage_live, storage_paper,
        // and also the legacy storage/ path for backwards compatibility.
        $botBase = dirname($tradingBotStorageDir);
        $this->botStorageDirs = [];

        foreach (['storage_demo' => 'demo', 'storage_live' => 'live', 'storage_paper' => 'paper'] as $dir => $label) {
            $path = $botBase . '/' . $dir;
            if (is_dir($path)) {
                $this->botStorageDirs[] = ['path' => $path, 'label' => $label];
            }
        }

        // Always include legacy storage/ path as 'live' fallback so existing data is never lost
        if (is_dir($tradingBotStorageDir)) {
            $this->botStorageDirs[] = ['path' => $tradingBotStorageDir, 'label' => 'live'];
        }

        if (!is_dir($this->passportsDir)) {
            @mkdir($this->passportsDir, 0755, true);
        }
        if (!is_dir($this->evidenceDir)) {
            @mkdir($this->evidenceDir, 0755, true);
        }
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Return all passports, sorted by symbol.
     *
     * @return array<string,array<string,mixed>>
     */
    public function loadAll(): array
    {
        $passports = [];
        if (!is_dir($this->passportsDir)) {
            return $passports;
        }

        foreach (glob($this->passportsDir . '/*.json') ?: [] as $file) {
            $symbol = basename($file, '.json');
            $data   = $this->readJson($file);
            if (is_array($data) && !empty($data)) {
                $passports[$symbol] = $data;
            }
        }

        ksort($passports);
        return $passports;
    }

    /**
     * Load a single passport by symbol.
     *
     * @return array<string,mixed>|null
     */
    public function load(string $symbol): ?array
    {
        $file = $this->passportPath($symbol);
        if (!is_file($file)) {
            return null;
        }
        $data = $this->readJson($file);
        return is_array($data) ? $data : null;
    }

    /**
     * Rebuild passports for all symbols found in trade data.
     *
     * @return array{updated:int,symbols:list<string>,errors:list<string>,bot_storage_namespaces_scanned:list<string>,demo_samples_count:int,live_samples_count:int,shadow_samples_count:int}
     */
    public function rebuildAll(): array
    {
        $tradesBySymbol = $this->collectTradesBySymbol();
        $result = [
            'updated'  => 0,
            'symbols'  => [],
            'errors'   => [],
            'bot_storage_namespaces_scanned' => array_column($this->botStorageDirs, 'label'),
            'demo_samples_count'   => 0,
            'live_samples_count'   => 0,
            'shadow_samples_count' => 0,
            'passport_symbols_updated_from_demo'  => 0,
            'passport_demo_samples_added'         => 0,
            'passport_confidence_upgrades_count'  => 0,
            // Trust state distribution after rebuild
            'trust_state_green'            => 0,
            'trust_state_yellow'           => 0,
            'trust_state_red'              => 0,
            'trust_state_insufficient_data' => 0,
            // Promotion / demotion counters
            'promoted_to_yellow_total'     => 0,
            'promoted_to_green_total'      => 0,
            'demoted_to_yellow_total'      => 0,
            'demoted_to_red_total'         => 0,
        ];

        foreach ($tradesBySymbol as $symbol => $trades) {
            $symbolDemoCount = 0;
            foreach ($trades as $t) {
                $src = (string)($t['_source'] ?? '');
                if (strncmp($src, 'demo', 4) === 0) {
                    $result['demo_samples_count']++;
                    $symbolDemoCount++;
                } elseif (strncmp($src, 'shadow', 6) === 0) {
                    $result['shadow_samples_count']++;
                } else {
                    $result['live_samples_count']++;
                }
            }
            try {
                $oldPassport  = $this->readJson($this->passportPath($symbol));
                $oldTrustState = is_array($oldPassport) ? (string)($oldPassport['trust_state'] ?? '') : '';
                $passport = $this->buildPassport($symbol, $trades);
                $this->save($symbol, $passport);
                $this->rebuildEvidenceTimeline($symbol, $trades);
                $result['updated']++;
                $result['symbols'][] = $symbol;
                if ($symbolDemoCount > 0) {
                    $result['passport_symbols_updated_from_demo']++;
                    $result['passport_confidence_upgrades_count']++;
                }
                // Track trust state distribution and transitions
                $newTrustState = (string)($passport['trust_state'] ?? 'insufficient_data');
                $distKey = 'trust_state_' . str_replace('_', '_', $newTrustState);
                if (array_key_exists($distKey, $result)) {
                    $result[$distKey]++;
                }
                if ($oldTrustState !== '' && $oldTrustState !== $newTrustState) {
                    if ($newTrustState === 'yellow' && in_array($oldTrustState, ['insufficient_data', 'red'], true)) {
                        $result['promoted_to_yellow_total']++;
                    } elseif ($newTrustState === 'green' && $oldTrustState !== 'green') {
                        $result['promoted_to_green_total']++;
                    } elseif ($newTrustState === 'yellow' && $oldTrustState === 'green') {
                        $result['demoted_to_yellow_total']++;
                    } elseif ($newTrustState === 'red' && in_array($oldTrustState, ['green', 'yellow'], true)) {
                        $result['demoted_to_red_total']++;
                    }
                }
            } catch (\Throwable $e) {
                $result['errors'][] = $symbol . ': ' . $e->getMessage();
            }
        }

        $result['passport_demo_samples_added'] = $result['demo_samples_count'];

        // Migrate existing passport files that were not covered by trade-based rebuild
        // (e.g. symbols with no trades in the current storage scan). Every stored passport
        // must contain trust_state. Rebuild those that are missing it.
        $result['trust_state_migrated'] = 0;
        foreach (glob($this->passportsDir . '/*.json') ?: [] as $file) {
            $sym = basename($file, '.json');
            if (in_array($sym, $result['symbols'], true)) {
                continue; // already rebuilt above
            }
            $existing = $this->readJson($file);
            if (!is_array($existing) || array_key_exists('trust_state', $existing)) {
                continue; // nothing to fix
            }
            try {
                $trades   = $tradesBySymbol[$sym] ?? [];
                $passport = $this->buildPassport($sym, $trades);
                $this->save($sym, $passport);
                $result['updated']++;
                $result['symbols'][] = $sym;
                $result['trust_state_migrated']++;
            } catch (\Throwable $e) {
                $result['errors'][] = $sym . ' (migrate): ' . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Rebuild passport for a single symbol.
     *
     * @return array<string,mixed>
     */
    public function rebuildSymbol(string $symbol): array
    {
        $tradesBySymbol = $this->collectTradesBySymbol();
        $trades = $tradesBySymbol[$symbol] ?? [];
        $passport = $this->buildPassport($symbol, $trades);
        $this->save($symbol, $passport);
        $this->rebuildEvidenceTimeline($symbol, $trades);
        return $passport;
    }

    /**
     * Load the evidence timeline for a symbol (last MAX_EVIDENCE_ITEMS items).
     *
     * @return list<array<string,mixed>>
     */
    public function loadEvidence(string $symbol): array
    {
        $path = $this->evidencePath($symbol);
        $data = $this->readJson($path);
        if (!is_array($data) || !isset($data['items'])) {
            return [];
        }
        return is_array($data['items']) ? $data['items'] : [];
    }

    /**
     * Append a single evidence event for a symbol (called on trade close etc.).
     *
     * @param array<string,mixed> $event
     */
    public function appendEvidence(string $symbol, array $event): void
    {
        $path  = $this->evidencePath($symbol);
        $data  = $this->readJson($path) ?? ['symbol' => $symbol, 'items' => []];
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];

        // Prepend newest events first
        array_unshift($items, array_merge(['ts' => time(), 'symbol' => $symbol], $event));

        // Cap to MAX_EVIDENCE_ITEMS
        if (count($items) > self::MAX_EVIDENCE_ITEMS) {
            $items = array_slice($items, 0, self::MAX_EVIDENCE_ITEMS);
        }

        $data['symbol']     = $symbol;
        $data['items']      = $items;
        $data['updated_at'] = date('Y-m-d H:i:s');

        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    // =========================================================================
    // Profit Manager passive write-back
    // =========================================================================

    /**
     * Passively write Profit Manager observation stats into a symbol's passport.
     *
     * Only updates the profit_manager_stats block — all other passport fields are
     * left untouched. Creates a minimal passport stub if none exists yet for the symbol.
     *
     * Non-fatal by design: returns false instead of throwing on any I/O error.
     *
     * @param string              $symbol Upper-case symbol, e.g. 'BTCUSDT'
     * @param array<string,mixed> $stats  Compact PM observation summary
     * @return bool  true on success, false on any failure
     */
    public function updateProfitManagerStats(string $symbol, array $stats): bool
    {
        $symbol = preg_replace('/[^A-Z0-9_\-]/', '', strtoupper($symbol));
        if ($symbol === '') {
            return false;
        }

        if (!is_dir($this->passportsDir)) {
            @mkdir($this->passportsDir, 0755, true);
        }

        $path     = $this->passportPath($symbol);
        $existing = $this->readJson($path);

        // If no passport exists yet, bootstrap a real minimal passport using the canonical
        // shape from buildPassport() with an empty trades array. This ensures PM write-back
        // always produces a proper passport file, not a bare PM-only JSON blob.
        if ($existing === null || !is_array($existing) || empty($existing)) {
            $existing = $this->buildPassport($symbol, []);
        }

        // Merge (not overwrite) the namespaced PM block cumulatively across runs.
        // All other passport fields are left completely untouched.
        $existing['profit_manager_stats'] = $this->mergePmStats(
            is_array($existing['profit_manager_stats'] ?? null) ? $existing['profit_manager_stats'] : [],
            $stats
        );

        // Atomic write: write to .tmp then rename
        $tmp = $path . '.pmtmp.' . getmypid();
        $json = json_encode(
            $existing,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) . "\n";

        $written = @file_put_contents($tmp, $json, LOCK_EX);
        if ($written === false) {
            @unlink($tmp);
            return false;
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    /**
     * Merge current-run PM stats into existing cumulative profit_manager_stats.
     *
     * Counters are summed. Averages are computed via weighted math using the
     * sample counts stored alongside each average:
     *   - avg_peak_roi / avg_current_roi          → weighted by samples_total
     *   - avg_proposed_lock_roi                   → weighted by samples_with_lock_roi
     *   - avg_post_lock_extension_roi / avg_stop_gap_difference_pct / avg_lock_difference_roi
     *                                             → weighted by comparison_matches_found_total
     * max_post_lock_extension_roi is the running maximum across all runs.
     * last_updated_at always reflects the latest successful merge.
     *
     * @param array $prev  Existing profit_manager_stats from passport (may be empty)
     * @param array $curr  Stats from the current PM run (from aggregatePmStatsBySymbol)
     * @return array       Merged cumulative stats
     */
    private function mergePmStats(array $prev, array $curr): array
    {
        $prevTotal = (int)($prev['samples_total'] ?? 0);
        $currTotal = (int)($curr['samples_total'] ?? 0);

        // If no prior history exists, treat the current run as the initial write
        if ($prevTotal <= 0) {
            return $curr;
        }

        // If the current run produced no meaningful data, preserve previous state
        if ($currTotal <= 0) {
            return $prev;
        }

        $merged = [];

        // ── Cumulative integer counters ─────────────────────────────────────
        $merged['samples_total']                  = $prevTotal + $currTotal;
        $merged['samples_profitable']             = (int)($prev['samples_profitable'] ?? 0)             + (int)($curr['samples_profitable'] ?? 0);
        $merged['samples_armed']                  = (int)($prev['samples_armed'] ?? 0)                  + (int)($curr['samples_armed'] ?? 0);
        $merged['samples_tightened']              = (int)($prev['samples_tightened'] ?? 0)              + (int)($curr['samples_tightened'] ?? 0);
        $merged['samples_exit_ready']             = (int)($prev['samples_exit_ready'] ?? 0)             + (int)($curr['samples_exit_ready'] ?? 0);
        $merged['comparison_samples_total']       = (int)($prev['comparison_samples_total'] ?? 0)       + (int)($curr['comparison_samples_total'] ?? 0);
        $merged['comparison_matches_found_total'] = (int)($prev['comparison_matches_found_total'] ?? 0) + (int)($curr['comparison_matches_found_total'] ?? 0);
        $merged['comparison_unavailable_total']   = (int)($prev['comparison_unavailable_total'] ?? 0)   + (int)($curr['comparison_unavailable_total'] ?? 0);

        // Denominator counts for sub-set averages (stored so future merges stay correct)
        $prevLockCount = (int)($prev['samples_with_lock_roi'] ?? 0);
        $currLockCount = (int)($curr['samples_with_lock_roi'] ?? 0);
        $merged['samples_with_lock_roi'] = $prevLockCount + $currLockCount;

        // comparison_matches_found_total doubles as the denominator for comparison-based averages
        $prevCmpCount = (int)($prev['comparison_matches_found_total'] ?? 0); // value before merge
        $currCmpCount = (int)($curr['comparison_matches_found_total'] ?? 0);

        // ── Weighted averages (weight = samples_total) ───────────────────────
        $merged['avg_peak_roi']     = $this->weightedAvgNullable($prev['avg_peak_roi'],     $prevTotal,    $curr['avg_peak_roi'],     $currTotal);
        $merged['avg_current_roi']  = $this->weightedAvgNullable($prev['avg_current_roi'],  $prevTotal,    $curr['avg_current_roi'],  $currTotal);

        // ── Weighted average (weight = samples_with_lock_roi) ────────────────
        $merged['avg_proposed_lock_roi'] = $this->weightedAvgNullable($prev['avg_proposed_lock_roi'], $prevLockCount, $curr['avg_proposed_lock_roi'], $currLockCount);

        // ── Weighted averages (weight = comparison_matches_found_total) ───────
        $merged['avg_post_lock_extension_roi']  = $this->weightedAvgNullable($prev['avg_post_lock_extension_roi'],  $prevCmpCount, $curr['avg_post_lock_extension_roi'],  $currCmpCount);
        $merged['avg_stop_gap_difference_pct']  = $this->weightedAvgNullable($prev['avg_stop_gap_difference_pct'],  $prevCmpCount, $curr['avg_stop_gap_difference_pct'],  $currCmpCount);
        $merged['avg_lock_difference_roi']      = $this->weightedAvgNullable($prev['avg_lock_difference_roi'],      $prevCmpCount, $curr['avg_lock_difference_roi'],      $currCmpCount);

        // ── Running maximum ──────────────────────────────────────────────────
        $prevMax = $prev['max_post_lock_extension_roi'] ?? null;
        $currMax = $curr['max_post_lock_extension_roi'] ?? null;
        if ($prevMax === null) {
            $merged['max_post_lock_extension_roi'] = $currMax;
        } elseif ($currMax === null) {
            $merged['max_post_lock_extension_roi'] = $prevMax;
        } else {
            $merged['max_post_lock_extension_roi'] = max((float)$prevMax, (float)$currMax);
        }

        // ── Early-close risk score: recomputed from merged avg_post_lock_extension_roi ──
        $avgExt = (float)($merged['avg_post_lock_extension_roi'] ?? 0.0);
        $merged['early_close_risk_score'] = $avgExt > 0.0 ? round(min(1.0, $avgExt / 5.0), 4) : 0.0;

        // ── Timestamp always reflects the latest successful merge ─────────────
        $merged['last_updated_at'] = $curr['last_updated_at'] ?? date('c');

        return $merged;
    }

    /**
     * Compute a weighted average of two nullable float values.
     *
     * Falls back gracefully when one side has no weight or no value.
     * Returns null only when both inputs are null.
     * Rounds to 4 decimal places.
     *
     * @param mixed $prevVal    Previous average (float|null)
     * @param int   $prevWeight Sample count for $prevVal
     * @param mixed $currVal    Current average (float|null)
     * @param int   $currWeight Sample count for $currVal
     */
    private function weightedAvgNullable(mixed $prevVal, int $prevWeight, mixed $currVal, int $currWeight): ?float
    {
        $prevF = ($prevVal !== null) ? (float)$prevVal : null;
        $currF = ($currVal !== null) ? (float)$currVal : null;

        if ($prevF === null && $currF === null) {
            return null;
        }
        if ($prevF === null || $prevWeight <= 0) {
            return $currF !== null ? round($currF, 4) : null;
        }
        if ($currF === null || $currWeight <= 0) {
            return round($prevF, 4);
        }

        $total = $prevWeight + $currWeight;
        return round(($prevF * $prevWeight + $currF * $currWeight) / $total, 4);
    }

    // =========================================================================
    // Data collection
    // =========================================================================

    /**
     * Collect all available trades grouped by symbol.
     *
     * Sources (in order):
     *   1. trades/closed/*.json  from each bot storage namespace (demo, live, paper, legacy)
     *   2. trades/active/*.json  from each bot storage namespace (partial signal)
     *   3. sig_*.json in legacy storage root
     *   4. ai_shadow/virtual_trades_closed/*.json  (shadow sim outcomes)
     *   5. ai_shadow/virtual_trades_active/*.json  (shadow partial signal)
     *
     * Source labels per namespace:
     *   demo storage  → demo_closed / demo_active
     *   live storage  → live_closed / live_active / live_legacy
     *   paper storage → paper_closed / paper_active
     *
     * @return array<string,list<array<string,mixed>>>
     */
    private function collectTradesBySymbol(): array
    {
        $bySymbol = [];

        // Sources 1+2: scan all mode-separated bot storage directories
        foreach ($this->botStorageDirs as $dirInfo) {
            $storePath = $dirInfo['path'];
            $modeLabel = $dirInfo['label']; // demo | live | paper

            // Closed trades
            $closedDir = $storePath . '/trades/closed';
            if (is_dir($closedDir)) {
                foreach (glob($closedDir . '/*.json') ?: [] as $file) {
                    $trade = $this->readJson($file);
                    if (is_array($trade) && !empty($trade['symbol'])) {
                        $sym = strtoupper((string)$trade['symbol']);
                        $trade['_source'] = $modeLabel . '_closed';
                        $bySymbol[$sym][] = $trade;
                    }
                }
            }

            // Active trades (partial signal — adds recency evidence)
            $activeDir = $storePath . '/trades/active';
            if (is_dir($activeDir)) {
                foreach (glob($activeDir . '/*.json') ?: [] as $file) {
                    $trade = $this->readJson($file);
                    if (is_array($trade) && !empty($trade['symbol'])) {
                        $sym = strtoupper((string)$trade['symbol']);
                        $trade['_source'] = $modeLabel . '_active';
                        $bySymbol[$sym][] = $trade;
                    }
                }
            }
        }

        // Source 3: root sig_*.json files in the legacy storage path (active / legacy trades)
        foreach (glob($this->tradingBotStorageDir . '/sig_*.json') ?: [] as $file) {
            $trade = $this->readJson($file);
            if (is_array($trade) && !empty($trade['symbol'])) {
                $sym = strtoupper((string)$trade['symbol']);
                $trade['_source'] = 'live_legacy';
                $bySymbol[$sym][] = $trade;
            }
        }

        // Source 4: AI shadow virtual closed trades (additional signal)
        if ($this->aiShadowStorageDir !== '' && is_dir($this->aiShadowStorageDir)) {
            $shadowClosedDir = $this->aiShadowStorageDir . '/virtual_trades_closed';
            if (is_dir($shadowClosedDir)) {
                foreach (glob($shadowClosedDir . '/*.json') ?: [] as $file) {
                    $trade = $this->readJson($file);
                    if (is_array($trade) && !empty($trade['symbol'])) {
                        $sym = strtoupper((string)$trade['symbol']);
                        $trade['_source'] = 'shadow_closed';
                        $bySymbol[$sym][] = $trade;
                    }
                }
            }

            // Source 5: AI shadow virtual active trades (partial signal — adds recency evidence)
            $shadowActiveDir = $this->aiShadowStorageDir . '/virtual_trades_active';
            if (is_dir($shadowActiveDir)) {
                foreach (glob($shadowActiveDir . '/*.json') ?: [] as $file) {
                    $trade = $this->readJson($file);
                    if (is_array($trade) && !empty($trade['symbol'])) {
                        $sym = strtoupper((string)$trade['symbol']);
                        $trade['_source'] = 'shadow_active';
                        $bySymbol[$sym][] = $trade;
                    }
                }
            }
        }

        return $bySymbol;
    }

    // =========================================================================
    // Passport building
    // =========================================================================

    /**
     * Build a complete deep behavioral profile passport for one symbol from its trades.
     *
     * @param list<array<string,mixed>> $trades
     * @return array<string,mixed>
     */
    private function buildPassport(string $symbol, array $trades): array
    {
        $sampleSizeTotal = count($trades);

        // Per-pattern sample counts (V2/V3, both sides)
        $sampleV2 = 0;
        $sampleV3 = 0;

        // Core metric arrays (all trades)
        $maxRois      = [];
        $finalRois    = [];
        $adverseRois  = [];
        $pullbacksAt2 = [];
        $pullbacksAt3 = [];
        $pullbacksAt5 = [];
        $holdMinutes  = [];
        $timeTo2Roi   = [];
        $timeTo5Roi   = [];
        $timeTo10Roi  = [];
        $pricePctMoves = [];

        // Pullback-from-peak arrays
        $pullbacksFromPeak = []; // max_roi - final_roi for every trade
        $deepRetraces      = 0;  // gave back >70% of peak

        // V2 / V3 pattern-specific arrays
        $v2MaxRois   = []; $v2FinalRois = []; $v2SlHits = 0; $v2Runners = 0;
        $v3MaxRois   = []; $v3FinalRois = []; $v3SlHits = 0; $v3Runners = 0;

        // Session / hour-of-day stats: hour (0–23) → [count, roi_sum, runner_count, sl_count, fake_count]
        $hourStats = [];

        // Counters
        $runners  = 0;
        $shorts   = 0;
        $longs    = 0;
        $slHits   = 0;
        $reach5   = 0;
        $reach10  = 0;
        $reach15  = 0;
        $failBefore3 = 0;
        $shadowSamples         = 0;  // from AI shadow source (closed + active)
        $shadowClosedSamples   = 0;  // shadow_closed only
        $shadowActiveSamples   = 0;  // shadow_active only
        $liveClosedSamples     = 0;  // live_closed only
        $liveActiveSamples     = 0;  // live_active + live_legacy
        $demoClosedSamples     = 0;  // demo_closed only
        $demoActiveSamples     = 0;  // demo_active only
        $healthyClosedSamples  = 0;  // non-orphan demo_closed + live_closed (used for trust promotion)

        // Initial burst: hit 2 ROI within the first half of hold time
        $burstCount = 0;
        $burstTotal = 0;

        // Fresh-window recent sample counters.
        // These drive live eligibility decisions — only data within each window is counted.
        // 30d bucket is kept separately for computeMarketRegimeHealthScore() only
        // (non-admission use); it must NOT be used as a primary live gate.
        $now           = time();
        $cutoff1h      = $now - self::WINDOW_1H;
        $cutoff6h      = $now - self::WINDOW_6H;
        $cutoff24h     = $now - self::WINDOW_24H;
        $cutoff7d      = $now - self::WINDOW_7D;
        $cutoff30d     = $now - 30 * 86400;
        $recentSamples    = 0;  // 30d — used only for computeMarketRegimeHealthScore
        $recentSamples1h  = 0;
        $recentSamples6h  = 0;
        $recentSamples24h = 0;
        $recentSamples7d  = 0;

        foreach ($trades as $trade) {
            $source  = (string)($trade['_source'] ?? 'live_closed');
            $isLive  = strncmp($source, 'live', 4) === 0;
            $isShadow = $source === 'shadow_closed' || $source === 'shadow_active';
            $isShadowClosed = $source === 'shadow_closed';
            $isShadowActive = $source === 'shadow_active';

            if ($isShadowClosed) {
                $shadowSamples++;
                $shadowClosedSamples++;
            } elseif ($isShadowActive) {
                $shadowSamples++;
                $shadowActiveSamples++;
            } elseif ($source === 'demo_closed') {
                $demoClosedSamples++;
            } elseif ($source === 'demo_active') {
                $demoActiveSamples++;
            } elseif ($source === 'live_closed') {
                $liveClosedSamples++;
            } elseif ($source === 'live_active' || $source === 'live_legacy') {
                $liveActiveSamples++;
            }

            // Non-orphan healthy closed samples (protect against orphan-only promotion)
            if (($source === 'demo_closed' || $source === 'live_closed')
                && empty($trade['is_orphan_adopted'])
                && empty($trade['adopted_from_exchange_orphan'])) {
                $healthyClosedSamples++;
            }

            $finalRoi   = $this->extractFinalRoi($trade);
            $maxRoi     = $this->extractPeakRoi($trade, $finalRoi);
            $adverseRoi = $this->extractAdverseRoi($trade);
            $side       = strtolower((string)($trade['side'] ?? ''));
            $patternAlgo = (string)($trade['pattern_algorithm'] ?? '');
            $closedTs   = (int)($trade['closed_ts'] ?? $trade['closed_at'] ?? 0);
            $openTs     = (int)($trade['open_ts'] ?? $trade['opened_at'] ?? $trade['created_ts'] ?? 0);

            // Pattern counts (V2/V3, both sides)
            if ($side === 'short') {
                $shorts++;
                if ($patternAlgo === 'double_top_contextual_v2') {
                    $sampleV2++;
                } elseif ($patternAlgo === 'double_top_contextual_v3') {
                    $sampleV3++;
                }
            } elseif ($side === 'long') {
                $longs++;
                if ($patternAlgo === 'double_bottom_contextual_v2') {
                    $sampleV2++;
                } elseif ($patternAlgo === 'double_bottom_contextual_v3') {
                    $sampleV3++;
                }
            }

            if ($closedTs >= $cutoff30d || ($openTs >= $cutoff30d && $openTs > 0)) {
                // Assign to the finest bucket whose cutoff the trade's effective timestamp satisfies.
                $tradeTs = $closedTs > 0 ? $closedTs : ($openTs > 0 ? $openTs : 0);
                $recentSamples++;
                if ($tradeTs >= $cutoff1h) {
                    $recentSamples1h++;
                    $recentSamples6h++;
                    $recentSamples24h++;
                    $recentSamples7d++;
                } elseif ($tradeTs >= $cutoff6h) {
                    $recentSamples6h++;
                    $recentSamples24h++;
                    $recentSamples7d++;
                } elseif ($tradeTs >= $cutoff24h) {
                    $recentSamples24h++;
                    $recentSamples7d++;
                } elseif ($tradeTs >= $cutoff7d) {
                    $recentSamples7d++;
                }
            }

            if ($finalRoi !== null) {
                $finalRois[] = $finalRoi;
            }
            if ($adverseRoi !== null) {
                $adverseRois[] = $adverseRoi;
            }

            // Stop-loss detection
            $isSlHit = $this->detectStopLossHit($trade, $finalRoi, $maxRoi);
            if ($isSlHit) {
                $slHits++;
            }

            if ($maxRoi !== null) {
                $maxRois[] = $maxRoi;

                if ($maxRoi >= 5.0)  $reach5++;
                if ($maxRoi >= 10.0) { $reach10++; $runners++; }
                if ($maxRoi >= 15.0) $reach15++;

                if ($finalRoi !== null) {
                    // Failure before 3 ROI
                    if ($maxRoi < 3.0 && $finalRoi <= 0.0) {
                        $failBefore3++;
                    }

                    // Pullback-from-peak (all trades)
                    $pbFromPeak = max(0.0, $maxRoi - $finalRoi);
                    $pullbacksFromPeak[] = $pbFromPeak;

                    // Deep retrace: gave back >70% of peak
                    if ($maxRoi > 0 && $pbFromPeak / $maxRoi > 0.70) {
                        $deepRetraces++;
                    }

                    // Milestone pullbacks
                    if ($maxRoi >= 2.0) {
                        $pullbacksAt2[] = $pbFromPeak;
                    }
                    if ($maxRoi >= 3.0) {
                        $pullbacksAt3[] = $pbFromPeak;
                    }
                    if ($maxRoi >= 5.0) {
                        $pullbacksAt5[] = $pbFromPeak;
                    }
                }

                // Pattern-specific tracking (live trades only for clean stats)
                if ($isLive) {
                    if ($patternAlgo === 'double_top_contextual_v2' || $patternAlgo === 'double_bottom_contextual_v2') {
                        $v2MaxRois[]   = $maxRoi;
                        if ($finalRoi !== null) $v2FinalRois[] = $finalRoi;
                        if ($isSlHit)  $v2SlHits++;
                        if ($maxRoi >= 10.0) $v2Runners++;
                    } elseif ($patternAlgo === 'double_top_contextual_v3' || $patternAlgo === 'double_bottom_contextual_v3') {
                        $v3MaxRois[]   = $maxRoi;
                        if ($finalRoi !== null) $v3FinalRois[] = $finalRoi;
                        if ($isSlHit)  $v3SlHits++;
                        if ($maxRoi >= 10.0) $v3Runners++;
                    }
                }
            }

            // Hold time
            $holdMin = $this->extractHoldMinutes($trade);
            if ($holdMin !== null && $holdMin > 0) {
                $holdMinutes[] = $holdMin;
            }

            // Time-to-target
            [$tt2, $tt5, $tt10] = $this->extractTimeToTargets($trade);
            if ($tt2 !== null) $timeTo2Roi[]  = $tt2;
            if ($tt5 !== null) $timeTo5Roi[]  = $tt5;
            if ($tt10 !== null) $timeTo10Roi[] = $tt10;

            // Price-pct move
            $pricePct = $this->extractPricePct($trade);
            if ($pricePct !== null) {
                $pricePctMoves[] = $pricePct;
            }

            // Hour-of-day from open_ts
            if ($openTs > 0) {
                $hour = (int)gmdate('G', $openTs);
                if (!isset($hourStats[$hour])) {
                    $hourStats[$hour] = ['count' => 0, 'roi_sum' => 0.0, 'runner_count' => 0, 'sl_count' => 0, 'fake_count' => 0];
                }
                $hourStats[$hour]['count']++;
                if ($finalRoi !== null) {
                    $hourStats[$hour]['roi_sum'] += $finalRoi;
                }
                if ($maxRoi !== null && $maxRoi >= 10.0) {
                    $hourStats[$hour]['runner_count']++;
                }
                if ($isSlHit) {
                    $hourStats[$hour]['sl_count']++;
                }
                // Fake: peaked >= 3 then closed <= 0
                if ($maxRoi !== null && $maxRoi >= 3.0 && $finalRoi !== null && $finalRoi <= 0.0) {
                    $hourStats[$hour]['fake_count']++;
                }
            }

            // Initial burst: did trade reach 2 ROI within first 40% of hold time?
            if ($tt2 !== null && $holdMin !== null && $holdMin > 0) {
                $burstTotal++;
                if ($tt2 / $holdMin <= 0.4) {
                    $burstCount++;
                }
            }
        }

        // ── Sort arrays ──────────────────────────────────────────────────────
        $totalSides = $shorts + $longs;
        sort($maxRois);
        sort($finalRois);
        sort($adverseRois);
        sort($pricePctMoves);
        sort($v2MaxRois);
        sort($v3MaxRois);

        // ── Corridor (on favorable move = maxRoi) ────────────────────────────
        $corridorP50 = $this->percentile($maxRois, 50);
        $corridorP75 = $this->percentile($maxRois, 75);
        $corridorP90 = $this->percentile($maxRois, 90);

        // ── Price pct corridors ──────────────────────────────────────────────
        $corridorPricePctP50 = $this->percentile($pricePctMoves, 50);
        $corridorPricePctP75 = $this->percentile($pricePctMoves, 75);
        $corridorPricePctP90 = $this->percentile($pricePctMoves, 90);

        // ── Median adverse ───────────────────────────────────────────────────
        $medianMaxAdverseRoi = $this->percentile($adverseRois, 50);

        // ── Pullbacks ────────────────────────────────────────────────────────
        $medianPullback2    = $this->median($pullbacksAt2);
        $medianPullback3    = $this->median($pullbacksAt3);
        $medianPullback5    = $this->median($pullbacksAt5);
        $medianPullbackPeak = $this->median($pullbacksFromPeak);

        // ── Reach rates ──────────────────────────────────────────────────────
        $reach5Rate      = $sampleSizeTotal > 0 ? round($reach5      / $sampleSizeTotal, 4) : 0.0;
        $reach10Rate     = $sampleSizeTotal > 0 ? round($reach10     / $sampleSizeTotal, 4) : 0.0;
        $reach15Rate     = $sampleSizeTotal > 0 ? round($reach15     / $sampleSizeTotal, 4) : 0.0;
        $failBefore3Rate = $sampleSizeTotal > 0 ? round($failBefore3 / $sampleSizeTotal, 4) : 0.0;
        $slHitRate       = $sampleSizeTotal > 0 ? round($slHits      / $sampleSizeTotal, 4) : 0.0;
        $deepRetraceProb = count($pullbacksFromPeak) > 0 ? round($deepRetraces / count($pullbacksFromPeak), 4) : 0.0;

        // ── Timing ───────────────────────────────────────────────────────────
        $avgHoldMinutes = count($holdMinutes) > 0 ? round(array_sum($holdMinutes) / count($holdMinutes), 1) : null;
        $avgTimeTo2Roi  = count($timeTo2Roi)  > 0 ? round(array_sum($timeTo2Roi)  / count($timeTo2Roi),  1) : null;
        $avgTimeTo5Roi  = count($timeTo5Roi)  > 0 ? round(array_sum($timeTo5Roi)  / count($timeTo5Roi),  1) : null;
        $avgTimeTo10Roi = count($timeTo10Roi) > 0 ? round(array_sum($timeTo10Roi) / count($timeTo10Roi), 1) : null;

        // ── Core behavioral scores ───────────────────────────────────────────
        $runnerProb            = $sampleSizeTotal > 0 ? round($runners / $sampleSizeTotal, 4) : 0.0;
        $shortSuitabilityScore = $totalSides > 0 ? round($shorts / $totalSides, 4) : 0.5;
        $noiseScore            = $this->computeNoiseScore($maxRois, $finalRois);
        $volatilityScore       = $this->computeVolatilityScore($maxRois);
        $trendPersistenceScore = $this->computeTrendPersistenceScore($maxRois, $pullbacksAt3);
        $fakeBreakoutScore     = $this->computeFakeBreakoutScore($maxRois, $finalRois);
        $slSurvivalScore       = $sampleSizeTotal > 0 ? round(1.0 - $slHitRate, 4) : 0.5;
        $marketRegimeHealth    = $this->computeMarketRegimeHealthScore($corridorP75, $runnerProb, $recentSamples);

        // ── Impulse behavior ─────────────────────────────────────────────────
        $impulse = $this->computeImpulseScores(
            $corridorP75, $corridorP90, $avgTimeTo2Roi, $avgTimeTo5Roi,
            $avgHoldMinutes, $reach5Rate, $reach10Rate, $trendPersistenceScore,
            $fakeBreakoutScore, $noiseScore, $burstCount, $burstTotal
        );

        // ── Pullback behavior ────────────────────────────────────────────────
        $pullbackBehavior = $this->computePullbackBehavior(
            $medianPullback3, $medianPullback5, $medianPullbackPeak,
            $deepRetraceProb, $noiseScore
        );

        // ── Session / hour-of-day behavior ───────────────────────────────────
        $sessionBehavior = $this->computeSessionBehavior($hourStats);

        // ── Pattern-specific behavior ─────────────────────────────────────────
        $patternBehavior = $this->computePatternSpecificBehavior(
            $v2MaxRois, $v2FinalRois, $v2SlHits, $v2Runners, $sampleV2,
            $v3MaxRois, $v3FinalRois, $v3SlHits, $v3Runners, $sampleV3
        );

        // ── Regime behavior ───────────────────────────────────────────────────
        $regimeBehavior = $this->computeRegimeBehavior(
            $maxRois, $finalRois, $shorts, $longs,
            $corridorP75, $corridorP90, $runnerProb, $slHitRate
        );

        // ── Data confidence ───────────────────────────────────────────────────
        [$dataConfidence, $confidenceScoreNumeric, $confidenceReasonSummary] = $this->computeConfidenceDetailed(
            $sampleSizeTotal, $liveClosedSamples + $demoClosedSamples, $shadowClosedSamples,
            $recentSamples, $sampleV2, $sampleV3
        );

        // ── Data sufficiency ──────────────────────────────────────────────────
        // Primary gate: 24h freshness (not lifetime totals).
        // $recentSamples7d and $recentSamples24h drive the fresh-window model.
        [$insufficientFlag, $insufficientReason, $fallbackMode] = $this->computeDataSufficiency(
            $sampleSizeTotal, $sampleV2, $sampleV3, $recentSamples, $dataConfidence,
            $recentSamples24h, $recentSamples7d
        );
        $lastDataGapWarning = $insufficientFlag ? $insufficientReason : null;

        // Pattern-specific insufficiency flags
        $patternInsufficient = [
            'v2' => $sampleV2 < self::MIN_PATTERN_V2_SAMPLES,
            'v3' => $sampleV3 < self::MIN_PATTERN_V3_SAMPLES,
        ];

        // ── Recommendations ───────────────────────────────────────────────────
        $recLockStart      = $this->recommendLockStart($corridorP50, $corridorP75, $this->percentile($maxRois, 25), $dataConfidence);
        $recLockValue      = max(0.0, $recLockStart - 0.5);
        $recStage1         = $this->recommendStage1($corridorP50, $medianPullback3);
        $recStage2         = $this->recommendStage2($corridorP75, $corridorP90);
        $recLadderMode     = $this->recommendLadderMode($runnerProb, $corridorP90, $dataConfidence);
        $recHarvest        = $this->recommendHarvestAggressiveness($medianPullback3, $medianPullback5, $noiseScore);
        $recLiveFloor      = $this->recommendLiveFloorRoi($corridorP75, $dataConfidence);
        $recMaxHold        = $this->recommendMaxHoldMinutes($avgHoldMinutes, $runnerProb, $corridorP75);
        $recRunnerExpect   = $this->recommendRunnerExpectation($runnerProb, $reach10Rate, $corridorP90);

        // ── Live eligibility gate ─────────────────────────────────────────────
        // States: shadow_only | sim_only | bootstrap_live | allow_live
        // 24h freshness is the primary gate; 7d provides behavior context.
        [$liveEligibility, $liveBlockReason] = $this->computeLiveEligibility(
            $corridorP75,
            $runnerProb,
            $shortSuitabilityScore,
            $noiseScore,
            $dataConfidence,
            $marketRegimeHealth,
            $insufficientFlag,
            $fallbackMode,
            $impulse['impulse_strength_score'],
            $pullbackBehavior['pullback_severity_score'],
            $patternBehavior['v2_success_rate'] ?? null,
            (string)($insufficientReason ?? ''),
            $recentSamples24h,
            $recentSamples7d
        );

        // ── Diagnostic notes ──────────────────────────────────────────────────
        $notes = $this->buildDiagnosticNotes(
            $sampleSizeTotal, $dataConfidence, $runnerProb,
            $corridorP50, $corridorP75, $corridorP90,
            $this->percentile($maxRois, 25), $corridorP75,
            $liveEligibility, $liveBlockReason, $insufficientFlag
        );

        return [
            // ── Identity ──────────────────────────────────────────────────────
            'symbol'                        => $symbol,
            'updated_at'                    => date('Y-m-d H:i:s'),

            // ── Sample sizes ───────────────────────────────────────────────────
            'sample_size_total'             => $sampleSizeTotal,
            'sample_size_short_v2'          => $sampleV2,
            'sample_size_short_v3'          => $sampleV3,
            'sample_size_shadow'            => $shadowSamples,
            'sample_size_live_closed'       => $liveClosedSamples,
            'sample_size_live_active'       => $liveActiveSamples,
            'sample_size_shadow_closed'     => $shadowClosedSamples,
            'sample_size_shadow_active'     => $shadowActiveSamples,
            'sample_size_demo_closed'       => $demoClosedSamples,
            'sample_size_demo_active'       => $demoActiveSamples,
            'healthy_closed_samples'        => $healthyClosedSamples,

            // ── Fresh-window recent sample counters (primary live gate inputs) ──
            // 24h is the primary live admission gate; 7d is the behavior context.
            // 1h/6h are freshness/acceleration indicators only.
            // Data older than 7d must not drive live admission directly.
            'recent_samples_1h'             => $recentSamples1h,
            'recent_samples_6h'             => $recentSamples6h,
            'recent_samples_24h'            => $recentSamples24h,
            'recent_samples_7d'             => $recentSamples7d,
            // Derived sufficiency booleans for quick inspection
            'fresh_behavior_window_ok'      => $recentSamples24h >= self::MIN_SAMPLES_24H_FOR_BOOTSTRAP,
            'behavior_context_7d_ok'        => $recentSamples7d  >= self::MIN_SAMPLES_7D_FOR_CONTEXT,

            // ── Data confidence / sufficiency ──────────────────────────────────
            'data_confidence'               => $dataConfidence,
            'confidence_score_numeric'      => $confidenceScoreNumeric,
            'confidence_reason_summary'     => $confidenceReasonSummary,
            'last_data_gap_warning'         => $lastDataGapWarning,
            'minimum_required_samples'      => self::MIN_TOTAL_SAMPLES,
            'current_usable_samples'        => $sampleSizeTotal,
            'insufficient_data_flag'        => $insufficientFlag,
            'insufficient_data_reason'      => $insufficientReason,
            'fallback_mode'                 => $fallbackMode,
            'pattern_specific_insufficient_data' => $patternInsufficient,
            'timing_insufficient_data'      => count($hourStats) < 3,
            'regime_insufficient_data'      => $sampleSizeTotal < 15,

            // ── Core behavioral scores (0.0–1.0) ──────────────────────────────
            'short_suitability_score'       => round($shortSuitabilityScore, 4),
            'runner_probability'            => round($runnerProb, 4),
            'noise_score'                   => round($noiseScore, 4),
            'volatility_score'              => round($volatilityScore, 4),
            'trend_persistence_score'       => round($trendPersistenceScore, 4),
            'fake_breakout_score'           => round($fakeBreakoutScore, 4),
            'sl_survival_score'             => round($slSurvivalScore, 4),
            'market_regime_health_score'    => round($marketRegimeHealth, 4),

            // ── Impulse behavior ───────────────────────────────────────────────
            'impulse_strength_score'        => $impulse['impulse_strength_score'],
            'impulse_speed_score'           => $impulse['impulse_speed_score'],
            'impulse_decay_score'           => $impulse['impulse_decay_score'],
            'runner_extension_score'        => $impulse['runner_extension_score'],
            'time_to_peak_score'            => $impulse['time_to_peak_score'],
            'initial_burst_score'           => $impulse['initial_burst_score'],
            'sustained_move_score'          => $impulse['sustained_move_score'],
            'late_failure_score'            => $impulse['late_failure_score'],

            // ── Pullback behavior ──────────────────────────────────────────────
            'pullback_severity_score'       => $pullbackBehavior['pullback_severity_score'],
            'post_impulse_retrace_habit'    => $pullbackBehavior['post_impulse_retrace_habit'],
            'deep_retrace_probability'      => $deepRetraceProb,
            'median_pullback_after_peak'    => round($medianPullbackPeak, 2),
            'median_pullback_after_2_roi'   => round($medianPullback2, 2),
            'median_pullback_after_3_roi'   => round($medianPullback3, 2),
            'median_pullback_after_5_roi'   => round($medianPullback5, 2),

            // ── Corridor — favorable ROI (max/peak) ────────────────────────────
            'corridor_p50_roi'              => round($corridorP50, 2),
            'corridor_p75_roi'              => round($corridorP75, 2),
            'corridor_p90_roi'              => round($corridorP90, 2),

            // ── Corridor — price pct move ──────────────────────────────────────
            'corridor_price_pct_p50'        => round($corridorPricePctP50, 4),
            'corridor_price_pct_p75'        => round($corridorPricePctP75, 4),
            'corridor_price_pct_p90'        => round($corridorPricePctP90, 4),

            // ── Max favorable / adverse ────────────────────────────────────────
            'median_max_favorable_roi'      => round($corridorP50, 2),
            'median_max_adverse_roi'        => round($medianMaxAdverseRoi, 2),

            // ── Reach rates ────────────────────────────────────────────────────
            'reach_5_roi_rate'              => $reach5Rate,
            'reach_10_roi_rate'             => $reach10Rate,
            'reach_15_roi_rate'             => $reach15Rate,
            'failure_before_3_roi_rate'     => $failBefore3Rate,
            'stop_loss_hit_rate'            => $slHitRate,
            'deep_retrace_rate'             => $deepRetraceProb,

            // ── Timing ────────────────────────────────────────────────────────
            'avg_hold_minutes'              => $avgHoldMinutes,
            'avg_time_to_2_roi'             => $avgTimeTo2Roi,
            'avg_time_to_5_roi'             => $avgTimeTo5Roi,
            'avg_time_to_10_roi'            => $avgTimeTo10Roi,

            // ── Session / timing behavior ──────────────────────────────────────
            'best_hours_utc'                => $sessionBehavior['best_hours_utc'],
            'worst_hours_utc'               => $sessionBehavior['worst_hours_utc'],
            'session_behavior_score'        => $sessionBehavior['session_behavior_score'],
            'time_of_day_runner_rate'       => $sessionBehavior['time_of_day_runner_rate'],
            'time_of_day_fake_move_rate'    => $sessionBehavior['time_of_day_fake_move_rate'],
            'time_of_day_stop_rate'         => $sessionBehavior['time_of_day_stop_rate'],

            // ── Pattern-specific behavior ──────────────────────────────────────
            'pattern_behavior'              => $patternBehavior,

            // ── Regime behavior ────────────────────────────────────────────────
            'bull_regime_behavior_score'    => $regimeBehavior['bull_regime_behavior_score'],
            'bear_regime_behavior_score'    => $regimeBehavior['bear_regime_behavior_score'],
            'sideways_regime_behavior_score' => $regimeBehavior['sideways_regime_behavior_score'],
            'high_vol_regime_behavior_score' => $regimeBehavior['high_vol_regime_behavior_score'],
            'fear_regime_behavior_score'    => $regimeBehavior['fear_regime_behavior_score'],
            'regime_sensitivity_score'      => $regimeBehavior['regime_sensitivity_score'],

            // ── Live eligibility ───────────────────────────────────────────────
            // States: shadow_only | sim_only | bootstrap_live | allow_live
            //   shadow_only    – no recent data (24h=0, 7d=0) or confidence=none
            //   sim_only       – 24h dead (7d has history) or hard metric gate fail
            //   bootstrap_live – 24h >= 1 sample, metric gates pass, but below
            //                    MIN_SAMPLES_24H_FOR_ALLOW_LIVE or 7d context thin
            //   allow_live     – 24h >= MIN_SAMPLES_24H_FOR_ALLOW_LIVE + 7d context
            //                    sufficient + all metric gates pass
            'recommended_live_eligibility'  => $liveEligibility,
            'live_block_reason'             => $liveBlockReason,
            // Compact gate-decision observability (aliased for quick inspection)
            'passport_gate_state'           => $liveEligibility,
            'passport_gate_reason_detail'   => $liveBlockReason,

            // ── Decision Engine trust state (green / yellow / red / insufficient_data) ──
            // Consumed by BotDecisionEngine to compute confidence_band without re-running
            // the full eligibility gate on every tick.
            'trust_state'                   => $this->computeTrustState(
                $liveEligibility,
                $dataConfidence,
                $noiseScore,
                $sampleSizeTotal,
                $insufficientFlag,
                $healthyClosedSamples
            ),

            // ── Recommendations ────────────────────────────────────────────────
            'recommended_live_floor_roi'          => round($recLiveFloor, 2),
            'recommended_stage1_start_roi'        => round($recStage1, 2),
            'recommended_stage2_start_roi'        => round($recStage2, 2),
            'recommended_harvest_aggressiveness'  => $recHarvest,
            'recommended_max_hold_minutes'        => $recMaxHold,
            'recommended_runner_expectation'      => $recRunnerExpect,

            // ── Legacy field aliases (kept for backward compat with Brain/UI) ───
            'sample_size'                        => $sampleSizeTotal,
            'median_max_roi'                     => round($corridorP50, 2),
            'p75_max_roi'                        => round($corridorP75, 2),
            'p90_max_roi'                        => round($corridorP90, 2),
            'corridor_low_roi'                   => round($this->percentile($maxRois, 25), 2),
            'corridor_mid_roi'                   => round($corridorP50, 2),
            'corridor_high_roi'                  => round($corridorP75, 2),
            'recommended_guaranteed_lock_start_roi' => round($recLockStart, 2),
            'recommended_guaranteed_lock_value_roi' => round($recLockValue, 2),
            'recommended_stage1_threshold_roi'      => round($recStage1, 2),
            'recommended_stage2_threshold_roi'      => round($recStage2, 2),
            'recommended_ladder_mode'               => $recLadderMode,

            // ── Diagnostics ────────────────────────────────────────────────────
            'notes'                         => $notes,
        ];
    }

    // =========================================================================
    // Field extraction helpers
    // =========================================================================

    /**
     * Extract final ROI (in %) from a trade record.
     */
    private function extractFinalRoi(array $trade): ?float
    {
        // Try common field names
        foreach (['roi_margin', 'roi_percent', 'roi_final', 'roi'] as $key) {
            if (isset($trade[$key]) && is_numeric($trade[$key])) {
                $val = (float)$trade[$key];
                // Values stored as ratio (e.g. 0.05 = 5%) → convert to %
                if (abs($val) < 2.0 && $val !== 0.0) {
                    $val *= 100.0;
                }
                return $val;
            }
        }

        // Look inside runtime sub-array
        $runtime = $trade['runtime'] ?? [];
        if (is_array($runtime)) {
            foreach (['roi_percent', 'roi_margin', 'final_roi'] as $key) {
                if (isset($runtime[$key]) && is_numeric($runtime[$key])) {
                    $val = (float)$runtime[$key];
                    if (abs($val) < 2.0 && $val !== 0.0) {
                        $val *= 100.0;
                    }
                    return $val;
                }
            }
        }

        return null;
    }

    /**
     * Extract peak/max ROI (in %) from a trade record.
     * Falls back to final ROI if no peak data available.
     *
     * Note: `mfe`/`mfe_roi` fields set to 0 are skipped when a positive
     * fallback (finalRoi) exists. Shadow/virtual trades use 0 as an
     * uninitialized default for MFE; skipping it lets the final ROI serve
     * as a reasonable peak approximation. For genuine loss-only trades
     * (fallback <= 0) the zero MFE is still returned as-is.
     */
    private function extractPeakRoi(array $trade, ?float $fallback): ?float
    {
        foreach (['trailing_peak_roi', 'peak_roi', 'max_roi', 'mfe', 'mfe_roi'] as $key) {
            if (isset($trade[$key]) && is_numeric($trade[$key])) {
                $val = (float)$trade[$key];
                // Skip uninitialized MFE zero defaults when a better fallback exists.
                // Shadow/virtual trades have mfe=0 as a mock placeholder.
                if ($val === 0.0 && ($key === 'mfe' || $key === 'mfe_roi')
                    && $fallback !== null && $fallback > 0.0) {
                    continue;
                }
                if (abs($val) < 2.0 && $val !== 0.0) {
                    $val *= 100.0;
                }
                return $val;
            }
        }

        // Check runtime sub-array
        $runtime = $trade['runtime'] ?? [];
        if (is_array($runtime)) {
            foreach (['trailing_peak_roi', 'peak_roi', 'mfe', 'max_roi_reached'] as $key) {
                if (isset($runtime[$key]) && is_numeric($runtime[$key])) {
                    $val = (float)$runtime[$key];
                    if ($val === 0.0 && ($key === 'mfe')
                        && $fallback !== null && $fallback > 0.0) {
                        continue;
                    }
                    if (abs($val) < 2.0 && $val !== 0.0) {
                        $val *= 100.0;
                    }
                    return $val;
                }
            }
        }

        return $fallback;
    }

    /**
     * Extract maximum adverse excursion (worst drawdown) in %, as a positive value.
     */
    private function extractAdverseRoi(array $trade): ?float
    {
        foreach (['mae', 'mae_roi', 'max_adverse_roi', 'max_drawdown_roi'] as $key) {
            if (isset($trade[$key]) && is_numeric($trade[$key])) {
                $val = abs((float)$trade[$key]);
                if ($val < 2.0 && $val !== 0.0) {
                    $val *= 100.0;
                }
                return $val;
            }
        }
        $runtime = $trade['runtime'] ?? [];
        if (is_array($runtime)) {
            foreach (['mae', 'mae_roi', 'max_adverse_roi'] as $key) {
                if (isset($runtime[$key]) && is_numeric($runtime[$key])) {
                    $val = abs((float)$runtime[$key]);
                    if ($val < 2.0 && $val !== 0.0) {
                        $val *= 100.0;
                    }
                    return $val;
                }
            }
        }
        return null;
    }

    /**
     * Detect whether a trade was closed by stop-loss.
     */
    private function detectStopLossHit(array $trade, ?float $finalRoi, ?float $maxRoi): bool
    {
        // Explicit close_reason field
        $closeReason = strtolower((string)($trade['close_reason'] ?? $trade['exit_reason'] ?? ''));
        if (str_contains($closeReason, 'stop_loss') || str_contains($closeReason, 'sl_hit')) {
            return true;
        }
        $runtime = $trade['runtime'] ?? [];
        if (is_array($runtime)) {
            $rr = strtolower((string)($runtime['close_reason'] ?? ''));
            if (str_contains($rr, 'stop_loss') || str_contains($rr, 'sl_hit')) {
                return true;
            }
        }
        // Heuristic: assume stop-loss if final ROI <= -3%
        if ($finalRoi !== null && $finalRoi <= -3.0) {
            return true;
        }
        return false;
    }

    /**
     * Extract hold time in minutes from a trade record.
     */
    private function extractHoldMinutes(array $trade): ?float
    {
        foreach (['hold_minutes', 'duration_minutes', 'trade_duration_minutes'] as $key) {
            if (isset($trade[$key]) && is_numeric($trade[$key])) {
                return (float)$trade[$key];
            }
        }
        // Derive from open/close timestamps
        $openTs  = (int)($trade['open_ts']   ?? $trade['created_ts']  ?? 0);
        $closeTs = (int)($trade['closed_ts'] ?? $trade['close_ts']    ?? 0);
        if ($openTs > 0 && $closeTs > $openTs) {
            return round(($closeTs - $openTs) / 60.0, 1);
        }
        return null;
    }

    /**
     * Extract time-to-target fields (minutes to reach 2/5/10 ROI).
     *
     * @return array{?float, ?float, ?float}
     */
    private function extractTimeToTargets(array $trade): array
    {
        $runtime = $trade['runtime'] ?? [];
        if (!is_array($runtime)) {
            $runtime = [];
        }

        $t2  = null;
        $t5  = null;
        $t10 = null;

        foreach (['time_to_2_roi_minutes',  'minutes_to_2_roi']  as $k) {
            if (isset($runtime[$k]) && is_numeric($runtime[$k])) { $t2  = (float)$runtime[$k]; break; }
            if (isset($trade[$k])   && is_numeric($trade[$k]))   { $t2  = (float)$trade[$k];   break; }
        }
        foreach (['time_to_5_roi_minutes',  'minutes_to_5_roi']  as $k) {
            if (isset($runtime[$k]) && is_numeric($runtime[$k])) { $t5  = (float)$runtime[$k]; break; }
            if (isset($trade[$k])   && is_numeric($trade[$k]))   { $t5  = (float)$trade[$k];   break; }
        }
        foreach (['time_to_10_roi_minutes', 'minutes_to_10_roi'] as $k) {
            if (isset($runtime[$k]) && is_numeric($runtime[$k])) { $t10 = (float)$runtime[$k]; break; }
            if (isset($trade[$k])   && is_numeric($trade[$k]))   { $t10 = (float)$trade[$k];   break; }
        }

        return [$t2, $t5, $t10];
    }

    /**
     * Extract raw price-pct move (abs value) if available.
     */
    private function extractPricePct(array $trade): ?float
    {
        foreach (['price_pct_move', 'price_change_pct', 'entry_to_peak_pct'] as $key) {
            if (isset($trade[$key]) && is_numeric($trade[$key])) {
                return abs((float)$trade[$key]);
            }
        }
        return null;
    }

    // =========================================================================
    // Statistical helpers
    // =========================================================================

    /**
     * Compute Nth percentile from a sorted array of floats.
     *
     * @param list<float> $sorted
     */
    private function percentile(array $sorted, int $n): float
    {
        if (empty($sorted)) {
            return 0.0;
        }
        $count = count($sorted);
        $idx   = ($n / 100) * ($count - 1);
        $lower = (int)floor($idx);
        $upper = (int)ceil($idx);
        if ($lower === $upper) {
            return (float)$sorted[$lower];
        }
        $frac = $idx - $lower;
        return (float)$sorted[$lower] + $frac * ((float)$sorted[$upper] - (float)$sorted[$lower]);
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }
        $sorted = $values;
        sort($sorted);
        return $this->percentile($sorted, 50);
    }

    // =========================================================================
    // Impulse behavior
    // =========================================================================

    /**
     * Compute impulse behavior scores.
     *
     * @return array<string,float>
     */
    private function computeImpulseScores(
        float   $corridorP75,
        float   $corridorP90,
        ?float  $avgTimeTo2Roi,
        ?float  $avgTimeTo5Roi,
        ?float  $avgHoldMinutes,
        float   $reach5Rate,
        float   $reach10Rate,
        float   $trendPersistenceScore,
        float   $fakeBreakoutScore,
        float   $noiseScore,
        int     $burstCount,
        int     $burstTotal
    ): array {
        // impulse_strength_score: how far typical good trades go (corridor-based)
        $impulseStrength = min(1.0, $corridorP75 / 10.0);

        // impulse_speed_score: inversely proportional to avg_time_to_2_roi (fast = high)
        $impulseSpeed = 0.5; // default
        if ($avgTimeTo2Roi !== null && $avgTimeTo2Roi > 0) {
            // Fast: < 15 min → 1.0, Slow: > 120 min → 0.0
            $impulseSpeed = max(0.0, min(1.0, 1.0 - ($avgTimeTo2Roi - 15) / 105));
        }

        // impulse_decay_score: how quickly gains evaporate after peak (lower = better)
        // Derived from noise_score and fake_breakout_score
        $impulseDecay = min(1.0, ($noiseScore + $fakeBreakoutScore) / 1.5);

        // runner_extension_score: how often runners go well beyond initial impulse
        $runnerExtension = min(1.0, $reach10Rate * 5.0 + ($corridorP90 / 20.0) * 0.3);

        // time_to_peak_score: how quickly trades reach peak (faster = better)
        $timeToPeakScore = 0.5;
        if ($avgTimeTo5Roi !== null && $avgHoldMinutes !== null && $avgHoldMinutes > 0) {
            $peakRatio = $avgTimeTo5Roi / $avgHoldMinutes;
            // Low ratio (reaches peak quickly) = high score
            $timeToPeakScore = max(0.0, min(1.0, 1.0 - $peakRatio));
        }

        // initial_burst_score: fraction of trades that burst to 2 ROI within 40% of hold time
        $initialBurstScore = $burstTotal > 0 ? round($burstCount / $burstTotal, 4) : 0.3;

        // sustained_move_score: how well moves persist (trend persistence)
        $sustainedMoveScore = round($trendPersistenceScore, 4);

        // late_failure_score: trades that peaked high but closed badly
        // High value = many late failures = bad
        $lateFailureScore = round($fakeBreakoutScore * 1.2 + $noiseScore * 0.3, 4);
        $lateFailureScore = min(1.0, $lateFailureScore);

        return [
            'impulse_strength_score'  => round($impulseStrength, 4),
            'impulse_speed_score'     => round($impulseSpeed, 4),
            'impulse_decay_score'     => round($impulseDecay, 4),
            'runner_extension_score'  => round($runnerExtension, 4),
            'time_to_peak_score'      => round($timeToPeakScore, 4),
            'initial_burst_score'     => round($initialBurstScore, 4),
            'sustained_move_score'    => round($sustainedMoveScore, 4),
            'late_failure_score'      => round($lateFailureScore, 4),
        ];
    }

    // =========================================================================
    // Pullback behavior
    // =========================================================================

    /**
     * @return array<string,mixed>
     */
    private function computePullbackBehavior(
        float $medianPullback3,
        float $medianPullback5,
        float $medianPullbackPeak,
        float $deepRetraceProb,
        float $noiseScore
    ): array {
        // pullback_severity_score: 0 = mild pullbacks, 1 = severe
        // Based on median pullback from peak relative to expected
        $severity = min(1.0, $medianPullbackPeak / 8.0 + $deepRetraceProb * 0.5 + $noiseScore * 0.2);

        // post_impulse_retrace_habit: qualitative label
        $habit = 'mild';
        if ($medianPullback3 >= 3.0 || $deepRetraceProb >= 0.5) {
            $habit = 'deep';
        } elseif ($medianPullback3 >= 1.5 || $deepRetraceProb >= 0.25) {
            $habit = 'moderate';
        }

        return [
            'pullback_severity_score'    => round(min(1.0, $severity), 4),
            'post_impulse_retrace_habit' => $habit,
        ];
    }

    // =========================================================================
    // Session / timing behavior
    // =========================================================================

    /**
     * Compute session/hour-of-day behavioral metrics from hourly trade stats.
     *
     * @param array<int,array<string,mixed>> $hourStats  hour(0-23) → {count, roi_sum, runner_count, sl_count, fake_count}
     * @return array<string,mixed>
     */
    private function computeSessionBehavior(array $hourStats): array
    {
        if (empty($hourStats)) {
            return [
                'best_hours_utc'             => [],
                'worst_hours_utc'            => [],
                'session_behavior_score'     => 0.5,
                'time_of_day_runner_rate'    => [],
                'time_of_day_fake_move_rate' => [],
                'time_of_day_stop_rate'      => [],
            ];
        }

        $hourAvgRoi    = [];
        $hourRunnerRate = [];
        $hourFakeRate  = [];
        $hourStopRate  = [];

        foreach ($hourStats as $hour => $stat) {
            $cnt = (int)($stat['count'] ?? 0);
            if ($cnt === 0) continue;
            $avgRoi  = round((float)($stat['roi_sum'] ?? 0) / $cnt, 2);
            $hourAvgRoi[$hour]    = $avgRoi;
            $hourRunnerRate[$hour] = round((int)($stat['runner_count'] ?? 0) / $cnt, 4);
            $hourFakeRate[$hour]  = round((int)($stat['fake_count']   ?? 0) / $cnt, 4);
            $hourStopRate[$hour]  = round((int)($stat['sl_count']     ?? 0) / $cnt, 4);
        }

        if (empty($hourAvgRoi)) {
            return [
                'best_hours_utc'             => [],
                'worst_hours_utc'            => [],
                'session_behavior_score'     => 0.5,
                'time_of_day_runner_rate'    => $hourRunnerRate,
                'time_of_day_fake_move_rate' => $hourFakeRate,
                'time_of_day_stop_rate'      => $hourStopRate,
            ];
        }

        // Sort hours by avg ROI
        arsort($hourAvgRoi);
        $best  = array_slice(array_keys($hourAvgRoi), 0, 3);
        asort($hourAvgRoi);
        $worst = array_slice(array_keys($hourAvgRoi), 0, 3);

        // session_behavior_score: 1.0 = consistent across hours, 0.0 = highly variable
        $allRois = array_values($hourAvgRoi);
        $rng     = count($allRois) > 1 ? (max($allRois) - min($allRois)) : 0.0;
        $sessionScore = max(0.0, min(1.0, 1.0 - $rng / 15.0));

        return [
            'best_hours_utc'             => array_values($best),
            'worst_hours_utc'            => array_values($worst),
            'session_behavior_score'     => round($sessionScore, 4),
            'time_of_day_runner_rate'    => $hourRunnerRate,
            'time_of_day_fake_move_rate' => $hourFakeRate,
            'time_of_day_stop_rate'      => $hourStopRate,
        ];
    }

    // =========================================================================
    // Pattern-specific behavior
    // =========================================================================

    /**
     * Compute per-pattern (V2/V3) behavioral statistics.
     *
     * @param list<float> $v2MaxRois
     * @param list<float> $v2FinalRois
     * @param list<float> $v3MaxRois
     * @param list<float> $v3FinalRois
     * @return array<string,mixed>
     */
    private function computePatternSpecificBehavior(
        array $v2MaxRois,   array $v2FinalRois,   int $v2SlHits,   int $v2Runners,   int $v2Total,
        array $v3MaxRois,   array $v3FinalRois,   int $v3SlHits,   int $v3Runners,   int $v3Total
    ): array {
        $patternStats = function (
            array $maxRois, array $finalRois, int $slHits, int $runners, int $total
        ): array {
            if ($total === 0) {
                return [
                    'sample_count'      => 0,
                    'success_rate'      => null,
                    'runner_rate'       => null,
                    'avg_roi'           => null,
                    'stop_rate'         => null,
                    'corridor_p75_roi'  => null,
                    'data_confidence'   => 'none',
                ];
            }
            $successCount = 0;
            $roiSum       = 0.0;
            foreach ($finalRois as $roi) {
                if ($roi > 0) $successCount++;
                $roiSum += $roi;
            }
            sort($maxRois);
            $conf = $total >= 20 ? 'high' : ($total >= 5 ? 'medium' : ($total > 0 ? 'low' : 'none'));

            return [
                'sample_count'      => $total,
                'success_rate'      => $total > 0 ? round($successCount / $total, 4) : null,
                'runner_rate'       => $total > 0 ? round($runners / $total, 4) : null,
                'avg_roi'           => count($finalRois) > 0 ? round($roiSum / count($finalRois), 2) : null,
                'stop_rate'         => $total > 0 ? round($slHits / $total, 4) : null,
                'corridor_p75_roi'  => count($maxRois) > 0 ? round($this->percentile($maxRois, 75), 2) : null,
                'data_confidence'   => $conf,
            ];
        };

        $v2Stats = $patternStats($v2MaxRois, $v2FinalRois, $v2SlHits, $v2Runners, $v2Total);
        $v3Stats = $patternStats($v3MaxRois, $v3FinalRois, $v3SlHits, $v3Runners, $v3Total);

        return [
            // V2 pattern stats (double_top_contextual_v2 / double_bottom_contextual_v2)
            'v2_sample_count'       => $v2Stats['sample_count'],
            'v2_success_rate'       => $v2Stats['success_rate'],
            'short_v2_success_rate' => $v2Stats['success_rate'],  // alias
            'v2_runner_rate'        => $v2Stats['runner_rate'],
            'v2_avg_roi'            => $v2Stats['avg_roi'],
            'v2_stop_rate'          => $v2Stats['stop_rate'],
            'v2_corridor_p75_roi'   => $v2Stats['corridor_p75_roi'],
            'v2_data_confidence'    => $v2Stats['data_confidence'],
            // V3 pattern stats (double_top_contextual_v3 / double_bottom_contextual_v3)
            'v3_sample_count'       => $v3Stats['sample_count'],
            'v3_success_rate'       => $v3Stats['success_rate'],
            'short_v3_success_rate' => $v3Stats['success_rate'],  // alias
            'v3_runner_rate'        => $v3Stats['runner_rate'],
            'v3_avg_roi'            => $v3Stats['avg_roi'],
            'v3_stop_rate'          => $v3Stats['stop_rate'],
            'v3_corridor_p75_roi'   => $v3Stats['corridor_p75_roi'],
            'v3_data_confidence'    => $v3Stats['data_confidence'],
        ];
    }

    // =========================================================================
    // Regime behavior
    // =========================================================================

    /**
     * Compute simplified regime behavior scores derived from trade data.
     * Since no external regime labels are available, we infer regime proxies.
     *
     * @param list<float> $maxRois
     * @param list<float> $finalRois
     * @return array<string,float>
     */
    private function computeRegimeBehavior(
        array $maxRois,
        array $finalRois,
        int   $shorts,
        int   $longs,
        float $corridorP75,
        float $corridorP90,
        float $runnerProb,
        float $slHitRate
    ): array {
        $n = count($maxRois);

        // bear_regime_behavior_score: short-side performance proxy
        // Short trades = adversarial market for price = "bear" for underlying
        $totalSides = $shorts + $longs;
        $bearScore = $totalSides > 0
            ? min(1.0, ($shorts / $totalSides) * ($corridorP75 / 8.0 + $runnerProb * 2.0))
            : 0.3;

        // bull_regime_behavior_score: long-side performance proxy
        $bullScore = $totalSides > 0
            ? min(1.0, ($longs / $totalSides) * ($corridorP75 / 8.0 + $runnerProb * 2.0))
            : 0.3;

        // sideways_regime_behavior_score: performance when moves are modest (< 5%)
        $sidewaysTotal = 0; $sidewaysSuccess = 0;
        foreach ($maxRois as $i => $maxRoi) {
            if ($maxRoi < 5.0) {
                $sidewaysTotal++;
                if (isset($finalRois[$i]) && $finalRois[$i] > 0) {
                    $sidewaysSuccess++;
                }
            }
        }
        $sidewaysScore = $sidewaysTotal > 0 ? round($sidewaysSuccess / $sidewaysTotal, 4) : 0.3;

        // high_vol_regime_behavior_score: performance when moves are very wide (> 8%)
        $highVolTotal = 0; $highVolSuccess = 0;
        foreach ($maxRois as $i => $maxRoi) {
            if ($maxRoi >= 8.0) {
                $highVolTotal++;
                if (isset($finalRois[$i]) && $finalRois[$i] > 0) {
                    $highVolSuccess++;
                }
            }
        }
        $highVolScore = $highVolTotal > 0 ? round($highVolSuccess / $highVolTotal, 4) : 0.3;

        // fear_regime_behavior_score: performance under high SL pressure
        // Low SL rate = coin handles stress = high fear score
        $fearScore = round(1.0 - min(1.0, $slHitRate * 2.0), 4);

        // regime_sensitivity_score: how much performance varies across regimes
        $scores = array_filter([$bearScore, $bullScore, $sidewaysScore, $highVolScore]);
        if (count($scores) >= 2) {
            $spread = max($scores) - min($scores);
            $regimeSensitivity = min(1.0, $spread * 2.0);
        } else {
            $regimeSensitivity = 0.3;
        }

        return [
            'bull_regime_behavior_score'    => round(min(1.0, $bullScore), 4),
            'bear_regime_behavior_score'    => round(min(1.0, $bearScore), 4),
            'sideways_regime_behavior_score' => round($sidewaysScore, 4),
            'high_vol_regime_behavior_score' => round($highVolScore, 4),
            'fear_regime_behavior_score'    => round($fearScore, 4),
            'regime_sensitivity_score'      => round($regimeSensitivity, 4),
        ];
    }

    // =========================================================================
    // Evidence timeline
    // =========================================================================

    /**
     * Rebuild the evidence timeline for a symbol from its trade list.
     * Generates up to MAX_EVIDENCE_ITEMS events from the most recent trades.
     *
     * @param list<array<string,mixed>> $trades
     */
    private function rebuildEvidenceTimeline(string $symbol, array $trades): void
    {
        if (empty($trades)) {
            return;
        }

        // Sort trades by close/open time descending (most recent first)
        usort($trades, function ($a, $b) {
            $ta = (int)($a['closed_ts'] ?? $a['closed_at'] ?? $a['open_ts'] ?? $a['opened_at'] ?? 0);
            $tb = (int)($b['closed_ts'] ?? $b['closed_at'] ?? $b['open_ts'] ?? $b['opened_at'] ?? 0);
            return $tb <=> $ta;
        });

        $items = [];
        foreach ($trades as $trade) {
            if (count($items) >= self::MAX_EVIDENCE_ITEMS) {
                break;
            }

            $source  = (string)($trade['_source'] ?? 'live_closed');
            $finalRoi = $this->extractFinalRoi($trade);
            $maxRoi   = $this->extractPeakRoi($trade, $finalRoi);
            $ts       = (int)($trade['closed_ts'] ?? $trade['closed_at'] ?? $trade['open_ts'] ?? $trade['opened_at'] ?? 0);
            $patternAlgo = (string)($trade['pattern_algorithm'] ?? '');
            $side        = (string)($trade['side'] ?? '');
            $closeReason = strtolower((string)($trade['close_reason'] ?? $trade['exit_reason'] ?? ''));
            $isSlHit     = $this->detectStopLossHit($trade, $finalRoi, $maxRoi);

            $type = 'trade_closed';
            $notes = '';

            if ($source === 'shadow_active') {
                $type = 'shadow_active';
            } elseif ($source === 'shadow_closed') {
                $type = 'shadow_outcome';
            } elseif ($source === 'live_active') {
                $type = 'trade_active';
            } elseif ($isSlHit) {
                $type = 'stop_hit';
            } elseif ($maxRoi !== null && $maxRoi >= 15.0) {
                $type = 'runner_case';
                $notes = 'Reached ' . round((float)$maxRoi, 1) . '% peak ROI';
            } elseif ($maxRoi !== null && $maxRoi >= 10.0) {
                $type = 'reached_10_roi';
            } elseif ($maxRoi !== null && $maxRoi >= 5.0) {
                $type = 'reached_5_roi';
            } elseif ($maxRoi !== null && $maxRoi >= 2.0) {
                $type = 'reached_2_roi';
            } elseif ($maxRoi !== null && $maxRoi < 3.0 && $finalRoi !== null && $finalRoi <= 0.0) {
                $type = 'fakeout_case';
            }

            $items[] = [
                'ts'              => $ts ?: time(),
                'symbol'          => $symbol,
                'type'            => $type,
                'source'          => $source,
                'pattern_algorithm' => $patternAlgo,
                'side'            => $side,
                'final_roi'       => $finalRoi !== null ? round($finalRoi, 2) : null,
                'peak_roi'        => $maxRoi   !== null ? round($maxRoi, 2)   : null,
                'close_reason'    => $closeReason ?: null,
                'notes'           => $notes ?: null,
            ];
        }

        $data = [
            'symbol'     => $symbol,
            'updated_at' => date('Y-m-d H:i:s'),
            'items'      => $items,
        ];

        $path = $this->evidencePath($symbol);
        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    // =========================================================================

    /**
     * Volatility score: how wide is the ROI spread?
     * High score = large spread = volatile.
     *
     * @param list<float> $maxRois
     */
    private function computeVolatilityScore(array $maxRois): float
    {
        if (count($maxRois) < 2) {
            return 0.5;
        }
        $range = max($maxRois) - min($maxRois);
        // Normalise: 0-30% range maps to 0-1
        return min(1.0, $range / 30.0);
    }

    /**
     * Noise score: fraction of trades where final ROI << peak ROI.
     * High noise = price peaks then reverses badly.
     *
     * @param list<float> $maxRois
     * @param list<float> $finalRois
     */
    private function computeNoiseScore(array $maxRois, array $finalRois): float
    {
        if (empty($maxRois) || count($maxRois) !== count($finalRois)) {
            return 0.5;
        }
        $noisy = 0;
        foreach ($maxRois as $i => $peak) {
            if ($peak > 0 && isset($finalRois[$i])) {
                $giveback = $peak - $finalRois[$i];
                if ($giveback / $peak > 0.5) {
                    $noisy++;
                }
            }
        }
        return round($noisy / count($maxRois), 4);
    }

    /**
     * Trend persistence: fraction of trades that hold >=60% of peak ROI at close.
     *
     * @param list<float> $maxRois
     * @param list<float> $pullbacksAt3
     */
    private function computeTrendPersistenceScore(array $maxRois, array $pullbacksAt3): float
    {
        if (empty($pullbacksAt3)) {
            return 0.5;
        }
        $persistent = 0;
        foreach ($pullbacksAt3 as $pullback) {
            if ($pullback < 1.5) {
                $persistent++;
            }
        }
        return round($persistent / count($pullbacksAt3), 4);
    }

    /**
     * Fake breakout score: fraction of trades that peaked quickly then failed.
     *
     * @param list<float> $maxRois
     * @param list<float> $finalRois
     */
    private function computeFakeBreakoutScore(array $maxRois, array $finalRois): float
    {
        if (empty($maxRois) || count($maxRois) !== count($finalRois)) {
            return 0.3;
        }
        $fakes = 0;
        foreach ($maxRois as $i => $peak) {
            if ($peak >= 3.0 && isset($finalRois[$i]) && $finalRois[$i] <= 0.0) {
                $fakes++;
            }
        }
        return round($fakes / count($maxRois), 4);
    }

    /**
     * Data confidence label based on sample size (legacy helper, kept for pattern-level use).
     */
    private function computeConfidence(int $sampleSize): string
    {
        if ($sampleSize >= self::MIN_SAMPLE_HIGH) {
            return 'high';
        }
        if ($sampleSize >= self::MIN_SAMPLE_MEDIUM) {
            return 'medium';
        }
        if ($sampleSize > 0) {
            return 'low';
        }
        return 'none';
    }

    /**
     * Rich data confidence computation that accounts for source diversity, recency,
     * and pattern-level coverage.
     *
     * Returns [label, numeric_score (0.0–1.0), reason_summary].
     *
     * @return array{string, float, string}
     */
    private function computeConfidenceDetailed(
        int $total,
        int $liveClosed,
        int $shadowClosed,
        int $recent,
        int $v2Samples,
        int $v3Samples
    ): array {
        $score = 0.0;
        $reasons = [];

        // Component 1: raw sample volume (0–0.35)
        if ($total >= 50) {
            $score += 0.35;
            $reasons[] = "volume:50+({$total})";
        } elseif ($total >= 20) {
            $score += 0.25;
            $reasons[] = "volume:20+({$total})";
        } elseif ($total >= 10) {
            $score += 0.15;
            $reasons[] = "volume:10+({$total})";
        } elseif ($total >= 5) {
            $score += 0.08;
            $reasons[] = "volume:5+({$total})";
        } elseif ($total > 0) {
            $score += 0.03;
            $reasons[] = "volume:sparse({$total})";
        }

        // Component 2: live closed data quality (0–0.25)
        if ($liveClosed >= 20) {
            $score += 0.25;
            $reasons[] = "live_closed:rich({$liveClosed})";
        } elseif ($liveClosed >= 10) {
            $score += 0.18;
            $reasons[] = "live_closed:good({$liveClosed})";
        } elseif ($liveClosed >= 5) {
            $score += 0.10;
            $reasons[] = "live_closed:some({$liveClosed})";
        } elseif ($liveClosed >= 1) {
            $score += 0.04;
            $reasons[] = "live_closed:minimal({$liveClosed})";
        }

        // Component 3: shadow data supplement (0–0.15)
        if ($shadowClosed >= 15) {
            $score += 0.15;
            $reasons[] = "shadow:strong({$shadowClosed})";
        } elseif ($shadowClosed >= 5) {
            $score += 0.10;
            $reasons[] = "shadow:moderate({$shadowClosed})";
        } elseif ($shadowClosed >= 1) {
            $score += 0.04;
            $reasons[] = "shadow:some({$shadowClosed})";
        }

        // Component 4: recency (0–0.15)
        if ($recent >= 10) {
            $score += 0.15;
            $reasons[] = "recent:strong({$recent})";
        } elseif ($recent >= 5) {
            $score += 0.10;
            $reasons[] = "recent:ok({$recent})";
        } elseif ($recent >= 1) {
            $score += 0.04;
            $reasons[] = "recent:sparse({$recent})";
        } else {
            $reasons[] = "recent:none";
        }

        // Component 5: pattern-specific depth (0–0.10)
        $patternDepth = min($v2Samples, $v3Samples);
        if ($patternDepth >= 10) {
            $score += 0.10;
            $reasons[] = "pattern_depth:rich";
        } elseif ($patternDepth >= 5) {
            $score += 0.06;
            $reasons[] = "pattern_depth:ok";
        } elseif ($patternDepth >= 1) {
            $score += 0.02;
            $reasons[] = "pattern_depth:sparse";
        }

        $score = min(1.0, round($score, 4));

        // Map score to label
        $label = 'none';
        if ($score >= 0.65) {
            $label = 'high';
        } elseif ($score >= 0.35) {
            $label = 'medium';
        } elseif ($score > 0.0) {
            $label = 'low';
        }

        $summary = $label . ':' . implode(',', $reasons);

        return [$label, $score, $summary];
    }

    /**
     * Compute data sufficiency flags using the fresh-window model.
     *
     * Primary gate: 24h freshness.
     * Behavior context: 7d window.
     * Lifetime totals are NOT the primary driver.
     *
     * State mapping when insufficientFlag=true:
     *   fallbackMode='shadow_only' → no usable data at all (total=0, or 24h+7d both empty, or confidence=none)
     *   fallbackMode='sim_only'    → 7d has history but 24h is dead (stale coin)
     *
     * When insufficientFlag=false, computeLiveEligibility() decides among
     * sim_only / bootstrap_live / allow_live based on metric gates and sample counts.
     *
     * NOTE: 'low' confidence is intentionally NOT flagged as insufficient here.
     * LIVE_GATE_CONFIDENCE_MIN = 'low' means 'low' is the minimum accepted level,
     * so a coin with enough samples but low confidence must reach the regular
     * confidence gate inside computeLiveEligibility(), which will correctly pass it.
     * Treating 'low' as insufficient would bypass that gate and produce overbroad sim_only.
     *
     * @return array{bool, string|null, string}  [insufficient_flag, reason, fallback_mode]
     */
    private function computeDataSufficiency(
        int    $total,
        int    $shortV2,
        int    $shortV3,
        int    $recent,      // 30d bucket — kept for signature compat, not the primary gate
        string $confidence,
        int    $samples24h = 0,  // primary live gate window
        int    $samples7d  = 0   // behavior context window
    ): array {
        // No trade data at all → shadow_only (cannot say anything about the coin)
        if ($total === 0) {
            return [true, 'no_trade_data', 'shadow_only'];
        }

        // Primary gate: 24h freshness.
        // If 24h AND 7d both have zero samples, all data is older than 7 days.
        // Such stale data must not drive live admission.
        if ($samples24h === 0 && $samples7d === 0) {
            return [true, 'no_recent_data:all_data_older_than_7d', 'shadow_only'];
        }

        // 24h is dead but 7d has history → coin was recently active but has gone stale in 24h.
        // Demote to sim_only (not shadow_only, because we have recent context).
        if ($samples24h === 0) {
            return [true, "stale_24h:no_24h_evidence,{$samples7d}_7d_samples", 'sim_only'];
        }

        // No data confidence (e.g. zero total quality signals) → shadow_only
        if ($confidence === 'none') {
            return [true, 'no_data_confidence', 'shadow_only'];
        }

        // 24h has >= 1 sample; proceed to metric gates in computeLiveEligibility()
        // 'low' confidence is NOT treated as insufficient: the regular confidence
        // gate inside computeLiveEligibility() handles it correctly.
        return [false, null, 'live_eligible'];
    }

    /**
     * Market regime health score based on corridor quality and recent activity.
     * 0.0 = dead/unknown, 1.0 = strong.
     */
    private function computeMarketRegimeHealthScore(
        float $corridorP75,
        float $runnerProb,
        int   $recentSamples
    ): float {
        $score = 0.0;

        // Corridor quality component (0–0.5)
        if ($corridorP75 >= 8.0) {
            $score += 0.5;
        } elseif ($corridorP75 >= 5.0) {
            $score += 0.35;
        } elseif ($corridorP75 >= 3.0) {
            $score += 0.2;
        } elseif ($corridorP75 >= 1.0) {
            $score += 0.1;
        }

        // Runner probability component (0–0.3)
        if ($runnerProb >= 0.15) {
            $score += 0.3;
        } elseif ($runnerProb >= 0.07) {
            $score += 0.15;
        } elseif ($runnerProb >= 0.03) {
            $score += 0.07;
        }

        // Recent activity component (0–0.2)
        if ($recentSamples >= 10) {
            $score += 0.2;
        } elseif ($recentSamples >= 5) {
            $score += 0.12;
        } elseif ($recentSamples >= self::MIN_RECENT_SAMPLES) {
            $score += 0.05;
        }

        return min(1.0, round($score, 4));
    }

    /**
     * Compute live eligibility decision based on all passport metrics.
     *
     * States (in order of restrictiveness):
     *   shadow_only    – forced by computeDataSufficiency() for no-data / no-confidence cases
     *   sim_only       – forced by computeDataSufficiency() for stale 24h, OR by a metric gate failure
     *   bootstrap_live – 24h has >= MIN_SAMPLES_24H_FOR_BOOTSTRAP samples, all metric gates pass,
     *                    but 24h count is below MIN_SAMPLES_24H_FOR_ALLOW_LIVE OR 7d context is thin.
     *                    Live orders are permitted at reduced confidence — Smart Brain must tag these.
     *   allow_live     – 24h >= MIN_SAMPLES_24H_FOR_ALLOW_LIVE, 7d context sufficient
     *                    (>= MIN_SAMPLES_7D_FOR_CONTEXT), AND all metric gates pass.
     *
     * Which passport fields trigger sim_only:
     *   - data_confidence below LIVE_GATE_CONFIDENCE_MIN ('low')
     *   - corridor_p75_roi < LIVE_GATE_CORRIDOR_P75_MIN (3.0)
     *   - noise_score > LIVE_GATE_NOISE_MAX (0.65)
     *   - short_suitability_score < LIVE_GATE_SUITABILITY_MIN (0.3)
     *   - runner_probability < LIVE_GATE_RUNNER_PROB_MIN (0.05)
     *   - market_regime_health_score < LIVE_GATE_REGIME_HEALTH_MIN (0.3)
     *   - impulse_strength_score < LIVE_GATE_IMPULSE_STRENGTH_MIN (0.2)
     *   - pullback_severity_score > LIVE_GATE_PULLBACK_SEVERITY_MAX (0.75)
     *   - v2_success_rate < 0.35 (when pattern data is available)
     *
     * What causes bootstrap_live (metric gates all pass, but fresh window is thin):
     *   - recent_samples_24h >= MIN_SAMPLES_24H_FOR_BOOTSTRAP (1)
     *     but < MIN_SAMPLES_24H_FOR_ALLOW_LIVE (2)
     *   - OR recent_samples_7d < MIN_SAMPLES_7D_FOR_CONTEXT (3)
     *
     * Fallback when passport data is missing / partial / stale:
     *   - total=0 or 24h+7d=0             → shadow_only (handled by computeDataSufficiency)
     *   - 24h=0 but 7d>0                   → sim_only   (handled by computeDataSufficiency)
     *   - confidence='none'                → shadow_only (handled by computeDataSufficiency)
     *   - insufficientFlag=true, otherwise → sim_only or shadow_only per fallbackMode
     *
     * @param string $insufficientReason  The actual reason set by computeDataSufficiency()
     *                                    (e.g. "stale_24h:no_24h_evidence,5_7d_samples"). Used to
     *                                    produce an informative live_block_reason instead of
     *                                    the opaque "insufficient_data:{fallbackMode}" string.
     * @return array{string, string|null}  [eligibility, block_reason]
     */
    private function computeLiveEligibility(
        float   $corridorP75,
        float   $runnerProb,
        float   $shortSuitability,
        float   $noiseScore,
        string  $dataConfidence,
        float   $regimeHealth,
        bool    $insufficientFlag,
        string  $fallbackMode,
        float   $impulseStrength = 0.5,
        float   $pullbackSeverity = 0.5,
        ?float  $patternSuccessRate = null,
        string  $insufficientReason = '',
        int     $samples24h = 0,  // primary 24h window count
        int     $samples7d  = 0   // 7d behavior context window count
    ): array {
        // Insufficient data → forced fallback; include actual reason for observability.
        // The state is either shadow_only or sim_only per computeDataSufficiency().
        if ($insufficientFlag) {
            $detailReason = $insufficientReason !== '' ? $insufficientReason : $fallbackMode;
            return [$fallbackMode === 'shadow_only' ? 'shadow_only' : 'sim_only',
                    "insufficient_data:{$detailReason}"];
        }

        // ── Metric gate chain ────────────────────────────────────────────────────
        // All of these produce sim_only on failure — they reflect hard behavioral
        // constraints that must hold regardless of the 24h freshness level.

        // Data confidence gate
        $confRank = ['none' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];
        $minRank  = $confRank[self::LIVE_GATE_CONFIDENCE_MIN] ?? 1;
        $curRank  = $confRank[$dataConfidence] ?? 0;
        if ($curRank < $minRank) {
            return ['sim_only', "data_confidence_too_low:{$dataConfidence}"];
        }

        // Corridor P75 gate
        if ($corridorP75 < self::LIVE_GATE_CORRIDOR_P75_MIN) {
            return ['sim_only', "corridor_p75_too_low:{$corridorP75}<" . self::LIVE_GATE_CORRIDOR_P75_MIN];
        }

        // Noise gate
        if ($noiseScore > self::LIVE_GATE_NOISE_MAX) {
            return ['sim_only', "noise_score_too_high:{$noiseScore}>" . self::LIVE_GATE_NOISE_MAX];
        }

        // Suitability gate
        if ($shortSuitability < self::LIVE_GATE_SUITABILITY_MIN) {
            return ['sim_only', "short_suitability_too_low:{$shortSuitability}<" . self::LIVE_GATE_SUITABILITY_MIN];
        }

        // Runner probability gate
        if ($runnerProb < self::LIVE_GATE_RUNNER_PROB_MIN) {
            return ['sim_only', "runner_prob_too_low:{$runnerProb}<" . self::LIVE_GATE_RUNNER_PROB_MIN];
        }

        // Regime health gate
        if ($regimeHealth < self::LIVE_GATE_REGIME_HEALTH_MIN) {
            return ['sim_only', "regime_health_too_low:{$regimeHealth}<" . self::LIVE_GATE_REGIME_HEALTH_MIN];
        }

        // Impulse strength gate (weak impulse = coin doesn't move meaningfully)
        if ($impulseStrength < self::LIVE_GATE_IMPULSE_STRENGTH_MIN) {
            return ['sim_only', "impulse_strength_too_low:{$impulseStrength}<" . self::LIVE_GATE_IMPULSE_STRENGTH_MIN];
        }

        // Pullback severity gate (extreme retracing = dangerous for live)
        if ($pullbackSeverity > self::LIVE_GATE_PULLBACK_SEVERITY_MAX) {
            return ['sim_only', "pullback_severity_too_high:{$pullbackSeverity}>" . self::LIVE_GATE_PULLBACK_SEVERITY_MAX];
        }

        // Pattern-specific success rate gate (if we have pattern data)
        if ($patternSuccessRate !== null && $patternSuccessRate < 0.35) {
            return ['sim_only', "pattern_success_rate_too_low:{$patternSuccessRate}<0.35"];
        }

        // ── All metric gates passed. Apply fresh-window ceiling. ─────────────────
        //
        // 24h freshness is the primary live gate:
        //   < MIN_SAMPLES_24H_FOR_ALLOW_LIVE (2) → bootstrap_live (probationary)
        //   >= MIN_SAMPLES_24H_FOR_ALLOW_LIVE     → check 7d context
        //
        // 7d behavior context:
        //   < MIN_SAMPLES_7D_FOR_CONTEXT (3) → bootstrap_live (limited context)
        //   >= MIN_SAMPLES_7D_FOR_CONTEXT    → allow_live

        if ($samples24h < self::MIN_SAMPLES_24H_FOR_ALLOW_LIVE) {
            // 24h has data (computeDataSufficiency ensured >= 1 sample) but not enough
            // for full allow_live confidence. Permit live at bootstrap level.
            return [
                'bootstrap_live',
                "bootstrap_24h:only_{$samples24h}_24h_samples,need_" . self::MIN_SAMPLES_24H_FOR_ALLOW_LIVE,
            ];
        }

        if ($samples7d < self::MIN_SAMPLES_7D_FOR_CONTEXT) {
            // 24h is strong but 7d context is too thin to confirm stable behavior.
            return [
                'bootstrap_live',
                "bootstrap_7d:limited_context_{$samples7d}_7d_samples,need_" . self::MIN_SAMPLES_7D_FOR_CONTEXT,
            ];
        }

        // Strong 24h freshness + sufficient 7d context + all metric gates passed.
        return ['allow_live', null];
    }

    // =========================================================================
    // Recommendation helpers
    // =========================================================================

    private function recommendLockStart(float $medianMax, float $p75, float $corridorLow, string $confidence): float
    {
        if ($confidence === 'none') {
            return 3.0;
        }
        // Conservative: lock at corridor low, capped between 1.5 and 5
        $base = max(1.5, $corridorLow * 0.6);
        return min(5.0, round($base, 1));
    }

    private function recommendStage1(float $corridorMid, float $medianPullback3): float
    {
        $base = max(3.0, $corridorMid * 0.5);
        return min(7.0, round($base, 1));
    }

    private function recommendStage2(float $corridorHigh, float $p90MaxRoi): float
    {
        $base = max(5.0, $corridorHigh * 0.7);
        return min(15.0, round($base, 1));
    }

    private function recommendLiveFloorRoi(float $corridorP75, string $confidence): float
    {
        if ($confidence === 'none' || $confidence === 'low') {
            return 0.0;
        }
        // Minimum live target: at least 40% of P75 corridor, floored at 2.0
        return max(2.0, round($corridorP75 * 0.4, 1));
    }

    private function recommendLadderMode(float $runnerProb, float $p90MaxRoi, string $confidence): string
    {
        if ($confidence === 'none' || $confidence === 'low') {
            return 'conservative';
        }
        if ($runnerProb >= 0.15 && $p90MaxRoi >= 10.0) {
            return 'aggressive_ladder';
        }
        if ($runnerProb >= 0.05 || $p90MaxRoi >= 7.0) {
            return 'soft_ladder';
        }
        return 'conservative';
    }

    private function recommendHarvestAggressiveness(float $medianPullback3, float $medianPullback5, float $noiseScore): string
    {
        if ($noiseScore >= 0.6 || $medianPullback3 >= 3.0) {
            return 'aggressive';
        }
        if ($noiseScore >= 0.3 || $medianPullback3 >= 1.5) {
            return 'moderate';
        }
        return 'patient';
    }

    private function recommendMaxHoldMinutes(?float $avgHoldMinutes, float $runnerProb, float $corridorP75): ?float
    {
        if ($avgHoldMinutes === null || $avgHoldMinutes <= 0) {
            return null;
        }
        // Runner coins: allow longer holds; weak corridor: tighter hold limit
        $multiplier = 1.5;
        if ($runnerProb >= 0.15 || $corridorP75 >= 8.0) {
            $multiplier = 2.5;
        } elseif ($runnerProb >= 0.05 || $corridorP75 >= 4.0) {
            $multiplier = 2.0;
        }
        return round($avgHoldMinutes * $multiplier, 0);
    }

    private function recommendRunnerExpectation(float $runnerProb, float $reach10Rate, float $corridorP90): string
    {
        if ($runnerProb >= 0.2 || $reach10Rate >= 0.2 || $corridorP90 >= 15.0) {
            return 'high_runner';
        }
        if ($runnerProb >= 0.07 || $reach10Rate >= 0.07 || $corridorP90 >= 8.0) {
            return 'occasional_runner';
        }
        return 'scalp_coin';
    }

    // =========================================================================
    // Diagnostic notes
    // =========================================================================

    /** @return list<string> */
    private function buildDiagnosticNotes(
        int    $sampleSize,
        string $confidence,
        float  $runnerProb,
        float  $medianMaxRoi,
        float  $p75MaxRoi,
        float  $p90MaxRoi,
        float  $corridorLow,
        float  $corridorHigh,
        string $liveEligibility = 'unknown',
        ?string $liveBlockReason = null,
        bool   $insufficientFlag = false
    ): array {
        $notes = [];

        if ($confidence === 'none') {
            $notes[] = 'No trade data available — passport is theoretical defaults only.';
        } elseif ($confidence === 'low') {
            $notes[] = "Low confidence: only {$sampleSize} sample(s). Accumulate more trades for accuracy.";
        }

        if ($insufficientFlag) {
            $notes[] = "Insufficient data: symbol pushed to sim/shadow-only until more evidence accumulates.";
        }

        if ($runnerProb >= 0.15) {
            $notes[] = "Runner coin: " . round($runnerProb * 100, 1) . "% chance of reaching 10+ ROI.";
        } elseif ($runnerProb >= 0.05) {
            $notes[] = "Occasional runner: some trades reach 10+ ROI.";
        } else {
            $notes[] = "Low runner probability: 10+ ROI is rare for this symbol.";
        }

        if ($medianMaxRoi > 0) {
            $notes[] = "Typical trade peaks at {$medianMaxRoi}% ROI (median). 75th pct: {$p75MaxRoi}%.";
        }

        if ($corridorHigh - $corridorLow > 8) {
            $notes[] = "Wide ROI corridor ({$corridorLow}–{$corridorHigh}%) — high variability.";
        }

        if ($liveEligibility === 'allow_live') {
            $notes[] = "Live eligibility: ALLOWED — all passport gates passed.";
        } elseif ($liveEligibility === 'bootstrap_live') {
            $notes[] = "Live eligibility: BOOTSTRAP_LIVE — probationary live; 24h/7d windows thin. Reason: {$liveBlockReason}.";
        } elseif ($liveBlockReason !== null) {
            $notes[] = "Live eligibility: {$liveEligibility} — blocked: {$liveBlockReason}.";
        }

        return $notes;
    }

    // =========================================================================
    // Persistence
    // =========================================================================

    /** @param array<string,mixed> $passport */
    private function save(string $symbol, array $passport): void
    {
        $path = $this->passportPath($symbol);
        file_put_contents(
            $path,
            json_encode($passport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    private function passportPath(string $symbol): string
    {
        $safe = preg_replace('/[^A-Z0-9_\-]/', '', strtoupper($symbol));
        return $this->passportsDir . '/' . $safe . '.json';
    }

    private function evidencePath(string $symbol): string
    {
        $safe = preg_replace('/[^A-Z0-9_\-]/', '', strtoupper($symbol));
        return $this->evidenceDir . '/' . $safe . '.json';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Compute the trust_state field for the Decision Engine.
     *
     * States (roadmap Phase 4):
     *   green            – live-eligible with solid evidence; system can act confidently
     *   yellow           – some evidence; proceed with caution
     *   red              – poor performance or high noise; avoid live, prefer demo learning
     *   insufficient_data – not enough evidence to make a judgment
     *
     * Promotion rules:
     *   insufficient_data → yellow: requires at least MIN_HEALTHY_CLOSED_FOR_YELLOW non-orphan healthy closes
     *   yellow → green:             requires at least MIN_HEALTHY_CLOSED_FOR_GREEN non-orphan healthy closes
     *                               + allow_live + medium/high data_confidence + noise <= 0.55
     *   Orphan protection: if healthy_closed_samples < MIN_HEALTHY_CLOSED_FOR_YELLOW, always insufficient_data
     *
     * bootstrap_live is treated as yellow: the coin is live-capable but still on probation.
     */
    // =========================================================================
    // Coin Cycle Profile (data layer — not yet wired into live or PM decisions)
    // =========================================================================

    /**
     * Build derived coin behavior cycle profiles from parser2_history_accumulator NDJSON data.
     *
     * Primary window: 24h. Context window: 7d. Data older than 7d is excluded entirely.
     * Output is a read-only behavior profile artifact — does NOT feed into live admission
     * or PM decisions at this stage.
     *
     * @param string $parser2StorageDir  Absolute path to parser2 per-symbol storage root
     *                                   (contains {SYMBOL}/{YYYY-MM-DD}.ndjson files)
     * @param string $runtimeOutputPath  Absolute path to write coin_cycle_profile.json
     * @param int    $now                Unix timestamp (0 = use time())
     * @return array<string,mixed>
     */
    public function buildCoinCycleProfiles(string $parser2StorageDir, string $runtimeOutputPath, int $now = 0): array
    {
        if ($now === 0) {
            $now = time();
        }
        $generatedAt = date('c', $now);

        /** @var array<string,int> $cutoffs */
        $cutoffs = [
            '1h'  => $now - 3600,
            '2h'  => $now - 7200,
            '3h'  => $now - 10800,
            '6h'  => $now - 21600,
            '12h' => $now - 43200,
            '24h' => $now - 86400,
            '7d'  => $now - 604800,
        ];

        $cycleSymbolsTotal = 0;
        $cycleProfilesOk   = 0;
        $cycleErrorTotal   = 0;
        $profiles          = [];

        if (is_dir($parser2StorageDir)) {
            $entries = scandir($parser2StorageDir) ?: [];
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $entryPath = $parser2StorageDir . '/' . $entry;
                if (!is_dir($entryPath)) {
                    continue;
                }
                // Symbol directory names are uppercase alphanumeric (e.g. BTCUSDT)
                if (!preg_match('/^[A-Z][A-Z0-9]+$/', $entry)) {
                    continue;
                }
                $cycleSymbolsTotal++;
                $symbol = $entry;
                try {
                    $records           = $this->readParser2SymbolRecords($entryPath, $cutoffs['7d'], $now);
                    $profiles[$symbol] = $this->computeCycleProfile($symbol, $records, $cutoffs, $generatedAt);
                    $cycleProfilesOk++;
                } catch (\Throwable $ex) {
                    $profiles[$symbol] = [
                        'symbol'                    => $symbol,
                        'updated_at'                => $generatedAt,
                        'behavior_cycle_state'      => 'error',
                        'behavior_cycle_confidence' => 'none',
                    ];
                    $cycleErrorTotal++;
                }
            }
            ksort($profiles);
        }

        $artifact = [
            'generated_at'                   => $generatedAt,
            'source'                         => 'parser2_history_accumulator',
            'symbols_total'                  => $cycleSymbolsTotal,
            'generated_ok'                   => $cycleProfilesOk,
            'cycle_symbols_total'            => $cycleSymbolsTotal,
            'cycle_profiles_generated_total' => $cycleProfilesOk,
            'cycle_generation_error_total'   => $cycleErrorTotal,
            'symbols'                        => $profiles,
        ];

        $runtimeDir = dirname($runtimeOutputPath);
        if (!is_dir($runtimeDir)) {
            @mkdir($runtimeDir, 0755, true);
        }
        @file_put_contents(
            $runtimeOutputPath,
            json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return $artifact;
    }

    // =========================================================================
    // Coin cycle read model (Step 2 — data layer only, no live routing)
    // =========================================================================

    /**
     * Build coin_cycle_read_model.json from a previously written coin_cycle_profile.json.
     *
     * Reads the raw derived cycle metrics from the profile artifact and converts them
     * into compact, decision-friendly per-symbol state summaries.
     * Best-effort and non-fatal: if the profile is missing or a symbol entry fails,
     * the remaining symbols are still processed.
     *
     * @param  string $profilePath        Absolute path to coin_cycle_profile.json
     * @param  string $readModelOutputPath Absolute path to write coin_cycle_read_model.json
     * @return array<string,mixed>
     */
    public function buildCoinCycleReadModel(string $profilePath, string $readModelOutputPath): array
    {
        $generatedAt     = date('c');
        $symbolsTotal    = 0;
        $generatedTotal  = 0;
        $errorTotal      = 0;
        $highConfTotal   = 0;
        $mediumConfTotal = 0;
        $lowConfTotal    = 0;
        $entryReadyTotal = 0;
        $pmReadyTotal    = 0;
        $entries         = [];

        $sourceProfileUpdated = null;

        if (is_file($profilePath)) {
            $raw = @file_get_contents($profilePath);
            $profile = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($profile)) {
                $sourceProfileUpdated = $profile['generated_at'] ?? null;
                $symbols = $profile['symbols'] ?? [];
                if (is_array($symbols)) {
                    foreach ($symbols as $symbol => $symProfile) {
                        if (!is_array($symProfile)) {
                            continue;
                        }
                        $symbolsTotal++;
                        try {
                            $entry = $this->deriveSymbolReadModelEntry($symProfile, $generatedAt);
                            $entries[$symbol] = $entry;
                            $generatedTotal++;
                            $conf = $entry['behavior_cycle_confidence'] ?? 'none';
                            if ($conf === 'high')             { $highConfTotal++; }
                            elseif ($conf === 'medium')       { $mediumConfTotal++; }
                            else                              { $lowConfTotal++; }
                            if (($entry['cycle_entry_readiness'] ?? '') === 'ready') { $entryReadyTotal++; }
                            if (($entry['cycle_pm_readiness']    ?? '') === 'ready') { $pmReadyTotal++; }
                        } catch (\Throwable $ex) {
                            $entries[$symbol] = [
                                'symbol'                    => (string)$symbol,
                                'updated_at'                => $generatedAt,
                                'behavior_cycle_state'      => 'error',
                                'behavior_cycle_confidence' => 'none',
                                'low_confidence_reason'     => 'read_model_build_error',
                            ];
                            $errorTotal++;
                            $lowConfTotal++;
                        }
                    }
                    ksort($entries);
                }
            }
        }

        $artifact = [
            'generated_at'          => $generatedAt,
            'source'                => 'coin_cycle_profile',
            'source_profile_updated_at' => $sourceProfileUpdated,
            'symbols_total'         => $symbolsTotal,
            'high_confidence_total' => $highConfTotal,
            'medium_confidence_total' => $mediumConfTotal,
            'low_confidence_total'  => $lowConfTotal,
            'entry_ready_total'     => $entryReadyTotal,
            'pm_ready_total'        => $pmReadyTotal,
            'generated_ok'          => $generatedTotal,
            'error_total'           => $errorTotal,
            'symbols'               => $entries,
        ];

        $runtimeDir = dirname($readModelOutputPath);
        if (!is_dir($runtimeDir)) {
            @mkdir($runtimeDir, 0755, true);
        }
        @file_put_contents(
            $readModelOutputPath,
            json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return [
            'read_model_symbols_total'   => $symbolsTotal,
            'read_model_generated_total' => $generatedTotal,
            'read_model_error_total'     => $errorTotal,
            'generated_at'               => $generatedAt,
        ];
    }

    /**
     * Derive a compact read-model entry for one symbol from its raw cycle profile.
     *
     * @param  array<string,mixed> $profile     Raw profile entry from coin_cycle_profile.json
     * @param  string              $generatedAt ISO timestamp of the read model generation run
     * @return array<string,mixed>
     */
    private function deriveSymbolReadModelEntry(array $profile, string $generatedAt): array
    {
        $state      = $profile['behavior_cycle_state']      ?? 'insufficient_data';
        $confidence = $profile['behavior_cycle_confidence'] ?? 'none';

        // ── Per-window activity states ──────────────────────────────────────────
        $hot1h = $this->windowActivityState(
            (int)($profile['recent_samples_1h'] ?? 0),
            $profile['corridor_range_1h'] ?? null,
            $profile['impulse_strength_1h'] ?? null
        );

        // 2h: no direct corridor/impulse in profile — proxy via 1h activity + 2h sample count
        $s2h   = (int)($profile['recent_samples_2h'] ?? 0);
        $hot2h = 'insufficient_data';
        if ($s2h >= 2) {
            if ($hot1h === 'active' && $s2h >= 5) {
                $hot2h = 'active';
            } elseif ($hot1h === 'cooling' || $hot1h === 'flat') {
                $hot2h = $hot1h;
            } else {
                $hot2h = 'cooling';
            }
        }

        $short3h = $this->windowActivityState(
            (int)($profile['recent_samples_3h'] ?? 0),
            $profile['corridor_range_3h'] ?? null,
            $profile['impulse_strength_3h'] ?? null
        );

        $short6h = $this->windowActivityState(
            (int)($profile['recent_samples_6h'] ?? 0),
            $profile['corridor_range_6h'] ?? null,
            $profile['impulse_strength_6h'] ?? null
        );

        // 12h: no direct corridor/impulse in profile — proxy via 24h metrics + 12h sample count
        $s12h        = (int)($profile['recent_samples_12h'] ?? 0);
        $range24h    = $profile['corridor_range_24h'] ?? null;
        $impulse24h  = $profile['impulse_strength_24h'] ?? null;
        $intraday12h = 'insufficient_data';
        if ($s12h >= 2) {
            if ($range24h !== null && $range24h >= 0.5 && ($impulse24h ?? 0.0) >= 0.25 && $s12h >= 10) {
                $intraday12h = 'active';
            } elseif ($range24h !== null && $range24h >= 0.2) {
                $intraday12h = 'cooling';
            } else {
                $intraday12h = 'flat';
            }
        }

        $daily24h = $this->windowActivityState(
            (int)($profile['recent_samples_24h'] ?? 0),
            $range24h,
            $impulse24h
        );

        // ── Structural states (24h primary) ────────────────────────────────────
        $corridorState = $this->deriveCorridorState($range24h);
        $impulseState  = $this->deriveImpulseState($impulse24h);

        $pullbackState     = (string)($profile['pullback_profile_24h']     ?? 'insufficient_data');
        $continuationState = (string)($profile['continuation_profile_24h'] ?? 'insufficient_data');

        $vol24h         = $profile['volatility_profile_24h'] ?? null;
        $volatilityState = $this->deriveVolatilityState($vol24h);

        $liquidityProfile = $profile['liquidity_profile_24h'] ?? [];
        $liquidityState   = is_array($liquidityProfile)
            ? (string)($liquidityProfile['state'] ?? 'insufficient_data')
            : 'insufficient_data';

        $oiPressureState = (string)($profile['oi_pressure_profile_24h'] ?? 'insufficient_data');

        // ── Derived composite states ────────────────────────────────────────────
        $cycleBiasState      = $this->deriveCycleBiasState($impulseState, $continuationState, $pullbackState);
        $cycleQualityState   = $this->deriveCycleQualityState($confidence, (int)($profile['recent_samples_24h'] ?? 0));
        $cycleStabilityState = $this->deriveCycleStabilityState($volatilityState, $corridorState);
        $cycleEntryReadiness = $this->deriveCycleEntryReadiness($state, $confidence, $impulseState, $volatilityState, $liquidityState, $continuationState);
        $cyclePmReadiness    = $this->deriveCyclePmReadiness($state, $confidence, $corridorState, $volatilityState);

        // ── Low-confidence reason ───────────────────────────────────────────────
        $lowConfidenceReason = null;
        if ($confidence === 'none' || $state === 'insufficient_data') {
            $s24h = (int)($profile['recent_samples_24h'] ?? 0);
            if ($s24h < 2) {
                $lowConfidenceReason = 'insufficient_24h_samples';
            } elseif ($state === 'insufficient_data') {
                $lowConfidenceReason = 'state_undetermined';
            } else {
                $lowConfidenceReason = 'no_confidence';
            }
        } elseif ($confidence === 'low') {
            $lowConfidenceReason = 'low_sample_count';
        }

        return [
            'symbol'                    => (string)($profile['symbol'] ?? ''),
            'updated_at'                => $generatedAt,
            'behavior_cycle_state'      => $state,
            'behavior_cycle_confidence' => $confidence,
            'hot_state_1h'              => $hot1h,
            'hot_state_2h'              => $hot2h,
            'short_state_3h'            => $short3h,
            'short_state_6h'            => $short6h,
            'intraday_state_12h'        => $intraday12h,
            'daily_state_24h'           => $daily24h,
            'behavior_context_7d_state' => (string)($profile['behavior_context_7d_state'] ?? 'insufficient_data'),
            'corridor_state'            => $corridorState,
            'impulse_state'             => $impulseState,
            'pullback_state'            => $pullbackState,
            'continuation_state'        => $continuationState,
            'volatility_state'          => $volatilityState,
            'liquidity_state'           => $liquidityState,
            'oi_pressure_state'         => $oiPressureState,
            'cycle_bias_state'          => $cycleBiasState,
            'cycle_quality_state'       => $cycleQualityState,
            'cycle_stability_state'     => $cycleStabilityState,
            'cycle_entry_readiness'     => $cycleEntryReadiness,
            'cycle_pm_readiness'        => $cyclePmReadiness,
            'low_confidence_reason'     => $lowConfidenceReason,
            'source_profile_updated_at' => $profile['updated_at'] ?? null,
        ];
    }

    /**
     * Classify window activity from sample count, corridor range %, and impulse strength.
     * Returns 'active'|'cooling'|'flat'|'insufficient_data'.
     */
    private function windowActivityState(int $samples, ?float $corridorRange, ?float $impulse): string
    {
        if ($samples < 2 || $corridorRange === null) {
            return 'insufficient_data';
        }
        $imp = $impulse ?? 0.0;
        if ($corridorRange >= 0.5 && $imp >= 0.25) {
            return 'active';
        }
        if ($corridorRange < 0.2) {
            return 'flat';
        }
        return 'cooling';
    }

    /**
     * Corridor state from 24h range %.
     * Returns 'wide'|'normal'|'narrow'|'tight'|'insufficient_data'.
     */
    private function deriveCorridorState(?float $range24h): string
    {
        if ($range24h === null) {
            return 'insufficient_data';
        }
        if ($range24h >= 3.0) { return 'wide'; }
        if ($range24h >= 1.0) { return 'normal'; }
        if ($range24h >= 0.3) { return 'narrow'; }
        return 'tight';
    }

    /**
     * Impulse state from 24h impulse strength (0–1).
     * Returns 'strong'|'moderate'|'weak'|'insufficient_data'.
     */
    private function deriveImpulseState(?float $impulse24h): string
    {
        if ($impulse24h === null) {
            return 'insufficient_data';
        }
        if ($impulse24h >= 0.6) { return 'strong'; }
        if ($impulse24h >= 0.3) { return 'moderate'; }
        return 'weak';
    }

    /**
     * Volatility state from 24h coefficient-of-variation %.
     * Returns 'high'|'moderate'|'low'|'insufficient_data'.
     */
    private function deriveVolatilityState(?float $vol24h): string
    {
        if ($vol24h === null) {
            return 'insufficient_data';
        }
        if ($vol24h >= 2.0)  { return 'high'; }
        if ($vol24h >= 0.5)  { return 'moderate'; }
        return 'low';
    }

    /**
     * Cycle bias: directional character derived from impulse, continuation, pullback.
     * Returns 'directional'|'reverting'|'choppy'|'neutral'|'insufficient_data'.
     */
    private function deriveCycleBiasState(string $impulse, string $continuation, string $pullback): string
    {
        if ($impulse === 'insufficient_data' && $continuation === 'insufficient_data') {
            return 'insufficient_data';
        }
        if ($pullback === 'severe') {
            return 'reverting';
        }
        if ($impulse === 'strong' && in_array($continuation, ['moderate', 'strong'], true)) {
            return 'directional';
        }
        if ($impulse === 'weak' && $continuation === 'none') {
            return 'choppy';
        }
        return 'neutral';
    }

    /**
     * Cycle quality: overall data quality label.
     * Returns 'high'|'medium'|'low'|'insufficient_data'.
     */
    private function deriveCycleQualityState(string $confidence, int $samples24h): string
    {
        if ($confidence === 'none') { return 'insufficient_data'; }
        if ($confidence === 'high' && $samples24h >= 100) { return 'high'; }
        if ($confidence === 'medium')                     { return 'medium'; }
        if ($confidence === 'high')                       { return 'medium'; } // high conf but not enough samples for 'high' quality
        return 'low';
    }

    /**
     * Cycle stability: how settled/predictable the price corridor is.
     * Returns 'stable'|'moderate'|'volatile'|'insufficient_data'.
     */
    private function deriveCycleStabilityState(string $volatility, string $corridor): string
    {
        if ($volatility === 'insufficient_data' || $corridor === 'insufficient_data') {
            return 'insufficient_data';
        }
        if ($volatility === 'high' || $corridor === 'wide') {
            return 'volatile';
        }
        if ($volatility === 'low' && in_array($corridor, ['narrow', 'tight'], true)) {
            return 'stable';
        }
        return 'moderate';
    }

    /**
     * Cycle entry readiness: whether this symbol looks favourable for a new entry.
     * Returns 'ready'|'marginal'|'not_ready'.
     */
    private function deriveCycleEntryReadiness(
        string $state,
        string $confidence,
        string $impulse,
        string $volatility,
        string $liquidity,
        string $continuation
    ): string {
        if ($confidence === 'none' || $state === 'insufficient_data') {
            return 'not_ready';
        }
        if ($state !== 'active') {
            return 'not_ready';
        }
        // Active but high volatility or poor liquidity → marginal
        if ($volatility === 'high') {
            return 'marginal';
        }
        if (in_array($liquidity, ['insufficient_data', 'unknown', 'low'], true)) {
            return 'marginal';
        }
        if ($impulse === 'strong' && in_array($continuation, ['moderate', 'strong'], true)) {
            return 'ready';
        }
        return 'marginal';
    }

    /**
     * Cycle PM readiness: whether conditions support profit-manager trailing.
     * Returns 'ready'|'marginal'|'not_ready'.
     */
    private function deriveCyclePmReadiness(
        string $state,
        string $confidence,
        string $corridor,
        string $volatility
    ): string {
        if ($confidence === 'none' || $state === 'insufficient_data') {
            return 'not_ready';
        }
        if ($state === 'active' && in_array($corridor, ['normal', 'wide'], true) && $volatility !== 'high') {
            return 'ready';
        }
        if ($state !== 'flat') {
            return 'marginal';
        }
        return 'not_ready';
    }

    /**
     * Read all parser2 NDJSON records for one symbol that fall within the 7d window.
     * Reads daily files for the last 8 calendar days (extra day for timezone boundaries).
     *
     * @param  string $symDir   Absolute path to the symbol's NDJSON directory
     * @param  int    $cutoff7d Unix timestamp: exclude records older than this
     * @param  int    $now      Current Unix timestamp
     * @return list<array<string,mixed>>  Records sorted by ts_unix ascending
     */
    private function readParser2SymbolRecords(string $symDir, int $cutoff7d, int $now): array
    {
        $records = [];
        for ($i = 0; $i <= 7; $i++) {
            $day  = date('Y-m-d', $now - $i * 86400);
            $file = $symDir . '/' . $day . '.ndjson';
            if (!is_file($file)) {
                continue;
            }
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $line) {
                $row = json_decode($line, true);
                if (!is_array($row)) {
                    continue;
                }
                $tsUnix = (int)($row['ts_unix'] ?? 0);
                if ($tsUnix < $cutoff7d) {
                    continue; // Older than 7d — exclude
                }
                $records[] = $row;
            }
        }
        // Sort ascending by ts_unix (oldest first — needed for directional metrics)
        usort($records, static fn(array $a, array $b): int => $a['ts_unix'] <=> $b['ts_unix']);
        return $records;
    }

    /**
     * Compute the derived cycle profile for one symbol from its NDJSON records.
     *
     * @param  string                    $symbol
     * @param  list<array<string,mixed>> $records    All 7d records sorted ascending by ts_unix
     * @param  array<string,int>         $cutoffs    Window cutoffs keyed by '1h','2h',…,'7d'
     * @param  string                    $generatedAt ISO timestamp string
     * @return array<string,mixed>
     */
    private function computeCycleProfile(
        string $symbol,
        array  $records,
        array  $cutoffs,
        string $generatedAt
    ): array {
        // Partition records into per-window buckets
        $b1h  = [];
        $b2h  = [];
        $b3h  = [];
        $b6h  = [];
        $b12h = [];
        $b24h = [];
        $b7d  = $records;

        foreach ($records as $r) {
            $ts = (int)($r['ts_unix'] ?? 0);
            if ($ts >= $cutoffs['1h'])  { $b1h[]  = $r; }
            if ($ts >= $cutoffs['2h'])  { $b2h[]  = $r; }
            if ($ts >= $cutoffs['3h'])  { $b3h[]  = $r; }
            if ($ts >= $cutoffs['6h'])  { $b6h[]  = $r; }
            if ($ts >= $cutoffs['12h']) { $b12h[] = $r; }
            if ($ts >= $cutoffs['24h']) { $b24h[] = $r; }
        }

        // Pre-compute 24h metrics used by state/confidence/context
        $range24h   = $this->cycleCorridorRange($b24h);
        $impulse24h = $this->cycleImpulseStrength($b24h);
        $vol24h     = $this->cycleVolatilityPct($b24h);

        $profile = [
            'symbol'     => $symbol,
            'updated_at' => $generatedAt,
            // Sample counts per window
            'recent_samples_1h'  => count($b1h),
            'recent_samples_2h'  => count($b2h),
            'recent_samples_3h'  => count($b3h),
            'recent_samples_6h'  => count($b6h),
            'recent_samples_12h' => count($b12h),
            'recent_samples_24h' => count($b24h),
            'recent_samples_7d'  => count($b7d),
            // Corridor ranges (% of mid-price; null = insufficient samples)
            'corridor_range_1h'  => $this->cycleCorridorRange($b1h),
            'corridor_range_3h'  => $this->cycleCorridorRange($b3h),
            'corridor_range_6h'  => $this->cycleCorridorRange($b6h),
            'corridor_range_24h' => $range24h,
            // Impulse strength: 0.0 = choppy/flat, 1.0 = strongly directional
            'impulse_strength_1h'  => $this->cycleImpulseStrength($b1h),
            'impulse_strength_3h'  => $this->cycleImpulseStrength($b3h),
            'impulse_strength_6h'  => $this->cycleImpulseStrength($b6h),
            'impulse_strength_24h' => $impulse24h,
            // Pullback profiles
            'pullback_profile_6h'  => $this->cyclePullbackProfile($b6h),
            'pullback_profile_24h' => $this->cyclePullbackProfile($b24h),
            // Continuation profiles
            'continuation_profile_6h'  => $this->cycleContinuationProfile($b6h),
            'continuation_profile_24h' => $this->cycleContinuationProfile($b24h),
            // Volatility profiles (coefficient of variation, %)
            'volatility_profile_6h'  => $this->cycleVolatilityPct($b6h),
            'volatility_profile_24h' => $vol24h,
            // Liquidity (from 24h window)
            'liquidity_profile_24h' => $this->cycleLiquidityProfile($b24h),
            // Open-interest pressure
            'oi_pressure_profile_6h'  => $this->cycleOiPressureProfile($b6h),
            'oi_pressure_profile_24h' => $this->cycleOiPressureProfile($b24h),
        ];

        // Behavior cycle state & confidence (24h is the primary window)
        [$state, $confidence] = $this->cycleBehaviorState(count($b24h), $range24h, $impulse24h);
        $profile['behavior_cycle_state']      = $state;
        $profile['behavior_cycle_confidence'] = $confidence;

        // 7d behavior context (weak background signal — must not dominate)
        $profile['behavior_context_7d_state'] = $this->cycleContext7dState(count($b7d), $range24h, $vol24h);

        return $profile;
    }

    /**
     * Price corridor range as a percentage of the mid-price.
     * Returns null when fewer than 2 price records are available.
     *
     * @param list<array<string,mixed>> $records
     */
    private function cycleCorridorRange(array $records): ?float
    {
        if (count($records) < 2) {
            return null;
        }
        $prices = [];
        foreach ($records as $r) {
            $p = (float)($r['last_price'] ?? 0);
            if ($p > 0.0) {
                $prices[] = $p;
            }
        }
        if (count($prices) < 2) {
            return null;
        }
        $min = min($prices);
        $max = max($prices);
        if ($min <= 0.0) {
            return null;
        }
        return round(($max - $min) / $min * 100.0, 4);
    }

    /**
     * Impulse strength: net directional move as a fraction of the total price range.
     * 0.0 = pure chop (start ≈ end relative to range), 1.0 = perfectly directional.
     * Returns null when fewer than 2 records.
     *
     * @param list<array<string,mixed>> $records  Sorted ascending by ts_unix
     */
    private function cycleImpulseStrength(array $records): ?float
    {
        if (count($records) < 2) {
            return null;
        }
        $prices = [];
        foreach ($records as $r) {
            $p = (float)($r['last_price'] ?? 0);
            if ($p > 0.0) {
                $prices[] = $p;
            }
        }
        if (count($prices) < 2) {
            return null;
        }
        $first = $prices[0];
        $last  = $prices[count($prices) - 1];
        $min   = min($prices);
        $max   = max($prices);
        $range = $max - $min;
        if ($range <= 0.0) {
            return 0.0;
        }
        return round(abs($last - $first) / $range, 4);
    }

    /**
     * Pullback profile: classify how much the price retraced from its peak within the window.
     * Returns 'insufficient_data' when fewer than 3 records.
     *
     * @param list<array<string,mixed>> $records  Sorted ascending by ts_unix
     * @return string  'insufficient_data'|'none'|'mild'|'moderate'|'severe'
     */
    private function cyclePullbackProfile(array $records): string
    {
        if (count($records) < 3) {
            return 'insufficient_data';
        }
        $prices = [];
        foreach ($records as $r) {
            $p = (float)($r['last_price'] ?? 0);
            if ($p > 0.0) {
                $prices[] = $p;
            }
        }
        if (count($prices) < 3) {
            return 'insufficient_data';
        }
        // Find peak index
        $peakIdx = 0;
        $peakVal = $prices[0];
        foreach ($prices as $i => $p) {
            if ($p > $peakVal) {
                $peakVal = $p;
                $peakIdx = $i;
            }
        }
        // Minimum after the peak (retracement from peak)
        $postPeak = array_slice($prices, $peakIdx);
        if (count($postPeak) < 2) {
            return 'none'; // Peak is at the very end — no retracement measurable
        }
        $minAfterPeak = min($postPeak);
        if ($peakVal <= 0.0) {
            return 'insufficient_data';
        }
        $retrace = ($peakVal - $minAfterPeak) / $peakVal;
        if ($retrace < 0.005) {
            return 'none';
        }
        if ($retrace < 0.02) {
            return 'mild';
        }
        if ($retrace < 0.05) {
            return 'moderate';
        }
        return 'severe';
    }

    /**
     * Continuation profile: how much of the total range was sustained (not retraced) at window end.
     * Returns 'insufficient_data' when fewer than 3 records.
     *
     * @param list<array<string,mixed>> $records  Sorted ascending by ts_unix
     * @return string  'insufficient_data'|'none'|'weak'|'moderate'|'strong'
     */
    private function cycleContinuationProfile(array $records): string
    {
        if (count($records) < 3) {
            return 'insufficient_data';
        }
        $prices = [];
        foreach ($records as $r) {
            $p = (float)($r['last_price'] ?? 0);
            if ($p > 0.0) {
                $prices[] = $p;
            }
        }
        if (count($prices) < 3) {
            return 'insufficient_data';
        }
        $first = $prices[0];
        $last  = $prices[count($prices) - 1];
        $min   = min($prices);
        $max   = max($prices);
        $range = $max - $min;
        if ($range <= 0.0) {
            return 'none';
        }
        $netMove      = abs($last - $first);
        $continuation = $netMove / $range;
        if ($continuation < 0.1) {
            return 'none';
        }
        if ($continuation < 0.35) {
            return 'weak';
        }
        if ($continuation < 0.65) {
            return 'moderate';
        }
        return 'strong';
    }

    /**
     * Volatility profile: coefficient of variation (std deviation / mean) of last_price, as %.
     * Returns null when fewer than 2 records.
     *
     * @param list<array<string,mixed>> $records
     */
    private function cycleVolatilityPct(array $records): ?float
    {
        if (count($records) < 2) {
            return null;
        }
        $prices = [];
        foreach ($records as $r) {
            $p = (float)($r['last_price'] ?? 0);
            if ($p > 0.0) {
                $prices[] = $p;
            }
        }
        $n = count($prices);
        if ($n < 2) {
            return null;
        }
        $mean = array_sum($prices) / $n;
        if ($mean <= 0.0) {
            return null;
        }
        $variance = 0.0;
        foreach ($prices as $p) {
            $variance += ($p - $mean) ** 2;
        }
        $std = sqrt($variance / $n);
        return round($std / $mean * 100.0, 4);
    }

    /**
     * Liquidity profile derived from 24h volume/turnover/spread snapshots.
     *
     * @param list<array<string,mixed>> $records
     * @return array<string,mixed>
     */
    private function cycleLiquidityProfile(array $records): array
    {
        if (empty($records)) {
            return ['state' => 'insufficient_data', 'samples' => 0];
        }
        $spreads   = [];
        $volumes   = [];
        $turnovers = [];
        foreach ($records as $r) {
            $ask  = (float)($r['ask1_price'] ?? 0);
            $bid  = (float)($r['bid1_price'] ?? 0);
            $last = (float)($r['last_price']  ?? 0);
            $vol  = (float)($r['volume24h']   ?? 0);
            $turn = (float)($r['turnover24h'] ?? 0);
            if ($ask > 0.0 && $bid > 0.0 && $last > 0.0) {
                $spreads[] = ($ask - $bid) / $last * 100.0;
            }
            if ($vol  > 0.0) { $volumes[]   = $vol;  }
            if ($turn > 0.0) { $turnovers[] = $turn; }
        }
        $avgSpread   = count($spreads)   > 0 ? round(array_sum($spreads)   / count($spreads),   6) : null;
        $avgVolume   = count($volumes)   > 0 ? round(array_sum($volumes)   / count($volumes),   2) : null;
        $avgTurnover = count($turnovers) > 0 ? round(array_sum($turnovers) / count($turnovers), 2) : null;

        $state = 'unknown';
        if ($avgTurnover !== null) {
            if ($avgTurnover >= 10_000_000) {
                $state = 'high';
            } elseif ($avgTurnover >= 1_000_000) {
                $state = 'medium';
            } elseif ($avgTurnover > 0.0) {
                $state = 'low';
            }
        }
        return [
            'state'           => $state,
            'avg_spread_pct'  => $avgSpread,
            'avg_volume24h'   => $avgVolume,
            'avg_turnover24h' => $avgTurnover,
            'samples'         => count($records),
        ];
    }

    /**
     * Open-interest pressure: detect whether OI is trending up/flat/down over the window.
     * Reads openInterestValue from the raw 'data' field (Bybit linear ticker).
     *
     * @param list<array<string,mixed>> $records  Sorted ascending by ts_unix
     * @return string  'insufficient_data'|'falling'|'flat'|'rising'
     */
    private function cycleOiPressureProfile(array $records): string
    {
        if (count($records) < 3) {
            return 'insufficient_data';
        }
        $oiValues = [];
        foreach ($records as $r) {
            $raw = is_array($r['data'] ?? null) ? $r['data'] : [];
            $oi  = null;
            if (isset($raw['openInterestValue'])) {
                $oi = (float)$raw['openInterestValue'];
            } elseif (isset($raw['openInterest'])) {
                $oi = (float)$raw['openInterest'];
            }
            if ($oi !== null && $oi > 0.0) {
                $oiValues[] = $oi;
            }
        }
        if (count($oiValues) < 3) {
            return 'insufficient_data';
        }
        $first = $oiValues[0];
        $last  = $oiValues[count($oiValues) - 1];
        if ($first <= 0.0) {
            return 'insufficient_data';
        }
        $changePct = ($last - $first) / $first * 100.0;
        if ($changePct > 1.0) {
            return 'rising';
        }
        if ($changePct < -1.0) {
            return 'falling';
        }
        return 'flat';
    }

    /**
     * Behavior cycle state classification driven by the 24h window.
     *
     * States:
     *   active            – substantial range + directional character in 24h
     *   cooling           – some range but losing direction
     *   flat              – very low range (coin barely moving)
     *   insufficient_data – too few samples to determine state
     *
     * @param int        $samples24h  Number of ticker records in 24h window
     * @param float|null $range24h    Price corridor range % (may be null)
     * @param float|null $impulse24h  Impulse strength 0–1 (may be null)
     * @return array{string, string}  [state, confidence]
     */
    private function cycleBehaviorState(int $samples24h, ?float $range24h, ?float $impulse24h): array
    {
        if ($samples24h < 2 || $range24h === null) {
            return ['insufficient_data', 'none'];
        }
        $confidence = 'none';
        if ($samples24h >= 100) {
            $confidence = 'high';
        } elseif ($samples24h >= 20) {
            $confidence = 'medium';
        } elseif ($samples24h >= 2) {
            $confidence = 'low';
        }
        $impulse = $impulse24h ?? 0.0;
        // Active: meaningful range + clear directional character
        if ($range24h >= 1.0 && $impulse >= 0.3) {
            return ['active', $confidence];
        }
        // Flat: very low range regardless of direction
        if ($range24h < 0.3) {
            return ['flat', $confidence];
        }
        // Some range but losing direction or low impulse → cooling
        return ['cooling', $confidence];
    }

    /**
     * 7d behavior context state (weak background signal — must not dominate decisions).
     *
     * @param int        $samples7d  Number of records in 7d window
     * @param float|null $range24h   Price range % from 24h (most recent activity proxy)
     * @param float|null $vol24h     Volatility % from 24h
     * @return string  'insufficient_data'|'active'|'moderate'|'quiet'
     */
    private function cycleContext7dState(int $samples7d, ?float $range24h, ?float $vol24h): string
    {
        if ($samples7d < 3 || $range24h === null) {
            return 'insufficient_data';
        }
        if ($range24h >= 1.5 && $samples7d >= 20) {
            return 'active';
        }
        if ($range24h >= 0.5 || $samples7d >= 10) {
            return 'moderate';
        }
        return 'quiet';
    }

    // =========================================================================
    // Trust state
    // =========================================================================

    private function computeTrustState(
        string $liveEligibility,
        string $dataConfidence,
        float  $noiseScore,
        int    $sampleSizeTotal,
        bool   $insufficientFlag,
        int    $healthyClosedSamples = 0
    ): string {
        // Orphan protection: orphan-only or no healthy closes → insufficient_data
        if ($healthyClosedSamples < self::MIN_HEALTHY_CLOSED_FOR_YELLOW) {
            return 'insufficient_data';
        }

        // Have enough healthy closes to begin promoting.
        // If raw data sufficiency still not met, promote to yellow (not green) as long as data exists.
        if ($insufficientFlag || $dataConfidence === 'none' || $sampleSizeTotal < self::MIN_TOTAL_SAMPLES) {
            return $dataConfidence !== 'none' ? 'yellow' : 'insufficient_data';
        }

        // allow_live + sufficient confidence + low noise → green
        if ($liveEligibility === 'allow_live'
            && in_array($dataConfidence, ['medium', 'high'], true)
            && $noiseScore <= 0.55
            && $healthyClosedSamples >= self::MIN_HEALTHY_CLOSED_FOR_GREEN) {
            return 'green';
        }

        if ($noiseScore > 0.72) {
            // High noise: red regardless of eligibility
            return 'red';
        }

        // allow_live but not fully green (confidence or noise not quite there)
        if ($liveEligibility === 'allow_live') {
            return 'yellow';
        }

        // bootstrap_live: probationary live — treat as yellow (caution, not red)
        if ($liveEligibility === 'bootstrap_live') {
            return 'yellow';
        }

        // sim_only or shadow_only
        if ($dataConfidence === 'low') {
            return 'yellow';
        }

        return 'red';
    }

    // =========================================================================
    // Coin cycle context projection (Step 3 — passive, storage/read-side only)
    // =========================================================================

    /**
     * Project coin_cycle_read_model.json into each passport as a passive
     * namespaced 'coin_cycle_context' block.
     *
     * Reads the already-generated read-model artifact and writes the compact
     * per-symbol state block into every passport file found.  Does NOT affect
     * live-admission, Bot routing, or PM behavior.  Best-effort and non-fatal.
     *
     * Also writes a compact projection summary artifact.
     *
     * @param  string $readModelPath      Absolute path to coin_cycle_read_model.json
     * @param  string $summaryOutputPath  Absolute path to write coin_cycle_projection_summary.json
     * @return array<string,mixed>
     */
    // =========================================================================
    // Step 4 — Passive cycle-derived eligibility hints
    // =========================================================================

    /**
     * Derive compact passive eligibility hints from a symbol's coin_cycle_context block.
     * All hint values use the label set: favorable | cautious | weak | unavailable.
     * This method is pure / side-effect-free.
     *
     * @param  array<string,mixed> $ctx  The passport's coin_cycle_context block
     * @return array<string,mixed>
     */
    private function deriveCycleHints(array $ctx): array
    {
        $ts         = date('c');
        $state      = (string)($ctx['behavior_cycle_state']      ?? 'unavailable');
        $conf       = (string)($ctx['behavior_cycle_confidence'] ?? 'none');
        $entry      = (string)($ctx['cycle_entry_readiness']     ?? '');
        $pm         = (string)($ctx['cycle_pm_readiness']        ?? '');
        $volatility = (string)($ctx['volatility_state']          ?? '');
        $corridor   = (string)($ctx['corridor_state']            ?? '');
        $pullback   = (string)($ctx['pullback_state']            ?? '');
        $continu    = (string)($ctx['continuation_state']        ?? '');
        $bias       = (string)($ctx['cycle_bias_state']          ?? '');
        $lowReason  = ($ctx['low_confidence_reason'] ?? null);

        $unavailable = ($state === 'unavailable' || $conf === 'none');

        // cycle_entry_hint
        if ($unavailable || $entry === '') {
            $entryHint = 'unavailable';
        } elseif ($entry === 'ready') {
            $entryHint = 'favorable';
        } elseif ($entry === 'marginal') {
            $entryHint = 'cautious';
        } else {
            $entryHint = 'weak';
        }

        // cycle_pm_hint
        if ($unavailable || $pm === '') {
            $pmHint = 'unavailable';
        } elseif ($pm === 'ready') {
            $pmHint = 'favorable';
        } elseif ($pm === 'marginal') {
            $pmHint = 'cautious';
        } else {
            $pmHint = 'weak';
        }

        // cycle_live_hint
        if ($unavailable) {
            $liveHint = 'unavailable';
        } elseif ($state === 'active' && $conf === 'high') {
            $liveHint = 'favorable';
        } elseif ($state === 'active') {
            $liveHint = 'cautious';
        } elseif ($state === 'cooling') {
            $liveHint = 'cautious';
        } elseif ($state === 'flat') {
            $liveHint = 'weak';
        } else {
            $liveHint = 'unavailable';
        }

        // cycle_risk_hint (based on volatility + corridor)
        if ($unavailable || $volatility === '' || $volatility === 'insufficient_data') {
            $riskHint = 'unavailable';
        } elseif ($volatility === 'high') {
            $riskHint = 'weak';
        } elseif ($volatility === 'low' && in_array($corridor, ['normal', 'narrow', 'tight'], true)) {
            $riskHint = 'favorable';
        } else {
            $riskHint = 'cautious';
        }

        // cycle_stop_hint (stop-loss placement friendliness, based on pullback severity)
        if ($unavailable || $pullback === '' || $pullback === 'insufficient_data') {
            $stopHint = 'unavailable';
        } elseif ($pullback === 'mild' && $volatility !== 'high') {
            $stopHint = 'favorable';
        } elseif ($pullback === 'severe') {
            $stopHint = 'weak';
        } else {
            $stopHint = 'cautious';
        }

        // cycle_hold_hint (continuation/hold support)
        if ($unavailable || $continu === '' || $continu === 'insufficient_data') {
            $holdHint = 'unavailable';
        } elseif ($continu === 'strong') {
            $holdHint = 'favorable';
        } elseif ($continu === 'moderate') {
            $holdHint = 'cautious';
        } else {
            $holdHint = 'weak';
        }

        // cycle_confidence_hint
        if ($conf === 'high') {
            $confHint = 'favorable';
        } elseif ($conf === 'medium') {
            $confHint = 'cautious';
        } elseif ($conf === 'low') {
            $confHint = 'weak';
        } else {
            $confHint = 'unavailable';
        }

        // cycle_warning_flag + reason
        $warningReasons = [];
        if ($volatility === 'high') {
            $warningReasons[] = 'high_volatility';
        }
        if ($pullback === 'severe') {
            $warningReasons[] = 'severe_pullback';
        }
        if (in_array($bias, ['choppy', 'reverting'], true)) {
            $warningReasons[] = 'unfavorable_bias:' . $bias;
        }
        $warningFlag   = count($warningReasons) > 0;
        $warningReason = $warningFlag ? implode(',', $warningReasons) : null;

        // cycle_low_confidence_flag
        $lowConfFlag   = in_array($conf, ['none', 'low'], true);
        $lowConfReason = $lowConfFlag ? (is_string($lowReason) ? $lowReason : 'low_or_none_confidence') : null;

        return [
            'updated_at'                  => $ts,
            'cycle_entry_hint'            => $entryHint,
            'cycle_pm_hint'               => $pmHint,
            'cycle_live_hint'             => $liveHint,
            'cycle_risk_hint'             => $riskHint,
            'cycle_stop_hint'             => $stopHint,
            'cycle_hold_hint'             => $holdHint,
            'cycle_confidence_hint'       => $confHint,
            'cycle_warning_flag'          => $warningFlag,
            'cycle_warning_reason'        => $warningReason,
            'cycle_low_confidence_flag'   => $lowConfFlag,
            'cycle_low_confidence_reason' => $lowConfReason,
            'source_cycle_state'          => $state,
            'source_cycle_confidence'     => $conf,
            'source_profile_updated_at'   => $ctx['source_profile_updated_at'] ?? null,
        ];
    }

    /**
     * Project derived cycle eligibility hints into all passport files.
     * Reads coin_cycle_context from each passport, derives coin_cycle_hints, saves back.
     * Writes a compact summary to $summaryOutputPath.
     * Storage/read-side only — does NOT affect Bot, PM, or live admission.
     *
     * @param  string $summaryOutputPath  Absolute path to write the hints summary JSON
     * @return array<string,mixed>
     */
    public function projectCycleHintsToPassports(string $summaryOutputPath): array
    {
        $ts             = date('c');
        $symbolsTotal   = 0;
        $hintsTotal     = 0;
        $favorableTotal = 0;
        $cautiousTotal  = 0;
        $weakTotal      = 0;
        $unavailTotal   = 0;
        $lowConfTotal   = 0;
        $errorTotal     = 0;

        $passportFiles = glob($this->passportsDir . '/*.json') ?: [];
        $symbolsTotal  = count($passportFiles);

        foreach ($passportFiles as $file) {
            try {
                $passport = $this->readJson($file);
                if (!is_array($passport)) {
                    $errorTotal++;
                    continue;
                }

                $ctx = $passport['coin_cycle_context'] ?? null;

                if (!is_array($ctx)) {
                    // No cycle context available — write minimal unavailable block
                    $passport['coin_cycle_hints'] = [
                        'updated_at'                  => $ts,
                        'cycle_entry_hint'            => 'unavailable',
                        'cycle_pm_hint'               => 'unavailable',
                        'cycle_live_hint'             => 'unavailable',
                        'cycle_risk_hint'             => 'unavailable',
                        'cycle_stop_hint'             => 'unavailable',
                        'cycle_hold_hint'             => 'unavailable',
                        'cycle_confidence_hint'       => 'unavailable',
                        'cycle_warning_flag'          => false,
                        'cycle_warning_reason'        => null,
                        'cycle_low_confidence_flag'   => true,
                        'cycle_low_confidence_reason' => 'no_cycle_context',
                        'source_cycle_state'          => 'unavailable',
                        'source_cycle_confidence'     => 'none',
                        'source_profile_updated_at'   => null,
                    ];
                    $unavailTotal++;
                    $lowConfTotal++;
                } else {
                    $hints = $this->deriveCycleHints($ctx);
                    $passport['coin_cycle_hints'] = $hints;
                    $hintsTotal++;

                    // Tally by entry hint as representative
                    $entryHint = $hints['cycle_entry_hint'];
                    if ($entryHint === 'favorable')    { $favorableTotal++; }
                    elseif ($entryHint === 'cautious') { $cautiousTotal++; }
                    elseif ($entryHint === 'weak')     { $weakTotal++; }
                    else                               { $unavailTotal++; }

                    if ((bool)($hints['cycle_low_confidence_flag'] ?? false)) {
                        $lowConfTotal++;
                    }
                }

                // Atomic write
                $tmp  = $file . '.chtmp.' . getmypid();
                $json = json_encode($passport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
                    if (!@rename($tmp, $file)) {
                        @unlink($tmp);
                        $errorTotal++;
                    }
                } else {
                    @unlink($tmp);
                    $errorTotal++;
                }
            } catch (\Throwable $ex) {
                $errorTotal++;
            }
        }

        $summary = [
            'updated_at'          => $ts,
            'source'              => 'coin_cycle_context',
            'symbols_total'       => $symbolsTotal,
            'hints_written_total' => $hintsTotal,
            'favorable_total'     => $favorableTotal,
            'cautious_total'      => $cautiousTotal,
            'weak_total'          => $weakTotal,
            'unavailable_total'   => $unavailTotal,
            'low_confidence_total' => $lowConfTotal,
            'error_total'         => $errorTotal,
        ];

        $summaryDir = dirname($summaryOutputPath);
        if (!is_dir($summaryDir)) {
            @mkdir($summaryDir, 0755, true);
        }
        @file_put_contents(
            $summaryOutputPath,
            json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return $summary;
    }

    // =========================================================================
    // Step 5 — Passive passport-side cycle decision summary block
    // =========================================================================

    /**
     * Derive a compact cycle decision summary from the passport's existing
     * coin_cycle_context and coin_cycle_hints blocks.
     * All values use the label set: favorable | cautious | weak | unavailable |
     * stable | unstable | actionable | non_actionable.
     * Pure / side-effect-free.
     *
     * @param  array<string,mixed> $ctx    The passport's coin_cycle_context block
     * @param  array<string,mixed> $hints  The passport's coin_cycle_hints block
     * @return array<string,mixed>
     */
    private function deriveCycleSummary(array $ctx, array $hints): array
    {
        $ts = date('c');

        // Pull from context
        $state     = (string)($ctx['behavior_cycle_state']      ?? 'unavailable');
        $conf      = (string)($ctx['behavior_cycle_confidence'] ?? 'none');
        $stability = (string)($ctx['cycle_stability_state']     ?? '');
        $bias      = (string)($ctx['cycle_bias_state']          ?? '');
        $ctxAt     = $ctx['updated_at'] ?? null;

        // Pull from hints
        $entryHint     = (string)($hints['cycle_entry_hint']          ?? 'unavailable');
        $pmHint        = (string)($hints['cycle_pm_hint']             ?? 'unavailable');
        $liveHint      = (string)($hints['cycle_live_hint']           ?? 'unavailable');
        $riskHint      = (string)($hints['cycle_risk_hint']           ?? 'unavailable');
        $stopHint      = (string)($hints['cycle_stop_hint']           ?? 'unavailable');
        $holdHint      = (string)($hints['cycle_hold_hint']           ?? 'unavailable');
        $warnFlag      = (bool)($hints['cycle_warning_flag']          ?? false);
        $warnReason    = $hints['cycle_warning_reason']               ?? null;
        $lowConfFlag   = (bool)($hints['cycle_low_confidence_flag']   ?? false);
        $lowConfReason = $hints['cycle_low_confidence_reason']        ?? null;
        $hintsAt       = $hints['updated_at']                         ?? null;

        $unavailable = ($state === 'unavailable' || $conf === 'none');

        // cycle_summary_state
        if ($unavailable) {
            $summaryState = 'unavailable';
        } elseif ($state === 'active') {
            $summaryState = ($conf === 'high') ? 'favorable' : 'cautious';
        } elseif ($state === 'cooling') {
            $summaryState = 'cautious';
        } elseif ($state === 'flat') {
            $summaryState = 'weak';
        } else {
            $summaryState = 'unavailable';
        }

        // cycle_summary_confidence
        if ($conf === 'high') {
            $summaryConf = 'favorable';
        } elseif ($conf === 'medium') {
            $summaryConf = 'cautious';
        } elseif ($conf === 'low') {
            $summaryConf = 'weak';
        } else {
            $summaryConf = 'unavailable';
        }

        // cycle_entry_summary: combines entry + pm hint
        $entryPmVals = [$entryHint, $pmHint];
        if (in_array('unavailable', $entryPmVals, true)) {
            $entrySummary = 'unavailable';
        } elseif (in_array('weak', $entryPmVals, true)) {
            $entrySummary = 'weak';
        } elseif (in_array('cautious', $entryPmVals, true)) {
            $entrySummary = 'cautious';
        } else {
            $entrySummary = 'favorable';
        }

        // cycle_readiness_state: aggregate entry + pm + live
        $readinessVals    = [$entryHint, $pmHint, $liveHint];
        $readinessNonAvail = array_filter($readinessVals, static fn(string $v): bool => $v !== 'unavailable');
        if (empty($readinessNonAvail)) {
            $readinessState = 'unavailable';
        } elseif (!in_array('weak', $readinessNonAvail, true) && !in_array('cautious', $readinessNonAvail, true)) {
            $readinessState = 'favorable';
        } elseif (in_array('weak', $readinessNonAvail, true)) {
            $readinessState = 'weak';
        } else {
            $readinessState = 'cautious';
        }

        // cycle_stability_summary
        if ($unavailable || $stability === '') {
            $stabilitySummary = 'unavailable';
        } elseif ($stability === 'stable') {
            $stabilitySummary = 'stable';
        } elseif ($stability === 'unstable') {
            $stabilitySummary = 'unstable';
        } else {
            $stabilitySummary = 'unavailable';
        }

        // cycle_bias_summary
        if ($unavailable || $bias === '') {
            $biasSummary = 'unavailable';
        } elseif ($bias === 'trending') {
            $biasSummary = 'favorable';
        } elseif ($bias === 'neutral') {
            $biasSummary = 'cautious';
        } elseif (in_array($bias, ['choppy', 'reverting'], true)) {
            $biasSummary = 'weak';
        } else {
            $biasSummary = 'cautious';
        }

        // cycle_actionability_summary
        $entryOk = in_array($entryHint, ['favorable', 'cautious'], true);
        $liveOk  = in_array($liveHint, ['favorable', 'cautious'], true);
        if (!$unavailable && $entryOk && $liveOk && !$lowConfFlag) {
            $actionability = 'actionable';
        } elseif (!$unavailable && ($entryOk || $liveOk) && !$warnFlag) {
            $actionability = 'actionable';
        } else {
            $actionability = 'non_actionable';
        }

        return [
            'updated_at'                  => $ts,
            'cycle_summary_state'         => $summaryState,
            'cycle_summary_confidence'    => $summaryConf,
            'cycle_entry_summary'         => $entrySummary,
            'cycle_pm_summary'            => $pmHint,
            'cycle_live_summary'          => $liveHint,
            'cycle_risk_summary'          => $riskHint,
            'cycle_stop_summary'          => $stopHint,
            'cycle_hold_summary'          => $holdHint,
            'cycle_warning_flag'          => $warnFlag,
            'cycle_warning_reason'        => $warnReason,
            'cycle_low_confidence_flag'   => $lowConfFlag,
            'cycle_low_confidence_reason' => $lowConfReason,
            'cycle_readiness_state'       => $readinessState,
            'cycle_stability_summary'     => $stabilitySummary,
            'cycle_bias_summary'          => $biasSummary,
            'cycle_actionability_summary' => $actionability,
            'source_context_updated_at'   => $ctxAt,
            'source_hints_updated_at'     => $hintsAt,
        ];
    }

    /**
     * Project passive cycle decision summaries into all passport files.
     * Reads coin_cycle_context + coin_cycle_hints from each passport, derives
     * coin_cycle_summary, saves back.  Writes a compact summary artifact.
     * Storage/read-side only — does NOT affect Bot, PM, or live admission.
     *
     * @param  string $summaryOutputPath  Absolute path to write coin_cycle_summary_projection.json
     * @return array<string,mixed>
     */
    public function projectCycleSummaryToPassports(string $summaryOutputPath): array
    {
        $ts                 = date('c');
        $symbolsTotal       = 0;
        $writtenTotal       = 0;
        $favorableTotal     = 0;
        $cautiousTotal      = 0;
        $weakTotal          = 0;
        $unavailTotal       = 0;
        $actionableTotal    = 0;
        $nonActionableTotal = 0;
        $lowConfTotal       = 0;
        $errorTotal         = 0;

        $passportFiles = glob($this->passportsDir . '/*.json') ?: [];
        $symbolsTotal  = count($passportFiles);

        foreach ($passportFiles as $file) {
            try {
                $passport = $this->readJson($file);
                if (!is_array($passport)) {
                    $errorTotal++;
                    continue;
                }

                $ctx   = $passport['coin_cycle_context'] ?? null;
                $hints = $passport['coin_cycle_hints']   ?? null;

                if (!is_array($ctx) || !is_array($hints)) {
                    // Source blocks absent — write compact unavailable summary
                    $passport['coin_cycle_summary'] = [
                        'updated_at'                  => $ts,
                        'cycle_summary_state'         => 'unavailable',
                        'cycle_summary_confidence'    => 'unavailable',
                        'cycle_entry_summary'         => 'unavailable',
                        'cycle_pm_summary'            => 'unavailable',
                        'cycle_live_summary'          => 'unavailable',
                        'cycle_risk_summary'          => 'unavailable',
                        'cycle_stop_summary'          => 'unavailable',
                        'cycle_hold_summary'          => 'unavailable',
                        'cycle_warning_flag'          => false,
                        'cycle_warning_reason'        => null,
                        'cycle_low_confidence_flag'   => true,
                        'cycle_low_confidence_reason' => 'no_context_or_hints',
                        'cycle_readiness_state'       => 'unavailable',
                        'cycle_stability_summary'     => 'unavailable',
                        'cycle_bias_summary'          => 'unavailable',
                        'cycle_actionability_summary' => 'non_actionable',
                        'source_context_updated_at'   => null,
                        'source_hints_updated_at'     => null,
                    ];
                    $unavailTotal++;
                    $lowConfTotal++;
                    $nonActionableTotal++;
                } else {
                    $cycleSummary = $this->deriveCycleSummary($ctx, $hints);
                    $passport['coin_cycle_summary'] = $cycleSummary;
                    $writtenTotal++;

                    $summaryState = $cycleSummary['cycle_summary_state'];
                    if ($summaryState === 'favorable')    { $favorableTotal++; }
                    elseif ($summaryState === 'cautious') { $cautiousTotal++; }
                    elseif ($summaryState === 'weak')     { $weakTotal++; }
                    else                                  { $unavailTotal++; }

                    if ($cycleSummary['cycle_actionability_summary'] === 'actionable') {
                        $actionableTotal++;
                    } else {
                        $nonActionableTotal++;
                    }
                    if ((bool)($cycleSummary['cycle_low_confidence_flag'] ?? false)) {
                        $lowConfTotal++;
                    }
                }

                // Atomic write
                $tmp  = $file . '.cstmp.' . getmypid();
                $json = json_encode($passport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
                    if (!@rename($tmp, $file)) {
                        @unlink($tmp);
                        $errorTotal++;
                    }
                } else {
                    @unlink($tmp);
                    $errorTotal++;
                }
            } catch (\Throwable $ex) {
                $errorTotal++;
            }
        }

        $projectionSummary = [
            'updated_at'              => $ts,
            'source'                  => 'passport coin_cycle_context + coin_cycle_hints',
            'symbols_total'           => $symbolsTotal,
            'summaries_written_total' => $writtenTotal,
            'favorable_total'         => $favorableTotal,
            'cautious_total'          => $cautiousTotal,
            'weak_total'              => $weakTotal,
            'unavailable_total'       => $unavailTotal,
            'actionable_total'        => $actionableTotal,
            'non_actionable_total'    => $nonActionableTotal,
            'low_confidence_total'    => $lowConfTotal,
            'error_total'             => $errorTotal,
        ];

        $summaryDir = dirname($summaryOutputPath);
        if (!is_dir($summaryDir)) {
            @mkdir($summaryDir, 0755, true);
        }
        @file_put_contents(
            $summaryOutputPath,
            json_encode($projectionSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return $projectionSummary;
    }

    // =========================================================================
    // Coin Core Step 6 — passive cycle routing profile
    // =========================================================================

    /**
     * Derive a compact passive routing profile from context + hints + summary.
     * All fields are compact labels only.  Does NOT drive any live decisions.
     *
     * @param  array<string,mixed> $ctx
     * @param  array<string,mixed> $hints
     * @param  array<string,mixed> $summary
     * @return array<string,mixed>
     */
    private function deriveRoutingProfile(array $ctx, array $hints, array $summary): array
    {
        $ts = date('c');

        // Inputs from context
        $state        = (string)($ctx['behavior_cycle_state']      ?? 'unavailable');
        $conf         = (string)($ctx['behavior_cycle_confidence'] ?? 'none');
        $ctxAt        = $ctx['updated_at'] ?? null;

        // Inputs from hints
        $liveHint     = (string)($hints['cycle_live_hint']           ?? 'unavailable');
        $riskHint     = (string)($hints['cycle_risk_hint']           ?? 'unavailable');
        $stopHint     = (string)($hints['cycle_stop_hint']           ?? 'unavailable');
        $holdHint     = (string)($hints['cycle_hold_hint']           ?? 'unavailable');
        $warnFlag     = (bool)($hints['cycle_warning_flag']          ?? false);
        $warnReason   = $hints['cycle_warning_reason']               ?? null;
        $lowConfFlag  = (bool)($hints['cycle_low_confidence_flag']   ?? false);
        $lowConfReason = $hints['cycle_low_confidence_reason']       ?? null;
        $hintsAt      = $hints['updated_at']                         ?? null;

        // Inputs from summary
        $summaryState  = (string)($summary['cycle_summary_state']         ?? 'unavailable');
        $summaryConf   = (string)($summary['cycle_summary_confidence']    ?? 'unavailable');
        $actionability = (string)($summary['cycle_actionability_summary'] ?? 'non_actionable');
        $summaryAt     = $summary['updated_at']                           ?? null;

        $unavailable = ($state === 'unavailable' || $conf === 'none' || $summaryState === 'unavailable');

        // routing_profile_state — overall profile label
        if ($unavailable) {
            $profileState = 'unavailable';
        } elseif ($summaryState === 'favorable' && $actionability === 'actionable') {
            $profileState = 'favorable';
        } elseif ($summaryState === 'cautious') {
            $profileState = 'cautious';
        } elseif ($summaryState === 'weak') {
            $profileState = 'weak';
        } else {
            $profileState = 'unavailable';
        }

        // routing_profile_confidence
        if ($conf === 'high' && !$lowConfFlag) {
            $profileConf = 'favorable';
        } elseif ($conf === 'medium') {
            $profileConf = 'cautious';
        } elseif ($conf === 'low' || $lowConfFlag) {
            $profileConf = 'weak';
        } else {
            $profileConf = 'unavailable';
        }

        // routing_live_profile — can this symbol go live?
        if ($unavailable || $warnFlag || $lowConfFlag) {
            $liveProfile = 'unavailable';
        } elseif ($liveHint === 'favorable' && $summaryState === 'favorable') {
            $liveProfile = 'live_ready';
        } elseif (in_array($liveHint, ['favorable', 'cautious'], true) && $summaryState !== 'weak') {
            $liveProfile = 'live_ready';
        } else {
            $liveProfile = 'demo_only';
        }

        // routing_demo_profile
        if ($unavailable) {
            $demoProfile = 'unavailable';
        } elseif (in_array($liveProfile, ['live_ready', 'demo_only'], true)) {
            $demoProfile = 'favorable';
        } else {
            $demoProfile = 'cautious';
        }

        // routing_shadow_profile
        if ($unavailable) {
            $shadowProfile = 'shadow_only';
        } elseif ($summaryState === 'weak') {
            $shadowProfile = 'shadow_only';
        } else {
            $shadowProfile = 'favorable';
        }

        // routing_skip_profile — should this symbol be skipped entirely?
        if ($unavailable || ($summaryState === 'weak' && $warnFlag)) {
            $skipProfile = 'skip';
        } else {
            $skipProfile = 'non_skip';
        }

        // routing_risk_profile
        if ($unavailable) {
            $riskProfile = 'unavailable';
        } elseif ($riskHint === 'low_risk' || $riskHint === 'favorable') {
            $riskProfile = 'low_risk';
        } elseif ($riskHint === 'medium_risk' || $riskHint === 'cautious') {
            $riskProfile = 'medium_risk';
        } elseif ($riskHint === 'high_risk' || $riskHint === 'weak') {
            $riskProfile = 'high_risk';
        } else {
            $riskProfile = 'medium_risk';
        }

        // routing_hold_profile
        if ($unavailable) {
            $holdProfile = 'unavailable';
        } elseif ($holdHint === 'favorable') {
            $holdProfile = 'favorable';
        } elseif ($holdHint === 'cautious') {
            $holdProfile = 'cautious';
        } elseif ($holdHint === 'weak') {
            $holdProfile = 'weak';
        } else {
            $holdProfile = 'cautious';
        }

        // routing_actionability_profile
        if (!$unavailable && $actionability === 'actionable' && !$warnFlag && $liveProfile !== 'unavailable') {
            $actionabilityProfile = 'actionable';
        } else {
            $actionabilityProfile = 'non_actionable';
        }

        // routing_preferred_mode_hint (compact label for future routing use)
        if ($unavailable) {
            $preferredMode = 'shadow_only';
        } elseif ($liveProfile === 'live_ready') {
            $preferredMode = 'live';
        } elseif ($demoProfile === 'favorable') {
            $preferredMode = 'demo';
        } else {
            $preferredMode = 'shadow_only';
        }

        // routing_preferred_risk_hint
        if ($unavailable) {
            $preferredRisk = 'unavailable';
        } elseif ($riskProfile === 'low_risk') {
            $preferredRisk = 'low_risk';
        } elseif ($riskProfile === 'medium_risk') {
            $preferredRisk = 'medium_risk';
        } else {
            $preferredRisk = 'high_risk';
        }

        // routing_preferred_hold_hint
        $preferredHold = $unavailable ? 'unavailable' : $holdProfile;

        // routing_preferred_stop_hint
        if ($unavailable) {
            $preferredStop = 'unavailable';
        } elseif ($stopHint === 'favorable') {
            $preferredStop = 'tight';
        } elseif ($stopHint === 'cautious') {
            $preferredStop = 'normal';
        } elseif ($stopHint === 'weak') {
            $preferredStop = 'wide';
        } else {
            $preferredStop = 'normal';
        }

        return [
            'updated_at'                    => $ts,
            'routing_profile_state'         => $profileState,
            'routing_profile_confidence'    => $profileConf,
            'routing_live_profile'          => $liveProfile,
            'routing_demo_profile'          => $demoProfile,
            'routing_shadow_profile'        => $shadowProfile,
            'routing_skip_profile'          => $skipProfile,
            'routing_risk_profile'          => $riskProfile,
            'routing_hold_profile'          => $holdProfile,
            'routing_actionability_profile' => $actionabilityProfile,
            'routing_warning_flag'          => $warnFlag,
            'routing_warning_reason'        => $warnReason,
            'routing_low_confidence_flag'   => $lowConfFlag,
            'routing_low_confidence_reason' => $lowConfReason,
            'routing_preferred_mode_hint'   => $preferredMode,
            'routing_preferred_risk_hint'   => $preferredRisk,
            'routing_preferred_hold_hint'   => $preferredHold,
            'routing_preferred_stop_hint'   => $preferredStop,
            'source_summary_updated_at'     => $summaryAt,
            'source_hints_updated_at'       => $hintsAt,
            'source_context_updated_at'     => $ctxAt,
        ];
    }

    /**
     * Project passive cycle routing profiles into all passport files.
     * Reads coin_cycle_context + coin_cycle_hints + coin_cycle_summary from each
     * passport, derives coin_cycle_routing_profile, saves back.
     * Writes a compact projection artifact.
     * Storage/read-side only — does NOT affect Bot, PM, or live admission.
     *
     * @param  string $outputPath  Absolute path to write coin_cycle_routing_profile_projection.json
     * @return array<string,mixed>
     */
    public function projectCycleRoutingProfileToPassports(string $outputPath): array
    {
        $ts               = date('c');
        $symbolsTotal     = 0;
        $writtenTotal     = 0;
        $liveReadyTotal   = 0;
        $demoOnlyTotal    = 0;
        $shadowOnlyTotal  = 0;
        $skipTotal        = 0;
        $lowConfTotal     = 0;
        $errorTotal       = 0;

        $passportFiles = glob($this->passportsDir . '/*.json') ?: [];
        $symbolsTotal  = count($passportFiles);

        foreach ($passportFiles as $file) {
            try {
                $passport = $this->readJson($file);
                if (!is_array($passport)) {
                    $errorTotal++;
                    continue;
                }

                $ctx     = $passport['coin_cycle_context']  ?? null;
                $hints   = $passport['coin_cycle_hints']    ?? null;
                $summary = $passport['coin_cycle_summary']  ?? null;

                if (!is_array($ctx) || !is_array($hints) || !is_array($summary)) {
                    // Source blocks absent — write compact unavailable routing profile
                    $passport['coin_cycle_routing_profile'] = [
                        'updated_at'                    => $ts,
                        'routing_profile_state'         => 'unavailable',
                        'routing_profile_confidence'    => 'unavailable',
                        'routing_live_profile'          => 'unavailable',
                        'routing_demo_profile'          => 'unavailable',
                        'routing_shadow_profile'        => 'shadow_only',
                        'routing_skip_profile'          => 'skip',
                        'routing_risk_profile'          => 'unavailable',
                        'routing_hold_profile'          => 'unavailable',
                        'routing_actionability_profile' => 'non_actionable',
                        'routing_warning_flag'          => false,
                        'routing_warning_reason'        => null,
                        'routing_low_confidence_flag'   => true,
                        'routing_low_confidence_reason' => 'no_context_hints_or_summary',
                        'routing_preferred_mode_hint'   => 'shadow_only',
                        'routing_preferred_risk_hint'   => 'unavailable',
                        'routing_preferred_hold_hint'   => 'unavailable',
                        'routing_preferred_stop_hint'   => 'unavailable',
                        'source_summary_updated_at'     => null,
                        'source_hints_updated_at'       => null,
                        'source_context_updated_at'     => null,
                    ];
                    $shadowOnlyTotal++;
                    $skipTotal++;
                    $lowConfTotal++;
                } else {
                    $profile = $this->deriveRoutingProfile($ctx, $hints, $summary);
                    $passport['coin_cycle_routing_profile'] = $profile;
                    $writtenTotal++;

                    $liveP = $profile['routing_live_profile'];
                    $skipP = $profile['routing_skip_profile'];

                    if ($liveP === 'live_ready')   { $liveReadyTotal++; }
                    elseif ($liveP === 'demo_only') { $demoOnlyTotal++; }
                    else                            { $shadowOnlyTotal++; }

                    if ($skipP === 'skip') { $skipTotal++; }

                    if ((bool)($profile['routing_low_confidence_flag'] ?? false)) {
                        $lowConfTotal++;
                    }
                }

                // Atomic write
                $tmp  = $file . '.crptmp.' . getmypid();
                $json = json_encode($passport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
                    if (!@rename($tmp, $file)) {
                        @unlink($tmp);
                        $errorTotal++;
                    }
                } else {
                    @unlink($tmp);
                    $errorTotal++;
                }
            } catch (\Throwable $ex) {
                $errorTotal++;
            }
        }

        $projection = [
            'updated_at'          => $ts,
            'source'              => 'passport cycle layers',
            'symbols_total'       => $symbolsTotal,
            'profiles_written_total' => $writtenTotal,
            'live_ready_total'    => $liveReadyTotal,
            'demo_only_total'     => $demoOnlyTotal,
            'shadow_only_total'   => $shadowOnlyTotal,
            'skip_total'          => $skipTotal,
            'low_confidence_total' => $lowConfTotal,
            'error_total'         => $errorTotal,
        ];

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(
            $outputPath,
            json_encode($projection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return $projection;
    }

    public function projectCycleContextToPassports(string $readModelPath, string $summaryOutputPath): array
    {
        $ts             = date('c');
        $symbolsTotal   = 0;
        $projectedTotal = 0;
        $skippedTotal   = 0;
        $lowConfTotal   = 0;
        $errorTotal     = 0;

        // Load read model entries indexed by symbol
        $readModelEntries = [];
        $sourceReadModelAt = null;
        if (is_file($readModelPath)) {
            $raw = @file_get_contents($readModelPath);
            $rm  = ($raw !== false) ? json_decode($raw, true) : null;
            if (is_array($rm)) {
                $sourceReadModelAt = $rm['generated_at'] ?? null;
                $symbols = $rm['symbols'] ?? [];
                if (is_array($symbols)) {
                    foreach ($symbols as $sym => $entry) {
                        if (is_array($entry)) {
                            $readModelEntries[strtoupper((string)$sym)] = $entry;
                        }
                    }
                }
            }
        }

        // Collect all passport files to update
        $passportFiles = glob($this->passportsDir . '/*.json') ?: [];
        $symbolsTotal  = count($passportFiles);

        foreach ($passportFiles as $file) {
            $symbol = basename($file, '.json');
            try {
                $passport = $this->readJson($file);
                if (!is_array($passport)) {
                    $skippedTotal++;
                    continue;
                }

                $upper = strtoupper($symbol);
                if (!isset($readModelEntries[$upper])) {
                    // No read-model entry for this symbol — write explicit unavailable block
                    $passport['coin_cycle_context'] = [
                        'updated_at'                => $ts,
                        'behavior_cycle_state'      => 'unavailable',
                        'behavior_cycle_confidence' => 'none',
                        'low_confidence_reason'     => 'no_read_model_entry',
                        'source_profile_updated_at' => null,
                    ];
                    $skippedTotal++;
                } else {
                    $entry = $readModelEntries[$upper];
                    $conf  = (string)($entry['behavior_cycle_confidence'] ?? 'none');

                    // Project compact namespaced block — only the defined cycle-context fields
                    $passport['coin_cycle_context'] = [
                        'updated_at'                => $ts,
                        'behavior_cycle_state'      => $entry['behavior_cycle_state']      ?? 'unavailable',
                        'behavior_cycle_confidence' => $conf,
                        'hot_state_1h'              => $entry['hot_state_1h']              ?? null,
                        'hot_state_2h'              => $entry['hot_state_2h']              ?? null,
                        'short_state_3h'            => $entry['short_state_3h']            ?? null,
                        'short_state_6h'            => $entry['short_state_6h']            ?? null,
                        'intraday_state_12h'        => $entry['intraday_state_12h']        ?? null,
                        'daily_state_24h'           => $entry['daily_state_24h']           ?? null,
                        'behavior_context_7d_state' => $entry['behavior_context_7d_state'] ?? null,
                        'corridor_state'            => $entry['corridor_state']            ?? null,
                        'impulse_state'             => $entry['impulse_state']             ?? null,
                        'pullback_state'            => $entry['pullback_state']            ?? null,
                        'continuation_state'        => $entry['continuation_state']        ?? null,
                        'volatility_state'          => $entry['volatility_state']          ?? null,
                        'liquidity_state'           => $entry['liquidity_state']           ?? null,
                        'oi_pressure_state'         => $entry['oi_pressure_state']         ?? null,
                        'cycle_bias_state'          => $entry['cycle_bias_state']          ?? null,
                        'cycle_quality_state'       => $entry['cycle_quality_state']       ?? null,
                        'cycle_stability_state'     => $entry['cycle_stability_state']     ?? null,
                        'cycle_entry_readiness'     => $entry['cycle_entry_readiness']     ?? null,
                        'cycle_pm_readiness'        => $entry['cycle_pm_readiness']        ?? null,
                        'low_confidence_reason'     => $entry['low_confidence_reason']     ?? null,
                        'source_profile_updated_at' => $entry['source_profile_updated_at'] ?? null,
                    ];

                    $projectedTotal++;
                    if (in_array($conf, ['none', 'low'], true)) {
                        $lowConfTotal++;
                    }
                }

                // Atomic write: tmp → rename
                $tmp  = $file . '.cctmp.' . getmypid();
                $json = json_encode($passport, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
                    if (!@rename($tmp, $file)) {
                        @unlink($tmp);
                        $errorTotal++;
                    }
                } else {
                    @unlink($tmp);
                    $errorTotal++;
                }
            } catch (\Throwable $ex) {
                $errorTotal++;
            }
        }

        // Persist the projection summary
        $summary = [
            'updated_at'          => $ts,
            'source'              => 'coin_cycle_read_model',
            'source_read_model_at' => $sourceReadModelAt,
            'symbols_total'       => $symbolsTotal,
            'projected_total'     => $projectedTotal,
            'skipped_total'       => $skippedTotal,
            'low_confidence_total' => $lowConfTotal,
            'error_total'         => $errorTotal,
        ];

        $summaryDir = dirname($summaryOutputPath);
        if (!is_dir($summaryDir)) {
            @mkdir($summaryDir, 0755, true);
        }
        @file_put_contents(
            $summaryOutputPath,
            json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return $summary;
    }
}
