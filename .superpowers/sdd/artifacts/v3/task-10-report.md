# Volume 3 Task 10 — Leave Management Workspace Report

## Status

**Implemented and committed.**

The leave workspace is a complete, server-driven frontend slice: balance cards,
a preview-then-submit request drawer, a status-filtered request list with a
confirmed cancellation path, and a read-only ledger with a server-computed
running balance. It uses the real `/v1/time/leave/*` endpoints only. No backend,
OpenAPI, router, or other page file was modified.

## Commit

`71d965d5f8697b7bb4ea976608293569f1f3f4d1` — `feat(time): build the leave management workspace`

Staged explicit paths only:

- `frontend/src/modules/time/leave/api.ts`
- `frontend/src/modules/time/leave/pages/LeaveWorkspacePage.vue`
- `frontend/src/modules/time/leave/components/LeaveBalanceCards.vue`
- `frontend/src/modules/time/leave/components/LeaveRequestDrawer.vue`
- `frontend/src/modules/time/leave/components/LeaveRequestList.vue`
- `frontend/src/modules/time/leave/components/LeaveLedgerPanel.vue`
- `frontend/tests/unit/LeaveWorkspace.spec.ts`

## Requirements delivered

1. **Balance cards per leave type** — entitled (应享), used (已用), pending
   (待占用), and available (可用) values, unit label 天/小时 from the server's
   `unit`, and the server `asOf` date shown per card and in the page meta row.
   Hourly accounts are rendered from the server's canonical minute fields
   (`grantedMinutes`/`consumedMinutes`/`reservedMinutes`/`availableMinutes`) so
   an hours label can never sit beside day-scaled amounts. No balance is
   recomputed in the client.
2. **Preview-then-submit drawer** — leave type, start/end date, half-day option
   (disabled unless the range is one day), and reason. Any change to type or
   dates invalidates the current preview and immediately calls
   `POST /v1/time/leave/preview`; submit is disabled until a fresh preview
   exists for the current key. Submit is blocked if the preview is `null`,
   stale, or contains a `BLOCKING` conflict. The server's computed deduction,
   available before/after, working days, and conflicts are displayed.
3. **Stable Idempotency-Key** — a key is created when the submission first
   leaves the client and is reused across retries of the same payload (for
   example a network failure whose outcome is unknown). Any material edit
   invalidates the key, so a different request gets a different key. Covered by
   test: two POSTs for the retry share one key; an edited payload receives a
   different key.
