<?php

declare(strict_types=1);

namespace Modules\ProfManager\Profiles\Long;

use Modules\ProfManager\Lib\RiskMath;
use Modules\ProfManager\Lib\ProfitLockPlanner;

/**
 * LongProfile
 *
 * Baseline long-position profit management — legacy_safe_long logic.
 *
 * Lifecycle per position:
 *   ROI < init_roi                         → skip (below_init_roi)
 *   init_roi <= ROI < activation_roi       → track peak, observe only (below_activation_roi)
 *   ROI >= activation_roi (via peak_roi)   → run step-trailing lock planner
 *
 * Hybrid Long overlay (when hybrid_enabled = true):
 *   After legacy lock logic, applies pattern-based exit confirmation layer:
 *     idle               → on pattern detected: waiting_confirmation + guard stop
 *     waiting_confirmation → confirm or reject; on reject: breathing trailing recovery
 *   Pattern detection is a stub (always returns detected=false at this stage).
 *
 * Reads and writes own state to profiles/long/storage/.
 * Demo only — no exchange actions.
 */
class LongProfile
{
    private string $storageDir;
    private array  $config;
    private RiskMath $riskMath;
    private ProfitLockPlanner $planner;

    public function __construct(string $profileDir, array $configOverrides = [])
    {
        $this->storageDir = rtrim($profileDir, '/') . '/storage';
        $this->config     = $this->loadConfig($profileDir, $configOverrides);
        $this->riskMath   = new RiskMath();
        $this->planner    = new ProfitLockPlanner($this->riskMath);
        $this->ensureStorage();
    }

    /**
     * Process a single long position.
     *
     * @param array $position Normalized position (side = 'long')
     * @param int   $nowTs    Current unix timestamp
     * @return array {action, skip_reason, roi, peak_roi, lock_price, lock_active, profile_used, notes,
     *               hybrid_state, hybrid_pattern_detected, hybrid_confirmation_ticks,
     *               hybrid_guard_stop, hybrid_guard_active, hybrid_breathing_stop, hybrid_breathing_active}
     */
    public function process(array $position, int $nowTs): array
    {
        $symbol = (string) ($position['symbol'] ?? '');
        $key    = $this->positionKey($symbol, 'long');

        $positionsState = $this->readState();
        $locks          = $this->readLocks();

        $positionState = $positionsState[$key] ?? [];
        $lockState     = $locks[$key]          ?? [];

        // ── STEP 1: run legacy_safe_long lifecycle ────────────────────────────
        $runResult = $this->runLifecycle($position, $lockState, $positionState, $this->config, $nowTs);

        $positionState = $runResult['position_state'];
        $lockState     = $runResult['lock_state'];
        $plan          = $runResult['plan'];

        // Update lock entry for legacy lock actions
        if (in_array($plan['action'], ['would_set_profit_lock', 'would_move_profit_lock'], true)) {
            $locks[$key] = [
                'symbol'        => $symbol,
                'side'          => 'long',
                'lock_price'    => $plan['proposed_lock'],
                'lock_roi'      => $plan['proposed_roi'],
                'action'        => $plan['action'],
                'updated_at'    => date('c', $nowTs),
                'updated_at_ts' => $nowTs,
            ];
        } elseif (!empty($lockState)) {
            $locks[$key] = $lockState;
        }

        // ── STEP 2–4: hybrid overlay ──────────────────────────────────────────
        $hybridMeta = [
            'hybrid_state'              => 'idle',
            'hybrid_pattern_detected'   => false,
            'hybrid_confirmation_ticks' => 0,
            'hybrid_guard_stop'         => null,
            'hybrid_guard_active'       => false,
            'hybrid_breathing_stop'     => null,
            'hybrid_breathing_active'   => false,
        ];

        if (!empty($this->config['hybrid_enabled'])) {
            [$plan, $positionState, $hybridMeta] = $this->applyHybridOverlay(
                $position,
                $positionState,
                $locks[$key] ?? [],
                $plan,
                $nowTs
            );
        }

        // Persist updated state (includes hybrid fields)
        $positionsState[$key] = $positionState;
        $this->writeState($positionsState);
        $this->writeLocks($locks);

        $lockRecord = $locks[$key] ?? [];
        $lockPrice  = isset($lockRecord['lock_price']) ? (float) $lockRecord['lock_price'] : null;

        return [
            'action'                    => $plan['action']               ?? 'skip',
            'skip_reason'               => $plan['skip_reason']          ?? null,
            'roi'                       => $plan['current_roi']          ?? null,
            'peak_roi'                  => $plan['peak_roi']             ?? null,
            'lock_price'                => ($lockPrice !== null && $lockPrice > 0.0) ? $lockPrice : null,
            'lock_active'               => ($lockPrice !== null && $lockPrice > 0.0),
            'profile_used'              => 'legacy_safe_long',
            'notes'                     => !empty($plan['note']) ? [$plan['note']] : [],
            'init_roi'                  => (float) ($this->config['init_roi']       ?? 2.0),
            'activation_roi'            => (float) ($this->config['activation_roi'] ?? 10.0),
            'distance_pct'              => $plan['distance_pct']               ?? null,
            'min_required_distance_pct' => $plan['min_required_distance_pct']  ?? null,
            'hybrid_state'              => $hybridMeta['hybrid_state'],
            'hybrid_pattern_detected'   => $hybridMeta['hybrid_pattern_detected'],
            'hybrid_confirmation_ticks' => $hybridMeta['hybrid_confirmation_ticks'],
            'hybrid_guard_stop'         => $hybridMeta['hybrid_guard_stop'],
            'hybrid_guard_active'       => $hybridMeta['hybrid_guard_active'],
            'hybrid_breathing_stop'     => $hybridMeta['hybrid_breathing_stop'],
            'hybrid_breathing_active'   => $hybridMeta['hybrid_breathing_active'],
        ];
    }

