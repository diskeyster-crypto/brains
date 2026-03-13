# Manager v2 — Behavior Profiler

Backend-only module (JSON storage only).  
Reads:
- Parser1 active registry (`modules/parser1_market_registry/storage/active.json`)
- Parser2 RAW history NDJSON (`modules/parser2_history_accumulator/storage/{SYMBOL}/*.ndjson`)

Writes:
- `storage/profiles/{SYMBOL}.json` — behavior profile (NO prices)
- `storage/classes/{class}.json` — class index lists
- `storage/state.json`, `storage/last_run.json`, `storage/errors.json`

Run via CronManager by calling `cron.php` or `ManagerBehaviorService::execute()`.
