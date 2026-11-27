# FactoryHR V2.1 — Volume 3 Acceptance

Date: 2026-11-27
Branch: `feat/v2.1-remediation`
Worktree: `D:/code/ai/factoryHR/.worktrees/v2.1-remediation`
Scope: Volume 3 of 4 — employee domains, time, payroll, talent, platform workspaces.

## Verdict

**APPROVED** for progression to Volume 4.

All gates are green on a clean tree. Every one of the 17 implementation tasks is
merged with its own focused test suite, and the three product gates that were
red at the start of acceptance (`page_model`, `ui_primitives`, `user_copy`) were
fixed at the source rather than suppressed.

## Task ledger

| Task | Deliverable | Commit |
| --- | --- | --- |
| 1 | Atomic employee onboarding (transaction + idempotency + audit) | `31c350f` |
| 2 | Employee list workspace | `75caed9` |
| 3 | Employee detail (same-object tabs) | `fe9e023` |
| 4 | Sub-profiles, attachments, change requests | `a8bc7ed` |
| 5 | Department workspace + write endpoints | `34cc4bc`, `90af76d` |
| 6 | Position workspace | `1b55cb8` |
| 7 | Movement workspace (onboarding/probation/transfer/salary/secondment/offboarding) | `bed01fa`, `925690b` |
| 8 | Employment contract workspace | `1344bcb` |
| 9 | Attendance workspace + endpoint realignment | `76b86da`, `dc6dd28` |
| 10 | Leave workspace | `71d965d` |
| 11 | Overtime + travel workspace | `a137fba` |
| 12 | Payroll workspace + specialist run scope fix | `fa140c2` |
| 13 | Performance workspace | `7ef7abb` |
| 14 | Recruitment workspace (requisitions + pipeline + conversion) | `33c222e`, `da7c343` |
| 15 | Training workspace | `4ee8da5` |
| 16 | Dashboard, reports, notices | `9225de0` |
| 17 | Workflow center, calendar, settings, notifications, audit | `f9bea2f` |
| — | Router wiring for every new workspace | `bffeaa0` |
| — | Role rename/delete endpoints | `d5a05da` |
| — | Page actions + required APIs registered | `6001fe2` |
| — | Form routes converted to actions (ADR-0035) | `1998669` |
| — | Raw controls replaced with shared primitives | `4c503c6` |
| — | Chinese labels and dictionary | `54ee940` |

## Gate evidence (commands actually run)

Backend, from `backend/`:

```
../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit --colors=never
  OK (1246 tests, 58376 assertions)          exit 0
../.tools/php/php.exe -d memory_limit=1G vendor/bin/pint --test
  PASS 1190 files                             exit 0
../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpstan analyse --no-progress
  [OK] No errors                              exit 0
../.tools/php/php.exe -d memory_limit=1G artisan capability:audit
  Capability audit OK. Public routes (allowlist): 6   exit 0
../.tools/php/php.exe -d memory_limit=1G artisan arch:test
  Module boundaries OK. Warnings (non-blocking): 4    exit 0
```

Frontend, from `frontend/`:

```
pnpm typecheck      exit 0
pnpm lint           exit 0   (0 warnings, --max-warnings=0)
pnpm test:unit      66 files / 545 tests passed      exit 0
VITE_API_MODE=http pnpm build                        exit 0
pnpm exec playwright test --config=playwright.real.config.ts --list
  52 tests in 15 files                               collected, NOT executed
```

Root:

```
node deploy/scripts/openapi-lint.mjs
  OK (259 paths, 334 operations, 3.1.0)      exit 0
pnpm openapi:validate                        exit 0
pnpm actions:check
  OK (155 pages, 16 write pages, 15 pages with actions, 34 actions,
      149 explicit write gaps, 14 owned capability gaps, 1 public write exception)
node scripts/audit_page_model.mjs            exit 0
node scripts/audit_ui_primitives.mjs         exit 0
node scripts/audit_user_copy.mjs             exit 0
node scripts/audit_page_components.mjs       155/155, 149 module + 6 base, exit 0
node scripts/audit_route_coverage.mjs        exit 0
node scripts/audit_action_manifest.mjs       exit 0
```

Real-stack Playwright execution is **NOT RUN**: there is no seeded demo
password or exposed port in this environment. Only collection was verified.
This is recorded here so no later reader mistakes collection for execution.

## Real defects found and fixed in this volume

These were not cosmetic; each had a failing test before the fix.

1. **PAYROLL_SPECIALIST could not list payroll runs** (carried from the Volume 2
   review). The index was guarded `capability:payroll.run.read,COMPANY` while the
   role grants the capability at `PAYROLL_RUN`. Worse, `listRuns()` applied **no
   membership filter at all** — dropping the COMPANY anchor alone would have
   exposed every run in the company. Fixed by making membership the row filter
   (`runIdsForUser`) and keeping the guard as an "may work with payroll" check.
   Regression covered in `RoleMatrixTest` and `PayrollWorkspaceTest`.
2. **Contract creation opened a DB transaction inside a controller**, violating
   the one-writer/architecture rule. The transaction moved into
   `EmploymentContractService::open()` via an `afterOpen` callback so the contract
   row and its creation version still commit together.
3. **Four recruitment endpoints had controller methods but no routes** — the new
   workspace would have 404'd. Routes added, then registered in the endpoint
   matrix, the legacy-route ledger and OpenAPI, with `openapi:generate` re-run.
4. **Eight write operations had no page action** (departments, role rename/delete,
   recruitment requisition update/close, candidate stage). Registered with their
   capabilities, host pages and required APIs in both the frontend registry and
   the backend permission catalog.
5. **Seven form routes were modelled as pages**, contrary to ADR-0035. Converted
   to actions on their workspaces; the cross-module tab bar in `PeopleWorkspace`
   was removed at the same time.

## Carried forward (owned by later volumes / documented backlog)

Recorded in `.superpowers/sdd/artifacts/v3/backlog.md`; none blocks this volume:

- `GET /v1/employees/export` does not exist.
- Document projection lacks uploader and upload time.
- No delete endpoint for versioned sub-profiles.
- Change-request submit does not attach a workflow case.
- Contacts have no effective-date fields.
- Position list lacks server-side page/sort parameters.
- Travel estimated cost and overtime compensation preference are not persisted.
- Notice retraction has no control in the reader workspace.
- Performance has no cycle-detail route, no review PATCH, no per-goal score
  columns (recorded by Task 13).
- Training lacks program update/publish/close and enrollment cancel routes;
  recruitment candidate list needs retargeting to `RecruitmentController`.
- Server-side audit filters/export, calendar version history and notification
  channel preferences are not implemented (Task 17).

## Volume 3 exit checklist

- [x] All 17 implementation tasks merged.
- [x] Every new workspace reachable from the registry (155 pages, no orphans).
- [x] No form route modelled as a page; no cross-module tab bar.
- [x] No raw `table`/`input`/`select` in page components.
- [x] No English enum, API path, table name or dev marker in user copy.
- [x] Every new `/v1` route capability-guarded, documented and in the matrices.
- [x] Money as integer minor units; no PHP floats introduced.
- [x] Backend and frontend suites green; all quality gates green.
- [x] Real E2E specs written and collected (execution blocked by environment).
