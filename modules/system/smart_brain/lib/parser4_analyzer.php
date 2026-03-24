<?php
declare(strict_types=1);

require_once __DIR__ . '/patterns/pattern_detector_interface.php';
require_once __DIR__ . '/patterns/double_bottom_detector.php';
require_once __DIR__ . '/patterns/double_top_detector.php';
require_once __DIR__ . '/patterns/pullback_trend_continue_detector.php';
require_once __DIR__ . '/patterns/double_bottom_confirm_v2_detector.php';
require_once __DIR__ . '/patterns/double_top_confirm_v2_detector.php';
require_once __DIR__ . '/patterns/double_bottom_contextual_v2_detector.php';
require_once __DIR__ . '/patterns/double_bottom_contextual_v3_detector.php';
require_once __DIR__ . '/context/context_adapter.php';

/**
 * Parser4 Analyzer — Smart Brain Market Structure Analyzer
 *
 * Pattern-First Decision Flow.
 *
 * Reads Parser3 symbols + Parser2 price history.
 * Runs enabled pattern detectors (double_bottom, double_top, pullback_trend_continue,
 *   double_bottom_confirm_v2, double_top_confirm_v2, double_bottom_contextual_v2,
 *   double_bottom_contextual_v3).
 * Computes trend_match_score, corridor_fit_score, entry_quality_score.
 * Calculates weighted analyzer_score; applies analyzer_pass threshold.
 * Outputs corridor/volatility/strength/trend candidates with full decision fields.
 *
 * Does NOT generate trading signals.
 */
final class Parser4Analyzer
{
    /** @var array<string,mixed> */
    private array $cfg;
    private StateManager $state;

    /** @var PatternDetectorInterface[] */
    private array $detectors = [];

    /** @var string Pattern mode: 'one', 'any', 'all' */
    private string $patternMode;

    /** @var array<string,mixed> Analyzer decision config */
    private array $decisionCfg;

    /** @var array<int,string> Debug rejection lines from last run */
    private array $analyzerDebugLines = [];

    /** @var ContextAdapter Canonical context adapter for contextual patterns */
    private ContextAdapter $contextAdapter;

    /**
     * @param array<string,mixed> $cfg  Full parser4 config (settings + pattern_algorithms + analyzer_decision)
     * @param StateManager $state
     */
    public function __construct(array $cfg, StateManager $state)
    {
        // Support full parser4 config: extract settings sub-key for setting-level reads
        if (isset($cfg['settings']) && is_array($cfg['settings'])) {
            $this->cfg = $cfg['settings'];
        } else {
            $this->cfg = $cfg;
        }
        $this->state = $state;

        $patternCfg = (array)($cfg['pattern_algorithms'] ?? []);
        $enabledAlgorithms = (array)($patternCfg['enabled'] ?? []);
        $this->patternMode = (string)($patternCfg['mode'] ?? 'one');

        $this->decisionCfg = (array)($cfg['analyzer_decision'] ?? []);

        $this->detectors = $this->buildDetectors($enabledAlgorithms);
        $this->contextAdapter = new ContextAdapter();
    }

    /**
     * Build detector instances for enabled algorithms.
     *
     * @param array<int,string> $enabled
     * @return PatternDetectorInterface[]
     */
    private function buildDetectors(array $enabled): array
    {
        $available = [
            'double_bottom' => static fn() => new DoubleBottomDetector(),
            'double_top' => static fn() => new DoubleTopDetector(),
            'pullback_trend_continue' => static fn() => new PullbackTrendContinueDetector(),
            'double_bottom_confirm_v2' => static fn() => new DoubleBottomConfirmV2Detector(),
            'double_top_confirm_v2' => static fn() => new DoubleTopConfirmV2Detector(),
            'double_bottom_contextual_v2' => static fn() => new DoubleBottomContextualV2Detector(),
            'double_bottom_contextual_v3' => static fn() => new DoubleBottomContextualV3Detector(),
        ];

        $detectors = [];
        foreach ($enabled as $name) {
            $name = (string)$name;
            if (isset($available[$name])) {
                $detectors[] = $available[$name]();
            }
        }

        return $detectors;
    }

