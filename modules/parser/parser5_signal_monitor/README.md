# Parser 5: Signal Monitor Engine

## Purpose

Parser5 is a **Signal Monitor Engine** that:
1. Takes candidates from Parser4
2. Puts them on "monitor" (watchlist tracking)
3. Every minute checks: "entry possible?"
4. When conditions are met → publishes signals to ONE shared file for Executor

## Pipeline Position

```
Parser0 → Parser1 → Parser2 → Parser3 → Parser4 → Parser5 → Executor
                                           ↓          ↓
                                      candidates   signals
                                           └────→ Parser5 ──→ signals.json
```

## Input Sources

| Source | Path | Description |
|--------|------|-------------|
| Parser4 Candidates | `modules/parser4_analyzer/storage/candidates.json` | Trading candidates with score, side |
| Parser2 History | `modules/parser2_history_accumulator/storage/{SYMBOL}/` | Price data for monitoring |

## Output Files

| File | Description |
|------|-------------|
| `storage/signals.json` | Active signals for Executor (ONE shared file) |
| `storage/monitor.json` | Currently monitored candidates |
| `storage/last_run.json` | Execution statistics |
| `storage/history/{date}.ndjson` | Signal history archive |
| `storage/logs/signal_monitor.log` | Execution log |

## Signal Format

```json
{
  "updated_at": "2026-01-30T12:00:00+00:00",
  "count": 3,
  "signals": [
    {
      "id": "BTCUSDT_1738234800",
      "symbol": "BTCUSDT",
      "side": "long",
      "entry_price": 95000.0,
      "take_profit": 96900.0,
      "stop_loss": 94050.0,
      "validity_minutes": 30,
      "expires_at": 1738236600,
      "created_at": "2026-01-30T12:00:00+00:00",
      "status": "active",
      "score": 0.025,
      "confirmations": 2
    }
  ]
}
```

## Entry Conditions

For a candidate to become a signal:

1. **Minimum monitor time**: 2 minutes (configurable)
2. **Price confirmation**: Price must move in expected direction by 0.3%
3. **Confirmation count**: Movement must be confirmed N times
4. **Drawdown check**: Max adverse movement < 1%

## Configuration

Key settings in `config/config.php`:

```php
'monitor' => [
    'max_monitored' => 50,        // Max candidates to track
    'monitor_ttl_minutes' => 60,  // Expiry time
    'min_score' => 0.01,          // Min Parser4 score
],

'entry' => [
    'min_price_move_pct' => 0.003, // 0.3% confirmation
    'max_drawdown_pct' => 0.01,    // 1% max drawdown
    'min_monitor_time' => 2,       // Minutes before signal
    'confirmation_count' => 2,     // Required confirmations
],

'signal' => [
    'max_active_signals' => 20,    // Hard limit
    'validity_minutes' => 30,      // Signal lifetime
    'take_profit_pct' => 0.02,     // 2% TP
    'stop_loss_pct' => 0.01,       // 1% SL
],
```

## Manual Run

```bash
php modules/parser5_signal_monitor/runner.php
```

## Cron

Runs every 60 seconds via CronManager.

## Architecture

- `manifest.php` — Module metadata
- `cron.php` — Task definition
- `config/config.php` — All settings (zero hardcode)
- `service.php` — Main class `Parser5SignalMonitorService`
- `runner.php` — CLI entry point

## Rules

- NO regex
- NO symbol validation
- NO hardcoded paths
- All paths from config
- Pure monitoring logic only
