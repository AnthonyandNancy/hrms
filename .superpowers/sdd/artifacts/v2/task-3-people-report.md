# Volume 2 Task 3 - People sweep report

## Status

**DONE_WITH_CONCERNS**

- Worktree: `D:/code/ai/factoryHR/.worktrees/v2.1-remediation`
- Branch: `feat/v2.1-remediation`
- Subject: `refactor(people): remove tenant and legal-entity scope`
- Scope: the whole People module (authorized by the root agent), including
  `Modules/People/Contracts/**`, plus the People test support/contract files
  needed to keep the suite compiling.

The People runtime has no tenant or legal-entity scope identifiers. All People
tests pass. The documented PHPUnit filter string returns "No tests found" on
this Windows shell, so the directory form was used and is reported below. A
full-suite run later exhausted the 128 MB CLI limit in
`Tests\Feature\Platform\CalendarAdminTest`; the People suite itself is green.

## Delivered

| Change | Detail |
|---|---|
| Models | Removed `tenant_id` / `legal_entity_id` from docblocks, `$fillable` and casts in all 14 People models (`Person`, `Employment`, `Assignment`, `EmploymentTerms`, `EmploymentUserLink`, `Document`, `ContractTemplate`, `EmploymentContract`, employee-profile models). |
| `PeopleEmploymentWriter` | Removed the leading `int $tenantId` from every interface method and implementation; `transferLegalEntity` deleted from both. Transaction-participating behaviour and effective-date rules unchanged. |
| Writer implementation | Dropped tenant filters/create keys and `legal_entity_id` payloads; employee number uniqueness is now company-wide. Audit/outbox calls use the final Shared signatures (no tenant argument). |
| `EmploymentService` / `AssignmentService` / `TermsService` | Removed tenant params/filters. `transferLegalEntity` deleted. Cross-company assignment guard replaced by org-unit active-window validation; effective dating untouched. |
| `DocumentService` | Removed tenant params; Platform ports are `FileMetadataReader::metadata($id)`, `FileObjectReader::contents($id)`, `PrivateFileStore::store(...)/delete($id)`. Signed URL no longer embeds a tenant query parameter. |
| Directory services | `PeopleDirectoryService`, `EmploymentLifecycleDirectoryService`, `AssignmentDirectoryService`, `DepartmentStaffingService`, `PositionOccupancyService`, `OrgAssignmentImpactService`, `EloquentNoticeAudienceDirectory`, `EloquentPeopleWorkspaceDirectory`, `EloquentWorkforceReportingDirectory`, `EloquentWorkforceIntegrityDirectory` all tenant-free. `tenantIds()` and legal-entity id methods removed. |
| Authorization adapters | `EloquentPersonEmploymentDirectory` implements Shared's final `findEmployment/managerEmploymentIds/employmentIdsForUser/employmentIdsForOrgUnits`; `EloquentEmploymentManagerDirectory` uses `managerUserIdForEmployment(int,string)`. `EmploymentRef` now carries `employmentId/personId/userId/orgUnitId/managerEmploymentId/validFrom/validTo`. |
| Controllers | Removed `$principal->tenantId()`, tenant filters and client-supplied `legal_entity_id` validation; `CompanyBusinessDate` replaces `TenantBusinessDate`; transfer action removed; timeline/document/profile reads are constrained through `Authorizer::constrain`. |
| `AsOfEmployeeQuery` | Removed tenant predicates and cross-table tenant joins; `legal_entity_id` removed from projections. |
| Commands | Demo seeder no longer creates/reads legal entities or tenant keys; `people:as-of-check` groups by employment id only. |
| Tests | `PeopleScenario` is single-company (`__construct(Clock)`, `orgUnit(string ...)`, `employment(Person,string,...)`, no `legalEntity()`); `PeopleTestCase::actAs(int $userId, ?int $employmentId)`; foreign-tenant tests rewritten as cross-department/out-of-scope; `CrossLegalEntityTransferTest` removed with the multi-company feature. |

## Final People contract signatures

