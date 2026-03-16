# Smart Brain — Real Runtime Audit Report

**Date:** 2026-03-16  
**Auditor:** Deep Code Analysis + Runtime Data Inspection  
**Scope:** Full simulation pipeline: pattern detection, side logic, leverage, stop engine, simulator lifecycle, stats engine  
**Method:** Static code analysis of 14 library files (3,623 LOC) + inspection of all runtime/storage JSON files  

---

## 1. Executive Summary

This audit was conducted through deep code analysis of the Smart Brain simulation pipeline combined with inspection of all runtime and storage data files. The audit clearly separates **CI/environment limitations** from **actual Smart Brain code issues**.

### Runtime Evidence Status

All storage files exist but contain empty data (`[]` or `{}`):

| File | Status | Interpretation |
|------|--------|----------------|
| `storage/candidates.json` | `[]` empty | **CI limitation** — Parser3 not available |
| `storage/monitors.json` | `[]` empty | Expected — no candidates to monitor |
| `storage/signals.json` | `[]` empty | Expected — no monitors to signal |
| `storage/simulator/waiting.json` | `[]` empty | Expected — no signals to process |
| `storage/simulator/active.json` | `[]` empty | Expected — no waiting trades to activate |
| `storage/simulator/closed.json` | `[]` empty | Expected — no active trades to close |
| `storage/simulator/stats.json` | Does not exist | Expected — only written at runtime after tick() |
| `storage/last_run.json` | `{}` empty | Pipeline has never executed successfully |
| `runtime/user_config.json` | Does not exist | **P1 Bug** — not created on first deploy |
| `runtime/effective_config.json` | Does not exist | Expected — only written during pipeline run |
| `storage/passports/` | Empty (`.gitkeep` only) | Expected — no trades yet |
| `storage/logs/` | Empty (`.gitkeep` only) | Expected — no pipeline runs |

### Key Distinction

> **The empty data state is NOT a Smart Brain bug.** It is a CI/environment limitation. Parser4 depends on Parser3 profiles data (`parser.parser3_manager_behavior.storage`) which does not exist in this environment. Without upstream data, Smart Brain correctly produces zero candidates.

### Actual Code Issues Found

| Priority | Count | Summary |
|----------|-------|---------|
| **P0** | 0 | No critical logic bugs remaining (previous P0 `signal_to_entry_conversion` bug has been **FIXED**) |
| **P1** | 2 | Flat trend side rejection; missing user_config.json initialization |
| **P2** | 3 | Fallback default mismatches; bootstrap documentation; config snapshot format |

### Configuration Status (Verified)

Several issues from prior audits have been **already fixed**:

| Issue | Previous Status | Current Status |
|-------|----------------|----------------|
| Pattern config defaults | Only `double_bottom`, mode `one` | ✅ **FIXED** — All 3 patterns enabled, mode `any` |
| `signal_to_entry_conversion` > 1.0 | Bug: used current-cycle signals as denominator | ✅ **FIXED** — Uses cumulative `(waiting+active+closed)` as denominator |
| Early failure guard | Disabled by default | ✅ **FIXED** — Now `early_failure_enabled = true` |
| Analyzer threshold too strict | 0.65 | ✅ **FIXED** — Lowered to 0.45 |
| Corridor fit over-rejection | Floor at 0.1 | ✅ **FIXED** — Floors raised to 0.35-0.45 |

---

## 2. Runtime Evidence Used

### Primary Data Sources Inspected

| Source | Location | Content | Used For |
|--------|----------|---------|----------|
| Candidates | `storage/candidates.json` | `[]` | Pattern statistics |
| Monitors | `storage/monitors.json` | `[]` | Side/leverage audit |
| Signals | `storage/signals.json` | `[]` | Signal flow analysis |
| Waiting | `storage/simulator/waiting.json` | `[]` | Lifecycle audit |
| Active | `storage/simulator/active.json` | `[]` | Lifecycle audit |
| Closed | `storage/simulator/closed.json` | `[]` | Stop engine / performance |
| Last Run | `storage/last_run.json` | `{}` | Pipeline counters |
| Stats | `storage/simulator/stats.json` | N/A (not generated) | Stats validation |
| Passports | `storage/passports/` | Empty | Bootstrap/normal audit |
| User Config | `runtime/user_config.json` | N/A (not created) | Config validation |
| Effective Config | `runtime/effective_config.json` | N/A (not generated) | Config validation |
| Logs | `storage/logs/` | Empty | Debug analysis |

