<?php
declare(strict_types=1);

namespace Modules\System\TradingBot\Lib;

/**
 * Bot Reversal Signal Helper
 *
 * Small static utility for the trend_reversal_soft_ladder_short overlay.
 *
 * Responsibilities:
 *   1. Identify whether a trade's source pattern qualifies for the overlay
 *      (short V2/V3 contextual patterns only).
 *   2. Optionally look up the Brain signals.json to detect a mirrored long
 *      reversal pattern (double_bottom_contextual_v2 or _v3) for the same
 *      symbol — stored as diagnostics only, NOT a hard activation requirement.
 *   3. Compute the overlay-locked ROI from peak ROI using the fixed test-mode
 *      constants (two-stage: stage1 at peak>=5, stage2 at peak>=10).
 *
 * SHORT-ONLY TEST MODE — Two-Stage Profit Protection (short_two_stage_peak_roi):
 *
 *   Stage 0 (peak < 5):
 *     No lock applied. No aggressive distance trailing. Normal exchange SL only.
 *
 *   Stage 1 (5 <= peak < 10):
 *     Non-burnable profit floor lock. Guaranteed locked ROI = STAGE1_FLOOR_LOCK_ROI (2).
 *     Only floor-lock stop is enforced. Distance-based trailing NOT active.
 *
 *   Stage 2 (peak >= 10):
 *     Existing soft ladder logic activates. Locked ROI follows the ladder formula.
 *     Distance trailing also becomes active for tighter protection.
 *
 *   Final locked ROI = max(prev_locked_roi, stage1_locked_roi, stage2_locked_roi)
 *   Protection is monotonic — locked ROI never decreases.
 *
 * TEST MODE CONSTANTS (not user-configurable in v1):
 *   Stage 1:
 *     - STAGE1_ACTIVATION_PEAK_ROI  = 5   — peak ROI threshold to arm stage 1
 *     - STAGE1_FLOOR_LOCK_ROI       = 2   — guaranteed minimum locked ROI at stage 1
 *   Stage 2:
 *     - OVERLAY_ACTIVATION_PEAK_ROI = 10  — peak ROI threshold to start ladder
 *     - OVERLAY_BASE_LOCK_ROI       = 5   — guaranteed ROI at stage 2 activation
 *     - OVERLAY_MAIN_STEP_ROI       = 3   — peak ROI increment per lock step
 *     - OVERLAY_LOCK_STEP_ROI       = 1   — locked ROI increment per step
 *
 * Stage 2 formula:
 *   if peak_roi < OVERLAY_ACTIVATION_PEAK_ROI:
 *     stage2_locked_roi = 0  (stage 2 dormant)
 *   else:
 *     steps = floor((peak_roi - OVERLAY_ACTIVATION_PEAK_ROI) / OVERLAY_MAIN_STEP_ROI)
 *     stage2_locked_roi = OVERLAY_BASE_LOCK_ROI + steps * OVERLAY_LOCK_STEP_ROI
 *
 * Stage 2 ladder examples:
 *   peak=10.0 → locked=5   (steps=0)
 *   peak=12.9 → locked=5   (steps=0, floor((12.9-10)/3)=0)
 *   peak=13.0 → locked=6   (steps=1)
 *   peak=15.9 → locked=6   (steps=1)
 *   peak=16.0 → locked=7   (steps=2)
 *   peak=19.0 → locked=8   (steps=3)
 */
class BotReversalSignalHelper
{
    // ------------------------------------------------------------------ //
    // TEST MODE CONSTANTS — Short V2/V3 Two-Stage Protection             //
    // ------------------------------------------------------------------ //

    // Stage 1 — Profit floor guarantee (non-burnable, no distance trailing)
    /** Peak ROI must reach this to activate Stage 1 floor lock. */
    const STAGE1_ACTIVATION_PEAK_ROI = 5.0;

    /** Guaranteed locked ROI once Stage 1 activation peak is reached. */
    const STAGE1_FLOOR_LOCK_ROI = 2.0;

    // Stage 2 — Soft ladder tightening (distance trailing also active)
    /** Peak ROI must reach this to activate Stage 2 (soft ladder). */
    const OVERLAY_ACTIVATION_PEAK_ROI = 10.0;

    /** Locked ROI guaranteed once Stage 2 activation peak is reached. */
    const OVERLAY_BASE_LOCK_ROI = 5.0;

    /** Each additional MAIN_STEP_ROI of peak ROI earns one lock step. */
    const OVERLAY_MAIN_STEP_ROI = 3.0;

    /** Each earned step increases locked ROI by this amount. */
    const OVERLAY_LOCK_STEP_ROI = 1.0;

    // ------------------------------------------------------------------ //
    // Allowed source / reversal pattern families                          //
    // ------------------------------------------------------------------ //

    /** Short patterns that qualify for this overlay (source of the trade). */
    const ALLOWED_SOURCE_PATTERNS = [
        'double_top_contextual_v2',
        'double_top_contextual_v3',
    ];

    /** Long patterns that count as a mirrored reversal trigger. */
    const ALLOWED_REVERSAL_PATTERNS = [
        'double_bottom_contextual_v2',
        'double_bottom_contextual_v3',
    ];

    // ------------------------------------------------------------------ //
    // Source pattern eligibility                                          //
    // ------------------------------------------------------------------ //

    /**
     * Return true if the trade's pattern_algorithm qualifies for the overlay.
     */
    public static function isEligibleSourcePattern(string $patternAlgorithm): bool
    {
        return in_array($patternAlgorithm, self::ALLOWED_SOURCE_PATTERNS, true);
    }

