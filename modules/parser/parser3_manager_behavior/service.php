<?php
declare(strict_types=1);

use Core\System\SystemPaths;


/**
 * Parser 3: Manager — Behavior Profiler (Market Intelligence)
 *
 * CronManager expects a global service class with execute():
 *   Parser3ManagerBehaviorService
 *
 * PURPOSE (воронка):
 * 1) Грубая оценка поведения монеты по истории (Parser2 RAW).
 * 2) Классификация (volatile / trend / corridor / dead / unclassified).
 * 3) Генерация "кандидатов" для детекторов (pump/corridor/trend/volatile).
 * 4) Выдача ТОЛЬКО тех. профиля (без цен и без таймсерии цен) — чтобы
 *    дальнейшие модули могли брать окна из Parser2 и принимать решения.
 *
 * INPUTS:
 * - Parser1 active registry (active.json)
 * - Parser2 RAW history (NDJSON per symbol)
 *
 * OUTPUTS (JSON-only, NO prices):
 * - storage/profiles/{SYMBOL}.json                 (профиль поведения)
 * - storage/index/classes/{CLASS}.json             (индекс по классам)
 * - storage/index/tags/{TAG}.json                  (индекс по тегам)
 * - storage/candidates/{TYPE}.json                 (кандидаты для детекторов)
 * - storage/state.json + storage/last_run.json + storage/errors.json
 *
 * PROHIBITIONS:
 * - No DB
 * - No UI
 * - No private Bybit endpoints
 * - No writes outside this module storage
 *
 * CONFIG FIRST / ZERO HARDCODE
 */

final class Parser3ManagerBehaviorService
{
    /** @var array<string,mixed> */
    private array $cfg = [];

    private \Core\System\SystemPaths $paths;

    private string $moduleStorage;

    /** @var array<int,string> */
    private array $errors = [];

    public function __construct()
    {
        $this->paths = \Core\System\SystemPaths::instance();
        $this->cfg = $this->loadConfig();

        // Cache module storage dir (inside modules/parser/<module>/storage)
        $this->moduleStorage = $this->paths->get('parser.parser3_manager_behavior.storage');
    }