### Secondary Sources (Code Analysis)

| Source | Location | Lines | Used For |
|--------|----------|-------|----------|
| Parser4 Analyzer | `lib/parser4_analyzer.php` | ~450 | Pattern/side/scoring logic |
| Risk Engine | `lib/risk_engine.php` | ~443 | Leverage/stop/signal logic |
| Simulator Engine | `lib/simulator_engine.php` | ~534 | Trade lifecycle/ROI/stats |
| Smart Brain Core | `lib/smart_brain_core.php` | ~545 | Pipeline flow |
| Config: parser4 | `config/parser4.php` | 39 | Pattern/analyzer settings |
| Config: risk_engine | `config/risk_engine.php` | 55 | Risk/bootstrap settings |

### Audit Script Output

The `simulation_audit.php` script was executed and correctly reported all-zero statistics, confirming the empty runtime state. Output: `storage/simulation_audit_report.json`.

---

## 3. Pattern Statistics

### Current Configuration (Verified from `config/parser4.php`)

| Setting | Value | Status |
|---------|-------|--------|
| Enabled algorithms | `['double_bottom', 'double_top', 'pullback_trend_continue']` | ✅ All 3 enabled |
| Pattern mode | `any` | ✅ Best of all detectors |
| Analyzer threshold | `0.45` | ✅ Reasonable |
| Weights | pattern=0.50, trend=0.20, corridor=0.10, entry=0.20 | ✅ Pattern dominant |

### Per-Algorithm Runtime Evidence

**No runtime evidence for any pattern in provided data.** All counters are zero because Parser3 profiles are not available in this environment.

| Metric | `double_bottom` | `double_top` | `pullback_trend_continue` |
|--------|-----------------|--------------|---------------------------|
| candidates_total | 0 | 0 | 0 |
| signals_total | 0 | 0 | 0 |
| waiting_total | 0 | 0 | 0 |
| active_total | 0 | 0 | 0 |
| closed_total | 0 | 0 | 0 |
| wins | 0 | 0 | 0 |
| losses | 0 | 0 | 0 |
| winrate | N/A | N/A | N/A |
| average_roi | N/A | N/A | N/A |
| average_mae | N/A | N/A | N/A |
| average_mfe | N/A | N/A | N/A |
| average_duration | N/A | N/A | N/A |
| long_count | 0 | 0 | 0 |
| short_count | 0 | 0 | 0 |
| stop_loss_count | 0 | 0 | 0 |
| early_failure_count | 0 | 0 | 0 |
| trailing_stop_count | 0 | 0 | 0 |
| break_even_stop_count | 0 | 0 | 0 |
| take_profit_count | 0 | 0 | 0 |
| bootstrap_signals_count | 0 | 0 | 0 |
| normal_signals_count | 0 | 0 | 0 |
| average_leverage | N/A | N/A | N/A |

> **Note:** Zero data is a CI environment limitation, NOT a Smart Brain code bug. The pattern detection code is structurally sound per static analysis.

### Code Quality Assessment (Static Analysis)

| Algorithm | Side Logic | Detection Method | Code Quality |
|-----------|-----------|------------------|--------------|
| `double_bottom` | Always `long` | Two troughs near corridor low | ✅ Solid |
| `double_top` | Always `short` | Two peaks near corridor high | ✅ Solid |
| `pullback_trend_continue` | From `trend_bias` | Pullback against trend direction | ⚠️ Fails on flat `trend_bias` |

### Pattern Enablement Recommendation

| Algorithm | Recommendation | Reason |
|-----------|---------------|--------|
| `double_bottom` | **Keep enabled** | Well-implemented, clean long entry |
| `double_top` | **Keep enabled** | Well-implemented, clean short entry |
| `pullback_trend_continue` | **Keep enabled, fix flat trend handling** | Good concept, flat-trend edge case needs resolution |

