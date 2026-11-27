# FactoryHR V2.1 — release checklist

Date: 2026-11-27
Branch: `feat/v2.1-remediation`
Base commits: gate matrix `20ce996`, backup evidence `cb8b630`, perf `27fc725`

This checklist is what must be true before the system is declared releasable.
Every line is either ticked with evidence or left unticked with the reason.

## A. Volume closure

| # | Item | State | Evidence |
| --- | --- | --- | --- |
| A1 | Volume 1 closed and reviewed | ✅ | `docs/audit/2026-11-26-v2-volume-1-acceptance.md` |
| A2 | Volume 2 closed and reviewed | ✅ | `docs/audit/2026-11-26-v2-volume-2-acceptance.md` |
| A3 | Volume 3 closed and reviewed | ✅ | `.superpowers/sdd/artifacts/v3/volume-3-signoff.md` (`SIGNOFF: VOLUME_3_APPROVED`, commit `279a494`) |
| A4 | Volume 4 Tasks 1–6 implemented | ✅ | Task 1 `db588e7`, Task 3 `b599e87`, Task 4 `b866296`, Task 6 `bd5829e`, Tasks 2+5 `9e9de01` |
| A5 | Volume 4 Task 7 (retire shared shells) | ❌ | see B1 below |
| A6 | Volume 4 Task 8 (full gate matrix) | ✅ | `docs/audit/2026-11-26-v2-full-gates.md`, 22/22 gates |
| A7 | Volume 4 Task 9 (real stack + five-role UAT) | ❌ | not executed — see B2 |
| A8 | Volume 4 Task 10 (performance/concurrency/recovery) | ⚠️ partial | `docs/audit/2026-11-26-v2-performance.md` — local baselines measured, live drills not executed |
| A9 | Volume 4 Task 11 (backup/restore + rollback) | ✅ | `evidence/v21-backup/README.md` — MySQL 156 tables / 0 mismatches, rollback drill PASS |
| A10 | Volume 4 Task 12 (this checklist + final verdict) | ✅ | this file + `2026-11-26-v2-final-acceptance.md` |

## B. Open blockers

### B1 — 123 of 155 pages still render through a shared shell

| Shell | Lines | `mode ===` branches | Pages using it |
| --- | --- | --- | --- |
| `modules/platform/components/PlatformWorkspace.vue` | 4583 | **32** | 21 |
| `modules/people/components/PeopleWorkspace.vue` | 399 | 0 | 28 |
| `modules/talent/components/TalentWorkspacePage.vue` | 554 | 0 | 31 |
| `modules/timepay/legacyBridge.ts` + `views/{people,talent,timepay}` | 29 + ~6862 | 0 | 43 |

Volume 3 delivered genuine purpose-built workspaces for the employee, department,
position, movement, contract, attendance, leave, overtime/travel, payroll,
performance, recruitment, training, dashboard/report/notice and platform-admin
domains. Those are the pages an operator uses most.

What remains config-driven is the long tail: secondary reports, dashboard
sub-views, notice governance sub-views and the TimePay panels. `PlatformWorkspace`
is the genuine anti-pattern the plan targets (4583 lines, 32 branches on `mode`).

Attempts to convert those 21 pages in parallel failed three times (agents exited
without producing work). Converting them one by one is mechanical but is ~21
dedicated page implementations, each needing five states, a real endpoint, and
its own tests — that is a multi-day job, not a finalisation step.

**Decision:** do not fake it. The shell stays for now, and the checklist records
it as an open blocker against the "160 mature pages" target.

### B2 — real-stack deployment and five-role UAT not executed

No Compose stack was deployed from this worktree. The Compose project that is up
belongs to a different worktree (`page-auth-remediation`) and serves that
branch's image, so it cannot stand in as evidence for this branch.

Consequences:

- the 52 real-stack Playwright specs are **COLLECTED, not EXECUTED**;
- five-role UAT (HR_ADMIN / LINE_MANAGER / PAYROLL_SPECIALIST / RECRUITER /
  employee) has no live evidence;
- HTTP latency and failure-recovery numbers are unmeasured on this build.

## C. Technical gates (all green, reproducible)

`pnpm gate:all` → **GATE:ALL PASS (22 gates, 230.8 s)**, at commit `9e9de01`:

- backend 1278 tests / 58,488 assertions, pint 1196 files, phpstan clean
- `capability:audit` OK (6 public routes allowlisted), `arch:test` boundaries OK
- OpenAPI lint 263 paths / 338 operations, validate valid, generated client current
- actions: 155 pages / 36 actions / 149 explicit write gaps / 13 owned capability gaps
- frontend 71 files / 599 tests, typecheck clean, lint clean, production build OK
- product audits: page model, UI primitives, user copy, page components (155/155),
  route coverage, frontend modules, single-company — all exit 0

## D. Security posture

| # | Item | State |
| --- | --- | --- |
| D1 | Every `/v1` route capability-guarded except `v1/health` + `v1/meta` | ✅ `capability:audit` |
| D2 | Password login is the only required auth path | ✅ ADR-0018 / ADR-0038 |
| D3 | MFA optional, off by default, no capability short-circuit when off | ✅ ADR-0038, `AccountSecurityTest` |
| D4 | Payroll amounts gated per run by field profile (P3), not per request | ✅ fixed in `d7ee097`, regression `test_p3_on_one_run_does_not_reveal_another_runs_amounts` |
| D5 | Mutation responses do not leak payroll amounts | ✅ fixed in `e117422`, gated `calculate()` |
| D6 | Contract creation is CSRF-protected and atomic with its version row | ✅ fixed in `3352259` |
| D7 | Single company: no tenant/legal-entity columns | ✅ `audit_single_company.mjs` exit 0, `NoTenantNoLegalEntityTest` |
| D8 | No real personal data, secrets, `.env`, SQLite or vendor artifacts committed | ✅ verified by `git ls-files` scan |

## E. Sign-off required before production

- [ ] B1 closed: `PlatformWorkspace.vue` deleted, its 21 pages purpose-built
- [ ] B2 closed: stack deployed from this branch, five-role UAT executed with real status codes
- [ ] Performance: load probe executed against this build with p95 recorded
- [ ] Disaster recovery: MySQL PITR measured (RPO/RTO), not just dump/restore
- [ ] Product owner UAT sign-off on the Chinese copy and workflow behaviour

Until those are ticked, the release verdict is **NO-GO for production**, while
the functional baseline is **GO for internal/staging use** — see
`2026-11-26-v2-final-acceptance.md`.
