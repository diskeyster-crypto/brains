# Parser 3 — Manager (Behavior Classifier)

## Назначение
Parser 3 классифицирует инструменты по поведению на основе истории, собранной Parser 2, и формирует списки/кандидатов для следующих стадий (Parser 4/5).

## Вход
1) **Universe / список символов** от Parser 1 (реестр):
- файл: `modules/parser1_registry/storage/active.json` (по умолчанию, задаётся в `config/config.php`)
- формат: массив строк, например: `["BTCUSDT","ETHUSDT", ...]`

2) **История цен** от Parser 2 (аккумулятор):
- директория: `modules/parser2_history_accumulator/storage/`
- структура:
  - `storage/<SYMBOL>/<YYYY-MM-DD>.ndjson`
  - пример: `modules/parser2_history_accumulator/storage/ZRXUSDT/2026-01-29.ndjson`

## Выход
В `modules/parser3_manager_behavior/storage/`:
- `classified/` — профили по символам (json)
- `candidates/` — кандидаты для следующих стадий (json)
- `last_run.json` — сводка последнего запуска (счётчики, входные пути, ошибки)

## Важные правила
- Parser 3 **не строит цены/свечи**, не делает трейдинг-решения.
- Parser 3 берёт **список символов** из Parser 1 и **только по ним** пытается прочитать историю в Parser 2.
- Если истории по символу нет — символ считается `unclassified`, счётчик `history_missing` растёт.
- В этом билде Parser 3 пропускает инструменты, которые **не являются простым символом вида `XXXXUSDT`** (например, `MNTUSDT-13FEB26`), чтобы мусор/деривативы не ломали пайплайн.

## Пайплайн (актуальный)
Parser0 → Parser1 → Parser2 → Parser3 → Parser4 → Parser5
