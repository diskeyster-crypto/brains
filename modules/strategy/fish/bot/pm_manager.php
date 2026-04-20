<?php

declare(strict_types=1);

namespace Modules\Strategy\Fish\Bot;

/**
 * Fish Bot — PM Manager (v1)
 *
 * Top-level Fish position-management tick.
 * Delegates to FishPositionManager for per-position actions.
 *
 * In v1 the pm_profile = 'default' supports breakeven-only management.
 *
 * Called once per cron cycle after the executor tick.
 */
final class FishPmManager
{
    private FishPositionManager $positionManager;
    private FishBotStore        $store;
    private FishBotJournal      $journal;

    public function __construct(
        FishPositionManager $positionManager,
        FishBotStore        $store,
        FishBotJournal      $journal
    ) {
        $this->positionManager = $positionManager;
        $this->store           = $store;
        $this->journal         = $journal;
    }

    /**
     * Run one PM cycle.
     *
     * Phase 1 — Fill detection:
     *   Inspect all Fish-owned open orders. Detect fills, cancellations, and
     *   other terminal states. On fill: create position + attach SL/TP.
     *
     * Phase 2 — Position management:
     *   For each open Fish position: retry SL/TP attach if missing, evaluate
     *   breakeven condition.
     *
     * @param  float  $defaultMarkPrice  Used when no live price feed is available
     * @return array  Merged tick summary (fill detection + position management)
     */
    public function tick(float $defaultMarkPrice = 0.0): array
    {
        // Phase 1: detect fills and transition orders → positions
        $fillSummary = $this->positionManager->detectAndProcessFills();

        // Phase 2: manage open positions (SL/TP retry, breakeven)
        $pmSummary = $this->positionManager->tick($defaultMarkPrice);

        $summary = array_merge($fillSummary, $pmSummary);
        $summary['pm_tick_at'] = date('c');
        return $summary;
    }
}