---

## 4. Side Audit

### Expected vs. Implemented Side Mapping

| Pattern | Expected Side | Implementation | Verified In | Status |
|---------|--------------|----------------|-------------|--------|
| `double_bottom` | `long` | Hardcoded in `deriveSideFromPattern()` | `parser4_analyzer.php:301-302` | ✅ Correct |
| `double_top` | `short` | Hardcoded in `deriveSideFromPattern()` | `parser4_analyzer.php:303` | ✅ Correct |
| `pullback_trend_continue` up | `long` | Maps `trend_bias='up'` → `long` | `parser4_analyzer.php:304-305` | ✅ Correct |
| `pullback_trend_continue` down | `short` | Maps `trend_bias='down'` → `short` | `parser4_analyzer.php:306` | ✅ Correct |
| `pullback_trend_continue` flat | **null → REJECTED** | Returns null → `rejected_side_unresolved` | `parser4_analyzer.php:307` | ⚠️ P1 Bug |

### Side Propagation Chain (Verified)

```
Parser4Analyzer.deriveSideFromPattern()  →  candidate['side']
    ↓ (smart_brain_core.php:148-150 — conditional copy if side exists)
SmartBrainCore.executePipeline()         →  monitor['side']
    ↓ (risk_engine.php:141 — resolveExplicitSide())
RiskEngine.apply()                       →  signal['side'] (or REJECT if null)
    ↓ (simulator_engine.php — propagated as-is)
SimulatorEngine.tick()                   →  trade['side']
```

### Safety Design: No Silent Long Fallback

The system explicitly rejects candidates with unresolved sides rather than silently defaulting to `long`. This is verified:
- `parser4_analyzer.php:307`: `default => null` for unknown trend_bias
- `risk_engine.php:142-145`: Rejects with `rejected_side_unresolved` counter
- No `?? 'long'` fallback anywhere in the smart_brain module

### Runtime Evidence

- **Wrong-side candidates in runtime data:** None found (no candidates exist)
- **Wrong-side signals:** None found (no signals exist)
- **Wrong-side closed trades:** None found (no closed trades exist)
- **Unresolved side rejections in last_run:** Cannot verify (last_run.json is empty)
- **Rejection evidence in logs:** No log files exist yet

> **Assessment:** Side logic is reliable for `up`/`down` trends. The flat-trend gap in `pullback_trend_continue` is the only code-level issue, classified as P1.

---

## 5. Leverage Audit

### Dynamic Leverage V1 Implementation (Verified: `risk_engine.php:389-443`)

**Base ladder from analyzer_score:**

| Score Range | Base Leverage | Code Location |
|-------------|--------------|---------------|
| < 0.50 | 2x | `risk_engine.php:406` |
| 0.50 - 0.65 | 3x | `risk_engine.php:404` |
| 0.65 - 0.80 | 4x | `risk_engine.php:402` |
| ≥ 0.80 | 5x | `risk_engine.php:400` |

**Adjustments (verified, can stack):**

| Condition | Adjustment | Threshold Constant | Code Location |
|-----------|-----------|-------------------|---------------|
| High volatility | -1x | > 0.05 | `risk_engine.php:413-416` |
| Wide corridor | -1x | > 0.10 | `risk_engine.php:419-422` |
| Bootstrap mode | Clamp to `bootstrap_max_leverage` | Config: 3 | `risk_engine.php:425-428` |
| Weak reliability | -1x (normal mode only) | < 0.30 | `risk_engine.php:431-434` |

**Final:** `max(1, min(leverage, modeMaxLeverage, userMaxLeverage))` at `risk_engine.php:437`

### Runtime Evidence

- **No signals exist in runtime data** → cannot compute min/max/avg leverage
- **No leverage distribution available** → cannot verify dynamism from data
- **No bootstrap vs. normal leverage split available**

### Code-Based Prediction

Leverage will appear **near-static at 2-3x** initially because:

