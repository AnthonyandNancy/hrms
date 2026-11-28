# T02 — Single action catalog, versioned role authorization and object allowed actions

- **Status:** COMPLETE
- **Date:** 2026-11-28
- **Branch:** `v2.4-implementation`
- **Plan:** `docs/superpowers/plans/2026-11-28-v2-4-implementation-master-plan.md`, T02

## 1. Implemented behaviour

1. The generated action manifest now has a backend PHP runtime artifact:
   `backend/app/Modules/IAM/Authorization/pageActions.generated.php`.
2. `PageActionCatalog` reads that generated PHP at runtime; the API no longer parses `docs/generated/page-actions.json` on requests.
3. The generator and freshness audit now keep JSON, TypeScript and PHP action artifacts in the same transactional generation step.
4. Detail pages now declare object actions:
   - `payroll-run-detail`: lock inputs, calculate, review, approve, publish, cancel, reopen, correction, mark-paid, close.
   - `hr-contracts-by-id`: amendment and termination.
5. `AllowedActionResolver` resolves permitted object actions from capability + concrete anchor + server-loaded state, including SELF/MANAGED subject checks. Payroll run detail now returns `allowed_actions`, `state`, `version` and `source_version`.
6. Role authorization uses `expected_version` and returns 409 `ROLE_AUTHORIZATION_VERSION_CONFLICT`; the former 64-grant cap was raised to 512 and tested with 65 unique grants.
7. A capability may belong to multiple pages; role save now accepts the grant when one owning page is granted instead of using the last-page map.
8. `/v1/me/permissions` and `/v1/pages/catalog` expose generated action definitions alongside page definitions.
9. The frontend PayrollActionBar consumes server `allowed_actions` when present and no longer displays capability codes in the server-action path.

## 2. Plan adjustment (small)

The initial T02 wording implied wiring object actions into every detail endpoint immediately. Actual detail endpoints for Time are introduced/changed in T07/T08, and contract activation/detail work is T03. This task therefore:

- provides the resolver and catalog foundation;
- wires the payroll run detail endpoint now;
- leaves contract wiring to T03 and leave/overtime/travel wiring to T07/T08;
- leaves the remaining detail-page frontend migration to T17.

No business/design decision changed.

## 3. Verification evidence

Backend focused + adjacent:

```bash
phpunit --filter 'PageActionCatalogParityTest|AllowedActionResolverTest|RoleAuthorizationConcurrencyTest|RoleAuthorizationApiTest|MePermissionsTest|RolePagesApiTest|PayrollWorkspaceTest|PayrollHttpLifecycleTest|PayrollRunLifecycleTest'
```

Result:

```text
OK (38 tests, 278 assertions)
```

Frontend:

```text
112 test files passed, 652 tests passed
typecheck PASS
lint PASS
```

Static/contract:

```text
Pint PASS (1346 files)
PHPStan [OK] No errors
arch:test Module boundaries OK
OpenAPI validate PASS (2 pre-existing operation-4xx warnings)
actions:check PASS (27 pages with actions, 131 actions, 82 explicit write gaps)
openapi:check generated types current
```

Full gate:

```text
GATE:ALL PASS (22 gates, 245.3s)
```

Log: `docs/implementation/v24/02-action-catalog/gate-all.log`.

## 4. Remaining integration obligations

- T03 must add contract `allowed_actions` to contract detail responses.
- T07/T08 must add Time application detail/object responses with `allowed_actions`.
- T17 must consume `allowed_actions` for all remaining editable details and remove client-side capability inference from those surfaces.
- T18/T19 must prove the role version conflict, object action visibility and old-tab denial in the real browser stack.
