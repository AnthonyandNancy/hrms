# Volume 2 Task 3 tail — API contract and frontend single-company cutover

Worktree: `D:/code/ai/factoryHR/.worktrees/v2.1-remediation`
Branch: `feat/v2.1-remediation`

## Result

The strict gate `Tests\Contract\NoTenantNoLegalEntityTest` is green: **OK (4 tests, 6 assertions)** — 0 violations,
down from 289. The two `// single-company:preflight-only` marker lines in `CompanyPreflightCommand` remain and are
the only allowed hits.

## Scope and what changed

### 1. `docs/openapi/openapi.yaml` (100 hits -> 0)

- Deleted every `legal_entity_id` / `tenant_id` parameter (query), request-body property and response-body property.
- Removed only the matching entries from `required` lists (block style and flow style); no other key changed.
- Deleted `tenant_name` / `legal_entity_name` from `MeData` and added `company_name: string` (required), which is what
  `MeController::show()` actually returns since the IAM sweep (`CompanyProfileReader`).
- Recruitment funnel/reporting rows (`RecruitmentBoardRow`, `RecruitmentBudgetRow`,
  `RecruitmentCandidateConversionRequest`, `RecruitmentCandidateConversion`) lost the entity field and kept every other
  metric field and its `required` entry.
- Anchor enums `LEGAL_ENTITY` -> `COMPANY` (data-scope templates, `DataScopePolicy`, capability bindings) because
  `App\Shared\Authorization\AnchorType` has `COMPANY` and no `LEGAL_ENTITY` case. `late_grace_source` enum likewise
  `LEGAL_ENTITY` -> `COMPANY`.
- Calendar operations already carried the updated paths; verified
  `POST /v1/system/calendars/publish` and `GET /v1/time/calendars` exist and are the only calendar operations.
  No other path parameter was removed and no path string changed in this task.
- Wording that named a removed identifier was rewritten ("for one legal entity" -> "for the company"), including the
  `timeAttendanceClosingsIndex` description that mentioned a `legal_entity_id` scope.
- **Preserved**: all 323 operations, 317 `x-capability`, 173 `x-action-label`, 325 tags, 8 operation-level `security`
  blocks and every error response (verified by diffing parsed operation metadata against `HEAD`).

### 2. `docs/openapi/iam.yaml` (14 hits -> 0)

- Deleted the flow-style `X-Legal-Entity-Id` header parameter from 10 operations.
- Anchor enums `LEGAL_ENTITY` -> `COMPANY` in 3 schemas; "tenant exception role" wording -> "company exception role".

### 3. `frontend/src/generated/api.ts`

Regenerated with `pnpm openapi:generate`; never hand-edited. `pnpm openapi:check` reports the file is current.
The generated client now has zero `tenant_id` / `legal_entity_id` / `LEGAL_ENTITY` hits (was 133).

### 4. Frontend source (56 hits -> 0)

- `src/api/http.ts`: removed `legalEntityId()` and `legalEntityHeaders()`; every request builder dropped the
  `legal_entity_id` param and `X-Legal-Entity-Id` header. `BackendMe` no longer declares tenant fields and maps
  `company_name` to `Principal.companyName`.
- `src/api/contracts.ts`: dropped `Principal.tenantId/tenantName/legalEntityName`, `EmployeeDetail.legalEntity`,
  `OrgNode.legalEntityId`, `PayrollRunSummary.legalEntity`, `Payslip.legalEntity`; added `Principal.companyName`.
- `src/api/normalise.ts`: payroll run mapping no longer derives a `legalEntity` label.
- People forms (module + view copies): removed the legal-entity draft field, defaults, validation and picker from
  `DepartmentForms`, `EmployeeCreateForm`, `MovementForms`, `PositionForms`; request bodies no longer send the field.
- `peopleRead.ts` / `peopleService.ts`: deleted `ENTITY_SCOPE` and the `employeeParams` helper; all reads send only
  `as_of`/paging. Copy in the department/position forms now says "公司" instead of "法人".
- `TalentForm.vue` (module + view): removed the three legal-entity inputs (requisition, candidate conversion, offer
  acceptance) and their request-body fields.
- `PlatformWorkspace.vue`: removed `entityId`, all query params/headers, repointed calendar calls to
  `/v1/time/calendars` and `/v1/system/calendars/publish`, anchor defaults/options `LEGAL_ENTITY` -> `COMPANY`,
  account panel reads `company_name`, and parameter scope defaults to `COMPANY` (backend rejects anything else).
- `AdminView.vue`: anchor option `LEGAL_ENTITY` -> `COMPANY`.
- Timepay: `definitions.ts` and `useTimePayrollLoad.ts` dropped entity query params; `AttendancePanel` close/recalculate
  payloads are `{ month }` and the late-grace option is `COMPANY`; `TravelPanel` lost its legal-entity input and
  validation while keeping every other field.
- Employee/payroll/payslip headers and the shell/login company label use `companyName`; the standalone "法人" fact rows
  were removed because the singleton company is already the page context.
- `src/mocks/adapter.ts`: principal, capability catalogues, anchor bindings and org-tree fixtures no longer carry
  tenant/entity ids; anchor types use `COMPANY`; `legalEntity` fixture labels removed.