1. **Bootstrap cap dominates:** With `bootstrap_max_leverage = 3`, all new symbols are capped at 3x regardless of analyzer_score
2. **Score clustering near threshold:** With threshold at 0.45, passing scores will be in 0.45-0.65 range → base 2-3x
3. **Adjustments stack downward:** High volatility (-1) + wide corridor (-1) can reduce to 1x
4. **No upward adjustments exist:** Only downward penalties, no bonuses

### Leverage Changes With Key Factors (Code Analysis)

| Factor | Changes Leverage? | How |
|--------|-------------------|-----|
| analyzer_score | ✅ Yes | Base ladder: 2x/3x/4x/5x |
| bootstrap status | ✅ Yes | Clamps to bootstrap_max (3x) |
| reliability | ✅ Yes | -1x if < 0.30 (normal mode only) |
| volatility | ✅ Yes | -1x if > 0.05 |
| corridor width | ✅ Yes | -1x if > 0.10 |

> **Assessment:** Leverage system IS truly dynamic in code. However, bootstrap mode effectively clamps it to a 1-3x range. Normal mode will show 2-5x variation once reliability data accumulates. The system is NOT stuck at a static value — it's correctly conservative during bootstrap.

---

## 6. Stop Engine Audit

### Current Configuration (Verified: `config/risk_engine.php:44-53`)

| Setting | Value |
|---------|-------|
| `stop_mode` | `brain_managed` |
| `simple_stop_liq_factor` | 0.15 |
| `brain_stop_corridor_factor` | 0.25 |
| `brain_stop_volatility_factor` | 0.50 |
| `brain_stop_liq_safety_factor` | 0.30 |
| `early_failure_enabled` | **true** ✅ |
| `early_failure_window_minutes` | 5 |
| `early_failure_max_adverse_roi` | -0.008 (-0.8%) |

### Mode 1: `simple_liq_percent` (Verified: `risk_engine.php`)

```
stop_distance = (1 / leverage) × simple_stop_liq_factor
```

Example: 3x leverage × 0.15 factor = 5% stop from entry.

### Mode 2: `brain_managed` (Verified: `risk_engine.php`)

```
corridor_stop = corridor_width × brain_stop_corridor_factor (0.25)
volatility_stop = volatility × brain_stop_volatility_factor (0.50)
liq_stop = (1/leverage) × brain_stop_liq_safety_factor (0.30)
effective_stop = max(corridor_stop, volatility_stop, liq_stop)
```

### Early Failure Guard (Verified: `simulator_engine.php:301-317`)

| Setting | Value | Status |
|---------|-------|--------|
| Enabled | `true` | ✅ Now enabled |
| Window | 5 minutes | Checks trade age ≤ window |
| Max adverse ROI | -0.008 | Cuts trade if ROI ≤ -0.8% within window |

### Exit Priority Order (Verified: `simulator_engine.php` tick() method)

1. **Early Failure** — if enabled AND within window AND ROI ≤ threshold
2. **Trailing Stop** — if trailing active AND ROI drops below trailing lock
3. **Break-Even Stop** — if break-even active AND stoploss==0 AND ROI ≤ 0
4. **Stop Loss** — if ROI ≤ -stop_distance
5. **Take Profit** — if ROI ≥ take_profit target

### Runtime Evidence

| Metric | Value | Source |
|--------|-------|--------|
| closed_total | 0 | `storage/simulator/closed.json` is empty |
| stop_loss_count | 0 | No closed trades |
| early_failure_count | 0 | No closed trades |
| trailing_stop_count | 0 | No closed trades |
| break_even_stop_count | 0 | No closed trades |
| take_profit_count | 0 | No closed trades |
| average_roi | N/A | No data |
| average_mae | N/A | No data |
| average_mfe | N/A | No data |
| winrate | N/A | No data |

> **Assessment:** Stop engine code is well-structured. `brain_managed` is correctly configured as default. Early failure is now enabled. Cannot assess real-world effectiveness without trade data. Code analysis confirms exit priority order is correct and all close reasons are properly tracked.

---

## 7. Simulator Lifecycle Audit

### State Machine (Verified: `simulator_engine.php`)

