<?php

declare(strict_types=1);

namespace Modules\ProfManager\Lib;

/**
 * PositionReader
 *
 * Reads active/open positions from the bot module's paper runtime source.
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
        $candidates = [
            'modules/bot/storage/active_positions.json',
        ];

        foreach ($candidates as $relPath) {
            $absPath = $this->repoRoot . '/' . $relPath;
            if (is_file($absPath) && is_readable($absPath)) {
                $raw = file_get_contents($absPath);
                if ($raw === false) {
                    continue;
                }
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    continue;
                }
                // Support both indexed array and keyed object
                $positions = array_values($decoded);
                return [
                    'positions'   => $positions,
                    'source'      => $relPath,
                    'diagnostics' => [
                        'path'  => $absPath,
                        'count' => count($positions),
                    ],
                ];
            }
        }

        return [
            'positions'   => [],
            'source'      => 'none',
            'diagnostics' => [
                'reason'     => 'no_compatible_source_found',
                'candidates' => $candidates,
            ],
        ];
    }
}
