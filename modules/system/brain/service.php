<?php

declare(strict_types=1);

namespace Modules\System\Brain;

use Core\System\System;
use Core\System\SystemPaths;

// Load trait files via SystemPaths (PackMap)
$__brainModuleBase = SystemPaths::instance()->get('system.brain');
require_once $__brainModuleBase . '/lib/brain_core_trait.php';
require_once $__brainModuleBase . '/lib/brain_strategies_trait.php';
require_once $__brainModuleBase . '/lib/brain_graph_trait.php';
require_once $__brainModuleBase . '/lib/brain_pipeline_trait.php';
require_once $__brainModuleBase . '/lib/brain_streams_trait.php';
require_once $__brainModuleBase . '/lib/brain_fitness_trait.php';
require_once $__brainModuleBase . '/lib/brain_passports_trait.php';
require_once $__brainModuleBase . '/lib/brain_profiles_trait.php';
require_once $__brainModuleBase . '/lib/brain_trailing_learning_trait.php';
require_once $__brainModuleBase . '/lib/brain_maintenance_trait.php';
require_once $__brainModuleBase . '/lib/brain_symbol_policy_trait.php';

/**
 * SYSTEM PATCH RULES (per Parser4 reference):
 * - NO local path computations
 * - NO dirname()
 * - NO relative paths (../../)
 * - NO hardcoded paths (/modules/parser/...)
 * - ALL paths via SystemPaths::instance()->get()
 */

/**
 * Brain Service - Meta-Orchestrator (NOT an analytics engine)
 * 
 * ============================================================================
 * BLOCK F: HARD RULE (v2.1 - AI Safety)
 * ============================================================================
 * 
 * CRITICAL PRINCIPLE: Brain is ONLY: orchestrator + selector + trainer
 * 
 * Brain has NO RIGHT to:
 *   ❌ Calculate prices
 *   ❌ Analyze candles  
 *   ❌ Calculate impulses
 *   ❌ Generate trading formulas
 *   ❌ Work with market data directly
 *   ❌ Build signals
 * 
 * Brain ONLY does:
 *   ✅ Orchestrates pipeline (launches modules, waits for results)
 *   ✅ Selects strategies (enables/disables based on performance)
 *   ✅ Trains strategies (adjusts constraints based on feedback)
 *   ✅ Manages strategy profiles (JSON, NOT formulas)
 *   ✅ Reads module outputs (does NOT generate them)
 *   ✅ Compares results (decides which strategies work)
 * 
 * ============================================================================
 * 
 * PIPELINE:
 *   Parser4 → candidates.json
 *   Parser5 → signals.json
 *   Parser6 (Simulator) → simulation.json
 *   Executor → live trades
 * 
 * STORAGE STRUCTURE:
 *   - storage/strategies/*.json - strategy profiles (wishes, NOT formulas)
 *   - storage/runs/*.json - orchestration run results
 *   - storage/feedback/*.json - simulator feedback
 *   - storage/logs/brain.log - orchestration log
 *   - storage/last_run.json - last run metadata
 * 
 * v2.1 SAFETY FEATURES:
 *   - Block A: Resource Guard (memory limits)
 *   - Block B: Feedback Confidence (statistical requirements)
 *   - Block C: Time-aware Fitness (efficiency metrics)
 *   - Block D: Anti-Overfitting (train/validation split)
 *   - Block E: DAG Explosion Guard (depth/signals limits)
 *   - Block F: Hard Rule (role boundaries)
 */
final class BrainService
{
    // Include all trait methods
    use \Modules\System\Brain\Lib\BrainCoreTrait;
    use \Modules\System\Brain\Lib\BrainStrategiesTrait;
    use \Modules\System\Brain\Lib\BrainGraphTrait;
    use \Modules\System\Brain\Lib\BrainPipelineTrait;
    use \Modules\System\Brain\Lib\BrainStreamsTrait;
    use \Modules\System\Brain\Lib\BrainSymbolPolicyTrait; // FIX: provides loadLiveSymbolStats()
    use \Modules\System\Brain\Lib\BrainFitnessTrait;
    use \Modules\System\Brain\Lib\BrainPassportsTrait;
    use \Modules\System\Brain\Lib\BrainProfilesTrait;
    use \Modules\System\Brain\Lib\BrainTrailingLearningTrait;
    use \Modules\System\Brain\Lib\BrainMaintenanceTrait;

