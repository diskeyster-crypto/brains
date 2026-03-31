<?php
declare(strict_types=1);

/**
 * AiShadowJournal
 *
 * Decision journal for the AI Shadow research module.
 *
 * Every significant decision step is journaled so the full AI reasoning
 * timeline for each signal/trade can be inspected.
 *
 * Storage: storage/journal/{signal_id}.json
 * Each file is an array of journal events (append-only).
 *
 * Journal event types:
 *  signal_seen, ai_input_built, ai_request_sent, ai_response_received,
 *  ai_decision_enter, ai_decision_skip, ai_decision_hold, ai_decision_harvest,
 *  ai_decision_close, virtual_trade_opened, virtual_trade_updated,
 *  virtual_trade_closed, comparison_finalized, provider_error
 */
final class AiShadowJournal
{
    private AiShadowStateManager $state;

    public function __construct(AiShadowStateManager $state)
    {
        $this->state = $state;
    }

    /**
     * Append a journal event for a signal/trade.
     *
     * @param string               $signalId  Live signal id (used as journal file key)
     * @param string               $eventType Event type constant
     * @param array<string,mixed>  $payload   Event-specific data (sanitized — no raw API keys)
     */
    public function record(string $signalId, string $eventType, array $payload = []): void
    {
        if ($signalId === '') {
            return;
        }

        $relPath = 'storage/journal/' . $signalId . '.json';

        $events = $this->state->readJson($relPath, []);

        $event = array_merge([
            'ts'         => time(),
            'event_type' => $eventType,
            'signal_id'  => $signalId,
        ], $payload);

        $events[] = $event;

        $this->state->writeJson($relPath, $events);
    }

    /**
     * Retrieve the full journal for a signal.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getJournal(string $signalId): array
    {
        if ($signalId === '') {
            return [];
        }
        $data = $this->state->readJson('storage/journal/' . $signalId . '.json', []);
        return is_array($data) ? $data : [];
    }

    /**
     * List all journal files (returns list of signal ids with journal data).
     *
     * @return array<int,string>
     */
    public function listSignalIds(): array
    {
        $dir    = $this->state->resolvePath('storage/journal');
        $result = [];
        if (!is_dir($dir)) {
            return $result;
        }
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $basename = basename($file, '.json');
            if ($basename !== '.gitkeep') {
                $result[] = $basename;
            }
        }
        sort($result);
        return $result;
    }

    /**
     * Load recent journal entries across all signals, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getRecentEvents(int $limit = 100): array
    {
        $dir = $this->state->resolvePath('storage/journal');
        if (!is_dir($dir)) {
            return [];
        }

        $all = [];
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $data = json_decode((string)file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }
            foreach ($data as $event) {
                if (is_array($event)) {
                    $all[] = $event;
                }
            }
        }

        // Sort newest first
        usort($all, static function (array $a, array $b): int {
            return ((int)($b['ts'] ?? 0)) <=> ((int)($a['ts'] ?? 0));
        });

        return array_slice($all, 0, $limit);
    }

    /**
     * Count total journal events.
     */
    public function countEvents(): int
    {
        $dir = $this->state->resolvePath('storage/journal');
        if (!is_dir($dir)) {
            return 0;
        }
        $count = 0;
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $data = json_decode((string)file_get_contents($file), true);
            if (is_array($data)) {
                $count += count($data);
            }
        }
        return $count;
    }

    /**
     * Clear all journal files (testing only).
     */
    public function clearAll(): void
    {
        $dir = $this->state->resolvePath('storage/journal');
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            if (basename($file) !== '.gitkeep') {
                unlink($file);
            }
        }
    }
}
