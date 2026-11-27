# Volume 3 Blockers — Independent Recheck

Date: 2026-11-27 (Asia/Singapore)
Branch: `feat/v2.1-remediation`
Worktree: `D:/code/ai/factoryHR/.worktrees/v2.1-remediation`
Fix commit: `3352259` (`fix: close volume 3 review blockers ...`)
Original review: `.superpowers/sdd/artifacts/v3/volume-3-review.md`

## Verdict

**BLOCKERS_REMAIN**

All six originally reported conditions changed. The cross-run P3 scope,
contract atomicity, candidate wiring, the stage payload contract and the CSRF
route are genuinely fixed. Two blockers remain in the fixed area:

1. the payroll amount gate ignores the binding's P2/P3 field profile, so a P2
   payslip grant receives amounts that the field projection says are hidden;
2. notice restore is only an immediate in-memory undo: the `/notices` workspace
   never loads archived notices, so restore is unreachable after a reload.

The `updateRequisition` contract mismatch recorded in the original review also
remains.

## Per-blocker results

| id | result | evidence |
| --- | --- | --- |
| V3-B1 | **PASS for the reported cross-run P3 leak** (new profile bug below) | `PayrollRunController.php:58-79` builds `$amountsByRunId` per item; `:84-93` gates `show()` per run; `:116-127` calls `authorizer->allows(..., AnchorRef::payrollRun($runId), today)`. `canSeeAmounts` is absent from application code; `rg` only finds a stale comment in an unchanged test. `PayrollWorkspaceTest.php:274-309` binds P3 only on run A, binds P2 run-read only on run B, installs the real `CapabilityAuthorizer` (`:233-247`), and asserts run B list/detail omit money. Focused run: `OK (1 test, 6 assertions)` in 2.956s. |
| V3-B2 | **PASS** | `EmploymentContractService::open()` accepts `?callable $afterOpen` (`EmploymentContractService.php:51`) and invokes it inside `DB::transaction` before returning (`:53`, `:95-97`). `EmploymentContractController::store()` passes the ledger append as that callback (`EmploymentContractController.php:71-100`) and contains no `DB::` (`rg` finds none). A temporary failure-injection probe that threw from the callback left zero `employment_contracts` rows: `OK (1 test, 2 assertions)`; the probe was removed after the run. |
| V3-B3 | **PASS** | `modules/talent/pages/index.ts:18-21` maps `recruitment/candidates/index`, `by-id` and `pipeline` to the wrappers. The wrappers resolve to `recruiting/pages/CandidateWorkspacePage.vue` (`CandidateListPage.vue:4,8`; `CandidatePipelinePage.vue:4,8`) and `recruiting/pages/CandidateDetailPage.vue` (`CandidateDetailPage.vue:4,8`), not `TalentWorkspacePage`. Focused talent suite: `28 passed`. |
| V3-B4 | **PASS for the requested stage contract; one original mismatch remains** | OpenAPI stage body is `required: [status]`, enum `NEW,SCREENING,INTERVIEW,OFFER,OFFER_ACCEPTED,REJECTED`, `reason` maxLength 500 (`docs/openapi/openapi.yaml:15910-15935`). The controller requires `status` with max 24, `reason` max 500 (`RecruitmentController.php:203-206`), and validates against the same six-value `MOVABLE_CANDIDATE_STAGES` (`:35-42`, `:466-474`). The spec does not state a status maxLength, but all enum values are shorter than 24, so this is not a payload break. Remaining original mismatch: `/v1/recruitment/requisitions/{id}` documents `status` with enum `DRAFT,OPEN,PAUSED,CLOSED` (`openapi.yaml:16163-16187`) while `updateRequisition` neither validates nor applies `status` (`RecruitmentController.php:108-131`, `:378-405`). |
| V3-B5 | **FAIL (partial)** | Controls and API calls exist: `NoticesPage.vue:82-102` calls `archiveNotice`/`restoreNotice`; controls are at `:259-280`; `reporting/api.ts:814-835` POSTs `/archive` and `/restore` with idempotency keys; the named test (`platformPages.spec.ts:529-584`) asserts both POSTs and idempotency keys and passes. But restore is not durably reachable: `NoticeService::list()` excludes `ARCHIVED` from both public and `managed` filters (`NoticeService.php:102-113`), so an archived notice disappears on reload. No frontend code calls `GET /v1/engagement/notices/archive` (`rg` finds none). The restore button at `NoticesPage.vue:270-280` can only appear for a row whose status was changed to `ARCHIVED` in the same component instance (`:94`). The test mocks a PUBLISHED list and an ARCHIVED archive response, so it proves the immediate transition, not restoration of an already archived notice. |
| V3-B6 | **PASS** | `routes/api.php:268-271` adds `csrf.header` to `POST /v1/employments/{employmentId}/contracts`. Live `artisan route:list --path=employments -v` reports: `api`, `auth.session`, `csrf.header`, `mfa.enrolled`, `capability:people.contract.manage`. |

## New blockers found in the fix diff / newly reachable pages

### NEW-P1 — payroll amount gate ignores the field-profile ceiling

