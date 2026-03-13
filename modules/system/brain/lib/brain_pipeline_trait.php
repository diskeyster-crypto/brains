<?php
declare(strict_types=1);

namespace Modules\System\Brain\Lib;

use Core\System\SystemPaths;
use Modules\System\Brain\BrainProcessRunner;

/**
 * BrainPipelineTrait - Pipeline execution methods for BrainService
 * 
 * Contains methods for:
 * - Main pipeline execution (runPipeline, runRealPipeline)
 * - Parser5 execution
 * - Simulator execution
 * - Management commands
 * - Process runner integration
 */
trait BrainPipelineTrait
{
    public function execute(): array
    {
        $startTime = microtime(true);
        $result = [
            'success' => true,
            'started_at' => date('Y-m-d H:i:s'),
            'tasks' => [],
            'errors' => [],
        ];
        
        $this->log("Brain cron execute started", 'info');
        
        try {
            // Task 1: Recalculate passports for all strategies
            $passportsResult = $this->recalculatePassports();
            $result['tasks']['passports'] = $passportsResult;
            if (!$passportsResult['success']) {
                $result['errors'][] = $passportsResult['error'] ?? 'Passport recalculation failed';
            }
            
            // Task 2: Apply feedback learning to strategies
            $feedbackResult = $this->applyFeedbackToAllStrategies();
            $result['tasks']['feedback_learning'] = [
                'success' => true,
                'strategies_updated' => $feedbackResult['strategies_updated'] ?? 0,
            ];
            
            // Task 3: Update config_overrides
            $overridesResult = $this->updateConfigOverrides();
            $result['tasks']['config_overrides'] = $overridesResult;
            
            // Task 4: Auto-disable low fitness strategies (if enabled)
            if ($this->getConfig('pipeline.auto_learn', false)) {
                $disableResult = $this->autoDisableLowFitnessStrategies();
                $result['tasks']['auto_disable'] = [
                    'success' => true,
                    'disabled_count' => count($disableResult['disabled'] ?? []),
                ];
            }
            
        } catch (\Throwable $e) {
            $result['success'] = false;
            $result['errors'][] = $e->getMessage();
            $this->log("Brain cron execute error: " . $e->getMessage(), 'error');
        }
        
        $result['finished_at'] = date('Y-m-d H:i:s');
        $result['duration_ms'] = round((microtime(true) - $startTime) * 1000);
        
        // Update state with last cron run info
        $this->updateState([
            'last_cron_run' => [
                'timestamp' => date('Y-m-d H:i:s'),
                'success' => $result['success'],
                'duration_ms' => $result['duration_ms'],
            ],
            'metrics' => [
                'total_cron_runs' => ($this->getState()['metrics']['total_cron_runs'] ?? 0) + 1,
            ],
        ]);
        
        // Log to decisions journal
        $this->logDecision('cron_execute', $result);
        
        $this->log("Brain cron execute completed in {$result['duration_ms']}ms", 'info');
        
        return $result;
    }
    
