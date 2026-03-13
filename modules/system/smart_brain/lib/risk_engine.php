<?php
declare(strict_types=1);

final class RiskEngine
{
    /** @var array<string,mixed> */
    private array $cfg;
    /** @var array<string,mixed> */
    private array $profiles;

    /**
     * @param array<string,mixed> $cfg
     * @param array<string,mixed> $profiles
     */
    public function __construct(array $cfg, array $profiles)
    {
        $this->cfg = $cfg;
        $this->profiles = $profiles;
    }

    /**
     * @param array<int,array<string,mixed>> $monitors
     * @return array<int,array<string,mixed>>
     */
    public function apply(array $monitors): array
    {
        $profileKey = (string)($this->profiles['default_profile'] ?? '111');
        $profile = $this->profiles['profiles'][$profileKey] ?? [];
        $maxBudget = (float)($this->cfg['max_budget_per_coin'] ?? 20.0);
        $globalMaxLeverage = (int)($this->cfg['max_leverage'] ?? 15);
        $rules = $this->cfg['risk_levels'] ?? [];

        $result = [];

        foreach ($monitors as $monitor) {
            $low = (float)($monitor['corridor_low'] ?? 0.0);
            $high = (float)($monitor['corridor_high'] ?? 0.0);
            $widthPct = $low > 0 ? (($high / $low) - 1.0) : 1.0;

            $leverage = 3;
            $budgetFactor = 0.4;

            foreach ($rules as $rule) {
                if ($widthPct <= (float)$rule['max_corridor_width']) {
                    $leverage = (int)$rule['leverage'];
                    $budgetFactor = (float)$rule['budget_factor'];
                    break;
                }
            }

            $profileBudget = (float)($profile['budget'] ?? 15.0);
            $profileMaxLev = (int)($profile['max_leverage'] ?? 5);
            $stopRange = (float)($profile['stop_loss_range'] ?? 0.20);

            $result[] = $monitor + [
                'risk_corridor_width_pct' => round($widthPct, 6),
                'leverage' => min($leverage, $globalMaxLeverage, $profileMaxLev),
                'budget' => round(min($profileBudget * $budgetFactor, $maxBudget), 2),
                'stop_loss_range' => $stopRange,
            ];
        }

        return $result;
    }
}
