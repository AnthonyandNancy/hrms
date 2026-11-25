# HRMS V1.0 — Subagent-Driven Progress Ledger

Plan source: docs/HRMS_V1.0_实施计划_30周路线图.md (approved v1.1)
Solution source: docs/HRMS_V1.0_解决方案_第一性原理重构.md (approved v1.1)
Baseline commit: 227ddee (document baseline)

## Environment facts (verified)

- PHP 8.4.28 portable at `.tools/php/php.exe` (bcmath, pdo_sqlite, pdo_mysql, intl, mbstring, openssl, curl, zip enabled).
- Composer 2.11.2 at `.tools/composer2.phar`; `COMPOSER_HOME=.tools/composer-home`.
- Node 24.19.0; pnpm 10.34.5.
- Laravel 13.17 skeleton installed at `backend/`, `php artisan test` green.
- MySQL not installed locally; Docker engine is running but daemon previously unavailable.
- All three source documents plus `docs/README.md` and `docs/appendix_e.md` are committed and treated as authoritative requirements.

## Execution boundary

This is a **reference implementation of the documented architecture and P0 vertical slices**, not a 30-week production rollout. Real IdP credentials, production payroll data, HA infrastructure, penetration testing, and named business signatories are organizational gates that cannot be fabricated in code.

## Delivery tasks

- Task 1: Engineering baseline + platform (health, response envelope, request-id, errors, audit, outbox, idempotency, module boundaries, OpenAPI, CI).
- Task 2: IAM + capability×anchor authorization + sessions/MFA/break-glass.
- Task 3: Organization + Person/Employment/Assignment + as-of queries + terms/contracts.
- Task 4: Case/Decision/Effect + workflow + idempotent effects + outbox.
- Task 5: Lifecycle (onboarding, transfer, offboarding, contract renewal) + Talent thin slices.
- Task 6: Time/attendance/business calendar/leave ledger + reconciliation.
- Task 7: Payroll gross-to-net, cumulative withholding, snapshot, correction, parallel check.
- Task 8: Self-service/reporting/export/notices + Vue 3 frontend.
- Task 9: Migration/cutover tooling + seed data + deployment assets.
- Task 10: Joint audit, integration/E2E/security/perf verification, acceptance evidence (honest pass/gate status).

## Review ledger

(no tasks complete yet)

---

# Plan 2: superuser coverage & production baseline (2026-11-24)

Plan source: docs/superpowers/plans/2026-11-24-superuser-coverage-and-production-baseline.md
Design source: docs/superpowers/specs/2026-11-24-superuser-coverage-and-production-baseline-design.md
Baseline commit for this plan: f688568 (docs approved)
Order: 1-11, 13, 12, 14-19 (Task 13 before Task 12 acceptance)

## Review ledger

Task 1: complete (commits f688568..7c12de7, review clean)

## SDD workspace baseline (2026-11-24)