    /**
     * Run the analyzer pipeline — Pattern-First Decision Flow.
     *
     * 1. Load symbols from Parser3 profiles directory
     * 2. For each symbol load latest Parser2 NDJSON history
     * 3. Calculate market structure (corridor, volatility, strength, trend)
     * 4. STEP 1: Run enabled pattern detectors (primary gate)
     * 5. STEP 2: Compute trend_match_score
     * 6. STEP 3: Compute corridor_fit_score
     * 7. STEP 4: Compute entry_quality_score
     * 8. STEP 5: Compute weighted analyzer_score
     * 9. STEP 6: Apply analyzer_pass threshold
     * 10. Write storage/candidates.json
     *
     * @return array<int,array<string,mixed>>
     */
    public function run(): array
    {
        if (($this->cfg['enabled'] ?? true) !== true) {
            return [];
        }

        $this->analyzerDebugLines = [];

        // Reset context adapter counters for this run
        $this->contextAdapter->resetCounters();

        $minHistoryPoints = (int)($this->cfg['min_history_points'] ?? 40);
        $strengthThreshold = (float)($this->cfg['strength_threshold'] ?? 0.50);
        $maxCandidates = (int)($this->cfg['max_candidates'] ?? 200);

        $decisionEnabled = (bool)($this->decisionCfg['enabled'] ?? true);
        $threshold = (float)($this->decisionCfg['threshold'] ?? 0.65);
        $weights = (array)($this->decisionCfg['weights'] ?? []);
        $wPattern   = (float)($weights['pattern_confidence'] ?? 0.40);
        $wTrend     = (float)($weights['trend_match_score'] ?? 0.20);
        $wCorridor  = (float)($weights['corridor_fit_score'] ?? 0.20);
        $wEntry     = (float)($weights['entry_quality_score'] ?? 0.20);

        // Debug: log effective analyzer config to confirm config delivery
        $this->analyzerDebugLines[] = sprintf(
            'analyzer config: threshold=%.2f weights=[pattern=%.2f trend=%.2f corridor=%.2f entry=%.2f] pattern_mode=%s detectors=%d',
            $threshold, $wPattern, $wTrend, $wCorridor, $wEntry,
            $this->patternMode,
            count($this->detectors)
        );

        $symbols = $this->loadSymbols();

        if ($symbols === []) {
            $this->state->writeJson('storage/candidates.json', []);
            return [];
        }

        $candidates = [];

        foreach ($symbols as $symbol) {
            $history = $this->loadHistory($symbol);

            if (count($history) < $minHistoryPoints) {
                continue;
            }

            $corridor = $this->calculateCorridor($history);
            $volatility = $this->calculateVolatility($history);

            if ($volatility <= 0.0) {
                continue;
            }

            $strength = $this->calculateStrength($corridor['width'], $volatility);

            if ($strength < $strengthThreshold) {
                continue;
            }

            $trendBias = $this->calculateTrend($history);
            $lastPrice = $history[count($history) - 1]['price'];

            // Prepare Parser2 context for contextual detectors
            $this->injectContextToDetectors($history, $trendBias, $volatility);

            // STEP 1 — Pattern detection (primary gate)
            $patternResult = $this->runPatternDetection($history);

            // If detectors are configured but no pattern found, reject
            if ($this->detectors !== [] && $patternResult['pattern_algorithm'] === 'none') {
                $this->addAnalyzerDebug($symbol, 'no pattern detected');
                continue;
            }

            $patternAlgorithm = $patternResult['pattern_algorithm'];
            $patternConfidence = $patternResult['pattern_confidence'];
            $patternTrendBias = $patternResult['trend_bias'] ?? $trendBias;

            // STEP 2 — Trend confirmation score
            $trendMatchScore = $this->computeTrendMatchScore($history, $patternAlgorithm, $patternTrendBias);

            // STEP 3 — Corridor fit score
            $corridorFitScore = $this->computeCorridorFitScore(
                $lastPrice, $corridor['low'], $corridor['high'], $patternTrendBias
            );

            // STEP 4 — Entry quality score
            $entryQualityScore = $this->computeEntryQualityScore(
                $history, $lastPrice, $corridor['low'], $corridor['high'], $patternTrendBias
            );

            // STEP 5 — Weighted analyzer score
            $analyzerScore = round(
                ($patternConfidence * $wPattern)
                + ($trendMatchScore * $wTrend)
                + ($corridorFitScore * $wCorridor)
                + ($entryQualityScore * $wEntry),
                4
            );

            // STEP 6 — Analyzer pass threshold
            $analyzerPass = true;
            $rejectionReason = '';

            if ($decisionEnabled && $analyzerScore < $threshold) {
                $analyzerPass = false;

                // Build detailed reason breakdown
                if ($trendMatchScore < 0.4) {
                    $rejectionReason = 'trend mismatch (score=' . number_format($trendMatchScore, 2) . ')';
                } elseif ($corridorFitScore < 0.4) {
                    $rejectionReason = 'corridor fit too weak (score=' . number_format($corridorFitScore, 2) . ')';
                } elseif ($entryQualityScore < 0.4) {
                    $rejectionReason = 'entry quality too poor (score=' . number_format($entryQualityScore, 2) . ')';
                } else {
                    $rejectionReason = 'analyzer_score ' . number_format($analyzerScore, 4)
                        . ' below threshold ' . number_format($threshold, 2);
                }

                $this->addAnalyzerDebug($symbol, $rejectionReason);
                continue;
            }

            // Derive explicit side from pattern algorithm + trend_bias
            $side = $this->deriveSideFromPattern($patternAlgorithm, $patternTrendBias);

            // Reject candidate if side cannot be resolved
            if ($side === null) {
                $this->addAnalyzerDebug($symbol, 'side unresolved (pattern=' . $patternAlgorithm . ', trend_bias=' . $patternTrendBias . ')');
                continue;
            }

            $candidate = [
                'symbol' => $symbol,
                'corridor_low' => $corridor['low'],
                'corridor_high' => $corridor['high'],
                'corridor_width' => $corridor['width'],
                'volatility' => $volatility,
                'strength' => $strength,
                'trend_bias' => $patternTrendBias,
                'side' => $side,
                'history_points' => count($history),
                'last_price' => $lastPrice,
                'pattern_algorithm' => $patternAlgorithm,
                'pattern_confidence' => $patternConfidence,
                'confirmation_score' => (float)($patternResult['confirmation_score'] ?? 0.0),
                'setup_score' => (float)($patternResult['setup_score'] ?? 0.0),
                'context_score' => (float)($patternResult['context_score'] ?? 0.0),
                'hold_score' => (float)($patternResult['hold_score'] ?? 0.0),
                'trigger_level' => (float)($patternResult['trigger_level'] ?? 0.0),
                'setup_neckline_price' => (float)($patternResult['setup_neckline_price'] ?? 0.0),
                'setup_low_1_price' => (float)($patternResult['setup_low_1_price'] ?? 0.0),
                'setup_low_2_price' => (float)($patternResult['setup_low_2_price'] ?? 0.0),
                // Confirmation score component debug fields
                'reclaim_strength_score' => (float)($patternResult['reclaim_strength_score'] ?? 0.0),
                'hold_quality_score' => (float)($patternResult['hold_quality_score'] ?? 0.0),
                'post_reclaim_stability_score' => (float)($patternResult['post_reclaim_stability_score'] ?? 0.0),
                'zone_defense_score' => (float)($patternResult['zone_defense_score'] ?? 0.0),
                // V3 confirmation tier (from detector, enriched later by policy fields)
                'confirmation_tier' => (string)($patternResult['confirmation_tier'] ?? ''),
                'trend_match_score' => round($trendMatchScore, 4),
                'corridor_fit_score' => round($corridorFitScore, 4),
                'entry_quality_score' => round($entryQualityScore, 4),
                'analyzer_score' => $analyzerScore,
                'analyzer_pass' => $analyzerPass,
            ];

            $candidates[] = $candidate;
        }

        // Sort by analyzer_score descending
        usort($candidates, function (array $a, array $b): int {
            return ($b['analyzer_score'] ?? 0) <=> ($a['analyzer_score'] ?? 0);
        });

        // Track V2/V3 downstream counts before truncation
        $downstreamBeforeTrunc = $this->countByAlgorithm($candidates);

        // Apply max_candidates limit
        $droppedByLimit = [];
        if (count($candidates) > $maxCandidates) {
            $dropped = array_slice($candidates, $maxCandidates);
            $droppedByLimit = $this->countByAlgorithm($dropped);
            $candidates = array_slice($candidates, 0, $maxCandidates);
        }

        $this->state->writeJson('storage/candidates.json', $candidates);

        // Persist V2/V3 stage counters for reversal comparison layer
        $this->persistV2StageCounters();

        // Persist downstream pipeline counters for V2/V3 diagnostics
        $this->persistDownstreamCounters($downstreamBeforeTrunc, $droppedByLimit, $candidates);

        return $candidates;
    }

