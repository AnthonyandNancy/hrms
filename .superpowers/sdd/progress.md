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

---

# Plan 3: FactoryHR V2.1 full remediation (2026-11-26)

Plan source:
- docs/superpowers/specs/2026-11-26-factoryhr-v2-1-full-remediation-design.md
- docs/superpowers/plans/2026-11-26-v2-1-remediation-master-plan.md
- Volumes 1-4 (2026-11-26-v2-1-volume-*.md)

## Status
- 2026-11-26: user approved spec + four-volume plan; order 1→2→3→4, each volume closed and reviewed before the next.
- Planning baseline commit on main: 4fadd0c (approval status recorded).
- Isolated worktree: .worktrees/v2.1-remediation, branch feat/v2.1-remediation, based on 4fadd0c.
- Pre-flight plan review: 10 findings.
  - 4 Critical: frozen PageSpec dropped fields; action-manifest pipeline unbuildable; single-company drop list 13 of 139 tables; runtime legacy sweep under-scoped.
  - 6 Important: Volume 2→3 dependency cycle; ADR acceptance ordering; wrong endpoints; unpassable tests; wrong operational commands; red-gate contradiction.
  - Resolution: plans revised (not product decisions). Independent re-review pending.
- Baseline before Volume 1:
  - backend (PHPUnit direct, memory_limit=1G): 1123 tests, 51283 assertions, 1 failure = Contract\NoTenantNoLegalEntityTest (expected pre-cutover).
  - Artisan test wrapper OOM at 128M (environment limit); direct PHPUnit is the recorded method.
  - Pint --test: PASS (1168 files).
  - PHPStan: OK, no errors.
  - capability:audit: OK (6 public allowlisted routes).
  - arch:test: OK, 4 non-blocking dynamic-table warnings.
  - Frontend typecheck: PASS. Frontend lint: PASS.
  - page-components / frontend-modules / action-manifest / openapi-lint / openapi:validate (2 warnings): PASS.
- Environment: PHP 8.4.28 at .tools/php/php.exe; Node 24.19.0; pnpm 10.34.5; Laravel 13.17; SQLite in-memory for tests.
- Note: AGENTS.md line 10 still says "legal entity's timezone"; Volume 1 Task 1 Step 6 changes it to single company profile. Do not treat pre-change wording as authoritative for V2.1.

## Review ledger
(no V2.1 tasks complete yet)

## Pre-flight review history (2026-11-26)
- Round 1: 10 findings (4 Critical, 6 Important). Patched plan set.
- Round 2 verification: 8 partial + 12 new findings (2 Critical: unique-index loss, non-autoloadable/MySQL-only preflight).
- Round 3 patch: single-company unique rebuild, autoloaded driver-neutral preflight, explicit scan roots, generated TS action module, real Playwright config, single-company gate, helper contracts, real endpoints.
- Round 3 independent verification: in progress before Task 1 dispatch.
- 2026-11-26 user decision: system not live; migration may delete all data and rebuild. Volume 2 Task 2 is now schema-only cutover + `migrate:fresh --seed`; no duplicate resolution or data preservation. Unique indexes must still be rebuilt; a snapshot restore drill remains.
Task 7: complete (commit 1b6d7d8, reviewer approved). Backlog:
  - FormDialog/FormDrawer have no interaction tests (v-model + confirm contract).
  - PageShell re-implements PageHeader instead of composing it; only one may survive adoption.
  - dictionary employeeStatus omits ON_LEAVE and includes non-contract ARCHIVED; fix before wiring into employee list.
  - PageShell actions slot renders an empty flex item when no slot passed.

## Volume 1 batch-fix backlog (do at volume close, not per task)
- Task 3: hasWrittenUserApproval() is fail-open on negated text ("用户未批准：…"). Require a strict positive approval marker/record and add negative regression tests.
- Task 3 (minor): add tests for assertExpectedCounts / required mappings / stale-decision rejection; reject blank registry fields; derive the 140 count from the V1.1 inventory rather than layer=business.
- Task 7: FormDialog/FormDrawer interaction tests; PageShell vs PageHeader duplication; dictionary ON_LEAVE/ARCHIVED drift.
- Task 2: confirm the follow-up summarizeFailedGates commit is present and the tree is clean.
- Task 1: confirm refix verdict covers all four ADRs, page-model invariant, MFA narrowing, IAM numbering.
Task 2: complete (commits 9d14679 + 98c4348). Baseline evidence records: backend 1123 tests / 51283 assertions / 1 expected failure (NoTenantNoLegalEntityTest); frontend unit gate red (pageComponentUniqueness.spec.ts syntax error + stale generated api types) — pre-existing, must be fixed before Volume 1 exit; Docker/real stack NOT RUN.
Task 8: complete (commit 6750ccf, fixture tests green; real-repo gates exit 1 with documented baseline: page-model 12 failures, ui-primitives and copy counts recorded in docs/audit/2026-11-26-v2-gate-baseline.md). Not claimed as passing.

## Volume 1 task ledger (as of 2026-11-26 18:05)
- Task 1: complete (4c6886e + e523c82 fix; ADR files exist, six sections verified; refix still listed 2 normative contradictions to resolve at volume close).
- Task 2: complete (9d14679 + 98c4348).
- Task 3: complete (2f8c464; 160/160 routes, 140/140 capabilities, 0 deprecate). Backlog: approval predicate fail-open; add invariant tests.
- Task 4: in progress (retry2).
- Task 5: complete (0388ab6; generator+audit 13/13; parity 1 pass/17 assertions; 3 page actions; 170 gaps each owned).
- Task 6: in progress.
- Task 7: complete (1b6d7d8) with backlog.
- Task 8: complete (6750ccf; real gates red by design, baselined).
- Task 9: runner complete (4baaa72 + e811cb0 all node tests 70/70); acceptance must be regenerated after Task 4/6 land.
- Pre-existing frontend gate fixed: pageComponentUniqueness now 15/15 (334c68f, e811cb0).