    /**
     * Cron entrypoint.
     *
     * @return array<string,mixed>
     */
    public function execute(): array
    {
        $t0 = microtime(true);
        $ts = date('c');

        if (($this->cfg['enabled'] ?? false) !== true) {
            return $this->finish(false, 'disabled', $t0, [
                'ts' => $ts,
                'symbols_total' => 0,
                'classified' => 0,
                'unclassified' => 0,
            ]);
        }

        // ------------------------------------------------------
        // OUTPUT DIRS
        // ------------------------------------------------------
        $outProfilesDir = $this->pathFromModule((string)($this->cfg['output']['profiles_dir'] ?? 'storage/profiles'));
        $outIndexClassesDir = $this->pathFromModule((string)($this->cfg['output']['index_classes_dir'] ?? 'storage/index/classes'));
        $outIndexTagsDir = $this->pathFromModule((string)($this->cfg['output']['index_tags_dir'] ?? 'storage/index/tags'));
        $outCandidatesDir = $this->pathFromModule((string)($this->cfg['output']['candidates_dir'] ?? 'storage/candidates'));

        $this->ensureDir($outProfilesDir);
        $this->ensureDir($outIndexClassesDir);
        $this->ensureDir($outIndexTagsDir);
        $this->ensureDir($outCandidatesDir);

        // ------------------------------------------------------
        // INPUT SYMBOLS
        // ------------------------------------------------------
        $symbols = $this->loadActiveSymbols();
        $symbolsTotal = count($symbols);

        $scanDays = max(0, (int)($this->cfg['scan_days'] ?? 14));
        $maxLinesPerFile = max(0, (int)($this->cfg['max_lines_per_file'] ?? 0));

        /** @var array<string,array<int,array<string,mixed>>> $classesIndex */
        $classesIndex = [];
        /** @var array<string,array<int,array<string,mixed>>> $tagsIndex */
        $tagsIndex = [];
        /** @var array<string,array<int,array<string,mixed>>> $candidates */
        $candidates = [
            'pump' => [],
            'corridor' => [],
            'trend' => [],
            'volatile' => [],
        ];

        $classified = 0;
        $unclassified = 0;


        $skippedEmptySymbol = 0;
        $skippedBadSymbol = 0;
        $historyMissing = 0;
        $historyTooShort = 0;
        foreach ($symbols as $symbol) {
            $symbol = strtoupper(trim((string)$symbol));
            if ($symbol === '') {
                $skippedEmptySymbol++;
                continue;
            }
            // Accept only plain spot symbols like BTCUSDT (ignore dated futures like MNTUSDT-13FEB26)
            if (!preg_match('/^[A-Z0-9]{2,}USDT$/', $symbol)) {
                $skippedBadSymbol++;
                continue;
            }

            $profile = $this->buildProfileForSymbol($symbol, $scanDays, $maxLinesPerFile);

            $class = (string)($profile['class'] ?? 'unclassified');
            $confidence = (float)($profile['confidence'] ?? 0.0);
            $tags = is_array($profile['tags'] ?? null) ? $profile['tags'] : [];

            if ($class === 'unclassified') {
                $unclassified++;
            } else {
                $classified++;
            }

            // classes index
            if (!isset($classesIndex[$class])) {
                $classesIndex[$class] = [];
            }
            $classesIndex[$class][] = [
                'symbol' => $symbol,
                'confidence' => $confidence,
                'tags' => $tags,
                'updated_at' => (string)($profile['updated_at'] ?? $ts),
            ];

            // tags index
            foreach ($tags as $tag) {
                $tag = (string)$tag;
                if ($tag === '') {
                    continue;
                }
                if (!isset($tagsIndex[$tag])) {
                    $tagsIndex[$tag] = [];
                }
                $tagsIndex[$tag][] = [
                    'symbol' => $symbol,
                    'confidence' => $confidence,
                    'class' => $class,
                    'updated_at' => (string)($profile['updated_at'] ?? $ts),
                ];
            }

            // candidates (воронка): менеджер только готовит кандидатов + hints (без цен)
            $cand = $this->buildCandidateItem($profile);
            if ($cand !== null) {
                $type = (string)($cand['type'] ?? '');
                if ($type !== '' && isset($candidates[$type])) {
                    $candidates[$type][] = $cand;
                }
            }

            // write profile
            $this->writeJson($this->pathJoin($outProfilesDir, $symbol . '.json'), $profile);
        }

        // ------------------------------------------------------
        // WRITE INDEX FILES
        // ------------------------------------------------------
        foreach ($classesIndex as $class => $items) {
            $items = $this->sortByConfidenceDesc($items);
            $this->writeJson($this->pathJoin($outIndexClassesDir, $class . '.json'), [
                'ts' => $ts,
                'class' => $class,
                'count' => count($items),
                'items' => $items,
            ]);
        }

        foreach ($tagsIndex as $tag => $items) {
            $items = $this->sortByConfidenceDesc($items);
            $this->writeJson($this->pathJoin($outIndexTagsDir, $tag . '.json'), [
                'ts' => $ts,
                'tag' => $tag,
                'count' => count($items),
                'items' => $items,
            ]);
        }

        // ------------------------------------------------------
        // WRITE CANDIDATES
        // ------------------------------------------------------
        foreach ($candidates as $type => $items) {
            $items = $this->sortByScoreDesc($items);
            $this->writeJson($this->pathJoin($outCandidatesDir, $type . '.json'), [
                'ts' => $ts,
                'type' => $type,
                'count' => count($items),
                'items' => $items,
            ]);
        }

        // ------------------------------------------------------
        // STATE + LAST RUN
        // ------------------------------------------------------
        $durationMs = (int)round((microtime(true) - $t0) * 1000);

        $statePath = $this->pathFromModule((string)($this->cfg['output']['state'] ?? 'storage/state.json'));
        $state = [
            'ts' => $ts,
            'symbols_total' => $symbolsTotal,
            'classified' => $classified,
            'unclassified' => $unclassified,
            'errors_count' => count($this->errors),
            'hash' => sha1((string)json_encode([$symbolsTotal, $classified, $unclassified], JSON_UNESCAPED_SLASHES)),
        ];
        $this->writeJson($statePath, $state);

        $lastRunPath = $this->pathFromModule((string)($this->cfg['output']['last_run'] ?? 'storage/last_run.json'));
        $lastRun = [
            'ts' => $ts,
            'ok' => count($this->errors) === 0,
            'duration_ms' => $durationMs,
            'symbols_total' => $symbolsTotal,
            'classified' => $classified,
            'unclassified' => $unclassified,
            'errors' => $this->errors,
        ];
        $this->writeJson($lastRunPath, $lastRun);

        if (count($this->errors) > 0) {
            $errorsPath = $this->pathFromModule((string)($this->cfg['output']['errors'] ?? 'storage/errors.json'));
            $this->writeJson($errorsPath, [
                'ts' => $ts,
                'errors' => $this->errors,
            ]);
        }

        return [
            'success' => count($this->errors) === 0,
            'message' => count($this->errors) === 0 ? 'ok' : 'ok_with_errors',
            'ts' => $ts,
            'symbols_total' => $symbolsTotal,
            'classified' => $classified,
            'unclassified' => $unclassified,
            'duration_ms' => $durationMs,
            'errors' => $this->errors,
        ];
    }

