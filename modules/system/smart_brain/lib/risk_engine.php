<?php
declare(strict_types=1);

/**
 * Risk Engine — Smart Brain Phase 6 + Stable Config Refactor
 *
 * Transforms monitored opportunities into trade-ready signals
 * with adaptive risk parameters based on passport data.
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
            'exit_mode'                  => (string)($userLimits['exit_mode'] ?? 'fixed_tp'),
            'stop_floor_type'            => (string)($userLimits['stop_floor_type'] ?? 'roi_percent'),
            'stop_floor_value'           => (float)($userLimits['stop_floor_value'] ?? 0.03),
            'trailing_enabled'           => (bool)($userLimits['trailing_enabled'] ?? false),
            'trailing_activation_roi'    => (float)($userLimits['trailing_activation_roi'] ?? 0.02),
            'trailing_min_lock_roi'      => (float)($userLimits['trailing_min_lock_roi'] ?? 0.005),
            'trailing_min_step'          => (float)($userLimits['trailing_min_step'] ?? 0.005),
            'fixed_take_profit_roi'      => (float)($userLimits['fixed_take_profit_roi'] ?? 0.05),
            'break_even_enabled'         => (bool)($userLimits['break_even_enabled'] ?? false),
            'break_even_activation_roi'  => (float)($userLimits['break_even_activation_roi'] ?? 0.01),
        ];

        $this->rejectionCounters = [
            'rejected_not_entry_zone' => 0,
            'rejected_low_reliability' => 0,
            'rejected_missing_passport' => 0,
            'rejected_missing_price' => 0,
        ];
        $this->debugLines = [];
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
                $this->addDebugLine($symbol, 'status=' . $status);
                continue;
            }

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

                $corridorWidth = (float)($monitor['corridor_width'] ?? 0.0);
                $leverage = min($bootstrapMaxLeverage, $maxLeverage);
                $budget = round($maxBudgetPerCoin * $bootstrapBudgetFactor, 2);
                $stopLoss = round($corridorWidth * $stopLossRange, 6);
                $takeProfit = round($corridorWidth * $takeProfitRoi, 6);

                $signals[] = array_merge([
                    'symbol' => $symbol,
                    'entry_zone_low' => $monitor['entry_zone_low'] ?? null,
                    'entry_zone_high' => $monitor['entry_zone_high'] ?? null,
                    'corridor_low' => $monitor['corridor_low'] ?? null,
                    'corridor_high' => $monitor['corridor_high'] ?? null,
                    'corridor_width' => $corridorWidth,
                    'leverage' => $leverage,
                    'budget' => $budget,
                    'stop_loss' => $stopLoss,
                    'take_profit' => $takeProfit,
                    'status' => 'waiting',
                    'signal_mode' => 'bootstrap',
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

                $corridorWidth = (float)($monitor['corridor_width'] ?? 0.0);

                // Calculate leverage: reliability_score × max_leverage, clamped [1, max_leverage]
                $leverage = (int)round($reliabilityScore * $profileMaxLeverage);
                $leverage = max(1, min($leverage, $profileMaxLeverage, $maxLeverage));

                // Calculate budget: profile.budget × reliability_score, clamped ≤ max_budget_per_coin
                $budget = round($profileBudget * $reliabilityScore, 2);
                $budget = min($budget, $maxBudgetPerCoin);

                // Calculate stop_loss: corridor_width × stop_loss_range
                $stopLoss = round($corridorWidth * $stopLossRange, 6);

                // Calculate take_profit: corridor_width × take_profit_roi
                $takeProfit = round($corridorWidth * $takeProfitRoi, 6);

                $signals[] = array_merge([
                    'symbol' => $symbol,
                    'entry_zone_low' => $monitor['entry_zone_low'] ?? null,
                    'entry_zone_high' => $monitor['entry_zone_high'] ?? null,
                    'corridor_low' => $monitor['corridor_low'] ?? null,
                    'corridor_high' => $monitor['corridor_high'] ?? null,
                    'corridor_width' => $corridorWidth,
                    'leverage' => $leverage,
                    'budget' => $budget,
                    'stop_loss' => $stopLoss,
                    'take_profit' => $takeProfit,
                    'status' => 'waiting',
                    'signal_mode' => 'normal',
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
     * Record a debug rejection line (max 200).
     */
    private function addDebugLine(string $symbol, string $reason): void
    {
        if (count($this->debugLines) < 200) {
            $this->debugLines[] = $symbol . ' rejected: ' . $reason;
        }
    }
}
