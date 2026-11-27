# Volume 3 Task 11 — Overtime and Business-Travel Workspace Report

## Status

**Implemented and committed.** The seven owned frontend files are staged
explicitly and committed with no backend, OpenAPI, router, or other page edits.
Focused vitest is **11/11**; the clean-checkout full frontend unit suite is
**435/436** with the single failure being a pre-existing stale generated
OpenAPI type check (`docs/openapi/openapi.yaml` vs
`frontend/src/generated/api.ts`), unrelated to this task and outside the owned
file set. Typecheck and lint are **exit 0** in the clean checkout.

## Commit

`a137fba0e97df8049bfb3ef4d6fcf749499fd617` — `feat(time): build the overtime and travel workspace`

Staged paths only (explicit `git add -- <paths>`):

- `frontend/src/modules/time/overtime/pages/OvertimeTravelWorkspacePage.vue` (new)
- `frontend/src/modules/time/overtime/components/OvertimeRequestDrawer.vue` (new)
- `frontend/src/modules/time/overtime/components/OvertimeRecordTable.vue` (new)
- `frontend/src/modules/time/overtime/components/TravelRequestDrawer.vue` (new)
- `frontend/src/modules/time/overtime/components/TravelExpensePanel.vue` (new)
- `frontend/src/modules/time/overtime/api.ts` (new)
- `frontend/tests/unit/OvertimeTravelWorkspace.spec.ts` (new)

## What was built

1. **Two independent sections** (`OvertimeTravelWorkspacePage.vue`)
   - `加班` and `出差` are separate `<section>` regions, each with its own
     header, status/date filters, loading/empty/error/partial handling, list,
     and write entry points. No merged form or shared submit state.
   - Overtime lists requests with 申报/确认 minutes, reason, Chinese status,
     next step, and a confirmation flow for `time.overtime.confirm`.
   - Travel lists requests with destination, range, purpose, Chinese travel /
     application status, and the workflow case number.

2. **Overtime request drawer** (`OvertimeRequestDrawer.vue`)
   - Fields: business date, planned start/end, live integer planned-minutes
     preview, reason, and compensation preference (`调休` / `加班费`).
   - Shows the applicable server policy (weekday multiplier, statutory-holiday
     multiplier, pay/time-off allowance, source, note) and disables a
     preference the policy forbids.
   - Submitted payload uses the real contract:
     `{ business_date, declared_minutes, reason }`. The preference is
     explicitly disclosed as request-time intent, because the backend
     compensations are settled later through the single settlement command.

3. **Confirmation and settlement**
   - `POST /v1/time/overtime/requests/{id}/confirm` with
     `{ confirmed_minutes }`, gated by `time.overtime.confirm`.
   - `OvertimeRecordTable.vue` renders server records (no fake rows) and
     performs `POST /v1/overtime/records/{recordId}/settlements` with
     `{ method: 'PAY' | 'TIMEOFF', reason }` after an explicit inline
     confirmation; gated by `time.overtime.settle`.
   - Both commands keep their idempotency key stable across retries of the same
     payload and rotate it when the payload changes.

4. **Travel request and expense flows**
   - `TravelRequestDrawer.vue`: destination, date range, purpose, and an
     integer-minor-unit estimated cost with formatted preview.
   - The live `POST /v1/travel/requests` contract accepts only
     `from_date`, `to_date`, `destination`, `purpose`; the estimate is visibly
     disclosed as not persisted rather than being smuggled into an invented
     field. Estimated cost is validated as integer minor units.
   - `TravelExpensePanel.vue`: per-request category
     (`TRANSPORT/LODGING/MEALS/OTHER` in Chinese), integer minor units, note,
     and formatted row amounts. It only offers APPROVED requests with an
     APPROVED application status, matching the server's claim precondition.
   - Expense submission uses the real `POST /v1/travel/expenses` payload
     `{ travel_id, amount_cents, category, note? }`, gated by
     `time.travel.expense.manage`; the list requires `time.travel.expense`.

5. **Money rules**
   - All model values are integer minor units; decimal/minus input is rejected
     before transport.
   - `formatMinorUnits(minor, currency)` formats through `Intl` without ever
     converting the amount to a float; it derives the currency exponent from
     the API currency. Unsupported currency data falls back to an explicit
     minor-unit string rather than assuming two decimals.
   - Currency is never hardcoded per row. Expense rows use the currency returned
     by the expense API; the page-level currency is only the unique currency
     when the API projection agrees, otherwise blank. No client currency default
     or company-profile currency endpoint exists in this frontend today.

6. **Five states and capability guards**
   - Each section independently renders loading, empty, error (request id +
     retry), forbidden (no reads issued), and partial warnings.
   - Guards: `time.overtime.read`, `time.overtime.request`,
     `time.overtime.confirm`, `time.overtime.settle`; `time.travel.read`,
     `time.travel.request`, `time.travel.expense`,
     `time.travel.expense.manage`, `time.travel.policy.read`.
   - Travel approval and policy publishing are deliberately not surfaced here:
     they are real routes but belong to governance pages. No hidden shortcut
     was added.
   - All display enums are mapped to Chinese with explicit unknown fallbacks.

