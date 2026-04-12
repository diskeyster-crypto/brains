<?php
declare(strict_types=1);

namespace Modules\System\Config\Lib;

/**
 * ConfigStore — persistence layer for the Unified Config Module.
 *
 * Reads and writes the five draft/report artefacts:
 *  - config_ownership_map.json
 *  - config_conflict_report.json
 *  - config_operational_draft.json
 *  - config_immutable_draft.json
 *  - config_effective_preview.json
 *  - last_extract.json
 *
 * All writes are atomic (write-to-temp + rename).
 * This class NEVER writes to other modules' files.
 */
final class ConfigStore
{
    private string $moduleBase;
    private array  $config;

    public function __construct(string $moduleBase, array $config)
    {
        $this->moduleBase = rtrim($moduleBase, '/');
        $this->config     = $config;
    }

    // =========================================================================
    // Ownership map
    // =========================================================================

    /**
     * Load config_ownership_map.json.
     *
     * @return array{generated_at:string|null,parameters:array<string,mixed>}
     */
    public function loadOwnershipMap(): array
    {
        return $this->readJson($this->resolvePath('ownership_map_file')) ?? [
            'generated_at' => null,
            'parameters'   => [],
        ];
    }

    /**
     * Save config_ownership_map.json.
     *
     * @param array<string,mixed> $data
     */
    public function saveOwnershipMap(array $data): bool
    {
        return $this->writeJson($this->resolvePath('ownership_map_file'), $data);
    }

    // =========================================================================
    // Conflict report
    // =========================================================================

    /**
     * Load config_conflict_report.json.
     *
     * @return array{generated_at:string|null,conflicts:list<array<string,mixed>>}
     */
    public function loadConflictReport(): array
    {
        return $this->readJson($this->resolvePath('conflict_report_file')) ?? [
            'generated_at' => null,
            'conflicts'    => [],
        ];
    }

    /**
     * Save config_conflict_report.json.
     *
     * @param array<string,mixed> $data
     */
    public function saveConflictReport(array $data): bool
    {
        return $this->writeJson($this->resolvePath('conflict_report_file'), $data);
    }

    // =========================================================================
    // Operational master config draft
    // =========================================================================

    /**
     * Load config_operational_draft.json.
     *
     * @return array{generated_at:string|null,params:array<string,mixed>}
     */
    public function loadOperationalDraft(): array
    {
        return $this->readJson($this->resolvePath('operational_draft_file')) ?? [
            'generated_at' => null,
            'params'       => [],
        ];
    }

    /**
     * Save config_operational_draft.json.
     *
     * @param array<string,mixed> $data
     */
    public function saveOperationalDraft(array $data): bool
    {
        return $this->writeJson($this->resolvePath('operational_draft_file'), $data);
    }

    // =========================================================================
    // Immutable / internal config draft
    // =========================================================================

    /**
     * Load config_immutable_draft.json.
     *
     * @return array{generated_at:string|null,params:array<string,mixed>}
     */
    public function loadImmutableDraft(): array
    {
        return $this->readJson($this->resolvePath('immutable_draft_file')) ?? [
            'generated_at' => null,
            'params'       => [],
        ];
    }

    /**
     * Save config_immutable_draft.json.
     *
     * @param array<string,mixed> $data
     */
    public function saveImmutableDraft(array $data): bool
    {
        return $this->writeJson($this->resolvePath('immutable_draft_file'), $data);
    }

    // =========================================================================
    // Effective preview
    // =========================================================================

    /**
     * Load config_effective_preview.json.
     *
     * @return array{generated_at:string|null,modules:array<string,mixed>}
     */
    public function loadEffectivePreview(): array
    {
        return $this->readJson($this->resolvePath('effective_preview_file')) ?? [
            'generated_at' => null,
            'modules'      => [],
        ];
    }

    /**
     * Save config_effective_preview.json.
     *
     * @param array<string,mixed> $data
     */
    public function saveEffectivePreview(array $data): bool
    {
        return $this->writeJson($this->resolvePath('effective_preview_file'), $data);
    }

    // =========================================================================
    // Last extract record
    // =========================================================================

    /**
     * Load last_extract.json.
     *
     * @return array{extracted_at:string|null,status:string,errors:list<string>}
     */
    public function loadLastExtract(): array
    {
        return $this->readJson($this->resolvePath('last_extract_file')) ?? [
            'extracted_at' => null,
            'status'       => 'never',
            'errors'       => [],
        ];
    }

    /**
     * Save last_extract.json.
     *
     * @param array<string,mixed> $data
     */
    public function saveLastExtract(array $data): bool
    {
        return $this->writeJson($this->resolvePath('last_extract_file'), $data);
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    /**
     * Resolve a storage file path from config key to absolute path.
     */
    private function resolvePath(string $key): string
    {
        $rel = $this->config['storage'][$key] ?? '';
        if ($rel === '') {
            return '';
        }
        return $this->moduleBase . '/' . ltrim($rel, '/');
    }

    /**
     * Read and JSON-decode a file. Returns null on missing / invalid.
     *
     * @return array<string,mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if ($path === '' || !is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = @json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Atomically write JSON to a file (temp + rename).
     *
     * @param array<string,mixed> $data
     */
    private function writeJson(string $path, array $data): bool
    {
        if ($path === '') {
            return false;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }
        $tmp = $path . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $json) === false) {
            return false;
        }
        return @rename($tmp, $path);
    }
}
