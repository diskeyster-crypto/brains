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
     * @return array {action, skip_reason, roi, peak_roi, lock_price, lock_active, profile_used, notes, ...}
     */
    public function process(array $position, int $nowTs): array
    {
        $symbol = (string) ($position['symbol'] ?? '');
        $key    = $this->positionKey($symbol, 'long');

        $positionsState = $this->readState();
        $locks          = $this->readLocks();

        $positionState = $positionsState[$key] ?? [];
        $lockState     = $locks[$key]          ?? [];

        $runResult = $this->runLifecycle($position, $lockState, $positionState, $this->config, $nowTs);

        // Update position state
        $positionsState[$key] = $runResult['position_state'];

        // Update lock state when a real lock action was planned
        $plan = $runResult['plan'];
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
        } elseif (!empty($runResult['lock_state'])) {
            $locks[$key] = $runResult['lock_state'];
        }

        // Persist to own storage
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
            } catch (\Throwable) {}
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