    /**
     * Collect and persist V2/V3 detector stage counters.
     *
     * Writes per-algorithm and family-aggregate V2/V3 funnel metrics
     * to storage/v2_stage_counters.json for consumption by
     * simulator_engine and simulation_audit comparison layers.
     *
     * Includes context_rejected counts and reject reason diagnostics
     * for contextual detectors (V2-contextual and V3).
     */
    private function persistV2StageCounters(): void
    {
        $v2CountersByAlgo = [];
        $familySetup = 0;
        $familyConfirmed = 0;
        $familyRejected = 0;
        $familyContextRejected = 0;
        $familyContextPassed = 0;

        $contextDiagnostics = [];

        foreach ($this->detectors as $detector) {
            if (!method_exists($detector, 'getStageCounters')) {
                continue;
            }
            $name = $detector->getName();
            $counters = $detector->getStageCounters();

            $setup = (int)($counters['setup_candidates'] ?? 0);
            $confirmed = (int)($counters['confirmed'] ?? 0);
            $rejected = (int)($counters['confirm_rejected'] ?? 0);
            $contextRejected = (int)($counters['context_rejected'] ?? 0);
            $contextPassed = (int)($counters['context_passed'] ?? 0);

            $totalContextAttempts = $contextRejected + $contextPassed;
            $contextPassRate = $totalContextAttempts > 0 ? round($contextPassed / $totalContextAttempts, 4) : 0.0;

            $v2CountersByAlgo[$name] = [
                'setup_candidates_count'   => $setup,
                'confirmed_signals_count'  => $confirmed,
                'confirm_rejected_count'   => $rejected,
                'context_rejected_count'   => $contextRejected,
                'context_passed_count'     => $contextPassed,
                'context_pass_rate'        => $contextPassRate,
                'confirmation_rate'        => $setup > 0 ? round($confirmed / $setup, 4) : 0.0,
                'rejection_rate'           => $setup > 0 ? round($rejected / $setup, 4) : 0.0,
            ];

            // Collect reject reason distribution for contextual detectors
            if (method_exists($detector, 'getRejectReasonDistribution')) {
                $distribution = $detector->getRejectReasonDistribution();
                $preview = method_exists($detector, 'getContextRejectPreview')
                    ? $detector->getContextRejectPreview()
                    : [];

                // Collect confirmation-stage reject diagnostics (V3+)
                $confirmRejectDistribution = method_exists($detector, 'getConfirmRejectReasonDistribution')
                    ? $detector->getConfirmRejectReasonDistribution()
                    : [];
                $confirmRejectPreview = method_exists($detector, 'getConfirmRejectPreview')
                    ? $detector->getConfirmRejectPreview()
                    : [];

                $contextDiagnostics[$name] = [
                    'reject_reason_distribution' => $distribution,
                    'context_rejected_count'     => $contextRejected,
                    'context_passed_count'       => $contextPassed,
                    'context_pass_rate'          => $contextPassRate,
                    'context_reject_preview'     => $preview,
                    'confirm_reject_reason_distribution' => $confirmRejectDistribution,
                    'confirm_reject_preview'     => $confirmRejectPreview,
                ];
            } elseif (method_exists($detector, 'getLastRejectReasons')) {
                $reasons = $detector->getLastRejectReasons();
                if ($reasons !== []) {
                    $reasonCounts = [];
                    foreach ($reasons as $r) {
                        $reasonCounts[$r] = ($reasonCounts[$r] ?? 0) + 1;
                    }
                    $contextDiagnostics[$name] = [
                        'reject_reasons' => $reasonCounts,
                        'context_rejected_count' => $contextRejected,
                    ];
                }
            }

            $familySetup += $setup;
            $familyConfirmed += $confirmed;
            $familyRejected += $rejected;
            $familyContextRejected += $contextRejected;
            $familyContextPassed += $contextPassed;
        }

        if ($v2CountersByAlgo === []) {
            return;
        }

        $totalFamilyContextAttempts = $familyContextRejected + $familyContextPassed;
        $familyContextPassRate = $totalFamilyContextAttempts > 0
            ? round($familyContextPassed / $totalFamilyContextAttempts, 4) : 0.0;

        $payload = [
            'by_algorithm' => $v2CountersByAlgo,
            'reversal_v2_aggregate' => [
                'setup_candidates_count'   => $familySetup,
                'confirmed_signals_count'  => $familyConfirmed,
                'confirm_rejected_count'   => $familyRejected,
                'context_rejected_count'   => $familyContextRejected,
                'context_passed_count'     => $familyContextPassed,
                'context_pass_rate'        => $familyContextPassRate,
                'confirmation_rate'        => $familySetup > 0 ? round($familyConfirmed / $familySetup, 4) : 0.0,
                'rejection_rate'           => $familySetup > 0 ? round($familyRejected / $familySetup, 4) : 0.0,
            ],
            'context_diagnostics' => $contextDiagnostics,
            'context_adapter_counters' => $this->contextAdapter->getAdapterCounters(),
            'updated_at' => date('c'),
        ];

        $this->state->writeJson('storage/v2_stage_counters.json', $payload);
    }

