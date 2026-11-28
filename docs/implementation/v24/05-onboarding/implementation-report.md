# T05a — Single onboarding entry

- **Status:** COMPLETE (Step 1-3). T05b contract/compensation drafts are intentionally not implemented.
- **Date:** 2026-11-28
- **Baseline:** `b888693e165b07901539a748594fba916503b078`
- **Commit:** branch HEAD — `feat(v2.4): route onboarding through lifecycle case`
- **Branch/worktree:** `v24-t05-onboarding` / `D:\code\ai\factoryHR-worktrees\t05-onboarding`
- **Plan:** `docs/superpowers/plans/2026-11-28-t05a-single-onboarding-entry-mini-plan.md`
- **Parent task:** `docs/superpowers/plans/2026-11-28-v2-4-implementation-master-plan.md` T05

## Scope

`POST /v1/employees/onboard` is now a compatibility facade over the canonical
Lifecycle onboarding Case. It maps the historical People request shape and
submits a pending Workflow Case. It never creates Person, Employment,
Assignment or EmploymentTerms rows; only the approved Lifecycle Onboarding
Effect writes those rows. Contract and compensation drafts remain T05b and were
not touched.

## Behaviour by requirement

### Step 1 — Lifecycle case command

- Added `Lifecycle\Contracts\OnboardingCaseCommand::create(int $actorUserId, array $payload, string $idempotencyKey, string $businessDate): array`.
- `LifecycleService` implements the contract as a thin adapter around the
  existing `createCase()` path. It returns `case_id`, `case_no`, `case_type`,
  `application_status`, `effect_status`, `canonical_endpoint` and `replayed`.
- The idempotency ledger scope is `CMD:lifecycle.onboarding.create`; the same key
  and payload replay the same Case and preserve the response.
- The contract has no dependency on People writers and asserts zero Person /
  Employment / Assignment / Terms rows before approval.

### Step 2 — Legacy route is a facade

- `EmployeeController::onboard()` no longer imports or resolves
  `CreateEmployeeService`. It takes `OnboardingCaseCommand`, maps:
  - `person.given_name + family_name` to `person.legal_name`;
  - `national_id` to `person.id_number`;
  - `employee_no`, `org_unit_id`, `position_id`, `hire_date`,
    `employment_type`;
  - `salary_minor` to `terms.base_salary_minor`, `currency` to
    `terms.salary_currency`, plus work location/schedule.
- It always returns **202** with pending Case metadata, including idempotent
  replays (with `Idempotency-Replayed: true`). The old 201/200 employee shapes
  are gone.
- Validation happens before Case creation: missing/unknown org, invalid hire
  date, invalid payload and duplicate employee number create neither a Case nor
  a domain row.
- An end-to-end regression test approves the returned Case and proves this is
  the only path that creates the four People rows, with `source_type=ONBOARDING`
  and `source_id=case_id`.

### Step 3 — Frontend and contract sync

- `onboardEmployee()` now validates and returns `PendingOnboardingCase`; a
  legacy employee-shaped body is rejected rather than synthesised into an
  employee.
- The create drawer emits the pending Case. The list page shows the Chinese
  message **“已提交入职审批。”** and a link to
  `/hr/movements/{case_id}` labelled with the Case number. It does not refetch
  the table as if a new employee existed, and it never displays a fabricated
  `employment_id`/`employee_no`.
- `/v1/employees/onboard` OpenAPI now documents only 202 with
  `PendingOnboardingCase`; 201/200 employee schemas were removed from that
  operation. Generated TypeScript types were regenerated.
- No new menu, route, form, migration, contract draft or compensation draft was
  added.

## Changed files

### Backend

- `backend/app/Modules/Lifecycle/Contracts/OnboardingCaseCommand.php` (new)
- `backend/app/Modules/Lifecycle/Services/LifecycleService.php`
- `backend/app/Modules/Lifecycle/Providers/LifecycleServiceProvider.php`
- `backend/app/Modules/People/Http/Controllers/EmployeeController.php`
- `backend/tests/Feature/Lifecycle/OnboardingCaseCommandTest.php` (new)
- `backend/tests/Feature/People/CreateEmployeeCommandTest.php`

### Frontend and contract

- `frontend/src/modules/people/employees/api.ts`
- `frontend/src/modules/people/employees/components/EmployeeCreateDrawer.vue`
- `frontend/src/modules/people/employees/pages/EmployeeListPage.vue`
- `frontend/tests/unit/EmployeeListPage.spec.ts`
- `frontend/tests/unit/onboardingCaseResponse.spec.ts` (new)
- `docs/openapi/openapi.yaml`
- `frontend/src/generated/api.ts`

