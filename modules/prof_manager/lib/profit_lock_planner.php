<?php

declare(strict_types=1);

namespace Modules\ProfManager\Lib;

/**
 * ProfitLockPlanner
 *
 * Decides WHAT the planned profit-lock price should be for a validated position,
 * given the current state and the profile config.
 *
 * Returns a structured plan — does NOT write anything.
 *
 * Plan actions:
 *   would_set_profit_lock    — first lock to be set
 *   would_move_profit_lock   — lock should be ratcheted up/down
 *   would_close_on_lock_touch— current price has crossed the lock (informational)
 *   skip                     — no action needed (with reason)
 */
class ProfitLockPlanner
{
    private RiskMath $riskMath;

    public function __construct(RiskMath $riskMath)
    {
        $this->riskMath = $riskMath;
    }

    /**
     * Plan a profit-lock action for a single position.
     *
     * @param array $position      Validated position array
     * @param array $lockState     Existing lock state for this key (from locks.json) — may be empty
     * @param array $positionState Existing tracking state for this key (from positions_state.json) — may be empty
     * @param array $profileConfig Active profile config slice
     * @param int   $nowTs         Current unix timestamp
     * @return array               Plan record
     */
    public function plan(
        array $position,
        array $lockState,
        array $positionState,
        array $profileConfig,
        int $nowTs
    ): array {
        $symbol     = $position['symbol'] ?? '';
        $side       = strtolower(trim($position['side'] ?? ''));
        $entryPrice = (float) ($position['entry_price'] ?? $position['avg_price'] ?? 0.0);
        $leverage   = (float) ($position['leverage'] ?? 0.0);

        // ── ROI ──────────────────────────────────────────────────────────────
        $currentRoi = $this->riskMath->calculateRoiPct($position);
        if ($currentRoi === null) {
            return $this->skip($symbol, $side, 'cannot_calculate_roi', $lockState, $positionState);
        }

        // ── Phase: init_roi gate ──────────────────────────────────────────────
        $initRoi = (float) ($profileConfig['init_roi'] ?? 2.0);
        if ($currentRoi < $initRoi) {
            return $this->skip($symbol, $side, 'below_init_roi', $lockState, $positionState, $currentRoi);
        }

        // ── Update peak ROI ───────────────────────────────────────────────────
        $peakRoi = (float) ($positionState['peak_roi'] ?? $currentRoi);
        if ($currentRoi > $peakRoi) {
            $peakRoi = $currentRoi;
        }

        // ── Phase: activation_roi gate ───────────────────────────────────────
        $activationRoi = (float) ($profileConfig['activation_roi'] ?? 10.0);
        if ($peakRoi < $activationRoi) {
            return $this->skip($symbol, $side, 'below_activation_roi', $lockState, $positionState, $currentRoi, $peakRoi);
        }

        // ── Early lock-touch check (BEFORE new lock calculation) ─────────────
        // If there is already an existing lock and the current price has crossed
        // it, report would_close_on_lock_touch immediately without computing a
        // new lock price.
        $oldLockPrice = (float) ($lockState['lock_price'] ?? 0.0);
        $currentPrice = (float) ($position['current_price'] ?? $position['mark_price'] ?? 0.0);
        if ($oldLockPrice > 0.0 && $currentPrice > 0.0) {
            $lockCrossed = false;
            if (($side === 'long' || $side === 'buy') && $currentPrice <= $oldLockPrice) {
                $lockCrossed = true;
            } elseif (($side === 'short' || $side === 'sell') && $currentPrice >= $oldLockPrice) {
                $lockCrossed = true;
            }
            if ($lockCrossed) {
                return [
                    'action'         => 'would_close_on_lock_touch',
                    'symbol'         => $symbol,
                    'side'           => $side,
                    'skip_reason'    => null,
                    'note'           => 'current_price_crossed_lock',
                    'current_roi'    => $currentRoi,
                    'peak_roi'       => $peakRoi,
                    'current_lock'   => $oldLockPrice,
                    'proposed_lock'  => null,
                    'proposed_roi'   => null,
                    'position_state' => $positionState,
                ];
            }
        }

        // ── Target lock ROI ───────────────────────────────────────────────────
        $targetLockRoi = $this->riskMath->calculateStepTrailingLockRoi($peakRoi, $profileConfig);
        if ($targetLockRoi === null) {
            return $this->skip($symbol, $side, 'no_target_lock_roi', $lockState, $positionState, $currentRoi, $peakRoi);
        }

        // ── Target lock price ─────────────────────────────────────────────────
        $tickSize        = (float) ($profileConfig['default_tick_size'] ?? 0.0001);
        $targetLockPrice = $this->riskMath->calculateProfitLockPrice($position, $targetLockRoi);
        if ($targetLockPrice === null || $targetLockPrice <= 0.0) {
            return $this->skip($symbol, $side, 'cannot_calculate_lock_price', $lockState, $positionState, $currentRoi, $peakRoi);
        }

        // Normalize to tick
        $targetLockPrice = $this->riskMath->roundToTick($targetLockPrice, $tickSize);

        // ── Profit-side check ─────────────────────────────────────────────────
        if (!$this->riskMath->isProfitSide($position, $targetLockPrice)) {
            return $this->skip($symbol, $side, 'lock_not_on_profit_side', $lockState, $positionState, $currentRoi, $peakRoi);
        }

        // ── Ratchet check ─────────────────────────────────────────────────────
        if (!$this->riskMath->isImprovingLock($position, $oldLockPrice, $targetLockPrice)) {
            return $this->skip($symbol, $side, 'lock_not_improving', $lockState, $positionState, $currentRoi, $peakRoi);
        }

        // ── Anti-spam: min_update_interval_sec ───────────────────────────────
        $minInterval    = (int) ($profileConfig['min_update_interval_sec'] ?? 30);
        $lastUpdateTs   = (int) ($lockState['updated_at_ts'] ?? 0);
        if ($oldLockPrice > 0.0 && ($nowTs - $lastUpdateTs) < $minInterval) {
            return $this->skip($symbol, $side, 'update_interval_not_elapsed', $lockState, $positionState, $currentRoi, $peakRoi);
        }

        // ── Anti-spam: min_roi_step ───────────────────────────────────────────
        $minRoiStep   = (float) ($profileConfig['min_roi_step'] ?? 1.0);
        $lastLockRoi  = (float) ($lockState['lock_roi'] ?? 0.0);
        if ($oldLockPrice > 0.0 && abs($targetLockRoi - $lastLockRoi) < $minRoiStep) {
            return $this->skip($symbol, $side, 'roi_step_too_small', $lockState, $positionState, $currentRoi, $peakRoi);
        }

        // ── Anti-spam: min_price_distance_pct ────────────────────────────────
        $minDistPct = (float) ($profileConfig['min_price_distance_pct'] ?? 0.15);
        if (!$this->riskMath->isSafeDistance($position, $targetLockPrice, $profileConfig)) {
            $currentPrice2 = (float) ($position['current_price'] ?? $position['mark_price'] ?? 0.0);
            $actualDistPct = null;
            if ($currentPrice2 > 0.0 && $targetLockPrice > 0.0) {
                if ($side === 'long' || $side === 'buy') {
                    $actualDistPct = round(($currentPrice2 - $targetLockPrice) / $currentPrice2 * 100.0, 4);
                } elseif ($side === 'short' || $side === 'sell') {
                    $actualDistPct = round(($targetLockPrice - $currentPrice2) / $currentPrice2 * 100.0, 4);
                }
            }
            return $this->skip($symbol, $side, 'lock_price_too_close_to_current', $lockState, $positionState, $currentRoi, $peakRoi, [
                'distance_pct'              => $actualDistPct,
                'min_required_distance_pct' => $minDistPct,
            ]);
        }

        // ── Plan the lock ─────────────────────────────────────────────────────
        $action = $oldLockPrice <= 0.0 ? 'would_set_profit_lock' : 'would_move_profit_lock';

        return $this->buildPlan($action, $symbol, $side, $targetLockPrice, $targetLockRoi, $oldLockPrice, $currentRoi, $peakRoi, null, $positionState);
    }

