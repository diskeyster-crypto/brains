# Smart Brain Simulator — Engineering Audit Report

**Date:** 2026-03-14
**Auditor:** Automated Pipeline Audit
**Module:** `modules/system/smart_brain/`
**Scope:** Full end-to-end simulator pipeline validation

---

## Executive Summary

The Smart Brain simulator engine is **functionally correct** — all state transitions (WAITING → ACTIVE → CLOSED), ROI/MAE/MFE tracking, SL/TP close conditions, and state persistence work as designed.

**The root cause of missing closed trades is upstream**: the pipeline produces **zero candidates** in production because Parser3 profiles directory does not exist, resulting in zero monitors, zero signals, and zero trades entering the simulator.

Additionally, a **take_profit threshold imbalance** makes TP exits virtually unreachable even when trades are active, and a **stats formula bug** causes `signal_to_entry_conversion` to exceed 1.0.

---

## Pipeline Status

| Stage | Status | Notes |
|-------|--------|-------|
| Parser4 (candidates) | ❌ FAIL | 0 candidates — no Parser3 profiles data |
| Corridor Monitor | ⚠ N/A | No input to process |
| Risk Engine (signals) | ⚠ N/A | No monitors to filter |
| Simulator WAITING | ⚠ N/A | No signals to ingest |
| Simulator ACTIVE | ⚠ N/A | No waiting trades to transition |
| Simulator CLOSED | ❌ FAIL | No active trades to close |
| Stats Engine | ⚠ BUG | `signal_to_entry_conversion` can exceed 1.0 |
| Price Feed | ✅ OK | Code is correct; Bybit endpoint configured properly |

---

## Step 1 — Signal Generation

### Finding
**0 signals generated** in production environment.

### Root Cause
Parser4Analyzer loads symbols from the Parser3 profiles directory:
```
profiles_key → parser.parser3_manager_behavior.storage → .../storage/profiles/
```

This directory **does not exist** in the current environment:
```
/home/runner/work/brains/brains/modules/parser/parser3_manager_behavior/storage/profiles → NOT FOUND
```

The Parser2 history accumulator storage directory exists but contains **0 symbol directories**:
```
/home/runner/work/brains/brains/modules/parser/parser2_history_accumulator/storage → EXISTS, 0 subdirs
```

With 0 symbols, Parser4 produces 0 candidates, and the entire downstream pipeline is starved.

### Synthetic Validation
When injected with 3 synthetic candidates (BTCUSDT, ETHUSDT, SOLUSDT) with bootstrap-mode passports (trades_total=0):
- **3 bootstrap signals** generated correctly
- All required fields present: `symbol`, `entry_zone_low/high`, `stop_loss`, `take_profit`, `budget`, `leverage`, `signal_mode`

---

## Step 2 — Waiting State Validation

### Finding
**Waiting state is empty** (0 trades) due to 0 signals.

### Synthetic Validation
With injected signals and prices at entry zone:
- Signals transition **directly to ACTIVE** (prices are within entry zone)
- No duplicates detected per symbol
- Entry zone values preserved correctly from signals

### Code Path (simulator_engine.php lines 56-75)
```php
// Duplicate prevention: skip if symbol already in waiting or active
if (isset($waitingSymbols[$symbol]) || isset($activeSymbols[$symbol])) {
    continue;
}
```
✅ Duplicate protection works correctly.

---

## Step 3 — Waiting → Active Transition

### Finding
Transition logic is **correct**.

### Condition
```php
$price >= $low && $price <= $high  // price within entry zone
```

### Synthetic Validation
- When prices are within `[entry_zone_low, entry_zone_high]`: trade immediately activates
- When prices are outside zone: trade remains in waiting state
- `entry_price` correctly set to current price at activation time
- `opened_at` timestamp correctly recorded

---

## Step 4 — Active Trade Update Loop

### Finding
ROI/MAE/MFE calculations are **correct**.

### Formulas (simulator_engine.php lines 125-142)
```php
$roi = ($price - $entryPrice) / $entryPrice;      // fractional ROI
$mae = max($oldMae, abs($roi));                    // worst drawdown (when roi < 0)
$mfe = max($oldMfe, $roi);                         // best gain (when roi > 0)
```

### Synthetic Validation
Over 20 simulated cycles with realistic price movements (±0.1-0.3% per tick):
- ROI updates correctly each tick
- MAE increases monotonically when new drawdowns occur
- MFE increases monotonically when new highs occur
- `current_price` updates correctly from price feed