    /**
     * Count candidates by pattern_algorithm.
     *
     * @param array<int,array<string,mixed>> $candidates
     * @return array<string,int>
     */
    private function countByAlgorithm(array $candidates): array
    {
        $counts = [];
        foreach ($candidates as $c) {
            $algo = (string)($c['pattern_algorithm'] ?? 'unknown');
            $counts[$algo] = ($counts[$algo] ?? 0) + 1;
        }
        return $counts;
    }

    /**
     * Persist downstream pipeline counters for V2/V3 signal flow diagnostics.
     *
     * Tracks how many V2/V3 candidates survived scoring, were dropped by
     * threshold, dropped by rank/limit, and reached final output.
     *
     * @param array<string,int> $beforeTruncation Counts by algo before max_candidates limit
     * @param array<string,int> $droppedByLimit   Counts by algo dropped by max_candidates
     * @param array<int,array<string,mixed>> $finalCandidates Final output candidates
     */
    private function persistDownstreamCounters(array $beforeTruncation, array $droppedByLimit, array $finalCandidates): void
    {
        $trackedAlgos = [
            'double_bottom_confirm_v2',
            'double_top_confirm_v2',
            'double_bottom_contextual_v2',
            'double_bottom_contextual_v3',
        ];

        $finalCounts = $this->countByAlgorithm($finalCandidates);

        $downstream = [];
        foreach ($trackedAlgos as $algo) {
            $shaped   = $beforeTruncation[$algo] ?? 0;
            $dropped  = $droppedByLimit[$algo] ?? 0;
            $selected = $finalCounts[$algo] ?? 0;

            $downstream[$algo] = [
                'candidates_shaped_count'  => $shaped,
                'candidates_selected_count'=> $selected,
                'candidates_dropped_by_rank_count' => $dropped,
                'drop_reason' => $dropped > 0 ? 'dropped_by_rank' : ($shaped === 0 ? 'no_candidates_shaped' : 'all_selected'),
            ];
        }

        // Build a small debug preview of first few V2/V3 items
        $preview = [];
        $previewLimit = 5;
        foreach ($finalCandidates as $c) {
            $algo = (string)($c['pattern_algorithm'] ?? '');
            if (in_array($algo, $trackedAlgos, true) && count($preview) < $previewLimit) {
                $preview[] = [
                    'symbol' => $c['symbol'] ?? '',
                    'pattern_algorithm' => $algo,
                    'side' => $c['side'] ?? '',
                    'analyzer_score' => $c['analyzer_score'] ?? 0,
                    'pattern_confidence' => $c['pattern_confidence'] ?? 0,
                    'stage' => 'final_output',
                    'selected' => true,
                ];
            }
        }

        $payload = [
            'by_algorithm' => $downstream,
            'debug_preview' => $preview,
            'updated_at' => date('c'),
        ];

        $this->state->writeJson('storage/downstream_counters.json', $payload);
    }

    /**
     * Get analyzer debug rejection lines from the last run.
     *
     * @return array<int,string>
     */
    public function getAnalyzerDebugLines(): array
    {
        return $this->analyzerDebugLines;
    }

    /**
     * Record an analyzer debug rejection line (max 200).
     */
    private function addAnalyzerDebug(string $symbol, string $reason): void
    {
        if (count($this->analyzerDebugLines) < 200) {
            $this->analyzerDebugLines[] = $symbol . ' rejected: ' . $reason;
        }
    }

