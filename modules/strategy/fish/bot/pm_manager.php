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
     * @param  float  $defaultMarkPrice  Used when no live price feed is available
     * @return array  Tick summary
     */
    public function tick(float $defaultMarkPrice = 0.0): array
    {
        $summary = $this->positionManager->tick($defaultMarkPrice);
        $summary['pm_tick_at'] = date('c');
        return $summary;
    }
}
