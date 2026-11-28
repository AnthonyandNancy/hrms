# T05a — Single onboarding entry (small-step plan)

**Status:** COMPLETE (Step 1-3; T05b contract/compensation drafts deferred).
**Parent task:** `docs/superpowers/plans/2026-11-28-v2-4-implementation-master-plan.md` T05
**Baseline:** `f63fe3e`
**Worktree:** `D:\code\ai\factoryHR-worktrees\t05-onboarding`

## Goal

Stop `/v1/employees/onboard` from being a second direct writer of Person/Employment/Assignment/Terms. Convert it into a compatibility facade that creates or returns the canonical Lifecycle onboarding case, returns a pending case response, and leaves all domain writes to the existing Onboarding Effect.

This mini step does **not** implement contract/compensation drafts; those remain a follow-up T05b. Success means one employee onboarding authority and zero direct domain writes through the legacy route.

## Current Understanding

- `EmployeeController::onboard()` calls `CreateEmployeeService::onboard()`, which directly calls `PeopleEmploymentWriter` to create Person/Employment/Assignment/Terms.
- `LifecycleController::onboarding()` creates a Workflow Case; `OnboardingEffectHandler` creates the same domain chain after approval.
- Frontend `frontend/src/modules/people/employees/api.ts` calls `/v1/employees/onboard`.
- Existing `OnboardingDedupTest` proves duplicate Lifecycle cases are deduplicated by employee number.
- There is no Lifecycle case-command contract exposed to People; People currently cannot call the canonical command without importing a concrete service.

## Impact Map

### Direct modification
- Add `backend/app/Modules/Lifecycle/Contracts/OnboardingCaseCommand.php`.
- Implement it in `backend/app/Modules/Lifecycle/Services/LifecycleService.php`.
- `backend/app/Modules/People/Http/Controllers/EmployeeController.php`: map legacy onboarding payload to lifecycle payload, call the contract, return 202 with case metadata.
- `backend/tests/Feature/People/CreateEmployeeCommandTest.php`: assert legacy route creates pending Case and zero domain rows.
- `frontend/src/modules/people/employees/api.ts` + employee create UI: consume 202/case response and navigate/show pending result.
- OpenAPI: `/v1/employees/onboard` response changes from 201 employee to 202 case, without adding a route.
- Route ledger remains kernel; no new path.

### Associated checks
- `Lifecycle\OnboardingDedupTest`, `OnboardingEffectTest`, `OnboardingSingleEntryTest`.
- Frontend employee create tests and page actions manifest.
- Employee detail should not link to an unapproved employment.

### Confirm no modification
- Time/Payroll/Snapshot.
- Contract/compensation draft creation (T05b).
- Workflow engine semantics.
- Department/position master data.

## Mini Plan

### Step 1 — Lifecycle case command contract

Add `OnboardingCaseCommand::create(int $actorUserId, array $payload, string $idempotencyKey, string $businessDate): array`, implemented by a thin adapter around `LifecycleService::createCase`. Test the contract via a fake/real case and confirm no domain rows.

### Step 2 — Legacy endpoint becomes a facade

Map `CreateEmployeeRequest` payload to the Lifecycle onboarding payload:
`person.legal_name`, `employee_no`, `org_unit_id`, `position_id`, `hire_date`, `employment_type` and accepted terms fields. Call the Lifecycle contract under the same Idempotency-Key. Return 202 with `case_id`, `case_no`, `application_status`, `effect_status` and `canonical_endpoint=/v1/lifecycle/onboarding`; do not call `CreateEmployeeService`.

Update the existing direct-endpoint tests to assert:
- 202 pending response;
- zero `persons/employments/assignments/employment_terms` rows;
- duplicate key returns the same case;
- missing/unknown org or invalid date never creates a case or a domain row.

### Step 3 — Frontend and contract sync

Update the employee create API/UI to handle 202: display a Chinese “已提交入职审批” result with the case link and do not show a newly created employee. Regenerate OpenAPI/types/action manifest and update any copy checks. Do not add a new menu or parallel form.

## Verification

