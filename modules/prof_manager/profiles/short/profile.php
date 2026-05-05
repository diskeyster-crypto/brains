<?php

declare(strict_types=1);

namespace Modules\ProfManager\Profiles\Short;

use Modules\ProfManager\Lib\RiskMath;
use Modules\ProfManager\Lib\ProfitLockPlanner;

/**
 * ShortProfile — Baseline short-position profit management.
 *
 * Profile name: baseline_short_lock
 *
 * Lifecycle per position:
 *   ROI < init_roi                         → skip (below_init_roi)
 *   init_roi <= ROI < activation_roi       → track peak, observe only (below_activation_roi)
 *   ROI >= activation_roi (via peak_roi)   → run step-trailing lock planner
 *   current_price >= lock_price            → would_close_on_lock_touch
 *
 * Uses ProfitLockPlanner (which already supports short-side math via RiskMath).
 * No hybrid overlay — baseline step-lock only.
 *
 * Reads and writes own state to profiles/short/storage/.
 * Demo only — no exchange actions.
 */
class ShortProfile
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
     * Process a single short position.
     *
     * @param array $position Normalized position (side = 'short')
     * @param int   $nowTs    Current unix timestamp
     * @return array {action, skip_reason, roi, peak_roi, lock_price, lock_active,
     *               profile_used, notes, init_roi, activation_roi,
     *               distance_pct, min_required_distance_pct, side_supported}
     */
    public function process(array $position, int $nowTs, array $wallContext = []): array
    {
        $symbol = (string) ($position['symbol'] ?? '');
        $key    = $this->positionKey($symbol, 'short');

        $positionsState = $this->readState();
        $locks          = $this->readLocks();

        $positionState = $positionsState[$key] ?? [];
        $lockState     = $locks[$key]          ?? [];

        // ── Position identity validation ───────────────────────────────────────
        // Detect stale lock/position state that belongs to a previous position
        // on the same symbol+side.  If identity fields mismatch (or are absent
        // on a legacy record), discard the stored state so the new position
        // starts fresh and does not inherit an old lock_price.
        $identityMismatch  = false;
        $legacyKeyIgnored  = false;
        $curSignalId   = (string)($position['signal_id']   ?? '');
        $curOpenedAt   = (string)($position['opened_at']   ?? $position['bot_submitted_at'] ?? $position['created_at'] ?? '');
        $curEntryPrice = (float)($position['entry_price']  ?? $position['avg_price'] ?? 0.0);

        if (!empty($lockState)) {
            $hasIdentity = array_key_exists('position_signal_id',   $lockState)
                        || array_key_exists('position_opened_at',    $lockState)
                        || array_key_exists('position_entry_price',  $lockState);

            if (!$hasIdentity) {
                $legacyKeyIgnored = true;
                $lockState        = [];
                $positionState    = [];
            } else {
                $storedSigId      = (string)($lockState['position_signal_id']  ?? '');
                $storedOpenedAt   = (string)($lockState['position_opened_at']  ?? '');
                $storedEntryPrice = (float)($lockState['position_entry_price'] ?? 0.0);

                $mismatch = false;
                if ($storedSigId !== '' && $curSignalId !== '' && $storedSigId !== $curSignalId) {
                    $mismatch = true;
                } elseif ($storedOpenedAt !== '' && $curOpenedAt !== '' && $storedOpenedAt !== $curOpenedAt) {
                    $mismatch = true;
                } elseif ($storedEntryPrice > 0.0 && $curEntryPrice > 0.0) {
                    $tol = 0.001 * max($storedEntryPrice, $curEntryPrice);
                    if (abs($storedEntryPrice - $curEntryPrice) > $tol) {
                        $mismatch = true;
                    }
                }

                if ($mismatch) {
                    $identityMismatch = true;
                    $lockState        = [];
                    $positionState    = [];
                }
            }
        }

        $initRoi       = (float) ($this->config['init_roi']       ?? 2.0);
        $activationRoi = (float) ($this->config['activation_roi'] ?? 8.0);

        // ── STEP 1: ROI gate ──────────────────────────────────────────────────
        $currentRoi = $this->riskMath->calculateRoiPct($position);

        if ($currentRoi === null || $currentRoi < $initRoi) {
            // Persist state entry (unchanged) so symbol is tracked in storage
            $positionsState[$key] = $positionState;
            $this->writeState($positionsState);

            return [
                'action'                    => 'skip',
                'skip_reason'               => $currentRoi === null ? 'cannot_calculate_roi' : 'below_init_roi',
                'roi'                       => $currentRoi,
                'peak_roi'                  => $positionState['peak_roi'] ?? null,
                'lock_price'                => null,
                'lock_active'               => false,
                'profile_used'              => 'baseline_short_lock',
                'notes'                     => [],
                'init_roi'                  => $initRoi,
                'activation_roi'            => $activationRoi,
                'distance_pct'              => null,
                'min_required_distance_pct' => null,
                'side_supported'            => true,
            ];
        }

        // ── STEP 2: Peak ROI tracking ─────────────────────────────────────────
        $peakRoi = (float) ($positionState['peak_roi'] ?? $currentRoi);
        if ($currentRoi > $peakRoi) {
            $peakRoi = $currentRoi;
        }

        $entryPrice = (float) ($position['entry_price'] ?? $position['avg_price'] ?? 0.0);

        $positionState = array_merge($positionState, [
            'symbol'        => $symbol,
            'side'          => 'short',
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

        // ── STEP 3: Activation gate ───────────────────────────────────────────
        if ($peakRoi < $activationRoi) {
            $positionsState[$key] = $positionState;
            $this->writeState($positionsState);

            return [
                'action'                    => 'skip',
                'skip_reason'               => 'below_activation_roi',
                'roi'                       => $currentRoi,
                'peak_roi'                  => $peakRoi,
                'lock_price'                => null,
                'lock_active'               => false,
                'profile_used'              => 'baseline_short_lock',
                'notes'                     => [],
                'init_roi'                  => $initRoi,
                'activation_roi'            => $activationRoi,
                'distance_pct'              => null,
                'min_required_distance_pct' => null,
                'side_supported'            => true,
            ];
        }

        // ── STEP 4: Plan profit lock via ProfitLockPlanner ────────────────────
        // ProfitLockPlanner already handles short-side math (ROI, lock price,
        // profit-side check, ratchet, safe-distance, lock-touch detection).
        $plan = $this->planner->plan($position, $lockState, $positionState, $this->config, $nowTs);

        // Update lock entry for set/move actions
        if (in_array($plan['action'], ['would_set_profit_lock', 'would_move_profit_lock'], true)) {
            $locks[$key] = [
                'symbol'               => $symbol,
                'side'                 => 'short',
                'lock_price'           => $plan['proposed_lock'],
                'lock_roi'             => $plan['proposed_roi'],
                'action'               => $plan['action'],
                'updated_at'           => date('c', $nowTs),
                'updated_at_ts'        => $nowTs,
                // Position identity — used to detect stale state when the same
                // symbol+side is reused by a new, different position.
                'position_signal_id'   => $curSignalId,
                'position_opened_at'   => $curOpenedAt,
                'position_entry_price' => $curEntryPrice,
            ];
        } elseif (!empty($lockState)) {
            // Preserve existing lock but ensure identity fields are present.
            $lockState['position_signal_id']   = $curSignalId;
            $lockState['position_opened_at']   = $curOpenedAt;
            $lockState['position_entry_price'] = $curEntryPrice;
            $locks[$key] = $lockState;
        }

        $positionsState[$key] = $positionState;
        $this->writeState($positionsState);
        $this->writeLocks($locks);

        $lockRecord = $locks[$key] ?? [];
        $lockPrice  = isset($lockRecord['lock_price']) ? (float) $lockRecord['lock_price'] : null;

        // ── Wall exit check (short: bid wall below price = support) ───────────
        // Triggered only when:
        //   - wall context provided and wall_exit_enabled = true
        //   - current ROI >= wall_exit_min_roi
        //   - nearest bid wall is persistent (not eaten/broken)
        //   - bid wall distance_pct <= wall_exit_distance_pct
        //   - price has failed to break wall for >= wall_exit_fail_checks ticks
        // Skip if wall is eaten/broken (bearish continuation allowed).
        $wallExitChecked    = false;
        $wallExitTriggered  = false;
        $wallExitSkipped    = false;
        $wallExitSkipReason = null;
        $wallExitContext     = null;
        $wallExitCloseReason = null;
        $planAction          = $plan['action'] ?? 'skip';

        if (!empty($wallContext)) {
            $wallExitEnabled = !empty($this->config['wall_exit_enabled']);
            $wallExitMinRoi  = (float)($this->config['wall_exit_min_roi']      ?? 6.0);
            $wallExitDistPct = (float)($this->config['wall_exit_distance_pct'] ?? 0.6);
            $wallExitFail    = max(1, (int)($this->config['wall_exit_fail_checks'] ?? 2));

            $bidWall   = $wallContext['nearest_bid_wall'] ?? null;
            $bidStatus = (string)($wallContext['bid_wall_status']  ?? 'none');

            if ($wallExitEnabled && $currentRoi !== null && $currentRoi >= $wallExitMinRoi
                && $bidWall !== null && is_array($bidWall)
            ) {
                $bidDist = (float)($bidWall['distance_pct'] ?? 999.0);

                if ($bidDist <= $wallExitDistPct) {
                    $wallExitChecked = true;

                    if ($bidStatus === 'eaten' || $bidStatus === 'broken') {
                        // Wall being absorbed — bearish continuation possible, skip exit
                        $wallExitSkipped    = true;
                        $wallExitSkipReason = 'wall_exit_skipped_wall_eaten';
                        $wallExitContext     = $bidWall;
                        $positionState['wall_exit_fail_count'] = 0;
                    } elseif ($bidStatus === 'persistent') {
                        $failCount = (int)($positionState['wall_exit_fail_count'] ?? 0) + 1;
                        $positionState['wall_exit_fail_count'] = $failCount;

                        if ($failCount >= $wallExitFail) {
                            $wallExitTriggered   = true;
                            $wallExitContext      = $bidWall;
                            $wallExitCloseReason = 'bid_wall_rejection_profit_exit';
                            $planAction          = 'wall_exit_close';
                        }
                    } else {
                        $positionState['wall_exit_fail_count'] = 0;
                    }
                } else {
                    $positionState['wall_exit_fail_count'] = 0;
                }
            }
        }

        // Re-persist state with any wall exit counter updates
        $positionsState[$key] = $positionState;
        $this->writeState($positionsState);

        return [
            'action'                     => $planAction,
            'skip_reason'                => $planAction === 'wall_exit_close' ? null : ($plan['skip_reason'] ?? null),
            'roi'                        => $currentRoi,
            'peak_roi'                   => $peakRoi,
            'lock_price'                 => ($lockPrice !== null && $lockPrice > 0.0) ? $lockPrice : null,
            'lock_active'                => ($lockPrice !== null && $lockPrice > 0.0),
            'profile_used'               => 'baseline_short_lock',
            'notes'                      => $wallExitCloseReason !== null
                ? [$wallExitCloseReason]
                : (!empty($plan['note']) ? [$plan['note']] : []),
            'close_reason_hint'          => $wallExitCloseReason,
            'init_roi'                   => $initRoi,
            'activation_roi'             => $activationRoi,
            'distance_pct'               => $plan['distance_pct']              ?? null,
            'min_required_distance_pct'  => $plan['min_required_distance_pct'] ?? null,
            'side_supported'             => true,
            // ── Position identity diagnostics ─────────────────────────────────
            'pm_state_identity_mismatch' => $identityMismatch,
            'pm_state_legacy_key_ignored'=> $legacyKeyIgnored,
            // ── Wall exit diagnostics ─────────────────────────────────────────
            'wall_exit_checked'          => $wallExitChecked,
            'wall_exit_triggered'        => $wallExitTriggered,
            'wall_exit_skipped'          => $wallExitSkipped,
            'wall_exit_skip_reason'      => $wallExitSkipReason,
            'wall_exit_context'          => $wallExitContext,
        ];
    }

    /**
     * Return the effective merged config for this profile (base + overrides).
     *
     * @return array<string,mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Return the count of active lock entries in this profile's storage.
     */
    public function getLockCount(): int
    {
        return count($this->readLocks());
    }

    /**
     * Remove state and lock entries for symbols no longer in the active position list.
     *
     * Called by the service router after routing all positions in a tick.
     * Only cleans profiles/short/storage/{state,locks}.json — historical logs are untouched.
     *
     * @param list<string> $activeKeys  Position keys (symbol_short) currently active this tick
     * @return array{short_state_cleaned: int, short_locks_cleaned: int}
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
            'short_state_cleaned' => $statesCleaned,
            'short_locks_cleaned' => $locksCleaned,
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function positionKey(string $symbol, string $side): string
    {
        return strtolower($symbol) . '_' . strtolower($side);
    }

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
        foreach (['state.json', 'locks.json'] as $file) {
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
}