    private static ?self $instance = null;
    
    /** @var string Module base path from SystemPaths (NOT local path computations!) */
    private string $moduleBase;
    
    private string $storageDir;
    private string $strategiesDir;
    private string $runsDir;
    private string $feedbackDir;
    private string $passportsDir;
    private string $profilesDir;  // Risk Profiles v1
    private string $logFile;
    private array $config;
    
    // Module output paths (read-only)
    private array $modulePaths = [];
    
    private string $executorDir;
    private string $stateFile;
    
    // ========================================================================
    // Feedback Adjustment Constants (Блок 3)
    // ========================================================================
    
    /** Minimum drawdown percentage allowed (floor for adjustments) */
    private const MIN_DRAWDOWN_PCT = 10;
    
    /** Maximum target ROI percentage allowed (ceiling for adjustments) */
    private const MAX_TARGET_ROI_PCT = 15;
    
    /** Minimum target ROI percentage allowed (floor for adjustments) */
    private const MIN_TARGET_ROI_PCT = 2;
    
    /** Drawdown reduction factor when win rate is low (15% reduction) */
    private const LOW_WIN_RATE_DRAWDOWN_ADJUSTMENT = 0.85;
    
    /** ROI increase factor when win rate is low (15% increase for more selectivity) */
    private const LOW_WIN_RATE_ROI_ADJUSTMENT = 1.15;
    
    /** Drawdown reduction factor when actual drawdown is high (10% reduction) */
    private const HIGH_DRAWDOWN_ADJUSTMENT = 0.9;
    
    /** ROI/Drawdown increase factor when win rate is high (10% increase) */
    private const HIGH_WIN_RATE_AGGRESSION_ADJUSTMENT = 1.1;
    
    /** Maximum allowed drawdown percentage ceiling */
    private const MAX_DRAWDOWN_PCT = 50;
    
    // ========================================================================
    // Fitness Score Constants (Блок 5)
    // ========================================================================
    
    /** Weight for win rate in fitness calculation (40%) */
    private const FITNESS_WIN_RATE_WEIGHT = 0.4;
    
    /** Weight for average ROI in fitness calculation (35%) - reduced for time_efficiency */
    private const FITNESS_ROI_WEIGHT = 0.35;
    
    /** 
     * Weight for average drawdown in fitness calculation (15% penalty)
     * Note: Drawdown is SUBTRACTED in the formula, so actual positive range comes from
     * win_rate (0.4) + roi (0.35) + time_efficiency (0.10) = 0.85 max before drawdown penalty
     */
    private const FITNESS_DRAWDOWN_WEIGHT = 0.15;
    
    /** Weight for time efficiency in fitness calculation (10%) - Block C */
    private const FITNESS_TIME_EFFICIENCY_WEIGHT = 0.10;
    
    /** ROI normalization divisor (normalizes -10% to +10% range to -1 to 1) */
    private const ROI_NORMALIZATION_DIVISOR = 10.0;
    
    /** Drawdown normalization divisor (normalizes 0-50% range to 0-1) */
    private const DRAWDOWN_NORMALIZATION_DIVISOR = 50.0;
    
    /** Time efficiency normalization divisor (normalizes 0-1% per hour to 0-1) - Block C */
    private const TIME_EFFICIENCY_NORMALIZATION_DIVISOR = 1.0;
    
    // ========================================================================
    // Block B: Feedback Confidence Constants (v2.1)
    // ========================================================================
    
    /** Minimum number of signals required for feedback application */
    private const MIN_SIGNALS_FOR_FEEDBACK = 50;
    
    /** Minimum confidence level required for feedback application (0-1) */
    private const MIN_CONFIDENCE_FOR_FEEDBACK = 0.6;
    
    /** Minimum number of sample days required for feedback application */
    private const MIN_SAMPLE_DAYS_FOR_FEEDBACK = 3;
    
    // ========================================================================
    // Block D: Anti-Overfitting Constants (v2.1)
    // ========================================================================
    
