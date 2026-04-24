<?php

declare(strict_types=1);

namespace Modules\ProfManager;

/**
 * ProfManagerService
 *
 * Paper-only Profit Manager.
 *
 * Architecture:
 *   position_reader  → reads active positions from bot storage
 *   validator        → validates each position
 *   profile_legacy_safe → runs per-position lifecycle logic
 *   profit_lock_planner → decides lock price via step-trailing algorithm
 *   paper_executor   → records planned actions (no exchange calls)
 *   store            → persists runtime state
 *
 * Public API:
 *   tick()            — run one processing cycle
 *   getStatus()       — return current module status
 *   setEnabled(bool)  — toggle enabled flag in config (runtime only, not persisted)
 *
 * Mode is always 'paper'; no exchange calls are ever made.
 */
final class ProfManagerService
{
    private static ?self $instance = null;

    private string $moduleDir;
    private string $repoRoot;
    private bool   $runtimeEnabled;

    /** @var array<string,mixed> */
    private array $config = [];

    /** @var Lib\Store */
    private Lib\Store $store;

    /** @var Lib\PositionReader */
    private Lib\PositionReader $positionReader;

    /** @var Lib\Validator */
    private Lib\Validator $validator;

    /** @var Lib\RiskMath */
    private Lib\RiskMath $riskMath;

    /** @var Lib\ProfitLockPlanner */
    private Lib\ProfitLockPlanner $planner;

    /** @var Lib\ProfileLegacySafe */
    private Lib\ProfileLegacySafe $profile;

    /** @var Lib\PaperExecutor */
    private Lib\PaperExecutor $executor;

    public function __construct(?string $moduleDir = null)
    {
        $this->moduleDir = rtrim(
            $moduleDir ?? __DIR__,
            '/'
        );
        $this->repoRoot = rtrim(dirname($this->moduleDir, 2), '/');

        $this->config         = $this->loadConfig();
        $this->runtimeEnabled = (bool) ($this->config['enabled'] ?? false);

        $storageDir           = $this->moduleDir . '/storage';

        $this->store          = new Lib\Store($storageDir);
        $this->positionReader = new Lib\PositionReader($this->repoRoot);
        $this->validator      = new Lib\Validator();
        $this->riskMath       = new Lib\RiskMath();
        $this->planner        = new Lib\ProfitLockPlanner($this->riskMath);
        $this->profile        = new Lib\ProfileLegacySafe($this->planner, $this->riskMath);
        $this->executor       = new Lib\PaperExecutor();
    }