    /* ======================================================
       PROFILE BUILDER
       ====================================================== */

    /**
     * Build behavior profile for a symbol from Parser2 NDJSON.
     *
     * IMPORTANT: output MUST NOT contain raw prices or price series.
     *
     * @return array<string,mixed>
     */
    private function buildProfileForSymbol(string $symbol, int $scanDays, int $maxLinesPerFile): array
    {
        $ts = date('c');

                $historyStorageKey = (string)($this->cfg['sources']['history_storage_key'] ?? '');
        $historyDirRel = (string)($this->cfg['sources']['history_dir'] ?? ''); // legacy

        $symDir = '';

        if ($historyStorageKey !== '') {
            if (!$this->paths->has($historyStorageKey)) {
                $this->errors[] = 'history_storage_key_unknown';
                return $this->profileUnclassified($symbol, $ts, 'history_storage_key_unknown');
            }
            $base = $this->paths->get($historyStorageKey);
            $symDir = $this->pathJoin($base, $symbol);
        } elseif ($historyDirRel !== '') {
            $symDir = $this->pathFromRoot($this->pathJoin($historyDirRel, $symbol));
        } else {
            $this->errors[] = 'config_history_source_missing';
            return $this->profileUnclassified($symbol, $ts, 'config_history_source_missing');
        }

        if (!is_dir($symDir)) {
            return $this->profileUnclassified($symbol, $ts, 'history_symbol_dir_missing');
        }

        // ndjson files (YYYY-MM-DD.ndjson)
        $files = glob($symDir . '/*.ndjson');
        if (!is_array($files)) {
            $files = [];
        }
        sort($files);

        if ($scanDays > 0 && count($files) > $scanDays) {
            $files = array_slice($files, -$scanDays);
        }

        /** @var array<string,mixed> $thr */
        $thr = is_array($this->cfg['thresholds'] ?? null) ? $this->cfg['thresholds'] : [];

        $minPoints = max(10, (int)($thr['min_points'] ?? 300));

        // streaming stats over returns
        $points = 0;
        $returnsN = 0;

        $prevPrice = null;

        $sumR = 0.0;
        $sumR2 = 0.0;
        $sumAbsR = 0.0;
        $maxAbsR = 0.0;

        // pump / reversal tags
        $pumpStepPct = (float)($thr['pump_step_pct'] ?? 0.03);
        $pumpMinEvents = max(1, (int)($thr['pump_min_events'] ?? 3));
        $reversalSteps = max(1, (int)($thr['reversal_steps'] ?? 6));
        $reversalMinRatio = (float)($thr['reversal_min_ratio'] ?? 0.45);

        $pumpEvents = 0;
        $pumpReversals = 0;
        $pendingPump = 0; // countdown for reversal detection
        $pumpDir = 0;     // 1 up, -1 down

        $sumPumpAbs = 0.0;
        $sumPumpSteps = 0;

        // last seen meta fields (NOT prices)
        $lastTurnover24h = null;
        $lastVolume24h = null;
        $lastOpenInterest = null;
        $lastOpenInterestValue = null;
        $lastFundingRate = null;

        $firstTsUnix = null;
        $lastTsUnix = null;

        foreach ($files as $f) {
            $lineNo = 0;
            $fh = @fopen($f, 'rb');
            if ($fh === false) {
                continue;
            }

            while (!feof($fh)) {
                $line = fgets($fh);
                if ($line === false) {
                    break;
                }
                $lineNo++;
                if ($maxLinesPerFile > 0 && $lineNo > $maxLinesPerFile) {
                    break;
                }

                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $row = json_decode($line, true);
                if (!is_array($row)) {
                    continue;
                }

                // Parser2 may store:
                // A) flat raw fields: {lastPrice: "...", turnover24h:"...", ...}
                // B) wrapper: {"ts":"...","data":{...raw...}}
                // C) wrapper: {"t":"...","ts":123,"v":{...}}
                if (isset($row['data']) && is_array($row['data'])) {
                    $payload = $row['data'];
                } elseif (isset($row['v']) && is_array($row['v'])) {
                    $payload = $row['v'];
                } else {
                    $payload = $row;
                }

                // timestamps
                $tsUnix = null;
                if (isset($row['ts_unix'])) {
                    $tsUnix = (int)$row['ts_unix'];
                } elseif (isset($row['ts']) && is_string($row['ts'])) {
                    $tsUnix = $this->safeIsoToUnix($row['ts']);
                } elseif (isset($row['t']) && is_string($row['t'])) {
                    $tsUnix = $this->safeIsoToUnix($row['t']);
                } elseif (isset($row['ts']) && is_int($row['ts'])) {
                    $tsUnix = (int)$row['ts'];
                }

                if ($tsUnix !== null && $tsUnix > 0) {
                    if ($firstTsUnix === null) {
                        $firstTsUnix = $tsUnix;
                    }
                    $lastTsUnix = $tsUnix;
                }

                // price extraction ONLY for computing returns (prices NEVER written to output)
                $p = $this->extractPriceForReturn($payload);
                if ($p !== null && $p > 0) {
                    if ($prevPrice !== null && $prevPrice > 0) {
                        $r = ($p - $prevPrice) / $prevPrice;
                        $returnsN++;

                        $sumR += $r;
                        $sumR2 += ($r * $r);
                        $absR = abs($r);
                        $sumAbsR += $absR;
                        if ($absR > $maxAbsR) {
                            $maxAbsR = $absR;
                        }

                        // pump event detection by step return
                        if ($absR >= $pumpStepPct) {
                            $pumpEvents++;
                            $pendingPump = $reversalSteps;
                            $pumpDir = $r > 0 ? 1 : -1;

                            $sumPumpAbs += $absR;
                            $sumPumpSteps++;
                        } elseif ($pendingPump > 0) {
                            // quick reversal: opposite direction return
                            if ($pumpDir !== 0 && (($r > 0 && $pumpDir < 0) || ($r < 0 && $pumpDir > 0))) {
                                $pumpReversals++;
                                $pendingPump = 0;
                                $pumpDir = 0;
                            } else {
                                $pendingPump--;
                            }
                        }
                    }
                    $prevPrice = $p;
                }

                // liquidity / non-price signals
                if (isset($payload['turnover24h'])) {
                    $lastTurnover24h = $this->toFloatOrNull($payload['turnover24h']);
                }
                if (isset($payload['volume24h'])) {
                    $lastVolume24h = $this->toFloatOrNull($payload['volume24h']);
                }
                if (isset($payload['openInterest'])) {
                    $lastOpenInterest = $this->toFloatOrNull($payload['openInterest']);
                }
                if (isset($payload['openInterestValue'])) {
                    $lastOpenInterestValue = $this->toFloatOrNull($payload['openInterestValue']);
                }
                if (isset($payload['fundingRate'])) {
                    $lastFundingRate = $this->toFloatOrNull($payload['fundingRate']);
                }

                $points++;
            }

            fclose($fh);
        }

        // ------------------------------------------------------
        // CLASSIFICATION
        // ------------------------------------------------------
        $class = 'unclassified';
        $tags = [];

        $deadVolumeMax = (float)($thr['dead_volume24h_max'] ?? 500000);
        $deadPointsMax = max(1, (int)($thr['dead_points_max'] ?? 200));

        $n = max(1, $returnsN);
        $mean = $sumR / $n;
        $var = max(0.0, ($sumR2 / $n) - ($mean * $mean));
        $std = sqrt($var);
        $snr = $std > 0 ? abs($mean) / $std : 0.0;
        $avgAbs = $sumAbsR / $n;

        if ($points <= $deadPointsMax) {
            $class = 'dead';
        } elseif ($lastTurnover24h !== null && $lastTurnover24h > 0 && $lastTurnover24h <= $deadVolumeMax) {
            $class = 'dead';
        } elseif ($points < $minPoints || $returnsN < ($minPoints - 1)) {
            $class = 'unclassified';
        } else {
            $volHi = (float)($thr['volatility_std_hi'] ?? 0.012);
            $volLo = (float)($thr['volatility_std_lo'] ?? 0.004);
            $trendSNRhi = (float)($thr['trend_snr_hi'] ?? 0.18);

            $corrStdMax = (float)($thr['corridor_std_max'] ?? 0.006);
            $corrSNRmax = (float)($thr['corridor_snr_max'] ?? 0.08);

            if ($snr >= $trendSNRhi) {
                $class = 'trend';
            } elseif ($std <= $corrStdMax && $snr <= $corrSNRmax) {
                $class = 'corridor';
            } elseif ($std >= $volHi || $std >= $volLo) {
                $class = 'volatile';
            } else {
                $class = 'unclassified';
            }

            if ($pumpEvents >= $pumpMinEvents) {
                $tags[] = 'pump';
            }

            if ($pumpEvents > 0) {
                $ratio = $pumpReversals / $pumpEvents;
                if ($ratio >= $reversalMinRatio && $pumpEvents >= $pumpMinEvents) {
                    $tags[] = 'manipulated';
                }
            }

            if ($std >= $volHi) {
                $tags[] = 'high_volatility';
            }

            if ($class === 'corridor') {
                $tags[] = 'range';
            }

            if ($class === 'trend') {
                $tags[] = 'trend';
            }
        }

        $confidence = $this->confidenceFromPoints($points, $minPoints);

        $expectedReversalSteps = $pumpEvents >= $pumpMinEvents ? $reversalSteps : 0;
        $intervalSec = max(1, (int)($this->cfg['interval_sec'] ?? 60));
        $expectedReversalSec = $expectedReversalSteps > 0 ? ($expectedReversalSteps * $intervalSec) : 0;

        $avgPumpAbs = $sumPumpSteps > 0 ? ($sumPumpAbs / $sumPumpSteps) : 0.0;

        // candidate scores (for funnel)
        $pumpScore = ($pumpEvents > 0 ? (min(20, $pumpEvents) * 0.10) : 0.0) + min(1.0, $avgPumpAbs * 10.0);
        $corridorScore = ($class === 'corridor') ? (1.0 - min(1.0, $std / max(1e-9, (float)($thr['corridor_std_max'] ?? 0.006)))) : 0.0;
        $trendScore = ($class === 'trend') ? min(1.0, $snr / max(1e-9, (float)($thr['trend_snr_hi'] ?? 0.18))) : 0.0;
        $volatileScore = ($class === 'volatile') ? min(1.0, $std / max(1e-9, (float)($thr['volatility_std_hi'] ?? 0.012))) : 0.0;

        return [
            'schema_version' => '3.0',
            'symbol' => $symbol,

            'class' => $class,
            'tags' => array_values(array_unique($tags)),
            'confidence' => $confidence,

            'metrics' => [
                'points' => $points,
                'returns_n' => $returnsN,

                // returns stats (no prices)
                'mean_return' => $mean,
                'volatility_std' => $std,
                'snr' => $snr,
                'avg_abs_return' => $avgAbs,
                'max_abs_return' => $maxAbsR,

                // pump/reversal
                'pump_events' => $pumpEvents,
                'pump_reversals' => $pumpReversals,
                'avg_pump_abs_return' => $avgPumpAbs,

                // last liquidity snapshot (no prices)
                'turnover24h_last' => $lastTurnover24h,
                'volume24h_last' => $lastVolume24h,
                'open_interest_last' => $lastOpenInterest,
                'open_interest_value_last' => $lastOpenInterestValue,
                'funding_rate_last' => $lastFundingRate,

                // time coverage
                'first_ts_unix' => $firstTsUnix,
                'last_ts_unix' => $lastTsUnix,
                'days_scanned' => count($files),
            ],

            // hints for downstream analyzers (detectors/resolvers)
            'hints' => [
                'interval_sec' => $intervalSec,
                'expected_reversal_steps' => $expectedReversalSteps,
                'expected_reversal_sec' => $expectedReversalSec,
            ],

            // for funnel routing
            'scores' => [
                'pump' => $pumpScore,
                'corridor' => $corridorScore,
                'trend' => $trendScore,
                'volatile' => $volatileScore,
            ],

            'updated_at' => $ts,
            'source' => [
                'parser1_active' => (string)($this->cfg['sources']['registry_filename'] ?? ($this->cfg['sources']['registry_active'] ?? '')),
                'parser2_history_dir' => (string)($this->cfg['sources']['history_storage_key'] ?? ($this->cfg['sources']['history_dir'] ?? '')),
            ],
        ];
    }

