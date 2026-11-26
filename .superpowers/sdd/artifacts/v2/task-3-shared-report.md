# Task 3 — Shared contract de-scoping report

## Status

**Implemented and committed** for `backend/app/Shared/**` and the Shared-owned
tests/fakes. The acceptance `rg` over `backend/app/Shared` is **0**. `arch:test`
passes. The full-repository PHPStan and Pint gates are **not green yet** because
other in-flight sweep commits still contain stale test-side call sites and
line-ending churn outside this task's ownership; details and exact files are
listed below. No claim of a green full tree is made.

## Commits

- `0092606` — `refactor(shared): drop tenant and legal-entity from shared contracts` (primary, 38 files)
- `ec1e493` — `test(shared): adapt unit fakes and invariants to scoped contracts`
- `8669406` — `style(shared): apply pint to contract files`
- `b990818` — `test(shared): assert company business date replaces tenant date`

The primary commit is `0092606`; the follow-ups are test/style corrections owned
by this task. No Modules/** implementation was edited by this task.

## Final Shared signatures

### Authorization value objects / contracts

- `BindingGrant`: no `tenantId`; `(id, principalId, capabilityCode, anchorType,
  anchorId, fieldProfile, validFrom, validTo, authVersion, purposeCode)`.
- `AuthorityBindingRepository::effectiveBindings(int $principalId, string
  $capabilityCode, int $authVersion, string $businessDate): array`.
- `AccessSubjectStateDirectory::isEnabled(int $userId): bool`;
  `currentAuthVersion(int $userId): int`.
- `ElevationGrantDirectory::activeCapabilities(int $userId, int $grantId): array`.
- `SensitiveAccessAuditor::record(int $userId, string $capabilityCode, string
  $resourceType, ?int $resourceId = null, array $fields = [], array
  $context = []): void`.
- `ResourceRef`: no `tenantId`, no `legalEntityId`; resource type/id and
  employment/org/case/payroll references retained.
- `EmploymentRef`: `employmentId, personId, ?userId, ?orgUnitId,
  ?managerEmploymentId, ?validFrom, ?validTo`; no tenant/legal entity.
- `PersonEmploymentDirectory`:
  - `findEmployment(int $employmentId): ?EmploymentRef`
  - `managerEmploymentIds(int $userId, string $businessDate): array`
  - `employmentIdsForUser(int $userId, string $businessDate): array`
  - `employmentIdsForOrgUnits(array $orgUnitIds, string $businessDate): array`
- `OrganizationScopeDirectory`: `orgUnitExists(int $orgUnitId): bool`;
  `orgUnitAncestors(int $orgUnitId): array`;
  `descendantOrgUnitIds(int $orgUnitId): array`.
- `CaseMembershipDirectory`: `isMember(int $userId, int $caseId, string
  $capabilityCode): bool`; `caseIdsForUser(int $userId, string
  $businessDate): array`; `legalEntityForCase` deleted.
- `PayrollRunMembershipDirectory`: `isMember(int $userId, int $payrollRunId,
  string $capabilityCode): bool`; `runIdsForUser(int $userId, string
  $businessDate): array`; `legalEntityForRun` deleted.
- `EmploymentManagerDirectory::managerUserIdForEmployment(int $employmentId,
  string $businessDate): ?int`.
- `AnchorType::LEGAL_ENTITY` does not exist; `COMPANY` is the data-wide anchor
  (`anchor_id` null, `anchor_key` 0 semantics).

### Commands, observability, files, projections, time

- `Invariant::assert(array $payload): void`.
- `CommandRunner::run(...)` unchanged except it no longer forwards a tenant to
  idempotency/invariants.
- `AuditRecorder::record(?int $actorUserId, string $action, string
  $aggregateType, int|string $aggregateId, array $before = [], array $after = [],
  array $metadata = []): void`.
- `OutboxRecorder::record(string $eventType, string $aggregateType, string
  $aggregateId, int $aggregateVersion, array $payload): void`.
- `FileMetadataReader::metadata(int $fileObjectId): ?FileMetadata`;
  `FileMetadata` no longer has `tenantId`.
- `ProjectionRequest`: `(asOf, page, pageSize, filters)`; no tenant.
- `RequestContext`: no `tenantId` property/accessor; `setActor(?int $userId =
  null, ?int $employmentId = null)`.
- `TenantBusinessDate` deleted. `App\Shared\Time\CompanyBusinessDate`:
  - `today(): BusinessDate`
  - `resolve(?string $value, string $field = 'as_of'): BusinessDate`
  - constructor: `Clock` + `Organization\Contracts\CompanyProfileReader`;
    timezone comes from the current company profile.
- `ResolveLegalEntityContext` / `ResolveResourceLegalEntityContext` deleted;
  `ResolveCompanyScope` added.
- `ModuleBoundaryScanner::TABLE_OWNERS['Organization']`: dropped
  `legal_entities`, added `company_profile`.

## Acceptance / gate evidence (actual command output)

### `rg` acceptance — PASS (0)

```text
$ rg -n 'tenant_id|legal_entity_id|LEGAL_ENTITY|tenantId\(|legalEntityId' backend/app/Shared
(no output; exit 1 = no matches; count 0)
```

### `artisan arch:test` — PASS

```text
Scanned 681 module PHP files.
Warnings (non-blocking): 4
Module boundaries OK.
```

The four warnings are the pre-existing Platform dynamic-table warnings
(`DatabaseMigrationTarget`, `RetentionService` x3), unrelated to this task.

### `vendor/bin/pint --test` — FULL TREE FAIL (94 files), OWNED FILES PASS

Full-tree output currently reports 94 style failures, dominated by line-ending
churn and stale imports in other agents' in-flight files (People/Org/Workflow
tests, etc.). Scoped to this task's files it is clean:

```text
$ ../.tools/php/php.exe -d memory_limit=1G vendor/bin/pint --test app/Shared \
    tests/Fakes/FakePersonEmploymentDirectory.php \
    tests/Fakes/FakeOrganizationScopeDirectory.php \
    tests/Fakes/FakePayrollRunMembershipDirectory.php \
    tests/Fakes/FakeCaseMembershipDirectory.php \
    tests/Fakes/SpySensitiveAccessAuditor.php tests/Unit/Shared \
    tests/Unit/Commands/CommandRunnerTest.php \
    tests/Unit/Authorization/FailClosedAuthorizerTest.php
PASS ... 83 files
```

### `vendor/bin/phpstan analyse --no-progress` — FULL TREE FAIL (2 errors), SHARED CLEAN

Full-tree PHPStan completes and reports **2 errors in one file**; **none
reference `app/Shared` or any Shared contract signature**:

```text
tests\Feature\CapabilityGuardTest.php:69:Parameter #3 $authVersion of class
  Tests\Support\TestPrincipal constructor expects int, null given.
tests\Feature\CapabilityGuardTest.php:69:Parameter #4 $privileged of class
  Tests\Support\TestPrincipal constructor expects bool, int given.
```

Cause: `TestPrincipal`'s old positional argument list began with `tenantId`;
removing it shifts the call `new TestPrincipal(1, userId, null, 2)`. Owner:
core/IAM test sweep; the test itself is not a Shared file.

Scoped PHPStan over `app/Shared` plus the Shared-owned fakes/tests:

```text
$ phpstan analyse ... app/Shared tests/Unit/Shared tests/Fakes/... ...
[OK] No errors
```

### Required filtered suite — PASS

```text
$ artisan test --compact --filter='Tests\Unit|Tests\Integration'
Tests: 337 passed (34907 assertions)
```

The Shared-owned unit suite is green:

```text
$ artisan test --compact --filter='SharedSingleCompanyContractTest|CommandRunnerTest|FailClosedAuthorizerTest'
Tests: 15 passed (130 assertions)
```

and the full Unit suite is green:

```text
$ artisan test --compact --filter='Tests\\Unit'
Tests: 316 passed (34816 assertions)
```

## Module implementations that must adapt

All explicit implementations of the changed Shared contracts currently satisfy
the new signatures. The only remaining app-code call-site mismatch visible to
PHPStan was resolved by the People/IAM sweep. One non-Shared test call site
remains:

- `tests/Feature/CapabilityGuardTest.php:69` — `TestPrincipal` positional args
  after `tenantId` removal (`authVersion`/`privileged` shifted). Owner:
  core/IAM test sweep.

No implementation under `Modules/**` still fails to satisfy a Shared contract
signature. If a later merge reintroduces one, the first fatal will name the
implementation and missing method directly (as the Organization adapter did
before it was fixed in the org sweep).

Notable implementations verified against the final contracts:

- `IAM/Authorization/EloquentAuthorityBindingRepository` — 4-arg
  `effectiveBindings`, `BindingGrant` without tenant.
- `IAM/Authorization/EloquentAccessSubjectStateDirectory` — user-id only,
  `currentAuthVersion(): int`.
- `IAM/Authorization/EloquentElevationGrantDirectory` — `activeCapabilities():
  array`.
- `IAM/Authorization/PlatformSensitiveAccessAuditor` — 6-arg shape.
- `Organization/Authorization/EloquentOrganizationScopeDirectory` —
  `orgUnitExists` / `orgUnitAncestors` / `descendantOrgUnitIds`.
- `People/Authorization/EloquentPersonEmploymentDirectory` and
  `EloquentEmploymentManagerDirectory` — tenant-free.
- `Workflow/Authorization/EloquentCaseMembershipDirectory` — tenant-free.
- `Payroll/Authorization/EloquentPayrollRunMembershipDirectory` —
  tenant-free, no legal-entity method.
- `Platform/Services/AuditRecorderAdapter`, `OutboxRecorderAdapter`,
  `FileMetadataAdapter` — tenant-free.
