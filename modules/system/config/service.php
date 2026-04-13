<?php
declare(strict_types=1);

namespace Modules\System\Config;

use Modules\System\Config\Lib\ConfigStore;
use Modules\System\Config\Lib\ConfigAuditEngine;

/**
 * UnifiedConfigService — main service for the Unified Config Module.
 *
 * SHADOW ONLY — read-only extraction and preview.
 * Does NOT mutate any other module's config or runtime state.
 *
 * @package Modules\System\Config
 */
final class UnifiedConfigService
{
    private string $moduleBase;
    private array  $config;
    private ConfigStore $store;
    private ConfigAuditEngine $engine;

    private array $errors   = [];
    private array $warnings = [];

    public function __construct()
    {
        $this->moduleBase = $this->resolveModuleBase();
        $this->config     = $this->loadConfig();
        $this->store      = new ConfigStore($this->moduleBase, $this->config);
        $this->engine     = new ConfigAuditEngine($this->moduleBase, $this->config);
    }

    // =========================================================================
    // Extraction
    // =========================================================================

    /**
     * Trigger extraction only if no successful extraction has run yet (first-boot helper).
     *
     * Called from the controller on every page load so that the shadow artefacts
     * are written to disk on the very first visit, without requiring a manual
     * Re-extract click.  Subsequent page loads are skipped once a timestamp
     * exists in last_extract.json.
     */
    public function maybeAutoExtract(): void
    {
        $last = $this->store->loadLastExtract();
        if (($last['extracted_at'] ?? null) === null || ($last['status'] ?? 'never') === 'never') {
            $this->extract();
        }
    }

    /**
     * Run a full extraction pass: read all module configs and save artefacts.
     *
     * @return array{ok:bool,errors:list<string>,warnings:list<string>,extracted_at:string}
     */
    public function extract(): array
    {
        $this->errors   = [];
        $this->warnings = [];

        $result = $this->engine->extract();

        $ok = $result['ok'];
        $this->errors = array_merge($this->errors, $result['errors']);

        // Save all artefacts (best-effort; failures are non-fatal)
        $saved = [];
        $saved['ownership']   = $this->store->saveOwnershipMap($result['ownership_map']);
        $saved['conflicts']   = $this->store->saveConflictReport($result['conflict_report']);
        $saved['operational'] = $this->store->saveOperationalDraft($result['operational_draft']);
        $saved['immutable']   = $this->store->saveImmutableDraft($result['immutable_draft']);
        $saved['preview']     = $this->store->saveEffectivePreview($result['effective_preview']);

        foreach ($saved as $key => $written) {
            if (!$written) {
                $this->warnings[] = "Could not write {$key} artefact";
            }
        }

        $ts = date('c');
        $this->store->saveLastExtract([
            'extracted_at'    => $ts,
            'status'          => $ok ? 'ok' : 'partial',
            'errors'          => $this->errors,
            'warnings'        => $this->warnings,
            'artefacts_saved' => $saved,
            'counts'          => [
                'ownership_entries'  => count($result['ownership_map']['parameters'] ?? []),
                'conflicts'          => (int)($result['conflict_report']['conflict_count'] ?? 0),
                'duplicates'         => (int)($result['conflict_report']['duplicate_count'] ?? 0),
                'operational_params' => count($result['operational_draft']['params'] ?? []),
                'immutable_params'   => count($result['immutable_draft']['params'] ?? []),
                'preview_sections'   => count($result['effective_preview']['modules'] ?? []),
            ],
        ]);

        return [
            'ok'           => $ok && empty($this->errors),
            'errors'       => $this->errors,
            'warnings'     => $this->warnings,
            'extracted_at' => $ts,
        ];
    }

    // =========================================================================
    // Read-only accessors (for UI/API)
    // =========================================================================

    public function getOwnershipMap(): array
    {
        return $this->store->loadOwnershipMap();
    }

    public function getConflictReport(): array
    {
        return $this->store->loadConflictReport();
    }

    public function getOperationalDraft(): array
    {
        return $this->store->loadOperationalDraft();
    }

    /**
     * Load the editable operational master config.
     * Returns null when no master has been saved yet (first-time setup).
     *
     * @return array{saved_at:string|null,saved_by:string,params:array<string,mixed>}|null
     */
    public function getOperationalMaster(): ?array
    {
        return $this->store->loadOperationalMaster();
    }

