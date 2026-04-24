<?php

declare(strict_types=1);

namespace Modules\ProfManager\Lib;

/**
 * PositionReader
 *
 * Reads active/open positions from the bot module's paper runtime source.
 * Tries multiple candidate paths in order; uses the first non-empty valid source.
 * Returns an empty list with diagnostics when no compatible source is found.
 *
 * Does NOT invent fake positions.
 */
class PositionReader
{
    private string $repoRoot;

    public function __construct(string $repoRoot)
    {
        $this->repoRoot = rtrim($repoRoot, '/');
    }

    /**
     * Read active positions.
     *
     * @return array{positions: list<array>, source: string, diagnostics: array}
     */
    public function read(): array
    {
        // Fixed candidate paths
        $fixedCandidates = [
            'modules/bot/storage/active_positions.json',
        ];

        // Dynamic candidates: glob strategy sub-modules
        $strategyCandidates = $this->resolveStrategyCandidates();

        // Executor bot last_run as fallback
        $executorCandidates = [
            'modules/trading/executor_bot/storage/last_run.json',
        ];

        $allCandidates = array_merge($fixedCandidates, $strategyCandidates, $executorCandidates);
        $tried = [];

        foreach ($allCandidates as $relPath) {
            $absPath = $this->repoRoot . '/' . $relPath;
            $tried[] = $relPath;

            if (!is_file($absPath) || !is_readable($absPath)) {
                continue;
            }

            $raw = file_get_contents($absPath);
            if ($raw === false || $raw === '') {
                continue;
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }

            // executor_bot last_run may have a 'positions' key
            if (str_contains($relPath, 'last_run.json') && isset($decoded['positions']) && is_array($decoded['positions'])) {
                $decoded = $decoded['positions'];
            }

            $raw_positions = array_values($decoded);
            if (empty($raw_positions)) {
                continue;
            }

            $positions = $this->normalizePositions($raw_positions);

            return [
                'positions'   => $positions,
                'source'      => $relPath,
                'diagnostics' => [
                    'path'  => $absPath,
                    'count' => count($positions),
                    'tried' => $tried,
                ],
            ];
        }

        return [
            'positions'   => [],
            'source'      => 'none',
            'diagnostics' => [
                'reason'     => 'no_positions_source_found',
                'candidates' => $allCandidates,
                'tried'      => $tried,
            ],
        ];
    }

    /**
     * Resolve dynamic strategy sub-module candidates.
     *
     * @return list<string>
     */
    private function resolveStrategyCandidates(): array
    {
        $candidates = [];
        $strategyRoot = $this->repoRoot . '/modules/strategy';

        if (!is_dir($strategyRoot)) {
            return $candidates;
        }

        $dirs = glob($strategyRoot . '/*/storage', GLOB_ONLYDIR);
        if (!is_array($dirs)) {
            return $candidates;
        }

        foreach ($dirs as $storageDir) {
            $base = str_replace($this->repoRoot . '/', '', $storageDir);
            $candidates[] = $base . '/bot_active_positions.json';
            $candidates[] = $base . '/active_positions.json';
        }

        return $candidates;
    }

    /**
     * Normalize position fields to a canonical form.
     *
     * Mappings:
     *   qty → size
     *   avg_price → entry_price (if entry_price not set)
     *
     * If current_price / mark_price is missing, position is kept but
     * tagged with no_price_data so the planner can skip ROI calculation.
     *
     * @param list<mixed> $raw
     * @return list<array>
     */
    private function normalizePositions(array $raw): array
    {
        $result = [];
        foreach ($raw as $pos) {
            if (!is_array($pos)) {
                continue;
            }

            // qty → size
            if (!isset($pos['size']) && isset($pos['qty'])) {
                $pos['size'] = $pos['qty'];
            }

            // avg_price → entry_price
            if (!isset($pos['entry_price']) && isset($pos['avg_price'])) {
                $pos['entry_price'] = $pos['avg_price'];
            }

            // tag positions lacking price data
            $currentPrice = (float) ($pos['current_price'] ?? $pos['mark_price'] ?? 0.0);
            if ($currentPrice <= 0.0) {
                $pos['_no_price_data'] = true;
            }

            $result[] = $pos;
        }
        return $result;
    }
}
