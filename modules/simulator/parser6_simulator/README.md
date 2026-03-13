# Parser 6: Trading Simulator Engine (Stateful)

## Purpose

Parser6 Simulator is a **continuously running trading simulator** that:

- Runs **parallel to the live trading bot**
- **Does NOT send orders** to Bybit
- Fully replicates the **live execution logic**
- Maintains the **complete trade lifecycle**
- Records data for **ML training**

The simulator **always runs**, even when the live bot is disabled.

## Architecture Role

Parser6 = **Stateful Trading Engine**

It's not a "signal processor" - it **lives with the trades**.

Type: **Long-living state machine**, not a batch script.

## Pipeline Position

```
Parser0 → Parser1 → Parser2 → Parser3 → Parser4 → Parser5 → Parser6
                       ↓                            ↓          ↓
                   price history               signals    simulator
```

## Data Sources

### Input #1: Signals
- Source: `modules/parser/parser5_signal_monitor/storage/signals.json`
- Fields used: `id`, `symbol`, `side`, `entry_price`, `take_profit`, `stop_loss`, `expires_at`, `created_ts`, `score`, `confirmations`

### Input #2: Price History (NDJSON)
- Source: `modules/parser/parser2_history_accumulator/storage/{SYMBOL}/YYYY-MM-DD.ndjson`
- Format: `{"ts": 1770060783, "price": 0.023931}`
- This is the **only source of "real prices"**.

## Storage Structure

```
modules/simulator/parser6_simulator/storage/
├── trades/
│   ├── active/          # Active (open) trades, one file per trade
│   ├── closed/          # Closed trades with results
│   └── rejected/        # Rejected trades (never opened)
├── dataset/             # Per-trade JSONL files for ML training
├── stats/
│   └── summary.json     # Detailed statistics
├── logs/
│   └── simulator.log
├── training_dataset.ndjson   # Centralized training data
├── stats_global.json         # Global statistics per spec
├── ticks_last_seen.json      # Last seen tick timestamps per symbol
├── state.json
├── last_run.json
├── executed_index.json
└── errors.json
```

## Trade Model

Each trade has:

```json
{
  "trade_id": "ROAMUSDT_1770060542",
  "symbol": "ROAMUSDT",
  "side": "long",
  "entry": {
    "entry_price_signal": 0.022883,
    "opened": false,
    "opened_at": null,
    "opened_price": null,
    "qty": 1847.52
  },
  "targets": {
    "take_profit": 0.0238,
    "stop_loss_initial": 0.0221,
    "stop_loss_current": 0.0221
  },
  "trailing": {
    "enabled": true,
    "active": false,
    "activation_profit_pct": 0.02,
    "stop_price": null
  },
  "pnl": {
    "roi_unrealized": 0,
    "pnl_unrealized_usdt": 0
  },
  "events": [
    {"ts": 1770060542, "type": "pending_entry"}
  ]
}
```

## Trade States (State Machine)

| State | Description |
|-------|-------------|
| `pending_entry` | Waiting for entry price touch |
| `active` | Position is open |
| `closed` | Position closed (TP/SL/trailing) |
| `rejected` | Signal rejected (expired/timeout) |

## Main Loop (Each Cron)

```
1. Load pending trades (trades/active with opened=false)
2. Load active trades (trades/active with opened=true)
3. Load new signals from parser5
4. Load new ticks from parser2
5. processPending() → check entry, check expiry
6. processActive() → update PnL, check TP/SL/trailing
7. saveAll()
8. appendTrainingDataset()
```

## Close Reasons

| Reason | Description |
|--------|-------------|
| `tp` | Take profit hit |
| `sl` | Stop loss hit |
| `trailing_sl` | Trailing stop loss triggered |
| `closed_max_duration` | Max trade duration exceeded |
| `reject_expired_signal` | Signal expired before entry |
| `reject_entry_not_reached` | Entry timeout, price never touched |

## Training Dataset (training_dataset.ndjson)

Each closed trade appends a row:

```json
{
  "symbol": "ROAMUSDT",
  "side": "long",
  "entry_price": 0.022883,
  "exit_price": 0.02391,
  "roi": 3.2,
  "pnl": 12.34,
  "duration_sec": 842,
  "score": 0.057,
  "confirmations": 1,
  "close_reason": "trailing_sl"
}
```

## Global Statistics (stats_global.json)

```json
{
  "total_trades": 100,
  "wins": 65,
  "losses": 35,
  "winrate": 0.65,
  "total_roi": 0.42,
  "avg_roi": 0.0042,
  "max_drawdown": -0.15,
  "avg_duration": 3600,
  "profit_factor": 2.1
}
```

## UI Dashboard

Path: `/public/admin/simulator`

Displays:
- Active Trades (symbol, side, entry, current, ROI, trailing status)
- Closed Trades (symbol, reason, ROI, duration)
- Rejected Trades (symbol, reason, timestamp)
- Global Stats (winrate, total ROI, drawdown, profit factor)
- Configuration (budget, leverage, max trades)

## Key Principle

> **The simulator NEVER forgets a trade until it is CLOSED or REJECTED.**

## Main Concept (One Line)

> **Parser6 is a trading engine, not a signal processor.**

It must:
- Recalculate state on every cron
- Live with trades
- Maintain full lifecycle
- Generate training dataset

## Manual Run

```bash
php modules/simulator/parser6_simulator/runner.php
```
