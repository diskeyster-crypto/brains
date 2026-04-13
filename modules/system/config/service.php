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

        $paramCount    = count($ownership['parameters'] ?? []);
        $conflictCount = (int)($conflictReport['conflict_count'] ?? 0);
        $dupCount      = (int)($conflictReport['duplicate_count'] ?? 0);

        return [
            'extracted_at'     => $lastExtract['extracted_at']   ?? null,
            'extract_status'   => $lastExtract['status']          ?? 'never',
            'extract_errors'   => $lastExtract['errors']          ?? [],
            'param_count'      => $paramCount,
            'conflict_count'   => $conflictCount,
            'duplicate_count'  => $dupCount,
            'module_base'      => $this->moduleBase,
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
