# Smart Brain — Full Simulation Audit Report

**Date:** 2026-03-16  
**Auditor:** Automated Code Audit + Static Analysis  
**Scope:** Full simulation pipeline including pattern detection, side logic, leverage, stop engine, simulator lifecycle, stats engine  

---

## 1. Executive Summary

This audit was conducted on the Smart Brain simulation pipeline through deep code analysis and structural review of all runtime data files. The system currently has **zero simulation data** (empty candidates, signals, waiting, active, and closed files), which means no live trades have been produced by the pipeline. The primary blockers are:

1. **No Parser3 profile data** — Parser4 depends on Parser3 profiles directory which does not exist, producing zero candidates
2. **Pattern selection defaults to `double_bottom` only** — limiting signal generation to one direction
3. **`signal_to_entry_conversion` formula bug** — uses current-cycle signals count vs. cumulative trade counts
4. **Side derivation fails for flat trends** — `pullback_trend_continue` with flat trend_bias returns null, causing rejection

The system architecture is well-structured with clean separation of concerns, but cannot produce trading results until Parser3 data feeds are available.

---

## 2. Test Configurations Run

Since the system has no runtime data (empty simulator state files), the audit was performed via:

| Mode | Status | Notes |
|------|--------|-------|
| Code Audit | ✅ Complete | All 14+ lib files analyzed |
| Config Analysis | ✅ Complete | All 7 config files + user_config reviewed |
| Data State Check | ✅ Complete | All storage/runtime files inspected |
| Live Simulation | ❌ Not possible | No Parser3 data → 0 candidates |

**Required for live testing:**
- Parser3 profiles data at `parser.parser3_manager_behavior.storage`
- Parser2 history data at `parser.parser2_history_accumulator.storage`
- Active Bybit price feeds

---

## 3. Pattern Statistics

### Current Configuration

| Setting | Value |
|---------|-------|
| Enabled algorithms | `['double_bottom']` (default) |
| Pattern mode | `one` |
| Analyzer threshold | `0.45` |
| Weights | pattern=0.50, trend=0.20, corridor=0.10, entry=0.20 |

### Per-Algorithm Analysis (Code-Based)

| Algorithm | Side | Detection Method | Code Quality |
|-----------|------|------------------|--------------|
| `double_bottom` | Always `long` | Two troughs near corridor low | ✅ Solid |
| `double_top` | Always `short` | Two peaks near corridor high | ✅ Solid |
| `pullback_trend_continue` | From `trend_bias` | Pullback against trend in corridor | ⚠️ Fails on flat trends |

### Recommendations After Code Review

| Algorithm | Recommendation | Reason |
|-----------|---------------|--------|
| `double_bottom` | **Enable** | Clean long entry pattern, well-implemented |
| `double_top` | **Enable** | Clean short entry pattern, well-implemented |
| `pullback_trend_continue` | **Enable with caution** | Good concept but fails silently for flat/unknown trend_bias |

---

## 4. Side Audit

### Expected Side Mapping

| Pattern | Expected Side | Implementation | Status |
|---------|--------------|----------------|--------|
| `double_bottom` | `long` | Hardcoded in `deriveSideFromPattern()` | ✅ Correct |
| `double_top` | `short` | Hardcoded in `deriveSideFromPattern()` | ✅ Correct |
| `pullback_trend_continue` up | `long` | Maps `trend_bias='up'` → `long` | ✅ Correct |
| `pullback_trend_continue` down | `short` | Maps `trend_bias='down'` → `short` | ✅ Correct |
| `pullback_trend_continue` flat | ❌ null | Returns null → **REJECTED** | ⚠️ Bug |

### Side Propagation Chain

```
Parser4Analyzer.deriveSideFromPattern() → candidate['side']
    ↓
SmartBrainCore.executePipeline() lines 147-150 → monitor['side'] (conditional copy)
    ↓
RiskEngine.apply() line 140-146 → signal['side'] (or reject if null)
    ↓
SimulatorEngine → trade['side'] (propagated through)
```

### Issues Found

1. **P1 — Flat trend side failure:** `pullback_trend_continue` with `trend_bias` not in `['up','down']` returns null. The candidate is created but side is missing, causing RiskEngine to reject with `rejected_side_unresolved`. This is by design (no default to long) but means flat-market pullbacks are always lost.

2. **P2 — Side propagation is conditional:** In `smart_brain_core.php:147-149`, side is only copied from candidate to monitor if `candidate['side']` exists and is non-empty. If Parser4 fails to set side, the monitor silently has no side field.

---

## 5. Leverage Audit

### Dynamic Leverage V1 Implementation

**Location:** `risk_engine.php` `computeDynamicLeverage()`

