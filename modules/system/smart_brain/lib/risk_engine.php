<?php
declare(strict_types=1);

/**
 * Risk Engine — Smart Brain Phase 6 + Side & Leverage Fix
 *
 * Transforms monitored opportunities into trade-ready signals
 * with adaptive risk parameters based on passport data.
 *
 * Side determination:
 *   Uses explicit side from pattern detection (monitor['side']).
 *   Rejects signals where side cannot be resolved — no silent long fallback.
 *
 * Dynamic Leverage V1:
 *   Base leverage from analyzer_score ladder.
 *   Adjustments: volatility, corridor_width, bootstrap mode, reliability.
 *   Clamped to [1, user max_leverage].
 *
 * Bootstrap mode:
 *   If passport.trades_total < warmup_min_trades AND bootstrap_enabled:
 *   - Allow entry_zone monitors with reduced budget/leverage
 *   - Mark signal_mode = "bootstrap"
 *
 * Normal mode:
 *   If passport.trades_total >= warmup_min_trades:
 *   - Require reliability_score >= min_reliability_after_warmup
 *   - Standard budget/leverage calculation
 */
final class RiskEngine
{
    /** @var array<string,mixed> */
    private array $cfg;
    /** @var array<string,mixed> */
    private array $profiles;
    private StateManager $state;

    /**
     * @param array<string,mixed> $cfg       Effective risk_engine config
     * @param array<string,mixed> $profiles  Effective profiles config
     */
    public function __construct(array $cfg, array $profiles, StateManager $state)
    {
        $this->cfg = $cfg;
        $this->profiles = $profiles;
        $this->state = $state;
    }

    /** @var array<string,int> Rejection counters from last apply() call */
    private array $rejectionCounters = [];
    /** @var array<int,string> Per-symbol rejection debug lines from last apply() call */
    private array $debugLines = [];
    /** @var array<string,int> Signal mode counters from last apply() call */
    private array $signalModeCounters = [];
    /** @var array<string,array<string,int>> Per-pattern rejection counters */
    private array $perPatternRejections = [];
    /** @var array<int,array<string,mixed>> Preview of first N failed monitors */
    private array $failedMonitorPreview = [];
    private int $failedMonitorPreviewLimit = 10;