    /**
     * Return the count of active lock entries in this profile's storage.
     */
    public function getLockCount(): int
    {
        $locks = $this->readLocks();
        return count($locks);
    }

    /**
     * Remove state and lock entries for symbols no longer in the active position list.
     *
     * Called by the service router after routing all positions in a tick.
     * Only cleans profiles/long/storage/{state,locks}.json — historical logs are untouched.
     *
     * @param list<string> $activeKeys  Position keys (symbol_long) currently active this tick
     * @return array{long_state_cleaned: int, long_locks_cleaned: int}
     */
    public function cleanStale(array $activeKeys): array
    {
        $statesCleaned = 0;
        $locksCleaned  = 0;

        $state = $this->readState();
        foreach (array_keys($state) as $key) {
            if (!in_array($key, $activeKeys, true)) {
                unset($state[$key]);
                $statesCleaned++;
            }
        }

        $locks = $this->readLocks();
        foreach (array_keys($locks) as $key) {
            if (!in_array($key, $activeKeys, true)) {
                unset($locks[$key]);
                $locksCleaned++;
            }
        }

        if ($statesCleaned > 0) {
            $this->writeState($state);
        }
        if ($locksCleaned > 0) {
            $this->writeLocks($locks);
        }

        return [
            'long_state_cleaned' => $statesCleaned,
            'long_locks_cleaned' => $locksCleaned,
        ];
    }

    // =========================================================================
    // Lifecycle logic (migrated from ProfileLegacySafe)
    // =========================================================================

    private function runLifecycle(
        array $position,
        array $lockState,
        array $positionState,
        array $profileConfig,
        int   $nowTs
    ): array {
        $symbol = $position['symbol'] ?? '';
        $side   = strtolower(trim($position['side'] ?? ''));

        // ── ROI gate ──────────────────────────────────────────────────────────
        $currentRoi = $this->riskMath->calculateRoiPct($position);
        $initRoi    = (float) ($profileConfig['init_roi'] ?? 2.0);

        if ($currentRoi === null || $currentRoi < $initRoi) {
            return [
                'plan' => [
                    'action'       => 'skip',
                    'symbol'       => $symbol,
                    'side'         => $side,
                    'skip_reason'  => $currentRoi === null ? 'cannot_calculate_roi' : 'below_init_roi',
                    'current_roi'  => $currentRoi,
                    'peak_roi'     => null,
                    'proposed_lock'=> null,
                    'proposed_roi' => null,
                    'note'         => null,
                ],
                'position_state' => $positionState,
                'lock_state'     => $lockState,
            ];
        }

        // ── Peak ROI tracking ─────────────────────────────────────────────────
        $peakRoi = (float) ($positionState['peak_roi'] ?? $currentRoi);
        if ($currentRoi > $peakRoi) {
            $peakRoi = $currentRoi;
        }

        $entryPrice    = (float) ($position['entry_price'] ?? $position['avg_price'] ?? 0.0);
        $positionState = array_merge($positionState, [
            'symbol'        => $symbol,
            'side'          => $side,
            'entry_price'   => $entryPrice,
            'leverage'      => (float) ($position['leverage'] ?? 0.0),
            'current_roi'   => $currentRoi,
            'peak_roi'      => $peakRoi,
            'updated_at'    => date('c', $nowTs),
            'updated_at_ts' => $nowTs,
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
                    'action'       => 'skip',
                    'symbol'       => $symbol,
                    'side'         => $side,
                    'skip_reason'  => 'below_activation_roi',
                    'current_roi'  => $currentRoi,
                    'peak_roi'     => $peakRoi,
                    'proposed_lock'=> null,
                    'proposed_roi' => null,
                    'note'         => null,
                ],
                'position_state' => $positionState,
                'lock_state'     => $lockState,
            ];
        }