**Base ladder from analyzer_score:**

| Score Range | Base Leverage |
|-------------|--------------|
| < 0.50 | 2x |
| 0.50 - 0.65 | 3x |
| 0.65 - 0.80 | 4x |
| ≥ 0.80 | 5x |

**Adjustments (can stack):**

| Condition | Adjustment |
|-----------|------------|
| volatility > 0.05 | -1x |
| corridor_width > 0.10 | -1x |
| bootstrap mode | clamp to max 3x |
| reliability < 0.30 | -1x |

**Final:** `clamp(result, 1, min(mode_max, user_max))`

### Assessment

The leverage system IS dynamic in code. However, in practice:
- With analyzer threshold at 0.45, most passing candidates will have scores in 0.45-0.65 range → base 2-3x
- Bootstrap mode (dominant for new system) clamps to 3x max
- Multiple -1x adjustments can stack → effective leverage often 1-2x

**Prediction:** Leverage will appear near-static (mostly 2-3x) because:
1. System starts in bootstrap → 3x cap
2. Most scores cluster near threshold → 2-3x base
3. Volatility/corridor adjustments frequently reduce by 1-2x

---

## 6. Stop Engine Audit

### Stop Mode: `simple_liq_percent`

```
stop_distance = (1 / leverage) × simple_stop_liq_factor
```

With default factor 0.15 and leverage 3x: stop = 5% from entry.

### Stop Mode: `brain_managed`

```
corridor_stop = corridor_width × brain_stop_corridor_factor
volatility_stop = volatility × brain_stop_volatility_factor
liq_stop = (1/leverage) × brain_stop_liq_safety_factor
effective_stop = max(corridor_stop, volatility_stop, liq_stop)
```

### Early Failure Guard

| Setting | Default |
|---------|---------|
| Enabled | `false` |
| Window | 5 minutes |
| Max adverse ROI | -0.008 (-0.8%) |

**Assessment:** Early failure guard is a good concept but disabled by default. When enabled, it cuts trades that lose 0.8%+ within first 5 minutes — useful for catching bad entries.

### Stop Floor

```
roi_percent: stop_floor = stop_floor_value (direct)
corridor_percent: stop_floor = corridor_width × stop_floor_value
```

Effective SL = max(signal_stoploss, stop_floor, stop_mode_distance)

### Assessment

- `simple_liq_percent` is predictable but ignores market structure
- `brain_managed` adapts to corridor/volatility but may produce too-wide stops
- Both modes enforce a minimum via stop floor
- Exit priority order is correct: early_failure → trailing → break_even → stop_loss → take_profit

---

## 7. Simulator Lifecycle Audit

### State Machine

```
Signal → WAITING → (price in entry zone) → ACTIVE → (exit condition) → CLOSED
```

### Current State

| State | Count | Status |
|-------|-------|--------|
| Waiting | 0 | Empty |
| Active | 0 | Empty |
| Closed | 0 | Empty |
| Signals | 0 | Empty |

### Root Cause Analysis

The simulator has never produced trades because:

1. **Primary:** Parser3 profiles directory doesn't exist → Parser4 finds no symbols → 0 candidates
2. **Secondary:** Without candidates, no monitors are built → no entry zones → no signals
3. **Tertiary:** Without signals, simulator has nothing to process

### Code Quality Assessment

The `SimulatorEngine.tick()` method is well-structured:
- Proper WAITING→ACTIVE transition with price checking
- ROI calculation respects side (long vs short)
- MAE/MFE tracking is correct
- Duration tracking works
- All close conditions are properly prioritized

**No stuck-trade risks found in code.** Active trades are checked every tick cycle and will eventually hit stop_loss or take_profit.

---

## 8. Long vs Short Comparison

No data available. Code analysis shows:

### Long Performance Expectations
- `double_bottom` always produces long
- `pullback_trend_continue` with uptrend produces long
- Long ROI: `(current_price - entry_price) / entry_price`

### Short Performance Expectations
- `double_top` always produces short
- `pullback_trend_continue` with downtrend produces short
- Short ROI: `(entry_price - current_price) / entry_price`

### Assessment
Both sides use correct ROI formulas. No bias toward long detected in code. The system correctly derives side from pattern type.

---

## 9. Bootstrap vs Normal Comparison

### Bootstrap Mode Criteria
- Coin passport is empty OR total trades < 10
- Max leverage: 3x (capped)
- Budget: base × `bootstrap_budget_factor` (default 0.50)
- Max signals per cycle: `bootstrap_max_signals` (default 3)