```
Signal → WAITING → (price enters entry zone) → ACTIVE → (exit condition met) → CLOSED
```

### Current Runtime State

| State | Count | File | Status |
|-------|-------|------|--------|
| Waiting | 0 | `storage/simulator/waiting.json` | Empty `[]` |
| Active | 0 | `storage/simulator/active.json` | Empty `[]` |
| Closed | 0 | `storage/simulator/closed.json` | Empty `[]` |
| Signals | 0 | `storage/signals.json` | Empty `[]` |

### Root Cause of Empty State

This is a **CI environment limitation**, not a simulator bug:
1. Parser3 profiles not available → Parser4 finds no symbols → 0 candidates
2. Without candidates → no monitors → no entry zones → no signals
3. Without signals → simulator has nothing to process

### Code Quality Assessment (Static Analysis)

| Check | Result | Details |
|-------|--------|---------|
| WAITING→ACTIVE transition | ✅ Correct | Price checked against entry zone (`simulator_engine.php`) |
| ROI calculation (long) | ✅ Correct | `(price - entry) / entry` |
| ROI calculation (short) | ✅ Correct | `(entry - price) / entry` |
| MAE/MFE tracking | ✅ Correct | Updated every tick, preserved worst/best |
| Duration tracking | ✅ Correct | Minutes between `activated_at` and `closed_at` |
| Close condition priority | ✅ Correct | Early failure → trailing → break-even → stop → TP |
| Stuck trade risk | ✅ None found | Active trades checked every tick cycle |
| Orphan/duplicate risk | ✅ None found | State transitions are atomic with proper array manipulation |

> **Assessment:** Simulator lifecycle code is well-implemented. No stuck-trade or orphan risks detected. The empty state is due to missing upstream data, not simulator bugs.

---

## 8. Bootstrap vs Normal Audit

### Bootstrap Mode Criteria (Verified: `risk_engine.php:75-80`)

| Setting | Config Value | Code Fallback | Active Value |
|---------|-------------|---------------|-------------|
| `bootstrap_enabled` | `true` | `false` | `true` (from config) |
| `bootstrap_max_signals` | 8 | 5 | 8 (from config) |
| `bootstrap_budget_factor` | 0.35 | 0.50 | 0.35 (from config) |
| `bootstrap_max_leverage` | 3 | 3 | 3 |
| `warmup_min_trades` | 6 | 10 | 6 (from config) |
| `min_reliability_after_warmup` | 0.12 | 0.15 | 0.12 (from config) |

⚠️ **P2 Finding:** Code fallback defaults differ from config defaults. If config loading fails, the system would use different values silently. See Bug Catalog P2-2.

### Bootstrap vs Normal Determination (Verified: `risk_engine.php:168-172`)

```
isWarmup = (trades_total < warmup_min_trades)
```

- If passport doesn't exist OR trades_total < 6 → **Bootstrap mode**
- If trades_total ≥ 6 AND reliability_score ≥ 0.12 → **Normal mode**

### Runtime Evidence

| Metric | Bootstrap | Normal |
|--------|-----------|--------|
| signals | 0 | 0 |
| winrate | N/A | N/A |
| avg leverage | N/A | N/A |
| avg ROI | N/A | N/A |

- **Passports directory is empty** → all symbols would be in bootstrap mode
- **`last_run.json` is empty** → no bootstrap_signals_count or normal_signals_count available

### Assessment

**System is trapped in bootstrap** — this is expected and not a bug. Bootstrap is the correct initial state when no trading history exists. The transition requires:
1. Parser3 data to produce candidates
2. Trades to accumulate per-symbol passports
3. 6+ trades per symbol to exit warmup
4. Reliability score ≥ 0.12 to enter normal mode

> This is NOT a chicken-and-egg problem: bootstrap mode still generates signals (up to 8 per cycle), just with lower leverage (max 3x) and smaller budgets (35% factor). The system WILL transition naturally once trades accumulate.

---

## 9. Pattern Enablement Decision

Based on real runtime data AND code analysis:

### Patterns to Keep Enabled