- Isolated worktree: `.worktrees/superuser-baseline`, branch `feat/superuser-coverage-baseline`, based on `de00388` (Task 1 complete on main).
- Worktree dependencies installed independently (backend vendor, frontend node_modules).
- Baseline before Task 2: backend `728 passed (35,740 assertions)`; frontend `32 passed`.
- Human decisions: (1) use isolated worktree; (2) Task 5 replaces the plan's empty `assertTrue(true)` SoD test with a real object-level self-approval test; (3) `hrms:install` keeps the plan's `--admin-password` option (documented deviation from design D4 stdin wording).
- Execution order: 2-11, 13, 12, 14-19 (Task 13 before Task 12 acceptance).
Task 2: complete (commits de00388..cdfa615, review approved; added constrain/fields regression tests)
Task 3: complete (commits cdfa615..0421d7e, review approved after test fixes; backend 739 green)
Task 4: complete (commit 0421d7e..5a71423, review approved; backend 743 green). Minor backlog: projection tests bypass production auth middleware; overlap/foreign-tenant tests lack no-write snapshots; OpenAPI contract test does not assert required data.wildcard; canonical docs/openapi/openapi.yaml still stale (merge-coordination).
Task 5: complete (commits 5a71423..d02ef4f, two commits; reviewer subagents died at runtime 3x, controller performed diff review + mutation checks). Verified: removing the direct-binding acknowledgement guard makes the 422 test fail; removing ApprovalService::assertSod makes both object-level self-approval tests fail (HTTP returned 200 instead of 409). Focused 33 green; full 754 passed / 36,545 assertions; Pint, PHPStan, IAM OpenAPI lint green. Note: pnpm openapi:check is structurally unavailable on this branch (Task 1B openapi.yaml absent). docs/02-permissions.md SoD section updated early (nominally Task 18) and must be preserved.
Task 6: complete (commit d02ef4f..c6506f6, controller-implemented after subagent runtime failures; local review + mutation check). MfaPolicy filters tenant/principal/auth_version/validity via shared Clock, P2/P3 or wildcard grants and privileged users require MFA; MeController and MfaService delegate. Unit 6/6; MfaTest 6/6; full 760 passed / 36,555 assertions; Pint/PHPStan green. Existing MFA tests required no weakening.
Task 7: complete (commit c6506f6..214fe7d; controller-implemented). Real MFA login challenge: new sessions columns, pre-session with one-time challenge hash, 202 MFA_REQUIRED + no cookie, public rate-limited POST /v1/auth/mfa/challenge exchange, PrincipalResolver rejects incomplete challenges, canonical+IAM OpenAPI updated, privileged factory creates a real TOTP factor, loginAs completes challenge only when a factor exists. Full backend 765 passed / 36,602 assertions; Pint/PHPStan/capability:audit/arch/OpenAPI lint/frontend 32 green. Also fixed pre-existing wall-clock SLA bug in TaskController (verified failing on main baseline).
Task 8: complete (commit 8cdee63). iam:verify-mfa-enrollment command + real TOTP in demo seed + gate; invariant tests 4.
Task 9: complete (commit d608751). FieldSet restrictedVisible + authorizer wiring + P3 audit; field tests 14.
Task 10: complete (commit be4dae5). payroll run/payslip reads projected; 3 projection tests + existing payroll green.
Task 11: complete (commit 05dba5d). time:seed-calendar idempotent; 3 tests.
Task 13: complete (commit 3e09ca2). iam:bind-role with 30/366-day ceilings + idempotency; 5 tests.
Task 12: complete (commit 81cc9a3). hrms:install idempotent, one SYSTEM_ADMIN with real TOTP, zero business data; 3 tests (workflow count assertion corrected to required codes).
Task 14: complete (commit da16035). docker_up.sh defaults to hrms:install, --demo opt-in, no seed || true.
Task 15/16: complete (commit 1fcad8a). GET /v1/access-review + real admin aggregation + tenant names; demo UI/README removed; VITE_API_MODE guard; production bundle mock-free.
Task 17: complete (commit b13cdd8). G-COV-1/2/3 in capability:audit; coverage tests 4.
Task 18: complete (commit 1e5a5c4). ADR-0016/0017, ADR-0004/0013 narrowed, docs/02-permissions and index updated.
Final fixes: af3c106 (module contracts for installer/calendar + MeController) and ef23c67 (PHPStan/format).
Final verification: backend 793 passed / 36,872 assertions; frontend typecheck+lint+33 unit+build green; dist clean; demo wording scan clean; Pint/PHPStan/capability:audit/arch:test/OpenAPI lint green. Docker 29.9.2 available; real-stack run pending.
Final review: initial verdict "No" with 2 Critical + 16 Important. Fixed Critical (frontend MFA challenge; wildcard cross-entity scoping) and all high-risk Important (access-review tenant scope, payroll run detail amounts, atomic challenge consumption, bind-role tenant/idempotency, seeder invariant, installer inputs/report, canonical OpenAPI, admin normalisation/bindings, demo UI removal, lazy dev mock). Final gates: backend 800 passed / 36,891 assertions; frontend 34 unit + typecheck + lint + http build; verify_release --quick PASS=22 FAIL=0 SKIP=2; real Docker stack install PASS with login 202 and users=1/employments=0/runs=0/factors=1. Remaining review items are Minor/deferred or explicitly documented organizational gates.

## Round 13 — superuser baseline merged to main (2026-11-25)

- `feat/superuser-coverage-baseline` fast-forwarded into `main` at `3b441b8`.
- Three follow-up commits carried the round-13 work:
  `922b07f` (day-window binding validity + wildcard expansion + tasks/my session gate),
  `ab39b30` (frontend contract normalisers, org/payroll anchors, demo affordance removal),
  `3b441b8` (version 1.0.0, docs and release-gate wording).
- Verified on merged `main`: backend 818 passed / 37,064 assertions.
- Stale branches still outstanding (see the merge report): `feat/task-2-iam`,
  `feat/task-6b-time-api`, `feat/task-7b-payroll`, `feat/task-9-ops`.