    public function runPipeline(): array
    {
        $startTime = microtime(true);
        $startedAt = date('Y-m-d H:i:s');
        $runId = 'run_' . date('Ymd_His') . '_' . substr(md5(uniqid()), 0, 6);
        $pid = getmypid();
        
        $result = [
            'id' => $runId,
            'success' => true,
            'started_at' => $startedAt,
            'timestamp' => time(),
            // Summary fields for UI (ТЗ #1)
            'strategies_applied' => 0,
            'candidates_loaded' => 0,
            'signals_generated' => 0,
            'steps' => [],
            'errors' => [],
        ];
        
        // C3 FIX: Update state at run start
        $this->updateState([
            'status' => self::STATUS_RUNNING,
            'active_run_id' => $runId,
            'pid' => $pid,
            'started_at' => $startedAt,
        ]);
        
        // C3 FIX: Write process journal start entry
        $this->writeProcessJournalEntry([
            'run_id' => $runId,
            'phase' => 'start',
            'ts' => $startedAt,
            'pid' => $pid,
        ]);
        
        try {
            // Step 1: Get active strategies (for simulator)
            $strategies = $this->getStrategiesForSimulator();
            $result['strategies_applied'] = count($strategies);
            $result['steps'][] = [
                'step' => 'load_strategies',
                'status' => 'ok',
                'count' => count($strategies),
            ];
            
            if (empty($strategies)) {
                $result['steps'][] = [
                    'step' => 'export_profiles',
                    'status' => 'skip',
                    'reason' => 'No strategies enabled for simulator',
                ];
            } else {
                // Step 2: Export strategy profiles for Parser5
                $exportResult = $this->exportStrategyProfiles($strategies);
                $result['steps'][] = [
                    'step' => 'export_profiles',
                    'status' => $exportResult['success'] ? 'ok' : 'error',
                    'file' => $exportResult['file'] ?? null,
                    'count' => count($strategies),
                ];
            }
            
            // Step 3: Read candidates from Parser4 (just counting, NO evaluation)
            $candidates = $this->readCandidates();
            $result['candidates_loaded'] = $candidates ? count($candidates) : 0;
            $result['steps'][] = [
                'step' => 'read_parser4_candidates',
                'status' => $candidates !== null ? 'ok' : 'not_found',
                'count' => $candidates ? count($candidates) : 0,
            ];
            
            // Step 4: Read signals from Parser5 (just reading, NO generation)
            $signals = $this->readSignals();
            $result['steps'][] = [
                'step' => 'read_parser5_signals',
                'status' => $signals !== null ? 'ok' : 'not_found',
                'count' => $signals ? count($signals) : 0,
            ];
            
            // Step 4.5: Brain Signal Gateway - process and save signals for simulator
            $gatewayResult = $this->processSignalGateway($signals, $strategies);
            
            // HARD LOG: if signals = 0 after gateway, log and mark as EMPTY (per ТЗ)
            $outputCount = $gatewayResult['output_count'] ?? 0;
            $result['signals_generated'] = $outputCount;
            if ($outputCount === 0) {
                $this->log("Signal gateway produced 0 signals", 'warning');
                error_log("[Brain] Signal gateway produced 0 signals");
            }
            
            $result['steps'][] = [
                'step' => 'signal_gateway',
                'status' => !$gatewayResult['success'] ? 'error' : ($outputCount === 0 ? 'empty' : 'ok'),
                'input_count' => $gatewayResult['input_count'] ?? 0,
                'output_count' => $outputCount,
                'file' => $gatewayResult['file'] ?? null,
            ];
            
            // Step 4.6: Run Parser6 Simulator if we have signals
            // C2 FIX: Remove condition !empty($strategies) - run simulator if signals_output_count > 0
            // Per ТЗ: Brain should run Parser6 even if strategies=0 (as long as signals > 0)
            if ($outputCount > 0) {
                $simulatorResult = $this->runSimulatorStep();
                $result['steps'][] = [
                    'step' => 'run_simulator',
                    'status' => $simulatorResult['success'] ? 'ok' : 'error',
                    'duration_ms' => $simulatorResult['duration_ms'] ?? 0,
                    'error' => $simulatorResult['error'] ?? null,
                ];
            } else {
                $result['steps'][] = [
                    'step' => 'run_simulator',
                    'status' => 'skip',
                    'reason' => 'no_signals',
                ];
            }
            
            // Step 5: Read simulation results (re-read after running simulator)
            // Per ТЗ: Hard-error if simulation.json not generated
            $simulation = $this->readSimulationResults();
            
            // Check if simulation.json was actually created (hard-error per ТЗ)
            $simulatorStoragePath = $this->modulePaths['simulator'] ?? null;
            $simulationFile = $simulatorStoragePath ? $simulatorStoragePath . '/simulation.json' : null;
            $simulationExists = $simulationFile && is_file($simulationFile);
            
            // A1: Remove !empty($strategies) - simulation.json should be created if outputCount > 0
            // C-2: HARD-FAIL when simulation.json is missing after simulator ran
            if (!$simulationExists && $outputCount > 0) {
                // Per ТЗ: Hard-error if simulation.json not generated after run
                $this->log("ERROR: simulation.json not generated after simulator run!", 'error');
                error_log("[Brain] ERROR: simulation.json not generated at: " . ($simulationFile ?? 'path unknown'));
                
                // C-2: Set success=false, ok=false per requirement
                $result['success'] = false;
                $result['ok'] = false;
                $result['status'] = 'error';
                $result['errors'][] = 'simulator_output_missing';
                
                $result['steps'][] = [
                    'step' => 'read_simulator_results',
                    'status' => 'error',
                    'error' => 'simulator_output_missing',
                    'error_details' => 'simulation.json not generated after simulator run',
                ];
            } else {
                $result['steps'][] = [
                    'step' => 'read_simulator_results',
                    'status' => $simulation !== null ? 'ok' : 'not_found',
                ];
            }
            
            // Step 6: Update strategy performance from simulation feedback
            if ($simulation !== null) {
                $this->updatePerformanceFromSimulation($simulation);
                $result['steps'][] = [
                    'step' => 'update_performance',
                    'status' => 'ok',
                ];
            }
            
            // Step 6.5: Read simulator's last_run.json for detailed metrics (ТЗ #2)
            $simulatorLastRun = $this->readSimulatorLastRun();
            $simMetrics = $this->extractSimulatorMetrics($simulatorLastRun);
            
            // Add simulator metrics to Brain run record for UI display
            $result['sim_total_signals'] = $simMetrics['sim_total_signals'];
            $result['sim_closed'] = $simMetrics['sim_closed'];
            $result['sim_rejected'] = $simMetrics['sim_rejected'];
            $result['sim_win_rate'] = $simMetrics['sim_win_rate'];
            $result['sim_avg_roi'] = $simMetrics['sim_avg_roi'];
            $result['sim_profit_factor'] = $simMetrics['sim_profit_factor'];
            $result['sim_duration_ms'] = $simMetrics['sim_duration_ms'];
            
            $result['steps'][] = [
                'step' => 'read_simulator_metrics',
                'status' => $simulatorLastRun !== null ? 'ok' : 'not_found',
                'metrics' => $simMetrics,
            ];
            
            // Step 7: Build coin passports from simulator training data
            $passportsResult = $this->buildPassportsFromSimulator();
            $passportsUpdated = $passportsResult['passports_created'] ?? 0;
            $result['passports_updated'] = $passportsUpdated;
            
            // Determine passport step status:
            // - no_data_yet: training_dataset missing or empty (not an error)
            // - ok: passports built successfully
            // - error: actual error during processing
            $passportStatus = 'ok';
            if ($passportsResult['total_trades'] === 0) {
                $passportStatus = 'no_data_yet';
            } elseif (!$passportsResult['success']) {
                $passportStatus = 'error';
            }
            
            $result['steps'][] = [
                'step' => 'build_coin_passports',
                'status' => $passportStatus,
                'passports_created' => $passportsUpdated,
                'total_trades' => $passportsResult['total_trades'] ?? 0,
                'symbols_processed' => $passportsResult['symbols_processed'] ?? 0,
            ];
            
            // Step 8: Get executor-enabled strategies summary
            $executorStrategies = $this->getStrategiesForExecutor();
            $result['steps'][] = [
                'step' => 'executor_summary',
                'status' => 'ok',
                'enabled_count' => count($executorStrategies),
            ];
            
        } catch (\Throwable $e) {
            $result['success'] = false;
            $result['errors'][] = $e->getMessage();
            $this->log("Pipeline error: " . $e->getMessage(), 'error');
        }
        
        // Finalize
        $result['duration_ms'] = round((microtime(true) - $startTime) * 1000);
        $result['finished_at'] = date('Y-m-d H:i:s');
        
        // Save run result
        $this->saveRun($result);
        $this->updateLastRun($result);
        
        // C3 FIX: Update state in finally (set to IDLE, clear active_run_id/pid)
        $state = $this->getState();
        $metrics = $state['metrics'] ?? [];
        $this->updateState([
            'status' => self::STATUS_IDLE,
            'active_run_id' => null,
            'pid' => null,
            'last_pipeline_run' => $result['finished_at'],
            'metrics' => [
                'total_pipeline_runs' => ($metrics['total_pipeline_runs'] ?? 0) + 1,
                'successful_runs' => ($metrics['successful_runs'] ?? 0) + ($result['success'] ? 1 : 0),
                'failed_runs' => ($metrics['failed_runs'] ?? 0) + ($result['success'] ? 0 : 1),
            ],
        ]);
        
        // C3 FIX: Write process journal finish entry
        $simClosed = 0;
        $simRejected = 0;
        foreach ($result['steps'] ?? [] as $step) {
            if (($step['step'] ?? '') === 'read_simulator_metrics' && isset($step['metrics'])) {
                $simClosed = $step['metrics']['sim_closed'] ?? 0;
            }
        }
        $this->writeProcessJournalEntry([
            'run_id' => $runId,
            'phase' => 'finish',
            'ts' => $result['finished_at'],
            'ok' => $result['success'],
            'duration_ms' => $result['duration_ms'],
            'signals_out' => $result['signals_generated'] ?? 0,
            'sim_closed' => $simClosed,
            'sim_rejected' => $simRejected,
        ]);
        
        $this->log("Pipeline completed: {$runId} in {$result['duration_ms']}ms");
        
        return $result;
    }
    
