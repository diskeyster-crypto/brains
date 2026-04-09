<?php
declare(strict_types=1);

/**
 * AiShadowService
 *
 * Thin wrapper around AiShadowCore that resolves the module base path
 * and exposes all core operations.
 */
final class AiShadowService
{
    private AiShadowCore $core;

    public function __construct()
    {
        $moduleBase = __DIR__;

        require_once $moduleBase . '/lib/ai_shadow_state_manager.php';
        require_once $moduleBase . '/lib/ai_provider_interface.php';
        require_once $moduleBase . '/lib/ai_provider_mock.php';
        require_once $moduleBase . '/lib/ai_provider_openai.php';
        require_once $moduleBase . '/lib/ai_shadow_journal.php';
        require_once $moduleBase . '/lib/virtual_lifecycle.php';
        require_once $moduleBase . '/lib/signal_mirror.php';
        require_once $moduleBase . '/lib/trade_mirror.php';
        require_once $moduleBase . '/lib/stats_engine.php';
        require_once $moduleBase . '/lib/ai_shadow_core.php';

        $this->core = new AiShadowCore($moduleBase);
    }

    /**
     * @return array<string,mixed>
     */
    public function runLiveMirror(): array
    {
        return $this->core->runLiveMirror();
    }

    /**
     * @param  array<int,array<string,mixed>> $historicalSignals
     * @return array<string,mixed>
     */
    public function runReplay(array $historicalSignals = []): array
    {
        return $this->core->runReplay($historicalSignals);
    }

    /**
     * @return array<string,mixed>
     */
    public function getStats(): array
    {
        return $this->core->getStats();
    }

    /**
     * @param  array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function getVirtualSignals(array $filters = []): array
    {
        return $this->core->getVirtualSignals($filters);
    }

    /**
     * @param  array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function getVirtualActiveTrades(array $filters = []): array
    {
        return $this->core->getVirtualActiveTrades($filters);
    }

    /**
     * @param  array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function getVirtualClosedTrades(array $filters = []): array
    {
        return $this->core->getVirtualClosedTrades($filters);
    }

    public function clearStorage(): void
    {
        $this->core->clearStorage();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getRecentJournalEvents(int $limit = 100): array
    {
        return $this->core->getRecentJournalEvents($limit);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getSignalJournal(string $signalId): array
    {
        return $this->core->getSignalJournal($signalId);
    }

    /**
     * Run a demo pass that seeds virtual trade artifacts for all stored
     * enter-decision virtual signals. Used for bootstrap/testing when
     * no live trading_bot trades exist. Purely research artifacts — no live authority.
     *
     * @return array<string,mixed>
     */
    public function runDemoWithTrades(): array
    {
        return $this->core->runDemoWithTrades();
    }

    /**
     * Test the AI provider connection.
     *
     * @return array<string,mixed>
     */
    public function testConnection(): array
    {
        return $this->core->testConnection();
    }

    /**
     * @return array<string,mixed>
     */
    public function getStatus(): array
    {
        return $this->core->getStatus();
    }

    /**
     * Cron entry point: run live mirror cycle for automatic local evidence accumulation.
     * Called by CronManager (ai_shadow:execute).
     *
     * @return array<string,mixed>
     */
    public function execute(): array
    {
        return $this->runLiveMirror();
    }
}
