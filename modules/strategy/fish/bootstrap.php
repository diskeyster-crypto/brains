<?php

declare(strict_types=1);

/**
 * Fish Strategy — Bootstrap
 *
 * Responsible for loading and merging config (base + active overrides),
 * validating it against schema.php, and returning the effective config.
 *
 * Called by service.php before any runtime logic.
 * Throws \RuntimeException on invalid config so the caller can fail safely.
 */

namespace Modules\Strategy\Fish;

final class FishBootstrap
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
        $base   = $this->loadFile($this->moduleDir . '/config/base.php', 'base');
        $active = $this->loadFile($this->moduleDir . '/config/active.php', 'active');
        $schema = $this->loadFile($this->moduleDir . '/config/schema.php', 'schema');

        // Merge: active overrides base
        $effective = array_merge($base, $active);

        // Validate against schema
        $errors = $this->validate($effective, $schema);

        return [
            'config' => $effective,
            'errors' => $errors,
            'valid'  => empty($errors),
        ];
    }

    /**
     * Load a PHP config file that must return an array.
     *
     * @throws \RuntimeException if file missing or returns wrong type
     */
    private function loadFile(string $path, string $label): array
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Fish config file missing [{$label}]: {$path}");
        }

        $data = require $path;

        if (!is_array($data)) {
            throw new \RuntimeException("Fish config file [{$label}] must return array: {$path}");
        }

        return $data;
    }

    /**
     * Validate effective config against schema type definitions.
     *
     * @param array $config   Effective merged config
     * @param array $schema   Key => type map from schema.php
     * @return list<string>   List of validation error strings (empty = valid)
     */
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
                $actual = gettype($value);
                $errors[] = "Config key [{$key}]: expected {$expectedType}, got {$actual}";
            }
        }

        return $errors;
    }
}
