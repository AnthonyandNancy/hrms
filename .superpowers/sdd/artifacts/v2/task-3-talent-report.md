# Task 3 Talent Sweep Report

## Status

**DONE_WITH_CONCERNS**

- Worktree: `D:/code/ai/factoryHR/.worktrees/v2.1-remediation`
- Scope: `backend/app/Modules/Talent/**` plus Talent-owned feature tests and `tests/Support/TalentTestCase.php`.
- Forbidden-token scan for the owned scope:
  `rg -n 'tenant_id|legal_entity_id|LEGAL_ENTITY|tenantId\(|legalEntityId' backend/app/Modules/Talent backend/tests/Feature/Talent backend/tests/Support/TalentTestCase.php`
  returned **0** matches.

## Delivered

| Area | Change |
|---|---|
| Models | Removed `tenant_id` / `legal_entity_id` from every Talent model `$fillable`, casts and docblocks. |
| Services | Removed tenant columns/filters and tenant parameters. `AuditRecorder`/`SensitiveAccessAuditor` use the new tenant-free contract shapes. |
| Controllers | Replaced `TenantBusinessDate` with `CompanyBusinessDate`; removed principal `tenantId()` calls; read paths no longer filter by tenant. |
| Candidate conversion | Request validation now accepts only `org_unit_id`, `employee_no`, optional `hire_date`; conversion payload and `CandidateConversion` row contain `org_unit_id` + `employee_no`, no legal entity. Idempotency semantics unchanged. |
| Contracts | `OfferIntegrityReader` returns `{candidate_id, accepted_count}`; `RecruitmentFunnelDirectory` is tenant-free; its unavailable reason is non-null. |
| Effects / console | Approval effect handlers no longer filter by tenant; demo seed command no longer has a tenant option. |
| Tests | Removed tenant/legal-entity fixture keys; conversion tests now use real org units; the former cross-tenant calibration assertion became an out-of-scope published-cycle assertion with the same 409/immutability coverage. |

## Verification

| Gate | Result |
|---|---|
| `artisan arch:test` | **PASS** — Module boundaries OK; 4 existing non-blocking Platform warnings. |
| Talent feature filter (`artisan test --compact --filter='Tests\Feature\Talent'`) | **26 passed, 257 assertions**. |
| `TalentRedLinesTest` + Talent suite | **30 passed, 270 assertions** (this file remains Lifecycle-owned; run only as adjacent coverage). |
| `pint --test app/Modules/Talent tests/Feature/Talent tests/Support/TalentTestCase.php` | **PASS** — 58 files. |
| Forbidden-token rg | **0**. |
| `phpstan analyse --no-progress` | **Not green globally**: 199 errors, all in concurrent/external in-flight sweeps (IAM/WebAuthn, People tests, Lifecycle, Organization, Platform). Filtered PHPStan output has **zero Talent paths**. |

## External call sites not edited

- `backend/app/Modules/Reporting/Services/ProjectionBuilder.php`: calls `RecruitmentFunnelDirectory::available()` / `unavailableReason()` with no tenant argument (already adapted by External owner; still consumes Talent contract).
- `backend/app/Modules/Lifecycle/Console/CheckLifecycleIntegrityCommand.php`: already calls `candidatesWithMultipleAcceptedOffers()` with no tenant argument and no longer reads `tenant_id` from the row.
- `backend/app/Modules/Lifecycle/Services/TalentOnboardingDraftService.php`: accepts the Talent onboarding draft payload; it no longer requires `legal_entity_id` and must be kept that way by Lifecycle.
- `WorkflowCaseGateway` calls match the new tenant-free signatures delivered by Workflow.

## Commit discipline

Only the 52 explicit Talent paths plus this report will be staged; no `git add backend`, `git add -A`, or external-module files.
