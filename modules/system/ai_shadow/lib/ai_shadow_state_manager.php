<?php
declare(strict_types=1);

/**
 * AiShadowStateManager
 *
 * Lightweight JSON state manager following the same pattern as StateManager
 * in modules/system/smart_brain/lib/state_manager.php.
 * Scoped to the ai_shadow module base directory.
 */
final class AiShadowStateManager
{
    private string $moduleBase;

    public function __construct(string $moduleBase)
    {
        $this->moduleBase = rtrim($moduleBase, '/');
    }

    /**
     * @return array<string,mixed>|array<int,mixed>
     */
    public function readJson(string $relativeFile, array $default = []): array
    {
        $path = $this->resolvePath($relativeFile);
        if (!is_file($path)) {
            return $default;
        }

        $raw  = file_get_contents($path);
        $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : $default;
    }

    /**
     * @param array<string,mixed>|array<int,mixed> $data
     */
    public function writeJson(string $relativeFile, array $data): void
    {
        $path = $this->resolvePath($relativeFile);
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    public function resolvePath(string $relativeFile): string
    {
        return $this->moduleBase . '/' . ltrim($relativeFile, '/');
    }

    public function fileExists(string $relativeFile): bool
    {
        return is_file($this->resolvePath($relativeFile));
    }

    /**
     * Read a JSON file from an absolute path (for read-only access to other modules).
     *
     * @return array<string,mixed>|array<int,mixed>
     */
    public static function readAbsolute(string $absolutePath, array $default = []): array
    {
        if (!is_file($absolutePath)) {
            return $default;
        }

        $raw  = file_get_contents($absolutePath);
        $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : $default;
    }
}