---

## Step 5 — Close Conditions ⚠ CRITICAL

### Finding
Close condition logic is **correct but practically imbalanced**.

### Stop-Loss Condition
```php
if ($stoploss > 0.0 && $roi <= -$stoploss) { $closedReason = 'stop_loss'; }
```

### Take-Profit Condition
```php
if ($takeprofit > 0.0 && $roi >= $takeprofit) { $closedReason = 'take_profit'; }
```

### ⚠ Critical Imbalance in SL/TP Thresholds

For a typical corridor_width of ~0.033 (3.3%):

| Parameter | Formula | Value | Meaning |
|-----------|---------|-------|---------|
| `stop_loss` | `corridor_width × stop_loss_range (0.20)` | **0.006667** | **-0.67% ROI** |
| `take_profit` | `corridor_width × take_profit_roi (5.55)` | **0.184998** | **+18.5% ROI** |

**The SL:TP ratio is ~1:28.** This means:
- Stop-loss triggers at just **-0.67%** drop — normal crypto noise within minutes
- Take-profit requires a **+18.5%** gain — an extraordinary move taking days to weeks

### Synthetic Validation Results
- **SL test**: Price dropped 1.16% → SL triggered immediately ✅
- **TP test**: Price rose 19.6% → TP triggered for BTC/ETH ✅, SOL required +39.6% (unreachable) ✅
- **20-cycle realistic simulation**: No SL or TP triggered with ±0.1-0.3% moves per tick — trade remained ACTIVE indefinitely

### Conclusion
The close conditions **mathematically work** but the `take_profit_roi` parameter (5.55) creates an effectively unreachable TP threshold. In normal operation:
- **SL will be the primary exit mechanism** (small threshold, easily hit)
- **TP exits are extremely rare** (requires massive sustained price movement)
- Trades may **remain ACTIVE for very long periods** if price stays within the narrow SL band

---

## Step 6 — Closed Trades Writing

### Finding
Closed trade recording is **correct**.

### Required Fields — All Present ✅
| Field | Status |
|-------|--------|
| `entry_price` | ✅ |
| `exit_price` | ✅ |
| `roi` | ✅ |
| `mae` | ✅ |
| `mfe` | ✅ |
| `duration` | ✅ (in minutes) |
| `reason` | ✅ (`stop_loss` or `take_profit`) |
| `opened_at` | ✅ (ISO 8601) |
| `closed_at` | ✅ (ISO 8601) |

### Synthetic Validation
When SL/TP conditions are met, closed trades are correctly written to `storage/simulator/closed.json` with all required fields.

---

## Step 7 — Stats Engine Validation

### Finding
Stats engine has a **formula bug** in `signal_to_entry_conversion`.

### Bug Detail

```php
// simulator_engine.php line 232-234
$totalSignals = count($signals);        // reads CURRENT signals.json
$enteredCount = count($active) + $totalClosed;  // cumulative across ALL time
$conversion   = ($totalSignals > 0) ? round($enteredCount / $totalSignals, 4) : 0.0;
```

**Problem**: `signals.json` is overwritten each cycle with only the CURRENT cycle's signals. But `active` and `closed` accumulate across ALL cycles. This means:

- Cycle 1: 3 signals generated, 3 become active → conversion = 3/3 = 1.0 ✅
- Cycle 2: 2 signals generated (different symbols), 2 more become active → active=5, signals=2 → conversion = 5/2 = **2.5** ❌

**Observed**: conversion = 2.0 in synthetic test (4 entered trades ÷ 2 current signals).

### Other Metrics
All other metrics compute correctly:
- `total_trades`: count of closed trades ✅
- `winrate`: wins/total where win = roi >= 0 ✅
- `average_roi`: mean of closed ROIs ✅
- `median_mae`, `median_mfe`, `median_duration`: correct median calculation ✅

---

## Step 8 — Price Feed Validation

### Finding
Price feed code is **correct**.

### Implementation
- Endpoint: `https://api.bybit.com/v5/market/tickers?category=linear`
- Prefers `markPrice`, falls back to `lastPrice`
- Returns empty array on failure (graceful degradation)
- 10-second timeout configured
- SSL verification enabled

