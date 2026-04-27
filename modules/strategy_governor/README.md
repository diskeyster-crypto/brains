# Strategy Governor — V1 Shadow-Only Routing Layer

## Purpose

The Strategy Governor is the first self-learning routing layer for strategy signals.
**V1 is shadow-only.** It observes, analyses, and writes recommendations — it does
not block orders, modify bot queues, change strategy behaviour, or interact with
any exchange or API.

---

## Module structure

```
modules/strategy_governor/
  config/
    base.php                — default config (committed)
    active.php              — local overrides (not committed)
  src/
    strategy_governor.php   — core engine
  storage/                  — runtime files (not committed, created lazily)
    strategy_state.json     — per-strategy recommended state + live-gate
    strategy_stats.json     — aggregated closed-trade stats per strategy
    hourly_stats.json       — stats bucketed by hour-of-day + weekday
    pending_signals.json    — signals still inside the confirmation window
    decisions.ndjson        — append-only decision journal (one record per line)
    last_run.json           — last tick summary
  service.php               — public entry point / mod_class
  cron.php                  — cron task descriptor (interval: 60 s)
  manifest.json
  mod_class.txt
  README.md
```

---

## Data inputs (read-only, all optional)

| File | Description |
|---|---|
| `modules/bot/storage/order_queue.json` | Pending bot orders |
| `modules/bot/storage/active_positions.json` | Open positions |
| `modules/bot/storage/trades/closed_trades.json` | Closed trade history |
| `modules/strategy/pattern/*/storage/signals.json` | Per-strategy signals |
| `modules/strategy/pattern/*/storage/bot_handoff_queue.json` | Handoff-ready signals |
| `modules/strategy/pattern/*/storage/last_run.json` | Strategy last-run metadata |

Missing files are treated as empty — no fatal errors.

---

## Decision states (V1)

| State | Meaning |
|---|---|
| `observed` | Signal seen, no further action yet |
| `wait_confirmation` | Inside the tick-confirmation window |
| `approve_demo_shadow` | Governor recommends demo routing |
| `approve_live_shadow` | Governor recommends live routing |
| `reject_shadow` | Governor recommends rejection |
| `expired_shadow` | Signal too old or confirmation window exhausted |

All decisions are **shadow-only** in V1. No routing happens.

---

## 5-tick confirmation model

1. A new signal enters `wait_confirmation`.
2. `tick_count` increments each Governor run (~60 s interval).
3. After `pending_confirmation_ticks` (default 5) the signal receives a final decision.
4. Hard-reject conditions (`missing_symbol`, `missing_entry_price`, `missing_detected_at`,
   `handoff_signal_invalid`, `signal_too_old`) fire immediately regardless of tick count.

---

## Strategy state (`strategy_state.json`)

Example:

```json
{
  "corridor_bottom_long": {
    "state": "insufficient_data",
    "recommended_live_allowed": false,
    "recommended_route": "demo",
    "reason": "not_enough_closed_trades",
    "closed_trades_total": 0,
    "winrate": 0.0,
    "avg_roi": 0.0
  }
}
```

Possible `state` values:
- `insufficient_data` — fewer than `min_closed_trades_for_live` trades
- `shadow_observe` — enough data but below live thresholds
- `shadow_live_ready` — live gate passed (shadow recommendation only)
- `shadow_blocked` — too many consecutive losses

---

## Config (`config/base.php`)

| Key | Default | Description |
|---|---|---|
| `enabled` | `true` | Master switch |
| `mode` | `shadow` | V1 only supports `shadow` |
| `enforce_live_gate` | `false` | Must stay `false` in V1 |
| `pending_confirmation_ticks` | `5` | Ticks before final decision |
| `min_closed_trades_for_live` | `20` | Minimum closed trades for live gate |
| `min_hourly_trades_for_live` | `5` | Minimum hourly-bucket trades |
| `min_winrate_for_live` | `0.55` | Win-rate threshold |
| `min_avg_roi_for_live` | `1.0` | Average ROI % threshold |
| `max_consecutive_losses_live` | `3` | Max consecutive losses before block |
| `cooldown_minutes_after_block` | `180` | Cooldown after recommended block |

Override in `config/active.php` (not committed).

---

## Safety guarantees (V1)

- No writes to `modules/bot/storage/`
- No writes to strategy storage
- No calls to Profit Manager or Stop Manager
- No exchange/API calls
- `enforce_live_gate` is always `false` in V1 — no gating of any order
