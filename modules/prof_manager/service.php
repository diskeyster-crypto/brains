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
 *   setEnabled(bool)  — toggle enabled flag and persist to active.php
 *   saveConfig(array) — persist arbitrary config keys to active.php
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
        $this->store->ensureStorageInit();
        $ttlHours             = (int) ($this->config['paper_position_ttl_hours'] ?? 6);
        $this->positionReader = new Lib\PositionReader($this->repoRoot, $ttlHours);
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
                    'ok'         => true,
                    'ts'         => $ts,
                    'enabled'    => false,
                    'mode'       => 'paper',
                    'skipped'    => 'module_disabled',
                    'skip_reason'=> 'module_disabled',
                    'positions'  => 0,
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
                $skipReason = ($readResult['source'] === 'none')
                    ? 'no_positions_source_found'
                    : 'no_positions';
                $earlyDiag = $readResult['diagnostics'] ?? [];
                $result = [
                    'ok'                                     => true,
                    'ts'                                     => $ts,
                    'enabled'                                => true,
                    'mode'                                   => 'paper',
                    'profile'                                => $activeProfile,
                    'positions'                              => 0,
                    'source'                                 => $readResult['source'],
                    'diagnostics'                            => $earlyDiag,
                    'executed_count'                         => 0,
                    'skipped_count'                          => 0,
                    'executed'                               => [],
                    'skipped'                                => [],
                    'skip_reason'                            => $skipReason,
                    'validation_errors'                      => [],
                    'ignored_disabled_strategy_positions'    => (int) ($earlyDiag['ignored_disabled_strategy_positions'] ?? 0),
                    'ignored_stale_positions'                => (int) ($earlyDiag['ignored_stale_positions'] ?? 0),
                    'stale_ttl_hours'                        => (int) ($earlyDiag['stale_ttl_hours'] ?? 6),
                ];
                $this->store->writeLastRun($result);
                return $result;
            }

            // ── Load persisted state ──────────────────────────────────────────
            $positionsState = $this->store->readPositionsState();
            $locks          = $this->store->readLocks();

            // ── Validate + run profile ────────────────────────────────────────
            $plans            = [];
            $validationErrors = [];
            $allWarningCodes  = [];
            $positionsRuntime = [];

            // Config values used for per-position diagnostics
            $initRoiCfg       = (float) ($profileConfig['init_roi']       ?? 2.0);
            $activationRoiCfg = (float) ($profileConfig['activation_roi'] ?? 10.0);

            foreach ($rawPositions as $pos) {
                if (!is_array($pos)) {
                    continue;
                }

                // Normalize side
                $pos['side'] = $this->validator->normalizeSide($pos['side'] ?? '');

                $validation = $this->validator->validatePosition($pos);

                // Collect warnings regardless of validity
                foreach ($validation['warnings'] as $wCode) {
                    $allWarningCodes[] = (string) $wCode;
                }

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

                // Capture existing lock price before running profile
                $oldLockPrice = (float) ($lockState['lock_price'] ?? 0.0);

                $runResult = $this->profile->run($pos, $lockState, $positionState, $profileConfig, $nowTs);

                // Update tracking state immediately (in-memory)
                $positionsState[$key] = $runResult['position_state'];
                $locks[$key]          = $runResult['lock_state'];

                $plans[] = $runResult['plan'];

                // ── Collect per-position runtime diagnostics ──────────────────
                $positionsRuntime[] = [
                    'symbol'                    => $pos['symbol']      ?? '',
                    'side'                      => $pos['side']        ?? '',
                    'entry_price'               => (float) ($pos['entry_price']    ?? $pos['avg_price'] ?? 0.0),
                    'current_price'             => (float) ($pos['current_price']  ?? 0.0),
                    'roi'                       => $runResult['plan']['current_roi'] ?? null,
                    'peak_roi'                  => $runResult['plan']['peak_roi']    ?? null,
                    'init_roi'                  => $initRoiCfg,
                    'activation_roi'            => $activationRoiCfg,
                    'roi_gap_to_activation'     => ($runResult['plan']['current_roi'] !== null)
                        ? round($activationRoiCfg - (float) $runResult['plan']['current_roi'], 4)
                        : null,
                    'action'                    => $runResult['plan']['action']      ?? 'skip',
                    'skip_reason'               => !empty($runResult['plan']['skip_reason'])
                        ? $runResult['plan']['skip_reason']
                        : (($runResult['plan']['action'] ?? 'skip') === 'skip' ? 'unknown' : null),
                    'lock_price'                => $runResult['lock_state']['lock_price'] ?? null,
                    'old_lock_price'            => $oldLockPrice > 0.0 ? $oldLockPrice : null,
                    'distance_pct'              => $runResult['plan']['distance_pct']              ?? null,
                    'min_required_distance_pct' => $runResult['plan']['min_required_distance_pct'] ?? null,
                    'price_source'              => $pos['_price_source'] ?? 'unknown',
                ];
            }

            // ── Build skip-reason summary ─────────────────────────────────────
            // Count each position's final skip_reason independently.
            // below_activation_roi and below_init_roi may both appear if different
            // positions have different states — do NOT remove either globally.
            $skipReasonsSummary = [];
            foreach ($plans as $plan) {
                if (($plan['action'] ?? '') === 'skip' && !empty($plan['skip_reason'])) {
                    $r = (string) $plan['skip_reason'];
                    $skipReasonsSummary[$r] = ($skipReasonsSummary[$r] ?? 0) + 1;
                }
            }

            // ── Paper execution ───────────────────────────────────────────────
            $execResult = $this->executor->execute($plans, $locks, $maxUpdates);

            // ── Persist updated state ─────────────────────────────────────────
            $this->store->writePositionsState($positionsState);
            $this->store->writeLocks($execResult['locks']);

            // ── Enrichment summary from position reader ───────────────────────
            $enrichmentSummary    = $readResult['enrichment_summary'] ?? [];
            $priceProviderError   = $readResult['price_provider_error'] ?? null;
            $priceProviderSource  = $readResult['price_provider_source'] ?? 'none';

            // ── Count positions missing price data ────────────────────────────
            $priceMissingCount = 0;
            foreach ($rawPositions as $pos) {
                if (is_array($pos) && !empty($pos['_no_price_data'])) {
                    $priceMissingCount++;
                }
            }

            // ── Build warnings summary ────────────────────────────────────────
            if ($priceMissingCount > 0) {
                $allWarningCodes[] = 'no_price_data';
            }
            if (!empty($enrichmentSummary['sizes_calculated'])) {
                $allWarningCodes[] = 'size_calculated';
            }
            if (!empty($enrichmentSummary['leverage_defaulted'])) {
                $allWarningCodes[] = 'leverage_defaulted';
            }
            if (!empty($enrichmentSummary['budget_defaulted'])) {
                $allWarningCodes[] = 'budget_defaulted';
            }
            $warningsSummary = array_values(array_unique($allWarningCodes));

            // ── Compute validation error summaries ────────────────────────────
            $positionsTotal = count($rawPositions);
            $invalidCount   = count($validationErrors);
            $validCount     = $positionsTotal - $invalidCount;

            $allErrorCodes  = [];
            $errorsBySymbol = [];
            foreach ($validationErrors as $ve) {
                $sym  = (string) ($ve['symbol'] ?? '?');
                $errs = is_array($ve['errors']) ? $ve['errors'] : [$ve['errors']];
                foreach ($errs as $code) {
                    $allErrorCodes[] = (string) $code;
                }
                $errorsBySymbol[$sym] = $errs;
            }
            $errorsSummary = array_values(array_unique($allErrorCodes));

            $readDiag   = $readResult['diagnostics'] ?? [];
            $skipReason = ($positionsTotal > 0 && $validCount === 0)
                ? 'all_positions_invalid'
                : '';

            $result = [
                'ok'                                     => true,
                'ts'                                     => $ts,
                'enabled'                                => true,
                'mode'                                   => 'paper',
                'profile'                                => $activeProfile,
                'source'                                 => $readResult['source'],
                'positions'                              => $positionsTotal,
                'valid_positions'                        => $validCount,
                'invalid_positions'                      => $invalidCount,
                'price_missing_positions'                => $priceMissingCount,
                'executed_count'                         => $execResult['summary']['executed'],
                'skipped_count'                          => $execResult['summary']['skipped'],
                'executed'                               => $execResult['executed'],
                'skipped'                                => $execResult['skipped'],
                'skip_reason'                            => $skipReason,
                'skip_reasons_summary'                   => $skipReasonsSummary,
                'positions_runtime'                      => $positionsRuntime,
                'validation_errors'                      => $validationErrors,
                'validation_errors_summary'              => $errorsSummary,
                'validation_errors_by_symbol'            => $errorsBySymbol,
                'warnings_summary'                       => $warningsSummary,
                'enrichment_summary'                     => $enrichmentSummary,
                'price_provider_error'                   => $priceProviderError,
                'price_provider_source'                  => $priceProviderSource,
                'ignored_disabled_strategy_positions'    => (int) ($readDiag['ignored_disabled_strategy_positions'] ?? 0),
                'ignored_stale_positions'                => (int) ($readDiag['ignored_stale_positions'] ?? 0),
                'stale_ttl_hours'                        => (int) ($readDiag['stale_ttl_hours'] ?? 6),
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

        $lastError = null;
        // Only surface an active error when the most recent tick actually failed.
        // Do NOT show historical error.log entries when last_run ok=true.
        if (($lastRun['ok'] ?? null) !== true) {
            if (!empty($lastRun['error'])) {
                $lastError = (string) $lastRun['error'];
            } else {
                $lastErrRaw = $this->store->readLastError();
                if ($lastErrRaw !== '') {
                    $decoded = json_decode($lastErrRaw, true);
                    if (is_array($decoded) && isset($decoded['message'])) {
                        $lastError = $decoded['message'];
                    } else {
                        $lastError = $lastErrRaw;
                    }
                }
            }
        }

        $cronToken = (string)($this->config['cron_token'] ?? '');

        return [
            'enabled'          => $this->runtimeEnabled,
            'mode'             => 'paper',
            'active_profile'   => $this->config['active_profile'] ?? 'legacy_safe',
            'last_tick'        => $lastRun['ts'] ?? null,
            'positions_tracked'=> (int) ($lastRun['valid_positions'] ?? $lastRun['positions'] ?? 0),
            'locks_active'     => count($locks),
            'planned_updates'  => (int) ($lastRun['executed_count'] ?? 0),
            'skipped'          => (int) ($lastRun['skipped_count'] ?? 0),
            'last_error'       => $lastError,
            'cron_interval_sec'=> 60,
            'cron_configured'  => ($cronToken !== ''),
        ];
    }

    /**
     * Enable or disable the module and persist the flag to active.php.
     *
     * @param bool $enabled
     * @return array{ok: bool, enabled: bool}
     */
    public function setEnabled(bool $enabled): array
    {
        $this->runtimeEnabled = $enabled;
        $this->config['enabled'] = $enabled;
        $this->saveConfig($this->config);
        return ['ok' => true, 'enabled' => $enabled];
    }

    /**
     * Save an arbitrary config array to active.php (merges on top of base config).
     *
     * @param array<string,mixed> $data
     */
    public function saveConfig(array $data): void
    {
        $activeFile = $this->moduleDir . '/config/active.php';

        $existing = [];
        if (is_file($activeFile)) {
            try {
                $loaded = @include $activeFile;
                if (is_array($loaded)) {
                    $existing = $loaded;
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        $merged = array_merge($existing, $data);

        $php  = "<?php\n\ndeclare(strict_types=1);\n\n";
        $php .= "/**\n * Profit Manager Module — Active Config Overrides\n";
        $php .= " * Written by the admin UI. Do not edit manually.\n */\n\n";
        $php .= "return ";
        $php .= var_export($merged, true);
        $php .= ";\n";

        $dir = dirname($activeFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($activeFile, $php);

        // Reload config to keep instance in sync
        $this->config = $this->loadConfig();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function loadConfig(): array
    {
        $configPath = $this->moduleDir . '/config/config.php';
        $activePath = $this->moduleDir . '/config/active.php';
        $cfg = [];
        try {
            if (is_file($configPath)) {
                $base = require $configPath;
                if (is_array($base)) {
                    $cfg = $base;
                }
            }
            if (is_file($activePath)) {
                $active = require $activePath;
                if (is_array($active)) {
                    $cfg = array_merge($cfg, $active);
                }
            }
        } catch (\Throwable $e) {
            // return whatever we have
        }
        return $cfg;
    }

    private function positionKey(string $symbol, string $side): string
    {
        return strtolower($symbol) . '_' . strtolower($side);
    }
}