    public static function instance(?string $moduleDir = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($moduleDir);
        }
        return self::$instance;
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Run one processing tick.
     *
     * @return array Structured result
     */
    public function tick(): array
    {
        $nowTs = time();
        $ts    = date('c', $nowTs);

        try {
            // ── Module enabled? ───────────────────────────────────────────────
            if (!$this->runtimeEnabled) {
                $result = [
                    'ok'        => true,
                    'ts'        => $ts,
                    'enabled'   => false,
                    'mode'      => 'paper',
                    'skipped'   => 'module_disabled',
                    'positions' => 0,
                ];
                $this->store->writeLastRun($result);
                return $result;
            }

            // ── Profile config ────────────────────────────────────────────────
            $activeProfile = $this->config['active_profile'] ?? 'legacy_safe';
            $profileConfig = $this->config['profiles'][$activeProfile] ?? [];
            $maxUpdates    = (int) ($profileConfig['max_updates_per_run'] ?? 20);

            // ── Read positions ────────────────────────────────────────────────
            $readResult  = $this->positionReader->read();
            $rawPositions = $readResult['positions'];

            if (empty($rawPositions)) {
                $result = [
                    'ok'             => true,
                    'ts'             => $ts,
                    'enabled'        => true,
                    'mode'           => 'paper',
                    'profile'        => $activeProfile,
                    'positions'      => 0,
                    'source'         => $readResult['source'],
                    'diagnostics'    => $readResult['diagnostics'],
                    'executed_count' => 0,
                    'skipped_count'  => 0,
                    'executed'       => [],
                    'skipped'        => [],
                    'validation_errors' => [],
                ];
                $this->store->writeLastRun($result);
                return $result;
            }

            // ── Load persisted state ──────────────────────────────────────────
            $positionsState = $this->store->readPositionsState();
            $locks          = $this->store->readLocks();

            // ── Validate + run profile ────────────────────────────────────────
            $plans           = [];
            $validationErrors = [];

            foreach ($rawPositions as $pos) {
                if (!is_array($pos)) {
                    continue;
                }

                // Normalize side
                $pos['side'] = $this->validator->normalizeSide($pos['side'] ?? '');

                $validation = $this->validator->validatePosition($pos);
                if (!$validation['ok']) {
                    $validationErrors[] = [
                        'symbol' => $pos['symbol'] ?? '?',
                        'errors' => $validation['errors'],
                    ];
                    continue;
                }

                $key           = $this->positionKey($pos['symbol'] ?? '', $pos['side'] ?? '');
                $lockState     = $locks[$key] ?? [];
                $positionState = $positionsState[$key] ?? [];

                $runResult = $this->profile->run($pos, $lockState, $positionState, $profileConfig, $nowTs);

                // Update tracking state immediately (in-memory)
                $positionsState[$key] = $runResult['position_state'];
                $locks[$key]          = $runResult['lock_state'];

                $plans[] = $runResult['plan'];
            }

            // ── Paper execution ───────────────────────────────────────────────
            $execResult = $this->executor->execute($plans, $locks, $maxUpdates);

            // ── Persist updated state ─────────────────────────────────────────
            $this->store->writePositionsState($positionsState);
            $this->store->writeLocks($execResult['locks']);

            $result = [
                'ok'                => true,
                'ts'                => $ts,
                'enabled'           => true,
                'mode'              => 'paper',
                'profile'           => $activeProfile,
                'source'            => $readResult['source'],
                'positions'         => count($rawPositions),
                'valid_positions'   => count($rawPositions) - count($validationErrors),
                'executed_count'    => $execResult['summary']['executed'],
                'skipped_count'     => $execResult['summary']['skipped'],
                'executed'          => $execResult['executed'],
                'skipped'           => $execResult['skipped'],
                'validation_errors' => $validationErrors,
            ];

            $this->store->writeLastRun($result);
            return $result;

        } catch (\Throwable $e) {
            $this->store->appendError('tick', $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $errorResult = [
                'ok'    => false,
                'ts'    => $ts,
                'error' => $e->getMessage(),
            ];
            try {
                $this->store->writeLastRun($errorResult);
            } catch (\Throwable) {
                // swallow secondary write failure
            }
            return $errorResult;
        }
    }

    /**
     * Return current module status (without running a tick).
     *
     * @return array
     */
    public function getStatus(): array
    {
        $lastRun = $this->store->readLastRun();
        $locks   = $this->store->readLocks();

        return [
            'module'        => 'prof_manager',
            'version'       => '0.1.0',
            'enabled'       => $this->runtimeEnabled,
            'mode'          => 'paper',
            'profile'       => $this->config['active_profile'] ?? 'legacy_safe',
            'active_locks'  => count($locks),
            'last_run'      => $lastRun,
        ];
    }

    /**
     * Enable or disable the module at runtime (does not persist to config file).
     *
     * @param bool $enabled
     */
    public function setEnabled(bool $enabled): void
    {
        $this->runtimeEnabled = $enabled;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function loadConfig(): array
    {
        $configPath = $this->moduleDir . '/config/config.php';
        if (!is_file($configPath)) {
            return [];
        }
        try {
            $cfg = require $configPath;
            return is_array($cfg) ? $cfg : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function positionKey(string $symbol, string $side): string
    {
        return strtolower($symbol) . '_' . strtolower($side);
    }
}