### Normal Mode Criteria
- Passport exists AND reliability ≥ `min_reliability_after_warmup` (default 0.15)
- Leverage: dynamic (2-5x based on analyzer_score)
- Budget: profile budget × reliability_score

### Assessment
**System is permanently trapped in bootstrap** because:
1. No closed trades exist → passports are empty → every symbol is bootstrap
2. Bootstrap produces fewer signals with lower leverage
3. This creates a chicken-and-egg: need trades to exit bootstrap, but bootstrap limits signal generation

This is NOT a bug — it's the intended cautious start behavior. Once Parser3 data enables candidates, the system will gradually accumulate trades per symbol and transition to normal mode.

---

## 10. Analyzer / Gating Audit

### Current Configuration

| Parameter | Value | Assessment |
|-----------|-------|------------|
| Threshold | 0.45 | ✅ Reasonable (lowered from 0.65) |
| Pattern weight | 0.50 | ✅ Dominant factor as intended |
| Trend weight | 0.20 | ✅ Moderate |
| Corridor weight | 0.10 | ✅ Soft modifier |
| Entry quality weight | 0.20 | ✅ Moderate |
| Strength threshold | 0.40 | ✅ Low enough for testing |

### Corridor Fit Score (computeCorridorFitScore)

The corridor fit computation was softened with floors:
- Bullish (long): floor 0.35 if price in lower half, floor 0.45 if outside
- Bearish (short): floor 0.45 if price in upper half, floor 0.35 if outside

**Assessment:** Good. Corridor fit is now a soft modifier (10% weight + floors) rather than a dominant rejection factor. This was correctly tuned.

### Pattern Mode Logic

| Mode | Behavior | Risk |
|------|----------|------|
| `one` | First detector only | May miss better patterns |
| `any` | Best of all detectors | Most permissive, recommended |
| `all` | All must confirm | Very restrictive, likely too strict |

**Recommendation:** Use `any` mode for initial testing to maximize signal flow.

---

## 11. Stats Engine Audit

### Known Bug: `signal_to_entry_conversion > 1.0`

**Location:** `simulator_engine.php:508-512`

```php
$totalSignals = count($signals);  // Current cycle's signals only
$enteredCount = count($active) + $totalClosed;  // Cumulative across all cycles
$conversion = ($totalSignals > 0) ? round($enteredCount / $totalSignals, 4) : 0.0;
```

**Problem:** `signals.json` is overwritten each cycle with only current signals, but `active` and `closed` accumulate across all cycles. After multiple cycles, `enteredCount` will greatly exceed `totalSignals`, producing conversion > 1.0.

**Fix Direction:** Either:
1. Track cumulative signal count in a persistent counter
2. Or compute conversion only within current cycle's trades

### Other Stats Issues

- `winrate` counts roi >= 0 as win (break-even counted as win) — **acceptable behavior**
- `median` function is correctly implemented
- Duration tracking works but depends on `activated_at` / `closed_at` timestamps

---

## 12. Bug Catalog

### P0 — Critical Logic Bugs

| # | Title | Symptom | Root Cause | Impact | Fix Direction |
|---|-------|---------|------------|--------|---------------|
| P0-1 | No candidates generated | 0 candidates, 0 signals, 0 trades | Parser3 profiles directory doesn't exist; Parser4 reads from `parser.parser3_manager_behavior.storage` which is empty | **System produces nothing** | Ensure Parser3 data feed is active, or provide mock data for testing |
| P0-2 | `signal_to_entry_conversion` > 1.0 | Stats show impossible conversion ratio | `signals.json` = current cycle only; `active+closed` = cumulative | **Misleading statistics** | Track cumulative signal count persistently |

### P1 — Major Quality Problems

| # | Title | Symptom | Root Cause | Impact | Fix Direction |
|---|-------|---------|------------|--------|---------------|
| P1-1 | Flat trend rejects pullback_trend_continue | Pullback candidates with `trend_bias='flat'` silently rejected | `deriveSideFromPattern()` returns null for non-up/non-down trend_bias | Lost signals in ranging markets | Consider treating flat as neutral or classifying differently |
| P1-2 | Default pattern config too restrictive | Only `double_bottom` enabled, mode `one` | `config/parser4.php` defaults to single pattern | **Only long signals possible** by default | Enable all 3 patterns, use `any` mode as default |
| P1-3 | User config not created on first deploy | `runtime/user_config.json` doesn't exist until user saves | No initialization logic | System runs with base config defaults only | Create default user_config.json during module initialization |
| P1-4 | Config snapshot uses old format | `runtime/config.snapshot.json` has no `pattern_algorithms` section | Old snapshot logic doesn't include pattern selection | Config view may be incomplete | Update `SmartBrainRuntime.snapshot()` to include patterns |