    /**
     * Build single candidate list item, or null.
     *
     * @param array<string,mixed> $profile
     * @return array<string,mixed>|null
     */
    private function buildCandidateItem(array $profile): ?array
    {
        $class = (string)($profile['class'] ?? 'unclassified');
        $confidence = (float)($profile['confidence'] ?? 0.0);
        $symbol = (string)($profile['symbol'] ?? '');
        if ($symbol === '') {
            return null;
        }

        /** @var array<string,float> $scores */
        $scores = is_array($profile['scores'] ?? null) ? $profile['scores'] : [];
        $pumpScore = (float)($scores['pump'] ?? 0.0);
        $corridorScore = (float)($scores['corridor'] ?? 0.0);
        $trendScore = (float)($scores['trend'] ?? 0.0);
        $volatileScore = (float)($scores['volatile'] ?? 0.0);

        $candCfg = is_array($this->cfg['candidates'] ?? null) ? $this->cfg['candidates'] : [];

        $minConf = (float)($candCfg['min_confidence'] ?? 0.25);
        $minScorePump = (float)($candCfg['min_score_pump'] ?? 0.35);
        $minScoreCorridor = (float)($candCfg['min_score_corridor'] ?? 0.35);
        $minScoreTrend = (float)($candCfg['min_score_trend'] ?? 0.35);
        $minScoreVolatile = (float)($candCfg['min_score_volatile'] ?? 0.35);

        if ($confidence < $minConf) {
            return null;
        }

        $type = '';
        $score = 0.0;

        $tags = is_array($profile['tags'] ?? null) ? $profile['tags'] : [];
        $hasPumpTag = in_array('pump', $tags, true);

        if ($hasPumpTag || $pumpScore >= $minScorePump) {
            $type = 'pump';
            $score = $pumpScore;
        } elseif ($class === 'corridor' && $corridorScore >= $minScoreCorridor) {
            $type = 'corridor';
            $score = $corridorScore;
        } elseif ($class === 'trend' && $trendScore >= $minScoreTrend) {
            $type = 'trend';
            $score = $trendScore;
        } elseif ($class === 'volatile' && $volatileScore >= $minScoreVolatile) {
            $type = 'volatile';
            $score = $volatileScore;
        } else {
            return null;
        }

        $hints = is_array($profile['hints'] ?? null) ? $profile['hints'] : [];

        return [
            'type' => $type,
            'symbol' => $symbol,
            'class' => $class,
            'confidence' => $confidence,
            'score' => $score,
            'tags' => $tags,
            'hints' => $hints,
            'updated_at' => (string)($profile['updated_at'] ?? date('c')),
        ];
    }

