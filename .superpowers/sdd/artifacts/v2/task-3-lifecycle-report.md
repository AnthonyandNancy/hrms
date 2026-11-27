# V2 Task 3 — Lifecycle runtime scope sweep

## Status
Complete for the Lifecycle module.

## Scope
- Removed tenant/legal-entity persistence keys from Lifecycle fillables, casts, selects, creates and docblocks.
- Removed tenant filters/arguments from Lifecycle services, commands, controllers, effect handlers and models.
- `LEGAL_ENTITY` policy anchors are already `COMPANY` at the route layer; Lifecycle controllers use `AnchorRef::company()` with a null id.
- Replaced `transferLegalEntity` in `TransferEffectHandler` with an org-unit transfer on the same employment: the prior assignment is ended at the effective date and a new PRIMARY assignment starts then. The lifecycle intent's effective status/idempotency guard is preserved; replay returns `employment.assignment.changed.v1` without writing a second assignment.
- Onboarding/offboarding checklist defaults, workflow effects and case semantics unchanged.
- Lifecycle contracts and providers were updated module-wide (not just lifecycle.txt), including tenant-free `EmploymentContractVersionLedger`, `TurnoverDirectory` and `ExportTaskCanceller`.

## Transfers
- Transfer handler outbox event is `employment.assignment.changed.v1` (same as the prior intra-company path).
- Effective dating: `assignmentAsOf(effective_date - 1 day)` determines the assignment to end; new assignment starts on `effective_date`.
- Idempotency: `LifecycleEffectSupport::alreadyApplied(caseId)` short-circuits replay and returns the stored employment id.
- Cross-tenant/cross-entity test was replaced by `test_cross_department_transfer_keeps_the_employment_and_is_idempotent`, asserting the employment stays ACTIVE, exactly 2 assignments exist after replay, and the old view is retained as-of the day before effect.

## Tests
- Cross-tenant case-list assertion replaced by company-wide visibility (`test_lifecycle_cases_are_company_wide`).
- Secondment cross-tenant assertion replaced by unknown-employment 404.
- Lifecycle test support (`Tests\Support\LifecycleTestCase`) adapted for the tenant-free People/Workflow APIs; `PeopleScenario` owned by the People sweep.
- Lifecycle integration tests updated for tenant-free directory calls.

## Verification
- `rg -n 'tenant_id|legal_entity_id|LEGAL_ENTITY|tenantId\(|transferLegalEntity'` over lifecycle.txt + Lifecycle tests: **0 hits** (exit 1).
- Broader Lifecycle module scan including `legalEntity|tenantId`: **0 hits**.
- Lifecycle feature tests via `phpunit tests/Feature/Lifecycle`: **35 passed, 237 assertions**.
  - `artisan test --compact --filter='Tests\\Feature\\Lifecycle'` reports "No tests found" in this PHPUnit/Laravel version (filter string does not match); direct directory run is the equivalent evidence.
- Lifecycle integration tests (LifecycleToIam, LifecycleToPeople, UatOnboardingToFirstPayrollPreparation): **3 passed, 22 assertions**.
- Targeted PHPStan (`app/Modules/Lifecycle`, Lifecycle feature/integration tests, LifecycleTestCase): **No errors**.
- Full PHPStan remains red with 104 errors from other in-flight module sweeps (People/Organization/Talent/Authorization/etc.); no Lifecycle errors remain.
- Targeted Pint (`app/Modules/Lifecycle`, Lifecycle tests, LifecycleTestCase): **PASS, 55 files**.
- `php artisan arch:test`: **Module boundaries OK** (4 pre-existing non-blocking Platform warnings).

## External call sites not edited (compiled against the new Lifecycle contracts)
- `backend/app/Modules/People/Services/ContractLifecycleService.php` — `EmploymentContractVersionLedger::append/history` (already tenant-free at call sites).
- `backend/app/Modules/Talent/Services/RecruitmentService.php` — `TalentOnboardingDraftPort::createDraftFromAcceptedOffer` (already omits `tenant_id`/`legal_entity_id`).
- `backend/app/Modules/Reporting/Services/ProjectionBuilder.php` and `ReportingServiceProvider.php` — `TurnoverDirectory::available/unavailableReason/terminations` (already tenant-free).
- `TransferLegalEntity` has no remaining Lifecycle or other backend call sites.