        // ── Plan profit lock via ProfitLockPlanner ────────────────────────────
        $plan = $this->planner->plan($position, $lockState, $positionState, $profileConfig, $nowTs);

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

    // =========================================================================
    // Hybrid Long overlay
    // =========================================================================

    /**
     * Pattern detection placeholder.
     *
     * Returns a stub result — always detected=false at this stage.
     * Structure is fixed so real detection can be plugged in later.
     *
     * @param array $position     Normalized position data
     * @param array $positionState Current per-symbol state
     * @return array{detected: bool, pattern_type: string|null, confidence: float}
     */
    private function detectExitPattern(array $position, array $positionState): array
    {
        return [
            'detected'     => false,
            'pattern_type' => null,
            'confidence'   => 0.0,
        ];
    }

    /**
     * Apply hybrid overlay on top of the legacy plan.
     *
     * STEP 2: On fresh pattern detection (hybrid_state=idle) → activate guard stop.
     * STEP 3: In waiting_confirmation → increment tick counter; confirm or reject.
     * STEP 4: On rejection → restore breathing trailing (respecting legacy lock floor).
     *
     * @param array $position      Normalized position
     * @param array $positionState Per-symbol mutable state (will be updated with hybrid fields)
     * @param array $lockState     Current lock record for this symbol
     * @param array $plan          Legacy plan (may have action overridden)
     * @param int   $nowTs         Current unix timestamp
     * @return array{0: array, 1: array, 2: array} [$plan, $positionState, $hybridMeta]
     */
    private function applyHybridOverlay(
        array $position,
        array $positionState,
        array $lockState,
        array $plan,
        int   $nowTs
    ): array {
        $currentPrice = (float) ($position['current_price'] ?? $position['mark_price'] ?? 0.0);
        $leverage     = (float) ($position['leverage'] ?? 0.0);

        $hybridState        = (string)  ($positionState['hybrid_state']         ?? 'idle');
        $patternDetectedAt  = isset($positionState['pattern_detected_at'])
            ? (int) $positionState['pattern_detected_at']
            : null;
        $confirmationTicks  = (int)    ($positionState['confirmation_ticks']    ?? 0);
        $guardStopPrice     = isset($positionState['guard_stop_price'])
            ? (float) $positionState['guard_stop_price']
            : null;
        $lastBreathingStop  = isset($positionState['last_breathing_stop'])
            ? (float) $positionState['last_breathing_stop']
            : null;

        $patternResult = $this->detectExitPattern($position, $positionState);
        $patternDetected = (bool) ($patternResult['detected'] ?? false);

        $hybridAction      = null;
        $guardActive       = false;
        $breathingActive   = false;
        $newBreathingStop  = null;

        // ── STEP 2: fresh pattern detection while idle ────────────────────────
        if ($patternDetected && $hybridState === 'idle') {
            $hybridState       = 'waiting_confirmation';
            $patternDetectedAt = $nowTs;
            $confirmationTicks = 0;

            $guardOffset = ($currentPrice > 0.0 && $leverage > 0.0)
                ? $this->riskMath->roiDistanceToPriceOffset(
                    $currentPrice,
                    (float) ($this->config['guard_roi_distance'] ?? 3.0),
                    $leverage
                )
                : null;

            $guardStopPrice = ($guardOffset !== null) ? $currentPrice - $guardOffset : null;
            $hybridAction   = 'hybrid_guard_activated';
        }

        // ── STEP 3: confirmation loop ─────────────────────────────────────────
        if ($hybridState === 'waiting_confirmation') {
            $confirmationTicks++;
            $guardActive = ($guardStopPrice !== null && $guardStopPrice > 0.0);

            $minTicks      = (int)   ($this->config['pattern_confirmation_min_ticks']  ?? 2);
            $windowSec     = (int)   ($this->config['pattern_confirmation_window_sec'] ?? 300);
            $windowExpired = ($patternDetectedAt !== null)
                && (($nowTs - $patternDetectedAt) > $windowSec);
            $confirmed     = ($confirmationTicks >= $minTicks) && !$windowExpired;

            if ($confirmationTicks < $minTicks && !$windowExpired) {
                // Still gathering ticks — hold
                $hybridAction = 'waiting_confirmation';
            } elseif ($confirmed) {
                // Pattern confirmed — plan close (no execution yet)
                $hybridAction      = 'hybrid_close_confirmed';
                $hybridState       = 'idle';
                $confirmationTicks = 0;
                $guardStopPrice    = null;
            } else {
                // ── STEP 4: rejection recovery ────────────────────────────────
                $hybridAction = 'hybrid_rejected';
                $hybridState  = 'idle';
                $guardStopPrice = null;

                // Breathing trailing: stop = current - (breathing_distance / leverage)
                $breathingRoiDist = (float) ($this->config['breathing_roi_distance_min'] ?? 5.0);
                $breathingOffset  = ($currentPrice > 0.0 && $leverage > 0.0)
                    ? $this->riskMath->roiDistanceToPriceOffset($currentPrice, $breathingRoiDist, $leverage)
                    : null;
                $rawBreathingStop = ($breathingOffset !== null) ? $currentPrice - $breathingOffset : null;

                // Respect legacy lock — final stop = max(legacy_lock, breathing_stop)
                $legacyLockPrice = isset($lockState['lock_price']) ? (float) $lockState['lock_price'] : 0.0;
                if ($rawBreathingStop !== null) {
                    $newBreathingStop = ($legacyLockPrice > 0.0)
                        ? max($legacyLockPrice, $rawBreathingStop)
                        : $rawBreathingStop;
                    $breathingActive  = ($newBreathingStop > 0.0);
                    $lastBreathingStop = $newBreathingStop;
                }
            }
        }

        // ── Persist hybrid fields back into positionState ─────────────────────
        $positionState['hybrid_state']        = $hybridState;
        $positionState['pattern_detected_at'] = $patternDetectedAt;
        $positionState['confirmation_ticks']  = $confirmationTicks;
        $positionState['guard_stop_price']    = $guardStopPrice;
        $positionState['last_breathing_stop'] = $lastBreathingStop;

        // ── Override plan action if hybrid produced one ───────────────────────
        if ($hybridAction !== null) {
            $plan['action']      = $hybridAction;
            $plan['skip_reason'] = null;
        }

        $hybridMeta = [
            'hybrid_state'              => $hybridState,
            'hybrid_pattern_detected'   => $patternDetected,
            'hybrid_confirmation_ticks' => $confirmationTicks,
            'hybrid_guard_stop'         => ($guardStopPrice !== null && $guardStopPrice > 0.0) ? $guardStopPrice : null,
            'hybrid_guard_active'       => $guardActive,
            'hybrid_breathing_stop'     => ($newBreathingStop !== null && $newBreathingStop > 0.0) ? $newBreathingStop : null,
            'hybrid_breathing_active'   => $breathingActive,
        ];

        return [$plan, $positionState, $hybridMeta];
    }