    /**
     * Generate signals from monitors using passport-based risk parameters.
     * Supports bootstrap mode for cold-start passports.
     *
     * @param array<int,array<string,mixed>> $monitors
     * @param array<string,float>            $prices     Current prices (symbol→price)
     * @param array<string,mixed>            $userLimits User limits config
     * @return array<int,array<string,mixed>>
     */
    public function apply(array $monitors, array $prices = [], array $userLimits = []): array
    {
        $profileKey = (string)($this->profiles['default_profile'] ?? '111');
        $profile = (array)($this->profiles['profiles'][$profileKey] ?? []);

        // User limits (with defaults)
        $maxBudgetPerCoin = (float)($userLimits['max_budget_per_coin'] ?? $this->cfg['max_budget_per_coin'] ?? 20.0);
        $maxLeverage = (int)($userLimits['max_leverage'] ?? $this->cfg['max_leverage'] ?? 15);
        $maxActiveTasks = (int)($userLimits['max_active_tasks'] ?? 10);

        // Bootstrap config
        $bootstrapEnabled = (bool)($userLimits['bootstrap_enabled'] ?? false);
        $bootstrapMaxSignals = (int)($userLimits['bootstrap_max_signals'] ?? 5);
        $bootstrapBudgetFactor = (float)($userLimits['bootstrap_budget_factor'] ?? 0.50);
        $bootstrapMaxLeverage = (int)($userLimits['bootstrap_max_leverage'] ?? 3);
        $warmupMinTrades = (int)($userLimits['warmup_min_trades'] ?? 10);
        $minReliabilityAfterWarmup = (float)($userLimits['min_reliability_after_warmup'] ?? 0.15);

        // Profile values
        $profileBudget = (float)($profile['budget'] ?? 15.0);
        $profileMaxLeverage = min((int)($profile['max_leverage'] ?? 5), $maxLeverage);
        $stopLossRange = (float)($profile['stop_loss_range'] ?? 0.20);
        $takeProfitRoi = (float)($profile['take_profit_roi'] ?? 5.55);

        // Exit policy fields from user limits
        $exitPolicy = [
            'exit_mode'                  => (string)($userLimits['exit_mode'] ?? 'trailing_tp'),
            'stop_floor_type'            => (string)($userLimits['stop_floor_type'] ?? 'roi_percent'),
            'stop_floor_value'           => (float)($userLimits['stop_floor_value'] ?? 0.03),
            'trailing_enabled'           => (bool)($userLimits['trailing_enabled'] ?? false),
            'trailing_activation_roi'    => (float)($userLimits['trailing_activation_roi'] ?? 0.03),
            'trailing_min_lock_roi'      => (float)($userLimits['trailing_min_lock_roi'] ?? 0.008),
            'trailing_min_step'          => (float)($userLimits['trailing_min_step'] ?? 0.005),
            'fixed_take_profit_roi'      => (float)($userLimits['fixed_take_profit_roi'] ?? 0.05),
            'break_even_enabled'         => (bool)($userLimits['break_even_enabled'] ?? false),
            'break_even_activation_roi'  => (float)($userLimits['break_even_activation_roi'] ?? 0.015),
            // Stop Loss Engine V2
            'stop_mode'                    => (string)($userLimits['stop_mode'] ?? 'brain_managed'),
            'simple_stop_liq_factor'       => (float)($userLimits['simple_stop_liq_factor'] ?? 0.15),
            'brain_stop_corridor_factor'   => (float)($userLimits['brain_stop_corridor_factor'] ?? 0.25),
            'brain_stop_volatility_factor' => (float)($userLimits['brain_stop_volatility_factor'] ?? 0.50),
            'brain_stop_liq_safety_factor' => (float)($userLimits['brain_stop_liq_safety_factor'] ?? 0.30),
            // Early Failure Guard
            'early_failure_enabled'        => (bool)($userLimits['early_failure_enabled'] ?? false),
            'early_failure_window_minutes' => (int)($userLimits['early_failure_window_minutes'] ?? 5),
            'early_failure_max_adverse_roi' => (float)($userLimits['early_failure_max_adverse_roi'] ?? -0.008),
        ];

        // Leverage & Stop Control
        $leverageMode = (string)($userLimits['leverage_mode'] ?? 'auto');
        $manualLeverage = (int)($userLimits['manual_leverage'] ?? 3);
        $stopControlMode = (string)($userLimits['stop_control_mode'] ?? 'auto');
        $manualStopLossRoi = (float)($userLimits['manual_stop_loss_roi'] ?? 0.03);

        $this->rejectionCounters = [
            'rejected_not_entry_zone' => 0,
            'rejected_monitoring_stalled' => 0,
            'rejected_invalidated' => 0,
            'rejected_price_above_zone' => 0,
            'rejected_zone_too_far' => 0,
            'rejected_low_reliability' => 0,
            'rejected_missing_passport' => 0,
            'rejected_missing_price' => 0,
            'rejected_side_unresolved' => 0,
        ];
        $this->debugLines = [];
        $this->perPatternRejections = [];
        $this->failedMonitorPreview = [];
        $this->signalModeCounters = [
            'bootstrap_signals_count' => 0,
            'normal_signals_count' => 0,
            'warmup_symbols_count' => 0,
        ];

        $signals = [];
        $bootstrapCount = 0;

        foreach ($monitors as $monitor) {
            $symbol = (string)($monitor['symbol'] ?? '');
            $status = (string)($monitor['status'] ?? '');

            // Only generate signals for entry_zone monitors
            if ($status !== 'entry_zone') {
                $this->rejectionCounters['rejected_not_entry_zone']++;
                $algo = (string)($monitor['pattern_algorithm'] ?? 'none');
                $rejectDetail = (string)($monitor['reject_detail'] ?? 'rejected_not_entry_zone');

                // Specific sub-reason counters
                if ($status === 'invalidated') {
                    $this->rejectionCounters['rejected_invalidated']++;
                    $this->perPatternRejections[$algo]['rejected_invalidated'] = ($this->perPatternRejections[$algo]['rejected_invalidated'] ?? 0) + 1;
                } elseif ($status === 'monitoring') {
                    $this->rejectionCounters['rejected_monitoring_stalled']++;
                    $this->perPatternRejections[$algo]['rejected_monitoring_stalled'] = ($this->perPatternRejections[$algo]['rejected_monitoring_stalled'] ?? 0) + 1;
                }
                if ($rejectDetail === 'reject_price_above_zone') {
                    $this->rejectionCounters['rejected_price_above_zone']++;
                    $this->perPatternRejections[$algo]['rejected_price_above_zone'] = ($this->perPatternRejections[$algo]['rejected_price_above_zone'] ?? 0) + 1;
                } elseif ($rejectDetail === 'reject_zone_too_far') {
                    $this->rejectionCounters['rejected_zone_too_far']++;
                    $this->perPatternRejections[$algo]['rejected_zone_too_far'] = ($this->perPatternRejections[$algo]['rejected_zone_too_far'] ?? 0) + 1;
                }
                $this->perPatternRejections[$algo]['rejected_not_entry_zone'] = ($this->perPatternRejections[$algo]['rejected_not_entry_zone'] ?? 0) + 1;

                // Store failed monitor preview (first N)
                if (count($this->failedMonitorPreview) < $this->failedMonitorPreviewLimit) {
                    $this->failedMonitorPreview[] = [
                        'symbol' => $symbol,
                        'pattern_algorithm' => $algo,
                        'status' => $status,
                        'price_position' => round((float)($monitor['price_position'] ?? 0), 4),
                        'entry_zone_low' => (float)($monitor['entry_zone_low'] ?? 0),
                        'entry_zone_high' => (float)($monitor['entry_zone_high'] ?? 0),
                        'entry_zone_percent' => (float)($monitor['entry_zone_percent'] ?? 0),
                        'entry_zone_widened' => (bool)($monitor['entry_zone_widened'] ?? false),
                        'pattern_confidence' => (float)($monitor['pattern_confidence'] ?? 0),
                        'confirmation_score' => (float)($monitor['confirmation_score'] ?? 0),
                        'analyzer_score' => (float)($monitor['analyzer_score'] ?? 0),
                        'reject_reason' => $rejectDetail,
                        'zone_width_pct' => (float)($monitor['zone_width_pct'] ?? 0),
                        'zone_distance_from_price' => (float)($monitor['zone_distance_from_price'] ?? 0),
                        'current_price_at_creation' => (float)($monitor['current_price_at_creation'] ?? 0),
                        'whatif_enter_now_status' => (string)($monitor['whatif_enter_now_status'] ?? ''),
                        'whatif_wider_zone_status' => (string)($monitor['whatif_wider_zone_status'] ?? ''),
                    ];
                }
                $this->addDebugLine($symbol, 'status=' . $status . ' pattern=' . $algo . ' reject=' . $rejectDetail);
                continue;
            }

            // Resolve explicit side — reject if unresolvable (no silent long fallback)
            $side = $this->resolveExplicitSide($monitor);
            if ($side === null) {
                $algo = (string)($monitor['pattern_algorithm'] ?? 'none');
                $this->rejectionCounters['rejected_side_unresolved']++;
                $this->perPatternRejections[$algo]['rejected_side_unresolved'] = ($this->perPatternRejections[$algo]['rejected_side_unresolved'] ?? 0) + 1;
                $this->addDebugLine($symbol, 'side unresolved (pattern=' . $algo . ', trend_bias=' . ($monitor['trend_bias'] ?? '') . ')');
                continue;
            }

            $this->addDebugLine($symbol, 'side=' . $side . ' from pattern ' . ($monitor['pattern_algorithm'] ?? 'none'));

            // Check if current price exists
            if (!isset($prices[$symbol]) || $prices[$symbol] <= 0.0) {
                $this->rejectionCounters['rejected_missing_price']++;
                $this->addDebugLine($symbol, 'missing current price');
                continue;
            }

            // Enforce max active tasks
            if (count($signals) >= $maxActiveTasks) {
                $this->addDebugLine($symbol, 'max_active_tasks=' . $maxActiveTasks . ' reached');
                continue;
            }

            // Load passport for symbol
            $passport = $this->state->readJson('storage/passports/' . $symbol . '.json', []);
            $tradesTotalRaw = $passport['trades_total'] ?? null;
            $tradesTotal = $tradesTotalRaw !== null ? (int)$tradesTotalRaw : 0;
            $reliabilityScore = (float)($passport['reliability_score'] ?? 0.0);
            $isWarmup = ($tradesTotal < $warmupMinTrades);

            if ($isWarmup) {
                $this->signalModeCounters['warmup_symbols_count']++;
            }

            $analyzerScore = (float)($monitor['analyzer_score'] ?? 0.0);
            $volatility = (float)($monitor['volatility'] ?? 0.0);
            $corridorWidth = (float)($monitor['corridor_width'] ?? 0.0);

            // Determine signal mode: bootstrap or normal
            if (empty($passport) || $isWarmup) {
                // Bootstrap path
                if (!$bootstrapEnabled) {
                    if (empty($passport)) {
                        $this->rejectionCounters['rejected_missing_passport']++;
                        $this->addDebugLine($symbol, 'missing passport (bootstrap disabled)');
                    } else {
                        $this->rejectionCounters['rejected_low_reliability']++;
                        $this->addDebugLine($symbol, 'warmup (trades=' . $tradesTotal . ') but bootstrap disabled');
                    }
                    continue;
                }

                if ($bootstrapCount >= $bootstrapMaxSignals) {
                    $this->addDebugLine($symbol, 'bootstrap_max_signals=' . $bootstrapMaxSignals . ' reached');
                    continue;
                }

                // Dynamic Leverage V1 — bootstrap: capped at bootstrap_max_leverage
                $leverageResult = $this->computeDynamicLeverage(
                    $analyzerScore, $volatility, $corridorWidth,
                    $reliabilityScore, true, $bootstrapMaxLeverage, $maxLeverage
                );
                $leverage = $leverageResult['leverage'];
                $leverageReason = $leverageResult['reason'];

                // Manual leverage override
                if ($leverageMode === 'manual') {
                    $leverage = max(1, min($manualLeverage, $bootstrapMaxLeverage, $maxLeverage));
                    $leverageReason = 'manual=' . $leverage;
                }

                $budget = round($maxBudgetPerCoin * $bootstrapBudgetFactor, 2);
                $stopLoss = round($corridorWidth * $stopLossRange, 6);
                $takeProfit = round($corridorWidth * $takeProfitRoi, 6);

                $entryZoneLow = (float)($monitor['entry_zone_low'] ?? 0);
                $entryZoneHigh = (float)($monitor['entry_zone_high'] ?? 0);
                $entryPriceRef = ($entryZoneLow > 0 && $entryZoneHigh > 0)
                    ? round(($entryZoneLow + $entryZoneHigh) / 2, 8) : 0.0;
                $corridorLow = $monitor['corridor_low'] ?? null;
                $corridorHigh = $monitor['corridor_high'] ?? null;
                $patternAlgorithm = (string)($monitor['pattern_algorithm'] ?? 'none');
                $signalId = $this->buildSignalId($symbol, $side, $patternAlgorithm, 'bootstrap',
                    (string)$corridorLow, (string)$corridorHigh, (string)$entryZoneLow, (string)$entryZoneHigh);

                $signals[] = array_merge([
                    'id' => $signalId,
                    'schema_version' => 'clean_signal_v1',
                    'symbol' => $symbol,
                    'entry_zone_low' => $entryZoneLow > 0 ? $entryZoneLow : null,
                    'entry_zone_high' => $entryZoneHigh > 0 ? $entryZoneHigh : null,
                    'corridor_low' => $corridorLow,
                    'corridor_high' => $corridorHigh,
                    'corridor_width' => $corridorWidth,
                    'leverage' => $leverage,
                    'leverage_reason' => $leverageReason,
                    'budget' => $budget,
                    'stop_loss' => $stopLoss,
                    'take_profit_ratio' => $takeProfit,
                    'status' => 'waiting',
                    'signal_mode' => 'bootstrap',
                    'trend_bias' => (string)($monitor['trend_bias'] ?? ''),
                    'side' => $side,
                    'pattern_algorithm' => $patternAlgorithm,
                    'pattern_confidence' => (float)($monitor['pattern_confidence'] ?? 0.0),
                    'confirmation_score' => (float)($monitor['confirmation_score'] ?? 0.0),
                    'reclaim_strength_score' => (float)($monitor['reclaim_strength_score'] ?? 0.0),
                    'hold_quality_score' => (float)($monitor['hold_quality_score'] ?? 0.0),
                    'post_reclaim_stability_score' => (float)($monitor['post_reclaim_stability_score'] ?? 0.0),
                    'zone_defense_score' => (float)($monitor['zone_defense_score'] ?? 0.0),
                    'trend_match_score' => (float)($monitor['trend_match_score'] ?? 0.0),
                    'corridor_fit_score' => (float)($monitor['corridor_fit_score'] ?? 0.0),
                    'entry_quality_score' => (float)($monitor['entry_quality_score'] ?? 0.0),
                    'analyzer_score' => $analyzerScore,
                    'leverage_mode' => $leverageMode,
                    'stop_control_mode' => $stopControlMode,
                    'manual_stop_loss_roi' => $manualStopLossRoi,
                    'stop_loss_from_entry_roi' => (float)($userLimits['stop_loss_from_entry_roi'] ?? 0.10),
                    'risk' => [
                        'leverage' => $leverage,
                        'budget' => $budget,
                        'stop_loss' => $stopLoss,
                        // P0: Omit take_profit entirely when trailing covers exit.
                        // When trailing is NOT active, emit structured format or null — never scalar.
                        'trailing' => [
                            'enabled' => $exitPolicy['trailing_enabled'] ?? false,
                            'activation_roi_pct' => $exitPolicy['trailing_activation_roi'] ?? 0.03,
                            'min_lock_roi' => $exitPolicy['trailing_min_lock_roi'] ?? 0.008,
                            'min_step' => $exitPolicy['trailing_min_step'] ?? 0.005,
                        ],
                    ],
                    'entry' => [
                        'entry_zone_low' => $entryZoneLow > 0 ? $entryZoneLow : null,
                        'entry_zone_high' => $entryZoneHigh > 0 ? $entryZoneHigh : null,
                        'price' => $entryPriceRef,
                    ],
                    'created_ts' => time(),
                ], $exitPolicy);
                $bootstrapCount++;
                $this->signalModeCounters['bootstrap_signals_count']++;

            } else {
                // Normal path — require reliability gate
                if ($reliabilityScore < $minReliabilityAfterWarmup) {
                    $this->rejectionCounters['rejected_low_reliability']++;
                    $this->addDebugLine($symbol, 'reliability_score=' . number_format($reliabilityScore, 4) . ' below threshold ' . number_format($minReliabilityAfterWarmup, 2));
                    continue;
                }

                // Dynamic Leverage V1 — normal mode
                $leverageResult = $this->computeDynamicLeverage(
                    $analyzerScore, $volatility, $corridorWidth,
                    $reliabilityScore, false, $profileMaxLeverage, $maxLeverage
                );
                $leverage = $leverageResult['leverage'];
                $leverageReason = $leverageResult['reason'];

                // Manual leverage override
                if ($leverageMode === 'manual') {
                    $leverage = max(1, min($manualLeverage, $maxLeverage));
                    $leverageReason = 'manual=' . $leverage;
                }

                // Calculate budget: profile.budget × reliability_score, clamped ≤ max_budget_per_coin
                $budget = round($profileBudget * $reliabilityScore, 2);
                $budget = min($budget, $maxBudgetPerCoin);

                // Calculate stop_loss: corridor_width × stop_loss_range
                $stopLoss = round($corridorWidth * $stopLossRange, 6);

                // Calculate take_profit: corridor_width × take_profit_roi
                $takeProfit = round($corridorWidth * $takeProfitRoi, 6);

                $entryZoneLow = (float)($monitor['entry_zone_low'] ?? 0);
                $entryZoneHigh = (float)($monitor['entry_zone_high'] ?? 0);
                $entryPriceRef = ($entryZoneLow > 0 && $entryZoneHigh > 0)
                    ? round(($entryZoneLow + $entryZoneHigh) / 2, 8) : 0.0;
                $corridorLow = $monitor['corridor_low'] ?? null;
                $corridorHigh = $monitor['corridor_high'] ?? null;
                $patternAlgorithm = (string)($monitor['pattern_algorithm'] ?? 'none');
                $signalId = $this->buildSignalId($symbol, $side, $patternAlgorithm, 'normal',
                    (string)$corridorLow, (string)$corridorHigh, (string)$entryZoneLow, (string)$entryZoneHigh);

                $signals[] = array_merge([
                    'id' => $signalId,
                    'schema_version' => 'clean_signal_v1',
                    'symbol' => $symbol,
                    'entry_zone_low' => $entryZoneLow > 0 ? $entryZoneLow : null,
                    'entry_zone_high' => $entryZoneHigh > 0 ? $entryZoneHigh : null,
                    'corridor_low' => $corridorLow,
                    'corridor_high' => $corridorHigh,
                    'corridor_width' => $corridorWidth,
                    'leverage' => $leverage,
                    'leverage_reason' => $leverageReason,
                    'budget' => $budget,
                    'stop_loss' => $stopLoss,
                    'take_profit_ratio' => $takeProfit,
                    'status' => 'waiting',
                    'signal_mode' => 'normal',
                    'trend_bias' => (string)($monitor['trend_bias'] ?? ''),
                    'side' => $side,
                    'pattern_algorithm' => $patternAlgorithm,
                    'pattern_confidence' => (float)($monitor['pattern_confidence'] ?? 0.0),
                    'confirmation_score' => (float)($monitor['confirmation_score'] ?? 0.0),
                    'reclaim_strength_score' => (float)($monitor['reclaim_strength_score'] ?? 0.0),
                    'hold_quality_score' => (float)($monitor['hold_quality_score'] ?? 0.0),
                    'post_reclaim_stability_score' => (float)($monitor['post_reclaim_stability_score'] ?? 0.0),
                    'zone_defense_score' => (float)($monitor['zone_defense_score'] ?? 0.0),
                    'trend_match_score' => (float)($monitor['trend_match_score'] ?? 0.0),
                    'corridor_fit_score' => (float)($monitor['corridor_fit_score'] ?? 0.0),
                    'entry_quality_score' => (float)($monitor['entry_quality_score'] ?? 0.0),
                    'analyzer_score' => $analyzerScore,
                    'leverage_mode' => $leverageMode,
                    'stop_control_mode' => $stopControlMode,
                    'manual_stop_loss_roi' => $manualStopLossRoi,
                    'stop_loss_from_entry_roi' => (float)($userLimits['stop_loss_from_entry_roi'] ?? 0.10),
                    'risk' => [
                        'leverage' => $leverage,
                        'budget' => $budget,
                        'stop_loss' => $stopLoss,
                        // P0: Omit take_profit entirely when trailing covers exit.
                        // When trailing is NOT active, emit structured format or null — never scalar.
                        'trailing' => [
                            'enabled' => $exitPolicy['trailing_enabled'] ?? false,
                            'activation_roi_pct' => $exitPolicy['trailing_activation_roi'] ?? 0.03,
                            'min_lock_roi' => $exitPolicy['trailing_min_lock_roi'] ?? 0.008,
                            'min_step' => $exitPolicy['trailing_min_step'] ?? 0.005,
                        ],
                    ],
                    'entry' => [
                        'entry_zone_low' => $entryZoneLow > 0 ? $entryZoneLow : null,
                        'entry_zone_high' => $entryZoneHigh > 0 ? $entryZoneHigh : null,
                        'price' => $entryPriceRef,
                    ],
                    'created_ts' => time(),
                ], $exitPolicy);
                $this->signalModeCounters['normal_signals_count']++;
            }
        }

        return $signals;
    }