```
PeopleEmploymentWriter
  createPerson(array $person, ?int $actorUserId): int
  createEmployment(array $input, ?int $actorUserId): int
  createAssignment(int $employmentId, array $input, ?int $actorUserId): int
  appendTerms(int $employmentId, array $input, ?int $actorUserId): int
  endAssignment(int $assignmentId, string $endExclusive, ?int $actorUserId, string $sourceType, ?int $sourceId = null): void
  endEmployment(int $employmentId, string $endExclusive, string $lastWorkingDate, ?int $actorUserId, string $sourceType, ?int $sourceId = null): void
  setEmploymentStatus(int $employmentId, string $status, ?int $actorUserId): void
  appendDocument(int $employmentId, array $input, ?int $actorUserId): int
  linkUser(int $userId, int $employmentId, string $validFrom, ?string $validTo, ?int $actorUserId): void
  (transferLegalEntity deleted)

PeopleDirectory
  employmentForUser(int $userId): ?int
  managedEmploymentIds(int $employmentId, string $on): array
  employmentIdsAsOf(string $on): array
  employmentSnapshot(int $employmentId, string $on): ?EmploymentSnapshot
  employmentExistsInTenant(int $employmentId): bool
  (employmentIdsForLegalEntities / legalEntityIdsForEmployment removed)

EmploymentLifecycleDirectory
  employmentsForPerson(int $personId): array
  employment(int $employmentId, ?string $knownAt = null): ?EmploymentLifecycleSnapshot
  hasOverlappingEmployment(int $personId, string $validFrom, string $validTo): bool
  employeeNumberExists(string $employeeNo, ?int $exceptEmploymentId = null): bool
  activeManagerAssignment(int $managerAssignmentId, string $on): bool
  primaryAssignmentId(int $employmentId, string $on): ?int
  isAssignmentActive(int $assignmentId, string $on): bool
  termsVersions(int $employmentId): array
  termsAsOf(int $employmentId, string $on): ?EmploymentTermsSnapshot
  assignments(int $employmentId): array
  assignmentAsOf(int $employmentId, string $on): ?EmploymentAssignmentSnapshot
  linkedUserIds(int $employmentId, string $on): array

AssignmentDirectory
  positionIncumbents(int $positionId, string $on): array
  secondments(?int $employmentId = null): array
  occupant(int $assignmentId): ?AssignmentOccupant

DepartmentMembershipPort::moveMember(int $targetOrgUnitId, int $employmentId, string $effectiveFrom, ?int $actorUserId): array
DepartmentStaffingDirectory::activeHeadcount(array $orgUnitIds, string $on); members(int $orgUnitId, string $on)
NoticeAudienceDirectory: employmentIdsForOrgUnits(array,string); userIdsForEmployments(array,string); employmentOrgUnitIds(int,string)
OrgAssignmentImpactDirectory::impactCounts(array $orgUnitIds, string $on)
PositionOccupancyDirectory::occupancy(array $positionIds, string $on)
WorkforceIntegrityDirectory::overlappingPrimaryAssignments(); assignmentsOnTerminatedEmployments()
WorkforceReportingDirectory::rows(string $on)
PeopleWorkspaceDirectory::profile(int $employmentId, string $on)
EmploymentTermsDirectory::termsAsOf(int $employmentId, string $on)

Value objects: EmploymentSnapshot/EmploymentLifecycleSnapshot/AssignmentOccupant/
WorkforceReportingRow/PeopleWorkspaceProfile no longer expose legalEntityId
(tenantId was removed from EmploymentLifecycleSnapshot); WorkforceReportingRow
also drops legalEntityName.
```

## Verification

| Gate | Result |
|---|---|
| `rg -n 'tenant_id\|legal_entity_id\|LEGAL_ENTITY\|tenantId(\|transferLegalEntity' <people.txt files>` | **0** hits |
| `rg -n 'tenant_id\|legal_entity_id\|LEGAL_ENTITY\|tenantId(\|legalEntityId\|transferLegalEntity' backend/app/Modules/People backend/tests/Support/People* backend/tests/Feature/People` | **0** hits |
| `php artisan arch:test` | PASS - `Module boundaries OK.` (4 pre-existing non-blocking warnings) |
| `phpstan analyse` | PASS - `[OK] No errors` |
| `pint --test` (repository) | PASS - 1171 files |
| `php artisan test --compact Tests/Feature/People` | PASS - **87 passed, 418 assertions** |
| `php artisan test --compact --filter='People'` (includes People contract/integration tests) | PASS - **107 passed, 480 assertions** |
| `php artisan test --compact --filter='Tests\\\\Feature\\\\People'` | "No tests found" (shell/PHPUnit escaping on Windows; directory run used instead) |
| Full `php artisan test --compact` | Not green: PHP fatal memory exhaustion in `Tests/Feature/Platform/CalendarAdminTest` (outside People ownership); People tests had already passed |

## External call sites not edited

These modules still consume the People writer contract and were adapted by
their owning sweeps, not by this one (current line numbers):

- Lifecycle: `app/Modules/Lifecycle/EffectHandlers/OnboardingEffectHandler.php:70,83,97,157,171`
- Lifecycle: `app/Modules/Lifecycle/EffectHandlers/OffboardingEffectHandler.php:87,96`
- Lifecycle: `app/Modules/Lifecycle/EffectHandlers/TermsChangeEffectHandler.php:70`
- Lifecycle: `app/Modules/Lifecycle/EffectHandlers/ProbationConfirmEffectHandler.php:78`
- Lifecycle: `app/Modules/Lifecycle/EffectHandlers/TransferEffectHandler.php:78,81,100`
- Lifecycle: `app/Modules/Lifecycle/Services/SecondmentService.php:98`
- Lifecycle: `app/Modules/Lifecycle/Console/LifecycleDemoCommand.php:52,53`
- Talent: `app/Modules/Talent/Services/RecruitmentFunnelService.php:436,442,454`

`transferLegalEntity` has **0** remaining call sites anywhere in `backend/app`,
`backend/routes` or `backend/tests`; the Lifecycle transfer handler now ends and
recreates assignments/terms through the tenant-free writer methods instead.

Other external contract consumers observed and left to their owners:
IAM `TimeActorBridge`/`CapabilityAuthorizer`/`WorkspaceController`, Time
`Adapters/PeopleEmploymentDirectory`, Payroll `SnapshotService`,
`CompensationService`, `BenefitService`, `SeedDemoPayrollRunCommand`,
Reporting `ProjectionBuilder`, Engagement `NoticeAccessPolicy` /
`NoticeAudienceService`, Organization `PositionService` /
`PositionCatalogService` / `OrgUnitService` / `DepartmentController`.
