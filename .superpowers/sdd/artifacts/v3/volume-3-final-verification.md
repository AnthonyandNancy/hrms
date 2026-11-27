# Volume 3 — Final Blocker Verification

Date: 2026-11-27 (Asia/Singapore)
Branch: `feat/v2.1-remediation`
Worktree: `D:/code/ai/factoryHR/.worktrees/v2.1-remediation`
Fix commits reviewed: `3352259`, `d7ee097`
Prior findings: `.superpowers/sdd/artifacts/v3/volume-3-blocker-recheck.md`

## Verdict summary

**BLOCKERS_REMAIN** — NEW-P2 is only partially fixed.

`d7ee097` correctly closes NEW-P1, V3-B5, and the requested NEW-P3 wiring, and
does not weaken access controls. However, `PayrollRunController::calculate()`
still calls `runDetail()` without an amount-visibility argument and also spreads
the calculation totals (gross/deductions/net/employer cost) directly into the
response. A P2 `payroll.run.prepare` holder therefore still receives payroll
amounts from the calculate mutation.

## Per-item results

| id | result | evidence |
| --- | --- | --- |
| NEW-P1 | **PASS** | `PayrollRunController.php:66-70` resolves the per-run payslip `FieldSet` and gates each list row with `isVisible('gross') && isVisible('net')`; `:93-96` does the same for show. The temporary real-authorizer probe bound `payroll.payslip.read` at `P2_SENSITIVE` and asserted the listed row had no `gross_cents`/`net_cents`; it passed with `OK (1 test, 4 assertions)`. Probe removed afterwards; no test-file diff remains from this verification. |
| NEW-P2 | **FAIL** | 12 `runDetail(` calls exist. 11 are field-set-gated: show at `:96` passes `$includeAmounts` from `moneyFields()` (`:93-94`), and 10 mutation responses pass `amountsVisibleFor()` (`:190,198,219,228,237,254,262,271,289,307`). The calculate branch at `PayrollRunController.php:201-210` is ungated: `:209` calls `runDetail($run)` with the default `includeAmounts = true` (`PayrollRunService.php:211`), and `:207-210` also spreads `$result` containing `gross_cents`, `deductions_cents`, `net_cents`, `employer_cost_cents` from `CalculationService.php:149-152,175`. Count: **11 gated / 1 ungated**. The OpenAPI `PayrollCalculationResult` also requires these amount fields (`docs/openapi/openapi.yaml:4436-4450`), so the contract must be gated or revised with the code. |
| NEW-P3 | **PASS** | `RecruitmentController.php:158-164` validates `candidate_id`; `:184-187` applies `whereKey`. `CandidateDetailPage.vue:116-118` calls `listCandidates({ candidateId: candidateId.value }, 1, 1)`, and `recruiting/api.ts:449-452` sends `candidate_id`. Focused regression output: `OK (1 test, 6 assertions)`. Caveat: the regression route is test-only (`RecruitmentWorkspaceTest.php:46`); the live `GET /v1/recruitment/candidates` route is `RecruitmentFunnelController@candidates` (`routes/api.php:868`), which ignores `candidate_id` and pagination and returns the unpaginated candidate set. That leaves residual OpenAPI/filter contract drift, but no first-100 production cutoff. |
| V3-B5 | **PASS** | `NoticeService.php:102-118` includes `Notice::STATUS_ARCHIVED` in the managed branch, while non-managed filters still require PUBLISHED and `publish_at <= now`. `NoticeGovernanceTest.php:206-211` asserts the archived notice is returned by `?filter=managed`; focused run `OK (1 test, 15 assertions)`. `NoticesPage.vue:57-63` loads the managed filter and `:270-280` renders restore for ARCHIVED rows. |
| V3-B2 | **PASS** | `EmploymentContractService.php:51-53` opens `DB::transaction`; the `afterOpen` callback runs at `:95-97`, before return. `EmploymentContractController.php:68-100` passes the contract-version ledger append as that callback and opens no controller transaction. Focused `EmploymentContractApiTest` + `ContractWorkspaceTest`: `OK (8 tests, 73 assertions)`. |
| V3-B6 | **PASS** | `routes/api.php:268-271` keeps `csrf.header`, `mfa.enrolled`, and `capability:people.contract.manage` on `POST /v1/employments/{employmentId}/contracts`. Live `artisan route:list --path=employments -v` shows exactly those middleware for `employments.contracts.store`. |
| d7ee097 audit | **PASS** | Product diff only tightens the payroll gate, adds a candidate-id predicate under the existing `recruitment.candidate.read` check, and adds ARCHIVED only to the managed query whose rows still pass `NoticeAccessPolicy::canRead`/`canManage`. No route guard, middleware, capability check, or test assertion was removed or relaxed; the only test adjustment explicitly opts an arithmetic test into P3. The one surviving gap is the pre-existing/partially addressed NEW-P2 calculate path above. |