    /**
     * Inject Parser2 market context into contextual detectors.
     *
     * Uses the canonical ContextAdapter to compute multi-factor regime
     * classification from price history, then passes the normalized
     * context contract to any detector that implements setContext().
     *
     * Both V2 field names (trend_direction, trend_strength) and V3
     * canonical field names (regime_direction, regime_strength, etc.)
     * are included in the adapter output so all detector generations
     * receive the contract they expect.
     *
     * @param array<int,array{ts_unix:int,price:float}> $history
     */
    private function injectContextToDetectors(array $history, string $trendBias, float $volatility): void
    {
        $n = count($history);
        if ($n < 10) {
            return;
        }

        $prices = array_column($history, 'price');

        // Use canonical context adapter for multi-factor regime classification
        $context = $this->contextAdapter->computeContext($prices, $volatility);

        foreach ($this->detectors as $detector) {
            if (method_exists($detector, 'setContext')) {
                $detector->setContext($context);
            }
        }
    }

    /**
     * Derive explicit trade side from pattern algorithm and trend_bias.
     *
     * double_bottom                → long
     * double_bottom_confirm_v2     → long
     * double_bottom_contextual_v2  → long
     * double_bottom_contextual_v3  → long
     * double_top                   → short
     * double_top_confirm_v2        → short
     * pullback_trend_continue up   → long
     * pullback_trend_continue down → short
     *
     * Returns null if side cannot be determined (reject candidate).
     */
    private function deriveSideFromPattern(string $patternAlgorithm, string $trendBias): ?string
    {
        return match ($patternAlgorithm) {
            'double_bottom', 'double_bottom_confirm_v2', 'double_bottom_contextual_v2', 'double_bottom_contextual_v3' => 'long',
            'double_top', 'double_top_confirm_v2' => 'short',
            'pullback_trend_continue' => match ($trendBias) {
                'up' => 'long',
                'down' => 'short',
                default => null,
            },
            default => match ($trendBias) {
                'up' => 'long',
                'down' => 'short',
                default => null,
            },
        };
    }

    // ------------------------------------------------------------------
    // Analyzer Decision Scoring (Steps 2–4)
    // ------------------------------------------------------------------

    /**
     * STEP 2: Compute trend confirmation score.
     *
     * Measures how well the broader trend confirms the detected pattern direction.
     * Range: 0.0 to 1.0
     *
     * @param array<int,array{ts_unix:int,price:float}> $history
     */
    private function computeTrendMatchScore(array $history, string $patternAlgorithm, string $patternTrendBias): float
    {
        $n = count($history);
        if ($n < 10) {
            return 0.5;
        }

        $prices = array_column($history, 'price');

        // Compute short-term trend (last 30% of data)
        $shortLen = max(5, (int)($n * 0.3));
        $shortSlice = array_slice($prices, $n - $shortLen);
        $shortStart = $shortSlice[0];
        $shortEnd = $shortSlice[count($shortSlice) - 1];
        $shortReturn = ($shortStart > 0.0) ? ($shortEnd - $shortStart) / $shortStart : 0.0;

        // Compute medium-term trend (last 60% of data)
        $medLen = max(10, (int)($n * 0.6));
        $medSlice = array_slice($prices, $n - $medLen);
        $medStart = $medSlice[0];
        $medEnd = $medSlice[count($medSlice) - 1];
        $medReturn = ($medStart > 0.0) ? ($medEnd - $medStart) / $medStart : 0.0;

        // For reversal patterns, trend context is different
        if ($patternAlgorithm === 'double_bottom') {
            // double_bottom → bullish: good if medium trend was down (reversal context)
            // AND short-term shows recovery
            $medScore = ($medReturn < 0) ? min(1.0, abs($medReturn) / 0.05) : max(0.0, 0.5 - $medReturn * 5);
            $shortScore = ($shortReturn > 0) ? min(1.0, $shortReturn / 0.02) : 0.2;
            return round(max(0.0, min(1.0, ($medScore * 0.5) + ($shortScore * 0.5))), 4);
        }

        if ($patternAlgorithm === 'double_top') {
            // double_top → bearish: good if medium trend was up (reversal context)
            // AND short-term shows decline
            $medScore = ($medReturn > 0) ? min(1.0, $medReturn / 0.05) : max(0.0, 0.5 + $medReturn * 5);
            $shortScore = ($shortReturn < 0) ? min(1.0, abs($shortReturn) / 0.02) : 0.2;
            return round(max(0.0, min(1.0, ($medScore * 0.5) + ($shortScore * 0.5))), 4);
        }

        if ($patternAlgorithm === 'pullback_trend_continue') {
            // continuation: trend and pattern bias should align
            if ($patternTrendBias === 'up') {
                $trendAlign = ($medReturn > 0) ? min(1.0, $medReturn / 0.03) : 0.1;
                $shortAlign = ($shortReturn > 0) ? min(1.0, $shortReturn / 0.01) : 0.2;
            } else {
                $trendAlign = ($medReturn < 0) ? min(1.0, abs($medReturn) / 0.03) : 0.1;
                $shortAlign = ($shortReturn < 0) ? min(1.0, abs($shortReturn) / 0.01) : 0.2;
            }
            return round(max(0.0, min(1.0, ($trendAlign * 0.6) + ($shortAlign * 0.4))), 4);
        }

        // Fallback: simple trend alignment
        if ($patternTrendBias === 'up') {
            return round(max(0.0, min(1.0, 0.5 + $shortReturn * 10)), 4);
        }
        if ($patternTrendBias === 'down') {
            return round(max(0.0, min(1.0, 0.5 - $shortReturn * 10)), 4);
        }

        return 0.5;
    }