    /**
     * Get rejection counters from the last apply() call.
     *
     * @return array<string,int>
     */
    public function getRejectionCounters(): array
    {
        return $this->rejectionCounters;
    }

    /**
     * Get debug rejection lines from the last apply() call.
     *
     * @return array<int,string>
     */
    public function getDebugLines(): array
    {
        return $this->debugLines;
    }

    /**
     * Get signal mode counters from the last apply() call.
     *
     * @return array<string,int>
     */
    public function getSignalModeCounters(): array
    {
        return $this->signalModeCounters;
    }

    /**
     * Get per-pattern rejection counters from the last apply() call.
     * @return array<string,array<string,int>>
     */
    public function getPerPatternRejections(): array
    {
        return $this->perPatternRejections;
    }

    /**
     * Get preview of first N failed monitors from the last apply() call.
     * @return array<int,array<string,mixed>>
     */
    public function getFailedMonitorPreview(): array
    {
        return $this->failedMonitorPreview;
    }

    /**
     * Record a debug rejection line (max 200).
     */
    private function addDebugLine(string $symbol, string $reason): void
    {
        if (count($this->debugLines) < 200) {
            $this->debugLines[] = $symbol . ' rejected: ' . $reason;
        }
    }

    /**
     * Build a deterministic signal ID from stable signal fields.
     *
     * @return string Deterministic signal ID (prefixed with 'sig_')
     */
    private function buildSignalId(
        string $symbol,
        string $side,
        string $patternAlgorithm,
        string $signalMode,
        string $corridorLow,
        string $corridorHigh,
        string $entryZoneLow,
        string $entryZoneHigh
    ): string {
        $hashInput = implode('|', [
            $symbol, $side, $patternAlgorithm, $signalMode,
            $corridorLow, $corridorHigh, $entryZoneLow, $entryZoneHigh,
        ]);
        return 'sig_' . substr(md5($hashInput), 0, 16);
    }

