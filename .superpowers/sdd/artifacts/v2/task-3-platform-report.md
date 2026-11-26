# Task 3 Platform Report — Remove Tenant and Legal-Entity Runtime Scope

- **Status:** DONE_WITH_CONCERNS
- **Worktree:** `D:/code/ai/factoryHR/.worktrees/v2.1-remediation`
- **Commit:** `refactor(platform): remove tenant and legal-entity scope` (see SHA below)
- **Scope:** all Platform-owned runtime files; Platform tests updated only where needed to compile against the single-company schema. No Shared, other module, route, migration, or OpenAPI edits were made.

## What changed

| Area | Change |
|---|---|
| Models | Removed `tenant_id` from fillable/casts/docblocks for audit, dictionary, file, idempotency, menu, notification, outbox, parameter, migration, demo and retention models. |
| Contracts | `DictionaryReader::entries(string $type)`; `ParameterReader::get(string $key, mixed $default = null)`; `FileObjectReader::contents/exists(int $fileObjectId)`; `PrivateFileStore::store(string $contents, ...)` and `delete(int $fileObjectId)`; `MenuOverride*` methods without tenant; `IdempotencyRunner::run(int $principalId, string $scope, string $key, array $payload, Closure $operation)`. |
| Services | Removed all tenant filters/writes; file storage keys no longer include tenant id; idempotency is unique by `(principal_id, scope, key)`; audit/outbox adapters match the tenant-free Shared ports; feature flags are instance-wide; retention loses tenant parameter while preserving legal-hold/exclusion semantics. |
| Migration | `MigrationTargetPort`, CSV importers, runner, reconciler, rollback planner and database/shadow targets lost tenant args and `legal_entities`/`legal_entity_id` handling. Fixture `legal_entity_code` columns are no longer required or validated. Migration/retention feature semantics are retained: source markers, reconciliation, rollback-plan, dry-run, legal holds. |
| HTTP | Dictionary/notification admin controllers no longer resolve a principal tenant or filter by it; parameters use the `COMPANY` scope; projections derive `as_of` from `Clock` + configured company timezone, avoiding a Platform→Organization dependency. |
| Demo | `demo:seed`, `demo:reset`, `demo:verify` no longer accept/filter tenant; dataset identity is `dataset` only. |
| Tests | Platform kernel/admin/menu/notification/calendar/benefit tests rewritten for single company; migration tests updated to tenant-free signatures; retention/audit/outbox/idempotency/feature-flag tests updated. Cross-tenant assertions became company-wide/out-of-scope assertions. |

## Verification evidence

| Gate | Command | Result |
|---|---|---|
| Acceptance scan | `rg -n 'tenant_id|legal_entity_id|LEGAL_ENTITY|tenantId\(|legalEntityId' app/Modules/Platform` | **0 matches** (exit 1) |
| Platform tests | `php artisan test --compact --filter 'Tests.Feature.Platform'` | **PASS — 32 passed (198 assertions)** |
| Migration tests | `php artisan test --compact tests/Feature/Migration` | **PASS — 25 passed (162 assertions)** |
| Platform-owned unit/feature tests | Idempotency/Outbox/Audit/Demo/Retention/FeatureFlag | **PASS — 25 passed** |
| Architecture | `php artisan arch:test` | **PASS** — Module boundaries OK; 4 pre-existing dynamic-table warnings only |
| PHPStan (Platform) | `phpstan analyse app/Modules/Platform` | **PASS — no errors** |
| PHPStan (repo) | `phpstan analyse` | **FAIL (external/in-flight)** — 310 errors, all outside Platform-owned files (Organization/People/Payroll test sweep still in progress). No Platform-file error remains. |
| Pint | `pint --test` on all touched Platform + test files | **PASS** |
| Platform filter exact command | `artisan test --compact --filter='Tests\Feature\Platform'` | PHPUnit reports "No tests found" under this literal escaping; `--filter 'Tests.Feature.Platform'` selects and passes all 32. |

## Migration/retention/feature-flag semantics preserved

- Migration: two-phase staging, dependency wave checks, idempotent natural keys, source_type/source_id markers, shadow-vs-live reports, reconciliation counts/sums, and plan-only rollback statements remain.
- Retention: preview/purge split, dry-run default, permanent-class guard, fail-closed unavailable targets, legal-hold subject matching and audit evidence remain. Only scope columns/parameters were removed.
- Feature flags: code catalogue + default-deny + audited overrides remain; now one instance-wide override per flag.

## External call sites not edited (owner action required)

| Owner | File | Required change |
|---|---|---|
| People | `backend/tests/Feature/People/EmployeeDocumentTest.php:101` | `FileObjectReader::exists(1, $fileObjectId)` → `exists($fileObjectId)` |
| Organization | `backend/app/Modules/Organization/Services/DemoOrganizationSeederService.php` / `OrganizationBootstrapService.php` | still write `legal_entities`, `tenant_id`, `legal_entity_id`; Organization sweep owns removal |
| People | `backend/tests/Support/PeopleScenario.php` | constructor/legalEntity/orgUnit/employment still legacy-shaped; People sweep owns it (Platform tests were decoupled from it) |
| IAM | `backend/app/Modules/IAM/Authorization/PermissionProjection.php`, `MenuAdminController.php` | already aligned to `overrides()/create()/update()/delete()` (no action detected) |
| Talent / Workflow / Lifecycle | `PerformanceController`, `PerformanceTemplateController`, `RecruitmentController`, `CaseController`, `DecisionController`, `LifecycleController` | already aligned to tenant-free `IdempotencyRunner::run(principalId, ...)` (no lingering tenant arg detected) |
| Reporting / People | `ExportJobRunner`, `DocumentService` | already aligned to tenant-free file/store/outbox ports (no lingering tenant arg detected) |

## Concerns

1. Full-repo PHPStan is still red from other agents' in-flight modules/tests; the Platform subtree itself is clean.
2. Organization's production demo/bootstrap services still query the removed `legal_entities` table; this belongs to the Organization sweep and can break `migrate:fresh --seed` until fixed.
3. `tests/Support/PeopleScenario.php` was transiently corrupt/incomplete during the run (missing closers); Platform tests no longer depend on it.
4. The literal `--filter='Tests\Feature\Platform'` form in the task brief matches zero tests in this PHPUnit version; the dot-separated filter was used as equivalent and is recorded above.
