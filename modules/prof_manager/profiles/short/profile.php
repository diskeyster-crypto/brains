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
    public function process(array $position, int $nowTs): array
    {
        $symbol = (string) ($position['symbol'] ?? '');
        $key    = $this->positionKey($symbol, 'short');

        $positionsState = $this->readState();
        $locks          = $this->readLocks();

        $positionState = $positionsState[$key] ?? [];
        $lockState     = $locks[$key]          ?? [];

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
                'symbol'        => $symbol,
                'side'          => 'short',
                'lock_price'    => $plan['proposed_lock'],
                'lock_roi'      => $plan['proposed_roi'],
                'action'        => $plan['action'],
                'updated_at'    => date('c', $nowTs),
                'updated_at_ts' => $nowTs,
            ];
        } elseif (!empty($lockState)) {
            // Preserve existing lock (e.g. during would_close_on_lock_touch or skip)
            $locks[$key] = $lockState;
        }

        $positionsState[$key] = $positionState;
        $this->writeState($positionsState);
        $this->writeLocks($locks);

        $lockRecord = $locks[$key] ?? [];
        $lockPrice  = isset($lockRecord['lock_price']) ? (float) $lockRecord['lock_price'] : null;

        return [
            'action'                    => $plan['action']       ?? 'skip',
            'skip_reason'               => $plan['skip_reason']  ?? null,
            'roi'                       => $currentRoi,
            'peak_roi'                  => $peakRoi,
            'lock_price'                => ($lockPrice !== null && $lockPrice > 0.0) ? $lockPrice : null,
            'lock_active'               => ($lockPrice !== null && $lockPrice > 0.0),
            'profile_used'              => 'baseline_short_lock',
            'notes'                     => !empty($plan['note']) ? [$plan['note']] : [],
            'init_roi'                  => $initRoi,
            'activation_roi'            => $activationRoi,
            'distance_pct'              => $plan['distance_pct']              ?? null,
            'min_required_distance_pct' => $plan['min_required_distance_pct'] ?? null,
            'side_supported'            => true,
        ];
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