    /**
     * STEP 3: Compute corridor fit score.
     *
     * Measures whether the current price sits in a structurally acceptable zone
     * relative to the corridor and pattern direction.
     * Range: 0.0 to 1.0
     */
    private function computeCorridorFitScore(float $lastPrice, float $corridorLow, float $corridorHigh, string $patternTrendBias): float
    {
        $range = $corridorHigh - $corridorLow;
        if ($range <= 0.0 || $lastPrice <= 0.0) {
            return 0.5;
        }

        // Price position within corridor: 0 = at low, 1 = at high
        $position = ($lastPrice - $corridorLow) / $range;

        // Clamp for outside-corridor prices
        $position = max(-0.2, min(1.2, $position));

        if ($patternTrendBias === 'up') {
            // For bullish patterns, better if price is in the lower half (buy low)
            // Ideal zone: 0.1 to 0.5 — soft penalty outside, floor at 0.35
            if ($position < 0.0) {
                return round(max(0.35, 0.55 + $position), 4);
            }
            if ($position <= 0.5) {
                return round(0.65 + (0.35 - abs($position - 0.25)) * 0.8, 4);
            }
            if ($position <= 0.8) {
                return round(max(0.45, 0.75 - ($position - 0.5) * 0.8), 4);
            }
            return round(max(0.35, 0.55 - ($position - 0.8) * 0.5), 4);
        }

        if ($patternTrendBias === 'down') {
            // For bearish patterns, better if price is in the upper half (sell high)
            // Ideal zone: 0.5 to 0.9 — soft penalty outside, floor at 0.35
            if ($position > 1.0) {
                return round(max(0.35, 0.55 - ($position - 1.0) * 0.5), 4);
            }
            if ($position >= 0.5) {
                return round(0.65 + (0.35 - abs($position - 0.75)) * 0.8, 4);
            }
            if ($position >= 0.2) {
                return round(max(0.45, 0.75 - (0.5 - $position) * 0.8), 4);
            }
            return round(max(0.35, 0.55 - (0.2 - $position) * 0.5), 4);
        }

        // Flat/neutral — prefer mid-corridor, soft penalty at extremes
        return round(max(0.4, 1.0 - abs($position - 0.5) * 1.0), 4);
    }

    /**
     * STEP 4: Compute entry quality score.
     *
     * Estimates whether entering now is timely:
     * - not too late (move already extended)
     * - not too early (no confirmation yet)
     * - not chasing a stretched move
     * Range: 0.0 to 1.0
     */
    private function computeEntryQualityScore(
        array $history,
        float $lastPrice,
        float $corridorLow,
        float $corridorHigh,
        string $patternTrendBias
    ): float {
        $n = count($history);
        if ($n < 10 || $lastPrice <= 0.0) {
            return 0.5;
        }

        $prices = array_column($history, 'price');

        // Recent momentum: last 5 bars
        $recentLen = min(5, $n - 1);
        $recentStart = $prices[$n - 1 - $recentLen];
        $recentMomentum = ($recentStart > 0.0) ? ($lastPrice - $recentStart) / $recentStart : 0.0;

        // How far current price is from corridor extremes
        $range = $corridorHigh - $corridorLow;
        $distFromLow = ($range > 0.0) ? ($lastPrice - $corridorLow) / $range : 0.5;
        $distFromHigh = ($range > 0.0) ? ($corridorHigh - $lastPrice) / $range : 0.5;

        // Volatility of last few bars (should not be too extreme)
        $recentSlice = array_slice($prices, $n - min(10, $n));
        $recentMax = max($recentSlice);
        $recentMin = min($recentSlice);
        $recentRange = ($recentMin > 0.0) ? ($recentMax - $recentMin) / $recentMin : 0.0;
        $volatilityPenalty = min(1.0, $recentRange / 0.10); // penalize wild swings

        if ($patternTrendBias === 'up') {
            // Bullish: moderate positive momentum is good, too much is chasing
            $momentumScore = 0.5;
            if ($recentMomentum > 0.0 && $recentMomentum < 0.03) {
                $momentumScore = 0.7 + ($recentMomentum / 0.03) * 0.3;
            } elseif ($recentMomentum >= 0.03) {
                // Chasing — already extended
                $momentumScore = max(0.2, 0.7 - ($recentMomentum - 0.03) * 5);
            } elseif ($recentMomentum < 0.0 && $recentMomentum > -0.02) {
                // Small dip — good entry zone
                $momentumScore = 0.6;
            } else {
                $momentumScore = 0.3;
            }

            // Prefer lower corridor position
            $positionScore = max(0.2, 1.0 - max(0.0, $distFromLow - 0.2));

            return round(max(0.0, min(1.0,
                ($momentumScore * 0.45) + ($positionScore * 0.35) + ((1.0 - $volatilityPenalty) * 0.20)
            )), 4);
        }

        if ($patternTrendBias === 'down') {
            // Bearish: moderate negative momentum is good
            $momentumScore = 0.5;
            if ($recentMomentum < 0.0 && $recentMomentum > -0.03) {
                $momentumScore = 0.7 + (abs($recentMomentum) / 0.03) * 0.3;
            } elseif ($recentMomentum <= -0.03) {
                $momentumScore = max(0.2, 0.7 - (abs($recentMomentum) - 0.03) * 5);
            } elseif ($recentMomentum > 0.0 && $recentMomentum < 0.02) {
                $momentumScore = 0.6;
            } else {
                $momentumScore = 0.3;
            }

            // Prefer higher corridor position for bearish
            $positionScore = max(0.2, 1.0 - max(0.0, $distFromHigh - 0.2));

            return round(max(0.0, min(1.0,
                ($momentumScore * 0.45) + ($positionScore * 0.35) + ((1.0 - $volatilityPenalty) * 0.20)
            )), 4);
        }

        return round(max(0.3, 0.6 - $volatilityPenalty * 0.3), 4);
    }