| Pattern | Reason | Confidence |
|---------|--------|------------|
| `double_bottom` | Well-implemented, clean long entry, hardcoded side=long | ✅ High |
| `double_top` | Well-implemented, clean short entry, hardcoded side=short | ✅ High |
| `pullback_trend_continue` | Good concept, derives side from trend_bias, covers both directions | ⚠️ Medium (flat-trend gap) |

### Patterns to Disable

**None.** All three are structurally sound. Config already has all 3 enabled with mode `any`.

### Patterns with Insufficient Evidence

All three patterns have **no runtime trade evidence** — this is a CI limitation, not a pattern quality issue. Real production data from Parser3 is required to evaluate actual pattern performance.

### Patterns Not Actually Being Used Despite Config

**All patterns are correctly configured and enabled**, but none are producing candidates because Parser3 data is not available in this environment. In a real runtime with Parser3 profiles:
- All 3 detectors would be called per candidate (mode `any` → best result wins)
- Each detector independently checks price history for its pattern

---

## 10. Metric Validation

### Stats Engine Formula (Verified: `simulator_engine.php:508-515`)

```php
// FIXED: signal_to_entry_conversion now uses cumulative denominator
$totalEntries = count($waiting) + count($active) + $totalClosed;
$enteredCount = count($active) + $totalClosed;
$conversion   = ($totalEntries > 0)
    ? round($enteredCount / $totalEntries, 4)
    : 0.0;
```

| Metric | Formula | Status |
|--------|---------|--------|
| `total_trades` | count(closed) | ✅ Correct |
| `winrate` | wins / total_trades where win = ROI ≥ 0 | ✅ Correct (break-even = win) |
| `average_roi` | sum(rois) / total_trades | ✅ Correct |
| `median_mae` | median of all MAE values | ✅ Correct |
| `median_mfe` | median of all MFE values | ✅ Correct |
| `median_duration` | median of all durations (minutes) | ✅ Correct |
| `signal_to_entry_conversion` | (active+closed) / (waiting+active+closed) | ✅ **FIXED** — was previously using current-cycle signals as denominator |

### Previous Bug Status

> **`signal_to_entry_conversion` > 1.0 bug — RESOLVED.** The formula now uses `(waiting + active + closed)` as the denominator instead of `count(signals.json)`. Since waiting+active+closed ≥ active+closed, the result is guaranteed to be ≤ 1.0. Verified at `simulator_engine.php:508-515`.

### Runtime Metric Values

No metrics available to validate (stats.json not generated, no closed trades exist).

---

## 11. Bug Catalog

### P0 — Critical Logic Bugs

**None remaining.** The previously reported P0 bug (`signal_to_entry_conversion > 1.0`) has been fixed.

| Previously Reported | Status |
|---------------------|--------|
| `signal_to_entry_conversion` > 1.0 | ✅ **FIXED** — denominator changed to cumulative total |
| Parser3 data missing | **Environment issue** — not a Smart Brain code bug |

### P1 — Major Quality Problems

| # | Title | Real Observed Symptom | Evidence Source | Likely Root Cause | Impact | Recommended Fix |
|---|-------|----------------------|-----------------|-------------------|--------|-----------------|
| P1-1 | Flat trend rejects `pullback_trend_continue` | `deriveSideFromPattern()` returns `null` when `trend_bias='flat'`, causing `rejected_side_unresolved` | `parser4_analyzer.php:307` — `default => null` | Match expression has no case for `'flat'` or other non-up/non-down values | Pullback candidates in ranging/flat markets are always rejected | Add explicit handling: flat → skip pattern OR flat → use neutral logic |
| P1-2 | User config not created on first deploy | `runtime/user_config.json` does not exist until user manually saves config in UI | File system inspection — file does not exist | No initialization/seeding logic in `SmartBrainConfig` constructor | System runs with code-level fallback defaults only; some fallbacks differ from config file values | Create default `user_config.json` with sensible defaults during module init |

### P2 — Secondary Issues / Tuning

