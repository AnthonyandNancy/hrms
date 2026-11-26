# Volume 3 Task 9 — Attendance Workspace Report

## Status

**Implemented and committed? Partial integration due missing backend routes.**

The frontend attendance workspace is implemented, TDD-covered, typechecked,
linted, and committed. It is wired to the five endpoint paths named in the task
brief, but only two of the needed read/resolve paths exist in this worktree's
route table at the time of implementation. No backend or router file was edited
(ownership restriction), so the page is not yet reachable through the existing
router and its live read/resolve flows are blocked on backend work described
below.

## Commit

`76b86dac316810731a069ab1920457f2fad70dec` — `feat(time): build the attendance workspace`

Staged paths only:

- `frontend/src/modules/time/attendance/pages/AttendanceWorkspacePage.vue`
- `frontend/src/modules/time/attendance/components/AttendanceCalendar.vue`
- `frontend/src/modules/time/attendance/components/AttendanceCorrectionDrawer.vue`
- `frontend/src/modules/time/attendance/components/AttendanceExceptionList.vue`
- `frontend/src/modules/time/attendance/api.ts`
- `frontend/tests/unit/AttendanceWorkspace.spec.ts`

## What was built

1. **Month calendar** (`AttendanceCalendar.vue`)
   - API-driven business month with a 7-column grid. Day cells are derived
     from the API `month`, not the browser date.
   - Markers and legend for 出勤 (present), 迟到 (late), 早退 (early), 缺勤
     (absent), and 休假 (leave), plus remote/rest/holiday/incomplete labels.
   - Day detail dialog shows clock-in, clock-out, server-computed worked minutes,
     scheduled minutes, late/early minutes, exception label, and the API
     timezone used for display.
   - Time display converts the API UTC instant through the API-provided IANA
     timezone using `Intl.DateTimeFormat`; no local business-date derivation.
   - Keyboard-accessible day buttons with descriptive `aria-label`.

2. **Correction drawer** (`AttendanceCorrectionDrawer.vue`)
   - Day picker, correction type selector with Chinese labels:
     `MISSING_PUNCH` → 补卡, `WRONG_TIME` → 漏卡,
     `WRONG_STATUS` → 异常修正.
   - Optional clock-in/clock-out times and a required reason (minimum 5
     characters, matching the server rule).
   - Submits `POST /v1/time/attendance/corrections` with an idempotency key.
   - Server 422 is shown inline with field errors and request id; the drawer
     stays open on failure. Success closes and triggers a day refetch.

3. **Exception list** (`AttendanceExceptionList.vue`)
   - Shows only open/pending exceptions returned by the API.
   - Chinese labels for missing punch, late, early, absent, and incomplete.
   - Resolve flow requires a non-empty note and an explicit confirmation
     button; it posts to
     `POST /v1/time/attendance/exceptions/{id}/resolve` with an idempotency key.

4. **Workspace page** (`AttendanceWorkspacePage.vue`)
   - Employee number and org-unit filters are forwarded to the APIs for
     self/manager scoping; no client-side invention of team data.
   - Capability guards:
     - read: `time.attendance.read` (forbidden state without data requests)
     - correction: `time.attendance.correct.request`
     - resolve: `time.attendance.manage`
     - rules navigation: `time.attendance.rule.manage`
   - Five states: loading, empty, error (retry + request id), forbidden, and
     partial (calendar remains visible when the exception read fails).
   - No money, payroll, salary, leave, or overtime logic in this page.
   - No fake rows; empty responses render explicit empty states.

## TDD evidence

- Wrote `frontend/tests/unit/AttendanceWorkspace.spec.ts` first (10 tests).
- Observed RED: suite failed to resolve the not-yet-created
  `AttendanceWorkspacePage.vue`.
- Implemented the minimum production components/API and iterated until green.

Coverage includes:

1. legend + all five calendar markers;
2. day detail with clock times and computed minutes;
3. timezone display using API `timezone` and API business month;
4. correction reason validation before transport;
5. server 422 inline + drawer remains open;
6. correction payload + refetch on success;
7. exception note + confirmation behavior;
8. capability-guarded controls;
9. forbidden state with no data requests;
10. loading, empty, error, and partial states.

## Verification

- `cd frontend && pnpm exec vitest run tests/unit/AttendanceWorkspace.spec.ts`
  → **10 passed / 10**.
- `cd frontend && pnpm test:unit`
  → **52 files passed, 367 tests passed / 367**.