    /**
     * Run pattern detection for a symbol's history.
     *
     * @param array<int,array{ts_unix:int,price:float}> $history
     * @return array{pattern_algorithm:string,pattern_confidence:float,trend_bias:string|null}
     */
    private function runPatternDetection(array $history): array
    {
        $default = [
            'pattern_algorithm' => 'none',
            'pattern_confidence' => 0.0,
            'trend_bias' => null,
        ];

        if ($this->detectors === []) {
            return $default;
        }

        $results = [];

        foreach ($this->detectors as $detector) {
            $result = $detector->detect($history);
            if ($result !== null && ($result['detected'] ?? false)) {
                $results[] = [
                    'name' => $detector->getName(),
                    'confidence' => (float)($result['confidence'] ?? 0.0),
                    'trend_bias' => (string)($result['trend_bias'] ?? ''),
                    // Preserve detector detail fields for downstream propagation
                    'confirmation_score' => (float)($result['confirmation_score'] ?? 0.0),
                    'setup_score' => (float)($result['setup_score'] ?? 0.0),
                    'context_score' => (float)($result['context_score'] ?? 0.0),
                    'hold_score' => (float)($result['hold_score'] ?? 0.0),
                    'trigger_level' => (float)($result['trigger_level'] ?? 0.0),
                    'setup_neckline_price' => (float)($result['setup_neckline_price'] ?? 0.0),
                    'setup_low_1_price' => (float)($result['setup_low_1_price'] ?? 0.0),
                    'setup_low_2_price' => (float)($result['setup_low_2_price'] ?? 0.0),
                    // Confirmation score component debug fields
                    'reclaim_strength_score' => (float)($result['reclaim_strength_score'] ?? 0.0),
                    'hold_quality_score' => (float)($result['hold_quality_score'] ?? 0.0),
                    'post_reclaim_stability_score' => (float)($result['post_reclaim_stability_score'] ?? 0.0),
                    'zone_defense_score' => (float)($result['zone_defense_score'] ?? 0.0),
                    // V3 confirmation tier (computed by V3 detector)
                    'confirmation_tier' => (string)($result['confirmation_tier'] ?? ''),
                ];
            }
        }

        if ($results === []) {
            return $default;
        }

        // Apply mode logic
        $best = null;
        switch ($this->patternMode) {
            case 'one':
                // Use the first enabled detector's result (if detected)
                // In mode=one only one algorithm should be enabled, pick best
                $best = $results[0];
                break;

            case 'any':
                // Any enabled algorithm match → pick highest confidence
                usort($results, static fn($a, $b) => $b['confidence'] <=> $a['confidence']);
                $best = $results[0];
                break;

            case 'all':
                // All enabled algorithms must confirm
                if (count($results) < count($this->detectors)) {
                    return $default;
                }
                // All confirmed — pick highest confidence
                usort($results, static fn($a, $b) => $b['confidence'] <=> $a['confidence']);
                $best = $results[0];
                break;

            default:
                return $default;
        }

        return [
            'pattern_algorithm' => $best['name'],
            'pattern_confidence' => round($best['confidence'], 2),
            'trend_bias' => $best['trend_bias'] ?: null,
            'confirmation_score' => $best['confirmation_score'],
            'setup_score' => $best['setup_score'],
            'context_score' => $best['context_score'],
            'hold_score' => $best['hold_score'],
            'trigger_level' => $best['trigger_level'],
            'setup_neckline_price' => $best['setup_neckline_price'],
            'setup_low_1_price' => $best['setup_low_1_price'],
            'setup_low_2_price' => $best['setup_low_2_price'],
            // Confirmation score component debug fields
            'reclaim_strength_score' => $best['reclaim_strength_score'],
            'hold_quality_score' => $best['hold_quality_score'],
            'post_reclaim_stability_score' => $best['post_reclaim_stability_score'],
            'zone_defense_score' => $best['zone_defense_score'],
            // V3 confirmation tier
            'confirmation_tier' => $best['confirmation_tier'] ?? '',
        ];
    }

    // ------------------------------------------------------------------
    // Symbol loading
    // ------------------------------------------------------------------

    /**
     * Load symbol list from Parser3 profiles directory.
     * Symbols are derived from filenames: {profiles_dir}/profiles/*.json
     *
     * @return array<int,string>
     */
    private function loadSymbols(): array
    {
        $profilesKey = (string)($this->cfg['profiles_key'] ?? '');

        if ($profilesKey === '') {
            return [];
        }

        try {
            $storageDir = \Core\System\SystemPaths::instance()->get($profilesKey);
        } catch (\Throwable $e) {
            return [];
        }

        $profilesDir = $storageDir . '/profiles';

        if (!is_dir($profilesDir)) {
            return [];
        }

        $files = scandir($profilesDir);
        if ($files === false) {
            return [];
        }

        $symbols = [];

        foreach ($files as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            if (substr($f, -5) === '.json') {
                $symbol = substr($f, 0, -5);
                if ($symbol !== '') {
                    $symbols[] = $symbol;
                }
            }
        }

        return $symbols;
    }

