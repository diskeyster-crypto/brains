<?php

declare(strict_types=1);

namespace Modules\ProfManager\Lib;

/**
 * PaperExecutor
 *
 * Accepts a batch of plans from ProfileLegacySafe and "executes" them in paper mode.
 *
 * Paper mode means:
 *   - would_set_profit_lock  → recorded in locks.json, no exchange call
 *   - would_move_profit_lock → recorded in locks.json, no exchange call
 *   - would_close_on_lock_touch → recorded as event in locks.json, no real close
 *   - skip → no write
 *
 * Returns a structured execution summary.
 */
class PaperExecutor
{
    /**
     * Execute a batch of plans.
     *
     * @param list<array> $plans           Plan records from ProfileLegacySafe
     * @param array       $currentLocks    Current locks.json content (keyed by position key)
     * @param int         $maxUpdatesPerRun Anti-spam: max writes per run
     * @return array{locks: array, summary: array, executed: list<array>, skipped: list<array>}
     */
    public function execute(array $plans, array $currentLocks, int $maxUpdatesPerRun = 20): array
    {
        $locks    = $currentLocks;
        $executed = [];
        $skipped  = [];
        $updates  = 0;

        foreach ($plans as $plan) {
            $action = $plan['action'] ?? 'skip';
            $key    = $this->positionKey($plan['symbol'] ?? '', $plan['side'] ?? '');

            if ($action === 'skip') {
                $skipped[] = [
                    'key'    => $key,
                    'reason' => $plan['skip_reason'] ?? 'skip',
                ];
                continue;
            }

            // Anti-spam gate
            if ($updates >= $maxUpdatesPerRun) {
                $skipped[] = [
                    'key'    => $key,
                    'reason' => 'max_updates_per_run_reached',
                    'action' => $action,
                ];
                continue;
            }

            if (in_array($action, ['would_set_profit_lock', 'would_move_profit_lock', 'would_close_on_lock_touch'], true)) {
                $locks[$key] = array_merge($locks[$key] ?? [], [
                    'symbol'       => $plan['symbol'] ?? '',
                    'side'         => $plan['side'] ?? '',
                    'lock_price'   => $plan['proposed_lock'],
                    'lock_roi'     => $plan['proposed_roi'],
                    'last_action'  => $action,
                    'current_roi'  => $plan['current_roi'],
                    'peak_roi'     => $plan['peak_roi'],
                    'updated_at'   => date('c'),
                    'updated_at_ts'=> time(),
                ]);

                $executed[] = [
                    'key'         => $key,
                    'action'      => $action,
                    'lock_price'  => $plan['proposed_lock'],
                    'lock_roi'    => $plan['proposed_roi'],
                    'current_roi' => $plan['current_roi'],
                    'peak_roi'    => $plan['peak_roi'],
                ];
                $updates++;
            }
        }

        return [
            'locks'    => $locks,
            'summary'  => [
                'total_plans'   => count($plans),
                'executed'      => $updates,
                'skipped'       => count($skipped),
            ],
            'executed' => $executed,
            'skipped'  => $skipped,
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function positionKey(string $symbol, string $side): string
    {
        return strtolower($symbol) . '_' . strtolower($side);
    }
}