## NEW-P1 probe output (temporary, removed)

```text
..\.tools\php\php.exe -d memory_limit=1G vendor/bin/phpunit --colors=never \
  --filter test_probe_p2_payslip_grant_does_not_reveal_list_amounts \
  tests/Feature/Payroll/PayrollWorkspaceTest.php

.                                                                   1 / 1 (100%)

Time: 00:04.345, Memory: 64.00 MB

OK (1 test, 4 assertions)
```

The probe used `FakePayrollRunMembershipDirectory`, the real
`CapabilityAuthorizer`, a `payroll.run.read` P2 binding, and a
`payroll.payslip.read` P2 binding on the same run. It failed before `d7ee097`
per the prior report and now passes. It was deleted after evidence capture.

## Focused runs

```text
test_candidate_detail_lookup_by_id_works_beyond_the_first_page
  OK (1 test, 6 assertions)

test_templates_archive_and_restore_use_real_persisted_state
  OK (1 test, 15 assertions)

EmploymentContractApiTest + ContractWorkspaceTest
  OK (8 tests, 73 assertions)

test_p3_on_one_run_does_not_reveal_another_runs_amounts
  OK (1 test, 6 assertions)
```

## Full gates

```text
backend phpunit --colors=never
  OK (1248 tests, 58394 assertions)                       exit 0  (2:32.029, 368 MB)

pint --test
  PASS 1190 files                                         exit 0

phpstan analyse --no-progress
  [OK] No errors                                          exit 0

artisan capability:audit
  Capability audit OK. Public routes (allowlist): 6       exit 0

artisan arch:test
  Module boundaries OK. Warnings (non-blocking): 4        exit 0

frontend pnpm typecheck                                  exit 0
frontend pnpm lint                                       exit 0
frontend pnpm test:unit
  66 files / 546 tests passed                             exit 0

node deploy/scripts/openapi-lint.mjs
  openapi-lint: OK (259 paths, 334 operations, 3.1.0)     exit 0

pnpm openapi:validate
  valid; 2 warnings (health/live, health/ready no 4XX)     exit 0

pnpm actions:check
  OK (155 pages, 16 write pages, 15 pages with actions,
      34 actions, 149 explicit write gaps, 14 owned
      capability gaps, 1 public write exception)          exit 0
```

## Worktree note

At verification time the worktree also contained a pre-existing uncommitted
4-line test-support change in `backend/tests/Support/PayrollTestCase.php`
(`forgetInstance(PayrollRunService::class)` after membership registration). It
does not widen production access and was present for the gate runs. It is not
part of either fix commit and was not included in this report commit.

VERDICT: BLOCKERS_REMAIN — NEW-P2: `PayrollRunController.php:201-210` calculate response still returns run amounts ungated (and top-level `gross_cents`/`deductions_cents`/`net_cents`/`employer_cost_cents` from `CalculationService`), so a P2 `payroll.run.prepare` holder can still receive money.
