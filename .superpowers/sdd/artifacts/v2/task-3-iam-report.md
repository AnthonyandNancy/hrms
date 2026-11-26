# Volume 2 Task 3 — IAM Sweep Report

Date: 2026-11-26
Worktree: `D:/code/ai/factoryHR/.worktrees/v2.1-remediation`
Commit: `5dd3b1fc3d611a3b6fc77d9c5f8f9e645850ebd9` — `refactor(iam): remove tenant and legal-entity scope`

## Scope

IAM runtime files from `.superpowers/sdd/artifacts/v2/sweep/iam.txt`, excluding core-agent files:
- `backend/app/Modules/IAM/Contracts/Principal.php`
- `backend/app/Modules/IAM/Auth/AuthenticatedPrincipal.php`
- `backend/app/Modules/IAM/Auth/PrincipalResolver.php`
- `backend/app/Shared/Authorization/*`

The core agent changed `Principal`/`AuthenticatedPrincipal`/`PrincipalResolver` and the shared agent changed
`Shared/**`; neither set is included in this commit. I adapted all IAM call sites to their landed shapes.

Also committed under lead authorization: `backend/database/factories/**` (tenant columns removed,
`forPrincipal(int $userId, int $authVersion = 1)`) and the IAM-owned test harness adjustments.

## What changed

- Removed every tenant/legal-entity column from IAM models, create/update/forceFill/fillable/casts/docblocks.
- Deleted all tenant filters from IAM queries; single company means no predicate (never hardcoded `1`).
- Removed tenant-scoped arguments from IAM-owned contracts and services:
  `UserDirectory`, `UserAudienceDirectory`, `DemoUserDirectory`, `SessionRevoker`,
  `RoleAdminService`, `DataScopePolicyService`, `FieldPolicyService`, `PermissionProjection`,
  `WebAuthnService`.
- Adapted IAM implementations to the landed Shared contracts: `BindingGrant` without tenant,
  4-arg `effectiveBindings`, userId-only `AccessSubjectStateDirectory`, array-return
  `ElevationGrantDirectory`, 6-arg `SensitiveAccessAuditor`, tenant-free People/Org/Case/Payroll
  directories, tenant-free `AuditRecorder`/`OutboxRecorder`.
- `CapabilityAuthorizer`: tenant -> principal -> auth_version -> capability -> effective dates ->
  anchor; tenant query predicates removed. SELF/MANAGED/ORG_UNIT/ORG_SUBTREE/CASE/PAYROLL_RUN/COMPANY
  semantics retained; COMPANY is null anchor id / anchor_key 0.
- Session semantics preserved: token hash, revocation, idle and absolute expiry, auth_version,
  MFA challenge/step-up freshness, CSRF. Only tenant cross-check/scope removed.
- Audit events retain actor, action, result, request_id; tenant column removed.
- `LEGAL_ENTITY` anchors: none remain. Role templates, authority bindings, data-scope templates,
  field policies and permission catalog all resolve to COMPANY/null/0 for the single company.
- `MeController` now returns `company_name` from `CompanyProfileReader` rather than tenant/legal-entity
  duplicate fields.
- Commands updated: `iam:access-review` no longer takes `--tenant`; `iam:bind-role`,
  `iam:seed-demo`, `iam:sync-capabilities`, `hrms:install` no longer write/resolve tenant.
- Factories updated (User, Session, AuthorityBinding, EmployeeStub).

## Tests

Tenant isolation coverage was rewritten, not deleted:
- cross-tenant binding -> unrelated-principal / department-anchor isolation
- legal-entity scope -> company-vs-department isolation
- cross-tenant detail 404 -> out-of-scope employee detail 404
- IDOR binding read -> capability-denied binding read
- field projection cross-tenant -> unknown-schema empty FieldSet; unknown employment stays denied by capability
- wildcard tests now assert single-company expansion semantics

Retained: SOD hard-deny, last-admin/superadmin baseline, session revocation/auth_version,
MFA challenge and step-up, CSRF, capability denial, break-glass two-person/expiry/revocation,
sensitive-field audit without values.

## Verification (scoped)

- `rg -n 'tenantId\(|tenant_id|LEGAL_ENTITY|legal_entity_id' <IAM sweep files excluding core/Shared>` -> **0 matches** (exit 1)
- IAM + frontend? No frontend touched.
- `php artisan arch:test` -> PASS (682 files, 4 pre-existing non-blocking Platform warnings)
- `php artisan test --compact --filter='Tests.Feature.IAM|Tests.Feature.Authorization'`
  -> **179 passed, 2,961 assertions**
- Direct PHPUnit `tests/Feature/IAM tests/Feature/Authorization tests/Unit/IAM tests/Unit/Authorization`
  -> **241 passed, 7,073 assertions**
- `vendor/bin/pint --test` on IAM + factories + IAM/Authorization tests -> PASS (154 files)
- `vendor/bin/phpstan analyse --no-progress` scoped to IAM + factories + IAM/Authorization tests -> **OK, no errors**

Full-repo PHPStan is not green because other sweep owners still have stale call sites. The standard
PHPStan invocation also hits a Windows temp-directory ACL (`Could not write ... resultCache.php`);
scoped run used an in-worktree `sys_temp_dir`.

## External call sites / remaining mismatches (not edited by IAM)

1. `backend/app/Modules/People/Commands/SeedPeopleDemoCommand.php:75` still calls
   `userIdForUsername(1, $username)`; IAM contract now takes only `string $username`.
2. `backend/app/Modules/People/Authorization/EloquentPersonEmploymentDirectory.php` now matches the
   new Shared shape, but People-owned other adapters/tests still need the directory sweep.
3. `backend/tests/Integration/DirectoryContractTest.php`, `AsOfConsistencyTest.php`,
   `UatOnboardingToFirstPayrollPreparationTest.php`, `tests/Feature/People/PaginationScopeTest.php`,
   `tests/Contract/PeopleOrgApiContractTest.php`, and Organization feature tests still use the old
   `PeopleScenario::legalEntity()` / `orgUnit($entity, ...)` / `actAs(tenantId: ...)` shapes.
4. `backend/app/Modules/Lifecycle/EffectHandlers/OnboardingEffectHandler.php` and
   `OffboardEffectHandler.php` were already adapted by the lifecycle sweep to tenant-free IAM calls.
5. `backend/app/Modules/Engagement/**` call sites were already adapted by the rest sweep to the new
   `UserAudienceDirectory` signatures.
6. `WorkflowPrincipal`/`ActorSnapshot` are Workflow-owned and were landed tenant-free by that sweep;
   IAM `WorkflowPrincipalBridge` now constructs the tenant-free shape.
7. Shared contracts and DTOs are owned by `v2-sweep-shared` (commit `0092606`) and are not in this commit.

## Not edited

- Core-owned: `IAM/Contracts/Principal.php`, `IAM/Auth/AuthenticatedPrincipal.php`,
  `IAM/Auth/PrincipalResolver.php`, `Shared/Authorization/*`.
- `routes/api.php`, other modules, frontend.

## Commit contents

107 files: 58 IAM runtime files, 4 factories, 45 IAM/Authorization tests/harness files.
