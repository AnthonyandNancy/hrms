# Volume 2 Task 3 - Core runtime context report

## Status

**DONE_WITH_CONCERNS** (core scope complete; full gate red only on parallel module/frontend sweeps still in flight)

- Worktree: `D:/code/ai/factoryHR/.worktrees/v2.1-remediation`
- Core commit: `refactor(iam): remove tenant and legal-entity runtime context` (this commit; use `git log -1 --format=%H -- backend/routes/api.php` for the SHA)
- Shared owner commit `0092606` had already landed three of my owned Shared paths plus both deletions: `Shared/Auth/AuthenticatedPrincipal.php`, `Shared/Authorization/AuthorizationSnapshot.php`, `Shared/Authorization/EnsureCapability.php`, `ResolveCompanyScope.php`, and deletion of both `Resolve*LegalEntityContext.php`. My commit completed IAM principal/resolver, routes and test support, and corrected the `ResolveCompanyScope` docblock.

## Contract published to all module agents

1. `Principal` (IAM and Shared\Auth) has **no `tenantId()`**. IAM `AuthenticatedPrincipal` has no tenant constructor arg/accessor; `TestPrincipal` constructor now starts with `int $userId`. No replacement tenant concept.
2. `company_profile` is a singleton configuration, **not a scope**. Any old `tenantId()` filter is removed (single company = all rows); old tenant insert columns are removed.
3. Authorization anchors use `AnchorType::COMPANY` only; whole-company anchor is anchor id `NULL` / anchor key `0`. There is no `LEGAL_ENTITY` anchor.
4. Both `ResolveLegalEntityContext` and `ResolveResourceLegalEntityContext` are deleted. `ResolveCompanyScope` only attaches `company_profile` to the request; it performs no isolation, filtering or anchor resolution.
5. `routes/api.php` keeps every capability guard. Calendar paths are `/v1/system/calendars/publish` and `/v1/time/calendars`; all `COMPANY,legal_entity_id` anchors are just `COMPANY`.
6. `NoTenantNoLegalEntityTest` retains exactly its old strictness/allowlist (two marked preflight marker lines only).

## Owned files delivered

| File | State |
|---|---|
| `backend/app/Shared/Auth/AuthenticatedPrincipal.php` | tenantId removed (landed in `0092606`) |
| `backend/app/Modules/IAM/Contracts/Principal.php` | tenantId removed |
| `backend/app/Modules/IAM/Auth/AuthenticatedPrincipal.php` | tenant field/arg/accessor removed |
| `backend/app/Modules/IAM/Auth/PrincipalResolver.php` | tenant/session cross-check removed; employment lookup uses `CompanyBusinessDate` + `employmentIdsForUser(userId, businessDate)` |
| `backend/app/Shared/Authorization/AuthorizationSnapshot.php` | tenant predicates and tenant audit arg removed (landed in `0092606`) |
| `backend/app/Shared/Authorization/EnsureCapability.php` | tenant log keys removed (landed in `0092606`) |
| `backend/app/Shared/Authorization/ResolveLegalEntityContext.php` | deleted (landed in `0092606`) |
| `backend/app/Shared/Authorization/ResolveResourceLegalEntityContext.php` | deleted (landed in `0092606`) |
| `backend/app/Shared/Authorization/ResolveCompanyScope.php` | created; attaches `company_profile` only |
| `backend/routes/api.php` | Resolve* registrations/imports removed; calendar paths changed; all capability guards retained |
| `backend/tests/Support/TestPrincipal.php` | tenant constructor arg/accessor removed |
| `backend/tests/Support/TestSessionMiddleware.php` | no change needed (never passed tenantId) |
| `backend/tests/Support/TestCapabilityMiddleware.php` | no change needed (never passed tenantId) |

## Verification evidence

| Command | Result |
|---|---|
| `php artisan test --compact --filter='NoTenantNoLegalEntityTest'` | **RED as expected**: 294 remaining violations, first 80 printed; **zero hits in any core-owned file**. |
| `php artisan capability:audit` | **PASS (exit 0)**, `Capability audit OK.` Non-blocking warning: no negative-test manifest entry yet for `POST /v1/system/calendars/publish` and `GET /v1/time/calendars` (renamed routes; manifest owner must add entries). |
| `php artisan arch:test` | **PASS (exit 0)**, `Module boundaries OK.` 4 pre-existing non-blocking dynamic-table warnings. |
| `phpstan analyse` on owned files | **PASS (exit 0)**, `[OK] No errors`. |
| Full `phpstan analyse` | Run completed with **339 errors in other modules/tests still mid-sweep**; no errors reported in core-owned paths. |
| `pint --test` on owned files | **PASS (exit 0)**, 9 files clean. |
| Full `pint --test` | **242 style failures across other modules/tests mid-sweep**; no core-owned file listed. |

## Remaining files under my ownership

**None.** A literal scan of every owned path for `tenant_id|legal_entity_id|LEGAL_ENTITY|X-Legal-Entity-Id|activeLegalEntityId` returns zero hits, and the strict guard output contains no core-owned path.

## Required module changes (module owners, not edited here)

1. Every caller of `Principal::tenantId()` must drop it. Known current call sites: `Modules/IAM/Auth/CapabilityAuthorizer.php`, `Modules/IAM/Auth/TimeActorBridge.php`, `Modules/IAM/Auth/WorkflowPrincipalBridge.php`, `Modules/IAM/Http/Controllers/{MeController,WorkspaceController}.php`, `Modules/Payroll/Http/Controllers/PayrollPayslipController.php`, `Modules/Engagement/Services/NoticeAccessPolicy.php`; all module tests/fakes likewise.
2. Every `new AuthenticatedPrincipal(...)` must drop `tenantId:` and every remaining positional `new TestPrincipal(...)` must use the new argument order (`userId` first). Known: `tests/Feature/Authorization/{AuthorizationTestSupport,WorkspaceProjectionTest}.php`, `tests/Feature/IAM/{MeCapabilitiesWildcardTest,MePermissionsPageGrantTest,MePermissionsTest}.php`, `tests/Feature/Reporting/ReportingTestCase.php`, `tests/Feature/CapabilityGuardTest.php`, `tests/Feature/People/PaginationScopeTest.php`, `tests/Integration/DirectoryContractTest.php`.
3. Time must change controller signatures after path params are gone: `CalendarAdminController::publish(Request $request)` and `TimeCatalogController::calendar(Request $request)`; response/payload must not emit legal-entity fields.
4. Module services that received the deleted middleware's merged `legal_entity_id` must derive company/profile/employment context themselves (no request header, no fallback). Position, secondment, travel, notice and reporting controllers should be compile-checked against the new route middleware list.
5. Non-IAM code still calling `$principal->tenantId()` must use the shared `CompanyBusinessDate`/`CompanyProfileReader` and service-owned IDs; no tenant stand-in is permitted.
6. The strict guard remains red for 294 module/frontend/docs references until the parallel module sweeps and Task 4 frontend/OpenAPI sweep land.

## Notes

- `PrincipalResolver` now injects `Clock` only through `CompanyBusinessDate`; no `OrganizationFacts` injection.
- `PersonEmploymentDirectory::employmentIdsForUser(int $userId, string $businessDate): array` is the shared final shape used by the resolver.
- No `git add -A`/`git add backend` was used; only explicit paths were staged.