## Volume 1 CLOSED (2026-11-26)
- Whole-volume review verdict NEEDS FIXES with 5 blockers; all 5 fixed:
  1. Generated action manifest wired into registry via actionsForPage(); no second hand-written list; freshness check added and proven by hand-edit (exit 1) and restore (exit 0).
  2. Manifest reconciled: 15 real page actions across 6 pages, 158 explicitly owned gaps; duplicate API ownership resolved; registry requiredApis mirrored from the backend catalog for the 4 hosted pages.
  3. Single-company preflight path unified at App\Console\Commands (guards, Volume 2 plan, tests); false-positive violations removed (1929 -> 1927 legacy hits).
  4. Acceptance record rebuilt with 冻结接口 / 已知失败与归属 / 环境探测 / 严格结论 sections.
  5. Master §3.1 now points at the real dependency-free pageModel.ts contract; master index marks Volume 1 ✅.
- Final acceptance: YES — Volume 1 contracts closed; carried gates owned by Volume 2/3.
- Carried red gates (each owned): backend-phpunit + backend-contract (NoTenantNoLegalEntityTest, V2 Tasks 2/3); audit_page_model / audit_ui_primitives / audit_user_copy (V2 Tasks 6-8, V3 Tasks 2-17).
- Non-blocking backlog: FormDialog/FormDrawer interaction tests; frontend-registry ↔ backend-catalog requiredApis parity test; exact failing-test-ID reporting in the acceptance runner.

## Volume 2 task ledger (as of 2026-11-26 23:55)
- Task 1: complete (5c9f8b5; preflight command + single-company gate; gate baseline 1927 legacy hits recorded honestly).
- Task 2: complete (7ae663e; SQLite + MySQL 8.4; 171->0 legacy columns; 74 legacy unique indexes -> 72 rebuilt; in-place + fresh both proven).
- Task 3: complete (13 parallel module sweeps + shared/core contracts; production code 0 hits; strict scanner green with only the 2 marked preflight lines).
- Task 4: complete (API + UI contract cleanup; NoTenant 0 hits; openapi-lint/validate green).
- Task 5: complete (7031473; 19 actions / 155 owned write gaps / 14 owned capability gaps; deterministic hashes; service validation).
- Task 6: complete (9ad51c0 user workbench; 16 frontend tests; last-admin guards delegated to AdminProtectionGuard).
- Task 7: complete (8eb0efb + retry; role workbench 8 tests; PUT /v1/roles/{id}/authorization added server-side).
- Task 8: complete (c255074; four governance pages + write APIs declared; permission-read guard fixed to iam.permission.read).
- Task 9: complete (faf12bc; auth_version consistency, session invalidation, last-admin protection; integrated into UserAdminController).
- Task 10: complete (d361324; password-only login, MFA default off, no capability bypass).
- Task 11: complete (c5572b9; RoleMatrixTest 7 tests / 69 assertions; 3 product findings owned by V3).
- Task 12: complete (6c703a4 + 68fc665; real MySQL 8.4 rehearsal, 156/171 -> 155/0, restore 155/0, migration suite 7/7 639 assertions).
- Gates at close: backend 1184 tests / 57606 assertions OK; pint PASS 1179 files; phpstan OK; capability:audit OK; arch:test OK; frontend 50 files/348 tests; typecheck+lint PASS; openapi-lint 252/324 OK; openapi:validate valid (2 pre-existing warnings); actions:check OK.

## Volume 2 REVIEW — APPROVED (2026-11-27 00:35)
- Blocking checks B1-B6 all PASS: no authorization bypass (no privileged short-circuit, COMPANY still requires anchor_id null, MFA middleware never bypasses capability); schema migrations forward-only/idempotent with index rebuild derived from Schema::getIndexes; action manifest wired via actionsForPage with byte-exact drift detection; password login with MFA off does not bypass authz; no secrets/PII/SQL in range.
- Evidence-honesty scare resolved: NoTenantNoLegalEntityTest appeared red only when invoked from the MAIN checkout (two Composer autoloaders). Run inside the worktree backend/: OK (4 tests, 6 assertions). Acceptance doc now states the required run context (commit 4a77ace) and both migration assertion counts (327 SQLite / 639 MySQL).
- Carried into Volume 3 (not blockers): payroll run anchor mismatch (V3 T12), LINE_MANAGER MANAGED vs ORG_UNIT (V3 T2/T5), 403-vs-404 out-of-scope disclosure (V3 T3).
- Volume 2 CLOSED. Next: Volume 3 (18 tasks), starting with Task 1 (atomic employee onboarding) then the domain workspaces.

## Volume 3 implementation (as of 2026-11-27 05:10)
- Tasks 1-17 implemented (18 remains: cross-domain acceptance).
- Backend at 1246 tests / 58,423 assertions OK; frontend 66 files / 544 tests OK; typecheck+lint+pint+phpstan+capability:audit+arch:test+openapi-lint+openapi:validate+actions:check all PASS.
- Registry 162 pages (1 new payroll run detail, 1 new workflow case detail, 7 form routes pending conversion to actions by the page-model gate fix).
- Real defects fixed along the way: PAYROLL_SPECIALIST run scope (listRuns now membership-filtered), role rename/delete endpoints, department write endpoints, 4 recruitment write routes, contract transaction moved out of the controller (arch rule), 8 page actions registered with their required APIs.
- Three product gates still red at start of cleanup: page_model 12, ui_primitives 49, user_copy 32 -> three parallel agents assigned.