| # | Title | Real Observed Symptom | Evidence Source | Likely Root Cause | Impact | Recommended Fix |
|---|-------|----------------------|-----------------|-------------------|--------|-----------------|
| P2-1 | Fallback defaults mismatch config values | Code fallbacks differ from `config/risk_engine.php` values | `risk_engine.php:76` fallback=5 vs config=8; line 79 fallback=10 vs config=6; line 80 fallback=0.15 vs config=0.12 | `??` fallback values hardcoded independently of config | If config loading fails, system uses stricter defaults silently | Align `??` fallback values with config file defaults |
| P2-2 | Bootstrap mode permanent until production data | All symbols in bootstrap (passports directory empty) | `storage/passports/` — empty | No Parser3 data → no trades → no passport data | Lower leverage, fewer signals, smaller budgets | Expected behavior — document that 6+ trades per symbol needed to transition |
| P2-3 | Effective config not written until pipeline runs | `runtime/effective_config.json` does not exist | File system inspection | Only written during `executePipeline()` | Global Config UI tab may show stale/missing data before first run | Write effective config eagerly on module load |

---

## 12. Fix Roadmap

### P0 — Critical (None Remaining)

All previously reported P0 bugs have been resolved:
- ✅ `signal_to_entry_conversion` formula fixed
- ✅ All 3 patterns enabled with mode `any`
- ✅ Early failure guard enabled
- ✅ Analyzer threshold lowered to 0.45

### P1 — Major (Do Next)

| Order | Fix | Effort | Files | Details |
|-------|-----|--------|-------|---------|
| 1 | Handle flat `trend_bias` in `deriveSideFromPattern()` | Low | `parser4_analyzer.php:307` | Add `'flat' => null` explicitly (documented rejection) or assign side based on secondary indicators |
| 2 | Create default `user_config.json` on first deploy | Low | `smart_brain_config.php` | Seed file with all 3 patterns enabled, mode `any`, current risk defaults |

### P2 — Secondary (Tune Later)

| Order | Fix | Effort | Files | Details |
|-------|-----|--------|-------|---------|
| 1 | Align code fallback defaults with config values | Low | `risk_engine.php:75-80` | Change `?? 5` → `?? 8`, `?? 10` → `?? 6`, `?? 0.15` → `?? 0.12` |
| 2 | Write effective config on module load | Medium | `smart_brain_config.php` | Compute and persist effective config eagerly |
| 3 | Document bootstrap→normal transition requirements | Low | This report / README | Clearly state: 6+ trades/symbol, reliability ≥ 0.12 |

### External Dependencies (NOT Smart Brain bugs)

| Item | Owner | Impact on Smart Brain | Status |
|------|-------|----------------------|--------|
| Parser3 profile data feed | Parser3 module / infrastructure | Without it, zero candidates are generated | **Blocking all production use** |
| Parser2 history data feed | Parser2 module / infrastructure | Without it, no price history for pattern detection | **Blocking all production use** |
| Bybit price feeds | External API / infrastructure | Without it, no current prices for ROI calculation | Required for simulator ticks |

---

## Report Integrity Notes

This report clearly distinguishes:

| Category | Examples | How Identified |
|----------|----------|----------------|
| **Actual runtime evidence** | Empty JSON files, missing user_config.json, config values | Direct file inspection |
| **Code-verified findings** | Side logic, leverage ladder, stop engine, ROI formulas | Static analysis of 3,623 LOC |
| **CI/environment limitations** | No Parser3 data, empty candidates, zero trades | Environment context — NOT Smart Brain bugs |
| **Inferences from code** | Leverage will cluster at 2-3x, bootstrap will dominate initially | Predictions based on code logic + config values |

---

## Appendix: Audit Tooling

### Reusable Simulation Audit Script

```
modules/system/smart_brain/simulation_audit.php
```

Run from CLI:
```bash
php modules/system/smart_brain/simulation_audit.php
```

Output: `storage/simulation_audit_report.json` (git-ignored)

This script analyzes all runtime data and produces a structured JSON report covering:
pattern statistics, side audit, leverage audit, stop engine audit, lifecycle audit, long vs short comparison, analyzer gating audit, and stats engine validation.

**Recommendation:** Run this script after each production cycle to collect real pattern statistics and validate system behavior over time.