`PayrollRunController::index()` computes a `FieldSet` with
`moneyFieldsForRunId()` (`:67`) but discards it. `canSeeRunAmounts()` (`:116-127`)
then asks only `Authorizer::allows()`, which checks capability and anchor but does
not inspect the binding's P2/P3 field profile. The production authorizer hides
P3 money under a P2 profile (`CapabilityAuthorizer.php:209-247`), and a P2
`payroll.payslip.read` grant is a valid role-admin configuration (P3 is the
capability maximum; `RoleAdminService.php:305-313`). A P2 payslip reader
therefore receives list/detail money even though the projection says it is
hidden.

A temporary probe bound only `payroll.payslip.read` at `P2_SENSITIVE` on a run
and installed the real `CapabilityAuthorizer`; the list row still contained
`gross_cents`:

```text
FAIL ... P2 payslip grant must not reveal list amounts
Failed asserting that an array does not have the key 'gross_cents'.
Tests: 1, Assertions: 3, Failures: 1.
```

The gate should derive visibility from the returned field set (for example
`isVisible('gross')`/`net`) or otherwise enforce the effective field profile,
not capability presence alone. The regression test only exercises a P3 binding
and does not cover this case.

### NEW-P2 — payroll mutation responses still expose amounts without P3

`PayrollRunController.php:193,201,212,222,231,240,257,265,274,292,310` call
`runDetail($run)` with the default `includeAmounts = true`
(`PayrollRunService.php:211-251`). A lifecycle actor on a run without
`payroll.payslip.read` can receive amounts from create/lock/calculate/review/
approve/publish/mark-paid/close/cancel/reopen/correction responses. This
predates `3352259` in this form, but it is the same amount-gating boundary and
should be fixed with NEW-P1.

### NEW-P3 — candidate detail is limited to the first 100 candidates

The newly wired `CandidateDetailPage.vue` reads `listCandidates({}, 1, 100)`
(`:116`) and selects the route id client-side (`:140`). A valid candidate id
outside the first 100 therefore renders “not found” from the production
`/recruitment/candidates/:id` route. The same first-100 limit exists in the
candidate list workspace. This is pre-existing page logic made production-
reachable by the wrapper fix, so it is recorded as a newly reachable functional
blocker rather than a regression in the wrapper itself.

## Gate outputs observed

Backend:

```text
phpunit --colors=never
  OK (1247 tests, 58382 assertions)   exit 0   (1:35.215, 368 MB)

pint --test (first run)
  FAIL 1190 files, 2 style issues
  tests/Feature/Payroll/PayrollWorkspaceTest.php  line_ending
  tests/Support/PayrollTestCase.php               line_ending
  Cause: worktree copies had CRLF; committed blobs are LF (`git hash-object`
  matches the 3352259 blobs, `git cat-file` contains 0 CR bytes).

pint --test (after normalizing only worktree CRLF -> LF; no content change)
  PASS 1190 files

phpstan analyse --no-progress
  [OK] No errors

artisan capability:audit
  Capability audit OK. Public routes (allowlist): 6

artisan arch:test
  Module boundaries OK. Warnings (non-blocking): 4
```

Frontend:

```text
pnpm typecheck    exit 0
pnpm lint         exit 0 (eslint --max-warnings=0)
pnpm test:unit    66 files / 546 tests passed, exit 0
```

Root:

```text
node deploy/scripts/openapi-lint.mjs
  openapi-lint: OK (259 paths, 334 operations, 3.1.0)
pnpm openapi:validate
  valid; 2 warnings (health/live and health/ready have no 4XX response)
pnpm actions:check
  OK (155 pages, 16 write pages, 15 pages with actions, 34 actions,
      149 explicit write gaps, 14 owned capability gaps, 1 public write exception)
```

Focused evidence runs:

```text
PayrollWorkspaceTest::test_p3_on_one_run_does_not_reveal_another_runs_amounts
  OK (1 test, 6 assertions)
platformPages.spec.ts -t "archives and restores a notice ..."
  1 passed / 35 skipped
EmploymentContractApiTest|ContractWorkspaceTest
  OK (8 tests, 73 assertions)
NoticeGovernanceTest
  OK (5 tests, 46 assertions)
talentPages.spec.ts
  28 passed
```

## Residual / out-of-scope observations

- `EmploymentContractService.php:40-50` and
  `tests/Support/PayrollTestCase.php:66-75` each contain a duplicated docblock
  left by the fix. Pint accepts them; not a functional blocker.
- The worktree still has the pre-existing untracked
  `.superpowers/sdd/artifacts/**` files; no product file was modified by this
  recheck.
- The test database is SQLite in memory; the contract rollback probe therefore
  proves Laravel transaction semantics on SQLite, not MySQL-specific behavior.

VERDICT: BLOCKERS_REMAIN — NEW-P1 payroll P2 field-profile disclosure; V3-B5 restore not durably reachable; V3-B4-R1 updateRequisition documents an ignored status field; NEW-P2 amount-bearing mutation responses; NEW-P3 candidate detail first-100 limit.