    private function confidenceFromPoints(int $points, int $minPoints): float
    {
        if ($points <= 0) {
            return 0.0;
        }
        if ($points >= ($minPoints * 10)) {
            return 0.98;
        }
        $x = $points / max(1, $minPoints);
        $c = 1.0 - (1.0 / (1.0 + $x));
        return max(0.05, min(0.98, $c));
    }

    /**
     * @return array<string,mixed>
     */
    private function profileUnclassified(string $symbol, string $ts, string $reason): array
    {
        return [
            'schema_version' => '3.0',
            'symbol' => $symbol,
            'class' => 'unclassified',
            'tags' => [],
            'confidence' => 0.0,
            'metrics' => [
                'points' => 0,
                'reason' => $reason,
            ],
            'updated_at' => $ts,
        ];
    }

    /* ======================================================
       INPUT LOADERS
       ====================================================== */

    /**
     * Load active symbols list from Parser1 active.json
     *
     * Supported shapes:
     * - { items: [ {symbol:"..."}, ... ] }
     * - { symbols: ["BTCUSDT", ...] }
     * - [ {symbol:"..."}, ... ]
     *
     * @return array<int,string>
     */
    private function loadActiveSymbols(): array
    {
        // New SystemPaths-driven config (preferred)
        $storageKey = (string)($this->cfg['sources']['registry_storage_key'] ?? '');
        $filename = (string)($this->cfg['sources']['registry_filename'] ?? 'active.json');

        // Legacy config compatibility (old key): sources.registry_active (relative path)
        $legacyRel = (string)($this->cfg['sources']['registry_active'] ?? '');

        $path = '';

        if ($storageKey !== '') {
            if (!$this->paths->has($storageKey)) {
                $this->errors[] = 'registry_storage_key_unknown';
                return [];
            }
            $base = $this->paths->get($storageKey);
            $path = $this->pathJoin($base, $filename);
        } elseif ($legacyRel !== '') {
            // Best-effort: resolve legacy path relative to project root
            $path = $this->pathFromRoot($legacyRel);
        } else {
            $this->errors[] = 'registry_source_missing';
            return [];
        }

        if (!is_file($path)) {
            $this->errors[] = 'registry_active_missing';
            return [];
        }

        $data = $this->readJson($path);
        if (!is_array($data)) {
            $this->errors[] = 'registry_active_json_invalid';
            return [];
        }
        if (!is_file($path)) {
            $this->errors[] = 'registry_active_missing';
            return [];
        }

        $data = $this->readJson($path);
        if (!is_array($data)) {
            $this->errors[] = 'registry_active_json_invalid';
            return [];
        }

        $list = null;

        if (isset($data['items']) && is_array($data['items'])) {
            // Shape 1: { items: [ {symbol:"..."}, ... ] }
            $list = $data['items'];
        } elseif (isset($data['symbols']) && is_array($data['symbols'])) {
            // Shape 2: { symbols: ["BTCUSDT", ...] }
            $syms = [];
            foreach ($data['symbols'] as $s) {
                $sym = strtoupper(trim((string)$s));
                if ($sym !== '') {
                    $syms[] = $sym;
                }
            }
            $syms = array_values(array_unique($syms));
            sort($syms);
            return $syms;
        } elseif (isset($data[0]) && is_array($data[0])) {
            // Shape 3: [ {symbol:"..."}, ... ] - numeric indexed array
            $list = $data;
        } elseif (!empty($data) && !array_is_list($data)) {
            // Shape 4: { "BTCUSDT": {symbol:"BTCUSDT",...}, ... } - associative array keyed by symbol
            // This is the format produced by Parser1 active.json
            $list = array_values($data);
        }

        if (!is_array($list)) {
            $this->errors[] = 'registry_shape_unknown';
            return [];
        }

        $syms = [];
        foreach ($list as $key => $row) {
            if (!is_array($row)) {
                // If $row is not an array but $key is a string, the key might be the symbol
                if (is_string($key) && $key !== '') {
                    $syms[] = strtoupper(trim($key));
                }
                continue;
            }
            $sym = strtoupper(trim((string)($row['symbol'] ?? '')));
            if ($sym !== '') {
                $syms[] = $sym;
            }
        }

        $syms = array_values(array_unique($syms));
        sort($syms);
        return $syms;
    }