    // =========================================================================
    // Private builders
    // =========================================================================

    private function skip(
        string $symbol,
        string $side,
        string $reason,
        array $lockState,
        array $positionState,
        ?float $currentRoi = null,
        ?float $peakRoi    = null,
        array  $extra      = []
    ): array {
        return array_merge([
            'action'          => 'skip',
            'symbol'          => $symbol,
            'side'            => $side,
            'skip_reason'     => $reason,
            'current_roi'     => $currentRoi,
            'peak_roi'        => $peakRoi,
            'current_lock'    => $lockState['lock_price'] ?? null,
            'proposed_lock'   => null,
            'proposed_roi'    => null,
            'position_state'  => $positionState,
        ], $extra);
    }

    private function buildPlan(
        string $action,
        string $symbol,
        string $side,
        float  $targetLockPrice,
        float  $targetLockRoi,
        float  $oldLockPrice,
        float  $currentRoi,
        float  $peakRoi,
        ?string $note,
        array  $positionState
    ): array {
        return [
            'action'          => $action,
            'symbol'          => $symbol,
            'side'            => $side,
            'skip_reason'     => null,
            'note'            => $note,
            'current_roi'     => $currentRoi,
            'peak_roi'        => $peakRoi,
            'current_lock'    => $oldLockPrice > 0.0 ? $oldLockPrice : null,
            'proposed_lock'   => $targetLockPrice,
            'proposed_roi'    => $targetLockRoi,
            'position_state'  => $positionState,
        ];
    }
}
