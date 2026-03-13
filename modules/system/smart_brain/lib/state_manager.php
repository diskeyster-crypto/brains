<?php
declare(strict_types=1);

final class StateManager
{
    private string $moduleBase;

    public function __construct(string $moduleBase)
    {
        $this->moduleBase = rtrim($moduleBase, '/');
    }

    /**
     * @return array<string,mixed>
     */
    public function readJson(string $relativeFile, array $default = []): array
    {
        $path = $this->moduleBase . '/' . ltrim($relativeFile, '/');
        if (!is_file($path)) {
            return $default;
        }

        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : $default;
    }

    /**
     * @param array<string,mixed>|array<int,mixed> $data
     */
    public function writeJson(string $relativeFile, array $data): void
    {
        $path = $this->moduleBase . '/' . ltrim($relativeFile, '/');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}
