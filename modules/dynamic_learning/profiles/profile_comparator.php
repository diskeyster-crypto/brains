<?php

declare(strict_types=1);

namespace Modules\DynamicLearning\Profiles;

/**
 * Scaffold for comparing auto-generated profile vs strategy default config.
 *
 * An auto profile is only considered usable when:
 * - compared_to_default = true
 * - auto_not_worse_than_default = true
 *
 * Current state: diagnostic/scaffold only. No automatic application.
 */
final class ProfileComparator
{
    /**
     * Compare auto-generated profile performance against default config baseline.
     *
     * @param array<string,mixed> $autoProfile Current generated profile
     * @param array<string,mixed> $defaultBenchmark Default config outcome summary
     * @param array<string,mixed> $cfg Dynamic learning config
     * @return array{
     *     compared_to_default: bool,
     *     auto_not_worse_than_default: bool,
     *     auto_improvement_score: float|null,
     *     default_result_summary: array<string,mixed>,
     *     auto_candidate_result_summary: array<string,mixed>,
     *     comparison_note: string,
     * }
     */
    public static function compare(array $autoProfile, array $defaultBenchmark, array $cfg): array
    {
        if (!(bool)($cfg['compare_auto_vs_default_enabled'] ?? true)) {
            return [
                'compared_to_default' => false,
                'auto_not_worse_than_default' => false,
                'auto_improvement_score' => null,
                'default_result_summary' => [],
                'auto_candidate_result_summary' => [],
                'comparison_note' => 'comparison_disabled',
            ];
        }

        // Scaffold: comparison requires backtesting capability not yet implemented.
        // Return diagnostic placeholder until backtesting is available.
        $autoRules = count((array)($autoProfile['rules'] ?? []));
        $autoBad = (int)($autoProfile['bad_entries_total'] ?? 0);
        $autoGood = (int)($autoProfile['good_entries_total'] ?? 0);
        $defaultBad = (int)($defaultBenchmark['bad_entries_total'] ?? 0);
        $defaultGood = (int)($defaultBenchmark['good_entries_total'] ?? 0);

        $autoSummary = [
            'rules_total' => $autoRules,
            'bad_entries_total' => $autoBad,
            'good_entries_total' => $autoGood,
            'apply_mode' => (string)($autoProfile['apply_mode'] ?? 'observe_only'),
            'status' => (string)($autoProfile['status'] ?? 'candidate'),
        ];

        $defaultSummary = [
            'bad_entries_total' => $defaultBad,
            'good_entries_total' => $defaultGood,
            'note' => 'backtesting_not_yet_implemented',
        ];

        return [
            'compared_to_default' => false,
            'auto_not_worse_than_default' => false,
            'auto_improvement_score' => null,
            'default_result_summary' => $defaultSummary,
            'auto_candidate_result_summary' => $autoSummary,
            'comparison_note' => 'comparison_not_implemented',
        ];
    }
}
