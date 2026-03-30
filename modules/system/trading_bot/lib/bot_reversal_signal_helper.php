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
 *      constants (activation=10, base=5, main_step=3, lock_step=1).
 *
 * SHORT-ONLY TEST MODE (v2):
 *   Overlay activates purely by peak_roi >= OVERLAY_ACTIVATION_PEAK_ROI for
 *   eligible short V2/V3 trades. No long reversal signal is required.
 *   findReversalSignal() is retained for optional observability only.
 *
 * TEST MODE CONSTANTS (not user-configurable in v1):
 *   - OVERLAY_ACTIVATION_PEAK_ROI = 10  — peak ROI threshold to start locking
 *   - OVERLAY_BASE_LOCK_ROI       = 5   — guaranteed ROI at activation
 *   - OVERLAY_MAIN_STEP_ROI       = 3   — peak ROI increment per lock step
 *   - OVERLAY_LOCK_STEP_ROI       = 1   — locked ROI increment per step
 *
 * Formula:
 *   if peak_roi < OVERLAY_ACTIVATION_PEAK_ROI:
 *     overlay_locked_roi = 0  (overlay dormant)
 *   else:
 *     steps = floor((peak_roi - OVERLAY_ACTIVATION_PEAK_ROI) / OVERLAY_MAIN_STEP_ROI)
 *     overlay_locked_roi = OVERLAY_BASE_LOCK_ROI + steps * OVERLAY_LOCK_STEP_ROI
 *
 * Ladder examples:
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
    // TEST MODE CONSTANTS — Short V2/V3 Soft Ladder                      //
    // ------------------------------------------------------------------ //

    /** Peak ROI must reach this to activate overlay lock. */
    const OVERLAY_ACTIVATION_PEAK_ROI = 10.0;

    /** Locked ROI guaranteed once activation peak is reached. */
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
     * Compute the overlay-guaranteed locked ROI from a peak ROI value.
     *
     * Returns 0.0 if peak is below activation threshold (overlay dormant).
     * Result is non-negative and monotonically increases as peak grows.
     *
     * @param float $peakRoi Monotonic peak ROI seen so far (percent)
     * @return float Overlay locked ROI (percent). 0 if overlay dormant.
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
     * @param float $peakRoi Current peak ROI
     * @return float ROI at which the next lock step is earned
     */
    public static function computeNextStepTargetRoi(float $peakRoi): float
    {
        if ($peakRoi < self::OVERLAY_ACTIVATION_PEAK_ROI) {
            return self::OVERLAY_ACTIVATION_PEAK_ROI;
        }
        $stepsDone = (int)floor(
            ($peakRoi - self::OVERLAY_ACTIVATION_PEAK_ROI) / self::OVERLAY_MAIN_STEP_ROI
        );
        return self::OVERLAY_ACTIVATION_PEAK_ROI + (float)($stepsDone + 1) * self::OVERLAY_MAIN_STEP_ROI;
    }
}