    /**
     * Resolve explicit trade side from monitor data.
     *
     * Priority:
     *   1. monitor['side'] (explicit from pattern detection)
     *   2. Fallback to trend_bias if side not set
     *
     * Returns null if side cannot be determined — caller must reject signal.
     * No silent default to 'long'.
     */
    private function resolveExplicitSide(array $monitor): ?string
    {
        // Priority 1: explicit side from pattern detection
        $side = (string)($monitor['side'] ?? '');
        if ($side === 'long' || $side === 'short') {
            return $side;
        }

        // Priority 2: derive from trend_bias (backward compat for older data)
        $trendBias = (string)($monitor['trend_bias'] ?? '');
        if ($trendBias === 'up') {
            return 'long';
        }
        if ($trendBias === 'down') {
            return 'short';
        }

        // Cannot determine side — reject
        return null;
    }

    // Dynamic Leverage V1 thresholds (configurable in future versions)
    // Fractional thresholds: 0.05 = 5% price std-dev over analysis window
    private const LEVERAGE_HIGH_VOLATILITY_THRESHOLD = 0.05;
    // Fractional thresholds: 0.10 = 10% corridor width (high/low spread)
    private const LEVERAGE_WIDE_CORRIDOR_THRESHOLD = 0.10;
    // Passport reliability score below which leverage is reduced by 1
    private const LEVERAGE_WEAK_RELIABILITY_THRESHOLD = 0.30;