    // ------------------------------------------------------------------
    // History loading
    // ------------------------------------------------------------------

    /**
     * Load price history for a single symbol from Parser2 NDJSON files.
     * Uses the latest .ndjson file in {history_dir}/{SYMBOL}/
     *
     * Supports two NDJSON line formats:
     *   Format A (flat):  {"ts":"...","ts_unix":123,"price":"0.12122"}
     *   Format B (Bybit): {"ts":"...","ts_unix":123,"data":{"lastPrice":"0.12122",...}}
     *
     * @return array<int,array{ts_unix:int,price:float}>
     */
    private function loadHistory(string $symbol): array
    {
        $historyKey = (string)($this->cfg['history_key'] ?? '');

        if ($historyKey === '') {
            return [];
        }

        try {
            $storageDir = \Core\System\SystemPaths::instance()->get($historyKey);
        } catch (\Throwable $e) {
            return [];
        }

        $symbolDir = $storageDir . '/' . $symbol;

        if (!is_dir($symbolDir)) {
            return [];
        }

        $files = scandir($symbolDir);
        if ($files === false) {
            return [];
        }

        $ndjsonFiles = [];
        foreach ($files as $f) {
            if (substr($f, -7) === '.ndjson') {
                $ndjsonFiles[] = $f;
            }
        }

        if ($ndjsonFiles === []) {
            return [];
        }

        sort($ndjsonFiles);

        // Use latest .ndjson file
        $latestFile = $symbolDir . '/' . $ndjsonFiles[count($ndjsonFiles) - 1];

        return $this->parseNdjson($latestFile);
    }

    /**
     * Parse an NDJSON file line by line (safe streaming read).
     *
     * @return array<int,array{ts_unix:int,price:float}>
     */
    private function parseNdjson(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return [];
        }

        $history = [];

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $data = json_decode($line, true);
                if (!is_array($data)) {
                    continue;
                }

                $price = $this->extractPrice($data);
                if ($price <= 0.0) {
                    continue;
                }

                $history[] = [
                    'ts_unix' => (int)($data['ts_unix'] ?? 0),
                    'price' => $price,
                ];
            }
        } finally {
            fclose($handle);
        }

        return $history;
    }

    /**
     * Extract numeric price from a ticker row.
     *
     * Format A (flat):  {"price":"0.12122"}
     * Format B (Bybit): {"data":{"lastPrice":"0.12122","markPrice":"..."}}
     */
    private function extractPrice(array $row): float
    {
        // Format A — flat price field
        if (isset($row['price'])) {
            $p = (float)$row['price'];
            if ($p > 0.0) {
                return $p;
            }
        }

        // Format B — nested Bybit data.lastPrice
        if (isset($row['data']) && is_array($row['data'])) {
            if (isset($row['data']['lastPrice'])) {
                $p = (float)$row['data']['lastPrice'];
                if ($p > 0.0) {
                    return $p;
                }
            }
        }

        return 0.0;
    }

    // ------------------------------------------------------------------
    // Market structure calculations
    // ------------------------------------------------------------------

    /**
     * @param array<int,array{ts_unix:int,price:float}> $history
     * @return array{low:float,high:float,width:float}
     */
    private function calculateCorridor(array $history): array
    {
        $low = PHP_FLOAT_MAX;
        $high = 0.0;

        foreach ($history as $point) {
            $price = $point['price'];
            if ($price < $low) {
                $low = $price;
            }
            if ($price > $high) {
                $high = $price;
            }
        }

        $width = 0.0;
        if ($low > 0.0) {
            $width = ($high / $low) - 1.0;
        }

        return [
            'low' => round($low, 8),
            'high' => round($high, 8),
            'width' => round($width, 6),
        ];
    }

    /**
     * Calculate volatility as standard deviation of returns.
     *
     * @param array<int,array{ts_unix:int,price:float}> $history
     */
    private function calculateVolatility(array $history): float
    {
        $returns = [];

        for ($i = 1, $n = count($history); $i < $n; $i++) {
            $prev = $history[$i - 1]['price'];
            $curr = $history[$i]['price'];
            if ($prev > 0.0) {
                $returns[] = ($curr / $prev) - 1.0;
            }
        }

        return round($this->stddev($returns), 6);
    }

    /**
     * @param array<int,float> $values
     */
    private function stddev(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }

        $mean = array_sum($values) / $n;
        $variance = 0.0;

        foreach ($values as $v) {
            $variance += ($v - $mean) * ($v - $mean);
        }

        return sqrt($variance / ($n - 1));
    }

    /**
     * strength = corridor_width / volatility
     */
    private function calculateStrength(float $corridorWidth, float $volatility): float
    {
        if ($volatility <= 0.0) {
            return 0.0;
        }

        return round($corridorWidth / $volatility, 6);
    }

    /**
     * Determine trend bias from first and last price.
     *
     * @param array<int,array{ts_unix:int,price:float}> $history
     */
    private function calculateTrend(array $history): string
    {
        $first = $history[0]['price'];
        $last = $history[count($history) - 1]['price'];

        if ($last > $first) {
            return 'up';
        }
        if ($last < $first) {
            return 'down';
        }

        return 'flat';
    }
}
