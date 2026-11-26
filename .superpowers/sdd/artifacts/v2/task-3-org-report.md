# Volume 2 Task 3 - Organization sweep report

## Status

**DONE_WITH_CONCERNS**

- Worktree: `D:/code/ai/factoryHR/.worktrees/v2.1-remediation`
- Branch: `feat/v2.1-remediation`
- Commit: `147b0d25ebb8a63967cac8a8d428fa35896c2722`
- Subject: `refactor(org): remove tenant and legal-entity scope`

The Organization runtime no longer references tenant/legal-entity scope and all
Organization tests pass. At the time of the Organization commit the
repository-wide PHPStan gate still reported two errors in
`tests/Feature/CapabilityGuardTest.php`, outside this sweep's file ownership;
that unrelated fake was fixed afterwards by another sweep and the current run is
clean.

## Delivered

| Change | Detail |
|---|---|
| Models | Removed `LegalEntity`; removed `tenant_id`/`legal_entity_id` from docblocks, fillables and casts in `OrgUnit`, `OrgUnitClosure`, `Position`, `PositionGrade`, `PositionQualification`. |
| Facts contract | Deleted `LegalEntityInfo`, `legalEntity()` and `legalEntitiesForTenant()`. `OrganizationFacts` is now `orgUnit(int): ?OrgUnitInfo`, `position(int): ?PositionInfo`, `defaultTimezone(): string`, backed by `CompanyProfileReader`. |
| Info objects | `OrgUnitInfo`/`PositionInfo` no longer carry `tenantId` or `legalEntityId`. |
| Directory contract | `OrganizationDirectory` is now `orgUnitIds(array,string)`, `subtreeIds(int,string)`, `tree(string)`; `legalEntityOfOrg()` removed. As-of closure/tree semantics unchanged. |
| Scope adapter | `EloquentOrganizationScopeDirectory` implements Shared's final `orgUnitExists(int)`, `orgUnitAncestors(int)`, `descendantOrgUnitIds(int)` using `CompanyBusinessDate`. |
| Controllers/services | Removed tenant filters and client-supplied legal-entity parameters; business date uses `CompanyBusinessDate`; audit/outbox recorders use the new single-company signatures; People contracts use their tenant-free signatures. |
| Seeding/bootstrap | Demo seeder, installer bootstrap and closure rebuild are company-wide and no longer create/query `legal_entities`. |
| Tests | Cross-tenant assertions replaced with cross-department/subtree and unknown-id assertions; OrganizationDirectory/Facts tests updated. `OrgTenantIsolationTest` replaced by `OrgScopeIsolationTest`. |

## Verification

| Gate | Result |
|---|---|
| `rg -n 'tenant_id\|legal_entity_id\|LEGAL_ENTITY\|tenantId(\|legalEntitiesForTenant' <sweep files>` | **0** hits (also 0 via `git grep` at the commit) |
| `php artisan arch:test` | PASS - `Module boundaries OK.`, 4 pre-existing non-blocking warnings |
| `php artisan test --compact --filter='Tests\\\\Feature\\\\Organization'` | PASS - **40 passed, 1231 assertions** |
| `pint --test app/Modules/Organization tests/Feature/Organization` | PASS - 39 files |
| `phpstan analyse` | PASS - `No errors` (current tree). At Organization-commit time: 0 Organization errors, 2 unrelated `CapabilityGuardTest` errors that a later sweep fixed |

The exact documented filter needs four backslashes on this shell; with two
backslashes PHPUnit reports "No tests found". Running the directory directly
also gives 40 passed.

## External call sites not edited

The removed Facts/Directory signatures and the deleted `LegalEntity` model had
consumers outside Organization. They were left to their owning sweeps:

- IAM: `Http/Controllers/MeController.php` (`legalEntitiesForTenant`).
- Shared authorization: `ResolveResourceLegalEntityContext.php`/`ResolveLegalEntityContext.php` (legal-entity resource anchoring).
- Lifecycle: `Domain/LifecycleValidator.php`, `EffectHandlers/OnboardingEffectHandler.php`, `EffectHandlers/TransferEffectHandler.php`, `Services/SecondmentService.php`.
- People: `EmploymentService`, `AssignmentService`, `PeopleDirectoryService`, `EloquentWorkforceReportingDirectory`, `EloquentPeopleWorkspaceDirectory`, `Commands/SeedPeopleDemoCommand`, plus `Tests/Support/PeopleScenario`.
- Payroll: `Services/CalculationService.php`, `Services/SnapshotService.php`.
- Talent: `Services/RecruitmentFunnelService.php`.
- Time: `Http/Controllers/CalendarAdminController.php`.

Peers subsequently adapted these; the current tree compiles with zero PHPStan
errors and zero Organization legacy identifiers.