### 5. Frontend tests updated (coverage kept, not deleted)

`peoplePages`, `platformPages`, `talentPages`, `timePayrollPages`, `timePayModulePages`, `LoginView`, `shellMenu`,
`routerPageGrant`, `sessionPageGuard`: principal fixtures, `/me` payloads, calendar paths, anchor types and request
assertions now express the single-company contract. The legal-entity picker assertions were replaced with assertions
that the request carries no entity field and that the picker is gone.

## Verification (actual output)

| Gate | Result |
| --- | --- |
| `phpunit --filter=NoTenantNoLegalEntityTest` | `OK (4 tests, 6 assertions)` — **0 hits** |
| `node deploy/scripts/openapi-lint.mjs` | `openapi-lint: OK (251 paths, 323 operations, 3.1.0)` |
| `pnpm openapi:validate` | `Your API description is valid.` (2 pre-existing `operation-4xx-response` warnings on health probes) |
| `phpunit --filter='OpenApiContractTest\|OpenApiSchemaContractTest\|PagePermissionCoverageTest\|OpenApiActionLabelTest'` | `OK (26 tests, 9751 assertions)`; 0 success-schema / 0 error-set / 0 capability violations across 323 operations |
| `pnpm openapi:check` | `generated/api.ts is current` |
| `pnpm actions:check` | `OK (160 pages, 10 write pages, 9 pages with actions, 20 actions, 155 explicit write gaps, 15 owned capability gaps, 1 public write exceptions)` |
| `node scripts/audit_single_company.mjs` | `{"ok": true, "failures": []}` |
| `pnpm test:unit` (my 9 spec files) | **150 / 150 PASS** |
| `pnpm test:unit` (whole suite) | earlier clean run 324/324; final tree shows 4 failures in `platformModulePages.spec.ts` + `systemUsersPage.spec.ts` owned by concurrent v2-task6/7 agents (see concern 1) |
| `vue-tsc` scoped to every path this commit touches | **0 errors** |
| `eslint` scoped to every path this commit touches | **exit 0, 0 warnings** |
| `pnpm typecheck` / `pnpm lint` (whole tree) | blocked by concurrent agents' in-flight files, not by this commit (see concern 1) |

## Concerns / notes for the lead

1. **The strict gate was re-broken by v2-task8 after this commit landed.** `NoTenantNoLegalEntityTest` showed 0 hits at
   the moment of commit and in the immediately following runs. Afterwards the governance-pages agent added a private
   `singleCompanyRules()` to `DataScopeAdminController`, `FieldPolicyAdminController` and `MenuAdminController` whose
   literal array keys `'tenant_id'` and `'legal_entity_id'` re-trip the scanner (6 hits at
   `DataScopeAdminController:143,145`, `FieldPolicyAdminController:128,130`, `MenuAdminController:176,178`). Those files
   are `backend/app/**` and outside my scope, so I did not edit them. The rule can keep its behaviour without the literal
   keys (e.g. reject the fields from a constant list, or let the existing 422 validation stand). Re-run the gate after
   that fix; nothing in my 43 committed paths contributes to it.

2. **Concurrent agents were actively writing the same worktree.** `SystemMenusPage.vue`, `SystemDataScopesPage.vue`,
   `SystemFieldPoliciesPage.vue`, `SystemPermissionsPage.vue`, `SystemUsersPage.vue`, `UserCreateDialog.vue`,
   `UserPermissionPreview.vue`, `UserRoleDrawer.vue`, `generated/pageActions.ts` and `tests/unit/systemUsersPage.spec.ts`
   changed under me (v2-task5/6/7/8). Whole-tree `pnpm typecheck` / `pnpm lint` currently report only their in-flight
   findings (`UserCreateDialog.vue` unused `CreatePayload`/`setFieldError`, `UserPermissionPreview.vue` undefined index
   type, `SystemUsersPage.vue` unused `currentStatus`), and whole-tree `pnpm test:unit` has failures in
   `platformModulePages.spec.ts` / `systemUsersPage.spec.ts` for pages those tasks are rewriting. **None of these are my
   files.** Every one of the 43 paths in my commit passes `vue-tsc` and `eslint` with zero findings, and my 9 spec files
   pass 150/150. Re-run the three whole-tree frontend gates once tasks 5-8 land.

3. **`late_grace_source` value change.** `LEGAL_ENTITY` -> `COMPANY` was required by the forbidden-token gate. The
   calculator only reads the key's presence (`AttendanceCalculator::DEFAULT_RULE_CONFIG`), so behaviour is unchanged,
   but any stored rule row with the old value should be migrated by the backend owner if such data exists.

4. **`company_name`** is the field `MeController` already returns; I added it to `MeData` and the frontend principal so
   the account panel and shell label have a real source. No `company_id` was invented anywhere.

5. **`pnpm actions:check`** failed mid-task on a stale gap entry naming
   `POST /v1/system/calendars/{legal_entity_id}/publish` (a path removed by commit `d92725f`, before this task); v2-task5
   refreshed the gap list and the gate now reports `OK (160 pages, 20 actions, 155 explicit write gaps)`.

6. Backend production code, routes, migrations, tests, scripts and the legacy-route audit doc were not touched.