    public function runPipelineWithDependencies(): array
    {
        $startTime = microtime(true);
        $runId = 'run_dag_' . date('Ymd_His') . '_' . substr(md5(uniqid()), 0, 6);
        
        $result = [
            'id' => $runId,
            'success' => true,
            'mode' => 'dependency_graph',
            'started_at' => date('Y-m-d H:i:s'),
            'graph' => null,
            'strategies' => [],
            'dependency_chain' => [],
            'errors' => [],
            'dag_guard' => null, // Block E
        ];
        
        // Build and validate graph
        $graph = $this->buildStrategyGraph();
        $result['graph'] = [
            'nodes' => count($graph['nodes']),
            'edges' => count($graph['edges']),
            'has_cycles' => $graph['has_cycles'],
            'max_depth' => $graph['max_depth_found'] ?? 0, // Block E
            'is_valid' => $graph['is_valid'] ?? true, // Block E
        ];
        
        // Block E: DAG Explosion Guard - check validity
        $result['dag_guard'] = [
            'limits' => $graph['dag_limits'] ?? [],
            'violations' => $graph['dag_violations'] ?? [],
            'passed' => empty($graph['dag_violations']),
        ];
        
        if ($graph['has_cycles']) {
            $result['success'] = false;
            $result['errors'][] = "Cycle detected in strategy dependencies";
            $result['errors'] = array_merge($result['errors'], $graph['errors']);
            return $result;
        }
        
        // Block E: Stop if DAG limits exceeded
        if (!empty($graph['dag_violations'])) {
            $result['success'] = false;
            $result['errors'][] = "DAG explosion guard triggered - pipeline stopped";
            $result['errors'] = array_merge($result['errors'], $graph['dag_violations']);
            $this->log("Block E: Pipeline stopped due to DAG violations: " . implode(', ', $graph['dag_violations']), 'error');
            return $result;
        }
        
        $executionOrder = $this->getStrategiesInExecutionOrder();
        $result['execution_order'] = $executionOrder;
        
        // Signals storage for passing between dependent strategies
        $signalsByStrategy = [];
        
        foreach ($executionOrder as $strategyId) {
            $strategy = $this->getStrategy($strategyId);
            if (!$strategy) continue;
            
            $strategyResult = [
                'id' => $strategyId,
                'name' => $strategy['name'],
                'depends_on' => $strategy['depends_on'] ?? [],
                'steps' => [],
            ];
            
            // Collect signals from dependencies
            $dependencySignals = [];
            foreach ($strategy['depends_on'] ?? [] as $depId) {
                if (isset($signalsByStrategy[$depId])) {
                    $dependencySignals = array_merge($dependencySignals, $signalsByStrategy[$depId]);
                }
            }
            
            // Run Parser5 with dependency context
            $parser5Result = $this->runParser5WithContext($strategyId, $dependencySignals);
            $strategyResult['steps'][] = [
                'step' => 'parser5',
                'status' => $parser5Result['success'] ? 'ok' : 'error',
                'signals' => count($parser5Result['signals'] ?? []),
                'dependency_signals_received' => count($dependencySignals),
            ];
            
            // Store signals for dependents
            $signalsByStrategy[$strategyId] = $parser5Result['signals'] ?? [];
            
            // Run Simulator
            $simulatorResult = $this->runSimulator($strategyId);
            $strategyResult['steps'][] = [
                'step' => 'simulator',
                'status' => $simulatorResult['success'] ? 'ok' : 'error',
            ];
            
            $result['strategies'][$strategyId] = $strategyResult;
            
            // Build dependency chain
            $result['dependency_chain'][] = [
                'strategy' => $strategyId,
                'depends_on' => $strategy['depends_on'] ?? [],
                'signals_produced' => count($signalsByStrategy[$strategyId]),
            ];
        }
        
        $result['duration_ms'] = round((microtime(true) - $startTime) * 1000);
        $result['finished_at'] = date('Y-m-d H:i:s');
        
        // Save run result
        $this->saveRun($result);
        
        return $result;
    }
    