    /**
     * Persist the operational master config.
     *
     * Only known operational param keys accepted; values are typed per schema.
     * Immutable/internal params are silently ignored (they stay read-only).
     *
     * @param array<string,mixed> $rawPost Associative array from POST/JSON
     * @return array{ok:bool,saved_at:string,errors:list<string>}
     */
    public function saveOperationalMaster(array $rawPost): array
    {
        $errors = [];

        // ------------------------------------------------------------------
        // Schema: key → [type, required]
        // Only operational (non-immutable) params that consumers actually use.
        // ------------------------------------------------------------------
        $schema = [
            // Smart Brain first-wave
            'live_trading_enabled'           => 'bool',
            'live_max_positions'             => 'int',
            'live_signal_selection_mode'     => 'str',
            'live_one_trade_per_symbol'      => 'bool',
            'live_entry_policy'              => 'str',
            'live_reverse_side_enabled'      => 'bool',
            'leverage_mode'                  => 'str',
            'manual_leverage'                => 'int',
            'max_leverage'                   => 'int',
            'max_budget_per_coin'            => 'float',
            'stop_control_mode'              => 'str',
            'stop_loss_from_entry_roi'       => 'float',
            'trailing_enabled'               => 'bool',
            'trailing_mode'                  => 'str',
            'trailing_activation_roi'        => 'float',
            'trailing_activation_floor_roi'  => 'float',
            'break_even_enabled'             => 'bool',
            'break_even_activation_roi'      => 'float',
            'patterns_enabled'               => 'array',
            'patterns_mode'                  => 'str',
            'execution_profile'              => 'str',
            // Trading Bot first-wave
            'bot_enabled'                    => 'bool',
            'bot_mode'                       => 'str',
            'max_intents_per_run'            => 'int',
            'max_concurrent_positions'       => 'int',
            'bot_brain_controlled'           => 'bool',
            'pm_trailing_owner'              => 'str',
        ];

        $params = [];
        $ts     = date('c');

        foreach ($schema as $key => $type) {
            if (!array_key_exists($key, $rawPost)) {
                continue; // not submitted — do not overwrite existing master value
            }
            $raw = $rawPost[$key];
            switch ($type) {
                case 'bool':
                    $val = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($val === null) {
                        // HTML checkboxes send 'on'/'1'/'' when toggled
                        $val = in_array(strtolower((string)$raw), ['1','true','on','yes'], true);
                    }
                    break;
                case 'int':
                    $val = (int)$raw;
                    break;
                case 'float':
                    $val = (float)$raw;
                    break;
                case 'array':
                    $val = is_array($raw) ? array_values(array_filter(array_map('trim', $raw))) : [];
                    break;
                default: // 'str'
                    $val = trim((string)$raw);
            }
            $params[$key] = [
                'value'    => $val,
                'type'     => $type,
                'saved_at' => $ts,
            ];
        }

        // Load existing master and merge (keep keys not submitted)
        $existing = $this->store->loadOperationalMaster();
        $existingParams = $existing['params'] ?? [];
        $merged = array_merge($existingParams, $params);

        $masterData = [
            'saved_at'  => $ts,
            'saved_by'  => 'config_center',
            'param_count' => count($merged),
            'params'    => $merged,
        ];

        $ok = $this->store->saveOperationalMaster($masterData);
        if (!$ok) {
            $errors[] = 'Не удалось записать config_operational_master.json';
        }

        return [
            'ok'       => $ok,
            'saved_at' => $ts,
            'errors'   => $errors,
        ];
    }

    public function getImmutableDraft(): array
    {
        return $this->store->loadImmutableDraft();
    }

    public function getEffectivePreview(): array
    {
        return $this->store->loadEffectivePreview();
    }

    public function getLastExtract(): array
    {
        return $this->store->loadLastExtract();
    }

    /**
     * Load Smart Brain config migration status from its runtime artifact.
     * Returns the contents of smart_brain/runtime/config_source_status.json,
     * or an array with available=false on any failure.
     *
     * @return array<string,mixed>
     */
    public function getSmartBrainMigrationStatus(): array
    {
        $brainBase = dirname($this->moduleBase) . '/smart_brain';
        $path = $brainBase . '/runtime/config_source_status.json';
        if (!is_file($path)) {
            return ['available' => false, 'reason' => 'file_not_found'];
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return ['available' => false, 'reason' => 'read_failed'];
        }
        $data = @json_decode($raw, true);
        if (!is_array($data)) {
            return ['available' => false, 'reason' => 'invalid_json'];
        }
        return array_merge(['available' => true], $data);
    }

    /**
     * Load Trading Bot config migration status from its runtime artifact.
     * Returns the contents of trading_bot/runtime/config_source_status.json,
     * or an array with available=false on any failure.
     *
     * @return array<string,mixed>
     */
    public function getTradingBotMigrationStatus(): array
    {
        $botBase = dirname($this->moduleBase) . '/trading_bot';
        $path = $botBase . '/runtime/config_source_status.json';
        if (!is_file($path)) {
            return ['available' => false, 'reason' => 'file_not_found'];
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return ['available' => false, 'reason' => 'read_failed'];
        }
        $data = @json_decode($raw, true);
        if (!is_array($data)) {
            return ['available' => false, 'reason' => 'invalid_json'];
        }
        return array_merge(['available' => true], $data);
    }

    /**
     * Return a summary for the UI header (quick status).
     */
    public function getSummary(): array
    {
        $lastExtract    = $this->store->loadLastExtract();
        $ownership      = $this->store->loadOwnershipMap();
        $conflictReport = $this->store->loadConflictReport();
        $master         = $this->store->loadOperationalMaster();

        $paramCount    = count($ownership['parameters'] ?? []);
        $conflictCount = (int)($conflictReport['conflict_count'] ?? 0);
        $dupCount      = (int)($conflictReport['duplicate_count'] ?? 0);

        return [
            'extracted_at'         => $lastExtract['extracted_at']   ?? null,
            'extract_status'       => $lastExtract['status']          ?? 'never',
            'extract_errors'       => $lastExtract['errors']          ?? [],
            'param_count'          => $paramCount,
            'conflict_count'       => $conflictCount,
            'duplicate_count'      => $dupCount,
            'module_base'          => $this->moduleBase,
            'master_saved_at'      => $master['saved_at']             ?? null,
            'master_param_count'   => count($master['params']         ?? []),
        ];
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    private function resolveModuleBase(): string
    {
        // Prefer SystemPaths registration; fall back to __DIR__
        try {
            $paths = \Core\System\SystemPaths::instance();
            if ($paths->has('system.config')) {
                return rtrim((string)$paths->get('system.config'), '/');
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return __DIR__;
    }

    private function loadConfig(): array
    {
        $cfgPath = $this->moduleBase . '/config/config.php';
        if (!is_file($cfgPath)) {
            return [];
        }
        try {
            $cfg = require $cfgPath;
            return is_array($cfg) ? $cfg : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