    /** Train/validation split ratio (0.7 = 70% train, 30% validation) */
    private const TRAIN_VALIDATION_SPLIT_RATIO = 0.7;
    
    /** Simulator mode: training data */
    private const SIMULATOR_MODE_TRAIN = 'train';
    
    /** Simulator mode: validation data */
    private const SIMULATOR_MODE_VALIDATION = 'validation';
    
    // ========================================================================
    // Block E: DAG Explosion Guard Constants (v2.1)
    // ========================================================================
    
    /** Maximum allowed signals per strategy chain */
    private const MAX_SIGNALS_PER_CHAIN = 10000;
    
    /** Maximum allowed depth of strategy dependencies */
    private const MAX_STRATEGY_DEPTH = 5;
    
    /** Maximum samples per symbol for passport building (memory limit) */
    private const PASSPORT_MAX_SAMPLES_PER_SYMBOL = 2000;
    
    // ========================================================================
    // Brain v2.2 Constants - Stabilization & Production Hardening
    // ========================================================================
    
    /** State machine statuses (Блок 2) */
    private const STATUS_IDLE = 'IDLE';
    private const STATUS_RUNNING = 'RUNNING';
    private const STATUS_ERROR = 'ERROR';
    private const STATUS_STOPPED = 'STOPPED';
    
    /** Maximum strategies per batch in auto search (Блок 6) */
    private const MAX_STRATEGIES_PER_BATCH = 50;
    
    /** Event types for journal (Блок 4) */
    private const EVENT_RUN_START = 'RUN_START';
    private const EVENT_RUN_END = 'RUN_END';
    private const EVENT_STRATEGY_UPDATED = 'STRATEGY_UPDATED';
    private const EVENT_AUTO_SEARCH = 'AUTO_SEARCH';
    private const EVENT_PROCESS_START = 'PROCESS_START';
    private const EVENT_PROCESS_END = 'PROCESS_END';
    private const EVENT_ERROR = 'ERROR';
    
    /** NDJSON file paths (Блок 1, 3, 4) */
    private string $eventsFile;
    private string $processJournalFile;
    
    public function __construct()
    {
        // SystemPaths: Get module base path (NEVER use local path computations)
        $paths = SystemPaths::instance();
        $this->moduleBase = $paths->get('system.brain');
        
        // All paths derived from moduleBase via SystemPaths
        $this->storageDir = $this->moduleBase . '/storage';
        $this->strategiesDir = $this->storageDir . '/strategies';
        $this->runsDir = $this->storageDir . '/runs';
        $this->feedbackDir = $this->storageDir . '/feedback';
        $this->passportsDir = $this->storageDir . '/passports';
        $this->profilesDir = $this->storageDir . '/profiles';  // Risk Profiles v1
        $this->executorDir = $this->storageDir . '/executor';
        $this->logFile = $this->storageDir . '/logs/brain.log';
        $this->stateFile = $this->storageDir . '/state.json';
        
        // v2.2: NDJSON files for streaming I/O (Блок 1, 3, 4)
        $this->eventsFile = $this->storageDir . '/events.ndjson';
        $this->processJournalFile = $this->storageDir . '/process_journal.ndjson';
        
        // Load BrainProcessRunner via moduleBase (NOT local path computations)
        $processRunnerPath = $this->moduleBase . '/brainprocessrunner.php';
        if (is_file($processRunnerPath)) {
            require_once $processRunnerPath;
        }
        
        $this->ensureDirectories();
        $this->loadConfig();
        $this->discoverModulePaths();
        $this->initializeState();
    }
    
}

/* ==========================================================
   RULES (Tredercopis)
   1) CONFIG FIRST / ZERO-HARDCODE.
   2) Источник истины — код и файлы storage (не слова/описания).
   3) Любые пути — только через SystemPaths/PackMap; без жёстких относительных путей.
   4) Selftest обязан корректно понимать schema clean_signal_v1 (signals wrapper).
   5) LF-only.
========================================================== */

/* RULES
 * NO HARDCODE. CONFIG FIRST. SystemPaths ONLY.
 * This file is part of Brain refactor into traits.
 * No behavior changes allowed (refactor-only).
 */