4. **Request list** — status filters 全部 / 待审批 / 已通过 / 已驳回 with counts,
   Chinese status labels (including the backend's `IN_REVIEW` state), leave type
   name, dates, and duration rendered from server data. Cancellation requires an
   explicit confirmation dialog and shows a Chinese failure reason derived from
   the server reason (`LEAVE_REQUEST_NOT_FOUND` → 未找到该请假申请), with the
   request id. A stable cancel key is reused on retry. Successful cancellation
   refetches balances and requests because the reservation was released.
5. **Ledger panel** — read-only entries with 计入/扣减 direction, server
   `balanceAfter` running balance, business date, entry type, reason/source
   case, and the API `as_of` date. Opening/closing balances are server values;
   the page explicitly states it does not recompute the balance. If the user
   lacks `time.leave.ledger.read`, a locked panel explains the permission
   instead of issuing the request.
6. **Business-date honesty** — the page sends no `as_of` and derives no
   "today"; the server decides the business date from the company profile
   timezone. No `new Date()` or browser date arithmetic exists in the module.
   The ledger request intentionally omits `as_of`; its returned `as_of` is
   displayed. Preview/request dates come from the user's inputs as
   `YYYY-MM-DD`, never from the browser "today".
7. **Five states** — loading, empty, error (with code and request id), forbidden
   (no data requests issued), and partial (one section failing leaves the others
   visible; all failing escalates to the page error).
8. **Capability guards** —
   - read: `time.leave.read` (forbidden panel and no requests without it),
   - request drawer / 申请请假: `time.leave.request`,
   - cancel: `time.leave.request.cancel`,
   - ledger: `time.leave.ledger.read`.
9. **No fake rows** — empty API results render explicit empty messages; the
   drawer disables its type selector when the catalog is empty rather than
   inventing a leave type. Chinese enums are mapped from server codes in one
   place.

## Endpoints used (all verified present)

- `GET /v1/time/leave/types`
- `GET /v1/time/leave/balances`
- `GET /v1/time/leave/requests`
- `POST /v1/time/leave/preview`
- `POST /v1/time/leave/requests` (Idempotency-Key)
- `POST /v1/time/leave/requests/{caseId}/cancel` (Idempotency-Key)
- `GET /v1/time/leave/ledger?leave_type=...`

`GET /v1/time/leave/policies` exists but has no UI action in this workspace;
policies are not required to request leave, and the brief's workspace scope is
balances, requests, cancellation, and ledger. Nothing was invented to fill that
gap. No missing endpoint blocks the implemented flows.

## TDD evidence

The Vitest suite was written first at
`frontend/tests/unit/LeaveWorkspace.spec.ts`; the first run failed because
`LeaveWorkspacePage.vue` did not exist ("Failed to resolve import"), and
subsequent RED runs failed on missing behaviors before each implementation
step (balances rendering, preview freshness, stable key reuse, cancel
confirmation, ledger fields). Final suite: **11 tests**.

Coverage:

1. balance cards render entitled/used/pending/available with unit and server
   as-of date, including an hourly account, request rows, and capability-guarded
   hidden controls while only `time.leave.read` is held;
2. preview is called before submit, a type change triggers a new preview, the
   retry of the same submission reuses the Idempotency-Key, and an edited
   payload gets a different key;
3. preview deduction and Chinese BLOCKING conflict display, with submission
   blocked and no POST issued;
4. a changed date range with a pending preview blocks submit ("预览已过期");
5. server 422 stays inline with field error and request id while the drawer
   stays open;
6. cancellation requires confirmation, posts the real endpoint, reuses one key,
   and shows a Chinese failure reason;
7. ledger renders credit/debit rows, running balance, 只读 marker, and as-of
   date;
8. forbidden state renders without any API call when read is missing;
9. loading then success renders, and the empty state renders from empty API
   responses;
10. all-sections-failing renders the error state with the server request id;
11. one-section failure renders the partial state while balances remain.

## Verification

- `cd frontend && pnpm exec vitest run tests/unit/LeaveWorkspace.spec.ts`
  → **11 passed / 11** (post-commit run).
- Scoped ESLint on the leave module and spec:
  `pnpm exec eslint src/modules/time/leave tests/unit/LeaveWorkspace.spec.ts --max-warnings=0`
  → **PASS**.
- Scoped `vue-tsc` over the leave module + spec with the app tsconfig
  → **PASS (exit 0)**.
- Full `pnpm test:unit` at final run: **58 files collected, 57 passed; 436
  tests, 431 passed, 5 failed** — all 5 failures were inside
  `OvertimeTravelWorkspace.spec.ts`, a concurrently written Task 11 file
  (`frontend/src/modules/time/overtime/` was still untracked/in flight at that
  moment). The leave spec itself passed in that run.
- Full `pnpm typecheck` → **PASS**.
- Full `pnpm lint` → **PASS**.

**The full frontend suite was not green at commit time because of
concurrently-written Task 11 overtime files; this is not a claim that the full
suite passed.** The lead will re-run the volume gate.

## Notes / residual risks

- The balance endpoint returns `asOf` per row; if rows ever disagree the page
  shows all distinct dates rather than picking one.
- The request list duration uses the matching balance's unit and
  `standardDayMinutes`; where no projection exists it truthfully shows
  canonical minutes instead of guessing.
- The drawer mirrors the backend's 3-character minimum for `reason`; the
  server's 422 remains authoritative and is surfaced inline with field errors
  and the request id.
- `time.leave.policies` is deliberately not surfaced in this workspace.