    private function runParser5WithContext(string $strategyId, array $dependencySignals): array
    {
        // Write dependency context
        $contextFile = $this->storageDir . '/tmp/context_' . $strategyId . '.json';
        file_put_contents($contextFile, json_encode([
            'strategy_id' => $strategyId,
            'dependency_signals' => $dependencySignals,
            'count' => count($dependencySignals),
            'created_at' => date('Y-m-d H:i:s'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        // Run Parser5 with context
        $result = $this->runParser5($strategyId);
        $result['dependency_signals'] = count($dependencySignals);
        
        return $result;
    }
    
    public function runParser5(string $strategyId): array
    {
        $strategy = $this->getStrategy($strategyId);
        if ($strategy === null) {
            return ['success' => false, 'error' => 'Strategy not found'];
        }
        
        $this->log("Running Parser5 for strategy: {$strategyId}");
        
        // Generate parser5_profile if not present (FIX 2)
        if (empty($strategy['parser5_profile'])) {
            $strategy['parser5_profile'] = $this->generateParser5Profile($strategy['constraints'] ?? []);
            // Save generated profile to strategy
            $this->saveStrategy(array_merge($strategy, ['id' => $strategyId]));
        }
        
        // Write profile to temp file for Parser5 to read
        $profileFile = $this->storageDir . '/tmp/profile_' . $strategyId . '.json';
        file_put_contents($profileFile, json_encode([
            'strategy_id' => $strategyId,
            'profile' => $strategy['parser5_profile'],
            'constraints' => $strategy['constraints'] ?? [],
            'generated_at' => date('Y-m-d H:i:s'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        // Update state
        $this->updateState([
            'module_status' => [
                'parser5' => [
                    'status' => 'running',
                    'strategy_id' => $strategyId,
                    'started_at' => date('Y-m-d H:i:s'),
                ],
            ],
        ]);
        
        // Execute Parser5
        $parser5Path = $this->findParser5Script();
        $result = [
            'success' => false,
            'strategy_id' => $strategyId,
            'output' => null,
            'signals' => [],
            'error' => null,
        ];
        
        if ($parser5Path && is_file($parser5Path)) {
            // Real exec() call using PHP_BINARY for cross-platform compatibility
            $phpBinary = defined('PHP_BINARY') ? PHP_BINARY : 'php';
            $cmd = escapeshellarg($phpBinary) . " " . escapeshellarg($parser5Path) . " --profile=" . escapeshellarg($profileFile) . " 2>&1";
            $output = [];
            $returnCode = 0;
            exec($cmd, $output, $returnCode);
            
            $result['output'] = implode("\n", $output);
            $result['return_code'] = $returnCode;
            
            if ($returnCode === 0) {
                $result['success'] = true;
                // Read signals generated by Parser5
                $result['signals'] = $this->readSignalsForStrategy($strategyId);
            } else {
                $result['error'] = "Parser5 exited with code: {$returnCode}";
            }
        } else {
            // Parser5 script not found - simulate execution
            $this->log("Parser5 script not found, simulating execution", 'warning');
            $result['success'] = true;
            $result['simulated'] = true;
            $result['signals'] = $this->readSignals() ?? [];
        }
        
        // Update state
        $this->updateState([
            'module_status' => [
                'parser5' => [
                    'status' => 'idle',
                    'last_run' => date('Y-m-d H:i:s'),
                    'last_strategy' => $strategyId,
                    'success' => $result['success'],
                ],
            ],
        ]);
        
        $this->log("Parser5 completed for strategy: {$strategyId}, signals: " . count($result['signals']));
        
        return $result;
    }
    
    private function runSimulatorStep(): array
    {
        $startTime = microtime(true);
        $result = [
            'success' => false,
            'duration_ms' => 0,
            'error' => null,
        ];
        
        try {
            // Try to load and execute Parser6SimulatorService directly
            $simulatorServicePath = $this->findSimulatorServicePath();
            
            if ($simulatorServicePath && is_file($simulatorServicePath)) {
                // Include service file if not already loaded
                require_once $simulatorServicePath;
                
                if (class_exists('Parser6SimulatorService')) {
                    $simulator = new \Parser6SimulatorService();
                    $execResult = $simulator->execute();
                    
                    $result['success'] = ($execResult['ok'] ?? false) || ($execResult['status'] ?? '') === 'ok';
                    $simStatus = $execResult['status'] ?? 'unknown';
                    $signalsLoaded = $execResult['signals_loaded'] ?? null;
                    $openedNow = $execResult['opened_now'] ?? null;
                    $closedNow = $execResult['closed_now'] ?? null;

                    // Mode-aware metrics: comparison_complete has raw/clean payloads without top-level counters
                    if ($simStatus === 'comparison_complete' && isset($execResult['clean']) && is_array($execResult['clean'])) {
                        $signalsLoaded = $execResult['clean']['signals_loaded'] ?? 0;
                        $openedNow = $execResult['clean']['opened_now'] ?? 0;
                        $closedNow = $execResult['clean']['closed_now'] ?? 0;
                    }

                    $result['simulator_result'] = [
                        'status' => $simStatus,
                        'signals_loaded' => is_numeric($signalsLoaded) ? (int)$signalsLoaded : 0,
                        'opened_now' => is_numeric($openedNow) ? (int)$openedNow : 0,
                        'closed_now' => is_numeric($closedNow) ? (int)$closedNow : 0,
                    ];
                    
                    $this->log("Simulator step completed: " . json_encode($result['simulator_result']));
                } else {
                    $result['error'] = 'Parser6SimulatorService class not found';
                    $this->log("Simulator step error: class not found", 'error');
                }
            } else {
                // Fallback: try exec() with runner.php
                $runnerPath = $this->findSimulatorRunnerPath();
                if ($runnerPath && is_file($runnerPath)) {
                    $phpBinary = defined('PHP_BINARY') ? PHP_BINARY : 'php';
                    $cmd = escapeshellarg($phpBinary) . " " . escapeshellarg($runnerPath) . " 2>&1";
                    $output = [];
                    $returnCode = 0;
                    exec($cmd, $output, $returnCode);
                    
                    $result['success'] = ($returnCode === 0);
                    $result['output'] = implode("\n", $output);
                    $result['return_code'] = $returnCode;
                    
                    if (!$result['success']) {
                        $result['error'] = "Simulator exited with code: {$returnCode}";
                    }
                } else {
                    $result['error'] = 'Simulator service/runner not found';
                    $this->log("Simulator step: no executable found", 'warning');
                }
            }
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
            $this->log("Simulator step exception: " . $e->getMessage(), 'error');
        }
        
        $result['duration_ms'] = round((microtime(true) - $startTime) * 1000);
        return $result;
    }
    
    private function findSimulatorServicePath(): ?string
    {
        $paths = SystemPaths::instance();
        
        // Try SystemPaths base keys only - NO FALLBACK
        $baseKeys = [
            'simulator.parser6_simulator',
            'parser.parser6_simulator',
        ];
        
        foreach ($baseKeys as $key) {
            try {
                $basePath = $paths->get($key);
                if (!empty($basePath)) {
                    $servicePath = $basePath . '/service.php';
                    if (is_file($servicePath)) {
                        return $servicePath;
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        
        // A2: NO dirname() fallback - keys must be configured
        // If we get here, simulator is not properly configured in SystemPaths
        $this->log("Simulator service not found: configure simulator.parser6_simulator or parser.parser6_simulator in SystemPaths", 'error');
        
        return null;
    }
    
    private function findSimulatorRunnerPath(): ?string
    {
        $paths = SystemPaths::instance();
        
        // Try SystemPaths base keys only - NO FALLBACK
        $baseKeys = [
            'simulator.parser6_simulator',
            'parser.parser6_simulator',
        ];
        
        foreach ($baseKeys as $key) {
            try {
                $basePath = $paths->get($key);
                if (!empty($basePath)) {
                    $runnerPath = $basePath . '/runner.php';
                    if (is_file($runnerPath)) {
                        return $runnerPath;
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        
        // A2: NO dirname() fallback - keys must be configured  
        // If we get here, simulator is not properly configured in SystemPaths
        $this->log("Simulator runner not found: configure simulator.parser6_simulator or parser.parser6_simulator in SystemPaths", 'error');
        
        return null;
    }
    
    public function runSimulator(string $strategyId): array
    {
        $strategy = $this->getStrategy($strategyId);
        if ($strategy === null) {
            return ['success' => false, 'error' => 'Strategy not found'];
        }
        
        $this->log("Running Simulator for strategy: {$strategyId} (LEGACY mode)");
        
        // Get signals for this strategy
        $signals = $this->readSignalsForStrategy($strategyId);
        if (empty($signals)) {
            $signals = $this->readSignals() ?? [];
        }
        
        // Write signals to temp file for Simulator to read
        $signalsFile = $this->storageDir . '/tmp/signals_' . $strategyId . '.json';
        file_put_contents($signalsFile, json_encode([
            'strategy_id' => $strategyId,
            'signals' => $signals,
            'count' => count($signals),
            'generated_at' => date('Y-m-d H:i:s'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        // Update state
        $this->updateState([
            'module_status' => [
                'simulator' => [
                    'status' => 'running',
                    'strategy_id' => $strategyId,
                    'started_at' => date('Y-m-d H:i:s'),
                ],
            ],
        ]);
        
        // Execute Simulator
        $simulatorPath = $this->findSimulatorScript();
        $result = [
            'success' => false,
            'strategy_id' => $strategyId,
            'output' => null,
            'feedback' => null,
            'error' => null,
        ];
        
        if ($simulatorPath && is_file($simulatorPath)) {
            // Real exec() call using PHP_BINARY for cross-platform compatibility
            $phpBinary = defined('PHP_BINARY') ? PHP_BINARY : 'php';
            $cmd = escapeshellarg($phpBinary) . " " . escapeshellarg($simulatorPath) . " --signals=" . escapeshellarg($signalsFile) . " 2>&1";
            $output = [];
            $returnCode = 0;
            exec($cmd, $output, $returnCode);
            
            $result['output'] = implode("\n", $output);
            $result['return_code'] = $returnCode;
            
            if ($returnCode === 0) {
                $result['success'] = true;
                // Read feedback generated by Simulator
                $result['feedback'] = $this->readSimulationResults();
            } else {
                $result['error'] = "Simulator exited with code: {$returnCode}";
            }
        } else {
            // Simulator script not found - simulate execution
            $this->log("Simulator script not found, simulating execution", 'warning');
            $result['success'] = true;
            $result['simulated'] = true;
            $result['feedback'] = $this->readSimulationResults();
        }
        
        // Update strategy performance from feedback
        if ($result['success'] && $result['feedback']) {
            $this->updatePerformanceFromSimulation($result['feedback'], $strategyId);
        }
        
        // Update state
        $this->updateState([
            'module_status' => [
                'simulator' => [
                    'status' => 'idle',
                    'last_run' => date('Y-m-d H:i:s'),
                    'last_strategy' => $strategyId,
                    'success' => $result['success'],
                ],
            ],
        ]);
        
        $this->log("Simulator completed for strategy: {$strategyId}");
        
        return $result;
    }
    
    public function exportToExecutor(): array
    {
        $this->log("Exporting signals to Executor");
        
        // Get only executor-enabled strategies
        $strategies = $this->getStrategiesForExecutor();
        
        if (empty($strategies)) {
            return [
                'success' => true,
                'message' => 'No strategies enabled for executor',
                'exported' => 0,
            ];
        }
        
        $exportData = [
            'exported_at' => date('Y-m-d H:i:s'),
            'exported_by' => 'brain',
            'strategies' => [],
            'signals' => [],
            'total_signals' => 0,
        ];
        
        // Collect signals for each enabled strategy
        foreach ($strategies as $id => $strategy) {
            $strategySignals = $this->readSignalsForStrategy($id);
            if (empty($strategySignals)) {
                // Try reading all signals and filter
                $allSignals = $this->readSignals() ?? [];
                // Use all signals if no strategy-specific ones
                $strategySignals = $allSignals;
            }
            
            $exportData['strategies'][$id] = [
                'id' => $id,
                'name' => $strategy['name'],
                'enabled_for_executor' => true,
                'constraints' => $strategy['constraints'] ?? [],
            ];
            
            foreach ($strategySignals as $signal) {
                $signal['strategy_id'] = $id;
                $exportData['signals'][] = $signal;
            }
        }
        
        $exportData['total_signals'] = count($exportData['signals']);
        
        // Write to executor export file
        $exportFile = $this->executorDir . '/export_signals.json';
        $result = file_put_contents($exportFile, json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        if ($result === false) {
            return [
                'success' => false,
                'error' => 'Failed to write export file',
            ];
        }
        
        // Update state (FIX 5)
        $this->updateState([
            'module_status' => [
                'executor' => [
                    'status' => 'exported',
                    'last_export' => date('Y-m-d H:i:s'),
                ],
            ],
            'executor_export' => [
                'last_export' => date('Y-m-d H:i:s'),
                'strategies_exported' => count($strategies),
                'signals_exported' => $exportData['total_signals'],
                'file' => $exportFile,
            ],
        ]);
        
        $this->log("Exported {$exportData['total_signals']} signals for " . count($strategies) . " strategies to Executor");
        
        return [
            'success' => true,
            'file' => $exportFile,
            'strategies' => count($strategies),
            'signals' => $exportData['total_signals'],
        ];
    }
    
    public function generateParser5Profile(array $constraints): array
    {
        $targetRoi = (float)($constraints['target_roi_pct'] ?? 5.0);
        $maxDrawdown = (float)($constraints['max_drawdown_pct'] ?? 30.0);
        $session = (string)($constraints['session'] ?? 'any');
        $durationMax = (int)($constraints['duration_max_min'] ?? 120);
        $direction = (string)($constraints['direction'] ?? 'both');
        
        return [
            'instant' => [
                'min_roi_pct' => $targetRoi * 0.5,  // Trigger at 50% of target
                'max_loss_pct' => $maxDrawdown * 0.3,  // Early warning at 30% of max drawdown
                'sessions' => $session === 'any' ? ['london', 'new_york', 'tokyo', 'sydney'] : [$session],
            ],
            'monitor' => [
                'check_interval_sec' => 60,
                'max_duration_min' => $durationMax,
                'drawdown_threshold_pct' => $maxDrawdown * 0.7,  // Alert at 70% of max drawdown
            ],
            'signal' => [
                'direction' => $direction,
                'target_roi_pct' => $targetRoi,
                'stop_loss_pct' => $maxDrawdown * 0.5,  // Stop loss at 50% of max drawdown
                'take_profit_pct' => $targetRoi * 1.5,  // Take profit at 150% of target
            ],
            'generated_at' => date('Y-m-d H:i:s'),
            'generated_from' => 'constraints',
        ];
    }
    
    private function findParser5Script(): ?string
    {
        $paths = SystemPaths::instance();
        
        // Try SystemPaths keys for Parser5
        $parser5Keys = [
            'signal.parser5_signals',
            'parser.parser5_signals',
        ];
        
        foreach ($parser5Keys as $key) {
            try {
                $modulePath = $paths->get($key);
                if (!empty($modulePath)) {
                    $runScript = $modulePath . '/run.php';
                    if (is_file($runScript)) {
                        return $runScript;
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        
        return null;
    }
    
    private function findSimulatorScript(): ?string
    {
        $paths = SystemPaths::instance();
        
        // Try SystemPaths keys for Simulator/Parser6
        $simulatorKeys = [
            'simulator.parser6_simulator',
            'parser.parser6_simulator',
        ];
        
        foreach ($simulatorKeys as $key) {
            try {
                $modulePath = $paths->get($key);
                if (!empty($modulePath)) {
                    $runScript = $modulePath . '/run.php';
                    if (is_file($runScript)) {
                        return $runScript;
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        
        return null;
    }
    
    private function readSignalsForStrategy(string $strategyId): array
    {
        $file = $this->storageDir . '/tmp/signals_' . $strategyId . '_output.json';
        if (is_file($file)) {
            $data = json_decode((string)file_get_contents($file), true);
            if (isset($data['signals']) && is_array($data['signals'])) {
                return $data['signals'];
            }
        }
        return [];
    }
    
    public function runRealPipeline(bool $realExecution = true): array
    {
        $startTime = microtime(true);
        $runId = 'run_' . date('Ymd_His') . '_' . substr(md5(uniqid()), 0, 6);
        
        $result = [
            'id' => $runId,
            'success' => true,
            'real_execution' => $realExecution,
            'started_at' => date('Y-m-d H:i:s'),
            'strategies' => [],
            'steps' => [],
            'errors' => [],
        ];
        
        // Load state once to avoid redundant file I/O
        $currentState = $this->getState();
        
        // Update state - pipeline starting
        $this->updateState([
            'last_pipeline_run' => date('Y-m-d H:i:s'),
            'metrics' => [
                'total_pipeline_runs' => ($currentState['metrics']['total_pipeline_runs'] ?? 0) + 1,
            ],
        ]);
        
        try {
            // Get active strategies
            $strategies = $this->getStrategiesForSimulator();
            $result['steps'][] = [
                'step' => 'load_strategies',
                'status' => 'ok',
                'count' => count($strategies),
            ];
            
            if (empty($strategies)) {
                $result['steps'][] = [
                    'step' => 'pipeline',
                    'status' => 'skip',
                    'reason' => 'No strategies enabled for simulator',
                ];
            } else {
                // Process each strategy (FIX 3 - real pipeline run)
                foreach ($strategies as $id => $strategy) {
                    $strategyResult = [
                        'id' => $id,
                        'name' => $strategy['name'],
                        'steps' => [],
                    ];
                    
                    if ($realExecution) {
                        // Step 1: Run Parser5
                        $parser5Result = $this->runParser5($id);
                        $strategyResult['steps'][] = [
                            'step' => 'parser5',
                            'status' => $parser5Result['success'] ? 'ok' : 'error',
                            'signals' => count($parser5Result['signals'] ?? []),
                        ];
                        
                        // Step 2: Run Simulator
                        $simulatorResult = $this->runSimulator($id);
                        $strategyResult['steps'][] = [
                            'step' => 'simulator',
                            'status' => $simulatorResult['success'] ? 'ok' : 'error',
                            'feedback' => $simulatorResult['feedback'] ? 'received' : 'none',
                        ];
                    } else {
                        // Just read existing data
                        $signals = $this->readSignalsForStrategy($id);
                        if (empty($signals)) {
                            $signals = $this->readSignals() ?? [];
                        }
                        $strategyResult['steps'][] = [
                            'step' => 'read_signals',
                            'status' => 'ok',
                            'count' => count($signals),
                        ];
                    }
                    
                    $result['strategies'][$id] = $strategyResult;
                }
                
                $result['steps'][] = [
                    'step' => 'process_strategies',
                    'status' => 'ok',
                    'processed' => count($strategies),
                ];
            }
            
            // Export to Executor (FIX 4)
            $exportResult = $this->exportToExecutor();
            $result['steps'][] = [
                'step' => 'export_to_executor',
                'status' => $exportResult['success'] ? 'ok' : 'error',
                'signals' => $exportResult['signals'] ?? 0,
            ];
            
            // Update state - pipeline completed
            $this->updateState([
                'metrics' => [
                    'successful_runs' => ($currentState['metrics']['successful_runs'] ?? 0) + 1,
                ],
                'active_strategies' => array_keys($strategies),
            ]);
            
        } catch (\Throwable $e) {
            $result['success'] = false;
            $result['errors'][] = $e->getMessage();
            $this->log("Pipeline error: " . $e->getMessage(), 'error');
            
            // Update state - pipeline failed
            $this->updateState([
                'metrics' => [
                    'failed_runs' => ($currentState['metrics']['failed_runs'] ?? 0) + 1,
                ],
            ]);
        }
        
        // Finalize
        $result['duration_ms'] = round((microtime(true) - $startTime) * 1000);
        $result['finished_at'] = date('Y-m-d H:i:s');
        
        // Save run result
        $this->saveRun($result);
        $this->updateLastRun($result);
        
        $this->log("Real pipeline completed: {$runId} in {$result['duration_ms']}ms");
        
        return $result;
    }
    
    public function getProcessRunner(): BrainProcessRunner
    {
        return BrainProcessRunner::instance();
    }
    
    public function runModuleIsolated(string $module, array $args = [], ?int $timeout = null): array
    {
        return $this->getProcessRunner()->run($module, $args, $timeout);
    }
    
    public function killHungProcesses(): array
    {
        $killed = $this->getProcessRunner()->killHungProcesses();
        
        if (!empty($killed)) {
            $this->log("Killed hung processes: " . implode(', ', $killed), 'warning');
        }
        
        return $killed;
    }
    
    public function runManagement(): array
    {
        $result = [
            'success' => false,
            'enabled' => false,
            'management_active_trades_seen' => 0,
            'management_commands_written' => 0,
            'management_skipped_throttled' => 0,
            'management_skipped_missing_roi_current' => 0,
            'management_skipped_not_activated' => 0,
            'management_skipped_no_change' => 0,
            'errors' => [],
        ];
        
        $cfg = $this->config['management'] ?? [];
        
        // Check if management is enabled
        if (!($cfg['enabled'] ?? true)) {
            $result['enabled'] = false;
            $result['success'] = true;
            return $result;
        }
        
        $result['enabled'] = true;
        $now = time();
        
        try {
            // Load simulator state
            $state = $this->loadSimulatorState($cfg);
            
            if ($state === null) {
                $result['errors'][] = 'Failed to load simulator state';
                return $result;
            }
            
            // Build commands
            $commandsResult = $this->buildManagementCommandsFromSimulatorState($state, $cfg, $now);
            
            // Merge counters
            $result['management_active_trades_seen'] = $commandsResult['trades_seen'];
            $result['management_commands_written'] = count($commandsResult['commands']);
            $result['management_skipped_throttled'] = $commandsResult['skipped_throttled'];
            $result['management_skipped_missing_roi_current'] = $commandsResult['skipped_missing_roi'];
            $result['management_skipped_not_activated'] = $commandsResult['skipped_not_activated'];
            $result['management_skipped_no_change'] = $commandsResult['skipped_no_change'];
            
            // Write commands file
            $this->writeManagementCommands($cfg, $commandsResult['commands'], $now);
            
            $result['success'] = true;
        } catch (\Throwable $e) {
            $result['errors'][] = 'Management error: ' . $e->getMessage();
        }
        
        return $result;
    }
    
    private function loadSimulatorState(array $cfg): ?array
    {
        // Get storage path from SystemPaths via modulePaths
        // TASK 4: Use modulePaths['simulator'] first, not 'parser6_simulator'
        $storagePath = $this->modulePaths['simulator'] ?? null;
        
        // TASK 4: If not found in modulePaths, try SystemPaths keys
        if (!$storagePath) {
            $simulatorStorageKeys = [
                'simulator.parser6_simulator.storage',
                'parser.parser6_simulator.storage',
            ];
            foreach ($simulatorStorageKeys as $key) {
                try {
                    $path = SystemPaths::instance()->get($key);
                    if ($path && is_dir($path)) {
                        $storagePath = $path;
                        break;
                    }
                } catch (\Throwable $e) {
                    // Key not found, try next
                }
            }
        }
        
        // TASK 4: NO fallback path building - return null if not found
        if (!$storagePath) {
            return null;
        }
        
        // C4.1: Read active trades from files in clean/trades/active/
        $activeTrades = [];
        
        // Check clean mode storage first
        $activeDir = $storagePath . '/clean/trades/active';
        if (!is_dir($activeDir)) {
            // Fallback to root storage
            $activeDir = $storagePath . '/trades/active';
        }
        
        if (is_dir($activeDir)) {
            $files = glob($activeDir . '/*.json') ?: [];
            foreach ($files as $file) {
                $content = @file_get_contents($file);
                if ($content === false) continue;
                
                $trade = @json_decode($content, true);
                if (is_array($trade)) {
                    $activeTrades[] = $trade;
                }
            }
        }
        
        // Return in state format for compatibility with buildManagementCommandsFromSimulatorState
        return [
            'trades_active' => $activeTrades,
        ];
    }
    
    private function buildManagementCommandsFromSimulatorState(array $state, array $cfg, int $now): array
    {
        $result = [
            'commands' => [],
            'trades_seen' => 0,
            'skipped_throttled' => 0,
            'skipped_missing_roi' => 0,
            'skipped_not_activated' => 0,
            'skipped_no_change' => 0,
        ];
        
        // Extract active trades (support both key formats)
        $activeTrades = $state['trades_active'] ?? $state['active_trades'] ?? [];
        
        if (!is_array($activeTrades)) {
            return $result;
        }
        
        $result['trades_seen'] = count($activeTrades);
        
        // Limits
        $limits = $cfg['limits'] ?? [];
        $minIntervalSec = $limits['min_interval_sec_per_trade'] ?? 120;
        $maxCommands = $limits['max_commands_per_run'] ?? 200;
        
        // Trailing config
        $trailingCfg = $cfg['trailing'] ?? [];
        $factorMin = $trailingCfg['drawdown_factor_min'] ?? 0.20;
        $factorMax = $trailingCfg['drawdown_factor_max'] ?? 0.80;
        $stepDelta = $cfg['step_delta'] ?? 0.10;
        
        // Thresholds
        $thresholds = $cfg['thresholds'] ?? [];
        $givebackFactorTighten = $thresholds['giveback_factor_to_tighten'] ?? 0.60;
        $givebackFactorLoosen = $thresholds['giveback_factor_to_loosen'] ?? 0.20;
        
        foreach ($activeTrades as $trade) {
            if (count($result['commands']) >= $maxCommands) {
                break;
            }
            
            $tradeId = $trade['trade_id'] ?? $trade['id'] ?? null;
            $symbol = $trade['symbol'] ?? '';
            
            if (!$tradeId) {
                continue;
            }
            
            // Check if trailing is activated
            $trailing = $trade['trailing'] ?? [];
            $trailingActivated = $trailing['activated'] ?? false;
            
            if (!$trailingActivated) {
                $result['skipped_not_activated']++;
                continue;
            }
            
            // Get required fields
            $activationRoiPct = (float)($trailing['activation_roi_pct'] 
                ?? $trade['risk']['trailing']['activation_roi_pct'] 
                ?? 0);
            
            $currentFactor = (float)($trailing['drawdown_factor'] ?? 0.5);
            $peakRoiPct = (float)($trailing['peak_roi_pct'] ?? 0);
            
            // Get current ROI - REQUIRED field
            $roiCurrentPct = $trade['roi_current_pct'] 
                ?? $trade['current_roi_pct'] 
                ?? $trade['roi_pct'] 
                ?? null;
            
            if ($roiCurrentPct === null) {
                $result['skipped_missing_roi']++;
                continue;
            }
            
            $roiCurrentPct = (float)$roiCurrentPct;
            
            // Check throttle
            $lastManagementTs = $trade['brain_management_last_ts'] ?? 0;
            if (($now - $lastManagementTs) < $minIntervalSec) {
                $result['skipped_throttled']++;
                continue;
            }
            
            // Calculate giveback from peak
            $givebackNowPct = $peakRoiPct - $roiCurrentPct;
            
            // Determine action
            $newFactor = $currentFactor;
            $reason = null;
            
            // TIGHTEN: giving back too much from peak
            $tightenThreshold = $activationRoiPct * $givebackFactorTighten;
            if ($givebackNowPct >= $tightenThreshold && $currentFactor > $factorMin) {
                $newFactor = max($factorMin, $currentFactor - $stepDelta);
                $reason = 'tighten_giveback';
            }
            // LOOSEN: very little giveback, may exit too early
            elseif ($activationRoiPct > 0) {
                $loosenThreshold = $activationRoiPct * $givebackFactorLoosen;
                if ($givebackNowPct <= $loosenThreshold && $currentFactor < $factorMax) {
                    $newFactor = min($factorMax, $currentFactor + $stepDelta);
                    $reason = 'loosen_stable';
                }
            }
            
            // Skip if no change
            if ($newFactor === $currentFactor || $reason === null) {
                $result['skipped_no_change']++;
                continue;
            }
            
            // Create command
            $commandId = hash('sha256', $tradeId . '|' . $newFactor . '|' . $now);
            
            $result['commands'][] = [
                'command_id' => $commandId,
                'trade_id' => $tradeId,
                'symbol' => $symbol,
                'action' => 'set_trailing_drawdown_factor',
                'value' => round($newFactor, 2),
                'reason' => $reason,
                'created_ts_unix' => $now,
                'expires_ts_unix' => $now + ($cfg['commands']['ttl_sec'] ?? 900),
                'metadata' => [
                    'current_factor' => $currentFactor,
                    'peak_roi_pct' => $peakRoiPct,
                    'roi_current_pct' => $roiCurrentPct,
                    'giveback_pct' => round($givebackNowPct, 4),
                    'activation_roi_pct' => $activationRoiPct,
                ],
            ];
        }
        
        return $result;
    }
    
    private function writeManagementCommands(array $cfg, array $commands, int $now): void
    {
        // Get storage path from SystemPaths
        $storageKey = $cfg['commands']['storage_key'] ?? 'system.brain.storage';
        $filename = $cfg['commands']['filename'] ?? 'management/commands.json';
        $ttlSec = $cfg['commands']['ttl_sec'] ?? 900;
        
        // C4.1: Use storageDir from constructor (already set via SystemPaths) - NO dirname() fallback
        $storagePath = $this->storageDir;
        $filePath = $storagePath . '/' . $filename;
        
        // Ensure directory exists (use 0750 for better security)
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        
        $data = [
            'schema_version' => 'management_commands_v1', // STEP 7: Schema version for management commands
            'generated_ts_unix' => $now,
            'generated_at' => date('c', $now),
            'ttl_sec' => $ttlSec,
            'expires_ts_unix' => $now + $ttlSec, // C4.1: Add explicit expires_ts_unix
            'commands_count' => count($commands),
            'commands' => $commands,
        ];
        
        @file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    
    public function runSmokeCheck(int $sampleCount = 3): array
    {
        $result = [
            'ok' => true,
            'missing_fields' => [],
            'sample_signal_ids' => [],
            'issues' => [],
            'counters' => [],
        ];
        
        // Load signals.json
        $signalsFile = $this->storageDir . '/signals.json';
        if (!is_file($signalsFile)) {
            $result['ok'] = false;
            $result['issues'][] = 'signals.json not found';
            return $result;
        }
        
        $content = @file_get_contents($signalsFile);
        if ($content === false) {
            $result['ok'] = false;
            $result['issues'][] = 'Failed to read signals.json';
            return $result;
        }
        
        $data = @json_decode($content, true);
        if (!is_array($data)) {
            $result['ok'] = false;
            $result['issues'][] = 'Invalid JSON in signals.json';
            return $result;
        }
        
        $signals = $data['signals'] ?? [];
        if (empty($signals)) {
            $result['ok'] = true;
            $result['issues'][] = 'No signals in signals.json (empty is OK)';
            return $result;
        }
        
        // Check last N signals
        $samplesToCheck = array_slice($signals, -$sampleCount);
        $allMissingFields = [];
        
        foreach ($samplesToCheck as $signal) {
            $signalId = $signal['trade_id'] ?? $signal['id'] ?? 'unknown';
            $result['sample_signal_ids'][] = $signalId;
            
            // Check schema_version
            if (($signal['schema_version'] ?? null) !== 'clean_signal_v1') {
                $result['ok'] = false;
                $result['issues'][] = "Signal {$signalId} missing schema_version=clean_signal_v1";
            }
            
            // Validate full schema
            $validation = $this->validateSignalSchema($signal);
            if (!$validation['valid']) {
                $result['ok'] = false;
                $allMissingFields = array_merge($allMissingFields, $validation['missing_fields']);
            }
        }
        
        $result['missing_fields'] = array_unique($allMissingFields);
        
        // Load last_run.json counters
        $lastRun = $this->getLastRun();
        if ($lastRun) {
            $result['counters'] = [
                'last_run_ok' => $lastRun['ok'] ?? false,
                'last_run_ts' => $lastRun['ts'] ?? 0,
                'last_run_duration_ms' => $lastRun['duration_ms'] ?? 0,
            ];
        }
        
        // Add signal file counters
        $result['counters']['signals_count'] = count($signals);
        $result['counters']['signals_written'] = $data['signals_written'] ?? $data['count'] ?? count($signals);
        $result['counters']['signals_rejected_schema_incomplete'] = $data['signals_rejected_schema_incomplete'] ?? 0;
        
        // Add learning counters from signals.json
        $result['counters']['learning_recommendations_applied_count'] = $data['learning_recommendations_applied_count'] ?? 0;
        $result['counters']['trailing_mode_source_learning_count'] = $data['trailing_mode_source_learning_count'] ?? 0;
        
        // Add management counters
        $managementFile = $this->storageDir . '/management/commands.json';
        if (is_file($managementFile)) {
            $mgmtData = @json_decode(file_get_contents($managementFile), true);
            $result['counters']['management_commands_written'] = $mgmtData['commands_count'] ?? 0;
        } else {
            $result['counters']['management_commands_written'] = 0;
        }
        
        return $result;
    }
}

/* ==========================================================
   RULES (Tredercopis)
   1) CONFIG FIRST / ZERO-HARDCODE.
   2) Источник истины — код и файлы storage (не слова/описания).
   3) Любые пути — только через SystemPaths/PackMap; без жёстких относительных путей.
   4) LF-only.
========================================================== */
