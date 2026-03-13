<?php
declare(strict_types=1);

namespace Modules\System\Brain\Lib;

/**
 * BrainGraphTrait - Strategy dependency graph (DAG) methods
 * 
 * Contains: DAG building, cycle detection, topological sort, execution order
 */
trait BrainGraphTrait
{
    /**
     * Build strategy dependency graph (DAG) - Блок 2
     * 
     * @return array Graph structure with nodes and edges
     */
    public function buildStrategyGraph(): array
    {
        $strategies = $this->getStrategies();
        
        $graph = [
            'nodes' => [],
            'edges' => [],
            'execution_order' => [],
            'has_cycles' => false,
            'errors' => [],
            // Block E: DAG Explosion Guard
            'dag_limits' => [
                'max_depth' => self::MAX_STRATEGY_DEPTH,
                'max_signals_per_chain' => self::MAX_SIGNALS_PER_CHAIN,
            ],
            'dag_violations' => [],
            'is_valid' => true,
        ];
        
        // Build nodes
        foreach ($strategies as $id => $strategy) {
            $graph['nodes'][$id] = [
                'id' => $id,
                'name' => $strategy['name'],
                'depends_on' => $strategy['depends_on'] ?? [],
                'enabled' => $strategy['enabled_for_simulator'] ?? false,
                'total_signals' => $strategy['performance']['total_signals'] ?? 0, // Block E
            ];
            
            // Build edges
            foreach ($strategy['depends_on'] ?? [] as $depId) {
                $graph['edges'][] = [
                    'from' => $depId,
                    'to' => $id,
                ];
                
                // Validate dependency exists
                if (!isset($strategies[$depId])) {
                    $graph['errors'][] = "Strategy {$id} depends on non-existent strategy: {$depId}";
                }
            }
        }
        
        // Detect cycles and compute execution order
        $cycleResult = $this->detectCyclesAndSort($graph['nodes']);
        $graph['has_cycles'] = $cycleResult['has_cycles'];
        $graph['execution_order'] = $cycleResult['order'];
        
        if ($cycleResult['has_cycles']) {
            $graph['errors'][] = "Cycle detected in strategy dependencies: " . implode(' -> ', $cycleResult['cycle']);
            $graph['is_valid'] = false;
        }
        
        // ====================================================================
        // Block E: DAG Explosion Guard - Check depth and signals limits
        // ====================================================================
        
        $depthResult = $this->calculateGraphDepths($graph['nodes']);
        $graph['node_depths'] = $depthResult['depths'];
        $graph['max_depth_found'] = $depthResult['max_depth'];
        
        // Check max depth limit
        if ($depthResult['max_depth'] > self::MAX_STRATEGY_DEPTH) {
            $violation = "DAG depth exceeded: max allowed = " . self::MAX_STRATEGY_DEPTH . 
                        ", found = " . $depthResult['max_depth'];
            $graph['dag_violations'][] = $violation;
            $graph['errors'][] = $violation;
            $graph['is_valid'] = false;
            $this->log("Block E: {$violation}", 'error');
        }
        
        // Check signals per chain limit
        $chainSignals = $this->calculateChainSignals($graph['nodes'], $graph['execution_order']);
        $graph['chain_signals'] = $chainSignals;
        
        if ($chainSignals['max_signals'] > self::MAX_SIGNALS_PER_CHAIN) {
            $violation = "Chain signals exceeded: max allowed = " . self::MAX_SIGNALS_PER_CHAIN . 
                        ", found = " . $chainSignals['max_signals'] . 
                        " (chain: " . implode(' -> ', $chainSignals['max_chain']) . ")";
            $graph['dag_violations'][] = $violation;
            $graph['errors'][] = $violation;
            $graph['is_valid'] = false;
            $this->log("Block E: {$violation}", 'error');
        }
        
        return $graph;
    }
    
    /**
     * Calculate depth for each node in the graph (Block E: DAG Explosion Guard)
     * 
     * @param array $nodes Graph nodes
     * @return array Depths and max depth
     */
    private function calculateGraphDepths(array $nodes): array
    {
        $depths = [];
        $maxDepth = 0;
        
        // Calculate depth for each node (depth = longest path from root)
        $calculateDepth = function (string $nodeId, array $visited = []) use (&$calculateDepth, &$depths, $nodes): int {
            if (isset($depths[$nodeId])) {
                return $depths[$nodeId];
            }
            
            // Prevent infinite loops
            if (in_array($nodeId, $visited)) {
                return 0;
            }
            
            $visited[] = $nodeId;
            $deps = $nodes[$nodeId]['depends_on'] ?? [];
            
            if (empty($deps)) {
                $depths[$nodeId] = 0;
                return 0;
            }
            
            $maxParentDepth = 0;
            foreach ($deps as $depId) {
                if (isset($nodes[$depId])) {
                    $parentDepth = $calculateDepth($depId, $visited);
                    $maxParentDepth = max($maxParentDepth, $parentDepth);
                }
            }
            
            $depths[$nodeId] = $maxParentDepth + 1;
            return $depths[$nodeId];
        };
        
        foreach (array_keys($nodes) as $nodeId) {
            $depth = $calculateDepth($nodeId);
            $maxDepth = max($maxDepth, $depth);
        }
        
        return [
            'depths' => $depths,
            'max_depth' => $maxDepth,
        ];
    }
    