### Production Concern
If the Bybit API is unreachable (firewall, DNS, rate limit), `getPrices()` returns `[]`. In this case:
- `buildPriceMap()` falls back to `candidate.last_price` values
- But RiskEngine **rejects monitors** with `$prices[$symbol] <= 0.0`, counting them as `rejected_missing_price`

This is NOT the root cause since the pipeline fails earlier at Parser4 (0 candidates).

---

## Step 9 — State Consistency

### Finding
State is **consistent** — no duplicate symbols across states.

### Verification
- No symbol exists in both `waiting.json` and `active.json` simultaneously
- No orphan trades detected
- `activeSymbols` tracking prevents duplicate activations
- Closed trades are properly removed from `newActive` array

---

## Step 10 — Multi-Cycle Simulation

### Finding
Over 20 simulated cycles with realistic ±0.1-0.3% price movements:

- **Time to first active trade**: Immediate (Tick 0) when price is within entry zone
- **Time to first closed trade**: Not reached in 20 cycles with normal volatility
  - SL requires -0.67% cumulative drop from entry
  - TP requires +18.5% gain — unreachable in 20 ticks

With forced price drops (-1% to -5%): SL closes correctly in 1 tick.

---

## Root Cause Analysis

### PRIMARY ROOT CAUSE: No Input Data

The simulator receives **zero signals** because:

1. **Parser3 profiles directory does not exist** → Parser4 finds 0 symbols
2. **Parser2 history accumulator has 0 symbol directories** → no price history to analyze
3. **Result**: 0 candidates → 0 monitors → 0 signals → 0 trades → 0 closures

The entire pipeline is functioning correctly but is **starved of input data**.

### SECONDARY ISSUE: TP Threshold Imbalance

Even when signals exist, the `take_profit_roi = 5.55` parameter creates a take-profit threshold that is **27x larger** than the stop-loss threshold. For typical corridor widths (3-7%), this means:
- SL triggers at -0.6% to -1.4% (very tight)
- TP requires +18% to +40% (nearly impossible in short timeframes)

This results in:
- Active trades getting stuck for extended periods
- Nearly all exits being stop_loss (losses)
- Very low winrate in production

### TERTIARY ISSUE: Stats Formula Bug

`signal_to_entry_conversion` can exceed 1.0 because it divides cumulative entries by current-cycle signal count.

---

## Fix Recommendations

### Priority 1 — Data Pipeline (root cause)
**Ensure Parser3 profiles data exists and Parser2 history accumulates.**
- Verify cron runs for Parser2 and Parser3 are operational
- Verify manifest.json path registrations are correct
- Without upstream data, the simulator can never produce trades

### Priority 2 — Take-Profit Threshold
**Reduce `take_profit_roi` from 5.55 to a realistic value.**
- Current: `take_profit_roi = 5.55` → TP requires corridor_width × 5.55 ROI
- Suggestion: `take_profit_roi` in range 0.5–2.0 for realistic crypto timeframes
- Alternative: Use absolute ROI targets instead of corridor-width-relative values
- Location: `config/profiles.php` → `profiles.111.take_profit_roi`

### Priority 3 — Stats Formula Fix
**Fix `signal_to_entry_conversion` in `simulator_engine.php`.**
- Option A: Cap at 1.0: `$conversion = min(1.0, $enteredCount / $totalSignals)`
- Option B: Track cumulative signal count in a separate counter
- Option C: Use `waiting + active + closed` as denominator
- Location: `lib/simulator_engine.php` lines 230-234

---

## Files Audited

| File | Lines | Status |
|------|-------|--------|
| `lib/simulator_engine.php` | 272 | ✅ Functionally correct (1 stats bug) |
| `lib/smart_brain_core.php` | 411 | ✅ Pipeline orchestration correct |
| `lib/risk_engine.php` | 257 | ✅ Signal generation correct |
| `lib/price_feed.php` | 141 | ✅ Implementation correct |
| `lib/corridor_monitor.php` | 111 | ✅ Status determination correct |
| `lib/parser4_analyzer.php` | 399 | ✅ Code correct; no input data |
| `lib/state_manager.php` | 44 | ✅ Read/write correct |
| `lib/smart_brain_config.php` | 273 | ✅ Config merge correct |
| `config/risk_engine.php` | 33 | ✅ User limits defined |
| `config/profiles.php` | — | ⚠ `take_profit_roi` too high |
| `config/simulator.php` | — | ✅ Enabled correctly |

---

*End of Audit Report*
