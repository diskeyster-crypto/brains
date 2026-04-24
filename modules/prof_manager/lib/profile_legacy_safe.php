<?php

declare(strict_types=1);

namespace Modules\ProfManager\Lib;

/**
 * ProfileLegacySafe
 *
 * Baseline profile based on the legacy PM step-trailing logic.
 *
 * Lifecycle per position:
 *   ROI < init_roi                         → do nothing
 *   init_roi <= ROI < activation_roi       → initialize tracking, observe only
 *   ROI >= activation_roi (via peak_roi)   → run ProfitLockPlanner, update state
 *
 * All planned actions are paper-only (written to locks.json, never executed on exchange).
 */
class ProfileLegacySafe
{
    private ProfitLockPlanner $planner;
    private RiskMath $riskMath;

    public function __construct(ProfitLockPlanner $planner, RiskMath $riskMath)
    {
        $this->planner  = $planner;
        $this->riskMath = $riskMath;
    }

    /**
     * Run the profile for a single validated position.
     *
     * @param array $position       Validated position (side normalized)
     * @param array $lockState      Existing lock record for this position key (may be empty)
     * @param array $positionState  Existing tracking state for this position key (may be empty)
     * @param array $profileConfig  Profile config slice
     * @param int   $nowTs          Current unix timestamp
     * @return array                Updated state: {plan, position_state, lock_state}
     */
    public function run(
        array $position,
        array $lockState,
        array $positionState,
        array $profileConfig,
        int $nowTs
    ): array {
        $symbol = $position['symbol'] ?? '';
        $side   = strtolower(trim($position['side'] ?? ''));

        // ── ROI for tracking ──────────────────────────────────────────────────
        $currentRoi = $this->riskMath->calculateRoiPct($position);

        $initRoi = (float) ($profileConfig['init_roi'] ?? 2.0);

        // Below init_roi — do nothing, preserve existing state
        if ($currentRoi === null || $currentRoi < $initRoi) {
            return [
                'plan'           => [
                    'action'      => 'skip',
                    'symbol'      => $symbol,
                    'side'        => $side,
                    'skip_reason' => $currentRoi === null ? 'cannot_calculate_roi' : 'below_init_roi',
                    'current_roi' => $currentRoi,
                    'peak_roi'    => null,
                ],
                'position_state' => $positionState,
                'lock_state'     => $lockState,
            ];
        }

        // ── Initialize / update tracking state ────────────────────────────────
        $peakRoi = (float) ($positionState['peak_roi'] ?? $currentRoi);
        if ($currentRoi > $peakRoi) {
            $peakRoi = $currentRoi;
        }

        $entryPrice = (float) ($position['entry_price'] ?? $position['avg_price'] ?? 0.0);

        $positionState = array_merge($positionState, [
            'symbol'       => $symbol,
            'side'         => $side,
            'entry_price'  => $entryPrice,
            'leverage'     => (float) ($position['leverage'] ?? 0.0),
            'current_roi'  => $currentRoi,
            'peak_roi'     => $peakRoi,
            'updated_at'   => date('c', $nowTs),
            'updated_at_ts'=> $nowTs,
        ]);

        if (!isset($positionState['tracked_since'])) {
            $positionState['tracked_since']    = date('c', $nowTs);
            $positionState['tracked_since_ts'] = $nowTs;
        }

        // ── Observe only below activation ─────────────────────────────────────
        $activationRoi = (float) ($profileConfig['activation_roi'] ?? 10.0);
        if ($peakRoi < $activationRoi) {
            return [
                'plan' => [
                    'action'      => 'skip',
                    'symbol'      => $symbol,
                    'side'        => $side,
                    'skip_reason' => 'below_activation_roi',
                    'current_roi' => $currentRoi,
                    'peak_roi'    => $peakRoi,
                ],
                'position_state' => $positionState,
                'lock_state'     => $lockState,
            ];
        }

        // ── Plan profit lock ──────────────────────────────────────────────────
        $plan = $this->planner->plan($position, $lockState, $positionState, $profileConfig, $nowTs);

        // ── Update lock state when a real action was planned ──────────────────
        if (in_array($plan['action'], ['would_set_profit_lock', 'would_move_profit_lock'], true)) {
            $lockState = [
                'symbol'        => $symbol,
                'side'          => $side,
                'lock_price'    => $plan['proposed_lock'],
                'lock_roi'      => $plan['proposed_roi'],
                'action'        => $plan['action'],
                'updated_at'    => date('c', $nowTs),
                'updated_at_ts' => $nowTs,
            ];
        }

        return [
            'plan'           => $plan,
            'position_state' => $positionState,
            'lock_state'     => $lockState,
        ];
    }
}
