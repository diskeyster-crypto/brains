<?php

declare(strict_types=1);

namespace Modules\System\Theme;

use Core\System\System;
use ZipArchive;

/**
 * Theme Export Service
 * 
 * Handles export and import of themes via ZIP archives.
 * 
 * @package Modules\System\Theme
 */
class ThemeExport
{
    private static ?self $instance = null;
    
    private string $themesPath;
    private string $tempPath;
    
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct()
    {
        $this->themesPath = System::path('root') . '/storage/system/themes';
        $this->tempPath = System::path('root') . '/runtime/temp';
        
        if (!is_dir($this->tempPath)) {
            mkdir($this->tempPath, 0755, true);
        }
    }
    
    /**
     * Export theme to ZIP file
     */
    public function toZip(string $themeId): ?string
    {
        require_once __DIR__ . '/theme.loader.php';
        
        $theme = ThemeLoader::instance()->load($themeId);
        if (!$theme) {
            return null;
        }
        
        $themePath = $theme['path'];
        $zipName = 'theme-' . $themeId . '-' . date('Ymd-His') . '.zip';
        $zipPath = $this->tempPath . '/' . $zipName;
        
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return null;
        }
        
        // Add all files from theme directory
        $this->addDirectoryToZip($zip, $themePath, $themeId);
        
        $zip->close();
        
        return $zipPath;
    }
    
    /**
     * Import theme from ZIP file
     */
    public function fromZip(string $zipPath): ?string
    {
        if (!file_exists($zipPath)) {
            return null;
        }
        
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return null;
        }
        
        // Extract to temp directory first
        $extractPath = $this->tempPath . '/import-' . uniqid();
        if (!is_dir($extractPath)) {
            mkdir($extractPath, 0755, true);
        }
        
        $zip->extractTo($extractPath);
        $zip->close();
        
        // Find theme.json in extracted files
        $themeJson = $this->findThemeJson($extractPath);
        if (!$themeJson) {
            $this->deleteDirectory($extractPath);
            return null;
        }
        
        // Read theme data
        $themeData = json_decode(file_get_contents($themeJson), true);
        if (!$themeData || empty($themeData['id'])) {
            $this->deleteDirectory($extractPath);
            return null;
        }
        
        $themeId = $themeData['id'];
        $sourceDir = dirname($themeJson);
        
        // Check if theme already exists
        $targetPath = $this->themesPath . '/' . $themeId;
        if (is_dir($targetPath)) {
            // Generate new ID
            $themeId = $themeId . '-imported-' . date('Ymd');
            $themeData['id'] = $themeId;
            $themeData['name'] = $themeData['name'] . ' (Imported)';
            $targetPath = $this->themesPath . '/' . $themeId;
        }
        
        // Move to themes directory
        if (!is_dir($targetPath)) {
            mkdir($targetPath, 0755, true);
        }
        
        // Copy all files
        $files = scandir($sourceDir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $src = $sourceDir . '/' . $file;
            $dst = $targetPath . '/' . $file;
            if (is_file($src)) {
                copy($src, $dst);
            }
        }
        
        // Update theme.json with new ID if needed
        file_put_contents(
            $targetPath . '/theme.json',
            json_encode($themeData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        
        // Clean up
        $this->deleteDirectory($extractPath);
        
        return $themeId;
    }
    
    /**
     * Add directory contents to ZIP
     */
    private function addDirectoryToZip(ZipArchive $zip, string $path, string $prefix = ''): void
    {
        if (!is_dir($path)) return;
        
        $files = scandir($path);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $filePath = $path . '/' . $file;
            $zipPath = $prefix ? $prefix . '/' . $file : $file;
            
            if (is_dir($filePath)) {
                $zip->addEmptyDir($zipPath);
                $this->addDirectoryToZip($zip, $filePath, $zipPath);
            } else {
                $zip->addFile($filePath, $zipPath);
            }
        }
    }
    
    /**
     * Find theme.json in extracted directory
     */
    private function findThemeJson(string $path): ?string
    {
        // Check direct path
        if (file_exists($path . '/theme.json')) {
            return $path . '/theme.json';
        }
        
        // Check subdirectories (one level)
        $dirs = scandir($path);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            $subPath = $path . '/' . $dir;
            if (is_dir($subPath) && file_exists($subPath . '/theme.json')) {
                return $subPath . '/theme.json';
            }
        }
        
        return null;
    }
    
    /**
     * Recursively delete directory
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
    
    /**
     * Get download headers for ZIP file
     */
    public function getDownloadHeaders(string $filename): array
    {
        return [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="' . basename($filename) . '"',
            'Content-Length' => (string) filesize($filename),
            'Cache-Control' => 'no-cache, must-revalidate',
            'Pragma' => 'no-cache'
        ];
    }
}