### P2 — Secondary Issues / Tuning

| # | Title | Symptom | Root Cause | Impact | Fix Direction |
|---|-------|---------|------------|--------|---------------|
| P2-1 | Take profit ROI may be unreachable | With default `take_profit_roi` values, TP may require unrealistic price moves | Profile TP vs effective TP configuration mismatch | Trades never reach TP | Verify effective TP calculation matches profile |
| P2-2 | Bootstrap mode is permanent without data | All symbols stay in bootstrap forever | No coin passports populated | Lower leverage, fewer signals | Expected behavior, but document clearly |
| P2-3 | Early failure disabled by default | Bad entries not caught early | Default `early_failure_enabled = false` | Potential unnecessary losses | Enable by default for simulation testing |
| P2-4 | Effective config not written without a run | `runtime/effective_config.json` only written during pipeline run | No lazy initialization | Global Config page may show stale data | Write effective config on module load |

---

## 13. Decision Report

### 1. Which patterns should remain enabled after testing?

**All three should be enabled:**
- `double_bottom` — well-implemented, clean long entry
- `double_top` — well-implemented, clean short entry
- `pullback_trend_continue` — good concept, needs flat-trend handling fix

**Recommended mode:** `any` (most permissive, captures all valid patterns)

### 2. Which patterns should be disabled?

**None.** All three are structurally sound. The only issue is `pullback_trend_continue` with flat trends, which is a minor edge case.

### 3. Which stop mode is better right now?

**`brain_managed`** is recommended because:
- Adapts to market conditions (corridor width, volatility)
- Provides more intelligent stop placement
- `simple_liq_percent` is a static formula that ignores context

However, `brain_managed` may produce wider stops. Recommend monitoring average MAE to tune.

### 4. Is side logic reliable enough?

**Yes, with caveats:**
- `double_bottom` → long: ✅ Reliable
- `double_top` → short: ✅ Reliable
- `pullback_trend_continue` → from trend_bias: ⚠️ Fails on flat
- No silent fallback to long: ✅ Good safety practice

### 5. Is leverage logic reliable enough?

**Yes, but effectively near-static:**
- Dynamic leverage implementation is correct
- Bootstrap cap (3x) dominates until passports are built
- Normal mode will show more variation once reliability data exists
- The -1x adjustments for volatility/corridor work correctly

### 6. Is system still trapped in bootstrap?

**Yes** — and will remain so until Parser3 data enables candidates and trades accumulate. This is expected and not a bug.

### 7. What should be fixed first before aiming for 75%+ winrate?

1. **Enable Parser3 data feed** — Without it, nothing works
2. **Enable all 3 patterns** — More signals = more data = faster learning
3. **Fix `signal_to_entry_conversion` bug** — Accurate stats required for tuning
4. **Enable `early_failure`** — Cut bad entries early
5. **Tune pattern confidence thresholds** — Based on actual trade data

---

## 14. Fix Roadmap

### P0 — Critical (Do First)

| Priority | Fix | Effort | Files |
|----------|-----|--------|-------|
| P0-1 | Ensure Parser3 data feed is active and producing profiles | High | External dependency |
| P0-2 | Fix `signal_to_entry_conversion` to track cumulative signals | Low | `simulator_engine.php` |

### P1 — Major (Do Next)

| Priority | Fix | Effort | Files |
|----------|-----|--------|-------|
| P1-1 | Handle flat trend_bias in `deriveSideFromPattern()` | Low | `parser4_analyzer.php` |
| P1-2 | Change default patterns to all 3 enabled, mode `any` | Low | `config/parser4.php` |
| P1-3 | Create default `user_config.json` on first deploy | Low | `smart_brain_config.php` |
| P1-4 | Update runtime snapshot to include pattern selection | Low | `smart_brain_runtime.php` |

### P2 — Secondary (Tune Later)

| Priority | Fix | Effort | Files |
|----------|-----|--------|-------|
| P2-1 | Verify effective take profit calculation | Low | `profiles.php`, `risk_engine.php` |
| P2-2 | Document bootstrap-to-normal transition clearly | Low | Documentation only |
| P2-3 | Enable early failure by default for simulation | Low | `config/risk_engine.php` |
| P2-4 | Write effective config on module initialization | Medium | `smart_brain_config.php` |

---

## Appendix: Audit Tooling

A reusable simulation audit script has been created at:

```
modules/system/smart_brain/simulation_audit.php
```

Run from CLI:
```bash
php modules/system/smart_brain/simulation_audit.php
```

Output: `storage/simulation_audit_report.json`

This script should be run after each simulation test cycle to collect pattern statistics and validate system behavior.