    /* ======================================================
       NDJSON HELPERS
       ====================================================== */

    /**
     * Extract one numeric price to compute returns.
     * Price never leaves the function (not written to output).
     *
     * @param array<string,mixed> $payload
     */
    private function extractPriceForReturn(array $payload): ?float
    {
        if (isset($payload['lastPrice'])) {
            return $this->toFloatOrNull($payload['lastPrice']);
        }
        if (isset($payload['markPrice'])) {
            return $this->toFloatOrNull($payload['markPrice']);
        }
        if (isset($payload['indexPrice'])) {
            return $this->toFloatOrNull($payload['indexPrice']);
        }
        return null;
    }

    /**
     * @param mixed $v
     */
    private function toFloatOrNull($v): ?float
    {
        if ($v === null) {
            return null;
        }
        if (is_float($v) || is_int($v)) {
            return (float)$v;
        }
        $s = trim((string)$v);
        if ($s === '') {
            return null;
        }
        if (!is_numeric($s)) {
            return null;
        }
        return (float)$s;
    }

    private function safeIsoToUnix(string $iso): ?int
    {
        $iso = trim($iso);
        if ($iso === '') {
            return null;
        }
        $t = strtotime($iso);
        if ($t === false) {
            return null;
        }
        return (int)$t;
    }

