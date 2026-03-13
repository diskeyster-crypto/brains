<?php

declare(strict_types=1);

namespace Core\System;

/**
 * SystemPaths Bootstrap
 * 
 * Scans all modules subdirectories for manifest.json OR manifest.php and builds the PackMap.
 * Must be called AFTER SystemPaths::init() to have the root path.
 * 
 * PackMap format: parser.{module}.{path_key}
 * 
 * Example:
 *   parser.parser1_market_registry.storage => /path/to/modules/parser/parser1_market_registry/storage
 *   parser.delist_parser0.config => /path/to/modules/parser/delist_parser0/config/config.php
 */
final class SystemPathsBootstrap
{
    /**
     * Scan all manifest.json files and register paths in SystemPaths
     * 
     * @param SystemPaths $paths The SystemPaths instance to populate
     * @return array Statistics about scanned manifests
     */
    public static function scan(SystemPaths $paths): array
    {
        $stats = [
            'scanned' => 0,
            'registered' => 0,
            'errors' => [],
            'modules' => [],
        ];
        
        $root = $paths->get('root');
        $modulesDir = $root . '/modules';
        
        if (!is_dir($modulesDir)) {
            return $stats;
        }
        
        // Recursively find all manifest.json OR manifest.php files (prefer manifest.json)
        $manifests = self::findManifests($modulesDir);
        
        foreach ($manifests as $manifestPath) {
            $stats['scanned']++;
            
            try {
                $manifest = self::loadManifest($manifestPath);
                $moduleDir = dirname($manifestPath);
                $category = $manifest['category'] ?? self::detectCategory($moduleDir, $modulesDir);
                $moduleName = $manifest['name'] ?? basename($moduleDir);
                
                // Register paths from manifest
                $pathsRegistered = self::registerModulePaths(
                    $paths,
                    $category,
                    $moduleName,
                    $moduleDir,
                    $manifest['paths'] ?? []
                );
                
                $stats['registered'] += $pathsRegistered;
                $stats['modules'][] = "{$category}.{$moduleName}";
                
            } catch (\Throwable $e) {
                $stats['errors'][] = [
                    'file' => $manifestPath,
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        return $stats;
    }
    
    /**
     * Recursively find all manifest.json files in the modules directory
     */
    private static function findManifests(string $dir): array
    {
        /** @var array<string,string> $picked */
        $picked = [];
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $fn = $file->getFilename();
            if ($fn !== 'manifest.json' && $fn !== 'manifest.php') {
                continue;
            }

            $moduleDir = $file->getPath();
            $full = $file->getPathname();

            // Prefer manifest.json over manifest.php for same module directory
            if (!isset($picked[$moduleDir])) {
                $picked[$moduleDir] = $full;
                continue;
            }

            $existing = $picked[$moduleDir];
            if (basename($existing) === 'manifest.php' && $fn === 'manifest.json') {
                $picked[$moduleDir] = $full;
            }
        }

        return array_values($picked);
    }
    
    /**
     * Load and parse a manifest.json file
     * 
     * @throws \RuntimeException if manifest is invalid
     */
    private static function loadManifest(string $path): array
    {
        $base = basename($path);

        // manifest.php returns array
        if ($base === 'manifest.php') {
            $manifest = require $path;
            if (!is_array($manifest)) {
                throw new \RuntimeException("Invalid PHP manifest (must return array): {$path}");
            }
            return $manifest;
        }

        // manifest.json
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Cannot read manifest: {$path}");
        }

        $manifest = json_decode($content, true);
        if (!is_array($manifest)) {
            throw new \RuntimeException("Invalid JSON in manifest: {$path}");
        }

        return $manifest;
    }
    
    /**
     * Detect module category from directory structure
     * e.g., modules/parser/parser1 => 'parser'
     */
    private static function detectCategory(string $moduleDir, string $modulesDir): string
    {
        $relativePath = str_replace($modulesDir . '/', '', $moduleDir);
        $parts = explode('/', $relativePath);
        
        // If in a subdirectory like modules/parser/parser1, category is 'parser'
        if (count($parts) > 1) {
            return $parts[0];
        }
        
        // Otherwise, default to 'module'
        return 'module';
    }
    
    /**
     * Register module paths in SystemPaths
     * 
     * Creates keys like:
     *   parser.parser1_market_registry => /full/path/to/module
     *   parser.parser1_market_registry.storage => /full/path/to/module/storage
     *   parser.parser1_market_registry.config => /full/path/to/module/config/config.php
     * 
     * @return int Number of paths registered
     */
    private static function registerModulePaths(
        SystemPaths $paths,
        string $category,
        string $moduleName,
        string $moduleDir,
        array $pathDefs
    ): int {
        $count = 0;
        $baseKey = "{$category}.{$moduleName}";
        
        // Register base module path
        $paths->register($baseKey, $moduleDir);
        $count++;
        
        // Register each defined path
        foreach ($pathDefs as $pathKey => $relativePath) {
            $fullPath = $moduleDir . '/' . $relativePath;
            $paths->register("{$baseKey}.{$pathKey}", $fullPath);
            $count++;
        }
        
        return $count;
    }
}

/* RULES
- Purpose: Bootstrap SystemPaths by scanning manifest.json files
- Config sources: manifest.json files in modules/**
- Paths: Builds PackMap from manifest paths definitions
- Logs: None
- Prohibitions:
  - NO hardcoded paths
  - NO filesystem fallbacks - manifests are the source of truth
*/