- `cd frontend && pnpm typecheck`
  → **PASS** (final run; an earlier run was red only because a concurrent
  `DepartmentWorkspace.spec.ts` referenced a not-yet-created module page).
- `cd frontend && pnpm lint`
  → **PASS** (`--max-warnings=0`).
- `node scripts/audit_frontend_modules.mjs`
  → `{"ok":true,"scanned":203,"failures":[]}`.
- Full `pnpm test:unit` was green at **367/367** before a concurrent
  Department workspace edit landed; the final run was **365/367** with only two
  failures in `DepartmentWorkspace.spec.ts` (not Task 9 files).

A post-commit TDD addition covers API clock strings already in business-local
form (e.g. `09:00`): the test failed as `时区数据不可用`, then `formatClock` was
fixed to preserve local wall-clock values and still convert UTC instants through
the API timezone. The final targeted run is **10/10** and the fix is squashed
into the single commit above.

Backend tests were not run because no backend file was changed and the scope is
frontend-only. No OpenAPI or generated-client file was edited.

## Missing / mismatched endpoints (must be resolved before live use)

The brief's verified-state list names endpoints that are not present in this
worktree's `backend/routes/api.php`. Actual attendance routes present:

- `GET /v1/time/attendance` (self month projection)
- `GET /v1/time/attendance/{employment_id}` (scoped day rows)
- `POST /v1/time/attendance/corrections`
- `POST /v1/time/attendance/closings`, `POST /v1/time/attendance/closings/{id}/reopen`
- `POST /v1/time/attendance/recalculate`
- `GET /v1/time/attendance/rules`, `POST /v1/time/attendance/rules`
- `GET /v1/time/attendance/closings`, `GET /v1/time/attendance/devices`

Needed by this page but **missing in this worktree**:

1. `GET /v1/time/attendance/days` — no route. The existing day read is
   `GET /v1/time/attendance/{employment_id}`; it also lacks clock-in/out,
   exception labels, and the org-unit/manager filter shape this workspace
   needs.
2. `GET /v1/time/attendance/exceptions` — no route.
3. `POST /v1/time/attendance/exceptions/{id}/resolve` — no route.
4. `POST /v1/time/attendance/rule-sets` — no route. This page does not use it;
   the rules governance page is out of Task 9 scope.

Also noted but not used by this page:

5. `POST /v1/time/attendance/devices` — absent and deliberately unsupported:
   the existing device API is documented/tested as read-only and returns 405
   for writes. The task's "GET/POST devices" verified-state note is inconsistent
   with the current backend.

The UI does **not** invent fallback mutations or local rows for these missing
endpoints. Until the backend routes land (or the page is pointed at the existing
paths with equivalent payloads), calendar/exceptions reads and exception resolve
will fail at runtime.

## Constraints respected

- Only the six owned Task 9 files were created; no router, backend, OpenAPI,
  shared component, or other page file was edited.
- No money/payroll code, no fake rows, no local UTC "today" computation.
- No cross-module page imports; module boundary audit passes.


## Follow-up: alignment with real attendance routes

Peer commit `90af76d` retargeted the attendance API from the brief-only paths to
the routes that actually exist:

- month/day reads use `GET /v1/time/attendance?month=YYYY-MM` or
  `GET /v1/time/attendance/{employment_id}?from=&to=`;
- exceptions are derived from the same authorised month snapshot (late, early,
  absent, incomplete; leave is not an exception);
- “resolving” an exception submits the audited
  `POST /v1/time/attendance/corrections` command with the real business date,
  `WRONG_STATUS`, and the required note.

The peer change left four stale tests expecting `/attendance/days`,
`/attendance/exceptions`, and `/attendance/exceptions/{id}/resolve`. They were
updated to assert the real route, one-snapshot exception derivation, and the
correction payload. The page now makes a single month read per load/refetch
rather than issuing a duplicate exceptions read.

Fix commit: `dc6dd288291523e25d8453c4aaf43133e202071b` —
`fix(time): align attendance workspace with real routes` (explicit Task 9 paths
only).

Post-fix verification:

- `pnpm exec vitest run tests/unit/AttendanceWorkspace.spec.ts` → **10/10**.
- `pnpm test:unit` → **54 files / 392 tests passed**.
- `pnpm typecheck` → **PASS**.
- Task 9 file lint (`eslint src/modules/time/attendance tests/unit/AttendanceWorkspace.spec.ts`)
  → **PASS**.
