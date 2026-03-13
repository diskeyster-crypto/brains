<?php
declare(strict_types=1);

/**
 * Risk Engine — Smart Brain Phase 6
 *
 * Transforms monitored opportunities into trade-ready signals
 * with adaptive risk parameters based on passport data.
 *
 * Input:  monitors.json, passport data, profiles config
 * Output: storage/signals.json
 *
 * Algorithm per monitor:
 *   1. Load passport → skip if reliability_score < 0.15
 *   2. Only generate signals for monitors with status = entry_zone
 *   3. leverage  = reliability_score × profile.max_leverage  (clamped 1..max_leverage)
 *   4. budget    = profile.budget × reliability_score        (clamped ≤ max_budget_per_coin)
 *   5. stop_loss = corridor_width × profile.stop_loss_range
 *   6. take_profit = corridor_width × profile.take_profit_roi
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

    /**
     * Generate signals from monitors using passport-based risk parameters.
     *
     * @param array<int,array<string,mixed>> $monitors
     * @return array<int,array<string,mixed>>
     */
    public function apply(array $monitors): array
    {
        $profileKey = (string)($this->profiles['default_profile'] ?? '111');
        $profile = (array)($this->profiles['profiles'][$profileKey] ?? []);
        $maxBudgetPerCoin = (float)($this->cfg['max_budget_per_coin'] ?? 20.0);

        $profileBudget = (float)($profile['budget'] ?? 15.0);
        $profileMaxLeverage = (int)($profile['max_leverage'] ?? 5);
        $stopLossRange = (float)($profile['stop_loss_range'] ?? 0.20);
        $takeProfitRoi = (float)($profile['take_profit_roi'] ?? 5.55);

        $signals = [];

        foreach ($monitors as $monitor) {
            $symbol = (string)($monitor['symbol'] ?? '');
            $status = (string)($monitor['status'] ?? '');

            // Only generate signals for entry_zone monitors
            if ($status !== 'entry_zone') {
                continue;
            }

            // Load passport for symbol
            $passport = $this->state->readJson('storage/passports/' . $symbol . '.json', []);
            $reliabilityScore = (float)($passport['reliability_score'] ?? 0.0);

            // Skip if reliability too low
            if ($reliabilityScore < 0.15) {
                continue;
            }

            $corridorWidth = (float)($monitor['corridor_width'] ?? 0.0);

            // Calculate leverage: reliability_score × max_leverage, clamped [1, max_leverage]
            $leverage = (int)round($reliabilityScore * $profileMaxLeverage);
            $leverage = max(1, min($leverage, $profileMaxLeverage));

            // Calculate budget: profile.budget × reliability_score, clamped ≤ max_budget_per_coin
            $budget = round($profileBudget * $reliabilityScore, 2);
            $budget = min($budget, $maxBudgetPerCoin);

            // Calculate stop_loss: corridor_width × stop_loss_range
            $stopLoss = round($corridorWidth * $stopLossRange, 6);

            // Calculate take_profit: corridor_width × take_profit_roi
            $takeProfit = round($corridorWidth * $takeProfitRoi, 6);

            $signals[] = [
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
            ];
        }

        return $signals;
    }
}