    /* ======================================================
       FS / JSON HELPERS
       ====================================================== */

    /** @return array<string,mixed> */
    private function loadConfig(): array
    {
        $cfgPath = $this->paths->get('parser.parser3_manager_behavior.config');
        $cfg = is_file($cfgPath) ? require $cfgPath : [];
        return is_array($cfg) ? $cfg : [];
    }

    

    private function pathFromModule(string $rel): string
    {
        $moduleBase = $this->paths->get('parser.parser3_manager_behavior');
        $rel = ltrim($rel, '/');

        // If config uses 'storage/..' we map it into module storage dir.
        if (str_starts_with($rel, 'storage/')) {
            $rel = substr($rel, 8);
            return $this->pathJoin($this->moduleStorage, $rel);
        }

        return $this->pathJoin($moduleBase, $rel);
    }


    private function pathFromRoot(string $rel): string
    {
        $root = $this->paths->get('root');
        return $this->pathJoin($root, ltrim($rel, '/'));
    }


    private function pathJoin(string $a, string $b): string
    {
        return rtrim($a, "/\\") . '/' . ltrim($b, "/\\");
    }

    private function ensureDir(string $dir): void
    {
        if ($dir === '' || is_dir($dir)) {
            return;
        }
        @mkdir($dir, 0775, true);
    }

