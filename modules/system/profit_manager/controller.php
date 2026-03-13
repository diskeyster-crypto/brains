<?php
declare(strict_types=1);

namespace Modules\System\ProfitManager;

use Core\System\SystemPaths;
use Core\Auth\Auth;
use Core\System\System;

/**
 * Profit Manager Controller
 * 
 * UI controller for Profit Manager (P2 контур).
 * Handles admin interface for trailing/profit lock monitoring.
 * 
 * Note: No UI yet per ТЗ - just shows runtime/storage JSON data.
 */
final class ProfitManagerController
{
    private ?string $moduleBase = null;
    private ?string $storageDir = null;
    private array $config = [];
    private ?string $configError = null;
    
    public function __construct()
    {
        $this->moduleBase = $this->resolveModuleBase();
        
        if ($this->moduleBase === null) {
            $this->configError = 'module_base_unknown';
            return;
        }
        
        $this->storageDir = $this->moduleBase . '/storage';
        $this->config = $this->loadConfig();
    }
    
    /**
     * Resolve module base path via SystemPaths
     */
    private function resolveModuleBase(): ?string
    {
        $paths = SystemPaths::instance();
        
        $candidates = ['system.profit_manager', 'trading.profit_manager'];
        
        foreach ($candidates as $key) {
            try {
                if ($paths->has($key)) {
                    $path = $paths->get($key);
                    if (is_string($path) && $path !== '' && is_dir($path)) {
                        return rtrim($path, '/');
                    }
                }
            } catch (\Throwable $e) {
                // Continue
            }
        }
        
        return null;
    }
    
    /**
     * Load configuration
     */
    private function loadConfig(): array
    {
        $config = [];
        
        $configPath = $this->moduleBase . '/config/config.php';
        if (is_file($configPath)) {
            $loaded = require $configPath;
            if (is_array($loaded)) {
                $config = $loaded;
            }
        }
        
        return $config;
    }
    
    /**
     * Main index page
     */
    public function index(): string
    {
        if (!Auth::check()) {
            header('Location: ' . System::web('admin/login'));
            exit;
        }
        
        $data = [
            'title' => 'Profit Manager',
            'module_base' => $this->moduleBase,
            'config_error' => $this->configError,
            'config' => $this->config,
            'last_run' => $this->loadLastRun(),
            'status' => $this->loadStatus(),
            'applied_index' => $this->loadAppliedIndex(),
        ];
        
        return $this->render('index', $data);
    }
    
    /**
     * API: Get status
     */
    public function apiStatus(): void
    {
        header('Content-Type: application/json');
        
        $data = [
            'ok' => true,
            'config' => $this->config,
            'last_run' => $this->loadLastRun(),
            'status' => $this->loadStatus(),
        ];
        
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    
    /**
     * API: Get applied index (history)
     */
    public function apiAppliedIndex(): void
    {
        header('Content-Type: application/json');
        
        $data = [
            'ok' => true,
            'applied_index' => $this->loadAppliedIndex(),
        ];
        
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    
    /**
     * Load last_run.json
     */
    private function loadLastRun(): ?array
    {
        $path = $this->storageDir . '/runtime/last_run.json';
        if (!is_file($path)) {
            return null;
        }
        
        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }
        
        $data = @json_decode($content, true);
        return is_array($data) ? $data : null;
    }
    
    /**
     * Load status.json
     */
    private function loadStatus(): ?array
    {
        $path = $this->storageDir . '/runtime/status.json';
        if (!is_file($path)) {
            return null;
        }
        
        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }
        
        $data = @json_decode($content, true);
        return is_array($data) ? $data : null;
    }
    
    /**
     * Load applied_index.json
     */
    private function loadAppliedIndex(): ?array
    {
        $path = $this->storageDir . '/runtime/applied_index.json';
        if (!is_file($path)) {
            return null;
        }
        
        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }
        
        $data = @json_decode($content, true);
        return is_array($data) ? $data : null;
    }
    
    /**
     * Render a view
     */
    private function render(string $view, array $data = []): string
    {
        $viewPath = $this->moduleBase . '/views/' . $view . '.php';
        
        if (!is_file($viewPath)) {
            // Fallback: simple JSON output
            return '<pre>' . htmlspecialchars(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre>';
        }
        
        extract($data);
        ob_start();
        include $viewPath;
        return ob_get_clean();
    }
}