    // ------------------------------------------------------------------ //
    // Reversal signal lookup                                              //
    // ------------------------------------------------------------------ //

    /**
     * Optionally scan signals.json for a mirrored long reversal pattern on the same symbol.
     *
     * NOTE: In short-only test mode this result is stored for observability only.
     *       It does NOT gate overlay activation — activation is purely peak_roi based.
     *
     * @param string $symbol        Trading symbol (e.g. "BTCUSDT")
     * @param string $signalsPath   Absolute path to signals.json
     * @return array {
     *   found:   bool,
     *   pattern: string|null,   // matching pattern_algorithm or null
     *   reason:  string,        // diagnostic string
     * }
     */
    public static function findReversalSignal(string $symbol, string $signalsPath): array
    {
        if (!is_file($signalsPath)) {
            return ['found' => false, 'pattern' => null, 'reason' => 'signals_file_not_found'];
        }

        $content = @file_get_contents($signalsPath);
        if ($content === false) {
            return ['found' => false, 'pattern' => null, 'reason' => 'signals_file_unreadable'];
        }

        $data = @json_decode($content, true);
        if (!is_array($data)) {
            return ['found' => false, 'pattern' => null, 'reason' => 'signals_json_invalid'];
        }

        // Support both wrapped {"signals":[...]} and plain array formats
        if (isset($data['signals']) && is_array($data['signals'])) {
            $signals = $data['signals'];
        } else {
            $signals = $data;
        }

        foreach ($signals as $sig) {
            if (!is_array($sig)) {
                continue;
            }
            $sigSymbol  = (string)($sig['symbol'] ?? '');
            $sigPattern = (string)($sig['pattern_algorithm'] ?? '');
            $sigSide    = strtolower((string)($sig['side'] ?? ''));

            if ($sigSymbol !== $symbol) {
                continue;
            }
            if ($sigSide !== 'long') {
                continue;
            }
            if (!in_array($sigPattern, self::ALLOWED_REVERSAL_PATTERNS, true)) {
                continue;
            }

            return ['found' => true, 'pattern' => $sigPattern, 'reason' => 'reversal_signal_found'];
        }

        return ['found' => false, 'pattern' => null, 'reason' => 'no_matching_reversal_signal'];
    }

    // ------------------------------------------------------------------ //
    // Overlay lock ROI computation                                        //
    // ------------------------------------------------------------------ //

    /**
     * Compute the Stage 1 floor-lock ROI from a peak ROI value.
     *
     * Returns STAGE1_FLOOR_LOCK_ROI (2.0) when peak >= STAGE1_ACTIVATION_PEAK_ROI (5).
     * Returns 0.0 below that (Stage 0 — no lock yet).
     *
     * @param float $peakRoi Monotonic peak ROI seen so far (percent)
     * @return float Stage 1 locked ROI (percent). 0 if stage 1 not yet reached.
     */
    public static function computeStage1LockedRoi(float $peakRoi): float
    {
        return ($peakRoi >= self::STAGE1_ACTIVATION_PEAK_ROI) ? self::STAGE1_FLOOR_LOCK_ROI : 0.0;
    }

    /**
     * Compute the Stage 2 overlay-locked ROI from a peak ROI value.
     *
     * Returns 0.0 if peak is below OVERLAY_ACTIVATION_PEAK_ROI (10) — stage 2 dormant.
     * Result is non-negative and monotonically increases as peak grows.
     *
     * @param float $peakRoi Monotonic peak ROI seen so far (percent)
     * @return float Stage 2 locked ROI (percent). 0 if stage 2 dormant.
     */
    public static function computeOverlayLockedRoi(float $peakRoi): float
    {
        if ($peakRoi < self::OVERLAY_ACTIVATION_PEAK_ROI) {
            return 0.0;
        }
        $steps = (int)floor(
            ($peakRoi - self::OVERLAY_ACTIVATION_PEAK_ROI) / self::OVERLAY_MAIN_STEP_ROI
        );
        return self::OVERLAY_BASE_LOCK_ROI + (float)$steps * self::OVERLAY_LOCK_STEP_ROI;
    }

    /**
     * Compute next step target ROI (for diagnostics / UI display).
     *
     * Returns the ROI at which the next meaningful protection upgrade occurs:
     *   - Below stage 1: returns STAGE1_ACTIVATION_PEAK_ROI (5)
     *   - In stage 1:    returns OVERLAY_ACTIVATION_PEAK_ROI (10)
     *   - In stage 2:    returns the next ladder step peak
     *
     * @param float $peakRoi Current peak ROI
     * @return float ROI at which the next stage/step activates
     */
    public static function computeNextStepTargetRoi(float $peakRoi): float
    {
        if ($peakRoi < self::STAGE1_ACTIVATION_PEAK_ROI) {
            return self::STAGE1_ACTIVATION_PEAK_ROI;
        }
        if ($peakRoi < self::OVERLAY_ACTIVATION_PEAK_ROI) {
            return self::OVERLAY_ACTIVATION_PEAK_ROI;
        }
        $stepsDone = (int)floor(
            ($peakRoi - self::OVERLAY_ACTIVATION_PEAK_ROI) / self::OVERLAY_MAIN_STEP_ROI
        );
        return self::OVERLAY_ACTIVATION_PEAK_ROI + (float)($stepsDone + 1) * self::OVERLAY_MAIN_STEP_ROI;
    }
}