    /** @return array<string,mixed>|null */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function writeJson(string $path, array $data): void
    {
        $dir = preg_replace('~/[^/]+$~', '', $path);
        if (!is_string($dir) || $dir === '') {
            $dir = $this->moduleStorage;
        }
        $this->ensureDir($dir);

        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $json = json_encode($data, $flags);
        if (!is_string($json)) {
            $this->errors[] = 'json_encode_failed:' . basename($path);
            return;
        }

        $atomic = (bool)($this->cfg['write']['atomic'] ?? true);
        if ($atomic) {
            $tmp = $path . '.tmp';
            @file_put_contents($tmp, $json . "\n", LOCK_EX);
            @rename($tmp, $path);
            return;
        }

        @file_put_contents($path, $json . "\n", LOCK_EX);
    }

    /**
     * Sort index items by confidence desc.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function sortByConfidenceDesc(array $items): array
    {
        usort($items, function (array $a, array $b): int {
            return ((float)($b['confidence'] ?? 0.0)) <=> ((float)($a['confidence'] ?? 0.0));
        });
        return $items;
    }

    /**
     * Sort candidate items by score desc.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function sortByScoreDesc(array $items): array
    {
        usort($items, function (array $a, array $b): int {
            return ((float)($b['score'] ?? 0.0)) <=> ((float)($a['score'] ?? 0.0));
        });
        return $items;
    }

    /**
     * Consistent finish helper.
     *
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function finish(bool $ok, string $message, float $t0, array $result): array
    {
        $durationMs = (int)round((microtime(true) - $t0) * 1000);
        $result['duration_ms'] = $durationMs;

        $lastRunPath = $this->pathFromModule((string)($this->cfg['output']['last_run'] ?? 'storage/last_run.json'));
        $this->writeJson($lastRunPath, [
            'ts' => $result['ts'] ?? date('c'),
            'ok' => $ok,
            'duration_ms' => $durationMs,
            'message' => $message,
            'errors' => $this->errors,
        ]);

        return [
            'success' => $ok,
            'message' => $message,
        ] + $result;
    }
}

/* RULES
- Manager does NOT output price series or raw prices.
- Manager prepares: classes + tags + confidence + hints + candidates.
- Downstream:
  - Detector reads storage/candidates/*.json + windows from Parser2
  - Resolver/Decider computes precise entry price & side
- CONFIG FIRST / ZERO HARDCODE
*/