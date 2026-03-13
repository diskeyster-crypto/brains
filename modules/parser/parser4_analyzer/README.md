# Parser 4: Analyzer

Pure analytical module (second contour).

## Purpose

Takes ready price history (Parser2) and symbol list (Parser3), calculates rolling metrics, outputs classification.

## Position in Pipeline

```
Parser0 → Parser1 → Parser2 → Parser3 → Parser4 → Parser5
                      ↓           ↓
                   history    symbols
                      ↓           ↓
                      └─────┬─────┘
                            ↓
                        Parser4
                            ↓
              blocklist/watchlist/candidates
```

## Sensitivity Profiles

Parser4 supports three sensitivity profiles that control thresholds and candidate limits.

### Configuration

In `config/config.php`:
```php
'profile' => 'medium',  // 'light' | 'medium' | 'high'
```

### Available Profiles

| Profile | Description | Expected Candidates | Use Case |
|---------|-------------|---------------------|----------|
| **light** | Wide scan, noisy | 150-300 | Volatile markets, exploration, anomaly detection |
| **medium** | Balanced (default) | 50-120 | Production, stable flow to Parser5 |
| **high** | Sensitive, narrow | 10-40 | Calm markets, precision hunting |

### Profile Thresholds

| Parameter | light | medium | high |
|-----------|-------|--------|------|
| `pump_abs_return` | 0.005 | 0.015 | 0.025 |
| `confirm_abs_return` | 0.003 | 0.008 | 0.015 |
| `block_std_return` | 0.07 | 0.05 | 0.04 |
| `watch_std_return` | 0.05 | 0.03 | 0.02 |
| `max_candidates` | 200 | 100 | 40 |

### How It Works

The profile acts like a **sensitivity dial**:
- **light** = Wide aperture, catches more (but noisier)
- **high** = Narrow aperture, catches less (but cleaner)

No algorithm changes — only threshold substitution.

## Input Sources

### Parser2 — Price History
```
modules/parser2_history_accumulator/storage/{SYMBOL}/{YYYY-MM-DD}.ndjson
```

Each line is one JSON object. Two formats supported:

**Format A (flat):**
```json
{"ts": "2026-01-30T00:01:03+00:00", "ts_unix": 1769731261, "price": "0.12122"}
```

**Format B (Bybit style):**
```json
{"ts": "2026-01-30T00:01:03+00:00", "ts_unix": 1769731261, "data": {"symbol": "ZRXUSDT", "lastPrice": "0.12122", "markPrice": "0.12135"}}
```

### Parser3 — Symbol List
```
modules/parser3_manager_behavior/storage/profiles/*.json
```

Just reads filenames (without .json extension). No validation, no regex.

## Metrics

Rolling windows (in minutes):
```
[2, 3, 4, 5, 8, 10, 13, 15, 20, 25, 30, 40, 50, 60, 120, 180, 240, 360, 480, 720, 1440]
```

For each window:
- `signed_return` = (last_price / first_price) - 1
- `abs_return` = abs(signed_return)
- `std_return` = stddev of (price[i] / price[i-1]) - 1

## Classification

Two-step filter for candidates:

1. **Pump Check**: `max_abs_return(pump_windows) >= pump_abs_return`
2. **Confirm Check**: `abs_return(confirm_windows) >= confirm_abs_return`

Classification:

| Condition | Result |
|-----------|--------|
| max_std(block_windows) >= block_std_return | BLOCKLIST |
| max_std(watch_windows) >= watch_std_return | WATCHLIST |
| Passes pump + confirm check | CANDIDATES |

Rules:
- If BLOCK → only blocklist (mutually exclusive)
- If WATCH → watchlist (not candidate)
- Candidates sorted by `score = abs_return - (std_return * 0.5)`

## Output Files

### blocklist.json
```json
[
  {"symbol": "ZRXUSDT", "max_std": 0.061, "ts": "2026-01-30T10:12:00+03:00"}
]
```

### watchlist.json
```json
[
  {"symbol": "ZRXUSDT", "max_std": 0.034}
]
```

### candidates.json
```json
[
  {
    "symbol": "ZRXUSDT",
    "side": "long",
    "score": 0.015234,
    "abs_return_best": 0.023456,
    "signed_return_best": 0.023456,
    "best_window_min": 5,
    "confirm_abs": 0.012345,
    "max_std_watch": 0.008765,
    "history_points": 1240
  }
]
```

### last_run.json
```json
{
  "ts": "2026-01-30T13:00:00+00:00",
  "profile": "medium",
  "processed": 574,
  "candidates": 12,
  "candidates_before_limit": 15,
  "blocklist": 3,
  "watchlist": 8,
  "history_missing": 0,
  "history_too_short": 4,
  "rejected_below_pump": 320,
  "rejected_no_confirm": 45,
  "duration_ms": 1532
}
```

### errors.json
```json
["history_missing: BTCUSDT"]
```

## Prohibitions (HARD)

Parser4 is **FORBIDDEN** to:
- ❌ Use regex (`preg_match`)
- ❌ Use `normalizeSymbol`
- ❌ Use `isValidSymbol`
- ❌ Access Parser0 / Parser1
- ❌ Hardcode paths

Parser4 is **ALLOWED** only to:
- ✅ Read files
- ✅ Calculate numbers
- ✅ Write JSON

## Architecture

Single file: `service.php`

Single class:
```php
final class Parser4AnalyzerService
{
    public function execute(): array
}
```

- No inheritance
- No traits
- No DI
- No regex
- No magic

## Manual Run

```bash
php modules/parser4_analyzer/runner.php
```

## Configuration

All paths and parameters in `config/config.php`. Zero hardcode.
