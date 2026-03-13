Patch: ProfitManager status reasons (step vs dumb trailing)

- Fixes misleading `last_reason` in status.json:
  previously `dumb_trailing_disabled` overwrote the real step-trailing reason (e.g. `below_activation`).

Files:
- modules/system/profit_manager/lib/profit_manager.php
