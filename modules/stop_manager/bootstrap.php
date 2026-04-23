<?php

declare(strict_types=1);

/**
 * Stop Manager Module — Bootstrap
 *
 * Loads and merges config (base + active overrides), validates against
 * schema.php, and returns the effective config.  Called by StopManagerService
 * before any runtime logic.  Throws \RuntimeException on invalid config.
 */

namespace Modules\StopManager;

final class StopManagerBootstrap
{
    private static ?self $instance = null;
    private string $moduleDir;

    private function __construct(string $moduleDir)
    {
        $this->moduleDir = rtrim($moduleDir, '/');
    }

    public static function instance(string $moduleDir): self
    {
        if (self::$instance === null) {
            self::$instance = new self($moduleDir);
        }
        return self::$instance;
    }

    /**
     * Load, merge, and validate config.
     *
     * @return array{config: array, errors: list<string>, valid: bool}
     */
    public function load(): array
    {
        $base   = $this->loadFile($this->moduleDir . '/config/base.php',  'base');
        $active = $this->loadFile($this->moduleDir . '/config/active.php', 'active');
        $schema = $this->loadFile($this->moduleDir . '/config/schema.php', 'schema');

        $effective = array_merge($base, $active);
        $errors    = $this->validate($effective, $schema);

        return [
            'config' => $effective,
            'errors' => $errors,
            'valid'  => empty($errors),
        ];
    }

    private function loadFile(string $path, string $label): array
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Stop Manager config file missing [{$label}]: {$path}");
        }
        $data = require $path;
        if (!is_array($data)) {
            throw new \RuntimeException("Stop Manager config file [{$label}] must return array: {$path}");
        }
        return $data;
    }

    private function validate(array $config, array $schema): array
    {
        $errors = [];
        foreach ($schema as $key => $expectedType) {
            if (!array_key_exists($key, $config)) {
                $errors[] = "Missing required config key: {$key}";
                continue;
            }
            $value = $config[$key];
            $ok = match ($expectedType) {
                'string' => is_string($value),
                'bool'   => is_bool($value),
                'int'    => is_int($value),
                'float'  => is_float($value) || is_int($value),
                'array'  => is_array($value),
                default  => true,
            };
            if (!$ok) {
                $errors[] = "Config key [{$key}]: expected {$expectedType}, got " . gettype($value);
            }
        }
        return $errors;
    }
}