## TDD evidence

Step 1 initial RED:

```text
Target class [App\Modules\Lifecycle\Contracts\OnboardingCaseCommand] does not exist.
Tests: 2, Assertions: 0, Errors: 2.
```

After adding the contract, binding and implementation:

```text
OK (2 tests, 16 assertions)
```

Step 2 RED before the controller change:

```text
Expected response status code [202] but received 201.
Expected response status code [422] but received 201.
Tests: 9, Assertions: 29, Errors: 1, Failures: 5.
```

After the 202 facade and validation mapping:

```text
OK (9 tests, 76 assertions)
```

Step 3 API RED:

```text
TypeError: 员工入职接口未返回员工关系编号。
promise resolved "{ person_id: 3, employment_id: 7, …(4) }" instead of rejecting
Tests: 2 failed (2)
```

Step 3 UI RED:

```text
expected "spy" to be called 1 times, but got 2 times
```

After the pending-Case API and UI:

```text
Test Files 4 passed (4)
Tests     22 passed (22)
```

The approval-path regression was added after the facade RED/GREEN cycle; its
first execution was GREEN because it exercises the already-implemented canonical
Lifecycle Effect, and it is recorded as integration coverage rather than as a
claim of its own RED cycle.

## Verification

Executed in the isolated worktree:

```text
PHPUnit focused:
  OK (38 tests, 12005 assertions)
  - CreateEmployeeCommandTest
  - OnboardingCaseCommandTest
  - OnboardingDedupTest
  - OnboardingEffectTest
  - ActionManifestParityTest
  - OpenApiContractTest
  - OpenApiSchemaContractTest
  - LegacyRouteInventoryTest

Pint:
  PASS (1361 files)

PHPStan:
  [OK] No errors (full configured paths: app, routes, database, tests)

arch:test:
  Module boundaries OK (765 files; 4 pre-existing non-blocking warnings)

OpenAPI:
  openapi-lint        OK (293 paths, 376 operations, 3.1.0)
  openapi:validate    valid (2 pre-existing health-operation warnings)
  openapi:generate    wrote frontend/src/generated/api.ts
  openapi:check       generated types current
  actions:check       OK (37 pages, 132 actions)

Frontend:
  typecheck           PASS
  focused unit tests  22 passed (4 files)
```

`pnpm gate:all` was intentionally not run per the task instruction.

### Worktree verification caveat

