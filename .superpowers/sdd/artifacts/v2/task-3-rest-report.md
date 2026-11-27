# Volume 2 Task 3 Rest Sweep Report - Reporting, Engagement, Console, Routes

## Status
DONE_WITH_CONCERNS

## Commits
- `913ddc9` refactor(platform): clean reporting, engagement and route scope (51 files)
- `dac5351` refactor(platform): clean reporting, engagement and route scope (ReportableDataset)
- `602f4d0` refactor(platform): clean reporting, engagement and route scope (PHPStan follow-up)

## Scope delivered
- Reporting models/services/controllers/contracts/jobs/providers: removed tenant and legal-entity keys, filters, parameters, selects, casts, docblocks and report fields.
- Report definitions: removed legal-entity field/filter; custom definitions now company-wide; Payroll report renamed "Payroll cost".
- Export/field policies retained: whitelist rejection, P3 salary rejection, 50k row cap, token/signed URL binding, audit outputs unchanged.
- Engagement models/services/contracts/adapters/controllers/providers: removed tenant scope; audience targeting remains by org unit/role/all, not tenant.
- Engagement notices: cross-tenant test converted to cross-department; audience/ack/governance coverage retained.
- Console: `ops:demo-state --tenant` removed; capability audit docblock cleaned.
- Tests: Reporting/Engagement fixtures/assertions updated to company-wide schema; cross-department notice coverage replaced cross-tenant assertion.

## Route ownership
`backend/routes/api.php` was reassigned to v2-sweep-core by the lead/core agent. I did not edit it. The core commit `328cac8` contains the two Resolve* middleware removals, both calendar path changes, and preserved capability guards/public allowlist. My required rg (including routes/api.php) is 0.

## Verification
- `rg -n 'tenant_id|legal_entity_id|LEGAL_ENTITY|tenantId\('` over reporting.txt, engagement.txt, console.txt + routes/api.php: **0**.
- `artisan arch:test`: exit 0 (4 non-blocking warnings).
- `artisan capability:audit`: exit 0 (2 pre-existing negative-manifest warnings for new calendar paths).
- Targeted Reporting/Engagement tests: **27 passed, 401 assertions**, exit 0.
- Pint (owned files): PASS, 54 files.
- PHPStan (owned files): 0 errors. Whole-repo PHPStan still exit 1 with **34 errors in other agents' files** (PeopleScenario/legalEntity call sites, PayrollContext fixtures, CapabilityGuardTest, etc.).

## External call sites not edited
- `app/Modules/IAM/Http/Controllers/WorkspaceController.php:95` already calls `NoticeWorkspaceDirectory::forUser($userId)` and matches the new shape.
- No production external call sites remain for Reporting/Engagement changed APIs.
- Cross-module contract shape changes (People/Time/Payroll/Talent/Lifecycle/Workflow/Platform/Shared) were made by their owners; reported here rather than edited.
- Test support `PeopleScenario`/`PeopleTestCase` updated by v2-sweep-people; `EngagementFixture` updated by me to the new API.

## Concerns
1. Whole-repo PHPStan is red in unrelated modules because other sweep commits are still landing; my files are clean.
2. `capability:audit` warns that the two renamed calendar routes lack negative-test manifest entries. Guards are present; this is a route-manifest follow-up.
