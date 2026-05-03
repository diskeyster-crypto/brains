<?php

declare(strict_types=1);

/**
 * Dynamic Strategies — Service
 *
 * Thin orchestration layer called by CronManager.
 * Delegates all evaluation work to DynamicStrategiesStrategy.
 *
 * Handler:
 *   tickRun — execute one full evaluation cycle.
 *             Respects the 'enabled' flag in config/active.php (merged with base.php).
 *             When handoff_enabled = false: diagnostics only, no executable handoff.
 *             When handoff_enabled = true AND emit_bot_handoff = true AND mode = demo:
 *             the strategy writes executable rows to bot_handoff_queue.json.
 *             Live execution additionally requires live_enabled = true AND live_handoff_enabled = true.
 */

namespace Modules\Strategy\DynamicStrategies;

require_once __DIR__ . '/strategy.php';

use Core\Logger\Logger;

final class DynamicStrategiesService
{
    private string $moduleDir;

    public function __construct(?string $moduleDir = null)
    {
        $this->moduleDir = $moduleDir !== null
            ? rtrim($moduleDir, '/')
            : rtrim(__DIR__, '/');
    }

    /**
     * Batch tick alias — calls tickRun() and returns the same result.
     * Dynamic Strategies does not have a separate batch registry cursor;
     * this method exists for UI/action naming consistency with other strategy modules.
     */
    public function tickBatch(): array
    {
        return $this->tickRun();
    }

    /**
     * Called by CronManager every 60 seconds.
     * Runs the full dynamic-strategies evaluation cycle.
     * Returns the strategy result array so callers (e.g. dashboard action handler)
     * can read stats without duplicating the run logic.
     * CronManager ignores the return value, so the void→array change is backwards-compatible.
     */
    public function tickRun(): array
    {
        try {
            $strategy = new DynamicStrategiesStrategy($this->moduleDir);
            $result   = $strategy->run();

            if (!($result['ok'] ?? false)) {
                $error = $result['error'] ?? 'unknown';
                // strategy_disabled is expected when enabled = false — not an error.
                if ($error !== 'strategy_disabled') {
                    Logger::cron('dynamic_strategies tickRun error', ['error' => $error]);
                }
                return $result;
            }

            $stats = $result['stats'] ?? [];
            Logger::cron('dynamic_strategies tickRun completed', [
                'useful_contexts_total'   => $stats['useful_contexts_total']   ?? 0,
                'candidates_total'        => $stats['candidates_total']        ?? 0,
                'signals_total'           => $stats['signals_total']           ?? 0,
                'executable_signals_total' => $stats['executable_signals_total'] ?? 0,
                'bot_handoff_ready_total' => $stats['bot_handoff_ready_total'] ?? 0,
                'mode'                    => $stats['mode']                    ?? 'demo',
                'side_mode'               => $stats['side_mode']               ?? 'short',
                'handoff_enabled'         => $stats['handoff_enabled']         ?? true,
            ]);

            return $result;
        } catch (\Throwable $e) {
            Logger::cron('dynamic_strategies tickRun exception', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