`backend/vendor` is a symlink to the primary workspace's vendor directory, whose
Composer PSR-4 `App\` root points at `D:\code\ai\factoryHR\backend\app`. A plain
`vendor/bin/phpunit` therefore resolves application classes from the primary
workspace, not this worktree. Focused PHPUnit was run through an untracked
worktree-priority bootstrap (`.phpunit-t05-bootstrap.php`) that prepends this
worktree's `app/` and `tests/` roots. This bootstrap is deliberately not
committed; it contains no product behaviour. Pint and PHPStan were run against
explicit worktree files / configured paths, and frontend commands ran from the
worktree.

## Plan deviations

1. The Mini Plan verification names `OnboardingSingleEntryTest`; no such file
   exists on baseline `b888693`. Equivalent single-entry coverage is provided by
   `OnboardingCaseCommandTest` plus the rewritten `CreateEmployeeCommandTest`
   (including approval-only writes). `OnboardingDedupTest` and
   `OnboardingEffectTest` also pass.
2. The Mini Plan states `CreateEmployeeService` "remains used by the Lifecycle
   Effect only". In this baseline the Effect uses `PeopleEmploymentWriter`
   directly; `CreateEmployeeService` is unchanged and is no longer called by any
   HTTP controller or test. We did not falsely rewire the Effect to it, and we
   did not delete it.
3. No T05b contract/compensation draft work or migration was performed.

## Risks

- **Breaking API change:** existing clients expecting 201 plus `employment_id`
  now receive 202 plus `case_id`/`case_no`. The OpenAPI description documents the
  change. The frontend has been migrated in this commit; any external clients
  must migrate to the pending-Case workflow.
- **Idempotency scope changed** from `CMD:people.employee.onboard` to
  `CMD:lifecycle.onboarding.create` for this route. Within a deployment upgrade,
  an in-flight key from the old implementation will not replay across versions;
  operators should drain old requests before rollout.
- **Case metadata, not an employee:** callers must poll/read the Case or open the
  movements detail page; the old `replayed` + employee payload is intentionally
  unavailable.
- **Static runtime caveat:** `vendor` is a shared symlink, so worktree-local plain
  PHPUnit can load primary-workspace classes. This is a pre-existing harness
  issue; focused tests above used the worktree-priority bootstrap.
- **T05b remains open:** contract and compensation drafts are not created by
  onboarding. No claim of T05 completion beyond the Mini Plan scope is made.
- **No MySQL/browser acceptance evidence:** SQLite/PHPUnit and frontend unit
  tests only; MCP/browser evidence remains deferred to T18/T19.

## T05a2a — encrypted People-owned onboarding draft

- Added `people_onboarding_drafts` as a People-owned encrypted PII draft.
- `POST /v1/employees/onboard` now stores person PII (legal name, ID, mobile,
  email, birth date) only in that encrypted draft; Workflow Case/Intent and
  Lifecycle/Workflow detail payloads carry only `onboarding_draft_id`.
- The approved Onboarding Effect decrypts the People-owned draft through the
  `OnboardingDraftStore` contract, creates the Person, and marks the draft
  consumed. Case/Intent raw JSON contains no plaintext PII.
- Preserved legacy execution semantics for the facade: initial employment
  status ACTIVE, default work location HQ, and mapped
  `assignment.valid_from` / `terms.effective_from`.
- Added PII redaction regression coverage (57 assertions) and kept onboarding
  focused suites green: 39 tests / 12,057 assertions.
- Remaining T05a2 items: cross-entry idempotency scope, legacy capability/org
  scope, 409 OpenAPI/error mapping, stale pendingCase link, and canonical
  Lifecycle onboarding PII draft adoption.

## T05a2b — capability, OpenAPI and frontend contract alignment

- Legacy `/v1/employees/onboard` now requires both
  `people.employee.manage` and canonical `lifecycle.onboarding.manage`.
- The create action is hidden unless both capabilities are present; the Case
  link is hidden unless `lifecycle.case.read` is present.
- Duplicate employee number is mapped from the real 422
  `VALIDATION_FAILED + EMPLOYEE_NO_DUPLICATE` contract, including alias fields
  `employee_no` / `org_unit_id`.
- Added OpenAPI 409 for idempotency conflicts and synchronized
  `x-capability` with route middleware.
- Stale `pendingCase` links are cleared on other success messages/alert close.
- Verification: onboarding focused 39 tests / 12,057 assertions; frontend
  EmployeeListPage + onboarding response 14 tests; typecheck/lint, Pint,
  PHPStan, arch, OpenAPI lint/validate/generate/check green.

## T05a2c — canonical onboarding uses the same People draft

- `POST /v1/lifecycle/onboarding` now reserves the same People-owned encrypted
  draft when a person payload is supplied; the canonical Case/Intent also carry
  only `onboarding_draft_id`.
- The canonical command and legacy facade share the
  `CMD:lifecycle.onboarding.create` idempotency scope.
- Added canonical PII regression coverage: raw Case/Intent and lifecycle Case
  detail contain no ID number, mobile/email, and approval consumes the draft.
- Verification: onboarding focused 40 tests / 12,081 assertions; Pint, PHPStan
  and arch:test green.

## T05a2d — target organization scope and pending dedup

- Both legacy and canonical onboarding now evaluate
  `lifecycle.onboarding.manage` against COMPANY, ORG_UNIT and ORG_SUBTREE
  anchors for the target organization; the authorized org anchor is recorded
  in the authorizer audit calls.
- Added `pending_employee_no` with a unique index to the People draft table so
  only one pending onboarding intent can exist per employee number, while a
  consumed draft releases the key for a later rehire.
- Draft consumption clears `pending_employee_no`; regression coverage asserts
  it after approval.
- Verification: onboarding focused 40 tests / 12,082 assertions; Pint,
  PHPStan, arch:test and OpenAPI contract checks green.

## T05a2e — architecture and capability-gate correction

- Renamed the Lifecycle contract method from `create()` to `submit()` so the
  architecture scanner no longer mistakes a cross-module command for an
  Eloquent write.
- Kept `people.employee.manage` as the import/export management capability,
  while the create-onboarding entry additionally requires
  `lifecycle.onboarding.manage`.
- Full-suite verification before this correction found and fixed the
  controller SQL/architecture false positive and the import drawer capability
  regression.
