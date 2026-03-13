<?php

declare(strict_types=1);

namespace Modules\System\Pages;

use Core\System\System;
use Core\Auth\Auth;

require_once __DIR__ . '/registry.php';

/**
 * Pages Manager Controller
 * 
 * Admin routes registry management.
 */
final class PagesController
{
    private static ?self $instance = null;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Pages list
     */
    public function index(): void
    {
        if (!Auth::check()) {
            header('Location: ' . System::adminUrl('login'));
            exit;
        }

        $registry = PagesRegistry::instance();
        
        $data = [
            'pages' => $registry->all(),
            'total' => $registry->count(),
            'system_count' => $registry->countByType('system'),
            'module_count' => $registry->countByType('module'),
            'custom_count' => $registry->countByType('custom'),
        ];

        $this->render('index', 'Менеджер страниц', $data);
    }

    /**
     * Toggle page visibility
     */
    public function toggle(): void
    {
        if (!Auth::check()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }

        $id = (int)($_POST['id'] ?? 0);
        $visible = ($_POST['visible'] ?? 'true') === 'true';

        try {
            $registry = PagesRegistry::instance();
            $registry->setVisible($id, $visible);

            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
        } catch (\Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /**
     * Lock/unlock page
     */
    public function lock(): void
    {
        if (!Auth::check()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }

        $id = (int)($_POST['id'] ?? 0);
        $lock = ($_POST['lock'] ?? 'true') === 'true';

        try {
            $registry = PagesRegistry::instance();
            if ($lock) {
                $registry->lock($id);
            } else {
                $registry->unlock($id);
            }

            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
        } catch (\Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /**
     * Update page roles
     */
    public function roles(): void
    {
        if (!Auth::check()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }

        $id = (int)($_POST['id'] ?? 0);
        $roles = json_decode($_POST['roles'] ?? '[]', true);

        try {
            $registry = PagesRegistry::instance();
            $registry->setRoles($id, $roles);

            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
        } catch (\Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /**
     * Add new custom page
     */
    public function add(): void
    {
        if (!Auth::check()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }

        $title = trim($_POST['title'] ?? '');
        $route = trim($_POST['route'] ?? '');
        $icon = trim($_POST['icon'] ?? 'bi-circle');
        $order = (int)($_POST['order'] ?? 100);

        // Validate
        if (empty($title)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Название обязательно']);
            exit;
        }

        if (empty($route) || !str_starts_with($route, '/admin/')) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Маршрут должен начинаться с /admin/']);
            exit;
        }

        try {
            $registry = PagesRegistry::instance();
            
            // Check if route already exists
            if ($registry->getByRoute($route)) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Маршрут уже существует']);
                exit;
            }
            
            $id = $registry->register([
                'title' => $title,
                'route' => $route,
                'type' => 'custom',
                'roles' => ['admin'],
                'visible' => true,
                'icon' => $icon,
                'order' => $order,
                'locked' => false,
            ]);

            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'id' => $id]);
        } catch (\Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /**
     * Delete custom page
     */
    public function delete(): void
    {
        if (!Auth::check()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }

        $id = (int)($_POST['id'] ?? 0);

        try {
            $registry = PagesRegistry::instance();
            $registry->delete($id);

            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
        } catch (\Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ============================================================
    // RENDER HELPER
    // ============================================================

    private function render(string $view, string $title, array $data = []): void
    {
        $viewPath = System::path('root') . '/modules/system/pages/views/' . $view . '.php';
        
        if (!file_exists($viewPath)) {
            echo "View not found: {$view}";
            return;
        }
        
        // Extract data for view
        extract($data);
        
        // Capture view content
        ob_start();
        include $viewPath;
        $content = ob_get_clean();
        
        // Load layout
        $layoutPath = System::path('root') . '/admin/views/layout.php';
        require_once $layoutPath;
        
        // Render with layout
        echo renderLayout($title, $content, 'pages', [
            'page_title' => $title,
        ]);
    }
}