    // =========================================================================
    // Config
    // =========================================================================

    private function loadConfig(string $profileDir, array $overrides): array
    {
        $configPath = rtrim($profileDir, '/') . '/config.php';
        $base = [];
        if (is_file($configPath)) {
            try {
                $loaded = require $configPath;
                if (is_array($loaded)) {
                    $base = $loaded;
                }
            } catch (\Throwable) {
                // Config file parse errors are non-fatal; profile falls back to defaults
            }
        }
        return array_merge($base, $overrides);
    }

    // =========================================================================
    // Storage
    // =========================================================================

    private function ensureStorage(): void
    {
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0775, true);
        }
        foreach (['state.json', 'locks.json', 'patterns.json'] as $file) {
            $path = $this->storageDir . '/' . $file;
            if (!file_exists($path)) {
                file_put_contents($path, "{}\n", LOCK_EX);
            }
        }
    }

    private function readState(): array
    {
        return $this->readJson($this->storageDir . '/state.json');
    }

    private function writeState(array $data): void
    {
        $this->writeJson($this->storageDir . '/state.json', $data);
    }

    private function readLocks(): array
    {
        return $this->readJson($this->storageDir . '/locks.json');
    }

    private function writeLocks(array $data): void
    {
        $this->writeJson($this->storageDir . '/locks.json', $data);
    }

    private function readJson(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeJson(string $path, array $data): void
    {
        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            LOCK_EX
        );
    }

    private function positionKey(string $symbol, string $side): string
    {
        return strtolower($symbol) . '_' . strtolower($side);
    }
}