    /**
     * Dynamic Leverage V1 — compute leverage based on signal quality and risk context.
     *
     * Base leverage from analyzer_score ladder:
     *   score < 0.50     → 2x
     *   0.50 <= score < 0.65 → 3x
     *   0.65 <= score < 0.80 → 4x
     *   score >= 0.80    → 5x
     *
     * Adjustments:
     *   - high volatility (> 0.05)    → -1
     *   - wide corridor (> 0.10)      → -1
     *   - bootstrap mode              → clamp to bootstrap_max
     *   - weak reliability (< 0.30)   → -1
     *
     * Final: clamped to [1, max_leverage]
     *
     * @return array{leverage:int,reason:string}
     */
    private function computeDynamicLeverage(
        float $analyzerScore,
        float $volatility,
        float $corridorWidth,
        float $reliabilityScore,
        bool $isBootstrap,
        int $modeMaxLeverage,
        int $userMaxLeverage
    ): array {
        // Base leverage from analyzer_score ladder
        if ($analyzerScore >= 0.80) {
            $base = 5;
        } elseif ($analyzerScore >= 0.65) {
            $base = 4;
        } elseif ($analyzerScore >= 0.50) {
            $base = 3;
        } else {
            $base = 2;
        }

        $reasons = ['base=' . $base . '(score=' . number_format($analyzerScore, 2) . ')'];
        $leverage = $base;

        // Adjustment: high volatility reduces leverage
        if ($volatility > self::LEVERAGE_HIGH_VOLATILITY_THRESHOLD) {
            $leverage--;
            $reasons[] = 'high_volatility(-1)';
        }

        // Adjustment: wide corridor reduces leverage
        if ($corridorWidth > self::LEVERAGE_WIDE_CORRIDOR_THRESHOLD) {
            $leverage--;
            $reasons[] = 'wide_corridor(-1)';
        }

        // Adjustment: bootstrap mode caps leverage
        if ($isBootstrap) {
            $leverage = min($leverage, $modeMaxLeverage);
            $reasons[] = 'bootstrap(max=' . $modeMaxLeverage . ')';
        }

        // Adjustment: weak reliability reduces leverage
        if (!$isBootstrap && $reliabilityScore < self::LEVERAGE_WEAK_RELIABILITY_THRESHOLD) {
            $leverage--;
            $reasons[] = 'weak_reliability(-1)';
        }

        // Clamp to valid range
        $leverage = max(1, min($leverage, $modeMaxLeverage, $userMaxLeverage));

        return [
            'leverage' => $leverage,
            'reason' => implode(' + ', $reasons),
        ];
    }
}