    /**
     * Calculate total signals in each dependency chain (Block E: DAG Explosion Guard)
     * 
     * @param array $nodes Graph nodes
     * @param array $executionOrder Topological order
     * @return array Chain signals info
     */
    private function calculateChainSignals(array $nodes, array $executionOrder): array
    {
        $chainSignals = [];
        $maxSignals = 0;
        $maxChain = [];
        
        foreach ($executionOrder as $nodeId) {
            if (!isset($nodes[$nodeId])) continue;
            
            $deps = $nodes[$nodeId]['depends_on'] ?? [];
            $nodeSignals = $nodes[$nodeId]['total_signals'] ?? 0;
            
            if (empty($deps)) {
                $chainSignals[$nodeId] = [
                    'signals' => $nodeSignals,
                    'chain' => [$nodeId],
                ];
            } else {
                // Find the chain with max signals
                $maxDepSignals = 0;
                $maxDepChain = [];
                
                foreach ($deps as $depId) {
                    if (isset($chainSignals[$depId])) {
                        if ($chainSignals[$depId]['signals'] > $maxDepSignals) {
                            $maxDepSignals = $chainSignals[$depId]['signals'];
                            $maxDepChain = $chainSignals[$depId]['chain'];
                        }
                    }
                }
                
                $totalSignals = $maxDepSignals + $nodeSignals;
                $chainSignals[$nodeId] = [
                    'signals' => $totalSignals,
                    'chain' => array_merge($maxDepChain, [$nodeId]),
                ];
            }
            
            if ($chainSignals[$nodeId]['signals'] > $maxSignals) {
                $maxSignals = $chainSignals[$nodeId]['signals'];
                $maxChain = $chainSignals[$nodeId]['chain'];
            }
        }
        
        return [
            'per_node' => $chainSignals,
            'max_signals' => $maxSignals,
            'max_chain' => $maxChain,
        ];
    }
    
    /**
     * Detect cycles in strategy graph and return topological sort (Блок 2)
     * 
     * @param array $nodes Graph nodes
     * @return array Result with has_cycles, order, cycle
     */
    private function detectCyclesAndSort(array $nodes): array
    {
        $visited = [];
        $recStack = [];
        $order = [];
        $cycle = [];
        $hasCycle = false;
        $path = [];
        
        // DFS-based cycle detection and topological sort
        $dfs = function (string $nodeId) use (&$visited, &$recStack, &$order, &$cycle, &$hasCycle, &$path, $nodes, &$dfs): void {
            if ($hasCycle) return;
            
            $visited[$nodeId] = true;
            $recStack[$nodeId] = true;
            $path[] = $nodeId;
            
            $deps = $nodes[$nodeId]['depends_on'] ?? [];
            foreach ($deps as $depId) {
                if (!isset($nodes[$depId])) {
                    continue; // Skip non-existent dependencies
                }
                
                if (!isset($visited[$depId])) {
                    $dfs($depId);
                } elseif (isset($recStack[$depId]) && $recStack[$depId]) {
                    // Cycle found
                    $hasCycle = true;
                    $cycleStart = array_search($depId, $path);
                    $cycle = array_slice($path, $cycleStart !== false ? $cycleStart : 0);
                    $cycle[] = $depId;
                    return;
                }
            }
            
            $recStack[$nodeId] = false;
            $order[] = $nodeId;
            array_pop($path);
        };
        
        foreach (array_keys($nodes) as $nodeId) {
            if (!isset($visited[$nodeId])) {
                $path = []; // Reset path for each DFS tree
                $dfs($nodeId);
            }
        }
        
        return [
            'has_cycles' => $hasCycle,
            'order' => $hasCycle ? [] : $order, // Topological order (dependencies first)
            'cycle' => $cycle,
        ];
    }
    
    /**
     * Get strategies in execution order (respecting dependencies) - Блок 2
     * 
     * @return array Ordered list of strategy IDs
     */
    public function getStrategiesInExecutionOrder(): array
    {
        $graph = $this->buildStrategyGraph();
        
        if ($graph['has_cycles']) {
            $this->log("Cannot compute execution order: cycle detected", 'error');
            // Fall back to no order (just return enabled strategies)
            return array_keys(array_filter($graph['nodes'], fn($n) => $n['enabled']));
        }
        
        // Filter to only enabled strategies
        return array_filter($graph['execution_order'], function ($id) use ($graph) {
            return $graph['nodes'][$id]['enabled'] ?? false;
        });
    }
}

/* ==========================================================
   RULES (Tredercopis)
   1) CONFIG FIRST / ZERO-HARDCODE.
   2) Источник истины — код и файлы storage (не слова/описания).
   3) Любые пути — только через SystemPaths/PackMap; без жёстких относительных путей.
   4) LF-only.
========================================================== */
