<?php

declare(strict_types=1);

/**
 * Corridor Bottom Long — Service
 *
 * Thin orchestration layer called by CronManager.
 * Delegates all work to CorridorBottomLongStrategy.
 *
 * Handler:
 *   tickRun — execute one full scan cycle.
 *             Respects the 'enabled' flag in config/active.php (merged with base.php).
 *             When enabled = false the scan is silently skipped.
 *             When handoff_enabled = true the strategy also writes bot_handoff_queue.json.
 */

namespace Modules\Strategy\CorridorBottomLong;

require_once __DIR__ . '/lib/validation_engine.php';
require_once __DIR__ . '/lib/pattern_detector.php';
require_once __DIR__ . '/strategy.php';

use Core\Logger\Logger;

final class CorridorBottomLongService
{
    private string $moduleDir;

    public function __construct(?string $moduleDir = null)
    {
        $this->moduleDir = $moduleDir !== null
            ? rtrim($moduleDir, '/')
            : rtrim(__DIR__, '/');
    }

    /**
     * Called by CronManager every 60 seconds.
     * Runs the full corridor-bottom-long scan cycle.
     */
    public function tickRun(): void
    {
        try {
            $strategy = new CorridorBottomLongStrategy($this->moduleDir);
            $result   = $strategy->run();

            if (!($result['ok'] ?? false)) {
                $error = $result['error'] ?? 'unknown';
                // strategy_disabled is expected when enabled = false — not an error.
                if ($error !== 'strategy_disabled') {
                    Logger::cron('corridor_bottom_long tickRun error', ['error' => $error]);
                }
                return;
            }

            $stats = $result['stats'] ?? [];
            Logger::cron('corridor_bottom_long tickRun completed', [
                'symbols_checked'         => $stats['symbols_checked']         ?? 0,
                'generated_signals_count' => $stats['generated_signals_count'] ?? 0,
                'handoff_enabled'         => $stats['handoff_enabled']         ?? false,
                'handoff_ready'           => $stats['handoff_ready']           ?? 0,
            ]);
        } catch (\Throwable $e) {
            Logger::cron('corridor_bottom_long tickRun exception', ['error' => $e->getMessage()]);
        }
    }
}
