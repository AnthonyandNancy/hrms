# FactoryHR V2.4 — P00 Baseline Record

- **Date:** 2026-11-28
- **Branch:** `v2.4-implementation`
- **Starting HEAD:** `f659862`
- **Requirement/Design:** `docs/FactoryHR_V2.4_需求与技术设计说明书.md`
- **Implementation plan:** `docs/superpowers/plans/2026-11-28-v2-4-implementation-master-plan.md`
- **ADR:** `docs/adr/0039-payroll-execution-compensation-and-time-fact-versions.md`

## 1. Environment

| Item | Observed value |
|---|---|
| OS | Windows workspace |
| PHP CLI | `D:\code\ai\factoryHR\.tools\php\php.exe` / PHP 8.4.28 NTS |
| PHPUnit | 12.5.45 |
| Test database | SQLite in-memory/test configuration |
| Node tooling | pnpm workspace, Node >= 24 declared |
| Browser acceptance mode required | `VITE_API_MODE=http` with isolated real backend |
| Migration baseline | Local SQLite was previously reported incomplete; not used as final acceptance evidence |

Laravel Boost is already present in the repository dependency tree; no new package installation was needed for this baseline.

## 2. Baseline gate result before fixes

The authoritative command was run from the repository root:

```bash
pnpm gate:all
```

It failed fast at `backend:phpunit`:

```text
Tests: 1490, Assertions: 58645, Failures: 8.
GATE FAIL backend:phpunit
```

No later contract/frontend/product gates were reached by that run.

### 2.1 Failure inventory and root cause

| # | Test | Root cause | Resolution |
|---:|---|---|---|
| 1 | `Tests\Unit\Authorization\CapabilityCatalogTest::test_gap_completion_catalog_reaches_the_documented_total` | Implementation catalog reached 171 capabilities while the frozen expectation still said 170. The extra release/report capability is intentional in the current code-owned catalog. | Updated the frozen expectation to 171 with the delta documented. |
| 2 | `CapabilityCatalogTest::test_no_template_grant_uses_an_anchor_the_capability_forbids` | `HR_SPECIALIST` and `ATTENDANCE_SPECIALIST` granted company-owned leave type/policy capabilities at `ORG_UNIT`. | Changed the six grants to `COMPANY`. The capability definitions were not widened. |
| 3 | `RoleAdminTest::test_role_can_be_copied...` | Copying a standard role validated template-only `CASE`/`PAYROLL_RUN` grants as if they already had a concrete anchor id. A role grant is an authoring template; concrete anchors are supplied by `AuthorityBinding`. | Copy path now validates capability/type/profile but permits an unbound template anchor; create/authorization paths still require concrete anchors. |
| 4 | `RoleAuthorizationApiTest::test_template_role_authorization_is_read_only...` | Same root cause as #3. | Same copy-path fix. |
| 5 | `IamRouteMatrixTest::test_every_iam_route_appears_in_the_denial_matrix` | `GET /v1/auth/csrf` was registered but missing from the denial matrix. | Added a low-risk SELF row with unauthenticated/stale-auth-version/session-revoked denials. |
| 6 | `LegacyRouteInventoryTest::test_every_route_is_classified_as_kernel_or_legacy` | `GET /v1/auth/csrf` was missing from the route ledger. | Added a `kernel` ledger row. |
| 7 | `LegacyRouteInventoryTest::test_the_ledger_covers_every_registered_operation` | Ledger count was one lower than the registered operation count. | Resolved by the same ledger row. |
| 8 | `PeopleDirectoryFrozenSignatureTest::test_people_directory_methods_match_the_interface_freeze` | The frozen interface snapshot predated `employmentIdByEmployeeNo(string): ?int`, which is used by the demo/employee lookup path. | Added the method to the frozen contract expectation; the interface itself already had it. |

### 2.2 Additional baseline gate failures found after PHPUnit turned green

The first full rerun reached later gates and exposed two more pre-existing gate defects:

| Gate | Failure | Root cause | Resolution |
|---|---|---|---|
| `backend:phpstan` | 2 errors in `SeedDemoCommand.php:93-94` | The demo employment map is a closed literal array, so `?? null` and the null comparison were statically impossible. | Read the known key directly and check only the returned employment id. |
| `contract:actions-check` | `docs/generated/page-actions.json is stale` | Generated files were checked out with Windows CRLF while the generator/audit expects canonical LF. | Added `.gitattributes` entries forcing LF for generated action files and regenerated them. |
| `contract:openapi-check` | committed generated API types were stale | `/v1/auth/csrf` was added to OpenAPI without regenerating `frontend/src/generated/api.ts`. | Ran `pnpm openapi:generate`; added the generated API file to the LF policy. |
| `product:user-copy` | `ContractFormDrawer.vue:283` exposed the raw enum `ACTIVE` | The contract creation payload used a literal server enum instead of the shared display-code constant. | Replaced the literals with `DISPLAY_CODES.contractStatus.active/draft`. |

## 3. Focused verification after fixes

Command:

```bash
cd backend
../.tools/php/php.exe -d memory_limit=1G vendor/bin/phpunit \
  --filter 'CapabilityCatalogTest|RoleAdminTest|RoleAuthorizationApiTest|IamRouteMatrixTest|LegacyRouteInventoryTest|PeopleDirectoryFrozenSignatureTest' \
  --colors=never
```

Result:

```text
OK (29 tests, 1326 assertions)
```

## 4. Source audit facts carried into the plan

These findings are not optional background; they define the implementation order:

1. Payroll currently prefers `employment_terms.base_salary_minor` over `employee_compensations` in `SnapshotService` / `CalculationService`.
2. A payroll run has one required `scheme_id`; the calculator receives one scheme for all employees.
3. Missing Time data produces a zero attendance summary and can still calculate full salary.
4. `attendance_period_closures` has no immutable fact version/hash; payroll reads live attendance.
5. Leave/overtime/travel Workflow templates are not connected to production domain effects; overtime creation can be immediately approved.
6. Overtime PAY does not enter payroll via a settlement; Payroll reads `attendance_days.overtime_minutes`, so TIMEOFF can be paid.
7. Corrections only baseline against the original run; YTD uses a mutable aggregate with `max(0)`/non-negative clamping.
8. Payroll publication, payslip publication and disbursement are one status chain.
9. Benefit transactions cannot attach multiple plans per employee/period and never enter the payroll snapshot.
10. Performance results have no approved bonus mapping into payroll; Offers do not create execution compensation drafts.
11. Notification external delivery is a log stub and may be marked `SENT`; report columns/attributes are not consistently field-filtered.
12. Detail APIs do not expose `allowed_actions`, and the frontend derives actions from capabilities/status; the self-service payslip path still points through management payroll.
13. Frontend monetary formatting still converts through floating-point division for some paths and uses a fixed `¥`.
14. `DatabaseSeeder` seeds demo by default and `iam:sync-capabilities` can delete custom role grants/pages.
15. The local SQLite migration state is not a substitute for the required MySQL/Redis/object-storage acceptance environment.

## 5. Baseline decision

The initial failures were baseline contract drift, not acceptance evidence of V2.4 completion. After the fixes above, the authoritative full gate was rerun:

```text
GATE:ALL PASS (22 gates, 238.9s)
```

The log is stored at `docs/implementation/v24/00-baseline/gate-all.log`. T00 is complete. The overall V2.4 task remains `IN PROGRESS` until the requirement acceptance matrix and MCP evidence are complete; no AC is claimed from this baseline alone.
