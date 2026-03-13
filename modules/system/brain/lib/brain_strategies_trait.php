<?php
declare(strict_types=1);

namespace Modules\System\Brain\Lib;

/**
 * BrainStrategiesTrait - Strategy management methods
 * 
 * Contains: CRUD for strategies, versioning, toggles, export
 */
trait BrainStrategiesTrait
{
    /**
     * Get all strategies
     * 
     * @return array<string, array>
     */
    public function getStrategies(): array
    {
        $strategies = [];
        $files = glob($this->strategiesDir . '/*.json') ?: [];
        
        foreach ($files as $file) {
            $id = basename($file, '.json');
            $data = json_decode((string)file_get_contents($file), true);
            if (is_array($data)) {
                // ТЗ-2: Normalize timestamps (migrate old strategies)
                $needsSave = false;
                
                // Normalize created_ts
                if (empty($data['created_ts']) || !is_numeric($data['created_ts'])) {
                    if (!empty($data['created_at']) && is_string($data['created_at'])) {
                        $data['created_ts'] = strtotime($data['created_at']) ?: filemtime($file);
                    } else {
                        $data['created_ts'] = filemtime($file) ?: time();
                    }
                    $needsSave = true;
                }
                
                // Normalize updated_ts
                if (empty($data['updated_ts']) || !is_numeric($data['updated_ts'])) {
                    if (!empty($data['updated_at']) && is_string($data['updated_at'])) {
                        $data['updated_ts'] = strtotime($data['updated_at']) ?: $data['created_ts'];
                    } else {
                        $data['updated_ts'] = $data['created_ts'];
                    }
                    $needsSave = true;
                }
                
                // Ensure string timestamps exist for readability
                if (empty($data['created_at'])) {
                    $data['created_at'] = date('Y-m-d H:i:s', $data['created_ts']);
                    $needsSave = true;
                }
                if (empty($data['updated_at'])) {
                    $data['updated_at'] = date('Y-m-d H:i:s', $data['updated_ts']);
                    $needsSave = true;
                }
                
                // Save normalized strategy back to file
                if ($needsSave) {
                    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
                
                $strategies[$id] = $data;
            }
        }
        
        // Sort by created_ts desc (prefer int timestamp)
        uasort($strategies, fn($a, $b) => 
            ($b['created_ts'] ?? strtotime($b['created_at'] ?? '2000-01-01')) 
            - ($a['created_ts'] ?? strtotime($a['created_at'] ?? '2000-01-01'))
        );
        
        return $strategies;
    }
    
    /**
     * Get single strategy
     */
    public function getStrategy(string $id): ?array
    {
        $file = $this->strategiesDir . '/' . $this->sanitizeId($id) . '.json';
        if (!is_file($file)) {
            return null;
        }
        
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }
    
    /**
     * Save strategy (wishes/constraints, NOT formulas)
     * 
     * Strategy = description of trader's wishes, passed to Parser5 as-is.
     * Brain does NOT interpret these numbers mathematically.
     * 
     * Enhanced with: depends_on (Блок 2), versions (Блок 4), fitness_score (Блок 5)
     * 
     * @param array $data Strategy data
     * @return array Result with success/error
     */
    public function saveStrategy(array $data): array
    {
        // Generate or use existing ID
        $id = !empty($data['id']) 
            ? $this->sanitizeId($data['id']) 
            : 'strategy_' . date('Ymd_His') . '_' . substr(md5(uniqid()), 0, 6);
        
        // Validate required fields
        if (empty($data['name'])) {
            return ['success' => false, 'error' => 'Strategy name is required'];
        }
        
        // Load existing strategy for versioning (Блок 4)
        $existingStrategy = $this->getStrategy($id);
        $currentVersion = ($existingStrategy['current_version'] ?? 0) + 1;
        
        // Build constraints array
        $constraints = [
            'target_roi_pct' => (float)($data['target_roi_pct'] ?? $data['constraints']['target_roi_pct'] ?? 5.0),
            'max_drawdown_pct' => (float)($data['max_drawdown_pct'] ?? $data['constraints']['max_drawdown_pct'] ?? 30.0),
            'session' => (string)($data['session'] ?? $data['constraints']['session'] ?? 'any'),
            'duration_max_min' => (int)($data['duration_max_min'] ?? $data['constraints']['duration_max_min'] ?? 120),
            'direction' => (string)($data['direction'] ?? $data['constraints']['direction'] ?? 'both'),
        ];
        
        // Build strategy structure (pure data, NO calculations)
        $nowTs = time();
        $strategy = [
            'id' => $id,
            'name' => (string)($data['name'] ?? 'Unnamed Strategy'),
            'description' => (string)($data['description'] ?? ''),
            // String timestamps for readability
            'created_at' => $existingStrategy['created_at'] ?? $data['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            // Unix timestamps for UI safety (int)
            'created_ts' => $existingStrategy['created_ts'] ?? $data['created_ts'] ?? $nowTs,
            'updated_ts' => $nowTs,
            
            // Control flags
            'enabled_for_simulator' => (bool)($data['enabled_for_simulator'] ?? true),
            'enabled_for_executor' => (bool)($data['enabled_for_executor'] ?? false),
            
            // Constraints (wishes) - passed to Parser5 as-is
            'constraints' => $constraints,
            
            // Parser5 profile override (optional, passed to Parser5 directly)
            'parser5_profile' => $data['parser5_profile'] ?? null,
            
            // Performance tracking (updated from Simulator feedback, NOT calculated by Brain)
            'performance' => $data['performance'] ?? $existingStrategy['performance'] ?? [
                'total_signals' => 0,
                'profitable' => 0,
                'win_rate' => 0.0,
                'avg_roi' => 0.0,
                'avg_drawdown' => 0.0,
                'last_evaluated' => null,
            ],
            
            // Strategy dependencies (Блок 2 - Strategy Graph)
            'depends_on' => $data['depends_on'] ?? $existingStrategy['depends_on'] ?? [],
            
            // Versioning (Блок 4)
            'current_version' => $currentVersion,
            'versions' => $existingStrategy['versions'] ?? [],
            
            // Fitness score (Блок 5)
            'fitness_score' => $data['fitness_score'] ?? $existingStrategy['fitness_score'] ?? null,
            
            // Auto-generated flag (Блок 6 - Meta-Engine)
            'auto_generated' => $data['auto_generated'] ?? $existingStrategy['auto_generated'] ?? false,
            'generation_params' => $data['generation_params'] ?? $existingStrategy['generation_params'] ?? null,
        ];
        
        // Create version entry if strategy changed (Блок 4)
        if ($existingStrategy !== null) {
            $oldProfile = $existingStrategy['parser5_profile'] ?? null;
            $newProfile = $strategy['parser5_profile'];
            $oldConstraints = $existingStrategy['constraints'] ?? [];
            
            // Check if strategy actually changed
            if ($oldProfile !== $newProfile || $oldConstraints !== $constraints) {
                $strategy['versions'][] = [
                    'v' => $currentVersion,
                    'created_at' => date('Y-m-d H:i:s'),
                    'constraints' => $constraints,
                    'profile' => $newProfile,
                    'fitness_score' => $strategy['fitness_score'],
                ];
                
                // Keep only last 20 versions
                if (count($strategy['versions']) > 20) {
                    $strategy['versions'] = array_slice($strategy['versions'], -20);
                }
            }
        } else {
            // First version
            $strategy['versions'][] = [
                'v' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'constraints' => $constraints,
                'profile' => $strategy['parser5_profile'],
                'fitness_score' => null,
            ];
        }
        
        // Save to file
        $file = $this->strategiesDir . '/' . $id . '.json';
        $result = file_put_contents($file, json_encode($strategy, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        if ($result === false) {
            return ['success' => false, 'error' => 'Failed to save strategy file'];
        }
        
        $this->log("Strategy saved: {$id} - {$strategy['name']} (v{$currentVersion})");
        
        return [
            'success' => true,
            'id' => $id,
            'version' => $currentVersion,
            'strategy' => $strategy,
        ];
    }
    
    /**
     * Delete strategy
     */
    public function deleteStrategy(string $id): array
    {
        $id = $this->sanitizeId($id);
        $file = $this->strategiesDir . '/' . $id . '.json';
        
        if (!is_file($file)) {
            return ['success' => false, 'error' => 'Strategy not found'];
        }
        
        if (!unlink($file)) {
            return ['success' => false, 'error' => 'Failed to delete strategy file'];
        }
        
        $this->log("Strategy deleted: {$id}");
        
        return ['success' => true];
    }
    
    /**
     * Toggle strategy for simulator
     */
    public function toggleSimulator(string $id, bool $enabled): array
    {
        return $this->updateStrategyFlag($id, 'enabled_for_simulator', $enabled);
    }
    
    /**
     * Toggle strategy for executor
     */
    public function toggleExecutor(string $id, bool $enabled): array
    {
        return $this->updateStrategyFlag($id, 'enabled_for_executor', $enabled);
    }
    
    /**
     * Update a strategy flag
     */
    private function updateStrategyFlag(string $id, string $flag, bool $value): array
    {
        $strategy = $this->getStrategy($id);
        if ($strategy === null) {
            return ['success' => false, 'error' => 'Strategy not found'];
        }
        
        $strategy[$flag] = $value;
        $strategy['updated_at'] = date('Y-m-d H:i:s');
        $strategy['updated_ts'] = time();
        
        $file = $this->strategiesDir . '/' . $this->sanitizeId($id) . '.json';
        file_put_contents($file, json_encode($strategy, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        $this->log("Strategy {$flag}: {$id} - " . ($value ? 'enabled' : 'disabled'));
        
        return ['success' => true, $flag => $value];
    }
    
    /**
     * Get strategies enabled for simulator
     */
    public function getStrategiesForSimulator(): array
    {
        return array_filter($this->getStrategies(), fn($s) => $s['enabled_for_simulator'] ?? false);
    }
    
    /**
     * Get strategies enabled for executor (live trading)
     */
    public function getStrategiesForExecutor(): array
    {
        return array_filter($this->getStrategies(), fn($s) => $s['enabled_for_executor'] ?? false);
    }
    
    /**
     * Export strategy profiles for Parser5 to use
     * 
     * Creates a temporary file with all active strategy profiles.
     * Parser5 reads this file and applies the constraints.
     */
    private function exportStrategyProfiles(array $strategies): array
    {
        $profiles = [];
        foreach ($strategies as $id => $strategy) {
            $profiles[$id] = [
                'id' => $id,
                'name' => $strategy['name'],
                'constraints' => $strategy['constraints'] ?? [],
                'parser5_profile' => $strategy['parser5_profile'] ?? null,
            ];
        }
        
        $file = $this->storageDir . '/tmp/active_profiles.json';
        $data = [
            'exported_at' => date('Y-m-d H:i:s'),
            'count' => count($profiles),
            'profiles' => $profiles,
        ];
        
        $result = file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        return [
            'success' => $result !== false,
            'file' => $file,
        ];
    }
    
    /**
     * Get strategy version history (Блок 4)
     * 
     * @param string $strategyId Strategy ID
     * @return array Version history
     */
    public function getStrategyVersions(string $strategyId): array
    {
        $strategy = $this->getStrategy($strategyId);
        if ($strategy === null) {
            return [];
        }
        
        return $strategy['versions'] ?? [];
    }
    
    /**
     * Rollback strategy to a previous version (Блок 4)
     * 
     * @param string $strategyId Strategy ID
     * @param int $version Version number to rollback to
     * @return array Rollback result
     */
    public function rollbackStrategy(string $strategyId, int $version): array
    {
        $strategy = $this->getStrategy($strategyId);
        if ($strategy === null) {
            return ['success' => false, 'error' => 'Strategy not found'];
        }
        
        $versions = $strategy['versions'] ?? [];
        $targetVersion = null;
        
        foreach ($versions as $v) {
            if ($v['v'] === $version) {
                $targetVersion = $v;
                break;
            }
        }
        
        if ($targetVersion === null) {
            return ['success' => false, 'error' => "Version {$version} not found"];
        }
        
        // Create rollback entry
        $currentVersion = $strategy['current_version'] ?? 1;
        
        // Apply old version's data
        $strategy['constraints'] = $targetVersion['constraints'] ?? $strategy['constraints'];
        $strategy['parser5_profile'] = $targetVersion['profile'] ?? $strategy['parser5_profile'];
        
        // Save with new version number (rollback creates new version)
        $result = $this->saveStrategy($strategy);
        
        if ($result['success']) {
            $this->log("Strategy {$strategyId} rolled back from v{$currentVersion} to v{$version}");
        }
        
        return [
            'success' => $result['success'],
            'rolled_back_to' => $version,
            'new_version' => $result['version'] ?? null,
            'strategy' => $result['strategy'] ?? null,
        ];
    }
    
    /**
     * Compare two versions of a strategy (Блок 4)
     * 
     * @param string $strategyId Strategy ID
     * @param int $versionA Version A
     * @param int $versionB Version B
     * @return array Diff result
     */
    public function compareStrategyVersions(string $strategyId, int $versionA, int $versionB): array
    {
        $strategy = $this->getStrategy($strategyId);
        if ($strategy === null) {
            return ['success' => false, 'error' => 'Strategy not found'];
        }
        
        $versions = $strategy['versions'] ?? [];
        $verA = null;
        $verB = null;
        
        foreach ($versions as $v) {
            if ($v['v'] === $versionA) $verA = $v;
            if ($v['v'] === $versionB) $verB = $v;
        }
        
        if ($verA === null || $verB === null) {
            return ['success' => false, 'error' => 'One or both versions not found'];
        }
        
        $diff = [
            'strategy_id' => $strategyId,
            'version_a' => $versionA,
            'version_b' => $versionB,
            'constraints_diff' => [],
            'fitness_diff' => null,
        ];
        
        // Compare constraints
        $constraintsA = $verA['constraints'] ?? [];
        $constraintsB = $verB['constraints'] ?? [];
        
        $allKeys = array_unique(array_merge(array_keys($constraintsA), array_keys($constraintsB)));
        foreach ($allKeys as $key) {
            $valA = $constraintsA[$key] ?? null;
            $valB = $constraintsB[$key] ?? null;
            
            if ($valA !== $valB) {
                $diff['constraints_diff'][$key] = [
                    'v' . $versionA => $valA,
                    'v' . $versionB => $valB,
                ];
            }
        }
        
        // Compare fitness
        $fitnessA = $verA['fitness_score'] ?? null;
        $fitnessB = $verB['fitness_score'] ?? null;
        if ($fitnessA !== $fitnessB) {
            $diff['fitness_diff'] = [
                'v' . $versionA => $fitnessA,
                'v' . $versionB => $fitnessB,
            ];
        }
        
        return $diff;
    }
}

/* ==========================================================
   RULES (Tredercopis)
   1) CONFIG FIRST / ZERO-HARDCODE.
   2) Источник истины — код и файлы storage (не слова/описания).
   3) Любые пути — только через SystemPaths/PackMap; без жёстких относительных путей.
   4) LF-only.
========================================================== */