## TDD evidence

- `frontend/tests/unit/OvertimeTravelWorkspace.spec.ts` was written first
  (11 tests).
- Observed RED: Vitest failed to resolve
  `@/modules/time/overtime/pages/OvertimeTravelWorkspacePage.vue`.
- Implemented the API boundary and components until green. Additional REDs were
  observed for:
  - settlement/confirmation key reuse (initially regenerated per request);
  - missing `applicationStatusLabel` import breaking the travel table render;
  - expense-form visibility semantics under `expense` vs `expense.manage`.
- Coverage:
  1. two sections + independent filters + policies + Chinese statuses + exact
     read endpoints;
  2. overtime policy shown and planned time submitted as integer minutes;
  3. overtime 422 inline + stable key on retry;
  4. confirmation with integer minutes + stable key across a 422 retry;
  5. settlement after explicit confirmation with `PAY/TIMEOFF` + stable key;
  6. API-currency formatting, decimal minor-unit rejection, expense 422 inline,
     stable key;
  7. travel request 422 inline + stable key;
  8. estimated cost stays local and is disclosed, real snake_case payload;
  9. forbidden states per section with zero unauthorized reads;
  10. write controls hidden for read-only capabilities, including
      `expense` without `expense.manage` and without `time.travel.expense`;
  11. loading/empty/error/partial states independently per section.

## Verification (real output)

- `cd frontend && pnpm exec vitest run tests/unit/OvertimeTravelWorkspace.spec.ts`
  → **11 passed / 11** (post-commit).
- `cd frontend && pnpm typecheck` → **exit 0** (clean verified checkout and
  current worktree).
- `cd frontend && pnpm lint` → **exit 0** with `--max-warnings=0`.
- `cd frontend && pnpm test:unit` in a clean detached worktree at `a137fba`:
  **57 files passed, 1 failed; 435/436 tests passed**. The only failure is
  `openapiGeneration.spec.ts > committed types are current`, reporting
  `generated types are stale` against the pre-existing committed
  `frontend/src/generated/api.ts`. This task changed no OpenAPI or generated
  file and must not edit `docs/openapi/**`; that check is outside the owned
  files.
- In the shared worktree, concurrent tasks were actively editing
  `timepay/pages/*` and `platform/pages/*`; cross-module page imports from
  those concurrent edits caused transient `moduleBoundaries` and
  `timePayrollPages` failures there. The clean checkout above is the reliable
  signal for this task.
- `cd backend && ../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --colors=never`
  → **OK (1201 tests, 57841 assertions)**. No backend file changed.
- `node scripts/audit_frontend_modules.mjs` → `ok: true`, no failures.
- Endpoint verification: `php artisan route:list --path=overtime` and
  `--path=travel` confirmed every endpoint consumed here:
  - `GET /v1/time/overtime/records`
  - `GET /v1/time/overtime/policies`
  - `GET /v1/time/overtime/requests`
  - `POST /v1/time/overtime/requests`
  - `POST /v1/time/overtime/requests/{id}/confirm`
  - `GET /v1/time/overtime/timeoff-balances`
  - `POST /v1/overtime/records/{recordId}/settlements`
  - `GET /v1/travel/requests`
  - `POST /v1/travel/requests`
  - `GET /v1/travel/expenses`
  - `POST /v1/travel/expenses`
  - `GET /v1/travel/policies`

## Known gaps / missing endpoint support (reported, not invented)

1. **No estimated-cost field on the travel request contract or table.** The
   brief asks for estimated cost; the real
   `POST /v1/travel/requests` and `business_trip_records` table have no such
   field. The drawer collects and validates it as integer minor units and shows
   the formatted preview, then explicitly tells the user that the application
   endpoint does not persist it.
2. **No company profile currency endpoint is currently exposed by the frontend
   API.** `GET /v1/me` returns `company_name` only. Expense currency comes from
   the `TravelExpense` API; there is no local hardcoded fallback. The page-level
   request-estimate preview therefore has no symbol until a real unique
   currency is available.
3. **Overtime compensation preference has no request-record field.** The real
   overtime request stores only declared minutes and reason. The choice is
   collected at request time for user intent and is disclosed to be confirmed
   later at settlement, where the real `PAY`/`TIMEOFF` method is persisted.
4. **Travel approval / policy publishing** exist as endpoints but belong to
   the governance pages; this task's workspace intentionally exposes neither.
   No action is fabricated.
5. **Page wiring is not in this commit.** `frontend/src/router/registry.ts`,
   `frontend/src/modules/timepay/pages/index.ts`, and the TimePay page wrappers
   are not owned by this task. In the shared worktree, a concurrent task
   modified `OvertimeMyPage.vue` / `TravelMyPage.vue` to mount this workspace;
   those modifications are outside this commit and were left untouched.
6. **`GET /v1/time/overtime/timeoff-balances` is effectively invisible to
   `time.overtime.read` only** because its route requires
   `time.timeoff.read`. The page reads it only when that capability is present
   and otherwise renders the balance as `—`; no unauthorized request is made.