- `phpunit --filter 'CreateEmployeeCommandTest|OnboardingDedupTest|OnboardingEffectTest|OnboardingSingleEntryTest|ActionManifestParityTest'`
- `pnpm openapi:validate && pnpm openapi:generate && pnpm openapi:check`
- `pnpm actions:check`, frontend typecheck and employee-create unit test
- `git diff --check`, Pint, PHPStan, arch:test
- Manual API check: direct route returns only a Case; lifecycle approval is the only path that creates Person/Employment.
- MCP evidence is deferred to T18/T19.

## Risks

- Existing clients expecting 201/employee_id must move to the pending-case workflow; this is an intentional contract change and must be documented in the OpenAPI description/release notes.
- `CreateEmployeeService` remains used by the Lifecycle Effect only; it must not be deleted or called from the legacy controller.
- If current frontend cannot display a pending onboarding case without a larger rewrite, stop and record a Plan Deviation rather than silently keeping direct writes.

## Execution rule

Execute this small plan only after merging current parallel worktrees. Each step must update its evidence and must not expand into contract/compensation draft creation until T05a is green.


## Changed Files Log

| File | Change | Step |
|---|---|---|
| `backend/app/Modules/Lifecycle/Contracts/OnboardingCaseCommand.php` | New People → Lifecycle onboarding Case contract; documented zero-domain-row guarantee. | 1 |
| `backend/app/Modules/Lifecycle/Services/LifecycleService.php` | Implements the contract with idempotent Case submission; no People writes. | 1 |
| `backend/app/Modules/Lifecycle/Providers/LifecycleServiceProvider.php` | Binds `OnboardingCaseCommand` to `LifecycleService`. | 1 |
| `backend/tests/Feature/Lifecycle/OnboardingCaseCommandTest.php` | Contract RED/GREEN coverage, idempotent replay and zero domain rows. | 1 |
| `backend/app/Modules/People/Http/Controllers/EmployeeController.php` | Legacy endpoint maps request to Lifecycle payload; calls only the Contract; 202 + Case metadata; removed `CreateEmployeeService` dependency. | 2 |
| `backend/tests/Feature/People/CreateEmployeeCommandTest.php` | 202/pending Case/zero domain rows, replay identity, invalid input no-write, and approval-effect-only write coverage. | 2 |
| `frontend/src/modules/people/employees/api.ts` | Returns typed `PendingOnboardingCase`; rejects legacy employee-shaped success bodies. | 3 |
| `frontend/src/modules/people/employees/components/EmployeeCreateDrawer.vue` | Emits the pending-Case result instead of an employee. | 3 |
| `frontend/src/modules/people/employees/pages/EmployeeListPage.vue` | Shows “已提交入职审批”, Case number/link, and does not refetch as if an employee existed. | 3 |
| `frontend/tests/unit/EmployeeListPage.spec.ts` | Updated create-flow assertions for pending Case and link. | 3 |
| `frontend/tests/unit/onboardingCaseResponse.spec.ts` | API normalisation RED/GREEN and malformed-response rejection. | 3 |
| `docs/openapi/openapi.yaml` | `/v1/employees/onboard` 202 + `PendingOnboardingCase`; deprecated 201/employee and 200 replay shapes. | 3 |
| `frontend/src/generated/api.ts` | Regenerated OpenAPI TypeScript types. | 3 |
| `docs/implementation/v24/05-onboarding/implementation-report.md` | TDD, verification, deviation and risk evidence. | 1-3 |

### Plan deviations

- The Mini Plan names `OnboardingSingleEntryTest`; that file does not exist on this baseline. Equivalent single-entry coverage is `OnboardingCaseCommandTest` plus the rewritten `CreateEmployeeCommandTest` (including approval-only domain writes); `OnboardingDedupTest` and `OnboardingEffectTest` remain green.
- `CreateEmployeeService` is not wired into `OnboardingEffectHandler`; the Effect uses `PeopleEmploymentWriter` directly. The service is now unreferenced by HTTP and is retained unchanged as requested, rather than being falsely described as the Effect's current implementation. No T05b work was attempted.
- No migration was added or edited; no contract/compensation draft logic was introduced.
